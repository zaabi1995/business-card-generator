<?php
/**
 * Generate the capped web variants for every tenant brand image already on
 * disk (company_themes.logo_path / favicon_path).
 *
 * Those files are saved at whatever size the client uploaded and were never
 * resized. Measured on the VPS before this ran, 6 of 23 theme images were over
 * 60KB: a 292KB favicon, a 98KB logo the public card page draws at max-width
 * 96px (about 23px tall, roughly 2 seconds of a 400kbps connection), and four
 * more between 74KB and 90KB. The same file is also served to the admin UI and
 * the portal.
 *
 * Writes two siblings beside each original and touches nothing else:
 *   <base>_web.webp  for <img> tags       (logo cap 400px long edge)
 *   <base>_web.png   for <link rel=icon>  (favicon cap 512px)
 * The original is left exactly as uploaded, so wallet_apple.php,
 * wallet_google.php and the print paths are unaffected. The "<base>-dark.*"
 * reverse logo the Apple pass globs for (cardify skill rule 45) is never a
 * logo_path / favicon_path value, so it is never touched, and the "_web."
 * infix cannot match that glob anyway.
 *
 * Idempotent: a sibling newer than its original is left alone, and a sibling
 * that comes out no smaller than the original is deleted so the readers fall
 * back to the original rather than shipping more bytes.
 *
 *   php scripts/backfill-theme-image-variants.php --dry-run
 *   php scripts/backfill-theme-image-variants.php [--limit=N] [--force]
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/ThemeImage.php';

$opts   = getopt('', ['dry-run', 'limit::', 'force']);
$dryRun = isset($opts['dry-run']);
$force  = isset($opts['force']);
$limit  = isset($opts['limit']) ? max(1, (int) $opts['limit']) : 0;

$db  = Database::getInstance()->getConnection();
$sql = 'SELECT company_id, logo_path, favicon_path
        FROM company_themes
        WHERE (logo_path IS NOT NULL AND logo_path <> "")
           OR (favicon_path IS NOT NULL AND favicon_path <> "")';
if ($limit) { $sql .= ' LIMIT ' . $limit; }
$rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

$targets = [
    'logo_path'    => ThemeImage::LOGO_MAX,
    'favicon_path' => ThemeImage::FAVICON_MAX,
];

$seen = [];              // one file can be both logo_path and favicon_path
$made = 0; $skipped = 0; $missing = 0; $savedBytes = 0;

foreach ($rows as $row) {
    foreach ($targets as $col => $maxEdge) {
        $stored = trim((string) ($row[$col] ?? ''));
        if ($stored === '') continue;

        $abs = ThemeImage::absPath($stored);
        if ($abs === null) {
            printf("MISS %-28s %s %s\n", $row['company_id'], $col, $stored);
            $missing++;
            continue;
        }
        if (isset($seen[$abs])) continue;
        $seen[$abs] = true;

        if (!ThemeImage::isConvertible($abs)) {
            // SVG and ICO are vector / GD-unreadable and already small.
            printf("SKIP %-28s %s %s (%s)\n", $row['company_id'], $col,
                basename($abs), strtolower(pathinfo($abs, PATHINFO_EXTENSION)));
            $skipped++;
            continue;
        }

        $before = (int) filesize($abs);
        $info   = @getimagesize($abs);

        if ($dryRun) {
            printf("DRY  %-28s %s %s %dx%d %dKB\n", $row['company_id'], $col,
                basename($abs), $info[0] ?? 0, $info[1] ?? 0, (int) ($before / 1024));
            continue;
        }

        $variants = ThemeImage::ensureVariants($stored, $maxEdge, $force);
        $kept = array_filter($variants);
        if (!$kept) {
            printf("SKIP %-28s %s %s (no smaller variant)\n",
                $row['company_id'], $col, basename($abs));
            $skipped++;
            continue;
        }

        $parts = [];
        foreach ($kept as $relPath) {
            $vAbs = ThemeImage::absPath($relPath);
            if ($vAbs === null) continue;
            ThemeImage::fixPerms($vAbs);
            $parts[] = sprintf('%s %dKB', pathinfo($vAbs, PATHINFO_EXTENSION), (int) (filesize($vAbs) / 1024));
        }
        // Only the WebP is what a card view actually downloads, so count that
        // one as the saving; the PNG only replaces the favicon request.
        $webpAbs = isset($variants['webp']) ? ThemeImage::absPath($variants['webp']) : null;
        if ($webpAbs) { $savedBytes += max(0, $before - filesize($webpAbs)); }

        printf("OK   %-28s %s %s %dx%d %dKB -> %s\n", $row['company_id'], $col,
            basename($abs), $info[0] ?? 0, $info[1] ?? 0, (int) ($before / 1024),
            implode(', ', $parts));
        $made++;
    }
}

printf("\nthemes=%d images=%d skipped=%d missing=%d saved=%.1fKB%s\n",
    count($rows), $made, $skipped, $missing, $savedBytes / 1024,
    $dryRun ? ' (DRY RUN)' : '');
