<?php
/**
 * Persist authoritative deletion ownership and durable cleanup queues.
 * The cleanup worker purges completed replay tombstones after 30 days.
 */
function migration_145_scan_account_deletion_cleanup(PDO $pdo): array
{
    $result = ['success' => false, 'errors' => [], 'messages' => []];
    try {
        $columns = $pdo->query(
            "SELECT column_name
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = 'scan_account_delete_operations'
               AND column_name IN (
                   'confirmation_token_hash',
                   'deleted_account_id',
                   'deleted_employee_ids'
               )"
        )->fetchAll(PDO::FETCH_COLUMN);
        $columnSet = array_fill_keys($columns, true);
        $alterations = [];
        if (empty($columnSet['confirmation_token_hash'])) {
            $alterations[] =
                'ADD COLUMN confirmation_token_hash CHAR(64) '
                . 'CHARACTER SET ascii COLLATE ascii_bin NULL '
                . 'AFTER operation_id';
        }
        if (empty($columnSet['deleted_account_id'])) {
            $alterations[] =
                'ADD COLUMN deleted_account_id CHAR(36) NULL AFTER account_id';
        }
        if (empty($columnSet['deleted_employee_ids'])) {
            $alterations[] =
                'ADD COLUMN deleted_employee_ids LONGTEXT NULL '
                . 'AFTER deleted_account_id';
        }
        if ($alterations) {
            $pdo->exec(
                'ALTER TABLE scan_account_delete_operations '
                . implode(', ', $alterations)
            );
        }
        $tokenHashIndex = $pdo->query(
            "SELECT 1
             FROM information_schema.statistics
             WHERE table_schema = DATABASE()
               AND table_name = 'scan_account_delete_operations'
               AND index_name = 'uniq_scan_account_delete_token_hash'
             LIMIT 1"
        )->fetchColumn();
        if (!$tokenHashIndex) {
            $pdo->exec(
                'ALTER TABLE scan_account_delete_operations '
                . 'ADD UNIQUE KEY uniq_scan_account_delete_token_hash '
                . '(confirmation_token_hash)'
            );
        }
        $retentionIndex = $pdo->query(
            "SELECT 1
             FROM information_schema.statistics
             WHERE table_schema = DATABASE()
               AND table_name = 'scan_account_delete_operations'
               AND index_name = 'idx_scan_account_delete_retention'
             LIMIT 1"
        )->fetchColumn();
        if (!$retentionIndex) {
            $pdo->exec(
                'ALTER TABLE scan_account_delete_operations '
                . 'ADD KEY idx_scan_account_delete_retention '
                . '(status, updated_at)'
            );
        }
        $indexedScanColumns = $pdo->query(
            "SELECT DISTINCT column_name
             FROM information_schema.statistics
             WHERE table_schema = DATABASE()
               AND table_name = 'scans'
               AND seq_in_index = 1
               AND column_name IN ('image_path', 'image_path_back')"
        )->fetchAll(PDO::FETCH_COLUMN);
        $indexedScanColumnSet = array_fill_keys($indexedScanColumns, true);
        $scanIndexAlterations = [];
        if (empty($indexedScanColumnSet['image_path'])) {
            $scanIndexAlterations[] =
                'ADD KEY idx_scans_image_path (image_path)';
        }
        if (empty($indexedScanColumnSet['image_path_back'])) {
            $scanIndexAlterations[] =
                'ADD KEY idx_scans_image_path_back (image_path_back)';
        }
        if ($scanIndexAlterations) {
            $pdo->exec(
                'ALTER TABLE scans '
                . implode(', ', $scanIndexAlterations)
            );
        }
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS scan_account_delete_files (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                operation_id CHAR(36)
                    CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                relative_path VARCHAR(255) NOT NULL,
                path_hash CHAR(64)
                    CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                attempts INT UNSIGNED NOT NULL DEFAULT 0,
                last_error VARCHAR(255) NULL,
                completed_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_scan_delete_file (operation_id, path_hash),
                KEY idx_scan_delete_file_retry (status, updated_at),
                KEY idx_scan_delete_file_operation (operation_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS
                scan_account_delete_render_invalidations (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                operation_id CHAR(36)
                    CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                employee_id VARCHAR(36) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                attempts INT UNSIGNED NOT NULL DEFAULT 0,
                last_error VARCHAR(255) NULL,
                completed_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_scan_delete_render (
                    operation_id,
                    employee_id
                ),
                KEY idx_scan_delete_render_retry (status, updated_at),
                KEY idx_scan_delete_render_operation (
                    operation_id,
                    status
                )
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci"
        );
        $result['success'] = true;
        $result['messages'][] =
            'Account deletion cleanup is durable with 30-day replay retention';
    } catch (Throwable $e) {
        $result['errors'][] = $e->getMessage();
    }
    return $result;
}
