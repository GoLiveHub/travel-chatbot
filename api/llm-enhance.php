<?php
// api/llm-enhance.php — Receives LLM classification from client, returns enhanced response
// Called by chat.js when the main chat.php returns low confidence (llm_hint: true)
declare(strict_types=1);
require __DIR__ . '/config.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    h_error('Метод не поддерживается', 405);
}
rate_limit('llm-enhance', 15, 60);
if (request_is_too_large(32768)) {
    h_error('Слишком большой запрос', 413);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    h_error('Неверный формат запроса');
}

$originalText = trim((string) ($input['text'] ?? ''));
$llmIntent = trim((string) ($input['llm_intent'] ?? ''));
$llmCity = trim((string) ($input['llm_city'] ?? ''));
$context = is_array($input['context'] ?? null) ? $input['context'] : [];

if ($originalText === '') {
    h_error('Текст не может быть пустым');
}

$textLower = mb_strtolower($originalText);
$hotels = load_json('hotels.json');
$cities = load_json('cities.json');

// --- Use LLM classification to enhance the response ---
$answer = null;
$suggestions = [];
$state = 'IDLE';
$flow = null;

// If LLM identified a city, try to find hotels
$matchedCity = null;
if ($llmCity !== '') {
    foreach ($cities as $c) {
        if (mb_stripos($c['name'], $llmCity) !== false || mb_strtolower($c['name']) === mb_strtolower($llmCity)) {
            $matchedCity = $c['name'];
            break;
        }
    }
    // Also check transliterations
    if ($matchedCity === null) {
        $aliases = [
            'праг' => 'Прага', 'амстердам' => 'Амстердам', 'санторин' => 'Санторини',
            'барселон' => 'Барселона', 'москв' => 'Москва', 'париж' => 'Париж',
            'лондон' => 'Лондон', 'рим' => 'Рим', 'токио' => 'Токио',
            'пекин' => 'Пекин', 'бангкок' => 'Бангкок', 'сочи' => 'Сочи',
        ];
        foreach ($aliases as $alias => $city) {
            if (mb_stripos($llmCity, $alias) !== false) {
                $matchedCity = $city;
                break;
            }
        }
    }
}

switch ($llmIntent) {
    case 'search':
    case 'refine':
        if ($matchedCity !== null) {
            $filtered = array_values(array_filter($hotels, fn($h) => mb_strtolower($h['city']) === mb_strtolower($matchedCity)));
            usort($filtered, fn($a, $b) => (float) $b['rating'] <=> (float) $a['rating']);
            $suggestions = array_slice($filtered, 0, 5);
            $answer = 'Подобрал отели в ' . cityPrep($matchedCity) . ' — вот лучшие варианты по рейтингу гостей:';
            $state = 'SEARCH_RESULTS';
        } else {
            $answer = 'В каком городе ищете отель? Назовите город — и я подберу лучшие варианты.';
        }
        break;
    case 'book':
        $answer = 'Для бронирования мне нужны: город, даты заезда и выезда, количество гостей, имя, телефон. Можно написать всё сразу или по одному пункту.';
        $flow = 'book';
        $state = 'BOOKING_FLOW';
        break;
    case 'cancel':
        $answer = 'Отменить бронь можно бесплатно не позднее чем за 48 часов до заезда. Откройте «Мои бронирования» (/bookings.php) и введите номер заявки вместе с телефоном.';
        break;
    case 'info':
        $answer = 'Какой отель вас интересует? Уточните город или название — и я расскажу подробнее об удобствах и услугах.';
        break;
    case 'promo':
        $PROMOS = load_json('promos.json');
        $promoLines = [];
        foreach ($PROMOS as $code => $p) {
            $promoLines[] = '🎟 ' . $code . ' — ' . ($p['description'] ?? 'скидка ' . ($p['value'] ?? '') . ($p['type'] === 'percent' ? '%' : '₽'));
        }
        $answer = "Сейчас действуют промокоды:\n" . implode("\n", $promoLines) . "\n\nВведите промокод при бронировании — система проверит автоматически.";
        break;
    case 'greeting':
        $greetings = [
            'Привет! 👋 Готов помочь с отелями — подбор, сравнение, бронь. Куда хотите поехать?',
            'Здравствуйте! 🏨 Я ассистент Travel.ru. Помогу найти идеальный отель. Какой город интересует?',
            'Алло! 👂 Слушаю вас. Где ищете отель?',
        ];
        $answer = $greetings[array_rand($greetings)];
        break;
    case 'thanks':
        $answer = 'Пожалуйста! 😊 Рад помочь. Если нужен ещё отель — просто напишите!';
        break;
    case 'help':
        $answer = 'Я умею: искать отели по городу/цене/удобствам, бронировать, отменять бронь, отвечать на вопросы про отели. Просто напишите, что нужно!';
        break;
    default:
        // Try to find hotels based on the original text
        $foundHotels = [];
        foreach ($hotels as $h) {
            if (mb_stripos($h['name'], $textLower) !== false || mb_stripos($h['city'], $textLower) !== false) {
                $foundHotels[] = $h;
            }
        }
        if (!empty($foundHotels)) {
            $suggestions = array_slice($foundHotels, 0, 3);
            $answer = 'Вот что нашёл по вашему запросу — может, один из этих вариантов подойдёт:';
            $state = 'SEARCH_RESULTS';
        } else {
            $answer = 'Могу помочь с подбором отеля! Укажите город и даты — например: «отели в Праге на выходные».';
        }
        break;
}

h_json([
    'ok' => true,
    'reset' => false,
    'answer' => $answer,
    'suggestions' => $suggestions,
    'flow' => $flow,
    'state' => $state,
    'hotel' => null,
    'hotelId' => null,
    'filters' => [
        'city' => $matchedCity,
        'min' => null, 'max' => null,
        'amenities' => [], 'type' => null, 'stars' => null,
    ],
    'prefill' => [],
    'llm_enhanced' => true,
]);
