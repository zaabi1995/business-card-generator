<?php
/**
 * Company — Credit Accounts Overview
 * View all credit accounts across print shops
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/CreditManager.php';
require_once INCLUDES_DIR . '/Currency.php';
require_once INCLUDES_DIR . '/admin-layout.php';

requireAdmin();
$companyId = getCurrentCompanyId();

if (!$companyId) {
    header('Location: ' . getBasePath() . 'login.php');
    exit;
}

$accounts = CreditManager::getCompanyAccounts($companyId);

$statusBadges = [
    'pending' => 'bg-yellow-100 text-yellow-700',
    'approved' => 'bg-green-100 text-green-700',
    'suspended' => 'bg-red-100 text-red-700',
    'rejected' => 'bg-gray-100 text-gray-600'
];

$totalAvailable = 0;
$totalUsed = 0;
$activeCount = 0;
foreach ($accounts as $acc) {
    if ($acc['status'] === 'approved') {
        $activeCount++;
        $totalUsed += (float)$acc['balance_used'];
        $totalAvailable += ((float)$acc['credit_limit'] - (float)$acc['balance_used']);
    }
}

adminHeader('Credit Accounts', 'print');
?>

<div class="max-w-5xl mx-auto">
    <div class="mb-6">
        <a href="<?= getBasePath() ?>admin/print.php" class="text-sm text-gray-500 hover:text-gray-700">
            <i class="fa-solid fa-arrow-left mr-1"></i> Back to Print Orders
        </a>
        <h1 class="text-2xl font-bold mt-2"><i class="fa-solid fa-building-columns mr-2 text-gray-400"></i>My Credit Accounts</h1>
    </div>

    <!-- Stats -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8">
        <div class="bg-white rounded-xl shadow-sm border p-4">
            <p class="text-sm text-gray-500">Active Accounts</p>
            <p class="text-2xl font-bold text-green-600"><?= $activeCount ?></p>
        </div>
        <div class="bg-white rounded-xl shadow-sm border p-4">
            <p class="text-sm text-gray-500">Total Outstanding</p>
            <p class="text-2xl font-bold text-red-600"><?= number_format($totalUsed, 3) ?> <span class="text-sm text-gray-400">OMR</span></p>
        </div>
        <div class="bg-white rounded-xl shadow-sm border p-4">
            <p class="text-sm text-gray-500">Total Available</p>
            <p class="text-2xl font-bold text-blue-600"><?= number_format($totalAvailable, 3) ?> <span class="text-sm text-gray-400">OMR</span></p>
        </div>
    </div>

    <!-- Accounts List -->
    <div class="bg-white rounded-xl shadow-sm border">
        <div class="px-6 py-4 border-b"><h2 class="font-semibold">Credit Accounts</h2></div>
        <?php if ($accounts): ?>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Print Shop</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Status</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Limit</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Used</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Available</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Terms</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php foreach ($accounts as $acc):
                        $avail = $acc['status'] === 'approved' ? (float)$acc['credit_limit'] - (float)$acc['balance_used'] : 0;
                        $badge = $statusBadges[$acc['status']] ?? 'bg-gray-100 text-gray-600';
                    ?>
                    <tr>
                        <td class="px-6 py-4 text-sm font-medium"><?= htmlspecialchars($acc['shop_name'] ?? '') ?></td>
                        <td class="px-6 py-4 text-center">
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?= $badge ?>">
                                <?= ucfirst($acc['status']) ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 text-sm text-right">
                            <?= $acc['status'] === 'approved' ? number_format($acc['credit_limit'], 3) : '—' ?>
                        </td>
                        <td class="px-6 py-4 text-sm text-right <?= (float)$acc['balance_used'] > 0 ? 'text-red-600 font-medium' : '' ?>">
                            <?= $acc['status'] === 'approved' ? number_format($acc['balance_used'], 3) : '—' ?>
                        </td>
                        <td class="px-6 py-4 text-sm text-right text-green-600">
                            <?= $acc['status'] === 'approved' ? number_format($avail, 3) : '—' ?>
                        </td>
                        <td class="px-6 py-4 text-sm text-center">
                            <?= $acc['payment_terms'] ? strtoupper($acc['payment_terms']) : '—' ?>
                        </td>
                    </tr>
                    <?php if ($acc['status'] === 'pending'): ?>
                    <tr>
                        <td colspan="6" class="px-6 py-2 bg-yellow-50 text-sm text-yellow-700">
                            <i class="fa-solid fa-clock mr-1"></i>
                            Requested <?= number_format($acc['requested_limit'], 3) ?> OMR — awaiting print shop approval
                            <?php if ($acc['request_notes']): ?>
                                <span class="text-yellow-600 ml-2">(<?= htmlspecialchars($acc['request_notes']) ?>)</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($acc['status'] === 'rejected' && !empty($acc['rejected_reason'])): ?>
                    <tr>
                        <td colspan="6" class="px-6 py-2 bg-gray-50 text-sm text-gray-500">
                            <i class="fa-solid fa-info-circle mr-1"></i>
                            Reason: <?= htmlspecialchars($acc['rejected_reason']) ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="px-6 py-12 text-center">
            <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
                <i class="fa-solid fa-building-columns text-2xl text-gray-400"></i>
            </div>
            <h3 class="text-lg font-medium text-gray-900 mb-2">No credit accounts</h3>
            <p class="text-gray-500">You can request a credit account when checking out a print order.</p>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php adminFooter(); ?>
