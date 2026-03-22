<?php
require_once __DIR__ . '/helpers.php';
cors();

$method = $_SERVER['REQUEST_METHOD'];
$id = $_GET['id'] ?? null;
$controlId = $_GET['controlId'] ?? null;

if ($method === 'GET') {
    $tasks = read_json('tasks.json');
    if ($controlId) {
        $tasks = array_values(array_filter($tasks, fn($t) => $t['controlId'] === $controlId));
    }
    // Sort by priority then dueDate
    usort($tasks, function($a, $b) {
        $pOrder = ['high' => 0, 'medium' => 1, 'low' => 2];
        $pa = $pOrder[$a['priority']] ?? 1;
        $pb = $pOrder[$b['priority']] ?? 1;
        if ($pa !== $pb) return $pa - $pb;
        return strcmp($a['dueDate'] ?? '', $b['dueDate'] ?? '');
    });
    json_response($tasks);
}

if ($method === 'POST') {
    $body = get_body();
    if (empty($body['title'])) error_response('Title is required');
    $task = [
        'id'        => uuid(),
        'title'     => trim($body['title']),
        'description' => $body['description'] ?? '',
        'controlId' => $body['controlId'] ?? '',
        'assignee'  => $body['assignee'] ?? '',
        'priority'  => in_array($body['priority'] ?? '', ['high','medium','low']) ? $body['priority'] : 'medium',
        'status'    => 'open',
        'dueDate'   => $body['dueDate'] ?? '',
        'createdAt' => date('Y-m-d'),
    ];
    $tasks = read_json('tasks.json');
    $tasks[] = $task;
    write_json('tasks.json', $tasks);
    json_response($task, 201);
}

if ($method === 'PUT') {
    if (!$id) error_response('Task ID required');
    $body = get_body();
    $tasks = read_json('tasks.json');
    $allowed = ['title','description','controlId','assignee','priority','status','dueDate'];
    $updated = false;
    foreach ($tasks as &$t) {
        if ($t['id'] === $id) {
            foreach ($allowed as $field) {
                if (array_key_exists($field, $body)) $t[$field] = $body[$field];
            }
            $updated = true;
            $result = $t;
            break;
        }
    }
    if (!$updated) error_response('Task not found', 404);
    write_json('tasks.json', $tasks);
    json_response($result);
}

if ($method === 'DELETE') {
    if (!$id) error_response('Task ID required');
    $tasks = read_json('tasks.json');
    $filtered = array_values(array_filter($tasks, fn($t) => $t['id'] !== $id));
    if (count($filtered) === count($tasks)) error_response('Task not found', 404);
    write_json('tasks.json', $filtered);
    json_response(['ok' => true]);
}

error_response('Method not allowed', 405);
