<?php
// Компонент шапки сайта
$currentPage = basename($_SERVER['PHP_SELF'] ?? 'index.php');
$favCount = 0;
if (isset($_COOKIE['travel_favs']) && $_COOKIE['travel_favs'] !== '') {
    $favs = json_decode($_COOKIE['travel_favs'], true);
    $favCount = is_array($favs) ? count($favs) : 0;
}
// Авторизация
require_once __DIR__ . '/../api/config.php';
auth_start();
$currentUser = auth_user();
?>
<header class="sticky top-0 z-40 border-b border-slate-200 bg-white/90 backdrop-blur">
    <div class="mx-auto flex h-16 max-w-7xl items-center justify-between px-4 sm:px-6">
        <a href="/" class="flex items-center gap-2 text-xl font-extrabold text-slate-900">
            <span class="grid h-9 w-9 place-items-center rounded-xl bg-gradient-to-r from-blue-600 to-teal-500 text-white">✈</span>
            <span>Travel<span class="text-teal-500">.ru</span></span>
        </a>

        <nav class="hidden items-center gap-6 text-sm font-medium text-slate-600 md:flex">
            <a href="/" class="hover:text-teal-600 <?= $currentPage === 'index.php' ? 'text-teal-600' : '' ?>">Главная</a>
            <a href="/search.php" class="hover:text-teal-600 <?= $currentPage === 'search.php' ? 'text-teal-600' : '' ?>">Отели</a>
            <a href="/search.php?type=beach" class="hover:text-teal-600">Пляж</a>
            <a href="/search.php?type=mountain" class="hover:text-teal-600">Горы</a>
        </nav>

        <div class="flex items-center gap-2 sm:gap-3">
            <a href="/search.php" class="grid h-10 w-10 place-items-center rounded-full text-slate-500 transition hover:bg-slate-100 hover:text-slate-900 md:hidden" aria-label="Поиск отелей" title="Поиск отелей">⌕</a>
            <a href="/bookings.php" class="grid h-10 w-10 place-items-center rounded-full text-slate-500 transition hover:bg-slate-100 hover:text-slate-900" aria-label="Мои бронирования" title="Мои бронирования">▣</a>
            <?php if ($currentUser): ?>
                <div class="relative group">
                    <button class="flex h-10 w-10 items-center justify-center rounded-full bg-teal-100 text-sm font-bold text-teal-700 transition hover:bg-teal-200" title="<?= htmlspecialchars($currentUser['name']) ?>">
                        <?= strtoupper(mb_substr($currentUser['name'], 0, 1)) ?>
                    </button>
                    <div class="invisible group-hover:visible absolute right-0 top-12 z-50 w-48 rounded-xl border border-slate-200 bg-white py-2 shadow-lg">
                        <div class="px-4 py-1 text-xs text-slate-500"><?= htmlspecialchars($currentUser['email']) ?></div>
                        <a href="/favorites.php" class="block px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">Избранное</a>
                        <a href="/bookings.php" class="block px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">Мои бронирования</a>
                        <hr class="my-1">
                        <button onclick="fetch('/api/auth.php?action=logout',{method:'POST'}).then(()=>location.reload())" class="w-full px-4 py-2 text-left text-sm text-red-600 hover:bg-red-50">Выйти</button>
                    </div>
                </div>
            <?php else: ?>
                <a href="/login.php" class="rounded-full border border-teal-300 bg-teal-50 px-3 py-1.5 text-xs font-semibold text-teal-700 transition hover:bg-teal-100">Войти</a>
            <?php endif; ?>
            <button id="theme-toggle" aria-label="Переключить тему" class="grid h-10 w-10 place-items-center rounded-full border border-slate-200 text-slate-500 transition hover:bg-slate-100 hover:text-slate-900" title="Тёмная тема">
                <span id="theme-moon" class="text-lg leading-none">🌙</span>
                <span id="theme-sun" class="hidden text-lg leading-none">☀️</span>
            </button>
            <select id="currency-select" aria-label="Валюта" title="Курс: 1 $ ≈ 92 ₽, 1 € ≈ 100 ₽ (демо)" class="rounded-full border border-slate-200 px-2 py-1.5 text-sm font-semibold text-slate-600 focus:border-teal-500 focus:outline-none">
                <option value="RUB">₽</option>
                <option value="EUR">€</option>
                <option value="USD">$</option>
            </select>
            <a href="/favorites.php" id="fav-link" class="relative rounded-full p-2 text-slate-500 transition hover:bg-slate-100 hover:text-slate-900" title="Избранное">
                <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"></path>
                </svg>
                <span id="fav-count" class="absolute -right-1 -top-1 hidden h-5 min-w-5 items-center justify-center rounded-full bg-teal-500 px-1 text-xs font-bold text-white"><?= $favCount ?></span>
            </a>
            <button id="chat-toggle" aria-label="Открыть чат-помощник" class="grid h-10 w-10 place-items-center rounded-full bg-gradient-to-r from-blue-600 to-teal-500 text-white shadow-lg transition hover:shadow-xl" title="Чат-помощник">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"></path>
                </svg>
            </button>
        </div>
    </div>
</header>
