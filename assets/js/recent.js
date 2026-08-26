// Недавние поиски (localStorage): чипы под строкой поиска
(function () {
  const KEY = 'travel_recent';
  const box = document.getElementById('recent-searches');
  if (!box) return;

  function read() {
    try { return JSON.parse(localStorage.getItem(KEY) || '[]'); }
    catch (e) { return []; }
  }

  function add(city) {
    if (!city) return;
    const normalized = city.trim().toLowerCase().normalize('NFC');
    let list = read().filter((x) => x.city.trim().toLowerCase().normalize('NFC') !== normalized);
    list.unshift({ city: city.trim(), ts: Date.now() });
    list = list.slice(0, 5);
    localStorage.setItem(KEY, JSON.stringify(list));
    render();
  }

  function render() {
    const list = read();
    box.innerHTML = '';
    if (!list.length) {
      box.classList.add('hidden');
      box.classList.remove('flex');
      return;
    }
    box.classList.remove('hidden');
    box.classList.add('flex');
    const label = document.createElement('span');
    label.className = 'text-sm text-slate-500';
    label.textContent = 'Недавние:';
    box.appendChild(label);
    list.forEach((item) => {
      const chip = document.createElement('button');
      chip.type = 'button';
      chip.className = 'rounded-full border border-slate-300 bg-white px-3 py-1 text-sm text-slate-600 transition hover:border-teal-400 hover:text-teal-600';
      chip.textContent = item.city;
      chip.addEventListener('click', () => {
        const input = document.getElementById('search-city');
        const form = document.getElementById('search-city-form');
        if (input) input.value = item.city;
        if (form && form.requestSubmit) form.requestSubmit();
      });
      box.appendChild(chip);
    });
  }

  window.travelRecent = { add: add, read: read };
  render();
})();
