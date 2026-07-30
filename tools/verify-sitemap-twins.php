<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * Gate: every path in ArTwins::PATHS must appear in a LIVE sitemap, in BOTH
 * languages.
 *
 * verify-ar-twins.php already proves the map and the nginx rewrite table agree,
 * so an /ar/ URL always exists and always has an inbound hreflang. Neither
 * gate said anything about the sitemap, and four paths quietly fell through
 * that hole: /pricing, /case-studies, /changelog and /status were routed,
 * translated, hreflang-tagged, and listed in no sitemap at all in either
 * language. /pricing is the highest commercial-intent page on the site.
 *
 * This reads the RENDERED sitemaps over HTTP rather than parsing the
 * $staticPages array in sitemap.php. A gate that reads the input array would
 * stay green if the array were right and the emitter dropped the entry, which
 * is the failure that actually matters. It also means the gate measures what
 * Google is served, cache and rewrites included.
 *
 * Usage:
 *   php tools/verify-sitemap-twins.php [https://cardify.om]
 *
 * Exit 0 = every twin pair is sitemapped. Exit 1 = at least one is missing.
 */

require_once __DIR__ . '/../includes/ArTwins.php';

$base = rtrim($argv[1] ?? ArTwins::SITE, '/');

function fetch(string $url): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_USERAGENT      => 'cardify-sitemap-twin-gate',
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $eff  = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    if ($body === false || $code !== 200) {
        fwrite(STDERR, "FAIL: {$eff} returned {$code}\n");
        exit(1);
    }
    return (string) $body;
}

// Walk the index, then every child urlset, and collect every <loc> path.
$index = fetch($base . '/sitemap.xml');
preg_match_all('#<loc>([^<]+)</loc>#', $index, $m);
$children = $m[1] ?? [];
if (!$children) {
    fwrite(STDERR, "FAIL: sitemap.xml listed no child sitemaps, this gate would pass on anything.\n");
    exit(1);
}

$locs = [];
foreach ($children as $child) {
    preg_match_all('#<loc>([^<]+)</loc>#', fetch($child), $cm);
    foreach ($cm[1] as $loc) {
        $p = parse_url($loc, PHP_URL_PATH);
        if ($p !== null && $p !== false) $locs[rtrim($p, '/') ?: '/'] = true;
    }
}

echo "child sitemaps:   " . count($children) . "\n";
echo "sitemapped URLs:  " . count($locs) . "\n";

$missing = [];
foreach (ArTwins::paths() as $en) {
    foreach ([ArTwins::en($en), ArTwins::ar($en)] as $u) {
        if ($u === null) continue;
        $p = rtrim(parse_url($u, PHP_URL_PATH), '/') ?: '/';
        if (!isset($locs[$p])) $missing[] = $p;
    }
}

$ok = true;
if ($missing) {
    $ok = false;
    echo "\nFAIL: in ArTwins::PATHS but in no sitemap (Google is never told the URL exists):\n";
    foreach (array_unique($missing) as $p) echo "  {$p}\n";
}

// Self-falsification. If the collected set were empty, or if lookups always
// succeeded, the loop above would be vacuous. Prove the check can fail.
if (!$locs || !ArTwins::paths()) {
    echo "\nFAIL: nothing to compare, this gate would pass on anything.\n";
    $ok = false;
}
if (isset($locs['/this-path-does-not-exist-sentinel'])) {
    echo "\nFAIL: sentinel path present, the <loc> parser is matching everything.\n";
    $ok = false;
}

echo $ok ? "\nPASS: all " . count(ArTwins::paths()) . " twin pairs are sitemapped.\n"
         : "\nGATE FAILED.\n";
exit($ok ? 0 : 1);
