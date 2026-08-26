// Переключатель валют: конвертация всех цен, размеченных data-price-rub
(function () {
  const RATES = { RUB: 1, EUR: 100, USD: 92 };
  const SYMBOLS = { RUB: '₽', EUR: '€', USD: '$' };
  const KEY = 'travel_currency';
  const RATES_KEY = 'travel_rates';
  const RATES_TS_KEY = 'travel_rates_ts';

  try {
    const cached = JSON.parse(localStorage.getItem(RATES_KEY));
    if (cached && cached.EUR) { RATES.EUR = cached.EUR; RATES.USD = cached.USD; }
  } catch (e) {}

  async function refreshRates() {
    try {
      const res = await fetch('https://api.cbr-xml-daily.ru/latest.js');
      if (!res.ok) return;
      const data = await res.json();
      if (data.rates && data.rates.EUR) RATES.EUR = data.rates.EUR;
      if (data.rates && data.rates.USD) RATES.USD = data.rates.USD;
      try {
        localStorage.setItem(RATES_KEY, JSON.stringify({ EUR: RATES.EUR, USD: RATES.USD }));
        localStorage.setItem(RATES_TS_KEY, String(Date.now()));
      } catch (e) {}
      apply();
    } catch (e) {}
  }

  try {
    const ts = parseInt(localStorage.getItem(RATES_TS_KEY) || '0', 10);
    if (Date.now() - ts > 86400000) refreshRates();
  } catch (e) {}

  function current() {
    try {
      const v = localStorage.getItem(KEY);
      return RATES[v] ? v : 'RUB';
    } catch (e) { return 'RUB'; }
  }

  function format(rub) {
    const cur = current();
    const val = Math.round((parseFloat(rub) || 0) / RATES[cur]);
    try {
      return new Intl.NumberFormat(undefined, { style: 'currency', currency: cur, maximumFractionDigits: 0 }).format(val);
    } catch (e) {
      return new Intl.NumberFormat('ru-RU').format(val) + ' ' + SYMBOLS[cur];
    }
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
    try { localStorage.setItem(KEY, cur); } catch (e) {}
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
