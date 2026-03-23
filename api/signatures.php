<?php
require_once __DIR__ . '/helpers.php';
cors();

$method = $_SERVER['REQUEST_METHOD'];
$policyId = $_GET['policyId'] ?? null;

if ($method === 'GET') {
    $sigs = read_json('signatures.json');
    if ($policyId) {
        $sigs = array_values(array_filter($sigs, fn($s) => $s['policyId'] === $policyId));
    }
    json_response($sigs);
}

if ($method === 'POST') {
    $body = get_body();
    if (empty($body['policyId'])) error_response('policyId required');
    if (empty($body['signerName'])) error_response('signerName required');

    // Load policy to get current version
    $policies = read_json('policies.json');
    $policy = array_values(array_filter($policies, fn($p) => $p['id'] === $body['policyId']))[0] ?? null;
    if (!$policy) error_response('Policy not found', 404);

    $sig = [
        'id'          => uuid(),
        'policyId'    => $body['policyId'],
        'policyName'  => $policy['name'],
        'policyVersion' => $policy['version'],
        'signerName'  => trim($body['signerName']),
        'signerTitle' => trim($body['signerTitle'] ?? ''),
        'signedAt'    => date('Y-m-d H:i:s'),
        'ipAddress'   => $_SERVER['REMOTE_ADDR'] ?? '',
        'acknowledged'=> true,
    ];

    $sigs = read_json('signatures.json');
    $sigs[] = $sig;
    write_json('signatures.json', $sigs);

    // Also store signature reference on the policy
    foreach ($policies as &$p) {
        if ($p['id'] === $body['policyId']) {
            $p['signatures'][] = $sig['id'];
            break;
        }
    }
    write_json('policies.json', $policies);

    json_response($sig, 201);
}

if ($method === 'DELETE') {
    $id = $_GET['id'] ?? null;
    if (!$id) error_response('Signature ID required');
    $sigs = read_json('signatures.json');
    $filtered = array_values(array_filter($sigs, fn($s) => $s['id'] !== $id));
    if (count($filtered) === count($sigs)) error_response('Signature not found', 404);
    write_json('signatures.json', $filtered);
    json_response(['ok' => true]);
}

error_response('Method not allowed', 405);
