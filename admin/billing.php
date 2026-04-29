<?php
/**
 * Billing & Subscription Management - Cardify
 */

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display, but log
ini_set('log_errors', 1);

require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/Currency.php';
requireAdmin();

// Super admin should use Plans page instead
if (Auth::hasRole('super_admin')) {
    header('Location: ' . getBasePath() . 'admin/plans.php');
    exit;
}

require_once INCLUDES_DIR . '/admin-layout.php';

// Check if Billing class exists
if (!file_exists(INCLUDES_DIR . '/Billing.php')) {
    adminHeader(t('adminchrome.billing'), 'billing');
    echo '<div class="bg-amber-50 border border-amber-200 rounded-xl p-6 text-amber-700">';
    echo '<i class="fa-solid fa-exclamation-triangle mr-2"></i>';
    echo htmlspecialchars(t('billing.err_billing_missing'));
    echo '</div>';
    adminFooter();
    exit;
}

try {
    require_once INCLUDES_DIR . '/Billing.php';
} catch (Throwable $e) {
    error_log("Billing.php load error: " . $e->getMessage());
    adminHeader(t('adminchrome.billing'), 'billing');
    echo '<div class="bg-red-50 border border-red-200 rounded-xl p-6 text-red-700">';
    echo htmlspecialchars(t('billing.err_billing_load')) . ' ' . htmlspecialchars($e->getMessage());
    echo '</div>';
    adminFooter();
    exit;
}

$db = Database::getInstance();

// Initialize billing with error handling
try {
    $gateway = defined('BILLING_GATEWAY') ? BILLING_GATEWAY : 'paymob';
    $billingConfig = [];

    if ($gateway === 'paymob') {
        $billingConfig = [
            'public_key' => defined('PAYMOB_PUBLIC_KEY') ? PAYMOB_PUBLIC_KEY : '',
            'secret_key' => defined('PAYMOB_SECRET_KEY') ? PAYMOB_SECRET_KEY : '',
            'hmac_secret' => defined('PAYMOB_HMAC_SECRET') ? PAYMOB_HMAC_SECRET : '',
            'integration_ids' => defined('PAYMOB_INTEGRATION_IDS') ? PAYMOB_INTEGRATION_IDS : ''
        ];
    } elseif ($gateway === 'amwal') {
        $billingConfig = [
            'merchant_id' => defined('AMWAL_MERCHANT_ID') ? AMWAL_MERCHANT_ID : '',
            'terminal_id' => defined('AMWAL_TERMINAL_ID') ? AMWAL_TERMINAL_ID : '',
            'secure_key' => defined('AMWAL_SECURE_KEY') ? AMWAL_SECURE_KEY : '',
            'api_url' => defined('AMWAL_API_URL') ? AMWAL_API_URL : 'https://backend.sa.amwal.tech',
            'callback_url' => (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . (defined('APP_HOST') ? APP_HOST : 'cardify.om') . getBasePath() . 'amwalpay/callback.php',
            'return_url' => (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . (defined('APP_HOST') ? APP_HOST : 'cardify.om') . getBasePath() . 'admin/billing.php'
        ];
    }

    $billing = new Billing($gateway, $billingConfig);
} catch (Throwable $e) {
    error_log("Billing init error: " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine());
    adminHeader(t('adminchrome.billing'), 'billing');
    echo '<div class="bg-red-50 border border-red-200 rounded-xl p-6 text-red-700">';
    echo '<i class="fa-solid fa-exclamation-circle mr-2"></i>';
    echo htmlspecialchars(t('billing.err_billing_init')) . ' ' . htmlspecialchars($e->getMessage());
    echo '</div>';
    adminFooter();
    exit;
}

$companyId = null;
try {
    $companyId = getCurrentCompanyId();
} catch (Throwable $e) {
    error_log("Billing - getCurrentCompanyId error: " . $e->getMessage());
}

$message = null;
$messageType = 'success';

// Handle payment callback messages
if (isset($_GET['payment'])) {
    if ($_GET['payment'] === 'success') {
        $message = t('billing.pay_success');
    } elseif ($_GET['payment'] === 'error') {
        $message = $_GET['message'] ?? t('billing.pay_error_default');
        $messageType = 'error';
    }
}

$company = null;
$planLimits = ['employees' => 5, 'templates' => 5, 'storage' => 1];
$plans = [];
$currentPlan = null;
$companyCurrency = 'OMR';
$planPrices = [];

// Currency handling now done via Currency class

// Only proceed with DB queries if connected and company ID exists
if ($db && $db->isConnected() && $companyId) {
    try {
        $company = $db->fetchOne("SELECT * FROM companies WHERE id = :id", ['id' => $companyId]);
        $companyCurrency = $company['currency'] ?? 'OMR';
    } catch (Throwable $e) {
        error_log("Billing - company fetch error: " . $e->getMessage());
    }

    try {
        $fetchedLimits = $billing->getPlanLimits($companyId);
        if (is_array($fetchedLimits)) {
            $planLimits = array_merge($planLimits, $fetchedLimits);
        }
    } catch (Throwable $e) {
        error_log("Billing - limits fetch error: " . $e->getMessage());
    }

    // Try to get plans, handle if table doesn't exist
    try {
        $plans = $db->fetchAll("SELECT * FROM subscription_plans WHERE is_active = 1 ORDER BY sort_order, price_monthly ASC");
        if (!is_array($plans)) {
            $plans = [];
        }
        
        // Get prices for company's currency
        foreach ($plans as &$plan) {
            try {
                $price = $db->fetchOne(
                    "SELECT * FROM plan_prices WHERE plan_id = :plan_id AND currency = :currency",
                    ['plan_id' => $plan['id'], 'currency' => $companyCurrency]
                );
                if ($price) {
                    $plan['price_monthly'] = $price['price_monthly'];
                    $plan['price_yearly'] = $price['price_yearly'];
                }
            } catch (Exception $e) {
                // plan_prices table might not exist, use default prices
            }
        }
        unset($plan);
        
        if ($company && !empty($company['plan'])) {
            $currentPlan = $db->fetchOne("SELECT * FROM subscription_plans WHERE id = :id", ['id' => $company['plan']]);
            
            // Get current plan's price in company currency
            if ($currentPlan) {
                try {
                    $currentPrice = $db->fetchOne(
                        "SELECT * FROM plan_prices WHERE plan_id = :plan_id AND currency = :currency",
                        ['plan_id' => $currentPlan['id'], 'currency' => $companyCurrency]
                    );
                    if ($currentPrice) {
                        $currentPlan['price_monthly'] = $currentPrice['price_monthly'];
                        $currentPlan['price_yearly'] = $currentPrice['price_yearly'];
                    }
                } catch (Exception $e) {
                    // Use default prices
                }
            }
        }
    } catch (Throwable $e) {
        // subscription_plans table might not exist
        error_log("Billing plans: " . $e->getMessage());
        $plans = [];
    }
}

// Default current plan if none found
if (!$currentPlan) {
    $currentPlan = [
        'id' => 'free',
        'name' => 'Free',
        'description' => 'Basic features for small teams',
        'price_monthly' => 0,
        'price_yearly' => 0,
        'limits' => json_encode(['employees' => 5, 'templates' => 2, 'storage' => 1])
    ];
}

// Currency formatting is done via Currency::formatHtml()

// Fetch card credits and per-card pricing
$cardCredits = 0;
$cardsUsedThisMonth = 0;
$maxCardsPerMonth = -1;
$pricePerCard = 0.500; // default
if ($db && $db->isConnected() && $companyId) {
    try {
        $cardCredits = (int)($company['card_credits'] ?? 0);
        $cardsUsedThisMonth = (int)($db->fetchOne(
            "SELECT COUNT(*) as cnt FROM generated_cards WHERE company_id = :cid AND generated_at >= DATE_FORMAT(NOW(),'%Y-%m-01')",
            ['cid' => $companyId]
        )['cnt'] ?? 0);
        if ($currentPlan) {
            $maxCardsPerMonth = (int)($currentPlan['max_cards_per_month'] ?? -1);
            $pricePerCard = (float)($currentPlan['price_per_card'] ?? 0.500);
        }
    } catch (Throwable $e) {
        error_log("Billing card credits fetch: " . $e->getMessage());
    }
}

// Fetch unpaid print orders
$unpaidOrders = [];
if ($db && $db->isConnected() && $companyId) {
    try {
        $unpaidOrders = $db->fetchAll(
            "SELECT po.*, ps.name as shop_name
             FROM print_orders po
             LEFT JOIN print_shops ps ON ps.id = po.print_shop_id
             WHERE po.company_id = :cid
               AND po.payment_status != 'paid'
               AND po.total > 0
             ORDER BY po.created_at DESC
             LIMIT 20",
            ['cid' => $companyId]
        );
    } catch (Throwable $e) {
        error_log("Billing unpaid orders fetch: " . $e->getMessage());
    }
}

// Handle subscription upgrade
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'subscribe') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { die(htmlspecialchars(t('billing.invalid_request'))); }
    $planId = $_POST['plan_id'] ?? '';
    $billingCycle = $_POST['billing_cycle'] ?? 'monthly';
    
    $result = $billing->createSubscription($companyId, $planId, $billingCycle);

    if ($result['success'] && !empty($result['payment_url'])) {
        // Paymob: redirect directly to unified checkout
        header('Location: ' . $result['payment_url']);
        exit;
    } elseif ($result['success'] && !empty($result['free'])) {
        // Free plan activated directly, no payment needed
        header('Location: ' . getAdminBasePath() . 'billing' . ((defined('COMPANY_ADMIN_BASE') || !empty($_SESSION['company_slug'])) ? '' : '.php') . '?payment=success');
        exit;
    } elseif ($result['success'] && !empty($result['payment_data'])) {
        // Legacy Amwal: redirect to process page
        header('Location: ' . getBasePath() . 'amwalpay/process.php?order_id=' . urlencode($result['transaction_id']));
        exit;
    } else {
        $message = $result['error'] ?? t('billing.subscribe_failed');
        $messageType = 'error';
    }
}

// Handle buy card credits
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'buy_cards') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { die(htmlspecialchars(t('billing.invalid_request'))); }
    require_once INCLUDES_DIR . '/Payment.php';
    $cardCount = max(1, min(100, (int)($_POST['card_count'] ?? 10)));
    $result = Payment::createCardOrderIntent($companyId, $cardCount, $pricePerCard, $companyCurrency);
    if (!empty($result['checkout_url'])) {
        header('Location: ' . $result['checkout_url']);
        exit;
    }
    $message = $result['error'] ?? t('billing.buy_cards_failed');
    $messageType = 'error';
}

// Get counts with error handling
$employeeCount = 0;
$templateCount = 0;
try {
    $result = $db->fetchOne("SELECT COUNT(*) as count FROM employees WHERE company_id = :id", ['id' => $companyId]);
    $employeeCount = $result['count'] ?? 0;
} catch (Exception $e) {
    // employees table might not exist
}
try {
    $result = $db->fetchOne("SELECT COUNT(*) as count FROM templates WHERE company_id = :id", ['id' => $companyId]);
    $templateCount = $result['count'] ?? 0;
} catch (Exception $e) {
    // templates table might not exist
}

adminHeader(t('adminchrome.billing'), 'billing');
?>

<!-- Alert Message -->
<?php if ($message): ?>
<div class="mb-6 p-4 rounded-xl flex items-center gap-3 <?php echo $messageType === 'success' ? 'bg-green-50 border border-green-200 text-green-700' : 'bg-red-50 border border-red-200 text-red-700'; ?>">
    <i class="<?php echo $messageType === 'success' ? 'fa-solid fa-circle-check' : 'fa-solid fa-circle-exclamation'; ?>"></i>
    <?php echo sanitize($message); ?>
</div>
<?php endif; ?>

<!-- Current Plan -->
<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden mb-8">
    <div class="p-6 border-b border-gray-100 bg-gradient-to-r from-blue-50 to-indigo-50">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div>
                <p class="text-sm text-gray-600 mb-1"><?= htmlspecialchars(t('billing.current_plan')) ?></p>
                <h2 class="text-3xl font-bold text-gray-900"><?php echo sanitize($currentPlan['name'] ?? t('billing.col_free')); ?></h2>
                <p class="text-gray-600 mt-1"><?php echo sanitize($currentPlan['description'] ?? t('billing.plan_default_desc')); ?></p>
            </div>
            <div class="text-left md:text-right">
                <p class="text-sm text-gray-600 mb-1"><?= htmlspecialchars(t('billing.monthly_price')) ?></p>
                <p class="text-3xl font-bold text-gray-900">
                    <?php echo Currency::formatHtml($currentPlan['price_monthly'] ?? 0, $companyCurrency, 'lg'); ?>
                    <span class="text-lg font-normal text-gray-500"><?= htmlspecialchars(t('billing.per_month_short')) ?></span>
                </p>
                <p class="text-xs text-gray-500 mt-1"><?= htmlspecialchars(t('billing.currency_label')) ?> <?php echo $companyCurrency; ?></p>
            </div>
        </div>
    </div>
    
    <!-- Usage Stats -->
    <div class="p-6">
        <h3 class="font-semibold text-gray-900 mb-4"><?= htmlspecialchars(t('billing.usage_h3')) ?></h3>
        <div class="grid md:grid-cols-3 gap-6">
            <div>
                <div class="flex items-center justify-between mb-2">
                    <span class="text-sm text-gray-600"><?= htmlspecialchars(t('billing.usage_employees')) ?></span>
                    <span class="text-sm font-medium text-gray-900">
                        <?php echo $employeeCount; ?> / <?php echo $planLimits['employees'] === -1 ? '∞' : $planLimits['employees']; ?>
                    </span>
                </div>
                <div class="h-2 bg-gray-100 rounded-full overflow-hidden">
                    <div class="h-full bg-blue-600 rounded-full" style="width: <?php echo ($planLimits['employees'] <= 0 || $planLimits['employees'] === -1) ? 50 : min(100, ($employeeCount / $planLimits['employees']) * 100); ?>%"></div>
                </div>
            </div>
            
            <div>
                <div class="flex items-center justify-between mb-2">
                    <span class="text-sm text-gray-600"><?= htmlspecialchars(t('billing.usage_templates')) ?></span>
                    <span class="text-sm font-medium text-gray-900">
                        <?php echo $templateCount; ?> / <?php echo $planLimits['templates'] === -1 ? '∞' : $planLimits['templates']; ?>
                    </span>
                </div>
                <div class="h-2 bg-gray-100 rounded-full overflow-hidden">
                    <div class="h-full bg-purple-600 rounded-full" style="width: <?php echo ($planLimits['templates'] <= 0 || $planLimits['templates'] === -1) ? 50 : min(100, ($templateCount / $planLimits['templates']) * 100); ?>%"></div>
                </div>
            </div>
            
            <div>
                <div class="flex items-center justify-between mb-2">
                    <span class="text-sm text-gray-600"><?= htmlspecialchars(t('billing.usage_storage')) ?></span>
                    <span class="text-sm font-medium text-gray-900">
                        <?php echo $planLimits['storage'] === -1 ? htmlspecialchars(t('billing.unlimited')) : htmlspecialchars(str_replace(':n', (string) $planLimits['storage'], t('billing.gb_suffix'))); ?>
                    </span>
                </div>
                <div class="h-2 bg-gray-100 rounded-full overflow-hidden">
                    <div class="h-full bg-green-600 rounded-full" style="width: 30%"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Free-Forever Platform Callout (replaces the old SaaS upsell + plan comparison) -->
<div class="rounded-2xl bg-gradient-to-br from-blue-600 to-indigo-700 text-white p-6 lg:p-8 mb-8 shadow-xl">
    <div class="flex items-start gap-4">
        <div class="w-12 h-12 rounded-xl bg-white/15 flex items-center justify-center flex-shrink-0">
            <i class="fa-solid fa-gift text-2xl"></i>
        </div>
        <div class="flex-1">
            <h3 class="text-xl font-bold mb-1">Cardify is free for your team. Forever.</h3>
            <p class="text-blue-100 mb-4">Unlimited employees, unlimited templates, unlimited digital cards and analytics. You only pay when you order printed products.</p>
            <a href="<?= getBasePath() ?>pricing" class="inline-flex items-center gap-2 bg-white text-blue-700 hover:bg-blue-50 font-semibold px-5 py-2.5 rounded-xl transition">
                See print pricing
                <i class="fa-solid fa-arrow-right"></i>
            </a>
        </div>
    </div>
</div>

<!-- Legacy SaaS plan-comparison + upgrade UI, hidden Apr 2026. -->
<?php if (false): ?>
<?php
$planInfo = Billing::getCompanyPlanInfo($companyId);
$isFreePlan = ($planInfo['plan'] ?? 'free') === 'free';
?>
<?php if ($isFreePlan): ?>
<div class="bg-gradient-to-r from-blue-600 to-indigo-600 rounded-xl p-6 mb-8 text-white">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div>
            <h3 class="text-xl font-bold flex items-center gap-2">
                <i class="fa-solid fa-crown"></i>
                <?= htmlspecialchars(t('billing.unlock_premium_h3')) ?>
            </h3>
            <p class="mt-2 text-blue-100"><?= htmlspecialchars(t('billing.unlock_premium_body')) ?></p>
            <div class="mt-4 flex flex-wrap gap-4">
                <div class="flex items-center gap-2 text-sm">
                    <i class="fa-solid fa-check-circle text-green-300"></i>
                    <span><?= htmlspecialchars(t('billing.feat_hd_quality')) ?></span>
                </div>
                <div class="flex items-center gap-2 text-sm">
                    <i class="fa-solid fa-check-circle text-green-300"></i>
                    <span><?= htmlspecialchars(t('billing.feat_qr_analytics')) ?></span>
                </div>
                <div class="flex items-center gap-2 text-sm">
                    <i class="fa-solid fa-check-circle text-green-300"></i>
                    <span><?= htmlspecialchars(t('billing.feat_bulk_gen')) ?></span>
                </div>
            </div>
        </div>
        <div class="flex-shrink-0">
            <a href="#plans" class="inline-flex items-center gap-2 px-6 py-3 bg-white text-blue-600 rounded-lg font-semibold hover:bg-blue-50 transition-colors">
                <i class="fa-solid fa-rocket"></i>
                <?= htmlspecialchars(t('billing.view_plans_btn')) ?>
            </a>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Feature Matrix -->
<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden mb-8">
    <div class="p-4 border-b border-gray-100">
        <h3 class="font-semibold text-gray-900"><?= htmlspecialchars(t('billing.feature_matrix_h3')) ?></h3>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left font-medium text-gray-600"><?= htmlspecialchars(t('billing.col_feature')) ?></th>
                    <th class="px-4 py-3 text-center font-medium text-gray-600"><?= htmlspecialchars(t('billing.col_free')) ?></th>
                    <th class="px-4 py-3 text-center font-medium text-blue-700"><?= htmlspecialchars(t('billing.col_pro')) ?></th>
                    <th class="px-4 py-3 text-center font-medium text-gray-600"><?= htmlspecialchars(t('billing.col_enterprise')) ?></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <tr>
                    <td class="px-4 py-3 text-gray-900"><?= htmlspecialchars(t('billing.fm_employees')) ?></td>
                    <td class="px-4 py-3 text-center text-sm text-gray-600"><?= htmlspecialchars(str_replace(':n', '5', t('billing.limit_up_to'))) ?></td>
                    <td class="px-4 py-3 text-center text-sm text-blue-700 font-medium"><?= htmlspecialchars(str_replace(':n', '50', t('billing.limit_up_to'))) ?></td>
                    <td class="px-4 py-3 text-center text-sm text-gray-600"><?= htmlspecialchars(t('billing.unlimited')) ?></td>
                </tr>
                <tr>
                    <td class="px-4 py-3 text-gray-900"><?= htmlspecialchars(t('billing.fm_cards_month')) ?></td>
                    <td class="px-4 py-3 text-center text-sm text-gray-600">10</td>
                    <td class="px-4 py-3 text-center text-sm text-blue-700 font-medium">500</td>
                    <td class="px-4 py-3 text-center text-sm text-gray-600"><?= htmlspecialchars(t('billing.unlimited')) ?></td>
                </tr>
                <tr>
                    <td class="px-4 py-3 text-gray-900"><?= htmlspecialchars(t('billing.fm_preview_quality')) ?></td>
                    <td class="px-4 py-3 text-center"><span class="text-amber-600 text-sm"><?= htmlspecialchars(t('billing.quality_low')) ?></span></td>
                    <td class="px-4 py-3 text-center"><span class="text-green-600 text-sm font-medium"><?= htmlspecialchars(t('billing.quality_high')) ?></span></td>
                    <td class="px-4 py-3 text-center"><span class="text-green-600 text-sm"><?= htmlspecialchars(t('billing.quality_high')) ?></span></td>
                </tr>
                <tr>
                    <td class="px-4 py-3 text-gray-900"><?= htmlspecialchars(t('billing.fm_print_quality')) ?></td>
                    <td class="px-4 py-3 text-center"><i class="fa-solid fa-check text-green-600"></i></td>
                    <td class="px-4 py-3 text-center"><i class="fa-solid fa-check text-green-600"></i></td>
                    <td class="px-4 py-3 text-center"><i class="fa-solid fa-check text-green-600"></i></td>
                </tr>
                <tr>
                    <td class="px-4 py-3 text-gray-900"><?= htmlspecialchars(t('billing.fm_qr_analytics')) ?></td>
                    <td class="px-4 py-3 text-center"><i class="fa-solid fa-times text-gray-300"></i></td>
                    <td class="px-4 py-3 text-center"><i class="fa-solid fa-check text-green-600"></i></td>
                    <td class="px-4 py-3 text-center"><i class="fa-solid fa-check text-green-600"></i></td>
                </tr>
                <tr>
                    <td class="px-4 py-3 text-gray-900"><?= htmlspecialchars(t('billing.fm_bulk_gen')) ?></td>
                    <td class="px-4 py-3 text-center"><i class="fa-solid fa-times text-gray-300"></i></td>
                    <td class="px-4 py-3 text-center"><i class="fa-solid fa-check text-green-600"></i></td>
                    <td class="px-4 py-3 text-center"><i class="fa-solid fa-check text-green-600"></i></td>
                </tr>
                <tr>
                    <td class="px-4 py-3 text-gray-900"><?= htmlspecialchars(t('billing.fm_api_access')) ?></td>
                    <td class="px-4 py-3 text-center"><i class="fa-solid fa-times text-gray-300"></i></td>
                    <td class="px-4 py-3 text-center"><i class="fa-solid fa-check text-green-600"></i></td>
                    <td class="px-4 py-3 text-center"><i class="fa-solid fa-check text-green-600"></i></td>
                </tr>
                <tr>
                    <td class="px-4 py-3 text-gray-900"><?= htmlspecialchars(t('billing.fm_custom_brand')) ?></td>
                    <td class="px-4 py-3 text-center"><i class="fa-solid fa-times text-gray-300"></i></td>
                    <td class="px-4 py-3 text-center"><i class="fa-solid fa-check text-green-600"></i></td>
                    <td class="px-4 py-3 text-center"><i class="fa-solid fa-check text-green-600"></i></td>
                </tr>
                <tr>
                    <td class="px-4 py-3 text-gray-900"><?= htmlspecialchars(t('billing.fm_priority_support')) ?></td>
                    <td class="px-4 py-3 text-center"><i class="fa-solid fa-times text-gray-300"></i></td>
                    <td class="px-4 py-3 text-center"><i class="fa-solid fa-check text-green-600"></i></td>
                    <td class="px-4 py-3 text-center"><i class="fa-solid fa-check text-green-600"></i></td>
                </tr>
            </tbody>
        </table>
    </div>
    <div class="p-4 bg-blue-50 border-t border-blue-100">
        <p class="text-sm text-blue-700">
            <i class="fa-solid fa-info-circle mr-1"></i>
            <?= t('billing.matrix_note') ?>
        </p>
    </div>
</div>

<!-- Billing Cycle Toggle -->
<div class="flex items-center justify-between mb-6">
    <h2 id="plans" class="text-xl font-bold text-gray-900"><?= htmlspecialchars(t('billing.upgrade_your_plan')) ?></h2>
    <div class="flex items-center gap-2 bg-gray-100 p-1 rounded-xl" id="billingToggle">
        <button type="button" onclick="setBillingCycle('monthly')" id="btn-monthly"
                class="px-4 py-2 text-sm font-medium rounded-lg bg-white shadow text-gray-900 transition-all"><?= htmlspecialchars(t('billing.cycle_monthly')) ?></button>
        <button type="button" onclick="setBillingCycle('yearly')" id="btn-yearly"
                class="px-4 py-2 text-sm font-medium rounded-lg text-gray-500 hover:text-gray-700 transition-all">
            <?= htmlspecialchars(t('billing.cycle_yearly')) ?> <span class="ml-1 text-xs text-green-600 font-semibold"><?= htmlspecialchars(t('billing.save_20')) ?></span>
        </button>
    </div>
</div>
<script>
function setBillingCycle(cycle) {
    document.querySelectorAll('input[name="billing_cycle"]').forEach(i => i.value = cycle);
    const isYearly = cycle === 'yearly';
    document.getElementById('btn-monthly').className = isYearly
        ? 'px-4 py-2 text-sm font-medium rounded-lg text-gray-500 hover:text-gray-700 transition-all'
        : 'px-4 py-2 text-sm font-medium rounded-lg bg-white shadow text-gray-900 transition-all';
    document.getElementById('btn-yearly').className = isYearly
        ? 'px-4 py-2 text-sm font-medium rounded-lg bg-white shadow text-gray-900 transition-all'
        : 'px-4 py-2 text-sm font-medium rounded-lg text-gray-500 hover:text-gray-700 transition-all';
    // Show/hide pricing
    document.querySelectorAll('.price-monthly').forEach(el => el.classList.toggle('hidden', isYearly));
    document.querySelectorAll('.price-yearly').forEach(el => el.classList.toggle('hidden', !isYearly));
}
</script>
<?php if (empty($plans)): ?>
<div class="bg-white rounded-xl border border-gray-200 p-8 text-center mb-8">
    <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
        <i class="fa-solid fa-credit-card text-gray-400 text-2xl"></i>
    </div>
    <h3 class="text-lg font-medium text-gray-900 mb-2"><?= htmlspecialchars(t('billing.no_plans_h3')) ?></h3>
    <p class="text-gray-500"><?= htmlspecialchars(t('billing.no_plans_body')) ?></p>
</div>
<?php else: ?>
<div class="grid md:grid-cols-3 gap-6 mb-8">
    <?php foreach ($plans as $plan): ?>
    <?php $isCurrent = ($currentPlan['id'] ?? '') === $plan['id']; ?>
    <div class="bg-white rounded-xl border-2 <?php echo $plan['id'] === 'pro' ? 'border-blue-500 shadow-lg shadow-blue-500/10' : 'border-gray-200'; ?> overflow-hidden relative">
        <?php if ($plan['id'] === 'pro'): ?>
        <div class="absolute top-0 right-0 bg-blue-600 text-white text-xs font-bold px-3 py-1 rounded-bl-lg">
            <?= htmlspecialchars(t('billing.popular_badge')) ?>
        </div>
        <?php endif; ?>
        
        <div class="p-6">
            <h3 class="text-xl font-bold text-gray-900"><?php echo sanitize($plan['name']); ?></h3>
            <p class="text-gray-600 text-sm mt-1"><?php echo sanitize($plan['description']); ?></p>
            
            <div class="mt-4">
                <div class="price-monthly">
                    <span class="text-4xl font-bold text-gray-900"><?php echo Currency::formatHtml($plan['price_monthly'], $companyCurrency, 'xl'); ?></span>
                    <span class="text-gray-500"><?= htmlspecialchars(t('billing.per_month')) ?></span>
                </div>
                <?php if ($plan['price_yearly'] > 0): ?>
                <div class="price-yearly hidden">
                    <span class="text-4xl font-bold text-gray-900"><?php echo Currency::formatHtml($plan['price_yearly'], $companyCurrency, 'xl'); ?></span>
                    <span class="text-gray-500"><?= htmlspecialchars(t('billing.per_year')) ?></span>
                    <span class="ml-2 text-xs bg-green-100 text-green-700 px-2 py-0.5 rounded-full font-semibold">
                        <?php $monthlyBase = (float)$plan['price_monthly'] * 12; $pct = $monthlyBase > 0 ? round((1 - ((float)$plan['price_yearly'] / $monthlyBase)) * 100) : 0; echo htmlspecialchars(str_replace(':n', (string) $pct, t('billing.save_pct'))); ?>
                    </span>
                </div>
                <?php else: ?>
                <div class="price-yearly hidden">
                    <span class="text-4xl font-bold text-gray-900"><?php echo Currency::formatHtml($plan['price_monthly'] * 12, $companyCurrency, 'xl'); ?></span>
                    <span class="text-gray-500"><?= htmlspecialchars(t('billing.per_year')) ?></span>
                </div>
                <?php endif; ?>
            </div>
            
            <ul class="mt-6 space-y-3">
                <?php 
                // Try JSON limits first, fall back to individual columns
                $limits = null;
                if (!empty($plan['limits'])) {
                    $limits = json_decode($plan['limits'], true);
                }
                // Use individual columns if JSON not available
                $maxEmployees = $limits['employees'] ?? $limits['max_employees'] ?? $plan['max_employees'] ?? 10;
                $maxTemplates = $limits['templates'] ?? $limits['max_templates'] ?? $plan['max_templates'] ?? 5;
                $maxStorage = $limits['storage'] ?? $limits['max_storage_mb'] ?? $plan['max_storage_mb'] ?? 1;
                $features = $limits['features'] ?? [];
                if (empty($features) && !empty($plan['features_json'])) {
                    $featuresData = json_decode($plan['features_json'], true) ?: [];
                    $features = $featuresData['features'] ?? $featuresData;
                }
                ?>
                <li class="flex items-center gap-2 text-sm text-gray-600">
                    <i class="fa-solid fa-check text-green-600"></i>
                    <?php echo $maxEmployees === -1 ? htmlspecialchars(t('billing.unlimited')) : (int) $maxEmployees; ?> <?= htmlspecialchars(t('billing.employees_suffix')) ?>
                </li>
                <li class="flex items-center gap-2 text-sm text-gray-600">
                    <i class="fa-solid fa-check text-green-600"></i>
                    <?php echo $maxTemplates === -1 ? htmlspecialchars(t('billing.unlimited')) : (int) $maxTemplates; ?> <?= htmlspecialchars(t('billing.templates_suffix')) ?>
                </li>
                <li class="flex items-center gap-2 text-sm text-gray-600">
                    <i class="fa-solid fa-check text-green-600"></i>
                    <?php echo $maxStorage === -1 ? htmlspecialchars(t('billing.unlimited')) : htmlspecialchars(str_replace(':n', (string) $maxStorage, t('billing.gb_suffix'))); ?> <?= htmlspecialchars(t('billing.storage_suffix')) ?>
                </li>
                <?php if (!empty($features) && is_array($features)): ?>
                <?php foreach ($features as $feature): ?>
                <li class="flex items-center gap-2 text-sm text-gray-600">
                    <i class="fa-solid fa-check text-green-600"></i>
                    <?php echo sanitize($feature); ?>
                </li>
                <?php endforeach; ?>
                <?php endif; ?>
            </ul>
            
            <div class="mt-6">
                <?php if ($isCurrent): ?>
                <button disabled class="w-full py-3 bg-gray-100 text-gray-500 rounded-lg font-medium cursor-not-allowed">
                    <?= htmlspecialchars(t('billing.current_plan_btn')) ?>
                </button>
                <?php else: ?>
                <form method="post">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="subscribe">
                    <input type="hidden" name="plan_id" value="<?php echo $plan['id']; ?>">
                    <input type="hidden" name="billing_cycle" value="monthly">
                    <button type="submit" class="w-full py-3 <?php echo $plan['id'] === 'pro' ? 'bg-blue-600 hover:bg-blue-700' : 'bg-gray-900 hover:bg-gray-800'; ?> text-white rounded-lg font-medium transition-colors">
                        <?= htmlspecialchars(str_replace(':name', sanitize($plan['name']), t('billing.upgrade_to'))) ?>
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
<?php endif; // legacy SaaS UI block ?>

<!-- Unpaid Print Orders -->
<?php if (!empty($unpaidOrders)): ?>
<div class="bg-white rounded-xl border border-orange-200 shadow-sm overflow-hidden mb-8">
    <div class="p-4 border-b border-orange-100 flex items-center justify-between bg-orange-50">
        <h3 class="font-semibold text-orange-800 flex items-center gap-2">
            <i class="fa-solid fa-print text-orange-500"></i>
            <?= htmlspecialchars(t('billing.pending_payments')) ?>
        </h3>
        <span class="text-xs font-semibold bg-orange-200 text-orange-800 px-2 py-0.5 rounded-full">
            <?= htmlspecialchars(str_replace(':n', (string) count($unpaidOrders), t('billing.n_unpaid'))) ?>
        </span>
    </div>
    <div class="divide-y divide-gray-100">
        <?php foreach ($unpaidOrders as $uOrder):
            $isCompanyAdmin = defined('COMPANY_ADMIN_BASE') || !empty($_SESSION['company_slug']);
            $ext = $isCompanyAdmin ? '' : '.php';
            $basePath = getAdminBasePath();
        ?>
        <div class="p-4 flex items-center justify-between gap-4 flex-wrap">
            <div class="flex-1 min-w-0">
                <p class="font-medium text-gray-900 text-sm">
                    <?= htmlspecialchars(str_replace(':num', (string)($uOrder['order_number'] ?? $uOrder['id']), t('billing.order_num'))) ?>
                    <?php if (!empty($uOrder['shop_name'])): ?>
                    <span class="text-gray-400 font-normal">· <?= htmlspecialchars($uOrder['shop_name']) ?></span>
                    <?php endif; ?>
                </p>
                <p class="text-xs text-gray-500 mt-0.5">
                    <?= htmlspecialchars(str_replace(':n', (string)(int)($uOrder['quantity'] ?? 0), t('billing.n_cards'))) ?> ·
                    <?= ucfirst($uOrder['paper_type'] ?? 'standard') ?> ·
                    <?= ucfirst($uOrder['finish'] ?? 'matte') ?> ·
                    <?= date('d M Y', strtotime($uOrder['created_at'])) ?>
                </p>
            </div>
            <div class="flex items-center gap-3 flex-shrink-0">
                <span class="text-lg font-bold text-gray-900">
                    <?= number_format((float)$uOrder['total'], 3) ?>
                    <span class="text-sm font-normal text-gray-500"><?= htmlspecialchars($uOrder['currency'] ?? $companyCurrency) ?></span>
                </span>
                <span class="inline-block px-2 py-0.5 rounded-full text-xs font-medium
                    <?= ($uOrder['payment_status'] ?? '') === 'partial' ? 'bg-yellow-100 text-yellow-700' : 'bg-orange-100 text-orange-700' ?>">
                    <?= ucfirst($uOrder['payment_status'] ?? 'unpaid') ?>
                </span>
                <a href="<?= $basePath ?>order-checkout<?= $ext ?>?order=<?= (int)$uOrder['id'] ?>"
                   class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium transition flex items-center gap-1.5">
                    <i class="fa-solid fa-lock text-xs"></i> <?= htmlspecialchars(t('billing.pay_now')) ?>
                </a>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <div class="px-4 py-3 bg-gray-50 border-t border-gray-100 flex items-center justify-between">
        <p class="text-xs text-gray-500">
            <?= htmlspecialchars(t('billing.total_outstanding')) ?>
            <strong class="text-gray-900">
                <?= number_format(array_sum(array_column($unpaidOrders, 'total')), 3) ?> <?= $companyCurrency ?>
            </strong>
        </p>
        <?php $basePath2 = getAdminBasePath(); $ext2 = (defined('COMPANY_ADMIN_BASE') || !empty($_SESSION['company_slug'])) ? '' : '.php'; ?>
        <a href="<?= $basePath2 ?>print<?= $ext2 ?>" class="text-xs text-blue-600 hover:underline">
            <?= htmlspecialchars(t('billing.view_all_orders')) ?> <i class="fa-solid fa-arrow-right ml-1"></i>
        </a>
    </div>
</div>
<?php endif; ?>

<!-- Card Credits (Pay Per Card) -->
<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden mb-8">
    <div class="p-4 border-b border-gray-100 flex items-center justify-between">
        <h3 class="font-semibold text-gray-900 flex items-center gap-2">
            <i class="fa-solid fa-id-card text-purple-500"></i>
            <?= htmlspecialchars(t('billing.card_credits_h3')) ?>
        </h3>
        <span class="text-xs text-gray-400"><?= htmlspecialchars(t('billing.card_credits_sub')) ?></span>
    </div>
    <div class="p-6">
        <div class="grid md:grid-cols-3 gap-6 mb-6">
            <div class="text-center p-4 bg-purple-50 rounded-xl">
                <p class="text-3xl font-bold text-purple-700"><?php echo $cardCredits; ?></p>
                <p class="text-sm text-gray-500 mt-1"><?= htmlspecialchars(t('billing.credits_available')) ?></p>
            </div>
            <div class="text-center p-4 bg-gray-50 rounded-xl">
                <p class="text-3xl font-bold text-gray-700"><?php echo $cardsUsedThisMonth; ?></p>
                <p class="text-sm text-gray-500 mt-1"><?= htmlspecialchars(t('billing.generated_this_month')) ?></p>
            </div>
            <div class="text-center p-4 bg-blue-50 rounded-xl">
                <p class="text-3xl font-bold text-blue-700">
                    <?php echo $maxCardsPerMonth === -1 ? '∞' : number_format($maxCardsPerMonth); ?>
                </p>
                <p class="text-sm text-gray-500 mt-1"><?= htmlspecialchars(t('billing.monthly_limit_plan')) ?></p>
            </div>
        </div>

        <?php if ($pricePerCard > 0): ?>
        <div class="border border-purple-100 rounded-xl p-5 bg-purple-50">
            <p class="text-sm text-gray-700 mb-3">
                <?php echo str_replace([':price', ':cur'], [number_format($pricePerCard, 3), htmlspecialchars($companyCurrency)], t('billing.buy_credits_lead')); ?>
            </p>
            <form method="POST" class="flex items-end gap-3 flex-wrap">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="buy_cards">
                <div>
                    <label class="block text-xs font-medium text-gray-700 mb-1"><?= htmlspecialchars(t('billing.number_of_cards')) ?></label>
                    <select name="card_count" class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-purple-500">
                        <?php foreach ([5, 10, 25, 50, 100] as $qty): ?>
                        <option value="<?= $qty ?>" <?= $qty === 10 ? 'selected' : '' ?>>
                            <?= htmlspecialchars(strtr(t('billing.cards_option'), [
                                ':qty'   => (string) $qty,
                                ':total' => number_format($pricePerCard * $qty, 3),
                                ':cur'   => $companyCurrency,
                            ])) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="px-5 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-lg text-sm font-medium transition">
                    <i class="fa-solid fa-lock mr-1"></i> <?= htmlspecialchars(t('billing.buy_credits_btn')) ?>
                </button>
            </form>
        </div>
        <?php else: ?>
        <div class="text-sm text-gray-500 bg-gray-50 rounded-xl p-4">
            <i class="fa-solid fa-info-circle mr-1 text-blue-400"></i>
            <?= htmlspecialchars(t('billing.card_included')) ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Payment Methods -->
<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
    <div class="p-4 border-b border-gray-100">
        <h3 class="font-semibold text-gray-900 flex items-center gap-2">
            <i class="fa-solid fa-credit-card text-blue-600"></i>
            <?= htmlspecialchars(t('billing.payment_methods_h3')) ?>
        </h3>
    </div>
    <div class="p-6">
        <p class="text-gray-600 text-sm mb-4"><?= htmlspecialchars(t('billing.payment_methods_lead')) ?></p>
        <div class="flex flex-wrap items-center gap-4">
            <div class="px-4 py-2 bg-gray-50 rounded-lg border border-gray-200 flex items-center gap-2">
                <i class="fa-brands fa-cc-visa text-2xl text-blue-600"></i>
            </div>
            <div class="px-4 py-2 bg-gray-50 rounded-lg border border-gray-200 flex items-center gap-2">
                <i class="fa-brands fa-cc-mastercard text-2xl text-red-500"></i>
            </div>
            <div class="px-4 py-2 bg-gray-50 rounded-lg border border-gray-200 flex items-center gap-2">
                <i class="fa-brands fa-cc-amex text-2xl text-blue-500"></i>
            </div>
            <div class="px-4 py-2 bg-gray-50 rounded-lg border border-gray-200 flex items-center gap-2">
                <i class="fa-brands fa-apple text-2xl text-gray-900"></i>
                <span class="text-sm font-medium text-gray-700"><?= htmlspecialchars(t('billing.apple_pay')) ?></span>
            </div>
            <div class="px-4 py-2 bg-gray-50 rounded-lg border border-gray-200 flex items-center gap-2">
                <i class="fa-solid fa-building-columns text-2xl text-green-600"></i>
                <span class="text-sm font-medium text-gray-700"><?= htmlspecialchars(t('billing.omannet')) ?></span>
            </div>
        </div>
    </div>
</div>

<?php adminFooter(); ?>
