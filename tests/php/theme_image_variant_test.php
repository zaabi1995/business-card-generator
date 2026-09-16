<?php
/**
 * Regression test: tenant brand images (company_themes.logo_path /
 * favicon_path) must get capped web variants, the ORIGINAL must survive
 * untouched for the wallet passes and the print paths, and the variant name
 * must never collide with the "<base>-dark.*" reverse logo that
 * wallet_apple.php globs for (cardify skill rule 45).
 *
 * Pure-logic test: no DB, no config.php. BASE_DIR is pointed at a temp tree.
 *
 * Run: php tests/php/theme_image_variant_test.php
 */

$root = sys_get_temp_dir() . '/cardify-themeimage-' . getmypid();
@mkdir($root . '/uploads/companies/co1/theme', 0777, true);
define('BASE_DIR', $root);

require_once __DIR__ . '/../../includes/ThemeImage.php';

$fails = 0;
function check($label, $got, $want) {
    global $fails;
    $ok = ($got === $want);
    if (!$ok) { $fails++; }
    printf("[%s] %s  (got=%s want=%s)\n", $ok ? 'PASS' : 'FAIL', $label,
        var_export($got, true), var_export($want, true));
}
function note($label, $v) { printf("[INFO] %s = %s\n", $label, var_export($v, true)); }

/** A wide PNG with transparency, the shape a real brand logo has. */
function makePng(string $abs, int $w, int $h): void {
    $im = imagecreatetruecolor($w, $h);
    imagealphablending($im, false);
    imagesavealpha($im, true);
    imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
    // Noise, so the file does not compress to almost nothing and the
    // "is the variant actually smaller" assertion means something.
    for ($i = 0; $i < 4000; $i++) {
        imagefilledrectangle($im, rand(0, $w - 1), rand(0, $h - 1),
            rand(0, $w - 1), rand(0, $h - 1),
            imagecolorallocate($im, rand(0, 255), rand(0, 255), rand(0, 255)));
    }
    imagepng($im, $abs, 6);
    imagedestroy($im);
}

// ---------------------------------------------------------------- naming ---
check('webp sibling name',
    ThemeImage::siblingStored('companies/co1/theme/logo_1.png'),
    'companies/co1/theme/logo_1_web.webp');
check('png sibling name',
    ThemeImage::siblingStored('/uploads/logos/co1.jpg', ThemeImage::SUFFIX_PNG),
    '/uploads/logos/co1_web.png');

// The Apple pass finds the reverse logo by globbing "<base>-dark.*".
// "_web." must not be reachable by that pattern, in either direction.
$darkGlob = 'logo-shield-dark.*';
check('variant is not matched by the -dark glob',
    fnmatch($darkGlob, basename(ThemeImage::siblingStored('logo-shield-dark.png'))), false);
check('variant of the plain logo is not a -dark candidate',
    fnmatch('logo-shield-dark.*', 'logo-shield_web.webp'), false);
check('a variant is recognised as a variant',
    ThemeImage::isVariant('companies/co1/theme/logo_1_web.webp'), true);
check('an original is not a variant',
    ThemeImage::isVariant('companies/co1/theme/logo_1.png'), false);

// ------------------------------------------------------- path resolution ---
$storedBare = 'companies/co1/theme/logo_1.png';
$absLogo    = $root . '/uploads/' . $storedBare;
makePng($absLogo, 1400, 420);

check('bare relative path resolves',    ThemeImage::absPath($storedBare) !== null, true);
check('"/uploads/.." path resolves',    ThemeImage::absPath('/uploads/' . $storedBare) !== null, true);
check('"uploads/.." path resolves',     ThemeImage::absPath('uploads/' . $storedBare) !== null, true);
check('missing file resolves to null',  ThemeImage::absPath('companies/co1/theme/nope.png'), null);
check('traversal is refused',           ThemeImage::absPath('../../etc/passwd'), null);
check('absolute URL is refused',        ThemeImage::absPath('https://x.test/a.png'), null);

// ------------------------------------------------------------- variants ----
$origBytes = filesize($absLogo);
$v = ThemeImage::ensureVariants($storedBare, ThemeImage::LOGO_MAX);
note('original bytes', $origBytes);

$webpAbs = $root . '/uploads/companies/co1/theme/logo_1_web.webp';
$pngAbs  = $root . '/uploads/companies/co1/theme/logo_1_web.png';

check('webp variant written', is_file($webpAbs), true);
check('png variant written',  is_file($pngAbs), true);

$dims = getimagesize($webpAbs);
check('webp capped on the long edge', $dims[0], ThemeImage::LOGO_MAX);
check('webp keeps the aspect ratio',  $dims[1], (int) round(420 * (ThemeImage::LOGO_MAX / 1400)));
check('webp is smaller than the original', filesize($webpAbs) < $origBytes, true);
note('webp bytes', filesize($webpAbs));

// The original is what the wallet passes and the print renderers read.
check('original still on disk',      is_file($absLogo), true);
check('original bytes unchanged',    filesize($absLogo), $origBytes);
check('original size unchanged',     [getimagesize($absLogo)[0], getimagesize($absLogo)[1]], [1400, 420]);

// ------------------------------------------------------------ preference ---
ThemeImage::resetCache();
check('preferWeb picks the webp',  ThemeImage::preferWeb($storedBare), 'companies/co1/theme/logo_1_web.webp');
check('preferIcon picks the png',  ThemeImage::preferIcon($storedBare), 'companies/co1/theme/logo_1_web.png');
check('preferWeb keeps the shape it was given',
    ThemeImage::preferWeb('/uploads/' . $storedBare), '/uploads/companies/co1/theme/logo_1_web.webp');
check('preferWeb on an unknown path is a no-op',
    ThemeImage::preferWeb('companies/co1/theme/nope.png'), 'companies/co1/theme/nope.png');
check('preferWeb on empty is empty', ThemeImage::preferWeb(null), '');

// ------------------------------------------------------------ idempotent ---
$webpMtimeBefore = filemtime($webpAbs);
$webpBytesBefore = filesize($webpAbs);
clearstatcache();
ThemeImage::ensureVariants($storedBare, ThemeImage::LOGO_MAX);
clearstatcache();
check('re-run does not rewrite the variant', filemtime($webpAbs), $webpMtimeBefore);
check('re-run leaves the bytes alone',       filesize($webpAbs), $webpBytesBefore);

// A replaced logo keeps the SAME filename in apply_theme.php and
// api/onboarding.php, so only the mtime moves. A stale variant must not win.
touch($absLogo, time() + 10);
ThemeImage::resetCache();
clearstatcache();
check('a stale variant is not preferred', ThemeImage::preferWeb($storedBare), $storedBare);
check('a stale variant is not preferred as an icon', ThemeImage::preferIcon($storedBare), $storedBare);

// ---------------------------------------------------------------- skips ----
$svg = $root . '/uploads/companies/co1/theme/logo.svg';
file_put_contents($svg, '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"/>');
check('svg is not convertible', ThemeImage::isConvertible($svg), false);
check('svg produces no variants',
    ThemeImage::ensureVariants('companies/co1/theme/logo.svg', ThemeImage::LOGO_MAX),
    ['webp' => null, 'png' => null]);
check('svg path is served unchanged',
    ThemeImage::preferWeb('companies/co1/theme/logo.svg'), 'companies/co1/theme/logo.svg');

// A tiny image whose variant cannot beat the original must be dropped, or the
// page would ship MORE bytes than before.
$tiny = $root . '/uploads/companies/co1/theme/favicon_tiny.png';
$im = imagecreatetruecolor(16, 16);
imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
imagepng($im, $tiny, 9);
imagedestroy($im);
ThemeImage::ensureVariants('companies/co1/theme/favicon_tiny.png', ThemeImage::FAVICON_MAX);
ThemeImage::resetCache();
check('no-win variant falls back to the original',
    ThemeImage::preferIcon('companies/co1/theme/favicon_tiny.png'),
    'companies/co1/theme/favicon_tiny.png');

// A variant is never itself re-processed into a variant-of-a-variant.
check('a variant is not re-processed',
    ThemeImage::ensureVariants('companies/co1/theme/logo_1_web.webp', ThemeImage::LOGO_MAX),
    ['webp' => null, 'png' => null]);

// ---------------------------------------------------------------- teardown -
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
@rmdir($root);

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
