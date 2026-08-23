// Переключение вкладок на главной и мобильного меню
(function () {
  // Вкладки "Популярное / Пляж / Горы"
  const tabs = document.querySelectorAll('.home-tab');
  const panes = document.querySelectorAll('.home-pane');
  tabs.forEach((tab) => {
    tab.addEventListener('click', () => {
      tabs.forEach((t) => {
        t.classList.remove('tab-active', 'bg-teal-500', 'text-white', 'border-teal-500');
        t.classList.add('border-slate-300', 'text-slate-600');
      });
      tab.classList.remove('border-slate-300', 'text-slate-600');
      tab.classList.add('tab-active', 'bg-teal-500', 'text-white', 'border-teal-500');
      panes.forEach((p) => {
        p.classList.toggle('hidden', p.id !== 'pane-' + tab.dataset.tab);
      });
    });
  });

  // Поиск на главной: отель по названию -> страница отеля
  const heroForm = document.getElementById('hero-search');
  if (heroForm) {
    heroForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const input = document.getElementById('hero-city');
      const q = input ? input.value.trim() : '';
      const params = new URLSearchParams(new FormData(heroForm));
      try {
        const res = await fetch('/api/hotels.php');
        const data = await res.json();
        if (data.ok && q) {
          const ql = q.toLowerCase();
          const hotel = (data.hotels || []).find((h) => String(h.name).toLowerCase() === ql) ||
            (data.hotels || []).find((h) => String(h.name).toLowerCase().includes(ql));
          if (hotel) {
            params.delete('city');
            params.set('id', hotel.id);
            location.href = '/hotel.php?' + params.toString();
            return;
          }
        }
      } catch (err) { /* переходим к обычному поиску */ }
      location.href = '/search.php?' + params.toString();
    });

    const checkin = heroForm.querySelector('[name="checkin"]');
    const checkout = heroForm.querySelector('[name="checkout"]');
    if (checkin && checkout) checkin.addEventListener('change', () => {
      checkout.min = checkin.value;
      if (checkout.value <= checkin.value) {
        const next = new Date(checkin.value + 'T00:00:00');
        next.setDate(next.getDate() + 1);
        checkout.value = next.toISOString().slice(0, 10);
      }
    });
  }
})();
