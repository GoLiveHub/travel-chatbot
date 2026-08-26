<?php
// Отмена собственной брони по защищённой ссылке или номеру телефона.
declare(strict_types=1);
require __DIR__ . '/config.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    h_error('Метод не поддерживается', 405);
}
if (request_is_too_large()) {
    h_error('Слишком большой запрос', 413);
}
csrf_check();

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) $input = $_POST;

$ref = mb_strtoupper(trim((string) ($input['ref'] ?? '')));
$token = trim((string) ($input['token'] ?? ''));
$phone = trim((string) ($input['phone'] ?? ''));
if (!preg_match('/^TRV-[A-F0-9]{6,8}$/', $ref)) {
    h_error('Проверьте номер бронирования');
}

$entry = find_booking($ref, $token !== '' ? $token : null, $phone !== '' ? $phone : null);
if ($entry === null) {
    h_error('Бронирование не найдено или данные доступа не совпали', 404);
}
if (($entry['status'] ?? 'confirmed') === 'cancelled') {
    h_json(['ok' => true, 'status' => 'cancelled', 'message' => 'Бронирование уже отменено.']);
}

try {
    $checkin = new DateTime((string) $entry['checkin']);
    $deadline = (clone $checkin)->modify('-48 hours');
    if (new DateTime() >= $deadline) {
        h_error('Бесплатная онлайн-отмена закрывается за 48 часов до заезда. Обратитесь в поддержку.', 409);
    }
} catch (Exception $e) {
    h_error('В бронировании указаны некорректные даты', 500);
}

log_booking([
    'ref' => $ref,
    'event' => 'cancelled',
    'access_token' => (string) ($entry['access_token'] ?? ''),
]);

h_json([
    'ok' => true,
    'status' => 'cancelled',
    'message' => 'Бронирование отменено. Никаких списаний не будет.',
]);
