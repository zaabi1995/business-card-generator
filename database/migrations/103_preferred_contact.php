<?php
/**
 * Migration 103: employees.preferred_contact_action
 *
 * Stores the employee's preferred primary-tap behaviour on their
 * public card: save_contact (default), whatsapp, or call.
 *
 * ENUM chosen so the DB rejects bad values, cheaper than a check
 * constraint for a small closed vocabulary.
 */
require_once __DIR__ . '/../../config.php';

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();

    $has = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = 'employees'
           AND column_name = 'preferred_contact_action'"
    )->fetchColumn();

    if ((int) $has === 0) {
        $pdo->exec(
            "ALTER TABLE employees
             ADD COLUMN preferred_contact_action
             ENUM('save_contact','whatsapp','call')
             NOT NULL DEFAULT 'save_contact'"
        );
        echo "[103] Added employees.preferred_contact_action\n";
    } else {
        echo "[103] employees.preferred_contact_action already exists, skipping\n";
    }
    echo "[103] Migration complete\n";
} catch (Exception $e) {
    echo "[103] ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
