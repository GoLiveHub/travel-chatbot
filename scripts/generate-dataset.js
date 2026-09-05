#!/usr/bin/env node
/*  generate-dataset.js — Dataset generation pipeline for LLM fine-tuning
    Generates: single-turn labeled samples, multi-turn dialogues, ASR errors, edge cases.
    Usage:
      node scripts/generate-dataset.js [--out data/dataset.jsonl] [--count 500]
      node scripts/generate-dataset.js --format training  (HuggingFace SFT)
      node scripts/generate-dataset.js --format eval      (evaluation)
    Requires: Node.js 18+  No external deps. */

const fs = require('fs');
const path = require('path');
const ROOT = path.resolve(__dirname, '..');
const DATA_DIR = path.join(ROOT, 'data');

const CITIES = [
  'Москва', 'Санкт-Петербург', 'Сочи', 'Казань', 'Калининград',
  'Париж', 'Рим', 'Барселона', 'Лондон', 'Прага',
  'Амстердам', 'Токио', 'Пекин', 'Бангкок', 'Анталья',
  'Санторини', 'Дубай', 'Нью-Йорк', 'Майами', 'Бали',
  'Крит', 'Родос', 'Кипр', 'Гоа', 'Пхукет',
  'Вена', 'Будапешт', 'Стамбул', 'Мадрид', 'Лиссабон',
  'Хургада', 'Шарм-эль-Шейх', 'Занзибар', 'Мальдивы', 'Шри-Ланка',
];

const PREP = {
  'Москва': 'Москве', 'Санкт-Петербург': 'Санкт-Петербурге', 'Париж': 'Париже',
  'Рим': 'Риме', 'Барселона': 'Барселоне', 'Лондон': 'Лондоне', 'Прага': 'Праге',
  'Амстердам': 'Амстердаме', 'Пекин': 'Пекине', 'Бангкок': 'Бангкоке',
  'Анталья': 'Анталье', 'Дубай': 'Дубае', 'Нью-Йорк': 'Нью-Йорке',
  'Крит': 'Крите', 'Кипр': 'Кипре', 'Вена': 'Вене', 'Будапешт': 'Будапеште',
  'Стамбул': 'Стамбуле', 'Мадрид': 'Мадриде', 'Лиссабон': 'Лиссабоне',
  'Хургада': 'Хургаде', 'Шарм-эль-Шейх': 'Шарм-эль-Шейхе',
  'Мальдивы': 'Мальдивах', 'Шри-Ланка': 'Шри-Ланке',
};

const HOTELS = [
  'Sunrise Beach Resort', 'Горная Вершина', 'Tropical Island Paradise',
  'Grand Plaza Hotel', 'Речной Бриз', 'Alpine Comfort', 'Солнечный Берег',
  'Морская Звезда', 'Royal Palace', 'Зелёная Роща', 'Blue Lagoon',
  'Аврора', 'Романтика', 'Серебряный Пляж', 'Golden Sands',
  'Усадьба Лесная', 'Azure Coast', 'Коралловый Риф', 'Лунный Свет',
  'Emerald Bay', 'Amber Residence', 'Волна', 'Crystal Palace',
  'Пальмовый Оазис', 'Harmony Hotel', 'Изумрудный', 'Skyline Tower',
  'Алые Паруса', 'Golden Gate Inn', 'Северное Сияние', 'Маяк',
  'Русская Усадьба', 'Coral Reef Club', 'Вишнёвый Сад', 'Silver Mist',
];

const PRICES = [3200, 4100, 5500, 6800, 7200, 8900, 9500, 11000, 12800, 15000, 18500, 22000, 28000, 35000];
const NAMES = ['Алексей', 'Мария', 'Дмитрий', 'Анна', 'Сергей', 'Елена', 'Иван', 'Ольга', 'Андрей', 'Наталья', 'Пётр', 'Татьяна', 'Михаил', 'Ирина', 'Артём', 'Юлия', 'Кирилл', 'Виктория', 'Максим', 'Светлана'];
const PHONES = ['+7 900 123 45 67', '+7 916 234 56 78', '+7 495 345 67 89', '+7 925 456 78 90', '8 800 123 45 67'];
const DATES = ['15-20 марта', '1-5 апреля', '10-17 мая', '20-27 июня', '1-8 июля', '15-22 августа', '5-12 сентября', '1-7 октября', 'на выходные', 'на следующей неделе', 'завтра', 'через неделю'];

function pick(a) { return a[Math.floor(Math.random() * a.length)]; }
function prep(c) { return PREP[c] || c; }

// --- Intent templates ---
const INTENTS = {
  greeting: {
    t: ['привет', 'здравствуйте', 'добрый день', 'добрый вечер', 'доброе утро', 'хай', 'хелло', 'салют', 'приветик', 'hello', 'hi', 'hey', 'йо', 'даров', 'здаров', 'приветики', 'доброго времени суток'],
    r: ['Привет! Готов помочь с отелями. Куда хотите поехать?', 'Здравствуйте! Я ассистент Travel.ru. Какой город интересует?', 'Алло! Слушаю вас. Где ищете отель?'],
  },
  farewell: {
    t: ['пока', 'до свидания', 'до встречи', 'прощай', 'бай', 'bye', 'goodbye', 'пока пока', 'удачи', 'всего хорошего', 'бывай'],
    r: ['До встречи! Заходите когда угодно!', 'Пока! Хорошей вам поездки!', 'До свидания! Обращайтесь!'],
  },
  thanks: {
    t: ['спасибо', 'благодарю', 'сенкс', 'thanks', 'thank you', 'спасибо большое', 'огромное спасибо', 'спс', 'мерси', 'ты лучший', 'ты лучшая', 'спасибо за помощь'],
    r: ['Пожалуйста! Рад помочь!', 'Не за что! Обращайтесь ещё!', 'Рад быть полезным! Удачного бронирования!'],
  },
  help: {
    t: ['помощь', 'помоги', 'что ты умеешь', 'что можешь', 'help', 'что делаешь', 'чем поможешь', 'что умеешь', 'как пользоваться', 'расскажи о себе'],
    r: ['Я умею: искать отели, бронировать, отменять бронь. Просто напишите, что нужно!', 'Помогу найти отель, забронировать или отменить бронь.'],
  },
  promo: {
    t: ['промокод', 'промо', 'скидка', 'акция', 'как получить промокод', 'есть скидки', 'какие акции', 'бонус', 'промокоды', 'купон', 'промик', 'промо-код', 'как сэкономить', 'льготы'],
    r: ['Да! Действуют: TRAVEL20 (скидка 20%), SUMMER10 (скидка 10%). Введите промокод при бронировании.'],
  },
  cancel: {
    t: ['отменить бронь', 'отмена бронирования', 'отменить заказ', 'вернуть деньги', 'как отменить', 'отмена', 'отменить', 'аннулировать', 'мне нужно отменить', 'хочу отменить'],
    r: ['Отменить бронь можно бесплатно не позднее чем за 48 часов до заезда.', 'При отмене менее чем за 48 часов удерживается 30% стоимости.'],
  },
  map: {
    t: ['покажи на карте', 'покажи карту', 'карта отелей', 'где это на карте', 'отобразить на карте'],
    r: ['Карта отелей откроется в полном размере.'],
  },
  joke: {
    t: ['анекдот', 'шутка', 'рассмеши', 'смешн', 'joke', 'юмор', 'расскажи анекдот', 'пошути'],
    r: ['Почему программист любит отели? Потому что там бесплатный Wi-Fi и минибар!'],
  },
  count: {
    t: ['сколько отелей', 'сколько всего отелей', 'сколько городов', 'сколько направлений', 'каталог', 'всего отелей'],
    r: ['В каталоге 42 отеля в 20 городах мира.'],
  },
  search: {
    t: [
      'отели в {city}', 'найти отель в {city}', 'подберите отель в {city}',
      'где остановиться в {city}', 'лучшие отели в {city}', 'отели {city}',
      'отели рядом с {city}', 'жильё в {city}', 'пляжный отель', 'отель у моря',
      'горнолыжный отель', 'отель с бассейном', 'отель с завтраком', 'отель с парковкой',
      'отель для семьи', 'отель для двоих', 'покажи отели', 'какие отели',
      'отели до {price} рублей', 'отели дешевле {price}', 'хочу в {city}',
      'поедем в {city}', 'отдых в {city}', 'отдых на море', 'где отдохнуть',
      'куда поехать', 'пляжный отдых', 'spa отель', 'отель всё включено',
      'романтический отель', 'отель с джакузи', 'хостел в {city}', 'апартаменты в {city}',
    ],
  },
  book: {
    t: [
      'забронировать', 'бронь', 'бронирование', 'заказать номер',
      'хочу забронировать', 'забронируй', 'как забронировать', 'оформить бронь',
      'бронировать номер', 'остановиться в {hotel}', 'бронь в {hotel}',
      'забронируй {hotel}', 'мне {hotel}',
    ],
  },
  info: {
    t: [
      'расскажи про {hotel}', 'что есть в {hotel}', 'подробнее про {hotel}',
      'удобства в {hotel}', 'услуги в {hotel}', 'что предлагают',
      'какие удобства', 'что включено', 'рейтинг отеля', 'отзывы',
      'как добраться', 'адрес отеля', 'цена за ночь', 'сколько стоит',
    ],
  },
  refine: {
    t: [
      'а если дешевле?', 'а если дороже?', 'а с бассейном?', 'а без бассейна?',
      'а с завтраком?', 'а 4 звезды?', 'а 5 звёзд?', 'а для двоих?',
      'а дешевле?', 'а лучше?', 'а ещё?', 'ещё варианты', 'другой вариант',
      'без бассейна', 'с парковкой', 'с трансфером', 'только люкс', 'бюджет',
    ],
  },
  confirm: {
    t: ['да', 'ок', 'хорошо', 'отлично', 'беру', 'давай', 'yes', 'точно', 'конечно', 'согласен', 'согласна', 'подтверждаю'],
    r: ['Отлично! Оформляю...', 'Хорошо, продолжаем бронирование.'],
  },
};

const SEARCH_PHRASES = [
  c => `ищу отель в ${prep(c)}`, c => `подбери отель в ${prep(c)}`,
  c => `где жить в ${prep(c)}?`, c => `hotels in ${c.toLowerCase()}`,
  c => `хочу поехать в ${c}, подскажи отель`, c => `отели ${prep(c)} на море`,
  c => `нужен отель в ${prep(c)} на 5 ночей`, c => `жильё ${prep(c)} недорого`,
  c => `отели ${prep(c)} до ${pick(PRICES)} руб`, c => `какие отели есть в ${prep(c)}?`,
  c => `бюджетные отели ${prep(c)}`, c => `luxury отели ${prep(c)}`,
  c => `отели ${prep(c)} для семьи с детьми`, c => `отели ${prep(c)} all inclusive`,
  c => `отели ${prep(c)} с бассейном`, c => `пляжные отели ${prep(c)}`,
  c => `отели ${prep(c)} 4 звезды`, c => `дешёвые отели ${prep(c)}`,
  c => `лучший отель ${prep(c)}`, c => `рейтинг отелей ${prep(c)}`,
  c => `отели ${prep(c)} рядом с пляжем`, c => `отели ${prep(c)} с завтраком`,
];

const BOOK_PHRASES = [
  h => `хочу забронировать ${h}`, h => `забронируй ${h}`, h => `бронь ${h}`,
  h => `остановлюсь в ${h}`, h => `заказать номер в ${h}`, h => `мне ${h}`,
  h => `book ${h.toLowerCase()}`, h => `забронировать отель ${h}`,
  h => `бронирую ${h}`, h => `хочу номер в ${h}`,
];

const INFO_PHRASES = [
  h => `расскажи про ${h}`, h => `что есть в ${h}?`, h => `удобства ${h}`,
  h => `услуги ${h}`, h => `${h} отзывы`, h => `рейтинг ${h}`,
  h => `что включено в ${h}?`, h => `сколько стоит ${h}?`,
  h => `как добраться до ${h}?`, h => `${h} парковка есть?`,
  h => `${h} завтрак включён?`, h => `${h} бассейн есть?`, h => `про ${h}`,
];

// --- Dialogue templates ---
const DLG = [
  { t: [
    { role: 'user', text: 'Привет!', intent: 'greeting' },
    { role: 'bot', intent: 'greeting' },
    { role: 'user', text: '{search}' , intent: 'search' },
    { role: 'bot', intent: 'search' },
  ]},
  { t: [
    { role: 'user', text: '{search}', intent: 'search' },
    { role: 'bot', intent: 'search' },
    { role: 'user', text: 'А сколько стоит?', intent: 'info' },
    { role: 'bot', intent: 'info' },
    { role: 'user', text: 'Хорошо, забронирую', intent: 'book' },
    { role: 'bot', intent: 'book' },
  ]},
  { t: [
    { role: 'user', text: '{search}', intent: 'search' },
    { role: 'bot', intent: 'search' },
    { role: 'user', text: 'Можно с бассейном?', intent: 'refine' },
    { role: 'bot', intent: 'search' },
    { role: 'user', text: 'А завтрак включён?', intent: 'refine' },
    { role: 'bot', intent: 'info' },
    { role: 'user', text: 'Отлично, беру!', intent: 'book' },
    { role: 'bot', intent: 'book' },
  ]},
  { t: [
    { role: 'user', text: '{book}', intent: 'book' },
    { role: 'bot', intent: 'book' },
    { role: 'user', text: '{city}, {dates}, 2 гостя', intent: 'book' },
    { role: 'bot', intent: 'book' },
    { role: 'user', text: '{name}', intent: 'book' },
    { role: 'bot', intent: 'book' },
    { role: 'user', text: '{phone}', intent: 'book' },
    { role: 'bot', intent: 'book' },
    { role: 'user', text: 'Да, подтверждаю', intent: 'confirm' },
    { role: 'bot', intent: 'confirm' },
  ]},
  { t: [
    { role: 'user', text: 'Как отменить бронь?', intent: 'cancel' },
    { role: 'bot', intent: 'cancel' },
    { role: 'user', text: 'А если за 2 дня?', intent: 'cancel' },
    { role: 'bot', intent: 'cancel' },
    { role: 'user', text: 'Понял, спасибо', intent: 'thanks' },
    { role: 'bot', intent: 'thanks' },
  ]},
  { t: [
    { role: 'user', text: 'Есть промокоды?', intent: 'promo' },
    { role: 'bot', intent: 'promo' },
    { role: 'user', text: 'Спасибо!', intent: 'thanks' },
    { role: 'bot', intent: 'thanks' },
  ]},
  { t: [
    { role: 'user', text: 'Помоги', intent: 'help' },
    { role: 'bot', intent: 'help' },
    { role: 'user', text: '{search}', intent: 'search' },
    { role: 'bot', intent: 'search' },
  ]},
  { t: [
    { role: 'user', text: '{search}', intent: 'search' },
    { role: 'bot', intent: 'search' },
    { role: 'user', text: 'А в {city2} дешевле?', intent: 'refine' },
    { role: 'bot', intent: 'search' },
  ]},
  { t: [
    { role: 'user', text: '{search}', intent: 'search' },
    { role: 'bot', intent: 'search' },
    { role: 'user', text: 'Дорого! Есть дешевле?', intent: 'refine' },
    { role: 'bot', intent: 'search' },
    { role: 'user', text: 'До 8000 максимум', intent: 'refine' },
    { role: 'bot', intent: 'search' },
    { role: 'user', text: 'Ну ладно, этот пойдёт', intent: 'book' },
    { role: 'bot', intent: 'book' },
  ]},
  { t: [
    { role: 'user', text: '{search}', intent: 'search' },
    { role: 'bot', intent: 'search' },
    { role: 'user', text: 'Покажи на карте', intent: 'map' },
    { role: 'bot', intent: 'map' },
  ]},
  { t: [
    { role: 'user', text: '{search}', intent: 'search' },
    { role: 'bot', intent: 'search' },
    { role: 'user', text: 'Какой рейтинг у первого?', intent: 'info' },
    { role: 'bot', intent: 'info' },
    { role: 'user', text: 'А отзывы?', intent: 'info' },
    { role: 'bot', intent: 'info' },
  ]},
  { t: [
    { role: 'user', text: 'Привет!', intent: 'greeting' },
    { role: 'bot', intent: 'greeting' },
    { role: 'user', text: 'Хочу в {city}', intent: 'search' },
    { role: 'bot', intent: 'search' },
    { role: 'user', text: 'Есть с бассейном?', intent: 'refine' },
    { role: 'bot', intent: 'search' },
    { role: 'user', text: 'Сколько стоит?', intent: 'info' },
    { role: 'bot', intent: 'info' },
    { role: 'user', text: 'А промокод есть?', intent: 'promo' },
    { role: 'bot', intent: 'promo' },
    { role: 'user', text: 'Хорошо, забронирую', intent: 'book' },
    { role: 'bot', intent: 'book' },
    { role: 'user', text: '{name}', intent: 'book' },
    { role: 'bot', intent: 'book' },
    { role: 'user', text: '{phone}', intent: 'book' },
    { role: 'bot', intent: 'book' },
    { role: 'user', text: 'Да', intent: 'confirm' },
    { role: 'bot', intent: 'confirm' },
    { role: 'user', text: 'Спасибо!', intent: 'thanks' },
    { role: 'bot', intent: 'thanks' },
  ]},
  { t: [
    { role: 'user', text: 'Скучно. Расскажи анекдот', intent: 'joke' },
    { role: 'bot', intent: 'joke' },
    { role: 'user', text: 'Хаха! А теперь отели в {city}', intent: 'search' },
    { role: 'bot', intent: 'search' },
  ]},
  { t: [
    { role: 'user', text: '{search}', intent: 'search' },
    { role: 'bot', intent: 'search' },
    { role: 'user', text: 'Забронирую', intent: 'book' },
    { role: 'bot', intent: 'book' },
    { role: 'user', text: 'Стоп, передумал. Как отменить?', intent: 'cancel' },
    { role: 'bot', intent: 'cancel' },
  ]},
  { t: [
    { role: 'user', text: 'отель', intent: 'search' },
    { role: 'bot', intent: 'search' },
    { role: 'user', text: '{city}', intent: 'search' },
    { role: 'bot', intent: 'search' },
  ]},
  { t: [
    { role: 'user', text: '{book}', intent: 'book' },
    { role: 'bot', intent: 'book' },
    { role: 'user', text: 'да', intent: 'confirm' },
    { role: 'bot', intent: 'confirm' },
  ]},
  { t: [
    { role: 'user', text: '{book}', intent: 'book' },
    { role: 'bot', intent: 'book' },
    { role: 'user', text: 'нет, не надо', intent: 'cancel' },
    { role: 'bot', intent: 'cancel' },
  ]},
  { t: [
    { role: 'user', text: '{search}', intent: 'search' },
    { role: 'bot', intent: 'search' },
    { role: 'user', text: 'А в {city2} что есть?', intent: 'search' },
    { role: 'bot', intent: 'search' },
    { role: 'user', text: 'Вернись к первому', intent: 'search' },
    { role: 'bot', intent: 'search' },
  ]},
  { t: [
    { role: 'user', text: '{search}', intent: 'search' },
    { role: 'bot', intent: 'search' },
    { role: 'user', text: 'Только не хостел', intent: 'refine' },
    { role: 'bot', intent: 'search' },
    { role: 'user', text: 'И с parking', intent: 'refine' },
    { role: 'bot', intent: 'search' },
  ]},
  { t: [
    { role: 'user', text: 'Привет!', intent: 'greeting' },
    { role: 'bot', intent: 'greeting' },
    { role: 'user', text: 'Сколько отелей?', intent: 'count' },
    { role: 'bot', intent: 'count' },
    { role: 'user', text: 'А какие в {city}?', intent: 'search' },
    { role: 'bot', intent: 'search' },
    { role: 'user', text: 'Забронирую первый', intent: 'book' },
    { role: 'bot', intent: 'book' },
  ]},
];

// --- Generate single-turn ---
function genSingle() {
  const samples = [];
  for (const [intent, d] of Object.entries(INTENTS)) {
    for (const tpl of d.t) {
      const c = pick(CITIES), h = pick(HOTELS), p = pick(PRICES);
      samples.push({ text: tpl.replace(/\{city\}/g, c).replace(/\{hotel\}/g, h).replace(/\{price\}/g, String(p)), intent, source: 'template' });
    }
  }
  for (let i = 0; i < 100; i++) {
    const c = pick(CITIES);
    samples.push({ text: pick(SEARCH_PHRASES)(c), intent: 'search', source: 'paraphrase' });
  }
  for (let i = 0; i < 60; i++) {
    const h = pick(HOTELS);
    samples.push({ text: pick(BOOK_PHRASES)(h), intent: 'book', source: 'paraphrase' });
  }
  for (let i = 0; i < 50; i++) {
    const h = pick(HOTELS);
    samples.push({ text: pick(INFO_PHRASES)(h), intent: 'info', source: 'paraphrase' });
  }
  // Booking flow fragments
  for (const n of NAMES) samples.push({ text: n, intent: 'book', note: 'name_only', source: 'booking_flow' });
  for (const p of PHONES) samples.push({ text: p, intent: 'book', note: 'phone_only', source: 'booking_flow' });
  for (const d of DATES) samples.push({ text: d, intent: 'book', note: 'date_only', source: 'booking_flow' });
  for (const n of [1, 2, 3, 4, 5]) {
    samples.push({ text: n < 5 ? `${n} гостя` : `${n} гостей`, intent: 'book', note: 'guests', source: 'booking_flow' });
  }
  samples.push({ text: '2 взрослых, 1 ребёнок', intent: 'book', note: 'guests_mixed', source: 'booking_flow' });
  samples.push({ text: 'семья из 4 человек', intent: 'book', note: 'family', source: 'booking_flow' });
  samples.push({ text: 'завтра', intent: 'book', note: 'date_tomorrow', source: 'booking_flow' });
  samples.push({ text: 'на выходные', intent: 'book', note: 'date_weekend', source: 'booking_flow' });
  // Edge cases
  const edges = [
    ['отели', 'search', 'no_city'], ['сколько?', 'info', 'context_dep'], ['нет', 'cancel', 'negative'],
    ['покажи на карте', 'map', 'map'], ['как добраться', 'info', 'directions'],
    ['Алексей', 'book', 'name_standalone'], ['cancel', 'cancel', 'english'],
    ['спасибо за помощь', 'thanks', 'extended'], ['пока, до свидания', 'farewell', 'farewell'],
    ['один взрослый', 'book', 'guests'], ['на выходные', 'book', 'date'],
  ];
  for (const [text, intent, note] of edges) samples.push({ text, intent, note, source: 'edge_case' });
  return samples;
}

// --- ASR errors ---
function asr(text) {
  const rules = [
    [/(отел)/, 'оетл'], [/(покажи)/, 'пакажи'], [/(сколько)/, 'скалька'],
    [/(промокод)/, 'промакод'], [/(бронь)/, 'борнь'], [/(спасибо)/, 'спосибо'],
  ];
  let r = text;
  for (let i = 0; i < 2; i++) {
    const rule = pick(rules);
    if (rule[0].test(r)) r = r.replace(rule[0], rule[1]);
  }
  return r;
}

function genASR() {
  const base = ['привет', 'отели в Москве', 'забронировать отель', 'промокод', 'сколько стоит', 'покажи на карте', 'отменить бронь', 'спасибо', 'помоги', 'лучшие отели в Париже', 'отель с бассейном'];
  return base.map(t => ({ text: asr(t), intent: INTENTS.greeting.t.includes(t) ? 'greeting' : 'search', source: 'asr_error', note: 'simulated_asr' }));
}

// --- Generate dialogues ---
function resolveTpl(text) {
  if (!text) return text;
  const c = pick(CITIES), c2 = pick(CITIES.filter(x => x !== c));
  const h = pick(HOTELS), d = pick(DATES), n = pick(NAMES), p = pick(PHONES);
  if (text === '{search}') return pick(SEARCH_PHRASES)(c);
  if (text === '{book}') return pick(BOOK_PHRASES)(h);
  return text.replace(/\{city\}/g, c).replace(/\{city2\}/g, c2).replace(/\{hotel\}/g, h).replace(/\{dates\}/g, d).replace(/\{name\}/g, n).replace(/\{phone\}/g, p);
}

function intentResp(intent) {
  const d = INTENTS[intent];
  return d && d.r ? pick(d.r) : `[${intent}]`;
}

function genDialogues(count) {
  const out = [];
  for (let i = 0; i < count; i++) {
    const tmpl = pick(DLG);
    const turns = tmpl.t.map(t => ({
      role: t.role,
      text: t.role === 'user' ? resolveTpl(t.text) : intentResp(t.intent),
      intent: t.intent,
    }));
    out.push({ turns, source: 'synthetic' });
  }
  return out;
}

// --- Main ---
function main() {
  const args = process.argv.slice(2);
  let outPath = path.join(DATA_DIR, 'dataset.jsonl');
  let format = 'jsonl';
  let count = 500;

  for (let i = 0; i < args.length; i++) {
    if (args[i] === '--out' && args[i + 1]) outPath = args[++i];
    if (args[i] === '--format' && args[i + 1]) format = args[++i];
    if (args[i] === '--count' && args[i + 1]) count = parseInt(args[++i], 10) || 500;
  }

  console.log('Generating single-turn samples...');
  const single = genSingle();
  console.log(`  ${single.length} samples`);

  console.log('Generating ASR error samples...');
  const asrSamples = genASR();
  console.log(`  ${asrSamples.length} samples`);

  console.log(`Generating ${count} dialogues...`);
  const dialogues = genDialogues(count);
  console.log(`  ${dialogues.length} dialogues`);

  const lines = [];
  if (format === 'training') {
    for (const d of dialogues) {
      lines.push(JSON.stringify({ messages: d.turns.map(t => ({ role: t.role === 'bot' ? 'assistant' : 'user', content: t.text })) }));
    }
    for (const s of [...single, ...asrSamples]) {
      lines.push(JSON.stringify({ messages: [{ role: 'user', content: s.text }, { role: 'assistant', content: `[intent: ${s.intent}]` }] }));
    }
  } else if (format === 'eval') {
    for (const s of single.filter(x => x.source === 'edge_case')) lines.push(JSON.stringify(s));
    for (const s of asrSamples) lines.push(JSON.stringify(s));
  } else {
    for (const s of [...single, ...asrSamples]) lines.push(JSON.stringify(s));
    for (const d of dialogues) lines.push(JSON.stringify(d));
  }

  fs.mkdirSync(path.dirname(outPath), { recursive: true });
  fs.writeFileSync(outPath, lines.join('\n') + '\n', 'utf8');
  console.log(`\nWrote ${lines.length} lines to ${outPath} (format: ${format})`);
}

main();
