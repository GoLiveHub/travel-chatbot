<?php
// Динамическая генерация sitemap.xml
require __DIR__ . '/api/config.php';

header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=3600');

$base = app_base_url();
$hotels = load_json('hotels.json');

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

$pages = ['/' => 'daily', '/search.php' => 'weekly'];
foreach ($pages as $p => $freq) {
    echo '<url><loc>' . htmlspecialchars($base . $p, ENT_XML1) . '</loc><changefreq>' . $freq . '</changefreq><priority>1.0</priority></url>' . "\n";
}

$cities = [];
foreach ($hotels as $h) {
    $city = $h['city'] ?? '';
    if ($city !== '' && !in_array($city, $cities, true)) $cities[] = $city;
}
foreach ($cities as $c) {
    echo '<url><loc>' . htmlspecialchars($base . '/search.php?city=' . rawurlencode($c), ENT_XML1) . '</loc><changefreq>weekly</changefreq></url>' . "\n";
}

$lastmod = date('Y-m-d');
foreach ($hotels as $h) {
    echo '<url><loc>' . htmlspecialchars($base . '/hotel.php?id=' . (int) ($h['id'] ?? 0), ENT_XML1) . '</loc><lastmod>' . $lastmod . '</lastmod><changefreq>weekly</changefreq></url>' . "\n";
}

echo '</urlset>' . "\n";
