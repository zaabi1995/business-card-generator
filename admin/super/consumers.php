<?php
/**
 * Super Admin - Consumers
 *
 * The public side of cardify.om in one place: IQ test accounts, results and
 * payments, and logo download members and passes. Also the IQ prices, which
 * before this page could only be changed in the database (iq_settings).
 * Added 5 Oct 2026 with the one sign-in.
 */
require_once __DIR__ . '/../../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/admin-layout.php';
require_once INCLUDES_DIR . '/iq/IqStore.php';

Auth::requireRole('super_admin');

$db = Database::getInstance();
$flash = null;
$priceKeys = [
    'price_report'      => 'IQ full report',
    'price_certificate' => 'IQ certificate',
    'price_pro_month'   => 'IQ Pro, per month',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'prices') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { die('Invalid request'); }
    $changed = [];
    foreach ($priceKeys as $key => $label) {
        $raw = trim((string) ($_POST[$key] ?? ''));
        // Empty = not on sale ("Coming soon"). Otherwise OMR, 3 decimals.
        if ($raw !== '' && (!is_numeric($raw) || (float) $raw <= 0 || (float) $raw > 999)) {
            $flash = ['bad', $label . ': enter a price in OMR, or leave it empty to take it off sale.'];
            break;
        }
        $value = $raw === '' ? '' : number_format((float) $raw, 3, '.', '');
        if ($value !== (string) (IqStore::setting($key) ?? '')) {
            IqStore::setSetting($key, $value);
            $changed[$key] = $value;
        }
    }
    if (!$flash) {
        if ($changed && class_exists('AuditLog')) {
            AuditLog::log('iq_prices_updated', 'iq_settings', 'prices', null, $changed);
        }
        $flash = ['ok', $changed ? 'Prices saved.' : 'No change.'];
    }
}

$safe = static function (callable $fn, $default) {
    try { return $fn(); } catch (Throwable $e) { error_log('[super/consumers] ' . $e->getMessage()); return $default; }
};
$one = static fn(string $sql) => $safe(fn() => $db->fetchOne($sql) ?: [], []);

$iq = $one("SELECT
        (SELECT COUNT(*) FROM iq_users) AS users,
        (SELECT COUNT(*) FROM iq_users WHERE pro_until > NOW()) AS pro_active,
        (SELECT COUNT(*) FROM iq_attempts WHERE status = 'done') AS tests_done,
        (SELECT COUNT(*) FROM iq_attempts WHERE cert_no IS NOT NULL) AS certificates,
        (SELECT COALESCE(SUM(amount), 0) FROM iq_payments WHERE status = 'paid') AS revenue");
$logo = $one("SELECT
        (SELECT COUNT(*) FROM logo_members) AS members,
        (SELECT COUNT(*) FROM logo_members WHERE paid_until > UTC_TIMESTAMP()) AS passes_active,
        (SELECT COALESCE(SUM(amount), 0) FROM logo_pass_orders WHERE status = 'paid') AS revenue");

$iqPayments = $safe(fn() => $db->fetchAll(
    "SELECT p.created_at, p.paid_at, p.product, p.amount, p.status, u.email, u.phone, u.display_name
       FROM iq_payments p LEFT JOIN iq_users u ON u.id = p.user_id
      ORDER BY p.created_at DESC LIMIT 25"), []);
$logoOrders = $safe(fn() => $db->fetchAll(
    "SELECT o.created_at, o.amount, o.days, o.status, m.email, m.name
       FROM logo_pass_orders o LEFT JOIN logo_members m ON m.id = o.member_id
      ORDER BY o.created_at DESC LIMIT 25"), []);
$iqUsers = $safe(fn() => $db->fetchAll(
    "SELECT u.created_at, u.last_login_at, u.email, u.phone, u.display_name, u.pro_until,
            (SELECT COUNT(*) FROM iq_attempts a WHERE a.user_id = u.id AND a.status = 'done') AS tests
       FROM iq_users u ORDER BY COALESCE(u.last_login_at, u.created_at) DESC LIMIT 25"), []);

$e = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$omr = static fn($v) => 'OMR ' . number_format((float) $v, 3);
$who = static fn(array $r) => $r['email'] ?: ($r['phone'] ?: '(no contact)');
$statusClass = static fn(string $s) => [
    'paid' => 'bg-green-50 text-green-700', 'pending' => 'bg-amber-50 text-amber-700',
    'refunded' => 'bg-gray-100 text-gray-600', 'failed' => 'bg-red-50 text-red-700',
][$s] ?? 'bg-gray-100 text-gray-600';

adminHeader('Consumers', 'consumers');
?>
<div class="space-y-8">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">Consumers</h1>
        <p class="text-sm text-gray-500 mt-1">IQ test and logo downloads. Both use the one cardify.om sign-in.</p>
    </div>

    <?php if ($flash): ?>
    <div class="rounded-lg px-4 py-3 text-sm <?= $flash[0] === 'ok' ? 'bg-green-50 text-green-800' : 'bg-red-50 text-red-800' ?>" role="alert"><?= $e($flash[1]) ?></div>
    <?php endif; ?>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <?php foreach ([
            ['IQ accounts', number_format((int) ($iq['users'] ?? 0))],
            ['IQ tests finished', number_format((int) ($iq['tests_done'] ?? 0))],
            ['IQ Pro active', number_format((int) ($iq['pro_active'] ?? 0))],
            ['IQ revenue', $omr($iq['revenue'] ?? 0)],
            ['Certificates issued', number_format((int) ($iq['certificates'] ?? 0))],
            ['Logo members', number_format((int) ($logo['members'] ?? 0))],
            ['Logo passes active', number_format((int) ($logo['passes_active'] ?? 0))],
            ['Logo pass revenue', $omr($logo['revenue'] ?? 0)],
        ] as [$label, $value]): ?>
        <div class="bg-white rounded-xl border border-gray-200 p-4">
            <p class="text-xs font-medium text-gray-500"><?= $e($label) ?></p>
            <p class="mt-1 text-xl font-bold text-gray-900"><?= $e($value) ?></p>
        </div>
        <?php endforeach; ?>
    </div>

    <section class="bg-white rounded-xl border border-gray-200 p-6">
        <h2 class="text-lg font-semibold text-gray-900">IQ prices</h2>
        <p class="text-sm text-gray-500 mt-1">OMR, 3 decimals. Leave a price empty to show it as "Coming soon". Logo pass price is set in the server config.</p>
        <form method="POST" class="mt-4 grid sm:grid-cols-3 gap-4 items-end">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="prices">
            <?php foreach ($priceKeys as $key => $label): ?>
            <label class="block">
                <span class="text-sm font-medium text-gray-700"><?= $e($label) ?></span>
                <input type="text" inputmode="decimal" name="<?= $e($key) ?>" value="<?= $e(IqStore::setting($key) ?? '') ?>" placeholder="Not on sale" dir="ltr"
                       class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
            </label>
            <?php endforeach; ?>
            <div class="sm:col-span-3">
                <button type="submit" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">Save prices</button>
                <span class="ml-3 text-xs text-gray-500">Logo pass: <?= $e(defined('LOGO_PASS_PRICE_OMR') ? $omr(LOGO_PASS_PRICE_OMR) : $omr(2)) ?> for <?= (int) (defined('LOGO_PASS_DAYS') ? LOGO_PASS_DAYS : 30) ?> days</span>
            </div>
        </form>
    </section>

    <?php
    $table = static function (string $title, array $head, array $rows, callable $cells) use ($e) { ?>
    <section class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <h2 class="text-lg font-semibold text-gray-900 px-6 pt-5 pb-3"><?= $e($title) ?></h2>
        <?php if (!$rows): ?>
            <p class="px-6 pb-6 text-sm text-gray-500">Nothing yet.</p>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-left text-xs font-semibold text-gray-500 uppercase">
                    <tr><?php foreach ($head as $h): ?><th class="px-6 py-2 whitespace-nowrap"><?= $e($h) ?></th><?php endforeach; ?></tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php foreach ($rows as $r): ?><tr><?php foreach ($cells($r) as $c): ?><td class="px-6 py-2 whitespace-nowrap"><?= $c ?></td><?php endforeach; ?></tr><?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </section>
    <?php };

    $table('IQ payments (latest 25)', ['Created', 'Customer', 'Product', 'Amount', 'Status'], $iqPayments, fn($r) => [
        $e($r['created_at']), '<span dir="ltr">' . $e($who($r)) . '</span>',
        $e(['report' => 'Full report', 'certificate' => 'Certificate', 'pro_month' => 'IQ Pro, month'][$r['product']] ?? $r['product']),
        $e($omr($r['amount'])), '<span class="rounded-full px-2 py-0.5 text-xs font-semibold ' . $statusClass((string) $r['status']) . '">' . $e($r['status']) . '</span>',
    ]);
    $table('Logo pass orders (latest 25)', ['Created', 'Member', 'Days', 'Amount', 'Status'], $logoOrders, fn($r) => [
        $e($r['created_at']), '<span dir="ltr">' . $e($r['email'] ?: '(deleted member)') . '</span>', (int) $r['days'],
        $e($omr($r['amount'])), '<span class="rounded-full px-2 py-0.5 text-xs font-semibold ' . $statusClass((string) $r['status']) . '">' . $e($r['status']) . '</span>',
    ]);
    $table('IQ accounts (latest 25 active)', ['Last sign-in', 'Contact', 'Name', 'Tests', 'Pro until'], $iqUsers, fn($r) => [
        $e($r['last_login_at'] ?: $r['created_at']), '<span dir="ltr">' . $e($who($r)) . '</span>', $e($r['display_name']),
        (int) $r['tests'], $e($r['pro_until'] && strtotime((string) $r['pro_until']) > time() ? $r['pro_until'] : '-'),
    ]);
    ?>
</div>
<?php adminFooter(); ?>
