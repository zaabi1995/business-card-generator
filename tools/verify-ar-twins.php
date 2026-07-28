<?php
/**
 * Gate: the ArTwins map and the nginx /ar/ rewrite table must agree, exactly.
 *
 * The Arabic tree broke because three places decided it independently. This
 * gate covers the two that live in different repos-of-record (PHP map vs the
 * nginx rewrite file on the VPS) and fails on a mismatch in EITHER direction:
 *
 *   - a path in the map with no /ar/ rewrite  -> hreflang points at a 404
 *   - an /ar/ rewrite absent from the map     -> a live Arabic URL that no EN
 *                                                page links, no sitemap lists,
 *                                                and that still self-declares
 *                                                hreflang="en"
 *
 * Deliberately NOT an occurrence count: counting `/ar/` hits in the conf would
 * stay green while one rewrite silently pointed at the wrong PHP file. It
 * compares the two SETS by path.
 *
 * Usage:
 *   php tools/verify-ar-twins.php <path-to-nginx-rewrite-conf>
 *   # on the VPS: /www/server/panel/vhost/rewrite/cardify.om.conf
 *
 * Exit 0 = the sets match. Exit 1 = mismatch, with both diffs printed.
 */

require_once __DIR__ . '/../includes/ArTwins.php';

$conf = $argv[1] ?? '/www/server/panel/vhost/rewrite/cardify.om.conf';
if (!is_file($conf)) {
    fwrite(STDERR, "FAIL: rewrite conf not readable: {$conf}\n");
    exit(1);
}
$src = file_get_contents($conf);

// Collect the /ar/ paths nginx actually SERVES. Only single-segment hub
// rewrites, which is what the map covers; parameterised children such as
// ^/ar/companies/sector/([a-z0-9-]+)/?$ inherit their parent's language and
// are not separate twins.
//
// The trailing `last` is part of the match on purpose. A rule ending in
// `permanent` is a RETIREMENT (an /ar/ URL that served an English body, now
// 301'd to its canonical), not a twin; counting those would make every
// retirement look like an orphan Arabic URL and fail the gate for doing the
// right thing.
$live = [];
if (preg_match_all('#^\s*rewrite\s+\^/ar(/[a-z0-9-]*)?/\?\$\s+\S+\s+last\s*;#mi', $src, $m)) {
    foreach ($m[1] as $seg) {
        $live[] = ($seg === '' || $seg === null) ? '/' : $seg;
    }
}
$live = array_values(array_unique($live));
sort($live);

$mapped = ArTwins::paths();
sort($mapped);

$missingRewrite = array_values(array_diff($mapped, $live));
$missingMap     = array_values(array_diff($live, $mapped));

echo "ArTwins map:      " . count($mapped) . " paths\n";
echo "nginx /ar/ rules: " . count($live) . " paths\n";

$ok = true;
if ($missingRewrite) {
    $ok = false;
    echo "\nFAIL: in the map but NOT served by nginx (hreflang would point at a 404):\n";
    foreach ($missingRewrite as $p) echo "  /ar" . ($p === '/' ? '/' : $p) . "\n";
}
if ($missingMap) {
    $ok = false;
    echo "\nFAIL: served by nginx but NOT in the map (orphan Arabic URL, no inbound hreflang):\n";
    foreach ($missingMap as $p) echo "  /ar" . ($p === '/' ? '/' : $p) . "\n";
}

// Self-check: the gate must be able to fail. If the map is empty the
// comparison above is vacuously satisfiable, so refuse to report a pass.
if (!$mapped) {
    echo "\nFAIL: ArTwins map is empty, this gate would pass on anything.\n";
    $ok = false;
}

echo $ok ? "\nPASS: map and rewrite table agree on all " . count($mapped) . " paths.\n"
         : "\nGATE FAILED.\n";
exit($ok ? 0 : 1);
