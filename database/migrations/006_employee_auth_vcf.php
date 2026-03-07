<?php
/**
 * Migration 006: Employee Authentication and VCF Support
 * 
 * Adds:
 * - password_hash column to employees table for employee self-login
 * - domain column to companies table for domain-based company matching
 * - Indexes for better query performance
 */

function migration_006_employee_auth_vcf($pdo) {
    $errors = [];
    $success = true;
    
    // Add password_hash to employees table
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM employees LIKE 'password_hash'");
        if ($stmt->rowCount() === 0) {
            $pdo->exec("ALTER TABLE employees ADD COLUMN password_hash VARCHAR(255) NULL AFTER email");
        }
    } catch (Exception $e) {
        $errors[] = "Failed to add password_hash to employees: " . $e->getMessage();
        $success = false;
    }
    
    // Add domain column to companies table
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM companies LIKE 'domain'");
        if ($stmt->rowCount() === 0) {
            $pdo->exec("ALTER TABLE companies ADD COLUMN domain VARCHAR(255) NULL AFTER admin_email");
        }
    } catch (Exception $e) {
        $errors[] = "Failed to add domain to companies: " . $e->getMessage();
        $success = false;
    }
    
    // Add index on employees.email for faster lookups
    try {
        $stmt = $pdo->query("SHOW INDEX FROM employees WHERE Key_name = 'idx_employees_email'");
        if ($stmt->rowCount() === 0) {
            $pdo->exec("CREATE INDEX idx_employees_email ON employees(email)");
        }
    } catch (Exception $e) {
        // Index might already exist or column might not exist - non-critical
    }
    
    // Add index on companies.domain for faster lookups
    try {
        $stmt = $pdo->query("SHOW INDEX FROM companies WHERE Key_name = 'idx_companies_domain'");
        if ($stmt->rowCount() === 0) {
            $pdo->exec("CREATE INDEX idx_companies_domain ON companies(domain)");
        }
    } catch (Exception $e) {
        // Index might already exist - non-critical
    }
    
    // Add index on companies.admin_email for faster lookups
    try {
        $stmt = $pdo->query("SHOW INDEX FROM companies WHERE Key_name = 'idx_companies_admin_email'");
        if ($stmt->rowCount() === 0) {
            $pdo->exec("CREATE INDEX idx_companies_admin_email ON companies(admin_email)");
        }
    } catch (Exception $e) {
        // Index might already exist - non-critical
    }
    
    return [
        'success' => $success,
        'errors' => $errors
    ];
}
