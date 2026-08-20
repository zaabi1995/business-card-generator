<?php
/**
 * Print Shop, Credit Accounts Management
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
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { die(htmlspecialchars(t('printshopcredit.invalid_request'))); }
    $action    = $_POST['action'] ?? '';
    $accountId = trim($_POST['account_id'] ?? '');

    // Verify account belongs to this shop before acting
    $actAccount = $accountId ? CreditManager::getAccountById($accountId) : null;
    if ($accountId && (!$actAccount || (int)$actAccount['print_shop_id'] !== $shopId)) {
        $error = t('printshopcredit.account_not_found');
        goto render;
    }

    if ($action === 'approve' && $accountId) {
        $limit = (float)($_POST['credit_limit'] ?? 0);
        $exposure = strlen(trim($_POST['exposure_limit'] ?? '')) > 0 ? (float)$_POST['exposure_limit'] : null;
        $terms = $_POST['payment_terms'] ?? 'net30';
        if ($limit <= 0) {
            $error = t('printshopcredit.limit_gt_zero');
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
                $success = t('printshopcredit.approved');
            } else {
                $error = t('printshopcredit.approve_failed');
            }
        }

    } elseif ($action === 'reject' && $accountId) {
        $reason = trim($_POST['reason'] ?? t('printshopcredit.reject_default'));
        $ok = CreditManager::reject($accountId, $reason ?: t('printshopcredit.reject_default'), $user['id']);
        $success = $ok ? t('printshopcredit.rejected') : t('printshopcredit.reject_failed');

    } elseif ($action === 'suspend' && $accountId) {
        $ok = CreditManager::suspend($accountId);
        $success = $ok ? t('printshopcredit.suspended') : t('printshopcredit.suspend_failed');

    } elseif ($action === 'reactivate' && $accountId) {
        $ok = CreditManager::reactivate($accountId);
        $success = $ok ? t('printshopcredit.reactivated') : t('printshopcredit.reactivate_failed');

    } elseif ($action === 'adjust_limit' && $accountId) {
        $newLimit    = (float)($_POST['credit_limit'] ?? 0);
        $terms       = $_POST['payment_terms'] ?? null;
        $exposure    = strlen(trim($_POST['exposure_limit'] ?? '')) > 0 ? (float)$_POST['exposure_limit'] : 0;
        if ($newLimit <= 0) {
            $error = t('printshopcredit.limit_gt_zero');
        } else {
            $result = CreditManager::adjustLimit($accountId, $newLimit, $terms, $exposure ?: null);
            $success = isset($result['error']) ? '' : t('printshopcredit.updated');
            if (isset($result['error'])) $error = $result['error'];
        }

    } elseif ($action === 'upload_po' && $accountId) {
        $poNumber = trim($_POST['po_number'] ?? '');
        if (isset($_FILES['po_file']) && $_FILES['po_file']['error'] === UPLOAD_ERR_OK) {
            $result = CreditManager::uploadPO($accountId, $_FILES['po_file'], $poNumber ?: null);
            $success = isset($result['error']) ? '' : t('printshopcredit.po_uploaded');
            if (isset($result['error'])) $error = $result['error'];
        } else {
            $error = t('printshopcredit.please_select');
        }
    }
}

render:
$pendingAccounts   = CreditManager::getShopAccounts($shopId, 'pending');
$activeAccounts    = CreditManager::getShopAccounts($shopId, 'approved');
$suspendedAccounts = CreditManager::getShopAccounts($shopId, 'suspended');
$outstanding       = CreditManager::getOutstandingSummary($shopId);
$totalOutstanding  = array_sum(array_column($outstanding, 'balance_used'));

require_once INCLUDES_DIR . '/printshop-layout.php';
printshopHeader(t('printshoppages.title_credit_accounts', ['shop' => $printShop['name']]), 'credit');
?>
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
            <p class="text-sm text-gray-500"><?= htmlspecialchars(t('printshopcredit.stat_pending')) ?></p>
            <p class="text-2xl font-bold text-yellow-600"><?= count($pendingAccounts) ?></p>
        </div>
        <div class="bg-white rounded-xl border p-4">
            <p class="text-sm text-gray-500"><?= htmlspecialchars(t('printshopcredit.stat_active')) ?></p>
            <p class="text-2xl font-bold text-green-600"><?= count($activeAccounts) ?></p>
        </div>
        <div class="bg-white rounded-xl border p-4">
            <p class="text-sm text-gray-500"><?= htmlspecialchars(t('printshopcredit.stat_outstanding')) ?></p>
            <p class="text-2xl font-bold text-blue-600"><?= formatPriceHtml((float)$totalOutstanding, 'lg') ?></p>
        </div>
        <div class="bg-white rounded-xl border p-4">
            <p class="text-sm text-gray-500"><?= htmlspecialchars(t('printshopcredit.stat_suspended')) ?></p>
            <p class="text-2xl font-bold text-red-600"><?= count($suspendedAccounts) ?></p>
        </div>
    </div>

    <!-- ── Pending Requests ── -->
    <?php if ($pendingAccounts): ?>
    <div class="bg-white rounded-xl border border-yellow-200 mb-6">
        <div class="px-6 py-4 border-b border-yellow-100 bg-yellow-50">
            <h2 class="font-semibold text-yellow-800"><i class="fa-solid fa-clock mr-2"></i><?= htmlspecialchars(str_replace(':n', (string) count($pendingAccounts), t('printshopcredit.pending_h'))) ?></h2>
        </div>
        <div class="divide-y">
        <?php foreach ($pendingAccounts as $acc): ?>
        <div class="px-6 py-5">
            <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-4">
                <!-- Company Info -->
                <div class="flex-1">
                    <p class="font-semibold text-gray-900"><?= htmlspecialchars($acc['company_name']) ?></p>
                    <p class="text-sm text-gray-500"><?= htmlspecialchars($acc['company_email']) ?></p>
                    <p class="text-sm mt-1"><?= htmlspecialchars(t('printshopcredit.requested_prefix')) ?> <strong><?= formatPriceHtml((float)$acc['requested_limit']) ?></strong></p>
                    <?php if ($acc['request_notes']): ?>
                        <p class="text-sm text-gray-500 mt-1 italic">"<?= htmlspecialchars($acc['request_notes']) ?>"</p>
                    <?php endif; ?>
                    <!-- PO attached by client -->
                    <?php if (!empty($acc['po_file_path'])): ?>
                    <div class="mt-2 inline-flex items-center gap-2 px-3 py-1 bg-blue-50 rounded-lg text-sm text-blue-700">
                        <i class="fa-solid fa-file-invoice"></i>
                        <?= !empty($acc['po_number']) ? htmlspecialchars(str_replace(':num', $acc['po_number'], t('printshopcredit.po_attached_n'))) : htmlspecialchars(t('printshopcredit.po_attached')) ?>
                        <a href="<?= getBasePath() . htmlspecialchars($acc['po_file_path']) ?>" target="_blank" class="underline text-xs ml-1"><?= htmlspecialchars(t('printshopcredit.view')) ?></a>
                    </div>
                    <?php endif; ?>
                    <p class="text-xs text-gray-400 mt-2"><?= htmlspecialchars(str_replace(':date', date('d M Y', dbTs($acc['created_at'])), t('printshopcredit.requested_on'))) ?></p>
                </div>

                <!-- Approve Form -->
                <form method="POST" enctype="multipart/form-data" class="flex flex-col gap-3 lg:w-96">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="approve">
                    <input type="hidden" name="account_id" value="<?= htmlspecialchars($acc['id']) ?>">
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="text-xs text-gray-500 block mb-1" for="credit_limit_<?= htmlspecialchars($acc['id']) ?>"><?= htmlspecialchars(t('printshopcredit.field_limit')) ?></label>
                            <input type="number" name="credit_limit" id="credit_limit_<?= htmlspecialchars($acc['id']) ?>" step="0.001" min="0.001"
                                   value="<?= $acc['requested_limit'] ?>"
                                   aria-label="<?= htmlspecialchars(t('printshopcredit.field_limit')) ?>"
                                   class="w-full border rounded-lg px-2 py-1.5 text-sm focus:ring-2 focus:ring-green-400 tabular-nums" required>
                        </div>
                        <div>
                            <label class="text-xs text-gray-500 block mb-1" for="exposure_limit_<?= htmlspecialchars($acc['id']) ?>"><?= htmlspecialchars(t('printshopcredit.field_exposure')) ?></label>
                            <input type="number" name="exposure_limit" id="exposure_limit_<?= htmlspecialchars($acc['id']) ?>" step="0.001" min="0"
                                   placeholder="<?= htmlspecialchars(t('printshopcredit.exposure_same_ph')) ?>"
                                   aria-label="<?= htmlspecialchars(t('printshopcredit.field_exposure')) ?>"
                                   class="w-full border rounded-lg px-2 py-1.5 text-sm focus:ring-2 focus:ring-green-400 tabular-nums">
                            <p class="text-xs text-gray-400 mt-0.5"><?= htmlspecialchars(t('printshopcredit.exposure_hint')) ?></p>
                        </div>
                    </div>
                    <div>
                        <label class="text-xs text-gray-500 block mb-1" for="payment_terms_<?= htmlspecialchars($acc['id']) ?>"><?= htmlspecialchars(t('printshopcredit.field_terms')) ?></label>
                        <select name="payment_terms" id="payment_terms_<?= htmlspecialchars($acc['id']) ?>"
                                aria-label="<?= htmlspecialchars(t('printshopcredit.field_terms')) ?>"
                                class="w-full border rounded-lg px-2 py-1.5 text-sm">
                            <option value="net15"><?= htmlspecialchars(t('printshopcredit.terms_net15')) ?></option>
                            <option value="net30" selected><?= htmlspecialchars(t('printshopcredit.terms_net30')) ?></option>
                            <option value="net60"><?= htmlspecialchars(t('printshopcredit.terms_net60')) ?></option>
                            <option value="net90"><?= htmlspecialchars(t('printshopcredit.terms_net90')) ?></option>
                        </select>
                    </div>
                    <?php if (empty($acc['po_file_path'])): ?>
                    <div>
                        <label class="text-xs text-gray-500 block mb-1"><?= htmlspecialchars(t('printshopcredit.upload_po_optional')) ?></label>
                        <div class="grid grid-cols-2 gap-2">
                            <input type="text" name="po_number" placeholder="<?= htmlspecialchars(t('printshopcredit.po_number_ph')) ?>"
                                   aria-label="<?= htmlspecialchars(t('printshopcredit.po_number_ph')) ?>"
                                   class="border rounded-lg px-2 py-1.5 text-sm">
                            <input type="file" name="po_file" accept=".pdf,.jpg,.jpeg,.png"
                                   aria-label="<?= htmlspecialchars(t('printshopcredit.upload_po_optional')) ?>"
                                   class="text-xs py-1.5">
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="flex gap-2">
                        <button type="submit" class="flex-1 bg-green-600 text-white py-2 rounded-lg text-sm font-medium hover:bg-green-700 transition">
                            <i class="fa-solid fa-check mr-1"></i> <?= htmlspecialchars(t('printshopcredit.btn_approve')) ?>
                        </button>
                        <button type="button" onclick="this.closest('form').querySelector('[name=action]').value='reject'; this.closest('form').submit();"
                                class="px-4 bg-red-100 text-red-700 rounded-lg text-sm hover:bg-red-200 transition">
                            <?= htmlspecialchars(t('printshopcredit.btn_reject')) ?>
                        </button>
                    </div>
                    <input type="hidden" name="reason" value="<?= htmlspecialchars(t('printshopcredit.reject_default')) ?>">
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
            <h2 class="font-semibold text-green-700"><i class="fa-solid fa-circle-check mr-2"></i><?= htmlspecialchars(str_replace(':n', (string) count($activeAccounts), t('printshopcredit.active_h'))) ?></h2>
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
                            <?= str_replace(':amt', formatPriceHtml((float)$acc['exposure_limit']), htmlspecialchars(t('printshopcredit.exposure_badge'))) ?>
                        </span>
                        <?php endif; ?>
                    </div>
                    <p class="text-sm text-gray-500 mb-3"><?= htmlspecialchars($acc['company_email']) ?></p>
                    <!-- Usage bar -->
                    <div class="flex items-center gap-3 text-sm mb-1">
                        <span class="text-gray-500"><?= htmlspecialchars(t('printshopcredit.limit_prefix')) ?> <strong><?= formatPriceHtml((float)$acc['credit_limit'], 'sm') ?></strong></span>
                        <span class="text-red-600"><?= htmlspecialchars(t('printshopcredit.used_prefix')) ?> <strong><?= formatPriceHtml((float)$acc['balance_used'], 'sm') ?></strong></span>
                        <span class="text-green-600"><?= htmlspecialchars(t('printshopcredit.avail_prefix')) ?> <strong><?= formatPriceHtml((float)$available, 'sm') ?></strong></span>
                    </div>
                    <div class="w-full max-w-xs h-2 bg-gray-100 rounded-full overflow-hidden">
                        <div class="h-full rounded-full <?= $usedPct >= 90 ? 'bg-red-500' : ($usedPct >= 60 ? 'bg-yellow-400' : 'bg-green-500') ?>"
                             style="width:<?= $usedPct ?>%"></div>
                    </div>
                    <?php if (!empty($acc['po_file_path'])): ?>
                    <div class="mt-2 inline-flex items-center gap-2 text-xs text-blue-700">
                        <i class="fa-solid fa-file-invoice"></i>
                        <?= !empty($acc['po_number']) ? htmlspecialchars(str_replace(':num', $acc['po_number'], t('printshopcredit.po_on_file_n'))) : htmlspecialchars(t('printshopcredit.po_on_file')) ?>
                        <a href="<?= getBasePath() . htmlspecialchars($acc['po_file_path']) ?>" target="_blank" class="underline"><?= htmlspecialchars(t('printshopcredit.view')) ?></a>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Actions panel -->
                <div class="flex flex-col gap-3 xl:w-80">
                    <!-- Edit limit/exposure/terms -->
                    <details class="border rounded-xl">
                        <summary class="px-4 py-2 cursor-pointer text-sm font-medium text-gray-700 hover:bg-gray-50 rounded-xl">
                            <i class="fa-solid fa-pen-to-square mr-1 text-blue-500"></i> <?= htmlspecialchars(t('printshopcredit.edit_limit_terms')) ?>
                        </summary>
                        <form method="POST" class="px-4 pb-4 pt-2 space-y-2">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="adjust_limit">
                            <input type="hidden" name="account_id" value="<?= htmlspecialchars($acc['id']) ?>">
                            <div class="grid grid-cols-2 gap-2">
                                <div>
                                    <label class="text-xs text-gray-500" for="edit_credit_limit_<?= htmlspecialchars($acc['id']) ?>"><?= htmlspecialchars(t('printshopcredit.field_limit_edit')) ?></label>
                                    <input type="number" name="credit_limit" id="edit_credit_limit_<?= htmlspecialchars($acc['id']) ?>" step="0.001" min="<?= $acc['balance_used'] ?>"
                                           value="<?= $acc['credit_limit'] ?>"
                                           aria-label="<?= htmlspecialchars(t('printshopcredit.field_limit_edit')) ?>"
                                           class="w-full border rounded px-2 py-1.5 text-sm tabular-nums" required>
                                </div>
                                <div>
                                    <label class="text-xs text-gray-500" for="edit_exposure_limit_<?= htmlspecialchars($acc['id']) ?>"><?= htmlspecialchars(t('printshopcredit.field_exposure_edit')) ?></label>
                                    <input type="number" name="exposure_limit" id="edit_exposure_limit_<?= htmlspecialchars($acc['id']) ?>" step="0.001" min="0"
                                           value="<?= $acc['exposure_limit'] ?? '' ?>" placeholder="<?= htmlspecialchars(t('printshopcredit.exposure_no_cap_ph')) ?>"
                                           aria-label="<?= htmlspecialchars(t('printshopcredit.field_exposure_edit')) ?>"
                                           class="w-full border rounded px-2 py-1.5 text-sm tabular-nums">
                                </div>
                            </div>
                            <div>
                                <label class="text-xs text-gray-500" for="edit_payment_terms_<?= htmlspecialchars($acc['id']) ?>"><?= htmlspecialchars(t('printshopcredit.field_terms')) ?></label>
                                <select name="payment_terms" id="edit_payment_terms_<?= htmlspecialchars($acc['id']) ?>"
                                        aria-label="<?= htmlspecialchars(t('printshopcredit.field_terms')) ?>"
                                        class="w-full border rounded px-2 py-1.5 text-sm">
                                    <?php foreach (['net15','net30','net60','net90'] as $val): ?>
                                    <option value="<?= $val ?>" <?= ($acc['payment_terms'] ?? 'net30') === $val ? 'selected' : '' ?>><?= htmlspecialchars(t('printshopcredit.terms_' . $val)) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="submit" class="w-full bg-blue-600 text-white py-1.5 rounded-lg text-sm hover:bg-blue-700 transition">
                                <?= htmlspecialchars(t('printshopcredit.btn_save_changes')) ?>
                            </button>
                        </form>
                    </details>

                    <!-- Upload / replace PO -->
                    <details class="border rounded-xl">
                        <summary class="px-4 py-2 cursor-pointer text-sm font-medium text-gray-700 hover:bg-gray-50 rounded-xl">
                            <i class="fa-solid fa-file-arrow-up mr-1 text-purple-500"></i>
                            <?= htmlspecialchars(empty($acc['po_file_path']) ? t('printshopcredit.attach_po') : t('printshopcredit.replace_po')) ?>
                        </summary>
                        <form method="POST" enctype="multipart/form-data" class="px-4 pb-4 pt-2 space-y-2">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="upload_po">
                            <input type="hidden" name="account_id" value="<?= htmlspecialchars($acc['id']) ?>">
                            <input type="text" name="po_number" placeholder="<?= htmlspecialchars(t('printshopcredit.po_ref_ph')) ?>"
                                   value="<?= htmlspecialchars($acc['po_number'] ?? '') ?>"
                                   aria-label="<?= htmlspecialchars(t('printshopcredit.po_ref_ph')) ?>"
                                   class="w-full border rounded px-2 py-1.5 text-sm">
                            <input type="file" name="po_file" accept=".pdf,.jpg,.jpeg,.png" required
                                   aria-label="<?= htmlspecialchars(empty($acc['po_file_path']) ? t('printshopcredit.attach_po') : t('printshopcredit.replace_po')) ?>"
                                   class="text-xs w-full">
                            <p class="text-xs text-gray-400"><?= htmlspecialchars(t('printshopcredit.file_size_hint')) ?></p>
                            <button type="submit" class="w-full bg-purple-600 text-white py-1.5 rounded-lg text-sm hover:bg-purple-700 transition">
                                <?= htmlspecialchars(t('printshopcredit.btn_upload')) ?>
                            </button>
                        </form>
                    </details>

                    <!-- Actions row -->
                    <div class="flex gap-2">
                        <a href="credit-ledger.php?account=<?= urlencode($acc['id']) ?>"
                           class="flex-1 text-center py-1.5 border border-blue-200 text-blue-600 rounded-lg text-sm hover:bg-blue-50 transition">
                            <i class="fa-solid fa-list mr-1"></i> <?= htmlspecialchars(t('printshopcredit.btn_ledger')) ?>
                        </a>
                        <form method="POST" class="flex-1">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="suspend">
                            <input type="hidden" name="account_id" value="<?= htmlspecialchars($acc['id']) ?>">
                            <button type="submit" onclick="return confirm(<?= htmlspecialchars(json_encode(t('printshopcredit.confirm_suspend'), JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>)"
                                    class="w-full py-1.5 border border-red-200 text-red-600 rounded-lg text-sm hover:bg-red-50 transition">
                                <?= htmlspecialchars(t('printshopcredit.btn_suspend')) ?>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
        <?php else: ?>
        <p class="px-6 py-8 text-center text-gray-500"><?= htmlspecialchars(t('printshopcredit.no_active')) ?></p>
        <?php endif; ?>
    </div>

    <!-- ── Suspended ── -->
    <?php if ($suspendedAccounts): ?>
    <div class="bg-white rounded-xl border mb-6">
        <div class="px-6 py-4 border-b"><h2 class="font-semibold text-red-700"><i class="fa-solid fa-ban mr-2"></i><?= htmlspecialchars(str_replace(':n', (string) count($suspendedAccounts), t('printshopcredit.suspended_h'))) ?></h2></div>
        <div class="divide-y">
        <?php foreach ($suspendedAccounts as $acc): ?>
        <div class="px-6 py-4 flex items-center justify-between gap-4">
            <div>
                <p class="font-medium"><?= htmlspecialchars($acc['company_name']) ?></p>
                <p class="text-sm text-gray-500">
                    <?= htmlspecialchars(t('printshopcredit.limit_prefix')) ?> <?= formatPriceHtml((float)$acc['credit_limit'], 'sm') ?> &middot;
                    <?= htmlspecialchars(t('printshopcredit.outstanding_prefix')) ?> <span class="text-red-600 font-medium"><?= formatPriceHtml((float)$acc['balance_used'], 'sm') ?></span>
                </p>
            </div>
            <div class="flex gap-2">
                <a href="credit-ledger.php?account=<?= urlencode($acc['id']) ?>"
                   class="px-3 py-1.5 border border-blue-200 text-blue-600 rounded-lg text-sm hover:bg-blue-50 transition">
                    <?= htmlspecialchars(t('printshopcredit.btn_ledger')) ?>
                </a>
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="reactivate">
                    <input type="hidden" name="account_id" value="<?= htmlspecialchars($acc['id']) ?>">
                    <button type="submit" class="px-3 py-1.5 bg-green-600 text-white rounded-lg text-sm hover:bg-green-700 transition">
                        <?= htmlspecialchars(t('printshopcredit.btn_reactivate')) ?>
                    </button>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
<?php printshopFooter(); ?>
