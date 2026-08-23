// Переключатель валют: конвертация всех цен, размеченных data-price-rub
(function () {
  const RATES = { RUB: 1, EUR: 100, USD: 92 };
  const SYMBOLS = { RUB: '₽', EUR: '€', USD: '$' };
  const KEY = 'travel_currency';

  function current() {
    const v = localStorage.getItem(KEY);
    return RATES[v] ? v : 'RUB';
  }

  function format(rub) {
    const cur = current();
    const val = Math.round((parseFloat(rub) || 0) / RATES[cur]);
    return new Intl.NumberFormat('ru-RU').format(val) + ' ' + SYMBOLS[cur];
  }

  function apply() {
    const cur = current();
    document.querySelectorAll('[data-price-rub]').forEach((el) => {
      el.textContent = format(el.dataset.priceRub);
    });
    const sel = document.getElementById('currency-select');
    if (sel) sel.value = cur;
  }

  function setCurrency(cur) {
    if (!RATES[cur]) return;
    localStorage.setItem(KEY, cur);
    apply();
    document.dispatchEvent(new CustomEvent('currency:changed', { detail: cur }));
  }

  const sel = document.getElementById('currency-select');
  if (sel) {
    sel.value = current();
    sel.addEventListener('change', () => setCurrency(sel.value));
  }

  window.travelCurrency = { current: current, format: format, set: setCurrency, RATES: RATES, SYMBOLS: SYMBOLS };
  apply();
})();
