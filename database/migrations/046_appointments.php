<?php
/**
 * Migration 046: Appointment Booking
 *
 * Per-employee scheduling: owner sets availability, visitors book a slot
 * from their digital card, owner gets emailed.
 *
 * Tables:
 *   employee_appointment_settings  (1:1 with employee)
 *   appointments                   (bookings)
 *
 * Idempotent — safe to re-run.
 */
require_once __DIR__ . '/../../config.php';

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();
    $pdo->beginTransaction();

    $pdo->exec("CREATE TABLE IF NOT EXISTS employee_appointment_settings (
        employee_id VARCHAR(36) PRIMARY KEY,
        company_id VARCHAR(36) NOT NULL,
        enabled TINYINT(1) NOT NULL DEFAULT 0,
        duration_minutes INT NOT NULL DEFAULT 30,
        buffer_minutes INT NOT NULL DEFAULT 0,
        timezone VARCHAR(64) NOT NULL DEFAULT 'Asia/Muscat',
        available_days VARCHAR(255) NOT NULL DEFAULT 'mon,tue,wed,thu',
        available_start TIME NOT NULL DEFAULT '09:00:00',
        available_end TIME NOT NULL DEFAULT '17:00:00',
        max_advance_days INT NOT NULL DEFAULT 30,
        notification_email VARCHAR(255) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
        FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
        INDEX idx_company (company_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS appointments (
        id VARCHAR(36) PRIMARY KEY,
        employee_id VARCHAR(36) NOT NULL,
        company_id VARCHAR(36) NOT NULL,
        visitor_name VARCHAR(255) NOT NULL,
        visitor_email VARCHAR(255) DEFAULT NULL,
        visitor_phone VARCHAR(50) DEFAULT NULL,
        visitor_notes TEXT,
        slot_start DATETIME NOT NULL,
        slot_end DATETIME NOT NULL,
        status ENUM('pending','confirmed','cancelled','completed') NOT NULL DEFAULT 'pending',
        cancellation_reason VARCHAR(500) DEFAULT NULL,
        ip VARCHAR(64) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        confirmed_at TIMESTAMP NULL DEFAULT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
        FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
        INDEX idx_employee_slot (employee_id, slot_start),
        INDEX idx_status (status),
        INDEX idx_company_created (company_id, created_at DESC)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->commit();
    echo "[046] Appointment booking tables created\n";
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "[046] Migration failed: " . $e->getMessage() . "\n");
    exit(1);
}
