<?php
require_once __DIR__ . '/helpers.php';
cors();

$method = $_SERVER['REQUEST_METHOD'];
$id = $_GET['id'] ?? null;

if ($method === 'GET') {
    $controls = read_json('controls.json');
    if ($id) {
        $control = array_values(array_filter($controls, fn($c) => $c['id'] === $id))[0] ?? null;
        if (!$control) error_response('Control not found', 404);
        json_response($control);
    }
    json_response($controls);
}

if ($method === 'PUT') {
    if (!$id) error_response('Control ID required');
    $body = get_body();
    $controls = read_json('controls.json');
    $allowed = ['status', 'owner', 'dueDate', 'notes', 'evidenceIds'];
    $updated = false;
    foreach ($controls as &$c) {
        if ($c['id'] === $id) {
            foreach ($allowed as $field) {
                if (array_key_exists($field, $body)) $c[$field] = $body[$field];
            }
            $updated = true;
            $result = $c;
            break;
        }
    }
    if (!$updated) error_response('Control not found', 404);
    write_json('controls.json', $controls);
    json_response($result);
}

error_response('Method not allowed', 405);
