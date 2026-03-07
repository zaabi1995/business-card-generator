<?php
/**
 * Test Migration 005 - DELETE AFTER USE
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/config.php';

echo "<h1>Testing Migration 005</h1><pre>";

$db = Database::getInstance();
$pdo = $db->getConnection();

if (!$pdo) {
    die("Database not connected!");
}

echo "Database connected.\n\n";

// Include the migration file
require_once __DIR__ . '/database/migrations/005_complete_schema.php';

echo "Running migration...\n\n";

try {
    $result = migration_005_complete_schema($pdo);
    
    echo "=== RESULT ===\n";
    echo "Success: " . ($result['success'] ? 'YES' : 'NO') . "\n";
    
    if (!empty($result['errors'])) {
        echo "\nErrors:\n";
        foreach ($result['errors'] as $error) {
            echo "  - $error\n";
        }
    } else {
        echo "No errors!\n";
    }
} catch (Exception $e) {
    echo "EXCEPTION: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . "\n";
    echo "Line: " . $e->getLine() . "\n";
}

echo "\n\n=== Tables in database ===\n";
$result = $pdo->query("SHOW TABLES");
foreach ($result as $row) {
    echo "  - " . array_values($row)[0] . "\n";
}

echo "</pre>";
echo "<p style='color:red'><strong>DELETE THIS FILE!</strong></p>";
?>
