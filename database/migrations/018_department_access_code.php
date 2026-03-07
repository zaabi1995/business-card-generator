<?php
/**
 * Migration 018: Department Access Code
 * Adds portal_passcode column to departments table for department-specific access control
 */

function migration_018_department_access_code($pdo) {
    $errors = [];
    
    // Helper function to safely execute SQL
    $safeExec = function($sql, $description) use ($pdo, &$errors) {
        try {
            $pdo->exec($sql);
            return true;
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'already exists') !== false || 
                strpos($e->getMessage(), 'Duplicate') !== false) {
                return true; // Column already exists, that's fine
            }
            $errors[] = "$description: " . $e->getMessage();
            return false;
        }
    };
    
    // Helper to check if column exists
    $columnExists = function($table, $column) use ($pdo) {
        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            return false;
        }
    };
    
    // Add portal_passcode column to departments table
    if (!$columnExists('departments', 'portal_passcode')) {
        $safeExec(
            "ALTER TABLE departments ADD COLUMN portal_passcode VARCHAR(10) NULL AFTER slug",
            "Add portal_passcode column to departments"
        );
    }
    
    // Return in expected format
    if (empty($errors)) {
        return ['success' => true, 'message' => 'Added portal_passcode column to departments table'];
    } else {
        return ['success' => false, 'errors' => $errors];
    }
}
