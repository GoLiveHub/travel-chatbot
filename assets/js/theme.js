// Тёмная тема: класс .dark на <html>, выбор сохраняется в localStorage
(function () {
  var KEY = 'travel_theme';
  var saved = null;
  try { saved = localStorage.getItem(KEY); } catch (e) {}

  var dark = saved ? saved === 'dark'
    : (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
  if (dark) document.documentElement.classList.add('dark');

  if (!saved && window.matchMedia) {
    var mq = window.matchMedia('(prefers-color-scheme: dark)');
    mq.addEventListener('change', function (e) {
      document.documentElement.classList.toggle('dark', e.matches);
      syncIcon();
    });
  }

  function syncIcon() {
    var btn = document.getElementById('theme-toggle');
    if (!btn) return;
    var isDark = document.documentElement.classList.contains('dark');
    var moon = btn.querySelector('#theme-moon');
    var sun = btn.querySelector('#theme-sun');
    if (moon) moon.classList.toggle('hidden', isDark);
    if (sun) sun.classList.toggle('hidden', !isDark);
    btn.title = isDark ? 'Светлая тема' : 'Тёмная тема';
  }

  document.addEventListener('DOMContentLoaded', function () {
    syncIcon();
    var btn = document.getElementById('theme-toggle');
    if (!btn) return;
    btn.addEventListener('click', function () {
      var isDark = document.documentElement.classList.toggle('dark');
      try { localStorage.setItem(KEY, isDark ? 'dark' : 'light'); } catch (e) {}
      syncIcon();
    });
  });
})();
