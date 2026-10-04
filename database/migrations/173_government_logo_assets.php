<?php
require_once __DIR__ . '/../../config.php';
try {
    $db = Database::getInstance();
    if (!$db->columnExists('om_companies', 'logo_identity_assets')) {
        $db->exec('ALTER TABLE om_companies ADD COLUMN logo_identity_assets LONGTEXT NULL');
    }
    $db->exec('ALTER TABLE logo_downloads MODIFY COLUMN format VARCHAR(32) NOT NULL');
    echo "[173] Government logo layouts ready\n";
} catch (Throwable $e) {
    fwrite(STDERR, "[173] " . $e->getMessage() . "\n");
    exit(1);
}
