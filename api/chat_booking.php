<?php
// chat_booking.php — inline booking creation (included from chat.php)
// Uses: $bookingData (input), sets $bookingResult (success) or $bookingError (failure)
// Requires: config.php already loaded (load_json, log_booking, booking_entries, normalize_phone)

declare(strict_types=1);

$hotelId = (int) ($bookingData['hotel_id'] ?? 0);
$name = trim((string) ($bookingData['name'] ?? ''));
$phone = normalize_phone(trim((string) ($bookingData['phone'] ?? '')));
$checkin = trim((string) ($bookingData['checkin'] ?? ''));
$checkout = trim((string) ($bookingData['checkout'] ?? ''));
$guests = (int) ($bookingData['guests'] ?? 1);

if ($hotelId <= 0) { $bookingError = 'Не указан отель'; return; }
if ($name === '' || mb_strlen($name) > 80 || preg_match('/[\x00-\x1F\x7F]/u', $name)) { $bookingError = 'Укажите имя длиной до 80 символов'; return; }
$name = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
if ($phone === null) { $bookingError = 'Укажите корректный телефон: от 10 до 15 цифр'; return; }
if ($checkin === '' || $checkout === '') { $bookingError = 'Укажите даты заезда и выезда'; return; }
if ($guests < 1 || $guests > 8) { $bookingError = 'Количество гостей должно быть от 1 до 8'; return; }

$hotels = load_json('hotels.json');
$hotel = null;
foreach ($hotels as $h) {
    if ((int) $h['id'] === $hotelId) { $hotel = $h; break; }
}
if ($hotel === null) { $bookingError = 'Отель не найден'; return; }

$nights = 0;
try {
    $d1 = new DateTime($checkin);
    $d2 = new DateTime($checkout);
    if ($d1 >= $d2) { $bookingError = 'Дата выезда должна быть позже даты заезда'; return; }
    $today = new DateTime(date('Y-m-d'));
    if ($d1 < $today) { $bookingError = 'Дата заезда не может быть в прошлом'; return; }
    $nights = max(1, (int) $d1->diff($d2)->days);
    if ($nights > 30) { $bookingError = 'За одну заявку можно забронировать не более 30 ночей'; return; }
} catch (Exception $e) {
    $bookingError = 'Некорректные даты';
    return;
}

$total = $hotel['price'] * $nights;
$ref = 'TRV-' . strtoupper(bin2hex(random_bytes(4)));
$accessToken = bin2hex(random_bytes(16));

foreach (booking_entries() as $e) {
    if (($e['status'] ?? 'confirmed') === 'confirmed'
        && (int) ($e['hotel_id'] ?? 0) === $hotelId
        && ($e['checkin'] ?? '') === $checkin
        && ($e['checkout'] ?? '') === $checkout
        && ($e['phone'] ?? '') === $phone) {
        $bookingError = 'У вас уже есть бронь этого отеля на эти даты (заявка ' . ($e['ref'] ?? '') . ')';
        return;
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
log_booking($logEntry);

$bookingResult = [
    'ok' => true,
    'ref' => $ref,
    'hotel' => $hotel['name'],
    'city' => $hotel['city'],
    'nights' => $nights,
    'total' => $total,
    'confirmation_url' => '/booking-confirm.php?ref=' . rawurlencode($ref) . '&token=' . rawurlencode($accessToken),
    'access_token' => $accessToken,
];
