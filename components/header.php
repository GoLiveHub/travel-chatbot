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
                <div class="relative" id="user-menu-wrap">
                    <button onclick="document.getElementById('user-dropdown').classList.toggle('hidden')" class="flex h-10 w-10 items-center justify-center rounded-full bg-gradient-to-br from-blue-600 to-teal-500 text-sm font-bold text-white shadow-sm transition hover:shadow-md" title="<?= htmlspecialchars($currentUser['name']) ?>">
                        <?= strtoupper(mb_substr($currentUser['name'], 0, 1)) ?>
                    </button>
                    <div id="user-dropdown" class="hidden absolute right-0 top-12 z-50 w-56 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xl">
                        <div class="border-b border-slate-100 px-4 py-3">
                            <div class="text-sm font-semibold text-slate-900"><?= htmlspecialchars($currentUser['name']) ?></div>
                            <div class="mt-0.5 truncate text-xs text-slate-500"><?= htmlspecialchars($currentUser['email']) ?></div>
                        </div>
                        <div class="py-1">
                            <a href="/favorites.php" class="flex items-center gap-2 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">
                                <svg class="h-4 w-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/></svg>
                                Избранное
                            </a>
                            <a href="/bookings.php" class="flex items-center gap-2 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">
                                <svg class="h-4 w-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                                Мои бронирования
                            </a>
                        </div>
                        <div class="border-t border-slate-100 py-1">
                            <button onclick="fetch('/api/auth.php?action=logout',{method:'POST'}).then(()=>location.reload())" class="flex w-full items-center gap-2 px-4 py-2 text-sm text-red-600 hover:bg-red-50">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                                Выйти
                            </button>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <a href="/login.php" class="grid h-10 w-10 place-items-center rounded-full border border-slate-200 text-slate-500 transition hover:border-teal-300 hover:bg-teal-50 hover:text-teal-600" title="Войти">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                </a>
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
<script>
(function(){
    var btn=document.getElementById('user-menu-wrap');
    if(!btn)return;
    document.addEventListener('click',function(e){
        var dd=document.getElementById('user-dropdown');
        if(!dd)return;
        if(btn.contains(e.target)){return;}
        dd.classList.add('hidden');
    });
})();
</script>
