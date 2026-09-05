<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require_once dirname(__DIR__) . '/config.php';
require_once INCLUDES_DIR . '/MigrationRunner.php';

const CARDIFY_AUTOMATED_MIGRATION_BASELINE = 141;

$fail = static function (string $message): void {
    fwrite(STDERR, "MIGRATION FAIL: {$message}\n");
    exit(1);
};

// A fatal inside a migration used to leave no trace here. Production config
// sets display_errors to 0 and routes error_log to a file, so when migration
// 156 hit a TypeError in the runner the deploy printed its two echo lines,
// exited 255, rolled the code back and said only "database migration failed".
// The reason has to reach the deploy log, which is the only thing anyone
// reads after a failed deploy.
register_shutdown_function(static function (): void {
    $e = error_get_last();
    if ($e === null) return;
    if (!in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) return;
    fwrite(STDERR, sprintf(
        "MIGRATION FATAL: %s in %s on line %d\n",
        $e['message'], $e['file'], $e['line']
    ));
});

$lock = fopen(sys_get_temp_dir() . '/cardify-pending-migrations.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    $fail('another migration process is already running');
}

$available = array_values(MigrationRunner::getAvailableMigrations());
$executed = array_values(array_unique(array_map(
    'intval',
    MigrationRunner::getExecutedMigrations()
)));

if (!in_array(CARDIFY_AUTOMATED_MIGRATION_BASELINE, $executed, true)) {
    $fail(sprintf(
        'ledger baseline %d is missing; reconcile historical migrations manually',
        CARDIFY_AUTOMATED_MIGRATION_BASELINE
    ));
}

$highWater = max($executed);
$availableAfterBaseline = [];
foreach ($available as $migration) {
    $number = (int)($migration['number'] ?? 0);
    if ($number > CARDIFY_AUTOMATED_MIGRATION_BASELINE) {
        $availableAfterBaseline[$number][] = $migration;
    }
}

foreach ($availableAfterBaseline as $number => $migrations) {
    if (count($migrations) > 1) {
        $fail(sprintf('duplicate migration number %03d', $number));
    }
    if ($number <= $highWater && !in_array($number, $executed, true)) {
        $fail(sprintf(
            'ledger gap at migration %03d below high-water %03d',
            $number,
            $highWater
        ));
    }
}

$pending = [];
foreach ($availableAfterBaseline as $number => $migrations) {
    if ($number > $highWater) {
        $pending[] = $migrations[0];
    }
}
usort($pending, static function (array $left, array $right): int {
    return (int)$left['number'] <=> (int)$right['number'];
});

if ($pending === []) {
    printf("Migrations OK (none pending; high-water %03d)\n", $highWater);
    exit(0);
}

$expected = $highWater + 1;
foreach ($pending as $migration) {
    $number = (int)($migration['number'] ?? 0);
    $name = (string)($migration['name'] ?? 'unknown');
    if ($number !== $expected) {
        $fail(sprintf(
            'expected migration %03d, found %03d',
            $expected,
            $number
        ));
    }

    $result = MigrationRunner::runMigration($number);
    if (empty($result['success'])) {
        $fail(sprintf(
            '%03d %s (%s)',
            $number,
            $name,
            (string)($result['error'] ?? 'unknown error')
        ));
    }
    printf("migration OK: %03d %s\n", $number, $name);
    $expected++;
}

$executedAfter = array_map('intval', MigrationRunner::getExecutedMigrations());
foreach ($pending as $migration) {
    $number = (int)$migration['number'];
    if (!in_array($number, $executedAfter, true)) {
        $fail(sprintf(
            'migration %03d is missing from the ledger after execution',
            $number
        ));
    }
}

printf(
    "Migrations OK (%d applied; high-water %03d)\n",
    count($pending),
    $expected - 1
);
