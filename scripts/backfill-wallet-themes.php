<?php
/**
 * Give every tenant its own default wallet theme.
 *
 * A company with no active default row in `wallet_themes` falls through to the
 * platform "Cardify Teal" theme, whose logo_mode is 'cardify'. Its staff then
 * hand customers an Apple or Google pass in Cardify's colour carrying Cardify's
 * logo. This backfills the missing rows from each tenant's own brand colour.
 *
 * Idempotent and non-destructive: tenants that already have a default are
 * skipped, existing rows are never edited.
 *
 *   php scripts/backfill-wallet-themes.php            # report only
 *   php scripts/backfill-wallet-themes.php --apply    # write
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/DatabaseAdapter.php';

$apply = in_array('--apply', $argv, true);
$db = Database::getInstance();

$rows = $db->fetchAll(
    "SELECT c.id, c.slug, c.name, COALESCE(t.primary_color, '#009bc1') AS brand
       FROM companies c
       LEFT JOIN company_themes t ON t.company_id = c.id
       LEFT JOIN wallet_themes  w ON w.company_id = c.id AND w.is_default = 1 AND w.is_active = 1
      WHERE c.status = 'active' AND w.id IS NULL
      ORDER BY c.slug"
);

printf("%d tenant(s) fall back to the Cardify platform wallet theme.\n", count($rows));
$done = 0;
foreach ($rows as $r) {
    if ($apply) {
        $ok = DatabaseAdapter::seedDefaultWalletTheme($r['id'], $r['brand']);
        $done += $ok ? 1 : 0;
        printf("  %-28s %s  %s\n", $r['slug'], $r['brand'], $ok ? 'seeded' : 'skipped');
    } else {
        printf("  %-28s %s  would seed\n", $r['slug'], $r['brand']);
    }
}
if (!$apply) {
    echo "\nDry run. Re-run with --apply to write.\n";
} else {
    printf("\nSeeded %d wallet theme(s).\n", $done);
}
