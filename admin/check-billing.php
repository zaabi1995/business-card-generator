<?php
/**
 * Billing Diagnostic Tool (super admin only).
 *
 * Dumps schema and migration state, so it MUST be restricted. Without auth,
 * this endpoint leaked table existence and row counts to the public internet.
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';

Auth::requireRole('super_admin');

echo "<h2>Billing Page Diagnostic</h2>";

// Step 1: config
echo "<h3>1. config.php</h3>✅ loaded<br>";

// Step 2: DB
echo "<h3>2. Database</h3>";
try {
    $db = Database::getInstance();
    if (!($db && $db->isConnected())) { die("❌ Database not connected"); }
    echo "✅ connected<br>";
} catch (Throwable $e) {
    die("❌ " . htmlspecialchars($e->getMessage()));
}

// Step 3: subscription_plans table
echo "<h3>3. subscription_plans table</h3>";
try {
    $result = $db->fetchAll("SHOW TABLES LIKE 'subscription_plans'");
    if ($result && count($result) > 0) {
        $plans = $db->fetchAll("SELECT * FROM subscription_plans");
        echo "✅ exists, " . count($plans) . " plans<br>";
    } else {
        echo "❌ table missing. Run Migration #5 via Admin → Updates<br>";
    }
} catch (Throwable $e) {
    echo "❌ " . htmlspecialchars($e->getMessage()) . "<br>";
}

// Step 4: migrations
echo "<h3>4. migrations status</h3>";
try {
    $result = $db->fetchAll("SHOW TABLES LIKE 'migrations'");
    if ($result && count($result) > 0) {
        $migrations = $db->fetchAll("SELECT * FROM migrations ORDER BY id");
        echo "✅ " . count($migrations) . " recorded migrations<br>";
        foreach ($migrations as $m) {
            echo "   - " . htmlspecialchars($m['migration'] ?? $m['name'] ?? 'Unknown')
                . " (" . htmlspecialchars($m['executed_at'] ?? 'N/A') . ")<br>";
        }
    } else {
        echo "⚠️ migrations table missing<br>";
    }
} catch (Throwable $e) {
    echo "⚠️ " . htmlspecialchars($e->getMessage()) . "<br>";
}

// Step 5: Billing class
echo "<h3>5. Billing class</h3>";
try {
    require_once INCLUDES_DIR . '/Billing.php';
    new Billing('amwal', []);
    echo "✅ loaded + instantiated<br>";
} catch (Throwable $e) {
    echo "❌ " . htmlspecialchars($e->getMessage()) . " ("
        . htmlspecialchars($e->getFile()) . ":" . (int)$e->getLine() . ")<br>";
}

// Step 6: session
echo "<h3>6. Session</h3>";
echo "Logged in: " . (Auth::isLoggedIn() ? 'Yes' : 'No') . "<br>";
$companyId = function_exists('getCurrentCompanyId') ? getCurrentCompanyId() : null;
echo "Company ID: " . htmlspecialchars((string)($companyId ?: 'None')) . "<br>";

echo "<h3>7. Summary</h3><p>Diagnostic only. Restrict to super admin.</p>";
