<?php
// api/auth.php — Регистрация, вход, выход, Google OAuth
declare(strict_types=1);
require __DIR__ . '/config.php';

rate_limit('auth', 10, 60); // 10 попыток в минуту

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = trim($_GET['action'] ?? '', '/');

// CORS для фронтенда
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Credentials: true');
if ($method === 'OPTIONS') { http_response_code(204); exit; }

auth_start();

switch ($path) {
    case 'register':
        if ($method !== 'POST') h_error('Метод не поддерживается', 405);
        $body = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $email = trim((string) ($body['email'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        $name = trim((string) ($body['name'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) h_error('Введите корректный email');
        if (mb_strlen($password) < 6) h_error('Пароль минимум 6 символов');
        if ($name === '') h_error('Укажите имя');
        $user = users_create($email, $password, $name);
        if ($user === null) h_error('Пользователь с таким email уже существует');
        unset($user['password_hash']);
        auth_start();
        $_SESSION['user'] = $user;
        h_json(['ok' => true, 'user' => $user]);
        break;

    case 'login':
        if ($method !== 'POST') h_error('Метод не поддерживается', 405);
        $body = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $email = trim((string) ($body['email'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        if ($email === '' || $password === '') h_error('Email и пароль обязательны');
        $user = auth_login($email, $password);
        if ($user === null) h_error('Неверный email или пароль');
        h_json(['ok' => true, 'user' => $user]);
        break;

    case 'logout':
        auth_logout();
        h_json(['ok' => true]);
        break;

    case 'me':
        $user = auth_user();
        if ($user === null) h_json(['ok' => false, 'user' => null], 401);
        h_json(['ok' => true, 'user' => $user]);
        break;

    case 'google':
        // Начало OAuth — редирект на Google
        $clientId = getenv('GOOGLE_CLIENT_ID') ?: '';
        $redirectUri = app_base_url() . '/api/auth.php?action=google-callback';
        if ($clientId === '') h_error('Google OAuth не настроен');
        $authUrl = 'https://accounts.google.com/o/oauth2/v2/auth'
            . '?client_id=' . urlencode($clientId)
            . '&redirect_uri=' . urlencode($redirectUri)
            . '&response_type=code'
            . '&scope=openid%20email%20profile'
            . '&access_type=offline';
        header('Location: ' . $authUrl);
        exit;

    case 'google-callback':
        $code = $_GET['code'] ?? '';
        $clientId = getenv('GOOGLE_CLIENT_ID') ?: '';
        $clientSecret = getenv('GOOGLE_CLIENT_SECRET') ?: '';
        $redirectUri = app_base_url() . '/api/auth.php?action=google-callback';
        if ($code === '' || $clientId === '' || $clientSecret === '') {
            h_error('Ошибка Google OAuth: отсутствуют параметры');
        }
        // Обмен кода на токен
        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'code' => $code, 'client_id' => $clientId, 'client_secret' => $clientSecret,
                'redirect_uri' => $redirectUri, 'grant_type' => 'authorization_code',
            ]),
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
        ]);
        $tokenResp = json_decode(curl_exec($ch), true);
        curl_close($ch);
        if (empty($tokenResp['access_token'])) h_error('Не удалось получить токен Google');
        // Получение профиля
        $ch = curl_init('https://www.googleapis.com/oauth2/v2/userinfo');
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $tokenResp['access_token']],
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
        ]);
        $profile = json_decode(curl_exec($ch), true);
        curl_close($ch);
        if (empty($profile['email'])) h_error('Не удалось получить данные профиля');
        // Найти или создать пользователя
        $email = mb_strtolower($profile['email']);
        $user = users_find_by_email($email);
        if ($user === null) {
            $user = [
                'id' => bin2hex(random_bytes(16)),
                'email' => $email,
                'name' => $profile['name'] ?? $email,
                'oauth_provider' => 'google',
                'created_at' => date('c'),
            ];
            $users = users_load();
            $users[] = $user;
            users_save($users);
        }
        auth_start();
        $_SESSION['user'] = $user;
        // Редирект на главную
        header('Location: /');
        exit;

    default:
        h_error('Неизвестное действие', 404);
}
