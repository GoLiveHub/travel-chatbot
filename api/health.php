<?php
// Health check endpoint for uptime monitoring
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

$checks = [
    'status' => 'ok',
    'timestamp' => time(),
    'php' => PHP_VERSION,
    'json' => function_exists('json_encode'),
    'data_dir' => is_dir(__DIR__ . '/../data'),
];

$hotelsFile = __DIR__ . '/../data/hotels.json';
$checks['hotels_file'] = is_file($hotelsFile) && filesize($hotelsFile) > 0;

http_response_code(200);
echo json_encode($checks, JSON_UNESCAPED_UNICODE);
