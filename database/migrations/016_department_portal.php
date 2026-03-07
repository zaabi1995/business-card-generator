<?php
/**
 * Migration 016: Department Portal Features
 * 
 * Adds:
 * - slug column to departments table for URL routing
 * - portal_passcode column to companies for access control
 * - portal_show_preview column to companies for preview visibility
 * - sample_card_front/back columns to companies for landing page sample
 */

function migration_016_department_portal() {
    $db = Database::getInstance();
    $results = [];
    $hasErrors = false;
    
    // Helper to check if column exists
    $columnExists = function($table, $column) use ($db) {
        try {
            $result = $db->fetchOne(
                "SELECT COUNT(*) as cnt FROM information_schema.COLUMNS 
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column",
                ['table' => $table, 'column' => $column]
            );
            return ($result['cnt'] ?? 0) > 0;
        } catch (Exception $e) {
            return false;
        }
    };
    
    // Helper to check if index exists
    $indexExists = function($table, $indexName) use ($db) {
        try {
            $result = $db->fetchOne(
                "SELECT COUNT(*) as cnt FROM information_schema.STATISTICS 
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND INDEX_NAME = :index",
                ['table' => $table, 'index' => $indexName]
            );
            return ($result['cnt'] ?? 0) > 0;
        } catch (Exception $e) {
            return false;
        }
    };
    
    // Safe execution helper
    $safeExec = function($sql, $description) use ($db, &$results, &$hasErrors) {
        try {
            $db->query($sql);
            $results[] = "OK: $description";
            return true;
        } catch (Exception $e) {
            $msg = $e->getMessage();
            // Ignore "already exists" errors
            if (strpos($msg, 'Duplicate') !== false || strpos($msg, 'already exists') !== false) {
                $results[] = "SKIP: $description (already exists)";
                return true;
            }
            $results[] = "ERROR: $description - $msg";
            $hasErrors = true;
            return false;
        }
    };
    
    // 1. Add slug column to departments table
    if (!$columnExists('departments', 'slug')) {
        $safeExec(
            "ALTER TABLE departments ADD COLUMN slug VARCHAR(100) NULL AFTER name",
            "Add slug column to departments"
        );
    } else {
        $results[] = "SKIP: departments.slug already exists";
    }
    
    // 2. Add unique index for company_id + slug combination
    if (!$indexExists('departments', 'idx_dept_company_slug')) {
        $safeExec(
            "ALTER TABLE departments ADD INDEX idx_dept_company_slug (company_id, slug)",
            "Add index for department slug lookup"
        );
    } else {
        $results[] = "SKIP: idx_dept_company_slug index already exists";
    }
    
    // 3. Add portal_passcode column to companies table
    if (!$columnExists('companies', 'portal_passcode')) {
        $safeExec(
            "ALTER TABLE companies ADD COLUMN portal_passcode VARCHAR(100) NULL AFTER portal_enabled",
            "Add portal_passcode column to companies"
        );
    } else {
        $results[] = "SKIP: companies.portal_passcode already exists";
    }
    
    // 4. Add portal_show_preview column to companies table
    if (!$columnExists('companies', 'portal_show_preview')) {
        $safeExec(
            "ALTER TABLE companies ADD COLUMN portal_show_preview TINYINT(1) DEFAULT 1 AFTER portal_passcode",
            "Add portal_show_preview column to companies"
        );
    } else {
        $results[] = "SKIP: companies.portal_show_preview already exists";
    }
    
    // 5. Add sample_card_front column to companies table
    if (!$columnExists('companies', 'sample_card_front')) {
        $safeExec(
            "ALTER TABLE companies ADD COLUMN sample_card_front VARCHAR(255) NULL AFTER portal_show_preview",
            "Add sample_card_front column to companies"
        );
    } else {
        $results[] = "SKIP: companies.sample_card_front already exists";
    }
    
    // 6. Add sample_card_back column to companies table
    if (!$columnExists('companies', 'sample_card_back')) {
        $safeExec(
            "ALTER TABLE companies ADD COLUMN sample_card_back VARCHAR(255) NULL AFTER sample_card_front",
            "Add sample_card_back column to companies"
        );
    } else {
        $results[] = "SKIP: companies.sample_card_back already exists";
    }
    
    // 7. Generate slugs for existing departments that don't have one
    try {
        $departments = $db->fetchAll("SELECT id, name, company_id FROM departments WHERE slug IS NULL OR slug = ''");
        foreach ($departments as $dept) {
            $slug = generateSlug($dept['name']);
            // Ensure uniqueness within company
            $baseSlug = $slug;
            $counter = 1;
            while (true) {
                $existing = $db->fetchOne(
                    "SELECT id FROM departments WHERE company_id = :cid AND slug = :slug AND id != :id",
                    ['cid' => $dept['company_id'], 'slug' => $slug, 'id' => $dept['id']]
                );
                if (!$existing) break;
                $slug = $baseSlug . '-' . $counter++;
            }
            $db->query(
                "UPDATE departments SET slug = :slug WHERE id = :id",
                ['slug' => $slug, 'id' => $dept['id']]
            );
            $results[] = "OK: Generated slug '{$slug}' for department '{$dept['name']}'";
        }
    } catch (Exception $e) {
        $results[] = "WARN: Could not auto-generate department slugs - " . $e->getMessage();
    }
    
    return [
        'success' => !$hasErrors,
        'messages' => $results,
        'errors' => $hasErrors ? array_filter($results, fn($r) => strpos($r, 'ERROR:') === 0) : []
    ];
}

/**
 * Generate URL-friendly slug from text
 */
function generateSlug($text) {
    // Transliterate Arabic/non-ASCII characters
    $text = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $text);
    // Remove special characters
    $text = preg_replace('/[^a-z0-9\s-]/', '', strtolower($text));
    // Replace spaces with hyphens
    $text = preg_replace('/[\s-]+/', '-', $text);
    // Trim hyphens from ends
    return trim($text, '-');
}

// Run migration if executed directly
if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    require_once __DIR__ . '/../../config.php';
    
    echo "Running Migration 016: Department Portal Features\n";
    echo str_repeat("=", 50) . "\n";
    
    $result = migration_016_department_portal();
    
    foreach ($result['messages'] as $msg) {
        echo "  $msg\n";
    }
    
    echo str_repeat("=", 50) . "\n";
    echo $result['success'] ? "Migration completed successfully!\n" : "Migration completed with errors.\n";
    
    exit($result['success'] ? 0 : 1);
}
