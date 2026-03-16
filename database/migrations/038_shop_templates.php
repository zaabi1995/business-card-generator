<?php
/**
 * Migration 038: Shop Templates
 * Creates shop_templates and shop_template_requests tables for BHD template upload workflow
 */
require_once __DIR__ . '/../../config.php';

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();

    // shop_templates: Print shop owned card templates with editable field definitions
    $pdo->exec("CREATE TABLE IF NOT EXISTS shop_templates (
        id VARCHAR(36) PRIMARY KEY,
        print_shop_id INT NOT NULL,
        name VARCHAR(255) NOT NULL,
        description TEXT,
        thumbnail_path VARCHAR(500),
        background_path VARCHAR(500),
        canvas_json LONGTEXT,
        field_definitions JSON COMMENT 'Array of {key, label, placeholder, required}',
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_shop (print_shop_id),
        INDEX idx_active (print_shop_id, is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "Migration 038: shop_templates created\n";

    // shop_template_requests: Customer card requests using a shop template
    $pdo->exec("CREATE TABLE IF NOT EXISTS shop_template_requests (
        id VARCHAR(36) PRIMARY KEY,
        template_id VARCHAR(36) NOT NULL,
        print_shop_id INT NOT NULL,
        customer_name VARCHAR(255),
        customer_phone VARCHAR(100),
        customer_email VARCHAR(255),
        field_values JSON COMMENT 'Customer filled values keyed by field key',
        canvas_snapshot LONGTEXT COMMENT 'Final Fabric.js JSON with customer data merged',
        preview_image_path VARCHAR(500),
        quantity INT NOT NULL DEFAULT 100,
        notes TEXT,
        status ENUM('pending','confirmed','printing','ready','delivered','cancelled') NOT NULL DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_template (template_id),
        INDEX idx_shop (print_shop_id),
        INDEX idx_status (status),
        INDEX idx_email (customer_email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "Migration 038: shop_template_requests created\n";

} catch (Exception $e) {
    echo "Migration 038 failed: " . $e->getMessage() . "\n";
    exit(1);
}
