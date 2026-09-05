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
    <script src="/assets/js/theme.js?v=20260901-c"></script>
    <link rel="stylesheet" href="/assets/css/tailwind.min.css">
    <link rel="stylesheet" href="/assets/css/styles.css?v=20260901-c">
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
            <span class="grid h-9 w-9 place-items-center rounded-xl bg-gradient-to-r from-blue-600 to-teal-500 text-white"><svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 2 11 13"></path><path d="M22 2 15 22l-4-9-9-4 20-7z"></path></svg></span>
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
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="email" name="email" required autocomplete="email" placeholder="Email"
                   aria-label="Email"
                   class="auth-input w-full rounded-xl border border-slate-300 px-4 py-3 text-sm transition focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200">
            <div class="pw-wrap">
                <input type="password" name="password" id="login-pw" required autocomplete="current-password" minlength="6" placeholder="Пароль"
                       aria-label="Пароль"
                       class="auth-input w-full rounded-xl border border-slate-300 px-4 py-3 pr-11 text-sm transition focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200">
                <button type="button" id="toggle-login-pw" class="pw-toggle" aria-label="Показать пароль">
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
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
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
                <button type="button" id="toggle-reg-pw" class="pw-toggle" aria-label="Показать пароль">
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

<script src="/assets/js/config.js"></script>
<script src="/assets/js/favs.js" defer></script>
<script src="/assets/js/currency.js" defer></script>
<script src="/assets/js/compare.js" defer></script>
<script src="/assets/js/chat.js" defer></script>
<script src="/assets/js/auth.js" defer></script>
</body>
</html>
