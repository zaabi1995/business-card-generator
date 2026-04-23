<?php
/**
 * Company — Credit Accounts Overview
 * View all credit accounts across print shops, upload PO documents
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

$error   = '';
$success = '';

// Handle PO upload
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { die(htmlspecialchars(t('creditacc.invalid_request'))); }
    $accountId = trim($_POST['account_id'] ?? '');

    // Verify this account belongs to this company
    $acc = $accountId ? CreditManager::getAccountById($accountId) : null;
    if ($acc && $acc['company_id'] === $companyId) {
        $poNumber = trim($_POST['po_number'] ?? '');
        if (isset($_FILES['po_file']) && $_FILES['po_file']['error'] === UPLOAD_ERR_OK) {
            $result = CreditManager::uploadPO($accountId, $_FILES['po_file'], $poNumber ?: null);
            $success = isset($result['error']) ? '' : t('creditacc.po_uploaded');
            if (isset($result['error'])) $error = $result['error'];
        } else {
            $error = t('creditacc.please_select_file');
        }
    } else {
        $error = t('creditacc.account_not_found');
    }
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

adminHeader(t('adminchrome.credit_accounts'), 'print');
?>

<div class="max-w-5xl mx-auto">
    <div class="mb-6">
        <a href="<?= getAdminBasePath() ?>print<?= (defined('COMPANY_ADMIN_BASE') || !empty($_SESSION['company_slug'])) ? '' : '.php' ?>" class="text-sm text-gray-500 hover:text-gray-700">
            <i class="fa-solid fa-arrow-left mr-1"></i> <?= htmlspecialchars(t('creditacc.back_to_print')) ?>
        </a>
        <h1 class="text-2xl font-bold mt-2"><i class="fa-solid fa-building-columns mr-2 text-gray-400"></i><?= htmlspecialchars(t('creditacc.page_h1')) ?></h1>
    </div>

    <?php if ($error): ?>
        <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-xl mb-4"><i class="fa-solid fa-circle-xmark mr-1"></i> <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-xl mb-4"><i class="fa-solid fa-circle-check mr-1"></i> <?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8">
        <div class="bg-white rounded-xl shadow-sm border p-4">
            <p class="text-sm text-gray-500"><?= htmlspecialchars(t('creditacc.stat_active')) ?></p>
            <p class="text-2xl font-bold text-green-600"><?= $activeCount ?></p>
        </div>
        <div class="bg-white rounded-xl shadow-sm border p-4">
            <p class="text-sm text-gray-500"><?= htmlspecialchars(t('creditacc.stat_outstanding')) ?></p>
            <p class="text-2xl font-bold text-red-600"><?= number_format($totalUsed, 3) ?> <span class="text-sm text-gray-400"><?= htmlspecialchars(t('creditacc.omr')) ?></span></p>
        </div>
        <div class="bg-white rounded-xl shadow-sm border p-4">
            <p class="text-sm text-gray-500"><?= htmlspecialchars(t('creditacc.stat_available')) ?></p>
            <p class="text-2xl font-bold text-blue-600"><?= number_format($totalAvailable, 3) ?> <span class="text-sm text-gray-400"><?= htmlspecialchars(t('creditacc.omr')) ?></span></p>
        </div>
    </div>

    <!-- Accounts List -->
    <div class="bg-white rounded-xl shadow-sm border">
        <div class="px-6 py-4 border-b"><h2 class="font-semibold"><?= htmlspecialchars(t('creditacc.section_h2')) ?></h2></div>
        <?php if ($accounts): ?>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?= htmlspecialchars(t('creditacc.col_shop')) ?></th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?= htmlspecialchars(t('creditacc.col_status')) ?></th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase"><?= htmlspecialchars(t('creditacc.col_limit')) ?></th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase"><?= htmlspecialchars(t('creditacc.col_used')) ?></th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase"><?= htmlspecialchars(t('creditacc.col_available')) ?></th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?= htmlspecialchars(t('creditacc.col_terms')) ?></th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?= htmlspecialchars(t('creditacc.col_po')) ?></th>
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
                        <td class="px-6 py-4 text-sm text-center">
                            <?php if (!empty($acc['po_file_path'])): ?>
                                <a href="<?= getBasePath() . htmlspecialchars($acc['po_file_path']) ?>" target="_blank"
                                   class="inline-flex items-center gap-1 text-blue-600 hover:text-blue-800 text-xs font-medium">
                                    <i class="fa-solid fa-file-invoice"></i>
                                    <?= htmlspecialchars($acc['po_number'] ?? t('creditacc.view_po')) ?>
                                </a>
                                <button onclick="document.getElementById('po-form-<?= $acc['id'] ?>').classList.toggle('hidden')"
                                        class="ml-2 text-xs text-gray-400 hover:text-gray-600">
                                    <i class="fa-solid fa-upload"></i>
                                </button>
                            <?php else: ?>
                                <button onclick="document.getElementById('po-form-<?= $acc['id'] ?>').classList.toggle('hidden')"
                                        class="text-xs text-blue-600 hover:text-blue-800 font-medium">
                                    <i class="fa-solid fa-upload mr-1"></i><?= htmlspecialchars(t('creditacc.upload_po')) ?>
                                </button>
                            <?php endif; ?>
                            <!-- PO Upload Form -->
                            <div id="po-form-<?= $acc['id'] ?>" class="hidden mt-2">
                                <form method="POST" enctype="multipart/form-data" class="text-left space-y-2 bg-gray-50 rounded-lg p-3 border">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="account_id" value="<?= htmlspecialchars($acc['id']) ?>">
                                    <div>
                                        <label class="block text-xs font-medium text-gray-600 mb-1"><?= htmlspecialchars(t('creditacc.po_number')) ?></label>
                                        <input type="text" name="po_number" value="<?= htmlspecialchars($acc['po_number'] ?? '') ?>"
                                               class="w-full border border-gray-300 rounded px-2 py-1 text-xs focus:ring-1 focus:ring-blue-500"
                                               placeholder="<?= htmlspecialchars(t('creditacc.po_number_ph')) ?>">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-gray-600 mb-1"><?= htmlspecialchars(t('creditacc.document')) ?></label>
                                        <input type="file" name="po_file" accept=".pdf,.jpg,.jpeg,.png" required
                                               class="w-full text-xs border border-gray-300 rounded px-2 py-1">
                                        <p class="text-xs text-gray-400 mt-0.5"><?= htmlspecialchars(t('creditacc.file_hint')) ?></p>
                                    </div>
                                    <button type="submit" class="w-full bg-blue-600 text-white text-xs py-1.5 rounded hover:bg-blue-700">
                                        <i class="fa-solid fa-upload mr-1"></i><?= htmlspecialchars(t('creditacc.upload')) ?>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php if ($acc['status'] === 'pending'): ?>
                    <tr>
                        <td colspan="6" class="px-6 py-2 bg-yellow-50 text-sm text-yellow-700">
                            <i class="fa-solid fa-clock mr-1"></i>
                            <?= htmlspecialchars(str_replace(':amt', number_format($acc['requested_limit'], 3), t('creditacc.pending_note'))) ?>
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
                            <?= htmlspecialchars(str_replace(':reason', $acc['rejected_reason'], t('creditacc.rejected_reason'))) ?>
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
            <h3 class="text-lg font-medium text-gray-900 mb-2"><?= htmlspecialchars(t('creditacc.empty_h3')) ?></h3>
            <p class="text-gray-500"><?= htmlspecialchars(t('creditacc.empty_body')) ?></p>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php adminFooter(); ?>
