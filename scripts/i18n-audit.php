<?php
/**
 * i18n audit, reports hard-coded English strings per PHP file.
 *
 * Usage:  php scripts/i18n-audit.php [--verbose] [path/to/dir ...]
 *
 * Flags likely-user-facing English strings that should be wrapped in t().
 * Heuristics:
 *   - Any 3+ word sentence inside <button>, <a>, <h1-6>, <label>, <p>, <span>, <div>
 *     text content, OR inside '..." or "..." PHP string literals that flow to echo/print.
 *   - Skips: SQL, JSON keys, class/id attributes, URLs, CSS selectors, PHP class names,
 *     inline comments, and strings wrapped in t() / I18n::t() / htmlspecialchars(t(...)).
 *   - Skips all files under vendor/, .worktrees/, .gstack/, .playwright-mcp/, cache/, storage/.
 *
 * Also cross-checks that every key in lang/en/{ns}.php exists in lang/ar/{ns}.php
 * and vice versa. Exits non-zero if any divergence is found.
 */

chdir(dirname(__DIR__));
$verbose = in_array('--verbose', $argv, true);
$argsDirs = array_values(array_filter(array_slice($argv, 1), fn($a) => $a !== '--verbose' && !str_starts_with($a, '-')));
$dirs = !empty($argsDirs) ? $argsDirs : ['admin', 'printshop', 'api', 'includes', '.'];

$excludeDirs = ['vendor', '.worktrees', '.gstack', '.playwright-mcp', 'cache', 'storage', 'node_modules', '.git', 'lang', 'scripts', 'install', 'database', 'assets', 'data'];
$excludeRegex = '#/(' . implode('|', array_map('preg_quote', $excludeDirs)) . ')/#';

/* 1) Build file list. */
$files = [];
foreach ($dirs as $d) {
    if (!is_dir($d)) continue;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $path = $f->getPathname();
        if (preg_match($excludeRegex, '/' . $path . '/')) continue;
        if (!preg_match('/\.php$/', $path)) continue;
        $files[] = $path;
    }
}
sort($files);

/* 2) Scan each file. */
$englishWord  = '/\b[A-Z][a-z]{2,}\b(?:\s+[a-z]{2,}){2,}/'; // "Upload your logo" style
$skipPatterns = [
    '/SELECT\s+|INSERT\s+INTO|UPDATE\s+\w+\s+SET|DELETE\s+FROM|CREATE\s+TABLE|ALTER\s+TABLE/i',
    '/\bhttps?:\/\/|mailto:|tel:|\.php$|\.css$|\.js$|\.svg$|\.png$|\.jpg$|\.webp$|\.woff2?$/i',
    '/class=|id=|data-[a-z-]+=|aria-[a-z-]+=|style=|onclick=|onload=/i',
];
$perFile = [];
$total = 0;
foreach ($files as $path) {
    $src = @file_get_contents($path);
    if ($src === false) continue;
    $lines = preg_split('/\r?\n/', $src);
    $hits = [];
    foreach ($lines as $i => $line) {
        if (strpos($line, 't(') !== false) continue; // already translated
        if (strpos($line, 'I18n::t(') !== false) continue;
        if (preg_match('/^\s*(\/\/|#|\/\*|\*)/', $line)) continue; // comments
        $skip = false;
        foreach ($skipPatterns as $p) { if (preg_match($p, $line)) { $skip = true; break; } }
        if ($skip) continue;
        if (preg_match_all($englishWord, $line, $m)) {
            foreach ($m[0] as $phrase) {
                if (strlen(trim($phrase)) < 10) continue;
                $hits[] = [$i + 1, trim($phrase)];
            }
        }
    }
    if (!empty($hits)) {
        $perFile[$path] = $hits;
        $total += count($hits);
    }
}

/* 3) Lang parity check. */
$parityIssues = [];
$en = glob('lang/en/*.php');
$ar = glob('lang/ar/*.php');
$nsFn = fn($p) => basename($p, '.php');
$enNs = array_map($nsFn, $en);
$arNs = array_map($nsFn, $ar);
$missingInAr = array_diff($enNs, $arNs);
$missingInEn = array_diff($arNs, $enNs);
foreach ($missingInAr as $ns) $parityIssues[] = "lang/ar/{$ns}.php missing";
foreach ($missingInEn as $ns) $parityIssues[] = "lang/en/{$ns}.php missing";
foreach (array_intersect($enNs, $arNs) as $ns) {
    $e = require __DIR__ . '/../lang/en/' . $ns . '.php';
    $a = require __DIR__ . '/../lang/ar/' . $ns . '.php';
    $keysE = array_keys($e);
    $keysA = array_keys($a);
    foreach (array_diff($keysE, $keysA) as $k) $parityIssues[] = "lang/ar/{$ns}.php missing key '{$k}'";
    foreach (array_diff($keysA, $keysE) as $k) $parityIssues[] = "lang/en/{$ns}.php missing key '{$k}'";
}

/* 4) Report. */
echo "=== i18n audit ===\n";
echo "Files scanned: " . count($files) . "\n";
echo "Files with likely hard-coded English: " . count($perFile) . "\n";
echo "Total flagged phrases: {$total}\n\n";

if ($verbose) {
    foreach ($perFile as $path => $hits) {
        echo "-- {$path} (" . count($hits) . ")\n";
        foreach (array_slice($hits, 0, 10) as [$line, $phrase]) {
            printf("   :%d %s\n", $line, $phrase);
        }
        if (count($hits) > 10) echo "   ... +" . (count($hits) - 10) . " more\n";
    }
} else {
    foreach ($perFile as $path => $hits) {
        printf("%4d  %s\n", count($hits), $path);
    }
}

echo "\n=== Lang parity ===\n";
if (empty($parityIssues)) {
    echo "OK, EN and AR namespaces and keys match.\n";
} else {
    foreach ($parityIssues as $issue) echo "FAIL: {$issue}\n";
}

$exitCode = !empty($parityIssues) ? 1 : 0;
echo "\nExit {$exitCode}\n";
exit($exitCode);
