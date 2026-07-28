<?php
/**
 * Generate the web-sized card images that generated_cards.front_web_path /
 * back_web_path were added to hold, and that nothing ever populated.
 *
 * The column landed in migration 029. Every other reference to it either reads
 * it or sets it to NULL, so the public card page has always fallen through to
 * front_file_path: the full PRINT asset. Measured on a live card: 3225px wide,
 * 991KB, displayed at 352px CSS. That is the largest object on the most-viewed
 * page in the product, and it got worse when the front face was correctly made
 * eager + fetchpriority=high for LCP, because the browser can no longer defer it.
 *
 * Writes a WebP variant beside the original and points *_web_path at it.
 * CardRenderer and digital_card.php already prefer that column, so nothing else
 * has to change.
 *
 *   php scripts/backfill-card-web-variants.php --dry-run
 *   php scripts/backfill-card-web-variants.php [--limit=N] [--force]
 */
require_once __DIR__ . '/../config.php';

const WEB_MAX_WIDTH = 900;   // ~2.5x the 352px display width, sharp on 3x screens
const WEB_QUALITY   = 82;

$opts    = getopt('', ['dry-run', 'limit::', 'force']);
$dryRun  = isset($opts['dry-run']);
$force   = isset($opts['force']);
$limit   = isset($opts['limit']) ? max(1, (int) $opts['limit']) : 0;

$db = Database::getInstance()->getConnection();
$where = $force ? '' : ' AND (front_web_path IS NULL OR front_web_path = "")';
$sql = 'SELECT id, company_id, front_file_path, back_file_path, front_web_path, back_web_path
        FROM generated_cards
        WHERE front_file_path IS NOT NULL AND front_file_path <> ""' . $where;
if ($limit) { $sql .= ' LIMIT ' . $limit; }
$rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

/** Mirror CardRenderer::expandStored, then map the URL path to disk. */
function diskPath(string $stored, string $companyId): ?string {
    $rel = strpos($stored, '/') === false
        ? '/uploads/companies/' . $companyId . '/cards/' . $stored
        : $stored;
    // ?: not ??  -  DOCUMENT_ROOT is an empty STRING under CLI, not null,
    // so ?? never fires and every path resolves to a bare /uploads/... 
    $root = (string) ($_SERVER['DOCUMENT_ROOT'] ?? '');
    $abs = rtrim($root !== '' ? $root : dirname(__DIR__), '/') . $rel;
    return is_file($abs) ? $abs : null;
}

function makeWebVariant(string $srcAbs): ?array {
    $info = @getimagesize($srcAbs);
    if (!$info) return null;
    [$w, $h] = $info;
    if ($w <= 0 || $h <= 0) return null;

    $dstAbs = preg_replace('/\.[A-Za-z0-9]+$/', '', $srcAbs) . '_web.webp';
    // Already small enough: still convert, WebP alone is a large saving.
    $scale  = $w > WEB_MAX_WIDTH ? WEB_MAX_WIDTH / $w : 1.0;
    $nw = max(1, (int) round($w * $scale));
    $nh = max(1, (int) round($h * $scale));

    $src = match ($info[2]) {
        IMAGETYPE_PNG  => @imagecreatefrompng($srcAbs),
        IMAGETYPE_JPEG => @imagecreatefromjpeg($srcAbs),
        IMAGETYPE_WEBP => @imagecreatefromwebp($srcAbs),
        default        => null,
    };
    if (!$src) return null;

    $dst = imagecreatetruecolor($nw, $nh);
    // Cards are frequently transparent PNGs; without this the corners go black.
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    $ok = imagewebp($dst, $dstAbs, WEB_QUALITY);
    imagedestroy($src);
    imagedestroy($dst);
    if (!$ok || !is_file($dstAbs)) return null;
    // This script runs as root from cron; the webserver runs as www. Without
    // this every generated variant 403s, and because the DB column is already
    // pointed at it, that breaks the card image on every public card page.
    @chmod($dstAbs, 0644);
    if (function_exists('chown')) { @chown($dstAbs, 'www'); @chgrp($dstAbs, 'www'); }
    return ['abs' => $dstAbs, 'from' => [$w, $h], 'to' => [$nw, $nh]];
}

$done = 0; $skipped = 0; $savedBytes = 0;
foreach ($rows as $row) {
    $update = [];
    foreach ([['front_file_path', 'front_web_path'], ['back_file_path', 'back_web_path']] as [$srcCol, $webCol]) {
        $stored = (string) ($row[$srcCol] ?? '');
        if ($stored === '') continue;
        $abs = diskPath($stored, (string) $row['company_id']);
        if ($abs === null) { $skipped++; continue; }
        $before = filesize($abs);
        if ($dryRun) {
            $i = @getimagesize($abs);
            printf("DRY %s %s %dx%d %dKB\n", $row['id'], $srcCol, $i[0] ?? 0, $i[1] ?? 0, (int) ($before / 1024));
            continue;
        }
        $made = makeWebVariant($abs);
        if (!$made) { $skipped++; continue; }
        $after = filesize($made['abs']);
        $savedBytes += max(0, $before - $after);
        // Store in the same shape the source used, so expandStored keeps working.
        $rel = strpos($stored, '/') === false
            ? basename($made['abs'])
            : dirname($stored) . '/' . basename($made['abs']);
        $update[$webCol] = $rel;
        printf("OK  %s %s %dx%d->%dx%d %dKB->%dKB\n", $row['id'], $srcCol,
            $made['from'][0], $made['from'][1], $made['to'][0], $made['to'][1],
            (int) ($before / 1024), (int) ($after / 1024));
        $done++;
    }
    if (!$dryRun && $update) {
        $sets = implode(', ', array_map(fn($c) => "$c = :$c", array_keys($update)));
        $st = $db->prepare("UPDATE generated_cards SET $sets WHERE id = :id");
        $st->execute($update + ['id' => $row['id']]);
    }
}
printf("\nrows=%d variants=%d skipped=%d saved=%.1fMB%s\n",
    count($rows), $done, $skipped, $savedBytes / 1048576, $dryRun ? ' (DRY RUN)' : '');
