<?php
// Компонент карточки отеля
// Ожидает: $hotel (массив с полями из hotels.json)
if (!isset($hotel) || !is_array($hotel)) return;
$img = isset($hotel['images'][0]) ? '/assets/' . $hotel['images'][0] : '/assets/img/hotel-1.jpg';
$stars = $hotel['stars'] ?? 3;
$badge = $hotel['badge'] ?? '';
$badgeCls = 'bg-teal-500 text-white';
if ($badge) {
    if (mb_strpos($badge, 'Выгодно') !== false || mb_strpos($badge, 'Бюджетно') !== false) $badgeCls = 'bg-amber-400 text-slate-900';
    elseif (mb_strpos($badge, 'Хит') !== false) $badgeCls = 'bg-rose-500 text-white';
    elseif (mb_strpos($badge, 'Премиум') !== false || mb_strpos($badge, 'Люкс') !== false) $badgeCls = 'bg-slate-900 text-white';
}
$amenityLabels = [
    'wifi' => 'Wi-Fi', 'breakfast' => 'Завтрак', 'pool' => 'Бассейн',
    'spa' => 'Спа', 'bar' => 'Бар', 'gym' => 'Фитнес', 'beach' => 'Пляж',
    'kitchen' => 'Кухня', 'mountain' => 'Горы', 'ski' => 'Лыжи',
    'airport' => 'Трансфер', 'parking' => 'Парковка', 'all-inclusive' => 'Всё включено',
    'kid-club' => 'Детский клуб', 'fireplace' => 'Камин', 'terrace' => 'Терраса',
];
?>
<article class="group overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm transition hover:shadow-xl" data-hotel-id="<?= (int) $hotel['id'] ?>">
    <a href="/hotel.php?id=<?= (int) $hotel['id'] ?>" class="relative block aspect-[4/3] overflow-hidden bg-slate-100">
        <img src="<?= htmlspecialchars($img) ?>" alt="<?= htmlspecialchars($hotel['name']) ?>" class="h-full w-full object-cover transition duration-300 group-hover:scale-105" loading="lazy" onerror="this.src='/assets/img/hotel-1.jpg'">
        <span class="absolute left-3 top-3 rounded-full bg-white/90 px-3 py-1 text-xs font-bold text-slate-800 shadow">
            <?= str_repeat('★', $stars) ?>
        </span>
        <?php if ($badge): ?>
            <span class="absolute right-3 top-3 rounded-full <?= $badgeCls ?> px-3 py-1 text-xs font-bold shadow"><?= htmlspecialchars($badge) ?></span>
        <?php endif; ?>
    </a>
    <div class="p-4">
        <div class="flex items-start justify-between gap-2">
            <div>
                <h3 class="font-bold text-slate-900"><a href="/hotel.php?id=<?= (int) $hotel['id'] ?>" class="hover:text-teal-600"><?= htmlspecialchars($hotel['name']) ?></a></h3>
                <p class="mt-0.5 text-sm text-slate-500"><?= htmlspecialchars($hotel['city']) ?>, <?= htmlspecialchars($hotel['country']) ?></p>
            </div>
            <div class="flex shrink-0 items-center gap-1">
                <button class="fav-btn rounded-full p-2 text-slate-300 transition hover:text-rose-500" data-id="<?= (int) $hotel['id'] ?>" title="В избранное">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"></path>
                    </svg>
                </button>
                <button class="cmp-btn rounded-full p-2 text-slate-300 transition hover:text-teal-600" data-id="<?= (int) $hotel['id'] ?>" data-name="<?= htmlspecialchars($hotel['name']) ?>" title="Добавить к сравнению">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"></path>
                    </svg>
                </button>
            </div>
        </div>
        <div class="mt-2 flex flex-wrap gap-1">
            <?php foreach (array_slice($hotel['amenities'], 0, 3) as $a): ?>
                <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600"><?= htmlspecialchars($amenityLabels[$a] ?? $a) ?></span>
            <?php endforeach; ?>
        </div>
        <div class="mt-3 flex items-end justify-between border-t border-slate-100 pt-3">
            <div class="text-xs text-slate-500">
                <span class="font-semibold text-teal-600"><?= number_format($hotel['rating'], 1, '.', '') ?></span>
                <span class="text-slate-400">(<?= (int) $hotel['reviews'] ?> отз.)</span>
            </div>
            <div class="text-right">
                <div class="text-lg font-extrabold text-slate-900"><span data-price-rub="<?= (int) $hotel['price'] ?>"><?= number_format($hotel['price'], 0, '', ' ') ?> ₽</span></div>
                <div class="text-xs text-slate-400">за ночь</div>
            </div>
        </div>
        <a href="/hotel.php?id=<?= (int) $hotel['id'] ?>" class="mt-3 block rounded-full bg-gradient-to-r from-blue-600 to-teal-500 py-2 text-center text-sm font-semibold text-white transition group-hover:shadow-md">Смотреть</a>
    </div>
</article>
