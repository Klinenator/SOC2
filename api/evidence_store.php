<?php
declare(strict_types=1);

/**
 * The single implementation of "file a piece of evidence".
 *
 * WHY THIS FILE EXISTS
 *
 * Creating an evidence record means: validate the artifact, put it in uploads/ under a
 * generated name, append a record to evidence.json, and link that record's id back into
 * controls.json and audit_tests.json. Until 2026-08-27 that sequence existed only inside
 * api/evidence.php's POST branch, reachable only by a multipart browser upload behind Google
 * OAuth. Everything the portal actually wants as recurring evidence -- the patch compliance
 * export, log-review closure summaries, vulnerability scan reports -- is produced by a script,
 * and a script cannot do a browser upload. So none of it was ever filed: months after the
 * portal went up, data/evidence.json did not exist.
 *
 * The wrong fix is a second copy of that sequence somewhere else. A second copy drifts, and it
 * drifted immediately -- the first attempt at a CLI filer corrected the array_values bug below
 * while api/evidence.php still had it, so the two paths already disagreed about the shape of
 * data they wrote. The other wrong fix is a token endpoint that bypasses auth_request on a host
 * that also serves mail and webmail.
 *
 * So: one function, two entry points. api/evidence.php calls it for browser uploads.
 * scripts/file_evidence.php calls it locally on the portal host for script-produced evidence.
 * No network, no second auth surface, no second implementation.
 *
 * Callers handle presentation. This file throws EvidenceStoreError with an HTTP status and
 * never emits output, so the same code serves an API response and a CLI exit code.
 */

require_once __DIR__ . '/helpers.php';

class EvidenceStoreError extends RuntimeException
{
    public function __construct(string $message, private int $status = 400)
    {
        parent::__construct($message);
    }
    public function status(): int { return $this->status; }
}

/**
 * MIME types the store accepts. One list, so the CLI cannot admit a type the browser path
 * would have rejected.
 */
function evidence_allowed_mime(): array
{
    return [
        'application/pdf',
        'image/png',
        'image/jpeg',
        'image/gif',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'text/plain',
        'text/csv',
        'application/zip',
    ];
}

function evidence_max_bytes(): int { return 50 * 1024 * 1024; }

/**
 * Add or remove one evidence id on the records in $file whose id appears in $ids.
 *
 * array_values on the result is not cosmetic. array_unique preserves keys, so removing a
 * duplicate leaves gaps, and json_encode then emits a JSON object where every reader --
 * js/evidence.js, api/dashboard.php, evidence_requirements.php -- expects an array. Both
 * branches in api/evidence.php got this wrong before this function existed.
 *
 * $promoteNotStarted moves an audit test off 'not_started' when evidence lands on it, which is
 * what the POST branch did for tests and not for controls. Kept as a flag rather than silently
 * applied to both, because controls carry an audited status that a file upload has no business
 * changing.
 */
function evidence_link(string $file, array $ids, string $evidenceId, bool $add, bool $promoteNotStarted = false): array
{
    if (!$ids) return [];
    $rows = read_json($file);
    $touched = [];
    foreach ($rows as &$row) {
        if (!in_array($row['id'] ?? '', $ids, true)) continue;
        $current = $row['evidenceIds'] ?? [];
        if ($add) {
            $current[] = $evidenceId;
        } else {
            $current = array_filter($current, fn($e) => $e !== $evidenceId);
        }
        $row['evidenceIds'] = array_values(array_unique($current));
        if ($add && $promoteNotStarted && ($row['status'] ?? '') === 'not_started') {
            $row['status'] = 'in_progress';
        }
        $touched[] = $row['id'];
    }
    unset($row);
    write_json($file, array_values($rows));
    return $touched;
}

/**
 * Create an evidence record.
 *
 * $input:
 *   sourcePath    string  readable path to the artifact
 *   originalName  string  filename to show in the portal
 *   isUpload      bool    true when sourcePath came from $_FILES (uses move_uploaded_file)
 *   description   string  required -- the evidence requirements ask for a review date and
 *                         remediation ticket numbers, and a record without them is not
 *                         evidence that anybody reviewed anything
 *   controlIds    array   control ids to link
 *   auditTestIds  array   audit test ids to link
 *   evidenceDate  string  YYYY-MM-DD, defaults to today
 *   source, owner string
 *
 * Returns the stored record. Throws EvidenceStoreError on any rejection.
 */
function evidence_create(array $input): array
{
    $path = (string)($input['sourcePath'] ?? '');
    if ($path === '' || !is_readable($path)) {
        throw new EvidenceStoreError('Artifact is missing or unreadable');
    }

    $size = filesize($path);
    if ($size === false || $size === 0) {
        throw new EvidenceStoreError('Artifact is empty');
    }
    if ($size > evidence_max_bytes()) {
        throw new EvidenceStoreError('File too large (max 50MB)');
    }

    // Sniffed, never taken from the client. A browser-supplied Content-Type is a claim.
    $mime = mime_content_type($path);
    if ($mime === false || !in_array($mime, evidence_allowed_mime(), true)) {
        throw new EvidenceStoreError('File type not allowed');
    }

    $description = trim((string)($input['description'] ?? ''));
    if ($description === '') {
        throw new EvidenceStoreError('A description is required: record the review date and any remediation ticket numbers');
    }

    $controlIds   = array_values(array_filter(array_map('strval', (array)($input['controlIds'] ?? []))));
    $auditTestIds = array_values(array_filter(array_map('strval', (array)($input['auditTestIds'] ?? []))));
    if (!$controlIds && !$auditTestIds) {
        throw new EvidenceStoreError('Link the evidence to at least one control or audit test');
    }

    $evidenceDate = (string)($input['evidenceDate'] ?? '');
    if ($evidenceDate === '') $evidenceDate = date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $evidenceDate)) {
        throw new EvidenceStoreError('evidenceDate must be YYYY-MM-DD');
    }

    // Extension from the original name, constrained. The stored name is generated, so a
    // hostile filename cannot steer where the file lands or what it is served as.
    $ext = strtolower((string)pathinfo((string)($input['originalName'] ?? $path), PATHINFO_EXTENSION));
    if (!preg_match('/^[a-z0-9]{1,8}$/', $ext)) $ext = 'bin';
    $storedName = uuid() . '.' . $ext;
    $dest = UPLOADS_DIR . $storedName;
    if (file_exists($dest)) {
        throw new EvidenceStoreError('Generated storage name already exists; retry', 500);
    }

    if (!is_dir(UPLOADS_DIR) || !is_writable(UPLOADS_DIR)) {
        throw new EvidenceStoreError('uploads directory is not writable by ' . (get_current_user() ?: 'this user'), 500);
    }

    $placed = !empty($input['isUpload'])
        ? move_uploaded_file($path, $dest)
        : copy($path, $dest);
    if (!$placed) {
        throw new EvidenceStoreError('Failed to save file', 500);
    }
    @chmod($dest, 0640);

    $record = [
        'id'           => uuid(),
        'filename'     => (string)($input['originalName'] ?? basename($path)),
        'storedName'   => $storedName,
        'size'         => (int)$size,
        'mimeType'     => $mime,
        'description'  => $description,
        'source'       => (string)($input['source'] ?? ''),
        'owner'        => (string)($input['owner'] ?? ''),
        'evidenceDate' => $evidenceDate,
        'controlIds'   => $controlIds,
        'auditTestIds' => $auditTestIds,
        'uploadedAt'   => date('Y-m-d H:i:s'),
    ];

    $evidence = read_json('evidence.json');
    $evidence[] = $record;
    write_json('evidence.json', $evidence);

    evidence_link('controls.json', $controlIds, $record['id'], true);
    evidence_link('audit_tests.json', $auditTestIds, $record['id'], true, true);

    // Read back rather than assuming the write held. write_json is atomic, but the failure
    // this catches is a real one: if the store is not writable by the caller's user the record
    // silently is not there, which is how evidence.json stayed absent for months.
    $confirm = read_json('evidence.json');
    foreach ($confirm as $r) {
        if (($r['id'] ?? '') === $record['id']) return $record;
    }
    throw new EvidenceStoreError('Record was written but could not be read back', 500);
}
