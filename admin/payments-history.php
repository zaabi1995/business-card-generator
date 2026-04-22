<?php
/**
 * Company admin payment history (Cat S action 448).
 *
 * All payment activity for the current company: subscriptions, print
 * orders, and card-credit top-ups. Filters by type, status and year.
 * Shows Paymob transaction ID + payment method so accounts can
 * reconcile against the bank statement without leaving Cardify.
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/admin-layout.php';

requireAdmin();
$companyId = getCurrentCompanyId();
if (!$companyId) { header('Location: ' . getBasePath() . 'login.php'); exit; }

$db = Database::getInstance();

$type   = $_GET['type']   ?? 'all';
$status = $_GET['status'] ?? 'all';
$year   = (int) ($_GET['year'] ?? date('Y'));
if ($year < 2020 || $year > 2100) $year = (int) date('Y');

$where  = ['company_id = :cid', 'YEAR(created_at) = :year'];
$params = ['cid' => $companyId, 'year' => $year];
if (in_array($type, ['subscription', 'print_order', 'card_order'], true)) {
    $where[] = 'type = :type';
    $params['type'] = $type;
}
if (in_array($status, ['pending', 'paid', 'failed', 'refunded'], true)) {
    $where[] = 'status = :status';
    $params['status'] = $status;
}

$rows = $db->fetchAll(
    "SELECT id, type, billing_cycle, reference_id, amount, currency, payment_method, status,
            paymob_transaction_id, special_reference, created_at, updated_at
       FROM payments
      WHERE " . implode(' AND ', $where) . "
      ORDER BY created_at DESC",
    $params
);

$years = $db->fetchAll(
    "SELECT DISTINCT YEAR(created_at) AS y FROM payments WHERE company_id = :cid ORDER BY y DESC",
    ['cid' => $companyId]
);

// Summary totals (paid rows only)
$totalPaid = 0.0;
$counts    = ['paid' => 0, 'pending' => 0, 'failed' => 0, 'refunded' => 0];
foreach ($rows as $r) {
    if ($r['status'] === 'paid') $totalPaid += (float) $r['amount'];
    if (isset($counts[$r['status']])) $counts[$r['status']]++;
}

adminHeader(t('payments.page_title'), 'billing');

$basePath = getAdminBasePath();
$isCompanyAdmin = defined('COMPANY_ADMIN_BASE') || !empty($_SESSION['company_slug']);
$ext = $isCompanyAdmin ? '' : '.php';

$typeLabels = [
    'all'          => t('payments.type_all'),
    'subscription' => t('payments.type_subscription'),
    'print_order'  => t('payments.type_print_order'),
    'card_order'   => t('payments.type_card_order'),
];
$statusLabels = [
    'all'      => t('payments.status_all'),
    'paid'     => t('payments.status_paid'),
    'pending'  => t('payments.status_pending'),
    'failed'   => t('payments.status_failed'),
    'refunded' => t('payments.status_refunded'),
];
$statusPill = [
    'paid'     => 'bg-green-50 text-green-700',
    'pending'  => 'bg-amber-50 text-amber-700',
    'failed'   => 'bg-red-50 text-red-700',
    'refunded' => 'bg-gray-100 text-gray-700',
];
?>
<div class="max-w-6xl mx-auto px-4 py-6">
    <header class="mb-6 flex items-end justify-between flex-wrap gap-3">
        <div>
            <h1 class="text-2xl font-bold text-gray-900"><?= htmlspecialchars(t('payments.page_title')) ?></h1>
            <p class="text-gray-600 mt-1 text-sm"><?= htmlspecialchars(t('payments.page_sub')) ?></p>
        </div>
        <form method="GET" class="flex items-center gap-2 flex-wrap">
            <?php if ($isCompanyAdmin): ?>
                <input type="hidden" name="page" value="payments-history">
            <?php endif; ?>
            <select name="type" class="px-3 py-2 rounded-lg border border-gray-300 text-sm">
                <?php foreach ($typeLabels as $k => $label): ?>
                    <option value="<?= $k ?>" <?= $type === $k ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="status" class="px-3 py-2 rounded-lg border border-gray-300 text-sm">
                <?php foreach ($statusLabels as $k => $label): ?>
                    <option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="year" class="px-3 py-2 rounded-lg border border-gray-300 text-sm">
                <?php $haveYear = false; foreach ($years as $y): $haveYear = $haveYear || (int) $y['y'] === $year; ?>
                    <option value="<?= (int) $y['y'] ?>" <?= (int) $y['y'] === $year ? 'selected' : '' ?>><?= (int) $y['y'] ?></option>
                <?php endforeach; ?>
                <?php if (!$haveYear): ?>
                    <option value="<?= $year ?>" selected><?= $year ?></option>
                <?php endif; ?>
            </select>
            <button type="submit" class="px-3 py-2 rounded-lg bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold">
                <i class="fa-solid fa-filter"></i>
            </button>
        </form>
    </header>

    <div class="grid sm:grid-cols-4 gap-4 mb-6">
        <div class="bg-white rounded-2xl border border-gray-200 p-5">
            <div class="text-xs uppercase tracking-wide text-gray-500 mb-1"><?= htmlspecialchars(t('payments.summary_paid')) ?></div>
            <div class="text-2xl font-bold text-gray-900"><?= number_format($totalPaid, 3) ?> <span class="text-sm font-medium text-gray-500">OMR</span></div>
            <div class="text-xs text-gray-400 mt-0.5"><?= $counts['paid'] ?> <?= htmlspecialchars(t('payments.status_paid')) ?></div>
        </div>
        <?php foreach (['pending', 'failed', 'refunded'] as $s): ?>
        <div class="bg-white rounded-2xl border border-gray-200 p-5">
            <div class="text-xs uppercase tracking-wide text-gray-500 mb-1"><?= htmlspecialchars($statusLabels[$s]) ?></div>
            <div class="text-2xl font-bold text-gray-900"><?= $counts[$s] ?></div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
        <?php if (empty($rows)): ?>
            <div class="p-12 text-center text-gray-500">
                <i class="fa-regular fa-credit-card text-4xl text-gray-300 mb-3"></i>
                <p><?= htmlspecialchars(t('payments.empty')) ?></p>
            </div>
        <?php else: ?>
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                <tr>
                    <th class="px-4 py-3 text-left"><?= htmlspecialchars(t('payments.col_date')) ?></th>
                    <th class="px-4 py-3 text-left"><?= htmlspecialchars(t('payments.col_type')) ?></th>
                    <th class="px-4 py-3 text-left"><?= htmlspecialchars(t('payments.col_reference')) ?></th>
                    <th class="px-4 py-3 text-left"><?= htmlspecialchars(t('payments.col_method')) ?></th>
                    <th class="px-4 py-3 text-right"><?= htmlspecialchars(t('payments.col_amount')) ?></th>
                    <th class="px-4 py-3"><?= htmlspecialchars(t('payments.col_status')) ?></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
            <?php foreach ($rows as $r):
                $typeLabel = $typeLabels[$r['type']] ?? $r['type'];
                $paymentMethod = $r['payment_method']
                    ?: ($r['paymob_transaction_id'] ? 'Paymob' : '—');
                $ref = $r['type'] === 'subscription'
                    ? strtoupper((string) ($r['billing_cycle'] ?? '')) . ' · ' . ($r['special_reference'] ?: $r['reference_id'])
                    : ($r['special_reference'] ?: $r['reference_id']);
                $pill = $statusPill[$r['status']] ?? 'bg-gray-100 text-gray-700';
            ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 text-gray-700"><?= htmlspecialchars(I18n::formatDate(strtotime($r['created_at']))) ?></td>
                    <td class="px-4 py-3 text-gray-900"><?= htmlspecialchars($typeLabel) ?></td>
                    <td class="px-4 py-3 font-mono text-xs text-gray-600" title="<?= htmlspecialchars($r['reference_id']) ?>"><?= htmlspecialchars(mb_substr((string) $ref, 0, 48)) ?></td>
                    <td class="px-4 py-3 text-gray-700"><?= htmlspecialchars(ucwords(str_replace('_', ' ', (string) $paymentMethod))) ?></td>
                    <td class="px-4 py-3 text-right font-mono font-semibold text-gray-900"><?= number_format((float) $r['amount'], 3) ?> <span class="text-xs text-gray-500"><?= htmlspecialchars($r['currency'] ?? 'OMR') ?></span></td>
                    <td class="px-4 py-3">
                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold <?= $pill ?>">
                            <?= htmlspecialchars($statusLabels[$r['status']] ?? $r['status']) ?>
                        </span>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php adminFooter(); ?>
