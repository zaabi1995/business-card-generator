<?php
/**
 * Debug Analytics - Minimal version to find error
 */
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "<!-- Debug Step 1 -->";
require_once __DIR__ . '/../config.php';

echo "<!-- Debug Step 2 -->";
require_once INCLUDES_DIR . '/Auth.php';

echo "<!-- Debug Step 3 -->";
require_once INCLUDES_DIR . '/admin-layout.php';

echo "<!-- Debug Step 4 -->";
if (!class_exists('QRTracker')) {
    require_once INCLUDES_DIR . '/QRTracker.php';
}

echo "<!-- Debug Step 5 -->";
Auth::requireLogin();
$currentUser = Auth::getCurrentUser();
$companyId = getCurrentCompanyId();

echo "<!-- Debug Step 6 - companyId: $companyId -->";

if (!$companyId) {
    header('Location: ' . getBasePath() . 'login.php');
    exit;
}

echo "<!-- Debug Step 7 -->";
$days = 30;
$employeeId = null;

echo "<!-- Debug Step 8 -->";
$stats = QRTracker::getCompanyStats($companyId, $days);
$pageTitle = 'QR Scan Analytics';

echo "<!-- Debug Step 9 -->";
$employees = loadEmployees($companyId);

echo "<!-- Debug Step 10 - About to call adminHeader -->";

// Define helper functions BEFORE adminHeader
function getCountryFlag($code) {
    if (empty($code) || strlen($code) !== 2) return '🌍';
    $code = strtoupper($code);
    $flag = '';
    for ($i = 0; $i < 2; $i++) {
        $flag .= mb_chr(ord($code[$i]) + 127397);
    }
    return $flag;
}

function getDeviceIcon($type) {
    return match($type) {
        'mobile' => 'fa-mobile-screen',
        'tablet' => 'fa-tablet-screen-button',
        'desktop' => 'fa-desktop',
        default => 'fa-question'
    };
}

function getBrowserIcon($browser) {
    return match(strtolower($browser ?? '')) {
        'chrome' => 'fa-chrome',
        'firefox' => 'fa-firefox-browser',
        'safari' => 'fa-safari',
        'edge' => 'fa-edge',
        'opera' => 'fa-opera',
        'ie' => 'fa-internet-explorer',
        default => 'fa-globe'
    };
}

echo "<!-- Debug Step 11 - Calling adminHeader -->";
adminHeader($pageTitle, 'analytics');

echo "<!-- Debug Step 12 - adminHeader completed -->";
?>

<div class="p-8">
    <h1 class="text-2xl font-bold mb-4">Analytics Debug Page</h1>
    
    <div class="bg-white rounded-lg shadow p-6 mb-4">
        <h2 class="text-lg font-semibold mb-2">Stats Summary</h2>
        <ul>
            <li>Total Scans: <?php echo $stats['total_scans'] ?? 0; ?></li>
            <li>Period Scans: <?php echo $stats['period_scans'] ?? 0; ?></li>
            <li>Unique Visitors: <?php echo $stats['unique_visitors'] ?? 0; ?></li>
        </ul>
    </div>
    
    <div class="bg-white rounded-lg shadow p-6">
        <h2 class="text-lg font-semibold mb-2">Raw Stats Data</h2>
        <pre class="text-xs bg-gray-100 p-4 rounded overflow-auto"><?php print_r($stats); ?></pre>
    </div>
</div>

<?php 
echo "<!-- Debug Step 13 - About to call adminFooter -->";
adminFooter(); 
echo "<!-- Debug Step 14 - Done -->";
?>
