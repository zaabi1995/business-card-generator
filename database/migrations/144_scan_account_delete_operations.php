<?php
/**
 * Durable capability records for native account deletion.
 *
 * The operation UUID lets a client confirm a committed deletion after losing
 * the response. Migration 145 adds token-bound pseudonymous replay data that
 * its cleanup worker purges or anonymizes after 30 days.
 */
function migration_144_scan_account_delete_operations(PDO $pdo): array
{
    $result = ['success' => false, 'errors' => [], 'messages' => []];
    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS scan_account_delete_operations (
                operation_id CHAR(36)
                    CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                account_id CHAR(36) NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (operation_id),
                KEY idx_scan_account_delete_operation_account
                    (account_id, status, updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci"
        );
        $result['success'] = true;
        $result['messages'][] =
            'Native account deletion operations are recoverable';
    } catch (Throwable $e) {
        $result['errors'][] = $e->getMessage();
    }
    return $result;
}
