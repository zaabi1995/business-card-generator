<?php
/**
 * Schema Fix - Adds missing columns to tables
 * DELETE AFTER USE
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/config.php';

echo "<h1>Schema Fix</h1><pre>";

$db = Database::getInstance();
$pdo = $db->getConnection();

if (!$pdo) {
    die("Database not connected!");
}

$fixes = [];

// Add missing columns to companies table
$companyColumns = [
    "ALTER TABLE companies ADD COLUMN parent_company_id VARCHAR(36) NULL AFTER id",
    "ALTER TABLE companies ADD COLUMN company_path VARCHAR(500) NULL AFTER parent_company_id",
    "ALTER TABLE companies ADD COLUMN company_type VARCHAR(50) DEFAULT 'standalone' AFTER company_path",
];

foreach ($companyColumns as $sql) {
    try {
        $pdo->exec($sql);
        echo "✓ " . substr($sql, 0, 60) . "...\n";
        $fixes[] = $sql;
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false) {
            echo "⚠ Column already exists\n";
        } else {
            echo "✗ " . $e->getMessage() . "\n";
        }
    }
}

// Add missing columns to employees table  
$employeeColumns = [
    "ALTER TABLE employees ADD COLUMN department_id VARCHAR(36) NULL AFTER company_id",
    "ALTER TABLE employees ADD COLUMN photo VARCHAR(500) NULL",
    "ALTER TABLE employees ADD COLUMN status VARCHAR(50) DEFAULT 'active'",
];

foreach ($employeeColumns as $sql) {
    try {
        $pdo->exec($sql);
        echo "✓ " . substr($sql, 0, 60) . "...\n";
        $fixes[] = $sql;
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false) {
            echo "⚠ Column already exists\n";
        } else {
            echo "✗ " . $e->getMessage() . "\n";
        }
    }
}

// Add missing columns to templates table
$templateColumns = [
    "ALTER TABLE templates ADD COLUMN theme_id VARCHAR(36) NULL AFTER company_id",
    "ALTER TABLE templates ADD COLUMN department_id VARCHAR(36) NULL AFTER theme_id",
    "ALTER TABLE templates ADD COLUMN is_shared BOOLEAN DEFAULT FALSE",
];

foreach ($templateColumns as $sql) {
    try {
        $pdo->exec($sql);
        echo "✓ " . substr($sql, 0, 60) . "...\n";
        $fixes[] = $sql;
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false) {
            echo "⚠ Column already exists\n";
        } else {
            echo "✗ " . $e->getMessage() . "\n";
        }
    }
}

// Create missing tables
$tables = [
    'company_themes' => "CREATE TABLE IF NOT EXISTS company_themes (
        id VARCHAR(36) PRIMARY KEY,
        company_id VARCHAR(36) NOT NULL,
        primary_color VARCHAR(7) DEFAULT '#d4af37',
        secondary_color VARCHAR(7) DEFAULT '#0f3460',
        logo_path VARCHAR(500) NULL,
        favicon_path VARCHAR(500) NULL,
        custom_css TEXT NULL,
        header_text VARCHAR(255) NULL,
        footer_text VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    'design_links' => "CREATE TABLE IF NOT EXISTS design_links (
        id VARCHAR(36) PRIMARY KEY,
        company_id VARCHAR(36) NOT NULL,
        template_id VARCHAR(100) NOT NULL,
        share_token VARCHAR(64) UNIQUE NOT NULL,
        password_hash VARCHAR(255) NULL,
        expires_at TIMESTAMP NULL,
        access_count INT DEFAULT 0,
        max_access INT NULL,
        is_active BOOLEAN DEFAULT TRUE,
        created_by VARCHAR(36) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    'print_orders' => "CREATE TABLE IF NOT EXISTS print_orders (
        id VARCHAR(36) PRIMARY KEY,
        company_id VARCHAR(36) NOT NULL,
        order_number VARCHAR(50) UNIQUE NOT NULL,
        employee_ids JSON NOT NULL,
        template_id VARCHAR(100) NOT NULL,
        quantity INT DEFAULT 1,
        status VARCHAR(50) DEFAULT 'pending',
        print_provider VARCHAR(255) NULL,
        print_provider_order_id VARCHAR(255) NULL,
        total_cost DECIMAL(10, 2) NULL,
        notes TEXT NULL,
        created_by VARCHAR(36) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];

foreach ($tables as $name => $sql) {
    try {
        $pdo->exec($sql);
        echo "✓ Created/verified table: $name\n";
    } catch (PDOException $e) {
        echo "✗ Error with $name: " . $e->getMessage() . "\n";
    }
}

// Add indexes
$indexes = [
    "ALTER TABLE companies ADD INDEX idx_parent_company_id (parent_company_id)",
    "ALTER TABLE companies ADD INDEX idx_company_path (company_path)",
    "ALTER TABLE companies ADD INDEX idx_company_type (company_type)",
];

foreach ($indexes as $sql) {
    try {
        $pdo->exec($sql);
        echo "✓ Added index\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate key') !== false) {
            echo "⚠ Index already exists\n";
        }
    }
}

echo "\n" . str_repeat("=", 50) . "\n";
echo "Schema fix complete!\n\n";

// Show companies table structure
echo "Companies table structure:\n";
$result = $pdo->query("DESCRIBE companies");
foreach ($result as $row) {
    echo "  - " . $row['Field'] . " (" . $row['Type'] . ")\n";
}

echo "\n</pre>";
echo "<h2 style='color:green'>Done!</h2>";
echo "<p><a href='admin/companies.php'>Go to Companies</a></p>";
echo "<p><a href='admin/super/'>Go to Super Admin</a></p>";
echo "<p style='color:red'><strong>DELETE THIS FILE!</strong></p>";
?>
