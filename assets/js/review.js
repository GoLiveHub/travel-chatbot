// Форма отзывов на странице отеля
(function () {
  const toggle = document.getElementById('review-toggle');
  const wrap = document.getElementById('review-form-wrap');
  const form = document.getElementById('review-form');
  const msg = document.getElementById('review-msg');
  if (!toggle || !form) return;

  toggle.addEventListener('click', () => {
    const hidden = wrap.classList.toggle('hidden');
    toggle.textContent = hidden ? 'Написать отзыв' : 'Скрыть форму';
  });

  function esc(s) {
    const d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
  }

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = form.querySelector('button[type="submit"]');
    const payload = {
      hotel_id: parseInt(document.getElementById('review-hotel-id').value, 10) || 0,
      author: document.getElementById('review-author').value.trim(),
      rating: parseInt(document.getElementById('review-rating').value, 10) || 0,
      text: document.getElementById('review-text').value.trim(),
    };
    if (!payload.author || !payload.text) {
      alert('Заполните имя и текст отзыва.');
      return;
    }
    if (payload.rating < 1 || payload.rating > 10) {
      alert('Оценка должна быть от 1 до 10.');
      return;
    }
    const old = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Отправляем…';
    try {
      const csrfMeta = document.querySelector('meta[name="csrf-token"]');
      const csrfToken = csrfMeta ? csrfMeta.content : '';
      const res = await fetch(window.API_BASE + '/review.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': csrfToken,
        },
        body: JSON.stringify(payload),
      });
      const data = await res.json();
      if (!data.ok) throw new Error(data.error || 'Ошибка');
      if (msg) {
        msg.classList.remove('hidden');
        msg.textContent = data.message;
      }
      form.querySelector('textarea').value = '';
      document.getElementById('review-author').value = '';
      document.getElementById('review-text').value = '';
      const list = document.getElementById('user-reviews');
      if (list) {
        const card = document.createElement('div');
        card.className = 'rounded-2xl border border-emerald-100 bg-emerald-50/40 p-4';
        card.innerHTML =
          '<div class="flex items-center justify-between">' +
          '<div class="font-semibold text-slate-800">' + esc(payload.author) + '</div>' +
          '<div class="text-sm font-bold text-teal-600">' + payload.rating + '/10</div>' +
          '</div>' +
          '<div class="mt-0.5 text-xs text-slate-400">только что · новый отзыв</div>' +
          '<p class="mt-2 text-sm text-slate-600">' + esc(payload.text) + '</p>';
        list.prepend(card);
      }
    } catch (err) {
      alert('Ошибка: ' + err.message);
    } finally {
      btn.disabled = false;
      btn.textContent = old;
    }
  });
})();
