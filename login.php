<?php
// Страница входа и регистрации: вкладки, email-форма и Google OAuth
require_once __DIR__ . '/api/config.php';

auth_start();

if (auth_user()) {
    header('Location: /');
    exit;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Вход и регистрация — Travel.ru</title>
    <meta name="description" content="Войдите или создайте аккаунт Travel.ru, чтобы бронировать отели и сохранять избранное.">
    <script src="/assets/js/theme.js"></script>
    <link rel="stylesheet" href="/assets/css/tailwind.min.css">
    <link rel="stylesheet" href="/assets/css/styles.css">
    <link rel="icon" type="image/svg+xml" href="/assets/img/favicon.svg">
    <style>
        .auth-input {
            background-color: #ffffff;
            color: #1e293b;
        }
        .auth-input::placeholder { color: #64748b; }
        html.dark .auth-input {
            background-color: #1e293b;
            color: #e2e8f0;
            border-color: #475569;
        }
        html.dark .auth-input::placeholder { color: #94a3b8; }
        html.dark .auth-input:focus { border-color: #14b8a6; box-shadow: 0 0 0 2px rgba(20,184,166,.2); }
        html.dark .auth-error { background-color: rgba(127, 29, 29, .35); border-color: #7f1d1d; color: #fca5a5; }
        html.dark .auth-divider { background-color: #475569; }
        html.dark .auth-footer-text { color: #94a3b8; }
        .pw-toggle {
            position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
            cursor: pointer; color: #94a3b8; padding: 4px;
        }
        .pw-toggle:hover { color: #64748b; }
        .pw-wrap { position: relative; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: translateY(0); } }
        .fade-in { animation: fadeIn .25s ease; }
    </style>
</head>
<body class="flex min-h-screen flex-col bg-slate-50 text-slate-900">

<?php include __DIR__ . '/components/header.php'; ?>

<main class="mx-auto flex w-full max-w-7xl flex-1 flex-col items-center justify-center px-4 py-10 sm:px-6 sm:py-14">
    <div class="fade-in-up w-full max-w-md rounded-2xl border border-slate-200 bg-white p-7 shadow-sm sm:p-9">

        <!-- Логотип -->
        <a href="/" class="mb-7 flex items-center justify-center gap-2 text-xl font-extrabold text-slate-900" aria-label="Travel.ru — на главную">
            <span class="grid h-9 w-9 place-items-center rounded-xl bg-gradient-to-r from-blue-600 to-teal-500 text-white">✈</span>
            <span>Travel<span class="text-teal-500">.ru</span></span>
        </a>

        <!-- Вкладки Вход / Регистрация -->
        <div class="mb-6 grid grid-cols-2 gap-2 rounded-xl bg-slate-100 p-1.5" role="tablist" aria-label="Вход или регистрация">
            <button type="button" id="tab-login" role="tab" aria-selected="true" aria-controls="form-login"
                    class="auth-tab tab-active rounded-full border border-teal-500 bg-teal-500 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition">Вход</button>
            <button type="button" id="tab-register" role="tab" aria-selected="false" aria-controls="form-register"
                    class="auth-tab rounded-full border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-slate-400 hover:bg-slate-50">Регистрация</button>
        </div>

        <!-- Ошибки -->
        <div id="error-box" role="alert" class="auth-error mb-4 hidden rounded-xl border border-red-100 bg-red-50 px-4 py-3 text-sm text-red-600"></div>

        <!-- Вход через Google -->
        <a href="/api/auth.php?action=google"
           class="flex w-full items-center justify-center gap-3 rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 hover:shadow-sm">
            <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" aria-hidden="true">
                <path fill="#EA4335" d="M12 5.04c1.62 0 3.06.56 4.2 1.64l3.12-3.12C17.46 1.8 14.96.75 12 .75 7.62.75 3.84 3.27 2.04 6.86l3.66 2.84C6.54 7.09 9 5.04 12 5.04z"/>
                <path fill="#4285F4" d="M23.25 12.27c0-.93-.08-1.61-.26-2.32H12v4.19h6.44c-.13 1.07-.83 2.68-2.39 3.76l3.57 2.77c2.14-1.97 3.63-4.88 3.63-8.4z"/>
                <path fill="#FBBC05" d="M5.71 14.29c-.25-.74-.39-1.53-.39-2.34s.14-1.6.38-2.34L2.04 6.77C1.22 8.42.75 10.16.75 11.95c0 1.79.47 3.53 1.29 5.18l3.67-2.84z"/>
                <path fill="#34A853" d="M12 23.25c3.04 0 5.59-1 7.45-2.72l-3.57-2.77c-.95.66-2.23 1.12-3.88 1.12-3 0-5.46-2.01-6.29-4.71l-3.66 2.84c1.8 3.59 5.58 6.24 9.95 6.24z"/>
            </svg>
            Продолжить с Google
        </a>

        <!-- Разделитель -->
        <div class="my-6 flex items-center gap-3">
            <div class="auth-divider h-px flex-1 bg-slate-200"></div>
            <span class="text-xs font-medium uppercase tracking-wide text-slate-500">или по email</span>
            <div class="auth-divider h-px flex-1 bg-slate-200"></div>
        </div>

        <!-- Форма входа -->
        <form id="form-login" class="space-y-4" novalidate>
            <input type="email" name="email" required autocomplete="email" placeholder="Email"
                   aria-label="Email"
                   class="auth-input w-full rounded-xl border border-slate-300 px-4 py-3 text-sm transition focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200">
            <div class="pw-wrap">
                <input type="password" name="password" id="login-pw" required autocomplete="current-password" minlength="6" placeholder="Пароль"
                       aria-label="Пароль"
                       class="auth-input w-full rounded-xl border border-slate-300 px-4 py-3 pr-11 text-sm transition focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200">
                <button type="button" onclick="togglePw('login-pw',this)" class="pw-toggle" aria-label="Показать пароль">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                </button>
            </div>
            <button type="submit" data-label="Войти"
                    class="w-full rounded-xl bg-gradient-to-r from-blue-600 to-teal-500 px-4 py-3 text-sm font-semibold text-white transition hover:shadow-lg disabled:cursor-not-allowed disabled:opacity-60">
                Войти
            </button>
        </form>

        <!-- Форма регистрации (скрыта по умолчанию) -->
        <form id="form-register" class="hidden space-y-4" novalidate>
            <input type="text" name="name" required autocomplete="name" placeholder="Имя"
                   aria-label="Имя"
                   class="auth-input w-full rounded-xl border border-slate-300 px-4 py-3 text-sm transition focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200">
            <input type="email" name="email" required autocomplete="email" placeholder="Email"
                   aria-label="Email"
                   class="auth-input w-full rounded-xl border border-slate-300 px-4 py-3 text-sm transition focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200">
            <div class="pw-wrap">
                <input type="password" name="password" id="reg-pw" required autocomplete="new-password" minlength="6" placeholder="Пароль (минимум 6 символов)"
                       aria-label="Пароль"
                       class="auth-input w-full rounded-xl border border-slate-300 px-4 py-3 pr-11 text-sm transition focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200">
                <button type="button" onclick="togglePw('reg-pw',this)" class="pw-toggle" aria-label="Показать пароль">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                </button>
            </div>
            <button type="submit" data-label="Зарегистрироваться"
                    class="w-full rounded-xl bg-gradient-to-r from-blue-600 to-teal-500 px-4 py-3 text-sm font-semibold text-white transition hover:shadow-lg disabled:cursor-not-allowed disabled:opacity-60">
                Зарегистрироваться
            </button>
        </form>

        <p class="auth-footer-text mt-6 text-center text-xs leading-relaxed text-slate-500">
            Продолжая, вы принимаете
            <a href="/" class="font-medium underline transition hover:text-teal-600">правила сервиса</a>
            и политику конфиденциальности Travel.ru
        </p>
    </div>
</main>

<?php include __DIR__ . '/components/footer.php'; ?>

<script src="/assets/js/favs.js"></script>
<script src="/assets/js/currency.js"></script>
<script src="/assets/js/compare.js"></script>
<script src="/assets/js/chat.js"></script>
<script>
function togglePw(id, btn) {
    var inp = document.getElementById(id);
    if (!inp) return;
    var isPw = inp.type === 'password';
    inp.type = isPw ? 'text' : 'password';
    btn.innerHTML = isPw
        ? '<svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/></svg>'
        : '<svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>';
}
</script>
<script>
(function () {
    'use strict';

    var errorBox = document.getElementById('error-box');
    var tabs = {
        login: document.getElementById('tab-login'),
        register: document.getElementById('tab-register')
    };
    var forms = {
        login: document.getElementById('form-login'),
        register: document.getElementById('form-register')
    };

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
            ACTIVE_CLASSES.forEach(function (cls) { tab.classList.toggle(cls, isActive); });
            INACTIVE_CLASSES.forEach(function (cls) { tab.classList.toggle(cls, !isActive); });
            tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
            form.classList.toggle('hidden', !isActive);
        });
        hideError();
    }

    Object.keys(tabs).forEach(function (key) {
        tabs[key].addEventListener('click', function () { switchTab(key); });
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
        fetch('/api/auth.php?action=' + action, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body,
            credentials: 'same-origin'
        })
            .then(function (res) {
                return res.json().catch(function () {
                    throw new Error('Некорректный ответ сервера');
                }).then(function (data) {
                    return { ok: res.ok, data: data };
                });
            })
            .then(function (result) {
                if ((result.ok || result.status === 200) && result.data && result.data.ok !== false) {
                    window.location.href = '/';
                    return;
                }
                showError((result.data && (result.data.error || result.data.message)) || 'Произошла ошибка, попробуйте ещё раз');
                setLoading(form, false);
            })
            .catch(function (err) {
                showError(err && err.message === 'Failed to fetch' ? 'Не удалось связаться с сервером' : (err && err.message) || 'Произошла ошибка, попробуйте ещё раз');
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
</script>
</body>
</html>
