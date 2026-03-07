<?php
/**
 * Migration 027: Definitive Print Orders Schema
 * 
 * Creates the authoritative print_orders table structure.
 * This migration will:
 * 1. Backup existing data if any
 * 2. Drop and recreate the table with proper schema
 * 3. Restore data where possible
 */

function migration_027_print_orders_definitive($pdo) {
    $results = ['success' => true, 'messages' => []];
    
    try {
        // Check if table exists
        $tableExists = $pdo->query("SHOW TABLES LIKE 'print_orders'")->rowCount() > 0;
        
        if ($tableExists) {
            // Backup existing data
            $existingData = $pdo->query("SELECT * FROM print_orders")->fetchAll(PDO::FETCH_ASSOC);
            $results['messages'][] = "Backed up " . count($existingData) . " existing orders";
            
            // Get existing columns for mapping
            $existingColumns = [];
            $colResult = $pdo->query("SHOW COLUMNS FROM print_orders");
            while ($col = $colResult->fetch(PDO::FETCH_ASSOC)) {
                $existingColumns[] = $col['Field'];
            }
            
            // Drop the old table
            $pdo->exec("DROP TABLE print_orders");
            $results['messages'][] = "Dropped old print_orders table";
        } else {
            $existingData = [];
        }
        
        // Create definitive table structure
        $pdo->exec("
            CREATE TABLE print_orders (
                id INT AUTO_INCREMENT PRIMARY KEY,
                order_number VARCHAR(50) UNIQUE NOT NULL,
                
                -- Relations (using VARCHAR for UUID compatibility)
                company_id VARCHAR(36) NOT NULL,
                employee_id VARCHAR(36) NULL,
                user_id VARCHAR(36) NULL COMMENT 'User who placed the order',
                print_shop_id INT NULL,
                
                -- Order Details
                quantity INT NOT NULL DEFAULT 100,
                paper_type VARCHAR(50) DEFAULT 'matte',
                finish VARCHAR(50) DEFAULT 'standard',
                card_size VARCHAR(50) DEFAULT 'standard',
                
                -- Card Files
                card_front_url VARCHAR(500) NULL,
                card_back_url VARCHAR(500) NULL,
                card_template_id VARCHAR(100) NULL,
                
                -- Pricing (using DECIMAL(10,3) for OMR support)
                subtotal DECIMAL(10,3) DEFAULT 0.000,
                setup_fee DECIMAL(10,3) DEFAULT 0.000,
                shipping_fee DECIMAL(10,3) DEFAULT 0.000,
                express_fee DECIMAL(10,3) DEFAULT 0.000,
                total DECIMAL(10,3) DEFAULT 0.000,
                currency VARCHAR(10) DEFAULT 'OMR',
                
                -- Shipping Address
                shipping_name VARCHAR(255) NULL,
                shipping_address TEXT NULL,
                shipping_city VARCHAR(100) NULL,
                shipping_state VARCHAR(100) NULL,
                shipping_country VARCHAR(100) DEFAULT 'OM',
                shipping_postal VARCHAR(20) NULL,
                shipping_phone VARCHAR(50) NULL,
                
                -- Order Status
                status ENUM('pending', 'confirmed', 'processing', 'printing', 'shipped', 'delivered', 'cancelled') DEFAULT 'pending',
                payment_status ENUM('pending', 'paid', 'refunded', 'failed') DEFAULT 'pending',
                express_delivery BOOLEAN DEFAULT FALSE,
                
                -- External Integration
                external_order_id VARCHAR(100) NULL,
                tracking_number VARCHAR(100) NULL,
                print_provider VARCHAR(255) NULL,
                
                -- API/System Fields
                api_response TEXT NULL,
                api_error TEXT NULL,
                notes TEXT NULL,
                
                -- Quotation Fields
                quotation_requested BOOLEAN DEFAULT FALSE,
                quotation_number VARCHAR(100) NULL,
                quotation_file_path VARCHAR(500) NULL,
                quotation_amount DECIMAL(10,3) NULL,
                quotation_issued_at TIMESTAMP NULL,
                quotation_valid_until DATE NULL,
                quotation_accepted BOOLEAN DEFAULT FALSE,
                quotation_accepted_at TIMESTAMP NULL,
                quotation_external_id VARCHAR(100) NULL COMMENT 'External ERP quotation ID',
                
                -- Purchase Order (PO) Fields
                po_required BOOLEAN DEFAULT FALSE,
                po_number VARCHAR(100) NULL,
                po_file_path VARCHAR(500) NULL,
                po_received_at TIMESTAMP NULL,
                po_approved BOOLEAN DEFAULT FALSE,
                po_approved_by VARCHAR(36) NULL,
                po_approved_at TIMESTAMP NULL,
                po_external_id VARCHAR(100) NULL COMMENT 'External ERP PO ID',
                
                -- Invoice Fields
                invoice_number VARCHAR(100) NULL,
                invoice_file_path VARCHAR(500) NULL,
                invoice_amount DECIMAL(10,3) NULL,
                invoice_issued_at TIMESTAMP NULL,
                invoice_due_date DATE NULL,
                invoice_paid BOOLEAN DEFAULT FALSE,
                invoice_paid_at TIMESTAMP NULL,
                invoice_external_id VARCHAR(100) NULL COMMENT 'External ERP invoice ID',
                
                -- Delivery Note Fields
                delivery_note_number VARCHAR(100) NULL,
                delivery_note_file_path VARCHAR(500) NULL,
                delivery_note_issued_at TIMESTAMP NULL,
                delivery_note_external_id VARCHAR(100) NULL COMMENT 'External ERP delivery note ID',
                
                -- ERP Integration Fields
                erp_system VARCHAR(50) NULL COMMENT 'odoo, sap, zoho, etc.',
                erp_order_id VARCHAR(100) NULL,
                erp_customer_id VARCHAR(100) NULL,
                erp_sync_status VARCHAR(50) DEFAULT 'none' COMMENT 'none, pending, synced, error',
                erp_last_sync TIMESTAMP NULL,
                erp_sync_error TEXT NULL,
                
                -- Timestamps
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
                shipped_at TIMESTAMP NULL,
                delivered_at TIMESTAMP NULL,
                
                -- Indexes
                INDEX idx_company_id (company_id),
                INDEX idx_employee_id (employee_id),
                INDEX idx_print_shop_id (print_shop_id),
                INDEX idx_status (status),
                INDEX idx_payment_status (payment_status),
                INDEX idx_order_number (order_number),
                INDEX idx_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $results['messages'][] = "Created new print_orders table with definitive schema";
        
        // Restore data if we had any
        if (!empty($existingData)) {
            $restored = 0;
            foreach ($existingData as $order) {
                try {
                    // Map old columns to new columns
                    $orderNumber = $order['order_number'] ?? ('ORD-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid()), 0, 5)));
                    $companyId = $order['company_id'] ?? null;
                    
                    // Handle employee_id vs employee_ids (JSON)
                    $employeeId = null;
                    if (isset($order['employee_id'])) {
                        $employeeId = $order['employee_id'];
                    } elseif (isset($order['employee_ids'])) {
                        $empIds = json_decode($order['employee_ids'], true);
                        $employeeId = is_array($empIds) && !empty($empIds) ? $empIds[0] : null;
                    }
                    
                    // Handle template_id vs card_template_id
                    $templateId = $order['card_template_id'] ?? $order['template_id'] ?? null;
                    
                    // Handle total vs total_cost
                    $total = $order['total'] ?? $order['total_cost'] ?? 0;
                    
                    // Handle user_id vs created_by
                    $userId = $order['user_id'] ?? $order['created_by'] ?? null;
                    
                    if ($companyId) {
                        $stmt = $pdo->prepare("
                            INSERT INTO print_orders 
                            (order_number, company_id, employee_id, user_id, print_shop_id,
                             quantity, paper_type, finish, card_size,
                             card_front_url, card_back_url, card_template_id,
                             subtotal, setup_fee, shipping_fee, total, currency,
                             shipping_name, shipping_address, shipping_city, shipping_state, 
                             shipping_country, shipping_postal, shipping_phone,
                             status, payment_status, notes, created_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        
                        $stmt->execute([
                            $orderNumber,
                            $companyId,
                            $employeeId,
                            $userId,
                            $order['print_shop_id'] ?? null,
                            $order['quantity'] ?? 100,
                            $order['paper_type'] ?? 'matte',
                            $order['finish'] ?? 'standard',
                            $order['card_size'] ?? 'standard',
                            $order['card_front_url'] ?? null,
                            $order['card_back_url'] ?? null,
                            $templateId,
                            $order['subtotal'] ?? 0,
                            $order['setup_fee'] ?? 0,
                            $order['shipping_fee'] ?? 0,
                            $total,
                            $order['currency'] ?? 'OMR',
                            $order['shipping_name'] ?? null,
                            $order['shipping_address'] ?? null,
                            $order['shipping_city'] ?? null,
                            $order['shipping_state'] ?? null,
                            $order['shipping_country'] ?? 'OM',
                            $order['shipping_postal'] ?? null,
                            $order['shipping_phone'] ?? null,
                            $order['status'] ?? 'pending',
                            $order['payment_status'] ?? 'pending',
                            $order['notes'] ?? null,
                            $order['created_at'] ?? date('Y-m-d H:i:s')
                        ]);
                        $restored++;
                    }
                } catch (PDOException $e) {
                    $results['messages'][] = "Warning: Could not restore order: " . $e->getMessage();
                }
            }
            $results['messages'][] = "Restored $restored orders";
        }
        
        $results['messages'][] = "Migration complete - print_orders table is now using definitive schema";
        
    } catch (PDOException $e) {
        $results['success'] = false;
        $results['messages'][] = "Error: " . $e->getMessage();
    }
    
    return $results;
}
