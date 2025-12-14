<?php
/**
 * Migration 001: Initial Schema
 * Creates all base tables for the application
 */
function migration_001_initial_schema($db) {
    $sql = file_get_contents(__DIR__ . '/../schema.sql');
    
    // Split by semicolon and execute each statement
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        function($stmt) {
            return !empty($stmt) && 
                   !preg_match('/^(--|\/\*)/', $stmt) && 
                   strlen(trim($stmt)) > 10;
        }
    );
    
    $errors = [];
    foreach ($statements as $statement) {
        // Skip comments and empty lines
        $statement = preg_replace('/--.*$/m', '', $statement);
        $statement = preg_replace('/\/\*.*?\*\//s', '', $statement);
        $statement = trim($statement);
        
        if (empty($statement)) continue;
        
        try {
            $db->exec($statement);
        } catch (PDOException $e) {
            // Ignore "table already exists" errors
            if (strpos($e->getMessage(), 'already exists') === false && 
                strpos($e->getMessage(), 'Duplicate key') === false) {
                $errors[] = $e->getMessage() . "\nStatement: " . substr($statement, 0, 100);
            }
        }
    }
    
    return [
        'success' => empty($errors),
        'errors' => $errors
    ];
}
