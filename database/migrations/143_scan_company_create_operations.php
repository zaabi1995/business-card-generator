<?php
/**
 * Durable idempotency records for native company creation.
 *
 * Each client operation UUID is globally owned by the account that first
 * claims it. A completed operation can mint a fresh session for the same
 * employee without creating another company when the mobile client lost the
 * first response or could not persist it locally. Account deletion preserves
 * an anonymized tombstone so the UUID can never be replayed by another account.
 */
function migration_143_scan_company_create_operations(PDO $pdo): array
{
    $result = ['success' => false, 'errors' => [], 'messages' => []];
    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS scan_company_create_operations (
                account_id CHAR(36) NULL,
                operation_id CHAR(36)
                    CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                company_name VARCHAR(100) NULL,
                requested_slug VARCHAR(100) NULL,
                company_id VARCHAR(36) NULL,
                employee_id VARCHAR(36) NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (operation_id),
                KEY idx_scan_company_operation_account
                    (account_id, status, updated_at),
                KEY idx_scan_company_operation_status (status, updated_at),
                KEY idx_scan_company_operation_company (company_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci"
        );
        $result['success'] = true;
        $result['messages'][] =
            'Native company creation operations have global replay protection';
    } catch (Throwable $e) {
        $result['errors'][] = $e->getMessage();
    }
    return $result;
}
