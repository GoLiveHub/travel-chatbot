<?php
// api/booking.php — приём бронирований (демо: пишет в журнал)
declare(strict_types=1);
require __DIR__ . '/config.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    h_error('Метод не поддерживается', 405);
}
rate_limit('booking', 5, 60);
if (request_is_too_large()) {
    h_error('Слишком большой запрос', 413);
}
csrf_check();

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$hotelId = (int) ($input['hotel_id'] ?? 0);
$name = trim((string) ($input['name'] ?? ''));
$phone = normalize_phone(trim((string) ($input['phone'] ?? '')));
$checkin = trim((string) ($input['checkin'] ?? ''));
$checkout = trim((string) ($input['checkout'] ?? ''));
$guests = (int) ($input['guests'] ?? 1);
$promo = mb_strtoupper(trim((string) ($input['promo'] ?? '')));

// Валидация
if ($hotelId <= 0) {
    h_error('Не указан отель');
}
if ($name === '' || mb_strlen($name) > 80 || preg_match('/[\x00-\x1F\x7F]/u', $name)) {
    h_error('Укажите имя длиной до 80 символов');
}
$name = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
if ($phone === null) {
    h_error('Укажите корректный телефон: от 10 до 15 цифр');
}
if ($checkin === '' || $checkout === '') {
    h_error('Укажите даты заезда и выезда');
}
if ($guests < 1 || $guests > 8) {
    h_error('Количество гостей должно быть от 1 до 8');
}

$hotels = load_json('hotels.json');
$hotel = null;
foreach ($hotels as $h) {
    if ((int) $h['id'] === $hotelId) {
        $hotel = $h;
        break;
    }
}
if ($hotel === null) {
    h_error('Отель не найден', 404);
}

// Считаем ночи и сумму
$nights = 0;
try {
    $d1 = new DateTime($checkin);
    $d2 = new DateTime($checkout);
    if ($d1 >= $d2) {
        h_error('Дата выезда должна быть позже даты заезда');
    }
    $today = new DateTime(date('Y-m-d'));
    if ($d1 < $today) {
        h_error('Дата заезда не может быть в прошлом');
    }
    $nights = max(1, (int) $d1->diff($d2)->days);
    if ($nights > 30) {
        h_error('За одну заявку можно забронировать не более 30 ночей');
    }
    if ((int) $today->diff($d1)->days > 730) {
        h_error('Бронирование доступно не более чем на 2 года вперёд');
    }
} catch (Exception $e) {
    h_error('Некорректные даты');
}

$total = $hotel['price'] * $nights;

// Промокоды — единый источник: data/promos.json
$PROMOS = load_json('promos.json');
$discount = 0;
$promoApplied = null;
if ($promo !== '') {
    if (!isset($PROMOS[$promo])) {
        h_error('Промокод не найден. Проверьте код и попробуйте снова.');
    }
    $promoApplied = $promo;
    $p = $PROMOS[$promo];
    $discount = $p['type'] === 'percent'
        ? (int) round($total * $p['value'] / 100)
        : min((int) $p['value'], $total);
    $total = max(0, $total - $discount);
}

$ref = 'TRV-' . strtoupper(bin2hex(random_bytes(4)));
$accessToken = bin2hex(random_bytes(16));

// Защита от двойного бронирования: тот же отель + даты + телефон
foreach (booking_entries() as $e) {
    if (($e['status'] ?? 'confirmed') === 'confirmed'
        && (int) ($e['hotel_id'] ?? 0) === $hotelId
        && ($e['checkin'] ?? '') === $checkin
        && ($e['checkout'] ?? '') === $checkout
        && ($e['phone'] ?? '') === $phone) {
        h_error('У вас уже есть бронь этого отеля на эти даты (заявка ' . ($e['ref'] ?? '') . '). Проверьте страницу «Мои брони» или измените даты.', 409);
    }
}

$logEntry = [
    'ref' => $ref,
    'hotel_id' => $hotelId,
    'hotel' => $hotel['name'],
    'city' => $hotel['city'],
    'name' => $name,
    'phone' => $phone,
    'checkin' => $checkin,
    'checkout' => $checkout,
    'guests' => $guests,
    'nights' => $nights,
    'price_per_night' => $hotel['price'],
    'total' => $total,
    'status' => 'confirmed',
    'access_token' => $accessToken,
];
if ($promoApplied) {
    $logEntry['promo'] = $promoApplied;
    $logEntry['discount'] = $discount;
    $logEntry['total_original'] = $hotel['price'] * $nights;
}
log_booking($logEntry);

$response = [
    'ok' => true,
    'ref' => $ref,
    'hotel' => $hotel['name'],
    'city' => $hotel['city'],
    'nights' => $nights,
    'total' => $total,
    'message' => 'Бронирование принято! В демо-версии заявка сохранена в журнал.',
    'confirmation_url' => '/booking-confirm.php?ref=' . rawurlencode($ref) . '&token=' . rawurlencode($accessToken),
    'access_token' => $accessToken,
];
if ($promoApplied) {
    $response['promo'] = $promoApplied;
    $response['discount'] = $discount;
    $response['total_original'] = $hotel['price'] * $nights;
}

h_json($response, 201);
