<?php
/**
 * Print Shop, Save Client Pricing
 * POST handler for printshop/client-pricing.php (save + reset).
 *
 * Accepts two pricing modes (form field `pricing_mode`):
 *  - "single"    : one tier table -> stored as `quantity_tiers`
 *  - "per_paper" : per paper-type tier tables -> stored as
 *                  `paper_type_pricing[uncoated|matte|silk].quantity_tiers`
 * Also persists optional `min_quantity` floor.
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/PrintShop.php';

Auth::requireLogin();
$user = Auth::getCurrentUser();

if ($user['role'] !== 'print_shop' && $user['role'] !== 'super_admin') {
    header('Location: ' . getBasePath() . 'login.php');
    exit;
}

$printShop = PrintShop::getByUserId($user['id']);
if (!$printShop && $user['role'] === 'super_admin' && !empty($_POST['shop_id'])) {
    $printShop = PrintShop::getById((int) $_POST['shop_id']);
}
if (!$printShop) {
    header('Location: ' . getBasePath() . 'printshop/register.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . getBasePath() . 'printshop/client-pricing.php');
    exit;
}

if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
    die(htmlspecialchars(t('printshopclientpricing.invalid_request')));
}

$shopId    = (int) $printShop['id'];
$action    = $_POST['action'] ?? '';
$companyId = trim($_POST['company_id'] ?? '');

if (empty($companyId)) {
    $_SESSION['client_pricing_flash'] = t('printshopclientpricing.error_missing_company');
    header('Location: ' . getBasePath() . 'printshop/client-pricing.php');
    exit;
}

if ($action === 'reset') {
    $result = PrintShop::deleteClientPricing($shopId, $companyId);
    $_SESSION['client_pricing_flash'] = $result['success']
        ? t('printshopclientpricing.flash_reset')
        : ($result['error'] ?? 'Error');
    header('Location: ' . getBasePath() . 'printshop/client-pricing.php');
    exit;
}

if ($action === 'save') {
    $pricingMode = ($_POST['pricing_mode'] ?? 'single') === 'per_paper' ? 'per_paper' : 'single';
    $minQuantity = (int) ($_POST['min_quantity'] ?? 0);
    if ($minQuantity < 0) $minQuantity = 0;

    /**
     * Convert paired qty[] + price[] arrays from POST into the canonical
     * tier shape: `{ "<qty>": {"price": float, "per_card": float} }`.
     * Skips rows with non-positive qty or negative price.
     */
    $buildTiers = function ($qtyArr, $priceArr) {
        $tiers = [];
        if (!is_array($qtyArr) || !is_array($priceArr)) return $tiers;
        foreach ($qtyArr as $idx => $qty) {
            $qty   = (int) $qty;
            $price = isset($priceArr[$idx]) ? (float) $priceArr[$idx] : 0;
            if ($qty <= 0 || $price < 0) continue;
            $tiers[(string) $qty] = [
                'price'    => round($price, 3),
                'per_card' => $qty > 0 ? round($price / $qty, 4) : 0,
            ];
        }
        ksort($tiers, SORT_NUMERIC);
        return $tiers;
    };

    $shopPricing = json_decode($printShop['pricing'] ?? '{}', true) ?: [];
    $pricing = [
        'setup_fee'     => isset($shopPricing['setup_fee']) ? (float) $shopPricing['setup_fee'] : 0,
        'shipping_base' => isset($shopPricing['shipping_base']) ? (float) $shopPricing['shipping_base'] : 0,
        'currency'      => $printShop['currency'] ?? ($shopPricing['currency'] ?? 'OMR'),
    ];
    if ($minQuantity > 0) {
        $pricing['min_quantity'] = $minQuantity;
    }

    if ($pricingMode === 'per_paper') {
        $paperTypes  = ['uncoated', 'matte', 'silk'];
        $rawQty      = $_POST['paper_qty']   ?? [];
        $rawPrice    = $_POST['paper_price'] ?? [];
        $perPaper    = [];
        foreach ($paperTypes as $pkey) {
            $tiers = $buildTiers($rawQty[$pkey] ?? [], $rawPrice[$pkey] ?? []);
            if (!empty($tiers)) {
                $perPaper[$pkey] = ['quantity_tiers' => $tiers];
            }
        }
        if (empty($perPaper)) {
            $_SESSION['client_pricing_flash'] = t('printshopclientpricing.error_no_tiers');
            header('Location: ' . getBasePath() . 'printshop/client-pricing.php');
            exit;
        }
        $pricing['paper_type_pricing'] = $perPaper;
    } else {
        $tiers = $buildTiers($_POST['tier_qty'] ?? [], $_POST['tier_price'] ?? []);
        if (empty($tiers)) {
            $_SESSION['client_pricing_flash'] = t('printshopclientpricing.error_no_tiers');
            header('Location: ' . getBasePath() . 'printshop/client-pricing.php');
            exit;
        }
        $pricing['quantity_tiers'] = $tiers;
    }

    $notes = trim($_POST['notes'] ?? '');
    if ($notes === '') $notes = null;

    $result = PrintShop::setClientPricing($shopId, $companyId, $pricing, $notes, $user['id']);
    $_SESSION['client_pricing_flash'] = $result['success']
        ? t('printshopclientpricing.flash_saved')
        : ($result['error'] ?? 'Error');
    header('Location: ' . getBasePath() . 'printshop/client-pricing.php');
    exit;
}

header('Location: ' . getBasePath() . 'printshop/client-pricing.php');
exit;
