<?php
/**
 * Migration: Add password_reset_tokens table
 * 
 * Stores tokens for password reset requests
 */

function migration_020_password_reset_tokens($pdo) {
    $errors = [];
    
    // Check if table already exists
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'password_reset_tokens'");
        if ($stmt->rowCount() > 0) {
            return [
                'success' => true,
                'message' => 'password_reset_tokens table already exists'
            ];
        }
    } catch (PDOException $e) {
        // Continue with creation
    }
    
    // Create password_reset_tokens table
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS password_reset_tokens (
                id INT AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(255) NOT NULL,
                token VARCHAR(64) NOT NULL UNIQUE,
                user_type ENUM('user', 'company', 'employee', 'print_shop') NOT NULL DEFAULT 'user',
                user_id VARCHAR(36) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                expires_at TIMESTAMP NOT NULL,
                used_at TIMESTAMP NULL,
                INDEX idx_token (token),
                INDEX idx_email (email),
                INDEX idx_expires (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (PDOException $e) {
        $errors[] = "Failed to create password_reset_tokens table: " . $e->getMessage();
    }
    
    if (!empty($errors)) {
        return ['success' => false, 'errors' => $errors];
    }
    
    return [
        'success' => true,
        'message' => 'Created password_reset_tokens table'
    ];
}
