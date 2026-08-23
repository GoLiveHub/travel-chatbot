<?php
// api/hotels.php — возвращает список отелей с фильтрацией
declare(strict_types=1);
require __DIR__ . '/config.php';

$hotels = load_json('hotels.json');

// Фильтрация по городу
$city = trim($_GET['city'] ?? '');
if ($city !== '') {
    $hotels = array_values(array_filter($hotels, function ($h) use ($city) {
        return mb_strtolower($h['city']) === mb_strtolower($city);
    }));
}

// Фильтрация по типу (пляж / горы / город)
$type = trim($_GET['type'] ?? '');
if ($type !== '' && $type !== 'all') {
    $hotels = array_values(array_filter($hotels, fn($h) => $h['type'] === $type));
}

// Поиск по подстроке в названии / описании
$q = trim($_GET['q'] ?? '');
if ($q !== '') {
    $hotels = array_values(array_filter($hotels, function ($h) use ($q) {
        $needle = mb_strtolower($q);
        return mb_strpos(mb_strtolower($h['name']), $needle) !== false
            || mb_strpos(mb_strtolower($h['description']), $needle) !== false;
    }));
}

// Ограничение максимума
$max = (int) ($_GET['limit'] ?? 0);
if ($max > 0) {
    $hotels = array_slice($hotels, 0, $max);
}

h_json(['ok' => true, 'count' => count($hotels), 'hotels' => $hotels]);
