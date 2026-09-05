<?php
// Страница избранного: отели, сохранённые через кнопку-сердце
require __DIR__ . '/api/config.php';

$hotels = load_json('hotels.json');
$favIds = [];
if (isset($_COOKIE['travel_favs']) && $_COOKIE['travel_favs'] !== '') {
    $decoded = json_decode($_COOKIE['travel_favs'], true);
    if (is_array($decoded)) {
        $favIds = array_map('strval', $decoded);
    }
}

$favHotels = array_values(array_filter($hotels, fn($h) => in_array(strval($h['id']), $favIds, true)));
$total = count($hotels);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Избранное — Travel.ru</title>
    <meta name="description" content="Сохранённые отели.">
        <script src="/assets/js/theme.js?v=20260901-c"></script>
<link rel="stylesheet" href="/assets/css/tailwind.min.css">
    <link rel="stylesheet" href="/assets/css/styles.css?v=20260901-c">
    <link rel="icon" type="image/svg+xml" href="/assets/img/favicon.svg">
</head>
<body class="min-h-screen bg-slate-50 text-slate-900">

<?php include __DIR__ . '/components/header.php'; ?>

<main class="mx-auto max-w-7xl px-4 py-8 sm:px-6">
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-3xl font-extrabold text-slate-900">Избранное</h1>
            <p class="mt-1 text-slate-500">Сохранено отелей: <b id="fav-page-count"><?= count($favHotels) ?></b></p>
        </div>
        <?php if ($favHotels): ?>
            <button id="fav-clear" class="rounded-full border border-slate-300 bg-white px-5 py-2 text-sm font-semibold text-slate-600 transition hover:bg-slate-50">Очистить всё</button>
        <?php endif; ?>
    </div>

    <div id="fav-empty" class="<?= $favHotels ? 'hidden' : '' ?> rounded-2xl border border-dashed border-slate-300 bg-white py-20 text-center">
        <div class="flex justify-center text-slate-300"><svg class="h-16 w-16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20.5s-7.5-4.6-9.3-9a4.7 4.7 0 0 1 8.6-2.6A4.7 4.7 0 0 1 21.3 11.5c-1.8 4.4-9.3 9-9.3 9z"></path></svg></div>
        <p class="mt-4 text-lg font-semibold text-slate-700">В избранном пока пусто</p>
        <p class="mt-1 text-sm text-slate-400">Нажимайте на сердечко в карточке отеля, чтобы сохранить его здесь.</p>
        <a href="/search.php" class="mt-6 inline-block rounded-full bg-gradient-to-r from-blue-600 to-teal-500 px-8 py-3 font-semibold text-white transition hover:shadow-lg">Подобрать отель</a>
    </div>

    <div id="fav-grid" class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
        <?php foreach ($favHotels as $hotel): ?>
            <div class="reveal"><?php include __DIR__ . '/components/hotel-card.php'; ?></div>
        <?php endforeach; ?>
    </div>
</main>

<?php include __DIR__ . '/components/footer.php'; ?>

<script>window.__favTotalHotels = <?= $total ?>;</script>
<script src="/assets/js/favs.js"></script>
<script src="/assets/js/currency.js"></script>
<script src="/assets/js/compare.js"></script>
<script src="/assets/js/chat.js"></script>
<script src="/assets/js/favorites.js"></script>
</body>
</html>
