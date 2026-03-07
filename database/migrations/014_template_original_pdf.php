<?php
/**
 * Migration 014: Template Original PDF
 * Add original PDF path to templates for high-quality exports
 * Safe to run multiple times (idempotent)
 */
function migration_014_template_original_pdf($pdo) {
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

    // Add original_pdf_path column to templates table
    if (!$columnExists('templates', 'original_pdf_path')) {
        $safeExec(
            "ALTER TABLE templates ADD COLUMN original_pdf_path VARCHAR(500) NULL AFTER background_image_path",
            "Add original_pdf_path column to templates"
        );
    }

    // Return in expected format
    if (empty($errors)) {
        return ['success' => true];
    } else {
        return ['success' => false, 'errors' => $errors];
    }
}
