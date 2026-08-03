<?php
/**
 * tools/verify-brand-name.php
 *
 * Fails if any registered misspelling of the brand's Arabic name appears in
 * the source tree. Companion to includes/BrandNames.php.
 *
 * The defect it exists for (r27-19): "كاردفاي", one ya short of "كارديفاي",
 * on 58% of the Arabic pages including the answer text inside FAQPage
 * JSON-LD. It survived because every check asked whether the brand was
 * named, none asked whether the name was spelled right.
 *
 * Scans the deployed tree only. Worktrees, vendor, node_modules and the
 * enriched company CSV (third-party company names we do not author) are out
 * of scope. tools/ itself is excluded so this file's own doc comment is not
 * a hit, and includes/BrandNames.php is excluded because the registry has to
 * be able to spell the wrong spelling in order to ban it.
 *
 * Run:  php tools/verify-brand-name.php
 *       php tools/verify-brand-name.php --selftest
 * Exit: 0 clean, 1 at least one misspelling, 2 the scan found no files or
 *       the selftest proved the scan cannot see one
 */

require_once __DIR__ . '/../includes/BrandNames.php';

$root = dirname(__DIR__);
$skip = ['/.git/', '/.worktrees/', '/.claude/', '/node_modules/', '/vendor/',
         '/uploads/', '/storage/', '/cache/', '/logs/', '/tmp/', '/tools/',
         '/includes/BrandNames.php'];
$exts = ['php', 'py', 'js', 'json', 'html', 'txt', 'md'];

// --selftest writes the misspelling into a real scanned path, reruns the
// scan in a subprocess and requires it to go red. A checker that cannot be
// shown failing is not evidence of anything.
if (in_array('--selftest', $argv, true)) {
    $probe = $root . '/lang/ar/_brandname_selftest.php';
    file_put_contents($probe, "<?php // " . BrandNames::MISSPELLED_AR[0] . "\n");
    $out = [];
    $rc = 0;
    exec('php ' . escapeshellarg(__FILE__) . ' 2>&1', $out, $rc);
    unlink($probe);
    $saw = false;
    foreach ($out as $l) {
        if (strpos($l, '_brandname_selftest.php') !== false) { $saw = true; }
    }
    if ($rc === 1 && $saw) {
        echo "selftest ok: injected misspelling detected, exit 1\n";
        exit(0);
    }
    fwrite(STDERR, "selftest FAILED: rc=$rc saw=" . var_export($saw, true) . "\n");
    fwrite(STDERR, implode("\n", $out) . "\n");
    exit(2);
}

$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

$scanned = 0;
$bad = [];
foreach ($it as $f) {
    if (!$f->isFile()) { continue; }
    $path = $f->getPathname();
    $rel  = substr($path, strlen($root));
    foreach ($skip as $s) {
        if (strpos($rel, $s) !== false) { continue 2; }
    }
    if (!in_array(strtolower($f->getExtension()), $exts, true)) { continue; }
    $scanned++;
    $txt = @file_get_contents($path);
    if ($txt === false) { continue; }
    foreach (BrandNames::misspellings($txt) as $wrong => $n) {
        $bad[] = [ltrim($rel, '/'), $wrong, $n];
    }
}

if ($scanned === 0) {
    fwrite(STDERR, "verify-brand-name: scanned 0 files, refusing to pass\n");
    exit(2);
}

foreach ($bad as [$rel, $wrong, $n]) {
    printf("FAIL  %-52s %s x%d\n", $rel, $wrong, $n);
}
printf("\n=== %d misspelling(s) in %d files scanned; correct spelling is %s\n",
       count($bad), $scanned, BrandNames::AR);
exit($bad ? 1 : 0);
