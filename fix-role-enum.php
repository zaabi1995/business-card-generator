<?php
/**
 * Quick fix: Update users.role ENUM to include 'company'
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config.php';

echo "<pre>";
echo "=== Fixing users.role ENUM ===\n\n";

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();
    
    // Update the ENUM to include 'company'
    $pdo->exec("ALTER TABLE users MODIFY COLUMN role ENUM('super_admin', 'admin', 'company', 'user', 'print_shop') DEFAULT 'user'");
    
    echo "✅ SUCCESS! The 'company' role has been added.\n\n";
    echo "You can now register new companies.\n";
    echo "\n<a href='/company/register.php'>Go to Registration</a>\n";
    
} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
}

echo "</pre>";
