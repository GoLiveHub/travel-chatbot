<?php
// Главная страница
require __DIR__ . '/api/config.php';

$hotels = load_json('hotels.json');
$cities = load_json('cities.json');

// Популярные: топ по рейтингу
usort($hotels, fn($a, $b) => $b['rating'] <=> $a['rating']);
$featured = array_slice($hotels, 0, 6);
$beach = array_values(array_filter($hotels, fn($h) => $h['type'] === 'beach'));
$mountain = array_values(array_filter($hotels, fn($h) => $h['type'] === 'mountain'));
$beach = array_slice($beach, 0, 3);
$mountain = array_slice($mountain, 0, 3);
$defaultCheckin = date('Y-m-d', strtotime('+14 days'));
$defaultCheckout = date('Y-m-d', strtotime('+16 days'));
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Travel.ru — поиск и бронирование отелей</title>
    <meta name="description" content="Умный поиск отелей по всему миру: пляжный, горнолыжный и городской отдых.">
    <meta property="og:title" content="Travel.ru — поиск и бронирование отелей">
    <meta property="og:description" content="Пляжи, горы и города — более 30 проверенных отелей. Поиск, сравнение и бронирование в несколько кликов.">
    <meta property="og:type" content="website">
    <meta property="og:image" content="/assets/img/hero.jpg">
        <script src="/assets/js/theme.js"></script>
<link rel="stylesheet" href="/assets/css/tailwind.min.css">
    <link rel="stylesheet" href="/assets/css/styles.css">
    <link rel="icon" type="image/svg+xml" href="/assets/img/favicon.svg">
</head>
<body class="min-h-screen bg-slate-50 text-slate-900">

<?php include __DIR__ . '/components/header.php'; ?>

<!-- Герой -->
<section class="relative overflow-hidden bg-gradient-to-br from-blue-700 via-blue-600 to-teal-500">
    <div class="absolute inset-0 opacity-15" style="background-image:url('/assets/img/hero.jpg');background-size:cover;background-position:center;"></div>
    <div class="relative mx-auto max-w-7xl px-4 py-20 text-center sm:px-6 sm:py-28">
        <h1 class="text-4xl font-extrabold text-white sm:text-5xl">Найдите отель для <span class="text-teal-200">идеального отдыха</span></h1>
        <p class="mx-auto mt-4 max-w-2xl text-lg text-white/80">Пляжи, горы и города — более 30 проверенных отелей. Поиск, сравнение и бронирование в несколько кликов.</p>

        <!-- Поисковая форма -->
        <form id="hero-search" action="/search.php" method="get" class="mx-auto mt-8 grid max-w-5xl gap-2 rounded-2xl bg-white/95 p-3 text-left shadow-2xl backdrop-blur sm:grid-cols-2 lg:grid-cols-[1.4fr_1fr_1fr_.7fr_auto]">
            <div class="relative">
                <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400">📍</span>
                <input type="text" name="city" list="city-list" id="hero-city" placeholder="Куда едем?" aria-label="Город" class="w-full rounded-xl border border-slate-200 bg-white px-10 py-3 text-slate-900 placeholder:text-slate-400 focus:border-teal-500 focus:outline-none">
                <datalist id="city-list">
                    <?php foreach ($cities as $c): ?>
                        <option value="<?= htmlspecialchars($c['name']) ?>"><?= htmlspecialchars($c['name']) ?></option>
                    <?php endforeach; ?>
                </datalist>
            </div>
            <label class="rounded-xl border border-slate-200 bg-white px-3 py-1 text-xs font-semibold text-slate-500">Заезд
                <input type="date" name="checkin" value="<?= $defaultCheckin ?>" min="<?= date('Y-m-d') ?>" class="block w-full bg-transparent py-1 text-sm text-slate-900 focus:outline-none">
            </label>
            <label class="rounded-xl border border-slate-200 bg-white px-3 py-1 text-xs font-semibold text-slate-500">Выезд
                <input type="date" name="checkout" value="<?= $defaultCheckout ?>" min="<?= $defaultCheckin ?>" class="block w-full bg-transparent py-1 text-sm text-slate-900 focus:outline-none">
            </label>
            <label class="rounded-xl border border-slate-200 bg-white px-3 py-1 text-xs font-semibold text-slate-500">Гостей
                <select name="guests" class="block w-full bg-transparent py-1 text-sm text-slate-900 focus:outline-none"><option value="1">1</option><option value="2" selected>2</option><option value="3">3</option><option value="4">4</option><option value="5">5</option><option value="6">6</option><option value="7">7</option><option value="8">8</option></select>
            </label>
            <button type="submit" class="rounded-xl bg-gradient-to-r from-blue-600 to-teal-500 px-8 py-3 font-semibold text-white transition hover:shadow-lg sm:col-span-2 lg:col-span-1">Найти</button>
        </form>

        <div class="mt-6 flex flex-wrap justify-center gap-2 text-sm">
            <a href="/search.php?type=beach" class="rounded-full bg-white/15 px-4 py-1.5 text-white backdrop-blur transition hover:bg-white/30">🏖 Пляжный отдых</a>
            <a href="/search.php?type=mountain" class="rounded-full bg-white/15 px-4 py-1.5 text-white backdrop-blur transition hover:bg-white/30">⛷ Горнолыжный</a>
            <a href="/search.php" class="rounded-full bg-white/15 px-4 py-1.5 text-white backdrop-blur transition hover:bg-white/30">🏙 Все отели</a>
        </div>
    </div>
</section>

<!-- Статистика -->
<section class="border-b border-slate-200 bg-white">
    <div class="mx-auto grid max-w-7xl grid-cols-2 gap-6 px-4 py-8 text-center sm:px-6 md:grid-cols-4">
        <div><div class="text-3xl font-extrabold text-teal-600"><?= count($hotels) ?>+</div><div class="text-sm text-slate-500">отелей</div></div>
        <div><div class="text-3xl font-extrabold text-teal-600"><?= count($cities) ?></div><div class="text-sm text-slate-500">направлений</div></div>
        <div><div class="text-3xl font-extrabold text-teal-600">24/7</div><div class="text-sm text-slate-500">поддержка</div></div>
        <div><div class="text-3xl font-extrabold text-teal-600">50 000+</div><div class="text-sm text-slate-500">туристов</div></div>
    </div>
</section>

<!-- Каталог с вкладками -->
<section class="mx-auto max-w-7xl px-4 py-12 sm:px-6">
    <div class="mb-8 flex flex-col items-center justify-between gap-4 sm:flex-row">
        <h2 class="text-3xl font-extrabold text-slate-900">Популярные отели</h2>
        <div class="flex gap-2 rounded-full border border-slate-200 bg-white p-1">
            <button class="home-tab rounded-full px-4 py-1.5 text-sm font-semibold tab-active bg-teal-500 text-white" data-tab="all">Популярное</button>
            <button class="home-tab rounded-full border border-slate-300 px-4 py-1.5 text-sm font-semibold text-slate-600 transition" data-tab="beach">Пляж</button>
            <button class="home-tab rounded-full border border-slate-300 px-4 py-1.5 text-sm font-semibold text-slate-600 transition" data-tab="mountain">Горы</button>
        </div>
    </div>

    <div id="pane-all" class="home-pane grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
        <?php foreach ($featured as $hotel): ?>
            <div class="fade-in-up"><?php include __DIR__ . '/components/hotel-card.php'; ?></div>
        <?php endforeach; ?>
    </div>
    <div id="pane-beach" class="home-pane hidden grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
        <?php foreach ($beach as $hotel): ?>
            <div class="fade-in-up"><?php include __DIR__ . '/components/hotel-card.php'; ?></div>
        <?php endforeach; ?>
    </div>
    <div id="pane-mountain" class="home-pane hidden grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
        <?php foreach ($mountain as $hotel): ?>
            <div class="fade-in-up"><?php include __DIR__ . '/components/hotel-card.php'; ?></div>
        <?php endforeach; ?>
    </div>
</section>

<!-- Направления -->
<section class="bg-white">
    <div class="mx-auto max-w-7xl px-4 py-12 sm:px-6">
        <h2 class="text-3xl font-extrabold text-slate-900">Популярные направления</h2>
        <div class="mt-6 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
            <?php foreach ($cities as $i => $c): ?>
                <a href="/search.php?city=<?= urlencode($c['name']) ?>" class="group relative overflow-hidden rounded-2xl aspect-[3/4]">
                    <img src="/assets/<?= htmlspecialchars($c['image']) ?>" alt="<?= htmlspecialchars($c['name']) ?>" class="h-full w-full object-cover transition duration-300 group-hover:scale-105" loading="lazy" onerror="this.style.opacity='0.6'">
                    <div class="absolute inset-0 bg-gradient-to-t from-black/60 to-transparent"></div>
                    <div class="absolute bottom-3 left-3 right-3 text-white">
                        <div class="font-bold"><?= htmlspecialchars($c['name']) ?></div>
                        <div class="text-xs text-white/80"><?= $c['hotels'] ?? count(array_filter($hotels, fn($h) => $h['city'] === $c['name'])) ?> отелей</div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- Отзывы гостей -->
<section class="bg-gradient-to-b from-white to-slate-50">
    <div class="mx-auto max-w-7xl px-4 py-12 sm:px-6">
        <h2 class="text-center text-3xl font-extrabold text-slate-900">Что говорят гости</h2>
        <div class="mt-8 grid gap-6 md:grid-cols-3">
            <?php
            $testimonials = [
                ['name' => 'Анна', 'city' => 'Москва', 'hotel' => 'Hotel & Spa',
                 'text' => 'Забронировали через Travel.ru впервые — всё чётко: подтверждение пришло мгновенно, промокод применился, на месте никаких сюрпризов.',
                 'stars' => 5, 'initial' => 'А'],
                ['name' => 'Дмитрий', 'city' => 'Санкт-Петербург', 'hotel' => 'Grand Hotel',
                 'text' => 'Сравнил несколько отелей на одной странице и выбрал по цене и рейтингу. Очень удобно, что можно отфильтровать по звёздам и удобствам.',
                 'stars' => 5, 'initial' => 'Д'],
                ['name' => 'Мария', 'city' => 'Казань', 'hotel' => 'San Remo Hotel',
                 'text' => 'Понравился чат-помощник: написала «отель у моря с бассейном до 15 000» — и он сразу подобрал варианты. Спасли, когда не было времени на долгий поиск.',
                 'stars' => 4, 'initial' => 'М'],
            ];
            foreach ($testimonials as $t):
            ?>
            <figure class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <div class="flex text-amber-400"><?= str_repeat('★', $t['stars']) ?><?= str_repeat('☆', 5 - $t['stars']) ?></div>
                <blockquote class="mt-3 text-sm leading-relaxed text-slate-600">«<?= htmlspecialchars($t['text']) ?>»</blockquote>
                <figcaption class="mt-4 flex items-center gap-3">
                    <div class="grid h-10 w-10 place-items-center rounded-full bg-gradient-to-r from-blue-600 to-teal-500 font-bold text-white"><?= $t['initial'] ?></div>
                    <div>
                        <div class="text-sm font-bold text-slate-900"><?= htmlspecialchars($t['name']) ?></div>
                        <div class="text-xs text-slate-400"><?= htmlspecialchars($t['city']) ?> · отель «<?= htmlspecialchars($t['hotel']) ?>»</div>
                    </div>
                </figcaption>
            </figure>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- Преимущества -->
<section class="mx-auto max-w-7xl px-4 py-12 sm:px-6">
    <h2 class="text-center text-3xl font-extrabold text-slate-900">Почему Travel.ru?</h2>
    <div class="mt-8 grid gap-6 sm:grid-cols-3">
        <div class="rounded-2xl border border-slate-200 bg-white p-6 text-center">
            <div class="mx-auto grid h-12 w-12 place-items-center rounded-full bg-teal-50 text-2xl">🛡</div>
            <h3 class="mt-4 font-bold text-slate-900">Безопасная бронь</h3>
            <p class="mt-2 text-sm text-slate-500">Подтверждение бронирования сразу, честные цены без скрытых платежей.</p>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-white p-6 text-center">
            <div class="mx-auto grid h-12 w-12 place-items-center rounded-full bg-teal-50 text-2xl">🤖</div>
            <h3 class="mt-4 font-bold text-slate-900">Умный помощник</h3>
            <p class="mt-2 text-sm text-slate-500">Чат-бот подберёт отель по вашим пожеланиям на естественном языке.</p>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-white p-6 text-center">
            <div class="mx-auto grid h-12 w-12 place-items-center rounded-full bg-teal-50 text-2xl">💬</div>
            <h3 class="mt-4 font-bold text-slate-900">Поддержка 24/7</h3>
            <p class="mt-2 text-sm text-slate-500">Отвечаем на вопросы до, во время и после поездки.</p>
        </div>
    </div>
</section>

<?php include __DIR__ . '/components/footer.php'; ?>

<script src="/assets/js/favs.js"></script>
<script src="/assets/js/currency.js"></script>
<script src="/assets/js/compare.js"></script>
<script src="/assets/js/chat.js"></script>
<script src="/assets/js/main.js"></script>
</body>
</html>
