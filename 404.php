<?php
// Страница 404
require __DIR__ . '/api/config.php';
http_response_code(404);
$hotels = load_json('hotels.json');
$popular = array_slice($hotels, 0, 3);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Страница не найдена — Travel.ru</title>
    <meta name="robots" content="noindex">
        <script src="/assets/js/theme.js"></script>
<link rel="stylesheet" href="/assets/css/tailwind.min.css">
    <link rel="stylesheet" href="/assets/css/styles.css">
    <link rel="icon" type="image/svg+xml" href="/assets/img/favicon.svg">
</head>
<body class="min-h-screen bg-slate-50 text-slate-900">

<?php include __DIR__ . '/components/header.php'; ?>

<main class="mx-auto max-w-3xl px-4 py-20 text-center sm:px-6">
    <div class="text-7xl font-extrabold tracking-tight text-slate-200">404</div>
    <div class="-mt-6 text-5xl">🧭</div>
    <h1 class="mt-4 text-3xl font-extrabold text-slate-900">Кажется, вы сбились с пути</h1>
    <p class="mt-2 text-slate-500">Страница не найдена или была удалена. Зато отличные отели никуда не делись.</p>
    <div class="mt-6 flex flex-wrap justify-center gap-3">
        <a href="/" class="rounded-full bg-gradient-to-r from-blue-600 to-teal-500 px-8 py-3 font-semibold text-white transition hover:shadow-lg">На главную</a>
        <a href="/search.php" class="rounded-full border border-slate-300 bg-white px-8 py-3 font-semibold text-slate-700 transition hover:bg-slate-50">Искать отели</a>
    </div>
</main>

<?php if ($popular): ?>
<section class="mx-auto max-w-5xl px-4 pb-16 sm:px-6">
    <h2 class="mb-6 text-2xl font-extrabold text-slate-900">Возможно, вы искали:</h2>
    <div class="grid grid-cols-1 gap-6 sm:grid-cols-3">
        <?php foreach ($popular as $hotel): ?>
            <?php include __DIR__ . '/components/hotel-card.php'; ?>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<?php include __DIR__ . '/components/footer.php'; ?>

<script src="/assets/js/favs.js"></script>
<script src="/assets/js/currency.js"></script>
<script src="/assets/js/compare.js"></script>
<script src="/assets/js/chat.js"></script>
</body>
</html>
