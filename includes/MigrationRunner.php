<?php
/**
 * Migration Runner
 * Handles running database migrations for future updates
 */
class MigrationRunner {
    private static $db = null;
    private static $migrationsDir = __DIR__ . '/../database/migrations';
    
    public static function init() {
        if (self::$db === null) {
            self::$db = Database::getInstance();
        }
    }
    
    /**
     * Get list of available migrations
     */
    public static function getAvailableMigrations() {
        $migrations = [];
        $files = glob(self::$migrationsDir . '/*.php');
        
        foreach ($files as $file) {
            $filename = basename($file);
            if (preg_match('/^(\d+)_(.+)\.php$/', $filename, $matches)) {
                $migrations[] = [
                    'number' => (int)$matches[1],
                    'name' => str_replace('_', ' ', $matches[2]),
                    'file' => $filename,
                    'path' => $file
                ];
            }
        }
        
        usort($migrations, function($a, $b) {
            return $a['number'] <=> $b['number'];
        });
        
        return $migrations;
    }
    
    /**
     * Get list of executed migrations
     */
    public static function getExecutedMigrations() {
        self::init();
        
        if (!self::$db->isConnected()) {
            return [];
        }
        
        // Create migrations table if it doesn't exist
        try {
            self::$db->getConnection()->exec("
                CREATE TABLE IF NOT EXISTS migrations (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    migration_number INT NOT NULL,
                    migration_name VARCHAR(255) NOT NULL,
                    executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY unique_migration (migration_number)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } catch (Exception $e) {
            // Table might already exist
        }
        
        $executed = self::$db->fetchAll("SELECT * FROM migrations ORDER BY migration_number");
        return array_column($executed, 'migration_number');
    }
    
    /**
     * Get pending migrations
     */
    public static function getPendingMigrations() {
        $available = self::getAvailableMigrations();
        $executed = self::getExecutedMigrations();
        
        return array_filter($available, function($migration) use ($executed) {
            return !in_array($migration['number'], $executed);
        });
    }
    
    /**
     * Run a specific migration
     */
    public static function runMigration($migrationNumber) {
        self::init();
        
        if (!self::$db->isConnected()) {
            return ['success' => false, 'error' => 'Database not connected'];
        }
        
        $migrations = self::getAvailableMigrations();
        $migration = null;
        
        foreach ($migrations as $mig) {
            if ($mig['number'] == $migrationNumber) {
                $migration = $mig;
                break;
            }
        }
        
        if (!$migration) {
            return ['success' => false, 'error' => 'Migration not found'];
        }
        
        // Check if already executed
        $executed = self::getExecutedMigrations();
        if (in_array($migrationNumber, $executed)) {
            return ['success' => false, 'error' => 'Migration already executed'];
        }
        
        // Load and run migration
        require_once $migration['path'];
        
        // Try different function name patterns
        $functionName = null;
        $patterns = [
            'migration_' . str_pad($migrationNumber, 3, '0', STR_PAD_LEFT) . '_' . preg_replace('/[^a-z0-9_]/', '_', strtolower($migration['name'])),
            'migration_' . str_pad($migrationNumber, 3, '0', STR_PAD_LEFT) . '_' . str_replace(' ', '_', strtolower($migration['name'])),
            'migration_' . str_pad($migrationNumber, 3, '0', STR_PAD_LEFT),
        ];
        
        // Try to find function dynamically
        $functions = get_defined_functions()['user'];
        foreach ($functions as $func) {
            if (strpos($func, 'migration_' . str_pad($migrationNumber, 3, '0', STR_PAD_LEFT)) === 0) {
                $functionName = $func;
                break;
            }
        }
        
        // Try patterns if not found
        if (!$functionName) {
            foreach ($patterns as $pattern) {
                if (function_exists($pattern)) {
                    $functionName = $pattern;
                    break;
                }
            }
        }
        
        if (!$functionName || !function_exists($functionName)) {
            return ['success' => false, 'error' => 'Migration function not found. Expected pattern: migration_' . str_pad($migrationNumber, 3, '0', STR_PAD_LEFT) . '_*'];
        }
        
        try {
            $pdo = self::$db->getConnection();
            $result = call_user_func($functionName, $pdo);
            
            if ($result['success']) {
                // Record migration as executed
                self::$db->insert('migrations', [
                    'migration_number' => $migrationNumber,
                    'migration_name' => $migration['name'],
                    'executed_at' => date('Y-m-d H:i:s')
                ]);
                
                return [
                    'success' => true,
                    'message' => 'Migration executed successfully',
                    'migration' => $migration
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'Migration failed: ' . implode(', ', $result['errors'] ?? []),
                    'migration' => $migration
                ];
            }
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Migration error: ' . $e->getMessage(),
                'migration' => $migration
            ];
        }
    }
    
    /**
     * Run all pending migrations
     */
    public static function runAllPending() {
        $pending = self::getPendingMigrations();
        $results = [];
        
        foreach ($pending as $migration) {
            $result = self::runMigration($migration['number']);
            $results[] = [
                'migration' => $migration,
                'result' => $result
            ];
            
            // Stop on first failure
            if (!$result['success']) {
                break;
            }
        }
        
        return $results;
    }
}
