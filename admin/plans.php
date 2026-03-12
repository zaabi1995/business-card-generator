<?php
/**
 * Subscription Plans Management (Super Admin) - Cardify
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/admin-layout.php';

Auth::requireRole('super_admin');

$db = Database::getInstance();
$message = null;
$messageType = 'success';

// Get all plans with prices
$plans = $db->fetchAll("
    SELECT sp.*, 
        (SELECT COUNT(*) FROM companies WHERE plan = sp.id) as company_count
    FROM subscription_plans sp 
    ORDER BY sp.sort_order, sp.name
");

// Get all currencies from plan_prices
$currencies = ['USD', 'OMR', 'EUR'];
try {
    $currencyRows = $db->fetchAll("SELECT DISTINCT currency FROM plan_prices ORDER BY currency");
    if (!empty($currencyRows)) {
        $currencies = array_column($currencyRows, 'currency');
    }
} catch (Exception $e) {
    // Use defaults
}

// Get prices for each plan
$planPrices = [];
foreach ($plans as $plan) {
    try {
        $prices = $db->fetchAll("SELECT * FROM plan_prices WHERE plan_id = :plan_id", ['plan_id' => $plan['id']]);
        foreach ($prices as $price) {
            $planPrices[$plan['id']][$price['currency']] = $price;
        }
    } catch (Exception $e) {
        // No prices yet
    }
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { die('Invalid request'); }
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_plan') {
        $planId = $_POST['plan_id'] ?? '';
        $name = $_POST['name'] ?? '';
        $description = $_POST['description'] ?? '';
        $maxEmployees = (int)($_POST['max_employees'] ?? -1);
        $maxTemplates = (int)($_POST['max_templates'] ?? -1);
        $maxStorageMb = (int)($_POST['max_storage_mb'] ?? -1);
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $sortOrder = (int)($_POST['sort_order'] ?? 0);
        
        // Get old data for audit
        $oldPlan = $db->fetchOne("SELECT * FROM subscription_plans WHERE id = :id", ['id' => $planId]);
        
        $db->update('subscription_plans', [
            'name' => $name,
            'description' => $description,
            'max_employees' => $maxEmployees,
            'max_templates' => $maxTemplates,
            'max_storage_mb' => $maxStorageMb,
            'is_active' => $isActive,
            'sort_order' => $sortOrder,
            'updated_at' => date('Y-m-d H:i:s')
        ], 'id = :id', ['id' => $planId]);
        
        // Update prices
        foreach ($currencies as $currency) {
            $monthlyKey = "price_monthly_{$currency}";
            $yearlyKey = "price_yearly_{$currency}";
            
            if (isset($_POST[$monthlyKey]) || isset($_POST[$yearlyKey])) {
                $monthly = (float)($_POST[$monthlyKey] ?? 0);
                $yearly = (float)($_POST[$yearlyKey] ?? 0);
                
                // Check if price exists
                $existing = $db->fetchOne(
                    "SELECT id FROM plan_prices WHERE plan_id = :plan_id AND currency = :currency",
                    ['plan_id' => $planId, 'currency' => $currency]
                );
                
                if ($existing) {
                    $db->update('plan_prices', [
                        'price_monthly' => $monthly,
                        'price_yearly' => $yearly,
                        'updated_at' => date('Y-m-d H:i:s')
                    ], 'id = :id', ['id' => $existing['id']]);
                } else {
                    $db->insert('plan_prices', [
                        'id' => generateUUID(),
                        'plan_id' => $planId,
                        'currency' => $currency,
                        'price_monthly' => $monthly,
                        'price_yearly' => $yearly,
                        'created_at' => date('Y-m-d H:i:s')
                    ]);
                }
            }
        }
        
        // Audit log
        if (class_exists('AuditLog')) {
            $newPlan = $db->fetchOne("SELECT * FROM subscription_plans WHERE id = :id", ['id' => $planId]);
            AuditLog::logPlan('update', $planId, $oldPlan, $newPlan);
        }
        
        $message = 'Plan updated successfully!';
        
        // Refresh data
        $plans = $db->fetchAll("
            SELECT sp.*, 
                (SELECT COUNT(*) FROM companies WHERE plan = sp.id) as company_count
            FROM subscription_plans sp 
            ORDER BY sp.sort_order, sp.name
        ");
        
        $planPrices = [];
        foreach ($plans as $plan) {
            $prices = $db->fetchAll("SELECT * FROM plan_prices WHERE plan_id = :plan_id", ['plan_id' => $plan['id']]);
            foreach ($prices as $price) {
                $planPrices[$plan['id']][$price['currency']] = $price;
            }
        }
    } elseif ($action === 'add_currency') {
        $newCurrency = strtoupper(trim($_POST['new_currency'] ?? ''));
        
        if (!empty($newCurrency) && strlen($newCurrency) === 3 && !in_array($newCurrency, $currencies)) {
            // Add prices for all plans with this currency
            foreach ($plans as $plan) {
                $db->insert('plan_prices', [
                    'id' => generateUUID(),
                    'plan_id' => $plan['id'],
                    'currency' => $newCurrency,
                    'price_monthly' => 0,
                    'price_yearly' => 0,
                    'created_at' => date('Y-m-d H:i:s')
                ]);
            }
            
            $currencies[] = $newCurrency;
            sort($currencies);
            
            $message = "Currency {$newCurrency} added successfully!";
            
            // Refresh prices
            $planPrices = [];
            foreach ($plans as $plan) {
                $prices = $db->fetchAll("SELECT * FROM plan_prices WHERE plan_id = :plan_id", ['plan_id' => $plan['id']]);
                foreach ($prices as $price) {
                    $planPrices[$plan['id']][$price['currency']] = $price;
                }
            }
        } else {
            $message = 'Invalid currency code. Must be 3 letters (e.g., GBP).';
            $messageType = 'error';
        }
    }
}

adminHeader('Plans', 'plans');
?>

<?php if ($message): ?>
<div class="mb-6 p-4 rounded-xl flex items-center gap-3 <?php echo $messageType === 'success' ? 'bg-green-50 border border-green-200 text-green-700' : 'bg-red-50 border border-red-200 text-red-700'; ?>">
    <i class="<?php echo $messageType === 'success' ? 'fa-solid fa-circle-check' : 'fa-solid fa-circle-exclamation'; ?>"></i>
    <?php echo sanitize($message); ?>
</div>
<?php endif; ?>

<!-- Add Currency -->
<div class="mb-6 flex items-center gap-4">
    <form method="post" class="flex items-center gap-2">
        <?php echo csrfField(); ?>
        <input type="hidden" name="action" value="add_currency">
        <input type="text" name="new_currency" placeholder="GBP" maxlength="3" 
               class="w-20 px-3 py-2 bg-white border border-gray-200 rounded-lg text-gray-900 uppercase">
        <button type="submit" class="px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg text-sm font-medium transition-colors">
            Add Currency
        </button>
    </form>
    <span class="text-gray-500 text-sm">Current: <?php echo implode(', ', $currencies); ?></span>
</div>

<!-- Plans Grid -->
<div class="grid gap-6">
    <?php foreach ($plans as $plan): ?>
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <form method="post">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="update_plan">
            <input type="hidden" name="plan_id" value="<?php echo $plan['id']; ?>">
            
            <div class="p-6 border-b border-gray-100">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-4">
                        <div class="w-12 h-12 rounded-xl flex items-center justify-center text-white font-bold
                            <?php echo match($plan['id']) {
                                'enterprise' => 'bg-purple-500',
                                'pro' => 'bg-blue-500',
                                default => 'bg-gray-500'
                            }; ?>">
                            <?php echo strtoupper(substr($plan['name'], 0, 1)); ?>
                        </div>
                        <div>
                            <input type="text" name="name" value="<?php echo sanitize($plan['name']); ?>" 
                                   class="text-xl font-bold text-gray-900 border-0 border-b border-transparent hover:border-gray-200 focus:border-blue-500 bg-transparent px-0">
                            <p class="text-gray-500 text-sm"><?php echo (int)$plan['company_count']; ?> companies using this plan</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-4">
                        <label class="flex items-center gap-2">
                            <input type="checkbox" name="is_active" value="1" <?php echo $plan['is_active'] ? 'checked' : ''; ?>
                                   class="w-4 h-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                            <span class="text-sm text-gray-600">Active</span>
                        </label>
                        <div class="flex items-center gap-2">
                            <label class="text-sm text-gray-600">Order:</label>
                            <input type="number" name="sort_order" value="<?php echo (int)$plan['sort_order']; ?>" 
                                   class="w-16 px-2 py-1 border border-gray-200 rounded text-center text-sm">
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="p-6 grid md:grid-cols-2 gap-6">
                <!-- Limits -->
                <div class="space-y-4">
                    <h4 class="font-semibold text-gray-900">Limits</h4>
                    
                    <div>
                        <label class="block text-sm text-gray-600 mb-1">Description</label>
                        <textarea name="description" rows="2" 
                                  class="w-full px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 text-sm"><?php echo sanitize($plan['description'] ?? ''); ?></textarea>
                    </div>
                    
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm text-gray-600 mb-1">Max Employees</label>
                            <input type="number" name="max_employees" value="<?php echo (int)$plan['max_employees']; ?>" 
                                   class="w-full px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 text-sm"
                                   placeholder="-1 = unlimited">
                        </div>
                        <div>
                            <label class="block text-sm text-gray-600 mb-1">Max Templates</label>
                            <input type="number" name="max_templates" value="<?php echo (int)$plan['max_templates']; ?>" 
                                   class="w-full px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 text-sm"
                                   placeholder="-1 = unlimited">
                        </div>
                        <div>
                            <label class="block text-sm text-gray-600 mb-1">Max Storage (MB)</label>
                            <input type="number" name="max_storage_mb" value="<?php echo (int)$plan['max_storage_mb']; ?>" 
                                   class="w-full px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 text-sm"
                                   placeholder="-1 = unlimited">
                        </div>
                    </div>
                </div>
                
                <!-- Pricing -->
                <div class="space-y-4">
                    <h4 class="font-semibold text-gray-900">Pricing by Currency</h4>
                    
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-gray-200">
                                    <th class="text-left py-2 text-gray-600 font-medium">Currency</th>
                                    <th class="text-left py-2 text-gray-600 font-medium">Monthly</th>
                                    <th class="text-left py-2 text-gray-600 font-medium">Yearly</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($currencies as $currency): ?>
                                <?php $price = $planPrices[$plan['id']][$currency] ?? ['price_monthly' => 0, 'price_yearly' => 0]; ?>
                                <tr class="border-b border-gray-100">
                                    <td class="py-2 font-medium text-gray-900"><?php echo $currency; ?></td>
                                    <td class="py-2">
                                        <input type="number" name="price_monthly_<?php echo $currency; ?>" 
                                               value="<?php echo number_format((float)$price['price_monthly'], 2, '.', ''); ?>" 
                                               step="0.01" min="0"
                                               class="w-24 px-2 py-1 bg-gray-50 border border-gray-200 rounded text-gray-900">
                                    </td>
                                    <td class="py-2">
                                        <input type="number" name="price_yearly_<?php echo $currency; ?>" 
                                               value="<?php echo number_format((float)$price['price_yearly'], 2, '.', ''); ?>" 
                                               step="0.01" min="0"
                                               class="w-24 px-2 py-1 bg-gray-50 border border-gray-200 rounded text-gray-900">
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <div class="px-6 py-4 bg-gray-50 border-t border-gray-100 flex justify-end">
                <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium transition-colors">
                    Save Changes
                </button>
            </div>
        </form>
    </div>
    <?php endforeach; ?>
</div>

<?php adminFooter(); ?>
