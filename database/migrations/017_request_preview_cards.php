<?php
/**
 * Migration 017: Request Preview Cards
 * Add preview_front and preview_back columns to card_requests
 * so preview cards are generated when requests are submitted
 */

function migration_017_request_preview_cards($pdo) {
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
    
    // Helper to check if table exists
    $tableExists = function($table) use ($pdo) {
        try {
            $stmt = $pdo->query("SHOW TABLES LIKE '{$table}'");
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            return false;
        }
    };
    
    // Add preview columns to card_requests table
    if ($tableExists('card_requests')) {
        if (!$columnExists('card_requests', 'preview_front')) {
            $safeExec(
                "ALTER TABLE card_requests ADD COLUMN preview_front VARCHAR(500) NULL COMMENT 'URL to generated front card preview' AFTER photo",
                "Add preview_front column to card_requests"
            );
        }
        
        if (!$columnExists('card_requests', 'preview_back')) {
            $safeExec(
                "ALTER TABLE card_requests ADD COLUMN preview_back VARCHAR(500) NULL COMMENT 'URL to generated back card preview' AFTER preview_front",
                "Add preview_back column to card_requests"
            );
        }
        
        if (!$columnExists('card_requests', 'preview_generated_at')) {
            $safeExec(
                "ALTER TABLE card_requests ADD COLUMN preview_generated_at TIMESTAMP NULL COMMENT 'When preview was last generated' AFTER preview_back",
                "Add preview_generated_at column to card_requests"
            );
        }
    }
    
    return [
        'success' => empty($errors),
        'errors' => $errors
    ];
}

// Run migration if called directly
if (php_sapi_name() === 'cli' || (isset($_GET['run']) && $_GET['run'] === '1')) {
    require_once __DIR__ . '/../../config.php';
    
    $db = Database::getInstance();
    $pdo = $db->getConnection();
    
    if (!$pdo) {
        die("Database connection failed\n");
    }
    
    echo "Running migration 017_request_preview_cards...\n\n";
    $result = migration_017_request_preview_cards($pdo);
    
    if ($result['success']) {
        echo "Migration completed successfully.\n";
    } else {
        echo "Migration failed:\n";
        foreach ($result['errors'] ?? [] as $error) {
            echo "  - $error\n";
        }
    }
}
