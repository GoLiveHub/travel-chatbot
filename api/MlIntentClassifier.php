<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use Phpml\Classification\NaiveBayes;
use Phpml\FeatureExtraction\TfIdfTransformer;
use Phpml\FeatureExtraction\TokenCountVectorizer;
use Phpml\Tokenization\WhitespaceTokenizer;

class MlIntentClassifier
{
    private NaiveBayes $classifier;
    private TokenCountVectorizer $vectorizer;
    private TfIdfTransformer $tfidf;
    private bool $trained = false;

    private const INTENTS = [
        'search', 'book', 'cancel', 'show', 'refine', 'reject', 'info',
        'price', 'greeting', 'goodbye', 'help', 'complaint', 'negation',
        'change', 'map', 'review', 'compare', 'promo', 'payment', 'joke',
        'weather', 'time', 'who', 'thanks', 'insult',
    ];

    public function __construct()
    {
        $this->vectorizer = new TokenCountVectorizer(new WhitespaceTokenizer());
        $this->tfidf = new TfIdfTransformer();
        $this->classifier = new NaiveBayes();
    }

    public function isTrained(): bool
    {
        return $this->trained;
    }

    /**
     * Train the classifier on labeled Russian-language phrases.
     * Each intent has ~15-30 example phrases.
     */
    public function train(): void
    {
        $samples = $this->getTrainingData();
        $labels = [];

        $corpus = [];
        foreach ($samples as $intent => $phrases) {
            foreach ($phrases as $phrase) {
                $corpus[] = $this->normalize($phrase);
                $labels[] = $intent;
            }
        }

        $this->vectorizer->fit($corpus);
        $this->vectorizer->transform($corpus);
        $this->tfidf->fit($corpus);
        $this->tfidf->transform($corpus);

        $this->classifier->train($corpus, $labels);
        $this->trained = true;
    }

    /**
     * Classify a user message. Returns [intent, probability].
     * probability is estimated via class frequency in training data.
     */
    public function classify(string $text): array
    {
        if (!$this->trained) {
            return ['unknown', 0.0];
        }

        $normalized = $this->normalize($text);
        $vectorized = [$normalized];
        $this->vectorizer->transform($vectorized);
        $this->tfidf->transform($vectorized);

        $predicted = $this->classifier->predict($vectorized);

        if (empty($predicted)) {
            return ['unknown', 0.0];
        }

        $intent = $predicted[0];

        $confidence = $this->estimateConfidence($intent, $text);

        return [$intent, $confidence];
    }

    private function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    }

    /**
     * Heuristic confidence estimate based on how many training
     * samples contain similar tokens.
     */
    private function estimateConfidence(string $intent, string $text): float
    {
        $data = $this->getTrainingData();
        if (!isset($data[$intent])) {
            return 0.5;
        }

        $tokens = explode(' ', $this->normalize($text));
        $maxOverlap = 0;
        foreach ($data[$intent] as $sample) {
            $sampleTokens = explode(' ', $this->normalize($sample));
            $overlap = count(array_intersect($tokens, $sampleTokens));
            $maxOverlap = max($maxOverlap, $overlap);
        }

        $totalTokens = max(count($tokens), 1);
        return min(1.0, 0.4 + ($maxOverlap / $totalTokens) * 0.6);
    }

    /**
     * Levenshtein-based city typo correction using PHP-ML's approach.
     * Returns the best matching city name or null.
     */
    public function correctCityTypo(string $input, array $cities): ?string
    {
        $input = $this->normalize($input);
        if (mb_strlen($input) < 2) {
            return null;
        }

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
        if ($bestScore <= $maxDist) {
            return $bestCity;
        }

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

    /**
     * Training data: Russian phrases mapped to intent labels.
     */
    private function getTrainingData(): array
    {
        return [
            'search' => [
                'найди отели в москве', 'покажи гостиницы в сочи', 'поищи отель в петербурге',
                'подбери отель в париже', 'хочу найти отель', 'ищу гостиницу', 'поищи 호텔',
                'найди мне отель', 'какие отели есть', 'покажи отели', 'есть отели',
                'отели рядом', 'найди рядом', 'поищи рядом', 'гостиницы рядом',
                'отель в центре', 'ищу жилье', 'найди жилье', 'hotel search',
                'где остановиться', 'подбери жилье', 'найди отели в токио',
                'какие отели в барселоне', 'найди в лондоне', 'отели в дубае',
                'ищу отель в риме', 'найди в праге', 'покажи варианты',
                'варианты размещения', 'подберите отель',
            ],
            'book' => [
                'забронируй отель', 'хочу забронировать', 'бронирую этот', 'забронируй',
                'бронь номера', 'снять номер', 'хочу снять номер', 'хочу номер',
                'забукировать', 'забронировать номер', 'бронируй этот отель',
                'я хочу забронировать', 'забронируй пожалуйста', 'давай бронируй',
                'беру этот', 'возьму этот', 'хочу забронировать этот',
                'забронируй на эти даты', 'бронируй на завтра', 'бронируй на weekend',
                'я забронирую', 'дайте забронировать', 'нужно забронировать',
                'сделай бронь', 'оформи бронь', 'забронируй мне',
            ],
            'cancel' => [
                'отменить бронь', 'отмени бронирование', 'хочу отменить',
                'отмена бронирования', 'верни деньги', 'возврат', 'отмените бронь',
                'я хочу отменить', 'отмени пожалуйста', 'cancel booking',
                'я отменяю', 'надо отменить', 'хочу отмену', 'отмена',
                'otmena', 'cancel', 'отменить заказ', 'отмена заказа',
                'снять бронь', 'убрать бронь', 'разбронировать',
            ],
            'show' => [
                'покажи подробнее', 'расскажи об отеле', 'что за отель',
                'подробнее об отеле', 'покажи информацию', 'что за номер',
                'сколько стоит', 'цена', 'цены', 'show details',
                'что есть в отеле', 'какие удобства', 'что включено',
                'покажи фото', 'расскажи подробнее', 'дай информацию',
                'что нового', 'описание отеля', 'отель подробнее',
                'покажи этот', 'этот отель', 'номер подробнее',
            ],
            'refine' => [
                'а с бассейном', 'с завтраком', 'без завтрака', 'покажи другие',
                'еще варианты', 'другие варианты', 'а более дешевые',
                'а подешевле', 'без бассейна', 'а с спа', 'с фитнесом',
                'покажи другие варианты', 'ещё варианты', 'другой вариант',
                'а какие ещё', 'ещё есть', 'без пляжа', 'а более дорогие',
                'хочу другой вариант', 'другой отель', 'не хочу этот',
                'поменяй фильтры', 'измени фильтры', 'без бара',
                'а с парковкой', 'без парковки', 'с террасой',
            ],
            'reject' => [
                'не хочу этот город', 'не хочу сюда', 'не в этот город',
                'давай другой город', 'поменяй город', 'смени город',
                'не этот город', 'другой город', 'не сюда',
                'не хочу туда', 'не этот', 'не тот', 'не та',
                'давай по-другому', 'нет этого', 'не так',
                'не хочу в москву', 'давай в другой', 'поменяй направление',
                'не этот отель', 'поменяй отель', 'не этот вариант',
                'другой вариант', 'не тот отель', 'смени направление',
            ],
            'info' => [
                'есть ли бассейн', ' есть ли spa', 'предоставляют ли завтрак',
                'какие услуги', ' есть трансфер', 'парковка есть',
                'ваучер', 'купон', 'промокод', 'скидки есть',
                'акции', 'спецпредложение', 'services available',
                'есть ли кондиционер', 'есть ли лифт', ' есть ли балкон',
                'оснащен ли', 'оборудован ли', ' есть ли сейф',
                'паркинг', 'шаттл', 'трансфер есть',
                'есть ли горячая вода', 'wifi есть', 'wi-fi есть',
            ],
            'price' => [
                'почему так дорого', 'зачем такие цены', 'откуда такие цены',
                'почему дорого', 'цены завышены', 'почему高价',
                'почему так высокие цены', ' почему дорого',
                'так дорого почему', 'ну почему', 'за что такие деньги',
                'why so expensive', 'why is it expensive', 'почему цена',
            ],
            'greeting' => [
                'привет', 'здравствуйте', 'добрый день', 'добрый вечер',
                'доброе утро', 'хай', 'хелло', 'приветик', 'здорово',
                'hi', 'hello', 'hey', 'добрый', 'приветствую',
                'салют', 'йо', 'йоу', 'добрый вечер',
                'добрый день', 'день добрый', 'вечер добрый',
                'здравствуй', 'здравствуйте', 'хэй',
            ],
            'goodbye' => [
                'пока', 'до свидания', 'прощай', 'увидимся', 'bay',
                'bye', 'goodbye', 'пока пока', 'до встречи', 'счастливо',
                'всего хорошего', 'всего доброго', 'до скорого',
                'я пошел', 'мне пора', 'я ухожу', 'до новой встречи',
                'пока-пока', 'бай', 'бай-бай', 'чмоки',
            ],
            'help' => [
                'что ты умеешь', 'помоги', 'help', 'что можешь',
                'чем помочь', 'помощь', 'что делаешь', 'режимы',
                'что ты можешь', 'capabilities', 'как пользоваться',
                'как тебя использовать', 'что за бот', 'инструкция',
                'хелп', 'помогите', 'ты умеешь', 'что умеешь',
            ],
            'complaint' => [
                'ты не понимаешь', 'ты не уловил', 'какой бред',
                ' ты тупой', 'ты дурак', 'бессмысленно', 'ужасно',
                'кошмар', 'ты некомпетентный', 'ничего не понимаешь',
                'все путаете', 'ужасный бот', 'хрень', 'бред',
                'ты тупица', 'не понимаешь', 'ты вникаешь',
                'зачем ты тут', 'что ты вообще', 'хватит заедать',
                'достал', 'надоел', 'надоели',
            ],
            'negation' => [
                'нет', 'неа', 'нетушки', 'не', 'ни за что',
                'стоп', 'хватит', 'отвали', 'я передумал',
                'не хочу', 'не надо', 'не нужен', 'не беру',
                'пропустить', 'без этого', 'забудь', 'давай без',
            ],
            'change' => [
                'изменить бронь', 'поменять даты', 'другие даты',
                'другой номер', 'другая дата', 'сменить',
                'обновить', 'другой отель', 'поменять отель',
                'измени бронь', 'поменяй бронь', 'другое число',
                'обновить бронь', 'change dates', 'modify booking',
            ],
            'map' => [
                'покажи на карте', 'где находится', 'адрес', 'как доехать',
                'как проехать', 'транспорт', 'как добраться',
                'покажи маршрут', 'карта', 'адрес отеля', 'на карте',
                'где отель', 'как проехать', 'show on map',
                'как попасть', ' добираться', 'проезд',
            ],
            'review' => [
                'какие отзывы', 'что говорят', 'отзывы', 'рейтинг',
                'как оценивают', 'что пишут', 'мнение гостей',
                'отзывы гостей', 'рейтинг отеля', ' оценка',
                'как рейтинг', 'review', ' reviews', 'мнение',
                'что думают', 'как оценки',
            ],
            'compare' => [
                'сравни отели', 'сравнение', 'сравнить', 'чем отличается',
                'разница между', 'what is the difference', 'сравни',
                'сравните', 'сопоставь', 'какой лучше', 'сравнение отелей',
                'compare', 'сравни два', 'сравни эти',
            ],
            'promo' => [
                'промокод', 'купон', 'ваучер', 'скидка', 'скидки',
                'акция', 'акции', 'спецпредложение', 'промо',
                'промокоды', 'есть промокод', 'промокод есть',
                'скидка есть', 'купон есть', 'промо есть',
                'промокодом', 'промокодом можно',
            ],
            'payment' => [
                'как оплатить', 'оплата', 'способы оплаты', 'карта',
                'наличные', 'оплатить', 'how to pay', 'payment',
                'безнал', 'безналичный', 'перевод', 'оплату',
                'какие способы оплаты', 'оплатка',
            ],
            'joke' => [
                'расскажи анекдот', 'шутка', 'рассмеши меня',
                'смешной анекдот', 'joke', 'расскажи шутку',
                'анекдот', 'юмор', 'шутки', 'расскажи что-нибудь смешное',
                'смешно', 'анекдотик', 'рассмеши', 'funny',
            ],
            'weather' => [
                'какая погода', 'погода', 'сколько градусов', 'тепло',
                'холодно', 'дождь', 'снег', 'облачно', 'weather',
                'прогноз погоды', 'какая погода будет', 'погода там',
                'там тепло', 'там холодно', 'там дождь',
            ],
            'time' => [
                'который час', 'сколько времени', 'время', 'часы',
                'time', 'what time', 'текущее время', 'время сейчас',
                'сейчас время', 'который сейчас час',
            ],
            'who' => [
                'кто ты', 'что ты за бот', 'представься', 'как зовут',
                'имя', 'who are you', 'what are you', 'ты кто',
                'кто ты такой', 'кто ты такая', 'что за бот',
                'представься', 'ассистент', 'бот',
            ],
            'thanks' => [
                'спасибо', 'благодарю', 'спс', 'сенкс', 'thanks',
                'thank you', 'merci', 'спасибо тебе', 'огромное спасибо',
                'благодарность', ' Merci', 'big thanks', 'thanks a lot',
                'сенкью', 'спасибочки', 'премного благодарен',
            ],
            'insult' => [
                'иди нахуй', 'пошел нахуй', 'заткнись', 'тупица',
                'дебил', 'идиот', 'дурак', 'мудак', 'лох',
                'урод', 'сволочь', 'мразь', 'кретин', 'идиот',
                'go to hell', 'shut up', 'stupid', 'idiot', 'moron',
                'тупой', 'глупый', 'дебильный', 'олух',
            ],
        ];
    }
}
