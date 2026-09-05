// Тёмная тема: класс .dark на <html>, выбор сохраняется в localStorage
(function () {
  document.documentElement.classList.add('js');
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

  // Плавное появление блоков при прокрутке
  document.addEventListener('DOMContentLoaded', function () {
    var els = document.querySelectorAll('.reveal');
    if (!els.length) return;
    var isVisible = function (el) { return el.classList.contains('is-visible'); };
    var reveal = function (el) {
      if (isVisible(el)) return;
      el.classList.add('is-visible');
      if (window.__revealIO && window.__revealIO.observe) { try { window.__revealIO.unobserve(el); } catch (e) {} }
    };
    if (!('IntersectionObserver' in window)) {
      for (var i = 0; i < els.length; i++) els[i].classList.add('is-visible');
      return;
    }
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) { if (e.isIntersecting) reveal(e.target); });
    }, { threshold: 0.08, rootMargin: '0px 0px -40px 0px' });
    window.__revealIO = io;
    for (var j = 0; j < els.length; j++) io.observe(els[j]);
    // Надёжный sweep: раскрывать всё, что уже в/над вьюпортом (страховка от резких прыжков скролла)
    var sweep = function () {
      for (var k = 0; k < els.length; k++) {
        var el = els[k];
        if (isVisible(el)) continue;
        var r = el.getBoundingClientRect();
        if (r.top < window.innerHeight - 40) reveal(el);
      }
    };
    window.addEventListener('scroll', function () { requestAnimationFrame(sweep); }, { passive: true });
    sweep();
  });
})();
