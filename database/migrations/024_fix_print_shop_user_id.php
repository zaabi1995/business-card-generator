<?php
/**
 * Migration 024: Fix print_shops user_id column type
 * Changes user_id from INT to VARCHAR(36) to match users.id UUID format
 */

function migration_024_fix_print_shop_user_id($pdo) {
    $results = ['success' => true, 'messages' => []];
    
    try {
        // Check if print_shops table exists
        $tableCheck = $pdo->query("SHOW TABLES LIKE 'print_shops'");
        if ($tableCheck->rowCount() === 0) {
            $results['messages'][] = "print_shops table doesn't exist, skipping";
            return $results;
        }
        
        // Check current column type
        $columnCheck = $pdo->query("SHOW COLUMNS FROM print_shops LIKE 'user_id'");
        $column = $columnCheck->fetch(PDO::FETCH_ASSOC);
        
        if ($column) {
            $currentType = strtoupper($column['Type']);
            
            // If it's already VARCHAR, skip
            if (strpos($currentType, 'VARCHAR') !== false) {
                $results['messages'][] = "user_id column is already VARCHAR type";
                return $results;
            }
            
            // Alter column to VARCHAR(36)
            $pdo->exec("ALTER TABLE print_shops MODIFY COLUMN user_id VARCHAR(36) NULL");
            $results['messages'][] = "Changed user_id column from {$currentType} to VARCHAR(36)";
        } else {
            // Add column if it doesn't exist
            $pdo->exec("ALTER TABLE print_shops ADD COLUMN user_id VARCHAR(36) NULL");
            $results['messages'][] = "Added user_id column as VARCHAR(36)";
        }
        
    } catch (PDOException $e) {
        $results['success'] = false;
        $results['messages'][] = "Error: " . $e->getMessage();
    }
    
    return $results;
}
