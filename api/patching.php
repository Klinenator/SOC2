<?php
require_once __DIR__ . '/helpers.php';
cors();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'summary';
$id = $_GET['id'] ?? null;

if ($method === 'GET') {
    if ($action === 'summary') {
        json_response(build_patching_summary());
    }
    error_response('Unknown action', 400);
}

if ($method === 'POST') {
    $body = get_body();

    if ($action === 'server') {
        $servers = read_json('patch_servers.json');
        if (empty($body['name'])) error_response('Server name is required');

        $server = [
            'id' => $body['id'] ?? 'srv-' . substr(str_replace('-', '', uuid()), 0, 8),
            'name' => trim((string)$body['name']),
            'hostname' => trim((string)($body['hostname'] ?? '')),
            'environment' => trim((string)($body['environment'] ?? 'production')),
            'ownerId' => trim((string)($body['ownerId'] ?? '')),
            'token' => bin2hex(random_bytes(16)),
            'status' => 'never_reported',
            'lastCheckIn' => '',
            'lastPatchAt' => '',
            'rebootRequired' => false,
            'updateCount' => 0,
            'securityUpdateCount' => 0,
            'error' => '',
            'lastReport' => [
                'osVersion' => '',
                'updates' => [],
                'heldPackages' => [],
                'aptHistoryExcerpt' => '',
            ],
        ];
        $servers[] = $server;
        write_json('patch_servers.json', $servers);

        $safe = $server;
        unset($safe['token']);
        json_response(['server' => $safe, 'token' => $server['token']], 201);
    }

    if ($action === 'checkin') {
        $serverId = trim((string)($body['serverId'] ?? ''));
        $token = trim((string)($body['token'] ?? ''));
        if ($serverId === '' || $token === '') error_response('serverId and token are required');

        $servers = read_json('patch_servers.json');
        $jobs = read_json('patch_jobs.json');
        $serverIndex = null;
        foreach ($servers as $idx => $candidate) {
            if (($candidate['id'] ?? '') === $serverId) {
                $serverIndex = $idx;
                break;
            }
        }
        if ($serverIndex === null) error_response('Server not found', 404);
        if (($servers[$serverIndex]['token'] ?? '') !== $token) error_response('Invalid token', 403);

        $report = is_array($body['report'] ?? null) ? $body['report'] : [];
        $servers[$serverIndex]['hostname'] = trim((string)($report['hostname'] ?? $servers[$serverIndex]['hostname'] ?? ''));
        $servers[$serverIndex]['status'] = trim((string)($report['status'] ?? 'ok')) ?: 'ok';
        $servers[$serverIndex]['lastCheckIn'] = date('Y-m-d H:i:s');
        $servers[$serverIndex]['lastPatchAt'] = trim((string)($report['lastPatchAt'] ?? ''));
        $servers[$serverIndex]['rebootRequired'] = !empty($report['rebootRequired']);
        $servers[$serverIndex]['error'] = trim((string)($report['error'] ?? ''));
        $servers[$serverIndex]['updateCount'] = count($report['updates'] ?? []);
        $servers[$serverIndex]['securityUpdateCount'] = count(array_filter($report['updates'] ?? [], fn($u) => !empty($u['security'])));
        $servers[$serverIndex]['lastReport'] = [
            'osVersion' => trim((string)($report['osVersion'] ?? '')),
            'updates' => array_values($report['updates'] ?? []),
            'heldPackages' => array_values($report['heldPackages'] ?? []),
            'aptHistoryExcerpt' => trim((string)($report['aptHistoryExcerpt'] ?? '')),
        ];

        $jobResults = is_array($body['jobResults'] ?? null) ? $body['jobResults'] : [];
        foreach ($jobResults as $jobResult) {
            $jobId = trim((string)($jobResult['jobId'] ?? ''));
            if ($jobId === '') continue;
            foreach ($jobs as &$job) {
                if (($job['id'] ?? '') !== $jobId || ($job['serverId'] ?? '') !== $serverId) continue;
                $job['status'] = in_array($jobResult['status'] ?? '', ['succeeded', 'failed'], true) ? $jobResult['status'] : 'succeeded';
                $job['completedAt'] = trim((string)($jobResult['completedAt'] ?? date('Y-m-d H:i:s')));
                $job['resultSummary'] = trim((string)($jobResult['summary'] ?? ''));
                $job['output'] = trim((string)($jobResult['output'] ?? ''));
                $job['packagesUpgraded'] = array_values($jobResult['packagesUpgraded'] ?? []);
                $job['rebootRequired'] = !empty($jobResult['rebootRequired']);
                break;
            }
            unset($job);
        }

        $pendingJobs = [];
        foreach ($jobs as &$job) {
            if (($job['serverId'] ?? '') !== $serverId) continue;
            if (($job['status'] ?? '') !== 'pending') continue;
            $job['status'] = 'acknowledged';
            $job['acknowledgedAt'] = date('Y-m-d H:i:s');
            $pendingJobs[] = [
                'id' => $job['id'],
                'command' => $job['command'],
                'notes' => $job['notes'] ?? '',
                'requestedBy' => $job['requestedBy'] ?? '',
                'createdAt' => $job['createdAt'] ?? '',
            ];
        }
        unset($job);

        write_json('patch_servers.json', $servers);
        write_json('patch_jobs.json', $jobs);
        json_response(['ok' => true, 'jobs' => $pendingJobs]);
    }

    error_response('Unknown action', 400);
}

if ($method === 'PUT') {
    $body = get_body();

    if ($action === 'server') {
        if (!$id) error_response('Server ID required');
        $servers = read_json('patch_servers.json');
        $allowed = ['name', 'hostname', 'environment', 'ownerId'];
        $result = null;
        foreach ($servers as &$server) {
            if (($server['id'] ?? '') !== $id) continue;
            foreach ($allowed as $field) {
                if (array_key_exists($field, $body)) {
                    $server[$field] = trim((string)$body[$field]);
                }
            }
            if (!empty($body['rotateToken'])) {
                $server['token'] = bin2hex(random_bytes(16));
            }
            $result = $server;
            break;
        }
        unset($server);
        if (!$result) error_response('Server not found', 404);
        write_json('patch_servers.json', $servers);
        $safe = $result;
        unset($safe['token']);
        json_response(['server' => $safe, 'token' => $result['token'] ?? null]);
    }

    if ($action === 'queue') {
        if (!$id) error_response('Server ID required');
        $servers = read_json('patch_servers.json');
        $exists = false;
        foreach ($servers as $server) {
            if (($server['id'] ?? '') === $id) {
                $exists = true;
                break;
            }
        }
        if (!$exists) error_response('Server not found', 404);

        $command = trim((string)($body['command'] ?? ''));
        if (!in_array($command, ['scan', 'security-upgrade'], true)) error_response('Invalid command');

        $jobs = read_json('patch_jobs.json');
        $job = [
            'id' => 'job-' . substr(str_replace('-', '', uuid()), 0, 10),
            'serverId' => $id,
            'command' => $command,
            'status' => 'pending',
            'createdAt' => date('Y-m-d H:i:s'),
            'requestedBy' => trim((string)($body['requestedBy'] ?? '')),
            'notes' => trim((string)($body['notes'] ?? '')),
            'completedAt' => '',
            'resultSummary' => '',
            'output' => '',
            'packagesUpgraded' => [],
            'rebootRequired' => false,
        ];
        $jobs[] = $job;
        write_json('patch_jobs.json', $jobs);
        json_response($job);
    }

    error_response('Unknown action', 400);
}

if ($method === 'DELETE') {
    if ($action !== 'server' || !$id) error_response('Server ID required');

    $servers = read_json('patch_servers.json');
    $jobs = read_json('patch_jobs.json');
    $newServers = array_values(array_filter($servers, fn($server) => ($server['id'] ?? '') !== $id));
    if (count($newServers) === count($servers)) error_response('Server not found', 404);
    $newJobs = array_values(array_filter($jobs, fn($job) => ($job['serverId'] ?? '') !== $id));
    write_json('patch_servers.json', $newServers);
    write_json('patch_jobs.json', $newJobs);
    json_response(['ok' => true]);
}

error_response('Method not allowed', 405);

function build_patching_summary() {
    $servers = read_json('patch_servers.json');
    $jobs = read_json('patch_jobs.json');
    $people = read_json('people.json');
    $peopleById = [];
    foreach ($people as $person) {
        $peopleById[$person['id']] = $person['name'];
    }

    $now = time();
    foreach ($servers as &$server) {
        unset($server['token']);
        $server['ownerName'] = $peopleById[$server['ownerId'] ?? ''] ?? '';
        $server['jobCounts'] = ['pending' => 0, 'acknowledged' => 0, 'succeeded' => 0, 'failed' => 0];
        foreach ($jobs as $job) {
            if (($job['serverId'] ?? '') !== ($server['id'] ?? '')) continue;
            $status = $job['status'] ?? 'pending';
            $server['jobCounts'][$status] = ($server['jobCounts'][$status] ?? 0) + 1;
        }

        $server['health'] = 'unknown';
        if (($server['status'] ?? '') === 'never_reported') {
            $server['health'] = 'unknown';
        } elseif (($server['error'] ?? '') !== '') {
            $server['health'] = 'error';
        } elseif (!empty($server['lastCheckIn'])) {
            $ageSeconds = max($now - strtotime($server['lastCheckIn']), 0);
            $server['health'] = $ageSeconds > 86400 ? 'stale' : 'healthy';
        }
    }
    unset($server);

    usort($jobs, fn($a, $b) => strcmp($b['createdAt'] ?? '', $a['createdAt'] ?? ''));

    $summary = [
        'servers' => count($servers),
        'healthy' => count(array_filter($servers, fn($server) => ($server['health'] ?? '') === 'healthy')),
        'stale' => count(array_filter($servers, fn($server) => ($server['health'] ?? '') === 'stale')),
        'pendingJobs' => count(array_filter($jobs, fn($job) => in_array(($job['status'] ?? ''), ['pending', 'acknowledged'], true))),
        'securityUpdates' => array_sum(array_map(fn($server) => (int)($server['securityUpdateCount'] ?? 0), $servers)),
    ];

    return [
        'summary' => $summary,
        'servers' => $servers,
        'jobs' => array_slice($jobs, 0, 25),
    ];
}
