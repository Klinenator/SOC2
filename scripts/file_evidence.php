<?php
declare(strict_types=1);

/**
 * file_evidence.php — file an evidence record from a script or the command line.
 *
 * THIS IS ONE OF EXACTLY TWO WAYS TO FILE EVIDENCE, AND BOTH RUN THE SAME CODE:
 *
 *   1. A person, from a browser  ->  POST multipart to api/evidence.php
 *   2. A script, on this host    ->  this file
 *
 * Both call evidence_create() in api/evidence_store.php. That function is the only place that
 * validates an artifact, writes it into uploads/, appends to data/evidence.json and links the
 * record into controls.json / audit_tests.json. Adding a third way means adding another caller
 * of evidence_create(), not another copy of the sequence.
 *
 * WHERE THIS RUNS, AND WHY IT MATTERS
 *
 * On the portal host (ip.rrsaccess.com), as the www-data user, because that is who php-fpm
 * runs as and therefore who must own what lands in data/ and uploads/:
 *
 *   cd /var/www/SOC2
 *   sudo -u www-data php scripts/file_evidence.php --file=... --controls=CC7.3 --description='...'
 *
 * It does NOT go over the network and does NOT authenticate, because it is not a client: it is
 * the portal's own code writing the portal's own files, on the portal's own host, as the
 * portal's own user. That is deliberate. Every /api/*.php sits behind Google OAuth
 * (auth_request), which is correct and must stay -- this host also serves mail and webmail. A
 * token endpoint that let scripts bypass that gate would trade a real authentication control
 * for a convenience. Running locally needs no such trade.
 *
 * WHAT IT DELIBERATELY WILL NOT DO
 *
 * Close the task the evidence belongs to. Filing an artifact is not reviewing it, and a script
 * that does both means nobody looked. Attach the evidence, then close the task in the portal.
 *
 * OPTIONS
 *   --file=PATH        the artifact. Required.
 *   --controls=IDS     comma-separated control ids, e.g. CC7.3 or CC7.1,CC7.2
 *   --audit-tests=IDS  comma-separated audit test ids
 *                      (at least one of --controls / --audit-tests is required)
 *   --description=TEXT required. Put the review date and any remediation ticket numbers here;
 *                      that is what data/evidence_requirements.json asks for.
 *   --date=YYYY-MM-DD  evidence date. Default: today.
 *   --source=TEXT      what produced it, e.g. scripts/export_patch_compliance.php
 *   --owner=ID         default skline
 *   --apply            actually write. Without it nothing changes.
 *
 * EXAMPLE — the monthly CC7.3 patch compliance evidence:
 *
 *   sudo -u www-data php scripts/file_evidence.php \
 *     --file=/tmp/patch-compliance-2026-08-27.csv \
 *     --controls=CC7.3 \
 *     --date=2026-08-27 \
 *     --source=scripts/export_patch_compliance.php \
 *     --description='Monthly patch compliance export, reviewed 2026-08-27. 0 pending security
 *                     updates across 10 servers. Remediation: RRS-000155.' \
 *     --apply
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

// Print the block comment above for --help, so the documentation cannot drift from the tool.
function usage(int $code): never
{
    $src = (string)file_get_contents(__FILE__);
    if (preg_match('#/\*\*(.+?)\*/#s', $src, $m)) {
        fwrite($code === 0 ? STDOUT : STDERR, preg_replace('/^\s*\* ?/m', '', trim($m[1])) . "\n");
    }
    exit($code);
}

$options = getopt('', [
    'file:', 'controls::', 'audit-tests::', 'description:', 'date::',
    'source::', 'owner::', 'apply', 'help',
]);
if (isset($options['help'])) usage(0);
if (empty($options['file']) || empty($options['description'])) {
    fwrite(STDERR, "--file and --description are both required. Run with --help.\n");
    exit(1);
}

// api/evidence_store.php defines DATA_DIR and UPLOADS_DIR relative to api/, so requiring it
// from anywhere inside the checkout resolves to the right place.
require_once __DIR__ . '/../api/evidence_store.php';

/**
 * Refuse to run somewhere the write cannot land, and say which of the two reasons it is.
 * "Nothing happened" is how data/evidence.json stayed absent for months.
 */
function preflight(bool $apply): void
{
    $user = get_current_user() ?: (string)(posix_getpwuid(posix_geteuid())['name'] ?? 'unknown');
    fwrite(STDOUT, "running as   : $user\n");
    fwrite(STDOUT, "data dir     : " . DATA_DIR . "\n");
    fwrite(STDOUT, "uploads dir  : " . UPLOADS_DIR . "\n");

    foreach ([DATA_DIR => 'data', UPLOADS_DIR => 'uploads'] as $dir => $label) {
        if (!is_dir($dir)) {
            fwrite(STDERR, "\nABORT: $label directory does not exist at $dir.\n");
            fwrite(STDERR, "This script must run on the portal host, from the checkout root.\n");
            exit(1);
        }
        if ($apply && !is_writable($dir)) {
            fwrite(STDERR, "\nABORT: $label directory is not writable by '$user'.\n");
            fwrite(STDERR, "Run it as the user php-fpm runs as, so the portal can read back what you write:\n");
            fwrite(STDERR, "  sudo -u www-data php scripts/file_evidence.php ...\n");
            exit(1);
        }
    }
}

$apply = array_key_exists('apply', $options);
preflight($apply);

$path = (string)$options['file'];
if (!is_readable($path)) {
    fwrite(STDERR, "\nABORT: cannot read artifact: $path\n");
    exit(1);
}

$fields = [
    'sourcePath'   => $path,
    'originalName' => basename($path),
    'isUpload'     => false,
    'description'  => (string)$options['description'],
    'source'       => (string)($options['source'] ?? ''),
    'owner'        => (string)($options['owner'] ?? 'skline'),
    'evidenceDate' => (string)($options['date'] ?? date('Y-m-d')),
    'controlIds'   => array_values(array_filter(array_map('trim', explode(',', (string)($options['controls'] ?? ''))))),
    'auditTestIds' => array_values(array_filter(array_map('trim', explode(',', (string)($options['audit-tests'] ?? ''))))),
];

fwrite(STDOUT, "\nartifact     : {$fields['originalName']} (" . (mime_content_type($path) ?: '?') . ', ' . filesize($path) . " bytes)\n");
fwrite(STDOUT, "controls     : " . (implode(', ', $fields['controlIds']) ?: '(none)') . "\n");
fwrite(STDOUT, "audit tests  : " . (implode(', ', $fields['auditTestIds']) ?: '(none)') . "\n");
fwrite(STDOUT, "evidence date: {$fields['evidenceDate']}\n");
fwrite(STDOUT, "description  : {$fields['description']}\n");

if (!$apply) {
    fwrite(STDOUT, "\nDry run. Nothing written. Re-run with --apply.\n");
    exit(0);
}

try {
    $record = evidence_create($fields);
} catch (EvidenceStoreError $e) {
    fwrite(STDERR, "\nREFUSED (" . $e->status() . "): " . $e->getMessage() . "\n");
    exit(1);
}

fwrite(STDOUT, "\nFiled.\n");
fwrite(STDOUT, "  id         : {$record['id']}\n");
fwrite(STDOUT, "  stored as  : uploads/{$record['storedName']}\n");
fwrite(STDOUT, "  linked to  : " . (implode(', ', array_merge($record['controlIds'], $record['auditTestIds'])) ?: 'nothing') . "\n");
fwrite(STDOUT, "  records now: " . count(read_json('evidence.json')) . "\n");
fwrite(STDOUT, "\nevidence_create() read the record back before returning, so the store holds it.\n");
fwrite(STDOUT, "Now review it in the portal and close the task yourself -- this script does not\n");
fwrite(STDOUT, "close tasks, on purpose.\n");
exit(0);
