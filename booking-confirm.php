<?php
// Страница подтверждения бронирования
require __DIR__ . '/api/config.php';

// Запрещаем Referer чтобы токен не утек в URL внешних сайтов
header('Referrer-Policy: no-referrer');

$ref = trim((string) ($_GET['ref'] ?? ''));
$token = trim((string) ($_GET['token'] ?? ''));
$entry = $ref !== '' && $token !== '' ? find_booking($ref, $token, null) : null;
$cancelled = $entry && ($entry['status'] ?? 'confirmed') === 'cancelled';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $entry ? 'Бронирование ' . htmlspecialchars($ref) : 'Заявка не найдена' ?> — Travel.ru</title>
    <meta name="description" content="Подтверждение бронирования отеля.">
        <script src="/assets/js/theme.js"></script>
<link rel="stylesheet" href="/assets/css/tailwind.min.css">
    <link rel="stylesheet" href="/assets/css/styles.css">
    <link rel="icon" type="image/svg+xml" href="/assets/img/favicon.svg">
</head>
<body class="min-h-screen bg-slate-50 text-slate-900">

<?php include __DIR__ . '/components/header.php'; ?>

<main class="mx-auto max-w-2xl px-4 py-12 sm:px-6">
    <?php if (!$entry): ?>
        <div class="rounded-2xl border border-dashed border-slate-300 bg-white py-16 text-center">
            <div class="flex justify-center text-slate-300"><svg class="h-16 w-16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"></circle><line x1="16.5" y1="16.5" x2="21" y2="21"></line></svg></div>
            <h1 class="mt-4 text-2xl font-extrabold text-slate-900">Заявка не найдена</h1>
            <p class="mt-2 text-slate-500">Проверьте номер бронирования или оформите новую заявку.</p>
            <a href="/search.php" class="mt-6 inline-block rounded-full bg-gradient-to-r from-blue-600 to-teal-500 px-8 py-3 font-semibold text-white transition hover:shadow-lg">Подобрать отель</a>
        </div>
    <?php else: ?>
        <div class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
            <div class="<?= $cancelled ? 'bg-slate-600' : 'bg-gradient-to-r from-emerald-500 to-teal-500' ?> px-6 py-8 text-center text-white">
                <div class="mx-auto grid h-16 w-16 place-items-center rounded-full bg-white/20 text-3xl"><?= $cancelled ? '×' : '✓' ?></div>
                <h1 class="mt-4 text-2xl font-extrabold"><?= $cancelled ? 'Бронирование отменено' : 'Бронирование подтверждено!' ?></h1>
                <p class="mt-1 text-white/80">Заявка № <?= htmlspecialchars($entry['ref']) ?></p>
            </div>

            <div class="p-6">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h2 class="text-xl font-extrabold text-slate-900"><?= htmlspecialchars($entry['hotel']) ?></h2>
                        <p class="text-slate-500"><?= htmlspecialchars($entry['city']) ?></p>
                    </div>
                    <div class="text-right">
                        <?php if (!empty($entry['discount'])): ?>
                            <div class="text-xs text-slate-400 line-through"><?= number_format((int) ($entry['total_original'] ?? $entry['total']), 0, '', ' ') ?> ₽</div>
                            <div class="text-2xl font-extrabold text-teal-600"><?= number_format((int) $entry['total'], 0, '', ' ') ?> ₽</div>
                            <div class="text-xs font-semibold text-emerald-600">Промокод <?= htmlspecialchars($entry['promo']) ?> · −<?= number_format((int) $entry['discount'], 0, '', ' ') ?> ₽</div>
                        <?php else: ?>
                            <div class="text-2xl font-extrabold text-teal-600"><?= number_format((int) $entry['total'], 0, '', ' ') ?> ₽</div>
                        <?php endif; ?>
                        <div class="text-xs text-slate-400"><?= (int) $entry['nights'] ?> ноч. · <?= number_format((int) $entry['price_per_night'], 0, '', ' ') ?> ₽/ночь</div>
                    </div>
                </div>

                <div class="mt-6 grid grid-cols-2 gap-4 sm:grid-cols-4">
                    <div class="rounded-xl bg-slate-50 p-3">
                        <div class="text-xs text-slate-400">Заезд</div>
                        <div class="mt-0.5 font-semibold text-slate-800"><?= htmlspecialchars($entry['checkin']) ?></div>
                    </div>
                    <div class="rounded-xl bg-slate-50 p-3">
                        <div class="text-xs text-slate-400">Выезд</div>
                        <div class="mt-0.5 font-semibold text-slate-800"><?= htmlspecialchars($entry['checkout']) ?></div>
                    </div>
                    <div class="rounded-xl bg-slate-50 p-3">
                        <div class="text-xs text-slate-400">Гостей</div>
                        <div class="mt-0.5 font-semibold text-slate-800"><?= (int) $entry['guests'] ?></div>
                    </div>
                    <div class="rounded-xl bg-slate-50 p-3">
                        <div class="text-xs text-slate-400">Оформлено</div>
                        <div class="mt-0.5 font-semibold text-slate-800"><?= htmlspecialchars(($entry['ts'] ?? '') ?: date('Y-m-d H:i')) ?></div>
                    </div>
                </div>

                <div class="mt-6 rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-600">
                    <p class="font-semibold text-slate-700">Что дальше?</p>
                    <ul class="mt-2 list-inside list-disc space-y-1 text-slate-500">
                        <li><?= $cancelled ? 'Заявка закрыта, оплата не требуется.' : 'Подтверждение доступно по этой защищённой ссылке.' ?></li>
                        <li>Бесплатная онлайн-отмена доступна не позднее чем за 48 часов до заезда.</li>
                        <li>Это демонстрационный сервис: реальные списания не выполняются.</li>
                    </ul>
                </div>

                <div class="mt-6 flex flex-wrap gap-3">
                    <a href="/hotel.php?id=<?= (int) ($entry['hotel_id'] ?? 1) ?>" class="rounded-full border border-slate-300 bg-white px-6 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">К отелю</a>
                    <a href="/bookings.php" class="rounded-full border border-slate-300 bg-white px-6 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">Мои бронирования</a>
                    <?php if (!$cancelled): ?>
                        <button id="cancel-booking" type="button" class="rounded-full border border-rose-300 bg-white px-6 py-2.5 text-sm font-semibold text-rose-600 transition hover:bg-rose-50">Отменить бронь</button>
                    <?php endif; ?>
                    <a href="/search.php" class="rounded-full bg-gradient-to-r from-blue-600 to-teal-500 px-6 py-2.5 text-sm font-semibold text-white transition hover:shadow-lg">Найти ещё отель</a>
                </div>
            </div>
        </div>
    <?php endif; ?>
</main>

<?php include __DIR__ . '/components/footer.php'; ?>

<script src="/assets/js/favs.js"></script>
<script src="/assets/js/currency.js"></script>
<script src="/assets/js/compare.js"></script>
<script src="/assets/js/chat.js"></script>
<?php if ($entry && !$cancelled): ?>
<script>
document.getElementById('cancel-booking')?.addEventListener('click', async function () {
    if (!confirm('Точно отменить бронирование? Это действие нельзя отменить.')) return;
    this.disabled = true;
    this.textContent = 'Отменяем…';
    try {
        const response = await fetch('/api/cancel-booking.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ref: <?= json_encode($ref) ?>, token: <?= json_encode($token) ?>})
        });
        const data = await response.json();
        if (!data.ok) throw new Error(data.error || 'Не удалось отменить бронь');
        location.reload();
    } catch (error) {
        alert(error.message || 'Не удалось отменить бронь');
        this.disabled = false;
        this.textContent = 'Отменить бронь';
    }
});
</script>
<?php endif; ?>
</body>
</html>
