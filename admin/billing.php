<?php
/**
 * Billing & Subscription Management
 */
require_once __DIR__ . '/../config.php';
requireAdmin();
require_once INCLUDES_DIR . '/Billing.php';

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
        $messageType = 'success';
    } elseif ($_GET['payment'] === 'error') {
        $message = $_GET['message'] ?? 'Payment processing failed. Please try again.';
        $messageType = 'error';
    }
}

// Get current company
$company = $db->fetchOne("SELECT * FROM companies WHERE id = :id", ['id' => $companyId]);

// Get available plans
$plans = $db->fetchAll("SELECT * FROM subscription_plans WHERE is_active = 1 ORDER BY price_monthly ASC");

// Get current plan limits
$planLimits = $billing->getPlanLimits($companyId);
$currentPlan = $db->fetchOne("SELECT * FROM subscription_plans WHERE id = :id", ['id' => $company['plan'] ?? 'free']);

// Handle subscription upgrade
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'subscribe') {
    $planId = $_POST['plan_id'] ?? '';
    $billingCycle = $_POST['billing_cycle'] ?? 'monthly';
    
    $result = $billing->createSubscription($companyId, $planId, $billingCycle);
    
    if ($result['success'] && !empty($result['payment_data'])) {
        // Redirect to process endpoint with order ID
        $orderId = $result['transaction_id'];
        header('Location: ' . getBasePath() . 'amwalpay/process.php?order_id=' . urlencode($orderId));
        exit;
    } else {
        $message = $result['error'] ?? 'Failed to create subscription';
        $messageType = 'error';
    }
}

// Get usage stats
$employeeCount = $db->fetchOne("SELECT COUNT(*) as count FROM employees WHERE company_id = :id", ['id' => $companyId])['count'] ?? 0;
$templateCount = $db->fetchOne("SELECT COUNT(*) as count FROM templates WHERE company_id = :id", ['id' => $companyId])['count'] ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Billing & Subscription | <?php echo SITE_NAME; ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo assetUrl('css/tailwind.css'); ?>">
    <style>
        .glass-card { background: rgba(255, 255, 255, 0.03); backdrop-filter: blur(20px); border: 1px solid rgba(255, 255, 255, 0.1); }
        .btn-bhd { background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%); transition: all 0.3s ease; border: 1px solid rgba(212, 175, 55, 0.3); }
        .btn-bhd:hover { box-shadow: 0 0 20px rgba(212, 175, 55, 0.2); border-color: rgba(212, 175, 55, 0.5); }
        .plan-card { transition: all 0.3s ease; }
        .plan-card:hover { transform: translateY(-4px); }
        .plan-card.featured { border: 2px solid rgba(212, 175, 55, 0.6); }
    </style>
</head>
<body class="bg-alzayani-dark text-white font-sans min-h-screen antialiased">
    <div class="min-h-screen">
        <!-- Header -->
        <header class="glass-card border-b border-white/10">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4">
                <div class="flex items-center justify-between">
                    <div class="flex items-center space-x-4">
                        <a href="index.php" class="text-gray-400 hover:text-white transition-colors">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                            </svg>
                        </a>
                        <div>
                            <h1 class="text-xl font-bold text-white">Billing & Subscription</h1>
                            <p class="text-gray-500 text-xs">Manage your subscription plan</p>
                        </div>
                    </div>
                </div>
            </div>
        </header>
        
        <!-- Main Content -->
        <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
            <?php if ($message): ?>
            <div class="mb-6 p-4 rounded-xl <?php echo $messageType === 'success' ? 'bg-green-500/10 border border-green-500/30 text-green-400' : 'bg-red-500/10 border border-red-500/30 text-red-400'; ?>">
                <?php echo sanitize($message); ?>
            </div>
            <?php endif; ?>
            
            <!-- Current Plan -->
            <div class="glass-card rounded-xl p-6 mb-8">
                <h2 class="text-xl font-bold mb-4">Current Plan</h2>
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-2xl font-bold text-white"><?php echo sanitize($currentPlan['name'] ?? 'Free'); ?></p>
                        <p class="text-gray-400 text-sm"><?php echo sanitize($currentPlan['description'] ?? ''); ?></p>
                    </div>
                    <div class="text-right">
                        <p class="text-3xl font-bold text-amber-400">
                            $<?php echo number_format($currentPlan['price_monthly'] ?? 0, 2); ?>
                            <span class="text-lg text-gray-400">/month</span>
                        </p>
                        <?php if ($company['subscription_expires_at']): ?>
                        <p class="text-gray-500 text-sm mt-1">
                            Expires: <?php echo date('M d, Y', strtotime($company['subscription_expires_at'])); ?>
                        </p>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Usage Stats -->
                <div class="grid grid-cols-3 gap-4 mt-6 pt-6 border-t border-white/10">
                    <div>
                        <p class="text-gray-400 text-sm">Employees</p>
                        <p class="text-xl font-bold text-white">
                            <?php echo $employeeCount; ?>
                            <?php if ($planLimits['max_employees'] !== -1): ?>
                            <span class="text-sm text-gray-500">/ <?php echo $planLimits['max_employees']; ?></span>
                            <?php else: ?>
                            <span class="text-sm text-green-400">/ Unlimited</span>
                            <?php endif; ?>
                        </p>
                    </div>
                    <div>
                        <p class="text-gray-400 text-sm">Templates</p>
                        <p class="text-xl font-bold text-white">
                            <?php echo $templateCount; ?>
                            <?php if ($planLimits['max_templates'] !== -1): ?>
                            <span class="text-sm text-gray-500">/ <?php echo $planLimits['max_templates']; ?></span>
                            <?php else: ?>
                            <span class="text-sm text-green-400">/ Unlimited</span>
                            <?php endif; ?>
                        </p>
                    </div>
                    <div>
                        <p class="text-gray-400 text-sm">Status</p>
                        <p class="text-xl font-bold <?php echo $billing->hasActiveSubscription($companyId) ? 'text-green-400' : 'text-gray-400'; ?>">
                            <?php echo $billing->hasActiveSubscription($companyId) ? 'Active' : 'Inactive'; ?>
                        </p>
                    </div>
                </div>
            </div>
            
            <!-- Available Plans -->
            <div>
                <h2 class="text-xl font-bold mb-6">Available Plans</h2>
                <div class="grid md:grid-cols-3 gap-6">
                    <?php foreach ($plans as $plan): ?>
                    <div class="glass-card rounded-xl p-6 plan-card <?php echo ($plan['id'] === ($company['plan'] ?? 'free')) ? 'featured' : ''; ?>">
                        <div class="text-center mb-4">
                            <h3 class="text-xl font-bold text-white mb-2"><?php echo sanitize($plan['name']); ?></h3>
                            <p class="text-gray-400 text-sm mb-4"><?php echo sanitize($plan['description']); ?></p>
                            <div class="mb-4">
                                <span class="text-4xl font-bold text-white">$<?php echo number_format($plan['price_monthly'], 2); ?></span>
                                <span class="text-gray-400">/month</span>
                            </div>
                            <?php if ($plan['price_yearly'] > 0): ?>
                            <p class="text-sm text-gray-500">
                                or $<?php echo number_format($plan['price_yearly'], 2); ?>/year
                                <span class="text-green-400">(Save <?php echo round((1 - ($plan['price_yearly'] / ($plan['price_monthly'] * 12))) * 100); ?>%)</span>
                            </p>
                            <?php endif; ?>
                        </div>
                        
                        <ul class="space-y-2 mb-6">
                            <li class="flex items-center space-x-2 text-sm">
                                <span class="text-green-400">✓</span>
                                <span>
                                    <?php echo $plan['max_employees'] === -1 ? 'Unlimited' : $plan['max_employees']; ?> Employees
                                </span>
                            </li>
                            <li class="flex items-center space-x-2 text-sm">
                                <span class="text-green-400">✓</span>
                                <span>
                                    <?php echo $plan['max_templates'] === -1 ? 'Unlimited' : $plan['max_templates']; ?> Templates
                                </span>
                            </li>
                            <li class="flex items-center space-x-2 text-sm">
                                <span class="text-green-400">✓</span>
                                <span>
                                    <?php echo $plan['max_storage_mb'] === -1 ? 'Unlimited' : $plan['max_storage_mb'] . ' MB'; ?> Storage
                                </span>
                            </li>
                        </ul>
                        
                        <?php if ($plan['id'] === ($company['plan'] ?? 'free')): ?>
                        <button disabled class="w-full py-3 rounded-xl bg-gray-700 text-gray-400 cursor-not-allowed">
                            Current Plan
                        </button>
                        <?php else: ?>
                        <form method="post" class="space-y-2">
                            <input type="hidden" name="action" value="subscribe">
                            <input type="hidden" name="plan_id" value="<?php echo sanitize($plan['id']); ?>">
                            <select name="billing_cycle" class="w-full px-4 py-2 rounded-lg bg-white/5 border border-white/10 text-white text-sm mb-2">
                                <option value="monthly">Monthly</option>
                                <?php if ($plan['price_yearly'] > 0): ?>
                                <option value="yearly">Yearly (Save <?php echo round((1 - ($plan['price_yearly'] / ($plan['price_monthly'] * 12))) * 100); ?>%)</option>
                                <?php endif; ?>
                            </select>
                            <button type="submit" class="btn-bhd w-full py-3 rounded-xl text-white">
                                Upgrade to <?php echo sanitize($plan['name']); ?>
                            </button>
                        </form>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </main>
    </div>
</body>
</html>
