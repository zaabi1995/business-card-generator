<?php
/**
 * Billing Diagnostic Tool (super admin only)
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

echo "<h2>Billing Page Diagnostic</h2>";

// Step 1: Check config
echo "<h3>1. Loading config.php...</h3>";
try {
    require_once __DIR__ . '/../config.php';
    echo "✅ config.php loaded successfully<br>";
} catch (Throwable $e) {
    die("❌ Error loading config.php: " . $e->getMessage());
}

// Step 2: Check database connection
echo "<h3>2. Checking database connection...</h3>";
try {
    $db = Database::getInstance();
    if ($db && $db->isConnected()) {
        echo "✅ Database connected<br>";
    } else {
        die("❌ Database not connected");
    }
} catch (Throwable $e) {
    die("❌ Database error: " . $e->getMessage());
}

// Step 3: Check if subscription_plans table exists
echo "<h3>3. Checking subscription_plans table...</h3>";
try {
    $result = $db->fetchAll("SHOW TABLES LIKE 'subscription_plans'");
    if ($result && count($result) > 0) {
        echo "✅ subscription_plans table exists<br>";
        
        // Check for data
        $plans = $db->fetchAll("SELECT * FROM subscription_plans");
        echo "   Found " . count($plans) . " plans<br>";
    } else {
        echo "❌ subscription_plans table does NOT exist<br>";
        echo "<strong>You need to run Migration #5 from Admin → Updates</strong><br>";
    }
} catch (Throwable $e) {
    echo "❌ Error checking table: " . $e->getMessage() . "<br>";
}

// Step 4: Check migrations table
echo "<h3>4. Checking migrations status...</h3>";
try {
    $result = $db->fetchAll("SHOW TABLES LIKE 'migrations'");
    if ($result && count($result) > 0) {
        $migrations = $db->fetchAll("SELECT * FROM migrations ORDER BY id");
        echo "✅ migrations table exists<br>";
        echo "   Executed migrations:<br>";
        foreach ($migrations as $m) {
            echo "   - " . ($m['migration'] ?? $m['name'] ?? 'Unknown') . " (executed: " . ($m['executed_at'] ?? 'N/A') . ")<br>";
        }
    } else {
        echo "⚠️ migrations table does not exist - no migrations have been run<br>";
    }
} catch (Throwable $e) {
    echo "⚠️ Error checking migrations: " . $e->getMessage() . "<br>";
}

// Step 5: Check Billing class
echo "<h3>5. Loading Billing class...</h3>";
try {
    require_once INCLUDES_DIR . '/Billing.php';
    echo "✅ Billing.php loaded<br>";
    
    $billing = new Billing('amwal', []);
    echo "✅ Billing class instantiated<br>";
} catch (Throwable $e) {
    echo "❌ Error with Billing class: " . $e->getMessage() . "<br>";
    echo "   File: " . $e->getFile() . "<br>";
    echo "   Line: " . $e->getLine() . "<br>";
}

// Step 6: Check auth
echo "<h3>6. Checking authentication...</h3>";
try {
    if (function_exists('isLoggedIn')) {
        echo "Logged in: " . (isLoggedIn() ? 'Yes' : 'No') . "<br>";
    }
    if (function_exists('getCurrentCompanyId')) {
        $companyId = getCurrentCompanyId();
        echo "Company ID: " . ($companyId ?: 'None') . "<br>";
    }
} catch (Throwable $e) {
    echo "⚠️ Auth check error: " . $e->getMessage() . "<br>";
}

echo "<h3>7. Summary</h3>";
echo "<p>If subscription_plans table doesn't exist, go to <a href='/admin/updates.php'>Admin → Updates</a> and run Migration #5.</p>";
