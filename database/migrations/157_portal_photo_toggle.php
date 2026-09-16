<?php
/**
 * Per-tenant toggle for the "Add a photo" step on the public request portal.
 *
 * The photo step is unconditional, but it only earns its place when the tenant's
 * card page can lead with a photo. A tenant whose card design has no photo (Mays
 * is a die-cut hexagon with logo + contact lines only) asks every requester for
 * a picture it will never use. Defaults to 1 so every existing tenant keeps the
 * step exactly as it is today.
 */
require_once __DIR__ . '/../../config.php';

try {
    $db = Database::getInstance();
    if (!$db->columnExists('companies', 'portal_photo_enabled')) {
        $db->exec("ALTER TABLE companies
                   ADD COLUMN portal_photo_enabled TINYINT(1) NOT NULL DEFAULT 1");
        echo "Migration 157: companies.portal_photo_enabled added\n";
    } else {
        echo "Migration 157: companies.portal_photo_enabled already present\n";
    }
} catch (Exception $e) {
    echo "Migration 157 failed: " . $e->getMessage() . "\n";
    exit(1);
}
