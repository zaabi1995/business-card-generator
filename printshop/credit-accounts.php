<?php
/**
 * Print Shop — Credit Accounts Management
 * Approve/reject credit requests, set limits and terms, suspend/reactivate
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/PrintShop.php';
require_once INCLUDES_DIR . '/CreditManager.php';
require_once INCLUDES_DIR . '/Currency.php';

Auth::requireLogin();
$user = Auth::getCurrentUser();

if ($user['role'] !== 'print_shop' && $user['role'] !== 'super_admin') {
    header('Location: ' . getBasePath() . 'login.php');
    exit;
}

$printShop = PrintShop::getByUserId($user['id']);
if (!$printShop && $user['role'] !== 'super_admin') {
    header('Location: ' . getBasePath() . 'printshop/register.php');
    exit;
}
if ($user['role'] === 'super_admin' && isset($_GET['shop'])) {
    $printShop = PrintShop::getById((int)$_GET['shop']);
}
if (!$printShop) {
    header('Location: ' . getBasePath() . 'admin/print_shops.php');
    exit;
}

$shopId = (int)$printShop['id'];
$error = '';
$success = '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRFToken($_POST['csrf_token'] ?? '');
    $action = $_POST['action'] ?? '';
    $accountId = $_POST['account_id'] ?? '';

    if ($action === 'approve' && $accountId) {
        $limit = (float)($_POST['credit_limit'] ?? 0);
        $terms = $_POST['payment_terms'] ?? 'net30';
        if ($limit > 0) {
            $ok = CreditManager::approve($accountId, $limit, $terms, $user['id']);
            $success = $ok ? 'Credit account approved' : 'Failed to approve';
        } else {
            $error = 'Credit limit must be greater than 0';
        }
    } elseif ($action === 'reject' && $accountId) {
        $reason = trim($_POST['reason'] ?? 'Request declined');
        $ok = CreditManager::reject($accountId, $reason, $user['id']);
        $success = $ok ? 'Credit request rejected' : 'Failed to reject';
    } elseif ($action === 'suspend' && $accountId) {
        $ok = CreditManager::suspend($accountId);
        $success = $ok ? 'Credit account suspended' : 'Failed to suspend';
    } elseif ($action === 'reactivate' && $accountId) {
        $ok = CreditManager::reactivate($accountId);
        $success = $ok ? 'Credit account reactivated' : 'Failed to reactivate';
    }
}

$pendingAccounts = CreditManager::getShopAccounts($shopId, 'pending');
$activeAccounts = CreditManager::getShopAccounts($shopId, 'approved');
$suspendedAccounts = CreditManager::getShopAccounts($shopId, 'suspended');
$outstanding = CreditManager::getOutstandingSummary($shopId);

$totalOutstanding = array_sum(array_column($outstanding, 'balance_used'));

$pageTitle = 'Credit Accounts - ' . $printShop['name'];
$bodyClass = 'bg-gray-50';
require_once INCLUDES_DIR . '/ui-header.php';
?>

<div class="min-h-screen">
    <nav class="bg-white border-b border-gray-200 fixed top-0 left-0 right-0 z-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-16">
                <div class="flex items-center gap-4">
                    <a href="<?= getBasePath() ?>printshop/dashboard.php" class="flex items-center gap-2">
                        <img src="<?= getBasePath() ?>assets/images/logo.svg" alt="Cardify" class="h-8 w-auto">
                    </a>
                    <span class="text-gray-300">|</span>
                    <span class="font-semibold text-gray-900"><?= sanitize($printShop['name']) ?></span>
                </div>
                <div class="flex items-center gap-4 text-sm">
                    <a href="dashboard.php" class="text-gray-500 hover:text-gray-700"><i class="fa-solid fa-chart-pie mr-1"></i>Dashboard</a>
                    <a href="orders.php" class="text-gray-500 hover:text-gray-700"><i class="fa-solid fa-box mr-1"></i>Orders</a>
                    <a href="credit-accounts.php" class="text-blue-600 font-medium"><i class="fa-solid fa-building-columns mr-1"></i>Credit</a>
                    <a href="settings.php" class="text-gray-500 hover:text-gray-700"><i class="fa-solid fa-cog"></i></a>
                </div>
            </div>
        </div>
    </nav>

    <div class="pt-20 pb-12 px-4 sm:px-6 lg:px-8 max-w-7xl mx-auto">

        <?php if ($error): ?>
            <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-xl mb-4"><i class="fa-solid fa-circle-xmark mr-1"></i> <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-xl mb-4"><i class="fa-solid fa-circle-check mr-1"></i> <?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <h1 class="text-2xl font-bold mb-6">Credit Accounts</h1>

        <!-- Stats -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-8">
            <div class="bg-white rounded-xl border p-4">
                <p class="text-sm text-gray-500">Pending Requests</p>
                <p class="text-2xl font-bold text-yellow-600"><?= count($pendingAccounts) ?></p>
            </div>
            <div class="bg-white rounded-xl border p-4">
                <p class="text-sm text-gray-500">Active Accounts</p>
                <p class="text-2xl font-bold text-green-600"><?= count($activeAccounts) ?></p>
            </div>
            <div class="bg-white rounded-xl border p-4">
                <p class="text-sm text-gray-500">Total Outstanding</p>
                <p class="text-2xl font-bold text-blue-600"><?= number_format($totalOutstanding, 3) ?> OMR</p>
            </div>
            <div class="bg-white rounded-xl border p-4">
                <p class="text-sm text-gray-500">Suspended</p>
                <p class="text-2xl font-bold text-red-600"><?= count($suspendedAccounts) ?></p>
            </div>
        </div>

        <!-- Pending Requests -->
        <?php if ($pendingAccounts): ?>
        <div class="bg-white rounded-xl border mb-6">
            <div class="px-6 py-4 border-b"><h2 class="font-semibold text-yellow-700"><i class="fa-solid fa-clock mr-2"></i>Pending Requests</h2></div>
            <div class="divide-y">
                <?php foreach ($pendingAccounts as $acc): ?>
                <div class="px-6 py-4">
                    <div class="flex items-start justify-between">
                        <div>
                            <p class="font-medium"><?= htmlspecialchars($acc['company_name']) ?></p>
                            <p class="text-sm text-gray-500"><?= htmlspecialchars($acc['company_email']) ?></p>
                            <p class="text-sm mt-1">Requested: <strong><?= number_format($acc['requested_limit'], 3) ?> OMR</strong></p>
                            <?php if ($acc['request_notes']): ?>
                                <p class="text-sm text-gray-500 mt-1"><?= htmlspecialchars($acc['request_notes']) ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="flex gap-2">
                            <form method="POST" class="inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="approve">
                                <input type="hidden" name="account_id" value="<?= $acc['id'] ?>">
                                <div class="flex gap-2 items-end">
                                    <div>
                                        <label class="text-xs text-gray-500">Limit</label>
                                        <input type="number" name="credit_limit" step="0.001" value="<?= $acc['requested_limit'] ?>" class="w-28 border rounded px-2 py-1 text-sm">
                                    </div>
                                    <div>
                                        <label class="text-xs text-gray-500">Terms</label>
                                        <select name="payment_terms" class="border rounded px-2 py-1 text-sm">
                                            <option value="net15">Net 15</option>
                                            <option value="net30" selected>Net 30</option>
                                            <option value="net60">Net 60</option>
                                            <option value="net90">Net 90</option>
                                        </select>
                                    </div>
                                    <button type="submit" class="bg-green-600 text-white px-3 py-1 rounded text-sm hover:bg-green-700">Approve</button>
                                </div>
                            </form>
                            <form method="POST" class="inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="reject">
                                <input type="hidden" name="account_id" value="<?= $acc['id'] ?>">
                                <input type="hidden" name="reason" value="Request declined">
                                <button type="submit" class="bg-red-600 text-white px-3 py-1 rounded text-sm hover:bg-red-700 self-end">Reject</button>
                            </form>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Active Accounts -->
        <div class="bg-white rounded-xl border mb-6">
            <div class="px-6 py-4 border-b"><h2 class="font-semibold text-green-700"><i class="fa-solid fa-circle-check mr-2"></i>Active Accounts (<?= count($activeAccounts) ?>)</h2></div>
            <?php if ($activeAccounts): ?>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Company</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Limit</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Used</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Available</th>
                            <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Terms</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php foreach ($activeAccounts as $acc):
                            $available = (float)$acc['credit_limit'] - (float)$acc['balance_used'];
                        ?>
                        <tr>
                            <td class="px-6 py-4 text-sm"><?= htmlspecialchars($acc['company_name']) ?></td>
                            <td class="px-6 py-4 text-sm text-right"><?= number_format($acc['credit_limit'], 3) ?></td>
                            <td class="px-6 py-4 text-sm text-right <?= $acc['balance_used'] > 0 ? 'text-red-600 font-medium' : '' ?>"><?= number_format($acc['balance_used'], 3) ?></td>
                            <td class="px-6 py-4 text-sm text-right text-green-600"><?= number_format($available, 3) ?></td>
                            <td class="px-6 py-4 text-sm text-center"><?= strtoupper($acc['payment_terms']) ?></td>
                            <td class="px-6 py-4 text-sm text-right space-x-2">
                                <a href="credit-ledger.php?account=<?= urlencode($acc['id']) ?>" class="text-blue-600 hover:text-blue-800 text-xs">Ledger</a>
                                <form method="POST" class="inline">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="suspend">
                                    <input type="hidden" name="account_id" value="<?= $acc['id'] ?>">
                                    <button type="submit" class="text-red-600 hover:text-red-800 text-xs" onclick="return confirm('Suspend this credit account?')">Suspend</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <p class="px-6 py-8 text-center text-gray-500">No active credit accounts</p>
            <?php endif; ?>
        </div>

        <!-- Suspended -->
        <?php if ($suspendedAccounts): ?>
        <div class="bg-white rounded-xl border mb-6">
            <div class="px-6 py-4 border-b"><h2 class="font-semibold text-red-700"><i class="fa-solid fa-ban mr-2"></i>Suspended (<?= count($suspendedAccounts) ?>)</h2></div>
            <div class="divide-y">
                <?php foreach ($suspendedAccounts as $acc): ?>
                <div class="px-6 py-4 flex items-center justify-between">
                    <div>
                        <p class="font-medium"><?= htmlspecialchars($acc['company_name']) ?></p>
                        <p class="text-sm text-gray-500">Limit: <?= number_format($acc['credit_limit'], 3) ?> &middot; Outstanding: <?= number_format($acc['balance_used'], 3) ?></p>
                    </div>
                    <form method="POST">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="reactivate">
                        <input type="hidden" name="account_id" value="<?= $acc['id'] ?>">
                        <button type="submit" class="bg-green-600 text-white px-3 py-1 rounded text-sm hover:bg-green-700">Reactivate</button>
                    </form>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
