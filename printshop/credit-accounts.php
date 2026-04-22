<?php
/**
 * Print Shop — Credit Accounts Management
 * Approve/reject/edit credit requests, set limits, exposure, terms, view PO docs
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
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { die('Invalid request'); }
    $action    = $_POST['action'] ?? '';
    $accountId = trim($_POST['account_id'] ?? '');

    // Verify account belongs to this shop before acting
    $actAccount = $accountId ? CreditManager::getAccountById($accountId) : null;
    if ($accountId && (!$actAccount || (int)$actAccount['print_shop_id'] !== $shopId)) {
        $error = 'Account not found';
        goto render;
    }

    if ($action === 'approve' && $accountId) {
        $limit = (float)($_POST['credit_limit'] ?? 0);
        $exposure = strlen(trim($_POST['exposure_limit'] ?? '')) > 0 ? (float)$_POST['exposure_limit'] : null;
        $terms = $_POST['payment_terms'] ?? 'net30';
        if ($limit <= 0) {
            $error = 'Credit limit must be greater than 0';
        } else {
            $ok = CreditManager::approve($accountId, $limit, $terms, $user['id']);
            if ($ok) {
                // Set exposure limit if provided
                if ($exposure !== null) {
                    CreditManager::adjustLimit($accountId, $limit, $terms, $exposure);
                }
                // Save PO if uploaded
                if (isset($_FILES['po_file']) && $_FILES['po_file']['error'] === UPLOAD_ERR_OK) {
                    CreditManager::uploadPO($accountId, $_FILES['po_file'], trim($_POST['po_number'] ?? '') ?: null);
                }
                $success = 'Credit account approved';
            } else {
                $error = 'Failed to approve';
            }
        }

    } elseif ($action === 'reject' && $accountId) {
        $reason = trim($_POST['reason'] ?? 'Request declined');
        $ok = CreditManager::reject($accountId, $reason ?: 'Request declined', $user['id']);
        $success = $ok ? 'Credit request rejected' : 'Failed to reject';

    } elseif ($action === 'suspend' && $accountId) {
        $ok = CreditManager::suspend($accountId);
        $success = $ok ? 'Credit account suspended' : 'Failed to suspend';

    } elseif ($action === 'reactivate' && $accountId) {
        $ok = CreditManager::reactivate($accountId);
        $success = $ok ? 'Credit account reactivated' : 'Failed to reactivate';

    } elseif ($action === 'adjust_limit' && $accountId) {
        $newLimit    = (float)($_POST['credit_limit'] ?? 0);
        $terms       = $_POST['payment_terms'] ?? null;
        $exposure    = strlen(trim($_POST['exposure_limit'] ?? '')) > 0 ? (float)$_POST['exposure_limit'] : 0;
        if ($newLimit <= 0) {
            $error = 'Credit limit must be greater than 0';
        } else {
            $result = CreditManager::adjustLimit($accountId, $newLimit, $terms, $exposure ?: null);
            $success = isset($result['error']) ? '' : 'Credit account updated';
            if (isset($result['error'])) $error = $result['error'];
        }

    } elseif ($action === 'upload_po' && $accountId) {
        $poNumber = trim($_POST['po_number'] ?? '');
        if (isset($_FILES['po_file']) && $_FILES['po_file']['error'] === UPLOAD_ERR_OK) {
            $result = CreditManager::uploadPO($accountId, $_FILES['po_file'], $poNumber ?: null);
            $success = isset($result['error']) ? '' : 'PO document uploaded';
            if (isset($result['error'])) $error = $result['error'];
        } else {
            $error = 'Please select a file';
        }
    }
}

render:
$pendingAccounts   = CreditManager::getShopAccounts($shopId, 'pending');
$activeAccounts    = CreditManager::getShopAccounts($shopId, 'approved');
$suspendedAccounts = CreditManager::getShopAccounts($shopId, 'suspended');
$outstanding       = CreditManager::getOutstandingSummary($shopId);
$totalOutstanding  = array_sum(array_column($outstanding, 'balance_used'));

$pageTitle = t('printshoppages.title_credit_accounts', ['shop' => $printShop['name']]);
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

    <h1 class="text-2xl font-bold mb-6"><?= htmlspecialchars(t("printshoppages.h1_credit_accounts")) ?></h1>

    <!-- Stats -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
        <div class="bg-white rounded-xl border p-4">
            <p class="text-sm text-gray-500">Pending</p>
            <p class="text-2xl font-bold text-yellow-600"><?= count($pendingAccounts) ?></p>
        </div>
        <div class="bg-white rounded-xl border p-4">
            <p class="text-sm text-gray-500">Active</p>
            <p class="text-2xl font-bold text-green-600"><?= count($activeAccounts) ?></p>
        </div>
        <div class="bg-white rounded-xl border p-4">
            <p class="text-sm text-gray-500">Total Outstanding</p>
            <p class="text-2xl font-bold text-blue-600"><?= htmlspecialchars(formatPrice((float)$totalOutstanding)) ?></p>
        </div>
        <div class="bg-white rounded-xl border p-4">
            <p class="text-sm text-gray-500">Suspended</p>
            <p class="text-2xl font-bold text-red-600"><?= count($suspendedAccounts) ?></p>
        </div>
    </div>

    <!-- ── Pending Requests ── -->
    <?php if ($pendingAccounts): ?>
    <div class="bg-white rounded-xl border border-yellow-200 mb-6">
        <div class="px-6 py-4 border-b border-yellow-100 bg-yellow-50">
            <h2 class="font-semibold text-yellow-800"><i class="fa-solid fa-clock mr-2"></i>Pending Requests (<?= count($pendingAccounts) ?>)</h2>
        </div>
        <div class="divide-y">
        <?php foreach ($pendingAccounts as $acc): ?>
        <div class="px-6 py-5">
            <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-4">
                <!-- Company Info -->
                <div class="flex-1">
                    <p class="font-semibold text-gray-900"><?= htmlspecialchars($acc['company_name']) ?></p>
                    <p class="text-sm text-gray-500"><?= htmlspecialchars($acc['company_email']) ?></p>
                    <p class="text-sm mt-1">Requested: <strong><?= htmlspecialchars(formatPrice((float)$acc['requested_limit'])) ?></strong></p>
                    <?php if ($acc['request_notes']): ?>
                        <p class="text-sm text-gray-500 mt-1 italic">"<?= htmlspecialchars($acc['request_notes']) ?>"</p>
                    <?php endif; ?>
                    <!-- PO attached by client -->
                    <?php if (!empty($acc['po_file_path'])): ?>
                    <div class="mt-2 inline-flex items-center gap-2 px-3 py-1 bg-blue-50 rounded-lg text-sm text-blue-700">
                        <i class="fa-solid fa-file-invoice"></i>
                        PO attached<?= !empty($acc['po_number']) ? ' — #' . htmlspecialchars($acc['po_number']) : '' ?>
                        <a href="<?= getBasePath() . htmlspecialchars($acc['po_file_path']) ?>" target="_blank" class="underline text-xs ml-1">View</a>
                    </div>
                    <?php endif; ?>
                    <p class="text-xs text-gray-400 mt-2">Requested <?= date('d M Y', strtotime($acc['created_at'])) ?></p>
                </div>

                <!-- Approve Form -->
                <form method="POST" enctype="multipart/form-data" class="flex flex-col gap-3 lg:w-96">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="approve">
                    <input type="hidden" name="account_id" value="<?= htmlspecialchars($acc['id']) ?>">
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="text-xs text-gray-500 block mb-1">Approved Limit (OMR)</label>
                            <input type="number" name="credit_limit" step="0.001" min="0.001"
                                   value="<?= $acc['requested_limit'] ?>"
                                   class="w-full border rounded-lg px-2 py-1.5 text-sm focus:ring-2 focus:ring-green-400" required>
                        </div>
                        <div>
                            <label class="text-xs text-gray-500 block mb-1">Exposure Limit (optional)</label>
                            <input type="number" name="exposure_limit" step="0.001" min="0"
                                   placeholder="Same as limit"
                                   class="w-full border rounded-lg px-2 py-1.5 text-sm focus:ring-2 focus:ring-green-400">
                            <p class="text-xs text-gray-400 mt-0.5">Max outstanding at once</p>
                        </div>
                    </div>
                    <div>
                        <label class="text-xs text-gray-500 block mb-1">Payment Terms</label>
                        <select name="payment_terms" class="w-full border rounded-lg px-2 py-1.5 text-sm">
                            <option value="net15">Net 15</option>
                            <option value="net30" selected>Net 30</option>
                            <option value="net60">Net 60</option>
                            <option value="net90">Net 90</option>
                        </select>
                    </div>
                    <?php if (empty($acc['po_file_path'])): ?>
                    <div>
                        <label class="text-xs text-gray-500 block mb-1">Upload PO / Guarantee Document (optional)</label>
                        <div class="grid grid-cols-2 gap-2">
                            <input type="text" name="po_number" placeholder="PO Number"
                                   class="border rounded-lg px-2 py-1.5 text-sm">
                            <input type="file" name="po_file" accept=".pdf,.jpg,.jpeg,.png" class="text-xs py-1.5">
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="flex gap-2">
                        <button type="submit" class="flex-1 bg-green-600 text-white py-2 rounded-lg text-sm font-medium hover:bg-green-700 transition">
                            <i class="fa-solid fa-check mr-1"></i> Approve
                        </button>
                        <button type="button" onclick="this.closest('form').querySelector('[name=action]').value='reject'; this.closest('form').submit();"
                                class="px-4 bg-red-100 text-red-700 rounded-lg text-sm hover:bg-red-200 transition">
                            Reject
                        </button>
                    </div>
                    <input type="hidden" name="reason" value="Request declined">
                </form>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── Active Accounts ── -->
    <div class="bg-white rounded-xl border mb-6">
        <div class="px-6 py-4 border-b flex items-center justify-between">
            <h2 class="font-semibold text-green-700"><i class="fa-solid fa-circle-check mr-2"></i>Active Accounts (<?= count($activeAccounts) ?>)</h2>
        </div>
        <?php if ($activeAccounts): ?>
        <div class="divide-y">
        <?php foreach ($activeAccounts as $acc):
            $available    = (float)$acc['credit_limit'] - (float)$acc['balance_used'];
            $exposureSet  = $acc['exposure_limit'] !== null && (float)$acc['exposure_limit'] > 0;
            $effectiveCap = $exposureSet ? min((float)$acc['credit_limit'], (float)$acc['exposure_limit']) : (float)$acc['credit_limit'];
            $usedPct      = $effectiveCap > 0 ? min(100, round(((float)$acc['balance_used'] / $effectiveCap) * 100)) : 0;
        ?>
        <div class="px-6 py-5">
            <div class="flex flex-col xl:flex-row xl:items-start xl:justify-between gap-4">
                <!-- Summary -->
                <div class="flex-1">
                    <div class="flex items-center gap-3 mb-1">
                        <p class="font-semibold text-gray-900"><?= htmlspecialchars($acc['company_name']) ?></p>
                        <span class="text-xs text-gray-400"><?= strtoupper($acc['payment_terms'] ?? 'NET30') ?></span>
                        <?php if ($exposureSet): ?>
                        <span class="text-xs bg-orange-100 text-orange-700 px-2 py-0.5 rounded-full">
                            Exposure: <?= htmlspecialchars(formatPrice((float)$acc['exposure_limit'])) ?>
                        </span>
                        <?php endif; ?>
                    </div>
                    <p class="text-sm text-gray-500 mb-3"><?= htmlspecialchars($acc['company_email']) ?></p>
                    <!-- Usage bar -->
                    <div class="flex items-center gap-3 text-sm mb-1">
                        <span class="text-gray-500">Limit: <strong><?= number_format($acc['credit_limit'], 3) ?></strong></span>
                        <span class="text-red-600">Used: <strong><?= number_format($acc['balance_used'], 3) ?></strong></span>
                        <span class="text-green-600">Avail: <strong><?= number_format($available, 3) ?></strong></span>
                    </div>
                    <div class="w-full max-w-xs h-2 bg-gray-100 rounded-full overflow-hidden">
                        <div class="h-full rounded-full <?= $usedPct >= 90 ? 'bg-red-500' : ($usedPct >= 60 ? 'bg-yellow-400' : 'bg-green-500') ?>"
                             style="width:<?= $usedPct ?>%"></div>
                    </div>
                    <?php if (!empty($acc['po_file_path'])): ?>
                    <div class="mt-2 inline-flex items-center gap-2 text-xs text-blue-700">
                        <i class="fa-solid fa-file-invoice"></i>
                        PO on file<?= !empty($acc['po_number']) ? ': #' . htmlspecialchars($acc['po_number']) : '' ?>
                        <a href="<?= getBasePath() . htmlspecialchars($acc['po_file_path']) ?>" target="_blank" class="underline">View</a>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Actions panel -->
                <div class="flex flex-col gap-3 xl:w-80">
                    <!-- Edit limit/exposure/terms -->
                    <details class="border rounded-xl">
                        <summary class="px-4 py-2 cursor-pointer text-sm font-medium text-gray-700 hover:bg-gray-50 rounded-xl">
                            <i class="fa-solid fa-pen-to-square mr-1 text-blue-500"></i> Edit Limit & Terms
                        </summary>
                        <form method="POST" class="px-4 pb-4 pt-2 space-y-2">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="adjust_limit">
                            <input type="hidden" name="account_id" value="<?= htmlspecialchars($acc['id']) ?>">
                            <div class="grid grid-cols-2 gap-2">
                                <div>
                                    <label class="text-xs text-gray-500">Credit Limit (OMR)</label>
                                    <input type="number" name="credit_limit" step="0.001" min="<?= $acc['balance_used'] ?>"
                                           value="<?= $acc['credit_limit'] ?>"
                                           class="w-full border rounded px-2 py-1.5 text-sm" required>
                                </div>
                                <div>
                                    <label class="text-xs text-gray-500">Exposure Limit</label>
                                    <input type="number" name="exposure_limit" step="0.001" min="0"
                                           value="<?= $acc['exposure_limit'] ?? '' ?>" placeholder="No cap"
                                           class="w-full border rounded px-2 py-1.5 text-sm">
                                </div>
                            </div>
                            <div>
                                <label class="text-xs text-gray-500">Payment Terms</label>
                                <select name="payment_terms" class="w-full border rounded px-2 py-1.5 text-sm">
                                    <?php foreach (['net15'=>'Net 15','net30'=>'Net 30','net60'=>'Net 60','net90'=>'Net 90'] as $val=>$lbl): ?>
                                    <option value="<?= $val ?>" <?= ($acc['payment_terms'] ?? 'net30') === $val ? 'selected' : '' ?>><?= $lbl ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="submit" class="w-full bg-blue-600 text-white py-1.5 rounded-lg text-sm hover:bg-blue-700 transition">
                                Save Changes
                            </button>
                        </form>
                    </details>

                    <!-- Upload / replace PO -->
                    <details class="border rounded-xl">
                        <summary class="px-4 py-2 cursor-pointer text-sm font-medium text-gray-700 hover:bg-gray-50 rounded-xl">
                            <i class="fa-solid fa-file-arrow-up mr-1 text-purple-500"></i>
                            <?= empty($acc['po_file_path']) ? 'Attach PO / Guarantee' : 'Replace PO Document' ?>
                        </summary>
                        <form method="POST" enctype="multipart/form-data" class="px-4 pb-4 pt-2 space-y-2">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="upload_po">
                            <input type="hidden" name="account_id" value="<?= htmlspecialchars($acc['id']) ?>">
                            <input type="text" name="po_number" placeholder="PO / Reference Number"
                                   value="<?= htmlspecialchars($acc['po_number'] ?? '') ?>"
                                   class="w-full border rounded px-2 py-1.5 text-sm">
                            <input type="file" name="po_file" accept=".pdf,.jpg,.jpeg,.png" required class="text-xs w-full">
                            <p class="text-xs text-gray-400">PDF, JPG, PNG — max 5MB</p>
                            <button type="submit" class="w-full bg-purple-600 text-white py-1.5 rounded-lg text-sm hover:bg-purple-700 transition">
                                Upload
                            </button>
                        </form>
                    </details>

                    <!-- Actions row -->
                    <div class="flex gap-2">
                        <a href="credit-ledger.php?account=<?= urlencode($acc['id']) ?>"
                           class="flex-1 text-center py-1.5 border border-blue-200 text-blue-600 rounded-lg text-sm hover:bg-blue-50 transition">
                            <i class="fa-solid fa-list mr-1"></i> Ledger
                        </a>
                        <form method="POST" class="flex-1">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="suspend">
                            <input type="hidden" name="account_id" value="<?= htmlspecialchars($acc['id']) ?>">
                            <button type="submit" onclick="return confirm('Suspend this credit account?')"
                                    class="w-full py-1.5 border border-red-200 text-red-600 rounded-lg text-sm hover:bg-red-50 transition">
                                Suspend
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
        <?php else: ?>
        <p class="px-6 py-8 text-center text-gray-500">No active credit accounts</p>
        <?php endif; ?>
    </div>

    <!-- ── Suspended ── -->
    <?php if ($suspendedAccounts): ?>
    <div class="bg-white rounded-xl border mb-6">
        <div class="px-6 py-4 border-b"><h2 class="font-semibold text-red-700"><i class="fa-solid fa-ban mr-2"></i>Suspended (<?= count($suspendedAccounts) ?>)</h2></div>
        <div class="divide-y">
        <?php foreach ($suspendedAccounts as $acc): ?>
        <div class="px-6 py-4 flex items-center justify-between gap-4">
            <div>
                <p class="font-medium"><?= htmlspecialchars($acc['company_name']) ?></p>
                <p class="text-sm text-gray-500">
                    Limit: <?= number_format($acc['credit_limit'], 3) ?> &middot;
                    Outstanding: <span class="text-red-600 font-medium"><?= number_format($acc['balance_used'], 3) ?></span>
                </p>
            </div>
            <div class="flex gap-2">
                <a href="credit-ledger.php?account=<?= urlencode($acc['id']) ?>"
                   class="px-3 py-1.5 border border-blue-200 text-blue-600 rounded-lg text-sm hover:bg-blue-50 transition">
                    Ledger
                </a>
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="reactivate">
                    <input type="hidden" name="account_id" value="<?= htmlspecialchars($acc['id']) ?>">
                    <button type="submit" class="px-3 py-1.5 bg-green-600 text-white rounded-lg text-sm hover:bg-green-700 transition">
                        Reactivate
                    </button>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

</div>
</div>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
