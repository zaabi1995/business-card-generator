<?php
/**
 * Super Admin Debug - DELETE AFTER USE
 */
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "<h1>Super Admin Debug</h1><pre>";

// Step 1: Load config
echo "1. Loading config...\n";
try {
    require_once __DIR__ . '/../../config.php';
    echo "   - Config loaded OK\n";
} catch (Throwable $e) {
    die("   - Config ERROR: " . $e->getMessage() . "\n");
}

// Step 2: Load Auth
echo "\n2. Loading Auth.php...\n";
try {
    require_once INCLUDES_DIR . '/Auth.php';
    echo "   - Auth loaded OK\n";
} catch (Throwable $e) {
    die("   - Auth ERROR: " . $e->getMessage() . "\n");
}

// Step 3: Load admin-layout
echo "\n3. Loading admin-layout.php...\n";
try {
    require_once INCLUDES_DIR . '/admin-layout.php';
    echo "   - admin-layout loaded OK\n";
} catch (Throwable $e) {
    die("   - admin-layout ERROR: " . $e->getMessage() . "\n");
}

// Step 4: Check session
echo "\n4. Session status...\n";
echo "   - Session ID: " . session_id() . "\n";
echo "   - Session data: " . print_r($_SESSION, true) . "\n";

// Step 5: Check Auth::requireRole
echo "\n5. Testing Auth::requireRole('super_admin')...\n";
try {
    // Check if user is logged in first
    $currentUser = Auth::getCurrentUser();
    echo "   - Current user: " . print_r($currentUser, true) . "\n";
    
    $currentRole = Auth::getCurrentRole();
    echo "   - Current role: " . ($currentRole ?? 'NULL') . "\n";
    
    if ($currentRole !== 'super_admin') {
        echo "   - WARNING: User is not super_admin, would redirect to login\n";
    } else {
        echo "   - User IS super_admin\n";
    }
} catch (Throwable $e) {
    echo "   - Auth check ERROR: " . $e->getMessage() . "\n";
    echo "   - File: " . $e->getFile() . " Line: " . $e->getLine() . "\n";
}

// Step 6: Test database queries
echo "\n6. Testing database queries...\n";
try {
    $db = Database::getInstance();
    
    echo "   - Testing companies table...\n";
    $result = $db->fetchOne("SELECT COUNT(*) as count FROM companies");
    echo "   - Companies count: " . ($result['count'] ?? 'ERROR') . "\n";
    
    echo "   - Testing employees table...\n";
    $result = $db->fetchOne("SELECT COUNT(*) as count FROM employees");
    echo "   - Employees count: " . ($result['count'] ?? 'ERROR') . "\n";
    
    echo "   - Testing templates table...\n";
    $result = $db->fetchOne("SELECT COUNT(*) as count FROM templates");
    echo "   - Templates count: " . ($result['count'] ?? 'ERROR') . "\n";
    
    echo "   - Testing generated_cards table...\n";
    $result = $db->fetchOne("SELECT COUNT(*) as count FROM generated_cards");
    echo "   - Generated cards count: " . ($result['count'] ?? 'ERROR') . "\n";
    
    echo "   - Testing payment_transactions table...\n";
    $result = $db->fetchOne("SELECT COUNT(*) as count FROM payment_transactions");
    echo "   - Transactions count: " . ($result['count'] ?? 'ERROR') . "\n";
    
} catch (Throwable $e) {
    echo "   - Database ERROR: " . $e->getMessage() . "\n";
    echo "   - This table might not exist!\n";
}

// Step 7: Test adminHeader
echo "\n7. Testing adminHeader function...\n";
try {
    ob_start();
    adminHeader('Test Page', 'dashboard');
    $output = ob_get_clean();
    echo "   - adminHeader executed OK (output length: " . strlen($output) . " chars)\n";
} catch (Throwable $e) {
    ob_end_clean();
    echo "   - adminHeader ERROR: " . $e->getMessage() . "\n";
    echo "   - File: " . $e->getFile() . " Line: " . $e->getLine() . "\n";
}

echo "\n</pre>";
echo "<p style='color:red'><strong>DELETE THIS FILE!</strong></p>";
?>
