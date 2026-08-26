<?php
// Страница конкретного отеля
require __DIR__ . '/api/config.php';

$id = (int) ($_GET['id'] ?? 0);
$hotel = null;
$hotels = load_json('hotels.json');
foreach ($hotels as $h) {
    if ((int) $h['id'] === $id) { $hotel = $h; break; }
}
if ($hotel === null) {
    http_response_code(404);
    $notFound = true;
}

$related = [];
$userReviews = [];
if ($hotel) {
    // Пользовательские отзывы из журнала reviews.log
    $revLogPath = data_path('reviews.log');
    if (file_exists($revLogPath)) {
        $revLines = file($revLogPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (is_array($revLines)) {
            foreach (array_reverse($revLines) as $line) {
            if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} \| (.*)$/', trim($line), $m)) {
                $data = json_decode($m[1], true);
                if (is_array($data) && (int) ($data['hotel_id'] ?? 0) === $id) {
                    $userReviews[] = ['ts' => substr($line, 0, 19)] + $data;
                }
            }
        }
        $userReviews = array_slice($userReviews, 0, 5);
        }
    }

    $related = array_values(array_filter($hotels, fn($h) => $h['id'] !== $hotel['id'] && $h['city'] === $hotel['city']));
    if (count($related) < 3) {
        foreach ($hotels as $h) {
            if ($h['id'] !== $hotel['id'] && count($related) < 3 && !in_array($h, $related, true)) $related[] = $h;
        }
    }
    $related = array_slice($related, 0, 3);
}
$baseUrl = app_base_url();
$amenityLabels = [
    'wifi' => 'Wi-Fi', 'breakfast' => 'Завтрак', 'pool' => 'Бассейн',
    'spa' => 'Спа', 'bar' => 'Бар', 'gym' => 'Фитнес', 'beach' => 'Пляж',
    'kitchen' => 'Кухня', 'mountain' => 'Горы', 'ski' => 'Лыжи',
    'airport' => 'Трансфер', 'parking' => 'Парковка', 'all-inclusive' => 'Всё включено',
    'kid-club' => 'Детский клуб', 'fireplace' => 'Камин', 'terrace' => 'Терраса',
];
// Оценка по критериям (детерминированная, из рейтинга и данных отеля)
function cat_score(float $base, int $id, int $seed, float $drift = 0.2): float
{
    $v = $base + ((($id * $seed) % 11) - 5) * $drift;
    return round(max(1.0, min(10.0, $v)), 1);
}
$catScores = [];
if ($hotel) {
    $type = $hotel['type'] ?? 'city';
    $dist = (float) ($hotel['center_distance'] ?? 0);
    $location = $type === 'mountain' ? 9.3 : round(max(1.0, min(10.0, 10.6 - min($dist, 10) * 0.85)), 1);
    $value = round(max(1.0, min(10.0, (float) $hotel['rating'] + 1.6 - (float) $hotel['price'] / 11000)), 1);
    $catScores = [
        'Чистота' => cat_score((float) $hotel['rating'], (int) $hotel['id'], 7),
        'Комфорт' => cat_score((float) $hotel['rating'], (int) $hotel['id'], 3),
        'Расположение' => $location,
        'Сервис' => cat_score((float) $hotel['rating'], (int) $hotel['id'], 5),
        'Цена / качество' => $value,
    ];
}
$defaultCheckin = date('Y-m-d', strtotime('+14 days'));
$defaultCheckout = date('Y-m-d', strtotime('+16 days'));
$today = date('Y-m-d');
$requestedGuests = max(1, min(8, (int) ($_GET['guests'] ?? 2)));
try {
    $requestedCheckin = new DateTime((string) ($_GET['checkin'] ?? ''));
    $requestedCheckout = new DateTime((string) ($_GET['checkout'] ?? ''));
    if ($requestedCheckin->format('Y-m-d') >= $today && $requestedCheckout > $requestedCheckin
        && (int) $requestedCheckin->diff($requestedCheckout)->days <= 30) {
        $defaultCheckin = $requestedCheckin->format('Y-m-d');
        $defaultCheckout = $requestedCheckout->format('Y-m-d');
    }
} catch (Exception $e) {
    // Используем безопасные даты по умолчанию.
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $hotel ? htmlspecialchars($hotel['name']) : 'Отель не найден' ?> — Travel.ru</title>
    <meta name="description" content="<?= $hotel ? htmlspecialchars($hotel['description']) : '' ?>">
        <script src="/assets/js/theme.js"></script>
<link rel="stylesheet" href="/assets/css/tailwind.min.css">
    <link rel="stylesheet" href="/assets/css/styles.css">
    <link rel="icon" type="image/svg+xml" href="/assets/img/favicon.svg">
    <?php if ($hotel): ?>
    <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@type": "Hotel",
      "name": <?= json_encode($hotel['name'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
      "description": <?= json_encode($hotel['description'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
      "image": <?= json_encode($baseUrl . '/assets/' . ($hotel['images'][0] ?? 'img/hotel-1.jpg'), JSON_HEX_TAG | JSON_HEX_AMP) ?>,
      "starRating": { "@type": "Rating", "ratingValue": "<?= (int) ($hotel['stars'] ?? 0) ?>" },
      "aggregateRating": { "@type": "AggregateRating", "ratingValue": "<?= number_format((float) ($hotel['rating'] ?? 0), 1, '.', '') ?>", "bestRating": "10", "reviewCount": "<?= (int) ($hotel['reviews'] ?? 0) ?>" },
      "address": { "@type": "PostalAddress", "addressLocality": <?= json_encode($hotel['city'] ?? '', JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>, "addressCountry": <?= json_encode($hotel['country'] ?? '', JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?> },
      <?php if (isset($hotel['coordinates'][0], $hotel['coordinates'][1])): ?>
      "geo": { "@type": "GeoCoordinates", "latitude": "<?= $hotel['coordinates'][0] ?>", "longitude": "<?= $hotel['coordinates'][1] ?>" },
      <?php endif; ?>
      "priceRange": "<?= (int) $hotel['price'] ?> RUB за ночь"
    }
    </script>
    <link rel="canonical" href="<?= $baseUrl ?>/hotel.php?id=<?= $hotel['id'] ?>">
    <meta property="og:title" content="<?= htmlspecialchars($hotel['name']) ?> — бронирование">
    <meta property="og:description" content="<?= htmlspecialchars($hotel['description']) ?>">
    <meta property="og:image" content="<?= $baseUrl ?>/assets/<?= htmlspecialchars($hotel['images'][0] ?? 'img/hotel-1.jpg') ?>">
    <meta property="og:url" content="<?= $baseUrl ?>/hotel.php?id=<?= $hotel['id'] ?>">
    <?php endif; ?>
</head>
<body class="min-h-screen bg-slate-50 text-slate-900">

<?php include __DIR__ . '/components/header.php'; ?>

<?php if ($notFound ?? false): ?>
<main class="mx-auto max-w-3xl px-4 py-24 text-center sm:px-6">
    <div class="flex justify-center text-slate-300"><svg class="h-16 w-16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 3 3 7v11l6-4 6 4 6-4V7l-6 4-6-4z"></path><path d="M9 7v11"></path><path d="M15 11v11"></path></svg></div>
    <h1 class="mt-4 text-3xl font-extrabold text-slate-900">Отель не найден</h1>
    <p class="mt-2 text-slate-500">Возможно, он был удалён или ссылка неверна.</p>
    <a href="/search.php" class="mt-6 inline-block rounded-full bg-gradient-to-r from-blue-600 to-teal-500 px-8 py-3 font-semibold text-white">К списку отелей</a>
</main>
<?php else: ?>

<?php
$mainImg = isset($hotel['images'][0]) ? '/assets/' . $hotel['images'][0] : '/assets/img/hotel-1.jpg';
$extraImgs = array_slice($hotel['images'] ?? [], 0, 4);
$hotelStars = (int) ($hotel['stars'] ?? 0);
$hotelRating = (float) ($hotel['rating'] ?? 0);
$hotelReviews = (int) ($hotel['reviews'] ?? 0);
$hotelAmenities = $hotel['amenities'] ?? [];
$hotelBadge = $hotel['badge'] ?? '';
?>

<main class="mx-auto max-w-7xl px-4 py-8 sm:px-6">
    <!-- Хлебные крошки -->
    <nav class="mb-4 text-sm text-slate-500">
        <a href="/" class="hover:text-teal-600">Главная</a> ›
        <a href="/search.php" class="hover:text-teal-600">Отели</a> ›
        <span class="text-slate-900"><?= htmlspecialchars($hotel['name']) ?></span>
    </nav>

    <div class="grid gap-8 lg:grid-cols-[1fr_380px]">
        <!-- Левая колонка -->
        <div>
            <!-- Галерея -->
            <div id="gallery">
                <div class="relative overflow-hidden rounded-2xl">
                    <img id="gallery-main" src="<?= htmlspecialchars($mainImg) ?>" alt="<?= htmlspecialchars($hotel['name']) ?>" class="aspect-[16/9] w-full object-cover">
                    <span class="absolute left-4 top-4 rounded-full bg-white/90 px-3 py-1 text-sm font-bold text-slate-800 shadow"><?= str_repeat('★', $hotelStars) ?></span>
                </div>
                <?php if (count($extraImgs) > 1): ?>
                <div class="mt-3 grid grid-cols-4 gap-3">
                    <?php foreach ($extraImgs as $i => $img): ?>
                        <button type="button" data-src="/assets/<?= htmlspecialchars($img) ?>" aria-label="Фото <?= $i + 1 ?>: <?= htmlspecialchars($hotel['name']) ?>" class="overflow-hidden rounded-xl <?= $i === 0 ? 'ring-2 ring-teal-500' : '' ?>">
                            <img src="/assets/<?= htmlspecialchars($img) ?>" alt="<?= htmlspecialchars($hotel['name'] . ' — фото ' . ($i + 1)) ?>" class="aspect-video w-full object-cover" loading="lazy">
                        </button>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Название и рейтинг -->
            <div class="mt-6 flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h1 class="text-3xl font-extrabold text-slate-900"><?= htmlspecialchars($hotel['name']) ?></h1>
                    <p class="mt-1 text-slate-500"><?= htmlspecialchars($hotel['city']) ?>, <?= htmlspecialchars($hotel['country']) ?>
                        <?php if (!empty($hotelBadge)): ?>
                            <span class="ml-2 rounded-full bg-teal-500 px-2.5 py-0.5 text-xs font-bold text-white"><?= htmlspecialchars($hotelBadge) ?></span>
                        <?php endif; ?>
                    </p>
                </div>
                <div class="flex items-center gap-3">
                    <div class="text-right">
                        <div class="text-lg font-extrabold text-slate-900"><?= number_format($hotelRating, 1, '.', '') ?></div>
                        <div class="text-xs text-slate-400"><?= $hotelReviews ?> отзывов</div>
                    </div>
                    <div class="grid h-14 w-14 place-items-center rounded-xl bg-gradient-to-r from-blue-600 to-teal-500 text-lg font-extrabold text-white"><?= number_format($hotelRating, 1, '.', '') ?></div>
                </div>
            </div>

            <div class="mt-4 flex flex-wrap gap-2">
<button id="share-btn" aria-label="Поделиться ссылкой на отель" class="rounded-full border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">Поделиться</button>
                <a href="https://wa.me/?text=<?= urlencode('Отель «' . $hotel['name'] . '» — ' . $hotel['city'] . '. Смотреть: ' . $baseUrl . '/hotel.php?id=' . $hotel['id']) ?>" target="_blank" rel="noopener" class="rounded-full border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">WhatsApp</a>
                <a href="https://t.me/share/url?url=<?= urlencode($baseUrl . '/hotel.php?id=' . $hotel['id']) ?>&amp;text=<?= urlencode('Отель «' . $hotel['name'] . '» — ' . $hotel['city']) ?>" target="_blank" rel="noopener" class="rounded-full border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">Telegram</a>
            </div>

            <!-- Описание -->
            <div class="mt-6 rounded-2xl border border-slate-200 bg-white p-6">
                <h2 class="text-lg font-bold text-slate-900">Об отеле</h2>
                <p class="mt-3 leading-relaxed text-slate-600"><?= nl2br(htmlspecialchars($hotel['description'])) ?></p>

                <h3 class="mt-6 font-bold text-slate-900">Удобства</h3>
                <div class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-3">
                    <?php foreach ($hotelAmenities as $a): ?>
                        <div class="flex items-center gap-2 rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-600">
                            <span class="text-emerald-500">✓</span> <?= htmlspecialchars($amenityLabels[$a] ?? $a) ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php $coords = $hotel['coordinates'] ?? []; ?>
                <?php if (isset($coords[0], $coords[1])): $lat = (float) $coords[0]; $lon = (float) $coords[1]; $d = 0.02; ?>
                <h3 class="mt-6 font-bold text-slate-900">Расположение</h3>
                <p class="mt-1 text-sm text-slate-500"><?= number_format((float) ($hotel['center_distance'] ?? 0), 1, '.', '') ?> км до центра города · координаты <?= number_format($lat, 4, '.', '') ?>, <?= number_format($lon, 4, '.', '') ?></p>
                <div class="mt-3 overflow-hidden rounded-xl border border-slate-200">
                    <iframe
                        title="Карта: <?= htmlspecialchars($hotel['name']) ?>"
                        src="https://www.openstreetmap.org/export/embed.html?bbox=<?= urlencode(($lon - $d) . ',' . ($lat - $d) . ',' . ($lon + $d) . ',' . ($lat + $d)) ?>&amp;layer=mapnik&amp;marker=<?= urlencode($lat . ',' . $lon) ?>"
                        class="h-72 w-full" loading="lazy"></iframe>
                </div>
                <?php endif; ?>
            </div>

            <?php if ($catScores): ?>
            <div class="mt-6 rounded-2xl border border-slate-200 bg-white p-6">
                <h2 class="text-lg font-bold text-slate-900">Оценка по критериям</h2>
                <p class="mt-1 text-sm text-slate-400">Средняя оценка гостей по категориям (из <?= $hotelReviews ?> отзывов)</p>
                <div class="mt-4 space-y-3">
                    <?php foreach ($catScores as $cat => $score): ?>
                        <div class="flex items-center gap-3">
                            <div class="w-40 shrink-0 text-sm text-slate-600"><?= htmlspecialchars($cat) ?></div>
                            <div class="h-2 flex-1 overflow-hidden rounded-full bg-slate-100">
                                <div class="h-full rounded-full bg-gradient-to-r from-teal-500 to-emerald-400" style="width: <?= $score * 10 ?>%"></div>
                            </div>
                            <div class="w-10 text-right text-sm font-bold text-slate-800"><?= number_format($score, 1, '.', '') ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Отзывы -->
            <div class="mt-6 rounded-2xl border border-slate-200 bg-white p-6">
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-bold text-slate-900">Отзывы гостей</h2>
                    <button id="review-toggle" aria-label="Открыть форму отзыва" class="rounded-full border border-teal-500 px-4 py-1.5 text-sm font-semibold text-teal-600 transition hover:bg-teal-50">Написать отзыв</button>
                </div>

                <div id="review-form-wrap" class="mt-4 hidden rounded-2xl border border-slate-200 bg-slate-50 p-4">
                    <form id="review-form" aria-label="Форма отзыва" class="space-y-3">
                        <input type="hidden" id="review-hotel-id" value="<?= (int) $hotel['id'] ?>">
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label for="review-author" class="mb-1 block text-sm font-medium text-slate-700">Имя</label>
                                <input id="review-author" type="text" maxlength="60" placeholder="Ваше имя" class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:border-teal-500 focus:outline-none">
                            </div>
                            <div>
                                <label for="review-rating" class="mb-1 block text-sm font-medium text-slate-700">Оценка</label>
                                <select id="review-rating" class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:border-teal-500 focus:outline-none">
                                    <?php for ($i = 10; $i >= 1; $i--): ?>
                                        <option value="<?= $i ?>"><?= $i ?> / 10<?= $i >= 8 ? ' — отлично' : ($i >= 6 ? ' — хорошо' : ' — плохо') ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                        </div>
                        <div>
                            <label for="review-text" class="mb-1 block text-sm font-medium text-slate-700">Отзыв</label>
                            <textarea id="review-text" rows="3" maxlength="1000" placeholder="Поделитесь впечатлениями об отеле…" class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:border-teal-500 focus:outline-none"></textarea>
                        </div>
                        <div class="flex items-center justify-between">
                            <p id="review-msg" class="hidden text-sm font-medium text-emerald-600"></p>
                            <button type="submit" class="ml-auto rounded-full bg-gradient-to-r from-blue-600 to-teal-500 px-6 py-2 text-sm font-semibold text-white transition hover:shadow-lg">Отправить</button>
                        </div>
                    </form>
                </div>

                <div id="user-reviews" class="mt-4 space-y-4">
                    <?php foreach ($userReviews as $r): ?>
                        <div class="rounded-2xl border border-emerald-100 bg-emerald-50/40 p-4">
                            <div class="flex items-center justify-between">
                                <div class="font-semibold text-slate-800"><?= htmlspecialchars($r['author']) ?></div>
                                <div class="text-sm font-bold text-teal-600"><?= (int) $r['rating'] ?>/10</div>
                            </div>
                            <div class="mt-0.5 text-xs text-slate-400"><?= htmlspecialchars($r['ts']) ?> · новый отзыв</div>
                            <p class="mt-2 text-sm text-slate-600"><?= nl2br(htmlspecialchars($r['text'])) ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="mt-4 space-y-4">
                    <?php foreach (($hotel['reviews_list'] ?? []) as $r): ?>
                        <div class="border-b border-slate-100 pb-4 last:border-0">
                            <div class="flex items-center justify-between">
                                <div class="font-semibold text-slate-800"><?= htmlspecialchars($r['author']) ?></div>
                                <div class="text-sm font-bold text-teal-600"><?= number_format($r['rating'], 1, '.', '') ?></div>
                            </div>
                            <div class="mt-0.5 text-xs text-slate-400"><?= htmlspecialchars($r['date']) ?></div>
                            <p class="mt-2 text-sm text-slate-600"><?= htmlspecialchars($r['text']) ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Правая колонка: бронирование -->
        <aside class="lg:sticky lg:top-20 lg:self-start">
            <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <div class="flex items-end justify-between">
                    <div>
                        <div class="text-3xl font-extrabold text-slate-900"><span data-price-rub="<?= (int) $hotel['price'] ?>"><?= number_format($hotel['price'], 0, '', ' ') ?> ₽</span></div>
                        <div class="text-sm text-slate-400">за ночь</div>
                    </div>
                    <span class="rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-600">✓ Бесплатная отмена</span>
                </div>

                <form id="booking-form" action="/api/booking.php" method="post" aria-label="Бронирование отеля" class="mt-6 space-y-4">
                    <input type="hidden" id="booking-hotel-id" name="hotel_id" value="<?= (int) $hotel['id'] ?>">
                    <div>
                        <label for="booking-name" class="mb-1 block text-sm font-medium text-slate-700">Имя</label>
                        <input id="booking-name" name="name" type="text" placeholder="Иван" class="w-full rounded-xl border border-slate-300 px-4 py-2.5 focus:border-teal-500 focus:outline-none">
                    </div>
                    <div>
                        <label for="booking-phone" class="mb-1 block text-sm font-medium text-slate-700">Телефон</label>
                        <input id="booking-phone" name="phone" type="tel" placeholder="+7 (900) 000-00-00" class="w-full rounded-xl border border-slate-300 px-4 py-2.5 focus:border-teal-500 focus:outline-none">
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label for="booking-checkin" class="mb-1 block text-sm font-medium text-slate-700">Заезд</label>
                            <input id="booking-checkin" name="checkin" type="date" value="<?= $defaultCheckin ?>" min="<?= $today ?>" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none">
                        </div>
                        <div>
                            <label for="booking-checkout" class="mb-1 block text-sm font-medium text-slate-700">Выезд</label>
                            <input id="booking-checkout" name="checkout" type="date" value="<?= $defaultCheckout ?>" min="<?= $defaultCheckin ?>" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none">
                        </div>
                    </div>
                    <div>
                        <label for="booking-guests" class="mb-1 block text-sm font-medium text-slate-700">Гостей</label>
                        <select id="booking-guests" name="guests" class="w-full rounded-xl border border-slate-300 px-4 py-2.5 focus:border-teal-500 focus:outline-none">
                            <?php for ($guestCount = 1; $guestCount <= 8; $guestCount++): ?>
                                <option value="<?= $guestCount ?>" <?= $guestCount === $requestedGuests ? 'selected' : '' ?>><?= $guestCount ?> <?= $guestCount === 1 ? 'гость' : ($guestCount < 5 ? 'гостя' : 'гостей') ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div>
                        <label for="booking-promo" class="mb-1 block text-sm font-medium text-slate-700">Промокод</label>
                        <div class="flex gap-2">
                            <input id="booking-promo" name="promo" type="text" maxlength="20" placeholder="Например: WELCOME10" class="w-full rounded-xl border border-slate-300 px-4 py-2.5 font-mono text-sm uppercase focus:border-teal-500 focus:outline-none">
                            <button type="button" id="promo-check" class="shrink-0 rounded-xl border border-teal-500 px-4 text-sm font-semibold text-teal-600 transition hover:bg-teal-50">Проверить</button>
                        </div>
                        <p id="promo-msg" class="mt-1 hidden text-xs font-medium text-emerald-600"></p>
                    </div>

                    <div class="flex items-center justify-between border-t border-slate-100 pt-4 text-sm">
                        <span class="text-slate-500">Итого:</span>
                        <span id="booking-sum" data-price-rub="<?= (int) $hotel['price'] ?>" class="font-bold text-slate-900"><?= number_format($hotel['price'] * 2, 0, '', ' ') ?> ₽ за 2 ноч.</span>
                    </div>

                    <button type="submit" class="w-full rounded-xl bg-gradient-to-r from-blue-600 to-teal-500 py-3 font-bold text-white transition hover:shadow-lg">Забронировать</button>

                    <p class="text-center text-xs text-slate-400">Демо-версия: оплата не списывается</p>
                </form>
            </div>
        </aside>
    </div>

    <!-- Похожие отели -->
    <?php if ($related): ?>
    <section class="mt-14">
        <h2 class="mb-6 text-2xl font-extrabold text-slate-900">Похожие отели</h2>
        <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
            <?php foreach ($related as $relatedHotel): ?>
                <?php $hotel = $relatedHotel; include __DIR__ . '/components/hotel-card.php'; ?>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>
</main>

<?php endif; ?>

<?php include __DIR__ . '/components/footer.php'; ?>

<script src="/assets/js/config.js"></script>
<script defer src="/assets/js/favs.js"></script>
<script defer src="/assets/js/currency.js"></script>
<script defer src="/assets/js/compare.js"></script>
<script defer src="/assets/js/chat.js"></script>
<script defer src="/assets/js/hotel.js"></script>
<script defer src="/assets/js/review.js"></script>
</body>
</html>
