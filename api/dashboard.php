<?php
require_once __DIR__ . '/helpers.php';
cors();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') error_response('Method not allowed', 405);

$controls = read_json('controls.json');
$tasks    = read_json('tasks.json');
$evidence = read_json('evidence.json');
$policies = read_json('policies.json');

// Controls summary
$statusCounts = ['not_started' => 0, 'in_progress' => 0, 'compliant' => 0, 'gap' => 0];
$categoryCounts = [];

foreach ($controls as $c) {
    $s = $c['status'] ?? 'not_started';
    $statusCounts[$s] = ($statusCounts[$s] ?? 0) + 1;
    $cat = $c['category'] ?? 'Other';
    if (!isset($categoryCounts[$cat])) {
        $categoryCounts[$cat] = ['name' => $c['categoryName'] ?? $cat, 'compliant' => 0, 'total' => 0];
    }
    $categoryCounts[$cat]['total']++;
    if ($s === 'compliant') $categoryCounts[$cat]['compliant']++;
}

$total = count($controls);
$compliant = $statusCounts['compliant'];
$readinessScore = $total > 0 ? round(($compliant / $total) * 100) : 0;

// Tasks summary
$openTasks = count(array_filter($tasks, fn($t) => $t['status'] === 'open'));
$overdueTasks = count(array_filter($tasks, function($t) {
    return $t['status'] === 'open' && !empty($t['dueDate']) && $t['dueDate'] < date('Y-m-d');
}));

// Upcoming due dates (next 30 days)
$upcoming = array_values(array_filter($tasks, function($t) {
    if ($t['status'] !== 'open' || empty($t['dueDate'])) return false;
    $days = (strtotime($t['dueDate']) - time()) / 86400;
    return $days >= 0 && $days <= 30;
}));
usort($upcoming, fn($a, $b) => strcmp($a['dueDate'], $b['dueDate']));
$upcoming = array_slice($upcoming, 0, 5);

// Policy summary
$policyCounts = ['draft' => 0, 'under_review' => 0, 'approved' => 0];
foreach ($policies as $p) {
    $s = $p['status'] ?? 'draft';
    $policyCounts[$s] = ($policyCounts[$s] ?? 0) + 1;
}

json_response([
    'readinessScore'  => $readinessScore,
    'controls'        => [
        'total'       => $total,
        'byStatus'    => $statusCounts,
        'byCategory'  => array_values($categoryCounts),
    ],
    'tasks' => [
        'total'    => count($tasks),
        'open'     => $openTasks,
        'overdue'  => $overdueTasks,
        'upcoming' => $upcoming,
    ],
    'evidence' => [
        'total' => count($evidence),
    ],
    'policies' => [
        'total'    => count($policies),
        'byStatus' => $policyCounts,
    ],
]);
