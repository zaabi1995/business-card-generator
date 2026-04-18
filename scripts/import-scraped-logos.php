<?php
/**
 * Import scraped logos from /tmp/2oman-logos into Cardify's om_companies.
 *
 * Reads /tmp/2oman-logos/index.json produced by scrape-2oman-playwright.mjs.
 * For each entry: fuzzy-matches name_ar against om_companies; if >=0.90 links
 * onto the existing row (indexed); if 0.75–0.89 flags for admin review;
 * otherwise inserts a new om_companies row. Copies logo file to
 * /storage/logos/indexed/{id}.{ext} and fills in logo_*_path columns.
 *
 * Run (on VPS):
 *   /www/server/php/83/bin/php scripts/import-scraped-logos.php \
 *     /tmp/2oman-logos/index.json
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/LogoLibrary.php';

$jsonPath = $argv[1] ?? '/tmp/2oman-logos/index.json';
if (!is_file($jsonPath)) {
    fwrite(STDERR, "index.json not found at $jsonPath\n");
    exit(1);
}
$scrapeDir = dirname($jsonPath);
$entries   = json_decode(file_get_contents($jsonPath), true);
if (!is_array($entries)) {
    fwrite(STDERR, "bad index.json\n");
    exit(1);
}

$db  = Database::getInstance();
$pdo = $db->getConnection();
// One-shot import script — enable emulated prepares so we can reuse the same
// :is_queue placeholder across multiple IF(…) branches in one UPDATE without
// hitting HY093 "Invalid parameter number". Does not affect request-path code.
$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);

$allCompanies = $pdo->query(
    "SELECT id, name_en, name_ar, slug, website_domain_cache, logo_status
       FROM om_companies"
)->fetchAll(PDO::FETCH_ASSOC);

function normalizeName(string $s): string {
    $s = mb_strtolower(trim($s), 'UTF-8');
    $s = preg_replace('~(llc|s\.p\.c\.?|spc|sa[o]?g|saog|saoc|co\.?|company|group|ltd|limited)~u', '', $s);
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

$destAbsDir = dirname(__DIR__) . '/storage/logos/indexed';
$pendingDir = dirname(__DIR__) . '/storage/logos/pending';
@mkdir($destAbsDir, 0755, true);
@mkdir($pendingDir, 0755, true);

$report = ['total' => count($entries), 'auto_linked' => 0, 'queued' => 0, 'new_rows' => 0, 'protected_skipped' => 0, 'errors' => []];

foreach ($entries as $i => $e) {
    $nameEn = $e['name_en'] ?? '';
    $nameAr = $e['name_ar'] ?? '';
    $fileSrc = $scrapeDir . '/' . $e['file'];
    if (!is_file($fileSrc)) {
        $report['errors'][] = "missing file: {$e['file']}";
        continue;
    }

    // Best fuzzy match: try both EN and AR names against both columns.
    $best = ['score' => 0.0, 'company' => null];
    foreach ($allCompanies as $c) {
        $s = max(
            similarity($nameEn, $c['name_en'] ?? ''),
            similarity($nameAr, $c['name_ar'] ?? ''),
            similarity($nameEn, $c['name_ar'] ?? ''),
            similarity($nameAr, $c['name_en'] ?? '')
        );
        if ($s > $best['score']) $best = ['score' => $s, 'company' => $c];
    }

    $decision = $best['score'] >= 0.90 ? 'auto_link' : ($best['score'] >= 0.75 ? 'queue' : 'new_row');
    $matchedStatus = $best['company']['logo_status'] ?? 'none';

    // Protect verified/takedown rows: downgrade auto_link to queue.
    if ($decision === 'auto_link' && in_array($matchedStatus, ['verified', 'takedown'], true)) {
        $decision = 'queue';
        $report['protected_skipped']++;
    }

    $ext = strtolower(pathinfo($e['file'], PATHINFO_EXTENSION)) ?: 'png';

    // JPEG normalization (library downstream expects PNG in logo_png_path).
    if (in_array($ext, ['jpg', 'jpeg'], true)) {
        $img = @imagecreatefromjpeg($fileSrc);
        if (!$img) { $report['errors'][] = "jpeg decode failed: {$e['file']}"; continue; }
        $png = tempnam(sys_get_temp_dir(), 'imp_') . '.png';
        imagepng($img, $png, 9); imagedestroy($img);
        $fileSrc = $png; $ext = 'png';
    }

    // Decide target company_id + destination.
    if ($decision === 'auto_link') {
        $companyId = (int) $best['company']['id'];
        $destDir   = $destAbsDir;
        $destRel   = "/storage/logos/indexed";
        $report['auto_linked']++;
    } elseif ($decision === 'queue') {
        $companyId = (int) $best['company']['id'];
        $destDir   = $pendingDir;
        $destRel   = "/storage/logos/pending";
        $report['queued']++;
    } else {
        // Insert new row.
        $slug = $e['slug'] ?: 'logo-' . substr(md5($fileSrc), 0, 8);
        $slug .= '-' . substr(md5($e['src'] ?? $fileSrc), 0, 4);
        try {
            $pdo->prepare("INSERT INTO om_companies
                (name_en, name_ar, slug, sector, wilayat, size_bucket, curated)
                VALUES (:en, :ar, :s, 'other', 'muscat', 'medium', 0)")
                ->execute([
                    ':en' => $nameEn ?: $nameAr,
                    ':ar' => $nameAr ?: $nameEn,
                    ':s'  => $slug,
                ]);
            $companyId = (int) $pdo->lastInsertId();
            $destDir   = $destAbsDir;
            $destRel   = "/storage/logos/indexed";
            $report['new_rows']++;
            // Add to pool so later imports can match.
            $allCompanies[] = [
                'id'      => (string) $companyId,
                'name_en' => $nameEn,
                'name_ar' => $nameAr,
                'slug'    => $slug,
                'logo_status' => 'none',
            ];
        } catch (Throwable $t) {
            $report['errors'][] = "insert failed for $nameEn: " . $t->getMessage();
            continue;
        }
    }

    // Copy the file to target.
    $destFile = "$destDir/{$companyId}.$ext";
    if (!@copy($fileSrc, $destFile)) {
        $report['errors'][] = "copy failed: $fileSrc → $destFile";
        continue;
    }
    [$w, $h] = @getimagesize($destFile) ?: [null, null];
    $dom     = LogoLibrary::dominantColor($destFile);
    $relPath = "$destRel/{$companyId}.$ext";

    $isSvg  = $ext === 'svg';
    $isPng  = $ext === 'png';
    $isWebp = $ext === 'webp';
    $isQueue = $decision === 'queue';

    // Clear stale variant files before auto_link overwrites canonical columns.
    if (!$isQueue) {
        foreach (['svg', 'png', 'webp'] as $oldExt) {
            if ($oldExt === $ext) continue;
            $old = "$destAbsDir/{$companyId}.{$oldExt}";
            if (is_file($old)) @unlink($old);
        }
        foreach (['512', '2048'] as $size) {
            $old = "$destAbsDir/{$companyId}-{$size}.png";
            if (is_file($old)) @unlink($old);
        }
    }

    $pdo->prepare("UPDATE om_companies SET
        logo_status          = IF(logo_status IN ('verified','takedown'), logo_status,
                                 IF(:is_queue = 1, logo_status, 'indexed')),
        logo_source          = '2oman_net',
        logo_source_url      = :su,
        logo_png_path        = IF(:is_queue = 1, logo_png_path,  IF(:is_png  = 1, :rel_png,  NULL)),
        logo_svg_path        = IF(:is_queue = 1, logo_svg_path,  IF(:is_svg  = 1, :rel_svg,  NULL)),
        logo_webp_path       = IF(:is_queue = 1, logo_webp_path, IF(:is_webp = 1, :rel_webp, NULL)),
        logo_png_512_path    = IF(:is_queue = 1, logo_png_512_path,  NULL),
        logo_png_2048_path   = IF(:is_queue = 1, logo_png_2048_path, NULL),
        logo_width           = IF(:is_queue = 1, logo_width,  :w),
        logo_height          = IF(:is_queue = 1, logo_height, :h),
        logo_dominant_color  = IF(:is_queue = 1, logo_dominant_color, :c),
        logo_match_pending   = IF(:mp = 1, 1, logo_match_pending),
        logo_updated_at      = NOW()
      WHERE id = :id")
       ->execute([
           ':su'       => $e['src'] ?? null,
           ':is_png'   => $isPng ? 1 : 0,
           ':is_svg'   => $isSvg ? 1 : 0,
           ':is_webp'  => $isWebp ? 1 : 0,
           ':is_queue' => $isQueue ? 1 : 0,
           ':rel_png'  => $relPath,
           ':rel_svg'  => $relPath,
           ':rel_webp' => $relPath,
           ':w'        => $w,
           ':h'        => $h,
           ':c'        => $dom,
           ':mp'       => $isQueue ? 1 : 0,
           ':id'       => $companyId,
       ]);

    printf("[%d/%d] %-32s → company_id=%d (%s, score=%.2f)\n",
        $i + 1, count($entries), substr($nameEn ?: $nameAr, 0, 32), $companyId, $decision, $best['score']);
}

$reportFile = dirname(__DIR__) . '/storage/logos/seed-reports/import-' . date('Y-m-d_His') . '.json';
file_put_contents($reportFile, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "\n[import] done\n";
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
echo "[import] report: $reportFile\n";
