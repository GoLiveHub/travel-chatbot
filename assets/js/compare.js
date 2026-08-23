// Сравнение отелей: плавающая панель + кнопки на карточках
(function () {
  const KEY = 'travel_compare';
  let items = [];
  try { items = JSON.parse(localStorage.getItem(KEY) || '[]'); } catch (e) { items = []; }
  if (!Array.isArray(items)) items = [];

  function save() { localStorage.setItem(KEY, JSON.stringify(items)); }
  function list() { return items.slice(); }
  function isIn(id) { return items.some((x) => String(x.id) === String(id)); }

  function toggle(id, name) {
    const s = String(id);
    const i = items.findIndex((x) => x.id === s);
    if (i >= 0) items.splice(i, 1);
    else {
      if (items.length >= 3) items.shift();
      items.push({ id: s, name: name || '' });
    }
    save();
    syncButtons();
    renderBar();
    document.dispatchEvent(new CustomEvent('compare:changed'));
  }

  function clear() { items = []; save(); syncButtons(); renderBar(); }

  function syncButtons() {
    document.querySelectorAll('.cmp-btn').forEach((b) => {
      const on = isIn(b.dataset.id);
      b.classList.toggle('is-compare', on);
      b.classList.toggle('text-teal-600', on);
      b.title = on ? 'Убрать из сравнения' : 'Добавить к сравнению';
    });
  }

  function renderBar() {
    const bar = document.getElementById('compare-bar');
    if (!bar || document.body.dataset.page === 'compare') return;
    if (items.length === 0) { bar.classList.add('hidden'); return; }
    bar.classList.remove('hidden');
    document.getElementById('compare-count').textContent = 'В сравнении: ' + items.length + ' из 3';
    document.getElementById('compare-go').href = '/compare.php?ids=' + items.map((x) => x.id).join(',');
  }

  function init() {
    document.addEventListener('click', (e) => {
      const btn = e.target.closest('.cmp-btn');
      if (btn) toggle(btn.dataset.id, btn.dataset.name);
    });
    const clearBtn = document.getElementById('compare-clear');
    if (clearBtn) clearBtn.addEventListener('click', clear);
    syncButtons();
    renderBar();
  }

  window.travelCompare = { list: list, isIn: isIn, toggle: toggle, clear: clear, syncButtons: syncButtons };
  init();
})();
