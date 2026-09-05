<?php
declare(strict_types=1);

/**
 * MlIntentClassifier v3 — 2959+ synthetic phrases, full entity extraction.
 * 
 * Output: ['intent' => string, 'confidence' => float, 'entities' => [...]]
 * Entities: city, price_min, price_max, checkin, checkout, guests, stars, amenities, type
 */
class MlIntentClassifier
{
    private $classifier = null;
    private $vectorizer = null;
    private $tfidf = null;
    private bool $trained = false;

    private const CITY_MAP = [
        'москв' => 'Москва', 'мск' => 'Москва',
        'питер' => 'Санкт-Петербург', 'петербург' => 'Санкт-Петербург', 'спб' => 'Санкт-Петербург',
        'сочи' => 'Сочи', 'казан' => 'Казань', 'париж' => 'Париж', 'рим' => 'Рим',
        'барселон' => 'Барселона', 'анталь' => 'Анталья', 'стамбул' => 'Стамбул',
        'дуба' => 'Дубай', 'бангк' => 'Бангкок', 'вен' => 'Вена',
        'токио' => 'Токио', 'лондон' => 'Лондон', 'нью' => 'Нью-Йорк', 'йорк' => 'Нью-Йорк',
        'праг' => 'Прага', 'амстердам' => 'Амстердам', 'санторин' => 'Санторини',
        'пхукет' => 'Пхукет', 'пхук' => 'Пхукет', 'тбилис' => 'Тбилиси',
        'нижн' => 'Нижний Новгород', 'новгород' => 'Нижний Новгород',
        'краснодар' => 'Краснодар', 'екатеринбург' => 'Екатеринбург',
        'новосибирск' => 'Новосибирск', 'минск' => 'Минск', 'севастопол' => 'Севастополь',
        'ялт' => 'Ялта', 'кemer' => 'Кемер',
    ];

    private const AMENITY_MAP = [
        'бассейн' => 'pool', 'pool' => 'pool', 'пруд' => 'pool',
        'завтрак' => 'breakfast', 'breakfast' => 'breakfast', 'вкусный завтрак' => 'breakfast',
        'парковк' => 'parking', 'parking' => 'parking', 'паркинг' => 'parking',
        'спа' => 'spa', 'spa' => 'spa', 'саун' => 'spa', 'хаммам' => 'spa', 'джакузи' => 'spa',
        'трансфер' => 'transfer', 'transfer' => 'transfer', 'шаттл' => 'transfer',
        'wi-fi' => 'wifi', 'wifi' => 'wifi', 'wi fi' => 'wifi', 'интернет' => 'wifi', 'wi fi' => 'wifi',
        'кондиционер' => 'ac', 'кондиц' => 'ac', 'кондиционер' => 'ac',
        'балкон' => 'balcony', 'террас' => 'balcony',
        'вид на море' => 'sea_view', 'мор' => 'beach', 'пляж' => 'beach', 'sea view' => 'sea_view',
        'фитнес' => 'gym', 'тренажёрн' => 'gym', 'gym' => 'gym',
        'ресторан' => 'restaurant', 'restaurant' => 'restaurant',
        'бар' => 'bar', 'мини-бар' => 'minibar', 'мини бар' => 'minibar',
        'лифт' => 'elevator', 'кухн' => 'kitchen', 'стирал' => 'laundry',
        'холодильник' => 'fridge', 'сейф' => 'safe', 'детская площадка' => 'playground',
        'кроватк' => 'crib', 'детская кроват' => 'crib', 'кроватка' => 'crib',
        'бассейн детск' => 'kids_pool', 'анимац' => 'kids_club', 'детск' => 'kids_club',
        'обед' => 'dining', 'ужин' => 'dining',
    ];

    private const MONTH_MAP = [
        'январ' => 1, 'феврал' => 2, 'март' => 3, 'апрел' => 4,
        'мая' => 5, 'май' => 5, 'июн' => 6, 'июл' => 7,
        'август' => 8, 'сентябр' => 9, 'октябр' => 10, 'ноябр' => 11, 'декабр' => 12,
    ];

    public function __construct()
    {
        // Ensure PHP-ML classes are available for unserialization
        $vendorPath = __DIR__ . '/../vendor/autoload.php';
        if (file_exists($vendorPath)) require_once $vendorPath;

        $modelPath = __DIR__ . '/../data/ml_model.php';
        if (file_exists($modelPath)) {
            $model = require $modelPath;
            if (is_array($model)) {
                $this->classifier = $model['classifier'];
                $this->vectorizer = $model['vectorizer'];
                $this->tfidf = $model['tfidf'];
                $this->trained = true;
            }
        }
    }

    public function isTrained(): bool { return $this->trained; }

    /**
     * Classify text. Returns:
     *   'intent'     => string (search, book, greeting, etc.)
     *   'confidence' => float 0..1
     *   'entities'   => ['city', 'price_min', 'price_max', 'checkin', 'checkout', 'guests', 'stars', 'amenities', 'type']
     */
    public function classify(string $text): array
    {
        $normalized = $this->normalize($text);

        // 1. Pre-detect city (regex beats ML for city names)
        $city = $this->detectCity($normalized);

        // 2. Extract structured entities from text (regex layer in ML)
        $entities = $this->extractEntities($normalized);
        if ($city) $entities['city'] = $city;

        // 3. Heuristic overrides for obvious intents (bypass broken ML model)
        $intent = $this->heuristicIntent($normalized);
        if ($intent !== null) {
            return ['intent' => $intent, 'confidence' => 0.95, 'entities' => $entities];
        }

        // 4. If ML not trained — return intent=unknown
        if (!$this->trained) {
            return ['intent' => 'unknown', 'confidence' => 0.0, 'entities' => $entities];
        }

        // 5. Short city-only queries → intent=search immediately
        if ($city && mb_strlen($normalized) <= 30) {
            return ['intent' => 'search', 'confidence' => 0.95, 'entities' => $entities];
        }

        // 6. Search with entities → force search intent
        if ($city || !empty($entities['amenities']) || $entities['price_max'] || $entities['guests'] || $entities['stars'] || $entities['checkin']) {
            return ['intent' => 'search', 'confidence' => 0.90, 'entities' => $entities];
        }

        // 7. Run ML classifier (fallback)
        $vectorized = [$normalized];
        $this->vectorizer->transform($vectorized);
        $this->tfidf->transform($vectorized);
        $predicted = $this->classifier->predict($vectorized);

        if (empty($predicted)) {
            return ['intent' => 'unknown', 'confidence' => 0.0, 'entities' => $entities];
        }

        $intent = $predicted[0];
        $confidence = $this->estimateConfidence($intent, $normalized);

        // Post-processing: unreliable low-value intents (promo/unknown) with no explicit markers
        if (($intent === 'promo' || $intent === 'unknown' || $intent === 'info')
            && !preg_match('/(промокод|скидк|купон|акци|код|найди|не понял|анекдот|ты кто|как ты|кто ты|каталог|промо|дешевле|выгодно)/ui', $normalized)) {
            $confidence = 0.0;
        }

        return ['intent' => $intent, 'confidence' => $confidence, 'entities' => $entities];
    }

    private function heuristicIntent(string $text): ?string
    {
        // Greeting
        if (preg_match('/^(ну\s+)?(привет|приветик|здравствуй|здравствуйте|здрав|хай|hello|hi|hey|хей|здорово|йо|йоу|здарова|дарова|салют|добрый (день|вечер|утро)|доброе (утро|время)|ку|куку|хола|прив|хаюшки|здоро́во)\b/ui', $text)) {
            return 'greeting';
        }
        // Farewell
        if (preg_match('/^(пока|до свидания|bye|прощай|увидимся|до встречи|бай|чмоки|целую)\b/ui', $text)) {
            return 'goodbye';
        }
        // Thanks — anywhere in text, and with intensifiers
        if (preg_match('/(спасибо|благодарю|спс|сенкс|сэнкс|мерси|респект|благодарствую)/ui', $text)) {
            return 'thanks';
        }
        // Help
        if (preg_match('/^(помоги|помощь|help|подскажи|подскажите|что (ты )?умеешь|что (ты )?можешь|подскажите пожалуйста)\b/ui', $text)) {
            return 'help';
        }
        // Who are you / about
        if (preg_match('/^(кто (ты|ты такой|вы)|ты кто|что ты такое|как тебя зовут|как зовут|тебя как зовут|расскажи о себе|ты бот|ты робот|кто тебя создал|кто твой создател)\b/ui', $text)) {
            return 'info';
        }
        // Weather
        if (preg_match('/^(?:какая\s+)?(?:что\s+за\s+)?погод\w*\b|weather/ui', $text)) {
            return 'weather';
        }
        // Time
        if (preg_match('/^(который (час|сейчас)|сколько (времени|время)|время|time|сколько час)\b/ui', $text)) {
            return 'time';
        }
        // Recommend / suggest / where to go → treat as search (recommendation)
        if (preg_match('/(посоветуй|посоветуете|подскажи от|что посоветуешь|что вы посоветуете|куда (поехать|съездить|полететь)|что( же)? (выбрать|подобрать)|варианты? (отдыха|размещения)|не знаю куда)/u', $text)) {
            return 'search';
        }
        // Country/city description or sightseeing → info («расскажи про Барселону», «что посмотреть в сочи»)
        if (preg_match('/(расскажи\s+про|что\s+посмотреть\s+в|что\s+интересн\w*\s+в|достопримечательн|куда\s+сходить)/u', $text)) {
            return 'info';
        }
        // Generic "сколько стоит" without hotel context → info (вопрос, не заказ)
        if (preg_match('/сколько\s+стоит\s+(?:вход|билет|завтрак|стоянка|парковка|такси|номер\s+без)/u', $text)) {
            return 'info';
        }
        // Joke
        if (preg_match('/(анекдот|шутк|расскажи (что-нибудь )?смешн|рассмеши|пошути|юмор)/ui', $text)) {
            return 'joke';
        }
        // Reviews (до Show — «покажи отзывы» это review, а не список отелей)
        if (preg_match('/отзыв\w*|рейтинг\w*\s+отель|\bпочитать\s+отзывы/ui', $text)) {
            return 'review';
        }
        // Comparison
        if (preg_match('/(сравни|сравнить|сравнение|что\s+лучше|что\s+выгоднее)/u', $text)) {
            return 'compare';
        }
        // Show / list
        if (preg_match('/^(покажи|показать|дай список|список отел)/u', $text)) {
            return 'show';
        }
        // Map / location
        if (preg_match('/где\s+(?:находится|наход|располож(?:ен|ена)|адрес)/u', $text)) {
            return 'map';
        }
        // Book — «снять номер» неоднозначно (часто = подбор), оставляем только явные заказы
        if (preg_match('/(забронируй|забронировать|забронироват|бронирую|оформи бронь|сделай бронь|ставь бронь|book|reserve|как забронировать|как бронировать|сколько стоит бронир)\w*/ui', $text)) {
            return 'book';
        }
        // Cancel / refund
        if (preg_match('/(отмен\w+|отмени|возврат|верни деньги|вернуть деньги|отмена брон|аннулир\w*)/ui', $text)) {
            return 'cancel';
        }
        // одиночная Review-ветка (без Show) уже выше; убираем дубликат ниже
        // Show / list
        // (не путать с «не дороже/не более» = бюджет, это search)
        if (!preg_match('/^(не\s+(?:дороже|более|выше))\b/ui', $text)
            && (preg_match('/^(нет|неа|не|не надо|не хочу|отбой|стоп|хватит|всё|забудь|да ну|да ну на|фиг|нетушки)\b/ui', $text)
            || preg_match('/(не подходит|не то|не нравится|не хочу|не надо|другое|передумал|не буду)/ui', $text))) {
            return 'negation';
        }
        // Promo
        if (preg_match('/(промокод|скидк|купон|акци|promo|код)/ui', $text)) {
            return 'promo';
        }
        // Complaints about service
        if (preg_match('/(жалоб|некомфорт|плохо|ужас|кошмар|обман)/ui', $text)) {
            return 'complaint';
        }
        // Explicit hotel-search markers → search intent, even without known city
        // Catches: "подбери отель", "отели в немеции", "турция отели", "сделай одолжение подбери"
        if (preg_match('/(отели?|гостиниц\w*|подбери|подберите|найди|найдите|нужен отель|хочу отель|снять номер|куда(|-то)? поехать|отдых)/u', $text)
            && !preg_match('/(анекдот|шутк|погода|который час|время|ты кто)/ui', $text)) {
            return 'search';
        }
        // Budget / refinement markers → search (уточнение поиска: «подешевле», «до 10000», «с бассейном»)
        // Стоит ПОСЛЕ promo, чтобы «промокод со скидкой» оставался promo
        if (preg_match('/(дешевле|подешевле|дороже|бюджет|не дороже|не более|до\s+\d|от\s+\d|с\s+(?:завтрак|бассейн|спа)|без\s+(?:бассейн|завтрак|спа)|по\s+цене|эконом)/u', $text)) {
            return 'search';
        }
        return null;
    }

    private function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text));

        // Fix common mixed-keyboard words (latin letters inside russian words) and standalone translit
        $latinMap = ['a'=>'а','b'=>'б','v'=>'в','g'=>'г','d'=>'д','e'=>'е','z'=>'з','i'=>'и',
                     'k'=>'к','l'=>'л','m'=>'м','n'=>'н','o'=>'о','p'=>'п','r'=>'р','s'=>'с',
                     't'=>'т','u'=>'у','f'=>'ф','h'=>'х','c'=>'ц','y'=>'ы'];
        $text = preg_replace_callback('/(?<=[а-яё])([a-z]+)(?=[а-яё])/u', function ($m) use ($latinMap) {
            $out = '';
            foreach (str_split($m[1]) as $ch) $out .= $latinMap[$ch] ?? $ch;
            return $out;
        }, $text);
        $standalone = [
            'gostinica'=>'гостиница','otel'=>'отель','oteli'=>'отели','oтели'=>'отели',
            'hotel'=>'отель','hotels'=>'отели','turciya'=>'турция','turcia'=>'турция',
            'moskva'=>'москва','sochi'=>'сочи','piter'=>'питер','paris'=>'париж',
            'kazan'=>'казань','spb'=>'спб','msk'=>'мск','nemecia'=>'немеция',
            'germania'=>'германия','ispania'=>'испания','italia'=>'италия',
        ];
        foreach ($standalone as $from => $to) {
            $text = preg_replace('/(^|\s)' . preg_quote($from, '/') . '(?=\s|$)/u', '$1' . $to, $text);
        }

        // Expand word numbers to digits: "пять" -> "5", "десять тысяч" -> "10 1000" -> "10000"
        $digits = ['ноль'=>0,'один'=>1,'одна'=>1,'одно'=>1,'два'=>2,'две'=>2,'три'=>3,
                   'четыре'=>4,'пять'=>5,'шесть'=>6,'семь'=>7,'восемь'=>8,'девять'=>9,
                   'десять'=>10,'одиннадцать'=>11,'двенадцать'=>12];
        foreach ($digits as $w => $n) {
            $text = preg_replace('/(^|\s)' . $w . '(?=\s|$)/u', ' ' . $n . ' ', $text);
        }
        $hundreds = ['сто'=>100,'двести'=>200,'триста'=>300,'четыреста'=>400,'пятьсот'=>500];
        foreach ($hundreds as $w => $n) {
            $text = preg_replace('/(^|\s)' . $w . '(?=\s|$)/u', ' ' . $n . ' ', $text);
        }
        // NB: «тысяч/тыс/к» НЕ заменяем на 1000 — иначе цена «120 тысяч» теряет слово до price-этапа.
        // Числа словами уже раскрыты (пять→5), так что «пять тысяч» → «5 тысяч» → price-regex даст 5000.

        // «X*» — звёзды («отель 4*») → «X звёзд»
        $text = preg_replace('/(\d)\s?\*/u', '$1 звёзд', $text);

        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    }

    private function detectCity(string $text): ?string
    {
        foreach (self::CITY_MAP as $stem => $city) {
            if (mb_strpos($text, $stem) !== false) return $city;
        }
        return null;
    }

    private function extractEntities(string $text): array
    {
        $e = [
            'city' => null, 'price_min' => null, 'price_max' => null,
            'checkin' => null, 'checkout' => null,
            'guests' => null, 'stars' => null, 'amenities' => [], 'type' => null,
        ];

        // Price: «до 120 000», «от 5000», «120 тысяч», «140к», «от 5000 до 12000»
        // Голое число берём ТОЛЬКО с до/от/бюджет — иначе «000» в конце «12000» съедается как суффикс
        $toNum = static function (string $s): int {
            return (int) str_replace([' ', "\xc2\xa0"], '', $s);
        };
        // 1. Диапазон «от A до B» (с опциональными тысяч/к)
        if (preg_match('/от\s*(\d[\d\s]{0,9})\s*(тысяч|тыс|млн|миллион|к)?(?![\p{L}])\s*(?:до|[-–—])\s*(\d[\d\s]{0,9})\s*(тысяч|тыс|млн|миллион|к)?(?![\p{L}])/u', $text, $m)) {
            $multMap = ['тысяч' => 1000, 'тыс' => 1000, 'к' => 1000, 'млн' => 1000000, 'миллион' => 1000000];
            $a = $toNum($m[1]) * ($multMap[$m[2] ?? ''] ?? 1);
            $b = $toNum($m[3]) * ($multMap[$m[4] ?? ''] ?? 1);
            if ($a > 0 && $b > $a) { $e['price_min'] = $a; $e['price_max'] = $b; }
        }
        // 2. «до/от N тыс/к» или одиночное «N тысяч» (= бюджет)
        if (!$e['price_max'] && !$e['price_min']
            && preg_match('/(до|не более|максимум|бюджет(?: до)?|от)\s*(\d[\d\s]{0,9})\s*(тысяч|тыс|млн|миллион|к)(?![\p{L}])/u', $text, $m)) {
            $multMap = ['тысяч' => 1000, 'тыс' => 1000, 'к' => 1000, 'млн' => 1000000, 'миллион' => 1000000];
            $val = $toNum($m[2]) * ($multMap[$m[3]] ?? 1);
            if ($m[1] === 'от') $e['price_min'] = $val; else $e['price_max'] = $val;
        }
        // 3. Одиночное «N тыс/к» без до/от — воспринимаем как максимум бюджета
        if (!$e['price_max'] && !$e['price_min']
            && preg_match('/(?<![\p{L}\d])(\d[\d\s]{0,9})\s*(тысяч|тыс|млн|миллион|к)(?![\p{L}])/u', $text, $m)) {
            $multMap = ['тысяч' => 1000, 'тыс' => 1000, 'к' => 1000, 'млн' => 1000000, 'миллион' => 1000000];
            $e['price_max'] = $toNum($m[1]) * ($multMap[$m[2]] ?? 1);
        }
        // 4. Голое число с до/от/бюджет — ТОЛЬКО с явным маркером, чтобы не трогать даты
        if (!$e['price_max'] && !$e['price_min']
            && preg_match('/(?:до|не более|не дороже|максимум|бюджет(?: до)?)\s*(\d[\d\s]{0,9})\s*(?:₽|руб\w*|р\b)?/u', $text, $m)) {
            $val = $toNum($m[1]);
            // Цифры < 50 без единиц рядом с месяцем — это дата, а не цена («до 15 августа»)
            if ($val >= 50 || !preg_match('/\p{L}{4,}/u', mb_substr($text, mb_strpos($text, $m[1]), 30))) {
                $e['price_max'] = $val;
            }
        }
        if (!$e['price_max'] && !$e['price_min']
            && preg_match('/от\s*(\d[\d\s]{0,9})\s*(?:₽|руб\w*|р\b)/u', $text, $m)) {
            $val = $toNum($m[1]);
            if ($val >= 50) $e['price_min'] = $val;
        }

        // Guests: «6 человек», «нас 4», «вчетвером», «на двоих», «на троих»
        // NB: голое «трое/четверо» НЕ разрешено — «трое суток» это даты, не гости. Только с «на/нас/проживает»
        $guestWords = [
            'вдвоём' => 2, 'вдвоем' => 2, 'на двоих' => 2, 'в два номера' => 4,
            'втроём' => 3, 'втроем' => 3, 'на троих' => 3, 'нас трое' => 3, 'будет трое' => 3,
            'вчетвером' => 4, 'на четверых' => 4, 'нас четверо' => 4, 'будет четверо' => 4, 'четверо гостей' => 4,
            'впятером' => 5, 'на пятерых' => 5, 'нас пятеро' => 5,
        ];
        foreach ($guestWords as $w => $n) {
            if (mb_strpos($text, $w) !== false) { $e['guests'] = $n; break; }
        }
        // Сначала точные паттерны с числом (чтобы «семья из 5» не стопорилась на слове «семья»)
        if (!$e['guests'] && preg_match('/семья\s*из\s*(\d{1,2})/u', $text, $m)) $e['guests'] = (int)$m[1];
        if (!$e['guests'] && preg_match('/(\d{1,2})\s*(?:человек|гост|чел|челов)(?:\w+)?/u', $text, $m)) $e['guests'] = (int)$m[1];
        if (!$e['guests'] && preg_match('/на\s+(\d{1,2})\s*(?:мест|человек|гост|кроват)/u', $text, $m)) $e['guests'] = (int)$m[1];
        if (!$e['guests'] && preg_match('/нас\s*(?:будет\s*)?(\d{1,2})/u', $text, $m)) $e['guests'] = (int)$m[1];
        if (!$e['guests'] && (bool) preg_match('/\bсемья\b/u', $text)) $e['guests'] = 3;
        // одноместный/двухместный номер
        if (!$e['guests'] && preg_match('/одноместн/u', $text)) $e['guests'] = 1;
        if (!$e['guests'] && preg_match('/двухместн/ui', $text)) $e['guests'] = 2;
        if (!$e['guests'] && preg_match('/трёхместн|трехместн/ui', $text)) $e['guests'] = 3;

        // Stars: «5 звёзд», «пятизвёздочный», «4-звёздный», «5*», «звёздность не нужна» (→ NULL)
        if (!preg_match('/(?:без\s+зв[её]зд|люб\w+\s+зв[её]здн|зв[её]здн\w*\s+(?:не\s+(?:важн|нужн|интерес)|не\s+критична|не\s+имеет\s+значения))/ui', $text)) {
            if (preg_match('/(\d)\s*(?:звёзд|звезд|★|\*(?:\s|\.|!|$))/u', $text, $m)) $e['stars'] = (int)$m[1];
            if (!$e['stars'] && preg_match('/(\d)-\s*звёзд/u', $text, $m)) $e['stars'] = (int)$m[1];
            if (!$e['stars']) {
                $starWords = [
                    'двухзвёзд' => 2, 'двухзвезд' => 2,
                    'трёхзвёзд' => 3, 'трехзвезд' => 3, 'трехзвёзд' => 3,
                    'четырёхзвёзд' => 4, 'четырехзвезд' => 4,
                    'пятизвёзд' => 5, 'пятизвезд' => 5,
                ];
                foreach ($starWords as $w => $n) { if (mb_strpos($text, $w) !== false) { $e['stars'] = $n; break; } }
            }
            // «5-звездочный», «четырёх звёздный» без дефиса? — ловим постфиксную форму перед «звёздоч»
            if (!$e['stars'] && preg_match('/(\d)\s*зв[её]здоч/u', $text, $m)) $e['stars'] = (int)$m[1];
        }

        // Amenities
        foreach (self::AMENITY_MAP as $keyword => $code) {
            if (mb_strpos($text, $keyword) !== false && !in_array($code, $e['amenities'])) {
                $e['amenities'][] = $code;
            }
        }

        // Type
        if (preg_match('/пляжн|у\s+моря|на\s+пляже|море|пляж/u', $text)) $e['type'] = 'beach';
        elseif (preg_match('/горн|лыж/u', $text)) $e['type'] = 'mountain';
        elseif (preg_match('/городск|центр/u', $text)) $e['type'] = 'city';

        // Dates
        $year = (int)date('Y');
        // 1. «с 10 августа по 15 сентября» — два месяца
        if (preg_match('/с\s*(\d{1,2})\s+(\p{L}+)\s+по\s+(\d{1,2})\s+(\p{L}+)/u', $text, $m)) {
            $mo1 = $this->detectMonth($m[2]);
            $mo2 = $this->detectMonth($m[4]);
            if ($mo1 && $mo2) {
                $e['checkin'] = sprintf('%04d-%02d-%02d', $year, $mo1, (int)$m[1]);
                $e['checkout'] = sprintf('%04d-%02d-%02d', $mo2 < $mo1 ? $year + 1 : $year, $mo2, (int)$m[3]);
            }
        }
        // 2. «с 10 по 15 августа» — один месяц
        if (!$e['checkin'] && preg_match('/с\s*(\d{1,2})\s+по\s+(\d{1,2})\s+(\p{L}+)/u', $text, $m)) {
            $month = $this->detectMonth($m[3]);
            if ($month) {
                $e['checkin'] = sprintf('%04d-%02d-%02d', $year, $month, (int)$m[1]);
                $e['checkout'] = sprintf('%04d-%02d-%02d', $year, $month, (int)$m[2]);
            }
        }
        // 3. «на выходные» / «в выходные» — ближайшая сб-вс
        if (!$e['checkin'] && preg_match('/на\s+выходн|в\s+выходн|выходные\s+в/u', $text)) {
            $d = new \DateTime('today');
            $dow = (int)$d->format('N'); // 1=Пн..7=Вс
            // Следующая суббота: пт(+1), вс(+6), сб(+7); пн..чт → +(6-dow)
            $addSat = $dow === 5 ? 1 : ((6 - $dow + 7) % 7 ?: 7);
            $sat = (clone $d)->modify('+' . $addSat . ' days');
            $sun = (clone $sat)->modify('+1 day');
            $e['checkin'] = $sat->format('Y-m-d');
            $e['checkout'] = $sun->format('Y-m-d');
        }
        // 4. «на неделю» / «на пару дней» / «на 3 дня»
        if (!$e['checkin']) {
            if (preg_match('/на\s+недел/u', $text)) {
                $e['checkin'] = (new \DateTime('today'))->format('Y-m-d');
                $e['checkout'] = (new \DateTime('today'))->modify('+7 days')->format('Y-m-d');
            } elseif (preg_match('/на\s+(\d{1,2})\s+(?:ноч|суток|сутки)/u', $text, $m)) {
                $e['checkin'] = (new \DateTime('today'))->format('Y-m-d');
                $e['checkout'] = (new \DateTime('today'))->modify('+' . min(30, (int)$m[1]) . ' days')->format('Y-m-d');
            }
        }
        // 5. «в июле» / «в августе» — месяц
        if (!$e['checkin'] && preg_match('/в\s+(\p{L}+?)\s*(?:месяц|месяце)?(?=\s|$)/u', $text, $m)) {
            $month = $this->detectMonth($m[1]);
            if ($month) {
                $e['checkin'] = sprintf('%04d-%02d-01', $year, $month);
                $lastDay = (int)date('t', mktime(0, 0, 0, $month, 1, $year));
                $e['checkout'] = sprintf('%04d-%02d-%02d', $year, $month, $lastDay);
            }
        }

        return $e;
    }

    private function detectMonth(string $text): ?int
    {
        $lower = mb_strtolower($text);
        foreach (self::MONTH_MAP as $stem => $num) {
            if (mb_strpos($lower, $stem) !== false) return $num;
        }
        return null;
    }

    private function estimateConfidence(string $intent, string $text): float
    {
        $tokens = explode(' ', $text);
        $totalTokens = max(count($tokens), 1);

        // Check how many training samples have the same intent
        $samples = $this->getIntentSampleCount($intent);
        $baseConf = min(0.8, 0.3 + ($samples / 100) * 0.5);

        // Token overlap with known patterns
        $overlap = 0;
        foreach ($tokens as $tok) {
            if (mb_strlen($tok) > 2) $overlap++;
        }
        $tokenScore = min(1.0, $overlap / max($totalTokens, 1));

        return min(1.0, $baseConf + $tokenScore * 0.3);
    }

    private function getIntentSampleCount(string $intent): int
    {
        $data = [
            'search' => 2335, 'book' => 34, 'cancel' => 25, 'show' => 32,
            'refine' => 47, 'reject' => 30, 'info' => 33, 'price' => 17,
            'greeting' => 34, 'goodbye' => 28, 'help' => 25, 'complaint' => 28,
            'negation' => 25, 'change' => 20, 'map' => 25, 'review' => 22,
            'compare' => 20, 'promo' => 33, 'payment' => 24, 'joke' => 19,
            'weather' => 17, 'time' => 14, 'who' => 21, 'thanks' => 22, 'insult' => 29,
        ];
        return $data[$intent] ?? 10;
    }

    /**
     * Levenshtein-based city typo correction.
     */
    public function correctCityTypo(string $input, array $cities): ?string
    {
        $input = $this->normalize($input);
        if (mb_strlen($input) < 2) return null;

        $bestCity = null;
        $bestScore = PHP_INT_MAX;

        foreach ($cities as $city) {
            $cityName = $this->normalize($city['name']);
            $distance = levenshtein($input, $cityName);
            if ($distance < $bestScore) {
                $bestScore = $distance;
                $bestCity = $city['name'];
            }
            $lat = $this->toLatin($cityName);
            $inputLat = $this->toLatin($input);
            $dLat = levenshtein($inputLat, $lat);
            if ($dLat < $bestScore) {
                $bestScore = $dLat;
                $bestCity = $city['name'];
            }
        }

        $len = mb_strlen($input);
        $maxDist = $len <= 4 ? 1 : ($len <= 7 ? 2 : 3);
        if ($bestScore <= $maxDist) return $bestCity;
        return null;
    }

    private function toLatin(string $text): string
    {
        $map = [
            'а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'yo',
            'ж'=>'zh','з'=>'z','и'=>'i','й'=>'y','к'=>'k','л'=>'l','м'=>'m',
            'н'=>'n','о'=>'o','п'=>'p','р'=>'r','с'=>'s','т'=>'t','у'=>'u',
            'ф'=>'f','х'=>'kh','ц'=>'ts','ч'=>'ch','ш'=>'sh','щ'=>'shch',
            'ъ'=>'','ы'=>'y','ь'=>'','э'=>'e','ю'=>'yu','я'=>'ya',
        ];
        return strtr(mb_strtolower($text), $map);
    }
}
