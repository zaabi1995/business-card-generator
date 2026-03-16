<?php
/**
 * Migration 037: Campaign logs table for WhatsApp/email outreach tracking
 */
require_once __DIR__ . '/../../config.php';

try {
    $db = Database::getInstance();
    $db->exec("CREATE TABLE IF NOT EXISTS campaign_logs (
        id VARCHAR(36) PRIMARY KEY,
        campaign_name VARCHAR(100) NOT NULL,
        channel ENUM('whatsapp','email') NOT NULL,
        recipient VARCHAR(200) NOT NULL,
        status ENUM('sent','failed','pending') NOT NULL DEFAULT 'pending',
        error_msg TEXT NULL,
        sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        referral_source VARCHAR(50) NULL,
        INDEX idx_campaign (campaign_name),
        INDEX idx_sent_at (sent_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "Migration 037: campaign_logs created\n";
} catch (Exception $e) {
    echo "Migration 037 failed: " . $e->getMessage() . "\n";
    exit(1);
}
