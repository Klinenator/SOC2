<?php
require_once __DIR__ . '/helpers.php';
cors();

$method = $_SERVER['REQUEST_METHOD'];
$id = $_GET['id'] ?? null;

if ($method === 'GET') {
    json_response(read_json('people.json'));
}

// Update a person's fields (name/title/email/confirmed). Owners are managed here so
// the reminder engine has verified addresses before going live.
if ($method === 'PUT') {
    if (!$id) error_response('Person id required');
    $body = get_body();
    $people = read_json('people.json');
    $allowed = ['name','title','email','confirmed'];
    $result = null;
    foreach ($people as &$p) {
        if ($p['id'] === $id) {
            foreach ($allowed as $f) {
                if (array_key_exists($f, $body)) {
                    $p[$f] = ($f === 'confirmed') ? (bool)$body[$f] : $body[$f];
                }
            }
            $result = $p;
            break;
        }
    }
    if (!$result) error_response('Person not found', 404);
    write_json('people.json', $people);
    json_response($result);
}

if ($method === 'POST') {
    $body = get_body();
    if (empty($body['name'])) error_response('Name is required');
    $people = read_json('people.json');
    $person = [
        'id'        => $body['id'] ?? strtolower(preg_replace('/[^a-z0-9]+/i', '', $body['name'])) . substr(uuid(), 0, 4),
        'name'      => trim($body['name']),
        'title'     => $body['title'] ?? '',
        'email'     => $body['email'] ?? '',
        'confirmed' => !empty($body['confirmed']),
    ];
    $people[] = $person;
    write_json('people.json', $people);
    json_response($person, 201);
}

error_response('Method not allowed', 405);
