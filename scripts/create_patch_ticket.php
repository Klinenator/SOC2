<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

const TICKETS_DB = 'rrs_tickets';
const VALID_PRIORITIES = ['low', 'normal', 'high', 'urgent'];

main($argv);

function main(array $argv): void
{
    $options = getopt('', [
        'create',
        'capture-live',
        'server:',
        'reviewer:',
        'requester:',
        'assignee-id:',
        'priority:',
        'category:',
        'subject:',
        'review-notes:',
        'deferred:',
        'upgradable-file:',
        'holds-file:',
        'history-file:',
        'help',
    ]);

    if (isset($options['help'])) {
        printUsage();
        exit(0);
    }

    $reviewer = trim((string)($options['reviewer'] ?? ''));
    if ($reviewer === '') {
        fwrite(STDERR, "--reviewer is required.\n\n");
        printUsage();
        exit(1);
    }

    $server = trim((string)($options['server'] ?? gethostname() ?: 'unknown-server'));
    $requester = normalizeUsername((string)($options['requester'] ?? getenv('USER') ?: ''));
    if ($requester === '') {
        fwrite(STDERR, "Could not determine requester username. Pass --requester.\n");
        exit(1);
    }

    $priority = strtolower(trim((string)($options['priority'] ?? 'high')));
    if (!in_array($priority, VALID_PRIORITIES, true)) {
        $priority = 'high';
    }

    $category = trim((string)($options['category'] ?? 'Server Patching'));
    $reviewNotes = trim((string)($options['review-notes'] ?? ''));
    $deferred = trim((string)($options['deferred'] ?? ''));
    $captureLive = isset($options['capture-live']);

    $upgradable = collectSection('Upgradable packages', $captureLive, $options['upgradable-file'] ?? null, [
        'apt',
        'list',
        '--upgradable',
    ]);
    $holds = collectSection('Held packages', $captureLive, $options['holds-file'] ?? null, [
        'apt-mark',
        'showhold',
    ]);
    $history = collectHistory($captureLive, $options['history-file'] ?? null);

    $subject = trim((string)($options['subject'] ?? sprintf(
        'Monthly patch review - %s - %s',
        $server,
        date('Y-m-d')
    )));

    $description = buildDescription([
        'server' => $server,
        'reviewer' => $reviewer,
        'requester' => $requester,
        'priority' => $priority,
        'category' => $category,
        'reviewNotes' => $reviewNotes,
        'deferred' => $deferred,
        'upgradable' => $upgradable,
        'holds' => $holds,
        'history' => $history,
    ]);

    if (!isset($options['create'])) {
        echo "Preview only. No ticket created.\n\n";
        echo "Subject: {$subject}\n";
        echo "Priority: {$priority}\n";
        echo "Category: {$category}\n";
        echo "Requester: {$requester}\n";
        if (!empty($options['assignee-id'])) {
            echo "Assignee ID: " . trim((string)$options['assignee-id']) . "\n";
        }
        echo "\n{$description}\n";
        exit(0);
    }

    $db = ticketsDb();
    $requesterUser = dbOne($db, 'SELECT * FROM users WHERE username = ?', [$requester]);
    if (!$requesterUser) {
        dbExec($db, "INSERT INTO users (username, role, is_active) VALUES (?, 'requester', 1)", [$requester]);
        $requesterUser = dbOne($db, 'SELECT * FROM users WHERE username = ?', [$requester]);
    }
    if (!$requesterUser) {
        throw new RuntimeException("Could not create or load requester {$requester}");
    }

    $assigneeId = trim((string)($options['assignee-id'] ?? ''));
    if ($assigneeId === '') {
        $assigneeId = null;
    }

    $db->begin_transaction();
    try {
        $tmpKey = 'TMP-' . bin2hex(random_bytes(8));
        dbExec(
            $db,
            "INSERT INTO tickets (ticket_key, subject, description, status, priority, category, requester_user_id, assignee_user_id)
             VALUES (?, ?, ?, 'new', ?, ?, ?, ?)",
            [$tmpKey, $subject, $description, $priority, ($category !== '' ? $category : null), (string)$requesterUser['id'], $assigneeId]
        );

        $ticketId = (int)$db->insert_id;
        if ($ticketId <= 0) {
            throw new RuntimeException('Insert did not return a valid ticket ID.');
        }

        $ticketKey = 'RRS-' . str_pad((string)$ticketId, 6, '0', STR_PAD_LEFT);
        dbExec($db, 'UPDATE tickets SET ticket_key = ? WHERE ticket_key = ?', [$ticketKey, $tmpKey]);
        $db->commit();

        echo "Created ticket {$ticketKey}\n";
        echo "Subject: {$subject}\n";
        exit(0);
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}

function printUsage(): void
{
    echo <<<TXT
Usage:
  php scripts/create_patch_ticket.php --reviewer "Sean Kline" [options]

Default behavior is preview-only. Add --create to insert a ticket into Tickets.

Options:
  --create                 Create the ticket in rrs_tickets instead of previewing
  --capture-live           Run apt commands on this server to capture package state
  --server NAME            Server name to include in the ticket
  --reviewer NAME          Human reviewer name (required)
  --requester USERNAME     Ticket requester username in Tickets
  --assignee-id ID         Optional assignee user ID in Tickets
  --priority VALUE         low|normal|high|urgent (default: high)
  --category VALUE         Ticket category (default: Server Patching)
  --subject VALUE          Override generated subject
  --review-notes TEXT      Reviewer summary / what was checked
  --deferred TEXT          Note any deferred/held items and why
  --upgradable-file PATH   Read apt list output from a file
  --holds-file PATH        Read apt-mark showhold output from a file
  --history-file PATH      Read apt history snippet from a file
  --help                   Show this help

Examples:
  php scripts/create_patch_ticket.php --reviewer "Sean Kline" --capture-live
  php scripts/create_patch_ticket.php --reviewer "Sean Kline" --capture-live --create --requester SKLINE
  php scripts/create_patch_ticket.php --reviewer "Sean Kline" --upgradable-file /tmp/upgradable.txt --history-file /tmp/apt-history.txt
TXT;
}

function normalizeUsername(string $value): string
{
    return strtoupper(trim($value));
}

function collectSection(string $label, bool $captureLive, ?string $filePath, ?array $command): string
{
    if ($filePath) {
        $content = @file_get_contents($filePath);
        if ($content === false) {
            throw new RuntimeException("Could not read {$label} file: {$filePath}");
        }
        return trim($content);
    }

    if (!$captureLive || $command === null) {
        return '';
    }

    return runCommand($command);
}

function collectHistory(bool $captureLive, ?string $filePath): string
{
    if ($filePath) {
        $content = @file_get_contents($filePath);
        if ($content === false) {
            throw new RuntimeException("Could not read Recent apt history file: {$filePath}");
        }
        return trim($content);
    }

    if (!$captureLive) {
        return '';
    }

    $historyPath = '/var/log/apt/history.log';
    if (!is_readable($historyPath)) {
        return '';
    }

    $content = @file($historyPath, FILE_IGNORE_NEW_LINES);
    if (!is_array($content) || !$content) {
        return '';
    }

    return trim(implode("\n", array_slice($content, -80)));
}

function runCommand(array $command): string
{
    $cmd = implode(' ', array_map('escapeshellarg', $command)) . ' 2>&1';
    $output = shell_exec($cmd);
    return trim((string)$output);
}

function buildDescription(array $ctx): string
{
    $lines = [
        'Monthly patch review created by automation.',
        '',
        'Review date: ' . date('Y-m-d H:i:s'),
        'Server: ' . $ctx['server'],
        'Reviewer: ' . $ctx['reviewer'],
        'Requester username: ' . $ctx['requester'],
        '',
        'Review summary:',
        $ctx['reviewNotes'] !== '' ? $ctx['reviewNotes'] : 'Reviewed pending packages, held packages, and recent apt activity.',
        '',
        'Deferred / held items:',
        $ctx['deferred'] !== '' ? $ctx['deferred'] : 'None noted.',
        '',
        'Upgradable packages:',
        fencedBlock($ctx['upgradable'] !== '' ? $ctx['upgradable'] : 'No package list captured.'),
        '',
        'Held packages:',
        fencedBlock($ctx['holds'] !== '' ? $ctx['holds'] : 'No held packages reported.'),
        '',
        'Recent apt history:',
        fencedBlock($ctx['history'] !== '' ? $ctx['history'] : 'No apt history snippet captured.'),
        '',
        'Audit evidence checklist:',
        '- Confirm the review date is correct',
        '- Attach any screenshots or exports if needed',
        '- Note exceptions or deferrals before closing',
    ];

    return implode("\n", $lines);
}

function fencedBlock(string $content): string
{
    return "```\n" . trim($content) . "\n```";
}

function ticketsDb(): mysqli
{
    $configPath = '/var/lib/php-fpm/config.php';
    if (!file_exists($configPath)) {
        throw new RuntimeException("Tickets DB config not found at {$configPath}. Run without --create for preview mode.");
    }

    require_once $configPath;
    if (!function_exists('connect')) {
        throw new RuntimeException('connect() is not available from /var/lib/php-fpm/config.php');
    }

    $db = connect();
    if (!($db instanceof mysqli)) {
        throw new RuntimeException('connect() did not return mysqli');
    }
    if ($db->connect_errno) {
        throw new RuntimeException('MySQL connect failed: ' . $db->connect_error);
    }
    if (!$db->select_db(TICKETS_DB)) {
        throw new RuntimeException('Could not select database ' . TICKETS_DB . ': ' . $db->error);
    }
    $db->set_charset('utf8mb4');
    return $db;
}

function dbOne(mysqli $db, string $sql, array $params): ?array
{
    $rows = dbAll($db, $sql, $params);
    return $rows[0] ?? null;
}

function dbAll(mysqli $db, string $sql, array $params): array
{
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Prepare failed: ' . $db->error);
    }
    bindParams($stmt, $params);
    if (!$stmt->execute()) {
        $err = $stmt->error ?: $db->error;
        $stmt->close();
        throw new RuntimeException('Execute failed: ' . $err);
    }
    $res = $stmt->get_result();
    $out = [];
    while ($row = $res->fetch_assoc()) {
        $out[] = $row;
    }
    $stmt->close();
    return $out;
}

function dbExec(mysqli $db, string $sql, array $params): int
{
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Prepare failed: ' . $db->error);
    }
    bindParams($stmt, $params);
    if (!$stmt->execute()) {
        $err = $stmt->error ?: $db->error;
        $stmt->close();
        throw new RuntimeException('Execute failed: ' . $err);
    }
    $affected = $stmt->affected_rows;
    $stmt->close();
    return $affected;
}

function bindParams(mysqli_stmt $stmt, array $params): void
{
    if (!$params) {
        return;
    }
    $types = str_repeat('s', count($params));
    $normalized = array_map(static fn($v) => $v === null ? null : (string)$v, $params);
    $stmt->bind_param($types, ...$normalized);
}
