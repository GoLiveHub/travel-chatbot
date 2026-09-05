// Логика страницы отеля: галерея, бронирование, отзывы
(function () {
  // Промокоды (демо, зеркало api/booking.php)
  const PROMOS = {
    WELCOME10: { type: 'percent', value: 10 },
    TRAVEL5: { type: 'fixed', value: 500 },
    SKI15: { type: 'percent', value: 15 },
  };

  const bookingForm = document.getElementById('booking-form');
  const hotelIdInput = document.getElementById('booking-hotel-id');
  const promoInput = document.getElementById('booking-promo');
  const promoMsg = document.getElementById('promo-msg');
  let validDiscount = 0;

  function promoCode() {
    return promoInput ? promoInput.value.toUpperCase().trim() : '';
  }

  function checkPromo() {
    if (!promoInput || !promoMsg) return;
    const code = promoCode();
    if (!code) { promoMsg.classList.add('hidden'); return; }
    const p = PROMOS[code];
    if (!p) {
      promoMsg.textContent = 'Промокод не найден. Доступны: WELCOME10, TRAVEL5, SKI15';
      promoMsg.className = 'mt-1 text-xs font-medium text-rose-500';
      validDiscount = 0;
      calc();
      return;
    }
    const nights = currentNights();
    const base = nights * pricePerNight;
    validDiscount = p.type === 'percent'
      ? Math.round(base * p.value / 100)
      : Math.min(p.value, base);
    promoMsg.textContent = 'Промокод ' + code + ' применён: −' + new Intl.NumberFormat('ru-RU').format(validDiscount) + ' ₽';
    promoMsg.className = 'mt-1 text-xs font-medium text-emerald-600';
    calc();
  }

  function currentNights() {
    const c1 = document.getElementById('booking-checkin');
    const c2 = document.getElementById('booking-checkout');
    if (c1 && c2 && c1.value && c2.value) {
      const d1 = new Date(c1.value);
      const d2 = new Date(c2.value);
      if (d2 > d1) return Math.round((d2 - d1) / 86400000);
    }
    return 0;
  }

  if (bookingForm) {
    bookingForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const btn = bookingForm.querySelector('button[type="submit"]');
      const payload = {
        hotel_id: parseInt(hotelIdInput.value, 10) || 0,
        name: document.getElementById('booking-name').value.trim(),
        phone: document.getElementById('booking-phone').value.trim(),
        checkin: document.getElementById('booking-checkin').value,
        checkout: document.getElementById('booking-checkout').value,
        guests: parseInt(document.getElementById('booking-guests').value, 10) || 1,
        promo: promoCode(),
      };
      if (!payload.name || !payload.phone) {
        alert('Пожалуйста, укажите имя и телефон.');
        return;
      }
      const old = btn.textContent;
      btn.disabled = true;
      btn.textContent = 'Отправляем…';
      try {
        const res = await fetch('/api/booking.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload),
        });
        const data = await res.json();
        if (!data.ok) throw new Error(data.error || 'Ошибка');
        if (data.ref) {
          location.href = data.confirmation_url || '/bookings.php';
          return;
        }
      } catch (err) {
        alert('Ошибка бронирования: ' + err.message);
      } finally {
        btn.disabled = false;
        btn.textContent = old;
      }
    });
  }

  // Галерея: миниатюры, стрелки, автопрокрутка
  const gallery = document.getElementById('gallery');
  if (gallery) {
    const main = document.getElementById('gallery-main');
    const items = Array.from(gallery.querySelectorAll('button'));
    if (main && items.length > 1) {
      let idx = 0;
      let timer = null;

      function show(i) {
        idx = (i + items.length) % items.length;
        if (main) main.src = items[idx].dataset.src;
        items.forEach((x) => x.classList.remove('ring-2', 'ring-teal-500'));
        items[idx].classList.add('ring-2', 'ring-teal-500');
      }

      function next() { show(idx + 1); }
      function prev() { show(idx - 1); }

      function start() {
        stop();
        timer = setInterval(next, 4500);
      }
      function stop() { if (timer) { clearInterval(timer); timer = null; } }

      items.forEach((b) => b.addEventListener('click', () => show(items.indexOf(b))));

      const wrap = gallery.querySelector('.relative');
      const mkArrow = (dir, fn) => {
        const b = document.createElement('button');
        b.type = 'button';
        b.setAttribute('aria-label', dir === 'prev' ? 'Предыдущее фото' : 'Следующее фото');
        b.className = 'absolute top-1/2 z-10 grid h-10 w-10 -translate-y-1/2 place-items-center rounded-full bg-white/85 text-xl font-bold text-slate-800 shadow transition hover:bg-white ' + (dir === 'prev' ? 'left-3' : 'right-3');
        b.textContent = dir === 'prev' ? '‹' : '›';
        b.addEventListener('click', fn);
        return b;
      };
      if (wrap) {
        wrap.appendChild(mkArrow('prev', prev));
        wrap.appendChild(mkArrow('next', next));
        wrap.addEventListener('mouseenter', stop);
        wrap.addEventListener('mouseleave', start);
      }
      start();
    } else if (main && items.length === 1) {
      items[0].addEventListener('click', () => { main.src = items[0].dataset.src; });
    }
  }

  // Пересчёт суммы при изменении дат, валюты и промокода
  const checkin = document.getElementById('booking-checkin');
  const checkout = document.getElementById('booking-checkout');
  const sumEl = document.getElementById('booking-sum');
  const pricePerNight = sumEl ? parseFloat(sumEl.dataset.priceRub || '0') : 0;
  function fmtPrice(rub) {
    return window.travelCurrency ? travelCurrency.format(rub) : new Intl.NumberFormat('ru-RU').format(rub) + ' ₽';
  }
  if (checkin && checkout && sumEl) {
    function calc() {
      if (checkin.value && checkout.value) {
        const d1 = new Date(checkin.value);
        const d2 = new Date(checkout.value);
        if (d2 > d1) {
          const nights = Math.round((d2 - d1) / 86400000);
          const base = nights * pricePerNight;
          const discount = Math.min(validDiscount, base);
          if (discount > 0) {
            sumEl.textContent = fmtPrice(base - discount) + ' за ' + nights + ' ноч. (−' + fmtPrice(discount) + ')';
          } else {
            sumEl.textContent = fmtPrice(base) + ' за ' + nights + ' ноч.';
          }
        }
      }
    }
    checkin.addEventListener('change', () => {
      checkout.min = checkin.value;
      if (checkout.value && new Date(checkout.value) <= new Date(checkin.value)) {
        checkout.value = '';
      }
      calc();
    });
    checkout.addEventListener('change', calc);
    document.addEventListener('currency:changed', calc);
    calc();
  }

  const promoBtn = document.getElementById('promo-check');
  if (promoBtn) promoBtn.addEventListener('click', checkPromo);
  if (promoInput) promoInput.addEventListener('input', () => {
    if (promoMsg) { promoMsg.classList.add('hidden'); validDiscount = 0; calc(); }
  });

  // Кнопка "Поделиться" — копирование ссылки
  const shareBtn = document.getElementById('share-btn');
  if (shareBtn) {
    shareBtn.addEventListener('click', async () => {
      const url = location.href;
      const done = () => {
        shareBtn.textContent = '✓ Ссылка скопирована';
        setTimeout(() => { shareBtn.textContent = 'Поделиться'; }, 2000);
      };
      try { await navigator.clipboard.writeText(url); done(); }
      catch (err) {
        const ta = document.createElement('textarea');
        ta.value = url;
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); done(); }
        catch (e2) { prompt('Скопируйте ссылку:', url); }
        document.body.removeChild(ta);
      }
    });
  }
})();
