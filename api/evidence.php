<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/evidence_store.php';
cors();

$method = $_SERVER['REQUEST_METHOD'];
$id = $_GET['id'] ?? null;
$controlId = $_GET['controlId'] ?? null;

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

if ($method === 'GET') {
    $evidence = read_json('evidence.json');
    if ($controlId) {
        $evidence = array_values(array_filter($evidence, fn($e) => in_array($controlId, $e['controlIds'] ?? [])));
    }
    json_response($evidence);
}

// ---------------------------------------------------------------------------------------
// FILING EVIDENCE. There are exactly two ways, and both run the same code:
//
//   1. A person, from a browser: POST multipart/form-data here. This branch.
//   2. A script, on the portal host: scripts/file_evidence.php
//
// Both call evidence_create() in api/evidence_store.php, which is the ONLY place that
// validates an artifact, writes it to uploads/, appends to evidence.json, and links the
// record into controls.json / audit_tests.json. If you are adding a third way, add another
// caller of evidence_create() -- do not reimplement the sequence, and do not add an
// authentication bypass to reach this endpoint. Recurring evidence comes from scripts, which
// is what path 2 is for.
// ---------------------------------------------------------------------------------------
if ($method === 'POST') {
    if (empty($_FILES['file'])) {
        error_response('No file uploaded. Scripts should use scripts/file_evidence.php on the portal host instead of posting here.');
    }
    $file = $_FILES['file'];
    if ($file['error'] !== UPLOAD_ERR_OK) error_response('Upload error: ' . $file['error']);

    try {
        $record = evidence_create([
            'sourcePath'   => $file['tmp_name'],
            'originalName' => $file['name'],
            'isUpload'     => true,
            'description'  => $_POST['description'] ?? '',
            'source'       => $_POST['source'] ?? '',
            'owner'        => $_POST['owner'] ?? '',
            'evidenceDate' => $_POST['evidenceDate'] ?? '',
            'controlIds'   => !empty($_POST['controlIds']) ? (json_decode($_POST['controlIds'], true) ?? []) : [],
            'auditTestIds' => !empty($_POST['auditTestIds']) ? (json_decode($_POST['auditTestIds'], true) ?? []) : [],
        ]);
    } catch (EvidenceStoreError $e) {
        error_response($e->getMessage(), $e->status());
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
            if (isset($body['source'])) $e['source'] = $body['source'];
            if (isset($body['owner'])) $e['owner'] = $body['owner'];
            if (isset($body['evidenceDate'])) $e['evidenceDate'] = $body['evidenceDate'];
            if (isset($body['controlIds'])) {
                $e['controlIds'] = $body['controlIds'];
            }
            if (isset($body['auditTestIds'])) $e['auditTestIds'] = $body['auditTestIds'];
            $updated = true;
            $result = $e;
            break;
        }
    }
    if (!$updated) error_response('Evidence not found', 404);
    write_json('evidence.json', $evidence);

    // Re-sync links through the same helper the create path uses, so all three operations
    // (create, re-link, delete) agree on the shape they write. This branch previously did its
    // own array_unique without array_values, which turns evidenceIds into a JSON object as
    // soon as a duplicate is dropped.
    if (isset($body['controlIds']) && $old) {
        $oldIds = $old['controlIds'] ?? [];
        $newIds = $body['controlIds'];
        evidence_link('controls.json', array_values(array_diff($oldIds, $newIds)), $id, false);
        evidence_link('controls.json', array_values(array_diff($newIds, $oldIds)), $id, true);
    }
    if (isset($body['auditTestIds']) && $old) {
        $oldIds = $old['auditTestIds'] ?? [];
        $newIds = $body['auditTestIds'];
        evidence_link('audit_tests.json', array_values(array_diff($oldIds, $newIds)), $id, false);
        evidence_link('audit_tests.json', array_values(array_diff($newIds, $oldIds)), $id, true, true);
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
    if (!empty($record['auditTestIds'])) {
        $tests = read_json('audit_tests.json');
        foreach ($tests as &$test) $test['evidenceIds'] = array_values(array_filter($test['evidenceIds'] ?? [], fn($eid) => $eid !== $id));
        write_json('audit_tests.json', $tests);
    }

    json_response(['ok' => true]);
}

error_response('Method not allowed', 405);
