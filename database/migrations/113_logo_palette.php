<?php
/**
 * Up to 5 brand colors as a JSON array. Used by /companies/<slug>
 * to show palette swatches under the logo. Cron crawler + on-demand
 * /admin/super/logos/refresh repopulate this.
 */
require_once __DIR__ . '/../../config.php';

try {
    $db = Database::getInstance();

    // MariaDB 10.x stores JSON as longtext, native JSON columns work too.
    $db->exec("ALTER TABLE om_companies
        ADD COLUMN logo_palette JSON NULL AFTER logo_dominant_color");

    echo "Migration 113: om_companies.logo_palette added\n";
} catch (Exception $e) {
    if (stripos($e->getMessage(), 'Duplicate column') !== false) {
        echo "Migration 113: column already exists (no-op)\n";
        exit(0);
    }
    echo "Migration 113 failed: " . $e->getMessage() . "\n";
    exit(1);
}
