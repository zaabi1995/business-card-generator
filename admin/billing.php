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
    adminHeader('Billing', 'billing');
    echo '<div class="bg-amber-50 border border-amber-200 rounded-xl p-6 text-amber-700">';
    echo '<i class="fa-solid fa-exclamation-triangle mr-2"></i>';
    echo 'Billing module is not configured. Please contact your administrator.';
    echo '</div>';
    adminFooter();
    exit;
}

try {
    require_once INCLUDES_DIR . '/Billing.php';
} catch (Throwable $e) {
    error_log("Billing.php load error: " . $e->getMessage());
    adminHeader('Billing', 'billing');
    echo '<div class="bg-red-50 border border-red-200 rounded-xl p-6 text-red-700">';
    echo 'Error loading billing module: ' . htmlspecialchars($e->getMessage());
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
            'callback_url' => (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . getBasePath() . 'amwalpay/callback.php',
            'return_url' => (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . getBasePath() . 'admin/billing.php'
        ];
    }

    $billing = new Billing($gateway, $billingConfig);
} catch (Throwable $e) {
    error_log("Billing init error: " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine());
    adminHeader('Billing', 'billing');
    echo '<div class="bg-red-50 border border-red-200 rounded-xl p-6 text-red-700">';
    echo '<i class="fa-solid fa-exclamation-circle mr-2"></i>';
    echo 'Error initializing billing system: ' . htmlspecialchars($e->getMessage());
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
        $message = 'Payment completed successfully! Your subscription has been activated.';
    } elseif ($_GET['payment'] === 'error') {
        $message = $_GET['message'] ?? 'Payment processing failed. Please try again.';
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

// Handle subscription upgrade
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'subscribe') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { die('Invalid request'); }
    $planId = $_POST['plan_id'] ?? '';
    $billingCycle = $_POST['billing_cycle'] ?? 'monthly';
    
    $result = $billing->createSubscription($companyId, $planId, $billingCycle);

    if ($result['success'] && !empty($result['payment_url'])) {
        // Paymob: redirect directly to unified checkout
        header('Location: ' . $result['payment_url']);
        exit;
    } elseif ($result['success'] && !empty($result['payment_data'])) {
        // Legacy Amwal: redirect to process page
        header('Location: ' . getBasePath() . 'amwalpay/process.php?order_id=' . urlencode($result['transaction_id']));
        exit;
    } else {
        $message = $result['error'] ?? 'Failed to create subscription';
        $messageType = 'error';
    }
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

adminHeader('Billing', 'billing');
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
                <p class="text-sm text-gray-600 mb-1">Current Plan</p>
                <h2 class="text-3xl font-bold text-gray-900"><?php echo sanitize($currentPlan['name'] ?? 'Free'); ?></h2>
                <p class="text-gray-600 mt-1"><?php echo sanitize($currentPlan['description'] ?? 'Basic features for small teams'); ?></p>
            </div>
            <div class="text-left md:text-right">
                <p class="text-sm text-gray-600 mb-1">Monthly Price</p>
                <p class="text-3xl font-bold text-gray-900">
                    <?php echo Currency::formatHtml($currentPlan['price_monthly'] ?? 0, $companyCurrency, 'lg'); ?>
                    <span class="text-lg font-normal text-gray-500">/mo</span>
                </p>
                <p class="text-xs text-gray-500 mt-1">Currency: <?php echo $companyCurrency; ?></p>
            </div>
        </div>
    </div>
    
    <!-- Usage Stats -->
    <div class="p-6">
        <h3 class="font-semibold text-gray-900 mb-4">Usage</h3>
        <div class="grid md:grid-cols-3 gap-6">
            <div>
                <div class="flex items-center justify-between mb-2">
                    <span class="text-sm text-gray-600">Employees</span>
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
                    <span class="text-sm text-gray-600">Templates</span>
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
                    <span class="text-sm text-gray-600">Storage</span>
                    <span class="text-sm font-medium text-gray-900">
                        <?php echo $planLimits['storage'] === -1 ? 'Unlimited' : $planLimits['storage'] . ' GB'; ?>
                    </span>
                </div>
                <div class="h-2 bg-gray-100 rounded-full overflow-hidden">
                    <div class="h-full bg-green-600 rounded-full" style="width: 30%"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Feature Comparison Banner -->
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
                Unlock Premium Features
            </h3>
            <p class="mt-2 text-blue-100">Get high-quality card generation, QR analytics, and more with a paid plan.</p>
            <div class="mt-4 flex flex-wrap gap-4">
                <div class="flex items-center gap-2 text-sm">
                    <i class="fa-solid fa-check-circle text-green-300"></i>
                    <span>HD Quality Cards (300+ DPI)</span>
                </div>
                <div class="flex items-center gap-2 text-sm">
                    <i class="fa-solid fa-check-circle text-green-300"></i>
                    <span>QR Scan Analytics</span>
                </div>
                <div class="flex items-center gap-2 text-sm">
                    <i class="fa-solid fa-check-circle text-green-300"></i>
                    <span>Bulk Card Generation</span>
                </div>
            </div>
        </div>
        <div class="flex-shrink-0">
            <a href="#plans" class="inline-flex items-center gap-2 px-6 py-3 bg-white text-blue-600 rounded-lg font-semibold hover:bg-blue-50 transition-colors">
                <i class="fa-solid fa-rocket"></i>
                View Plans
            </a>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Feature Matrix -->
<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden mb-8">
    <div class="p-4 border-b border-gray-100">
        <h3 class="font-semibold text-gray-900">Plan Feature Comparison</h3>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left font-medium text-gray-600">Feature</th>
                    <th class="px-4 py-3 text-center font-medium text-gray-600">Free</th>
                    <th class="px-4 py-3 text-center font-medium text-gray-600">Starter</th>
                    <th class="px-4 py-3 text-center font-medium text-gray-600">Professional</th>
                    <th class="px-4 py-3 text-center font-medium text-gray-600">Enterprise</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <tr>
                    <td class="px-4 py-3 text-gray-900">Card Preview Quality</td>
                    <td class="px-4 py-3 text-center"><span class="text-amber-600">Low (~100 DPI)</span></td>
                    <td class="px-4 py-3 text-center"><span class="text-green-600">High (~300 DPI)</span></td>
                    <td class="px-4 py-3 text-center"><span class="text-green-600">High (~300 DPI)</span></td>
                    <td class="px-4 py-3 text-center"><span class="text-green-600">High (~300 DPI)</span></td>
                </tr>
                <tr>
                    <td class="px-4 py-3 text-gray-900">Print Quality</td>
                    <td class="px-4 py-3 text-center"><i class="fa-solid fa-check text-green-600"></i> Full Quality</td>
                    <td class="px-4 py-3 text-center"><i class="fa-solid fa-check text-green-600"></i> Full Quality</td>
                    <td class="px-4 py-3 text-center"><i class="fa-solid fa-check text-green-600"></i> Full Quality</td>
                    <td class="px-4 py-3 text-center"><i class="fa-solid fa-check text-green-600"></i> Full Quality</td>
                </tr>
                <tr>
                    <td class="px-4 py-3 text-gray-900">QR Scan Analytics</td>
                    <td class="px-4 py-3 text-center"><i class="fa-solid fa-times text-gray-400"></i></td>
                    <td class="px-4 py-3 text-center"><i class="fa-solid fa-check text-green-600"></i></td>
                    <td class="px-4 py-3 text-center"><i class="fa-solid fa-check text-green-600"></i></td>
                    <td class="px-4 py-3 text-center"><i class="fa-solid fa-check text-green-600"></i></td>
                </tr>
                <tr>
                    <td class="px-4 py-3 text-gray-900">Bulk Card Generation</td>
                    <td class="px-4 py-3 text-center"><i class="fa-solid fa-times text-gray-400"></i></td>
                    <td class="px-4 py-3 text-center"><i class="fa-solid fa-check text-green-600"></i></td>
                    <td class="px-4 py-3 text-center"><i class="fa-solid fa-check text-green-600"></i></td>
                    <td class="px-4 py-3 text-center"><i class="fa-solid fa-check text-green-600"></i></td>
                </tr>
                <tr>
                    <td class="px-4 py-3 text-gray-900">API Access</td>
                    <td class="px-4 py-3 text-center"><i class="fa-solid fa-times text-gray-400"></i></td>
                    <td class="px-4 py-3 text-center"><i class="fa-solid fa-times text-gray-400"></i></td>
                    <td class="px-4 py-3 text-center"><i class="fa-solid fa-check text-green-600"></i></td>
                    <td class="px-4 py-3 text-center"><i class="fa-solid fa-check text-green-600"></i></td>
                </tr>
                <tr>
                    <td class="px-4 py-3 text-gray-900">Custom Branding</td>
                    <td class="px-4 py-3 text-center"><i class="fa-solid fa-times text-gray-400"></i></td>
                    <td class="px-4 py-3 text-center"><i class="fa-solid fa-times text-gray-400"></i></td>
                    <td class="px-4 py-3 text-center"><i class="fa-solid fa-check text-green-600"></i></td>
                    <td class="px-4 py-3 text-center"><i class="fa-solid fa-check text-green-600"></i></td>
                </tr>
                <tr>
                    <td class="px-4 py-3 text-gray-900">Priority Support</td>
                    <td class="px-4 py-3 text-center"><i class="fa-solid fa-times text-gray-400"></i></td>
                    <td class="px-4 py-3 text-center"><i class="fa-solid fa-times text-gray-400"></i></td>
                    <td class="px-4 py-3 text-center"><i class="fa-solid fa-check text-green-600"></i></td>
                    <td class="px-4 py-3 text-center"><i class="fa-solid fa-check text-green-600"></i></td>
                </tr>
            </tbody>
        </table>
    </div>
    <div class="p-4 bg-blue-50 border-t border-blue-100">
        <p class="text-sm text-blue-700">
            <i class="fa-solid fa-info-circle mr-1"></i>
            <strong>Note:</strong> All plans include full print quality when ordering physical cards from print shops. 
            Preview quality affects only the digital card images displayed in the dashboard.
        </p>
    </div>
</div>

<!-- Available Plans -->
<h2 id="plans" class="text-xl font-bold text-gray-900 mb-6">Upgrade Your Plan</h2>
<?php if (empty($plans)): ?>
<div class="bg-white rounded-xl border border-gray-200 p-8 text-center mb-8">
    <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
        <i class="fa-solid fa-credit-card text-gray-400 text-2xl"></i>
    </div>
    <h3 class="text-lg font-medium text-gray-900 mb-2">No Subscription Plans Available</h3>
    <p class="text-gray-500">Subscription plans have not been configured yet. Contact your administrator.</p>
</div>
<?php else: ?>
<div class="grid md:grid-cols-3 gap-6 mb-8">
    <?php foreach ($plans as $plan): ?>
    <?php $isCurrent = ($currentPlan['id'] ?? '') === $plan['id']; ?>
    <div class="bg-white rounded-xl border-2 <?php echo $plan['id'] === 'pro' ? 'border-blue-500 shadow-lg shadow-blue-500/10' : 'border-gray-200'; ?> overflow-hidden relative">
        <?php if ($plan['id'] === 'pro'): ?>
        <div class="absolute top-0 right-0 bg-blue-600 text-white text-xs font-bold px-3 py-1 rounded-bl-lg">
            Popular
        </div>
        <?php endif; ?>
        
        <div class="p-6">
            <h3 class="text-xl font-bold text-gray-900"><?php echo sanitize($plan['name']); ?></h3>
            <p class="text-gray-600 text-sm mt-1"><?php echo sanitize($plan['description']); ?></p>
            
            <div class="mt-4">
                <span class="text-4xl font-bold text-gray-900"><?php echo Currency::formatHtml($plan['price_monthly'], $companyCurrency, 'xl'); ?></span>
                <span class="text-gray-500">/month</span>
            </div>
            <?php if ($plan['price_yearly'] > 0): ?>
            <p class="text-sm text-green-600 mt-1">
                or <?php echo Currency::formatHtml($plan['price_yearly'], $companyCurrency, 'sm'); ?>/year (save <?php echo round((1 - ($plan['price_yearly'] / ($plan['price_monthly'] * 12))) * 100); ?>%)
            </p>
            <?php endif; ?>
            
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
                    <?php echo $maxEmployees === -1 ? 'Unlimited' : $maxEmployees; ?> employees
                </li>
                <li class="flex items-center gap-2 text-sm text-gray-600">
                    <i class="fa-solid fa-check text-green-600"></i>
                    <?php echo $maxTemplates === -1 ? 'Unlimited' : $maxTemplates; ?> templates
                </li>
                <li class="flex items-center gap-2 text-sm text-gray-600">
                    <i class="fa-solid fa-check text-green-600"></i>
                    <?php echo $maxStorage === -1 ? 'Unlimited' : $maxStorage . 'GB'; ?> storage
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
                    Current Plan
                </button>
                <?php else: ?>
                <form method="post">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="subscribe">
                    <input type="hidden" name="plan_id" value="<?php echo $plan['id']; ?>">
                    <input type="hidden" name="billing_cycle" value="monthly">
                    <button type="submit" class="w-full py-3 <?php echo $plan['id'] === 'pro' ? 'bg-blue-600 hover:bg-blue-700' : 'bg-gray-900 hover:bg-gray-800'; ?> text-white rounded-lg font-medium transition-colors">
                        Upgrade to <?php echo sanitize($plan['name']); ?>
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Payment Methods -->
<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
    <div class="p-4 border-b border-gray-100">
        <h3 class="font-semibold text-gray-900 flex items-center gap-2">
            <i class="fa-solid fa-credit-card text-blue-600"></i>
            Payment Methods
        </h3>
    </div>
    <div class="p-6">
        <p class="text-gray-600 text-sm mb-4">We accept the following payment methods:</p>
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
                <span class="text-sm font-medium text-gray-700">Apple Pay</span>
            </div>
            <div class="px-4 py-2 bg-gray-50 rounded-lg border border-gray-200 flex items-center gap-2">
                <i class="fa-solid fa-building-columns text-2xl text-green-600"></i>
                <span class="text-sm font-medium text-gray-700">Omannet</span>
            </div>
        </div>
    </div>
</div>

<?php adminFooter(); ?>
