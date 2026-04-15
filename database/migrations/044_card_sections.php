<?php
/**
 * Migration 044: Public Card Sections
 *
 * Adds sections (bio, services, gallery, testimonials, lead form) to public
 * employee card pages rendered by digital_card.php.
 */
require_once __DIR__ . '/../../config.php';

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();
    $pdo->beginTransaction();

    // Master row (1:1 with employee) holding flags + bio + order
    $pdo->exec("CREATE TABLE IF NOT EXISTS employee_card_sections (
        employee_id VARCHAR(36) PRIMARY KEY,
        company_id VARCHAR(36) NOT NULL,
        bio_enabled TINYINT(1) DEFAULT 0,
        bio_text TEXT,
        services_enabled TINYINT(1) DEFAULT 0,
        gallery_enabled TINYINT(1) DEFAULT 0,
        testimonials_enabled TINYINT(1) DEFAULT 0,
        lead_form_enabled TINYINT(1) DEFAULT 0,
        lead_form_email VARCHAR(255) DEFAULT NULL,
        section_order VARCHAR(255) DEFAULT 'bio,services,gallery,testimonials,lead_form',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
        FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
        INDEX idx_company (company_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Services list
    $pdo->exec("CREATE TABLE IF NOT EXISTS employee_card_services (
        id VARCHAR(36) PRIMARY KEY,
        employee_id VARCHAR(36) NOT NULL,
        icon VARCHAR(100) DEFAULT 'fa-solid fa-star',
        title VARCHAR(255) NOT NULL,
        description TEXT,
        position INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
        INDEX idx_employee_pos (employee_id, position)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Gallery photos
    $pdo->exec("CREATE TABLE IF NOT EXISTS employee_card_gallery (
        id VARCHAR(36) PRIMARY KEY,
        employee_id VARCHAR(36) NOT NULL,
        file_path VARCHAR(500) NOT NULL,
        caption VARCHAR(255),
        position INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
        INDEX idx_employee_pos (employee_id, position)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Testimonials
    $pdo->exec("CREATE TABLE IF NOT EXISTS employee_card_testimonials (
        id VARCHAR(36) PRIMARY KEY,
        employee_id VARCHAR(36) NOT NULL,
        name VARCHAR(255) NOT NULL,
        photo_path VARCHAR(500),
        quote TEXT NOT NULL,
        position INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
        INDEX idx_employee_pos (employee_id, position)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Lead form submissions
    $pdo->exec("CREATE TABLE IF NOT EXISTS employee_card_leads (
        id VARCHAR(36) PRIMARY KEY,
        employee_id VARCHAR(36) NOT NULL,
        company_id VARCHAR(36) NOT NULL,
        name VARCHAR(255) NOT NULL,
        email VARCHAR(255),
        phone VARCHAR(50),
        message TEXT,
        ip VARCHAR(64),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
        FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
        INDEX idx_employee_date (employee_id, created_at DESC),
        INDEX idx_ip_date (ip, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->commit();
    echo "Migration 044: card sections tables created\n";
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "Migration 044 failed: " . $e->getMessage() . "\n";
    exit(1);
}
