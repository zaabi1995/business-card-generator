<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "Step 1 - mb_chr exists: " . (function_exists('mb_chr') ? 'YES' : 'NO') . "\n";
echo "Step 2 - match expression: ";
$test = match('mobile') {
    'mobile' => 'OK',
    default => 'FAIL'
};
echo $test . "\n";

echo "Step 3 - Testing getCountryFlag:\n";
function getCountryFlag($code) {
    if (empty($code) || strlen($code) !== 2) return '🌍';
    $code = strtoupper($code);
    $flag = '';
    for ($i = 0; $i < 2; $i++) {
        $flag .= mb_chr(ord($code[$i]) + 127397);
    }
    return $flag;
}
echo "Flag for US: " . getCountryFlag('US') . "\n";

echo "Step 4 - Loading config\n";
require_once __DIR__ . '/../config.php';

echo "Step 5 - Loading Auth\n";
require_once INCLUDES_DIR . '/Auth.php';

echo "Step 6 - Checking Auth::requireLogin\n";
// Don't actually call it, just check it exists
echo "Auth::requireLogin exists: " . (method_exists('Auth', 'requireLogin') ? 'YES' : 'NO') . "\n";

echo "Step 7 - Loading admin-layout\n";
require_once INCLUDES_DIR . '/admin-layout.php';

echo "Step 8 - Testing QRTracker\n";
if (!class_exists('QRTracker')) {
    require_once INCLUDES_DIR . '/QRTracker.php';
}
echo "QRTracker loaded: " . (class_exists('QRTracker') ? 'YES' : 'NO') . "\n";

echo "\nAll basic tests passed!";
