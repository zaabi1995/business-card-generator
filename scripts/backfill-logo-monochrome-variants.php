<?php
/**
 * One-shot: generate dark + white monochrome variants for every
 * indexed/verified row. Safe to re-run; --force overrides the skip
 * check on rows that already have variants.
 *
 *   php backfill-logo-monochrome-variants.php
 *   php backfill-logo-monochrome-variants.php --force
 *   php backfill-logo-monochrome-variants.php --id=2501
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/LogoLibrary.php';

$force = in_array('--force', $argv, true);
$onlyId = null;
foreach ($argv as $a) if (preg_match('/^--id=(\d+)$/', $a, $m)) $onlyId = (int) $m[1];

$db = Database::getInstance();

$where = "logo_status IN ('indexed','verified')
          AND (logo_png_path IS NOT NULL OR logo_svg_path IS NOT NULL)";
if (!$force) $where .= " AND (logo_variants_at IS NULL)";
if ($onlyId) $where .= " AND id = " . $onlyId;

$rows = $db->fetchAll(
    "SELECT id, slug FROM om_companies WHERE $where ORDER BY id"
);

$done = $skipped = $failed = 0;
foreach ($rows as $row) {
    $out = LogoLibrary::generateMonochromeVariants((int) $row['id']);
    if (empty($out)) {
        $failed++;
        echo "fail\tid={$row['id']}\tslug={$row['slug']}\treason=no_variants_generated\n";
        continue;
    }
    $done++;
    echo "ok\tid={$row['id']}\tslug={$row['slug']}\tvariants=" . count($out) . "\n";
}
echo "\nvariants_done\tdone=$done\tfailed=$failed\n";
