// Управление избранным (localStorage + cookie для счётчика в шапке)
(function () {
  const KEY = 'travel_favs';

  function read() {
    try { return JSON.parse(localStorage.getItem(KEY) || '[]'); }
    catch (e) { return []; }
  }

  function write(list) {
    localStorage.setItem(KEY, JSON.stringify(list));
    document.cookie = 'travel_favs=' + encodeURIComponent(JSON.stringify(list)) + ';path=/;max-age=31536000';
    updateBadge(list.length);
    updateButtons(list);
    document.dispatchEvent(new CustomEvent('favs:changed', { detail: list }));
  }

  function updateBadge(n) {
    const b = document.getElementById('fav-count');
    if (b) {
      b.textContent = n;
      b.classList.toggle('hidden', n === 0);
      b.classList.toggle('flex', n > 0);
    }
  }

  function updateButtons(list) {
    document.querySelectorAll('.fav-btn').forEach((btn) => {
      const id = btn.dataset.id;
      const active = list.includes(String(id));
      btn.classList.toggle('text-rose-500', active);
      btn.classList.toggle('text-slate-300', !active);
      btn.title = active ? 'Убрать из избранного' : 'В избранное';
    });
  }

  function toggle(id) {
    const list = read();
    const i = list.indexOf(String(id));
    if (i >= 0) list.splice(i, 1); else list.push(String(id));
    write(list);
  }

  function toggleAllClear() {
    write([]);
  }

  document.addEventListener('click', (e) => {
    const btn = e.target.closest('.fav-btn');
    if (btn) {
      e.preventDefault();
      toggle(btn.dataset.id);
    }
  });

  window.travelFavs = {
    read,
    toggle,
    toggleAllClear,
    isFav: (id) => read().includes(String(id)),
  };

  // Инициализация при загрузке
  const list = read();
  updateBadge(list.length);
  updateButtons(list);
})();
