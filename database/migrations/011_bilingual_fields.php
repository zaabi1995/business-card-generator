<?php
/**
 * Migration 011: Bilingual Fields
 * Adds Arabic language support for employee fields
 * - phone_ar, mobile_ar (with Arabic numerals support)
 * - website_ar
 * - Renames address to address_en, adds address_ar
 * Safe to run multiple times (idempotent)
 */
function migration_011_bilingual_fields($pdo) {
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
    // 1. Add Arabic phone field (phone_ar)
    // =====================================================
    if (!$columnExists('employees', 'phone_ar')) {
        $safeExec(
            "ALTER TABLE employees ADD COLUMN phone_ar VARCHAR(50) NULL AFTER phone",
            "Add phone_ar column"
        );
    }

    // =====================================================
    // 2. Add Arabic mobile field (mobile_ar)
    // =====================================================
    if (!$columnExists('employees', 'mobile_ar')) {
        $safeExec(
            "ALTER TABLE employees ADD COLUMN mobile_ar VARCHAR(50) NULL AFTER mobile",
            "Add mobile_ar column"
        );
    }

    // =====================================================
    // 3. Add Arabic website field (website_ar)
    // =====================================================
    if (!$columnExists('employees', 'website_ar')) {
        $safeExec(
            "ALTER TABLE employees ADD COLUMN website_ar VARCHAR(255) NULL AFTER website",
            "Add website_ar column"
        );
    }

    // =====================================================
    // 4. Handle address field - rename to address_en if needed
    // =====================================================
    
    // Check if we have the old 'address' column but not 'address_en'
    $hasAddress = $columnExists('employees', 'address');
    $hasAddressEn = $columnExists('employees', 'address_en');
    
    if ($hasAddress && !$hasAddressEn) {
        // Rename address to address_en
        $safeExec(
            "ALTER TABLE employees CHANGE COLUMN address address_en TEXT NULL",
            "Rename address to address_en"
        );
    } elseif (!$hasAddress && !$hasAddressEn) {
        // Neither exists, create address_en
        $safeExec(
            "ALTER TABLE employees ADD COLUMN address_en TEXT NULL",
            "Add address_en column"
        );
    }
    // If hasAddressEn is already true, no action needed

    // =====================================================
    // 5. Add Arabic address field (address_ar)
    // =====================================================
    if (!$columnExists('employees', 'address_ar')) {
        $safeExec(
            "ALTER TABLE employees ADD COLUMN address_ar TEXT NULL AFTER address_en",
            "Add address_ar column"
        );
    }

    // =====================================================
    // 6. Add email_ar field for Arabic email display
    // =====================================================
    if (!$columnExists('employees', 'email_ar')) {
        $safeExec(
            "ALTER TABLE employees ADD COLUMN email_ar VARCHAR(255) NULL AFTER email",
            "Add email_ar column"
        );
    }

    // Return in expected format
    if (empty($errors)) {
        return ['success' => true];
    } else {
        return ['success' => false, 'errors' => $errors];
    }
}
