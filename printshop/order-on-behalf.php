<?php
/**
 * Internal-provider mode: place a print order on behalf of a client.
 *
 * Mirrors `admin/order_print.php` but the print-shop is fixed to the
 * operator's shop and the company comes from the query string. The
 * server-side createOrder picks up the operator id from the session
 * and stamps placed_by_operator_id on the resulting print_orders row.
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/PrintShopAuth.php';
require_once INCLUDES_DIR . '/PrintShop.php';
require_once INCLUDES_DIR . '/PrintShopIntegration.php';
require_once INCLUDES_DIR . '/Currency.php';

$ctx = PrintShopAuth::requireInternalProvider();
$shop = $ctx['shop'];
$shopId = (int) $shop['id'];

$employeeId = trim($_GET['employee'] ?? $_POST['employee_id'] ?? '');
$companyId  = trim($_GET['company']  ?? $_POST['company_id']  ?? '');
if ($employeeId === '' || $companyId === '') {
    header('Location: ' . getBasePath() . 'printshop/clients.php');
    exit;
}

$db  = Database::getInstance();
$pdo = $db->getConnection();

$company = $pdo->prepare("SELECT * FROM companies WHERE id = ? LIMIT 1");
$company->execute([$companyId]);
$company = $company->fetch(PDO::FETCH_ASSOC);

$employee = $pdo->prepare("SELECT * FROM employees WHERE id = ? AND company_id = ? LIMIT 1");
$employee->execute([$employeeId, $companyId]);
$employee = $employee->fetch(PDO::FETCH_ASSOC);

if (!$company || !$employee) {
    header('Location: ' . getBasePath() . 'printshop/clients.php');
    exit;
}

$cardsBase = getBasePath() . 'uploads/companies/' . $companyId . '/cards/';
$cardFront = $employee['front_file_path'] ? $cardsBase . $employee['front_file_path'] : '';
$cardBack  = $employee['back_file_path']  ? $cardsBase . $employee['back_file_path']  : '';

// Apply per-client effective pricing for Alpine + server math
$shop = PrintShop::getById($shopId);
$effectivePricing = PrintShop::getEffectivePricing($shop, $companyId);
$shop['pricing'] = json_encode($effectivePricing);

$message = null;
$messageType = 'success';
$orderSuccess = false;
$orderRef = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = 'Invalid request, please try again.';
        $messageType = 'error';
    } else {
        $orderData = [
            'company_id'        => $companyId,
            'employee_id'       => $employeeId,
            'user_id'           => null,
            'print_shop_id'     => $shopId,
            'quantity'          => max(1, (int)($_POST['quantity'] ?? 100)),
            'paper_type'        => $_POST['paper_type'] ?? 'matte',
            'finish'            => $_POST['finish']     ?? 'standard',
            'card_size'         => 'standard',
            'card_front_url'    => $cardFront,
            'card_back_url'     => $cardBack,
            'card_template_id'  => null,
            'shipping_name'     => trim($_POST['shipping_name']    ?? ($company['name'] ?? '')),
            'shipping_address'  => trim($_POST['shipping_address'] ?? ''),
            'shipping_city'     => trim($_POST['shipping_city']    ?? ''),
            'shipping_country'  => trim($_POST['shipping_country'] ?? 'OM'),
            'shipping_phone'    => trim($_POST['shipping_phone']   ?? ''),
            'notes'             => trim($_POST['notes'] ?? ''),
            'payment_status'    => 'pending',
        ];
        $result = PrintShopIntegration::createOrder($orderData);
        if (!empty($result['success'])) {
            $orderSuccess = true;
            $orderRef = $result;
            $message = 'Order #' . $result['order_id'] . ' placed for ' . $company['name'] . '. Total: ' . Currency::format($result['total'], $result['currency'] ?? 'OMR');
        } elseif (($result['error'] ?? '') === 'min_quantity') {
            $message = $result['message'] ?? 'Below minimum quantity.';
            $messageType = 'error';
        } else {
            $message = 'Error: ' . ($result['error'] ?? 'Unknown error');
            $messageType = 'error';
        }
    }
}

$pageTitle = 'Order , ' . $company['name'];
$bodyClass = 'bg-gray-50';
require_once INCLUDES_DIR . '/ui-header.php';
?>

<nav class="bg-white border-b border-gray-200 fixed top-0 left-0 right-0 z-50">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">
            <div class="flex items-center gap-4">
                <a href="<?= getBasePath() ?>printshop/dashboard.php" class="flex items-center gap-2">
                    <img src="<?= getBasePath() ?>assets/images/logo.svg" alt="Cardify" class="h-8 w-auto">
                </a>
                <span class="text-gray-300">|</span>
                <span class="font-semibold text-gray-900"><?= sanitize($shop['name']) ?></span>
            </div>
            <div class="flex items-center gap-4 text-sm">
                <a href="dashboard.php" class="text-gray-500 hover:text-gray-700"><i class="fa-solid fa-chart-pie mr-1"></i>Dashboard</a>
                <a href="orders.php" class="text-gray-500 hover:text-gray-700"><i class="fa-solid fa-box mr-1"></i>Orders</a>
                <a href="clients.php" class="text-blue-600 font-medium"><i class="fa-solid fa-building mr-1"></i><?= htmlspecialchars(t('printshopinternal.nav_clients')) ?></a>
                <a href="operators.php" class="text-gray-500 hover:text-gray-700"><i class="fa-solid fa-users-gear mr-1"></i><?= htmlspecialchars(t('printshopinternal.nav_operators')) ?></a>
                <a href="<?= getBasePath() ?>logout.php" class="text-gray-500 hover:text-red-600"><i class="fa-solid fa-sign-out-alt"></i></a>
            </div>
        </div>
    </div>
</nav>

<div class="pt-20 pb-12 px-4 sm:px-6 lg:px-8 max-w-5xl mx-auto" x-data="orderForm()" x-init="init()">

    <div class="mb-2 text-sm text-gray-500">
        <a href="client.php?company=<?= urlencode($companyId) ?>" class="hover:underline"><i class="fa-solid fa-arrow-left mr-1"></i><?= sanitize($company['name']) ?></a>
    </div>

    <?php if ($orderSuccess): ?>
    <div class="bg-white rounded-2xl border border-gray-200 p-8 text-center shadow-sm max-w-2xl mx-auto">
        <div class="w-20 h-20 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
            <i class="fa-solid fa-check text-green-600 text-3xl"></i>
        </div>
        <h3 class="text-xl font-semibold text-gray-900 mb-2"><?= htmlspecialchars(t('printshopinternal.order_placed_h')) ?></h3>
        <p class="text-gray-600 mb-6"><?= sanitize($message) ?></p>
        <div class="flex items-center justify-center gap-3 flex-wrap">
            <a href="orders.php" class="px-5 py-2.5 bg-gray-900 hover:bg-black text-white rounded-lg font-medium"><i class="fa-solid fa-box mr-2"></i><?= htmlspecialchars(t('printshopinternal.view_orders_btn')) ?></a>
            <a href="print-sheet.php?employee=<?= urlencode($employeeId) ?>&company=<?= urlencode($companyId) ?>" target="_blank"
               class="px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium"><i class="fa-solid fa-print mr-2"></i><?= htmlspecialchars(t('printshopinternal.download_sheet_btn')) ?></a>
            <a href="client.php?company=<?= urlencode($companyId) ?>" class="px-5 py-2.5 bg-white border border-gray-200 text-gray-700 rounded-lg font-medium"><?= htmlspecialchars(t('printshopinternal.back_to_client')) ?></a>
        </div>
    </div>
    <?php else: ?>

    <?php if ($message): ?>
    <div class="mb-4 p-3 rounded-lg bg-amber-50 border border-amber-200 text-amber-800 text-sm">
        <?= sanitize($message) ?>
    </div>
    <?php endif; ?>

    <form method="POST" class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <?= csrfField() ?>
        <input type="hidden" name="employee_id" value="<?= sanitize($employeeId) ?>">
        <input type="hidden" name="company_id"  value="<?= sanitize($companyId) ?>">

        <!-- Card preview -->
        <div class="lg:col-span-1 space-y-3">
            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                <div class="p-3 border-b border-gray-100 text-xs uppercase tracking-wide text-gray-500"><?= htmlspecialchars(t('printshopinternal.preview_front')) ?></div>
                <div class="aspect-[1.7/1] bg-gray-50 flex items-center justify-center">
                    <?php if ($cardFront): ?><img src="<?= sanitize($cardFront) ?>" class="w-full h-full object-contain"><?php else: ?><i class="fa-solid fa-id-card text-3xl text-gray-300"></i><?php endif; ?>
                </div>
            </div>
            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                <div class="p-3 border-b border-gray-100 text-xs uppercase tracking-wide text-gray-500"><?= htmlspecialchars(t('printshopinternal.preview_back')) ?></div>
                <div class="aspect-[1.7/1] bg-gray-50 flex items-center justify-center">
                    <?php if ($cardBack): ?><img src="<?= sanitize($cardBack) ?>" class="w-full h-full object-contain"><?php else: ?><i class="fa-solid fa-id-card text-3xl text-gray-300"></i><?php endif; ?>
                </div>
            </div>
            <div class="bg-blue-50 border border-blue-100 rounded-xl p-3 text-sm">
                <p class="font-semibold text-blue-900"><?= sanitize($employee['name_en'] ?? $employee['name_ar'] ?? '') ?></p>
                <p class="text-blue-700 text-xs"><?= sanitize($employee['position_en'] ?? $employee['position_ar'] ?? '') ?></p>
                <p class="text-blue-600 text-xs mt-2"><?= sanitize($company['name']) ?> &middot; /<?= sanitize($company['slug']) ?></p>
            </div>
        </div>

        <!-- Order details -->
        <div class="lg:col-span-2 space-y-6">
            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-5">
                <h2 class="font-semibold text-gray-900 mb-4"><?= htmlspecialchars(t('printshopinternal.order_h')) ?></h2>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5"><?= htmlspecialchars(t('printshopinternal.field_quantity')) ?></label>
                        <input type="number" name="quantity" x-model.number="quantity" @input="updatePrice()" min="1" step="1"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5"><?= htmlspecialchars(t('printshopinternal.field_paper')) ?></label>
                        <select name="paper_type" x-model="paperType" @change="updatePrice()" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                            <option value="uncoated"><?= htmlspecialchars(t('printshopinternal.paper_uncoated')) ?></option>
                            <option value="matte"><?= htmlspecialchars(t('printshopinternal.paper_matte')) ?></option>
                            <option value="silk"><?= htmlspecialchars(t('printshopinternal.paper_silk')) ?></option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5"><?= htmlspecialchars(t('printshopinternal.field_finish')) ?></label>
                        <select name="finish" x-model="finish" @change="updatePrice()" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                            <option value="standard"><?= htmlspecialchars(t('printshopinternal.finish_standard')) ?></option>
                            <option value="rounded_corners"><?= htmlspecialchars(t('printshopinternal.finish_rounded')) ?></option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5"><?= htmlspecialchars(t('printshopinternal.field_phone')) ?></label>
                        <input type="text" name="shipping_phone" value="<?= sanitize($employee['mobile'] ?? '') ?>"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-4">
                    <div class="sm:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-1.5"><?= htmlspecialchars(t('printshopinternal.field_shipping_address')) ?></label>
                        <input type="text" name="shipping_address"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5"><?= htmlspecialchars(t('printshopinternal.field_city')) ?></label>
                        <input type="text" name="shipping_city" value="Muscat"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5"><?= htmlspecialchars(t('printshopinternal.field_country')) ?></label>
                        <input type="text" name="shipping_country" value="OM"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                    </div>
                </div>

                <div class="mt-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1.5"><?= htmlspecialchars(t('printshopinternal.field_notes')) ?></label>
                    <textarea name="notes" rows="2" class="w-full px-3 py-2 border border-gray-300 rounded-lg"></textarea>
                </div>

                <div class="mt-6 p-4 rounded-xl bg-gradient-to-r from-blue-50 to-purple-50 border border-blue-100 space-y-2">
                    <div class="flex items-center justify-between text-sm">
                        <span class="text-gray-700"><?= htmlspecialchars(t('printshopinternal.summary_subtotal')) ?></span>
                        <span class="font-medium" x-text="formatCurrency(subtotal)"></span>
                    </div>
                    <div class="flex items-center justify-between text-sm">
                        <span class="text-gray-700"><?= htmlspecialchars(t('printshopinternal.summary_setup')) ?></span>
                        <span class="font-medium" x-text="formatCurrency(setupFee)"></span>
                    </div>
                    <div class="flex items-center justify-between text-sm">
                        <span class="text-gray-700"><?= htmlspecialchars(t('printshopinternal.summary_shipping')) ?></span>
                        <span class="font-medium" x-text="formatCurrency(shippingFee)"></span>
                    </div>
                    <div class="border-t border-blue-200 pt-2 flex items-center justify-between">
                        <span class="font-semibold text-gray-900"><?= htmlspecialchars(t('printshopinternal.summary_total')) ?></span>
                        <span class="text-2xl font-bold text-green-600" x-text="formatCurrency(total)"></span>
                    </div>
                </div>

                <template x-if="belowMin">
                    <div class="mt-4 p-3 rounded-lg bg-amber-50 border border-amber-200 text-amber-800 text-sm">
                        <i class="fa-solid fa-circle-exclamation mr-1"></i>
                        <strong x-text="'Minimum order is ' + minQuantity + ' cards.'"></strong>
                    </div>
                </template>

                <div class="mt-6 flex items-center justify-between">
                    <a href="client.php?company=<?= urlencode($companyId) ?>" class="px-4 py-2.5 bg-gray-100 hover:bg-gray-200 text-gray-700 border border-gray-300 rounded-lg font-medium">
                        <i class="fa-solid fa-arrow-left mr-1"></i> <?= htmlspecialchars(t('printshopinternal.cancel_btn')) ?>
                    </a>
                    <button type="submit" :disabled="belowMin" :class="belowMin ? 'opacity-50 cursor-not-allowed' : ''"
                            class="px-8 py-3 bg-green-600 hover:bg-green-700 text-white rounded-xl font-semibold shadow-md flex items-center gap-2">
                        <i class="fa-solid fa-paper-plane"></i> <?= htmlspecialchars(t('printshopinternal.place_order_btn')) ?>
                    </button>
                </div>
            </div>
        </div>
    </form>

    <?php endif; ?>
</div>

<script>
function orderForm() {
    const shopPricing = <?= json_encode($effectivePricing) ?>;
    const currency    = <?= json_encode($shop['currency'] ?? 'OMR') ?>;

    return {
        quantity: 100,
        paperType: 'matte',
        finish: 'standard',
        currency,
        setupFee: parseFloat(shopPricing.setup_fee || 0),
        shippingFee: parseFloat(shopPricing.shipping_base || 0),
        minQuantity: parseInt(shopPricing.min_quantity || 0) || 0,
        paperTypePricing: (shopPricing.paper_type_pricing && typeof shopPricing.paper_type_pricing === 'object') ? shopPricing.paper_type_pricing : null,
        subtotal: 0,
        total: 0,

        get belowMin() {
            return this.minQuantity > 0 && this.quantity < this.minQuantity;
        },

        init() {
            this.updatePrice();
        },

        _resolveTiers() {
            if (this.paperTypePricing && this.paperTypePricing[this.paperType] && this.paperTypePricing[this.paperType].quantity_tiers) {
                return this.paperTypePricing[this.paperType].quantity_tiers;
            }
            return shopPricing.quantity_tiers || {};
        },

        _designPriceFor(qty) {
            const tiers = this._resolveTiers();
            let bestQty = 0, price = 0;
            for (const [tQty, val] of Object.entries(tiers)) {
                const q = parseInt(tQty);
                if (qty < q) continue;
                if (q <= bestQty) continue;
                bestQty = q;
                price = (val && typeof val === 'object') ? parseFloat(val.price || 0) : parseFloat(val) * qty;
            }
            return price;
        },

        updatePrice() {
            const usingPerPaper = !!this.paperTypePricing;
            let designPrice = this._designPriceFor(this.quantity);
            if (!usingPerPaper && this.paperType === 'silk')   designPrice *= 1.15;
            if (this.finish === 'rounded_corners')             designPrice *= 1.10;
            this.subtotal = Math.round(designPrice * 1000) / 1000;
            this.total    = this.subtotal + this.setupFee + this.shippingFee;
        },

        formatCurrency(n) {
            const d = ['OMR','BHD','KWD'].includes(this.currency) ? 3 : 2;
            return (n || 0).toFixed(d) + ' ' + this.currency;
        }
    };
}
</script>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
