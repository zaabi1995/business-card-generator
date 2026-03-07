<?php
/**
 * Test script to debug analytics.php errors
 */
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "<h1>Testing Analytics Components</h1>";
echo "<pre>";

// Step 1: Config
echo "Step 1: Loading config... ";
try {
    require_once __DIR__ . '/../config.php';
    echo "OK\n";
} catch (Throwable $e) {
    die("FAILED: " . $e->getMessage() . "\n");
}

// Step 2: Auth
echo "Step 2: Loading Auth... ";
try {
    require_once INCLUDES_DIR . '/Auth.php';
    echo "OK\n";
} catch (Throwable $e) {
    die("FAILED: " . $e->getMessage() . "\n");
}

// Step 3: Admin Layout
echo "Step 3: Loading admin-layout... ";
try {
    require_once INCLUDES_DIR . '/admin-layout.php';
    echo "OK\n";
} catch (Throwable $e) {
    die("FAILED: " . $e->getMessage() . "\n");
}

// Step 4: Check session
echo "Step 4: Checking session... ";
echo "company_id = " . ($_SESSION['company_id'] ?? 'NOT SET') . "\n";

// Step 5: Get company ID
echo "Step 5: Getting company ID... ";
try {
    $companyId = getCurrentCompanyId();
    echo "OK - companyId = " . ($companyId ?? 'NULL') . "\n";
} catch (Throwable $e) {
    die("FAILED: " . $e->getMessage() . "\n");
}

// Step 5.5: Check QRTracker file
echo "Step 5.5: Checking QRTracker file... ";
$qrTrackerPath = INCLUDES_DIR . '/QRTracker.php';
echo "Path: " . $qrTrackerPath . "\n";
echo "   File exists: " . (file_exists($qrTrackerPath) ? 'YES' : 'NO') . "\n";
echo "   Is readable: " . (is_readable($qrTrackerPath) ? 'YES' : 'NO') . "\n";

if (file_exists($qrTrackerPath)) {
    echo "   File size: " . filesize($qrTrackerPath) . " bytes\n";
    
    // Try to include it manually
    echo "   Including manually... ";
    try {
        require_once $qrTrackerPath;
        echo "OK\n";
    } catch (Throwable $e) {
        echo "FAILED: " . $e->getMessage() . "\n";
    }
}

echo "   Class exists: " . (class_exists('QRTracker') ? 'YES' : 'NO') . "\n";

// Step 6: Test QRTracker
echo "Step 6: Testing QRTracker::getCompanyStats... ";
if (!class_exists('QRTracker')) {
    echo "SKIPPED - QRTracker class not available\n";
} else {
    try {
        $stats = QRTracker::getCompanyStats($companyId, 30);
        echo "OK - got " . count($stats) . " keys\n";
        echo "   Keys: " . implode(', ', array_keys($stats)) . "\n";
    } catch (Throwable $e) {
        echo "FAILED: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . "\n";
        echo "   Trace: " . $e->getTraceAsString() . "\n";
    }
}

// Step 7: Test loadEmployees
echo "Step 7: Testing loadEmployees... ";
try {
    $employees = loadEmployees($companyId);
    echo "OK - got " . count($employees) . " employees\n";
} catch (Throwable $e) {
    echo "FAILED: " . $e->getMessage() . "\n";
}

// Step 8: Test helper functions
echo "Step 8: Testing helper functions... \n";

echo "   getCountryFlag: ";
try {
    function getCountryFlag($code) {
        if (empty($code) || strlen($code) !== 2) return '🌍';
        $code = strtoupper($code);
        $flag = '';
        for ($i = 0; $i < 2; $i++) {
            $flag .= mb_chr(ord($code[$i]) + 127397);
        }
        return $flag;
    }
    echo getCountryFlag('US') . " OK\n";
} catch (Throwable $e) {
    echo "FAILED: " . $e->getMessage() . "\n";
}

echo "   getDeviceIcon (match expression): ";
try {
    function getDeviceIcon($type) {
        return match($type) {
            'mobile' => 'fa-mobile-screen',
            'tablet' => 'fa-tablet-screen-button',
            'desktop' => 'fa-desktop',
            default => 'fa-question'
        };
    }
    echo getDeviceIcon('mobile') . " OK\n";
} catch (Throwable $e) {
    echo "FAILED: " . $e->getMessage() . "\n";
}

echo "</pre>";
echo "<h2>All tests completed!</h2>";
echo "<p>If you see this, the basic components work. The issue may be in the HTML output.</p>";
