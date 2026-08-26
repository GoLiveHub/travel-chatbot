<?php // Компонент подвала ?>
<footer class="mt-16 border-t border-slate-200 bg-slate-50">
    <div class="mx-auto grid max-w-7xl gap-8 px-4 py-12 sm:grid-cols-2 sm:px-6 lg:grid-cols-4">
        <div>
            <div class="flex items-center gap-2 text-lg font-extrabold text-slate-900">
                <span class="grid h-8 w-8 place-items-center rounded-lg bg-gradient-to-r from-blue-600 to-teal-500 text-white"><svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 2 11 13"></path><path d="M22 2 15 22l-4-9-9-4 20-7z"></path></svg></span>
                Travel.ru
            </div>
            <p class="mt-3 text-sm text-slate-500">Умный поиск отелей по всему миру. Пляжный, горнолыжный и городской отдых — в одном месте.</p>
        </div>
        <div>
            <h4 class="text-sm font-bold uppercase tracking-wide text-slate-400">Направления</h4>
            <ul class="mt-3 space-y-2 text-sm text-slate-600">
                <li><a href="/search.php?city=Сочи" class="hover:text-teal-600">Сочи</a></li>
                <li><a href="/search.php?city=Париж" class="hover:text-teal-600">Париж</a></li>
                <li><a href="/search.php?city=Рим" class="hover:text-teal-600">Рим</a></li>
                <li><a href="/search.php?city=Анталья" class="hover:text-teal-600">Анталья</a></li>
            </ul>
        </div>
        <div>
            <h4 class="text-sm font-bold uppercase tracking-wide text-slate-400">Сервис</h4>
            <ul class="mt-3 space-y-2 text-sm text-slate-600">
                <li><a href="/search.php" class="hover:text-teal-600">Поиск отелей</a></li>
                <li><a href="/search.php?type=beach" class="hover:text-teal-600">Пляжный отдых</a></li>
                <li><a href="/search.php?type=mountain" class="hover:text-teal-600">Горнолыжный отдых</a></li>
                <li><a href="/search.php?type=city" class="hover:text-teal-600">Городские отели</a></li>
                <li><a href="/favorites.php" class="hover:text-teal-600">Избранное</a></li>
                <li><a href="/bookings.php" class="hover:text-teal-600">Мои бронирования</a></li>
            </ul>
        </div>
        <div>
            <h4 class="text-sm font-bold uppercase tracking-wide text-slate-400">Контакты</h4>
            <ul class="mt-3 space-y-2 text-sm text-slate-600">
                <li>support@travel.ru</li>
                <li>8 800 000-00-00</li>
                <li class="text-slate-400">Ежедневно 24/7</li>
            </ul>
        </div>
    </div>
    <div class="border-t border-slate-200 py-6 text-center text-xs text-slate-400">
        © <?= date('Y') ?> Travel.ru — демонстрационный проект. Все данные вымышлены.
    </div>
</footer>

<div id="compare-bar" class="fixed bottom-5 left-1/2 z-50 hidden -translate-x-1/2 items-center gap-3 rounded-full border border-slate-200 bg-white px-4 py-2.5 shadow-xl">
    <span id="compare-count" class="text-sm font-semibold text-slate-700"></span>
    <a id="compare-go" href="/compare.php" class="rounded-full bg-gradient-to-r from-blue-600 to-teal-500 px-4 py-1.5 text-sm font-semibold text-white transition hover:shadow">Сравнить</a>
    <button id="compare-clear" class="rounded-full p-1.5 text-slate-400 transition hover:text-slate-700" title="Очистить">✕</button>
</div>

<div id="chat-widget" role="dialog" aria-label="Помощник по подбору отелей" class="fixed bottom-5 right-5 z-50 hidden w-80 max-w-[calc(100vw-2.5rem)] flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl" style="max-height: 520px;">
    <div class="flex items-center justify-between bg-gradient-to-r from-blue-600 to-teal-500 px-4 py-3 text-white">
        <div class="flex items-center gap-2">
            <span class="grid h-8 w-8 place-items-center rounded-full bg-white/20"><svg class="h-5 w-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"></path></svg></span>
            <div>
                <div class="text-sm font-bold">Чат-помощник</div>
                <div class="text-xs text-white/80">онлайн</div>
            </div>
        </div>
        <button id="chat-close" aria-label="Закрыть чат" class="rounded-full p-1 hover:bg-white/20">✕</button>
    </div>
    <div id="chat-messages" role="log" aria-live="polite" aria-relevant="additions" class="flex-1 space-y-3 overflow-y-auto bg-slate-50 px-4 py-3 text-sm" style="height: 280px; min-height: 0;"></div>
    <div id="chat-chips" class="flex flex-wrap gap-1.5 border-t border-slate-100 bg-slate-50 px-3 py-2"></div>
    <form id="chat-form" class="flex gap-2 border-t border-slate-200 bg-white p-3">
        <input id="chat-input" type="text" maxlength="500" autocomplete="off" aria-label="Сообщение помощнику" placeholder="Например: недорогой отель в Риме…" class="flex-1 rounded-full border border-slate-300 px-4 py-2 text-sm focus:border-teal-500 focus:outline-none">
        <button type="submit" aria-label="Отправить сообщение" title="Отправить" class="grid h-10 w-10 place-items-center rounded-full bg-gradient-to-r from-blue-600 to-teal-500 text-white transition hover:shadow"><svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 2L11 13"></path><path d="M22 2L15 22 11 13 2 9z"></path></svg></button>
    </form>
</div>
<script src="/assets/js/sentry.js" defer></script>
