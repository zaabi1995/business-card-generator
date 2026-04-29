<?php
/**
 * Migration 085: soft-delete + undo foundation for Category P
 * (Cardify v2.0 sprint actions 391-405).
 *
 * Adds:
 *   - deleted_at + deleted_by columns on the 6 user-facing tables
 *     that most often hit "delete" buttons: employees, departments,
 *     templates, print_orders, credit_accounts, card_requests.
 *     (action 394)
 *
 *   - undo_actions table, 1-minute revert window for destructive
 *     operations initiated by an admin (action 401).
 *
 *   - data_exports table (action 398, self-service GDPR/PDPL export).
 *
 *   - tenant_deletions table (action 399, 30-day grace period).
 *
 *   - audit-logs immutability trigger (action 404): rejects any
 *     UPDATE or DELETE on audit_logs so a compromised admin cannot
 *     doctor history.
 *
 * audit_logs itself already carries IP, UA, before_data + after_data
 * snapshots (already live, actions 392/393).
 */
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/080_template_foundation.php'; // addColumnIfMissing()

try {
    $db = Database::getInstance();

    // 1. Soft-delete columns on key tables.
    $targets = ['employees','departments','templates','print_orders','credit_accounts','card_requests'];
    foreach ($targets as $tbl) {
        // Skip tables that don't exist on this install.
        $exists = $db->fetchOne(
            "SELECT COUNT(*) c FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t",
            ['t' => $tbl]
        );
        if ((int)($exists['c'] ?? 0) === 0) continue;
        addColumnIfMissing($db, $tbl, 'deleted_at TIMESTAMP NULL');
        addColumnIfMissing($db, $tbl, 'deleted_by VARCHAR(36) NULL');

        // Index the soft-delete column for IS NULL filter speed.
        $hasIdx = $db->fetchOne(
            "SELECT COUNT(*) c FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND INDEX_NAME = 'idx_deleted_at'",
            ['t' => $tbl]
        );
        if ((int)($hasIdx['c'] ?? 0) === 0) {
            try { $db->exec("ALTER TABLE `$tbl` ADD INDEX idx_deleted_at (deleted_at)"); } catch (Throwable $_) {}
        }
    }

    // 2. Undo actions, a 60-second revert window.
    $db->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS undo_actions (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            actor_id VARCHAR(36) NOT NULL,
            actor_email VARCHAR(255) NULL,
            action VARCHAR(80) NOT NULL,
            entity_type VARCHAR(60) NOT NULL,
            entity_id VARCHAR(36) NOT NULL,
            before_state JSON NULL,
            expires_at TIMESTAMP NOT NULL,
            consumed_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_actor_active (actor_id, consumed_at, expires_at),
            INDEX idx_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // 3. Data exports, self-service GDPR/PDPL export queue.
    $db->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS data_exports (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            requested_by VARCHAR(36) NOT NULL,
            company_id VARCHAR(36) NULL,
            scope ENUM('company','user') NOT NULL DEFAULT 'company',
            status ENUM('queued','running','ready','failed','expired') NOT NULL DEFAULT 'queued',
            file_path VARCHAR(500) NULL,
            file_bytes BIGINT UNSIGNED NULL,
            expires_at TIMESTAMP NULL,
            requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            completed_at TIMESTAMP NULL,
            error TEXT NULL,
            INDEX idx_requester (requested_by, status),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // 4. Tenant deletion grace queue.
    $db->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS tenant_deletions (
            company_id VARCHAR(36) PRIMARY KEY,
            requested_by VARCHAR(36) NOT NULL,
            reason VARCHAR(255) NULL,
            requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            purge_after TIMESTAMP NOT NULL,
            cancelled_at TIMESTAMP NULL,
            purged_at TIMESTAMP NULL,
            INDEX idx_purge (purge_after, cancelled_at, purged_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // 5. Immutability triggers on audit_logs. Raise SIGNAL on UPDATE or
    //    DELETE so a compromised admin can't doctor history. Wrapped
    //    in try/catch because re-runs can trip "trigger already exists".
    foreach (['trg_audit_logs_no_update' => 'BEFORE UPDATE', 'trg_audit_logs_no_delete' => 'BEFORE DELETE'] as $name => $when) {
        try { $db->exec("DROP TRIGGER IF EXISTS `$name`"); } catch (Throwable $_) {}
        try {
            $db->exec("
                CREATE TRIGGER `$name`
                $when ON audit_logs
                FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is immutable';
                END
            ");
        } catch (Throwable $e) {
            echo "WARN, could not create $name trigger: " . $e->getMessage() . "\n";
        }
    }

    echo "Migration 085: soft-delete foundation ready (deleted_at on 6 tables, undo_actions + data_exports + tenant_deletions tables, audit_logs immutable triggers)\n";
} catch (Exception $e) {
    echo "Migration 085 failed: " . $e->getMessage() . "\n";
    exit(1);
}
