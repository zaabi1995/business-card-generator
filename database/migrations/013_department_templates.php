<?php
/**
 * Migration 013: Department Templates
 * Links departments to templates for per-department card designs
 * Safe to run multiple times (idempotent)
 */
function migration_013_department_templates($pdo) {
    $errors = [];
    
    // Helper function to check if column exists
    $columnExists = function($table, $column) use ($pdo) {
        try {
            $result = $pdo->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
            return $result && $result->rowCount() > 0;
        } catch (PDOException $e) {
            return false;
        }
    };
    
    // Helper function to safely execute SQL
    $safeExec = function($sql, $description = '') use ($pdo, &$errors) {
        try {
            $pdo->exec($sql);
            return true;
        } catch (PDOException $e) {
            $errors[] = ($description ? "$description: " : '') . $e->getMessage();
            return false;
        }
    };

    // =====================================================
    // 1. Add template fields to departments table
    // =====================================================
    
    // Add front_template_id to departments
    if (!$columnExists('departments', 'front_template_id')) {
        $safeExec(
            "ALTER TABLE departments ADD COLUMN front_template_id VARCHAR(36) NULL AFTER company_id",
            "Add front_template_id column to departments"
        );
    }
    
    // Add back_template_id to departments
    if (!$columnExists('departments', 'back_template_id')) {
        $safeExec(
            "ALTER TABLE departments ADD COLUMN back_template_id VARCHAR(36) NULL AFTER front_template_id",
            "Add back_template_id column to departments"
        );
    }
    
    // Add template_pair_id to departments (links to a pair of templates)
    if (!$columnExists('departments', 'template_pair_id')) {
        $safeExec(
            "ALTER TABLE departments ADD COLUMN template_pair_id VARCHAR(36) NULL AFTER back_template_id",
            "Add template_pair_id column to departments"
        );
    }
    
    // =====================================================
    // 2. Add department_id to templates table (optional - for filtering)
    // =====================================================
    if (!$columnExists('templates', 'department_id')) {
        $safeExec(
            "ALTER TABLE templates ADD COLUMN department_id VARCHAR(36) NULL AFTER company_id",
            "Add department_id column to templates"
        );
        $safeExec(
            "ALTER TABLE templates ADD INDEX idx_department (department_id)",
            "Add department_id index to templates"
        );
    }
    
    // =====================================================
    // 3. Add description column to departments for better UX
    // =====================================================
    if (!$columnExists('departments', 'description')) {
        $safeExec(
            "ALTER TABLE departments ADD COLUMN description TEXT NULL AFTER name",
            "Add description column to departments"
        );
    }
    
    // =====================================================
    // 4. Add employee count cache column for performance
    // =====================================================
    if (!$columnExists('departments', 'employee_count')) {
        $safeExec(
            "ALTER TABLE departments ADD COLUMN employee_count INT DEFAULT 0",
            "Add employee_count column to departments"
        );
        
        // Update counts for existing departments
        $safeExec("
            UPDATE departments d 
            SET employee_count = (
                SELECT COUNT(*) FROM employees e WHERE e.department_id = d.id
            )
        ", "Update department employee counts");
    }

    // Return in expected format
    if (empty($errors)) {
        return ['success' => true];
    } else {
        return ['success' => false, 'errors' => $errors];
    }
}
