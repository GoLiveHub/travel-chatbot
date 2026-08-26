<?php
// Общая конфигурация API
declare(strict_types=1);
ob_start();

// Сессия — ДО любых header() вызовов
ini_set('session.gc_maxlifetime', 86400 * 7);
ini_set('session.sid_length', '48');
ini_set('session.sid_bits_per_character', '6');
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
if (session_status() === PHP_SESSION_NONE) {
    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'
        || !empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https'
        || !empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on');
    session_set_cookie_params([
        'lifetime' => 86400 * 7,
        'path' => '/',
        'httponly' => true,
        'secure' => $isSecure,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// Загрузка .env файла (с обработкой BOM)
$_envFile = __DIR__ . '/../.env';
if (is_file($_envFile)) {
    $_envContent = file_get_contents($_envFile);
    if ($_envContent !== false) {
        // Удаляем UTF-8 BOM если есть
        if (str_starts_with($_envContent, "\xEF\xBB\xBF")) {
            $_envContent = substr($_envContent, 3);
        }
        foreach (explode("\n", $_envContent) as $_line) {
            $_line = trim($_line);
            if ($_line === '' || $_line[0] === '#') continue;
            if (strpos($_line, '=') === false) continue;
            [$k, $v] = explode('=', $_line, 2);
            $k = trim($k);
            $v = trim(trim($v), '"\'');
            if ($k !== '' && !array_key_exists($k, $_ENV) && getenv($k) === false) {
                putenv("$k=$v");
                $_ENV[$k] = $v;
            }
        }
    }
}

const DATA_DIR = __DIR__ . '/../data';

function app_base_url(): string
{
    $configured = trim((string) (getenv('APP_BASE_URL') ?: ''));
    if ($configured !== '' && preg_match('#^https?://[a-z0-9.\-:\[\]]+$#i', $configured)) {
        return rtrim($configured, '/');
    }

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
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
    // Атомарное чтение через flock чтобы избежать race condition при параллельных запросах
    $fp = fopen($path, 'r');
    if ($fp === false) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => "Не удалось открыть $file"]);
        exit;
    }
    flock($fp, LOCK_SH);
    $raw = file_get_contents($path);
    flock($fp, LOCK_UN);
    fclose($fp);
    if ($raw === false || $raw === '') {
        // Пустой файл — возвращаем пустой массив как fallback
        error_log("load_json: $file is empty, returning []");
        return [];
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE) {
        // Повреждённый JSON — логируем и возвращаем пустой массив вместо 500
        error_log("load_json: $file parse error: " . json_last_error_msg() . ", returning []");
        return [];
    }
    return $data;
}

function save_json(string $file, array $data): bool
{
    $path = data_path($file);
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        error_log("save_json: json_encode failed for $file");
        return false;
    }
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

// --- CSRF защита (ротация токена после каждой проверки) ---
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_check(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') return;

    $token = $_POST['_csrf'] ?? '';
    $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

    if ($token === '' && $header !== '') {
        $token = $header;
    }

    if ($token === '' || !hash_equals(csrf_token(), $token)) {
        h_error('Неверная CSRF-проверка. Обновите страницу.', 403);
    }
    // Ротация токена после успешной проверки
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
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

// Лог бронирований (атомарная запись)
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
    if ($digits === null) return null;
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
    $entries = booking_entries();
    for ($i = count($entries) - 1; $i >= 0; $i--) {
        $entry = $entries[$i];
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
    $len = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($len === 0 && isset($_SERVER['HTTP_TRANSFER_ENCODING'])) {
        return true;
    }
    return $len > $maxBytes;
}

// --- Rate Limiting (файловый, по IP, атомарный) ---
function rate_limit(string $key, int $maxRequests, int $windowSeconds): void
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $dir = DATA_DIR . '/rate_limits';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
        if (!is_dir($dir)) {
            error_log("rate_limit: не удалось создать директорию $dir");
            return;
        }
    }

    $file = $dir . '/' . preg_replace('/[^a-z0-9_\-]/i', '_', $key) . '_' . md5($ip) . '.json';
    $now = time();
    $data = ['requests' => [], 'blocked_until' => 0];

    // Атомарное чтение через flock
    $fp = fopen($file, 'c+');
    if ($fp === false) return;
    flock($fp, LOCK_EX);

    $raw = fread($fp, filesize($file) ?: 1024);
    if ($raw !== false && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) $data = $decoded;
    }

    // Блокировка при DDoS
    if (($data['blocked_until'] ?? 0) > $now) {
        flock($fp, LOCK_UN);
        fclose($fp);
        http_response_code(429);
        header('Content-Type: application/json; charset=utf-8');
        header('Retry-After: ' . (($data['blocked_until'] ?? 0) - $now));
        echo json_encode(['ok' => false, 'error' => 'Слишком много запросов. Попробуйте позже.', 'answer' => 'Слишком много запросов. Подождите немного и попробуйте снова.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Очистка старых записей
    $data['requests'] = array_values(array_filter($data['requests'], fn($ts) => $ts > $now - $windowSeconds));
    $data['requests'][] = $now;

    if (count($data['requests']) > $maxRequests) {
        $data['blocked_until'] = $now + 300;
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($data));
        flock($fp, LOCK_UN);
        fclose($fp);
        http_response_code(429);
        header('Content-Type: application/json; charset=utf-8');
        header('Retry-After: 300');
        echo json_encode(['ok' => false, 'error' => 'Превышен лимит запросов. Блокировка на 5 минут.', 'answer' => 'Вы слишком часто отправляете сообщения. Подождите 5 минут.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data));
    flock($fp, LOCK_UN);
    fclose($fp);
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
        'password_hash' => password_hash($password, PASSWORD_DEFAULT, ['cost' => 12]),
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
    if (($user['oauth_provider'] ?? null) !== null) return null;
    if (!password_verify($password, $user['password_hash'] ?? '')) return null;
    $safe = $user;
    unset($safe['password_hash']);
    session_regenerate_id(true);
    $_SESSION['user'] = $safe;
    return $safe;
}

function auth_logout(): void
{
    $_SESSION = [];
    $params = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 3600,
        'path' => $params['path'],
        'domain' => $params['domain'],
        'secure' => $params['secure'],
        'httponly' => $params['httponly'],
        'samesite' => $params['samesite'] ?? 'Lax',
    ]);
    session_destroy();
}

// --- HTTP-заголовки безопасности ---
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: DENY');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}
