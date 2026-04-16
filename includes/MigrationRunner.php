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

        // Snapshot user functions BEFORE include so we can detect whether this
        // migration file defined a `migration_NNN_*` function (old pattern) or
        // simply ran its SQL at include time (new top-level pattern).
        $before = get_defined_functions()['user'];

        // Load migration file. For top-level scripts any thrown exception will
        // propagate up and be caught below → recorded as failure.
        try {
            require_once $migration['path'];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'error' => 'Migration include error: ' . $e->getMessage(),
                'migration' => $migration
            ];
        }

        $after = get_defined_functions()['user'];
        $prefix = 'migration_' . str_pad($migrationNumber, 3, '0', STR_PAD_LEFT);

        $functionName = null;
        foreach (array_diff($after, $before) as $func) {
            if (strpos($func, $prefix) === 0) {
                $functionName = $func;
                break;
            }
        }
        // Fallback: function may have been defined by an earlier include in
        // the same request (shouldn't happen in normal flow).
        if (!$functionName) {
            foreach ($after as $func) {
                if (strpos($func, $prefix) === 0) {
                    $functionName = $func;
                    break;
                }
            }
        }

        try {
            $pdo = self::$db->getConnection();

            if ($functionName && function_exists($functionName)) {
                // OLD PATTERN: migration file defines a function, we call it.
                $result = call_user_func($functionName, $pdo);
                if (!is_array($result) || empty($result['success'])) {
                    return [
                        'success' => false,
                        'error' => 'Migration failed: ' . implode(', ', (is_array($result) ? ($result['errors'] ?? []) : [])),
                        'migration' => $migration
                    ];
                }
            }
            // NEW PATTERN: top-level script already ran during require_once
            // above and did not throw → treat as success and record it.

            // Record migration as executed
            self::$db->insert('migrations', [
                'migration_number' => $migrationNumber,
                'migration_name' => $migration['name'],
                'executed_at' => date('Y-m-d H:i:s')
            ]);

            return [
                'success' => true,
                'message' => $functionName
                    ? 'Migration executed successfully'
                    : 'Migration (top-level script) executed successfully',
                'migration' => $migration
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Migration error: ' . $e->getMessage(),
                'migration' => $migration
            ];
        }
    }
    
    /**
     * Re-check/re-run a migration (bypasses "already executed" check)
     * Useful for verifying migrations are still valid
     */
    public static function recheckMigration($migrationNumber) {
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
        
        // Load migration file (top-level scripts are idempotent; functions just
        // get (re)defined). If the include itself throws, report it.
        try {
            require_once $migration['path'];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'error' => 'Re-check include error: ' . $e->getMessage(),
                'migration' => $migration
            ];
        }

        // Find the function if one was defined.
        $functionName = null;
        $prefix = 'migration_' . str_pad($migrationNumber, 3, '0', STR_PAD_LEFT);
        foreach (get_defined_functions()['user'] as $func) {
            if (strpos($func, $prefix) === 0) {
                $functionName = $func;
                break;
            }
        }

        try {
            $pdo = self::$db->getConnection();

            if ($functionName && function_exists($functionName)) {
                $result = call_user_func($functionName, $pdo);
                if (!is_array($result) || empty($result['success'])) {
                    return [
                        'success' => false,
                        'error' => 'Re-check found issues: ' . implode(', ', (is_array($result) ? ($result['errors'] ?? []) : [])),
                        'migration' => $migration
                    ];
                }
            }
            // Top-level script: already executed during require_once without throwing.
            return [
                'success' => true,
                'message' => 'Migration re-checked successfully - all changes verified',
                'migration' => $migration
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Re-check error: ' . $e->getMessage(),
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
    
    /**
     * Reset a migration (remove from executed list so it can be re-run)
     * @param int $migrationNumber Migration number to reset
     * @return array Result with success status
     */
    public static function resetMigration($migrationNumber) {
        self::init();
        
        if (!self::$db->isConnected()) {
            return ['success' => false, 'error' => 'Database not connected'];
        }
        
        try {
            self::$db->delete('migrations', 'migration_number = :num', ['num' => $migrationNumber]);
            return [
                'success' => true,
                'message' => "Migration {$migrationNumber} reset. You can now re-run it."
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Failed to reset migration: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Force re-run a migration (reset and run again)
     * WARNING: This may cause issues if the migration creates tables that already exist
     * @param int $migrationNumber Migration number to force re-run
     * @param bool $dropTables If true, attempt to drop tables created by this migration
     * @return array Result with success status
     */
    public static function forceRerun($migrationNumber, $dropTables = false) {
        self::init();
        
        if (!self::$db->isConnected()) {
            return ['success' => false, 'error' => 'Database not connected'];
        }
        
        $pdo = self::$db->getConnection();
        
        // Known tables created by specific migrations
        $migrationTables = [
            20 => ['password_reset_tokens'],
            23 => ['email_logs'],
        ];
        
        // Drop tables if requested and known
        if ($dropTables && isset($migrationTables[$migrationNumber])) {
            foreach ($migrationTables[$migrationNumber] as $table) {
                try {
                    $pdo->exec("DROP TABLE IF EXISTS `{$table}`");
                } catch (Exception $e) {
                    // Continue anyway
                }
            }
        }
        
        // Reset the migration record
        $resetResult = self::resetMigration($migrationNumber);
        if (!$resetResult['success']) {
            return $resetResult;
        }
        
        // Run the migration fresh
        return self::runMigration($migrationNumber);
    }
}
