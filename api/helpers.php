<?php
define('DATA_DIR', __DIR__ . '/../data/');
define('UPLOADS_DIR', __DIR__ . '/../uploads/');

function cors() {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");

    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($origin) {
        $originHost = parse_url($origin, PHP_URL_HOST);
        if (!$originHost || strcasecmp($originHost, preg_replace('/:\\d+$/', '', $host)) !== 0) {
            error_response('Cross-origin request denied', 403);
        }
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
    }

    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-SOC2-Request');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

    if ($_SERVER['REQUEST_METHOD'] !== 'GET' && ($_SERVER['HTTP_X_SOC2_REQUEST'] ?? '') !== '1') {
        error_response('Missing request verification header', 403);
    }
}

function json_response($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

function error_response($msg, $code = 400) {
    json_response(['error' => $msg], $code);
}

function read_json($file) {
    $path = DATA_DIR . $file;
    if (!file_exists($path)) return [];
    $fp = fopen($path, 'r');
    flock($fp, LOCK_SH);
    $data = json_decode(fread($fp, filesize($path) ?: 1), true) ?? [];
    flock($fp, LOCK_UN);
    fclose($fp);
    return $data;
}

function write_json($file, $data) {
    $path = DATA_DIR . $file;
    $directory = dirname($path);
    if (!is_dir($directory)) mkdir($directory, 0770, true);
    $tmp = tempnam($directory, '.soc2-json-');
    if ($tmp === false) throw new RuntimeException('Unable to create temporary data file');
    $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($encoded === false || file_put_contents($tmp, $encoded . "\n", LOCK_EX) === false) {
        @unlink($tmp);
        throw new RuntimeException('Unable to write data file');
    }
    chmod($tmp, 0660);
    if (!rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Unable to replace data file');
    }
}

// Next due date for a recurring obligation. Shared by the tasks API and the
// CLI importers so a task closed by either advances the same way.
function next_recurring_due($dueDate, $recurrence) {
    if (!$dueDate) return '';
    $intervals = [
        'weekly'    => '+1 week',
        'monthly'   => '+1 month',
        'quarterly' => '+3 months',
        'annual'    => '+1 year',
    ];
    if (!isset($intervals[$recurrence])) return '';

    // Roll forward past any missed occurrences. Closing a task that slipped should
    // schedule the next real one, not hand back another already-overdue date that
    // has to be closed again to catch up.
    $today = date('Y-m-d');
    $next = date('Y-m-d', strtotime($dueDate . ' ' . $intervals[$recurrence]));
    for ($guard = 0; $next < $today && $guard < 520; $guard++) {
        $next = date('Y-m-d', strtotime($next . ' ' . $intervals[$recurrence]));
    }
    return $next;
}

function normalize_date($date) {
    if (!$date || !preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $date)) return '';
    return $date;
}

function policy_health($policies, $signatures) {
    $today = date('Y-m-d');
    $summary = [
        'total' => count($policies), 'approved' => 0, 'draft' => 0,
        'missingReviewDate' => 0, 'overdueReview' => 0,
        'missingRequiredSignatures' => 0, 'templatePlaceholders' => 0,
    ];
    $signatureCounts = [];
    foreach ($signatures as $sig) {
        $policyId = $sig['policyId'] ?? '';
        $signatureCounts[$policyId] = ($signatureCounts[$policyId] ?? 0) + 1;
    }
    foreach ($policies as $policy) {
        if (($policy['status'] ?? 'draft') === 'approved') $summary['approved']++;
        else $summary['draft']++;
        $reviewDate = $policy['reviewDate'] ?? '';
        if (!$reviewDate) $summary['missingReviewDate']++;
        elseif ($reviewDate < $today) $summary['overdueReview']++;
        if (!empty($policy['requiresSignature']) && empty($signatureCounts[$policy['id'] ?? ''])) {
            $summary['missingRequiredSignatures']++;
        }
        $content = $policy['content'] ?? '';
        if (preg_match('/\\{\\{|_{4,}|AWS\\/Azure\\/GCP|headquartered in _/i', $content)) {
            $summary['templatePlaceholders']++;
        }
    }
    return $summary;
}

function workpaper_summary($workpapers) {
    $summary = ['total' => count($workpapers), 'not_started' => 0, 'in_progress' => 0, 'ready' => 0, 'tested' => 0, 'exception' => 0, 'not_applicable' => 0];
    foreach ($workpapers as $workpaper) {
        $status = $workpaper['status'] ?? 'not_started';
        $summary[$status] = ($summary[$status] ?? 0) + 1;
    }
    return $summary;
}

function get_body() {
    $raw = file_get_contents('php://input');
    return json_decode($raw, true) ?? [];
}

function uuid() {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

function evidence_record_date($record) {
    if (!empty($record['evidenceDate'])) return substr($record['evidenceDate'], 0, 10);
    if (!empty($record['uploadedAt'])) return substr($record['uploadedAt'], 0, 10);
    return null;
}

function evidence_matches_requirement($record, $requirement) {
    $controlId = $requirement['controlId'] ?? '';
    if ($controlId && !in_array($controlId, $record['controlIds'] ?? [])) return false;

    $terms = $requirement['matchTerms'] ?? [];
    if (!$terms) return true;

    $haystack = strtolower(trim(implode(' ', [
        $record['filename'] ?? '',
        $record['description'] ?? '',
        $record['source'] ?? '',
        $record['owner'] ?? '',
    ])));

    foreach ($terms as $term) {
        if (strpos($haystack, strtolower($term)) !== false) return true;
    }
    return false;
}

function evaluate_evidence_requirements($requirements = null, $evidence = null) {
    $requirements = $requirements ?? read_json('evidence_requirements.json');
    $evidence = $evidence ?? read_json('evidence.json');
    $today = date('Y-m-d');
    $summary = ['on_track' => 0, 'stale' => 0, 'missing' => 0];
    $evaluated = [];

    foreach ($requirements as $req) {
        $matches = array_values(array_filter($evidence, fn($record) => evidence_matches_requirement($record, $req)));
        usort($matches, function($a, $b) {
            return strcmp(evidence_record_date($b) ?? '', evidence_record_date($a) ?? '');
        });

        $latest = $matches[0] ?? null;
        $latestDate = $latest ? evidence_record_date($latest) : null;
        $freshnessDays = max((int)($req['freshnessDays'] ?? 30), 1);
        $status = 'missing';
        $ageDays = null;
        $nextDue = null;

        if ($latestDate) {
            $ageDays = (int) floor((strtotime($today) - strtotime($latestDate)) / 86400);
            $nextDue = date('Y-m-d', strtotime($latestDate . ' +' . $freshnessDays . ' days'));
            $status = $ageDays > $freshnessDays ? 'stale' : 'on_track';
        }

        $summary[$status]++;
        $req['status'] = $status;
        $req['latestEvidence'] = $latest;
        $req['latestEvidenceDate'] = $latestDate;
        $req['matchedEvidenceCount'] = count($matches);
        $req['ageDays'] = $ageDays;
        $req['nextDue'] = $nextDue;
        $evaluated[] = $req;
    }

    return [
        'summary' => $summary,
        'requirements' => $evaluated,
    ];
}
