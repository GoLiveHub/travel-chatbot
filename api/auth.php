<?php
// api/auth.php — Регистрация, вход, выход, Google OAuth
declare(strict_types=1);
require __DIR__ . '/config.php';

rate_limit('auth', 10, 60);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = trim($_GET['action'] ?? '', '/');

// CORS — credentials требует конкретный Origin, не *
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$base = app_base_url();
if ($origin !== '' && preg_match('#^https?://[a-z0-9\-]+(\.[a-z0-9\-]+)*(:\d+)?$#i', $origin)) {
    header('Access-Control-Allow-Origin: ' . $origin);
} else {
    header('Access-Control-Allow-Origin: ' . $base);
}
header('Access-Control-Allow-Credentials: true');
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

auth_start();

switch ($path) {
    case 'register':
        if ($method !== 'POST') h_error('Метод не поддерживается', 405);
        csrf_check();
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
        session_regenerate_id(true);
        $_SESSION['user'] = $user;
        h_json(['ok' => true, 'user' => $user]);
        break;

    case 'login':
        if ($method !== 'POST') h_error('Метод не поддерживается', 405);
        csrf_check();
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
        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'code' => $code, 'client_id' => $clientId, 'client_secret' => $clientSecret,
                'redirect_uri' => $redirectUri, 'grant_type' => 'authorization_code',
            ]),
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $resp = curl_exec($ch);
        $curlErr = curl_error($ch);
        curl_close($ch);
        if ($resp === false || $resp === '') {
            error_log("Google OAuth token exchange failed: $curlErr");
            h_error('Не удалось связаться с Google. Попробуйте позже.');
        }
        $tokenResp = json_decode($resp, true);
        if (!is_array($tokenResp) || empty($tokenResp['access_token'])) {
            error_log("Google OAuth token response: " . ($resp ?: 'empty'));
            h_error('Не удалось получить токен Google');
        }
        $ch = curl_init('https://www.googleapis.com/oauth2/v2/userinfo');
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $tokenResp['access_token']],
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $profResp = curl_exec($ch);
        curl_close($ch);
        $profile = json_decode($profResp ?: '{}', true);
        if (!is_array($profile) || empty($profile['email'])) h_error('Не удалось получить данные профиля');
        $email = mb_strtolower($profile['email']);
        $user = users_find_by_email($email);
        if ($user === null) {
            $users = users_load();
            $user = [
                'id' => bin2hex(random_bytes(16)),
                'email' => $email,
                'name' => $profile['name'] ?? $email,
                'oauth_provider' => 'google',
                'created_at' => date('c'),
            ];
            $users[] = $user;
            users_save($users);
        }
        unset($user['password_hash']);
        session_regenerate_id(true);
        $_SESSION['user'] = $user;
        header('Location: /');
        exit;

    default:
        h_error('Неизвестное действие', 404);
}
