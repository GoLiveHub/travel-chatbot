// Страница избранного: удаление карточек при снятии сердечка и кнопка "Очистить всё"
(function () {
  const grid = document.getElementById('fav-grid');
  const empty = document.getElementById('fav-empty');
  const count = document.getElementById('fav-page-count');
  const clearBtn = document.getElementById('fav-clear');
  if (!grid) return;

  function current() {
    return window.travelFavs ? travelFavs.read() : [];
  }

  function refresh() {
    const list = current();
    if (count) count.textContent = list.length;
    if (empty) empty.classList.toggle('hidden', list.length > 0);
    if (grid) grid.classList.toggle('hidden', list.length === 0);
    if (clearBtn) clearBtn.classList.toggle('hidden', list.length === 0);
  }

  document.addEventListener('favs:changed', () => {
    if (grid.childElementCount === 0) return;
    const list = current();
    grid.querySelectorAll('article[data-hotel-id]').forEach((card) => {
      if (!list.includes(String(card.dataset.hotelId))) {
        card.style.transition = 'opacity .2s, transform .2s';
        card.style.opacity = '0';
        card.style.transform = 'scale(.95)';
        setTimeout(() => card.remove(), 200);
      }
    });
    setTimeout(refresh, 220);
  });

  if (clearBtn) {
    clearBtn.addEventListener('click', () => {
      if (window.travelFavs) travelFavs.toggleAllClear();
      grid.innerHTML = '';
      refresh();
    });
  }

  refresh();
})();
