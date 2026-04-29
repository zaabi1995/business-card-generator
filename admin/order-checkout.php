<?php
/**
 * Order Checkout, Pay for print orders (Paymob or Credit)
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/Currency.php';
require_once INCLUDES_DIR . '/PrintShopBilling.php';
require_once INCLUDES_DIR . '/admin-layout.php';

requireAdmin();
$companyId = getCurrentCompanyId();

$orderId = (int)($_GET['order'] ?? 0);
if (!$orderId) {
    header('Location: ' . getBasePath() . 'admin/print.php');
    exit;
}

$error = '';
$success = '';

// Fetch checkout data early so POST handler can reference order totals
$checkout = PrintShopBilling::getCheckoutOptions($orderId);
if (isset($checkout['error'])) {
    header('Location: ' . getBasePath() . 'admin/print.php');
    exit;
}

$order = $checkout['order'];

// Verify company owns this order (unless super admin)
if ($order['company_id'] !== $companyId && !Auth::hasRole('super_admin')) {
    header('Location: ' . getBasePath() . 'admin/print.php');
    exit;
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { die('Invalid request'); }
    $action = $_POST['action'] ?? '';

    if ($action === 'pay_online') {
        // Support deposit payments
        $depositPct = (float)($_POST['deposit_percent'] ?? 0);
        if ($depositPct > 0 && $depositPct < 100) {
            $depositPct = max(10, min(90, $depositPct));
            $depositAmt = round(($order['total'] ?? 0) * ($depositPct / 100), 3);
            $balanceDue = round(($order['total'] ?? 0) - $depositAmt, 3);
            // Store deposit info first
            $db2 = Database::getInstance();
            $db2->exec("UPDATE print_orders SET deposit_percent = :dp, deposit_amount = :da, balance_due = :bd WHERE id = :id", [
                'dp' => $depositPct, 'da' => $depositAmt, 'bd' => $balanceDue, 'id' => $orderId
            ]);
            $result = PrintShopBilling::payOnlineAmount($orderId, $depositAmt, "Deposit ({$depositPct}%) for order #{$orderId}");
        } else {
            $result = PrintShopBilling::payOnline($orderId);
        }
        if (isset($result['checkout_url'])) {
            header('Location: ' . $result['checkout_url']);
            exit;
        }
        $error = $result['error'] ?? 'Payment failed';

    } elseif ($action === 'charge_credit') {
        $result = PrintShopBilling::chargeToCredit($orderId);
        if (!empty($result['success'])) {
            $success = 'Order charged to credit account successfully';
        } else {
            $error = $result['error'] ?? 'Credit charge failed';
        }

    } elseif ($action === 'upload_po') {
        $poNumber = trim($_POST['po_number'] ?? '');
        if (isset($_FILES['po_file']) && $_FILES['po_file']['error'] === UPLOAD_ERR_OK) {
            $result = PrintShopBilling::uploadPO($orderId, $_FILES['po_file'], $poNumber ?: null);
            if (isset($result['error'])) {
                $error = $result['error'];
            } else {
                $success = 'Purchase Order uploaded successfully';
            }
        } else {
            $error = 'Please select a file to upload';
        }

    } elseif ($action === 'request_credit') {
        $requestedLimit = (float)($_POST['requested_limit'] ?? 0);
        $notes = trim($_POST['request_notes'] ?? '');
        $order = PrintShopBilling::getOrderPaymentInfo($orderId);
        if ($order && $requestedLimit > 0) {
            require_once INCLUDES_DIR . '/CreditManager.php';
            $result = CreditManager::requestCredit($companyId, (int)$order['print_shop_id'], $requestedLimit, $notes ?: null);
            if (isset($result['error'])) {
                $error = $result['error'];
            } else {
                $success = 'Credit request submitted. You will be notified when approved.';
            }
        } else {
            $error = 'Please enter a valid credit limit';
        }
    }
}

// Check for payment callback status
if (isset($_GET['payment'])) {
    if ($_GET['payment'] === 'success') $success = t('order.payment_success');
    if ($_GET['payment'] === 'error')   $error   = $_GET['message'] ?? t('order.payment_failed');
}


$cur = $order['currency'] ?? 'OMR';

adminHeader(t('order.checkout_title'), 'print');
?>

<div class="max-w-3xl mx-auto">
    <div class="mb-6">
        <a href="<?= getAdminBasePath() ?>print<?= (defined('COMPANY_ADMIN_BASE') || !empty($_SESSION['company_slug'])) ? '' : '.php' ?>" class="text-sm text-gray-500 hover:text-gray-700">
            <i class="fa-solid fa-arrow-left mr-1"></i> <?= htmlspecialchars(t('order.back_to_orders')) ?>
        </a>
        <h1 class="text-2xl font-bold mt-2"><?= htmlspecialchars(t('order.checkout_title')) ?></h1>
    </div>

    <?php if ($error): ?>
        <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-xl mb-4">
            <i class="fa-solid fa-circle-xmark mr-1"></i> <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-xl mb-4">
            <i class="fa-solid fa-circle-check mr-1"></i> <?= htmlspecialchars($success) ?>
        </div>
    <?php endif; ?>

    <!-- Order Summary -->
    <div class="bg-white rounded-xl shadow-sm border p-6 mb-6">
        <h2 class="text-lg font-semibold mb-4"><i class="fa-solid fa-receipt mr-2 text-gray-400"></i><?= htmlspecialchars(t('order.order_summary')) ?></h2>
        <div class="grid grid-cols-2 gap-3 text-sm">
            <div><span class="text-gray-500"><?= htmlspecialchars(t('order.order_number')) ?>:</span> <strong><?= htmlspecialchars($order['order_number'] ?? '') ?></strong></div>
            <div><span class="text-gray-500"><?= htmlspecialchars(t('order.status')) ?>:</span>
                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium
                    <?= $order['status'] === 'confirmed' ? 'bg-green-100 text-green-700' : 'bg-yellow-100 text-yellow-700' ?>">
                    <?= htmlspecialchars(ucfirst($order['status'] ?? 'pending')) ?>
                </span>
            </div>
            <div><span class="text-gray-500"><?= htmlspecialchars(t('common.quantity')) ?>:</span> <?= htmlspecialchars(t('order.quantity_cards', ['n' => (int)$order['quantity']])) ?></div>
            <div><span class="text-gray-500"><?= htmlspecialchars(t('order.paper')) ?>:</span> <?= htmlspecialchars(ucfirst($order['paper_type'] ?? 'standard')) ?></div>
            <div><span class="text-gray-500"><?= htmlspecialchars(t('order.finish')) ?>:</span> <?= htmlspecialchars(ucfirst($order['finish'] ?? 'matte')) ?></div>
            <div><span class="text-gray-500"><?= htmlspecialchars(t('order.payment')) ?>:</span>
                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium
                    <?= $order['payment_status'] === 'paid' ? 'bg-green-100 text-green-700' : 'bg-orange-100 text-orange-700' ?>">
                    <?= htmlspecialchars(ucfirst($order['payment_status'] ?? 'pending')) ?>
                </span>
            </div>
        </div>
        <div class="border-t mt-4 pt-4 space-y-1">
            <div class="flex justify-between text-sm"><span class="text-gray-500"><?= htmlspecialchars(t('order.subtotal')) ?></span><span><?= number_format($order['subtotal'] ?? 0, 3) ?> <?= $cur ?></span></div>
            <?php if (($order['setup_fee'] ?? 0) > 0): ?>
                <div class="flex justify-between text-sm"><span class="text-gray-500"><?= htmlspecialchars(t('order.setup_fee')) ?></span><span><?= number_format($order['setup_fee'], 3) ?> <?= $cur ?></span></div>
            <?php endif; ?>
            <?php if (($order['shipping_fee'] ?? 0) > 0): ?>
                <div class="flex justify-between text-sm"><span class="text-gray-500"><?= htmlspecialchars(t('order.shipping_fee')) ?></span><span><?= number_format($order['shipping_fee'], 3) ?> <?= $cur ?></span></div>
            <?php endif; ?>
            <?php if (($order['express_fee'] ?? 0) > 0): ?>
                <div class="flex justify-between text-sm"><span class="text-gray-500"><?= htmlspecialchars(t('order.express_fee')) ?></span><span><?= number_format($order['express_fee'], 3) ?> <?= $cur ?></span></div>
            <?php endif; ?>
            <?php
            $userCur = Currency::getUserCurrency();
            $omrTotal = (float)($order['total'] ?? 0);
            $displayTotal = Currency::convert($omrTotal, $userCur);
            $showSecondary = $userCur !== 'OMR';
            ?>
            <div class="mt-3 rounded-2xl bg-gray-50 p-5 border border-gray-200">
                <div class="text-xs text-gray-500 uppercase tracking-wide mb-1"><?= htmlspecialchars(t('order.order_total')) ?></div>
                <div class="text-3xl font-extrabold text-gray-900">
                    <?= htmlspecialchars(Currency::format($displayTotal, $userCur)) ?>
                </div>
                <?php if ($showSecondary): ?>
                <div class="text-sm text-gray-500 mt-1">
                    <?= htmlspecialchars(Currency::format($omrTotal, 'OMR')) ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($order['payment_status'] !== 'paid'): ?>
    <!-- Payment Options -->
    <div class="bg-white rounded-xl shadow-sm border p-6 mb-6">
        <h2 class="text-lg font-semibold mb-4"><i class="fa-solid fa-credit-card mr-2 text-gray-400"></i><?= htmlspecialchars(t('order.payment_options')) ?></h2>

        <!-- Pay Now / Deposit -->
        <form method="POST" class="mb-4" id="payForm">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="pay_online">
            <input type="hidden" name="deposit_percent" id="depositPctInput" value="0">

            <!-- Deposit toggle for large orders -->
            <?php if (($order['total'] ?? 0) >= 50): ?>
            <div class="mb-4 p-4 bg-blue-50 border border-blue-100 rounded-xl">
                <label class="flex items-center gap-2 cursor-pointer mb-3">
                    <input type="checkbox" id="depositToggle" class="rounded border-gray-300 text-blue-600" onchange="toggleDeposit(this)">
                    <span class="text-sm font-medium text-gray-700"><?= htmlspecialchars(t('order.deposit_toggle')) ?></span>
                </label>
                <div id="depositOptions" class="hidden">
                    <p class="text-xs text-gray-500 mb-2"><?= htmlspecialchars(t('order.deposit_help')) ?></p>
                    <div class="flex items-center gap-2 flex-wrap">
                        <?php foreach ([25, 30, 50] as $pct): ?>
                        <button type="button" onclick="setDeposit(<?= $pct ?>)"
                                class="deposit-btn px-3 py-1.5 text-sm border border-blue-300 rounded-lg text-blue-700 hover:bg-blue-100 transition-colors font-medium">
                            <?= $pct ?>%
                            <span class="text-xs text-blue-500">(<?= number_format(($order['total'] ?? 0) * ($pct/100), 3) ?> <?= $cur ?>)</span>
                        </button>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <button type="submit" id="payBtn" class="w-full bg-blue-600 text-white py-3 px-6 rounded-xl font-medium hover:bg-blue-700 transition flex items-center justify-center gap-2">
                <i class="fa-solid fa-lock"></i>
                <span id="payBtnText"><?= htmlspecialchars(t('order.pay_now')) ?> &mdash; <?= number_format($order['total'] ?? 0, 3) ?> <?= $cur ?></span>
            </button>
            <p class="text-xs text-gray-500 mt-2 text-center"><?= htmlspecialchars(t('order.paymob_hint')) ?></p>
        </form>
        <script>
        function toggleDeposit(cb) {
            document.getElementById('depositOptions').classList.toggle('hidden', !cb.checked);
            if (!cb.checked) { setDeposit(0); }
        }
        function setDeposit(pct) {
            document.getElementById('depositPctInput').value = pct;
            const total = <?= floatval($order['total'] ?? 0) ?>;
            const cur = '<?= $cur ?>';
            const payBtnText = document.getElementById('payBtnText');
            document.querySelectorAll('.deposit-btn').forEach(b => b.classList.remove('bg-blue-600','text-white'));
            const payDepositTpl = <?= json_encode(t('order.pay_deposit')) ?>;
            const payNowTpl     = <?= json_encode(t('order.pay_now')) ?>;
            if (pct > 0) {
                const amt = (total * pct / 100).toFixed(3);
                payBtnText.textContent = payDepositTpl.replace(':pct', pct) + ` \u2014 ${amt} ${cur}`;
                event.target.closest('.deposit-btn').classList.add('bg-blue-600','text-white');
            } else {
                payBtnText.textContent = payNowTpl + ` \u2014 ${total.toFixed(3)} ${cur}`;
            }
        }
        </script>

        <?php if ($checkout['can_use_credit']): ?>
        <!-- Charge to Credit -->
        <form method="POST" class="mb-4">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="charge_credit">
            <button type="submit" class="w-full bg-emerald-600 text-white py-3 px-6 rounded-xl font-medium hover:bg-emerald-700 transition flex items-center justify-center gap-2">
                <i class="fa-solid fa-building-columns"></i>
                <?= htmlspecialchars(t('order.charge_credit')) ?>
            </button>
            <p class="text-xs text-gray-500 mt-2 text-center">
                <?= htmlspecialchars(t('order.available')) ?>: <?= number_format($checkout['available_credit'], 3) ?> <?= $cur ?>
                &middot; <?= htmlspecialchars(t('order.terms')) ?>: <?= strtoupper($checkout['credit_account']['payment_terms'] ?? 'net30') ?>
            </p>
        </form>
        <?php endif; ?>

        <?php if ($checkout['can_request_credit']): ?>
        <!-- Request Credit -->
        <details class="border border-gray-200 rounded-xl p-4 mt-4">
            <summary class="cursor-pointer font-medium text-gray-700 flex items-center gap-2">
                <i class="fa-solid fa-hand-holding-dollar text-gray-400"></i>
                <?= htmlspecialchars(t('order.request_credit')) ?>
            </summary>
            <form method="POST" class="mt-4 space-y-3">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="request_credit">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1"><?= htmlspecialchars(t('order.requested_limit', ['cur' => $cur])) ?></label>
                    <input type="number" name="requested_limit" step="0.001" min="0.001" required
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="<?= htmlspecialchars(t('order.limit_placeholder')) ?>">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1"><?= htmlspecialchars(t('order.notes_optional')) ?></label>
                    <textarea name="request_notes" rows="2" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500"
                              placeholder="<?= htmlspecialchars(t('order.notes_placeholder')) ?>"></textarea>
                </div>
                <button type="submit" class="bg-gray-700 text-white py-2 px-4 rounded-lg hover:bg-gray-800 transition text-sm">
                    <?= htmlspecialchars(t('order.submit_credit_request')) ?>
                </button>
            </form>
        </details>
        <?php endif; ?>
    </div>
    <?php else: ?>
    <div class="bg-green-50 border border-green-200 text-green-700 px-6 py-5 rounded-xl mb-6 text-center">
        <i class="fa-solid fa-circle-check text-2xl mb-2"></i>
        <p class="font-semibold"><?= htmlspecialchars(t('order.payment_complete')) ?></p>
        <p class="text-sm mb-3"><?= htmlspecialchars(t('order.paid_via', ['method' => ucfirst($order['payment_method'] ?? 'online')])) ?></p>
        <a href="<?= getAdminBasePath() ?>order-receipt<?= (defined('COMPANY_ADMIN_BASE') || !empty($_SESSION['company_slug'])) ? '' : '.php' ?>?order=<?= $orderId ?>"
           class="inline-flex items-center gap-2 px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg text-sm font-medium transition-colors">
            <i class="fa-solid fa-receipt"></i> <?= htmlspecialchars(t('order.view_receipt')) ?>
        </a>
    </div>
    <?php endif; ?>

    <!-- Upload PO -->
    <div class="bg-white rounded-xl shadow-sm border p-6 mb-6">
        <h2 class="text-lg font-semibold mb-4"><i class="fa-solid fa-file-invoice mr-2 text-gray-400"></i><?= htmlspecialchars(t('order.po_title')) ?></h2>
        <?php if (!empty($order['po_file_path'])): ?>
            <div class="bg-gray-50 rounded-lg p-3 mb-3 flex items-center justify-between">
                <div>
                    <span class="text-sm text-green-600 font-medium"><i class="fa-solid fa-check mr-1"></i><?= htmlspecialchars(t('order.po_uploaded')) ?></span>
                    <?php if (!empty($order['po_number'])): ?>
                        <span class="text-sm text-gray-500 ml-2">#<?= htmlspecialchars($order['po_number']) ?></span>
                    <?php endif; ?>
                </div>
                <a href="<?= getBasePath() . htmlspecialchars($order['po_file_path']) ?>" target="_blank" class="text-blue-600 hover:text-blue-800 text-sm">
                    <i class="fa-solid fa-eye mr-1"></i><?= htmlspecialchars(t('order.po_view')) ?>
                </a>
            </div>
        <?php endif; ?>
        <form method="POST" enctype="multipart/form-data" class="space-y-3">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="upload_po">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1"><?= htmlspecialchars(t('order.po_number_label')) ?></label>
                    <input type="text" name="po_number" class="w-full border border-gray-300 rounded-lg px-3 py-2" placeholder="<?= htmlspecialchars(t('order.po_number_placeholder')) ?>">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1"><?= htmlspecialchars(t('order.po_document_label')) ?></label>
                    <input type="file" name="po_file" accept=".pdf,.jpg,.jpeg,.png" required class="w-full text-sm">
                    <p class="text-xs text-gray-400 mt-1"><?= htmlspecialchars(t('order.po_document_hint')) ?></p>
                </div>
            </div>
            <button type="submit" class="bg-gray-600 text-white py-2 px-4 rounded-lg hover:bg-gray-700 transition text-sm">
                <i class="fa-solid fa-upload mr-1"></i> <?= htmlspecialchars(t('order.upload_po')) ?>
            </button>
        </form>
    </div>
</div>

<?php adminFooter(); ?>
