<?php
/**
 * Billing & Subscription Management - Cardify
 */
require_once __DIR__ . '/../config.php';
requireAdmin();
require_once INCLUDES_DIR . '/Billing.php';
require_once INCLUDES_DIR . '/admin-layout.php';

$db = Database::getInstance();
$billing = new Billing(BILLING_GATEWAY ?? 'amwal', [
    'merchant_id' => AMWAL_MERCHANT_ID ?? '',
    'terminal_id' => AMWAL_TERMINAL_ID ?? '',
    'secure_key' => AMWAL_SECURE_KEY ?? '',
    'api_url' => AMWAL_API_URL ?? 'https://backend.sa.amwal.tech',
    'callback_url' => (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . getBasePath() . 'amwalpay/callback.php',
    'return_url' => (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . getBasePath() . 'admin/billing.php'
]);

$companyId = getCurrentCompanyId();
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

$company = $db->fetchOne("SELECT * FROM companies WHERE id = :id", ['id' => $companyId]);
$plans = $db->fetchAll("SELECT * FROM subscription_plans WHERE is_active = 1 ORDER BY price_monthly ASC");
$planLimits = $billing->getPlanLimits($companyId);
$currentPlan = $db->fetchOne("SELECT * FROM subscription_plans WHERE id = :id", ['id' => $company['plan'] ?? 'free']);

// Handle subscription upgrade
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'subscribe') {
    $planId = $_POST['plan_id'] ?? '';
    $billingCycle = $_POST['billing_cycle'] ?? 'monthly';
    
    $result = $billing->createSubscription($companyId, $planId, $billingCycle);
    
    if ($result['success'] && !empty($result['payment_data'])) {
        header('Location: ' . getBasePath() . 'amwalpay/process.php?order_id=' . urlencode($result['transaction_id']));
        exit;
    } else {
        $message = $result['error'] ?? 'Failed to create subscription';
        $messageType = 'error';
    }
}

$employeeCount = $db->fetchOne("SELECT COUNT(*) as count FROM employees WHERE company_id = :id", ['id' => $companyId])['count'] ?? 0;
$templateCount = $db->fetchOne("SELECT COUNT(*) as count FROM templates WHERE company_id = :id", ['id' => $companyId])['count'] ?? 0;

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
                    $<?php echo number_format($currentPlan['price_monthly'] ?? 0, 2); ?>
                    <span class="text-lg font-normal text-gray-500">/mo</span>
                </p>
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
                    <div class="h-full bg-blue-600 rounded-full" style="width: <?php echo $planLimits['employees'] === -1 ? 50 : min(100, ($employeeCount / $planLimits['employees']) * 100); ?>%"></div>
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
                    <div class="h-full bg-purple-600 rounded-full" style="width: <?php echo $planLimits['templates'] === -1 ? 50 : min(100, ($templateCount / $planLimits['templates']) * 100); ?>%"></div>
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

<!-- Available Plans -->
<h2 class="text-xl font-bold text-gray-900 mb-6">Upgrade Your Plan</h2>
<div class="grid md:grid-cols-3 gap-6 mb-8">
    <?php foreach ($plans as $plan): ?>
    <?php $isCurrent = ($currentPlan['id'] ?? '') === $plan['id']; ?>
    <div class="bg-white rounded-xl border-2 <?php echo $plan['is_featured'] ? 'border-blue-500 shadow-lg shadow-blue-500/10' : 'border-gray-200'; ?> overflow-hidden relative">
        <?php if ($plan['is_featured']): ?>
        <div class="absolute top-0 right-0 bg-blue-600 text-white text-xs font-bold px-3 py-1 rounded-bl-lg">
            Popular
        </div>
        <?php endif; ?>
        
        <div class="p-6">
            <h3 class="text-xl font-bold text-gray-900"><?php echo sanitize($plan['name']); ?></h3>
            <p class="text-gray-600 text-sm mt-1"><?php echo sanitize($plan['description']); ?></p>
            
            <div class="mt-4">
                <span class="text-4xl font-bold text-gray-900">$<?php echo number_format($plan['price_monthly'], 0); ?></span>
                <span class="text-gray-500">/month</span>
            </div>
            
            <ul class="mt-6 space-y-3">
                <li class="flex items-center gap-2 text-sm text-gray-600">
                    <i class="fa-solid fa-check text-green-600"></i>
                    <?php $limits = json_decode($plan['limits'], true); ?>
                    <?php echo $limits['employees'] === -1 ? 'Unlimited' : $limits['employees']; ?> employees
                </li>
                <li class="flex items-center gap-2 text-sm text-gray-600">
                    <i class="fa-solid fa-check text-green-600"></i>
                    <?php echo $limits['templates'] === -1 ? 'Unlimited' : $limits['templates']; ?> templates
                </li>
                <li class="flex items-center gap-2 text-sm text-gray-600">
                    <i class="fa-solid fa-check text-green-600"></i>
                    <?php echo $limits['storage'] === -1 ? 'Unlimited' : $limits['storage'] . 'GB'; ?> storage
                </li>
                <?php if (!empty($limits['features'])): ?>
                <?php foreach ($limits['features'] as $feature): ?>
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
                    <input type="hidden" name="action" value="subscribe">
                    <input type="hidden" name="plan_id" value="<?php echo $plan['id']; ?>">
                    <input type="hidden" name="billing_cycle" value="monthly">
                    <button type="submit" class="w-full py-3 <?php echo $plan['is_featured'] ? 'bg-blue-600 hover:bg-blue-700' : 'bg-gray-900 hover:bg-gray-800'; ?> text-white rounded-lg font-medium transition-colors">
                        Upgrade to <?php echo sanitize($plan['name']); ?>
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

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
        <div class="flex items-center gap-4">
            <div class="px-4 py-2 bg-gray-50 rounded-lg border border-gray-200">
                <i class="fa-brands fa-cc-visa text-2xl text-blue-600"></i>
            </div>
            <div class="px-4 py-2 bg-gray-50 rounded-lg border border-gray-200">
                <i class="fa-brands fa-cc-mastercard text-2xl text-red-500"></i>
            </div>
            <div class="px-4 py-2 bg-gray-50 rounded-lg border border-gray-200">
                <i class="fa-brands fa-cc-amex text-2xl text-blue-500"></i>
            </div>
        </div>
    </div>
</div>

<?php adminFooter(); ?>
