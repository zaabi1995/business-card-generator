<?php
/**
 * Persist every table and column required by the native Scan app.
 *
 * These structures previously existed only through manual production drift or
 * request-time CREATE TABLE calls, which made clean installs unreliable.
 */
function migration_141_scan_feature_schema(PDO $pdo): array
{
    $result = ['success' => false, 'errors' => [], 'messages' => []];
    try {
        $columnExists = static function (string $table, string $column) use ($pdo): bool {
            $stmt = $pdo->prepare(
                "SELECT 1
                 FROM information_schema.columns
                 WHERE table_schema = DATABASE()
                   AND table_name = :table_name
                   AND column_name = :column_name
                 LIMIT 1"
            );
            $stmt->execute([
                'table_name' => $table,
                'column_name' => $column,
            ]);
            return (bool) $stmt->fetchColumn();
        };
        $indexExists = static function (string $table, string $index) use ($pdo): bool {
            $stmt = $pdo->prepare(
                "SELECT 1
                 FROM information_schema.statistics
                 WHERE table_schema = DATABASE()
                   AND table_name = :table_name
                   AND index_name = :index_name
                 LIMIT 1"
            );
            $stmt->execute([
                'table_name' => $table,
                'index_name' => $index,
            ]);
            return (bool) $stmt->fetchColumn();
        };

        if (!$columnExists('employees', 'scan_pro_until')) {
            $pdo->exec("ALTER TABLE employees ADD COLUMN scan_pro_until DATETIME NULL");
        }
        if (!$columnExists('employees', 'scan_pro_source')) {
            $pdo->exec("ALTER TABLE employees ADD COLUMN scan_pro_source VARCHAR(32) NULL");
        }

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS scan_pro_receipts (
                original_transaction_id VARCHAR(190) NOT NULL PRIMARY KEY,
                employee_id VARCHAR(64) NOT NULL,
                account_id CHAR(36) NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_scan_pro_employee (employee_id),
                KEY idx_scan_pro_account (account_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        if (!$columnExists('scan_pro_receipts', 'account_id')) {
            $pdo->exec(
                "ALTER TABLE scan_pro_receipts
                 ADD COLUMN account_id CHAR(36) NULL AFTER employee_id"
            );
        }
        if (!$indexExists('scan_pro_receipts', 'idx_scan_pro_account')) {
            $pdo->exec(
                "ALTER TABLE scan_pro_receipts
                 ADD KEY idx_scan_pro_account (account_id)"
            );
        }

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS scan_account_entitlements (
                account_id CHAR(36) NOT NULL,
                entitlement VARCHAR(64) NOT NULL,
                source VARCHAR(32) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'active',
                valid_until DATETIME NULL,
                original_transaction_id VARCHAR(190) NULL,
                latest_transaction_id VARCHAR(190) NULL,
                environment VARCHAR(20) NULL,
                verified_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (account_id, entitlement),
                UNIQUE KEY uniq_scan_entitlement_transaction (original_transaction_id),
                KEY idx_scan_entitlement_validity (entitlement, status, valid_until)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS scan_claim_tickets (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                ticket_hash CHAR(64) NOT NULL,
                shadow_profile_id INT NOT NULL,
                verified_identifier_hash CHAR(64) NOT NULL,
                expires_at DATETIME NOT NULL,
                consumed_at DATETIME NULL,
                revoked_at DATETIME NULL,
                claimed_company_id VARCHAR(36) NULL,
                claimed_employee_id VARCHAR(36) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_scan_claim_ticket_hash (ticket_hash),
                KEY idx_scan_claim_ticket_profile
                    (shadow_profile_id, consumed_at, revoked_at, expires_at),
                KEY idx_scan_claim_ticket_expiry (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdo->exec(
            "UPDATE scan_pro_receipts AS receipts
             JOIN scan_account_memberships AS memberships
               ON memberships.employee_id = receipts.employee_id
             SET receipts.account_id = memberships.account_id
             WHERE receipts.account_id IS NULL"
        );
        $pdo->exec(
            "INSERT IGNORE INTO scan_account_entitlements
                (account_id, entitlement, source, status, valid_until)
             SELECT memberships.account_id,
                    'scan_pro',
                    SUBSTRING_INDEX(
                        GROUP_CONCAT(
                            COALESCE(NULLIF(employees.scan_pro_source, ''), 'legacy')
                            ORDER BY employees.scan_pro_until DESC, employees.id ASC
                            SEPARATOR ','
                        ),
                        ',',
                        1
                    ),
                    CASE
                        WHEN MAX(employees.scan_pro_until) > NOW() THEN 'active'
                        ELSE 'expired'
                    END,
                    MAX(employees.scan_pro_until)
             FROM scan_account_memberships AS memberships
             JOIN employees
               ON employees.id = memberships.employee_id
             WHERE employees.scan_pro_until IS NOT NULL
             GROUP BY memberships.account_id"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS card_designs (
                id VARCHAR(36) NOT NULL PRIMARY KEY,
                employee_id VARCHAR(36) NOT NULL,
                company_id VARCHAR(36) NOT NULL,
                name VARCHAR(120) NOT NULL DEFAULT 'My design',
                side VARCHAR(10) NOT NULL DEFAULT 'front',
                pair_id VARCHAR(36) NULL,
                source VARCHAR(20) NOT NULL DEFAULT 'app',
                background_image_path VARCHAR(500) NULL,
                fields_json LONGTEXT NULL,
                settings_json LONGTEXT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_card_design_employee_active (employee_id, is_active, updated_at),
                KEY idx_card_design_company (company_id),
                KEY idx_card_design_pair (employee_id, pair_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS scan_passes (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                employee_id VARCHAR(64) NOT NULL,
                company_id VARCHAR(64) NOT NULL,
                serial_number VARCHAR(64) NOT NULL,
                auth_token VARCHAR(255) NOT NULL,
                auth_token_hmac CHAR(64) NULL,
                version INT UNSIGNED NOT NULL DEFAULT 1,
                last_modified DATETIME NOT NULL,
                revoked TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_scan_pass_employee (employee_id),
                UNIQUE KEY uniq_scan_pass_serial (serial_number),
                KEY idx_scan_pass_updated (last_modified, version)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS scan_pass_registrations (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                serial_number VARCHAR(64) NOT NULL,
                device_library_id VARCHAR(128) NOT NULL,
                push_token VARCHAR(200) NOT NULL,
                environment VARCHAR(16) NOT NULL DEFAULT 'production',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_scan_pass_registration (serial_number, device_library_id),
                KEY idx_scan_pass_registration_serial (serial_number),
                KEY idx_scan_pass_registration_device (device_library_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS scan_pass_changes (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                serial_number VARCHAR(64) NOT NULL,
                changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_scan_pass_change_serial (serial_number, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $result['success'] = true;
        $result['messages'][] = 'Scan Pro, claim, design, entitlement, and Wallet schemas are present';
    } catch (Throwable $e) {
        $result['errors'][] = $e->getMessage();
    }
    return $result;
}
