<?php
/**
 * Migration 093: status_incidents (Cat T action 458).
 *
 * Backs the /status page incident log. Rows are written by ops (or
 * future automation) and displayed bilingually for the last 30 days.
 */
require_once __DIR__ . '/../../config.php';
try {
    Database::getInstance()->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS status_incidents (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            title_en VARCHAR(255) NOT NULL,
            title_ar VARCHAR(255) NULL,
            body_en TEXT NULL,
            body_ar TEXT NULL,
            component VARCHAR(40) NOT NULL DEFAULT 'app',
            severity ENUM('info','minor','major') NOT NULL DEFAULT 'minor',
            status ENUM('investigating','identified','monitoring','resolved') NOT NULL DEFAULT 'investigating',
            started_at TIMESTAMP NOT NULL,
            resolved_at TIMESTAMP NULL,
            created_by VARCHAR(36) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_started (started_at),
            INDEX idx_component (component)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    echo "Migration 093: status_incidents table ready\n";
} catch (Exception $e) {
    echo "Migration 093 failed: " . $e->getMessage() . "\n";
    exit(1);
}
