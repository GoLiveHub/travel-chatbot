<?php
// API: промокоды — единый источник из data/promos.json
require __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=3600');
$promos = load_json('promos.json');
echo json_encode($promos, JSON_UNESCAPED_UNICODE);
