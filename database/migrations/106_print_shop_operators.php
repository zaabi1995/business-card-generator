<?php
/**
 * Migration 106: print_shop_operators
 *
 * Multi-operator login table for print shops. Each shop can have N
 * operators (Ali, Arshad, Hussain for BHD), each with their own
 * phone or email. Login by phone uses WhatsApp OTP; login by email
 * uses email OTP, both via OtpService.
 *
 * Per-shop uniqueness on phone + email so the same person can be
 * an operator at two shops without collision (rare but cheap).
 */
require_once __DIR__ . '/../../config.php';

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();

    $exists = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = 'print_shop_operators'"
    )->fetchColumn();

    if ($exists === 0) {
        $pdo->exec("
            CREATE TABLE print_shop_operators (
                id            VARCHAR(36) PRIMARY KEY,
                print_shop_id INT NOT NULL,
                name          VARCHAR(120) NOT NULL,
                phone         VARCHAR(32)  NULL,
                email         VARCHAR(190) NULL,
                status        ENUM('active','disabled') NOT NULL DEFAULT 'active',
                last_login_at TIMESTAMP NULL,
                created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_phone_per_shop (print_shop_id, phone),
                UNIQUE KEY uniq_email_per_shop (print_shop_id, email),
                KEY idx_phone (phone),
                KEY idx_email (email),
                KEY idx_shop (print_shop_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        echo "[106] Created print_shop_operators\n";
    } else {
        echo "[106] print_shop_operators already exists, skipping\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, "[106] FAILED: " . $e->getMessage() . "\n");
    exit(1);
}
