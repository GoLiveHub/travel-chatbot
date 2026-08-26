<?php
// api/review.php — приём отзывов (демо: пишет в журнал)
declare(strict_types=1);
require __DIR__ . '/config.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    h_error('Метод не поддерживается', 405);
}
if (request_is_too_large()) h_error('Слишком большой запрос', 413);
csrf_check();

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$hotelId = (int) ($input['hotel_id'] ?? 0);
$author = trim((string) ($input['author'] ?? ''));
$rating = (int) ($input['rating'] ?? 0);
$text = trim((string) ($input['text'] ?? ''));

if ($hotelId <= 0) {
    h_error('Не указан отель');
}
if ($author === '' || mb_strlen($author) > 60) {
    h_error('Укажите имя (до 60 символов)');
}
if ($rating < 1 || $rating > 10) {
    h_error('Оценка должна быть от 1 до 10');
}
if ($text === '' || mb_strlen($text) > 1000) {
    h_error('Напишите отзыв (до 1000 символов)');
}

$hotels = load_json('hotels.json');
$found = false;
foreach ($hotels as $h) {
    if ((int) $h['id'] === $hotelId) {
        $found = true;
        break;
    }
}
if (!$found) {
    h_error('Отель не найден', 404);
}

// Простая защита от дублей: один отзыв от одного имени в час
$rateLimit = 1; // хватит для демо
$logPath = data_path('reviews.log');
if (file_exists($logPath)) {
    $hourAgo = time() - 3600;
    foreach (file($logPath) as $line) {
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} \| (.*)$/', trim($line), $m)) {
            $data = json_decode($m[1], true);
            if (is_array($data) && $data['hotel_id'] == $hotelId && mb_strtolower($data['author']) === mb_strtolower($author)) {
                $ts = strtotime(substr($line, 0, 19));
                if ($ts !== false && $ts > $hourAgo) {
                    h_error('Спасибо! Вы уже оставили отзыв об этом отеле. Новый отзыв можно добавить позже.');
                }
            }
        }
    }
}

$entry = [
    'hotel_id' => $hotelId,
    'author' => $author,
    'rating' => $rating,
    'text' => $text,
];

$line = date('Y-m-d H:i:s') . ' | ' . json_encode($entry, JSON_UNESCAPED_UNICODE) . PHP_EOL;
if (file_put_contents($logPath, $line, FILE_APPEND | LOCK_EX) === false) {
    h_error('Не удалось сохранить отзыв', 500);
}

h_json([
    'ok' => true,
    'message' => 'Спасибо за отзыв! Он появится в списке.',
    'review' => $entry + ['ts' => date('Y-m-d H:i')],
], 201);
