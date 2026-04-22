<?php
/**
 * Order Payment Receipt — Printable/downloadable receipt for paid print orders
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/Currency.php';
require_once INCLUDES_DIR . '/admin-layout.php';

requireAdmin();
$companyId = getCurrentCompanyId();
$db = Database::getInstance();

$orderId = (int)($_GET['order'] ?? 0);
if (!$orderId) {
    header('Location: ' . getBasePath() . 'admin/print.php');
    exit;
}

$order = $db->fetchOne("
    SELECT po.*, c.name AS company_name, c.address AS company_address,
           ps.name AS shop_name, ps.email AS shop_email, ps.phone AS shop_phone,
           ps.address AS shop_address, ps.city AS shop_city, ps.country AS shop_country
    FROM print_orders po
    LEFT JOIN companies c ON c.id = po.company_id
    LEFT JOIN print_shops ps ON ps.id = po.print_shop_id
    WHERE po.id = :id
", ['id' => $orderId]);

if (!$order) {
    header('Location: ' . getBasePath() . 'admin/print.php');
    exit;
}

// Only company owner or super admin can view
if ($order['company_id'] !== $companyId && !Auth::hasRole('super_admin')) {
    header('Location: ' . getBasePath() . 'admin/print.php');
    exit;
}

// Payment must be paid (at least deposit)
if ($order['payment_status'] !== 'paid' && ($order['deposit_amount'] ?? 0) == 0) {
    header('Location: ' . getBasePath() . 'admin/order-checkout.php?order=' . $orderId);
    exit;
}

$cur = $order['currency'] ?? 'OMR';
$paidFull   = $order['payment_status'] === 'paid' && ($order['deposit_amount'] ?? 0) == 0;
$paidDeposit = ($order['deposit_amount'] ?? 0) > 0 && ($order['balance_due'] ?? 0) > 0;
$receiptDate = $order['deposit_paid_at'] ?? $order['balance_paid_at'] ?? $order['updated_at'];

// Bilingual render. Query param wins, then session locale. (Cat S action 444)
if (isset($_GET['lang']) && in_array($_GET['lang'], ['en','ar'], true)) {
    I18n::setLocale($_GET['lang']);
}
$locale = I18n::getLocale();
$isAr   = $locale === 'ar';
$altLang = $isAr ? 'en' : 'ar';
$altUrl  = $_SERVER['REQUEST_URI'];
$altUrl  = preg_replace('/([?&])lang=[a-z]+/', '$1lang=' . $altLang, $altUrl);
if (strpos($altUrl, 'lang=') === false) {
    $altUrl .= (strpos($altUrl, '?') === false ? '?' : '&') . 'lang=' . $altLang;
}
$pageTitle = t('order.receipt_header') . ' #' . ($order['order_number'] ?? $orderId);
?>
<!DOCTYPE html>
<html lang="<?= $locale ?>" dir="<?= $isAr ? 'rtl' : 'ltr' ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($pageTitle) ?></title>
<script src="https://cdn.tailwindcss.com"></script>
<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&display=swap');
* { font-family: <?= $isAr ? "'IBM Plex Sans Arabic', 'Inter', sans-serif" : "'Inter', sans-serif" ?>; }
@media print {
    .no-print { display: none !important; }
    body { print-color-adjust: exact; -webkit-print-color-adjust: exact; }
}
</style>
</head>
<body class="bg-gray-100 min-h-screen">

<!-- Print/Download bar -->
<div class="no-print bg-white border-b border-gray-200 px-6 py-3 flex items-center justify-between sticky top-0 z-10">
    <div class="flex items-center gap-3">
        <a href="<?= getBasePath() ?>admin/print.php" class="text-gray-500 hover:text-gray-700 text-sm">
            <i class="fa-solid fa-arrow-left mr-1"></i> <?= htmlspecialchars(t('order.receipt_back_orders')) ?>
        </a>
        <span class="text-gray-300">|</span>
        <span class="text-sm text-gray-700 font-medium"><?= htmlspecialchars($pageTitle) ?></span>
    </div>
    <div class="flex items-center gap-2">
        <a href="<?= getBasePath() ?>admin/order-checkout.php?order=<?= $orderId ?>" class="px-4 py-2 text-sm bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg font-medium transition-colors">
            <i class="fa-solid fa-arrow-left mr-1"></i> <?= htmlspecialchars(t('order.receipt_back_payment')) ?>
        </a>
        <a href="<?= htmlspecialchars($altUrl) ?>" class="px-3 py-2 text-sm bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg font-medium transition-colors" title="<?= $isAr ? 'English' : 'العربية' ?>">
            <i class="fa-solid fa-language mr-1"></i> <?= $isAr ? 'EN' : 'AR' ?>
        </a>
        <button onclick="window.print()" class="px-4 py-2 text-sm bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition-colors">
            <i class="fa-solid fa-print mr-1"></i> <?= htmlspecialchars(t('order.receipt_print_save')) ?>
        </button>
    </div>
</div>

<!-- Receipt -->
<div class="max-w-2xl mx-auto my-8 px-4">
    <div class="bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden">

        <!-- Header -->
        <div class="bg-blue-600 px-8 py-6 text-white">
            <div class="flex items-center justify-between mb-4">
                <img src="<?= getBasePath() ?>assets/images/logo.svg" alt="Cardify" class="h-8 w-auto brightness-0 invert">
                <div class="text-right">
                    <p class="text-blue-200 text-xs uppercase tracking-wide"><?= htmlspecialchars(t('order.receipt_header')) ?></p>
                    <p class="font-bold text-lg">#<?= htmlspecialchars($order['order_number'] ?? $orderId) ?></p>
                </div>
            </div>
            <div class="flex items-end justify-between">
                <div>
                    <p class="text-blue-200 text-xs mb-1"><?= htmlspecialchars(t('order.receipt_date')) ?></p>
                    <p class="font-medium"><?= htmlspecialchars(I18n::formatDate(strtotime($receiptDate))) ?></p>
                </div>
                <div class="text-right">
                    <p class="text-blue-200 text-xs mb-1"><?= htmlspecialchars(t('order.status')) ?></p>
                    <?php if ($paidDeposit): ?>
                    <span class="bg-amber-400 text-amber-900 text-xs font-bold px-2.5 py-1 rounded-full"><?= htmlspecialchars(t('order.receipt_deposit_paid')) ?></span>
                    <?php else: ?>
                    <span class="bg-green-400 text-green-900 text-xs font-bold px-2.5 py-1 rounded-full"><?= htmlspecialchars(t('order.receipt_paid_full')) ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Billed to / From -->
        <div class="grid grid-cols-2 gap-6 px-8 py-6 border-b border-gray-100">
            <div>
                <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-2"><?= htmlspecialchars(t('order.receipt_billed_to')) ?></p>
                <p class="font-semibold text-gray-900"><?= htmlspecialchars($order['company_name'] ?? 'N/A') ?></p>
                <?php if ($order['shipping_name']): ?>
                <p class="text-sm text-gray-600"><?= htmlspecialchars($order['shipping_name']) ?></p>
                <?php endif; ?>
                <?php if ($order['shipping_address']): ?>
                <p class="text-sm text-gray-600"><?= htmlspecialchars($order['shipping_address']) ?></p>
                <?php endif; ?>
                <?php if ($order['shipping_city']): ?>
                <p class="text-sm text-gray-600"><?= htmlspecialchars($order['shipping_city'] . ', ' . $order['shipping_country']) ?></p>
                <?php endif; ?>
                <?php if ($order['shipping_phone']): ?>
                <p class="text-sm text-gray-600"><?= htmlspecialchars($order['shipping_phone']) ?></p>
                <?php endif; ?>
            </div>
            <div>
                <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-2"><?= htmlspecialchars(t('order.receipt_print_shop')) ?></p>
                <p class="font-semibold text-gray-900"><?= htmlspecialchars($order['shop_name'] ?? 'N/A') ?></p>
                <?php if ($order['shop_email']): ?>
                <p class="text-sm text-gray-600"><?= htmlspecialchars($order['shop_email']) ?></p>
                <?php endif; ?>
                <?php if ($order['shop_phone']): ?>
                <p class="text-sm text-gray-600"><?= htmlspecialchars($order['shop_phone']) ?></p>
                <?php endif; ?>
                <?php if ($order['shop_city']): ?>
                <p class="text-sm text-gray-600"><?= htmlspecialchars($order['shop_city'] . ', ' . $order['shop_country']) ?></p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Order Items -->
        <div class="px-8 py-6 border-b border-gray-100">
            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-4"><?= htmlspecialchars(t('order.receipt_order_details')) ?></p>
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-xs text-gray-500 uppercase border-b border-gray-100">
                        <th class="text-left pb-2"><?= htmlspecialchars(t('order.receipt_item')) ?></th>
                        <th class="text-right pb-2"><?= htmlspecialchars(t('order.receipt_qty')) ?></th>
                        <th class="text-right pb-2"><?= htmlspecialchars(t('order.receipt_amount')) ?></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    <tr class="py-2">
                        <td class="py-2">
                            <p class="font-medium text-gray-900"><?= htmlspecialchars(t('order.receipt_cards_name')) ?></p>
                            <p class="text-gray-500 text-xs"><?= ucfirst($order['paper_type'] ?? 'Standard') ?> &bull; <?= ucfirst(str_replace('_', ' ', $order['finish'] ?? 'matte')) ?><?= !empty($order['express_delivery']) ? ' &bull; ' . htmlspecialchars(t('order.receipt_express_tag')) : '' ?></p>
                        </td>
                        <td class="py-2 text-right text-gray-700"><?= number_format($order['quantity']) ?></td>
                        <td class="py-2 text-right font-medium"><?= number_format($order['subtotal'] ?? 0, 3) ?> <?= $cur ?></td>
                    </tr>
                    <?php if (($order['setup_fee'] ?? 0) > 0): ?>
                    <tr>
                        <td class="py-2 text-gray-600"><?= htmlspecialchars(t('order.receipt_setup_row')) ?></td>
                        <td class="py-2 text-right text-gray-500">1</td>
                        <td class="py-2 text-right"><?= number_format($order['setup_fee'], 3) ?> <?= $cur ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if (($order['shipping_fee'] ?? 0) > 0): ?>
                    <tr>
                        <td class="py-2 text-gray-600"><?= htmlspecialchars(t('order.receipt_shipping_row')) ?></td>
                        <td class="py-2 text-right text-gray-500">1</td>
                        <td class="py-2 text-right"><?= number_format($order['shipping_fee'], 3) ?> <?= $cur ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if (($order['express_fee'] ?? 0) > 0): ?>
                    <tr>
                        <td class="py-2 text-gray-600"><?= htmlspecialchars(t('order.receipt_express_row')) ?></td>
                        <td class="py-2 text-right text-gray-500">1</td>
                        <td class="py-2 text-right"><?= number_format($order['express_fee'], 3) ?> <?= $cur ?></td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Totals -->
        <div class="px-8 py-6 border-b border-gray-100">
            <div class="space-y-2">
                <div class="flex justify-between text-sm text-gray-600">
                    <span><?= htmlspecialchars(t('order.receipt_total_row')) ?></span>
                    <span class="font-medium"><?= number_format($order['total'] ?? 0, 3) ?> <?= $cur ?></span>
                </div>
                <?php if ($paidDeposit): ?>
                <div class="flex justify-between text-sm text-gray-600">
                    <span><?= htmlspecialchars(t('order.receipt_deposit_row', ['pct' => number_format($order['deposit_percent'], 0)])) ?></span>
                    <span class="font-medium text-green-600">-<?= number_format($order['deposit_amount'], 3) ?> <?= $cur ?></span>
                </div>
                <div class="flex justify-between font-bold text-base border-t border-gray-200 pt-2">
                    <span class="text-amber-700"><?= htmlspecialchars(t('order.receipt_balance_due')) ?></span>
                    <span class="text-amber-700"><?= number_format($order['balance_due'], 3) ?> <?= $cur ?></span>
                </div>
                <?php else: ?>
                <div class="flex justify-between font-bold text-lg border-t border-gray-200 pt-2">
                    <span class="text-green-700"><?= htmlspecialchars(t('order.receipt_total_paid')) ?></span>
                    <span class="text-green-700"><?= number_format($order['total'] ?? 0, 3) ?> <?= $cur ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Payment Method -->
        <div class="px-8 py-5 border-b border-gray-100 bg-gray-50">
            <div class="flex items-center justify-between flex-wrap gap-3">
                <div class="flex items-center gap-3">
                    <div class="w-8 h-8 bg-blue-100 rounded-lg flex items-center justify-center">
                        <i class="fa-solid fa-credit-card text-blue-600 text-sm"></i>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500"><?= htmlspecialchars(t('order.receipt_payment_method')) ?></p>
                        <p class="font-medium text-gray-900"><?= ucfirst($order['payment_method'] ?? 'Online') ?></p>
                    </div>
                </div>
                <?php if (!empty($order['erp_invoice_number'])): ?>
                <div class="<?= $isAr ? 'text-left' : 'text-right' ?>">
                    <p class="text-xs text-gray-500"><?= htmlspecialchars(t('order.receipt_erp_invoice')) ?></p>
                    <p class="font-mono font-semibold text-gray-900"><?= htmlspecialchars($order['erp_invoice_number']) ?></p>
                </div>
                <?php endif; ?>
                <?php if (!empty($order['tracking_number'])): ?>
                <div class="<?= $isAr ? 'text-left' : 'text-right' ?>">
                    <p class="text-xs text-gray-500"><?= htmlspecialchars(t('order.receipt_tracking')) ?></p>
                    <p class="font-medium text-gray-900"><?= htmlspecialchars($order['tracking_number']) ?></p>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Footer -->
        <div class="px-8 py-5 text-center">
            <p class="text-sm text-gray-500"><?= htmlspecialchars(t('order.receipt_footer')) ?></p>
            <p class="text-xs text-gray-500 mt-3">
                <strong><?= $isAr ? 'مجموعة BHD' : 'BHD Group' ?></strong>
                · <?= $isAr ? 'بن حيدر درويش ش.ش.و' : 'Bin Haider Darwish S.P.C.' ?>
                · <?= $isAr ? 'س.ت 1334733' : 'C.R. 1334733' ?>
            </p>
            <p class="text-xs text-gray-400 mt-1">cardify.om · info@cardify.om · +968 9889 9100</p>
        </div>

    </div>
</div>

<script src="https://kit.fontawesome.com/a076d05399.js" crossorigin="anonymous"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</body>
</html>
