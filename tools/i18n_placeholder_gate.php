<?php
/**
 * i18n placeholder gate (r6-72).
 *
 * Three checks:
 *   1. ENGINE   - I18n::t() must resolve a placeholder whose name is a prefix of
 *                 another placeholder in the same string (':large' vs ':largePct').
 *   2. SOURCE   - every t('ns.key', [...]) call site whose param names collide by
 *                 prefix is reported, so a future engine regression is visible.
 *   3. RENDERED - the given URLs must contain no unresolved ':placeholder' and no
 *                 fused artefact (a digit immediately followed by a param-ish word).
 *
 * Usage:  php tools/i18n_placeholder_gate.php [--selftest] [url ...]
 * CLI only. Never reachable over HTTP.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('BASE_DIR', dirname(__DIR__));
require BASE_DIR . '/includes/I18n.php';

$fail = [];
$pass = [];

function ok(string $m): void { global $pass; $pass[] = $m; }
function bad(string $code, string $m): void { global $fail; $fail[] = "$code  $m"; }

/* ---------- 1. ENGINE ---------- */
$tpl = ':total / :large (:largePct%) / :medium (:mediumPct%)';
$params = ['total' => '2,502', 'large' => '1,027', 'largePct' => 41.0, 'medium' => '1,475', 'mediumPct' => 59.0];

// The real engine, exercised through a temporary namespace-free reflection of the
// substitution step: call t() on a known colliding key instead of duplicating logic.
I18n::setLocale('en');
$rendered = strip_tags(I18n::t('obi.finding03_body', $params));
if (preg_match('/\d(?:Pct|Total|Count|Medium|Large)/', $rendered)) {
    bad('ENGINE-COLLISION', 'obi.finding03_body fused a placeholder: ' . $rendered);
} elseif (strpos($rendered, '41%') === false || strpos($rendered, '59%') === false) {
    bad('ENGINE-COLLISION', 'obi.finding03_body did not substitute both percentages: ' . $rendered);
} else {
    ok('ENGINE: prefix-colliding placeholders resolve (41% / 59%)');
}

// Must-fail control: the naive left-to-right algorithm this gate exists to catch.
$naive = $tpl;
foreach ($params as $k => $v) { $naive = str_replace(':' . $k, (string) $v, $naive); }
if (!preg_match('/\d(?:Pct)/', $naive)) {
    bad('SELFTEST-CONTROL', 'the naive algorithm no longer fuses, so the ENGINE check proves nothing');
} else {
    ok('CONTROL: naive algorithm still fuses (' . $naive . '), so ENGINE is a real assertion');
}

/* ---------- 2. SOURCE ---------- */
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(BASE_DIR, FilesystemIterator::SKIP_DOTS));
$collisions = 0;
foreach ($rii as $f) {
    if ($f->getExtension() !== 'php') continue;
    $p = $f->getPathname();
    // Relative to BASE_DIR, else a checkout living under /tmp skips its own whole tree.
    $rel = ltrim(str_replace(BASE_DIR, '', $p), '/');
    if (preg_match('#(^|/)(node_modules|vendor|\.claude|\.worktrees|cache|tmp|logs|storage)/#', $rel)) continue;
    $src = file_get_contents($p);
    if (!preg_match_all("/\bt\(\s*'[^']+'\s*,\s*\[(.*?)\]\s*\)/s", $src, $m)) continue;
    foreach ($m[1] as $arr) {
        if (!preg_match_all("/'([A-Za-z_][A-Za-z0-9_]*)'\s*=>/", $arr, $km)) continue;
        $keys = $km[1];
        foreach ($keys as $a) {
            foreach ($keys as $b) {
                if ($a !== $b && strpos($b, $a) === 0) {
                    $collisions++;
                    fwrite(STDERR, "  note: prefix collision ':$a' / ':$b' in " . str_replace(BASE_DIR . '/', '', $p) . "\n");
                }
            }
        }
    }
}
ok("SOURCE: scanned t() call sites, $collisions prefix collision(s) present (engine handles them, listed on stderr)");

/* ---------- 3. RENDERED ---------- */
$urls = array_values(array_filter(array_slice($argv, 1), fn($a) => strpos($a, 'http') === 0));
if (in_array('--selftest', $argv, true) && !$urls) {
    $urls = [];
}
foreach ($urls as $u) {
    $html = @file_get_contents($u);
    if ($html === false) { bad('FETCH', $u); continue; }
    $text = strip_tags($html);
    if (preg_match('/\d(?:Pct|PctOfTotal)\b/', $text, $mm)) {
        bad('RENDERED-FUSED', "$u contains '{$mm[0]}'");
    } elseif (preg_match('/(?<![\w:\/])\:(total|large|medium|largePct|mediumPct|count|name|pct)\b/', $text, $mm)) {
        bad('RENDERED-UNRESOLVED', "$u contains '{$mm[0]}'");
    } else {
        ok("RENDERED: $u clean");
    }
}

foreach ($pass as $p) echo "PASS  $p\n";
foreach ($fail as $f) echo "FAIL  $f\n";
echo $fail ? "\nGATE FAIL (" . count($fail) . ")\n" : "\nGATE PASS (" . count($pass) . " checks)\n";
exit($fail ? 1 : 0);
