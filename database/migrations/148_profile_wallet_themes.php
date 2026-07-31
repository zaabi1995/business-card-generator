<?php
/**
 * Shared Wallet appearance catalog and one saved preference per profile.
 *
 * The existing website backend remains authoritative. Company administrators,
 * public cards, Apple Wallet, and the mobile API all use these same tables.
 */
function migration_148_profile_wallet_themes(PDO $pdo): array
{
    $result = ['success' => false, 'errors' => [], 'messages' => []];
    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS wallet_themes (
                id VARCHAR(36) NOT NULL PRIMARY KEY,
                company_id VARCHAR(36) NULL,
                name_en VARCHAR(120) NOT NULL,
                name_ar VARCHAR(120) NULL,
                style VARCHAR(24) NOT NULL DEFAULT 'eventTicket',
                background_color CHAR(7) NOT NULL,
                foreground_color CHAR(7) NOT NULL,
                label_color CHAR(7) NOT NULL,
                logo_mode VARCHAR(24) NOT NULL DEFAULT 'company',
                artwork_json LONGTEXT NULL,
                settings_json LONGTEXT NULL,
                preview_path VARCHAR(500) NULL,
                is_default TINYINT(1) NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                sort_order INT NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_wallet_theme_scope (company_id, is_active, sort_order),
                KEY idx_wallet_theme_default (company_id, is_default, is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS profile_wallet_preferences (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                employee_id VARCHAR(64) NOT NULL,
                company_id VARCHAR(64) NOT NULL,
                wallet_theme_id VARCHAR(36) NULL,
                overrides_json LONGTEXT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_profile_wallet_preference (employee_id),
                KEY idx_profile_wallet_company (company_id),
                KEY idx_profile_wallet_theme (wallet_theme_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci"
        );
        $result['success'] = true;
        $result['messages'][] = 'Wallet theme catalog and profile preferences are present';
    } catch (Throwable $e) {
        $result['errors'][] = $e->getMessage();
    }
    return $result;
}
