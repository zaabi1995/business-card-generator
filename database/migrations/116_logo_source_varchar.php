<?php
/**
 * logo_source was an ENUM('2oman_net','company_web','user_upload',
 * 'admin_upload'). The crawler also emits 'favicon','apple_touch_icon',
 * 'clearbit' (and discovery 'auto_discovered'), which truncated the
 * column and silently lost the logo on UPDATE. Widen to VARCHAR(32).
 */
require_once __DIR__ . '/../../config.php';

try {
    $db = Database::getInstance();
    $db->exec("ALTER TABLE om_companies MODIFY COLUMN logo_source VARCHAR(32) NULL");
    echo "Migration 116: logo_source widened to VARCHAR(32)\n";
} catch (Exception $e) {
    echo "Migration 116 failed: " . $e->getMessage() . "\n";
    exit(1);
}
