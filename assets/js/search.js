// Поиск отелей с фильтрацией на странице /search.php
(function () {
  const grid = document.getElementById('hotel-grid');
  const counter = document.getElementById('result-count');
  const emptyBox = document.getElementById('empty-state');
  if (!grid) return;

  let allHotels = [];

  const priceRange = document.getElementById('f-price-range');
  const priceMax = document.getElementById('f-price-max');
  const priceMin = document.getElementById('f-price-min');
  const ratingSel = document.getElementById('f-rating');
  const starsSel = document.getElementById('f-stars');
  const sortSel = document.getElementById('f-sort');
  const amenityBoxes = document.querySelectorAll('.f-amenity');
  const resetBtn = document.getElementById('f-reset');

  const pills = document.querySelectorAll('.filter-pill');
  const activeType = document.getElementById('active-type');
  const activeCity = document.getElementById('active-city');
  const cityInput = document.getElementById('search-city');
  const cityForm = document.getElementById('search-city-form');
  const cityError = document.getElementById('search-city-error');
  const emptyReset = document.getElementById('empty-reset');
  const checkinInput = document.getElementById('search-checkin');
  const checkoutInput = document.getElementById('search-checkout');
  const guestsInput = document.getElementById('search-guests');

  let city = activeCity ? activeCity.dataset.value || '' : '';

  function normalizeCity(s) {
    return String(s || '').trim().toLowerCase().replace(/[«»"]/g, '');
  }

  function state() {
    return {
      min: parseInt(priceMin?.value || '0', 10) || 0,
      max: parseInt(priceMax?.value || '100000', 10) || 100000,
      rating: parseFloat(ratingSel?.value) || 0,
      stars: parseInt(starsSel?.value || '0', 10) || 0,
      sort: sortSel?.value || 'rating',
      amenities: Array.from(amenityBoxes || []).filter((b) => b.checked).map((b) => b.value),
      type: activeType?.dataset?.value || 'all',
      city: normalizeCity(city),
    };
  }

  function esc(s) {
    const d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
  }

  const AMENITY_LABELS = {
    wifi: 'Wi-Fi', breakfast: 'Завтрак', pool: 'Бассейн', spa: 'Спа', bar: 'Бар',
    gym: 'Фитнес', beach: 'Пляж', kitchen: 'Кухня', mountain: 'Горы', ski: 'Лыжи',
    airport: 'Трансфер', parking: 'Парковка', 'all-inclusive': 'Всё включено',
    'kid-club': 'Детский клуб', fireplace: 'Камин', terrace: 'Терраса',
  };

  function fmtPrice(rub) {
    return window.travelCurrency ? travelCurrency.format(rub) : new Intl.NumberFormat('ru-RU').format(rub) + ' ₽';
  }

  function badgeHtml(badge) {
    if (!badge) return '';
    let cls = 'bg-teal-500 text-white';
    if (/Выгодно|Бюджетно/.test(badge)) cls = 'bg-amber-400 text-slate-900';
    else if (/Хит/.test(badge)) cls = 'bg-rose-500 text-white';
    else if (/Премиум|Люкс/.test(badge)) cls = 'bg-slate-900 text-white';
    return '<span class="absolute right-3 top-3 rounded-full ' + cls + ' px-3 py-1 text-xs font-bold shadow">' + esc(badge) + '</span>';
  }

  function bookingHref(id) {
    const p = new URLSearchParams({ id: String(id) });
    if (checkinInput && checkinInput.value) p.set('checkin', checkinInput.value);
    if (checkoutInput && checkoutInput.value) p.set('checkout', checkoutInput.value);
    if (guestsInput && guestsInput.value) p.set('guests', guestsInput.value);
    return '/hotel.php?' + p.toString();
  }

  function card(h) {
    const img = h.images && h.images[0] ? '/assets/' + h.images[0] : '/assets/img/hotel-1.jpg';
    const fav = window.travelFavs && travelFavs.isFav(h.id) ? 'text-rose-500' : 'text-slate-300';
    const ams = (h.amenities || []).slice(0, 3).map((a) =>
      '<span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600">' + esc(AMENITY_LABELS[a] || a) + '</span>'
    ).join('');
    return `
    <article class="group overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm transition hover:shadow-xl" data-hotel-id="${esc(String(h.id))}">
      <a href="${bookingHref(h.id)}" class="relative block aspect-[4/3] overflow-hidden bg-slate-100">
        <img src="${esc(img)}" alt="${esc(h.name)}" class="h-full w-full object-cover transition duration-300 group-hover:scale-105" loading="lazy" onerror="this.src='/assets/img/hotel-1.jpg'">
        <span class="absolute left-3 top-3 rounded-full bg-white/90 px-3 py-1 text-xs font-bold text-slate-800 shadow">${'★'.repeat(h.stars)}</span>
        ${badgeHtml(h.badge)}
      </a>
      <div class="p-4">
        <div class="flex items-start justify-between gap-2">
          <div>
            <h3 class="font-bold text-slate-900"><a href="${bookingHref(h.id)}" class="hover:text-teal-600">${esc(h.name)}</a></h3>
            <p class="mt-0.5 text-sm text-slate-500">${esc(h.city)}, ${esc(h.country)}</p>
          </div>
          <div class="flex shrink-0 items-center gap-1">
            <button class="fav-btn rounded-full p-2 ${fav} transition hover:text-rose-500" data-id="${esc(String(h.id))}" title="В избранное">
              <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"></path></svg>
            </button>
            <button class="cmp-btn rounded-full p-2 text-slate-300 transition hover:text-teal-600" data-id="${esc(String(h.id))}" data-name="${esc(h.name)}" title="Добавить к сравнению">
              <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"></path></svg>
            </button>
          </div>
        </div>
        <div class="mt-2 flex flex-wrap gap-1">${ams}</div>
        <div class="mt-3 flex items-end justify-between border-t border-slate-100 pt-3">
          <div class="text-xs text-slate-500">
            <span class="font-semibold text-teal-600">${h.rating.toFixed(1)}</span>
            <span class="text-slate-400">(${h.reviews} отз.)</span>
          </div>
          <div class="text-right">
            <div class="text-lg font-extrabold text-slate-900"><span data-price-rub="${h.price}">${fmtPrice(h.price)}</span></div>
            <div class="text-xs text-slate-400">за ночь</div>
          </div>
        </div>
        <a href="${bookingHref(h.id)}" class="mt-3 block rounded-full bg-gradient-to-r from-blue-600 to-teal-500 py-2 text-center text-sm font-semibold text-white transition group-hover:shadow-md">Смотреть</a>
      </div>
    </article>`;
  }

  function syncUrl(push) {
    const s = state();
    const p = new URLSearchParams();
    if (city) p.set('city', city);
    if (s.min > 0) p.set('min', s.min);
    if (s.max < 100000) p.set('max', s.max);
    if (s.rating > 0) p.set('rating', s.rating);
    if (s.stars > 0) p.set('stars', s.stars);
    if (s.sort && s.sort !== 'rating') p.set('sort', s.sort);
    if (s.type && s.type !== 'all') p.set('type', s.type);
    if (s.amenities.length) p.set('amenities', s.amenities.join(','));
    if (checkinInput && checkinInput.value) p.set('checkin', checkinInput.value);
    if (checkoutInput && checkoutInput.value) p.set('checkout', checkoutInput.value);
    if (guestsInput && guestsInput.value) p.set('guests', guestsInput.value);
    const qs = p.toString();
    const url = location.pathname + (qs ? '?' + qs : '');
    if (push) history.pushState({}, '', url);
    else history.replaceState({}, '', url);
  }

  function applyParams() {
    const params = new URLSearchParams(location.search);
    const min = params.get('min');
    const max = params.get('max');
    if (min && priceMin) priceMin.value = min; else if (priceMin) priceMin.value = '0';
    if (max && priceMax) { priceMax.value = max; if (priceRange) priceRange.value = max; } else if (priceMax) { priceMax.value = '100000'; if (priceRange) priceRange.value = '100000'; }
    const rating = params.get('rating');
    if (rating && ratingSel) ratingSel.value = rating; else if (ratingSel) ratingSel.value = '0';
    const stars = params.get('stars');
    if (stars && starsSel) starsSel.value = stars; else if (starsSel) starsSel.value = '0';
    const sort = params.get('sort');
    if (sort && sortSel) sortSel.value = sort; else if (sortSel) sortSel.value = 'rating';
    const amenities = params.get('amenities');
    const wanted = amenities ? amenities.split(',').map((x) => x.trim()).filter(Boolean) : [];
    amenityBoxes.forEach((b) => { b.checked = wanted.includes(b.value); });
    const t = params.get('type');
    if (t && activeType) {
      activeType.dataset.value = t;
      pills.forEach((p) => {
        p.classList.remove('pill-active', 'border-teal-500', 'bg-teal-500', 'text-white');
        if (p.dataset.type === t) p.classList.add('pill-active', 'border-teal-500', 'bg-teal-500', 'text-white');
      });
    } else if (activeType) {
      activeType.dataset.value = 'all';
      pills.forEach((p) => {
        p.classList.remove('pill-active', 'border-teal-500', 'bg-teal-500', 'text-white');
        if (p.dataset.type === 'all') p.classList.add('pill-active', 'border-teal-500', 'bg-teal-500', 'text-white');
      });
    }
    const c = params.get('city');
    if (c) { city = c; if (cityInput) cityInput.value = c; } else { city = ''; if (cityInput) cityInput.value = ''; }
    if (params.get('checkin') && checkinInput) checkinInput.value = params.get('checkin'); else if (checkinInput) checkinInput.value = '';
    if (params.get('checkout') && checkoutInput) checkoutInput.value = params.get('checkout'); else if (checkoutInput) checkoutInput.value = '';
    if (params.get('guests') && guestsInput) guestsInput.value = params.get('guests'); else if (guestsInput) guestsInput.value = '';
    if (checkinInput && checkoutInput) checkoutInput.min = checkinInput.value;
  }

  function render() {
    const s = state();
    let list = allHotels.filter((h) =>
      h.price >= s.min && h.price <= s.max &&
      h.rating >= s.rating &&
      h.stars >= s.stars &&
      (s.type === 'all' || h.type === s.type) &&
      (s.city === '' || normalizeCity(h.city) === s.city) &&
      s.amenities.every((a) => (h.amenities || []).includes(a))
    );

    if (s.sort === 'price-asc') list.sort((a, b) => a.price - b.price);
    else if (s.sort === 'price-desc') list.sort((a, b) => b.price - a.price);
    else if (s.sort === 'value') list.sort((a, b) => (a.price / Math.max(a.rating, 1)) - (b.price / Math.max(b.rating, 1)));
    else list.sort((a, b) => b.rating - a.rating);

    grid.innerHTML = list.map(card).join('');
    if (counter) counter.textContent = list.length;
    if (emptyBox) emptyBox.classList.toggle('hidden', list.length > 0);
    if (window.travelCompare) travelCompare.syncButtons();
  }

  function debounce(fn, ms) { let t; return function () { clearTimeout(t); t = setTimeout(() => fn.apply(this, arguments), ms); }; }

  function bindEvents() {
    const debouncedRender = debounce(() => { render(); syncUrl(true); }, 250);
    if (priceRange) {
      priceRange.addEventListener('input', () => {
        priceMax.value = priceRange.value;
        requestAnimationFrame(() => { render(); syncUrl(false); });
      });
    }
    if (priceMax) priceMax.addEventListener('change', debouncedRender);
    if (priceMin) priceMin.addEventListener('change', debouncedRender);
    if (ratingSel) ratingSel.addEventListener('change', debouncedRender);
    if (starsSel) starsSel.addEventListener('change', debouncedRender);
    if (sortSel) sortSel.addEventListener('change', debouncedRender);
    amenityBoxes.forEach((b) => b.addEventListener('change', debouncedRender));
    pills.forEach((p) => p.addEventListener('click', () => {
      pills.forEach((x) => x.classList.remove('pill-active', 'border-teal-500', 'bg-teal-500', 'text-white'));
      p.classList.add('pill-active', 'border-teal-500', 'bg-teal-500', 'text-white');
      if (activeType) { activeType.dataset.value = p.dataset.type; activeType.textContent = p.textContent; }
      render();
      syncUrl(true);
    }));
    if (resetBtn) resetBtn.addEventListener('click', () => {
      if (priceMin) priceMin.value = 0;
      if (priceMax) priceMax.value = 100000;
      if (priceRange) priceRange.value = 100000;
      if (ratingSel) ratingSel.value = '0';
      if (starsSel) starsSel.value = '0';
      if (sortSel) sortSel.value = 'rating';
      amenityBoxes.forEach((b) => (b.checked = false));
      pills.forEach((p) => { p.classList.remove('pill-active', 'border-teal-500', 'bg-teal-500', 'text-white'); if (p.dataset.type === 'all') p.classList.add('pill-active', 'border-teal-500', 'bg-teal-500', 'text-white'); });
      if (activeType) { activeType.dataset.value = 'all'; activeType.textContent = 'Все'; }
      city = '';
      if (cityInput) cityInput.value = '';
      if (cityError) cityError.classList.add('hidden');
      render();
      syncUrl(true);
    });

    if (cityForm) {
      cityForm.addEventListener('submit', (e) => {
        e.preventDefault();
        city = cityInput ? cityInput.value : '';
        const c = normalizeCity(city);
        const validCities = allHotels.map((h) => normalizeCity(h.city)).filter((v, i, a) => a.indexOf(v) === i);
        if (c && !validCities.includes(c)) {
          city = '';
          if (cityInput) cityInput.value = '';
          if (cityError) {
            cityError.textContent = 'Город не найден. Доступны: ' + allHotels.map((h) => h.city).filter((v, i, a) => a.indexOf(v) === i).slice(0, 5).join(', ') + '…';
            cityError.classList.remove('hidden');
          }
          render();
          return;
        }
        if (cityError) cityError.classList.add('hidden');
        if (window.travelRecent) travelRecent.add(city);
        render();
        syncUrl(true);
      });
      if (cityInput) cityInput.addEventListener('input', () => cityError && cityError.classList.add('hidden'));
    }
    if (emptyReset) emptyReset.addEventListener('click', () => resetBtn && resetBtn.click());
    if (checkinInput) checkinInput.addEventListener('change', () => {
      if (checkoutInput) {
        checkoutInput.min = checkinInput.value;
        if (checkinInput.value && checkoutInput.value && checkoutInput.value <= checkinInput.value) {
          const parts = checkinInput.value.split('-');
          const next = new Date(+parts[0], +parts[1] - 1, +parts[2] + 1);
          const p2 = (n) => String(n).padStart(2, '0');
          checkoutInput.value = next.getFullYear() + '-' + p2(next.getMonth() + 1) + '-' + p2(next.getDate());
        }
      }
      syncUrl(true);
      render();
    });
    if (checkoutInput) checkoutInput.addEventListener('change', () => { syncUrl(true); render(); });
    if (guestsInput) guestsInput.addEventListener('change', () => { syncUrl(true); render(); });
    window.addEventListener('popstate', () => {
      applyParams();
      render();
    });
  }

  async function init() {
    bindEvents();
    try {
      const res = await fetch(window.API_BASE + '/hotels.php');
      const data = await res.json();
      if (!data.ok) throw new Error(data.error || 'Ошибка загрузки');
      allHotels = data.hotels;
      applyParams();
      render();
    } catch (err) {
      grid.innerHTML = '<p class="col-span-full py-10 text-center text-slate-500">Не удалось загрузить отели. Проверьте, что API работает.</p>';
    }
  }

  document.addEventListener('currency:changed', () => {
    if (window.__hotels) render();
  });

  init();
})();
