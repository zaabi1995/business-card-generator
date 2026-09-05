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
     * Heuristic detector: does a migration file contain BOTH a function
     * definition AND obvious top-level side-effecting code?
     *
     * We strip: php open/close tags, line + block comments, and everything
     * inside balanced `function … { … }` blocks. What remains should contain
     * only require/declare statements and nothing else. Any `$pdo->`, `echo`,
     * `return`, `try`, `throw`, bare SQL in a $pdo->exec, etc. outside of the
     * function body is flagged.
     *
     * Hybrid files are dangerous: the top-level runs during require_once()
     * AND the function runs during call_user_func() → everything executes
     * twice. Callers should refuse to run such files and surface a loud
     * warning. (Codex round-2 Finding 5.)
     *
     * Returns true = hybrid pattern detected (unsafe), false = clean.
     */
    private static function isHybridMigration(string $path, string $prefix): bool {
        $src = @file_get_contents($path);
        if ($src === false || $src === '') {
            return false;
        }

        // No migration_NNN_* function at all → definitely not hybrid (pure
        // top-level script, which is fine).
        if (!preg_match('/function\s+' . preg_quote($prefix, '/') . '\w*\s*\(/', $src)) {
            return false;
        }

        // Strip comments.
        $clean = preg_replace('!/\*.*?\*/!s', '', $src);
        $clean = preg_replace('/(^|\s)\/\/[^\n]*/', '', $clean);
        $clean = preg_replace('/(^|\s)#[^\n]*/', '', $clean);

        // Strip php tags.
        $clean = preg_replace('/<\?php|<\?=|\?>/', '', $clean);

        // Strip balanced function bodies. Regex balance is tricky, walk the
        // source manually tracking brace depth.
        $stripped = '';
        $len = strlen($clean);
        $i = 0;
        while ($i < $len) {
            // Find next "function " keyword.
            if (preg_match('/\bfunction\b/', $clean, $m, PREG_OFFSET_CAPTURE, $i)) {
                $fnStart = $m[0][1];
                // Keep text up to function keyword.
                $stripped .= substr($clean, $i, $fnStart - $i);
                // Find the opening brace of the function body.
                $brace = strpos($clean, '{', $fnStart);
                if ($brace === false) {
                    $i = $len;
                    break;
                }
                // Walk to matching close brace.
                $depth = 1;
                $j = $brace + 1;
                while ($j < $len && $depth > 0) {
                    $ch = $clean[$j];
                    if ($ch === '{') $depth++;
                    elseif ($ch === '}') $depth--;
                    $j++;
                }
                // Skip the entire function definition (keyword through closing brace).
                $i = $j;
            } else {
                $stripped .= substr($clean, $i);
                break;
            }
        }

        // What's left outside functions. Allow: require/require_once/include,
        // declare, namespace, use, whitespace, and the bare return at EOF that
        // migrations rely on.
        $outside = trim($stripped);
        if ($outside === '') {
            return false;
        }

        // Tokenize what's left and look for anything that isn't a safe
        // statement. Anything matching these substrings indicates executable
        // top-level code alongside the function definition.
        $danger = [
            '$pdo',
            '$db',
            'Database::',
            '->exec(',
            '->query(',
            '->prepare(',
            '->insert(',
            'echo ',
            'print(',
            'print ',
            'throw ',
            'try ',
            'try{',
            'if(',
            'if (',
            'foreach(',
            'foreach (',
            'while(',
            'while (',
            'for(',
            'for (',
        ];
        foreach ($danger as $needle) {
            if (stripos($outside, $needle) !== false) {
                return true;
            }
        }
        return false;
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

        $prefix = 'migration_' . str_pad($migrationNumber, 3, '0', STR_PAD_LEFT);

        // Safety gate (Codex round-2 Finding 5): refuse migration files that
        // BOTH define a migration_NNN_* function AND run side-effecting code at
        // top level. Such files would execute twice (once on require_once,
        // once on call_user_func) and corrupt state.
        if (self::isHybridMigration($migration['path'], $prefix)) {
            $msg = sprintf(
                'Refusing to run migration %s, file %s contains both a %s*() function and top-level executable code. Pick one pattern: either put ALL work in the function, or remove the function and keep only a top-level script.',
                $migrationNumber,
                basename($migration['path']),
                $prefix
            );
            error_log('[MigrationRunner] ' . $msg);
            return [
                'success' => false,
                'error'   => $msg,
                'migration' => $migration,
            ];
        }

        // Snapshot user functions BEFORE include so we can detect whether this
        // migration file defined a `migration_NNN_*` function (old pattern) or
        // simply ran its SQL at include time (new top-level pattern).
        $functionsBefore = get_defined_functions()['user'];

        // Load migration file INSIDE A CLOSURE, so its local variables cannot
        // reach this method's.
        //
        // Migration 156 named a local $before while comparing a stored value to
        // the canonical one. A plain require_once shares scope with its caller,
        // so that string landed on the runner's own $before and the next line,
        // array_diff($after, $before), threw a TypeError. The migration itself
        // had already run and committed; only the ledger insert was skipped, so
        // the change was applied and recorded nowhere. The deploy printed no
        // reason at all, because production config sets display_errors to 0.
        //
        // Any of $after, $prefix, $executed, $migrations, $functionName, $pdo or
        // $result would have done the same thing. A migration's locals are its
        // own business; the runner should not be reading them.
        //
        // For top-level scripts any thrown exception propagates and is caught
        // below and recorded as a failure.
        try {
            (static function (string $cardifyMigrationPath): void {
                require_once $cardifyMigrationPath;
            })($migration['path']);
        } catch (Throwable $e) {
            return [
                'success' => false,
                'error' => 'Migration include error: ' . $e->getMessage(),
                'migration' => $migration
            ];
        }

        $functionsAfter = get_defined_functions()['user'];

        $functionName = null;
        foreach (array_diff($functionsAfter, $functionsBefore) as $func) {
            if (strpos($func, $prefix) === 0) {
                $functionName = $func;
                break;
            }
        }
        // Fallback: function may have been defined by an earlier include in
        // the same request (shouldn't happen in normal flow).
        if (!$functionName) {
            foreach ($functionsAfter as $func) {
                if (strpos($func, $prefix) === 0) {
                    $functionName = $func;
                    break;
                }
            }
        }

        try {
            $pdo = self::$db->getConnection();

            if ($functionName && function_exists($functionName)) {
                // FUNCTION-BASED PATTERN: migration file defines a function, we call it.
                // The hybrid-detector above ensures the top-level here was a pure
                // function-definition, so this is the ONLY execution.
                $result = call_user_func($functionName, $pdo);
                if (!is_array($result) || empty($result['success'])) {
                    return [
                        'success' => false,
                        'error' => 'Migration failed: ' . implode(', ', (is_array($result) ? ($result['errors'] ?? []) : [])),
                        'migration' => $migration
                    ];
                }
            }
            // TOP-LEVEL PATTERN: no function defined, script already ran during
            // require_once above and did not throw → treat as success.

            // Record migration as executed
            self::$db->insert('migrations', [
                'migration_number' => $migrationNumber,
                'migration_name' => $migration['name'],
                'executed_at' => dbNow()
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
        //
        // Isolated for the same reason runMigration() is: a migration's locals
        // would otherwise land on $migration, $prefix, $functionName and $pdo
        // right here.
        try {
            (static function (string $cardifyMigrationPath): void {
                require_once $cardifyMigrationPath;
            })($migration['path']);
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
