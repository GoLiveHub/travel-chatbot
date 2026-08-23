<?php
// Динамическая генерация sitemap.xml по данным отелей
require __DIR__ . '/api/config.php';

$base = app_base_url();
$hotels = load_json('hotels.json');

header('Content-Type: application/xml; charset=utf-8');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

$pages = ['/', '/search.php', '/favorites.php'];
foreach ($pages as $p) {
    echo '<url><loc>' . htmlspecialchars($base . $p, ENT_XML1) . '</loc><changefreq>weekly</changefreq></url>' . "\n";
}

$cities = [];
foreach ($hotels as $h) {
    if (!in_array($h['city'], $cities, true)) $cities[] = $h['city'];
}
foreach ($cities as $c) {
    echo '<url><loc>' . htmlspecialchars($base . '/search.php?city=' . urlencode($c), ENT_XML1) . '</loc><changefreq>weekly</changefreq></url>' . "\n";
}

foreach ($hotels as $h) {
    echo '<url><loc>' . htmlspecialchars($base . '/hotel.php?id=' . (int) $h['id'], ENT_XML1) . '</loc><changefreq>weekly</changefreq></url>' . "\n";
}

echo '</urlset>' . "\n";
