<?php
/**
 * One-shot: extract 5-color palettes for every indexed/verified
 * om_companies row that doesn't have one yet. Idempotent, safe to
 * re-run. Pass --force to recompute existing palettes.
 *
 *   php backfill-logo-palettes.php
 *   php backfill-logo-palettes.php --force
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/LogoLibrary.php';

$force = in_array('--force', $argv, true);

$db = Database::getInstance();
$where = "logo_status IN ('indexed','verified')
          AND (logo_png_path IS NOT NULL OR logo_png_512_path IS NOT NULL OR logo_webp_path IS NOT NULL)";
if (!$force) $where .= " AND (logo_palette IS NULL OR logo_palette = '')";

$rows = $db->fetchAll(
    "SELECT id, slug, logo_png_path, logo_png_512_path, logo_webp_path
     FROM om_companies WHERE $where ORDER BY id"
);

$root = realpath(__DIR__ . '/..');
$done = $skipped = $failed = 0;

foreach ($rows as $row) {
    $rel = $row['logo_png_path']
        ?: $row['logo_png_512_path']
        ?: $row['logo_webp_path'];
    $path = $root . $rel;
    if (!is_file($path)) {
        $skipped++;
        echo "skip\tid={$row['id']}\tslug={$row['slug']}\treason=file_missing\tpath=$rel\n";
        continue;
    }
    $palette = LogoLibrary::palette($path, 5);
    if (empty($palette)) {
        $failed++;
        echo "fail\tid={$row['id']}\tslug={$row['slug']}\treason=palette_empty\n";
        continue;
    }
    $db->getConnection()->prepare(
        "UPDATE om_companies SET logo_palette = :p WHERE id = :id"
    )->execute([':p' => json_encode($palette), ':id' => $row['id']]);
    $done++;
    echo "ok\tid={$row['id']}\tslug={$row['slug']}\tpalette=" . implode(',', $palette) . "\n";
}

echo "\nbackfill_done\tdone=$done\tskipped=$skipped\tfailed=$failed\n";
