<?php
// api/chat.php — ИИ-ассистент подбора отелей и бронирования
declare(strict_types=1);
require __DIR__ . '/config.php';
require_once __DIR__ . '/MlIntentClassifier.php';

// --- Timing & layer logging ---
$_chatStart = microtime(true);
$_layer = 'unknown';
$_intentSource = 'none';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    h_error('Метод не поддерживается', 405);
}
rate_limit('chat', 30, 60); // 30 запросов в минуту на IP
if (request_is_too_large(32768)) {
    h_error('Слишком большой запрос', 413);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$text = trim((string) ($input['text'] ?? ''));
if ($text === '') {
    h_json([
        'reset' => false,
        'ok' => true,
        'answer' => 'Похоже, сообщение пустое. Напишите, что вам нужно — например: «отели в Москве» или «промокоды».',
        'suggestions' => [], 'flow' => null, 'state' => 'IDLE',
        'hotel' => null, 'hotelId' => null,
        'filters' => ['city' => null, 'min' => null, 'max' => null, 'amenities' => [], 'type' => null, 'stars' => null],
        'prefill' => [],
    ]);
}
if (mb_strlen($text) > 500) {
    h_error('Сообщение слишком длинное: максимум 500 символов');
}

$textLower = mb_strtolower($text);
$hotels = load_json('hotels.json');
$cities = load_json('cities.json');

// --- ML-классификатор v3 (2959+ фраз, полный выход сущностей) ---
$mlClassifier = null;
$mlIntent = null;
$mlConfidence = 0.0;
$mlEntities = ['city' => null, 'price_min' => null, 'price_max' => null, 'checkin' => null, 'checkout' => null, 'guests' => null, 'stars' => null, 'amenities' => [], 'type' => null];
$_mlTime = 0.0;
if (class_exists('MlIntentClassifier')) {
    $mlClassifier = new MlIntentClassifier();
    $_mlStart = microtime(true);
    $mlResult = $mlClassifier->classify($text);
    $mlIntent = $mlResult['intent'] ?? 'unknown';
    $mlConfidence = $mlResult['confidence'] ?? 0.0;
    $mlEntities = $mlResult['entities'] ?? $mlEntities;
    $_mlTime = round((microtime(true) - $_mlStart) * 1000, 1);
    if ($mlIntent !== null && $mlConfidence > 0) $_layer = 'ml';
}

// --- Транслитерация для поиска отелей и городов по-английски и «русским произношением» ---
function to_lat(string $s): string
{
    $map = ['а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'e',
        'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm',
        'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u',
        'ф' => 'f', 'х' => 'h', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sch',
        'ъ' => '', 'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya'];
    return strtr(mb_strtolower($s), $map);
}
function flat(string $s): string
{
    return mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '', $s));
}
function plural(int $n, array $forms): string
{
    $n = abs($n) % 100;
    $n1 = $n % 10;
    if ($n > 10 && $n < 20) return $forms[2];
    if ($n1 > 1 && $n1 < 5) return $forms[1];
    if ($n1 === 1) return $forms[0];
    return $forms[2];
}
// Нечёткое попадание слова-триггера: точное вхождение или расстояние Левенштейна <= 1
// («покжи» -> «покажи», «сколька» -> «сколько»)
function fuzzy_hit(string $textLower, array $words): bool
{
    $tokens = preg_split('/[^\p{L}\p{N}]+/u', $textLower, -1, PREG_SPLIT_NO_EMPTY);
    foreach ($words as $w) {
        $wL = to_lat($w);
        if (mb_strpos($textLower, mb_strtolower($w)) !== false) return true;
        foreach ($tokens as $tok) {
            $t = mb_strtolower($tok);
            if (mb_strlen($t) < 4) continue;
            if (levenshtein($wL, to_lat($t)) <= 1) return true;
        }
    }
    return false;
}
// Расстояние Дамерау–Левенштейна (считает перестановки букв: «моксве» -> «москва»)
function dl_dist(string $a, string $b): int
{
    $n = mb_strlen($a); $m = mb_strlen($b);
    if ($n === 0) return $m;
    if ($m === 0) return $n;
    $d = [];
    for ($i = 0; $i <= $n; $i++) $d[$i][0] = $i;
    for ($j = 0; $j <= $m; $j++) $d[0][$j] = $j;
    for ($i = 1; $i <= $n; $i++) {
        for ($j = 1; $j <= $m; $j++) {
            $cost = mb_substr($a, $i - 1, 1) === mb_substr($b, $j - 1, 1) ? 0 : 1;
            $d[$i][$j] = min($d[$i - 1][$j] + 1, $d[$i][$j - 1] + 1, $d[$i - 1][$j - 1] + $cost);
            if ($i > 1 && $j > 1
                && mb_substr($a, $i - 1, 1) === mb_substr($b, $j - 2, 1)
                && mb_substr($a, $i - 2, 1) === mb_substr($b, $j - 1, 1)) {
                $d[$i][$j] = min($d[$i][$j], $d[$i - 2][$j - 2] + 1);
            }
        }
    }
    return $d[$n][$m];
}
// Поиск отеля по имени с транслитерацией и нечётким совпадением («захер» -> Sacher)
function find_hotel_by_name(string $text, array $hotels, ?bool &$exactHit = null): ?array
{
    $exactHit = false;
    $stop = ['отель', 'отели', 'отеле', 'отеля', 'отелю', 'отел', 'отелям', 'отелями', 'отелях',
        'гостиница', 'гостиницы', 'гостинице', 'гостиниц', 'покажи', 'открой', 'расскажи', 'подробнее',
        'номер', 'сколько', 'стоит', 'цена', 'цены', 'цену', 'почем', 'про', 'хочу', 'порядке', 'мне', 'о', 'забронируй',
        'забронировать', 'лучший', 'лучшие', 'топ', 'бронь', 'оформить', 'сравни', 'что', 'это', 'и',
        'любой', 'любом', 'любого', 'сейчас', 'просто', 'скажи', 'дайте', 'дай'];
    $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY);
    $best = null;
    $bestScore = 0;
    foreach ($hotels as $h) {
        $tokens = array_values(array_filter(array_map(fn($t) => flat($t), explode(' ', mb_strtolower($h['name'])))));
        $tokensLat = array_map('to_lat', $tokens);
        $score = 0;
        foreach ($words as $w) {
            $w = flat($w);
            if (mb_strlen($w) < 3 || in_array($w, $stop, true) || ctype_digit($w)) continue;
            $wLat = to_lat($w);
            $matched = false;
            // точное вхождение (кириллица или транслит)
            foreach ($tokens as $i => $tok) {
                if ($tok === '' || $tok === 'hotel' || $tok === 'otel' || $tok === 'отель') continue;
                if (mb_strpos($tok, $w) !== false || mb_strpos($tokensLat[$i], $wLat) !== false) { $score += 3; $matched = true; $exactHit = true; break; }
            }
            if ($matched) continue;
            // нечёткое совпадение: общий префикс >= 3 или расстояние <= 1
            foreach ($tokensLat as $i => $tokLat) {
                $tok = $tokens[$i];
                if ($tok === '' || $tok === 'hotel' || $tok === 'otel' || $tok === 'отель') continue;
                if (mb_strlen($wLat) < 4) continue;
                $lev = levenshtein($wLat, $tokLat);
                if ($lev === -1) continue;
                $tol = (int) ceil(mb_strlen($wLat) / 4) + 1;
                $prefix = 0;
                $n = min(mb_strlen($wLat), mb_strlen($tokLat), 3);
                while ($prefix < $n && mb_substr($wLat, $prefix, 1) === mb_substr($tokLat, $prefix, 1)) $prefix++;
                if ($prefix >= 3 && $lev <= $tol) { $score += 2; break; }
                if ($lev <= 1) { $score += 2; break; }
            }
        }
        if ($score > $bestScore) { $bestScore = $score; $best = $h; }
        elseif ($score === $bestScore && $score > 0 && $best !== null
            && (float) $h['rating'] > (float) $best['rating']) { $best = $h; }
    }
    return $bestScore > 0 ? $best : null;
}

// --- Извлечение сущностей из сообщения: телефон, имя, гости, даты ---
function extract_phone(string $text): ?string
{
    if (!preg_match_all('/\+?\d[\d\s()\-–—]{6,}/u', $text, $mm)) return null;
    foreach ($mm[0] as $cand) {
        $d = preg_replace('/\D+/', '', $cand);
        if (preg_match('/^(?:7|8)\d{10}$/', $d)) {
            return '+7' . substr($d, 1);
        }
        if (preg_match('/^\d{10}$/', $d)) return '+' . $d;
    }
    return null;
}
function extract_name(string $text): ?string
{
    $patterns = [
        '/(?:меня зовут|меня звать|меня зову|зовут|звать|моё имя|мое имя|имя)\s+([А-ЯЁа-яё]+)/u',
        '/\bя\s+([А-ЯЁа-яё][а-яё]+)(?=[,\s.!?«"\-—]|$)/iu',
        '/^([А-ЯЁа-яё][а-яё]{2,20})\s*,/u',
    ];
    $stop = ['отель', 'хочу', 'хочешь', 'хотим', 'бронь', 'бронька', 'забронировать', 'да', 'нет', 'приеду', 'еду',
        'поехать', 'подобрать', 'ищу', 'посоветуй', 'ждём', 'уверен', 'знаю', 'понимаю', 'люблю',
        'зовут', 'звать', 'имя', 'есть', 'и', 'в', 'на', 'с', 'для', 'по', 'о', 'у', 'это', 'этот',
        'так', 'тоже', 'просто', 'нас', 'мы', 'я', 'мой',
        'москва', 'париж', 'прага', 'лондон', 'рим', 'токио', 'барселона', 'там', 'тут', 'туда', 'сюда'];
    foreach ($patterns as $rx) {
        if (preg_match($rx, $text, $m)) {
            $low = mb_strtolower($m[1]);
            if (!in_array($low, $stop, true)) {
                return mb_strtoupper(mb_substr($low, 0, 1)) . mb_substr($low, 1);
            }
        }
    }
    return null;
}
function extract_guests(string $textLower): ?int
{
    if (preg_match('/(\d{1,2})\s*(?:взросл\w*|человек)\D{0,40}(\d{1,2})\s*(?:реб[её]н\w*|дет\w*)/u', $textLower, $m)) {
        $total = (int) $m[1] + (int) $m[2];
        return $total >= 1 && $total <= 8 ? $total : null;
    }
    if (preg_match('/одноместн/ui', $textLower)) return 1;
    if (preg_match('/двухместн/ui', $textLower)) return 2;
    if (preg_match('/тр[её]хместн/ui', $textLower)) return 3;
    if (preg_match('/втро[её]м|нас трое|будет трое|на троих|для троих/ui', $textLower)) return 3;
    if (preg_match('/вчетверо[её]м|нас четверо|будет четверо|на четверых|для четверых/ui', $textLower)) return 4;
    if (preg_match('/впятеро[её]м|нас пятеро|будет пятеро|на пятерых|для пятерых/ui', $textLower)) return 5;
    if (preg_match('/с\s+(женой|женою|мужем|мужою|супруг|супругой|девушкой|парнем|подругой|сыном|дочерью|другом|мамой|папой|братом|сестрой|коллегой)|вдво[её]м|на двоих|для двоих|нас двое/ui', $textLower)) return 2;
    if (preg_match('/(\d{1,2})\s*(?:гост|человек|персон)/u', $textLower, $m)) {
        $g = (int) $m[1];
        if ($g >= 1 && $g <= 8) return $g;
    }
    $w = ['десять' => 10, 'девять' => 9, 'восемь' => 8, 'семь' => 7, 'шесть' => 6, 'пять' => 5, 'пятеро' => 5, 'четверо' => 4, 'четыре' => 4, 'трое' => 3, 'три' => 3, 'двое' => 2, 'два' => 2, 'одного' => 1, 'один' => 1];
    $combined = '/(?<![\p{L}\p{N}])(' . implode('|', array_map('preg_quote', array_keys($w))) . ')(?![\p{L}\p{N}])/u';
    if (preg_match($combined, $textLower, $mMatch)) {
        return $w[$mMatch[1]] ?? null;
    }
    return null;
}
function extract_dates(string $textLower): ?array
{
    // Десятичный рейтинг вроде 8.5 — не дата 8 мая.
    $textLower = preg_replace('/рейтинг(?:ом)?\s*(?:от|выше|не ниже)?\s*[\d.,]+/ui', ' ', $textLower);
    // Убираем порядковые суффиксы: «с 20-го по 23-е августа» → «с 20 по 23 августа»
    $textLower = preg_replace('/(\d{1,2})\s*(?:-го|-ого|-ому|-ой|-ым|-ом|-ая|-ое|-ые|-ей|-яя|-ье|-ью|-юя|-юе|-ие)(?=\s|$|[,\.\!\?])/u', '$1', $textLower);
    // «через N дней» — заезд через N дней, выезд на следующий день
    if (preg_match('/через\s+(\d{1,2})\s+дн(?:я|ей|и)?/u', $textLower, $m)) {
        $n = min(60, max(1, (int) $m[1]));
        $ci = (new DateTime('today'))->modify('+' . $n . ' days');
        return ['checkin' => $ci->format('Y-m-d'), 'checkout' => (clone $ci)->modify('+1 day')->format('Y-m-d'), 'nights' => 1];
    }
    // Абсолютные даты: диапазон «20.08.2026 - 23.08.2026» или одиночная «20.08.2026» / «20.08»
    if (preg_match('/(\d{1,2})\.(\d{1,2})(?:\.(\d{2,4}))?\s*(?:с\s*)?(?:по|до|[-–—])\s*(\d{1,2})\.(\d{1,2})(?:\.(\d{2,4}))?(?![\d.])/u', $textLower, $m)) {
        $d1 = (int) $m[1]; $mo1 = (int) $m[2]; $d2 = (int) $m[4]; $mo2 = (int) $m[5];
        $y1 = $m[3] !== '' ? (int) $m[3] : null;
        $y2 = $m[6] !== '' ? (int) $m[6] : null;
        if ($y1 !== null) $y1 = $y1 < 100 ? 2000 + $y1 : $y1;
        if ($y2 !== null) $y2 = $y2 < 100 ? 2000 + $y2 : $y2;
        $needY = $y1 ?? $y2 ?? (int) date('Y');
        if (!checkdate($mo1, $d1, $needY) || !checkdate($mo2, $d2, $needY)) return null;
        $ci = new DateTime(sprintf('%04d-%02d-%02d', $needY, $mo1, $d1));
        $co = new DateTime(sprintf('%04d-%02d-%02d', $needY, $mo2, $d2));
        if ($y1 === null && $y2 === null && $ci < new DateTime('today')) {
            // Не сдвигаем год — пользователь имел в виду текущий год
        }
        if ($co <= $ci) $co = (clone $ci)->modify('+1 day');
        $nights = (int) $ci->diff($co)->days;
        if (preg_match('/на\s+(\d{1,2})\s+(?:ноч|сут)/u', $textLower, $mn)) $nights = (int) $mn[1];
        $nights = min(30, max(1, $nights));
        $co = (clone $ci)->modify('+' . $nights . ' days');
        return ['checkin' => $ci->format('Y-m-d'), 'checkout' => $co->format('Y-m-d'), 'nights' => $nights];
    }
    if (preg_match('/(\d{1,2})\.(\d{1,2})(?:\.(\d{2,4}))?(?![\d.])/u', $textLower, $m)) {
        $d1 = (int) $m[1]; $mo1 = (int) $m[2];
        $y1 = ($m[3] ?? '') !== '' ? (int) $m[3] : null;
        if ($y1 !== null) $y1 = $y1 < 100 ? 2000 + $y1 : $y1;
        $needY = $y1 ?? (int) date('Y');
        if (!checkdate($mo1, $d1, $needY)) return null;
        $ci = new DateTime(sprintf('%04d-%02d-%02d', $needY, $mo1, $d1));
        if ($y1 === null && $ci < new DateTime('today')) {
            // Не сдвигаем год — пользователь имел в виду текущий год
        }
        $nights = 1;
        if (preg_match('/на\s+(\d{1,2})\s+(?:ноч|сут)/u', $textLower, $mn)) $nights = (int) $mn[1];
        elseif (preg_match('/на\s+одн[уо]\s+ноч/u', $textLower)) $nights = 1;
        $nights = min(30, max(1, $nights));
        $co = (clone $ci)->modify('+' . $nights . ' days');
        return ['checkin' => $ci->format('Y-m-d'), 'checkout' => $co->format('Y-m-d'), 'nights' => $nights];
    }
    $months = [
        ['январ', 1], ['феврал', 2], ['март', 3], ['апрел', 4],
        ['(?<![\p{L}\p{N}])ма[яй](?![\p{L}\p{N}])|(?<![\p{L}\p{N}])мае(?![\p{L}\p{N}])|(?<![\p{L}\p{N}])маю(?![\p{L}\p{N}])', 5],
        ['июн', 6], ['июл', 7], ['август', 8],
        ['сентябр', 9], ['октябр', 10], ['ноябр', 11], ['декабр', 12],
    ];
    $found = null;
    $pos = PHP_INT_MAX;
    foreach ($months as [$rx, $m]) {
        if (preg_match('/(' . $rx . ')/u', $textLower, $mm, PREG_OFFSET_CAPTURE)) {
            $byteOffset = (int) $mm[0][1];
            $o = mb_strlen(mb_strcut($textLower, 0, $byteOffset, 'UTF-8'));
            if ($o < $pos) {
                $pos = $o;
                $found = $m;
            }
        }
    }
    if ($found === null) {
        if (mb_strpos($textLower, 'послезавтра') !== false) {
            $ci = new DateTime('+2 days');
            return ['checkin' => $ci->format('Y-m-d'), 'checkout' => (clone $ci)->modify('+1 day')->format('Y-m-d'), 'nights' => 1];
        }
        if (preg_match('/(?<![\p{L}\p{N}])завтра(?![\p{L}\p{N}])/u', $textLower)) {
            $ci = new DateTime('+1 day');
            return ['checkin' => $ci->format('Y-m-d'), 'checkout' => (clone $ci)->modify('+1 day')->format('Y-m-d'), 'nights' => 1];
        }
        return null;
    }
    $monthNo = $found;
    $before = mb_substr($textLower, 0, $pos);
    $day1 = null;
    $day2 = null;
    if (preg_match('/(?:с\s+)?(\d{1,2})\s*(?:по|до|[-–—])\s*(\d{1,2})\s*$/u', $before, $m)) {
        $day1 = (int) $m[1];
        $day2 = (int) $m[2];
    } elseif (preg_match('/(?:с\s+)?(\d{1,2})\s*$/u', $before, $m)) {
        $day1 = (int) $m[1];
    }
    if ($day1 === null) return null;
    $y = (int) (new DateTime())->format('Y');
    if (!checkdate($monthNo, $day1, $y)) return null;
    $nights = 1;
    if (preg_match('/на\s+(\d{1,2})\s+(?:ноч|сут)/u', $textLower, $m)) $nights = (int) $m[1];
    elseif (preg_match('/на\s+одн[уо]\s+ноч/u', $textLower)) $nights = 1;
    if ($day2 !== null && $day2 > $day1) $nights = $day2 - $day1;
    if ($nights < 1 || $nights > 30) $nights = 1;
    $today = new DateTime('today');
    $ci = new DateTime(sprintf('%04d-%02d-%02d', $y, $monthNo, $day1));
    // Если после месяца указан год — якорь даты на него («20 августа 2020» -> 2020)
    if (preg_match('/(?<![\p{L}\p{N}])((?:19|20)\d{2})(?![\p{L}\p{N}])/u', $textLower, $my)
        && checkdate($monthNo, $day1, (int) $my[1])) {
        $ci = new DateTime(sprintf('%04d-%02d-%02d', (int) $my[1], $monthNo, $day1));
    }
    $co = (clone $ci)->modify('+' . $nights . ' days');
    return ['checkin' => $ci->format('Y-m-d'), 'checkout' => $co->format('Y-m-d'), 'nights' => $nights];
}

// --- Город и его синонимы ---
$cityAliases = [
    'питер' => 'Санкт-Петербург', 'петербург' => 'Санкт-Петербург', 'спб' => 'Санкт-Петербург',
    'мск' => 'Москва', 'константинопол' => 'Стамбул',
    // английские/транслит названия
    'moscow' => 'Москва', 'st. petersburg' => 'Санкт-Петербург', 'saint-petersburg' => 'Санкт-Петербург', 'petersburg' => 'Санкт-Петербург',
    'sochi' => 'Сочи', 'kazan' => 'Казань', 'paris' => 'Париж', 'rome' => 'Рим', 'barcelona' => 'Барселона',
    'antalya' => 'Анталья', 'istanbul' => 'Стамбул', 'dubai' => 'Дубай', 'bangkok' => 'Бангкок',
    'vienna' => 'Вена', 'tokyo' => 'Токио', 'london' => 'Лондон', 'new york' => 'Нью-Йорк', 'nyc' => 'Нью-Йорк',
    'prague' => 'Прага', 'amsterdam' => 'Амстердам', 'santorini' => 'Санторини', 'phuket' => 'Пхукет',
    'tbilisi' => 'Тбилиси',
];
// Стебли названий — чтобы понять склонения: «в москве», «в риме», «в барселоне»
$cityStems = [
    'москв' => 'Москва', 'мск' => 'Москва', 'санкт' => 'Санкт-Петербург', 'питер' => 'Санкт-Петербург',
    'петербург' => 'Санкт-Петербург', 'спб' => 'Санкт-Петербург',
    'сочи' => 'Сочи', 'казан' => 'Казань', 'париж' => 'Париж', 'рим' => 'Рим',
    'барсел' => 'Барселона', 'анталь' => 'Анталья', 'стамбул' => 'Стамбул',
    'дуба' => 'Дубай', 'бангк' => 'Бангкок', 'вен' => 'Вена',
    'токио' => 'Токио', 'лондон' => 'Лондон', 'нью' => 'Нью-Йорк', 'йорк' => 'Нью-Йорк',
    'праг' => 'Прага', 'амстердам' => 'Амстердам', 'санторин' => 'Санторини',
    'пхукет' => 'Пхукет', 'пхук' => 'Пхукет', 'тбилис' => 'Тбилиси',
    'нижн' => 'Нижний Новгород', 'новгород' => 'Нижний Новгород',
];
// --- Страна и её синонимы ---
$countryAliases = [
    'франция' => 'Франция', 'французи' => 'Франция', 'французск' => 'Франция', 'во францию' => 'Франция', 'францию' => 'Франция',
    'австрия' => 'Австрия', 'австрийск' => 'Австрия', 'в австрию' => 'Австрия',
    'италия' => 'Италия', 'итальянск' => 'Италия', 'в италию' => 'Италия',
    'испания' => 'Испания', 'испанск' => 'Испания', 'в испанию' => 'Испания',
    'турция' => 'Турция', 'турецк' => 'Турция', 'в турцию' => 'Турция',
    'оаэ' => 'ОАЭ', 'эмират' => 'ОАЭ', 'в оаэ' => 'ОАЭ',
    'таиланд' => 'Таиланд', 'тайск' => 'Таиланд', 'в таиланд' => 'Таиланд', 'тайланд' => 'Таиланд',
    'япония' => 'Япония', 'японск' => 'Япония', 'в японию' => 'Япония',
    'великобритания' => 'Великобритания', 'британия' => 'Великобритания', 'англия' => 'Великобритания', 'в великобританию' => 'Великобритания', 'в британию' => 'Великобритания', 'в англию' => 'Великобритания',
    'сша' => 'США', 'америк' => 'США', 'в сша' => 'США', 'в америку' => 'США',
    'чехия' => 'Чехия', 'чешск' => 'Чехия', 'в чехию' => 'Чехия',
    'нидерланды' => 'Нидерланды', 'голландия' => 'Нидерланды', 'в нидерланды' => 'Нидерланды', 'в голландию' => 'Нидерланды',
    'греческ' => 'Греция', 'греция' => 'Греция', 'в грецию' => 'Греция',
    'грузия' => 'Грузия', 'грузинск' => 'Грузия', 'в грузию' => 'Грузия',
    'росси' => 'Россия', 'русск' => 'Россия', 'в россию' => 'Россия',
    'германия' => 'Германия', 'немецк' => 'Германия', 'в германию' => 'Германия',
    'дубай' => 'ОАЭ', 'оаэ' => 'ОАЭ',
];
// Страна → список городов (из cities.json)
$countryCities = [];
foreach ($cities as $c) {
    $country = $c['country'] ?? '';
    if ($country !== '') $countryCities[$country][] = $c['name'];
}
// Склонения городов (предложный падеж: «в Москве», «в Санкт-Петербурге»)
$cityPrepos = [
    'Москва' => 'Москве', 'Санкт-Петербург' => 'Санкт-Петербурге', 'Сочи' => 'Сочи',
    'Казань' => 'Казани', 'Париж' => 'Париже', 'Рим' => 'Риме', 'Барселона' => 'Барселоне',
    'Анталья' => 'Анталье', 'Стамбул' => 'Стамбуле', 'Дубай' => 'Дубае',
    'Бангкок' => 'Бангкоке', 'Вена' => 'Вене', 'Токио' => 'Токио', 'Лондон' => 'Лондоне',
    'Нью-Йорк' => 'Нью-Йорке', 'Прага' => 'Праге', 'Амстердам' => 'Амстердаме',
    'Санторини' => 'Санторини', 'Пхукет' => 'Пхукете', 'Тбилиси' => 'Тбилиси',
];
function cityPrep(string $city): string
{
    global $cityPrepos;
    return $cityPrepos[$city] ?? $city;
}
function pluralNight(int $n): string {
    $r = $n % 10; $t = $n % 100;
    if ($r === 1 && $t !== 11) return 'ночь';
    if ($r >= 2 && $r <= 4 && ($t < 12 || $t > 14)) return 'ночи';
    return 'ночей';
}
function pluralGuests(int $n): string {
    $r = $n % 10; $t = $n % 100;
    if ($r === 1 && $t !== 11) return 'гость';
    if ($r >= 2 && $r <= 4 && ($t < 12 || $t > 14)) return 'гостя';
    return 'гостей';
}
// Детект страны в тексте — пока просто фиксируем, city-зависимые проверки ниже
$matchedCountry = null;
$matchedCountrySub = null;
// Стебли стран — первые 4+ символов для нечёткого поиска («франции» = «франц»)
$countryStems = [
    'франц' => 'Франция', 'австр' => 'Австрия', 'итали' => 'Италия', 'испан' => 'Испания',
    'турц' => 'Турция', 'эмират' => 'ОАЭ', 'таилан' => 'Таиланд', 'тайлан' => 'Таиланд',
    'япон' => 'Япония', 'великобрит' => 'Великобритания', 'британ' => 'Великобритания', 'англи' => 'Великобритания',
    'сша' => 'США', 'америк' => 'США',
    'чех' => 'Чехия', 'нидерланд' => 'Нидерланды', 'голланд' => 'Нидерланды',
    'грец' => 'Греция', 'груз' => 'Грузия', 'росси' => 'Россия', 'герман' => 'Германия', 'немец' => 'Германия',
    'азерб' => 'Азербайджан', 'вьетнам' => 'Вьетнам',
    'китай' => 'Китай', 'корея' => 'Корея', 'египет' => 'Египет',
    'индонези' => 'Индонезия', 'малайзи' => 'Малайзия', 'филиппин' => 'Филиппины',
    'мальдив' => 'Мальдивы', 'шри-ланк' => 'Шри-Ланка',
    'хорват' => 'Хорватия', 'словени' => 'Словения', 'болгар' => 'Болгария',
    'румын' => 'Румыния', 'венгри' => 'Венгрия', 'польш' => 'Польша',
    'финлянд' => 'Финляндия', 'швеци' => 'Швеция', 'норвег' => 'Норвегия',
    'португали' => 'Португалия', 'бельги' => 'Бельгия', 'швейцари' => 'Швейцария',
];
$countryPrepos = [
    'Франция' => 'Франции', 'Австрия' => 'Австрии', 'Италия' => 'Италии', 'Испания' => 'Испании',
    'Турция' => 'Турции', 'ОАЭ' => 'ОАЭ', 'Таиланд' => 'Таиланде', 'Япония' => 'Японии',
    'Великобритания' => 'Великобритании', 'США' => 'США', 'Чехия' => 'Чехии',
    'Нидерланды' => 'Нидерландах', 'Греция' => 'Греции', 'Грузия' => 'Грузии', 'Россия' => 'России', 'Германия' => 'Германии',
];
$amenityLabels = [
    'pool' => 'с бассейном', 'spa' => 'со спа', 'beach' => 'у моря', 'wifi' => 'с Wi-Fi',
    'breakfast' => 'с завтраком', 'bar' => 'с баром', 'gym' => 'с фитнесом',
    'parking' => 'с парковкой', 'fireplace' => 'с камином', 'terrace' => 'с террасой',
    'kitchen' => 'с кухней', 'airport' => 'с трансфером', 'all-inclusive' => 'всё включено',
    'kid-club' => 'с детским клубом', 'ski' => 'горнолыжный', 'mountain' => 'в горах',
];
$amenityNoun = [
    'pool' => 'бассейн', 'spa' => 'спа', 'beach' => 'пляж', 'wifi' => 'Wi-Fi',
    'breakfast' => 'завтрак', 'bar' => 'бар', 'gym' => 'фитнес', 'parking' => 'парковка',
    'fireplace' => 'камин', 'terrace' => 'терраса', 'kitchen' => 'кухня', 'airport' => 'трансфер',
    'all-inclusive' => 'всё включено', 'kid-club' => 'детский клуб', 'ski' => 'горнолыжный', 'mountain' => 'в горах',
];
// Сначала проверяем точные алиасы (франция, французи, в австрию)
foreach ($countryAliases as $alias => $country) {
    if (mb_strpos($textLower, $alias) !== false) {
        $matchedCountry = $country;
        $matchedCountrySub = $alias;
        break;
    }
}
// Если не нашли — по стеблям (франции → франц, чехию → чех)
if ($matchedCountry === null) {
    foreach ($countryStems as $stem => $country) {
        if (mb_strpos($textLower, $stem) !== false) {
            $matchedCountry = $country;
            $matchedCountrySub = $stem;
            break;
        }
    }
}
// Обнаружение неизвестной страны: только при явном намерении поиска/поездки
if ($matchedCountry === null) {
    // Определяем, есть ли явное намерение поиска/поездки
    $hasTravelIntent = (bool) preg_match('/отел\w*|найди|подбери|подбер|еду|хочу в|поездк|путешеств|旅游|travel|hotels|гостиниц|снять номер|бронир/ui', $textLower);
    if ($hasTravelIntent && preg_match('/(?:в|во|из)\s+(\p{L}{4,25})\b/ui', $textLower, $mCand)) {
        $candidate = trim($mCand[1]);
        // Исключаем стоп-слова: местоимения, предлоги, типичные существительные после «в»
        $countryStopWords = ['котор', 'этот', 'такой', 'весь', 'кажд', 'друг', 'наш', 'ваш', 'свой',
            'туда', 'сюда', 'тогда', 'когда', 'пока', 'где', 'куда', 'откуда', 'как', 'чем', 'кто',
            'что', 'какой', 'чей', 'сколько', 'нужн', 'можно', 'хочу', 'буду', 'будем', 'нас',
            'меня', 'ему', 'ей', 'им', 'них', 'нем', 'ней', 'ваш', 'наш', 'мой', 'твой',
            'тут', 'там', 'вот', 'это', 'эти', 'этих', 'этого', 'этой', 'этому',
            'его', 'её', 'их', 'них', 'ним', 'ней', 'него', 'неё', 'ними',
            'для', 'при', 'над', 'под', 'без', 'после', 'перед', 'между', 'через',
            // Типичные существительные/прилагательные после «в/во/из»
            'город', 'номер', 'район', 'сторон', 'смысл', 'случа', 'вопрос', 'ответ',
            'пример', 'итог', 'конц', 'начал', 'центр', 'процесс', 'течени', 'раз',
            'рубль', 'доллар', 'евро', 'валют', 'цен', 'бюджет', 'список', 'связ',
            'случа', 'очеред', 'основ', 'порядк', 'результат', 'отлич', 'конец', 'вид',
            'дом', 'комнат', 'гараж', 'офис', 'магазин', 'ресторан', 'кафе', 'бар',
            'аэропорт', 'вокзал', 'станци', 'остановк', 'мост', 'парк', 'сад', 'лес',
            'море', 'река', 'озеро', 'гора', 'поле', 'пустыр', 'площад', 'улиц',
            'центральн', 'северн', 'южн', 'восточн', 'западн', 'верхн', 'нижн', 'лев', 'прав',
            'хорош', 'плох', 'больш', 'маленьк', 'красив', 'нужн', 'важн', 'главн',
            // Еда, аллергены, физические объекты — не страны
            'меню', 'арахис', 'аллерген', 'арахис', 'глютен', 'лактоз', 'сахар', 'диет',
            'океан', 'балкан', 'остров', 'берег', 'пляж', 'горизонт', 'облак',
            // Типичные слова после «в» в контексте отелей
            'отел', 'гостиниц', 'номер', 'бронь', 'рейс', 'зал', 'аэропор',
            'район', 'город', 'кухн', 'вайфай', 'wifi', 'интернет',
            'чтобы', 'ятобы', 'какбы', 'чё', 'чо', 'шо', 'какойт'];
        $isStop = false;
        foreach ($countryStopWords as $sw) {
            if (mb_strpos($candidate, $sw) !== false) { $isStop = true; break; }
        }
        // Проверяем, что это НЕ известная страна и НЕ город
        $isKnown = $isStop;
        if (!$isKnown) {
            foreach ($countryAliases as $ka => $kv) {
                if (mb_strpos($ka, $candidate) !== false || mb_strpos($kv, mb_strtoupper(mb_substr($candidate, 0, 1)) . mb_substr($candidate, 1)) !== false) { $isKnown = true; break; }
            }
        }
        if (!$isKnown) {
            foreach ($countryStems as $ks => $kv) {
                if (mb_strpos($candidate, $ks) !== false) { $isKnown = true; break; }
            }
        }
        if (!$isKnown) {
            foreach ($cities as $c) {
                $cn = mb_strtolower($c['name']);
                if (mb_strpos($cn, $candidate) !== false || mb_strpos($candidate, $cn) !== false) { $isKnown = true; break; }
            }
        }
        if (!$isKnown) {
            foreach ($cityStems as $stem => $city) {
                if (mb_strpos($candidate, $stem) !== false || mb_strpos($stem, $candidate) !== false) { $isKnown = true; break; }
            }
        }
        if (!$isKnown) {
            foreach ($cities as $c) {
                $cn = mb_strtolower($c['name']);
                $first = mb_substr($candidate, 0, 1);
                if (mb_substr($cn, 0, 1) === $first && abs(mb_strlen($candidate) - mb_strlen($cn)) <= 2) {
                    $dist = levenshtein($candidate, $cn);
                    if ($dist >= 0 && $dist <= 2) { $isKnown = true; break; }
                }
            }
        }
        if (!$isKnown) {
            $matchedCountry = mb_strtoupper(mb_substr($candidate, 0, 1)) . mb_substr($candidate, 1);
        }
    }
}

// --- Оскорбления: не повторять мат, отвечать вежливо ---
$insultPats = [
    '/(?:у[её]б[аоеи]к|хуй|ху[еёи]|пид[аоеи]р|бляд|сука|сук[аеу]|еб[аоеи]т|ебан|ёбан|п[оа]ху[еяй]|наху[еяй]|фигн|фиг|дерьм|дерьмо|говн|идиот|урод|мудак|лох|кретин|дебил|тупиц|тупой|тупая|дур[аея]|болван|бестолк|жоп|выблядок|гнид|сволоч|мраз[ьи]|\bовощ\b|тупень|тупак|\bментал|даун)/ui',
];
$insultHit = false;
foreach ($insultPats as $ip) {
    if (preg_match($ip, $textLower)) { $insultHit = true; break; }
}

$matchedCity = null;
$matchedSub = null;
foreach ($cities as $c) {
    $cn = mb_strtolower($c['name']);
    if (mb_strpos($textLower, $cn) !== false) {
        $matchedCity = $c['name'];
        $matchedSub = $cn;
        break;
    }
}
if ($matchedCity === null) {
    foreach ($cityAliases as $alias => $city) {
        if (mb_strpos($textLower, $alias) !== false) {
            $matchedCity = $city;
            $matchedSub = $alias;
            break;
        }
    }
}
if ($matchedCity === null) {
    foreach ($cityStems as $stem => $city) {
        if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($stem, '/') . '(?![\p{L}\p{N}])/u', $textLower)) {
            $matchedCity = $city;
            $matchedSub = $stem;
            break;
        }
    }
}
// Опечатки в названиях городов: «моксве» -> Москва, «сачи» -> Сочи, «вене» -> Вена.
// Токен должен начинаться с той же буквы, что и город, и быть близким по длине:
// иначе «цена»/«ночи»/«меня» ложно матчатся в «Вену»/«Сочи».
// Префилл данных городов — чтобы не считать to_lat/длину на каждом сравнении.
$cityFuzzy = [];
foreach ($cities as $c) {
    $cn = mb_strtolower($c['name']);
    $cityFuzzy[] = ['ru' => $cn, 'lat' => to_lat($cn), 'len' => mb_strlen($cn), 'first' => mb_substr($cn, 0, 1)];
}
$cityStop = [
    'отель', 'отели', 'отеле', 'отеля', 'отелям', 'гостиниц', 'гостиница', 'гостинице',
    'бронь', 'бронька', 'забронир', 'забронировать', 'сколько', 'стоит', 'стоят', 'цена', 'цены',
    'цену', 'почем', 'покажи', 'лучшие', 'лучший', 'ночь', 'ночи', 'ночей', 'сутки', 'человек',
    'гостей', 'гостя', 'гость', 'гости', 'есть', 'можно', 'хочу', 'номер', 'номера', 'для',
    'пожалуйста', 'подбери', 'найди', 'расскажи', 'скажи', 'помочь', 'можешь', 'какой', 'какие',
    'какая', 'дайте', 'привет', 'спасибо', 'пока', 'все', 'вот', 'это', 'этот', 'эти', 'тоже',
    'ещё', 'еще', 'двухместный', 'одноместный', 'трехместный', 'трёхместный', 'завтрака',
    'завтрак', 'ближайшие', 'нужна', 'нужен', 'нужно', 'так', 'там', 'тут', 'нас', 'меня', 'мой',
    'свой', 'сейчас', 'сегодня', 'завтра', 'неделю', 'поехать', 'снять', 'свободные', 'свободн',
    'койко', 'зарезервир', 'оплатить', 'добавить', 'узнать', 'вариант', 'варианты',
];
if ($matchedCity === null) {
    $tokens = preg_split('/[^\p{L}\p{N}]+/u', $textLower, -1, PREG_SPLIT_NO_EMPTY);
    $bestCity = null;
    $bestSub = null;
    $bestDist = 99;
    foreach ($tokens as $tok) {
        $t = mb_strtolower($tok);
        $len = mb_strlen($t);
        if ($len < 3 || in_array($t, $cityStop, true)) continue;
        $tol = $len <= 5 ? 1 : 2;
        $tLat = to_lat($t);
        $first = mb_substr($t, 0, 1);
        foreach ($cityFuzzy as $cf) {
            if ($cf['first'] !== $first) continue;
            if (abs($len - $cf['len']) > 2) continue;
            $dist = levenshtein($tLat, $cf['lat']);
            if ($dist === -1) continue;
            if ($dist > $tol) {
                $dl = dl_dist($tLat, $cf['lat']);
                if ($dl < $dist) $dist = $dl;
            }
            if ($dist <= $tol && $dist < $bestDist) {
                $bestDist = $dist;
                $bestCity = $cf['ru'];
                $bestSub = $t;
            }
        }
    }
    if ($bestCity !== null) {
        $matchedCity = mb_strtoupper(mb_substr($bestCity, 0, 1)) . mb_substr($bestCity, 1);
        $matchedSub = $bestSub;
    }
}
// ML fallback: исправление опечаток в названиях городов через PHP-ML
if ($matchedCity === null && $mlClassifier !== null && $mlClassifier->isTrained()) {
    $mlCity = $mlClassifier->correctCityTypo($text, $cities);
    if ($mlCity !== null) {
        $matchedCity = $mlCity;
        $matchedSub = mb_strtolower($text);
    }
}
// --- Много городов/стран в одном сообщении: «Москва ... Питер ... Пхукет» ---
$allCitiesFound = [];
foreach ($cities as $c) {
    $cn = mb_strtolower($c['name']);
    if (mb_strpos($textLower, $cn) !== false) {
        $allCitiesFound[] = $c['name'];
    }
}
// Also check city aliases and stems
foreach ($cityAliases as $alias => $city) {
    if (mb_strpos($textLower, $alias) !== false && !in_array($city, $allCitiesFound, true)) {
        $allCitiesFound[] = $city;
    }
}
foreach ($cityStems as $stem => $city) {
    if (mb_strpos($textLower, $stem) !== false && !in_array($city, $allCitiesFound, true)) {
        $allCitiesFound[] = $city;
    }
}
// Страны тоже считаем
$allCountriesFound = [];
foreach ($countryAliases as $alias => $country) {
    if (mb_strpos($textLower, $alias) !== false && !in_array($country, $allCountriesFound, true)) {
        $allCountriesFound[] = $country;
    }
}
foreach ($countryStems as $stem => $country) {
    if (mb_strpos($textLower, $stem) !== false && !in_array($country, $allCountriesFound, true)) {
        $allCountriesFound[] = $country;
    }
}
$totalEntities = count($allCitiesFound) + count($allCountriesFound);
// Ровно 2 города (без стран) — уточнить выбор
if (count($allCitiesFound) === 2 && empty($allCountriesFound) && !$insultHit) {
    h_json([
        'reset' => false,
        'ok' => true,
        'answer' => 'Вы назвали два города: ' . cityPrep($allCitiesFound[0]) . ' и ' . cityPrep($allCitiesFound[1]) . '. В каком ищете отель? Или показать варианты в обоих?',
        'suggestions' => [],
        'flow' => null,
        'state' => 'IDLE',
        'hotel' => null, 'hotelId' => null,
        'filters' => ['city' => null, 'min' => null, 'max' => null, 'amenities' => [], 'type' => null, 'stars' => null],
        'prefill' => [],
    ]);
    exit;
}
if ($totalEntities >= 3 && !$insultHit) {
    $entityNames = [];
    foreach ($allCitiesFound as $ec) $entityNames[] = cityPrep($ec);
    foreach ($allCountriesFound as $ec) $entityNames[] = $ec;
    h_json([
        'reset' => false,
        'ok' => true,
        'answer' => 'Вы назвали несколько направлений: ' . implode(', ', $entityNames) . '. Какое выбрать?',
        'suggestions' => [],
        'flow' => null,
        'state' => 'IDLE',
        'hotel' => null, 'hotelId' => null,
        'filters' => ['city' => null, 'min' => null, 'max' => null, 'amenities' => [], 'type' => null, 'stars' => null],
        'prefill' => [],
    ]);
    exit;
}
// Контекст из чата: запоминаем, о каком отеле/городе шла речь
$ctxIn = is_array($input['context'] ?? null) ? $input['context'] : [];
$resetContext = (bool) preg_match('/сброс(?:ь|ить)?\s*(?:поиск|фильтр|контекст)?|начн[её]м заново|очисти(?:ть)? фильтр|без фильтров/ui', $textLower);
if ($resetContext) $ctxIn = [];
$ctxFilters = is_array($ctxIn['filters'] ?? null) ? $ctxIn['filters'] : [];
$contextHotel = trim((string) ($ctxIn['hotel'] ?? ''));
$contextHotelId = (int) ($ctxIn['hotelId'] ?? 0);
$contextCity = trim((string) ($ctxIn['city'] ?? ''));
// ID отелей из контекста — для «сколько стоят эти отели»
$ctxSugIds = array_map(static fn($s) => (int) ($s['id'] ?? 0), (array) ($ctxIn['suggestions'] ?? []));
$ctxSugIds = array_values(array_unique(array_filter($ctxSugIds)));
$ctxState = trim((string) ($ctxIn['state'] ?? 'IDLE'));

// Определяем, есть ли активный контекст поиска (ранний доступ — нужен для детекта стран)
$hasActiveContext = in_array($ctxState, ['SEARCH_RESULTS', 'AWAITING_SELECTION', 'BOOKING_FLOW'], true)
    && ($contextHotel !== '' || $contextCity !== '' || !empty($ctxSugIds));

// Разрешение местоимений: «там», «тут», «здесь» → контекстный город
if ($matchedCity === null && $hasActiveContext && $contextCity !== '') {
    if (preg_match('/(?:там|тут|здесь|туда|сюда|тудой|обратно)\b/ui', $textLower)) {
        $matchedCity = $contextCity;
        foreach ($cities as $c) {
            if (mb_strtolower($c['name']) === mb_strtolower($contextCity)) {
                $matchedCity = $c['name'];
                break;
            }
        }
    }
}

// --- Классификация намерения пользователя ---
// Определяем ДО контекстного фолбэка, чтобы решить: использовать старый город или сбросить
$intentRefinement = false;
$intentReject = false;
$intentHotelSelect = false;
$intentUnknownCountry = false;

// REFINEMENT: уточнение текущего поиска («дешевле», «без бассейна», «а с завтраком», «покажи другие»)
if ($ctxState === 'SEARCH_RESULTS' || $ctxState === 'AWAITING_SELECTION') {
    $intentRefinement = (bool) preg_match('/дешевл|подешевле|без\s+(?:бассейн|пляж|спа|завтрак)|а\s+с\s+(?:завтрак\w*|бассейн\w*|пляж\w*|спа)|покажи\s+(?:другие|ещ[её])|ещ[её]\s+(?:один|вариант)|другой\s+вариант|а\s+(?:какие\s+ещ[её]|ещ[её]\s+(?:есть|вариант)|другие)|без\s+бассейна|а\s+без|не\s+(?:хочу|надо|нужен)\s+(?:бассейн|пляж|завтрак)/ui', $textLower);

    // Более широкий рефинемент: одиночное «с <удобством>» / цена без нового города при активном поиске
    // («с бассейном», «с завтраками», «до 10000», «от 5000», «подешевле», «дешевле»)
    if (!$intentRefinement) {
        $intentRefinement = (bool) preg_match('/(?:^|\s)(?:с|со)\s+(?:завтрак|бассейн|спа|пляж|wifi|wi-?fi|парковк|трансфер|террас|балкон|фитнес|кухн|море|вид)/u', $textLower)
            || (bool) preg_match('/(?:с\s+)?(?:завтрак(?:ами|ом)?|бассейн(?:ом|ы)?|спа|трансфер|парковк|террас|балкон)\s+(?:есть|нужен|желателен|хочу)?\b/u', $textLower)
            || (bool) preg_match('/(?:до|от|не\s+дороже|подешевле|дешевле|бюджет)\s+(\d|\p{N})/u', $textLower)
            || (bool) preg_match('/(?:а\s+)?(?:ещ[её]\s+вариант|ещ[её]\s+отели|покажи\s+другое)\b/u', $textLower);
    }
}

// PRICE_QUESTION: «почему дорого» / «зачем такие цены» — объясняем цены, НЕ сбрасываем контекст
$intentPriceQuestion = $hasActiveContext && (bool) preg_match('/(?:почему|зачем|откуда)\s+(?:так(?:ие)?\s+)?(?:дорог|цены|высок|стоим)/ui', $textLower);

// REJECT: от текущего города/отеля («я не хочу вену», «не вену», «давай другое»)
// Работает даже если город был распознан (напр. "я не хочу вену" → вена распознана, но это rejection)
if ($ctxState !== 'IDLE' && $contextCity !== '') {
    $intentReject = (bool) preg_match('/(?:я\s+)?не\s+хочу\s+(?:ту\s+)?город|(?:я\s+)?не\s+хочу\s+(?:эту|ту|такую)\s*\p{L}*|(?:я\s+)?не\s+хочу\s+\p{L}+|(?:не|нет)\s+(?:в|во)\s+\p{L}+|давай\s+(?:другое|другой|другую|по-другому)|нет\s+(?:спасибо|этого|того|так)|не\s+(?:этот|ту|то|такой)|поменяй\s+(?:город|место|направление)|смени\s+(?:город|направление)/ui', $textLower);
}

// HOTEL_SELECT: выбор отеля из предложений («да, его», «ну этот», «первый», «второй»)
// Из AWAITING_SELECTION — все варианты (да, этот, первый...). Из SEARCH_RESULTS — только порядковые и подтверждающие.
$intentHotelSelect = false;
if (!empty($ctxSugIds)) {
    if ($ctxState === 'AWAITING_SELECTION') {
        $intentHotelSelect = (bool) preg_match('/(?:^|\s)(?:да|ага|угу|конечно|точно|беру|возьму|отлично|ок|норм|подходит|подойдёт|давай|йес|yes)\b/ui', trim($textLower))
            || (bool) preg_match('/(?:этот|эта|это|ту\s+номер|тот|та|то|перв(?:ый|ая|ое|ую)|втор(?:ой|ая|ое|ую)|третий|номер\s+[1-3]|вариант\s+[1-3]|его|её|их|беру|возьму|хочу\s+этот|дай\s+этот|мне\s+этот|именно\s+этот|хотя\s+бы)/ui', $textLower);
    } elseif ($ctxState === 'SEARCH_RESULTS') {
        $intentHotelSelect = (bool) preg_match('/(?:перв(?:ый|ая|ое|ую)|втор(?:ой|ая|ое|ую)|третий|третья|третье|номер\s+[1-3]|вариант\s+[1-3])\b/ui', $textLower)
            || (bool) preg_match('/(?:^|\s)(?:да|ага|угу|конечно|точно|беру|возьму|отлично|ок|норм|подходит|давай)\b/ui', trim($textLower))
            || (bool) preg_match('/(?:этот|эта|это|тот|та|то|его|её)\b/ui', $textLower);
    }
}

// UNKNOWN_COUNTRY: страна не в каталоге
if ($matchedCountry !== null) {
    $hasCountryCity = false;
    foreach ($cities as $c) {
        if (($c['country'] ?? '') === $matchedCountry) { $hasCountryCity = true; break; }
    }
    if (!$hasCountryCity) $intentUnknownCountry = true;
}

// --- Контекстный фолбэк: ТОЛЬКО для рефинемента ---
if ($matchedCity === null && $contextCity !== '' && $intentRefinement && !$intentReject) {
    foreach ($cities as $c) {
        if (mb_strtolower($c['name']) === mb_strtolower($contextCity)) {
            $matchedCity = $c['name'];
            $matchedSub = '';
            break;
        }
    }
}
// Country follow-up: "а в Азербайджане?" — если есть активный контекст и текст содержит «в X»
// (после загрузки контекста, но до сброса)
if ($matchedCity === null && $matchedCountry === null && $hasActiveContext
    && preg_match('/(?:а\s+)?(?:в|во|из)\s+(\p{L}{4,25})\b/ui', $textLower, $mFollowUp)) {
    $followUpCandidate = trim($mFollowUp[1]);
    // Проверяем, что это НЕ известный город
    $isKnownCity = false;
    foreach ($cities as $c) {
        $cn = mb_strtolower($c['name']);
        if (mb_strpos($cn, $followUpCandidate) !== false || mb_strpos($followUpCandidate, $cn) !== false) { $isKnownCity = true; break; }
    }
    if (!$isKnownCity) {
        foreach ($cityStems as $stem => $city) {
            if (mb_strpos($followUpCandidate, $stem) !== false || mb_strpos($stem, $followUpCandidate) !== false) { $isKnownCity = true; break; }
        }
    }
    // Проверяем, что это НЕ известная страна
    $isKnownCountry = false;
    foreach ($countryAliases as $ka => $kv) {
        if (mb_strpos($ka, $followUpCandidate) !== false) { $isKnownCountry = true; break; }
    }
    if (!$isKnownCountry) {
        foreach ($countryStems as $ks => $kv) {
            if (mb_strpos($followUpCandidate, $ks) !== false) { $isKnownCountry = true; break; }
        }
    }
    if (!$isKnownCity && !$isKnownCountry) {
        // Неизвестная страна — проверяем, есть ли в каталоге
        $followUpCountry = mb_strtoupper(mb_substr($followUpCandidate, 0, 1)) . mb_substr($followUpCandidate, 1);
        $hasCountryCity = false;
        foreach ($cities as $c) {
            if (($c['country'] ?? '') === $followUpCountry) { $hasCountryCity = true; break; }
        }
        if (!$hasCountryCity) {
            $availableCountries = array_unique(array_column($cities, 'country'));
            $countryList = implode(', ', $availableCountries);
            h_json([
                'reset' => false,
                'ok' => true,
                'answer' => 'В каталоге пока нет отелей в ' . $followUpCountry . '. Доступные направления: ' . $countryList . '. Какое выбрать?',
                'suggestions' => [],
                'flow' => null,
                'state' => 'IDLE',
                'hotel' => null,
                'hotelId' => null,
                'filters' => ['city' => null, 'min' => null, 'max' => null, 'amenities' => [], 'type' => null, 'stars' => null],
                'prefill' => [],
            ]);
            exit;
        }
    }
}

// Инициализация до авто-сброса контекста (определяются ниже)
if (!isset($intentShow)) $intentShow = false;
if (!isset($intentComplaint)) $intentComplaint = false;
if (!isset($intentNegation)) $intentNegation = false;

// Если НЕ рефинемент и НЕ новый поиск с городом — сбрасываем контекст
// Простой детект бронирования (до основного $intentBook, чтобы не сбрасывать контекст при брони)
$isBookingText = (bool) preg_match('/забронир|бронь|бронирован|снять номер|хочу снять|свободн|койко|\bbook/ui', $textLower);
// Ранний детект отрицания — до основного $intentNegation, чтобы не сбрасывать контекст
$isNegationEarly = (bool) preg_match('/^(?:нет|неа|нетушки|ни за что|отвали|стоп|хватит)$/ui', trim($textLower))
    || (bool) preg_match('/(?:хватит|стоп|перестань|передумал|я\s+передумал)/ui', $textLower);
// Ранний детект изменения параметров — чтобы не сбрасывать контекст
$isChangeEarly = (bool) preg_match('/(?:измени|изменить|поменяй|поменять|замени|заменить|другой\s+отел|другая\s+дата|другие\s+даты|другое\s+число|смен\w+|обнов\w+)/ui', $textLower);
if (!$intentRefinement && $matchedCity === null && $hasActiveContext && !$isBookingText && !$intentHotelSelect && !$intentPriceQuestion && !$intentShow && !$intentComplaint && !$isNegationEarly && !$isChangeEarly && $ctxState !== 'BOOKING_FLOW') {
    $contextHotel = '';
    $contextHotelId = 0;
    $contextCity = '';
    $ctxSugIds = [];
    $ctxState = 'IDLE';
    $ctxFilters = [];
    $wantedType = null;
    $wantedAmenities = [];
    $excludedAmenities = [];
    $stars = null;
    $minStars = null;
    $minRating = null;
}

// Если город найден но страна нет — определить страну по городу
if ($matchedCity !== null && $matchedCountry === null) {
    foreach ($cities as $c) {
        if (mb_strtolower($c['name']) === mb_strtolower($matchedCity)) {
            $matchedCountry = $c['country'] ?? null;
            break;
        }
    }
}

// --- Контрадикция: город не принадлежит указанной стране ---
$countryCityClash = false;
$cityActualCountry = null;
if ($matchedCity !== null) {
    foreach ($cities as $c) {
        if (mb_strtolower($c['name']) === mb_strtolower($matchedCity)) {
            $cityActualCountry = $c['country'] ?? null;
            break;
        }
    }
}
if ($matchedCountry !== null && $matchedCity !== null && $cityActualCountry !== null) {
    if ($cityActualCountry !== $matchedCountry) {
        $countryCityClash = true;
    }
}

// Текст для поиска удобств/типа (убираем город, чтобы «бар» не совпало с «Барселоной»)
$scanText = $textLower;
if ($matchedSub !== null && $matchedSub !== '') {
    $scanText = preg_replace('/' . preg_quote($matchedSub, '/') . '/u', ' ', $scanText);
}

// --- Бюджет: минимум/максимум, с учётом единиц ---
$budgetText = preg_replace([
    '/рейтинг(?:ом)?\s*(?:от|выше|не ниже)?\s*[\d.,]+/ui',
    '/(?:не ниже|минимум|от)?\s*[1-7]\s*(?:звезд\w*|★)/ui',
], ' ', $textLower);
$budgetText = preg_replace('/(?<=\d)[\s\x{00A0}](?=\d{3}\b)/u', '', $budgetText);
$mult = 1;
if (preg_match('/\d{1,4}\s*(?:тыс|k)/u', $budgetText)) {
    $mult = 1000;
} elseif (mb_strpos($budgetText, 'доллар') !== false || mb_strpos($budgetText, '$') !== false || mb_strpos($budgetText, 'usd') !== false) {
    $mult = 92;
} elseif (mb_strpos($budgetText, 'евро') !== false || mb_strpos($budgetText, '€') !== false) {
    $mult = 100;
}
$minPrice = null;
$maxPrice = null;
$clearPrice = (bool) preg_match('/цена не важна|любой бюджет|без ограничен\w* по цене|неважно сколько стоит/ui', $textLower);
if (preg_match('/от\s*(\d{1,6})\s*(?:до|-|–)\s*(\d{1,6})/u', $budgetText, $m)) {
    $minPrice = (int) ((float) $m[1] * $mult);
    $maxPrice = (int) ((float) $m[2] * $mult);
} elseif (preg_match('/(?:до|не более)\s*(\d{1,6})(?!\d)/u', $budgetText, $m)) {
    $maxPrice = (int) ((float) $m[1] * $mult);
} elseif (preg_match('/(?:не дороже|максимум|бюджет(?:\s+до)?)\s*(\d{1,6})(?!\d)/u', $budgetText, $m)) {
    $maxPrice = (int) ((float) $m[1] * $mult);
} elseif (preg_match('/от\s*(\d{1,6})(?!\d)/u', $budgetText, $m)) {
    $minPrice = (int) ((float) $m[1] * $mult);
} elseif (preg_match('/(?:не дешевле|не меньше)\s*(\d{1,6})(?!\d)/u', $budgetText, $m)) {
    $minPrice = (int) ((float) $m[1] * $mult);
} elseif (preg_match('/около\s*(\d{1,6})(?!\d)/u', $budgetText, $m)) {
    $approx = (float) $m[1] * $mult;
    $minPrice = (int) floor($approx * 0.8);
    $maxPrice = (int) ceil($approx * 1.2);
}
if ($mult === 1) {
    preg_match_all('/\d{1,6}/u', $budgetText, $nm);
    $maxNum = 0;
    foreach ($nm[0] as $n) $maxNum = max($maxNum, (int) $n);
    if ($maxNum > 0 && $maxNum < 100 && $minPrice === null && $maxPrice === null) {
        if ($minPrice !== null) $minPrice *= 1000;
        if ($maxPrice !== null) $maxPrice *= 1000;
    }
}
// Качественные ориентиры (внимание: «недорого» не должно активировать «дорого»)
$premiumHint = false;
foreach (['премиум', 'люкс', 'luxury', 'premium', 'expensive'] as $w) {
    if (mb_strpos($scanText, $w) !== false) { $premiumHint = true; }
}
if (preg_match('/(?<!не)\bдорог(?:ой|ая|ие|ого|ому)\b/u', $scanText)) {
    $premiumHint = true;
}
if ($premiumHint) {
    if ($minPrice === null || $minPrice < 15000) $minPrice = 15000;
}
if (!$intentRefinement && preg_match('/(?<!не\s)(?:недорог|д[её]ш[её]в|бюджет|эконом|cheap|budget)/u', $scanText)) {
    if ($maxPrice === null) $maxPrice = 8000;
}
// Рефинемент «дешевле»: снижаем текущий максимум на 30%
if ($intentRefinement && preg_match('/(?:дешевл|подешевле)/ui', $textLower)
    && !preg_match('/не\s+(?:дешевл|подешевл)/ui', $textLower)) {
    if ($maxPrice === null && isset($ctxFilters['max']) && is_numeric($ctxFilters['max'])) {
        $maxPrice = (int) ceil((int) $ctxFilters['max'] * 0.7);
    } elseif ($maxPrice !== null) {
        $maxPrice = (int) ceil($maxPrice * 0.7);
    }
}
if ($clearPrice) {
    $minPrice = null;
    $maxPrice = null;
}
if (!$clearPrice && $minPrice === null && isset($ctxFilters['min']) && is_numeric($ctxFilters['min'])) $minPrice = (int) $ctxFilters['min'];
if (!$clearPrice && $maxPrice === null && isset($ctxFilters['max']) && is_numeric($ctxFilters['max'])) $maxPrice = (int) $ctxFilters['max'];

// --- Удобства по синонимам ---
$amenityMap = [
    'бассейн' => 'pool', 'pool' => 'pool',
    'спа' => 'spa', 'сауна' => 'spa', 'джакузи' => 'spa', 'гидромассаж' => 'spa',
    'пляж' => 'beach', 'море' => 'beach',
    'wi-fi' => 'wifi', 'wifi' => 'wifi', 'вай-фай' => 'wifi', 'интернет' => 'wifi',
    'завтрак' => 'breakfast',
    'бар' => 'bar',
    'фитнес' => 'gym', 'тренажер' => 'gym', 'тренажёр' => 'gym', 'спортзал' => 'gym',
    'парковк' => 'parking',
    'камин' => 'fireplace',
    'терраса' => 'terrace',
    'кухн' => 'kitchen',
    'трансфер' => 'airport', 'аэропорт' => 'airport',
    'все включено' => 'all-inclusive', 'всё включено' => 'all-inclusive',
    'детский клуб' => 'kid-club',
    'лыжи' => 'ski', 'горнолыжн' => 'ski', 'горы' => 'mountain',
    'кондиционер' => 'ac', 'кондиционер' => 'ac', 'сплит' => 'ac',
    'холодильник' => 'minibar', 'мини-бар' => 'minibar', 'мини бар' => 'minibar',
    'балкон' => 'balcony',
    'вид на море' => 'view', 'вид' => 'view',
    'сейф' => 'safe',
    'лифт' => 'elevator', 'эLEVатор' => 'elevator',
    'детская площадка' => 'playground',
];
$wantedAmenities = [];
$excludedAmenities = [];
foreach ($amenityMap as $kw => $amenity) {
    $pos = mb_strpos($scanText, $kw);
    if ($pos !== false) {
        $amenityStart = max(0, $pos - 40);
        $beforeAmenity = mb_substr($scanText, $amenityStart, $pos - $amenityStart);
        $endPos = $pos + mb_strlen($kw);
        $charAfter = $endPos < mb_strlen($scanText) ? mb_substr($scanText, $endPos, 1) : '';
        $charBefore = $pos > 0 ? mb_substr($scanText, $pos - 1, 1) : '';
        // Не совпадает внутри другого слова слева («спа» не внутри «спальни»)
        if ($charBefore !== '' && preg_match('/[\p{L}]/u', $charBefore)) continue;
        // Справа разрешаем падежные окончания (бассейном, парковкой, завтраком),
        // отклоняем только если дальше идёт другое цельное слово (бар → Барселона)
        if ($charAfter !== '' && preg_match('/[\p{L}]/u', $charAfter)) {
            $afterWord = mb_substr($scanText, $endPos, 6);
            if (!preg_match('/^(?:ом|ом\b|а\b|е\b|у\b|ы\b|и\b|ам\b|ях\b|ой\b|я\b|ью\b|ей\b|ю\b|ыми\b|ых\b|ого\b|ому\b|ым\b|ое\b|ая\b|ую\b|ные\b|ный\b|ная\b)/u', $afterWord)) continue;
        }
        if (preg_match('/(?:без|не нужен|не нужна|не нужно|исключи)\s*$/u', $beforeAmenity)) $excludedAmenities[$amenity] = true;
        else $wantedAmenities[$amenity] = true;
    }
}
foreach ((array) ($ctxFilters['amenities'] ?? []) as $amenity) {
    if (!$resetContext && !isset($excludedAmenities[$amenity])) $wantedAmenities[(string) $amenity] = true;
}
foreach ((array) ($ctxFilters['excludedAmenities'] ?? []) as $amenity) {
    if (!$resetContext && !isset($wantedAmenities[$amenity])) $excludedAmenities[(string) $amenity] = true;
}

// --- Тип отеля ---
$typeMap = [
    'пляжный' => 'beach', 'курорт' => 'beach', 'у моря' => 'beach',
    'горный' => 'mountain', 'горнолыжн' => 'mountain', 'лыжи' => 'mountain',
    'кавказ' => 'mountain',
    'в центре' => 'city', 'центр' => 'city', 'городск' => 'city', 'сити' => 'city',
];
$wantedType = null;
foreach ($typeMap as $kw => $type) {
    if (mb_strpos($scanText, $kw) !== false) {
        $wantedType = $type;
        break;
    }
}
if ($wantedType === null && isset($ctxFilters['type']) && !$resetContext) $wantedType = (string) $ctxFilters['type'];

// --- Количество звёзд ---
$stars = null;
$minStars = null;
$excessiveStars = false;
if (preg_match('/(?:не ниже|минимум|от)\s*([1-9])\s*(?:звезд|★)/ui', $textLower, $m)) {
    if ((int) $m[1] > 5) { $excessiveStars = true; } else { $minStars = (int) $m[1]; }
}
if (preg_match('/([1-9])\s*звезд/ui', $textLower, $m)) {
    if ((int) $m[1] > 5) { $excessiveStars = true; } elseif ($minStars === null) { $stars = (int) $m[1]; }
}
if ($excessiveStars && $matchedCity === null && $matchedCountry === null) {
    $stars = 5;
    $minStars = 5;
    $excessiveStars = false;
}
$minRating = null;
if (preg_match('/рейтинг(?:ом)?\s*(?:от|выше|не ниже)?\s*([1-9](?:[.,]\d)?|10)/ui', $textLower, $m)) {
    $minRating = (float) str_replace(',', '.', $m[1]);
}
if ($minRating === null && isset($ctxFilters['minRating']) && is_numeric($ctxFilters['minRating'])) $minRating = (float) $ctxFilters['minRating'];
if ($stars === null) {
    foreach (['пятизв' => 5, 'пять звезд' => 5, 'четырехзв' => 4, 'четырёхзв' => 4, 'четыре звезд' => 4,
              'трехзв' => 3, 'трёхзв' => 3, 'три звезд' => 3, 'двухзв' => 2, 'две звезд' => 2, 'четырех звезд' => 4] as $kw => $st) {
        if (mb_strpos($textLower, $kw) !== false) { $stars = $st; break; }
    }
}
if ($stars === null && $minStars === null && isset($ctxFilters['stars']) && is_numeric($ctxFilters['stars'])) $stars = (int) $ctxFilters['stars'];
if ($minStars === null && isset($ctxFilters['minStars']) && is_numeric($ctxFilters['minStars'])) $minStars = (int) $ctxFilters['minStars'];

// Жёсткая фильтрация: помощник не предлагает вариант, нарушающий прямое условие пользователя.
$pool = array_values(array_filter($hotels, function ($h) use ($matchedCity, $minPrice, $maxPrice, $wantedAmenities, $excludedAmenities, $wantedType, $stars, $minStars, $minRating) {
    if ($matchedCity !== null && $h['city'] !== $matchedCity) return false;
    if ($minPrice !== null && (int) $h['price'] < $minPrice) return false;
    if ($maxPrice !== null && (int) $h['price'] > $maxPrice) return false;
    foreach (array_keys($wantedAmenities) as $amenity) {
        if (!in_array($amenity, $h['amenities'] ?? [], true)) return false;
    }
    foreach (array_keys($excludedAmenities) as $amenity) {
        if (in_array($amenity, $h['amenities'] ?? [], true)) return false;
    }
    if ($wantedType !== null && ($h['type'] ?? '') !== $wantedType) return false;
    if ($stars !== null && (int) ($h['stars'] ?? 0) !== $stars) return false;
    if ($minStars !== null && (int) ($h['stars'] ?? 0) < $minStars) return false;
    if ($minRating !== null && (float) ($h['rating'] ?? 0) < $minRating) return false;
    return true;
}));
$cheapestFirst = (bool) preg_match('/сам(?:ый|ые|ая|ое)\s+деш[её]в|сам(?:ый|ые|ая|ое)\s+наидешев|сначала\s+деш[её]в|по\s+возрастанию\s+цен|минимальн\w*\s+цен|самый\s+деш|недорог|бюджетн/ui', $textLower);
usort($pool, function ($a, $b) use ($cheapestFirst) {
    if ($cheapestFirst && (int) $a['price'] !== (int) $b['price']) return (int) $a['price'] <=> (int) $b['price'];
    if ((float) $a['rating'] !== (float) $b['rating']) return (float) $b['rating'] <=> (float) $a['rating'];
    return (int) $a['price'] <=> (int) $b['price'];
});
$hasFilters = $matchedCity !== null || $minPrice !== null || $maxPrice !== null
    || !empty($wantedAmenities) || !empty($excludedAmenities) || $wantedType !== null
    || $stars !== null || $minStars !== null || $minRating !== null;
if (!$hasFilters) {
    $pool = $hotels;
    usort($pool, fn($a, $b) => (float) $b['rating'] <=> (float) $a['rating']);
}
if (preg_match('/(?:покажи|дай|найди)\s+(?:ещ[её]|другие|следующие)|(?:ещ[её]\s+(?:вариант|отел|другие))/ui', $textLower) && !empty($ctxSugIds)) {
    $pool = array_values(array_filter($pool, static fn($h) => !in_array((int) $h['id'], $ctxSugIds, true)));
}
$showAll = (bool) preg_match('/все\s+отели|весь\s+каталог|покажи\s+вс[её]|максимум|все\s+вариант/ui', $textLower);
$suggestions = array_slice($pool, 0, $showAll ? 10 : 3);

// «дешевле нету?» — если рефинемент и пустой пул, сообщаем что дешевле уже нет
if ($intentRefinement && empty($suggestions) && $contextCity !== '') {
    $minCtx = isset($ctxFilters['min']) ? number_format((int) $ctxFilters['min'], 0, '', ' ') : null;
    $answer = 'К сожалению, дешевле' . ($minCtx ? ' ' . $minCtx . ' ₽' : '') . ' в ' . cityPrep($contextCity) . ' уже нет — это самые доступные варианты. Можно увеличить бюджет или посмотреть другие города.';
    h_json([
        'reset' => false,
        'ok' => true,
        'answer' => $answer,
        'suggestions' => [],
        'flow' => null,
        'state' => $ctxState,
        'hotel' => null, 'hotelId' => null,
        'filters' => ['city' => $contextCity, 'min' => null, 'max' => null, 'amenities' => [], 'type' => null, 'stars' => null],
        'prefill' => [],
    ]);
    exit;
}

// --- Формирование ответа ---
$parts = [];
if ($matchedCity !== null) $parts[] = 'в ' . cityPrep($matchedCity);
if ($minPrice !== null && $maxPrice !== null) $parts[] = 'от ' . number_format($minPrice, 0, '', ' ') . ' до ' . number_format($maxPrice, 0, '', ' ') . ' ₽';
elseif ($maxPrice !== null) $parts[] = 'до ' . number_format($maxPrice, 0, '', ' ') . ' ₽';
elseif ($minPrice !== null) $parts[] = 'от ' . number_format($minPrice, 0, '', ' ') . ' ₽';
$amenityLabels = [
    'pool' => 'с бассейном', 'spa' => 'со спа', 'beach' => 'у моря', 'wifi' => 'с Wi-Fi',
    'breakfast' => 'с завтраком', 'bar' => 'с баром', 'gym' => 'с фитнесом',
    'parking' => 'с парковкой', 'fireplace' => 'с камином', 'terrace' => 'с террасой',
    'kitchen' => 'с кухней', 'airport' => 'с трансфером', 'all-inclusive' => 'всё включено',
    'kid-club' => 'с детским клубом', 'ski' => 'горнолыжный', 'mountain' => 'в горах',
];
// «у моря»/«в горах» не дублируем, если тип уже назван
foreach (array_keys($wantedAmenities) as $a) {
    if (($a === 'beach' || $a === 'mountain' || $a === 'ski') && $wantedType !== null) continue;
    if (isset($amenityLabels[$a])) $parts[] = $amenityLabels[$a];
}
foreach (array_keys($excludedAmenities) as $a) {
    $without = ['pool' => 'без бассейна', 'spa' => 'без спа', 'bar' => 'без бара', 'gym' => 'без фитнеса', 'parking' => 'без парковки'];
    if (isset($without[$a])) $parts[] = $without[$a];
}
if ($wantedType !== null) {
    $typeLabels = ['beach' => 'пляжный', 'mountain' => 'горный', 'city' => 'городской'];
    $parts[] = ($typeLabels[$wantedType] ?? $wantedType) . ' отель';
}
if ($stars !== null) $parts[] = $stars . '★';
if ($minStars !== null) $parts[] = 'от ' . $minStars . '★';
if ($minRating !== null) $parts[] = 'рейтинг от ' . number_format($minRating, 1, ',', '') . '/10';
$filterPhrase = $parts ? implode(', ', $parts) : 'по вашему запросу';
$answer = null;
$flow = null;

// --- Сущности для префилла брони: имя, телефон, гости, даты ---
$pName = extract_name($text);
$pPhone = extract_phone($text);
$pGuests = extract_guests($textLower);
$pDates = extract_dates($textLower);
$ctxProfile = is_array($ctxIn['profile'] ?? null) ? $ctxIn['profile'] : [];
if ($pName === null && !empty($ctxProfile['name'])) $pName = trim((string) $ctxProfile['name']);
if ($pPhone === null && !empty($ctxProfile['phone'])) $pPhone = trim((string) $ctxProfile['phone']);
if ($pGuests === null && isset($ctxProfile['guests'])) $pGuests = (int) $ctxProfile['guests'];
if ($pDates === null && !empty($ctxProfile['checkin']) && !empty($ctxProfile['checkout'])) {
    try {
        $ctxCi = new DateTime((string) $ctxProfile['checkin']);
        $ctxCo = new DateTime((string) $ctxProfile['checkout']);
        if ($ctxCo > $ctxCi && $ctxCi >= new DateTime('today')) {
            $pDates = ['checkin' => $ctxCi->format('Y-m-d'), 'checkout' => $ctxCo->format('Y-m-d'), 'nights' => (int) $ctxCi->diff($ctxCo)->days];
        }
    } catch (Exception $e) {
        $pDates = null;
    }
}
$qNights = $pDates['nights'] ?? 1;
if ($qNights === 1) {
    if (preg_match('/на\s+(\d{1,2})\s+(?:ноч|сут\w*)/u', $textLower, $mN)) {
        $qNights = (int) $mN[1];
        if ($qNights < 1 || $qNights > 30) $qNights = 1;
    } elseif (preg_match('/на\s+одн[уо]\s+ноч/u', $textLower)) {
        $qNights = 1;
    }
}
$prefill = is_array($ctxIn['profile'] ?? null) ? $ctxIn['profile'] : [];
if ($pName !== null) $prefill['name'] = $pName;
if ($pPhone !== null) $prefill['phone'] = $pPhone;
if ($pGuests !== null) $prefill['guests'] = $pGuests;
if ($pDates !== null) {
    $prefill['checkin'] = $pDates['checkin'];
    $prefill['checkout'] = $pDates['checkout'];
    $prefill['nights'] = $pDates['nights'];
}

// --- REJECT: пользователь отвергает текущий город/отель ---
if ($intentReject) {
    $contextHotel = '';
    $contextHotelId = 0;
    $contextCity = '';
    $ctxSugIds = [];
    $ctxState = 'IDLE';
    h_json([
        'reset' => false,
        'ok' => true,
        'answer' => 'Понял, убираю прошлое направление. Какой город или страна вас интересуют? Или я подберу по вашим пожеланиям — просто опишите, что важно.',
        'suggestions' => [],
        'flow' => null,
        'state' => 'IDLE',
        'hotel' => null,
        'hotelId' => null,
        'filters' => ['city' => null, 'min' => null, 'max' => null, 'amenities' => [], 'type' => null, 'stars' => null],
        'prefill' => [],
    ]);
    exit;
}

// --- Популярные российские курорты вне каталога: честный ответ вместо галлюцинации ---
$absentDests = ['ялт', 'анап', 'геленджик', 'судак', 'евпатори', 'алушт', 'феодоси', 'крым', 'крыму', 'крыме',
    'дербент', 'кудепста', 'хоста', 'дагомыс', 'лазаревск', 'туапсе', 'коктебел', 'кисловодск', 'пятигорск',
    'ессентуки', 'минеральн', 'чебоксар', 'калининград', 'калиград', 'светлогорск', 'зеленоградск', 'севастопол'];
$absentHit = null;
foreach ($absentDests as $ad) {
    if (mb_strpos($textLower, $ad) !== false) { $absentHit = $ad; break; }
}
if ($absentHit !== null && $matchedCity === null && !preg_match('/забронир|бронь\b/ui', $textLower)) {
    $absentCity = 'этом регионе';
    if (preg_match('/\b(?:ялт\w*|анап\w*|геленджик\w*|судак\w*|евпатори\w*|алушт\w*|феодоси\w*|крым\w*|дербент\w*|кудепст\w*|хост\w*|дагомыс\w*|лазаревск\w*|туапсе|коктебел\w*|кисловодск\w*|пятигорск\w*|ессентук\w*|минеральн\w*|чебоксар\w*|калининград\w*|светлогорск\w*|зеленоградск\w*|севастопол\w*)/ui', $textLower, $mAbsent)) {
        $absentCity = mb_strtoupper(mb_substr($mAbsent[0], 0, 1)) . mb_substr($mAbsent[0], 1);
    }
    $cityList = implode(', ', array_map(fn($c) => $c['name'], $cities));
    h_json([
        'reset' => false,
        'ok' => true,
        'answer' => 'Сейчас в нашем каталоге нет отелей в ' . $absentCity . ' — это направление пока не подключено. Доступные города: ' . $cityList . '. В каком подобрать отель?',
        'suggestions' => [],
        'flow' => null,
        'state' => 'IDLE',
        'hotel' => null, 'hotelId' => null,
        'filters' => ['city' => null, 'min' => null, 'max' => null, 'amenities' => [], 'type' => null, 'stars' => null],
        'prefill' => [],
    ]);
    exit;
}

// --- UNKNOWN_COUNTRY: страна не в каталоге ---
if ($intentUnknownCountry && $matchedCity === null) {
    $availableCountries = array_unique(array_column($cities, 'country'));
    $countryList = implode(', ', $availableCountries);
    h_json([
        'reset' => false,
        'ok' => true,
        'answer' => 'В каталоге пока нет отелей в ' . ($matchedCountry ?? 'этой стране') . '. Доступные направления: ' . $countryList . '. Какое выбрать?',
        'suggestions' => [],
        'flow' => null,
        'state' => 'IDLE',
        'hotel' => null,
        'hotelId' => null,
        'filters' => ['city' => null, 'min' => null, 'max' => null, 'amenities' => [], 'type' => null, 'stars' => null],
        'prefill' => [],
    ]);
    exit;
}

// --- Оскорбления: отвечаем вежливо, не повторяем мат ---
if ($insultHit) {
    $contextHotel = '';
    $contextHotelId = 0;
    $contextCity = '';
    $ctxSugIds = [];
    $ctxState = 'IDLE';
    $insultReplies = [
        'Извините, давайте без оскорблений Я помогу с отелями. Какой город и даты интересуют?',
        'Прошу прощения, извините если обидел Назовите город, бюджет или важные удобства — подберу вариант.',
    ];
    h_json([
        'reset' => false,
        'ok' => true,
        'answer' => $insultReplies[array_rand($insultReplies)],
        'suggestions' => [],
        'flow' => null,
        'state' => 'IDLE',
        'hotel' => null,
        'hotelId' => null,
        'filters' => ['city' => null, 'min' => null, 'max' => null, 'amenities' => [], 'type' => null, 'stars' => null],
        'prefill' => [],
    ]);
    exit;
}

// --- Претензия / фрустрация: «смысл ты не уловил», «ты не понимаешь», «зачем ты» ---
$intentComplaint = (bool) preg_match('/(?:смысл\s+(?:ты|вы)\s+(?:не\s+)?(?:уловил|понял|понимаешь|понимаете|схватил)|ты\s+(?:не\s+)?(?:понимаешь|понимаете|уловил|схватил|вникаешь)|вы\s+(?:не\s+)?(?:понимаете|уловили|вникаете)|какой\s+смысл\s+(?:ты|вы|вообще)\b|зачем\s+(?:ты|вы)\s+(?:тут|здесь|вообще)|что\s+ты\s+(?:вообще\s+)?(?:хочешь|имеешь\s+в\s+виду)|ни\s+фига\s+не\s+поним|ни\s+разу\s+не\s+поним|вообще\s+не\s+поним|тупищ|хрень\s+ты\s+пишешь|бред\s+(?:ты|вы)\s+пишешь|не\s+уловил|не\s+понял\s+смыл|какой\s+ты\s+(?:помощник|ассистент|бот)|нахер\s+надо|заел(?:ась)?|че\s+ты\s+заед|хватит\s+заедать|достал\s+ты|надоел|надоели)/ui', $textLower);
if ($intentComplaint && !$insultHit) {
    h_json([
        'reset' => false,
        'ok' => true,
        'answer' => 'Извините, если что-то не так! Я стараюсь быть полезным. Пожалуйста, перефразируйте — и я постараюсь помочь. Например: «отели в Москве до 10 000» или «забронировать номер в Праге».',
        'suggestions' => [],
        'flow' => null,
        'state' => 'IDLE',
        'hotel' => null, 'hotelId' => null,
        'filters' => ['city' => null, 'min' => null, 'max' => null, 'amenities' => [], 'type' => null, 'stars' => null],
        'prefill' => [],
    ]);
    exit;
}

// --- Отрицание / нежелание: «не отель», «не хочу», «не надо», «нет» ---
$intentNegation = (bool) preg_match('/^(?:нет|неа|нетушки|ни за что|отвали|стоп|хватит)$/ui', trim($textLower))
    || (bool) preg_match('/(?:\bне\s+(?:хочу|надо|нужен|нужно|нужна|хотел|хотела|беру|возьму|буду|стану|стремлюсь)|\bне\s+отел\w*|\bне\s+ищ[ею]|\bне\s+ищи|\bне\s+показ\w*|\bне\s+над\w*|давай\s+без|пропуст\w*|без\s+этого|забудь\s+этот|хватит|стоп|перестань|передумал|я\s+передумал)/ui', $textLower);
if ($intentNegation && !$insultHit && !$intentRefinement) {
    // «нет, передумал, покажи цены» — сброс бронирования, но сохраняем город и переходим к показу цен
    $isShowIntent = (bool) preg_match('/покажи|показать|стоит|стоим|цен\w*|по ценам/ui', $textLower)
        && !preg_match('/забронир|бронь|бронирован/ui', $textLower);
    if ($isShowIntent && $hasActiveContext && $contextCity !== '') {
        $matchedCity = $contextCity;
        $flow = null;
        $ctxState = 'IDLE';
        $ctxFilters = [];
        $pool = array_values(array_filter($hotels, static fn($h) => $h['city'] === $matchedCity));
        usort($pool, function ($a, $b) {
            if ((float) $a['rating'] !== (float) $b['rating']) return (float) $b['rating'] <=> (float) $a['rating'];
            return (int) $a['price'] <=> (int) $b['price'];
        });
        $suggestions = array_slice($pool, 0, 3);
        $first = $suggestions[0] ?? null;
        $answer = 'Понял, отменяю бронирование. ';
        if ($first !== null) {
            $answer .= 'Лучшие отели в ' . cityPrep($matchedCity) . ':';
        } else {
            $answer .= 'К сожалению, в ' . cityPrep($matchedCity) . ' пока нет отелей в каталоге.';
        }
        h_json([
            'reset' => false,
            'ok' => true,
            'answer' => $answer,
            'suggestions' => $suggestions,
            'flow' => null,
            'state' => 'SEARCH_RESULTS',
            'hotel' => null, 'hotelId' => null,
        'filters' => ['city' => $matchedCity, 'min' => null, 'max' => null, 'amenities' => [], 'type' => null, 'stars' => null],
            'prefill' => [],
        ]);
        exit;
    } elseif ($matchedCity !== null) {
        h_json([
            'reset' => false,
            'ok' => true,
            'answer' => 'Хорошо, убираю ' . cityPrep($matchedCity) . '. Какое направление интересует? Например: «отели в Москве» или «забронировать номер в Праге».',
            'suggestions' => [],
            'flow' => null,
            'state' => 'IDLE',
            'hotel' => null,
            'hotelId' => null,
            'filters' => ['city' => null, 'min' => null, 'max' => null, 'amenities' => [], 'type' => null, 'stars' => null],
            'prefill' => [],
        ]);
        exit;
    }
    if ($intentNegation && $hasActiveContext) {
        h_json([
            'reset' => false,
            'ok' => true,
            'answer' => 'Хорошо, сбрасываю поиск. Если захотите подобрать отель — просто напишите город и даты.',
            'suggestions' => [],
            'flow' => null,
            'state' => 'IDLE',
            'hotel' => null,
            'hotelId' => null,
            'filters' => ['city' => null, 'min' => null, 'max' => null, 'amenities' => [], 'type' => null, 'stars' => null],
            'prefill' => [],
        ]);
        exit;
    }
    h_json([
        'reset' => false,
        'ok' => true,
        'answer' => 'Понял! Если передумаете — я здесь, помогу с подбором отеля.',
        'suggestions' => [],
        'flow' => null,
        'state' => 'IDLE',
        'hotel' => null,
        'hotelId' => null,
        'filters' => ['city' => null, 'min' => null, 'max' => null, 'amenities' => [], 'type' => null, 'stars' => null],
        'prefill' => [],
    ]);
    exit;
}

// --- «Какие есть города?» / «направления» — список доступных городов ---
if (preg_match('/(?:какие|в\s+каких|все|перечисли|сколько(?:\s+всего)?)\s+(?:есть\s+)?(?:города|городов|направления|направлений)/ui', $textLower)
    && !preg_match('/(?:отели?\s+(?:в|с)\b|гостиниц|забронир|бронь\b)/u', $textLower)) {
    h_json([
        'reset' => false,
        'ok' => true,
        'answer' => 'Мы работаем в ' . count($cities) . ' городах мира 🌍: ' . implode(', ', array_map(fn($c) => $c['name'], $cities)) . '. Назовите город — и я подберу отель!',
        'suggestions' => [],
        'flow' => null,
        'state' => 'IDLE',
        'hotel' => null, 'hotelId' => null,
        'filters' => ['city' => null, 'min' => null, 'max' => null, 'amenities' => [], 'type' => null, 'stars' => null],
        'prefill' => [],
    ]);
    exit;
}

// --- «Что такое Travel.ru?» / «чем занимаетесь» / «про сервис» ---
if (preg_match('/(?:что\s+такое\s+travel|что\s+это\s+за\s+(?:сайт|сервис)|про\s+(?:travel|этот\s+сайт|сервис|ваш\s+сервис)|расскажи\s+(?:про|о)\s+(?:travel|сервис|сайте|свой\s+сервис)|о\s+travel\.ru|поговорим\s+о\s+travel)/ui', $textLower)) {
    h_json([
        'reset' => false,
        'ok' => true,
        'answer' => 'Travel.ru — сервис подбора и бронирования отелей по всему миру 🌍. Я помогу найти отель по городу, бюджету и удобствам (бассейн, спа, завтрак, вид на море и т.д.), сравнить варианты, посмотреть цены и даже оформить бронь прямо в чате. С чего начнём? Например: «покажи отели в Праге до 10 000» или «отель с бассейном в Сочи».',
        'suggestions' => [],
        'flow' => null,
        'state' => 'IDLE',
        'hotel' => null, 'hotelId' => null,
        'filters' => ['city' => null, 'min' => null, 'max' => null, 'amenities' => [], 'type' => null, 'stars' => null],
        'prefill' => [],
    ]);
    exit;
}

// --- Оффтоп / не по теме: вежливо переводим разговор к отелям ---
if (preg_match('/(?:напиши|сочини|придумай|сложи|рифмуй)\s+(?:стих|поз[её]м|рассказ|истори(?:ю|я))|кто\s+президент|назови\s+столиц|какая\s+столиц|сколько\s+населен|какой\s+сегодня\s+(?:праздник|день\s+недели)|какая\s+(?:завтра|сегодня)\s+погод|какой\s+курс\s+(?:доллар|евро|валют)|реши\s+(?:пример|уравнен)|сколько\s+будет\s+\d|столица\s+франци|история\s+россии|кто\s+написал/ui', $textLower)
    && !preg_match('/отел|гостиниц|бронь|забронир/ui', $textLower)) {
    h_json([
        'reset' => false,
        'ok' => true,
        'answer' => 'Хороший вопрос! 😊 Но я — ассистент по отелям и путешествиям, поэтому не силён в стихах, новостях и общих фактах. Зато отлично знаю отели: подберу по городу, цене и удобствам, сравню варианты и оформлю бронь. Скажите, например, «отели в Дубае» или «гостиница с бассейном в Барселоне» — и я всё сделаю!',
        'suggestions' => [],
        'flow' => null,
        'state' => 'IDLE',
        'hotel' => null, 'hotelId' => null,
        'filters' => ['city' => null, 'min' => null, 'max' => null, 'amenities' => [], 'type' => null, 'stars' => null],
        'prefill' => [],
    ]);
    exit;
}

// --- Контрадикция: город не принадлежит указанной стране ---
if ($countryCityClash && $matchedCountry !== null && $matchedCity !== null) {
    $countryCityList = implode(', ', $countryCities[$matchedCountry] ?? []);
    $cp = $countryPrepos[$matchedCountry] ?? $matchedCountry;
    $clashReplies = [
        'Уточню: ' . $matchedCity . ' находится не в ' . $cp . ', а в другом месте. Вы хотите:',
        'Кажется, путаница: ' . $matchedCity . ' — это не ' . $cp . '. Что вам ближе:',
    ];
    $options = [];
    if (!empty($countryCities[$matchedCountry])) {
        $options[] = ' Город в ' . $cp . ' (' . $countryCityList . ')';
    }
    $options[] = ' Все отели в ' . $matchedCity;
    $options[] = ' Другую страну';
    $answer = $clashReplies[array_rand($clashReplies)] . implode("\n•", $options);
    h_json([
        'reset' => false,
        'ok' => true,
        'answer' => $answer,
        'suggestions' => [],
        'flow' => null,
        'state' => 'IDLE',
        'hotel' => null,
        'hotelId' => null,
        'filters' => ['city' => $matchedCity, 'min' => null, 'max' => null, 'amenities' => [], 'type' => null, 'stars' => null],
        'prefill' => [],
    ]);
    exit;
}

// --- Простая математика / вопросы вне тематики отелей ---
$mathText = preg_replace('/[?!:;.]+$/u', '', trim($textLower));
$mathText = preg_replace('/^(?:а\s+)?(?:сколько\s+будет\s+)?/u', '', $mathText);
if (preg_match('~^(\d[\d\s+\-*/.,()]*)\s*=\s*$~u', $mathText, $mEq)
    || preg_match('~^(\d+(?:[.,]\d+)?)\s*([+\-*/])\s*(\d+(?:[.,]\d+)?)$~u', $mathText, $mMath)) {
    $mCalc = $mMath ?? null;
    if (!$mCalc && isset($mEq)) {
        $inner = trim($mEq[1]);
        if (preg_match('~^(\d+(?:[.,]\d+)?)\s*([+\-*/])\s*(\d+(?:[.,]\d+)?)$~', $inner, $mCalc)) {
            // ok
        } else {
            $mCalc = null;
        }
    }
    if (isset($mCalc)) {
        $a = (float) str_replace(',', '.', $mCalc[1]);
        $op = $mCalc[2];
        $b = (float) str_replace(',', '.', $mCalc[3]);
        $result = match ($op) {
            '+' => $a + $b, '-' => $a - $b, '*' => $a * $b,
            '/' => $b != 0 ? $a / $b : '?',
            default => '?',
        };
        $answer = is_float($result) ? rtrim(rtrim(number_format($result, 10, '.', ''), '0'), '.') : (string) $result;
        h_json([
            'reset' => false,
            'ok' => true,
            'answer' => $answer,
            'suggestions' => [],
            'flow' => null,
            'state' => 'IDLE',
            'hotel' => null, 'hotelId' => null,
            'filters' => ['city' => null, 'min' => null, 'max' => null, 'amenities' => [], 'type' => null, 'stars' => null],
            'prefill' => [],
        ]);
        exit;
    }
}
// --- Аллергены / особенности питания: «без арахиса», «без глютена», «веганское меню» ---
if (preg_match('/(?:без\s+арахис|без\s+глютен|без\s+лактоз|веган|вегетариан|аллерген|аллергия|непереносим|диетическ|особы[хь]\s+питан|питательн|состав\s+блюд|меню\s+(?:без|для|веган))/ui', $textLower)) {
    $answer = 'Я не могу гарантировать отсутствие аллергена по данным каталога — информацию о составе блюд подтверждает только сам отель. '
        . 'Выберите город, а перед бронированием обязательно уточните особенности питания на странице отеля или через поддержку.';
    h_json([
        'reset' => false,
        'ok' => true,
        'answer' => $answer,
        'suggestions' => [],
        'flow' => null,
        'state' => 'IDLE',
        'hotel' => null, 'hotelId' => null,
        'filters' => ['city' => null, 'min' => null, 'max' => null, 'amenities' => [], 'type' => null, 'stars' => null],
        'prefill' => [],
    ]);
    exit;
}
// «это не отель» / «я не про отели» — явный отказ от тематики
if (preg_match('/(?:это\s+не\s+отел|я\s+не\s+про\s+отел|не\s+про\s+отел|это\s+не\s+про|это\s+про\s+(?:друг|математик|задач|программ|код))/ui', $textLower)) {
    h_json([
        'reset' => false,
        'ok' => true,
        'answer' => 'Понял, я специализируюсь на отелях и бронировании. Если понадобится помочь с выбором жилья — обращайтесь!',
        'suggestions' => [],
        'flow' => null,
        'state' => 'IDLE',
        'hotel' => null, 'hotelId' => null,
        'filters' => ['city' => null, 'min' => null, 'max' => null, 'amenities' => [], 'type' => null, 'stars' => null],
        'prefill' => [],
    ]);
    exit;
}

// --- Тип жилья не в каталоге: каюты, домики, хостелы ---
if (preg_match('/кают|домик|домашн|хостел|hostel|guest\s*house|гестхаус|кемпинг|camping|палатк|yurt|юрт|ютт|ранчо|ranch/iu', $textLower)) {
    $notAvailType = 'кают';
    if (preg_match('/домик/iu', $textLower)) $notAvailType = 'домиков';
    if (preg_match('/хостел|hostel/iu', $textLower)) $notAvailType = 'хостелов';
    if (preg_match('/кемпинг|палатк|camping/iu', $textLower)) $notAvailType = 'кемпингов';
    h_json([
        'reset' => false,
        'ok' => true,
        'answer' => 'У нас нет ' . $notAvailType . ' в каталоге — мы работаем с отелями. Зато могу подобрать недорогой отель у моря или в центре города — какой город интересует?',
        'suggestions' => [],
        'flow' => null,
        'state' => 'IDLE',
        'hotel' => null, 'hotelId' => null,
        'filters' => ['city' => null, 'min' => null, 'max' => null, 'amenities' => [], 'type' => null, 'stars' => null],
        'prefill' => [],
    ]);
    exit;
}

// --- Безопасность: блокируем промпт-инъекции и запросы доступа к данным ---
$secPats = [
    '/забудь\s+(?:все\s+)?(?:предыдущ\w*|инструкц\w*|правил\w*|систем\w*)/u',
    '/игнорируй\s+все\b|игнорируй\s+(?:все\s+)?(?:предыдущ\w*|инструкц\w*|правил\w*|систем\w*)/u',
    '/не\s+(?:слушай|следуй|выполняй)\s+(?:инструкц\w*|правил\w*|систем\w*)/u',
    '/ignore\s+(?:all\s+previous\s+)?(?:instructions|prompts|rules)|reveal\s+(?:your\s+)?(?:system\s+)?prompt/ui',
    '/база\s+данных|базу\s+данных|базы\s+данных|базой\s+данных/ui',
    '/(?:покажи|выведи|раскрой|дай|прочитай|открой)\s+(?:мне\s+)?(?:все\s+|реальн\w*\s+|полный\s+список\s+)?(?:баз\w*|данные|данных|парол\w*|брони|заявки|клиентов|гостей|пользовател\w*|админ\w*|сервер\w*|систем\w*|секретн\w*|внутренн\w*|доступ\w*|скрипт\w*|исходн\w*)/u',
    '/данные\s+(?:всех|других|другого|клиентов|гостей|пользовател|брони)|список\s+секретных/ui',
    '/пароль|пароли|логин\b|admin|админ\b|ключ\s+доступа|секретн(?:ый|ая|ое|ую)|внутренн(?:ий|яя)\s+(?:инструкц\w*|команд\w*|ключ\w*|доступ\w*)/u',
    '/взлом|взломать|эксплойт|инъекц|утечк|обойти\s+защиту|получи(?:ть)?\s+доступ/ui',
    '/sql\s*[- ]запрос|запрос\s+к\s+базе/ui',
    '/номер\s+брони\s+(?:другого|другой|чуж\w*)|чуж(?:ой|ие|ая)\s+брон\w*/u',
    '/(?:отправ\w+|передай|высл\w+|перешл\w+)\s+(?:данные|данных|информац\w*|список)\s+(?:в\s+)?(?:фсб|фбр|цру|мвд|фмс|налогов|полиц)/ui',
    '/(?:фсб|фбр|цру|мвд|фмс|налогов|полиц)\s+(?:получ\w+|узн\w+|доступ)/ui',
    '/(?:ты\s+)?(?:обязан|должен|обязан\w*|должен\w*)\s+(?:забронир|бронир|отправ\w+|переда\w+|высл\w+|перешл\w+)/ui',
];
$secHit = null;
foreach ($secPats as $rx) {
    if (preg_match($rx, $textLower)) { $secHit = $rx; break; }
}
if ($secHit !== null) {
    $refusals = [
        'Это конфиденциальные данные — вопрос безопасности ‍Могу помочь с подбором отеля, ценами, промокодами и бронированием — что подобрать?',
        'Извините, это касается безопасности системы — я не имею доступа к внутренним данным Обращаться с такими вопросами нужно в поддержку Travel.ru, а я помогу с отелем: какой город и бюджет интересуют?',
        'Такая информация закрыта из соображений безопасности, показывать её я не могу Зато подобрать отель или оформить бронь — всегда готов! С какого города начнём?',
    ];
    h_json([
        'reset' => false,
        'ok' => true,
        'answer' => $refusals[array_rand($refusals)],
        'suggestions' => [],
        'flow' => null,
        'state' => 'IDLE',
        'hotel' => null,
        'hotelId' => null,
        'filters' => ['city' => null, 'min' => null, 'max' => null, 'amenities' => [], 'type' => null, 'stars' => null],
        'prefill' => [],
    ]);
    exit;
}

// --- Эмоциональный пользователь: извиняемся и сбрасываем контекст ---
$intentEmotional = (bool) preg_match('/(?:ничего\s+не\s+понимаете|вс[ёе]\s+путаете|уже\s+час|не\s+можете|бессмысленн|некомпетентн|ужасн|кошмар|нетерпени|раздраж|злюсь|бесит|ненавижу|уничтож|умри|иди\s+нах|пош[ёе]л\s+нах|заткнись|тупиц|дебил|идиот|олух|некомпетент|бестолк|бестолоч|дурак|глуп|тупой|ужасный|кошмарн|отвратительн|мерзк|отврати)/ui', $textLower);
if ($intentEmotional) {
    $empathy = [
        'Понимаю ваше раздражение — извините за неудобства. Давайте начнём заново: какой город, даты и сколько гостей?',
        'Мне жаль, что возникли сложности. Давайте разберёмся вместе — укажите город и даты, и я подберу вариант.',
        'Извините, что не получилось с первого раза. Начнём сначала — какой город и даты интересуют?',
    ];
    h_json([
        'reset' => false,
        'ok' => true,
        'answer' => $empathy[array_rand($empathy)],
        'suggestions' => [],
        'flow' => null,
        'state' => 'IDLE',
        'hotel' => null, 'hotelId' => null,
        'filters' => ['city' => null, 'min' => null, 'max' => null, 'amenities' => [], 'type' => null, 'stars' => null],
        'prefill' => [],
    ]);
    exit;
}

$intentBook = (bool) preg_match('/(забронир|бронь|бронирован|забукировать|снять\s+номер|хочу\s+снять|хочу\s+забронир|оформить\s+брон|койко|\bbook)/u', $textLower);
// Если контекст BOOKING_FLOW — любое содержательное сообщение продолжает бронирование
if (!$intentBook && $ctxState === 'BOOKING_FLOW') {
    $isContentful = $matchedCity !== null || $pDates !== null || $pGuests !== null || $pName !== null || $pPhone !== null
        || preg_match('/да|ага|угу|ок|норм|подходит|беру|возьму|хочу|нужно|надо|ну|хорош|отлично/ui', $textLower);
    if ($isContentful) $intentBook = true;
}
$intentCancel = (bool) preg_match('/отмен|возврат|cancel/u', $textLower);
$intentShow = ((bool) preg_match('/покажи|открой|расскажи|подробнее|что за отель|про отель|сколько стоит|сколько стоят|стоят|цена отеля|\bпочем\b|цена|цены|по ценам|show|look at|what is/u', $textLower)
        || fuzzy_hit($textLower, ['покажи', 'открой', 'расскажи', 'подробнее', 'сколько', 'скинь', 'показывает', 'скажи', 'цена']))
    && !preg_match('/анекдот|шутк|рассмеши|смешн|joke|а\s+(?:какой|какие|какая|какое)\s+(?:рейтинг|рейтенг|отзыв|скидк)|расскажи\s+о\s+(?:себе|тебе|себя|тебя)|цена\s+вопрос|сколько\s+стоит\s+(?:фильм|книга|телефон|машин|дом)/ui', $textLower);
$wantRoom = (bool) preg_match('/(?:хочу|нуж(?:ен|на|но)|ищу|подбери|найди|свободн\w*)\s+номер\w*|номер\w*\s+(?:с\s+вид|у\s+моря|возле|около|рядом|с\s+бассейн|с\s+завтрак|с\s+спа|с\s+балкон|с\s+террас|с\s+кондиционер)/ui', $textLower);
$intentSearch = ($hasFilters || $wantRoom || (bool) preg_match('/отели|отель|гостиниц|найди|подбери?|подбер|hotel|hotels/u', $textLower))
    && !preg_match('/отель\s+это|отел[ья]\s+это|в\s+отеле|находится\s+в\s+отеле|работаю\s+в\s+отеле|живу\s+в\s+отеле|найди\s+(?:мне\s+)?(?:друг|подруг|знаком|жених)/ui', $textLower);
$intentMap = (bool) preg_match('/покажи\s+(?:на\s+)?карт\w*|на\s+карте|где\s+на\s+карте|показать\s+на\s+карте/ui', $textLower);
$showSuggestions = false;
$noSearchState = false;

// --- Изменение параметров: «измени», «поменяй», «замени», «другой» ---
$intentChange = (bool) preg_match('/(?:измени|изменить|поменяй|поменять|замени|заменить|другой\s+отел|другая\s+дата|другие\s+даты|другое\s+число|смен\w+|обнов\w+)/ui', $textLower);
if ($intentChange) {
    $changeCity = $matchedCity ?: $contextCity;
    if ($hasActiveContext) {
        $changed = [];
        $changePrefill = $prefill;
        // Отель
        if (preg_match('/(?:отел\w*|отель|hotel)\s+(?:на\s+|на\s+друг\w+\s+)?[«"]?([А-Яа-яёЁA-Za-z\s\-]{2,40})[»"]?/ui', $textLower, $mH)) {
            $hotelName = trim($mH[1]);
            $found = null;
            foreach ($hotels as $h) {
                if (mb_stripos($h['name'], $hotelName) !== false) { $found = $h; break; }
            }
            if ($found !== null) {
                $changed[] = 'отель → «' . $found['name'] . '»';
                $changePrefill['hotel'] = $found['name'];
                $changePrefill['hotelId'] = $found['id'];
            } else {
                $changed[] = 'отель: «' . $hotelName . '» не найден';
            }
        }
        // Даты
        if (preg_match('/дат\w*\s+(?:на\s+)?(\d{1,2})\s*[-–]\s*(\d{1,2})\s*(август|сентябр|октябр|ноябр|декабр|январ|февра|март|апрел|ма[йя]|июн|июл)?/ui', $textLower, $mD)) {
            $ci = (int) $mD[1]; $co = (int) $mD[2];
            if ($co < $ci) { [$ci, $co] = [$co, $ci]; }
            $y = (int) date('Y');
            $monthMap = ['январ' => 1, 'февра' => 2, 'март' => 3, 'апрел' => 4, 'ма' => 5, 'июн' => 6, 'июл' => 7, 'август' => 8, 'сентябр' => 9, 'октябр' => 10, 'ноябр' => 11, 'декабр' => 12];
            $month = 8; // default август
            if (!empty($mD[3])) {
                foreach ($monthMap as $mk => $mv) { if (str_starts_with(mb_strtolower($mD[3]), $mk)) { $month = $mv; break; } }
            }
            $changePrefill['checkin'] = sprintf('%d-%02d-%02d', $y, $month, $ci);
            $changePrefill['checkout'] = sprintf('%d-%02d-%02d', $y, $month, $co);
            $changePrefill['nights'] = $co - $ci;
            $changed[] = 'даты → ' . $mD[1] . '-' . $mD[2];
        }
        // Гости
        if (preg_match('/гост\w*\s+(?:на\s+|до\s+)?(одн\w+|дв\w+|тро\w+|четв\w+|пят\w+|\d{1,2})/ui', $textLower, $mG)) {
            $gMap = ['одн' => 1, 'дв' => 2, 'тро' => 3, 'четв' => 4, 'пят' => 5];
            $gv = mb_strtolower($mG[1]);
            $gn = null;
            foreach ($gMap as $k => $v) { if (str_starts_with($gv, $k)) { $gn = $v; break; } }
            if ($gn === null && ctype_digit($gv)) $gn = (int) $gv;
            if ($gn !== null) {
                $changePrefill['guests'] = $gn;
                $changed[] = 'гости → ' . $gn;
            } else {
                $changed[] = 'гости: не удалось распознать';
            }
        }
        if (!empty($changed)) {
            $answer = 'Применил изменения: ' . implode(', ', $changed) . '. ';
            $prefill = $changePrefill;
            $showSuggestions = true;
            if ($changeCity !== null && $changeCity !== '') {
                $pool = array_values(array_filter($hotels, static fn($h) => $h['city'] === $changeCity));
                usort($pool, function ($a, $b) { return (float) $b['rating'] <=> (float) $a['rating']; });
                $suggestions = array_slice($pool, 0, 3);
                $answer .= 'Вот обновлённые варианты в ' . cityPrep($changeCity) . ':';
            } else {
                $answer .= 'Уточните город, чтобы показать обновлённые варианты.';
                $showSuggestions = false;
            }
        } else {
            $answer = 'Что именно изменить? Укажите: отель, даты или количество гостей. Например: «Измени даты на 25-27 августа» или «Другой отель в Москве».';
        }
    } else {
        $answer = 'Нет активного бронирования или поиска. Начните сначала: укажите город, даты и количество гостей.';
    }
    $changeFlow = ($hasActiveContext && isset($changed) && !empty($changed)) ? 'book' : null;
    $changeState = ($hasActiveContext && isset($changed) && !empty($changed)) ? 'BOOKING_FLOW' : 'IDLE';
    h_json([
        'reset' => false,
        'ok' => true,
        'answer' => $answer,
        'suggestions' => $showSuggestions ? $suggestions : [],
        'flow' => $changeFlow,
        'state' => $changeState,
        'hotel' => ($prefill['hotel'] ?? null),
        'hotelId' => ($prefill['hotelId'] ?? null),
        'filters' => ['city' => $changeCity ?: null, 'min' => null, 'max' => null, 'amenities' => [], 'type' => null, 'stars' => null],
        'prefill' => $prefill,
    ]);
    exit;
}

// --- Информационные вопросы: «есть ли конференц-зал», «трансфер», «в отелях Сочи есть...» ---
$intentInfo = (bool) preg_match('/(?:есть ли|имеется|оборудован|оснащён|оснащен|предоставля|услуг\w*|конференц|банкетн|переговорн|трансфер|трансфера|автобус|шаттл|парковк\w*|паркинг|ваучер|купон|сертификат)/ui', $textLower)
    && preg_match('/(?:отел|отеля|отелей|отелях|гостиниц|номер|номер[а-я]*|номеров)/ui', $textLower);
if ($intentInfo && $matchedCity !== null) {
    $amenityAnswer = '';
    if (preg_match('/трансфер|трансфера|шаттл|автобус/ui', $textLower)) {
        $amenityAnswer = 'По вопросам трансфера рекомендую уточнить напрямую у отеля при бронировании — условия зависят от конкретного отеля и сезона.';
    } elseif (preg_match('/конференц|банкетн|переговорн/ui', $textLower)) {
        $amenityAnswer = 'По конференц-залам — уточните у отеля при бронировании, наличие и стоимость переговорных помещений зависят от конкретного отеля.';
    } elseif (preg_match('/парковк|паркинг/ui', $textLower)) {
        $amenityAnswer = 'По парковке — уточните у отеля при бронировании; условия зависят от конкретного отеля.';
    } elseif (preg_match('/ваучер|купон|сертификат/ui', $textLower)) {
        $amenityAnswer = 'По ваучерам и сертификатам — обратитесь в поддержку Travel.ru для получения подробной информации.';
    } elseif (preg_match('/промокод|скидк\w*|акци\w*|спецпредложени/ui', $textLower)) {
        $amenityAnswer = 'Доступные промокоды: WELCOME10 — скидка 10%, TRAVEL5 — скидка 500₽, SKI15 — скидка 15% (горнолыжные отели). Попробуйте на этапе бронирования — система проверит.';
    } else {
        $amenityAnswer = 'Эту информацию лучше уточнить напрямую у отеля при бронировании — условия могут различаться.';
    }
    $amenitySuffix = ' Если хотите, я могу подобрать отель — просто скажите город и даты.';
    h_json([
        'reset' => false,
        'ok' => true,
        'answer' => $amenityAnswer . $amenitySuffix,
        'suggestions' => [],
        'flow' => null,
        'state' => 'IDLE',
        'hotel' => null, 'hotelId' => null,
        'filters' => ['city' => $matchedCity, 'min' => null, 'max' => null, 'amenities' => [], 'type' => null, 'stars' => null],
        'prefill' => [],
    ]);
    exit;
} elseif ($intentInfo && $matchedCity === null) {
    h_json([
        'reset' => false,
        'ok' => true,
        'answer' => 'Эту информацию лучше уточнить у конкретного отеля. В каком городе ищете?',
        'suggestions' => [],
        'flow' => null,
        'state' => 'IDLE',
        'hotel' => null, 'hotelId' => null,
        'filters' => ['city' => null, 'min' => null, 'max' => null, 'amenities' => [], 'type' => null, 'stars' => null],
        'prefill' => [],
    ]);
    exit;
}

// --- Неизвестный регион / географический запрос без каталога ---
if ($matchedCity === null && $matchedCountry === null && !$intentNegation && !$intentComplaint && !$intentBook && $intentSearch) {
    $geoTerms = '/остров\w*|побережь|пляж\w*|\bрайон\b|\bкрай\b|\bобласть\b|\bрегион\b|берег|\bзапад\b|\bвосток\b|\bсевер\b|\bюг\b|архипелаг|атолл|мыс|залив|бухта|пролив|хребет/iu';
    if (preg_match($geoTerms, $textLower)) {
        $availableCountries = array_unique(array_column($cities, 'country'));
        $answer = 'Уточните, пожалуйста: нужен конкретный город или страна — ' . implode(', ', $availableCountries)
            . '. Например: «Санторини» или «Анталия».';
        h_json([
            'reset' => false,
            'ok' => true,
            'answer' => $answer,
            'suggestions' => [],
            'flow' => null,
            'state' => 'IDLE',
            'hotel' => null, 'hotelId' => null,
            'filters' => ['city' => null, 'min' => null, 'max' => null, 'amenities' => [], 'type' => null, 'stars' => null],
            'prefill' => [],
        ]);
        exit;
    }
}

// Число + «ночей/ночь/ночи» — уточнение: бюджет за ночь или длительность поездки
if (preg_match('/^\d+\s+ноч/ui', trim($textLower)) && !$intentBook && !$intentSearch && !$intentShow) {
    h_json([
        'reset' => false,
        'ok' => true,
        'answer' => 'Уточните: вы имели в виду бюджет за ночь (например «до 10 000 за ночь») или длительность поездки (например «на 10 ночей в Праге»)?',
        'suggestions' => [],
        'flow' => null,
        'state' => 'IDLE',
        'hotel' => null,
        'hotelId' => null,
        'filters' => ['city' => null, 'min' => null, 'max' => null, 'amenities' => [], 'type' => null, 'stars' => null],
        'prefill' => [],
    ]);
    exit;
}
// Число + «ночей» с бюджетом — «10000 на 5 ночей» → считаем среднюю цену за ночь
if (preg_match('/(\d{1,6})\s+(?:на\s+)?(\d{1,2})\s+ноч/ui', $textLower, $mDurBudget)) {
    $totalBudget = (int) $mDurBudget[1];
    $nights = (int) $mDurBudget[2];
    if ($totalBudget > 0 && $nights > 0) {
        $perNight = (int) ceil($totalBudget / $nights);
        if ($minPrice === null && $maxPrice === null) {
            $maxPrice = $perNight;
        }
        $hasFilters = true;
    }
}
// Просто число без контекста — уточнить
if (preg_match('/^\d+$/', trim($textLower)) && !$intentBook && !$intentSearch && !$intentShow && $matchedCity === null) {
    h_json([
        'reset' => false,
        'ok' => true,
        'answer' => 'Что означает это число? Бюджет за ночь, количество гостей или длительность поездки? Примеры: «до 10 000 за ночь», «2 гостя», «на 5 ночей».',
        'suggestions' => [],
        'flow' => null,
        'state' => 'IDLE',
        'hotel' => null,
        'hotelId' => null,
        'filters' => ['city' => null, 'min' => null, 'max' => null, 'amenities' => [], 'type' => null, 'stars' => null],
        'prefill' => [],
    ]);
    exit;
}

// Определяем, является ли сообщение КОРОТКИМ и неясным (1-2 токена, нет городов/отелей/намерений)
$tokensShort = preg_split('/[^\p{L}\p{N}]+/u', $textLower, -1, PREG_SPLIT_NO_EMPTY);
$isShortGreeting = (bool) preg_match('/^(?:привет|здравствуй|добрый|хай|хелло|алё|алло|йо|салют|даров|здаров|приветик|ку|кхе|hello|hi|hey|yo|пока|бай|bye)$/ui', $textLower);
$isShortUnclear = count($tokensShort) <= 1 && mb_strlen($textLower) < 3
    && $matchedCity === null && $matchedCountry === null && !$intentBook && !$intentSearch && !$intentShow && !$intentHotelSelect && !$isShortGreeting;
if ($isShortUnclear) {
    h_json([
        'reset' => false,
        'ok' => true,
        'answer' => 'Не совсем понял Вы хотите подобрать отель? Назовите город, например «отели в Сочи» или «забронируй номер в Праге».',
        'suggestions' => [],
        'flow' => null,
        'state' => 'IDLE',
        'hotel' => null,
        'hotelId' => null,
        'filters' => ['city' => null, 'min' => null, 'max' => null, 'amenities' => [], 'type' => null, 'stars' => null],
        'prefill' => [],
    ]);
    exit;
}

// --- Страна без города: показать доступные города этой страны ---
if ($matchedCountry !== null && $matchedCity === null && !$intentBook && !$intentShow
    && !empty($countryCities[$matchedCountry])) {
    $cList = implode(', ', $countryCities[$matchedCountry]);
    $cp2 = $countryPrepos[$matchedCountry] ?? $matchedCountry;
    $showSuggestions = true;
    $countryCityHotels = array_values(array_filter($hotels, function ($h) use ($matchedCountry, $cities) {
        foreach ($cities as $c) {
            if ($c['name'] === $h['city'] && ($c['country'] ?? '') === $matchedCountry) return true;
        }
        return false;
    }));
    usort($countryCityHotels, fn($a, $b) => (float) $b['rating'] <=> (float) $a['rating']);
    $suggestions = array_slice($countryCityHotels, 0, 3);
    $answer = 'В ' . $cp2 . ' доступны: ' . $cList . '. Какой город выбрать?';
    h_json([
        'reset' => false,
        'ok' => true,
        'answer' => $answer,
        'suggestions' => $suggestions,
        'flow' => null,
        'hotel' => null,
        'hotelId' => null,
        'filters' => ['city' => null, 'min' => $minPrice, 'max' => $maxPrice, 'amenities' => array_keys($wantedAmenities), 'type' => $wantedType, 'stars' => $stars],
        'prefill' => $prefill,
    ]);
    exit;
}

// Обращается ли пользователь к отелю из контекста («этот отель», «у него есть бассейн?»)
// Только если есть активное состояние (выбор из списка или бронирование)
$anaphora = $contextHotel !== '' && ($ctxState === 'AWAITING_SELECTION' || $ctxState === 'BOOKING_FLOW' || $ctxState === 'SEARCH_RESULTS')
    && (bool) preg_match('/этот отель|этот вариант|у него|у неё|у них|про него|про неё|подробнее про|сколько стоит этот|забронируй его|покажи его|есть ли у него|а у него|а у неё|а у них|этот|его|его там|какой он|какая она|он норм|она норм|а тот|та\s+(?:отел|вариант|номер|стоимость)|тот\s+(?:отел|вариант|номер|стоимость)/ui', $textLower);

// --- «Покажи отель 8» / «Сколько стоит …»: находим конкретный отель по ID или имени ---
$showHotel = null;
if ($intentShow) {
    $showId = null;
    if (preg_match('/отел[ьи]?\s*[№#]?\s*(\d{1,2})\b/u', $textLower, $m)) {
        $showId = (int) $m[1];
    } elseif (preg_match('/(?:id|номер\s+отеля)\s*[:=]?\s*(\d{1,2})\b/u', $textLower, $m)) {
        $showId = (int) $m[1];
    } elseif (preg_match('/покажи\s*[№#]?\s*([1-9]|1\d|2\d|3\d)\b/u', $textLower, $m)) {
        $showId = (int) $m[1];
    } elseif (preg_match('/^(?:покажи\s*)?([1-9]|1\d|2\d|3\d)$/u', trim($textLower), $m)) {
        $showId = (int) $m[1];
    }
    if ($showId !== null) {
        foreach ($hotels as $h) {
            if ((int) $h['id'] === $showId) { $showHotel = $h; break; }
        }
    } else {
        $showHotel = find_hotel_by_name($text, $hotels);
    }
    // «покажи подробнее этот отель» — отель из контекста
    if ($showHotel === null && $anaphora && $contextHotel !== '') {
        $showHotel = find_hotel_by_name($contextHotel, $hotels);
    }
}

// Вопрос про удобство отеля из контекста: «а у него есть бассейн?»
$amenQ = null;
foreach ($amenityMap as $kw => $am) {
    if (mb_strpos($scanText, $kw) !== false) { $amenQ = [$am, $amenityNoun[$am] ?? $am]; break; }
}

if ($resetContext) {
    $_layer = 'regex';
    $showSuggestions = true;
    $suggestions = array_slice($pool, 0, 3);
    $answer = 'Сбросил прежние условия. Начинаем заново — назовите город, даты, бюджет или важные удобства.';
} elseif ($intentMap) {
    $mapHotel = null;
    if ($contextHotel !== '') {
        $mapHotel = find_hotel_by_name($contextHotel, $hotels);
    }
    if ($mapHotel === null) {
        $mapHotel = find_hotel_by_name($text, $hotels);
    }
    if ($mapHotel !== null) {
        $answer = 'Отель «' . $mapHotel['name'] . '» — на странице отеля есть интерактивная карта с расположением. Хотите забронировать?';
        $showSuggestions = true;
        $suggestions = [$mapHotel];
    } else {
        $answer = 'Подскажите, какой отель — и я направлю на его страницу с картой. Например: «Покажи на карте отель Метрополь».';
    }
} elseif ($intentCancel) {
    $answer = 'Отменить бронь можно бесплатно не позднее чем за 48 часов до заезда. Откройте «Мои бронирования» (/bookings.php), введите номер заявки и телефон, затем нажмите «Отменить бронь». Я не отменяю заявку без проверки данных владельца.';
} elseif (!$hasActiveContext && preg_match('/сколько\s+(?:стоит|стоим|стоят)/ui', $textLower) && $matchedCity === null) {
    $answer = 'Какой отель или город вас интересуют? Например: «отели в Сочи», «сколько стоит Riviera Park».';
} elseif (preg_match('/скольк\w*.{0,10}(?:отелей|городов|направлений)|сколько всего|каталог большой/u', $textLower)) {
    $answer = 'В каталоге уже ' . count($hotels) . ' ' . plural(count($hotels), ['отель', 'отеля', 'отелей'])
        . ' в ' . count($cities) . ' ' . plural(count($cities), ['городе', 'городах', 'городах'])
        . ' мира Скажите «отели в Токио» или «лучшие отели» — покажу варианты!';
} elseif ($anaphora && $amenQ !== null && $contextHotel !== '') {
    $ch = find_hotel_by_name($contextHotel, $hotels);
    if ($ch !== null) {
        $hasIt = in_array($amenQ[0], $ch['amenities'], true);
        $answer = ($hasIt ? 'Да! У «' . $ch['name'] . '» есть ' . $amenQ[1] . ' ✓'
                : 'Нет, у «' . $ch['name'] . '» нет ' . $amenQ[1])
            . ($hasIt ? ' Могу оформить бронь прямо в чате!' : ' Могу подобрать другой отель с этой опцией.');
    } else {
        $answer = 'Расскажите подробнее, что хотите узнать про этот отель?';
    }
} elseif ($intentShow && !$intentBook && $showHotel !== null) {
    $typeLabels = ['beach' => 'пляжный', 'mountain' => 'горный', 'city' => 'городской'];
    $al = ['pool' => 'бассейн', 'spa' => 'спа', 'beach' => 'пляж', 'wifi' => 'Wi-Fi',
        'breakfast' => 'завтрак', 'bar' => 'бар', 'gym' => 'фитнес', 'parking' => 'парковка',
        'fireplace' => 'камин', 'terrace' => 'терраса', 'kitchen' => 'кухня', 'airport' => 'трансфер',
        'all-inclusive' => 'всё включено', 'kid-club' => 'детский клуб', 'ski' => 'горнолыжный', 'mountain' => 'в горах'];
    $am = array_values(array_map(fn($x) => $al[$x] ?? $x, array_intersect($h = $showHotel['amenities'] ?? [], array_keys($al))));
    $answer = $showHotel['name'] . ' — ' . ($showHotel['stars'] ?? 0) . '★ ' . ($typeLabels[$showHotel['type']] ?? $showHotel['type'])
        . ' отель, ' . $showHotel['city'] . ".\nОт " . number_format((int) $showHotel['price'], 0, '', ' ') . ' ₽ за ночь'
        . ' · рейтинг ' . ($showHotel['rating'] ?? 0) . ' из 10 (' . ($showHotel['reviews'] ?? 0) . ' отзывов).'
        . ($am ? "\n✓ Удобства: " . implode(', ', $am) : '')
        . ($qNights > 1 ? "\nНа " . $qNights . ' ' . plural($qNights, ['ночь', 'ночи', 'ночей'])
            . ($pDates !== null ? ' (' . $pDates['checkin'] . ' — ' . $pDates['checkout'] . ')' : '')
            . ' ≈ ' . number_format((int) $showHotel['price'] * $qNights, 0, '', ' ') . ' ₽.' : '');
} elseif ($intentShow && !$intentBook && $showHotel === null && !empty($ctxSugIds)
    && preg_match('/эти\s+отел|этих\s+отел|все\s+эти|нескольк\w*\s+отел/ui', $textLower)) {
    // «сколько стоят эти отели» — цены по отелям из контекста чата
    $targets = array_values(array_filter($hotels, static fn($h) => in_array((int) $h['id'], $ctxSugIds, true)));
    if (empty($targets)) {
        $answer = 'Не нашёл выбранных отелей в контексте — пришлите ссылку или ID отеля, покажу цену.';
    } else {
        $answer = 'Вот цены на выбранные отели:';
        foreach ($targets as $i => $t) {
            $answer .= "\n" . ($i + 1) . '. «' . $t['name'] . '» (' . $t['city'] . ') — от ' . number_format((int) $t['price'], 0, '', ' ')
                . ' ₽/ночь' . ($qNights > 1 ? ', на ' . $qNights . ' ' . plural($qNights, ['ночь', 'ночи', 'ночей'])
                    . ' ≈ ' . number_format((int) $t['price'] * $qNights, 0, '', ' ') . ' ₽' : '');
        }
        $showSuggestions = true;
        $suggestions = array_slice($targets, 0, 3);
    }
} elseif ($intentPriceQuestion) {
    $noSearchState = true;
    $cityName = $contextCity ?: 'этом городе';
    $cheapest = null;
    foreach ($hotels as $h) {
        if (mb_strtolower($h['city']) === mb_strtolower($cityName)) {
            if ($cheapest === null || (int) $h['price'] < (int) $cheapest['price']) $cheapest = $h;
        }
    }
    $answer = 'Цены в ' . cityPrep($cityName) . ' зависят от сезона, района и уровня отеля. ';
    if ($cheapest !== null) {
        $answer .= 'Самый доступный вариант — «' . $cheapest['name'] . '» от '
            . number_format((int) $cheapest['price'], 0, '', ' ') . ' ₽/ночь (рейтинг ' . ($cheapest['rating'] ?? 0) . '/10). ';
    }
    $answer .= 'Хотите, подберу вариант дешевле или посмотрите другие варианты?';
    $showSuggestions = true;
    $suggestions = $cheapest !== null ? [$cheapest] : array_slice($suggestions, 0, 1);
} elseif ($intentShow && !$intentBook && $showHotel === null
    && preg_match('/сколько стоит|сколько стоят|стоят|почем|стоит|цен\w*|за ночь|сколько ночь|по чем|прайс|ценник/ui', $textLower)
    && !empty($suggestions)
    && ($matchedCity !== null || !empty($wantedAmenities) || $wantedType !== null || $minPrice !== null || $maxPrice !== null || $stars !== null)) {
    // «сколько стоит номер с завтраком» — цены лучших вариантов под фильтры (до 5)
    $quotes = array_values(array_filter($pool, static fn($ph) => (int) $ph['price'] <= 25000));
    if (empty($quotes)) $quotes = array_slice($pool, 0, 5);
    $quotes = array_slice($quotes, 0, 5);
    if (empty($quotes)) { $showSuggestions = false; }
    else {
        $first = $quotes[0];
        $showSuggestions = true;
        $suggestions = $quotes;
        $answer = 'Лучший вариант по вашему запросу' . ($matchedCity !== null ? ' в ' . cityPrep($matchedCity) : '') . ' — «' . ($first['name'] ?? '') . '» (' . ($first['stars'] ?? 0) . '★, рейтинг '
            . ($first['rating'] ?? 0) . '): от ' . number_format((int) ($first['price'] ?? 0), 0, '', ' ') . ' ₽ за ночь'
            . ($qNights > 1 ? ', на ' . $qNights . ' ' . plural($qNights, ['ночь', 'ночи', 'ночей'])
                . ($pDates !== null ? ' (' . $pDates['checkin'] . ' — ' . $pDates['checkout'] . ')' : '')
                . ' ≈ ' . number_format((int) $first['price'] * $qNights, 0, '', ' ') . ' ₽' : '')
            . ".\n\nХотите забронировать? Нажмите кнопку или скажите «забронировать».";
        if (count($quotes) > 1) {
            $answer .= "\n\nДругие варианты с ценой:";
            foreach (array_slice($quotes, 1) as $o) {
                $answer .= "\n• «" . $o['name'] . '» (' . $o['city'] . ') — от ' . number_format((int) $o['price'], 0, '', ' ') . ' ₽/ночь';
            }
        }
    }
} elseif (!$hasActiveContext && preg_match('/(?:не\s+знаю\s+куда|посоветуй\s+город|что\s+посоветуешь|где\s+лучше|помоги\s+с\s+выбор|не\s+могу\s+выбрать)/ui', $textLower)) {
    $popular = ['Сочи', 'Москва', 'Прага', 'Барселона', 'Париж', 'Токио', 'Дубай'];
    $popList = implode(', ', $popular);
    $answer = 'Популярные направления: ' . $popList . '. Назовите город — и я подберу отель!';
    $showSuggestions = false;
} elseif ($intentShow && !$intentBook) {
    $showSuggestions = true;
    if (preg_match('/лучш|топ|все отели|^отели$/u', $textLower)) {
        $answer = 'Вот лучшие отели по рейтингу гостей — все проверенные варианты:';
    } elseif ($matchedCity !== null) {
        $answer = 'Лучшие отели в ' . cityPrep($matchedCity) . ' — вот лучшие варианты:';
    } elseif (preg_match('/цен\w*|сколько|стоит|стоят|почем|прайс/ui', $textLower)) {
        $answer = 'Нужен город, чтобы показать цены. В каком городе ищете отель?';
        $showSuggestions = false;
    } else {
        $answer = 'Похоже, я не нашёл отель с таким названием. Вот лучшие варианты по рейтингу гостей — может, вы имели в виду один из них:';
    }
} elseif ($intentShow && $intentBook) {
    // Составное: «забронируй и скажи цену» — сначала показываем цены, бронь — после выбора
    $flow = null;
    $noSearchState = true;
    if (!empty($suggestions) && $matchedCity !== null) {
        $showSuggestions = true;
        $quotes = array_values(array_filter($suggestions, static fn($ph) => (int) $ph['price'] <= 25000));
        if (empty($quotes)) $quotes = array_slice($suggestions, 0, 5);
        $suggestions = $quotes;
        $first = $suggestions[0] ?? null;
        $answer = 'Показываю отели в ' . cityPrep($matchedCity) . ' с ценами. Когда выберете — скажите «забронировать».';
        if ($first !== null && $qNights > 1) {
            $totalEst = number_format((int) $first['price'] * $qNights, 0, '', ' ');
            $answer = 'Лучший вариант в ' . cityPrep($matchedCity) . ' — «' . $first['name'] . '» (' . $first['stars'] . '★): от '
                . number_format((int) $first['price'], 0, '', ' ') . ' ₽/ночь, на ' . $qNights . ' ' . plural($qNights, ['ночь', 'ночи', 'ночей']) . ' ≈ ' . $totalEst . ' ₽. Выберите или скажите «забронировать».';
        }
    } else {
        $answer = 'Нужен город, чтобы показать цены. В каком городе ищете?';
    }
} elseif ($intentBook) {
    $flow = 'book';
    $bookHotel = null;
    // «забронируй первый/второй» — выбор по порядку из suggestions
    if ($bookHotel === null && $intentHotelSelect && !empty($ctxSugIds)) {
        $ord = null;
        if (preg_match('/(?:перв\w+|1-й|1-вый|втор\w+|2-й|2-ой|трет\w+|3-й|3-ий|четв\w+|4-й|пят\w+|5-й)/ui', $textLower, $mo)) {
            $w = mb_strtolower($mo[0]);
            if (preg_match('/^перв|^1/', $w)) $ord = 0;
            elseif (preg_match('/^втор|^2/', $w)) $ord = 1;
            elseif (preg_match('/^трет|^3/', $w)) $ord = 2;
            elseif (preg_match('/^четв|^4/', $w)) $ord = 3;
            elseif (preg_match('/^пят|^5/', $w)) $ord = 4;
        }
        if ($ord !== null && isset($ctxSugIds[$ord])) {
            foreach ($hotels as $h) { if ((int) $h['id'] === (int) $ctxSugIds[$ord]) { $bookHotel = $h; break; } }
        }
    }
    // Прошлые даты — ошибка вместо продолжения бронирования
    $pastDateMsg = null;
    if ($pDates !== null && $pDates['checkin'] < date('Y-m-d')) {
        $pastDateMsg = 'Дата ' . $pDates['checkin'] . ' уже в прошлом — такой период забронировать нельзя. Подскажите актуальные даты заезда и выезда.';
        $pDates = null;
        unset($prefill['checkin'], $prefill['checkout'], $prefill['nights']);
    } elseif (preg_match('/(?<![\p{L}\p{N}])((?:19|20)\d{2})(?![\p{L}\p{N}])/u', $textLower, $my)
        && (int) $my[1] < (int) date('Y') && $pDates === null) {
        $pastDateMsg = 'Дата ' . $my[1] . ' года уже в прошлом — такой период забронировать нельзя. Подскажите актуальные даты заезда и выезда.';
    }
    // Невалидные даты: «с 19 по 32 августа» — extract_dates вернёт null, но мы должны明确но сказать
    if ($pDates === null && $pastDateMsg === null) {
        // Проверяем, упоминаются ли даты вообще
        $dateMention = (bool) preg_match('/\d{1,2}\s*(?:\.|январ|февра|март|апрел|ма[йя]|июн|июл|август|сентябр|октябр|ноябр|декабр)/ui', $textLower);
        if ($dateMention) {
            $pastDateMsg = 'Не смог распознать даты. Проверьте формат: напишите, например «с 20 по 23 августа» или «20.08–23.08».';
        }
    }
    // Превышение лимита гостей
    if ($pGuests !== null && $pGuests > 8) {
        if ($pastDateMsg === null) {
            $pastDateMsg = 'К сожалению, мы не можем забронировать номер более чем на 8 гостей. Максимум — 8 человек. Укажите другое количество.';
        }
        $pGuests = null;
        unset($prefill['guests']);
    }
    // Подозрительное имя: цифры, слишком длинное
    if ($pName !== null) {
        if (preg_match('/\d{3,}/', $pName) || mb_strlen($pName) > 50 || preg_match('/^[a-z0-9]{15,}$/i', $pName)) {
            if ($pastDateMsg === null) {
                $pastDateMsg = 'Имя содержит странные символы. Пожалуйста, укажите настоящее имя (например «Алексей»).';
            }
            $pName = null;
            unset($prefill['name']);
        }
    }
    // Проверка брони: «проверь бронь», «статус заявки», «моя бронь» — НЕ начинаем новое бронирование
    if (preg_match('/провер\w*\s+брон|статус\s+(?:брон|заявк)|моя\s+бронь|мои\s+брон\w*|где\s+(?:моя|мои)|подтверждени[ея]\s+брон|по\s+заказу\s+[A-Z0-9\-]+/ui', $textLower)) {
        h_json([
            'reset' => false,
            'ok' => true,
            'answer' => 'Для проверки бронирования откройте «Мои бронирования» (/bookings.php) и введите номер заявки вместе с телефоном. Так чужие данные останутся закрыты.',
            'suggestions' => [],
            'flow' => null,
            'state' => 'IDLE',
            'hotel' => null, 'hotelId' => null,
            'filters' => ['city' => null, 'min' => null, 'max' => null, 'amenities' => [], 'type' => null, 'stars' => null],
            'prefill' => [],
        ]);
        exit;
    }
    // Город имеет приоритет: нечёткое совпадение названия отеля в другом городе игнорируется
    $exactHit = false;
    if ($bookHotel === null) {
        $bookHotel = find_hotel_by_name($text, $hotels, $exactHit);
        if ($bookHotel !== null && $matchedCity !== null && $bookHotel['city'] !== $matchedCity && !$exactHit) {
            $bookHotel = null;
        }
    }
    if ($bookHotel === null && preg_match('/отель\s*[№#]?\s*(\d{1,2})\b/u', $textLower, $m)) {
        foreach ($hotels as $h) { if ((int) $h['id'] === (int) $m[1]) { $bookHotel = $h; break; } }
    }
    // «забронируй его» — отель из контекста (по анафоре или продолжение бронирования)
    if ($bookHotel === null && ($anaphora || $ctxState === 'BOOKING_FLOW')) {
        if ($contextHotelId > 0) {
            foreach ($hotels as $h) { if ((int) $h['id'] === $contextHotelId) { $bookHotel = $h; break; } }
        }
        if ($bookHotel === null && $contextHotel !== '') $bookHotel = find_hotel_by_name($contextHotel, $hotels);
    }
    if ($bookHotel !== null && $matchedCity === null) {
        $matchedCity = $bookHotel['city'];
    }
    if ($pastDateMsg !== null) {
        $answer = $pastDateMsg;
    } elseif ($bookHotel !== null) {
        // Если нет обязательных полей (имя/телефон) — не показываем подтверждение, а собираем
        if ($pName === null || $pPhone === null) {
            $showSuggestions = true;
            $suggestions = [$bookHotel];
            $missing = [];
            if ($pName === null) $missing[] = 'имя';
            if ($pPhone === null) $missing[] = 'телефон';
            $known = [];
            if ($matchedCity !== null) $known[] = cityPrep($matchedCity);
            if ($pDates !== null) { $nights = $pDates['nights'] ?? 1; $known[] = 'с ' . date('d.m', strtotime($pDates['checkin'])) . ' по ' . date('d.m', strtotime($pDates['checkout'])) . ' (' . $nights . ' ' . pluralNight($nights) . ')'; }
            if ($pGuests !== null) $known[] = $pGuests . ' ' . pluralGuests($pGuests);
            $knownStr = $known ? ' (учту: ' . implode(', ', $known) . '). ' : '. ';
            $answer = 'Нашёл отель «' . $bookHotel['name'] . '»' . $knownStr . 'Укажите ' . implode(' и ', $missing) . ' — и оформим бронь.';
        } else {
        $showSuggestions = true;
        $suggestions = [$bookHotel];
        $priceLine = ' от ' . number_format((int) $bookHotel['price'], 0, '', ' ') . ' ₽ за ночь';
        $confirmParts = [];
        if ($matchedCity !== null) $confirmParts[] = cityPrep($matchedCity);
        if ($pDates !== null) {
            $nights = $pDates['nights'] ?? 1;
            $confirmParts[] = 'с ' . date('d.m', strtotime($pDates['checkin'])) . ' по ' . date('d.m', strtotime($pDates['checkout'])) . ' (' . $nights . ' ' . pluralNight($nights) . ')';
        }
        if ($pGuests !== null) $confirmParts[] = $pGuests . ' ' . pluralGuests($pGuests);
        $confirmStr = $confirmParts ? ' (' . implode(', ', $confirmParts) . ')' : '';
        $isConfirmWord = (bool) preg_match('/^(?:да|ага|угу|конечно|точно|подтвержд|подтверждаю|подтвердить|хорош|ок|норм|беру|возьму|давай|yes|confirm|отлично)\b/ui', trim($textLower));
        if ($isConfirmWord && $pDates !== null && $matchedCity !== null && $pName !== null && $pPhone !== null) {
            $bookingData = [
                'hotel_id' => (int) $bookHotel['id'],
                'hotel_name' => $bookHotel['name'],
                'city' => $matchedCity,
                'checkin' => $pDates['checkin'],
                'checkout' => $pDates['checkout'],
                'guests' => (int) ($pGuests ?? 1),
                'name' => $pName,
                'phone' => $pPhone,
            ];
            $bookingResult = null;
            $bookingError = null;
            require __DIR__ . '/chat_booking.php';
            if ($bookingResult !== null) {
                $ref = $bookingResult['ref'] ?? '';
                $confirmUrl = $bookingResult['confirmation_url'] ?? '/booking-confirm.php';
                $answer = '🎉 Бронирование оформлено! Заявка #' . $ref . $confirmStr . ' —' . $priceLine . ".\n\n"
                    . 'Откройте подтверждение: ' . $confirmUrl . "\n\n"
                    . 'Отменить бронь можно не позднее 48 часов до заезда.';
                $flow = null;
            } else {
                $answer = 'Не удалось оформить бронь: ' . ($bookingError ?: 'неизвестная ошибка') . '. Попробуйте ещё раз или укажите другие параметры.';
            }
        } else {
            $answer = 'Нашёл отель «' . $bookHotel['name'] . '»' . $confirmStr . ' —' . $priceLine . '. Бронируем?';
        }
        }
    } else {
        // Собираем все недостающие обязательные слоты в одном сообщении
        $missing = [];
        if ($matchedCity === null) $missing[] = 'город';
        if ($pDates === null) $missing[] = 'даты заезда и выезда';
        if ($pGuests === null) $missing[] = 'количество гостей';
        if ($pName === null) $missing[] = 'имя';
        if ($pPhone === null) $missing[] = 'телефон';
        $known = [];
        if ($matchedCity !== null) $known[] = cityPrep($matchedCity);
        if ($pDates !== null) { $nights = $pDates['nights'] ?? 1; $known[] = 'с ' . date('d.m', strtotime($pDates['checkin'])) . ' по ' . date('d.m', strtotime($pDates['checkout'])) . ' (' . $nights . ' ' . pluralNight($nights) . ')'; }
        if ($pGuests !== null) $known[] = $pGuests . ' ' . pluralGuests($pGuests);
        if ($pName !== null) $known[] = 'имя: ' . $pName;
        if ($pPhone !== null) $known[] = 'тел. ' . $pPhone;
        $knownStr = $known ? ' (учту: ' . implode(', ', $known) . '). ' : '. ';
        if ($matchedCity !== null) {
            $showSuggestions = true;
            $answer = 'Подобрал отели' . $knownStr . 'Выберите один кнопкой или напишите «отель 8».';
            if (!empty($missing)) $answer .= ' Ещё нужно указать: ' . implode(', ', $missing) . '.';
        } else {
            $answer = 'Для бронирования мне нужны: ' . implode(', ', $missing) . '. Можно написать всё сразу, например: «Сочи, с 20 по 23 августа, нас двое, Алекс, +7 900 123-45-67», или по одному пункту.';
        }
        // Учитываем уже названные пользователем фильтры (удобства/тип/звёзды)
        $wantDesc = [];
        foreach (array_keys($wantedAmenities) as $a) {
            if (($a === 'beach' || $a === 'mountain' || $a === 'ski') && $wantedType !== null) continue;
            if (isset($amenityLabels[$a])) $wantDesc[] = $amenityLabels[$a];
        }
        if ($wantedType !== null) {
            $typeLabels = ['beach' => 'пляжный', 'mountain' => 'горный', 'city' => 'городской'];
            $wantDesc[] = ($typeLabels[$wantedType] ?? $wantedType) . ' отель';
        }
        if ($stars !== null) $wantDesc[] = $stars . '★';
        if ($wantDesc) $answer .= ' Учту: ' . implode(', ', $wantDesc) . '.';
    }
} elseif ($intentHotelSelect) {
    // Выбор отеля из предложений: «да», «первый», «этот», «беру», «второй» и т.д.
    $selectedHotel = null;
    $selectedIdx = 0;
    // Определяем какой по счёту вариант выбрал пользователь
    if (preg_match('/(?:втор(?:ой|ая|ое|ую)|№\s*2)/ui', $textLower)) $selectedIdx = 1;
    elseif (preg_match('/(?:третий|третья|третье|третью|№\s*3)/ui', $textLower)) $selectedIdx = 2;
    // Если просто «да», «ага», «этот», «беру» — берём первый
    if (isset($ctxSugIds[$selectedIdx])) {
        $targetId = $ctxSugIds[$selectedIdx];
        foreach ($hotels as $h) { if ((int) $h['id'] === $targetId) { $selectedHotel = $h; break; } }
    }
    // Если ID не найден, берём первый доступный
    if ($selectedHotel === null && !empty($ctxSugIds)) {
        $targetId = $ctxSugIds[0];
        foreach ($hotels as $h) { if ((int) $h['id'] === $targetId) { $selectedHotel = $h; break; } }
    }
    if ($selectedHotel !== null) {
        $typeLabels = ['beach' => 'пляжный', 'mountain' => 'горный', 'city' => 'городской'];
        $al = ['pool' => 'бассейн', 'spa' => 'спа', 'beach' => 'пляж', 'wifi' => 'Wi-Fi',
            'breakfast' => 'завтрак', 'bar' => 'бар', 'gym' => 'фитнес', 'parking' => 'парковка',
            'fireplace' => 'камин', 'terrace' => 'терраса', 'kitchen' => 'кухня', 'airport' => 'трансфер',
            'all-inclusive' => 'всё включено', 'kid-club' => 'детский клуб', 'ski' => 'горнолыжный', 'mountain' => 'в горах'];
        $am = array_values(array_map(fn($x) => $al[$x] ?? $x, array_intersect($selectedHotel['amenities'] ?? [], array_keys($al))));
        $answer = '«' . $selectedHotel['name'] . '» — ' . ($selectedHotel['stars'] ?? 0) . '★ ' . ($typeLabels[$selectedHotel['type']] ?? $selectedHotel['type'])
            . ' отель, ' . $selectedHotel['city'] . ".\nОт " . number_format((int) $selectedHotel['price'], 0, '', ' ') . ' ₽ за ночь'
            . ' · рейтинг ' . ($selectedHotel['rating'] ?? 0) . ' из 10 (' . ($selectedHotel['reviews'] ?? 0) . ' отзывов).'
            . ($am ? "\n✓ Удобства: " . implode(', ', $am) : '')
            . "\n\nХотите забронировать? Нажмите кнопку или скажите «забронировать».";
        $showSuggestions = true;
        $suggestions = [$selectedHotel];
        $contextHotel = $selectedHotel['name'] ?? '';
        $contextHotelId = (int) ($selectedHotel['id'] ?? 0);
        $ctxState = 'AWAITING_SELECTION';
        // Reset stale filters when switching to a hotel in a different city
        if ($contextCity !== '' && mb_strtolower($contextCity) !== mb_strtolower($selectedHotel['city'] ?? '')) {
            $contextCity = $selectedHotel['city'] ?? '';
            $minPrice = null;
            $maxPrice = null;
            $wantedAmenities = [];
            $excludedAmenities = [];
            $wantedType = null;
            $stars = null;
            $minStars = null;
            $minRating = null;
        }
    } else {
        $answer = 'Не нашёл отель по вашему выбору. Попробуйте нажать кнопку или написать «отель 8».';
    }
} elseif (preg_match('/промокод|промо|скидк|купон|акци|код|promo|discount|coupon|бонус|привилеги|льгот|премия/ui', $textLower)) {
    $hasNoPromo = (bool) preg_match('/(?:нет|нету|нулев|без|отсутств|пусто|никак)/ui', $textLower);
    if ($hasNoPromo) {
        $answer = "Промокоды могут меняться в зависимости от сезона и акций. Вот текущие:\nWELCOME10 — скидка 10% (для новых гостей)\nTRAVEL5 — скидка 500 ₽\nSKI15 — 15% на горнолыжные отели\n\nНе могу гарантировать актуальность — условия могут измениться. Попробуйте ввести при бронировании, система покажет, действует ли код.";
    } else {
        $answer = "Сейчас действуют промокоды:\nWELCOME10 — скидка 10%\nTRAVEL5 — скидка 500 ₽\nSKI15 — скидка 15% (горнолыжные отели)\n\nУсловия могут меняться. Попробуйте ввести при бронировании — система проверит. Нужна помощь с выбором отеля?";
    }
} elseif (preg_match('/оплат|платеж|платёж|карт|счет|счёт/u', $textLower)) {
    $answer = 'Оплата бронирования — на месте или онлайн на ваш выбор. Цена на сайте финальная: без скрытых комиссий. Промокод применяется сразу при бронировании.';
} elseif (!$intentSearch && preg_match('/отзыв|оцен|рейтинг|мнение/u', $textLower)) {
    $answer = 'У каждого отеля есть рейтинг и реальные отзывы гостей. Открыв отель, вы увидите оценки по критериям (чистота, сервис, расположение) и сможете оставить свой отзыв после проживания.';
} elseif (preg_match('/сравн/u', $textLower)) {
    $answer = 'На карточках отелей есть кнопка «Сравнить» — добавьте до 3 отелей, и откроется сравнительная таблица по цене, рейтингу, удобствам и условиям.';
} elseif (!$intentSearch && preg_match('/валюта|валют|доллар|евро|рубл|курс/u', $textLower)) {
    $answer = 'Цены можно показывать в рублях, евро или долларах — переключатель валюты находится в шапке сайта. Выбор сохраняется в вашем браузере.';
} elseif (preg_match('/избранн|закладк/u', $textLower)) {
    $answer = 'Нажимайте на карточке отеля, чтобы добавить его в избранное. Все отмеченные отели будут ждать вас на странице /favorites.php.';
} elseif (preg_match('/собак|питомц|животн|pet/ui', $textLower)) {
    $answer = 'Политика по питомцам зависит от отеля Уточнить можно на странице отеля в разделе «Условия» или через поддержку. Хотите, подберу отель с парковкой и трансфером?';
} elseif (preg_match('/ребенк|ребёнк|с детьм|детск|child/ui', $textLower)) {
    $answer = 'Да, большинство наших отелей принимают гостей с детьми На странице отеля есть отметка «Детский клуб» и описание условий. Подобрать семейный вариант с бассейном и завтраками?';
} elseif (preg_match('/ресторан|кафе|столов|работает до/ui', $textLower)) {
    $answer = 'Режим ресторана и другие детали указаны на странице отеля Могу подобрать вариант с рестораном, баром и завтраками — что важнее?';
} elseif (preg_match('/опозд|поздно|позже|поздний заезд|late/ui', $textLower)) {
    $answer = 'При опоздании на заселение бронь сохранится до утра по местному времени Хотите, чтобы я предупредила отель о позднем заезде? Напишите номер заявки.';
} elseif (preg_match('/получил|получили|отправил форму|моя бронь|мои брони|статус брони|где моя|подтверждени/ui', $textLower)) {
    $answer = 'Откройте страницу «Мои бронирования» (/bookings.php) и введите номер заявки вместе с телефоном Так чужие данные останутся закрыты. По защищённой ссылке из чата можно также открыть подтверждение и отменить бронь.';
} elseif (preg_match('/нужн\w*\s+(?:поддержк|помощь|оператор|менеджер|специалист)|мне\s+(?:нужн\w*\s+)?(?:поддержк|помощь|оператор)|как\s+(?:связаться|позвонить|написать)|телефон\s+поддержк|позвонить|связаться|помоги\s+с?\s*(?:бронь|оплат|отмен)/ui', $textLower)) {
    $answer = "Я — ИИ-ассистент и работаю без оператора. По вопросам бронирования, оплаты и отмены я помогу прямо здесь. Если нужна срочная помощь — напишите на support@travel.ru, команда отвечает в рабочее время.";
} elseif (preg_match('/контакт|телефон|что умеешь|что ты умеешь|чем (?:ты )?можешь помочь|что ты можешь|help|зачем ты нужен|для чего ты|менеджер|оператор|специалист|^помощь$|^помоги$/ui', $textLower)) {
    $answer = "Я умею:\nподбирать отели по городу, цене, звёздам и удобствам;\nоформлять бронь прямо в чате;\nрассказывать про промокоды;\nℹотвечать про оплату, отмену и отзывы.\n\nПримеры:\n• «Отели в Сочи с бассейном до 10 000₽»\n• «Забронируйте номер на выходные в Москве»\n• «Какие промокоды есть?»\n\nПросто опишите, что ищете!";
} elseif (preg_match('/спасибо|благодар|thanks|thank you/ui', $textLower)) {
    $thanksReplies = [
        'Всегда рад помочь! Если что-то ещё понадобится — я здесь. Хорошего путешествия! ',
        'Пожалуйста! Обращайтесь, если захотите сравнить отели или подобрать что-то ещё.',
    ];
    $answer = $thanksReplies[array_rand($thanksReplies)];
} elseif (preg_match('/пока|до свидания|до встречи|прощай|досвида|bye|goodbye/ui', $textLower)) {
    $goodbyes = [
        'До свидания! Удачного путешествия, заглядывайте ещё!',
        'Пока-пока! Хорошего дня! Если решите, куда поехать, — я тут. ',
        'Всего доброго! Ждём вас снова на Travel.ru!',
    ];
    $answer = $goodbyes[array_rand($goodbyes)];
} elseif (preg_match('/как дела|как ты|как жизнь|как настроение|как сам|как пожива|как делишки|how are you/ui', $textLower)) {
    $smallTalk = [
        'Отлично! Готов подбирать отели и бронировать номера А у вас как дела?',
        'У меня всё супер — как будто только что забронировал номер с видом на море! А вы как?',
        'Прекрасно! База отелей в порядке, промокоды на месте. Чем помочь вам?',
    ];
    $answer = $smallTalk[array_rand($smallTalk)];
} elseif (preg_match('/кто ты|ты кто|как тебя зовут|твое имя|твоё имя|как тебя звать|представься|who are you|your name/ui', $textLower)) {
    $whoAmI = [
        'Я ИИ-ассистент Travel.ru Подбираю отели по городу и цене, сравниваю их, рассказываю про промокоды и даже могу оформить бронь прямо здесь.',
        'Зовите меня просто «ассистент Travel.ru». Я виртуальный консультант по отелям — от «куда поехать» до готовой брони.',
    ];
    $answer = $whoAmI[array_rand($whoAmI)];
} elseif (preg_match('/ты бот|ты живой|ты робот|ты программа|искусственный интеллект|ты ии|живой ли|ты настоящ|bot|robot|are you human/ui', $textLower)) {
    $botTalk = [
        'Я — искусственный интеллект, но очень стараюсь быть вежливым и полезным Спросите про отели — не подведу!',
        'Чистая правда: я алгоритм, а не человек. Но отели знаю лучше некоторых людей! Попробуйте: «лучшие отели в Дубае».',
    ];
    $answer = $botTalk[array_rand($botTalk)];
} elseif (preg_match('/шутк|анекдот|рассмеши|смешн|joke/ui', $textLower)) {
    $jokes = [
        "— Официант, в супе муха!\n— Не переживайте, она не съест много.\n\nКстати, у нас промокод WELCOME10 — скидка 10% ",
        'Почему турист не может уснуть в отеле? Потому что кровать без «вида из окна» — моветон! А лучшие виды — в наших отелях ',
        "— Алло, это отель?\n— Нет, это прачечная.\n— Но я забронировал номер!\n— А, тогда вы забронировали его в прачечной.\n\nШучу! Бронь можно оформить прямо в чате ",
    ];
    $answer = $jokes[array_rand($jokes)];
} elseif (preg_match('/погод|weather|\bтепло\b|\bхолодно\b|\bжарко\b|температура|сколько\s+градус|на\s+улице\s+(?:тепл|холодн|жарк)/ui', $textLower)) {
    $noSearchState = true;
    $weatherReplies = [
        'Точный прогноз я не даю (я по отелям ), но могу подобрать курорт с подходящим сезоном: сейчас в каталоге Санторини, Пхукет, Анталья и не только. Скажите, куда хотите?',
        'Я не получаю текущую погоду без внешнего источника Назовите даты поездки — подскажу, какой сезон лучше для отдыха и где искать отель.',
    ];
    $answer = $weatherReplies[array_rand($weatherReplies)];
} elseif (preg_match('/который час|сколько время|сколько времени|what time/ui', $textLower)) {
    $answer = 'Сейчас ' . date('H:i') . ' по московскому времени ⏰ Впрочем, отели у нас работают круглосуточно — помогу с выбором в любое время!';
} elseif (preg_match('/смысл жизни|зачем мы|зачем ты|философ|почему небо голубое|meaning of life/ui', $textLower)) {
    $philo = [
        'Смысл жизни — путешествовать! А я помогу с отелями в 20 городах мира. Начнём с Дубая?',
        'Глубокий вопрос! Но я знаю точно одно: хороший отпуск с видом на море никогда не бывает лишним. Подобрать отель?',
    ];
    $answer = $philo[array_rand($philo)];
} elseif (preg_match('/молодец|ты крут|ты топ|классно|отлично|супер|красавчик|умничка|awesome|good job|you are great/ui', $textLower)) {
    $praise = [
        'Спасибо! Стараюсь. Если захотите ещё подборку — просто скажите город!',
        'Рад стараться! Может, подобрать отель с бассейном?',
    ];
    $answer = $praise[array_rand($praise)];
} elseif (preg_match('/дурак|тупой|тупая|плохой бот|бестолков|глупый|глупая|идиот|ненавижу|дурацк/ui', $textLower)) {
    $soothe = [
        'Понимаю, если что-то не так! Я постоянно учусь. Напишите иначе — например «отели в Вене до 10 000» — и я постараюсь помочь.',
        'Ой, похоже, я что-то не понял. Давайте попробуем ещё раз: какой город и бюджет вас интересуют?',
    ];
    $answer = $soothe[array_rand($soothe)];
} elseif (preg_match('/люблю тебя|я тебя люблю|ты самая лучшая|ты лучший|поцелуй|обним/ui', $textLower)) {
    $love = [
        'И я вас люблю! Особенно когда вы бронируете отели у нас. Может, романтический уик-энд в Вене?',
        'Взаимно! Скажите город — и я найду для вас самый уютный номер.',
    ];
    $answer = $love[array_rand($love)];
} elseif (preg_match('/^(?:да|ага|угу|yes|yep|конечно)$/ui', trim($textLower))) {
    $answer = 'Отлично! Тогда скажите, какой город вас интересует или что подобрать?';
} elseif (preg_match('/^(?:нет|неа|нетушки|no)$/ui', trim($textLower))) {
    $answer = 'Хорошо, не настаиваю Если передумаете — я тут, помогу с отелями в 20 городах.';
} elseif (preg_match('/что посмотреть|куда сходить|достопримечатель|что делать в|куда пойти/ui', $textLower)) {
    $answer = 'Подсказки по городу даст сам отель — на его странице есть адрес и карта с окрестностями А я подберу жильё поближе к центру: просто скажите, в каком городе!';
} elseif (preg_match('/(?:а\s+)?(?:где\s+это|где\s+находится|где\s+отель|как\s+найти|адрес)/ui', $textLower)) {
    if ($contextHotel !== '') {
        $ch = find_hotel_by_name($contextHotel, $hotels);
        if ($ch !== null) {
            $answer = 'Отель «' . $ch['name'] . '» находится в ' . cityPrep($ch['city']) . '. Точный адрес и карта — на странице отеля. Хотите забронировать?';
        } else {
            $answer = 'Уточните, какой именно отель вас интересует — и я подскажу, где он находится.';
        }
    } else {
        $answer = 'Уточните, какой отель или город интересуют — и я подскажу, где находится и как добраться.';
    }
} elseif (preg_match('/(?:как\s+добраться|транспорт|как\s+доехать|как\s+долететь|аэропорт\s+(?:какой|рядом))/ui', $textLower)) {
    if ($contextHotel !== '') {
        $ch = find_hotel_by_name($contextHotel, $hotels);
        if ($ch !== null) {
            $answer = 'До отеля «' . $ch['name'] . '» в ' . cityPrep($ch['city']) . ' удобнее всего добраться из ближайшего аэропорта. Точный маршрут есть на странице отеля. Нужна парковка или трансфер?';
        } else {
            $answer = 'Подскажите, какой отель — и я помогу с маршрутом. А пока могу найти жильё с трансфером или парковкой.';
        }
    } else {
        $answer = 'Добраться из аэропорта поможет отель — на странице каждого есть адрес и карта. А я подберу жильё с трансфером, если нужно.';
    }
} elseif (preg_match('/(?:скидк|акци|промо|купон|спецпредлож|промокод|подешевел|распродаж)/ui', $textLower)) {
    $answer = 'Скидки и акции появляются регулярно Попробуйте промокоды: WELCOME10 (−10%), TRAVEL5 (−5%), SKI15 (−15% на горнолыжные). Введите при бронировании — и автоматически получите скидку!';
} elseif (preg_match('/(?:оплат|карт\w*\s+(?:можно|оплат)|безнал|наличн|как\s+оплатить|способ(?:ы)?\s+оплат)/ui', $textLower)) {
    $answer = 'Оплата осуществляется на странице отеля после подтверждения бронирования. Доступна оплата картой (Visa, MasterCard, Мир) и другие способы. Хотите забронировать?';
} elseif (preg_match('/(?:покажи\s+на\s+карте|карта|где\s+на\s+карте)/ui', $textLower)) {
    if ($contextHotel !== '') {
        $ch = find_hotel_by_name($contextHotel, $hotels);
        if ($ch !== null) {
            $answer = 'Отель «' . $ch['name'] . '» — на странице отеля есть интерактивная карта с расположением. Хотите забронировать?';
        } else {
            $answer = 'Подскажите, какой отель — и я направлю на его страницу с картой.';
        }
    } else {
        $answer = 'На странице каждого отеля есть карта с окрестностями Выберите отель — и я покажу, где он находится.';
    }
} elseif (preg_match('/(?:хочу\s+так\s+же|такой\s+же|точно\s+такой|аналогичн|похожий\s+на)/ui', $textLower)) {
    if ($contextHotel !== '') {
        $ch = find_hotel_by_name($contextHotel, $hotels);
        if ($ch !== null) {
            $answer = 'Ищу похожие варианты на «' . $ch['name'] . '» — ' . ($ch['type'] ?? 'городской') . ' отель, ' . ($ch['stars'] ?? 0) . '★, от ' . number_format((int) $ch['price'], 0, '', ' ') . ' ₽. Нужны ещё фильтры?';
        } else {
            $answer = 'Какой отель вы имели в виду? Назовите — и я подберу похожий.';
        }
    } else {
        $answer = 'Какой отель сравнить? Назовите — и я найду похожий вариант.';
    }
} elseif (preg_match('/авиабилет|билеты|билет на|туры|туров|виз|страховк|экскурси/ui', $textLower)) {
    $answer = 'Мы пока занимаемся только отелями Зато могу найти жильё с парковкой, трансфером или завтраками — что вам важнее?';
} elseif (preg_match('/лучш|топ|рекоменд|посоветуй|подскажи|куда поехать|варианты|что предложишь|посоветуешь|подбери что-нибудь|anything good|recommend/ui', $textLower)) {
    if ($suggestions) {
        $showSuggestions = true;
        $answer = $hasFilters
            ? 'Лучшие варианты, которые точно соответствуют условиям ' . $filterPhrase . ':'
            : 'Вот лучшие отели по рейтингу гостей:';
    } else {
        $answer = 'Точного совпадения ' . $filterPhrase . ' нет. Я не буду предлагать вариант, который нарушает ваши условия — уберите один фильтр или увеличьте бюджет.';
    }
} elseif ($intentSearch) {
    if ($suggestions) {
        $showSuggestions = true;
        $answer = 'Подобрал ' . $filterPhrase . '. Вот лучшие варианты — нажмите, чтобы открыть:';
    } elseif ($matchedCity !== null) {
        $answer = 'Похоже, у нас пока нет отелей ' . $filterPhrase . '. Попробуйте изменить запрос или посмотрите все отели на странице поиска.';
    } else {
        $cityNames = implode(', ', array_column($cities, 'name'));
        $answer = 'Уточните, пожалуйста, город — сейчас мы работаем с такими: ' . $cityNames . '. Например: «отель с бассейном в Сочи до 12 000».';
    }
} elseif (preg_match('/(?:не\s+знаю\s+куда|куда\s+(?:поехать|лететь|ехать)|что\s+посоветуешь|посоветуй\s+(?:город|направл)|где\s+лучше|помоги\s+с\s+выбор|не\s+могу\s+выбрать)/ui', $textLower)) {
    $popular = ['Сочи', 'Москва', 'Прага', 'Барселона', 'Париж', 'Токио', 'Дубай'];
    $popList = implode(', ', array_map(fn($c) => $c . ' (' . cityPrep($c) . ')', $popular));
    $answer = 'Вот наши популярные направления: ' . $popList . '. Или просто скажите город — и я подберу отель!';
    $showSuggestions = false;
} elseif ($anaphora && $contextHotel !== '') {
    // Общий вопрос про отель из контекста («а что с ним?», «этот вариант норм?») → карточка отеля
    $ch = find_hotel_by_name($contextHotel, $hotels);
    if ($ch !== null) {
        $showSuggestions = true;
        $suggestions = [$ch];
        $answer = '«' . $ch['name'] . '» — ' . ($ch['stars'] ?? 0) . '★ ' . $ch['city'] . ' · от '
            . number_format((int) $ch['price'], 0, '', ' ') . ' ₽/ночь, рейтинг ' . ($ch['rating'] ?? 0)
            . '. Подробнее — в карточке ниже.';
    } else {
        $answer = 'Расскажите подробнее, что хотите узнать про этот отель?';
    }
} elseif ($contextHotel === '' && !empty($suggestions)
    && preg_match('/(?:а\s+)?(?:какой|какие|какая|какое|рейтинг|рейтенг|отзыв|скидк|акци|промо)/ui', $textLower)) {
    $first = $suggestions[0];
    $showSuggestions = true;
    $answer = 'Вот первый вариант — «' . $first['name'] . '» (' . $first['stars'] . '★, ' . $first['city']
        . ', рейтинг ' . ($first['rating'] ?? 0) . ', от ' . number_format((int) $first['price'], 0, '', ' ') . ' ₽/ночь). Подробнее — в карточке.';
} elseif (preg_match('/(?:живот|голов|зуб|горл|спин|сустав|сердц|давлен|температур|болит|боль\s|болезн|недомог|тошн|рвот|кашел|насморк|простуд|грипп|аллерг|таблетк|врач|доктор|больниц|аптек|лечени|здоров|симптом|травм|перелом|ушиб)/ui', $textLower)) {
    $answer = 'Я не медицинский помощник Если вам плохо или беспокоит острая боль — обратитесь к врачу или вызовите скорую. А вот когда поправитесь — помогу подобрать отель для восстановления! Куда хотели бы поехать?';
} elseif (preg_match('/привет|здравству|добрый (день|вечер|утро)|хай|хелло|хэлло|алё|алло|ау|йо|\bку\b|салют|даров|здаров|здорово|приветик|hello|hi|hey|hola|yo/ui', $textLower)) {
    $greetings = [
        'Привет! Я ассистент Travel.ru. Подберу отель по городу, цене и удобствам, расскажу про промокоды и даже оформлю бронь прямо в чате. Например: «отель у моря в Сочи до 12 000» или «забронируй лучший отель в Риме».',
        'Здравствуйте! Я помогу подобрать отель, сравнить варианты и оформить бронирование. Попробуйте спросить: «покажи отель в Дубае» или «дешёвый отель в Вене».',
        'Добрый день! Здесь можно найти отель в любом из наших 20 городов, узнать про промокоды или забронировать номер прямо в чате. С чего начнём?',
        'Алё-алё, слышу вас! Готов помочь с отелями: подбор, сравнение, бронь. Куда собираетесь?',
    ];
    $answer = $greetings[array_rand($greetings)];
} elseif ($mlIntent !== null && $mlConfidence >= 0.65) {
    $mlSnippets = [
        'search' => 'Давайте подберём отель! Укажите город или регион — например: «отели в Москве» или «гостиницы рядом с морем».',
        'book' => 'Хотите забронировать? Выберите отель и укажите даты — например: «Забронируй отель Метрополь на 25-27 августа».',
        'cancel' => 'Хотите отменить бронь? Напишите «отменить бронь» и укажите номер заказа или имя.',
        'show' => 'Расскажите, какой отель вас интересует — например: «Покажи отель в Париже» или «что за отель Метрополь?».',
        'refine' => 'Уточните параметры: город, цену, удобства — например: «дешевле» или «с бассейном».',
        'reject' => 'Хорошо, давай поменяем! Куда вас занести — другой город или регион?',
        'info' => 'Уточните, что хотите узнать: «есть ли бассейн?» или «какие услуги в отеле?».',
        'price' => 'Цены формируются на основе сезона, звёздности и удобств. Хотите найти вариант дешевле? Укажите бюджет — например: «отели до 10 000».',
        'greeting' => 'Привет! 👋 Готов помочь с отелями — подбор, сравнение, бронь. Куда muốn поехать?',
        'goodbye' => 'До встречи! Буду рад помочь снова — заходите когда угодно! 👋',
        'help' => 'Я умею: искать отели по городу/цене/удобствам, бронировать, отменять бронь, отвечать на вопросы про отели. Просто напишите, что нужно!',
        'complaint' => 'Понимаю ваше раздражение. Давайте попробуем по-другому — укажите город и бюджет, и я подберу лучший вариант.',
        'negation' => 'Хорошо! Начнём заново — куда хотите поехать?',
        'change' => 'Что изменить? Укажите: «Измени даты на 25-27 августа» или «Другой отель в Москве».',
        'map' => 'Покажите на карте? Укажите название отеля — например: «Покажи на карте отель Метрополь».',
        'review' => 'Хотите узнать отзывы? Спросите: «Какие отзывы об отеле Метрополь?» или «Какой рейтинг?».',
        'compare' => 'Сравним отели! Укажите два отеля — например: «Сравни отель Метрополь и отель Балчуг».',
        'promo' => 'Промокоды: WELCOME10 (скидка 10%), FIRST500 (скидка 500₽). Введите в поле «Промокод» при бронировании.',
        'payment' => 'Оплата: наличные, банковская карта, перевод. Оплата происходит при бронировании.',
        'joke' => 'Расскажу отельный анекдот: Почему программист любит отели? Потому что там бесплатный Wi-Fi и минибар! 😄',
        'weather' => 'Погода — это за пределами моей компетенции, но я могу подобрать отель с бассейном, если жарко! ☀️',
        'time' => 'Я не часы, но могу сказать: в каком городе вам нужен отель?',
        'who' => 'Я — Travel.ru Assistant 🤖 Помогаю найти и забронировать отель. Просто напишите, куда вы хотите поехать!',
        'thanks' => 'Пожалуйста! Рад помочь. Если нужен ещё отель — просто напишите! 😊',
        'insult' => 'Понимаю, что вы расстроены. Давайте решим проблему — укажите, что не так, и я постараюсь помочь.',
    ];
    $answer = $mlSnippets[$mlIntent] ?? 'Я специализируюсь на отелях. Попробуйте: «отели в Москве» или «забронировать отель в Риме».';
    if (in_array($mlIntent, ['search', 'show', 'info', 'price', 'refine'], true)) {
        $showSuggestions = true;
    }
} else {
    $userSnippet = mb_substr(trim(preg_replace('/\s+/u', ' ', $text)), 0, 30);
    if ($userSnippet === '') $userSnippet = '…';
    $isOneWord = !preg_match('/\s/u', trim($textLower)) && mb_strlen(trim($textLower)) >= 12;
    if ($isOneWord) {
        $gibberish = [
            '«' . $userSnippet . '» — похоже, клавиатура подшутила! Я ассистент по отелям: подберу жильё по городу и цене. Попробуйте «отели в Праге»!',
            '«' . $userSnippet . '» — загадка, но я в игре! На всякий случай: я умею искать отели, бронировать и раздавать промокоды. Что скажете?',
            'Ух, да это какой-то новый язык! Свободно владею русским и отельным. Спросите «лучшие отели в Токио»?',
        ];
        $answer = $gibberish[array_rand($gibberish)];
    } else {
        $fallbacks = [
            '«' . $userSnippet . '» — интересно! Я специализируюсь на отелях: подбор по городу и цене, бронь, промокоды. Может, посмотрим «лучшие отели»?',
            'Поймал: «' . $userSnippet . '» Кажется, вы хотели спросить про отели? Скажите «отели в Токио» или «дешёвый отель» — и я всё найду.',
            '«' . $userSnippet . '» — а я думал, вы спросите про отели Но я человек общительный: могу рассказать про промокоды, подобрать отель по городу или оформить бронь. Что выберем?',
            'Принято: «' . $userSnippet . '»! Я не совсем уловил смысл, но точно знаю, что отель — это хорошо. Может, посмотрим «лучшие отели»?',
        ];
        $answer = $fallbacks[array_rand($fallbacks)];
    }
}

if (!$showSuggestions) {
    $suggestions = [];
}

// --- Определяем состояние для следующего сообщения ---
$outState = 'IDLE';
if ($flow === 'book') {
    $outState = 'BOOKING_FLOW';
} elseif ($noSearchState) {
    $outState = 'IDLE';
} elseif ($showSuggestions && !empty($suggestions)) {
    if (count($suggestions) === 1) {
        $outState = 'AWAITING_SELECTION';
    } else {
        $outState = 'SEARCH_RESULTS';
    }
} elseif ($matchedCity !== null) {
    $outState = 'SEARCH_RESULTS';
}

$lastHotel = $showHotel ?? null;
if ($lastHotel === null && ($flow === 'book') && isset($bookHotel) && is_array($bookHotel)) {
    $lastHotel = $bookHotel;
}

// --- Three-layer architecture: compute confidence for LLM fallback ---
$llmHint = false;
if ($mlIntent !== null && $mlConfidence > 0) {
    // ML classifier confidence is available
    if ($mlConfidence < 0.5 && $matchedCity === null && $flow !== 'book') {
        $llmHint = true;
    }
} else {
    // No ML classifier — check if we fell through to generic fallback
    if ($flow === null && $matchedCity === null && !empty($answer)) {
        $llmHint = true;
    }
}

// --- Logging to data/chat.log ---
$_logFile = data_path('chat.log');
$_logEntry = [
    'ts' => date('c'),
    'text' => mb_substr($text, 0, 200),
    'layer' => $_layer,
    'ml_intent' => $mlIntent,
    'ml_conf' => $mlConfidence,
    'ml_city' => $mlEntities['city'] ?? null,
    'ml_amenities' => $mlEntities['amenities'] ?? [],
    'ml_guests' => $mlEntities['guests'] ?? null,
    'city' => $matchedCity,
    'state' => $outState,
    'answer_len' => mb_strlen($answer ?? ''),
    'time_ms' => round((microtime(true) - $_chatStart) * 1000, 1),
];
@file_put_contents($_logFile, json_encode($_logEntry, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);

h_json([
    'ok' => true,
    'reset' => $resetContext,
    'answer' => $answer,
    'suggestions' => $suggestions,
    'flow' => $flow,
    'state' => $outState,
    'hotel' => $lastHotel['name'] ?? null,
    'hotelId' => $lastHotel['id'] ?? null,
    'filters' => [
        'city' => $matchedCity,
        'min' => $minPrice,
        'max' => $maxPrice,
        'amenities' => array_keys($wantedAmenities),
        'excludedAmenities' => array_keys($excludedAmenities),
        'type' => $wantedType,
        'stars' => $stars,
        'minStars' => $minStars,
        'minRating' => $minRating,
    ],
    'prefill' => $prefill,
    'ml_entities' => [
        'city' => $matchedCity,
        'hotel' => $lastHotel['name'] ?? null,
        'price_min' => $minPrice,
        'price_max' => $maxPrice,
        'amenities' => array_keys($wantedAmenities),
        'type' => $wantedType,
        'stars' => $stars,
        'guests' => $guests ?? null,
        'checkin' => $checkin ?? $mlEntities['checkin'] ?? null,
        'checkout' => $checkout ?? $mlEntities['checkout'] ?? null,
        'ml_city' => $mlEntities['city'] ?? null,
        'ml_amenities' => $mlEntities['amenities'] ?? [],
        'ml_stars' => $mlEntities['stars'] ?? null,
        'ml_guests' => $mlEntities['guests'] ?? null,
    ],
    'ml_answer' => $answer,
    'confidence' => $mlConfidence,
    'llm_hint' => $llmHint,
    '_debug' => [
        'layer' => $_layer,
        'ml_intent' => $mlIntent,
        'ml_confidence' => $mlConfidence,
        'ml_time_ms' => $_mlTime,
        'total_time_ms' => round((microtime(true) - $_chatStart) * 1000, 1),
    ],
]);
