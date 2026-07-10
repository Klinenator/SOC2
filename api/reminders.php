<?php
// SOC 2 management-control reminder engine.
//
//   CLI (cron):   php api/reminders.php [--date=YYYY-MM-DD] [--force]
//   Web preview:  GET api/reminders.php?preview=1   -> JSON of what today would send
//
// Reminds the task OWNER ahead of / at due date and weekly while overdue, escalating
// a CC to the CTO once sufficiently overdue. Dedupe via data/reminder_log.json.
//
// DRY-RUN (config.dryRun=true): sends ONE digest to config.testRecipient of what
// *would* be sent, and does NOT write the log (so nothing is "consumed"). Flip
// dryRun=false only after owner emails in data/people.json are confirmed.

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/mail.php';

$IS_CLI = php_sapi_name() === 'cli';

function cfg() { return read_json('reminders_config.json'); }

function people_by_id() {
    $out = [];
    foreach (read_json('people.json') as $p) $out[$p['id']] = $p;
    return $out;
}

function is_done($status) {
    return in_array(strtolower((string)$status), ['done','complete','completed','closed','resolved'], true);
}

// Days from $today (Y-m-d) to $due (Y-m-d); negative = overdue. Null if no date.
function days_until($today, $due) {
    if (!$due) return null;
    $a = DateTime::createFromFormat('Y-m-d', $today);
    $b = DateTime::createFromFormat('Y-m-d', $due);
    if (!$a || !$b) return null;
    $a->setTime(0,0,0); $b->setTime(0,0,0);
    return (int)$a->diff($b)->format('%r%a');
}

// Which milestone (if any) fires for this task today. Returns [key, label] or null.
function milestone_for($daysUntil, $today, $c) {
    if ($daysUntil === null) return null;
    if ($daysUntil > 0 && in_array($daysUntil, $c['leadDays'] ?? [], true)) {
        return ["d{$daysUntil}", "due in {$daysUntil} day" . ($daysUntil === 1 ? '' : 's')];
    }
    if ($daysUntil === 0) return ['d0', 'due today'];
    if ($daysUntil < 0) {
        // overdue: fire once per ISO week, on configured day-of-week
        $dow = (int)date('N', strtotime($today)); // 1=Mon..7=Sun
        if ($dow === (int)($c['overdueWeeklyDow'] ?? 1)) {
            return ['ov-' . date('o-\WW', strtotime($today)), abs($daysUntil) . ' days overdue'];
        }
    }
    return null;
}

function compute($today = null) {
    $c = cfg();
    $today = $today ?: date('Y-m-d');
    $people = people_by_id();
    $tasks = read_json('tasks.json');
    $items = [];
    foreach ($tasks as $t) {
        if (empty($t['reminders'])) continue;
        if (is_done($t['status'] ?? '')) continue;
        $d = days_until($today, $t['dueDate'] ?? '');
        $ms = milestone_for($d, $today, $c);
        if (!$ms) continue;
        $owner = $people[$t['ownerId'] ?? ''] ?? null;
        $escalate = ($d !== null && $d <= -(int)($c['escalateAfterDays'] ?? 7));
        $items[] = [
            'taskId'      => $t['id'],
            'title'       => $t['title'],
            'controlId'   => $t['controlId'] ?? '',
            'recurrence'  => $t['recurrence'] ?? 'once',
            'dueDate'     => $t['dueDate'] ?? '',
            'daysUntil'   => $d,
            'milestone'   => $ms[0],
            'milestoneLabel' => $ms[1],
            'ownerId'     => $t['ownerId'] ?? '',
            'ownerName'   => $owner['name'] ?? '(unassigned)',
            'ownerEmail'  => $owner['email'] ?? '',
            'ownerConfirmed' => (bool)($owner['confirmed'] ?? false),
            'escalate'    => $escalate,
        ];
    }
    return [$c, $today, $items];
}

function log_key($i) { return $i['taskId'] . '|' . $i['milestone']; }

function email_html($i, $c) {
    $portal = $c['portalUrl'] ?? '';
    $when = $i['daysUntil'] < 0
        ? "<strong style='color:#b00'>{$i['milestoneLabel']}</strong>"
        : "<strong>{$i['milestoneLabel']}</strong>";
    $h  = "<p>This is a SOC 2 compliance reminder.</p>";
    $h .= "<p><strong>" . htmlspecialchars($i['title']) . "</strong><br>";
    $h .= "Control: " . htmlspecialchars($i['controlId']) . " &middot; ";
    $h .= "Recurrence: " . htmlspecialchars($i['recurrence']) . "<br>";
    $h .= "Due: " . htmlspecialchars($i['dueDate']) . " &mdash; {$when}</p>";
    $h .= "<p>Owner: " . htmlspecialchars($i['ownerName']) . "</p>";
    $h .= "<p>Please complete the activity and save the evidence in the SOC portal";
    if ($portal) $h .= " (<a href='{$portal}'>{$portal}</a>)";
    $h .= ". Mark the task done there once evidence is uploaded.</p>";
    $h .= "<p style='color:#888;font-size:12px'>Sent by the RRS SOC compliance reminder system.</p>";
    return $h;
}

function run_cli($argv) {
    $date = null; $force = false;
    foreach ($argv as $a) {
        if (strpos($a, '--date=') === 0) $date = substr($a, 7);
        if ($a === '--force') $force = true;
    }
    [$c, $today, $items] = compute($date);
    $people = people_by_id();
    $dry = !empty($c['dryRun']);
    $escEmail = $people[$c['escalateToId'] ?? '']['email'] ?? '';

    $log = read_json('reminder_log.json');
    $sent = array_fill_keys(array_map(fn($r) => $r['taskId'] . '|' . $r['milestone'], $log), true);

    $toSend = [];
    foreach ($items as $i) {
        if (!$force && !$dry && isset($sent[log_key($i)])) continue; // already sent this milestone
        $toSend[] = $i;
    }

    echo "SOC reminders — $today — " . ($dry ? "DRY-RUN" : "LIVE") . "\n";
    echo count($toSend) . " reminder(s) to process (of " . count($items) . " firing today)\n";

    if ($dry) {
        // one digest to the test recipient; do NOT consume the log
        if (!$toSend) { echo "nothing firing today; no digest sent\n"; return; }
        $rows = '';
        foreach ($toSend as $i) {
            echo sprintf("  would email: %-52s due %s (%s) -> %s <%s>%s%s\n",
                substr($i['title'], 0, 52), $i['dueDate'], $i['milestoneLabel'],
                $i['ownerName'], $i['ownerEmail'] ?: '?',
                $i['ownerEmail'] ? '' : ' [NO EMAIL]',
                $i['escalate'] ? ' [+CC CTO]' : '');
            $warn = $i['ownerEmail'] ? '' : " <span style='color:#b00'>[no owner email]</span>";
            $unc  = ($i['ownerEmail'] && !$i['ownerConfirmed']) ? " <span style='color:#b60'>[unconfirmed]</span>" : '';
            $rows .= "<tr><td>" . htmlspecialchars($i['title']) . "</td><td>{$i['controlId']}</td>"
                   . "<td>{$i['dueDate']}</td><td>{$i['milestoneLabel']}</td>"
                   . "<td>" . htmlspecialchars($i['ownerName']) . " &lt;" . htmlspecialchars($i['ownerEmail'] ?: '?') . "&gt;$warn$unc</td>"
                   . "<td>" . ($i['escalate'] ? 'CC CTO' : '') . "</td></tr>";
        }
        $html = "<p><strong>[DRY-RUN]</strong> The SOC reminder system WOULD send the "
              . "following " . count($toSend) . " email(s) today ($today). No real "
              . "owner was emailed. Flip <code>dryRun=false</code> after confirming owner emails.</p>"
              . "<table border='1' cellpadding='6' cellspacing='0'>"
              . "<tr><th>Task</th><th>Control</th><th>Due</th><th>When</th><th>Owner</th><th>Escalation</th></tr>"
              . $rows . "</table>";
        [$ok, $err] = send_mail($c['testRecipient'], "[DRY-RUN] SOC reminders for $today", $html);
        echo $ok ? "digest sent to {$c['testRecipient']}\n" : "digest FAILED: $err\n";
        return;
    }

    // LIVE
    foreach ($toSend as $i) {
        if (!$i['ownerEmail']) { echo "SKIP (no owner email): {$i['title']}\n"; continue; }
        $cc = $i['escalate'] && $escEmail && $escEmail !== $i['ownerEmail'] ? [$escEmail] : [];
        $subj = "[SOC] {$i['title']} — {$i['milestoneLabel']}";
        [$ok, $err] = send_mail($i['ownerEmail'], $subj, email_html($i, $c), $cc);
        if ($ok) {
            $log[] = ['taskId' => $i['taskId'], 'milestone' => $i['milestone'], 'sentAt' => date('c'), 'to' => $i['ownerEmail']];
            echo "sent: {$i['title']} -> {$i['ownerEmail']}" . ($cc ? " (cc {$cc[0]})" : '') . "\n";
        } else {
            echo "FAILED: {$i['title']} -> {$i['ownerEmail']}: $err\n";
        }
    }
    write_json('reminder_log.json', $log);
}

if ($IS_CLI) {
    run_cli($argv);
} else {
    cors();
    // Web: preview only (no send, no log write)
    [$c, $today, $items] = compute($_GET['date'] ?? null);
    json_response(['date' => $today, 'dryRun' => !empty($c['dryRun']), 'count' => count($items), 'items' => $items]);
}
