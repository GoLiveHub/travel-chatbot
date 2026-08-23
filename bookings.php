<?php
require __DIR__ . '/api/config.php';

$ref = mb_strtoupper(trim((string) ($_POST['ref'] ?? '')));
$phone = trim((string) ($_POST['phone'] ?? ''));
$entry = null;
$searched = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
if ($searched && preg_match('/^TRV-[A-F0-9]{6,8}$/', $ref)) {
    $entry = find_booking($ref, null, $phone);
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Мои бронирования — Travel.ru</title>
    <meta name="description" content="Безопасный поиск и управление бронированием.">
    <script src="/assets/js/theme.js"></script>
    <link rel="stylesheet" href="/assets/css/tailwind.min.css">
    <link rel="stylesheet" href="/assets/css/styles.css">
    <link rel="icon" type="image/svg+xml" href="/assets/img/favicon.svg">
</head>
<body class="min-h-screen bg-slate-50 text-slate-900">
<?php include __DIR__ . '/components/header.php'; ?>

<main class="mx-auto max-w-3xl px-4 py-10 sm:px-6">
    <div class="mb-7">
        <p class="text-sm font-bold uppercase tracking-wider text-teal-600">Управление поездкой</p>
        <h1 class="mt-1 text-3xl font-extrabold text-slate-900">Найти мою бронь</h1>
        <p class="mt-2 text-slate-500">Введите номер заявки и тот же телефон, который использовали при оформлении.</p>
    </div>

    <form method="post" class="grid gap-4 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:grid-cols-[1fr_1fr_auto] sm:items-end">
        <div>
            <label for="booking-ref" class="mb-1 block text-sm font-semibold text-slate-700">Номер заявки</label>
            <input id="booking-ref" name="ref" value="<?= htmlspecialchars($ref) ?>" required maxlength="12" placeholder="TRV-12AB34CD" class="w-full rounded-xl border border-slate-300 px-4 py-3 font-mono uppercase focus:border-teal-500 focus:outline-none">
        </div>
        <div>
            <label for="lookup-phone" class="mb-1 block text-sm font-semibold text-slate-700">Телефон</label>
            <input id="lookup-phone" name="phone" value="<?= htmlspecialchars($phone) ?>" required type="tel" autocomplete="tel" placeholder="+7 900 000-00-00" class="w-full rounded-xl border border-slate-300 px-4 py-3 focus:border-teal-500 focus:outline-none">
        </div>
        <button class="rounded-xl bg-gradient-to-r from-blue-600 to-teal-500 px-6 py-3 font-bold text-white transition hover:shadow-lg">Найти</button>
    </form>

    <?php if ($searched && !$entry): ?>
        <div class="mt-5 rounded-2xl border border-rose-200 bg-rose-50 p-5 text-sm text-rose-700" role="alert">
            Бронь не найдена. Проверьте номер заявки и телефон — они должны совпадать с данными при оформлении.
        </div>
    <?php elseif ($entry): ?>
        <?php $cancelled = ($entry['status'] ?? 'confirmed') === 'cancelled'; ?>
        <section class="mt-6 overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
            <div class="<?= $cancelled ? 'bg-slate-600' : 'bg-gradient-to-r from-emerald-500 to-teal-500' ?> px-6 py-6 text-white">
                <p class="text-sm text-white/80">Заявка <?= htmlspecialchars($entry['ref']) ?></p>
                <h2 class="mt-1 text-2xl font-extrabold"><?= $cancelled ? 'Бронирование отменено' : 'Бронирование подтверждено' ?></h2>
            </div>
            <div class="p-6">
                <div class="flex flex-wrap justify-between gap-4">
                    <div>
                        <h3 class="text-xl font-extrabold text-slate-900"><?= htmlspecialchars($entry['hotel'] ?? '') ?></h3>
                        <p class="text-slate-500"><?= htmlspecialchars($entry['city'] ?? '') ?></p>
                    </div>
                    <div class="text-right">
                        <div class="text-2xl font-extrabold text-teal-600"><?= number_format((int) ($entry['total'] ?? 0), 0, '', ' ') ?> ₽</div>
                        <div class="text-xs text-slate-400"><?= (int) ($entry['nights'] ?? 0) ?> ноч. · <?= (int) ($entry['guests'] ?? 1) ?> гост.</div>
                    </div>
                </div>
                <dl class="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <div class="rounded-xl bg-slate-50 p-3"><dt class="text-xs text-slate-400">Заезд</dt><dd class="font-semibold text-slate-800"><?= htmlspecialchars($entry['checkin'] ?? '') ?></dd></div>
                    <div class="rounded-xl bg-slate-50 p-3"><dt class="text-xs text-slate-400">Выезд</dt><dd class="font-semibold text-slate-800"><?= htmlspecialchars($entry['checkout'] ?? '') ?></dd></div>
                    <div class="rounded-xl bg-slate-50 p-3"><dt class="text-xs text-slate-400">Гость</dt><dd class="font-semibold text-slate-800"><?= htmlspecialchars($entry['name'] ?? '') ?></dd></div>
                    <div class="rounded-xl bg-slate-50 p-3"><dt class="text-xs text-slate-400">Статус</dt><dd class="font-semibold <?= $cancelled ? 'text-slate-500' : 'text-emerald-600' ?>"><?= $cancelled ? 'Отменена' : 'Подтверждена' ?></dd></div>
                </dl>
                <div class="mt-6 flex flex-wrap gap-3">
                    <a href="/hotel.php?id=<?= (int) ($entry['hotel_id'] ?? 1) ?>" class="rounded-full border border-slate-300 px-6 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">Страница отеля</a>
                    <?php if (!$cancelled && !empty($entry['access_token'])): ?>
                        <a href="/booking-confirm.php?ref=<?= rawurlencode($entry['ref']) ?>&amp;token=<?= rawurlencode((string) $entry['access_token']) ?>" class="rounded-full bg-gradient-to-r from-blue-600 to-teal-500 px-6 py-2.5 text-sm font-semibold text-white hover:shadow-lg">Открыть и управлять</a>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    <?php endif; ?>
</main>

<?php include __DIR__ . '/components/footer.php'; ?>
<script src="/assets/js/favs.js"></script>
<script src="/assets/js/currency.js"></script>
<script src="/assets/js/compare.js"></script>
<script src="/assets/js/chat.js"></script>
</body>
</html>
