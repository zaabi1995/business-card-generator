<?php
/**
 * Migration 010: Template Pairs
 * Creates front and back templates as pairs for better UX
 * Safe to run multiple times (idempotent)
 */
function migration_010_template_pairs($pdo) {
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

    // Add pair_id column to templates table
    if (!$columnExists('templates', 'pair_id')) {
        $safeExec(
            "ALTER TABLE templates ADD COLUMN pair_id VARCHAR(36) NULL AFTER company_id",
            "Add pair_id column"
        );
        $safeExec(
            "ALTER TABLE templates ADD INDEX idx_pair (pair_id)",
            "Add pair_id index"
        );
    }

    // Add settings_json column for template settings (size, orientation, etc.)
    if (!$columnExists('templates', 'settings_json')) {
        $safeExec(
            "ALTER TABLE templates ADD COLUMN settings_json JSON NULL AFTER fields_json",
            "Add settings_json column"
        );
    }

    // Update existing templates to create pairs where missing
    try {
        // Get all templates without pair_id
        $stmt = $pdo->query("SELECT * FROM templates WHERE pair_id IS NULL");
        $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $processed = [];
        
        foreach ($templates as $tpl) {
            // Skip if already processed
            if (isset($processed[$tpl['id']])) continue;
            
            // Generate a new pair_id for this template
            $pairId = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                mt_rand(0, 0xffff), mt_rand(0, 0xffff),
                mt_rand(0, 0xffff),
                mt_rand(0, 0x0fff) | 0x4000,
                mt_rand(0, 0x3fff) | 0x8000,
                mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
            );
            
            // Check if there's a matching template (same name, different side)
            $oppositeSide = $tpl['side'] === 'front' ? 'back' : 'front';
            
            $matchStmt = $pdo->prepare(
                "SELECT * FROM templates WHERE company_id = :cid AND name = :name AND side = :side AND pair_id IS NULL AND id != :id"
            );
            $matchStmt->execute([
                'cid' => $tpl['company_id'], 
                'name' => $tpl['name'], 
                'side' => $oppositeSide,
                'id' => $tpl['id']
            ]);
            $match = $matchStmt->fetch(PDO::FETCH_ASSOC);
            
            // Update this template with pair_id
            $updateStmt = $pdo->prepare("UPDATE templates SET pair_id = :pid WHERE id = :id");
            $updateStmt->execute(['pid' => $pairId, 'id' => $tpl['id']]);
            $processed[$tpl['id']] = true;
            
            if ($match) {
                // Link match with the same pair_id
                $updateStmt->execute(['pid' => $pairId, 'id' => $match['id']]);
                $processed[$match['id']] = true;
            }
        }
    } catch (PDOException $e) {
        $errors[] = "Error creating template pairs: " . $e->getMessage();
    }

    // Return in expected format
    if (empty($errors)) {
        return ['success' => true];
    } else {
        return ['success' => false, 'errors' => $errors];
    }
}
