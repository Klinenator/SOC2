<?php
require_once __DIR__ . '/helpers.php';
cors();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') error_response('Method not allowed', 405);

$controls = read_json('controls.json');
$tasks    = read_json('tasks.json');
$evidence = read_json('evidence.json');
$policies = read_json('policies.json');
$signatures = read_json('signatures.json');
$workpapers = read_json('audit_tests.json');
$evidenceRequirements = evaluate_evidence_requirements(null, $evidence);

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
$policyHealth = policy_health($policies, $signatures);
$workpaperSummary = workpaper_summary($workpapers);

// Readiness measures the audit package, not management's assertion alone.
$controlScore = $total ? $compliant / $total : 0;
$evidenceTotal = array_sum($evidenceRequirements['summary']);
$evidenceScore = $evidenceTotal ? $evidenceRequirements['summary']['on_track'] / $evidenceTotal : 0;
$workpaperDone = ($workpaperSummary['ready'] ?? 0) + ($workpaperSummary['tested'] ?? 0) + ($workpaperSummary['not_applicable'] ?? 0);
$workpaperScore = $workpaperSummary['total'] ? $workpaperDone / $workpaperSummary['total'] : 0;
$policyScore = $policyHealth['total'] ? $policyHealth['approved'] / $policyHealth['total'] : 0;
$taskScore = count($tasks) ? (count($tasks) - $openTasks) / count($tasks) : 0;
$readinessScore = round(100 * (($controlScore * .20) + ($workpaperScore * .35) + ($evidenceScore * .25) + ($policyScore * .15) + ($taskScore * .05)));

json_response([
    'readinessScore'  => $readinessScore,
    'readinessBreakdown' => [
        'controls' => round($controlScore * 100), 'workpapers' => round($workpaperScore * 100),
        'evidence' => round($evidenceScore * 100), 'policies' => round($policyScore * 100), 'tasks' => round($taskScore * 100),
    ],
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
        'requirements' => $evidenceRequirements['summary'],
    ],
    'policies' => [
        'total'    => count($policies),
        'byStatus' => $policyCounts,
        'health' => $policyHealth,
    ],
    'workpapers' => $workpaperSummary,
]);
