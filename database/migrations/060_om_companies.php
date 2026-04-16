<?php
require_once __DIR__ . '/../../config.php';

try {
    $db = Database::getInstance();
    $db->exec("CREATE TABLE IF NOT EXISTS om_companies (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name_en VARCHAR(500) NOT NULL,
        name_ar VARCHAR(500) NOT NULL,
        slug VARCHAR(255) NOT NULL,
        sector VARCHAR(60) NOT NULL DEFAULT 'other',
        wilayat VARCHAR(60) NOT NULL DEFAULT 'muscat',
        size_bucket ENUM('large', 'medium') NOT NULL DEFAULT 'medium',
        website VARCHAR(500) DEFAULT NULL,
        logo_url VARCHAR(500) DEFAULT NULL,
        curated TINYINT(1) NOT NULL DEFAULT 0,
        summary_en TEXT DEFAULT NULL,
        summary_ar TEXT DEFAULT NULL,
        verified_at TIMESTAMP NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_slug (slug),
        INDEX idx_sector (sector),
        INDEX idx_wilayat (wilayat),
        INDEX idx_size (size_bucket),
        INDEX idx_curated (curated),
        FULLTEXT KEY ft_names (name_en, name_ar)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "Migration 060: om_companies created\n";
} catch (Exception $e) {
    echo "Migration 060 failed: " . $e->getMessage() . "\n";
    exit(1);
}
