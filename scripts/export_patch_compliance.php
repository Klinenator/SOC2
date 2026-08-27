<?php
declare(strict_types=1);

/**
 * Populate the portal's Patching page from the real fleet.
 *
 * The monthly CC7.3 task asks you to "export or screenshot the patch management dashboard".
 * Until now there was nothing to screenshot: patch_servers.json has been empty since March,
 * because the portal expects agents to check in and no agent was ever installed. Meanwhile
 * the actual patch state has been sitting in SSM and on the hosts the whole time.
 *
 * This bridges the two. It gathers real state and merges it into patch_servers.json so the
 * page shows what is true, leaving the reading and the judgement to a person.
 *
 * WHY IT WRITES THE FILE INSTEAD OF POSTING TO THE API
 *
 * api/patching.php has a token-authenticated check-in endpoint built exactly for this. It is
 * unusable: every /api/*.php is behind auth_request (Google OAuth) since the portal was
 * closed to the internet on 2026-08-26, and an agent has no browser session. Punching an
 * exemption back through that gate to feed a dashboard would trade a real control for a
 * convenience, so this merges into the data file on the host instead.
 *
 * WHAT IT DOES NOT DO
 *
 * Close the monthly task, or file evidence. A machine that gathers the evidence AND signs it
 * off means nobody looked at the patch state, which is the failure log-review/README.md
 * describes as theatre. Gathering is automated; the review is not.
 *
 * Usage (needs AWS credentials and the rrs-security checkout for fleet-run.sh):
 *   php scripts/export_patch_compliance.php                # gather, print a summary, write nothing
 *   php scripts/export_patch_compliance.php --apply        # gather and update the live portal
 *   php scripts/export_patch_compliance.php --json=out.json
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

$options = getopt('', ['fleet-run::', 'apply', 'json::', 'portal-host::', 'help']);
if (isset($options['help'])) { fwrite(STDOUT, "See the header of this file.\n"); exit(0); }

$fleetRun = $options['fleet-run']
    ?? (getenv('HOME') . '/src/rrs-security/hardening/scripts/fleet-run.sh');
$portalHost = $options['portal-host'] ?? 'i-0e972f01770aac678';   // ip.rrsaccess.com
$apply = array_key_exists('apply', $options);

if (!is_executable($fleetRun)) {
    fwrite(STDERR, "fleet-run.sh not found or not executable: $fleetRun\n");
    fwrite(STDERR, "Pass --fleet-run=/path/to/fleet-run.sh\n");
    exit(1);
}

/** Run a command, returning [stdout, exitCode]. */
function run(string $command): array {
    $output = [];
    $status = 0;
    exec($command . ' 2>&1', $output, $status);
    return [implode("\n", $output), $status];
}

/* ---------------------------------------------------------------- Linux hosts */

// A simulation only: `apt-get -s upgrade` resolves what WOULD be installed and changes
// nothing. Security updates are identified by the origin of the candidate version rather
// than by guessing from package names.
// Reports are keyed by INSTANCE ID, not hostname.
//
// Hostname was the original key, and it made renaming a host silently destructive: the merge
// matches records by key, so a renamed host looks like a brand new server. It gets a new id, a
// new agent token, and a fresh row, while the old row lingers forever showing the patch state
// as of the rename. Ten servers quietly become fifteen. Instance IDs do not change when a host
// is renamed, rebooted, or moved to a different address, which is what a primary key needs.
$probe = <<<'PROBE'
h=$(hostname -s)
# Instance ID from IMDSv2, falling back to what cloud-init recorded. Both are on every host in
# this fleet; the fallback matters if IMDS hop limits ever change.
TOK=$(curl -s -X PUT -m 2 "http://169.254.169.254/latest/api/token" -H "X-aws-ec2-metadata-token-ttl-seconds: 60" 2>/dev/null)
IID=$(curl -s -m 2 -H "X-aws-ec2-metadata-token: $TOK" http://169.254.169.254/latest/meta-data/instance-id 2>/dev/null)
[ -n "$IID" ] || IID=$(cat /var/lib/cloud/data/instance-id 2>/dev/null | tr -d '\n')
p() { printf 'PATCH|%s|%s|%s\n' "$IID" "$1" "$(printf '%s' "$2" | tr '\n' '~')"; }
if [ -z "$IID" ]; then echo "PATCH-ERROR|$h|no instance id available" >&2; exit 1; fi
p hostname "$h"
p os "$(. /etc/os-release 2>/dev/null; echo "$PRETTY_NAME")"
p reboot "$([ -f /var/run/reboot-required ] && echo yes || echo no)"
p lastpatch "$(grep -h '^Start-Date:' /var/log/apt/history.log 2>/dev/null | tail -1 | cut -d' ' -f2-)"
p held "$(apt-mark showhold 2>/dev/null | paste -sd, -)"
SIM=$(apt-get -s -o Debug::NoLocking=1 upgrade 2>/dev/null | grep '^Inst ')
p updates "$(printf '%s' "$SIM" | sed -E 's/^Inst ([^ ]+) \[[^]]*\] \(([^ ]+) ([^)]*)\).*/\1\t\2\t\3/' | head -80)"
p apthist "$(tail -12 /var/log/apt/history.log 2>/dev/null)"
PROBE;

fwrite(STDERR, "Gathering Linux patch state across the fleet...\n");
$b64 = base64_encode($probe);
[$out, $status] = run(escapeshellarg($fleetRun)
    . ' --comment=' . escapeshellarg('patch compliance export (read-only simulation)')
    . ' ' . escapeshellarg("echo $b64 | base64 -d | bash"));
if ($status !== 0 && !str_contains($out, 'PATCH|')) {
    fwrite(STDERR, "Fleet probe failed:\n$out\n");
    exit(1);
}

$hosts = [];
foreach (explode("\n", $out) as $line) {
    $line = ltrim($line);
    if (!str_starts_with($line, 'PATCH|')) continue;
    $parts = explode('|', $line, 4);
    if (count($parts) < 4) continue;
    [, $iid, $key, $value] = $parts;
    $hosts[$iid][$key] = str_replace('~', "\n", $value);
}

$reports = [];
foreach ($hosts as $iid => $f) {
    $updates = [];
    foreach (explode("\n", trim($f['updates'] ?? '')) as $row) {
        if ($row === '') continue;
        $cols = explode("\t", $row);
        if (count($cols) < 2) continue;
        $origin = $cols[2] ?? '';
        $updates[] = [
            'name'     => $cols[0],
            'version'  => $cols[1],
            // The origin string looks like "Ubuntu:24.04/noble-security [amd64]".
            'security' => stripos($origin, 'security') !== false,
        ];
    }
    $held = array_values(array_filter(array_map('trim', explode(',', $f['held'] ?? ''))));
    $reports[$iid] = [
        'instanceId'        => $iid,
        'hostname'          => trim($f['hostname'] ?? $iid),
        'osVersion'         => trim($f['os'] ?? ''),
        'lastPatchAt'       => trim($f['lastpatch'] ?? ''),
        'rebootRequired'    => trim($f['reboot'] ?? 'no') === 'yes',
        'updates'           => $updates,
        'heldPackages'      => $held,
        'aptHistoryExcerpt' => trim($f['apthist'] ?? ''),
        'error'             => '',
        'platform'          => 'linux',
    ];
}

/* -------------------------------------------------------------- Windows hosts */

// The Windows hosts do not run apt; their patch state lives in SSM Patch Manager, which is
// the authority there. Reported in the same shape so one page covers the whole fleet.
fwrite(STDERR, "Gathering Windows patch state from SSM...\n");
[$ssmOut, $ssmStatus] = run('aws ssm describe-instance-patch-states --instance-ids '
    . 'i-057dd0c8f8a8768c7 i-02f0d04c7cfb5fd22 '
    . '--query "InstancePatchStates[].[InstanceId,InstalledCount,MissingCount,FailedCount,OperationEndTime]" '
    . '--output text');
if ($ssmStatus === 0) {
    $windowsNames = ['i-057dd0c8f8a8768c7' => 'SMB-Storage', 'i-02f0d04c7cfb5fd22' => 'QuickBooks'];
    foreach (explode("\n", trim($ssmOut)) as $row) {
        if (trim($row) === '') continue;
        $c = preg_split('/\s+/', trim($row));
        if (count($c) < 5) continue;
        [$instance, $installed, $missing, $failed, $endTime] = $c;
        $name = $windowsNames[$instance] ?? $instance;
        $reports[$instance] = [
            'instanceId'        => $instance,
            'hostname'          => $name,
            'osVersion'         => 'Windows (SSM Patch Manager)',
            'lastPatchAt'       => substr(str_replace('T', ' ', $endTime), 0, 19),
            'rebootRequired'    => false,
            // Missing patches are the equivalent of pending security updates here: the
            // baseline only counts what it considers required.
            'updates'           => array_fill(0, (int)$missing, ['name' => 'missing patch', 'version' => '', 'security' => true]),
            'heldPackages'      => [],
            'aptHistoryExcerpt' => "SSM patch baseline: installed=$installed missing=$missing failed=$failed",
            'error'             => (int)$failed > 0 ? "$failed patch(es) failed to install" : '',
            'platform'          => 'windows',
        ];
    }
} else {
    fwrite(STDERR, "  warning: could not read SSM patch states; Windows hosts omitted\n");
}

/* -------------------------------------------------------------------- summary */

fwrite(STDOUT, sprintf("\n%-18s %-28s %8s %8s %-6s %s\n", 'HOST', 'OS', 'PENDING', 'SECURITY', 'REBOOT', 'LAST PATCH'));
$totalSec = 0;
foreach ($reports as $r) {
    $sec = count(array_filter($r['updates'], fn($u) => $u['security']));
    $totalSec += $sec;
    fwrite(STDOUT, sprintf("%-18s %-28s %8d %8d %-6s %s\n",
        $r['hostname'], substr($r['osVersion'], 0, 28), count($r['updates']), $sec,
        $r['rebootRequired'] ? 'yes' : 'no', $r['lastPatchAt']));
}
fwrite(STDOUT, sprintf("\n%d host(s), %d pending security update(s) fleet-wide.\n", count($reports), $totalSec));

if (isset($options['json'])) {
    file_put_contents($options['json'], json_encode($reports, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    fwrite(STDOUT, "Wrote {$options['json']}\n");
}

if (!$apply) {
    fwrite(STDOUT, "\nNothing written to the portal. Re-run with --apply to update it.\n");
    exit(0);
}

/* ---------------------------------------------------------------------- apply */

// The merge runs ON the portal host, because patch_servers.json lives there and carries
// per-server ids and agent tokens that must survive. Anything already recorded keeps its id,
// token, display name, owner and environment.
//
// MATCHING IS BY INSTANCE ID, falling back to hostname for records written before instance ids
// were stored. That fallback is a migration path, not a permanent second key: the first run
// after this change adopts each existing row by hostname and stamps its instanceId, and every
// run after that matches on the id alone. Without it, this change would itself have caused the
// duplication it exists to prevent.
$merge = <<<'MERGE'
<?php
$file = '/var/www/SOC2/data/patch_servers.json';
$reports = json_decode(file_get_contents('php://stdin'), true);
if (!is_array($reports)) { fwrite(STDERR, "bad payload\n"); exit(1); }
$servers = file_exists($file) ? (json_decode(file_get_contents($file), true) ?: []) : [];
$byInstance = []; $byHost = [];
foreach ($servers as $i => $s) {
    if (!empty($s['instanceId'])) $byInstance[$s['instanceId']] = $i;
    if (!empty($s['hostname']))   $byHost[$s['hostname']] = $i;
}
$now = date('Y-m-d H:i:s');
$created = 0; $updated = 0; $adopted = 0;
foreach ($reports as $iid => $r) {
    $sec = count(array_filter($r['updates'], fn($u) => !empty($u['security'])));
    $host = $r['hostname'] ?? $iid;
    if (isset($byInstance[$iid]))      { $i = $byInstance[$iid]; $updated++; }
    elseif (isset($byHost[$host]))     { $i = $byHost[$host]; $updated++; $adopted++; }
    else {
        // Friendly names on first creation only, matching the EC2 Name tags. Keyed by
        // INSTANCE ID rather than hostname, so renaming a host cannot make this table miss
        // and fall back to showing a bare hostname as the display name.
        $labels = [
            'i-027209a92fef460c6' => 'Schedule Server', 'i-075cabfc24d85df82' => 'SFTP Ubuntu',
            'i-0ac0da3dfb312af1e' => 'REMOTE',          'i-0e972f01770aac678' => 'IP (web/mail)',
            'i-0ff8b73d48efc7061' => 'idp',             'i-0c5b47cc5bafe51c7' => 'MARKETING',
            'i-02a6aad02266aba15' => 'DIV-VPN',         'i-0226384292e496090' => 'DIV-VPN2',
            'i-057dd0c8f8a8768c7' => 'SMB-Storage',     'i-02f0d04c7cfb5fd22' => 'QuickBooks',
        ];
        $servers[] = [
            // Derived from the instance id, so it survives a rename. A record keyed on a
            // hostname hash would get a different id the moment the host was renamed.
            'id' => 'srv-' . substr(sha1($iid), 0, 8),
            'name' => $labels[$iid] ?? $host,
            'environment' => 'production', 'ownerId' => 'skline',
            'token' => bin2hex(random_bytes(16)),
        ];
        $i = array_key_last($servers); $created++;
    }
    // Stamped on every pass, not just at creation: this is what adopts pre-instanceId records
    // onto the stable key, and what lets the portal show a new hostname after a rename.
    $servers[$i]['instanceId'] = $iid;
    $servers[$i]['hostname'] = $host;
    $servers[$i]['status'] = 'ok';
    $servers[$i]['lastCheckIn'] = $now;
    $servers[$i]['lastPatchAt'] = $r['lastPatchAt'];
    $servers[$i]['rebootRequired'] = (bool)$r['rebootRequired'];
    $servers[$i]['error'] = $r['error'];
    $servers[$i]['updateCount'] = count($r['updates']);
    $servers[$i]['securityUpdateCount'] = $sec;
    $servers[$i]['lastReport'] = [
        'osVersion' => $r['osVersion'],
        'updates' => $r['updates'],
        'heldPackages' => $r['heldPackages'],
        'aptHistoryExcerpt' => $r['aptHistoryExcerpt'],
    ];
}
$tmp = $file . '.tmp';
file_put_contents($tmp, json_encode($servers, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
// 0640, not 0664: this file carries per-server agent tokens. nginx denies /data/ so it
// is not web-readable either way, but a world-readable secret is a world-readable secret.
chmod($tmp, 0640); chown($tmp, 'www-data'); chgrp($tmp, 'www-data');
rename($tmp, $file);
fwrite(STDOUT, "merged: $updated updated ($adopted adopted onto instanceId), $created created, " . count($servers) . " total\n");
MERGE;

$payload = base64_encode(json_encode($reports));
$mergeB64 = base64_encode($merge);
$remote = "echo $mergeB64 | base64 -d > /tmp/pcmerge.php && "
        . "echo $payload | base64 -d | php /tmp/pcmerge.php; rm -f /tmp/pcmerge.php";

fwrite(STDERR, "Applying to the portal...\n");
[$applyOut, $applyStatus] = run(escapeshellarg($fleetRun)
    . ' --hosts=' . escapeshellarg($portalHost)
    . ' --comment=' . escapeshellarg('patch compliance export: update portal data')
    . ' ' . escapeshellarg($remote));
fwrite(STDOUT, $applyOut . "\n");
exit(str_contains($applyOut, 'merged:') ? 0 : 1);
