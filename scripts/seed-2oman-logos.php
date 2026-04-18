<?php
/**
 * scripts/seed-2oman-logos.php
 *
 * One-shot seeder that crawls 2oman.net/omani_logo_library, downloads
 * each logo, stores it under /storage/logos/indexed/, and either links
 * to an existing om_companies row (fuzzy match ≥0.90) or queues for
 * admin review (0.75–0.89) or creates a new row (<0.75).
 *
 * Respects robots.txt (hard-aborts on disallow). Identifies itself via
 * User-Agent. Rate limited to ~2 req/s.
 *
 * Run: php scripts/seed-2oman-logos.php [--dry-run] [--limit=N] [--start-page=N]
 *
 * Outputs a reconciliation JSON report to:
 *   storage/logos/seed-reports/<timestamp>.json
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/LogoLibrary.php';

$UA      = 'Cardify-LogoIndex/1.0 (+https://cardify.om/logos; contact@cardify.om)';
$BASE    = 'https://www.2oman.net/omani_logo_library/';
$CRAWL_DELAY_MS = 500;

$opts  = getopt('', ['dry-run', 'limit::', 'start-page::']);
$DRY   = isset($opts['dry-run']);
$LIMIT = isset($opts['limit']) ? (int) $opts['limit'] : 0;
$START = isset($opts['start-page']) ? max(1, (int) $opts['start-page']) : 1;

echo "[seed] dry-run=" . ($DRY ? 'yes' : 'no') . " limit=$LIMIT start-page=$START\n";

// --- 1. robots.txt check ---
$robotsUrl = 'https://www.2oman.net/robots.txt';
$ctx = stream_context_create(['http' => ['header' => "User-Agent: $UA\r\n", 'timeout' => 10]]);
$robots = @file_get_contents($robotsUrl, false, $ctx);
if ($robots !== false && preg_match('~Disallow:\s*/omani_logo_library~i', $robots)) {
    fwrite(STDERR, "[seed] HARD ABORT: robots.txt disallows /omani_logo_library\n");
    exit(1);
}

$db  = Database::getInstance();
$pdo = $db->getConnection();
$report = [
    'started_at'  => date('c'),
    'pages'       => 0,
    'scraped'     => 0,
    'auto_linked' => 0,
    'queued'      => 0,
    'new_rows'    => 0,
    'errors'      => [],
];

function fetchHtml(string $url, string $ua): ?string {
    $ctx = stream_context_create(['http' => [
        'header' => "User-Agent: $ua\r\n",
        'timeout' => 15,
        'ignore_errors' => true,
    ]]);
    $html = @file_get_contents($url, false, $ctx);
    return ($html === false || $html === '') ? null : $html;
}

function parseLogoEntries(string $html): array {
    $out = [];
    $dom = new DOMDocument();
    @$dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
    $xp = new DOMXPath($dom);
    foreach ($xp->query("//img[@src]") as $img) {
        $src = $img->getAttribute('src');
        $alt = trim($img->getAttribute('alt') ?: $img->getAttribute('title') ?: '');
        if (!$src || !$alt) continue;
        if (!preg_match('~/(logos?|uploads?|media|wp-content)/~i', $src)) continue;
        $ext = strtolower(pathinfo(parse_url($src, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));
        if (!in_array($ext, ['svg', 'png', 'jpg', 'jpeg', 'webp'], true)) continue;
        $out[] = ['src' => $src, 'name' => $alt];
    }
    return $out;
}

function resolveUrl(string $base, string $src): string {
    if (preg_match('~^https?://~i', $src)) return $src;
    if (strpos($src, '//') === 0) return 'https:' . $src;
    $p = parse_url($base);
    $origin = $p['scheme'] . '://' . $p['host'];
    if ($src[0] === '/') return $origin . $src;
    return rtrim($base, '/') . '/' . $src;
}

function downloadFile(string $url, string $dest, string $ua): bool {
    $ctx = stream_context_create(['http' => ['header' => "User-Agent: $ua\r\n", 'timeout' => 30]]);
    $bytes = @file_get_contents($url, false, $ctx);
    if ($bytes === false || strlen($bytes) < 128) return false;
    return file_put_contents($dest, $bytes) !== false;
}

function normalizeName(string $s): string {
    $s = mb_strtolower(trim($s), 'UTF-8');
    $s = preg_replace(
        '~(llc|s\.p\.c\.?|spc|sa[o]?g|saog|saoc|co\.?|company|group|ltd|limited|international|trading|gen(\.|eral)?)~u',
        '', $s
    );
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

// --- 2. Crawl paginated index ---
$entries = [];
for ($page = $START; ; $page++) {
    $url = $BASE . '?i=' . $page;
    echo "[seed] GET $url\n";
    $html = fetchHtml($url, $UA);
    if (!$html) { echo "[seed] page $page empty, stopping\n"; break; }
    $found = parseLogoEntries($html);
    if (!$found) { echo "[seed] page $page no logo imgs, stopping\n"; break; }
    foreach ($found as $e) $entries[] = $e;
    $report['pages']++;
    if ($LIMIT && count($entries) >= $LIMIT) break;
    usleep($CRAWL_DELAY_MS * 1000);
}
if ($LIMIT) $entries = array_slice($entries, 0, $LIMIT);
echo "[seed] total entries: " . count($entries) . "\n";

// --- 3. Fuzzy match existing om_companies ---
$allCompanies = $pdo->query(
    "SELECT id, name_en, name_ar, slug, website_domain_cache FROM om_companies"
)->fetchAll(PDO::FETCH_ASSOC);

foreach ($entries as $i => $e) {
    $best = ['score' => 0.0, 'company' => null];
    foreach ($allCompanies as $c) {
        $s1 = similarity($e['name'], $c['name_en']);
        $s2 = similarity($e['name'], $c['name_ar']);
        $s = max($s1, $s2);
        if ($s > $best['score']) $best = ['score' => $s, 'company' => $c];
    }
    $entries[$i]['match'] = $best;
    $report['scraped']++;
}

// --- 4. Persist ---
$sessionDir = dirname(__DIR__) . '/storage/logos/indexed/raw/' . date('Y-m-d_His');
if (!$DRY) @mkdir($sessionDir, 0755, true);

foreach ($entries as $e) {
    $src = resolveUrl($BASE, $e['src']);
    $ext = strtolower(pathinfo(parse_url($src, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));
    if (!in_array($ext, ['svg', 'png', 'jpg', 'jpeg', 'webp'], true)) continue;

    $score   = $e['match']['score'];
    $matched = $e['match']['company'];
    $decision = $score >= 0.90 ? 'auto_link' : ($score >= 0.75 ? 'queue' : 'new_row');

    if ($DRY) {
        printf("[seed] %-50s score=%.2f -> %s\n", substr($e['name'], 0, 50), $score, $decision);
        continue;
    }

    $companyId = null;
    if ($decision === 'auto_link') {
        $companyId = (int) $matched['id'];
        $report['auto_linked']++;
    } elseif ($decision === 'queue') {
        $companyId = (int) $matched['id'];
        $report['queued']++;
    } else {
        $slug = strtolower(preg_replace('~[^a-z0-9]+~i', '-', $e['name']));
        $slug = trim($slug, '-') ?: 'indexed-' . substr(md5($e['name']), 0, 8);
        $slug .= '-' . substr(md5($e['name']), 0, 4); // dedupe suffix
        try {
            $pdo->prepare("INSERT INTO om_companies (name_en, name_ar, slug, sector, wilayat, size_bucket, curated)
                           VALUES (:n, :n, :s, 'other', 'muscat', 'medium', 0)")
                ->execute([':n' => $e['name'], ':s' => $slug]);
            $companyId = (int) $pdo->lastInsertId();
            $report['new_rows']++;
        } catch (Throwable $t) {
            $report['errors'][] = "insert new row failed for {$e['name']}: " . $t->getMessage();
            continue;
        }
    }

    // Queued files go under pending/ so they can't overwrite a live
    // /storage/logos/indexed/{id}.{ext} asset on disk before admin review.
    // Admin match-queue.php previews from pending/, confirm promotes to indexed/,
    // reject deletes from pending/.
    $destRelDir = $decision === 'queue' ? "/storage/logos/pending" : "/storage/logos/indexed";
    $destAbsDir = dirname(__DIR__) . $destRelDir;
    @mkdir($destAbsDir, 0755, true);
    $destFile = "$destAbsDir/{$companyId}.$ext";
    if (!downloadFile($src, $destFile, $UA)) {
        $report['errors'][] = "download failed for {$e['name']} ($src)";
        continue;
    }

    // Normalize JPEG → PNG so downstream (imagecreatefrompng, image/png MIME) works.
    if (in_array($ext, ['jpg', 'jpeg'], true)) {
        $jpgImg = @imagecreatefromjpeg($destFile);
        if ($jpgImg) {
            $pngFile = "$destAbsDir/{$companyId}.png";
            imagepng($jpgImg, $pngFile, 9);
            imagedestroy($jpgImg);
            @unlink($destFile);
            $destFile = $pngFile;
            $ext = 'png';
        }
    }

    [$w, $h] = @getimagesize($destFile) ?: [null, null];
    $dom     = LogoLibrary::dominantColor($destFile);

    $isSvg   = $ext === 'svg';
    $isPng   = $ext === 'png';
    $isWebp  = $ext === 'webp';
    $isQueue = $decision === 'queue';

    // Queue (0.75–0.89 fuzzy match) → admin must confirm before going public.
    // Hub/API/sitemap filter on logo_status IN ('indexed','verified'), so we
    // leave unqueued rows at whatever state they were in. Files are still
    // stored + previewed on /admin/super/logos/match-queue.
    // CRITICAL: when queued, also preserve existing logo_*_path columns so
    // an unreviewed 2oman asset never overwrites a verified/indexed public logo.
    // Match-queue preview reads the file directly from /storage/logos/indexed/{id}.{ext}.
    // PDO emulate_prepares=false: each placeholder must be unique.
    $relPath = "$destRelDir/{$companyId}.$ext";
    $pdo->prepare("UPDATE om_companies SET
        logo_status          = IF(logo_status IN ('verified','takedown'), logo_status,
                                 IF(:is_queue = 1, logo_status, 'indexed')),
        logo_source          = '2oman_net',
        logo_source_url      = :su,
        logo_png_path        = IF(:is_png  = 1 AND :is_queue = 0, :rel_png,  logo_png_path),
        logo_svg_path        = IF(:is_svg  = 1 AND :is_queue = 0, :rel_svg,  logo_svg_path),
        logo_webp_path       = IF(:is_webp = 1 AND :is_queue = 0, :rel_webp, logo_webp_path),
        logo_width           = IF(:is_queue = 1, logo_width,           :w),
        logo_height          = IF(:is_queue = 1, logo_height,          :h),
        logo_dominant_color  = IF(:is_queue = 1, logo_dominant_color,  :c),
        logo_match_pending   = IF(:mp = 1, 1, logo_match_pending),
        logo_updated_at      = NOW()
        WHERE id = :id")
       ->execute([
           ':su'       => $src,
           ':is_png'   => $isPng  ? 1 : 0,
           ':is_svg'   => $isSvg  ? 1 : 0,
           ':is_webp'  => $isWebp ? 1 : 0,
           ':is_queue' => $isQueue ? 1 : 0,
           ':rel_png'  => $relPath,
           ':rel_svg'  => $relPath,
           ':rel_webp' => $relPath,
           ':w'        => $w,
           ':h'        => $h,
           ':c'        => $dom,
           ':mp'       => $decision === 'queue' ? 1 : 0,
           ':id'       => $companyId,
       ]);

    usleep($CRAWL_DELAY_MS * 1000);
}

// --- 5. Report ---
$report['finished_at'] = date('c');
$reportDir = dirname(__DIR__) . '/storage/logos/seed-reports';
@mkdir($reportDir, 0755, true);
$reportFile = $reportDir . '/' . date('Y-m-d_His') . '.json';
file_put_contents($reportFile, json_encode($report, JSON_PRETTY_PRINT));
echo "[seed] report: $reportFile\n";
echo json_encode($report, JSON_PRETTY_PRINT) . "\n";
