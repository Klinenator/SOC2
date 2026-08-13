<?php
require_once __DIR__ . '/helpers.php';
cors();

$method = $_SERVER['REQUEST_METHOD'];
$id = $_GET['id'] ?? null;
$controlId = $_GET['controlId'] ?? null;
$tests = read_json('audit_tests.json');

if ($method === 'GET') {
    if ($controlId) $tests = array_values(array_filter($tests, fn($t) => ($t['controlId'] ?? '') === $controlId));
    json_response(['summary' => workpaper_summary($tests), 'workpapers' => $tests]);
}

if ($method === 'PUT') {
    if (!$id) error_response('Workpaper ID required');
    $body = get_body();
    $allowed = ['populationSource','ownerId','status','sampleRefs','evidenceIds','conclusion','exception'];
    $statuses = ['not_started','in_progress','ready','tested','exception','not_applicable'];
    if (isset($body['status']) && !in_array($body['status'], $statuses, true)) error_response('Invalid status');
    $updated = false;
    foreach ($tests as &$test) {
        if (($test['id'] ?? '') !== $id) continue;
        foreach ($allowed as $field) if (array_key_exists($field, $body)) $test[$field] = $body[$field];
        $test['updatedAt'] = date('Y-m-d H:i:s');
        $result = $test;
        $updated = true;
        break;
    }
    if (!$updated) error_response('Workpaper not found', 404);
    write_json('audit_tests.json', $tests);
    json_response($result);
}

error_response('Method not allowed', 405);
