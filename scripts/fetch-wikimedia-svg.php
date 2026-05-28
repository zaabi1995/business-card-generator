<?php
/**
 * Fetch a real SVG logo from Wikimedia Commons for one company, by id.
 * SVG-only library policy: this is how marquee brands get a crisp vector
 * logo when their own website doesn't expose a fetchable .svg.
 *
 * Work logo-by-logo and ALWAYS review the rendered result:
 *   php fetch-wikimedia-svg.php --id=15 --query="Omantel" --dry-run
 *   php fetch-wikimedia-svg.php --id=15 --file="Omantel.svg"        # exact file
 *   php fetch-wikimedia-svg.php --id=15 --file="Omantel.svg" --apply
 *
 * --dry-run lists candidate File: titles. --file pins an exact one.
 * --apply downloads it, stores as the company's SVG, renders variants,
 * marks logo_status=indexed, logo_source='wikimedia'.
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/LogoLibrary.php';

const WM_UA = 'CardifyLogoBot/1.0 (https://cardify.om/logos; logos@cardify.om)';

$opts = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z\-]+)(?:=(.*))?$/i', $a, $m)) $opts[$m[1]] = $m[2] ?? true;
}
$id    = (int) ($opts['id'] ?? 0);
$query = (string) ($opts['query'] ?? '');
$file  = (string) ($opts['file'] ?? '');
$apply = !empty($opts['apply']);
$dry   = !$apply;

if (!$id) { fwrite(STDERR, "need --id\n"); exit(1); }

$db = Database::getInstance();
$company = $db->fetchOne("SELECT id, slug, name_en FROM om_companies WHERE id = :id", [':id' => $id]);
if (!$company) { fwrite(STDERR, "no company id=$id\n"); exit(1); }
if ($query === '' && $file === '') $query = $company['name_en'];

// Resolve the File: title (either pinned via --file or searched via --query)
$fileTitle = $file !== '' ? (str_starts_with($file, 'File:') ? $file : "File:$file") : null;
if ($fileTitle === null) {
    $search = wmGet([
        'action' => 'query', 'list' => 'search', 'format' => 'json',
        'srsearch' => $query . ' logo filetype:svg', 'srnamespace' => 6, 'srlimit' => 6,
    ]);
    $hits = $search['query']['search'] ?? [];
    if (empty($hits)) { echo "no_results\tid=$id\tquery=$query\n"; exit(0); }
    echo "candidates for id=$id ($company[name_en]):\n";
    foreach ($hits as $h) echo "  - " . $h['title'] . "\n";
    if ($dry) { echo "(dry-run; pin one with --file=\"...\" --apply)\n"; exit(0); }
    $fileTitle = $hits[0]['title']; // apply with no --file: take top hit
    echo "auto-picked: $fileTitle\n";
}

// Get the actual upload URL for the File:
$info = wmGet([
    'action' => 'query', 'titles' => $fileTitle, 'prop' => 'imageinfo',
    'iiprop' => 'url|mime', 'format' => 'json',
]);
$pages = $info['query']['pages'] ?? [];
$page  = $pages ? reset($pages) : null;
$url   = $page['imageinfo'][0]['url'] ?? null;
$mime  = $page['imageinfo'][0]['mime'] ?? '';
if (!$url || stripos($mime, 'svg') === false) {
    echo "not_svg\tid=$id\tfile=$fileTitle\tmime=$mime\n";
    exit(0);
}
echo "svg_url\tid=$id\tfile=$fileTitle\turl=$url\n";

if ($dry) { echo "(dry-run; add --apply to download + store)\n"; exit(0); }

// Download the SVG
$svg = httpGet($url);
if ($svg === null || !preg_match('/<svg[\s>]/i', $svg)) {
    echo "download_failed\tid=$id\n"; exit(1);
}

$root = realpath(__DIR__ . '/..');
$dst  = "$root/storage/logos/indexed/{$id}.svg";
if (file_put_contents($dst, $svg) === false) { echo "write_failed\n"; exit(1); }
@chmod($dst, 0644);

// Render PNG + variants from the SVG, extract palette/color
$rel = "/storage/logos/indexed/{$id}";
$out = ['svg' => "$rel.svg"];
foreach ([512 => 'png_512', 1024 => 'png', 2048 => 'png_2048'] as $w => $key) {
    $pngDst = "$root/storage/logos/indexed/{$id}" . ($w === 1024 ? '' : "-$w") . '.png';
    @exec('rsvg-convert -w ' . $w . ' ' . escapeshellarg($dst) . ' -o ' . escapeshellarg($pngDst) . ' 2>/dev/null', $_o, $rc);
    if ($rc === 0 && is_file($pngDst)) {
        LogoLibrary::trimRasterFile($pngDst);
        @chmod($pngDst, 0644);
        $out[$key] = $rel . ($w === 1024 ? '.png' : "-$w.png");
    }
}
$masterPng = "$root/storage/logos/indexed/{$id}.png";
$webp = "$root/storage/logos/indexed/{$id}.webp";
if (is_file($masterPng)) {
    @exec('convert ' . escapeshellarg($masterPng) . ' -quality 90 ' . escapeshellarg($webp) . ' 2>/dev/null', $_o, $rc);
    if ($rc === 0 && is_file($webp)) { LogoLibrary::trimRasterFile($webp); @chmod($webp, 0644); $out['webp'] = "$rel.webp"; }
}
[$mw, $mh] = is_file($masterPng) ? (@getimagesize($masterPng) ?: [null, null]) : [null, null];
$color   = is_file($masterPng) ? LogoLibrary::dominantColor($masterPng) : null;
$palette = is_file($masterPng) ? LogoLibrary::palette($masterPng, 5) : [];

$db->getConnection()->prepare(
    "UPDATE om_companies SET
        logo_svg_path=:svg, logo_png_path=:png, logo_png_512_path=:p512, logo_png_2048_path=:p2048,
        logo_webp_path=:webp, logo_dominant_color=:color, logo_palette=:pal,
        logo_width=:w, logo_height=:h, logo_status='indexed', logo_source='wikimedia',
        logo_source_url=:srcurl, logo_updated_at=NOW()
     WHERE id=:id"
)->execute([
    ':svg' => $out['svg'], ':png' => $out['png'] ?? null, ':p512' => $out['png_512'] ?? null,
    ':p2048' => $out['png_2048'] ?? null, ':webp' => $out['webp'] ?? null,
    ':color' => $color, ':pal' => $palette ? json_encode($palette) : null,
    ':w' => $mw ?: null, ':h' => $mh ?: null, ':srcurl' => $url, ':id' => $id,
]);

// Monochrome dark/white variants
try { LogoLibrary::generateMonochromeVariants($id); } catch (Throwable $e) {}

echo "OK\tid=$id\tslug={$company['slug']}\tfile=$fileTitle\tcolor=" . ($color ?? '-') . "\n";

// === helpers ===
function wmGet(array $params): array {
    $url = 'https://commons.wikimedia.org/w/api.php?' . http_build_query($params);
    $body = httpGet($url);
    if ($body === null) return [];
    $j = json_decode($body, true);
    return is_array($j) ? $j : [];
}
function httpGet(string $url): ?string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_USERAGENT => WM_UA,
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($code >= 200 && $code < 300 && is_string($body) && $body !== '') ? $body : null;
}
