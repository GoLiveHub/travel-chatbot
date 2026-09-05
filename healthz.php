<?php
// Health-check для хостинга (Render). Без вывода чувствительных данных.
require __DIR__ . '/api/config.php';

$checks = ['hotels' => file_exists(__DIR__ . '/data/hotels.json'), 'cities' => file_exists(__DIR__ . '/data/cities.json')];
$ok = $checks['hotels'] && $checks['cities'];

http_response_code($ok ? 200 : 503);
header('Content-Type: application/json');
echo json_encode(['ok' => $ok, 'service' => 'travel-demo', 'time' => gmdate('c')]);
