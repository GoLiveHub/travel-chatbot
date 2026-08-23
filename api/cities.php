<?php
// api/cities.php — список городов для автоподсказки
declare(strict_types=1);
require __DIR__ . '/config.php';

$cities = load_json('cities.json');

// Поиск по подстроке
$q = trim($_GET['q'] ?? '');
if ($q !== '') {
    $cities = array_values(array_filter($cities, function ($c) use ($q) {
        return mb_stripos($c['name'], $q) !== false;
    }));
}

h_json(['ok' => true, 'cities' => $cities]);
