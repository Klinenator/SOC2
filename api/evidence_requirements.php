<?php
require_once __DIR__ . '/helpers.php';
cors();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') error_response('Method not allowed', 405);

$controlId = $_GET['controlId'] ?? null;
$result = evaluate_evidence_requirements();

if ($controlId) {
    $result['requirements'] = array_values(array_filter(
        $result['requirements'],
        fn($req) => ($req['controlId'] ?? '') === $controlId
    ));
    $result['summary'] = ['on_track' => 0, 'stale' => 0, 'missing' => 0];
    foreach ($result['requirements'] as $req) {
        $result['summary'][$req['status']]++;
    }
}

json_response($result);
