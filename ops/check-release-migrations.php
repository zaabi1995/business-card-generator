<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
require_once dirname(__DIR__) . '/includes/RuntimeDatabaseConfig.php';
try {
    $db = RuntimeDatabaseConfig::connect();
    $executed = array_map('intval', $db->query('SELECT migration_number FROM migrations')->fetchAll(PDO::FETCH_COLUMN));
    if (!in_array(141, $executed, true)) throw new RuntimeException('Missing migration baseline');
    $available = [];
    foreach (glob(dirname(__DIR__) . '/database/migrations/*.php') as $file) {
        if (preg_match('/^(\d+)_/', basename($file), $match) && (int)$match[1] > 141) {
            $number = (int)$match[1];
            if (isset($available[$number])) throw new RuntimeException('Duplicate migration number');
            $available[$number] = true;
            if (!in_array($number, $executed, true)) throw new RuntimeException('Pending migration requires an approved data release');
        }
    }
    printf("Migration ledger verified read-only; high-water %d; no pending migration.\n", max($executed));
} catch (Throwable $error) {
    fwrite(STDERR, "Code-only migration preflight failed; review the ledger without applying migrations.\n");
    exit(1);
}
