<?php // Блок фильтров (рендерится статически, логика в filters.js) ?>
<aside class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
    <h3 class="mb-4 text-sm font-bold uppercase tracking-wide text-slate-500">Фильтры</h3>

    <div class="space-y-5">
        <div>
            <label class="mb-1.5 block text-sm font-medium text-slate-700">Цена за ночь, ₽</label>
            <div class="flex items-center gap-3">
                <input type="number" id="f-price-min" value="0" min="0" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-teal-500 focus:outline-none">
                <span class="text-slate-400">—</span>
                <input type="number" id="f-price-max" value="100000" min="0" max="100000" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-teal-500 focus:outline-none">
            </div>
            <input type="range" id="f-price-range" min="0" max="100000" step="500" value="100000" class="mt-3 w-full accent-teal-600">
        </div>

        <div>
            <label class="mb-1.5 block text-sm font-medium text-slate-700">Рейтинг от</label>
            <select id="f-rating" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-teal-500 focus:outline-none">
                <option value="0">Любой</option>
                <option value="8">от 8.0</option>
                <option value="8.5">от 8.5</option>
                <option value="9">от 9.0</option>
            </select>
        </div>

        <div>
            <label class="mb-1.5 block text-sm font-medium text-slate-700">Категория отеля</label>
            <select id="f-stars" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-teal-500 focus:outline-none">
                <option value="0">Любая</option>
                <option value="3">от 3 звёзд</option>
                <option value="4">от 4 звёзд</option>
                <option value="5">5 звёзд</option>
            </select>
        </div>

        <div>
            <label class="mb-1.5 block text-sm font-medium text-slate-700">Тип отдыха</label>
            <div class="flex flex-wrap gap-2">
                <button type="button" class="filter-pill rounded-full border px-3 py-1.5 text-sm transition" data-type="all">Все</button>
                <button type="button" class="filter-pill rounded-full border px-3 py-1.5 text-sm transition" data-type="beach">Пляж</button>
                <button type="button" class="filter-pill rounded-full border px-3 py-1.5 text-sm transition" data-type="mountain">Горы</button>
                <button type="button" class="filter-pill rounded-full border px-3 py-1.5 text-sm transition" data-type="city">Город</button>
            </div>
        </div>

        <div>
            <label class="mb-1.5 block text-sm font-medium text-slate-700">Удобства</label>
            <div class="space-y-2">
                <label class="flex items-center gap-2 text-sm text-slate-600"><input type="checkbox" class="f-amenity accent-teal-600" value="wifi"> Wi-Fi</label>
                <label class="flex items-center gap-2 text-sm text-slate-600"><input type="checkbox" class="f-amenity accent-teal-600" value="breakfast"> Завтрак</label>
                <label class="flex items-center gap-2 text-sm text-slate-600"><input type="checkbox" class="f-amenity accent-teal-600" value="pool"> Бассейн</label>
                <label class="flex items-center gap-2 text-sm text-slate-600"><input type="checkbox" class="f-amenity accent-teal-600" value="spa"> Спа</label>
                <label class="flex items-center gap-2 text-sm text-slate-600"><input type="checkbox" class="f-amenity accent-teal-600" value="beach"> Пляж</label>
                <label class="flex items-center gap-2 text-sm text-slate-600"><input type="checkbox" class="f-amenity accent-teal-600" value="parking"> Парковка</label>
            </div>
        </div>

        <div>
            <label class="mb-1.5 block text-sm font-medium text-slate-700">Сортировка</label>
            <select id="f-sort" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-teal-500 focus:outline-none">
                <option value="rating">По рейтингу</option>
                <option value="value">Выгодно</option>
                <option value="price-asc">Сначала дешевле</option>
                <option value="price-desc">Сначала дороже</option>
            </select>
        </div>

        <button id="f-reset" class="w-full rounded-full border border-slate-300 py-2 text-sm font-semibold text-slate-600 transition hover:bg-slate-50">Сбросить</button>
    </div>
</aside>
