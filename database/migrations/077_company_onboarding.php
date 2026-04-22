<?php
/**
 * Migration 077: company_onboarding table.
 *
 * Backs the 7-step onboarding wizard shipped in Category C of the v2.0
 * sprint. One row per company. `data` is a JSON blob holding each step's
 * saved state so an admin can close the wizard mid-flow and resume later.
 *
 * Steps tracked (keys inside `data`):
 *   1. logo
 *   2. colors
 *   3. template
 *   4. first_employee
 *   5. preview
 *   6. invite_team
 *   7. order_cards
 *
 * `step` is the furthest step reached; 0 means not started.
 * `completed_at` is set when step 7 is saved or the wizard is marked
 * finished explicitly.
 */
require_once __DIR__ . '/../../config.php';

try {
    $db = Database::getInstance();

    $db->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS company_onboarding (
            company_id VARCHAR(36) PRIMARY KEY,
            step TINYINT UNSIGNED NOT NULL DEFAULT 0,
            data JSON NULL,
            started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            completed_at TIMESTAMP NULL,
            skipped_at TIMESTAMP NULL,
            resume_nudge_at TIMESTAMP NULL,
            INDEX idx_completed (completed_at),
            INDEX idx_step (step)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // Backfill: companies that already finished setup (have >=1 generated
    // card) count as wizard-complete, so the banner doesn't re-appear.
    $db->exec(<<<'SQL'
        INSERT INTO company_onboarding (company_id, step, completed_at)
        SELECT c.id, 7, NOW()
        FROM companies c
        WHERE EXISTS (
            SELECT 1 FROM generated_card_log g WHERE g.company_id = c.id LIMIT 1
        )
        AND NOT EXISTS (SELECT 1 FROM company_onboarding o WHERE o.company_id = c.id)
SQL);

    echo "Migration 077: company_onboarding table created + backfilled for active tenants\n";
} catch (Exception $e) {
    echo "Migration 077 failed: " . $e->getMessage() . "\n";
    exit(1);
}
