<?php
declare(strict_types=1);

/**
 * Import a completed weekly security log review (CC7.3).
 *
 * The review sheet is produced and sealed by rrs-log-review in the rrs-security
 * repo. This end does three things the reviewer should not have to do by hand:
 * files the artifact as evidence, links it to the control, and closes the open
 * weekly task so the portal — not an editable document — is what stamps the
 * review date. That server-side timestamp is the thing the last audit asked for
 * and an uploaded Word file could never prove.
 *
 * Run as the web user so the files stay writable by the portal:
 *
 *   sudo -u www-data php scripts/import_log_review.php \
 *       --file=/tmp/log-review-20260817/REVIEW.md --reviewer="Sean Kline"
 *
 * Options:
 *   --file=PATH        Sealed REVIEW.md (or REVIEW.pdf) to file as evidence
 *   --reviewer=NAME    Overrides the name read from the sheet
 *   --control=ID       Control to link (default CC7.3)
 *   --task-id=ID       Close a specific task instead of matching by title
 *   --no-task          File the evidence without closing any task
 *   --dry-run          Report what would change and write nothing
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

require_once __DIR__ . '/../api/helpers.php';

const DEFAULT_CONTROL = 'CC7.3';
const TASK_TITLE_MATCH = 'review security logs';
const PENDING_MARKER = '_pending_';

exit(main($argv));

function main(array $argv): int
{
    $options = getopt('', ['file:', 'reviewer:', 'control:', 'task-id:', 'no-task', 'dry-run']);
    $dryRun = isset($options['dry-run']);

    $file = (string)($options['file'] ?? '');
    if ($file === '' || !is_file($file)) {
        fwrite(STDERR, "--file must point at the sealed review sheet.\n");
        return 2;
    }
    $control = (string)($options['control'] ?? DEFAULT_CONTROL);

    try {
        $sheet = readSheet($file);
        $reviewer = (string)($options['reviewer'] ?? $sheet['reviewer']);
        if (trim($reviewer) === '') {
            throw new RuntimeException('No reviewer name in the sheet; pass --reviewer.');
        }

        report('Review window', $sheet['window']);
        report('Reviewed by', $reviewer);
        report('Review date', $sheet['reviewDate']);
        report('Open items evaluated', (string)$sheet['itemCount']);
        report('Control', $control);

        if ($dryRun) {
            fwrite(STDOUT, "\nDry run — nothing written.\n");
            return 0;
        }

        assertWritable();

        $record = storeEvidence($file, $sheet, $reviewer, $control);
        report('Evidence id', $record['id']);

        if (!isset($options['no-task'])) {
            $task = closeWeeklyTask((string)($options['task-id'] ?? ''), $control);
            if ($task === null) {
                fwrite(STDOUT, "No matching open task found; evidence filed without closing one.\n");
            } else {
                report('Task closed', $task['title'] . ' (completed ' . $task['completedAt'] . ')');
                if (!empty($task['_next'])) {
                    report('Next occurrence', $task['_next']);
                }
            }
        }

        fwrite(STDOUT, "\nDone.\n");
        return 0;
    } catch (Throwable $exception) {
        fwrite(STDERR, 'ERROR: ' . $exception->getMessage() . "\n");
        return 1;
    }
}

/**
 * A sheet with unanswered items is not evidence of a review, so it never becomes
 * an evidence record here either.
 */
function readSheet(string $path): array
{
    $isPdf = strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'pdf';
    $markdownPath = $isPdf ? dirname($path) . '/REVIEW.md' : $path;

    if (!is_file($markdownPath)) {
        throw new RuntimeException('Cannot find REVIEW.md next to ' . basename($path) . ' to verify it was completed.');
    }
    $markdown = (string)file_get_contents($markdownPath);

    if (str_contains($markdown, PENDING_MARKER)) {
        throw new RuntimeException('This sheet still has unanswered items. Finish it and run finalize first.');
    }

    verifyManifest($path);

    $reviewDate = extractField($markdown, 'Review date (UTC, YYYY-MM-DD)');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $reviewDate)) {
        throw new RuntimeException('The sheet has no valid review date.');
    }

    preg_match('/^# Weekly security log review — (.+)$/m', $markdown, $windowMatch);

    return [
        'reviewer'   => extractField($markdown, 'Reviewed by'),
        'reviewDate' => $reviewDate,
        'window'     => trim($windowMatch[1] ?? 'unknown window'),
        'itemCount'  => preg_match_all('/^### LR-\d+ /m', $markdown),
        'escalation' => extractField($markdown, 'Escalated to management'),
    ];
}

/** If the bundle was sealed, what gets filed must be what was sealed. */
function verifyManifest(string $path): void
{
    $manifestPath = dirname($path) . '/MANIFEST.sha256';
    if (!is_file($manifestPath)) {
        fwrite(STDERR, "WARNING: no MANIFEST.sha256 beside this sheet; filing it unverified.\n");
        return;
    }
    $wanted = basename($path);
    foreach (file($manifestPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        [$hash, $name] = array_pad(preg_split('/\s+/', trim($line), 2) ?: [], 2, '');
        if ($name === $wanted) {
            if (!hash_equals($hash, hash_file('sha256', $path))) {
                throw new RuntimeException($wanted . ' does not match its hash in MANIFEST.sha256.');
            }
            return;
        }
    }
    fwrite(STDERR, 'WARNING: ' . $wanted . " is not listed in MANIFEST.sha256.\n");
}

function extractField(string $markdown, string $label): string
{
    $quoted = preg_quote($label, '/');
    if (!preg_match('/^-\s+\*\*' . $quoted . ':\*\*\s*(.*)$/m', $markdown, $matches)) {
        return '';
    }
    return trim((string)preg_replace('/<!--.*?-->/s', '', $matches[1]));
}

function assertWritable(): void
{
    foreach ([DATA_DIR, UPLOADS_DIR] as $directory) {
        if (!is_writable($directory)) {
            throw new RuntimeException($directory . ' is not writable. Run this as the web user: sudo -u www-data php ' . $GLOBALS['argv'][0] . ' ...');
        }
    }
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        throw new RuntimeException('Do not run this as root — the files it writes must stay owned by the web user.');
    }
}

function storeEvidence(string $path, array $sheet, string $reviewer, string $control): array
{
    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION)) ?: 'md';
    $storedName = uuid() . '.' . $extension;
    if (!copy($path, UPLOADS_DIR . $storedName)) {
        throw new RuntimeException('Cannot copy the sheet into uploads/.');
    }
    chmod(UPLOADS_DIR . $storedName, 0640);

    $record = [
        'id'           => uuid(),
        'filename'     => 'log-review-' . str_replace('-', '', $sheet['reviewDate']) . '.' . $extension,
        'storedName'   => $storedName,
        'size'         => filesize($path) ?: 0,
        'mimeType'     => $extension === 'pdf' ? 'application/pdf' : 'text/plain',
        'description'  => sprintf(
            'Weekly security log review (%s) — %d item(s) evaluated with disposition and closure. Escalation: %s.',
            $sheet['window'],
            $sheet['itemCount'],
            $sheet['escalation'] !== '' ? $sheet['escalation'] : 'none recorded'
        ),
        'source'       => 'rrs-log-review collector (CloudWatch Logs, GuardDuty)',
        'owner'        => $reviewer,
        'evidenceDate' => $sheet['reviewDate'],
        'controlIds'   => [$control],
        'auditTestIds' => [],
        'uploadedAt'   => date('Y-m-d H:i:s'),
    ];

    $evidence = read_json('evidence.json');
    $evidence[] = $record;
    write_json('evidence.json', $evidence);

    $controls = read_json('controls.json');
    foreach ($controls as &$candidate) {
        if (($candidate['id'] ?? '') === $control) {
            $candidate['evidenceIds'][] = $record['id'];
            $candidate['evidenceIds'] = array_values(array_unique($candidate['evidenceIds']));
        }
    }
    unset($candidate);
    write_json('controls.json', $controls);

    return $record;
}

/**
 * Closing the task here is the point: `completedAt` is written by the server, so
 * the review has a date nobody can edit after the fact.
 */
function closeWeeklyTask(string $taskId, string $control): ?array
{
    $tasks = read_json('tasks.json');
    $closed = null;

    foreach ($tasks as &$task) {
        $matches = $taskId !== ''
            ? ($task['id'] ?? '') === $taskId
            : ($task['controlId'] ?? '') === $control
                && str_contains(strtolower((string)($task['title'] ?? '')), TASK_TITLE_MATCH);

        if (!$matches || ($task['status'] ?? '') !== 'open') {
            continue;
        }

        $task['status'] = 'closed';
        $task['completedAt'] = date('Y-m-d H:i:s');
        $closed = $task;
        break;
    }
    unset($task);

    if ($closed === null) {
        return null;
    }

    $next = next_recurring_due($closed['dueDate'] ?? '', $closed['recurrence'] ?? 'once');
    if ($next !== '') {
        $seriesId = $closed['seriesId'] ?? $closed['id'];
        $alreadyOpen = array_filter(
            $tasks,
            static fn($candidate) => ($candidate['seriesId'] ?? '') === $seriesId && ($candidate['status'] ?? '') === 'open'
        );
        if (!$alreadyOpen) {
            $upcoming = $closed;
            $upcoming['id'] = uuid();
            $upcoming['seriesId'] = $seriesId;
            $upcoming['status'] = 'open';
            $upcoming['dueDate'] = $next;
            $upcoming['createdAt'] = date('Y-m-d');
            unset($upcoming['completedAt']);
            $tasks[] = $upcoming;
            $closed['_next'] = $next;
        }
    }

    write_json('tasks.json', array_values($tasks));
    return $closed;
}

function report(string $label, string $value): void
{
    fwrite(STDOUT, sprintf("%-22s %s\n", $label . ':', $value));
}
