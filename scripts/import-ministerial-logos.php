<?php
/**
 * Import ministerial / government logos cataloged by the vision subagent.
 *
 * Input:  /tmp/ministerial-logos-catalog.json (from vision pipeline)
 * Source: /tmp/ministerial-logos/*.png       (4500x4500 originals)
 *
 * Strategy:
 *   1. Group catalog entries by name_en — many entities have 3 visual variants
 *      (stacked, horizontal, bilingual). Pick the first file as canonical
 *      and store a sibling list under logo_source_url for audit.
 *   2. Fuzzy-match name_en + name_ar against om_companies at score >= 0.85
 *      (ministries usually already exist in the OBI). Match → auto_link.
 *      No match → insert new row with sector + wilayat from the catalog.
 *   3. Copy PNG to /storage/logos/indexed/{id}.png, set logo_status='indexed',
 *      logo_source='admin_upload', logo_updated_at=NOW.
 *
 * Run:
 *   /www/server/php/83/bin/php scripts/import-ministerial-logos.php \
 *     /tmp/ministerial-logos-catalog.json /tmp/ministerial-logos
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require '/www/wwwroot/cardify.om/config.php';
require '/www/wwwroot/cardify.om/includes/LogoLibrary.php';

$catalogPath = $argv[1] ?? '/tmp/ministerial-logos-catalog.json';
$srcDir      = rtrim($argv[2] ?? '/tmp/ministerial-logos', '/');

if (!is_file($catalogPath)) { fwrite(STDERR, "catalog missing: $catalogPath\n"); exit(1); }
if (!is_dir($srcDir))       { fwrite(STDERR, "src dir missing: $srcDir\n");     exit(1); }

$catalog = json_decode(file_get_contents($catalogPath), true);
if (!is_array($catalog)) { fwrite(STDERR, "bad JSON\n"); exit(1); }

$db  = Database::getInstance();
$pdo = $db->getConnection();
$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);  // reuse placeholders

// Group by entity (name_en). Keep the first file as canonical, list siblings.
$groups = [];
foreach ($catalog as $row) {
    $key = $row['name_en'];
    if (!isset($groups[$key])) {
        $groups[$key] = ['canonical' => $row, 'siblings' => []];
    } else {
        $groups[$key]['siblings'][] = $row['file'];
    }
}
echo "[import] catalog entries: " . count($catalog) . ", unique entities: " . count($groups) . "\n";

$allCompanies = $pdo->query("SELECT id, name_en, name_ar, slug, logo_status FROM om_companies")->fetchAll(PDO::FETCH_ASSOC);

function normalizeName(string $s): string {
    $s = mb_strtolower(trim($s), 'UTF-8');
    $s = preg_replace('~(llc|s\.p\.c\.?|spc|sa[o]?g|saoc|co\.?|company|group|ltd|limited|the\s)~u', '', $s);
    $s = preg_replace('~[^\p{L}\p{N}]+~u', ' ', $s);
    return trim($s);
}
function similarity(string $a, string $b): float {
    $a = normalizeName($a); $b = normalizeName($b);
    if ($a === '' || $b === '') return 0.0;
    if ($a === $b) return 1.0;
    similar_text($a, $b, $pct);
    return $pct / 100.0;
}

$destRelDir = '/storage/logos/indexed';
$destAbsDir = '/www/wwwroot/cardify.om' . $destRelDir;
@mkdir($destAbsDir, 0755, true);

$stats = ['entities' => count($groups), 'auto_linked' => 0, 'new_rows' => 0, 'protected_skipped' => 0, 'errors' => []];

foreach ($groups as $name => $group) {
    $row = $group['canonical'];
    $nameEn = $row['name_en'];
    $nameAr = $row['name_ar'] ?? '';
    $sector = $row['sector'];
    $wilayat = $row['wilayat'];
    $srcFile = $srcDir . '/' . $row['file'];

    if (!is_file($srcFile)) {
        $stats['errors'][] = "src missing: " . $row['file'];
        continue;
    }

    // Best fuzzy match
    $best = ['score' => 0.0, 'c' => null];
    foreach ($allCompanies as $c) {
        $s = max(
            similarity($nameEn, $c['name_en'] ?? ''),
            similarity($nameEn, $c['name_ar'] ?? ''),
            similarity($nameAr, $c['name_ar'] ?? ''),
            similarity($nameAr, $c['name_en'] ?? '')
        );
        if ($s > $best['score']) $best = ['score' => $s, 'c' => $c];
    }

    $matchedStatus = $best['c']['logo_status'] ?? 'none';
    $decision = $best['score'] >= 0.85 ? 'auto_link' : 'new_row';

    if ($decision === 'auto_link' && in_array($matchedStatus, ['verified', 'takedown'], true)) {
        $stats['protected_skipped']++;
        printf("  · %-50s → PROTECTED (score=%.2f, status=%s) skip\n", substr($nameEn, 0, 50), $best['score'], $matchedStatus);
        continue;
    }

    if ($decision === 'auto_link') {
        $companyId = (int) $best['c']['id'];
        $stats['auto_linked']++;
        printf("  ✓ %-50s → auto_link id=%d (score=%.2f)\n", substr($nameEn, 0, 50), $companyId, $best['score']);
    } else {
        // Insert new row
        $slug = strtolower(preg_replace('~[^a-z0-9]+~i', '-', $nameEn));
        $slug = trim($slug, '-');
        $slug = substr($slug, 0, 80) ?: 'logo-' . substr(md5($nameEn), 0, 8);
        $slug .= '-' . substr(md5($nameEn), 0, 4);
        try {
            $pdo->prepare("INSERT INTO om_companies
                (name_en, name_ar, slug, sector, wilayat, size_bucket, curated)
                VALUES (:en, :ar, :s, :sec, :wil, 'large', 0)")
                ->execute([
                    ':en' => $nameEn,
                    ':ar' => $nameAr ?: $nameEn,
                    ':s'  => $slug,
                    ':sec'=> $sector,
                    ':wil'=> $wilayat,
                ]);
            $companyId = (int) $pdo->lastInsertId();
            $stats['new_rows']++;
            $allCompanies[] = ['id' => (string) $companyId, 'name_en' => $nameEn, 'name_ar' => $nameAr, 'slug' => $slug, 'logo_status' => 'none'];
            printf("  + %-50s → new id=%d [%s / %s]\n", substr($nameEn, 0, 50), $companyId, $sector, $wilayat);
        } catch (Throwable $t) {
            $stats['errors'][] = "insert failed for $nameEn: " . $t->getMessage();
            continue;
        }
    }

    // Copy PNG and clear stale variants
    $ext = 'png';
    foreach (['svg', 'webp', 'jpg', 'jpeg'] as $oldExt) {
        $old = "$destAbsDir/{$companyId}.{$oldExt}";
        if (is_file($old)) @unlink($old);
    }
    foreach (['512', '2048'] as $size) {
        $old = "$destAbsDir/{$companyId}-{$size}.png";
        if (is_file($old)) @unlink($old);
    }
    $destFile = "$destAbsDir/{$companyId}.{$ext}";
    if (!@copy($srcFile, $destFile)) {
        $stats['errors'][] = "copy failed: $srcFile → $destFile";
        continue;
    }
    @chmod($destFile, 0644);
    [$w, $h] = @getimagesize($destFile) ?: [null, null];
    $dom = LogoLibrary::dominantColor($destFile);
    $relPath = "$destRelDir/{$companyId}.{$ext}";
    $sourceNote = 'MoCIIP ministerial pack · ' . $row['file'] . ($group['siblings'] ? ' (+' . count($group['siblings']) . ' variants)' : '');

    $pdo->prepare("UPDATE om_companies SET
        logo_status          = IF(logo_status IN ('verified','takedown'), logo_status, 'indexed'),
        logo_source          = 'admin_upload',
        logo_source_url      = :su,
        logo_png_path        = :rel,
        logo_svg_path        = NULL,
        logo_webp_path       = NULL,
        logo_png_512_path    = NULL,
        logo_png_2048_path   = NULL,
        logo_width           = :w,
        logo_height          = :h,
        logo_dominant_color  = :c,
        logo_updated_at      = NOW(),
        sector               = IF(sector = 'other' OR sector IS NULL, :sec, sector),
        wilayat              = IF(wilayat IS NULL, :wil, wilayat)
      WHERE id = :id")
       ->execute([
           ':su'  => $sourceNote,
           ':rel' => $relPath,
           ':w'   => $w, ':h' => $h, ':c' => $dom,
           ':sec' => $sector, ':wil' => $wilayat,
           ':id'  => $companyId,
       ]);
}

echo "\n[import] done\n" . json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
