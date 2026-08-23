<?php
// Общая конфигурация API
declare(strict_types=1);

// Сессия — ДО любых header() вызовов
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 86400 * 7,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

const DATA_DIR = __DIR__ . '/../data';

function app_base_url(): string
{
    $configured = trim((string) (getenv('APP_BASE_URL') ?: ''));
    if ($configured !== '' && preg_match('#^https?://[a-z0-9.\-:\[\]]+$#i', $configured)) {
        return rtrim($configured, '/');
    }

    $https = ($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off';
    $host = preg_replace('/[^a-z0-9.\-:\[\]]/i', '', (string) ($_SERVER['HTTP_HOST'] ?? 'travel'));
    return ($https ? 'https' : 'http') . '://' . ($host ?: 'travel');
}

function data_path(string $file): string
{
    return DATA_DIR . '/' . $file;
}

function load_json(string $file): array
{
    $path = data_path($file);
    if (!file_exists($path)) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => "Данные $file не найдены"]);
        exit;
    }
    $data = json_decode(file_get_contents($path), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Ошибка парсинга JSON: ' . json_last_error_msg()]);
        exit;
    }
    return $data;
}

function save_json(string $file, array $data): bool
{
    $path = data_path($file);
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    return file_put_contents($path, $json, LOCK_EX) !== false;
}

function h_json(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function h_error(string $msg, int $code = 400): void
{
    h_json(['ok' => false, 'error' => $msg, 'answer' => $msg], $code);
}

// Валидация координат города по имени
function get_city_coords(string $city): ?array
{
    $cities = load_json('cities.json');
    foreach ($cities as $c) {
        if (mb_strtolower($c['name']) === mb_strtolower($city)) {
            return ['lat' => $c['lat'], 'lon' => $c['lon']];
        }
    }
    return null;
}

// Лог бронирований
function log_booking(array $entry): void
{
    $log = data_path('bookings.log');
    $line = date('Y-m-d H:i:s') . ' | ' . json_encode($entry, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    if (file_put_contents($log, $line, FILE_APPEND | LOCK_EX) === false) {
        throw new RuntimeException('Не удалось сохранить бронирование');
    }
}

function normalize_phone(string $phone): ?string
{
    $digits = preg_replace('/\D+/', '', $phone);
    if (preg_match('/^8\d{10}$/', $digits)) {
        $digits = '7' . substr($digits, 1);
    }
    if (!preg_match('/^\d{10,15}$/', $digits)) return null;
    return '+' . $digits;
}

function booking_entries(): array
{
    $path = data_path('bookings.log');
    if (!is_file($path)) return [];

    $entries = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if (!preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) \| (\{.*\})$/u', trim($line), $m)) continue;
        $data = json_decode($m[2], true);
        if (!is_array($data) || empty($data['ref'])) continue;
        $ref = (string) $data['ref'];
        if (($data['event'] ?? '') === 'cancelled' && isset($entries[$ref])) {
            $entries[$ref]['status'] = 'cancelled';
            $entries[$ref]['cancelled_at'] = $m[1];
            continue;
        }
        $entries[$ref] = ['ts' => $m[1], 'status' => 'confirmed'] + $data;
    }
    return array_values($entries);
}

function find_booking(string $ref, ?string $token = null, ?string $phone = null): ?array
{
    $ref = mb_strtoupper(trim($ref));
    $normalizedPhone = $phone !== null ? normalize_phone($phone) : null;
    foreach (array_reverse(booking_entries()) as $entry) {
        if (!hash_equals((string) ($entry['ref'] ?? ''), $ref)) continue;
        $tokenOk = $token !== null && $token !== '' && isset($entry['access_token'])
            && hash_equals((string) $entry['access_token'], $token);
        $entryPhone = isset($entry['phone']) ? normalize_phone((string) $entry['phone']) : null;
        $phoneOk = $normalizedPhone !== null && $entryPhone !== null
            && hash_equals($entryPhone, $normalizedPhone);
        if ($tokenOk || $phoneOk) return $entry;
    }
    return null;
}

function request_is_too_large(int $maxBytes = 16384): bool
{
    return (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > $maxBytes;
}

// --- Rate Limiting (файловый, по IP) ---
function rate_limit(string $key, int $maxRequests, int $windowSeconds): void
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $dir = DATA_DIR . '/rate_limits';
    if (!is_dir($dir)) @mkdir($dir, 0777, true);

    $file = $dir . '/' . preg_replace('/[^a-z0-9_\-]/i', '_', $key) . '_' . md5($ip) . '.json';
    $now = time();
    $data = ['requests' => [], 'blocked_until' => 0];

    if (file_exists($file)) {
        $raw = @file_get_contents($file);
        if ($raw !== false) $data = json_decode($raw, true) ?: $data;
    }

    // Блокировка при DDoS
    if (($data['blocked_until'] ?? 0) > $now) {
        http_response_code(429);
        header('Content-Type: application/json; charset=utf-8');
        header('Retry-After: ' . (($data['blocked_until'] ?? 0) - $now));
        echo json_encode(['ok' => false, 'error' => 'Слишком много запросов. Попробуйте позже.', 'answer' => 'Слишком много запросов. Подождите немного и попробуйте снова.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Очистка старых записей
    $data['requests'] = array_filter($data['requests'], fn($ts) => $ts > $now - $windowSeconds);
    $data['requests'][] = $now;

    if (count($data['requests']) > $maxRequests) {
        // 3 провала подряд → блокировка 5 минут
        $data['blocked_until'] = $now + 300;
        file_put_contents($file, json_encode($data), LOCK_EX);
        http_response_code(429);
        header('Content-Type: application/json; charset=utf-8');
        header('Retry-After: 300');
        echo json_encode(['ok' => false, 'error' => 'Превышен лимит запросов. Блокировка на 5 минут.', 'answer' => 'Вы слишком часто отправляете сообщения. Подождите 5 минут.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    file_put_contents($file, json_encode($data), LOCK_EX);
}

// --- Сессионная авторизация ---
function auth_start(): void
{
    // Сессия уже запущена в начале config.php
}

function auth_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function require_auth(): array
{
    $user = auth_user();
    if ($user === null) {
        h_json(['ok' => false, 'error' => 'Требуется авторизация', 'answer' => 'Для доступа нужно войти в аккаунт.'], 401);
    }
    return $user;
}

function users_load(): array
{
    return load_json('users.json');
}

function users_save(array $users): bool
{
    return save_json('users.json', $users);
}

function users_find_by_email(string $email): ?array
{
    $users = users_load();
    $email = mb_strtolower(trim($email));
    foreach ($users as $u) {
        if (($u['email'] ?? '') === $email) return $u;
    }
    return null;
}

function users_create(string $email, string $password, string $name): ?array
{
    $email = mb_strtolower(trim($email));
    if (users_find_by_email($email) !== null) return null;
    $users = users_load();
    $user = [
        'id' => bin2hex(random_bytes(16)),
        'email' => $email,
        'name' => trim($name),
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'oauth_provider' => null,
        'created_at' => date('c'),
    ];
    $users[] = $user;
    users_save($users);
    return $user;
}

function auth_login(string $email, string $password): ?array
{
    $user = users_find_by_email($email);
    if ($user === null) return null;
    if ($user['oauth_provider'] !== null) return null;
    if (!password_verify($password, $user['password_hash'] ?? '')) return null;
    $safe = $user;
    unset($safe['password_hash']);
    $_SESSION['user'] = $safe;
    return $safe;
}

function auth_logout(): void
{
    session_destroy();
}

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('X-Frame-Options: DENY');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}
