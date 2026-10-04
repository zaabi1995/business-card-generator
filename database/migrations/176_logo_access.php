<?php
if (!defined('CARDIFY_LOGO_ACCESS_TEST')) require_once __DIR__ . '/../../config.php';

$pdo = Database::getInstance()->getConnection();
$pdo->exec("CREATE TABLE IF NOT EXISTS logo_members (
    id VARCHAR(36) PRIMARY KEY,
    email VARCHAR(120) NOT NULL UNIQUE,
    name VARCHAR(120) NOT NULL DEFAULT '',
    phone VARCHAR(25) NULL,
    paid_until DATETIME NULL,
    last_payment_id VARCHAR(36) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->exec("CREATE TABLE IF NOT EXISTS logo_member_sessions (
    token_hash CHAR(64) PRIMARY KEY,
    member_id VARCHAR(36) NOT NULL,
    expires_at DATETIME NOT NULL,
    INDEX idx_member (member_id), INDEX idx_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->exec("CREATE TABLE IF NOT EXISTS logo_access_days (
    subject VARCHAR(80) NOT NULL,
    period DATE NOT NULL,
    used INT UNSIGNED NOT NULL DEFAULT 0,
    bonus INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (subject, period)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->exec("CREATE TABLE IF NOT EXISTS logo_access_grants (
    subject VARCHAR(80) NOT NULL,
    period DATE NOT NULL,
    company_id INT NOT NULL,
    kind VARCHAR(10) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (subject, period, company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->exec("CREATE TABLE IF NOT EXISTS logo_download_tickets (
    token_hash CHAR(64) PRIMARY KEY,
    subject VARCHAR(80) NOT NULL,
    company_id INT NOT NULL,
    format VARCHAR(32) NOT NULL,
    expires_at DATETIME NOT NULL,
    consumed_at DATETIME NULL,
    INDEX idx_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->exec("CREATE TABLE IF NOT EXISTS logo_pass_orders (
    id VARCHAR(36) PRIMARY KEY,
    member_id VARCHAR(36) NOT NULL,
    payment_id VARCHAR(36) NULL UNIQUE,
    transaction_id VARCHAR(255) NULL UNIQUE,
    amount DECIMAL(10,3) NOT NULL,
    days INT UNSIGNED NOT NULL,
    status VARCHAR(15) NOT NULL DEFAULT 'pending',
    return_company_id INT NULL,
    return_format VARCHAR(32) NULL,
    locale VARCHAR(2) NOT NULL DEFAULT 'en',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_member (member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$type = $pdo->query("SHOW COLUMNS FROM payments LIKE 'type'")->fetch(PDO::FETCH_ASSOC)['Type'];
if (str_starts_with($type, 'enum(') && !str_contains($type, "'logo_pass'")) {
    $type = substr($type, 0, -1) . ",'logo_pass')";
    $pdo->exec("ALTER TABLE payments MODIFY COLUMN type $type NOT NULL");
}
$pdo->exec('ALTER TABLE payments MODIFY COLUMN company_id VARCHAR(36) NULL');
echo "Migration 176: logo download access ready\n";
