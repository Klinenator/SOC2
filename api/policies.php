<?php
require_once __DIR__ . '/helpers.php';
cors();

$method = $_SERVER['REQUEST_METHOD'];
$id = $_GET['id'] ?? null;

if ($method === 'GET') {
    json_response(read_json('policies.json'));
}

if ($method === 'POST') {
    $body = get_body();
    if (empty($body['name'])) error_response('Policy name required');
    $policy = [
        'id'          => uuid(),
        'name'        => trim($body['name']),
        'description' => $body['description'] ?? '',
        'category'    => $body['category'] ?? 'General',
        'status'      => in_array($body['status'] ?? '', ['draft','under_review','approved']) ? $body['status'] : 'draft',
        'content'     => $body['content'] ?? '',
        'version'     => $body['version'] ?? '1.0',
        'owner'       => $body['owner'] ?? '',
        'reviewDate'  => $body['reviewDate'] ?? '',
        'createdAt'   => date('Y-m-d'),
        'updatedAt'   => date('Y-m-d'),
    ];
    $policies = read_json('policies.json');
    $policies[] = $policy;
    write_json('policies.json', $policies);
    json_response($policy, 201);
}

if ($method === 'PUT') {
    if (!$id) error_response('Policy ID required');
    $body = get_body();
    $policies = read_json('policies.json');
    $allowed = ['name','description','category','status','content','version','owner','reviewDate'];
    $updated = false;
    foreach ($policies as &$p) {
        if ($p['id'] === $id) {
            foreach ($allowed as $field) {
                if (array_key_exists($field, $body)) $p[$field] = $body[$field];
            }
            $p['updatedAt'] = date('Y-m-d');
            $updated = true;
            $result = $p;
            break;
        }
    }
    if (!$updated) error_response('Policy not found', 404);
    write_json('policies.json', $policies);
    json_response($result);
}

if ($method === 'DELETE') {
    if (!$id) error_response('Policy ID required');
    $policies = read_json('policies.json');
    $filtered = array_values(array_filter($policies, fn($p) => $p['id'] !== $id));
    if (count($filtered) === count($policies)) error_response('Policy not found', 404);
    write_json('policies.json', $filtered);
    json_response(['ok' => true]);
}

error_response('Method not allowed', 405);
