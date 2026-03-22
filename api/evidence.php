<?php
require_once __DIR__ . '/helpers.php';
cors();

$method = $_SERVER['REQUEST_METHOD'];
$id = $_GET['id'] ?? null;
$controlId = $_GET['controlId'] ?? null;

if ($method === 'GET') {
    $evidence = read_json('evidence.json');
    if ($controlId) {
        $evidence = array_values(array_filter($evidence, fn($e) => in_array($controlId, $e['controlIds'] ?? [])));
    }
    json_response($evidence);
}

if ($method === 'POST') {
    // Multipart file upload
    if (empty($_FILES['file'])) error_response('No file uploaded');
    $file = $_FILES['file'];
    if ($file['error'] !== UPLOAD_ERR_OK) error_response('Upload error: ' . $file['error']);

    $maxSize = 50 * 1024 * 1024; // 50MB
    if ($file['size'] > $maxSize) error_response('File too large (max 50MB)');

    $allowedTypes = ['application/pdf','image/png','image/jpeg','image/gif',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'text/plain','text/csv','application/zip'];
    $mime = mime_content_type($file['tmp_name']);
    if (!in_array($mime, $allowedTypes)) error_response('File type not allowed');

    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $storedName = uuid() . '.' . strtolower($ext);
    $dest = UPLOADS_DIR . $storedName;
    if (!move_uploaded_file($file['tmp_name'], $dest)) error_response('Failed to save file');

    $controlIds = [];
    if (!empty($_POST['controlIds'])) {
        $controlIds = json_decode($_POST['controlIds'], true) ?? [];
    }

    $record = [
        'id'          => uuid(),
        'filename'    => $file['name'],
        'storedName'  => $storedName,
        'size'        => $file['size'],
        'mimeType'    => $mime,
        'description' => $_POST['description'] ?? '',
        'controlIds'  => $controlIds,
        'uploadedAt'  => date('Y-m-d H:i:s'),
    ];

    $evidence = read_json('evidence.json');
    $evidence[] = $record;
    write_json('evidence.json', $evidence);

    // Link evidence ID back to each control
    if ($controlIds) {
        $controls = read_json('controls.json');
        foreach ($controls as &$c) {
            if (in_array($c['id'], $controlIds)) {
                $c['evidenceIds'][] = $record['id'];
                $c['evidenceIds'] = array_unique($c['evidenceIds']);
            }
        }
        write_json('controls.json', array_values($controls));
    }

    json_response($record, 201);
}

if ($method === 'PUT') {
    if (!$id) error_response('Evidence ID required');
    $body = get_body();
    $evidence = read_json('evidence.json');
    $updated = false;
    $old = null;
    foreach ($evidence as &$e) {
        if ($e['id'] === $id) {
            $old = $e;
            if (isset($body['description'])) $e['description'] = $body['description'];
            if (isset($body['controlIds'])) {
                $e['controlIds'] = $body['controlIds'];
            }
            $updated = true;
            $result = $e;
            break;
        }
    }
    if (!$updated) error_response('Evidence not found', 404);
    write_json('evidence.json', $evidence);

    // Re-sync control links
    if (isset($body['controlIds']) && $old) {
        $controls = read_json('controls.json');
        $oldIds = $old['controlIds'] ?? [];
        $newIds = $body['controlIds'];
        foreach ($controls as &$c) {
            $was = in_array($c['id'], $oldIds);
            $now = in_array($c['id'], $newIds);
            if ($was && !$now) {
                $c['evidenceIds'] = array_values(array_filter($c['evidenceIds'], fn($eid) => $eid !== $id));
            } elseif (!$was && $now) {
                $c['evidenceIds'][] = $id;
                $c['evidenceIds'] = array_unique($c['evidenceIds']);
            }
        }
        write_json('controls.json', array_values($controls));
    }

    json_response($result);
}

if ($method === 'DELETE') {
    if (!$id) error_response('Evidence ID required');
    $evidence = read_json('evidence.json');
    $record = null;
    $filtered = array_values(array_filter($evidence, function($e) use ($id, &$record) {
        if ($e['id'] === $id) { $record = $e; return false; }
        return true;
    }));
    if (!$record) error_response('Evidence not found', 404);

    // Remove file
    $filePath = UPLOADS_DIR . $record['storedName'];
    if (file_exists($filePath)) unlink($filePath);

    write_json('evidence.json', $filtered);

    // Remove from controls
    if (!empty($record['controlIds'])) {
        $controls = read_json('controls.json');
        foreach ($controls as &$c) {
            $c['evidenceIds'] = array_values(array_filter($c['evidenceIds'] ?? [], fn($eid) => $eid !== $id));
        }
        write_json('controls.json', array_values($controls));
    }

    json_response(['ok' => true]);
}

// Serve file download
if ($method === 'GET' && isset($_GET['download'])) {
    $evidence = read_json('evidence.json');
    $record = array_values(array_filter($evidence, fn($e) => $e['id'] === $_GET['download']))[0] ?? null;
    if (!$record) error_response('Not found', 404);
    $path = UPLOADS_DIR . $record['storedName'];
    if (!file_exists($path)) error_response('File not found', 404);
    header('Content-Type: ' . $record['mimeType']);
    header('Content-Disposition: attachment; filename="' . $record['filename'] . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

error_response('Method not allowed', 405);
