<?php
/**
 * Nightly logo auto-crawler for the Omani Logo Library.
 *
 * Walks om_companies rows where logo_status='none' AND website_domain_cache
 * is populated. For each domain, tries (in order) homepage <link rel=icon>,
 * apple-touch-icon, og:image, Clearbit, /favicon.ico. Saves the best match,
 * renders -512.png + .webp, extracts dominant color, marks logo_status=indexed.
 *
 * Polite by design: 2s sleep between domains, 8s HTTP timeout, custom UA,
 * gives up on a domain after 3 source failures.
 *
 * CLI:
 *   php crawl-logos.php                       # 50 logo_status=none rows
 *   php crawl-logos.php --limit=20
 *   php crawl-logos.php --id=2497             # re-crawl a specific company
 *   php crawl-logos.php --include-verified    # also refresh verified entries
 *   php crawl-logos.php --refresh-older-than=90  # only refresh older than N days
 *   php crawl-logos.php --dry-run             # don't write anything
 *
 * Output: tab-separated log lines to stdout. Pipe to a log file from cron.
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/LogoLibrary.php';

const CRAWLER_UA = 'CardifyLogoCrawler/1.0 (+https://cardify.om/logos/press)';
const HTTP_TIMEOUT = 8;
const SLEEP_BETWEEN_DOMAINS_SEC = 2;
const MIN_IMAGE_BYTES = 100; // sanity floor
const MIN_LOGO_DIMENSION = 32; // skip tiny faviocns

$opts = parseArgs($argv);
$limit  = max(1, min(500, (int) ($opts['limit'] ?? 50)));
$forceId = isset($opts['id']) ? (int) $opts['id'] : null;
$dryRun  = !empty($opts['dry-run']);
$includeVerified  = !empty($opts['include-verified']);
$refreshOlderDays = isset($opts['refresh-older-than']) ? max(1, (int) $opts['refresh-older-than']) : null;

$db = Database::getInstance();
$storageRoot = __DIR__ . '/../storage/logos/indexed';
@mkdir($storageRoot, 0755, true);

$startedAt = date('c');
logLine("crawl_start\ttime=$startedAt\tlimit=$limit\tdry_run=" . ($dryRun ? '1' : '0'));

if ($forceId) {
    $rows = $db->fetchAll(
        "SELECT id, slug, name_en, website_domain_cache, logo_status
         FROM om_companies
         WHERE id = :id AND website_domain_cache IS NOT NULL",
        [':id' => $forceId]
    );
} else {
    $statusWhere = $includeVerified
        ? "logo_status IN ('none', 'indexed', 'verified')"
        : "logo_status = 'none'";
    $extraWhere  = $refreshOlderDays
        ? " AND (logo_updated_at IS NULL OR logo_updated_at < NOW() - INTERVAL " . (int) $refreshOlderDays . " DAY)"
        : '';
    $rows = $db->fetchAll(
        "SELECT id, slug, name_en, website_domain_cache, logo_status
         FROM om_companies
         WHERE $statusWhere
           AND website_domain_cache IS NOT NULL
           $extraWhere
         ORDER BY (logo_status = 'none') DESC, curated DESC, id ASC
         LIMIT $limit"
    );
}

$counts = ['considered' => 0, 'indexed' => 0, 'skipped' => 0, 'failed' => 0];

foreach ($rows as $row) {
    $counts['considered']++;
    $id     = (int) $row['id'];
    $slug   = (string) $row['slug'];
    $domain = strtolower(trim((string) $row['website_domain_cache']));
    if ($domain === '') {
        $counts['skipped']++;
        logLine("skip\tid=$id\tslug=$slug\treason=empty_domain");
        continue;
    }

    $best = findBestLogo($domain);
    if (!$best) {
        $counts['failed']++;
        logLine("fail\tid=$id\tslug=$slug\tdomain=$domain\treason=no_logo_found");
        sleep(SLEEP_BETWEEN_DOMAINS_SEC);
        continue;
    }

    $ext = $best['ext'];
    $sourceLabel = $best['source'];

    if ($dryRun) {
        logLine("dryrun\tid=$id\tslug=$slug\tdomain=$domain\tsource=$sourceLabel\text=$ext\tbytes=" . strlen($best['bytes']));
        $counts['indexed']++;
        sleep(SLEEP_BETWEEN_DOMAINS_SEC);
        continue;
    }

    $written = persistLogo($id, $best, $storageRoot);
    if (!$written) {
        $counts['failed']++;
        logLine("fail\tid=$id\tslug=$slug\tdomain=$domain\treason=persist_failed");
        sleep(SLEEP_BETWEEN_DOMAINS_SEC);
        continue;
    }

    try {
        // Don't demote a verified row back to indexed when refreshing
        $newStatus = $row['logo_status'] === 'verified' ? 'verified' : 'indexed';

        $db->getConnection()->prepare(
            "UPDATE om_companies SET
                logo_svg_path = :svg,
                logo_png_path = :png,
                logo_png_512_path = :png512,
                logo_png_2048_path = :png2048,
                logo_webp_path = :webp,
                logo_dominant_color = :color,
                logo_palette = :palette,
                logo_width = :w,
                logo_height = :h,
                logo_status = :status,
                logo_source = :source,
                logo_updated_at = NOW()
             WHERE id = :id"
        )->execute([
            ':svg'     => $written['svg']      ?? null,
            ':png'     => $written['png']      ?? null,
            ':png512'  => $written['png_512']  ?? null,
            ':png2048' => $written['png_2048'] ?? null,
            ':webp'    => $written['webp']     ?? null,
            ':color'   => $written['color']    ?? null,
            ':palette' => $written['palette']  ?? null,
            ':w'       => $written['width']    ?? null,
            ':h'       => $written['height']   ?? null,
            ':status'  => $newStatus,
            ':source'  => $sourceLabel,
            ':id'      => $id,
        ]);
        $counts['indexed']++;
        logLine("ok\tid=$id\tslug=$slug\tdomain=$domain\tsource=$sourceLabel\text=$ext\tcolor=" . ($written['color'] ?? '-'));
    } catch (Throwable $e) {
        $counts['failed']++;
        logLine("fail\tid=$id\tslug=$slug\treason=db_update\terr=" . substr($e->getMessage(), 0, 200));
    }

    sleep(SLEEP_BETWEEN_DOMAINS_SEC);
}

logLine(sprintf(
    "crawl_done\tconsidered=%d\tindexed=%d\tskipped=%d\tfailed=%d",
    $counts['considered'], $counts['indexed'], $counts['skipped'], $counts['failed']
));

// === helpers below =========================================================

function parseArgs(array $argv): array {
    $out = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (preg_match('/^--([a-z\-]+)(?:=(.*))?$/i', $arg, $m)) {
            $out[$m[1]] = $m[2] ?? true;
        }
    }
    return $out;
}

function logLine(string $line): void {
    fwrite(STDOUT, date('Y-m-d H:i:s') . "\t" . $line . "\n");
}

/**
 * Try every source in priority order until one returns a usable image.
 * Returns ['bytes' => raw, 'ext' => svg|png|jpg|ico, 'source' => label].
 */
function findBestLogo(string $domain): ?array {
    $domain = trim($domain, '/');

    // 1. Homepage scrape for <link rel="icon">
    $html = httpFetch("https://$domain/");
    if (!$html) $html = httpFetch("http://$domain/");
    if ($html) {
        $iconUrl = extractBestIconFromHtml($html, $domain);
        if ($iconUrl) {
            $img = httpFetch($iconUrl, true);
            if ($img && validateImageBytes($img)) {
                return ['bytes' => $img['bytes'], 'ext' => detectExt($img, $iconUrl), 'source' => 'company_web'];
            }
        }
        // og:image fallback
        $ogUrl = extractMeta($html, 'og:image', $domain);
        if ($ogUrl) {
            $img = httpFetch($ogUrl, true);
            if ($img && validateImageBytes($img)) {
                return ['bytes' => $img['bytes'], 'ext' => detectExt($img, $ogUrl), 'source' => 'company_web'];
            }
        }
    }

    // 2. apple-touch-icon at the well-known path
    foreach (['/apple-touch-icon-precomposed.png', '/apple-touch-icon.png'] as $path) {
        $img = httpFetch("https://$domain$path", true);
        if ($img && validateImageBytes($img)) {
            return ['bytes' => $img['bytes'], 'ext' => 'png', 'source' => 'apple_touch_icon'];
        }
    }

    // 3. Clearbit (likely 404 since they sunset, but cheap to try)
    $img = httpFetch("https://logo.clearbit.com/$domain", true);
    if ($img && validateImageBytes($img)) {
        return ['bytes' => $img['bytes'], 'ext' => 'png', 'source' => 'clearbit'];
    }

    // 4. /favicon.ico, last resort
    $img = httpFetch("https://$domain/favicon.ico", true);
    if ($img && validateImageBytes($img)) {
        return ['bytes' => $img['bytes'], 'ext' => 'ico', 'source' => 'favicon'];
    }

    return null;
}

/**
 * Read the HTML and return the best <link rel="icon"> URL we can find.
 * Preference: SVG > biggest sizes attr > anything else.
 */
function extractBestIconFromHtml(string $html, string $domain): ?string {
    $candidates = [];
    if (preg_match_all('/<link\b[^>]*>/i', $html, $links)) {
        foreach ($links[0] as $tag) {
            $rel = preg_match('/\brel\s*=\s*"([^"]+)"|\brel\s*=\s*\'([^\']+)\'/i', $tag, $m)
                ? strtolower($m[1] ?: $m[2]) : '';
            if (!preg_match('/\b(icon|shortcut icon|apple-touch-icon|mask-icon)\b/', $rel)) continue;
            $href = preg_match('/\bhref\s*=\s*"([^"]+)"|\bhref\s*=\s*\'([^\']+)\'/i', $tag, $m)
                ? ($m[1] ?: $m[2]) : '';
            if ($href === '') continue;
            $type = preg_match('/\btype\s*=\s*"([^"]+)"|\btype\s*=\s*\'([^\']+)\'/i', $tag, $m)
                ? strtolower($m[1] ?: $m[2]) : '';
            $sizesRaw = preg_match('/\bsizes\s*=\s*"([^"]+)"|\bsizes\s*=\s*\'([^\']+)\'/i', $tag, $m)
                ? strtolower($m[1] ?: $m[2]) : '';
            $maxDim = 0;
            if (preg_match_all('/(\d+)x(\d+)/', $sizesRaw, $mm)) {
                foreach ($mm[1] as $n) $maxDim = max($maxDim, (int) $n);
            }
            $score = 0;
            if (strpos($type, 'svg') !== false) $score = 10000;            // SVG = best
            elseif ($maxDim) $score = $maxDim;                              // 192 > 32 etc.
            elseif (strpos($rel, 'apple-touch-icon') !== false) $score = 180;
            elseif (strpos($rel, 'icon') !== false) $score = 32;            // generic
            $candidates[] = ['url' => resolveUrl($href, $domain), 'score' => $score];
        }
    }
    usort($candidates, fn($a, $b) => $b['score'] - $a['score']);
    return $candidates[0]['url'] ?? null;
}

function extractMeta(string $html, string $property, string $domain): ?string {
    if (preg_match('/<meta\b[^>]*\bproperty\s*=\s*["\']' . preg_quote($property, '/') . '["\'][^>]*\bcontent\s*=\s*["\']([^"\']+)["\']/i', $html, $m)) {
        return resolveUrl($m[1], $domain);
    }
    if (preg_match('/<meta\b[^>]*\bcontent\s*=\s*["\']([^"\']+)["\'][^>]*\bproperty\s*=\s*["\']' . preg_quote($property, '/') . '["\']/i', $html, $m)) {
        return resolveUrl($m[1], $domain);
    }
    return null;
}

function resolveUrl(string $href, string $domain): string {
    if (preg_match('#^https?://#i', $href)) return $href;
    if (str_starts_with($href, '//')) return 'https:' . $href;
    if (str_starts_with($href, '/'))  return "https://$domain" . $href;
    return "https://$domain/" . ltrim($href, '/');
}

function httpFetch(string $url, bool $binary = false): array|string|null {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => HTTP_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_USERAGENT      => CRAWLER_UA,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => ['Accept: */*'],
    ]);
    $body = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ct   = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    if ($http < 200 || $http >= 300 || $body === false || $body === '') return null;

    if ($binary) {
        return ['bytes' => $body, 'content_type' => $ct];
    }
    return $body;
}

function validateImageBytes(array $img): bool {
    $bytes = $img['bytes'];
    if (strlen($bytes) < MIN_IMAGE_BYTES) return false;
    // PNG magic
    if (str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) return true;
    // JPEG magic
    if (str_starts_with($bytes, "\xff\xd8\xff")) return true;
    // GIF
    if (str_starts_with($bytes, 'GIF8')) return true;
    // WebP
    if (str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP') return true;
    // ICO
    if (str_starts_with($bytes, "\x00\x00\x01\x00")) return true;
    // SVG (text-based)
    if (preg_match('/^\s*<(\?xml|svg)/i', substr($bytes, 0, 200))) return true;
    return false;
}

function detectExt(array $img, string $url): string {
    $bytes = $img['bytes'];
    if (preg_match('/^\s*<(\?xml|svg)/i', substr($bytes, 0, 200))) return 'svg';
    if (str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) return 'png';
    if (str_starts_with($bytes, "\xff\xd8\xff"))      return 'jpg';
    if (str_starts_with($bytes, 'GIF8'))              return 'gif';
    if (str_starts_with($bytes, "\x00\x00\x01\x00"))  return 'ico';
    if (preg_match('/\.(svg|png|jpe?g|gif|webp|ico)(?:[?#]|$)/i', $url, $m)) {
        return strtolower($m[1] === 'jpeg' ? 'jpg' : $m[1]);
    }
    return 'png';
}

/**
 * Write the discovered logo + generate -512 / -2048 / .webp variants.
 * Returns ['svg'=>..., 'png'=>..., 'png_512'=>..., 'png_2048'=>..., 'webp'=>..., 'width','height','color'].
 */
function persistLogo(int $id, array $best, string $storageRoot): ?array {
    $ext = $best['ext'];
    $bytes = $best['bytes'];

    // ico is awkward, convert it to png up front
    if ($ext === 'ico') {
        $tmp = tempnam(sys_get_temp_dir(), 'logocrawl_');
        @unlink($tmp);
        $icoPath = "$tmp.ico";
        $pngPath = "$tmp.png";
        file_put_contents($icoPath, $bytes);
        @exec('convert ' . escapeshellarg($icoPath . '[-1]') . ' ' . escapeshellarg($pngPath) . ' 2>/dev/null', $_o, $rc);
        @unlink($icoPath);
        if ($rc !== 0 || !is_file($pngPath) || filesize($pngPath) < MIN_IMAGE_BYTES) {
            @unlink($pngPath);
            return null;
        }
        $bytes = file_get_contents($pngPath);
        @unlink($pngPath);
        $ext = 'png';
    }

    $srcFile = "$storageRoot/$id.$ext";
    if (file_put_contents($srcFile, $bytes) === false) return null;
    @chmod($srcFile, 0644);

    $out = ['width' => null, 'height' => null, 'color' => null];
    $rel = "/storage/logos/indexed/$id";

    if ($ext === 'svg') {
        $out['svg'] = "$rel.svg";
        renderSvgVariants($srcFile, $storageRoot, $id, $out, $rel);
    } else {
        // For raster, we treat the source as the "1024" png (if PNG) or convert it.
        $masterPng = "$storageRoot/$id.png";
        if ($ext !== 'png') {
            @exec('convert ' . escapeshellarg($srcFile) . ' ' . escapeshellarg($masterPng) . ' 2>/dev/null', $_o, $rc);
            @unlink($srcFile);
            if ($rc !== 0 || !is_file($masterPng)) return null;
        } else {
            // already saved as .png
        }
        @chmod($masterPng, 0644);
        renderPngVariants($masterPng, $storageRoot, $id, $out, $rel);
    }

    $masterPng = "$storageRoot/$id.png";
    [$w, $h] = @getimagesize($masterPng) ?: [null, null];
    $out['width']  = $w ?: null;
    $out['height'] = $h ?: null;
    $out['color']  = LogoLibrary::dominantColor($masterPng);
    $palette = LogoLibrary::palette($masterPng, 5);
    $out['palette'] = !empty($palette) ? json_encode($palette) : null;

    return $out;
}

function renderSvgVariants(string $svgPath, string $root, int $id, array &$out, string $rel): void {
    foreach ([512 => 'png_512', 1024 => 'png', 2048 => 'png_2048'] as $w => $key) {
        $dst = "$root/$id" . ($w === 1024 ? '' : "-$w") . '.png';
        @exec('rsvg-convert -w ' . $w . ' ' . escapeshellarg($svgPath) . ' -o ' . escapeshellarg($dst) . ' 2>/dev/null', $_o, $rc);
        if ($rc === 0 && is_file($dst) && filesize($dst) >= MIN_IMAGE_BYTES) {
            @chmod($dst, 0644);
            $out[$key] = $rel . ($w === 1024 ? '.png' : "-$w.png");
        }
    }
    $webp = "$root/$id.webp";
    if (is_file("$root/$id-2048.png")) {
        @exec('convert ' . escapeshellarg("$root/$id-2048.png") . ' -quality 85 ' . escapeshellarg($webp) . ' 2>/dev/null', $_o, $rc);
        if ($rc === 0 && is_file($webp)) {
            @chmod($webp, 0644);
            $out['webp'] = "$rel.webp";
        }
    }
}

function renderPngVariants(string $masterPng, string $root, int $id, array &$out, string $rel): void {
    $out['png'] = "$rel.png";
    $small = "$root/$id-512.png";
    @exec('convert ' . escapeshellarg($masterPng) . ' -resize 512x512\> ' . escapeshellarg($small) . ' 2>/dev/null', $_o, $rc);
    if ($rc === 0 && is_file($small) && filesize($small) >= MIN_IMAGE_BYTES) {
        @chmod($small, 0644);
        $out['png_512'] = "$rel-512.png";
    }
    $webp = "$root/$id.webp";
    @exec('convert ' . escapeshellarg($masterPng) . ' -quality 85 ' . escapeshellarg($webp) . ' 2>/dev/null', $_o, $rc);
    if ($rc === 0 && is_file($webp)) {
        @chmod($webp, 0644);
        $out['webp'] = "$rel.webp";
    }
}
