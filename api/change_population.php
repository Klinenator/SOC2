<?php
require_once __DIR__ . '/helpers.php'; cors();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') error_response('Method not allowed', 405);
$data = read_json('change_population.json'); $tickets = $data['tickets'] ?? [];
$changes = array_values(array_filter($tickets, fn($t) => !empty($t['likelyChange'])));
$needsReview = array_values(array_filter($changes, fn($t) => empty($t['hasApproval']) || empty($t['hasTesting']) || in_array($t['status'] ?? '', ['new','open'], true)));
json_response(['generatedAt'=>$data['generatedAt'] ?? null,'since'=>$data['since'] ?? null,'summary'=>['tickets'=>count($tickets),'likelyChanges'=>count($changes),'needsReview'=>count($needsReview),'approved'=>count(array_filter($changes,fn($t)=>!empty($t['hasApproval']))),'tested'=>count(array_filter($changes,fn($t)=>!empty($t['hasTesting'])))],'tickets'=>$tickets]);
