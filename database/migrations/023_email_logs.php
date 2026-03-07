<?php
/**
 * Migration 023: Email Logs Table
 * Track all sent emails for auditing and debugging
 */

function migration_023_email_logs($pdo) {
    $errors = [];
    
    // Check if table already exists
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'email_logs'");
        if ($stmt->rowCount() > 0) {
            return [
                'success' => true,
                'message' => 'email_logs table already exists'
            ];
        }
    } catch (PDOException $e) {
        // Continue with creation
    }
    
    // Create the email_logs table
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS email_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                recipient_email VARCHAR(255) NOT NULL,
                recipient_name VARCHAR(255) DEFAULT NULL,
                subject VARCHAR(500) NOT NULL,
                template VARCHAR(100) DEFAULT NULL,
                body_preview TEXT,
                status ENUM('pending', 'sent', 'failed', 'bounced') DEFAULT 'sent',
                error_message TEXT,
                company_id INT DEFAULT NULL,
                employee_id INT DEFAULT NULL,
                actor_id VARCHAR(36) DEFAULT NULL,
                actor_email VARCHAR(255) DEFAULT NULL,
                actor_role VARCHAR(50) DEFAULT NULL,
                ip_address VARCHAR(45) DEFAULT NULL,
                user_agent TEXT,
                metadata JSON,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_email_logs_recipient (recipient_email),
                INDEX idx_email_logs_company (company_id),
                INDEX idx_email_logs_status (status),
                INDEX idx_email_logs_template (template),
                INDEX idx_email_logs_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (PDOException $e) {
        $errors[] = "Failed to create email_logs table: " . $e->getMessage();
    }
    
    if (!empty($errors)) {
        return ['success' => false, 'errors' => $errors];
    }
    
    return [
        'success' => true,
        'message' => 'Created email_logs table for tracking sent emails'
    ];
}
