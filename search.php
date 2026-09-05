<?php
// Страница поиска отелей
require __DIR__ . '/api/config.php';

$type = $_GET['type'] ?? 'all';
$city = trim((string) ($_GET['city'] ?? ''));
$cities = load_json('cities.json');
$checkin = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['checkin'] ?? '')) ? (string) $_GET['checkin'] : date('Y-m-d', strtotime('+14 days'));
$checkout = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['checkout'] ?? '')) ? (string) $_GET['checkout'] : date('Y-m-d', strtotime('+16 days'));
$guests = max(1, min(8, (int) ($_GET['guests'] ?? 2)));
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Поиск отелей — Travel.ru</title>
    <meta name="description" content="Найдите отель под ваш бюджет и пожелания.">
        <script src="/assets/js/theme.js"></script>
<link rel="stylesheet" href="/assets/css/tailwind.min.css">
    <link rel="stylesheet" href="/assets/css/styles.css">
    <link rel="icon" type="image/svg+xml" href="/assets/img/favicon.svg">
</head>
<body class="min-h-screen bg-slate-50 text-slate-900">

<?php include __DIR__ . '/components/header.php'; ?>

<main class="mx-auto max-w-7xl px-4 py-8 sm:px-6">
    <div class="mb-6">
        <h1 class="text-3xl font-extrabold text-slate-900">Поиск отелей</h1>
        <p class="mt-1 text-slate-500">Найдено отелей: <b id="result-count">…</b></p>
    </div>

    <div class="grid gap-6 lg:grid-cols-[280px_1fr]">
        <div class="lg:sticky lg:top-20 lg:self-start"><?php include __DIR__ . '/components/filters.php'; ?></div>

        <div>
            <form id="search-city-form" class="mb-6" autocomplete="off">
                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-[1.5fr_1fr_1fr_.65fr_auto]">
                    <div class="relative sm:col-span-2 xl:col-span-1">
                        <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400"><svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s-7-5.5-7-11a7 7 0 0 1 14 0c0 5.5-7 11-7 11z"></path><circle cx="12" cy="10" r="2.6"></circle></svg></span>
                        <input type="text" id="search-city" list="search-city-list" value="<?= htmlspecialchars($city) ?>" placeholder="Город: Сочи, Париж, Рим…" class="w-full rounded-xl border border-slate-300 bg-white px-10 py-3 text-slate-900 placeholder:text-slate-400 focus:border-teal-500 focus:outline-none">
                        <datalist id="search-city-list">
                            <?php foreach ($cities as $c): ?>
                                <option value="<?= htmlspecialchars($c['name']) ?>"><?= htmlspecialchars($c['country']) ?></option>
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                    <label class="rounded-xl border border-slate-300 bg-white px-3 py-1 text-xs font-semibold text-slate-500">Заезд
                        <input id="search-checkin" name="checkin" type="date" min="<?= date('Y-m-d') ?>" value="<?= htmlspecialchars($checkin) ?>" class="block w-full bg-transparent py-1 text-sm text-slate-900 focus:outline-none">
                    </label>
                    <label class="rounded-xl border border-slate-300 bg-white px-3 py-1 text-xs font-semibold text-slate-500">Выезд
                        <input id="search-checkout" name="checkout" type="date" min="<?= htmlspecialchars($checkin) ?>" value="<?= htmlspecialchars($checkout) ?>" class="block w-full bg-transparent py-1 text-sm text-slate-900 focus:outline-none">
                    </label>
                    <label class="rounded-xl border border-slate-300 bg-white px-3 py-1 text-xs font-semibold text-slate-500">Гостей
                        <select id="search-guests" name="guests" class="block w-full bg-transparent py-1 text-sm text-slate-900 focus:outline-none"><?php for ($g = 1; $g <= 8; $g++): ?><option value="<?= $g ?>" <?= $g === $guests ? 'selected' : '' ?>><?= $g ?></option><?php endfor; ?></select>
                    </label>
                    <button type="submit" class="rounded-xl bg-gradient-to-r from-blue-600 to-teal-500 px-6 py-3 font-semibold text-white transition hover:shadow-lg sm:col-span-2 xl:col-span-1">Найти</button>
                </div>
                <p id="search-city-error" class="mt-2 hidden text-sm font-medium text-rose-500"></p>
            </form>
            <div id="recent-searches" class="mb-6 hidden flex flex-wrap items-center gap-2"></div>

            <div id="hotel-grid" class="grid grid-cols-1 gap-6 sm:grid-cols-2 xl:grid-cols-3"></div>
            <div id="empty-state" class="hidden rounded-2xl border border-dashed border-slate-300 bg-white py-16 text-center">
                <div class="flex justify-center text-slate-300"><svg class="h-14 w-14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"></circle><line x1="16.5" y1="16.5" x2="21" y2="21"></line></svg></div>
                <p class="mt-3 font-semibold text-slate-600">По вашим фильтрам ничего не нашлось</p>
                <p class="mt-1 text-sm text-slate-400">Попробуйте ослабить условия поиска</p>
                <button id="empty-reset" class="mt-4 rounded-full bg-teal-500 px-6 py-2 text-sm font-semibold text-white transition hover:bg-teal-600">Сбросить фильтры</button>
            </div>
        </div>
    </div>

    <!-- Скрытые поля активного состояния для JS -->
    <span id="active-type" class="hidden" data-value="<?= htmlspecialchars($type) ?>"></span>
    <span id="active-city" class="hidden" data-value="<?= htmlspecialchars($city) ?>"></span>
</main>

<?php include __DIR__ . '/components/footer.php'; ?>

<script src="/assets/js/favs.js"></script>
<script src="/assets/js/currency.js"></script>
<script src="/assets/js/compare.js"></script>
<script src="/assets/js/chat.js"></script>
<script src="/assets/js/recent.js"></script>
<script src="/assets/js/search.js"></script>
</body>
</html>
