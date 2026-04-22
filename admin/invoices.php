<?php
/**
 * Company admin invoice list (Cat S action 447).
 *
 * Shows every paid print order as an invoice row: order number, date,
 * ERP invoice number, amount (incl. VAT), VAT slice, download link.
 * Download opens the print-friendly bilingual receipt from
 * /admin/order-receipt.php — users "save as PDF" from the browser
 * print dialog, matching the existing receipt flow.
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/Tax.php';
require_once INCLUDES_DIR . '/admin-layout.php';

requireAdmin();
$companyId = getCurrentCompanyId();
if (!$companyId) { header('Location: ' . getBasePath() . 'login.php'); exit; }

$db = Database::getInstance();

// Optional query-string filters
$year  = (int) ($_GET['year'] ?? date('Y'));
if ($year < 2020 || $year > 2100) $year = (int) date('Y');
$q     = trim((string) ($_GET['q'] ?? ''));

$where  = ['po.company_id = :cid', 'po.payment_status = :status', 'YEAR(COALESCE(po.balance_paid_at, po.deposit_paid_at, po.updated_at)) = :year'];
$params = ['cid' => $companyId, 'status' => 'paid', 'year' => $year];
if ($q !== '') {
    $where[] = '(po.order_number LIKE :q OR po.erp_invoice_number LIKE :q)';
    $params['q'] = "%$q%";
}
$sql = "SELECT po.id, po.order_number, po.total, po.tax_amount, po.subtotal_excl_vat,
               po.erp_invoice_number, po.payment_method, po.currency,
               COALESCE(po.balance_paid_at, po.deposit_paid_at, po.updated_at) AS paid_at
          FROM print_orders po
         WHERE " . implode(' AND ', $where) . "
         ORDER BY paid_at DESC";
$orders = $db->fetchAll($sql, $params);

// Distinct years for the dropdown
$years = $db->fetchAll(
    "SELECT DISTINCT YEAR(COALESCE(balance_paid_at, deposit_paid_at, updated_at)) AS y
       FROM print_orders WHERE company_id = :cid AND payment_status = 'paid'
       ORDER BY y DESC",
    ['cid' => $companyId]
);

// Totals for the summary strip
$totalOmr = array_sum(array_map(static fn($o) => (float) ($o['total'] ?? 0), $orders));
$totalVat = 0.0;
foreach ($orders as $o) {
    $b = Tax::breakdownFromOrder($o);
    if ($b) { $totalVat += $b['tax']; }
    elseif ((float) ($o['total'] ?? 0) > 0) { $totalVat += Tax::breakdown((float) $o['total'])['tax']; }
}

adminHeader(t('invoices.page_title'), 'billing');

$basePath = getAdminBasePath();
$isCompanyAdmin = defined('COMPANY_ADMIN_BASE') || !empty($_SESSION['company_slug']);
$ext = $isCompanyAdmin ? '' : '.php';
?>
<div class="max-w-6xl mx-auto px-4 py-6">
    <header class="mb-6 flex items-end justify-between flex-wrap gap-3">
        <div>
            <h1 class="text-2xl font-bold text-gray-900"><?= htmlspecialchars(t('invoices.page_title')) ?></h1>
            <p class="text-gray-600 mt-1 text-sm"><?= htmlspecialchars(t('invoices.page_sub')) ?></p>
        </div>
        <form method="GET" class="flex items-center gap-2">
            <?php if ($isCompanyAdmin): ?>
                <input type="hidden" name="page" value="invoices">
            <?php endif; ?>
            <input type="search" name="q" value="<?= htmlspecialchars($q) ?>"
                   placeholder="<?= htmlspecialchars(t('invoices.search_ph')) ?>"
                   class="px-3 py-2 rounded-lg border border-gray-300 text-sm">
            <select name="year" class="px-3 py-2 rounded-lg border border-gray-300 text-sm">
                <?php foreach ($years as $y): ?>
                    <option value="<?= (int) $y['y'] ?>" <?= (int) $y['y'] === $year ? 'selected' : '' ?>><?= (int) $y['y'] ?></option>
                <?php endforeach; ?>
                <?php if (!$years || !in_array($year, array_map(static fn($r) => (int) $r['y'], $years), true)): ?>
                    <option value="<?= $year ?>" selected><?= $year ?></option>
                <?php endif; ?>
            </select>
            <button type="submit" class="px-3 py-2 rounded-lg bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold">
                <i class="fa-solid fa-filter"></i>
            </button>
        </form>
    </header>

    <!-- Summary strip -->
    <div class="grid sm:grid-cols-3 gap-4 mb-6">
        <div class="bg-white rounded-2xl border border-gray-200 p-5">
            <div class="text-xs uppercase tracking-wide text-gray-500 mb-1"><?= htmlspecialchars(t('invoices.summary_count')) ?></div>
            <div class="text-2xl font-bold text-gray-900"><?= count($orders) ?></div>
        </div>
        <div class="bg-white rounded-2xl border border-gray-200 p-5">
            <div class="text-xs uppercase tracking-wide text-gray-500 mb-1"><?= htmlspecialchars(t('invoices.summary_total')) ?></div>
            <div class="text-2xl font-bold text-gray-900"><?= number_format($totalOmr, 3) ?> <span class="text-sm font-medium text-gray-500">OMR</span></div>
        </div>
        <div class="bg-white rounded-2xl border border-gray-200 p-5">
            <div class="text-xs uppercase tracking-wide text-gray-500 mb-1"><?= htmlspecialchars(t('invoices.summary_vat')) ?></div>
            <div class="text-2xl font-bold text-gray-900"><?= number_format($totalVat, 3) ?> <span class="text-sm font-medium text-gray-500">OMR</span></div>
        </div>
    </div>

    <!-- Invoice table -->
    <div class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
        <?php if (empty($orders)): ?>
            <div class="p-12 text-center text-gray-500">
                <i class="fa-regular fa-file-lines text-4xl text-gray-300 mb-3"></i>
                <p><?= htmlspecialchars(t('invoices.empty')) ?></p>
            </div>
        <?php else: ?>
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                <tr>
                    <th class="px-4 py-3 text-left"><?= htmlspecialchars(t('invoices.col_date')) ?></th>
                    <th class="px-4 py-3 text-left"><?= htmlspecialchars(t('invoices.col_order_no')) ?></th>
                    <th class="px-4 py-3 text-left"><?= htmlspecialchars(t('invoices.col_erp_invoice')) ?></th>
                    <th class="px-4 py-3 text-right"><?= htmlspecialchars(t('invoices.col_subtotal')) ?></th>
                    <th class="px-4 py-3 text-right"><?= htmlspecialchars(t('invoices.col_vat')) ?></th>
                    <th class="px-4 py-3 text-right"><?= htmlspecialchars(t('invoices.col_total')) ?></th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
            <?php foreach ($orders as $o):
                $b = Tax::breakdownFromOrder($o);
                if (!$b && (float) ($o['total'] ?? 0) > 0) $b = Tax::breakdown((float) $o['total']);
                $cur = $o['currency'] ?? 'OMR';
                $receiptUrl = $basePath . 'order-receipt' . $ext . '?order=' . (int) $o['id'];
            ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 text-gray-700"><?= htmlspecialchars(I18n::formatDate(strtotime($o['paid_at']))) ?></td>
                    <td class="px-4 py-3 font-mono text-gray-900"><?= htmlspecialchars($o['order_number']) ?></td>
                    <td class="px-4 py-3 font-mono text-gray-700">
                        <?= $o['erp_invoice_number'] ? htmlspecialchars($o['erp_invoice_number']) : '<span class="text-gray-400">—</span>' ?>
                    </td>
                    <td class="px-4 py-3 text-right font-mono text-gray-700"><?= number_format($b['subtotal'] ?? 0, 3) ?></td>
                    <td class="px-4 py-3 text-right font-mono text-gray-700"><?= number_format($b['tax'] ?? 0, 3) ?></td>
                    <td class="px-4 py-3 text-right font-mono font-semibold text-gray-900"><?= number_format((float) $o['total'], 3) ?> <span class="text-xs text-gray-500"><?= $cur ?></span></td>
                    <td class="px-4 py-3 text-right">
                        <a href="<?= htmlspecialchars($receiptUrl) ?>" target="_blank" rel="noopener"
                           class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-blue-50 hover:bg-blue-100 text-blue-700 text-xs font-semibold rounded-lg">
                            <i class="fa-solid fa-download"></i>
                            <?= htmlspecialchars(t('invoices.download_btn')) ?>
                        </a>
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
