<?php
/**
 * Migration 003: Company Hierarchy and Routing
 * Adds parent company relationships and company-specific pages
 */
function migration_003_company_hierarchy($pdo) {
    $errors = [];
    
    try {
        // Check if companies table exists first
        $companiesTableExists = $pdo->query("SHOW TABLES LIKE 'companies'")->rowCount() > 0;
        
        if (!$companiesTableExists) {
            // Table doesn't exist yet, skip these alterations
            return ['success' => true, 'errors' => []];
        }
        
        // Add parent_company_id to companies table
        try {
            $columnExists = $pdo->query("SHOW COLUMNS FROM companies LIKE 'parent_company_id'")->rowCount() > 0;
            if (!$columnExists) {
                $pdo->exec("ALTER TABLE companies ADD COLUMN parent_company_id VARCHAR(36) NULL AFTER id");
                $pdo->exec("ALTER TABLE companies ADD INDEX idx_parent_company_id (parent_company_id)");
                // Try to add foreign key, but ignore if it fails
                try {
                    $pdo->exec("ALTER TABLE companies ADD FOREIGN KEY (parent_company_id) REFERENCES companies(id) ON DELETE SET NULL");
                } catch (PDOException $e) {
                    // Foreign key might fail if table is empty or constraint exists
                }
            }
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate column') === false && 
                strpos($e->getMessage(), 'Duplicate key') === false &&
                strpos($e->getMessage(), "doesn't exist") === false) {
                $errors[] = "Error adding parent_company_id: " . $e->getMessage();
            }
        }
        
        // Add company_path for hierarchical path (e.g., "Healthcare > MHD ITICS > MHD")
        try {
            $columnExists = $pdo->query("SHOW COLUMNS FROM companies LIKE 'company_path'")->rowCount() > 0;
            if (!$columnExists) {
                $pdo->exec("ALTER TABLE companies ADD COLUMN company_path VARCHAR(500) NULL AFTER parent_company_id");
                $pdo->exec("ALTER TABLE companies ADD INDEX idx_company_path (company_path)");
            }
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate column') === false &&
                strpos($e->getMessage(), "doesn't exist") === false) {
                $errors[] = "Error adding company_path: " . $e->getMessage();
            }
        }
        
        // Add company_type (parent, child, standalone)
        try {
            $columnExists = $pdo->query("SHOW COLUMNS FROM companies LIKE 'company_type'")->rowCount() > 0;
            if (!$columnExists) {
                $pdo->exec("ALTER TABLE companies ADD COLUMN company_type VARCHAR(50) DEFAULT 'standalone' AFTER company_path");
                $pdo->exec("ALTER TABLE companies ADD INDEX idx_company_type (company_type)");
            }
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate column') === false &&
                strpos($e->getMessage(), "doesn't exist") === false) {
                $errors[] = "Error adding company_type: " . $e->getMessage();
            }
        }
        
        // Update existing companies to have company_path = name
        try {
            $stmt = $pdo->query("SELECT id, name FROM companies WHERE company_path IS NULL");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $pdo->prepare("UPDATE companies SET company_path = :path WHERE id = :id")
                    ->execute(['path' => $row['name'], 'id' => $row['id']]);
            }
        } catch (PDOException $e) {
            // Only add error if it's not about column not existing
            if (strpos($e->getMessage(), "doesn't exist") === false) {
                $errors[] = "Error updating company_path: " . $e->getMessage();
            }
        }
        
    } catch (PDOException $e) {
        $errors[] = "Migration error: " . $e->getMessage();
    }
    
    return [
        'success' => empty($errors),
        'errors' => $errors
    ];
}
