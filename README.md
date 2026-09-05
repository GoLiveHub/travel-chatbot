# Travel.ru — AI Chatbot Demo

Демонстрационный проектTravel-сайта с AI-ассистентом подбора отелей и бронирования.

## Быстрый запуск

### 1. PHP-сервер (рекомендуется)

```bash
cd D:\OSPanel\home\travel
D:\OSPanel\modules\PHP-8.3\php.exe -S 127.0.0.1:8080
```

Откройте http://127.0.0.1:8080 в браузере.

### 2. Тестирование чата

1. Нажмите на иконку чата (правый нижний угол)
2. Попробуйте:
   - "Привет" — приветствие (Layer 1: local regex)
   - "Отели в Москве" — поиск (Layer 2: server ML)
   - "120 тысяч 10 дней море" — сложный поиск с сущностями
3. Откройте DevTools (F12) → Console для просмотра логирования ensemble

## Архитектура: Two-Layer NLU (без LLM)

```
Пользователь → [Layer 1: Local Regex] → [Layer 2: Server ML + Regex]
                    ↓ (instant)            ↓ (50-200ms)
                Greetings/Thanks     Search/Book/Cancel/Entities
```

### Layer 1: Local Regex (мгновенно, без сервера)
- Приветствия, прощания, благодарности, помощь
- Извлечение сущностей: город, цена, удобства, даты, гости, звёзды
- Время отклика: < 1ms

### Layer 2: Server ML + Regex (PHP NaiveBayes + TF-IDF)
- `MlIntentClassifier` v3: 2959+ синтетических фраз, 25 намерений
- Полное извлечение сущностей из текста (город по стеблям, цена, даты, гости, звёзды, удобства, тип)
- Эвристические оверрайды для очевидных намерений (приветствие, bye, помощь, бронь...)
- Слияние с regex (ансамбль): regex-город всегда побеждает
- Уточняющие вопросы при нехватке данных: город, даты
- Время отклика: 50-200ms (включая серверный round-trip)

## Логирование

### Сервер (chat.php)
- `data/chat.log` — JSON-логирование каждого запроса (время, intent, confidence, сущности, state)
- Возвращается в ответе `_debug`:
```json
{
  "_debug": {
    "layer": "ml",
    "ml_intent": "search",
    "ml_confidence": 0.85,
    "ml_time_ms": 12.3,
    "total_time_ms": 145.2
  }
}
```

### Клиент (chat.js)
Логи в DevTools Console:
```
[Ensemble] Layer1 instant: greeting | Time: 1 ms
[Ensemble] Layer2 ML: | intent: search | conf: 0.95 | city: Москва | Time: 46 ms
[Ensemble] Merged: {...}
[Ensemble] Clarifying: ...
```

## Генерация ML-датасета

```bash
# Генерация 2959+ синтетических фраз (25 интентов)
php scripts/generate-ml-dataset.php > data/training_data.json

# Обучение модели (NaiveBayes + TF-IDF) → data/ml_model.php
php scripts/train-ml.php
```

### Структура датасета
- **2335+ search** — поиск по городам, ценам, удобствам, датам, гостям, звёздам
- **34 book / 25 cancel** — бронирование и отмена
- **Приветствия, прощания, thanks, help, joke, promo, weather, time** и др.
- **Комплексные запросы** — «120 тысяч 10 дней море», «70к на выходные с бассейном»

## ML модель

### PHP-ML NaiveBayes + TF-IDF (без LLM)
- **Классификатор:** `MlIntentClassifier` v3 (20+ классов)
- **Модель:** `data/ml_model.php` (~5.6 MB, сериализована через base64)
- **Эвристики:** оверрайды очевидных намерений до ML
- **Полное извлечение сущностей:** город, цена min/max, даты, гости, звёзды, удобства, тип

### Проверка работы
1. Откройте чат, отправьте «Отели в Москве»
2. Убедитесь в ответе `hotel`-варианты и `filters.city = Москва`
3. В `data/chat.log` появится JSON-запись с intent/confidence/сущностями

## API Endpoints

| Endpoint | Method | Описание |
|----------|--------|----------|
| `/api/chat.php` | POST | Основной NLU endpoint (regex + ML ensemble, логирование) |
| `/api/chat_booking.php` | POST | Inline создание бронирования |
| `/api/cancel-booking.php` | POST | Отмена бронирования |
| `/api/auth.php` | POST | Авторизация |
| `/api/review.php` | POST | Отзывы |
| `/api/promos.php` | GET | Промокоды |
| `/api/health.php` | GET | Health check |

## Файловая структура

```
travel/
├── api/
│   ├── chat.php              # NLU: regex + ML ensemble, уточняющие вопросы, лог
│   ├── chat_booking.php      # Inline booking creation
│   ├── MlIntentClassifier.php # PHP-ML NaiveBayes v3 + entity extraction
│   ├── config.php            # Security, CSRF, helpers, data_path
│   └── ...
├── assets/
│   └── js/
│       ├── chat.js           # Chat UI + two-layer ensemble (regex + ML)
│       └── config.js         # API base
├── scripts/
│   ├── generate-ml-dataset.php # Синтетический датасет (2959 фраз)
│   └── train-ml.php          # Обучение NaiveBayes + TF-IDF
├── data/
│   ├── hotels.json           # Отели
│   ├── training_data.json    # Обучающий датасет
│   ├── ml_model.php          # Сериализованная ML-модель
│   ├── chat.log              # JSON-лог чата
│   └── ...
├── sw.js                     # Service Worker
├── Dockerfile                # PHP 8.3 Apache
└── README.md                 # This file
```

## Требования

- PHP 8.3+ (с расширениями: json, mbstring, session)
- Composer (для PHP-ML зависимостей)

## Дополнительные команды

```bash
# Проверка синтаксиса PHP
php -l api/chat.php
php -l api/MlIntentClassifier.php

# Проверка синтаксиса JS
node --check assets/js/chat.js

# Запуск сервера
php -S 127.0.0.1:8080

# Просмотр логов чата
tail -f data/chat.log
```
