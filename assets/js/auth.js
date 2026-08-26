// Auth form: password toggle + tab switching + form submission
(function () {
  'use strict';

  // Password visibility toggle
  window.togglePw = function (id, btn) {
    var inp = document.getElementById(id);
    if (!inp) return;
    var isPw = inp.type === 'password';
    inp.type = isPw ? 'text' : 'password';
    btn.innerHTML = isPw
      ? '<svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/></svg>'
      : '<svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/></svg>';
  };

  var toggleLoginPw = document.getElementById('toggle-login-pw');
  if (toggleLoginPw) {
    toggleLoginPw.addEventListener('click', function () { window.togglePw('login-pw', this); });
  }
  var toggleRegPw = document.getElementById('toggle-reg-pw');
  if (toggleRegPw) {
    toggleRegPw.addEventListener('click', function () { window.togglePw('reg-pw', this); });
  }

  var errorBox = document.getElementById('error-box');
  var tabs = {
    login: document.getElementById('tab-login'),
    register: document.getElementById('tab-register'),
  };
  var forms = {
    login: document.getElementById('form-login'),
    register: document.getElementById('form-register'),
  };

  if (!tabs.login || !forms.login) return;

  var ACTIVE_CLASSES = ['tab-active', 'border-teal-500', 'bg-teal-500', 'text-white'];
  var INACTIVE_CLASSES = ['border-slate-300', 'text-slate-600'];

  function showError(message) {
    errorBox.textContent = message;
    errorBox.classList.remove('hidden');
  }

  function hideError() {
    errorBox.classList.add('hidden');
    errorBox.textContent = '';
  }

  function switchTab(name) {
    Object.keys(tabs).forEach(function (key) {
      var isActive = key === name;
      var tab = tabs[key];
      var form = forms[key];
      ACTIVE_CLASSES.forEach(function (cls) {
        tab.classList.toggle(cls, isActive);
      });
      INACTIVE_CLASSES.forEach(function (cls) {
        tab.classList.toggle(cls, !isActive);
      });
      tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
      form.classList.toggle('hidden', !isActive);
    });
    hideError();
  }

  Object.keys(tabs).forEach(function (key) {
    tabs[key].addEventListener('click', function () {
      switchTab(key);
    });
  });

  function setLoading(form, loading) {
    var btn = form.querySelector('button[type="submit"]');
    if (!btn) return;
    if (loading) {
      btn.dataset.busy = '1';
      btn.disabled = true;
      btn.textContent = 'Подождите…';
    } else {
      delete btn.dataset.busy;
      btn.disabled = false;
      btn.textContent = btn.dataset.label || 'Отправить';
    }
  }

  function isLoading(form) {
    var btn = form.querySelector('button[type="submit"]');
    return !!(btn && btn.dataset.busy);
  }

  function submitForm(form, action) {
    if (isLoading(form)) return;
    hideError();
    if (!form.checkValidity()) {
      form.reportValidity();
      return;
    }
    setLoading(form, true);
    var body = new URLSearchParams(new FormData(form)).toString();
    fetch(window.API_BASE + '/auth.php?action=' + action, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: body,
      credentials: 'same-origin',
    })
      .then(function (res) {
        return res
          .json()
          .catch(function () {
            throw new Error('Некорректный ответ сервера');
          })
          .then(function (data) {
            return { ok: res.ok, data: data };
          });
      })
      .then(function (result) {
        if ((result.ok || result.status === 200) && result.data && result.data.ok !== false) {
          window.location.href = '/';
          return;
        }
        showError(
          (result.data && (result.data.error || result.data.message)) ||
            'Произошла ошибка, попробуйте ещё раз'
        );
        setLoading(form, false);
      })
      .catch(function (err) {
        showError(
          (err && err.message === 'Failed to fetch')
            ? 'Не удалось связаться с сервером'
            : (err && err.message) || 'Произошла ошибка, попробуйте ещё раз'
        );
        setLoading(form, false);
      });
  }

  forms.login.addEventListener('submit', function (e) {
    e.preventDefault();
    submitForm(forms.login, 'login');
  });

  forms.register.addEventListener('submit', function (e) {
    e.preventDefault();
    submitForm(forms.register, 'register');
  });
})();
