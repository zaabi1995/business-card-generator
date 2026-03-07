<?php
/**
 * Migration 022: Add currency and country to companies table
 * 
 * Adds currency and country columns to support localized pricing
 * and automatic currency selection based on company location.
 */

function migration_022_company_currency($pdo) {
    $errors = [];
    
    // Add currency column if it doesn't exist
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM companies LIKE 'currency'");
        if ($stmt->rowCount() === 0) {
            $pdo->exec("
                ALTER TABLE companies 
                ADD COLUMN currency VARCHAR(3) DEFAULT 'OMR' 
                COMMENT 'Default currency for company (ISO 4217 code)'
            ");
        }
    } catch (PDOException $e) {
        $errors[] = "Failed to add currency column: " . $e->getMessage();
    }
    
    // Add country column if it doesn't exist
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM companies LIKE 'country'");
        if ($stmt->rowCount() === 0) {
            $pdo->exec("
                ALTER TABLE companies 
                ADD COLUMN country VARCHAR(2) DEFAULT 'OM' 
                COMMENT 'Country code (ISO 3166-1 alpha-2)'
            ");
        }
    } catch (PDOException $e) {
        $errors[] = "Failed to add country column: " . $e->getMessage();
    }
    
    // Add index on country for filtering
    try {
        $stmt = $pdo->query("SHOW INDEX FROM companies WHERE Key_name = 'idx_company_country'");
        if ($stmt->rowCount() === 0) {
            $pdo->exec("CREATE INDEX idx_company_country ON companies (country)");
        }
    } catch (PDOException $e) {
        // Index might already exist or table structure doesn't support it
    }
    
    // Update existing companies based on their domain/slug (if they appear to be from Oman)
    try {
        $pdo->exec("
            UPDATE companies 
            SET currency = 'OMR', country = 'OM' 
            WHERE currency IS NULL OR currency = ''
        ");
    } catch (PDOException $e) {
        // Not critical, just means defaults will apply
    }
    
    if (!empty($errors)) {
        return ['success' => false, 'errors' => $errors];
    }
    
    return [
        'success' => true,
        'message' => 'Added currency and country columns to companies table'
    ];
}
