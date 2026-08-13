<?php
/** Read-only export of the RRS ticket population for SOC 2 management testing. */
$options = getopt('', ['env::','since::','output::']);
$envPath = $options['env'] ?? (getenv('HOME') . '/.env');
$since = $options['since'] ?? '2026-01-01';
$output = $options['output'] ?? (__DIR__ . '/../data/change_population.json');
if (!is_file($envPath)) throw new RuntimeException('Environment file not found');
$env = [];
foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if ($line[0] === '#' || !str_contains($line, '=')) continue;
    [$key,$value] = explode('=', $line, 2); $env[trim($key)] = trim($value, " \t\n\r\0\x0B\"'");
}
$db = new mysqli($env['DB_HOST'], $env['DB_USER'], $env['DB_PASSWORD'], 'rrs_tickets', (int)($env['DB_PORT'] ?? 3306));
if ($db->connect_errno) throw new RuntimeException('Database connection failed');
$sql = "SELECT t.id,t.ticket_key,t.subject,t.status,t.priority,t.category,t.created_at,t.updated_at,t.closed_at,
        COUNT(c.id) comment_count,
        MAX(CASE WHEN LOWER(c.body) REGEXP 'approv' THEN 1 ELSE 0 END) has_approval,
        MAX(CASE WHEN LOWER(CONCAT_WS(' ',t.description,c.body)) REGEXP 'test|verif|validat' THEN 1 ELSE 0 END) has_testing,
        MAX(CASE WHEN LOWER(CONCAT_WS(' ',t.description,c.body)) REGEXP 'rollback|backout|revert' THEN 1 ELSE 0 END) has_rollback
        FROM tickets t LEFT JOIN ticket_comments c ON c.ticket_id=t.id
        WHERE t.created_at >= ? GROUP BY t.id ORDER BY t.id";
$stmt = $db->prepare($sql); $stmt->bind_param('s', $since); $stmt->execute(); $result = $stmt->get_result();
$records = [];
while ($row = $result->fetch_assoc()) {
    $text = strtolower(($row['subject'] ?? '') . ' ' . ($row['category'] ?? ''));
    $likelyChange = ($row['category'] ?? '') === 'Change Management' || preg_match('/deploy|production|provision|config|fix|add |update|remove|restrict|migrat|hardening/', $text);
    $records[] = [
        'ticketKey'=>$row['ticket_key'],'subject'=>$row['subject'],'status'=>$row['status'],'priority'=>$row['priority'],
        'category'=>$row['category'] ?: '', 'createdAt'=>$row['created_at'],'updatedAt'=>$row['updated_at'],'closedAt'=>$row['closed_at'],
        'commentCount'=>(int)$row['comment_count'],'hasApproval'=>(bool)$row['has_approval'],'hasTesting'=>(bool)$row['has_testing'],
        'hasRollback'=>(bool)$row['has_rollback'],'likelyChange'=>(bool)$likelyChange,
    ];
}
$payload = ['generatedAt'=>date(DATE_ATOM),'since'=>$since,'tickets'=>$records];
file_put_contents($output, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
chmod($output, 0660);
echo 'Exported ' . count($records) . " tickets without credential values or ticket descriptions.\n";
