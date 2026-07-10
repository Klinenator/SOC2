<?php
// ICS calendar feed of SOC 2 management obligations. Subscribe once in Google
// Calendar; recurring tasks carry an RRULE so future occurrences appear, and each
// event has alarms at 30/14/7/1 days before due.
//
//   GET api/calendar.php                -> all reminder-enabled tasks
//   GET api/calendar.php?owner=<id>     -> only that owner's tasks
require_once __DIR__ . '/helpers.php';

function ics_escape($s) {
    return str_replace(["\\", "\n", ",", ";"], ["\\\\", "\\n", "\\,", "\\;"], (string)$s);
}

function rrule_for($recurrence) {
    switch ($recurrence) {
        case 'annual':    return 'RRULE:FREQ=YEARLY';
        case 'quarterly': return 'RRULE:FREQ=MONTHLY;INTERVAL=3';
        case 'monthly':   return 'RRULE:FREQ=MONTHLY';
        default:          return null; // once / onEvent -> single event
    }
}

$ownerFilter = $_GET['owner'] ?? null;
$people = [];
foreach (read_json('people.json') as $p) $people[$p['id']] = $p;
$tasks = read_json('tasks.json');

$lines = [
    'BEGIN:VCALENDAR',
    'VERSION:2.0',
    'PRODID:-//RRS//SOC Compliance//EN',
    'CALSCALE:GREGORIAN',
    'METHOD:PUBLISH',
    'X-WR-CALNAME:RRS SOC 2 Compliance',
    'X-WR-TIMEZONE:America/New_York',
];
$now = gmdate('Ymd\THis\Z');

foreach ($tasks as $t) {
    if (empty($t['reminders']) || empty($t['dueDate'])) continue;
    if (in_array(strtolower($t['status'] ?? ''), ['done','complete','completed','closed','resolved'], true)) continue;
    if ($ownerFilter && ($t['ownerId'] ?? '') !== $ownerFilter) continue;

    $due = str_replace('-', '', $t['dueDate']);          // YYYYMMDD
    $dtend = date('Ymd', strtotime($t['dueDate'] . ' +1 day')); // all-day exclusive end
    $owner = $people[$t['ownerId'] ?? ''] ?? null;
    $desc = 'Control ' . ($t['controlId'] ?? '') . ' \\, ' . ($t['recurrence'] ?? 'once')
          . ' \\, Owner: ' . ($owner['name'] ?? 'unassigned') . '\\n'
          . ics_escape($t['description'] ?? '') . '\\n'
          . 'Complete and upload evidence in the SOC portal, then mark the task done.';

    $lines[] = 'BEGIN:VEVENT';
    $lines[] = 'UID:soc-' . $t['id'] . '@soc2.rrsaccess.com';
    $lines[] = 'DTSTAMP:' . $now;
    $lines[] = 'DTSTART;VALUE=DATE:' . $due;
    $lines[] = 'DTEND;VALUE=DATE:' . $dtend;
    $lines[] = 'SUMMARY:' . ics_escape('[SOC] ' . $t['title']);
    $lines[] = 'DESCRIPTION:' . $desc;
    $lines[] = 'CATEGORIES:SOC2,' . ics_escape($t['controlId'] ?? '');
    if ($rr = rrule_for($t['recurrence'] ?? 'once')) $lines[] = $rr;
    foreach ([30, 14, 7, 1] as $d) {
        $lines[] = 'BEGIN:VALARM';
        $lines[] = 'ACTION:DISPLAY';
        $lines[] = 'TRIGGER:-P' . $d . 'D';
        $lines[] = 'DESCRIPTION:' . ics_escape('[SOC] ' . $t['title'] . ' due in ' . $d . ' day' . ($d === 1 ? '' : 's'));
        $lines[] = 'END:VALARM';
    }
    $lines[] = 'END:VEVENT';
}
$lines[] = 'END:VCALENDAR';

// RFC 5545 line folding: wrap long lines at 73 octets, continuation prefixed with a space.
function ics_fold($line) {
    if (strlen($line) <= 73) return $line;
    $out = ''; $chunk = 73;
    for ($i = 0; $i < strlen($line); $i += $chunk) {
        $out .= ($i ? "\r\n " : '') . substr($line, $i, $chunk);
        $chunk = 72; // continuation lines are 1 shorter (leading space)
    }
    return $out;
}

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: inline; filename="rrs-soc2.ics"');
echo implode("\r\n", array_map('ics_fold', $lines)) . "\r\n";
