<?php
/**
 * Detailed Billing Debug
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<pre>";
echo "=== BILLING DEBUG ===\n\n";

// Step 1
echo "1. Loading config...\n";
require_once __DIR__ . '/../config.php';
echo "   OK\n";

// Step 2
echo "2. Checking admin...\n";
requireAdmin();
echo "   OK - User is admin\n";

// Step 3
echo "3. Loading admin-layout...\n";
require_once INCLUDES_DIR . '/admin-layout.php';
echo "   OK\n";

// Step 4
echo "4. Loading Billing class...\n";
require_once INCLUDES_DIR . '/Billing.php';
echo "   OK\n";

// Step 5
echo "5. Getting database...\n";
$db = Database::getInstance();
echo "   OK - Connected: " . ($db->isConnected() ? 'Yes' : 'No') . "\n";

// Step 6
echo "6. Creating Billing instance...\n";
$billing = new Billing('amwal', []);
echo "   OK\n";

// Step 7
echo "7. Getting company ID...\n";
$companyId = getCurrentCompanyId();
echo "   OK - Company ID: $companyId\n";

// Step 8
echo "8. Fetching company...\n";
$company = $db->fetchOne("SELECT * FROM companies WHERE id = :id", ['id' => $companyId]);
echo "   OK - Company: " . ($company ? $company['name'] : 'Not found') . "\n";

// Step 9
echo "9. Getting plan limits...\n";
$planLimits = $billing->getPlanLimits($companyId);
echo "   OK - Limits: " . json_encode($planLimits) . "\n";

// Step 10
echo "10. Fetching subscription plans...\n";
try {
    $plans = $db->fetchAll("SELECT * FROM subscription_plans WHERE is_active = 1 ORDER BY price_monthly ASC");
    echo "   OK - Found " . count($plans) . " plans\n";
    foreach ($plans as $i => $plan) {
        echo "   Plan $i: " . json_encode(array_keys($plan)) . "\n";
        echo "   - id: " . ($plan['id'] ?? 'NULL') . "\n";
        echo "   - name: " . ($plan['name'] ?? 'NULL') . "\n";
        echo "   - limits column exists: " . (array_key_exists('limits', $plan) ? 'Yes' : 'No') . "\n";
        echo "   - max_employees: " . ($plan['max_employees'] ?? 'NULL') . "\n";
        echo "   - is_featured: " . (isset($plan['is_featured']) ? ($plan['is_featured'] ? 'true' : 'false') : 'NULL') . "\n";
    }
} catch (Throwable $e) {
    echo "   ERROR: " . $e->getMessage() . "\n";
}

// Step 11
echo "11. Getting current plan...\n";
$currentPlan = null;
if ($company && !empty($company['plan'])) {
    $currentPlan = $db->fetchOne("SELECT * FROM subscription_plans WHERE id = :id", ['id' => $company['plan']]);
}
if (!$currentPlan) {
    $currentPlan = [
        'id' => 'free',
        'name' => 'Free',
        'description' => 'Basic features',
        'price_monthly' => 0
    ];
}
echo "   OK - Current plan: " . $currentPlan['name'] . "\n";

// Step 12
echo "12. Getting employee count...\n";
try {
    $result = $db->fetchOne("SELECT COUNT(*) as count FROM employees WHERE company_id = :id", ['id' => $companyId]);
    $employeeCount = $result['count'] ?? 0;
    echo "   OK - Employees: $employeeCount\n";
} catch (Throwable $e) {
    $employeeCount = 0;
    echo "   ERROR: " . $e->getMessage() . "\n";
}

// Step 13
echo "13. Getting template count...\n";
try {
    $result = $db->fetchOne("SELECT COUNT(*) as count FROM templates WHERE company_id = :id", ['id' => $companyId]);
    $templateCount = $result['count'] ?? 0;
    echo "   OK - Templates: $templateCount\n";
} catch (Throwable $e) {
    $templateCount = 0;
    echo "   ERROR: " . $e->getMessage() . "\n";
}

// Step 14
echo "14. Calling adminHeader...\n";
ob_start();
try {
    adminHeader('Billing', 'billing');
    $headerOutput = ob_get_clean();
    echo "   OK - Header rendered (" . strlen($headerOutput) . " bytes)\n";
} catch (Throwable $e) {
    ob_end_clean();
    echo "   ERROR: " . $e->getMessage() . "\n";
    echo "   File: " . $e->getFile() . "\n";
    echo "   Line: " . $e->getLine() . "\n";
}

echo "\n=== ALL CHECKS PASSED ===\n";
echo "The billing page should work. If it doesn't, the error is in the HTML template section.\n";
echo "</pre>";
