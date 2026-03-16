<?php
/**
 * Migration 033: Add referral_source to companies + onboarding_completed flag
 */
require_once __DIR__ . '/../../config.php';

try {
    $db = Database::getInstance();

    if (!$db->columnExists('companies', 'referral_source')) {
        $db->exec("ALTER TABLE companies
            ADD COLUMN referral_source VARCHAR(50) NULL AFTER status,
            ADD COLUMN onboarding_completed TINYINT(1) NOT NULL DEFAULT 0 AFTER referral_source");
        echo "Migration 033: referral_source and onboarding_completed added to companies\n";
    } else {
        echo "Migration 033: columns already exist, skipping\n";
    }
} catch (Exception $e) {
    echo "Migration 033 failed: " . $e->getMessage() . "\n";
    exit(1);
}
