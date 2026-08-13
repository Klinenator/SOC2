<?php
require_once __DIR__ . '/helpers.php';
cors();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') error_response('Method not allowed', 405);

$controls = read_json('controls.json');
$evidence = read_json('evidence.json');
$workpapers = read_json('audit_tests.json');
$policies = read_json('policies.json');
$tasks = read_json('tasks.json');
$changePopulation = read_json('change_population.json');
$requirements = evaluate_evidence_requirements(null, $evidence)['requirements'];

$evidenceById = [];
foreach ($evidence as $record) $evidenceById[$record['id'] ?? ''] = $record;

$ticketsByKey = [];
foreach (($changePopulation['tickets'] ?? []) as $ticket) {
    $key = strtoupper((string)($ticket['ticketKey'] ?? ''));
    if ($key !== '') $ticketsByKey[$key] = $ticket;
}

function gap_ticket_refs($value) {
    $text = is_array($value) ? implode(' ', $value) : (string)$value;
    preg_match_all('/\bRRS-\d{6}\b/i', $text, $matches);
    return array_values(array_unique(array_map('strtoupper', $matches[0] ?? [])));
}

function gap_unique_records($records) {
    $unique = [];
    foreach ($records as $record) {
        $id = (string)($record['id'] ?? $record['ticketKey'] ?? $record['key'] ?? '');
        if ($id !== '') $unique[$id] = $record;
    }
    return array_values($unique);
}

$rows = [];
$summary = ['evidence_present' => 0, 'partial' => 0, 'no_evidence' => 0];
$categories = [];

foreach ($controls as $control) {
    $controlId = (string)($control['id'] ?? '');
    $controlEvidence = [];
    foreach (($control['evidenceIds'] ?? []) as $id) {
        if (isset($evidenceById[$id])) $controlEvidence[] = $evidenceById[$id];
    }
    foreach ($evidence as $record) {
        if (in_array($controlId, $record['controlIds'] ?? [], true)) $controlEvidence[] = $record;
    }

    $controlWorkpapers = array_values(array_filter(
        $workpapers,
        fn($workpaper) => ($workpaper['controlId'] ?? '') === $controlId
    ));
    $workpaperEvidence = [];
    $ticketRefs = gap_ticket_refs($control['notes'] ?? '');
    $completedWorkpapers = [];
    $documentedWorkpapers = [];
    foreach ($controlWorkpapers as $workpaper) {
        foreach (($workpaper['evidenceIds'] ?? []) as $id) {
            if (isset($evidenceById[$id])) $workpaperEvidence[] = $evidenceById[$id];
        }
        $ticketRefs = array_merge($ticketRefs, gap_ticket_refs([
            implode(' ', $workpaper['sampleRefs'] ?? []),
            $workpaper['populationSource'] ?? '',
            $workpaper['conclusion'] ?? '',
            $workpaper['exception'] ?? '',
        ]));
        if (stripos((string)($workpaper['populationSource'] ?? ''), 'change population') !== false) {
            foreach ($ticketsByKey as $key => $ticket) {
                if (!empty($ticket['likelyChange'])) $ticketRefs[] = $key;
            }
        }
        $status = $workpaper['status'] ?? 'not_started';
        if (in_array($status, ['ready', 'tested', 'not_applicable'], true)) {
            $completedWorkpapers[] = $workpaper;
        } elseif ($status !== 'not_started' || !empty($workpaper['populationSource']) || !empty($workpaper['conclusion'])) {
            $documentedWorkpapers[] = $workpaper;
        }
    }

    $ticketRefs = array_values(array_unique($ticketRefs));
    $matchedTickets = [];
    $unresolvedTicketRefs = [];
    foreach ($ticketRefs as $key) {
        if (isset($ticketsByKey[$key])) $matchedTickets[] = $ticketsByKey[$key];
        else $unresolvedTicketRefs[] = $key;
    }

    $linkedPolicies = array_values(array_filter(
        $policies,
        fn($policy) => in_array($controlId, $policy['soc2Controls'] ?? [], true)
    ));
    $approvedPolicies = array_values(array_filter(
        $linkedPolicies,
        fn($policy) => ($policy['status'] ?? 'draft') === 'approved'
    ));

    $controlRequirements = array_values(array_filter(
        $requirements,
        fn($requirement) => ($requirement['controlId'] ?? '') === $controlId
    ));
    $requirementHealth = ['on_track' => 0, 'stale' => 0, 'missing' => 0];
    foreach ($controlRequirements as $requirement) {
        $status = $requirement['status'] ?? 'missing';
        $requirementHealth[$status] = ($requirementHealth[$status] ?? 0) + 1;
    }

    $controlTasks = array_values(array_filter(
        $tasks,
        fn($task) => ($task['controlId'] ?? '') === $controlId
    ));
    $openTasks = count(array_filter($controlTasks, fn($task) => ($task['status'] ?? 'open') === 'open'));

    $controlEvidence = gap_unique_records($controlEvidence);
    $workpaperEvidence = gap_unique_records($workpaperEvidence);
    $artifactCount = count($controlEvidence) + count($workpaperEvidence) + count($matchedTickets)
        + count($approvedPolicies) + count($completedWorkpapers);
    // Assertions, narrative notes, tasks, and the existence of an evidence
    // requirement are not evidence. Only an unfinished policy/workpaper or a
    // ticket reference awaiting population refresh can move a row to partial.
    $supportCount = count($linkedPolicies) + count($documentedWorkpapers) + count($unresolvedTicketRefs);
    $requirementsNeedAttention = $requirementHealth['missing'] + $requirementHealth['stale'];

    if ($artifactCount > 0 && $requirementsNeedAttention === 0) {
        $coverage = 'evidence_present';
    } elseif ($artifactCount > 0 || $supportCount > 0) {
        $coverage = 'partial';
    } else {
        $coverage = 'no_evidence';
    }
    $summary[$coverage]++;

    $recommendations = [];
    if ($artifactCount === 0) $recommendations[] = 'Attach or link at least one independently reviewable artifact.';
    if ($requirementHealth['missing'] > 0) $recommendations[] = 'Collect the missing recurring evidence requirement.';
    if ($requirementHealth['stale'] > 0) $recommendations[] = 'Refresh stale recurring evidence.';
    if ($unresolvedTicketRefs) $recommendations[] = 'Refresh the ticket population or resolve unmatched ticket references.';
    if ($openTasks > 0) $recommendations[] = 'Complete or disposition linked remediation tasks.';
    if (!$recommendations && $coverage === 'evidence_present') $recommendations[] = 'Evidence sources are present; verify sampling and reviewer sign-off.';

    $category = $control['category'] ?? 'Other';
    $categories[$category] = $control['categoryName'] ?? $category;
    $rows[] = [
        'id' => $controlId,
        'name' => $control['name'] ?? '',
        'category' => $category,
        'categoryName' => $control['categoryName'] ?? $category,
        'controlStatus' => $control['status'] ?? 'not_started',
        'coverage' => $coverage,
        'sources' => [
            'uploadedEvidence' => count($controlEvidence),
            'workpaperEvidence' => count($workpaperEvidence),
            'tickets' => count($matchedTickets),
            'approvedPolicies' => count($approvedPolicies),
            'completedWorkpapers' => count($completedWorkpapers),
            'ticketKeys' => array_values(array_map(fn($ticket) => $ticket['ticketKey'], $matchedTickets)),
            'unresolvedTicketRefs' => $unresolvedTicketRefs,
        ],
        'requirements' => $requirementHealth,
        'openTasks' => $openTasks,
        'recommendations' => $recommendations,
    ];
}

$rank = ['no_evidence' => 0, 'partial' => 1, 'evidence_present' => 2];
usort($rows, function($a, $b) use ($rank) {
    $coverageOrder = ($rank[$a['coverage']] ?? 9) <=> ($rank[$b['coverage']] ?? 9);
    return $coverageOrder !== 0 ? $coverageOrder : strcmp($a['id'], $b['id']);
});

json_response([
    'generatedAt' => date(DATE_ATOM),
    'summary' => array_merge(['total' => count($rows)], $summary),
    'categories' => array_map(fn($id, $name) => ['id' => $id, 'name' => $name], array_keys($categories), array_values($categories)),
    'controls' => $rows,
    'methodology' => [
        'evidence_present' => 'At least one reviewable artifact exists and no recurring evidence requirement is stale or missing.',
        'partial' => 'An artifact or supporting record exists, but recurring evidence is incomplete/stale or the record is not yet independently reviewable.',
        'no_evidence' => 'No uploaded evidence, matched ticket, approved linked policy, or completed workpaper was found.',
    ],
]);
