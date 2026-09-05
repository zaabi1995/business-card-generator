<?php
/**
 * A generated card is validated against its own template's canvas.
 *
 * CardImageRenderer::validatedStagedImage() asserted 1050x600 exactly. Every
 * template imported at another size, portrait cards included, produced a
 * correctly sized image that was then rejected as <side>_render_invalid, so
 * "Regenerate cards" failed outright for those tenants.
 *
 * Found on 5 Sep 2026 while re-baking the eleven cards that had been printing
 * another person's name, position or mobile: all eleven came back
 * front_render_invalid and the renderer had done nothing wrong. AED Oman's
 * template is a portrait card imported from a PDF.
 *
 * The maths is a port of getTemplatePixelDims() in generate_card_html.php and
 * _canvas_dims() in scripts/render-card-images.py. All three have to agree, or
 * the render and the check disagree about what a valid card looks like.
 */
$root = dirname(__DIR__, 2);
if (!defined('UPLOADS_DIR')) define('UPLOADS_DIR', $root . '/uploads');
if (!defined('BASE_DIR')) define('BASE_DIR', $root);
if (!class_exists('Database')) {
    eval('class Database { public static function getInstance() { throw new RuntimeException("no database in this test"); } }');
}
require_once $root . '/includes/CardImageRenderer.php';

$failures = 0;
function dimCheck(bool $c, string $label, string $detail = ''): void
{
    global $failures;
    echo ($c ? 'PASS  ' : 'FAIL  ') . $label;
    if (!$c && $detail !== '') echo ' (' . $detail . ')';
    echo "\n";
    if (!$c) $failures++;
}

$d = static fn($t) => implode('x', CardImageRenderer::templatePixelDims($t));

// The legacy card, and everything with no stored physical size.
dimCheck($d(null) === '1050x600', 'no template at all falls back to the legacy card', $d(null));
dimCheck($d([]) === '1050x600', 'no settings falls back', $d([]));
dimCheck($d(['settings' => []]) === '1050x600', 'empty settings falls back');
dimCheck($d(['settings' => ['customWidth' => 0, 'customHeight' => 0]]) === '1050x600',
    'a zero size falls back');
dimCheck($d(['settings' => ['customWidth' => 'x', 'customHeight' => 'y']]) === '1050x600',
    'an unparseable size falls back');

// The standard 90x50mm business card at 300 dpi.
dimCheck($d(['settings' => ['customWidth' => 90, 'customHeight' => 50, 'dpi' => 300, 'customUnit' => 'mm']])
    === '1063x591', 'a 90x50mm card at 300dpi',
    $d(['settings' => ['customWidth' => 90, 'customHeight' => 50, 'dpi' => 300, 'customUnit' => 'mm']]));

// A portrait card, which is the shape that was being rejected.
dimCheck($d(['settings' => ['customWidth' => 50, 'customHeight' => 90, 'dpi' => 300, 'customUnit' => 'mm']])
    === '591x1063', 'a portrait card is not rejected for being tall');

// Units and dpi.
dimCheck($d(['settings' => ['customWidth' => 3.5, 'customHeight' => 2, 'dpi' => 300, 'customUnit' => 'in']])
    === '1050x600', 'the US 3.5x2in card is exactly the legacy pixel size');
dimCheck($d(['settings' => ['customWidth' => 252, 'customHeight' => 144, 'dpi' => 300, 'customUnit' => 'pt']])
    === '1050x600', 'the same card in points');
dimCheck($d(['settings' => ['customWidth' => 90, 'customHeight' => 50, 'dpi' => 0, 'customUnit' => 'mm']])
    === '1063x591', 'a zero dpi falls back to 300 rather than collapsing');
dimCheck($d(['settings' => ['customWidth' => 90, 'customHeight' => 50, 'customUnit' => 'mm']])
    === '1063x591', 'a missing dpi defaults to 300');

// settings_json as a string, which is how it comes out of the database.
dimCheck($d(['settings_json' => '{"customWidth":90,"customHeight":50,"dpi":300,"customUnit":"mm"}'])
    === '1063x591', 'settings_json arrives as a JSON string and still parses');
dimCheck($d(['settings_json' => 'not json']) === '1050x600', 'unparseable JSON falls back');

// The three implementations have to agree.
$src = file_get_contents($root . '/includes/CardImageRenderer.php');
dimCheck(!preg_match('/\(int\)\s*\$dimensions\[0\]\s*!==\s*1050/', $src),
    'the validator no longer hardcodes 1050x600');
dimCheck(str_contains($src, 'self::templatePixelDims($template)'),
    'the validator asks the template for its size');
foreach (['front', 'back'] as $side) {
    dimCheck(str_contains($src, "'{$side}', \$context['{$side}_template'] ?? null)"),
        "the {$side} check is given the {$side} template");
}
$py = file_get_contents($root . '/scripts/render-card-images.py');
dimCheck(str_contains($py, 'def _canvas_dims'), 'the renderer computes the same canvas');
dimCheck(str_contains($py, '1 / 25.4') && str_contains($py, '1 / 72'),
    'the renderer uses the same unit conversions');

$emDash = "\xE2\x80\x94";
dimCheck(!str_contains($src, $emDash), 'includes/CardImageRenderer.php contains no em dash');

echo $failures === 0 ? "\nALL PASS\n" : "\n{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
