<?php
/**
 * Print Shop, Save Client Pricing
 * POST handler for printshop/client-pricing.php (save + reset).
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
    $rawQty   = $_POST['tier_qty']   ?? [];
    $rawPrice = $_POST['tier_price'] ?? [];
    $tiers    = [];

    if (is_array($rawQty) && is_array($rawPrice)) {
        foreach ($rawQty as $idx => $qty) {
            $qty   = (int) $qty;
            $price = isset($rawPrice[$idx]) ? (float) $rawPrice[$idx] : 0;
            if ($qty <= 0 || $price < 0) continue;
            $perCard = $qty > 0 ? round($price / $qty, 4) : 0;
            $tiers[(string) $qty] = [
                'price'    => round($price, 3),
                'per_card' => $perCard,
            ];
        }
    }

    if (empty($tiers)) {
        $_SESSION['client_pricing_flash'] = t('printshopclientpricing.error_no_tiers');
        header('Location: ' . getBasePath() . 'printshop/client-pricing.php');
        exit;
    }

    // Sort numerically by quantity for predictable storage.
    ksort($tiers, SORT_NUMERIC);

    $shopPricing  = json_decode($printShop['pricing'] ?? '{}', true) ?: [];
    $pricing = [
        'quantity_tiers' => $tiers,
        'setup_fee'      => isset($shopPricing['setup_fee']) ? (float) $shopPricing['setup_fee'] : 0,
        'shipping_base'  => isset($shopPricing['shipping_base']) ? (float) $shopPricing['shipping_base'] : 0,
        'currency'       => $printShop['currency'] ?? ($shopPricing['currency'] ?? 'OMR'),
    ];

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
