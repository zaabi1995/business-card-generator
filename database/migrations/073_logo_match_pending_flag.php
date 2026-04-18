<?php
/**
 * Migration 073: add logo_match_pending flag to om_companies.
 *
 * Set by scripts/seed-2oman-logos.php when a fuzzy-match confidence falls
 * into the 0.75–0.89 band (needs human review). Admin match-queue page
 * lists rows with this flag set.
 *
 * Idempotent.
 */
require_once __DIR__ . '/../../config.php';
try {
    $db = Database::getInstance()->getConnection();
    $exists = $db->query("SHOW COLUMNS FROM om_companies LIKE 'logo_match_pending'")->rowCount() > 0;
    if (!$exists) {
        $db->exec("ALTER TABLE om_companies ADD COLUMN logo_match_pending TINYINT(1) NOT NULL DEFAULT 0");
        $db->exec("ALTER TABLE om_companies ADD INDEX idx_match_pending (logo_match_pending)");
        echo "[073] added logo_match_pending\n";
    } else {
        echo "[073] exists — skipped\n";
    }
    return ['success' => true];
} catch (Throwable $e) {
    echo "[073] ERROR: " . $e->getMessage() . "\n";
    return ['success' => false, 'errors' => [$e->getMessage()]];
}
