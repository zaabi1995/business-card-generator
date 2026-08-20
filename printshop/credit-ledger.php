<?php
/**
 * Print Shop, Credit Ledger
 * Transaction history per credit account, record manual payments
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
$accountId = $_GET['account'] ?? '';
$error = '';
$success = '';

if (!$accountId) {
    header('Location: ' . getBasePath() . 'printshop/credit-accounts.php');
    exit;
}

// Verify this account belongs to this shop
$account = CreditManager::getAccountById($accountId);
if (!$account || (int)$account['print_shop_id'] !== $shopId) {
    header('Location: ' . getBasePath() . 'printshop/credit-accounts.php');
    exit;
}

// Get company name
$db = Database::getInstance();
$company = $db->fetchOne("SELECT name, admin_email FROM companies WHERE id = :id", ['id' => $account['company_id']]);

// Handle POST, record payment
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { die(htmlspecialchars(t('printshopcredit.invalid_request'))); }
    $action = $_POST['action'] ?? '';

    if ($action === 'record_payment') {
        $amount = (float)($_POST['amount'] ?? 0);
        $notes  = trim($_POST['notes'] ?? '');
        if ($amount > 0) {
            $proofFile = (isset($_FILES['proof_file']) && $_FILES['proof_file']['error'] === UPLOAD_ERR_OK)
                ? $_FILES['proof_file'] : null;
            $result = CreditManager::recordPaymentWithProof(
                $accountId, $amount, $notes ?: null, $user['id'], $proofFile
            );
            if (isset($result['error'])) {
                $error = $result['error'];
            } else {
                $tpl = $result['proof_path'] ? 'printshopcredit.ledger_payment_ok_proof' : 'printshopcredit.ledger_payment_ok';
                $success = str_replace(':amt', formatPrice((float)$amount), t($tpl));
                $account = CreditManager::getAccountById($accountId);
            }
        } else {
            $error = t('printshopcredit.amount_gt_zero');
        }
    }
}

$ledger = CreditManager::getLedger($accountId, 100);
$available = (float)$account['credit_limit'] - (float)$account['balance_used'];

$txColors = [
    'charge' => 'text-red-600',
    'payment' => 'text-green-600',
    'refund' => 'text-blue-600',
    'adjustment' => 'text-gray-600'
];
$txIcons = [
    'charge' => 'fa-arrow-up',
    'payment' => 'fa-arrow-down',
    'refund' => 'fa-rotate-left',
    'adjustment' => 'fa-sliders'
];

require_once INCLUDES_DIR . '/printshop-layout.php';
printshopHeader(t('printshoppages.title_credit_ledger', ['name' => $company['name'] ?? 'Account']), 'credit');
?>
        <div class="mb-4">
            <a href="credit-accounts.php" class="text-sm text-gray-500 hover:text-gray-700"><i class="fa-solid fa-arrow-left mr-1"></i> <?= htmlspecialchars(t('printshopcredit.back_to_accounts')) ?></a>
        </div>

        <?php if ($error): ?>
            <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-xl mb-4"><i class="fa-solid fa-circle-xmark mr-1"></i> <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-xl mb-4"><i class="fa-solid fa-circle-check mr-1"></i> <?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <h1 class="text-2xl font-bold mb-2"><?= htmlspecialchars(t("printshoppages.h1_credit_ledger")) ?></h1>
        <p class="text-gray-500 mb-6"><?= htmlspecialchars($company['name'] ?? '') ?> &middot; <?= htmlspecialchars($company['admin_email'] ?? '') ?></p>

        <!-- Account Summary -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-8">
            <div class="bg-white rounded-xl border p-4">
                <p class="text-sm text-gray-500"><?= htmlspecialchars(t('printshopcredit.ledger_stat_limit')) ?></p>
                <p class="text-2xl font-bold"><?= formatPriceHtml((float)$account['credit_limit'], 'lg') ?></p>
            </div>
            <div class="bg-white rounded-xl border p-4">
                <p class="text-sm text-gray-500"><?= htmlspecialchars(t('printshopcredit.ledger_stat_used')) ?></p>
                <p class="text-2xl font-bold text-red-600"><?= formatPriceHtml((float)$account['balance_used'], 'lg') ?></p>
            </div>
            <div class="bg-white rounded-xl border p-4">
                <p class="text-sm text-gray-500"><?= htmlspecialchars(t('printshopcredit.ledger_stat_avail')) ?></p>
                <p class="text-2xl font-bold text-green-600"><?= formatPriceHtml((float)$available, 'lg') ?></p>
            </div>
            <div class="bg-white rounded-xl border p-4">
                <p class="text-sm text-gray-500"><?= htmlspecialchars(t('printshopcredit.ledger_stat_terms')) ?></p>
                <p class="text-2xl font-bold text-blue-600"><?= strtoupper($account['payment_terms'] ?? 'NET30') ?></p>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Transactions -->
            <div class="lg:col-span-2 bg-white rounded-xl border">
                <div class="px-6 py-4 border-b"><h2 class="font-semibold"><i class="fa-solid fa-list mr-2 text-gray-400"></i><?= htmlspecialchars(t('printshopcredit.transactions_h')) ?></h2></div>
                <?php if ($ledger): ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?= htmlspecialchars(t('printshopcredit.col_date')) ?></th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?= htmlspecialchars(t('printshopcredit.col_type')) ?></th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?= htmlspecialchars(t('printshopcredit.col_order')) ?></th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase"><?= htmlspecialchars(t('printshopcredit.col_amount')) ?></th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase"><?= htmlspecialchars(t('printshopcredit.col_balance')) ?></th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?= htmlspecialchars(t('printshopcredit.col_notes')) ?></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <?php foreach ($ledger as $tx): ?>
                            <tr>
                                <td class="px-6 py-3 text-sm text-gray-500 whitespace-nowrap"><?= date('M d, Y', dbTs($tx['created_at'])) ?><br><span class="text-xs"><?= date('h:i A', dbTs($tx['created_at'])) ?></span></td>
                                <td class="px-6 py-3 text-sm">
                                    <span class="<?= $txColors[$tx['type']] ?? 'text-gray-600' ?> font-medium">
                                        <i class="fa-solid <?= $txIcons[$tx['type']] ?? 'fa-circle' ?> mr-1"></i>
                                        <?php $txk = 'printshopcredit.tx_' . $tx['type']; $txl = t($txk); echo htmlspecialchars($txl === $txk ? ucfirst($tx['type']) : $txl); ?>
                                    </span>
                                </td>
                                <td class="px-6 py-3 text-sm text-gray-500"><?= $tx['order_number'] ? htmlspecialchars($tx['order_number']) : ',' ?></td>
                                <td class="px-6 py-3 text-sm text-right font-medium <?= $txColors[$tx['type']] ?? '' ?> whitespace-nowrap">
                                    <?= $tx['type'] === 'charge' ? '+' : '-' ?><?= currencySymbolHtml('sm') ?> <?= number_format($tx['amount'], 3) ?>
                                </td>
                                <td class="px-6 py-3 text-sm text-right whitespace-nowrap"><?= currencySymbolHtml('sm') ?> <?= number_format($tx['balance_after'], 3) ?></td>
                                <td class="px-6 py-3 text-sm text-gray-500">
                                    <?= $tx['notes'] ? htmlspecialchars($tx['notes']) : '' ?>
                                    <?php if (!empty($tx['po_file_path'])): ?>
                                    <a href="<?= getBasePath() . htmlspecialchars($tx['po_file_path']) ?>" target="_blank"
                                       class="inline-flex items-center gap-1 ml-2 text-blue-600 hover:text-blue-800 text-xs">
                                        <i class="fa-solid fa-file-invoice"></i> <?= htmlspecialchars(t('printshopcredit.proof')) ?>
                                    </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <p class="px-6 py-8 text-center text-gray-500"><?= htmlspecialchars(t('printshopcredit.no_tx')) ?></p>
                <?php endif; ?>
            </div>

            <!-- Record Payment -->
            <div class="bg-white rounded-xl border h-fit">
                <div class="px-6 py-4 border-b"><h2 class="font-semibold"><i class="fa-solid fa-money-bill-wave mr-2 text-green-500"></i><?= htmlspecialchars(t('printshopcredit.record_payment_h')) ?></h2></div>
                <form method="POST" enctype="multipart/form-data" class="p-6 space-y-4">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="record_payment">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1" for="cl_payment_amount"><?= htmlspecialchars(t('printshopcredit.field_amount')) ?></label>
                        <input type="number" id="cl_payment_amount" name="amount" step="0.001" min="0.001" max="<?= $account['balance_used'] ?>" required
                               aria-label="<?= htmlspecialchars(t('printshopcredit.field_amount')) ?>"
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 tabular-nums"
                               placeholder="<?= htmlspecialchars(t('printshopcredit.amount_ph')) ?>">
                        <p class="text-xs text-gray-400 mt-1"><?= str_replace(':amt', formatPriceHtml((float)$account['balance_used'], 'sm'), htmlspecialchars(t('printshopcredit.outstanding_hint'))) ?></p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1" for="cl_payment_notes"><?= htmlspecialchars(t('printshopcredit.field_notes')) ?></label>
                        <textarea id="cl_payment_notes" name="notes" rows="2"
                                  aria-label="<?= htmlspecialchars(t('printshopcredit.field_notes')) ?>"
                                  class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500"
                                  placeholder="<?= htmlspecialchars(t('printshopcredit.notes_ph')) ?>"></textarea>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1" for="cl_payment_proof"><?= htmlspecialchars(t('printshopcredit.field_proof')) ?></label>
                        <input type="file" id="cl_payment_proof" name="proof_file" accept=".pdf,.jpg,.jpeg,.png"
                               aria-label="<?= htmlspecialchars(t('printshopcredit.field_proof')) ?>"
                               class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2">
                        <p class="text-xs text-gray-400 mt-1"><?= htmlspecialchars(t('printshopcredit.proof_hint')) ?></p>
                    </div>
                    <button type="submit" class="w-full bg-green-600 text-white py-2 px-4 rounded-lg hover:bg-green-700 transition text-sm font-medium">
                        <i class="fa-solid fa-check mr-1"></i> <?= htmlspecialchars(t('printshopcredit.btn_record_payment')) ?>
                    </button>
                </form>
            </div>
        </div>
<?php printshopFooter(); ?>
