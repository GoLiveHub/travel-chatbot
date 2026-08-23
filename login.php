<?php
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
    <link rel="stylesheet" href="/assets/css/tailwind.min.css">
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;
            background: linear-gradient(160deg, #f0fdfa 0%, #f8fafc 45%, #ecfeff 100%);
            min-height: 100vh;
        }
        .tab-btn { transition: all .2s ease; }
        .tab-btn.active {
            color: #0d9488;
            border-color: #14b8a6;
        }
        .btn-primary {
            background-image: linear-gradient(to right, #14b8a6, #0d9488);
            transition: all .2s ease;
        }
        .btn-primary:hover {
            background-image: linear-gradient(to right, #0d9488, #0f766e);
            box-shadow: 0 6px 16px rgba(13, 148, 136, 0.3);
        }
        .field:focus {
            outline: none;
            border-color: #14b8a6;
            box-shadow: 0 0 0 3px rgba(20, 184, 166, 0.15);
        }
        .fade-in { animation: fadeIn .25s ease; }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(4px); }
            to   { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body class="flex flex-col items-center justify-center py-12 px-4">

    <!-- Logo -->
    <a href="/" class="flex items-center gap-2 mb-8">
        <span class="w-9 h-9 rounded-xl bg-gradient-to-br from-teal-400 to-teal-600 text-white flex items-center justify-center font-bold text-lg shadow-md">T</span>
        <span class="text-2xl font-bold text-slate-800 tracking-tight">Travel<span class="text-teal-600">.ru</span></span>
    </a>

    <!-- Card -->
    <div class="w-full max-w-sm bg-white rounded-2xl shadow-lg p-7">

        <!-- Tabs -->
        <div class="flex border-b border-slate-200 mb-6">
            <button type="button" id="tab-login" class="tab-btn active flex-1 pb-3 text-sm font-semibold text-slate-500 border-b-2 border-transparent" onclick="switchTab('login')">Вход</button>
            <button type="button" id="tab-register" class="tab-btn flex-1 pb-3 text-sm font-semibold text-slate-500 border-b-2 border-transparent" onclick="switchTab('register')">Регистрация</button>
        </div>

        <!-- Error -->
        <div id="error-box" class="hidden fade-in mb-4 px-4 py-3 rounded-lg bg-red-50 border border-red-100 text-red-600 text-sm"></div>

        <!-- Google OAuth -->
        <a href="/api/auth.php?action=google"
           class="w-full flex items-center justify-center gap-3 py-2.5 px-4 border border-slate-300 rounded-lg text-sm font-medium text-slate-700 hover:bg-slate-50 transition">
            <svg class="w-5 h-5" viewBox="0 0 24 24">
                <path fill="#EA4335" d="M12 5.04c1.62 0 3.06.56 4.2 1.64l3.12-3.12C17.46 1.8 14.96.75 12 .75 7.62.75 3.84 3.27 2.04 6.86l3.66 2.84C6.54 7.09 9 5.04 12 5.04z"/>
                <path fill="#4285F4" d="M23.25 12.27c0-.93-.08-1.61-.26-2.32H12v4.19h6.44c-.13 1.07-.83 2.68-2.39 3.76l3.57 2.77c2.14-1.97 3.63-4.88 3.63-8.4z"/>
                <path fill="#FBBC05" d="M5.71 14.29c-.25-.74-.39-1.53-.39-2.34s.14-1.6.38-2.34L2.04 6.77C1.22 8.42.75 10.16.75 11.95c0 1.79.47 3.53 1.29 5.18l3.67-2.84z"/>
                <path fill="#34A853" d="M12 23.25c3.04 0 5.59-1 7.45-2.72l-3.57-2.77c-.95.66-2.23 1.12-3.88 1.12-3 0-5.46-2.01-6.29-4.71l-3.66 2.84c1.8 3.59 5.58 6.24 9.95 6.24z"/>
            </svg>
            Войти через Google
        </a>

        <div class="flex items-center gap-3 my-5">
            <div class="flex-1 h-px bg-slate-200"></div>
            <span class="text-xs text-slate-400">или по email</span>
            <div class="flex-1 h-px bg-slate-200"></div>
        </div>

        <!-- Login form -->
        <form id="form-login" class="space-y-4">
            <input type="email" name="email" placeholder="Email" required autocomplete="email"
                   class="field w-full px-4 py-2.5 rounded-lg border border-slate-300 text-sm focus:border-teal-500">
            <input type="password" name="password" placeholder="Пароль" required autocomplete="current-password" minlength="6"
                   class="field w-full px-4 py-2.5 rounded-lg border border-slate-300 text-sm">
            <button type="submit"
                    class="btn-primary w-full py-2.5 rounded-lg text-white text-sm font-semibold disabled:opacity-60">
                Войти
            </button>
        </form>

        <!-- Register form (hidden by default) -->
        <form id="form-register" class="hidden space-y-4">
            <input type="text" name="name" placeholder="Имя" required autocomplete="name"
                   class="field w-full px-4 py-2.5 rounded-lg border border-slate-300 text-sm">
            <input type="email" name="email" placeholder="Email" required autocomplete="email"
                   class="field w-full px-4 py-2.5 rounded-lg border border-slate-300 text-sm">
            <input type="password" name="password" placeholder="Пароль (минимум 6 символов)" required autocomplete="new-password" minlength="6"
                   class="field w-full px-4 py-2.5 rounded-lg border border-slate-300 text-sm">
            <button type="submit"
                    class="btn-primary w-full py-2.5 rounded-lg text-white text-sm font-semibold disabled:opacity-60">
                Зарегистрироваться
            </button>
        </form>
    </div>

    <p class="mt-6 text-xs text-slate-400">
        Продолжая, вы принимаете <a href="/" class="underline hover:text-teal-600">правила сервиса</a> Travel.ru
    </p>

    <script>
        var errorBox = document.getElementById('error-box');

        function showError(msg) {
            errorBox.textContent = msg;
            errorBox.classList.remove('hidden');
        }

        function hideError() {
            errorBox.classList.add('hidden');
        }

        function switchTab(tab) {
            var isLogin = tab === 'login';
            document.getElementById('tab-login').classList.toggle('active', isLogin);
            document.getElementById('tab-register').classList.toggle('active', !isLogin);
            document.getElementById('form-login').classList.toggle('hidden', !isLogin);
            document.getElementById('form-register').classList.toggle('hidden', isLogin);
            hideError();
        }

        function setLoading(form, loading) {
            var btn = form.querySelector('button[type="submit"]');
            btn.disabled = loading;
            btn.textContent = loading ? 'Подождите…' : (form.id === 'form-login' ? 'Войти' : 'Зарегистрироваться');
        }

        async function submitForm(form, action) {
            hideError();
            setLoading(form, true);
            try {
                var res = await fetch('/api/auth.php?action=' + action, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                    body: new URLSearchParams(new FormData(form)).toString()
                });
                var data;
                try {
                    data = await res.json();
                } catch (e) {
                    throw new Error('Некорректный ответ сервера');
                }
                if (res.ok && data.ok !== false && data.success !== false) {
                    window.location.href = '/';
                    return;
                }
                showError(data.error || data.message || 'Произошла ошибка, попробуйте ещё раз');
                setLoading(form, false);
            } catch (err) {
                showError(err.message === 'Failed to fetch' ? 'Не удалось связаться с сервером' : err.message);
                setLoading(form, false);
            }
        }

        document.getElementById('form-login').addEventListener('submit', function (e) {
            e.preventDefault();
            submitForm(this, 'login');
        });

        document.getElementById('form-register').addEventListener('submit', function (e) {
            e.preventDefault();
            submitForm(this, 'register');
        });
    </script>
</body>
</html>
