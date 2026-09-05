<?php
/**
 * A migration's local variables are its own business.
 *
 * MigrationRunner::runMigration() used a plain `require_once`, which shares
 * scope with its caller. Migration 156 named a local $before while comparing a
 * stored price to the canonical one, that string landed on the runner's own
 * $before, and the very next line, array_diff($after, $before), threw a
 * TypeError. The migration had already run and committed its UPDATE; only the
 * ledger insert never happened, so production carried the change with no record
 * of it and the deploy rolled the code back.
 *
 * It printed nothing, because production config sets display_errors to 0. Both
 * halves are pinned here: the include is isolated, and a fatal reaches the
 * deploy log.
 */
$root = dirname(__DIR__, 2);
$failures = 0;
function mrCheck(bool $c, string $label, string $detail = ''): void
{
    global $failures;
    echo ($c ? 'PASS  ' : 'FAIL  ') . $label;
    if (!$c && $detail !== '') echo ' (' . $detail . ')';
    echo "\n";
    if (!$c) $failures++;
}

$src = file_get_contents($root . '/includes/MigrationRunner.php');

mrCheck(
    (bool) preg_match('/\(static function \(string \$cardifyMigrationPath\): void \{\s*require_once \$cardifyMigrationPath;\s*\}\)\(\$migration\[\'path\'\]\);/', $src),
    'the migration file is included inside a closure, not in the caller scope'
);
mrCheck(
    !preg_match('/^\s*require_once \$migration\[\'path\'\];/m', $src),
    'no bare require_once of the migration path is left'
);
mrCheck(
    str_contains($src, '$functionsBefore') && str_contains($src, '$functionsAfter'),
    'the function snapshots are named so a migration is unlikely to collide with them'
);

// The behaviour, proved rather than grepped: include a file that assigns to
// every name the runner uses, and check the caller's values survive.
$hostile = tempnam(sys_get_temp_dir(), 'mig') . '.php';
file_put_contents($hostile, "<?php\n"
    . '$before = "clobbered"; $after = "clobbered"; $prefix = "clobbered";' . "\n"
    . '$migration = "clobbered"; $executed = "clobbered"; $pdo = "clobbered";' . "\n"
    . '$functionsBefore = "clobbered"; $functionsAfter = "clobbered";' . "\n"
    . 'function migration_999_hostile($pdo) { return ["success" => true]; }' . "\n");

$functionsBefore = ['sentinel'];
$migration = ['path' => $hostile, 'number' => 999];
(static function (string $cardifyMigrationPath): void {
    require_once $cardifyMigrationPath;
})($migration['path']);
$functionsAfter = get_defined_functions()['user'];

mrCheck($functionsBefore === ['sentinel'], 'the caller keeps its own $functionsBefore');
mrCheck(is_array($migration) && ($migration['number'] ?? null) === 999, 'the caller keeps its own $migration');
mrCheck(
    is_array(array_diff($functionsAfter, $functionsBefore)),
    'array_diff over the two snapshots still takes two arrays'
);
mrCheck(
    in_array('migration_999_hostile', $functionsAfter, true),
    'a function defined inside the closure is still visible globally'
);
@unlink($hostile);

// The deploy log has to say why.
$runner = file_get_contents($root . '/ops/run-pending-migrations.php');
mrCheck(str_contains($runner, 'register_shutdown_function'),
    'the runner registers a shutdown handler');
mrCheck(str_contains($runner, 'MIGRATION FATAL:'),
    'a fatal inside a migration is written to STDERR, where the deploy log reads it');
mrCheck(str_contains($runner, 'E_PARSE') && str_contains($runner, 'E_ERROR'),
    'the handler covers the error types that end a process');

$emDash = "\xE2\x80\x94";
foreach (['includes/MigrationRunner.php', 'ops/run-pending-migrations.php',
          'database/migrations/156_nfc_price_canonical.php'] as $rel) {
    mrCheck(!str_contains(file_get_contents($root . '/' . $rel), $emDash), "{$rel} contains no em dash");
}

echo $failures === 0 ? "\nALL PASS\n" : "\n{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
