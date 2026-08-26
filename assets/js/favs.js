// Управление избранным (localStorage + cookie для счётчика в шапке)
(function () {
  const KEY = 'travel_favs';
  let favBusy = false;

  function read() {
    try { return JSON.parse(localStorage.getItem(KEY) || '[]'); }
    catch (e) { return []; }
  }

  function write(list) {
    try { localStorage.setItem(KEY, JSON.stringify(list)); } catch (e) {}
    try { document.cookie = 'travel_favs=' + encodeURIComponent(JSON.stringify(list)) + ';path=/;max-age=31536000'; } catch (e) {}
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
    if (favBusy) return;
    favBusy = true;
    setTimeout(() => { favBusy = false; }, 300);
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

  window.addEventListener('storage', (e) => {
    if (e.key === KEY) {
      const list = read();
      updateBadge(list.length);
      updateButtons(list);
    }
  });

  window.travelFavs = {
    read,
    toggle,
    toggleAllClear,
    isFav: (id) => read().includes(String(id)),
  };

  const list = read();
  updateBadge(list.length);
  updateButtons(list);
})();
