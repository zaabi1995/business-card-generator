<?php
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Database.php';
require_once INCLUDES_DIR . '/Auth.php';

// Require super admin authentication
Auth::requireRole('super_admin');

header('Content-Type: text/plain; charset=utf-8');

$db = Database::getInstance();
$connected = $db->isConnected();
$setup = $db->isSetup();

echo "connected=" . ($connected ? '1' : '0') . PHP_EOL;
echo "setup=" . ($setup ? '1' : '0') . PHP_EOL;

if ($connected) {
    try {
        $dbNameRow = $db->fetchOne("SELECT DATABASE() AS db_name");
        echo "database=" . ($dbNameRow['db_name'] ?? 'unknown') . PHP_EOL;
    } catch (Throwable $e) {
        echo "database_error=" . $e->getMessage() . PHP_EOL;
    }

    echo "table_companies=" . ($db->tableExists('companies') ? '1' : '0') . PHP_EOL;
    echo "table_employees=" . ($db->tableExists('employees') ? '1' : '0') . PHP_EOL;
    echo "table_templates=" . ($db->tableExists('templates') ? '1' : '0') . PHP_EOL;
} else {
    echo "error=not connected" . PHP_EOL;
}
