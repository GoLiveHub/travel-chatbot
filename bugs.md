# Анализ багов и недочетов — Travel.ru

> Глубокий аудит кодовой базы букинг-сайта. Дата анализа: 25.08.2026

---

## Содержание

1. [Безопасность (XSS, CSRF, сессии)](#1-безопасность)
2. [Данные и целостность](#2-данные-и-целостность)
3. [PHP: config.php и API](#3-php-configphp-и-api)
4. [PHP: hotel.php](#4-php-hotelpHP)
5. [PHP: chat.php — NLP и бизнес-логика](#5-php-chatphp)
6. [JavaScript: общие проблемы](#6-javascript-общие)
7. [JavaScript: отдельные файлы](#7-javascript-отдельные-файлы)
8. [HTML-шаблоны](#8-html-шаблоны)
9. [CSS](#9-css)
10. [Конфигурация и деплой](#10-конфигурация-и-деплой)
11. [SEO](#11-seo)
12. [Сравнение с эталонным функционалом](#12-сравнение)
13. [Приоритеты исправления](#13-приоритеты)

---

## 1. Безопасность

### 1.1. XSS в бронировании — нет серверной санитизации `name`
**Файл:** `api/booking.php:31`
`name` не очищается от HTML/JS перед записью в `data/bookings.log`. В логе уже есть `<script>alert(1)</script>`.

### 1.2. XSS через ответы чат-бота
**Файл:** `chat.js:87`
Режим `innerHTML` поддерживается. Любой новый вызов с `isHtml = true` создаёт XSS.

### 1.3. CORS `*` с credentials
**Файл:** `api/auth.php:12-13`
`Access-Control-Allow-Origin: *` + `Allow-Credentials: true` — невалидная комбинация.

### 1.4. Нет CSRF-защиты
**Файлы:** `api/booking.php`, `api/cancel-booking.php`, `api/review.php`, `booking-confirm.php:109`
Ни одна форма не использует CSRF-токены.

### 1.5. Session Fixation
**Файл:** `api/auth.php:31-32, 117-118`
`session_regenerate_id()` не вызывается после логина/регистрации/OAuth.

### 1.6. Google OAuth без `state`
**Файл:** `api/auth.php:58-70`
Нет CSRF-защиты на OAuth-потоке.

### 1.7. Session cookie без `secure` и `httponly`
**Файл:** `api/config.php:7-13`
Cookie передаётся по HTTP. MITM перехватывает сессию.

### 1.8. Session cookie не удаляется при logout
**Файл:** `api/config.php:278`
`session_destroy()` не удаляет cookie. Браузер продолжает отправлять невалидный ID.

### 1.9. Session GC lifetime < cookie lifetime
**Файл:** `api/config.php:7-13`
Cookie = 7 дней, `session.gc_maxlifetime` = 24 мин. Сессии удаляются GC через 24 мин.

### 1.10. Утечка промокодов в ошибках
**Файл:** `api/booking.php:91`
Все промокоды перечисляются в сообщении об ошибке.

### 1.11. Токен в URL (GET-параметры)
**Файлы:** `booking-confirm.php:5-6`, `bookings.php:77`
Access token попадает в историю браузера, логи, Referer.

### 1.12. Нет rate limiting на поиск бронирования
**Файл:** `bookings.php`
ref = 8 hex = ~4 млрд вариантов. Перебор занимает минуты.

### 1.13. Небезопасная генерация ref
**Файл:** `api/booking.php:101`
`random_bytes(4)` = 32 бита. Нужно `random_bytes(8)`.

### 1.14. XSS через JSON-LD
**Файл:** `hotel.php:101-102`
`json_encode()` без `JSON_HEX_TAG`. Название `</script>` прерывает JSON-LD.

### 1.15. Клиент управляет state machine чата
**Файл:** `api/chat.php:650-658`
Параметр `context` полностью управляется клиентом. Обход всех шагов поиска.

### 1.16. CSP с `unsafe-inline`
**Файл:** `.htaccess`
`script-src 'unsafe-inline'` — не защищает от XSS.

### 1.17. Нет HSTS
**Файл:** `.htaccess`
`Strict-Transport-Security` отсутствует.

### 1.18. TRACE метод не запрещён
**Файл:** `.htaccess`
Apache по умолчанию разрешает TRACE. XST-атака возможна.

### 1.19. Referrer-Policy слишком ограничительный
**Файл:** `.htaccess`
`same-origin` блокирует Referer для внешних API (платёжные шлюзы).

### 1.20. PII чата отправляется в открытом виде
**Файл:** `assets/js/chat.js:348`
`ctx.profile.phone`, `ctx.profile.name` — с каждым сообщением в открытом виде.

---

## 2. Данные и целостность

### 2.1. TOCTOU race conditions при записи в файлы
**Файлы:** `api/config.php:246-261`, `api/auth.php:105-115`, `api/booking.php:104-113`
Паттерн «прочитать-проверить-записать» — race condition. Дублирование, овербукинг.

### 2.2. TOCTOU в rate limiter
**Файл:** `api/config.php:175-203`
Чтение/проверка/запись лимита — не атомарные.

### 2.3. save_json() — json_encode возвращает false → данные уничтожаются
**Файл:** `api/config.php:72-73`
При невалидных данных `file_put_contents($path, false)` записывает пустой файл.

### 2.4. load_json() — может вернуть null с типом `: array`
**Файл:** `api/config.php:66`
`json_decode('null', true)` возвращает `null`. TypeError в strict_types.

### 2.5. .env — BOM-символ ломает первый ключ
**Файл:** `api/config.php:20-23`
UTF-8 BOM не удаляется `trim()`. Первый ключ никогда не совпадёт.

### 2.6. users_create() — undefined key warning
**Файл:** `api/config.php:268`
Ключ `oauth_provider` может отсутствовать — PHP 8.0+ E_WARNING.

### 2.7. normalize_phone() — preg_replace возвращает null
**Файл:** `api/config.php:113`
При ошибке PCRE → `null` → TypeError в PHP 8.1+.

### 2.8. Повреждённое имя в users.json
**Файл:** `data/users.json:6`
`"name": "????"` — повреждение UTF-8.

### 2.9. Бронирование на 3975 ночей (~11 лет)
**Файл:** `data/bookings.log:2`
Прошло валидацию. Лимит 30 ночей добавлен позже.

### 2.10. Несогласованная схема данных
**Файл:** `data/hotels.json`
`badge` отсутствует у 10 отелей. `reviews_list` и `rooms` не существуют, но код их использует.

### 2.11. Опечатки в chat.json
**Файл:** `data/chat.json:55`
`sда`, `sah` — никогда не совпадут с вводом.

### 2.12. 456 фантомных отзывов
**Файл:** `data/hotels.json:64`
Отель #3: `reviews: 456`. В `reviews.log` — 1 запись.

### 2.13. Отзыв до заселения
**Файлы:** `reviews.log:1`, `bookings.log:3`
Отзыв 2026-08-09, checkin = 2026-12-10. За 4 месяца до заезда.

### 2.14. Хаос форматов телефонов
**Файл:** `bookings.log`
Минимум 6 различных форматов. `normalize_phone()` не нормализует все.

### 2.15. access_token в открытом виде
**Файл:** `bookings.log:78-79`
MD5-хеш в открытом виде.

### 2.16. Формат ref изменился mid-log
**Файл:** `bookings.log:1-77` vs `78-79`
`TRV-XXXXXX` (6 hex) → `TRV-XXXXXXXX` (8 hex). Миграции не было.

---

## 3. PHP: config.php и API

### 3.1. Нет `exit` после ответа
**Файл:** `api/cancel-booking.php:27-29`
При отменённом бронировании отправляется ответ, но код продолжает выполнять второй `h_json()`.

### 3.2. request_is_too_large() обходится через chunked encoding
**Файл:** `api/config.php:158-160`
`CONTENT_LENGTH` = 0 при chunked. Тело может быть огромным.

### 3.3. load_json() — TOCTOU + нет проверки типа
**Файл:** `api/config.php:55-60`
`file_exists()` и `file_get_contents()` не атомарны. После `json_decode()` нет проверки типа.

### 3.4. load_json() → json_decode(false) → TypeError
**Файл:** `api/config.php:55-60`
Race condition → `false` → `json_decode(false, true)` — TypeError в PHP 8.

### 3.5. find_booking() — двойной reverse массива
**Файл:** `api/config.php:121-139`
`array_reverse(booking_entries())` — промежуточный массив, удвоение памяти.

### 3.6. normalize_phone() ложно принимает международные номера
**Файл:** `api/config.php:111-118`
`+4412345678901` (13 цифр) пройдёт `\d{10,15}`, будет интерпретирован как российский.

### 3.7. Case sensitivity в определении HTTPS
**Файл:** `api/config.php:42`
`'Off' !== 'off'` → ошибочно определит HTTPS как включённый.

### 3.8. password_hash без конфигурируемого cost
**Файл:** `api/config.php:255`
Стоимость хеширования не настраивается.

### 3.9. Несогласованность CORS-заголовков
**Файл:** `api/config.php:285`
`Allow-Methods` глобально, `Allow-Origin` — только в `auth.php`.

### 3.10. Цена бронирования не пересчитывается
**Файл:** `api/booking.php:79`
Между просмотром и отправкой цена может измениться.

### 3.11. Отзыв без привязки к бронированию
**Файл:** `api/review.php`
Отзыв без бронирования, CAPTCHA, верификации.

### 3.12. Double write в bookings.log при отмене
**Файл:** `api/cancel-booking.php`
Старая запись `confirmed` остаётся. `booking_entries()` — O(n).

### 3.13. @mkdir подавляет ошибки
**Файл:** `api/config.php:168`
`@` скрывает ошибки прав.

---

## 4. PHP: hotel.php

### 4.1. Related hotels перезаписывают `$hotel`
**Файл:** `hotel.php:360`
После `foreach ($related as $hotel)` переменная ссылается на последний связанный отель.

### 4.2. file() возвращает false → array_reverse(false)
**Файл:** `hotel.php:22`
Если `data/reviews.log` не существует → TypeError.

### 4.3. foreach по null amenities → TypeError
**Файл:** `hotel.php:193`
Если `amenities` отсутствует → `foreach (null as ...)` — TypeError.

### 4.4. str_repeat по null stars → TypeError
**Файл:** `hotel.php:148`
Если `stars` отсутствует → `str_repeat('★', null)` — TypeError.

### 4.5. number_format по null rating → TypeError
**Файл:** `hotel.php:173,176,284`
Если `rating` отсутствует → TypeError.

### 4.6. Пустой массив изображений
**Файл:** `hotel.php`
`$hotel['images'] ?? []` → `count([])` = 0 → слайдер пустой → JS крашится.

---

## 5. PHP: chat.php — NLP и бизнес-логика

### 5.1. levenshtein() возвращает -1 → ложное «совпадение»
**Файл:** `api/chat.php:63-76`
При длинах >255 байт levenshtein возвращает -1. `-1 <= 2` → true → ложное срабатывание.

### 5.2. $cityStop — numerically-indexed, isset() всегда false
**Файл:** `api/chat.php:543-553, 563`
Ключи числовые (0, 1, 2...), проверяется строковый. Фильтрация стоп-слов полностью сломана.

### 5.3. Городские стеммы конфликтуют со стеммами стран
**Файл:** `api/chat.php:326-331, 388-399`
`'вен'` (Вена) матчит `'венгрия'` (Венгрия) до проверки `'венгри'`. «В Венгрии» → Вена.

### 5.4. Авто-сброс контекста не прерывает выполнение
**Файл:** `api/chat.php:798-811`
`$pool`, `$suggestions`, `$hasFilters` вычислены ДО сброса. Ответ содержит старые данные.

### 5.5. fuzzy_hit() — levenshtein на байтах для кириллицы
**Файл:** `api/chat.php:63-76`
Кириллический символ = 2 байта. Опечатка в 1 символе → расстояние 2, а не 1.

### 5.6. Смещение байтов vs символов при парсинге дат
**Файл:** `api/chat.php:263`
`PREG_OFFSET_CAPTURE` возвращает байтовое, `mb_strlen()` — символьное.

### 5.7. $intentRefinement матчит «дешевле» в отрицании
**Файл:** `api/chat.php:688`
«Не хочу дешевле» интерпретируется как запрос на уменьшение цены.

### 5.8. Regex reject матчит целые предложения
**Файл:** `api/chat.php:696-698`
`\p{L}+` захватывает всё до конца. «Не хочу сидеть дома» → reject.

### 5.9. Regex жалобы слишком широкий
**Файл:** `api/chat.php:1195`
Матчит «ты не понял» даже в «ты не понял меня — я хочу в Пагу». Сбрасывает состояние.

### 5.10. Пустой $bookHotel при $matchedCity === null
**Файл:** `api/chat.php:1963-1965`
Бронирование по имени отеля без города всегда терпит неудачу.

### 5.11. Ординальный выбор отеля перезаписывается find_hotel_by_name
**Файл:** `api/chat.php:1901-1913, 1963`
«Забронируй второй» → `find_hotel_by_name` находит «отель» → перезаписывает выбор.

### 5.12. Промо-скидка считается от оригинальной цены
**Файл:** `api/chat.php` (booking flow)
`CHAT_BOOKING_DISCOUNT` применяется к оригинальной, а не к скинутой цене.

### 5.13. $minCtx читает из $ctxFilters['max']
**Файл:** `api/chat.php:1043`
Переменная названа `$minCtx`, но берёт максимальный бюджет.

### 5.14. $pastDateMsg перегружена ошибкой гостей
**Файл:** `api/chat.php:1933-1937`
При двух ошибках показывается только ошибка гостей.

### 5.15. $countryPrepos определён дважды
**Файлы:** `api/chat.php:1291-1296, 1695-1700`
Если страна добавлена в один, но не в другой — несогласованность.

### 5.16. Labels удобств определены 3 раза
**Файлы:** `api/chat.php:1064-1070, 1789-1793, 2060-2063`
Изменение в одном месте не отражается в других.

### 5.17. Budget per-night перезаписывает диапазон цен
**Файл:** `api/chat.php:1647-1655`
«От 5000 до 10000 на 5 ночей» → `$maxPrice = 2000` → `$minPrice > $maxPrice`.

### 5.18. Дублированный regex «ваучер»
**Файл:** `api/chat.php:1577`
`/ваучер|ваучер|сертификат/` — слово дважды.

### 5.19. «Ещё» в любом контексте фильтрует старые отели
**Файл:** `api/chat.php:1035`
Глагол опционален. «Ещё вопрос» триггерит фильтрацию.

### 5.20. «Та/тот» матчит любое существительное
**Файл:** `api/chat.php:1728`
«Та страна», «тот город», «та тема» — ложно срабатывают.

### 5.21. «2» где угодно выбирает второй отель
**Файл:** `api/chat.php:2047`
`\b2\b` матчит «2 отеля», «на 2 дня», «2 человека».

### 5.22. Опечатка услоб\w*
**Файл:** `api/chat.php:1567`
Несуществующее слово. Должно быть `услуг\w*`.

### 5.23. Грамматика: «год» вместо «года»
**Файл:** `api/chat.php:1923`
Правильно: «2024 года».

### 5.24. Бюджет-мультипликатор: «8» → 8000
**Файл:** `api/chat.php:879-886`
Число <100 без единиц ×1000. Нет способа указать 8.

### 5.25. «Недорого» перезаписывает ранее заданный бюджет
**Файл:** `api/chat.php:897-900`
«Недорого» → 8000 даже если было «до 20000».

### 5.26. Короткий ввод <6 символов считается неоднозначным
**Файл:** `api/chat.php:1674`
«Отель» (5 символов) отброшено.

### 5.27. Извлечение имени: «Я Хочу в Сочи» → имя «Хочу»
**Файл:** `api/chat.php:163-164`
Паттерн матчит первое слово после «я». Стоп-список не содержит «Хочу».

### 5.28. Regex имени отеля захватывает лишние слова
**Файл:** `api/chat.php:1489`
`\s` захватывает все слова до конца. «Отель на красивой улице «Ривьера Парк»».

### 5.29. Regex компилируется в цикле
**Файл:** `api/chat.php:198-199`
Десятки тысяч компиляций при длинном вводе.

### 5.30. Деление не обрабатывается в match
**Файл:** `api/chat.php:1327-1343`
Regex захватывает `/`, но match обрабатывает только `+`, `-`, `*`.

### 5.31. Отрицательное количество ночей
**Файл:** `api/chat.php:1514`
`$co - $ci` при неверном порядке → отрицательное. Без валидации.

### 5.32. Дублирование $matchedCity = null
**Файл:** `api/chat.php:423, 506`
Инициализация дважды. Первое бессмысленно.

### 5.33. str_replace на мультибайт строках
**Файл:** `api/chat.php`
`str_replace` работает с байтами → Mojibake на границах UTF-8 символов.

### 5.34. Бюджет-мультипликатор: переполнение 32-bit int
**Файл:** `api/chat.php:864-865, 884-885`
`$minPrice *= 1000` после `$mult` → до 999_999_000_000 → переполнение.

---

## 6. JavaScript: общие проблемы

### 6.1. esc() дублируется в 3 файлах
**Файлы:** `chat.js`, `favs.js`, `hotel.js`
Одна функция, три копии. XSS нужно править в трёх местах.

### 6.2. fmtPrice() дублируется в 2 файлах
**Файлы:** `chat.js`, `hotel.js`
Идентичные реализации, могут расходиться.

### 6.3. Три стратегии форматирования цен
**Файлы:** `chat.js`, `hotel.js`, `main.js`
`Intl.NumberFormat` / `Math.round` / `toFixed` — непоследовательный вид.

### 6.4. window.__hotels — мёртвый код
**Файл:** `search.js:302`
Нигде не определён. `if (window.__hotels) render()` — всегда false.

### 6.5. localStorage ключи не координированы
**Файлы:** `favs.js`, `compare.js`, `recent.js`, `currency.js`, `theme.js`
Нет централизованного хранилища. Переименование одного ломает остальные.

### 6.6. Глобальные мутабельные объекты
**Файлы:** `currency.js`, `favs.js`, `compare.js`
`window.travelCurrency` и др. — расширения могут модифицировать.

### 6.7. localStorage.setItem без try/catch
**Файлы:** `favs.js:11`, `compare.js:8`, `recent.js:17`
`QuotaExceededError` прерывает модуль.

---

## 7. JavaScript: отдельные файлы

### 7.1. search.js: бесконечный цикл при ошибке изображения
**Файл:** `search.js:92`
Fallback тоже 404 → бесконечный цикл `onerror`.

### 7.2. search.js: re-render 60 раз/сек на слайдере
**Файл:** `search.js:207-211`
Ползунок генерирует ~60 событий/сек. Debounce нужен.

### 7.3. search.js: render() уничтожает скролл и фокус
**Файл:** `search.js:199`
`grid.innerHTML = ...` — потеря состояния DOM.

### 7.4. search.js: RangeError при stars: -1
**Файл:** `search.js:93`
`'-1'.repeat(-1)` — без defensive check.

### 7.5. search.js: state() падает если DOM-элементы null
**Файл:** `search.js:36-47`
`parseInt(priceMin.value)` — TypeError при null.

### 7.6. chat.js: неправильный плюрализм «гость/гостя/гостей»
**Файл:** `chat.js:279, 384`
Для 5-20: «гостей». Для 21+: «гость». Код выдаёт «5 гостя».

### 7.7. chat.js: «15-15» → бронирование на месяц
**Файл:** `chat.js:560`
`a = b = 15` → `b.setMonth(+1)` → 30 ночей вместо ошибки.

### 7.8. chat.js: межгодовые диапазоны ломаются
**Файл:** `chat.js:548-551`
«25.12 и 01.01» — обе с текущим годом. 1 января в прошлом → null.

### 7.9. chat.js: data.total без null check → NaN
**Файл:** `chat.js:601`
`format(undefined)` → `NaN`.

### 7.10. chat.js: XSS через h.type
**Файл:** `chat.js:115-117`
Если тип не в `TYPE_LABELS` — raw значение в innerHTML.

### 7.11. chat.js: typing indicator одновременно с ответом
**Файл:** `chat.js:689-692`
`showTyping()` → `flowHandle()` → `removeTyping()` — оба видны.

### 7.12. chat.js: flow = null без очистки ctx.suggestions
**Файл:** `chat.js:606, 610`
Старые подсказки остаётся в sessionStorage.

### 7.13. chat.js: submitBooking() не блокирует UI
**Файл:** `chat.js:584-613`
Нет индикатора загрузки. Дублирование запросов.

### 7.14. chat.js: невалидный regex диапазон
**Файл:** `chat.js:537`
`‑` (U+2011) > `-` (U+002D). Диапазон невалиден в некоторых движках.

### 7.15. chat.js: sessionStorage не истекает
**Файл:** `chat.js:29-32`
Старый контекст восстанавливается через часы.

### 7.16. hotel.js: галерея не暂停 на фоновой вкладке
**Файл:** `hotel.js:115`
`setInterval(next, 4500)` тратит батарею.

### 7.17. hotel.js: document.execCommand — deprecated
**Файл:** `hotel.js:201`
Может перестать работать.

### 7.18. hotel.js: нет валидации дат
**Файл:** `hotel.js:54-91`
Нет проверки `checkin < checkout`, будущих дат, 30 ночей.

### 7.19. hotel.js: промокод может дать отрицательный total
**Файл:** `hotel.js:34-36`
100%+ скидка → отрицательное. Сервер защищён, клиент — нет.

### 7.20. hotel.js: галерея ловит все кнопки
**Файл:** `hotel.js:98`
`querySelectorAll('button')` ловит «Поделиться», «Нравится» → `src = "undefined"`.

### 7.21. main.js: нет проверки res.ok
**Файл:** `main.js:29-30`
500 парсится как JSON, игнорируется.

### 7.22. main.js: пустой catch
**Файл:** `main.js:42`
Все ошибки проглатываются.

### 7.23. main.js: timezone bug в auto-checkout (UTC+12)
**Файл:** `main.js:51-53`
Checkout = checkin в UTC+12.

### 7.24. review.js: очистка неправильного input
**Файл:** `review.js:49`
Очищается `review-hotel-id` вместо имени автора.

### 7.25. review.js: нет rate limiting
**Файл:** `review.js`
Отзывы бесконечно быстро.

### 7.26. compare.js: FIFO-удаление
**Файл:** `compare.js:17`
`items.shift()` удаляет первый добавленный.

### 7.27. compare.js: нет null-проверки внутренних элементов
**Файл:** `compare.js:42`
`compare-count`, `compare-go` — TypeError при изменении HTML.

### 7.28. theme.js: DOMContentLoaded может не сработать
**Файл:** `theme.js:22-31`
Скрипт в конце `<body>` — событие уже сработало. Кнопка не устанавливается.

### 7.29. theme.js: не реагирует на смену темы ОС
**Файл:** `theme.js:7-8`
matchMedia проверяется один раз.

### 7.30. currency.js: захардкоженные курсы
**Файл:** `currency.js:3`
RUB=1, EUR=100, USD=92. Никогда не обновляются.

### 7.31. recent.js: дубли при разных регистрациях
**Файл:** `recent.js:14`
«Сочи» и «сочи» — разные записи.

---

## 8. HTML-шаблоны

### 8.1. Кнопки табов без type="button"
**Файл:** `index.php:91-93`
По умолчанию `type=submit` — клик отправляет форму поиска.

### 8.2. Поля формы бронирования без name
**Файл:** `hotel.php:305-350`
При отключённом JS ничего не отправляется.

### 8.3. Формы без action
**Файлы:** `hotel.php:239,305`, `search.php:38`, `login.php:93,112`, `footer.php:64`
6 форм. При отключённом JS отправляется на текущий URL.

### 8.4. og:image — относительный URL
**Файл:** `index.php:28`
Open Graph требует абсолютный URL.

### 8.5. script до link — render-blocking
**Файлы:** Все 8 страниц
`theme.js` синхронно перед CSS.

### 8.6. Пустой alt у изображений галереи
**Файл:** `hotel.php:154`
Нет описания для скринридеров.

---

## 9. CSS

### 9.1. Неполный dark theme
**Файл:** `assets/css/styles.css`
Не переопределены: `.bg-teal-500`, `.bg-blue-600`, `.bg-rose-50`, `.hover:bg-slate-100`, `.text-amber-400`, `.text-emerald-500`, `.text-red-600`.

### 9.2. Broken focus ring в dark theme
**Файл:** `assets/css/styles.css`
`outline: none` от другого класса → `focus-visible` не восстанавливает видимость.

### 9.3. scroll-behavior: smooth мешает screen readers
**Файл:** `assets/css/styles.css`

### 9.4. Нет prefers-reduced-motion
Все анимации не отключаются. Нарушает WCAG.

### 9.5. Нет @media print
Нет стилей для печати.

### 9.6. Стилизация скроллбара только для Webkit
**Файл:** `assets/css/styles.css`
Firefox/Edge не поддерживаются.

---

## 10. Конфигурация и деплой

### 10.1. Dockerfile: sed заменяет DocumentRoot на себя же
**Файл:** `Dockerfile:18`
`${APACHE_DOCUMENT_ROOT}` = `/var/www/html` → no-op.

### 10.2. Dockerfile: unzip установлен, не используется
**Файл:** `Dockerfile`
Лишний слой (3.2MB).

### 10.3. Dockerfile: COPY . до composer install
**Файл:** `Dockerfile`
Инвалидация кэша при ЛЮБОМ изменении.

### 10.4. Dockerfile: ENV APACHE_DOCUMENT_ROOT не используется
**Файл:** `Dockerfile`
Объявлен, нигде не ссылается.

### 10.5. Dockerfile: контейнер работает от root
**Файл:** `Dockerfile`
Нет `USER www-data`. RCE → root.

### 10.6. render.yaml: нет disk persistence
**Файл:** `render.yaml`
Данные теряются при рестарте.

### 10.7. render.yaml: APP_BASE_URL: sync: false
**Файл:** `render.yaml`
URL не передаётся из env.

### 10.8. render.yaml: нет health check
**Файл:** `render.yaml`
PHP crash → render не перезапускает.

---

## 11. SEO

### 11.1. robots.txt: нет Sitemap
**Файл:** `robots.txt`
Google/Bing не находят sitemap.

### 11.2. robots.txt: разрешены чувствительные страницы
**Файл:** `robots.txt`
`/login.php`, `/favorites.php`, `/booking-confirm.php` в ALLOW.

### 11.3. robots.txt: неправильный синтаксис
**Файл:** `robots.txt`
`/api/*.php` блокирует поддиректории (`/api/v1/`).

### 11.4. robots.txt: несуществующая страница запрещена
**Файл:** `robots.txt`
`/booking-log.php` (нет) запрещена. `/bookings.php` (есть) — нет.

### 11.5. sitemap.php: нет lastmod
**Файл:** `sitemap.php`
Все страницы с одинаковым приоритетом.

### 11.6. sitemap.php: /favorites.php в sitemap
**Файл:** `sitemap.php`
Требует авторизации.

### 11.7. sitemap.php: PHP warning ломает XML
**Файл:** `sitemap.php`
`hotel_url()` undefined → warning → невалидный XML.

### 11.8. sitemap.php: нет кеширования
**Файл:** `sitemap.php`
Генерируется на каждый запрос.

### 11.9. sitemap.php: нет пагинации
**Файл:** `sitemap.php`
>50,000 отелей → превышает лимит Google.

### 11.10. Нет canonical
**Файлы:** Все страницы
Дублирование через query-параметры.

### 11.11. Нет OpenGraph на hotel.php
**Файл:** `hotel.php`

### 11.12. search.php: пустая страница без JS
**Файл:** `search.php:64`
Нет fallback.

---

## 12. Сравнение с эталонным функционалом

### 12.1. Клиентский функционал

| Функция | Статус |
|---------|--------|
| Умная поисковая строка | Частично (только города) |
| Динамический календарь (цены «на лету») | Частично (цены статичны) |
| Многоуровневые фильтры | Хорошо |
| Картография (Leaflet.js) | Реализовано |
| Личный кабинет | Частично (избранное + поиск по ref) |
| Онлайн-оплата | Демо (без шлюзов) |
| Отзывы | Слабо (без верификации) |
| Сравнение отелей | Реализовано |
| Избранное | Реализовано |
| Чат-помощник | Частично (rule-based NLP) |

### 12.2. Партнёрский функционал

| Функция | Статус |
|---------|--------|
| Управление тарифами | Не реализовано |
| Channel Manager | Не реализовано |
| Конструктор объявлений | Не реализовано |
| Модуль коммуникации | Не реализовано |
| Финансовая отчётность | Не реализовано |

### 12.3. Системный функционал

| Функция | Статус |
|---------|--------|
| Уведомления | Не реализовано |
| Программа лояльности | Не реализовано |
| Мультиязычность | Не реализовано |
| Мультивалютность | Частично (захардкоженные курсы) |
| Helpdesk | Частично (чат-бот) |
| Админ-панель | Не реализовано |
| Тёмная тема | Реализовано |
| Адаптивный дизайн | Реализовано |

**Покрытие: ~25% (полностью) + ~29% (частично) = ~54%**

---

## 13. Приоритеты исправления

### P0 — Немедленно (Критические уязвимости)

| # | Баг | Файл |
|---|-----|------|
| 1 | XSS в бронировании | `api/booking.php:31` |
| 2 | XSS через чат-бот (innerHTML) | `chat.js:87` |
| 3 | CORS * + credentials | `api/auth.php:12` |
| 4 | Нет CSRF-защиты | все POST-формы |
| 5 | Session Fixation | `api/auth.php:31,117` |
| 6 | Google OAuth без state | `api/auth.php:58` |
| 7 | Session cookie без secure | `api/config.php:7` |
| 8 | Session cookie не удаляется | `api/config.php:278` |
| 9 | Клиент управляет state machine | `api/chat.php:650` |
| 10 | Booking flow dead-end | `api/chat.php:1897` |
| 11 | levenshtein() → -1 → ложное совпадение | `api/chat.php:63` |
| 12 | $cityStop isset() всегда false | `api/chat.php:543` |
| 13 | hotel.php: file() → array_reverse(false) | `hotel.php:22` |
| 14 | hotel.php: related hotels перезаписывают $hotel | `hotel.php:360` |

### P1 — Скоро (Высокие)

| # | Баг | Файл |
|---|-----|------|
| 15 | TOCTOU race conditions | `api/config.php:246` |
| 16 | save_json() — пустой файл | `api/config.php:72` |
| 17 | load_json() → null с типом array | `api/config.php:66` |
| 18 | load_json() → json_decode(false) | `api/config.php:55` |
| 19 | .env BOM-символ | `api/config.php:20` |
| 20 | normalize_phone() → null | `api/config.php:113` |
| 21 | Rate limiter не атомарный | `api/config.php:175` |
| 22 | Session GC lifetime < cookie | `api/config.php:7` |
| 23 | XSS через JSON-LD | `hotel.php:101` |
| 24 | foreach null amenities | `hotel.php:193` |
| 25 | str_repeat null stars | `hotel.php:148` |
| 26 | number_format null rating | `hotel.php:173` |
| 27 | Theme.js DOMContentLoaded | `theme.js:22` |
| 28 | search.js бесконечный цикл | `search.js:92` |
| 29 | chat.js плюрализм | `chat.js:279` |
| 30 | chat.php промо от оригинальной цены | `chat.php` |
| 31 | chat.php find_hotel_by_name затирает выбор | `chat.php:1963` |
| 32 | chat.php float comparison !== | `chat.php:1025` |

### P2 — Планово (Средние)

| # | Баг | Файл |
|---|-----|------|
| 33 | CSP unsafe-inline | `.htaccess` |
| 34 | Нет HSTS | `.htaccess` |
| 35 | TRACE не запрещён | `.htaccess` |
| 36 | Referrer-Policy same-origin | `.htaccess` |
| 37 | Курсы валют захардкожены | `currency.js:3` |
| 38 | Валидация дат в hotel.js | `hotel.js:54` |
| 39 | window.__hotels мёртвый код | `search.js:302` |
| 40 | review.js очистка поля | `review.js:49` |
| 41 | Cookie избранного без SameSite | `favs.js:12` |
| 42 | Sitemap нет кеша | `sitemap.php` |
| 43 | Debounce слайдера | `search.js:207` |
| 44 | Timezone в main.js | `main.js:51` |
| 45 | chat.js «15-15» | `chat.js:560` |
| 46 | chat.js межгодовые даты | `chat.js:548` |
| 47 | chat.js NaN total | `chat.js:601` |
| 48 | chat.js XSS через h.type | `chat.js:115` |
| 49 | robots.txt нет Sitemap | `robots.txt` |
| 50 | robots.txt ALLOW чувствительных | `robots.txt` |
| 51 | sitemap.php нет lastmod | `sitemap.php` |
| 52 | sitemap.php warning | `sitemap.php` |
| 53 | Dockerfile sed no-op | `Dockerfile:18` |
| 54 | Dockerfile COPY до composer | `Dockerfile` |
| 55 | Dockerfile от root | `Dockerfile` |
| 56 | render.yaml нет disk | `render.yaml` |
| 57 | chat.php стеммы конфликт | `chat.php:326` |
| 58 | chat.php levenshtein байты | `chat.php:63` |
| 59 | chat.php regex reject широкий | `chat.php:696` |
| 60 | chat.php regex жалоба широкий | `chat.php:1195` |
| 61 | chat.php budget per-night | `chat.php:1647` |
| 62 | chat.php str_replace мультибайт | `chat.php` |
| 63 | chat.php regex в цикле | `chat.php:198` |
| 64 | chat.php delition не обрабатывается | `chat.php:1327` |
| 65 | chat.php 32-bit overflow | `chat.php:864` |
| 66 | search.js RangeError | `search.js:93` |
| 67 | search.js state() null | `search.js:36` |
| 68 | search.js render() уничтожает DOM | `search.js:199` |
| 69 | hotel.js deprecated execCommand | `hotel.js:201` |
| 70 | hotel.js отрицательный total | `hotel.js:34` |
| 71 | hotel.js галерея все кнопки | `hotel.js:98` |
| 72 | main.js нет res.ok | `main.js:29` |
| 73 | main.js пустой catch | `main.js:42` |
| 74 | compare.js FIFO | `compare.js:17` |
| 75 | theme.js смена темы ОС | `theme.js:7` |
| 76 | recent.js дубли регистраций | `recent.js:14` |
| 77 | index.php type=submit табы | `index.php:91` |
| 78 | hotel.php поля без name | `hotel.php:305` |
| 79 | og:image относительный | `index.php:28` |
| 80 | CSS prefers-reduced-motion | `styles.css` |
| 81 | CSS prefers-print | `styles.css` |

### P3 — Долгосрочно (Недочёты)

| # | Баг | Файл |
|---|-----|------|
| 82 | esc() дублирование ×3 | chat/favs/hotel.js |
| 83 | fmtPrice() дублирование ×2 | chat/hotel.js |
| 84 | 3 стратегии форматирования цен | chat/hotel/main.js |
| 85 | localStorage ключи не координированы | все JS |
| 86 | Глобальные мутабельные объекты | currency/favs/compare.js |
| 87 | Опечатка услоб\w* | `chat.php:1567` |
| 88 | Грамматика «год» → «года» | `chat.php:1923` |
| 89 | «Недорого» перезаписывает бюджет | `chat.php:897` |
| 90 | Ввод <6 символов отброшен | `chat.php:1674` |
| 91 | «Я Хочу» → имя «Хочу» | `chat.php:163` |
| 92 | Regex имени отеля лишние слова | `chat.php:1489` |
| 93 | $countryPrepos дважды | `chat.php:1291,1695` |
| 94 | Labels удобств 3 раза | `chat.php:1064,1789,2060` |
| 95 | «Ещё» где угодно | `chat.php:1035` |
| 96 | «Та/тот» любое существительное | `chat.php:1728` |
| 97 | «2» где угодно | `chat.php:2047` |
| 98 | Дублированный regex ваучер | `chat.php:1577` |
| 99 | $minCtx = max | `chat.php:1043` |
| 100 | $pastDateMsg перезапись | `chat.php:1933` |
| 101 | $matchedCity дубль | `chat.php:423,506` |
| 102 | Отрицательное кол-во ночей | `chat.php:1514` |
| 103 | Бюджет «8» → 8000 | `chat.php:879` |
| 104 | chat.js typing + ответ | `chat.js:689` |
| 105 | chat.js flow без очистки | `chat.js:606` |
| 106 | chat.js submit без блокировки | `chat.js:584` |
| 107 | chat.js regex диапазон | `chat.js:537` |
| 108 | chat.js sessionStorage | `chat.js:29` |
| 109 | hotel.js gallery pause | `hotel.js:115` |
| 110 | review.js rate limiting | `review.js` |
| 111 | compare.js null-check | `compare.js:42` |
| 112 | CSS scroll-behavior smooth | `styles.css` |
| 113 | CSS Webkit scrollbar only | `styles.css` |
| 114 | CSS broken focus ring | `styles.css` |
| 115 | Dockerfile unzip unused | `Dockerfile` |
| 116 | Dockerfile ENV unused | `Dockerfile` |
| 117 | render.yaml sync: false | `render.yaml` |
| 118 | render.yaml нет health check | `render.yaml` |
| 119 | robots.txt /api/*.php | `robots.txt` |
| 120 | robots.txt /booking-log.php | `robots.txt` |
| 121 | sitemap.php нет пагинации | `sitemap.php` |
| 122 | Нет canonical | все страницы |
| 123 | Нет OpenGraph на hotel.php | `hotel.php` |
| 124 | search.php нет fallback | `search.php:64` |
| 125 | Пустой alt галереи | `hotel.php:154` |
| 126 | Object.freeze для globals | все JS |
| 127 | Пагинация в search | search.js |
| 128 | prefers-color-scheme listener | theme.js |
| 129 | Личный кабинет | — |
| 130 | Экстранет | — |
| 131 | Уведомления | — |
| 132 | Лояльность | — |
| 133 | Мультиязычность | — |
| 134 | Админ-панель | — |
