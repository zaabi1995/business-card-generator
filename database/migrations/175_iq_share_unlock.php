<?php
/**
 * Migration 175: share to unlock. An attempt remembers which shared result brought the person in
 * (ref_id), so the sharer's full report unlocks for free once someone starts the test from it.
 * unlocked_by records how a report was opened: 'paid' or 'share'.
 */
require_once __DIR__ . '/../../config.php';

try {
    $db = Database::getInstance();
    $cols = array_column($db->fetchAll('SHOW COLUMNS FROM iq_attempts'), 'Field');
    if (!in_array('ref_id', $cols, true)) {
        $db->exec('ALTER TABLE iq_attempts ADD COLUMN ref_id BIGINT UNSIGNED NULL AFTER report_paid, ADD KEY idx_iq_attempts_ref (ref_id)');
    }
    if (!in_array('unlocked_by', $cols, true)) {
        $db->exec("ALTER TABLE iq_attempts ADD COLUMN unlocked_by ENUM('paid','share') NULL AFTER ref_id");
        $db->exec("UPDATE iq_attempts SET unlocked_by = 'paid' WHERE report_paid = 1");
    }
    echo "Migration 175: share unlock columns ready\n";
} catch (Exception $e) {
    echo "Migration 175 failed: " . $e->getMessage() . "\n";
    exit(1);
}
