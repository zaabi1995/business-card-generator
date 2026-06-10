<?php
/**
 * Migration sanity guard. Run in CI before deploy.
 *
 *   php scripts/check-migrations.php
 *
 * Asserts:
 *  1. No two migration files share a number (MigrationRunner dedups by number
 *     via a UNIQUE key, so a duplicate number means the second file is SKIPPED
 *     forever on a fresh install). This is the exact class of bug that left
 *     065/066/095 each doubled until the 10 Jun 2026 audit.
 *  2. Every file matches the NNN_description.php naming convention.
 *
 * Exit 0 = clean, 1 = problem found. No DB connection needed (static scan).
 */

$dir = __DIR__ . '/../database/migrations';
$files = glob($dir . '/*.php') ?: [];

$byNumber = [];
$malformed = [];

foreach ($files as $path) {
    $name = basename($path);
    if (!preg_match('/^(\d+)_(.+)\.php$/', $name, $m)) {
        $malformed[] = $name;
        continue;
    }
    $num = (int) $m[1];
    $byNumber[$num][] = $name;
}

$errors = [];

foreach ($byNumber as $num => $names) {
    if (count($names) > 1) {
        $errors[] = sprintf(
            "Duplicate migration number %03d shared by: %s",
            $num,
            implode(', ', $names)
        );
    }
}

foreach ($malformed as $name) {
    $errors[] = "Malformed migration filename (need NNN_description.php): $name";
}

if ($errors) {
    fwrite(STDERR, "Migration check FAILED:\n");
    foreach ($errors as $e) {
        fwrite(STDERR, "  - $e\n");
    }
    fwrite(STDERR, sprintf("\n%d migration file(s) scanned.\n", count($files)));
    exit(1);
}

printf("OK: %d migrations, all numbers unique, all names well-formed.\n", count($files));
exit(0);
