<?php
// Страница сравнения отелей
require __DIR__ . '/api/config.php';

$ids = [];
if (isset($_GET['ids'])) {
    foreach (explode(',', $_GET['ids']) as $part) {
        $id = (int) trim($part);
        if ($id > 0) $ids[$id] = true;
    }
}

$hotels = [];
if ($ids) {
    $byId = [];
    foreach (load_json('hotels.json') as $h) {
        $byId[(int) $h['id']] = $h;
    }
    foreach (array_keys($ids) as $id) {
        if (isset($byId[$id])) $hotels[] = $byId[$id];
    }
}

$amenityLabels = [
    'wifi' => 'Wi-Fi', 'breakfast' => 'Завтрак', 'pool' => 'Бассейн',
    'spa' => 'Спа', 'bar' => 'Бар', 'gym' => 'Фитнес', 'beach' => 'Пляж',
    'kitchen' => 'Кухня', 'mountain' => 'Горы', 'ski' => 'Лыжи',
    'airport' => 'Трансфер', 'parking' => 'Парковка', 'all-inclusive' => 'Всё включено',
    'kid-club' => 'Детский клуб', 'fireplace' => 'Камин', 'terrace' => 'Терраса',
];
$typeLabels = ['beach' => 'Пляжный', 'mountain' => 'Горнолыжный', 'city' => 'Городской'];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Сравнение отелей — Travel.ru</title>
    <meta name="description" content="Сравните выбранные отели по цене, рейтингу и удобствам.">
        <script src="/assets/js/theme.js"></script>
<link rel="stylesheet" href="/assets/css/tailwind.min.css">
    <link rel="stylesheet" href="/assets/css/styles.css">
    <link rel="icon" type="image/svg+xml" href="/assets/img/favicon.svg">
</head>
<body data-page="compare" class="min-h-screen bg-slate-50 text-slate-900">

<?php include __DIR__ . '/components/header.php'; ?>

<main class="mx-auto max-w-6xl px-4 py-8 sm:px-6">
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-3xl font-extrabold text-slate-900">Сравнение отелей</h1>
            <p class="mt-1 text-slate-500">Выберите до 3 отелей кнопкой ⇄ на карточках — сравнение появится здесь.</p>
        </div>
        <?php if ($hotels): ?>
            <a href="/compare.php" class="rounded-full border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-600 transition hover:border-rose-400 hover:text-rose-600" id="compare-clear-link">Очистить сравнение</a>
        <?php endif; ?>
    </div>

    <?php if (!$hotels): ?>
        <div class="rounded-2xl border border-dashed border-slate-300 bg-white py-16 text-center">
            <div class="text-4xl">⇄</div>
            <p class="mt-3 font-semibold text-slate-600">Ничего не выбрано</p>
            <p class="mx-auto mt-1 max-w-md text-sm text-slate-400">Откройте <a href="/search.php" class="text-teal-600 hover:underline">поиск отелей</a> и нажмите на значок ⇄ у понравившихся отелей, затем нажмите «Сравнить».</p>
        </div>
    <?php else: ?>
        <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
            <table class="w-full min-w-[720px] text-left text-sm">
                <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="w-36 px-4 py-3">Параметр</th>
                        <?php foreach ($hotels as $h): ?>
                            <th class="px-4 py-3"><?= htmlspecialchars($h['name']) ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3 font-semibold text-slate-500">Фото</td>
                        <?php foreach ($hotels as $h): ?>
                            <?php $img = isset($h['images'][0]) ? '/assets/' . $h['images'][0] : '/assets/img/hotel-1.jpg'; ?>
                            <td class="px-4 py-3">
                                <a href="/hotel.php?id=<?= (int) $h['id'] ?>">
                                    <img src="<?= htmlspecialchars($img) ?>" alt="<?= htmlspecialchars($h['name']) ?>" class="aspect-[4/3] w-40 rounded-xl object-cover" loading="lazy" onerror="this.src='/assets/img/hotel-1.jpg'">
                                </a>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3 font-semibold text-slate-500">Рейтинг</td>
                        <?php foreach ($hotels as $h): ?>
                            <td class="px-4 py-3">
                                <span class="font-bold text-teal-600"><?= number_format($h['rating'], 1, '.', '') ?></span>
                                <span class="text-slate-400">· <?= (int) $h['reviews'] ?> отз.</span>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3 font-semibold text-slate-500">Звёзды</td>
                        <?php foreach ($hotels as $h): ?>
                            <td class="px-4 py-3"><?= str_repeat('★', (int) ($h['stars'] ?? 3)) ?><span class="text-slate-300"><?= str_repeat('★', 5 - (int) ($h['stars'] ?? 3)) ?></span></td>
                        <?php endforeach; ?>
                    </tr>
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3 font-semibold text-slate-500">Локация</td>
                        <?php foreach ($hotels as $h): ?>
                            <td class="px-4 py-3"><?= htmlspecialchars($h['city']) ?>, <?= htmlspecialchars($h['country']) ?></td>
                        <?php endforeach; ?>
                    </tr>
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3 font-semibold text-slate-500">Тип отдыха</td>
                        <?php foreach ($hotels as $h): ?>
                            <td class="px-4 py-3"><?= htmlspecialchars($typeLabels[$h['type']] ?? $h['type']) ?></td>
                        <?php endforeach; ?>
                    </tr>
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3 font-semibold text-slate-500">Цена за ночь</td>
                        <?php foreach ($hotels as $h): ?>
                            <td class="px-4 py-3 text-lg font-extrabold text-slate-900"><span data-price-rub="<?= (int) $h['price'] ?>"><?= number_format($h['price'], 0, '', ' ') ?> ₽</span></td>
                        <?php endforeach; ?>
                    </tr>
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3 font-semibold text-slate-500">Удобства</td>
                        <?php foreach ($hotels as $h): ?>
                            <td class="px-4 py-3">
                                <div class="flex max-w-xs flex-wrap gap-1">
                                    <?php foreach ($h['amenities'] as $a): ?>
                                        <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600"><?= htmlspecialchars($amenityLabels[$a] ?? $a) ?></span>
                                    <?php endforeach; ?>
                                </div>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3 font-semibold text-slate-500">О номерах</td>
                        <?php foreach ($hotels as $h): ?>
                            <td class="px-4 py-3 text-slate-600"><?= htmlspecialchars($h['rooms'] ?? '') ?></td>
                        <?php endforeach; ?>
                    </tr>
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3 font-semibold text-slate-500">Описание</td>
                        <?php foreach ($hotels as $h): ?>
                            <td class="max-w-xs px-4 py-3 text-xs leading-relaxed text-slate-500"><?= htmlspecialchars(mb_strimwidth($h['description'] ?? '', 0, 160, '…')) ?></td>
                        <?php endforeach; ?>
                    </tr>
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3 font-semibold text-slate-500">Действие</td>
                        <?php foreach ($hotels as $h): ?>
                            <td class="px-4 py-3">
                                <a href="/hotel.php?id=<?= (int) $h['id'] ?>" class="rounded-full bg-gradient-to-r from-blue-600 to-teal-500 px-4 py-2 text-center text-sm font-semibold text-white transition hover:shadow">Смотреть</a>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</main>

<?php include __DIR__ . '/components/footer.php'; ?>

<script src="/assets/js/favs.js"></script>
<script src="/assets/js/currency.js"></script>
<script src="/assets/js/compare.js"></script>
<script src="/assets/js/chat.js"></script>
<script>
document.getElementById('compare-clear-link')?.addEventListener('click', (e) => {
    if (window.travelCompare) { e.preventDefault(); travelCompare.clear(); window.location.href = '/compare.php'; }
});
</script>
</body>
</html>
