<?php
/**
 * Payment History — All Paymob transactions for the company
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/Currency.php';
require_once INCLUDES_DIR . '/Payment.php';
require_once INCLUDES_DIR . '/admin-layout.php';

requireAdmin();

if (Auth::hasRole('super_admin')) {
    header('Location: ' . getBasePath() . 'admin/super/');
    exit;
}

$companyId = getCurrentCompanyId();
$db = Database::getInstance();

// Pagination
$perPage = 20;
$page = max(1, (int)($_GET['p'] ?? 1));
$offset = ($page - 1) * $perPage;

// Filter
$typeFilter = $_GET['type'] ?? '';
$statusFilter = $_GET['status'] ?? '';

$where = ['company_id = :cid'];
$params = ['cid' => $companyId];

if ($typeFilter) {
    $where[] = 'type = :type';
    $params['type'] = $typeFilter;
}
if ($statusFilter) {
    $where[] = 'status = :status';
    $params['status'] = $statusFilter;
}

$whereSQL = 'WHERE ' . implode(' AND ', $where);

$total = $db->fetchOne("SELECT COUNT(*) as cnt FROM payments $whereSQL", $params)['cnt'] ?? 0;
$payments = $db->fetchAll(
    "SELECT * FROM payments $whereSQL ORDER BY created_at DESC LIMIT $perPage OFFSET $offset",
    $params
);
$totalPages = max(1, (int)ceil($total / $perPage));

// Summary stats
$stats = $db->fetchOne(
    "SELECT
        COUNT(*) as total_count,
        SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END) as total_paid,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count,
        SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_count
     FROM payments WHERE company_id = :cid",
    ['cid' => $companyId]
);

$companyCurrency = $db->fetchOne("SELECT currency FROM companies WHERE id = :id", ['id' => $companyId])['currency'] ?? 'OMR';

adminHeader('Payment History', 'payment-history');
?>

<!-- Summary Cards -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
    <div class="bg-white rounded-xl border border-gray-200 p-5">
        <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Total Paid</p>
        <p class="text-2xl font-bold text-green-600">
            <?php echo number_format((float)($stats['total_paid'] ?? 0), 3); ?>
            <span class="text-base font-normal text-gray-500"><?php echo $companyCurrency; ?></span>
        </p>
    </div>
    <div class="bg-white rounded-xl border border-gray-200 p-5">
        <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Transactions</p>
        <p class="text-2xl font-bold text-gray-900"><?php echo (int)($stats['total_count'] ?? 0); ?></p>
    </div>
    <div class="bg-white rounded-xl border border-gray-200 p-5">
        <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Pending</p>
        <p class="text-2xl font-bold text-yellow-500"><?php echo (int)($stats['pending_count'] ?? 0); ?></p>
    </div>
    <div class="bg-white rounded-xl border border-gray-200 p-5">
        <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Failed</p>
        <p class="text-2xl font-bold text-red-500"><?php echo (int)($stats['failed_count'] ?? 0); ?></p>
    </div>
</div>

<!-- Filters -->
<form method="GET" class="flex flex-wrap gap-3 mb-6">
    <select name="type" class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500">
        <option value="">All Types</option>
        <option value="subscription" <?php echo $typeFilter === 'subscription' ? 'selected' : ''; ?>>Subscription</option>
        <option value="print_order" <?php echo $typeFilter === 'print_order' ? 'selected' : ''; ?>>Print Order</option>
    </select>
    <select name="status" class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500">
        <option value="">All Statuses</option>
        <option value="paid" <?php echo $statusFilter === 'paid' ? 'selected' : ''; ?>>Paid</option>
        <option value="pending" <?php echo $statusFilter === 'pending' ? 'selected' : ''; ?>>Pending</option>
        <option value="failed" <?php echo $statusFilter === 'failed' ? 'selected' : ''; ?>>Failed</option>
    </select>
    <button type="submit" class="px-4 py-2 bg-gray-900 text-white text-sm rounded-lg hover:bg-gray-700 transition">
        <i class="fa-solid fa-filter mr-1"></i> Filter
    </button>
    <?php if ($typeFilter || $statusFilter): ?>
    <a href="<?php echo getAdminBasePath(); ?>payment-history<?php echo (defined('COMPANY_ADMIN_BASE') || !empty($_SESSION['company_slug'])) ? '' : '.php'; ?>"
       class="px-4 py-2 border border-gray-300 text-gray-600 text-sm rounded-lg hover:bg-gray-50 transition">
        Clear
    </a>
    <?php endif; ?>
</form>

<!-- Transactions Table -->
<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
    <?php if (empty($payments)): ?>
    <div class="p-12 text-center">
        <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
            <i class="fa-solid fa-receipt text-gray-400 text-2xl"></i>
        </div>
        <h3 class="text-lg font-medium text-gray-900 mb-1"><?= htmlspecialchars(t('emptystates.no_transactions_h')) ?></h3>
        <p class="text-gray-500 text-sm"><?= htmlspecialchars(t('emptystates.no_transactions_sub')) ?></p>
    </div>
    <?php else: ?>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 border-b border-gray-200">
                <tr>
                    <th class="px-4 py-3 text-left font-medium text-gray-600">Date</th>
                    <th class="px-4 py-3 text-left font-medium text-gray-600">Type</th>
                    <th class="px-4 py-3 text-left font-medium text-gray-600">Reference</th>
                    <th class="px-4 py-3 text-left font-medium text-gray-600">Method</th>
                    <th class="px-4 py-3 text-right font-medium text-gray-600">Amount</th>
                    <th class="px-4 py-3 text-center font-medium text-gray-600">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <?php foreach ($payments as $pmt): ?>
                <tr class="hover:bg-gray-50 transition-colors">
                    <td class="px-4 py-3 text-gray-500 whitespace-nowrap">
                        <?php echo date('d M Y, H:i', strtotime($pmt['created_at'])); ?>
                    </td>
                    <td class="px-4 py-3">
                        <?php if ($pmt['type'] === 'subscription'): ?>
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-700">
                            <i class="fa-solid fa-crown text-xs"></i> Subscription
                        </span>
                        <?php else: ?>
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-purple-100 text-purple-700">
                            <i class="fa-solid fa-print text-xs"></i> Print Order
                        </span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-gray-600 font-mono text-xs">
                        <?php echo htmlspecialchars(substr($pmt['special_reference'] ?? $pmt['id'], 0, 30)); ?>…
                    </td>
                    <td class="px-4 py-3 text-gray-600 capitalize">
                        <?php echo htmlspecialchars($pmt['payment_method'] ?? '—'); ?>
                    </td>
                    <td class="px-4 py-3 text-right font-semibold text-gray-900 whitespace-nowrap">
                        <?php echo number_format((float)$pmt['amount'], 3); ?>
                        <span class="font-normal text-gray-500"><?php echo htmlspecialchars($pmt['currency'] ?? $companyCurrency); ?></span>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <?php
                        $statusClasses = [
                            'paid'    => 'bg-green-100 text-green-700',
                            'pending' => 'bg-yellow-100 text-yellow-700',
                            'failed'  => 'bg-red-100 text-red-700',
                        ];
                        $cls = $statusClasses[$pmt['status']] ?? 'bg-gray-100 text-gray-600';
                        ?>
                        <span class="inline-block px-2 py-0.5 rounded-full text-xs font-medium <?php echo $cls; ?>">
                            <?php echo ucfirst($pmt['status']); ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="px-4 py-3 border-t border-gray-100 flex items-center justify-between">
        <p class="text-sm text-gray-500">
            Showing <?php echo $offset + 1; ?>–<?php echo min($offset + $perPage, $total); ?> of <?php echo $total; ?>
        </p>
        <div class="flex gap-1">
            <?php
            $baseUrl = getAdminBasePath() . 'payment-history' . ((defined('COMPANY_ADMIN_BASE') || !empty($_SESSION['company_slug'])) ? '' : '.php');
            $queryBase = ($typeFilter ? '&type=' . urlencode($typeFilter) : '') . ($statusFilter ? '&status=' . urlencode($statusFilter) : '');
            for ($i = 1; $i <= $totalPages; $i++):
            ?>
            <a href="<?php echo $baseUrl; ?>?p=<?php echo $i; ?><?php echo $queryBase; ?>"
               class="px-3 py-1.5 rounded-lg text-sm <?php echo $i === $page ? 'bg-blue-600 text-white' : 'border border-gray-200 text-gray-600 hover:bg-gray-50'; ?> transition">
                <?php echo $i; ?>
            </a>
            <?php endfor; ?>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php adminFooter(); ?>
