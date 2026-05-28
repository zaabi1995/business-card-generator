<?php
/**
 * Marks when website-discovery last probed a company, so the discovery
 * cron marches forward through the 2,400+ logo-less shells instead of
 * re-probing the same non-matching head every night.
 */
require_once __DIR__ . '/../../config.php';

try {
    $db = Database::getInstance();
    try {
        $db->exec("ALTER TABLE om_companies ADD COLUMN logo_discovery_attempted_at TIMESTAMP NULL AFTER logo_variants_at");
        $db->exec("ALTER TABLE om_companies ADD INDEX idx_disc_attempt (logo_discovery_attempted_at)");
        echo "Migration 115: logo_discovery_attempted_at added\n";
    } catch (Throwable $e) {
        if (stripos($e->getMessage(), 'Duplicate') !== false) {
            echo "Migration 115: column already exists (no-op)\n";
            exit(0);
        }
        throw $e;
    }
} catch (Exception $e) {
    echo "Migration 115 failed: " . $e->getMessage() . "\n";
    exit(1);
}
