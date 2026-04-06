<?php
/**
 * Migration 039 — Direct payment methods + BHD-ERP sync fields
 *
 * Adds:
 * - payment_method ENUM extended with 'direct', 'cash', 'bank_transfer', 'cheque', 'po'
 * - erp_invoice_id   — BHD-ERP invoice ObjectId after sync
 * - erp_quote_id     — BHD-ERP quote ObjectId after sync
 * - erp_payment_id   — BHD-ERP payment ObjectId after sync
 * - erp_invoice_number — human-readable e.g. INV-03148/2026
 * - direct_payment_ref — reference number (PO number, cheque, bank ref)
 * - direct_payment_notes — free text notes for direct payment
 * - direct_payment_recorded_by — admin user ID who marked order as paid
 * - direct_payment_recorded_at — when it was marked
 */

require_once __DIR__ . '/../../config.php';

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();

    // Extend payment_method ENUM
    $pdo->exec("ALTER TABLE print_orders
        MODIFY COLUMN payment_method ENUM(
            'online', 'credit',
            'direct', 'cash', 'bank_transfer', 'cheque', 'po'
        ) DEFAULT NULL
    ");
    echo "Migration 039: payment_method ENUM extended\n";

    // BHD-ERP sync fields
    $columns = [
        "erp_invoice_id       VARCHAR(100) NULL COMMENT 'BHD-ERP Invoice ObjectId'",
        "erp_quote_id         VARCHAR(100) NULL COMMENT 'BHD-ERP Quote ObjectId'",
        "erp_payment_id       VARCHAR(100) NULL COMMENT 'BHD-ERP Payment ObjectId'",
        "erp_invoice_number   VARCHAR(50)  NULL COMMENT 'e.g. INV-03148/2026'",
        "direct_payment_ref   VARCHAR(200) NULL COMMENT 'PO number, cheque number, bank ref'",
        "direct_payment_notes TEXT         NULL",
        "direct_payment_recorded_by VARCHAR(36) NULL",
        "direct_payment_recorded_at TIMESTAMP  NULL",
    ];

    foreach ($columns as $col) {
        $name = preg_split('/\s+/', trim($col))[0];
        $exists = $pdo->query("SHOW COLUMNS FROM print_orders LIKE '$name'")->rowCount();
        if (!$exists) {
            $pdo->exec("ALTER TABLE print_orders ADD COLUMN $col");
            echo "Migration 039: added $name\n";
        } else {
            echo "Migration 039: $name already exists, skipping\n";
        }
    }

    // Add erp_client_name to companies (per-company ERP client name override)
    $erpClientCol = $pdo->query("SHOW COLUMNS FROM companies LIKE 'erp_client_name'")->rowCount();
    if (!$erpClientCol) {
        $pdo->exec("ALTER TABLE companies ADD COLUMN erp_client_name VARCHAR(200) NULL COMMENT 'BHD-ERP client name override'");
        echo "Migration 039: added companies.erp_client_name\n";
    }

    // Settings table: BHD-ERP API credentials for Cardify
    $pdo->exec("CREATE TABLE IF NOT EXISTS erp_settings (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        setting_key     VARCHAR(100) NOT NULL UNIQUE,
        setting_value   TEXT         NULL,
        updated_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "Migration 039: erp_settings table ready\n";

    // Seed default keys (empty — must be filled via DB or admin UI)
    $defaults = [
        'erp_api_url'       => 'https://erp.bhd.om',
        'erp_api_token'     => '',
        'erp_client_name'   => '',  // default ERP client name to use for Cardify orders
        'erp_sync_enabled'  => '0',
    ];
    $stmt = $pdo->prepare("INSERT IGNORE INTO erp_settings (setting_key, setting_value) VALUES (?, ?)");
    foreach ($defaults as $key => $val) {
        $stmt->execute([$key, $val]);
    }
    echo "Migration 039: erp_settings defaults seeded\n";

    echo "Migration 039 complete\n";

} catch (Exception $e) {
    echo "Migration 039 failed: " . $e->getMessage() . "\n";
    exit(1);
}
