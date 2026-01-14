<?php
/**
 * Migration 004: Add P.O. file upload and WhatsApp integration support
 * Adds po_file_path column to print_orders table
 * Creates system_settings table for WhatsApp API token
 */
require_once __DIR__ . '/../../includes/functions.php';

function migration_004_print_po_whatsapp($pdo) {
    $errors = [];
    
    try {
        // Add PO-related columns to print_orders table
        try {
            $pdo->exec("ALTER TABLE print_orders ADD COLUMN po_file_path VARCHAR(500) NULL AFTER notes");
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate column') === false) {
                $errors[] = "Error adding po_file_path column: " . $e->getMessage();
            }
        }
        
        try {
            $pdo->exec("ALTER TABLE print_orders ADD COLUMN po_required BOOLEAN DEFAULT FALSE AFTER po_file_path");
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate column') === false) {
                $errors[] = "Error adding po_required column: " . $e->getMessage();
            }
        }
        
        try {
            $pdo->exec("ALTER TABLE print_orders ADD COLUMN po_approved BOOLEAN DEFAULT FALSE AFTER po_required");
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate column') === false) {
                $errors[] = "Error adding po_approved column: " . $e->getMessage();
            }
        }
        
        try {
            $pdo->exec("ALTER TABLE print_orders ADD COLUMN po_approved_by VARCHAR(36) NULL AFTER po_approved");
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate column') === false) {
                $errors[] = "Error adding po_approved_by column: " . $e->getMessage();
            }
        }
        
        try {
            $pdo->exec("ALTER TABLE print_orders ADD COLUMN po_approved_at TIMESTAMP NULL AFTER po_approved_by");
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate column') === false) {
                $errors[] = "Error adding po_approved_at column: " . $e->getMessage();
            }
        }
        
        // Add quotation, invoice, and delivery note file columns
        try {
            $pdo->exec("ALTER TABLE print_orders ADD COLUMN quotation_file_path VARCHAR(500) NULL AFTER po_approved_at");
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate column') === false) {
                $errors[] = "Error adding quotation_file_path column: " . $e->getMessage();
            }
        }
        
        try {
            $pdo->exec("ALTER TABLE print_orders ADD COLUMN quotation_requested BOOLEAN DEFAULT FALSE AFTER quotation_file_path");
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate column') === false) {
                $errors[] = "Error adding quotation_requested column: " . $e->getMessage();
            }
        }
        
        try {
            $pdo->exec("ALTER TABLE print_orders ADD COLUMN invoice_file_path VARCHAR(500) NULL AFTER quotation_requested");
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate column') === false) {
                $errors[] = "Error adding invoice_file_path column: " . $e->getMessage();
            }
        }
        
        try {
            $pdo->exec("ALTER TABLE print_orders ADD COLUMN delivery_note_file_path VARCHAR(500) NULL AFTER invoice_file_path");
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate column') === false) {
                $errors[] = "Error adding delivery_note_file_path column: " . $e->getMessage();
            }
        }
        
        // Create system_settings table for storing WhatsApp API token and other settings
        $pdo->exec("CREATE TABLE IF NOT EXISTS system_settings (
            id VARCHAR(36) PRIMARY KEY,
            setting_key VARCHAR(100) UNIQUE NOT NULL,
            setting_value TEXT NULL,
            description TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_setting_key (setting_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        
        // Insert default WhatsApp API token setting (empty, to be configured by admin)
        try {
            $stmt = $pdo->prepare("INSERT INTO system_settings (id, setting_key, setting_value, description) VALUES (?, ?, ?, ?)");
            $stmt->execute([
                generateUUID(),
                'whatsapp_api_token',
                '',
                'WhatsApp REST API token for sending confirmation messages'
            ]);
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate') === false) {
                $errors[] = "Error inserting WhatsApp API token setting: " . $e->getMessage();
            }
        }
        
        // Insert WhatsApp API enabled setting
        try {
            $stmt = $pdo->prepare("INSERT INTO system_settings (id, setting_key, setting_value, description) VALUES (?, ?, ?, ?)");
            $stmt->execute([
                generateUUID(),
                'whatsapp_enabled',
                '0',
                'Enable/disable WhatsApp confirmation messages (1 = enabled, 0 = disabled)'
            ]);
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate') === false) {
                $errors[] = "Error inserting WhatsApp enabled setting: " . $e->getMessage();
            }
        }
        
        return [
            'success' => empty($errors),
            'errors' => $errors,
            'message' => empty($errors) ? 'Migration 004 completed successfully' : 'Migration completed with warnings'
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'errors' => array_merge($errors, [$e->getMessage()]),
            'message' => 'Migration failed: ' . $e->getMessage()
        ];
    }
}
