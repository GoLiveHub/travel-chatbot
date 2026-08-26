// User dropdown close handler + logout
(function () {
  var btn = document.getElementById('user-menu-wrap');
  var avatarBtn = document.getElementById('user-avatar-btn');
  var dropdown = document.getElementById('user-dropdown');

  if (avatarBtn && dropdown) {
    avatarBtn.addEventListener('click', function (e) {
      e.stopPropagation();
      dropdown.classList.toggle('hidden');
    });
  }

  if (btn) {
    document.addEventListener('click', function (e) {
      if (!dropdown) return;
      if (btn.contains(e.target)) return;
      dropdown.classList.add('hidden');
    });
  }

  var logoutBtn = document.getElementById('logout-btn');
  if (logoutBtn) {
    logoutBtn.addEventListener('click', function () {
      fetch(window.API_BASE + '/auth.php?action=logout', { method: 'POST' }).then(function () {
        location.reload();
      });
    });
  }
})();
