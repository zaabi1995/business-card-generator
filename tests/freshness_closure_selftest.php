<?php
/**
 * r250, bhd-r6-95 change of approach #65.
 *
 * The bug this pins: a composed page dated by ONE file's mtime publishes a date
 * older than its own bytes the moment a shared include is rewritten. Arms drive
 * the two pure functions directly, so nothing here needs a request, a database
 * or the network.
 *
 * Run: php tests/freshness_closure_selftest.php     (exit 0 = all arms pass)
 */
require_once __DIR__ . '/../includes/Freshness.php';

$root = sys_get_temp_dir() . '/freshness_closure_' . getmypid();
@mkdir($root . '/includes', 0777, true);
@mkdir($root . '/vendor/lib', 0777, true);

$page    = $root . '/about.php';
$footer  = $root . '/includes/ui-footer.php';
$header  = $root . '/includes/ui-header.php';
$config  = $root . '/config.php';
$vendor  = $root . '/vendor/lib/thing.php';
$outside = sys_get_temp_dir() . '/freshness_outside_' . getmypid() . '.php';

foreach ([$page, $footer, $header, $config, $vendor, $outside] as $f) {
    file_put_contents($f, "<?php\n");
}

$d = static fn(string $ymd): int => (int) strtotime($ymd . ' 12:00:00 UTC');

// The exact production shape measured on 15 Aug 2026.
touch($page,   $d('2026-08-05'));
touch($header, $d('2026-08-12'));
touch($footer, $d('2026-08-12'));
touch($config, $d('2026-08-14'));   // credentials touched later: must not date
touch($vendor, $d('2026-08-13'));   // third party: must not date
touch($outside, $d('2026-08-30'));  // another tree entirely: must not date

// The instrument itself: in every closure, and must never date a page.
$self = $root . '/includes/Freshness.php';
file_put_contents($self, "<?php\n");
touch($self, $d('2026-08-15'));   // deployed today

$closure = [$page, $header, $footer, $config, $vendor, $outside, $self];
$kept    = Freshness::keepRendering($closure, $root);
$newest  = Freshness::newestMtime($kept);

// A page whose whole closure is unreadable must decide nothing, not "today".
$nothing = Freshness::newestMtime([$root . '/does-not-exist.php']);

// A page nobody has touched since its own edit must keep its own date, i.e.
// the closure must not invent movement where there was none.
$quietRoot = $root . '/quiet';
@mkdir($quietRoot . '/includes', 0777, true);
$qPage = $quietRoot . '/solo.php';
$qInc  = $quietRoot . '/includes/inc.php';
file_put_contents($qPage, "<?php\n");
file_put_contents($qInc, "<?php\n");
touch($qPage, $d('2026-07-01'));
touch($qInc,  $d('2026-06-01'));
$quiet = Freshness::newestMtime(Freshness::keepRendering([$qPage, $qInc], $quietRoot));

// r231's calendar rule: a declared row date is a Muscat wall-clock instant.
date_default_timezone_set('Asia/Muscat');
$rowNight = (int) strtotime('2026-05-31 02:00:00 +04:00');   // 30 May 22:00 UTC
$GLOBALS['pageContentDate'] = $rowNight;
$declaredIso = Freshness::isoDate();
unset($GLOBALS['pageContentDate']);

$arms = [
    'a 02:00 Muscat row keeps its own day (r231: date(), not gmdate())'
        => $declaredIso === '2026-05-31' && gmdate('Y-m-d', $rowNight) === '2026-05-30',
    'the newest RENDERING file dates the page (12th, not the 5th)'
        => $newest === $d('2026-08-12'),
    'the page source alone would have published the stale date'
        => Freshness::newestMtime([$page]) === $d('2026-08-05'),
    'deploying Freshness.php itself does not re-date the estate'
        => !in_array($self, $kept, true) && $newest === $d('2026-08-12'),
    'config.php renders nothing, so touching it does not re-date'
        => !in_array($config, $kept, true),
    'vendor/ renders nothing, so touching it does not re-date'
        => !in_array($vendor, $kept, true),
    'a file outside the site root is never a dependency'
        => !in_array($outside, $kept, true),
    'the page and its real includes ARE kept'
        => in_array($page, $kept, true)
           && in_array($header, $kept, true)
           && in_array($footer, $kept, true),
    'duplicates in the closure collapse'
        => count(Freshness::keepRendering([$page, $page, $footer], $root)) === 2,
    'an unreadable closure decides nothing (null, never 0 and never today)'
        => $nothing === null,
    'a quiet page keeps its own older date, movement is not invented'
        => $quiet === $d('2026-07-01'),
    'a declared row date wins over the closure (epoch)'
        => Freshness::declaredTimestamp($d('2026-05-28')) === $d('2026-05-28'),
    'a declared row date wins over the closure (Y-m-d string)'
        => Freshness::declaredTimestamp('2026-05-28') === $d('2026-05-28') - 12 * 3600,
    'garbage is not a date: null, never today'
        => Freshness::declaredTimestamp('yesterday') === null
           && Freshness::declaredTimestamp('') === null
           && Freshness::declaredTimestamp(0) === null
           && Freshness::declaredTimestamp(null) === null,
];

// ---------------------------------------------------------------------------
// r252, CHANGE OF APPROACH #66: the closure is keyed to the ROUTE, so the
// channel that is NOT inside the request (sitemap.php) computes the same
// answer. These arms drive routeFiles()/routeTimestamp() over a tree with real
// require statements, from outside any request.
// ---------------------------------------------------------------------------
$r2 = sys_get_temp_dir() . '/freshness_route_' . getmypid();
@mkdir($r2 . '/includes', 0777, true);
@mkdir($r2 . '/lang/en', 0777, true);
@mkdir($r2 . '/lang/ar', 0777, true);

$rPage   = $r2 . '/about.php';
$rHeader = $r2 . '/includes/ui-header.php';
$rDeep   = $r2 . '/includes/deep.php';        // reached only THROUGH the header
$rSelf   = $r2 . '/includes/Freshness.php';   // the instrument, in every closure
$rLangEn = $r2 . '/lang/en/about.php';
$rLangAr = $r2 . '/lang/ar/about.php';

file_put_contents($rPage,
    "<?php require_once __DIR__ . '/includes/ui-header.php';\n"
  . "require_once INCLUDES_DIR . '/Freshness.php';\n"
  . "echo t('about.title');\n");
file_put_contents($rHeader, "<?php require_once INCLUDES_DIR . '/deep.php';\n");
file_put_contents($rDeep,   "<?php\n");
file_put_contents($rSelf,   "<?php\n");
file_put_contents($rLangEn, "<?php return [];\n");
file_put_contents($rLangAr, "<?php return [];\n");

touch($rPage,   $d('2026-08-05'));
touch($rHeader, $d('2026-08-09'));
touch($rDeep,   $d('2026-08-12'));   // the newest RENDERING byte, two hops down
touch($rSelf,   $d('2026-08-20'));   // newer than everything, must not count
touch($rLangEn, $d('2026-08-07'));
touch($rLangAr, $d('2026-08-08'));

$routeFiles = Freshness::routeFiles($rPage, $r2);
$routeTs    = Freshness::routeTimestamp($rPage, $r2);
$rel = static function (array $files) use ($r2): array {
    return array_map(static fn($f) => substr($f, strlen($r2)), $files);
};
$names = $rel($routeFiles);

$arms2 = [
    'the walk reaches a file the page never names, two hops down'
        => in_array('/includes/deep.php', $names, true),
    'INCLUDES_DIR resolves the same as __DIR__ does'
        => in_array('/includes/ui-header.php', $names, true),
    'a lang namespace named by t() is part of the route'
        => in_array('/lang/en/about.php', $names, true),
    'both language editions of a route are one claim (twin rule)'
        => in_array('/lang/ar/about.php', $names, true),
    'the instrument is still excluded when the walk finds it by require'
        => !in_array('/includes/Freshness.php', $names, true),
    'the route dates from its newest rendering byte, from OUTSIDE a request'
        => $routeTs === $d('2026-08-12'),
    'and that is NOT what the page file alone says: the pre-r252 sitemap answer'
        => (int) filemtime($rPage) === $d('2026-08-05') && $routeTs !== (int) filemtime($rPage),
    'a renderer that does not exist decides nothing, never today'
        => Freshness::routeTimestamp($r2 . '/no-such-page.php', $r2) === null,
];

foreach ($arms2 as $k => $v) { $arms[$k] = $v; }

$fail = 0;
foreach ($arms as $name => $ok) {
    echo ($ok ? '  ok   ' : '  FAIL ') . $name . PHP_EOL;
    $fail += $ok ? 0 : 1;
}

foreach ([$page, $footer, $header, $config, $vendor, $outside, $self, $qPage, $qInc,
          $rPage, $rHeader, $rDeep, $rSelf, $rLangEn, $rLangAr] as $f) {
    @unlink($f);
}
@rmdir($root . '/includes'); @rmdir($root . '/vendor/lib'); @rmdir($root . '/vendor');
@rmdir($quietRoot . '/includes'); @rmdir($quietRoot); @rmdir($root);
@rmdir($r2 . '/includes'); @rmdir($r2 . '/lang/en'); @rmdir($r2 . '/lang/ar');
@rmdir($r2 . '/lang'); @rmdir($r2);

echo sprintf("freshness_closure_selftest: %d/%d arms pass\n",
    count($arms) - $fail, count($arms));
exit($fail === 0 ? 0 : 1);
