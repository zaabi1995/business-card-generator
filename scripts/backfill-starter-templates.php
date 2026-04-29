<?php
/**
 * BHD-244 backfill: seed a starter template for companies that completed
 * onboarding but ended up with zero templates (including the MHD bulk-import
 * cluster). Idempotent — companies that already have templates are skipped
 * by seedStarterTemplate().
 *
 * Usage (dry-run):
 *   php scripts/backfill-starter-templates.php
 *
 * Apply for real:
 *   php scripts/backfill-starter-templates.php --apply
 */
require_once __DIR__ . '/../config.php';

$apply = in_array('--apply', $argv, true);
$db = Database::getInstance();

$stuck = $db->fetchAll(
    "SELECT c.id, c.name, c.slug
     FROM companies c
     LEFT JOIN templates t ON t.company_id = c.id
     WHERE c.onboarding_completed = 1
     GROUP BY c.id, c.name, c.slug
     HAVING COUNT(t.id) = 0
     ORDER BY c.created_at ASC"
);

echo 'Found ' . count($stuck) . " companies with onboarding_completed=1 and 0 templates\n";

$done = 0;
foreach ($stuck as $c) {
    printf("  %s  %-40s  %s\n", $c['id'], substr($c['name'], 0, 40), $c['slug']);
    if ($apply) {
        seedStarterTemplate($db, $c['id'], 'bhd-classic');
        $done++;
    }
}

if ($apply) {
    echo "\nSeeded $done starter templates.\n";
} else {
    echo "\nDry run. Re-run with --apply to actually seed.\n";
}
