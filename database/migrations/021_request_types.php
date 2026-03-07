<?php
/**
 * Migration: Add request_type and request_notes to card_requests
 * 
 * Allows employees to submit different types of requests:
 * - new: First time request (new employee)
 * - update: Update existing information (position change, contact update)
 * - reprint: Request additional cards (same info)
 */

function migration_021_request_types($pdo) {
    $errors = [];
    
    // Helper to safely execute SQL
    $safeExec = function($sql, $description) use ($pdo, &$errors) {
        try {
            $pdo->exec($sql);
            return true;
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'already exists') !== false || 
                strpos($e->getMessage(), 'Duplicate') !== false) {
                return true;
            }
            $errors[] = "{$description}: " . $e->getMessage();
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
    
    // Add request_type column
    if (!$columnExists('card_requests', 'request_type')) {
        $safeExec(
            "ALTER TABLE card_requests ADD COLUMN request_type ENUM('new', 'update', 'reprint') DEFAULT 'new' AFTER status",
            "Add request_type column to card_requests"
        );
    }
    
    // Add request_notes column (for employee to explain reason)
    if (!$columnExists('card_requests', 'request_notes')) {
        $safeExec(
            "ALTER TABLE card_requests ADD COLUMN request_notes TEXT NULL COMMENT 'Employee notes/reason for request' AFTER request_type",
            "Add request_notes column to card_requests"
        );
    }
    
    // Add quantity_requested column (for reprint requests)
    if (!$columnExists('card_requests', 'quantity_requested')) {
        $safeExec(
            "ALTER TABLE card_requests ADD COLUMN quantity_requested INT DEFAULT 1 COMMENT 'Number of card sets requested' AFTER request_notes",
            "Add quantity_requested column to card_requests"
        );
    }
    
    // Update existing requests with employee_id to be 'update' type
    $safeExec(
        "UPDATE card_requests SET request_type = 'update' WHERE employee_id IS NOT NULL AND request_type = 'new'",
        "Update existing requests with employee_id to update type"
    );
    
    return [
        'success' => empty($errors),
        'errors' => $errors
    ];
}
