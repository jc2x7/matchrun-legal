<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
if (!$data || !isset($data['event'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid data']);
    exit;
}

$logFile = __DIR__ . '/visitantes.txt';

$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
$timestamp = date('Y-m-d H:i:s');

$entry = [
    'timestamp'    => $timestamp,
    'event'        => $data['event'],
    'ip'           => $ip,
    'referrer'     => $data['referrer'] ?? '',
    'utm_source'   => $data['utm_source'] ?? '',
    'utm_medium'   => $data['utm_medium'] ?? '',
    'utm_campaign' => $data['utm_campaign'] ?? '',
    'utm_content'  => $data['utm_content'] ?? '',
    'page'         => $data['page'] ?? '',
    'button'       => $data['button'] ?? '',
    'time_on_page' => $data['time_on_page'] ?? '',
    'user_agent'   => $userAgent,
];

$line = json_encode($entry, JSON_UNESCAPED_UNICODE) . "\n";
file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);

echo json_encode(['ok' => true]);
