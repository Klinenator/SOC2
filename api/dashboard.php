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

// Per-team task counts. The dashboard scopes to one queue (IT / Business / HR) and the
// totals have to move with it, or the header says "19 open" while the list below shows
// eleven and the reader has to work out which number is lying.
$taskCategories = [];
foreach ($tasks as $t) {
    $cat = $t['category'] ?? 'business';
    if (!isset($taskCategories[$cat])) {
        $taskCategories[$cat] = ['id' => $cat, 'total' => 0, 'open' => 0, 'overdue' => 0];
    }
    $taskCategories[$cat]['total']++;
    if (($t['status'] ?? '') !== 'open') continue;
    $taskCategories[$cat]['open']++;
    if (!empty($t['dueDate']) && $t['dueDate'] < date('Y-m-d')) {
        $taskCategories[$cat]['overdue']++;
    }
}

// Outstanding work: anything open and already past due, plus the next 30 days.
//
// This used to require `$days >= 0`, so it showed only the future. With every open task
// currently overdue that produced "No upcoming deadlines in the next 30 days" on a
// dashboard with 24 outstanding items — the panel was most reassuring exactly when it
// should have been loudest. Overdue tasks sort first because they are the ones to act on.
$upcoming = array_values(array_filter($tasks, function($t) {
    if ($t['status'] !== 'open' || empty($t['dueDate'])) return false;
    $days = (strtotime($t['dueDate']) - time()) / 86400;
    return $days <= 30;
}));
usort($upcoming, fn($a, $b) => strcmp($a['dueDate'], $b['dueDate']));
// Deliberately NOT sliced to the five the card shows. The client filters by category
// first, and slicing here would hand it five business tasks and an empty IT view.
$upcoming = array_slice($upcoming, 0, 40);

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
        'total'      => count($tasks),
        'open'       => $openTasks,
        'overdue'    => $overdueTasks,
        'upcoming'   => $upcoming,
        'byCategory' => array_values($taskCategories),
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
