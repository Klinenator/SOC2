<?php
define('DATA_DIR', __DIR__ . '/../data/');
define('UPLOADS_DIR', __DIR__ . '/../uploads/');

function cors() {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
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
    $fp = fopen($path, 'w');
    flock($fp, LOCK_EX);
    fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    flock($fp, LOCK_UN);
    fclose($fp);
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
