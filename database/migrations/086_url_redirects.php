<?php
/**
 * Migration 086: url_redirects table (Category R action 431).
 *
 * Backs Redirects::check() fallback lookup so ops can add
 * renamed-slug / campaign-alias 301s without a code change.
 * Nginx-layer 301s stay the primary venue for static aliases.
 */
require_once __DIR__ . '/../../config.php';

try {
    $db = Database::getInstance();
    $db->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS url_redirects (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            source VARCHAR(500) NOT NULL,
            target VARCHAR(500) NOT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            note   VARCHAR(255) NULL,
            hits   BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_source (source),
            INDEX idx_active (active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    echo "Migration 086: url_redirects table ready\n";
} catch (Exception $e) {
    echo "Migration 086 failed: " . $e->getMessage() . "\n";
    exit(1);
}
