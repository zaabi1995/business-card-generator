<?php
/**
 * The print-shop template editor, pinned against three defects found on
 * 4 Sep 2026 by driving it live.
 *
 * 1. Canvas size was lost on save. The editor posts canvas_width and
 *    canvas_height; api/shop-templates.php read them into two variables and
 *    never used them, shop_templates has no width or height column, and
 *    fabric's toJSON() carries no canvas dimensions. A template saved as
 *    Square (900x900) reopened at the 900x514 standard, taking every object
 *    below y=514 off the card. The editor's load path already read
 *    data.width off canvas_json, so only the writer was missing.
 *
 * 2. No trim or safe-zone guide on a product whose output is printed and cut.
 *
 * 3. The font dropdown offered seven Latin-only system faces while the page
 *    loaded Cairo from fonts.bhd.om, so nothing in it drew Arabic on the
 *    bilingual product.
 */
$root = dirname(__DIR__, 2);
$api  = file_get_contents($root . '/api/shop-templates.php');
$ed   = file_get_contents($root . '/printshop/template-editor.php');
$en   = require $root . '/lang/en/printshoptpl.php';
$ar   = require $root . '/lang/ar/printshoptpl.php';

$failures = 0;
function tplCheck(bool $c, string $label, string $detail = ''): void
{
    global $failures;
    echo ($c ? 'PASS  ' : 'FAIL  ') . $label;
    if (!$c && $detail !== '') echo ' (' . $detail . ')';
    echo "\n";
    if (!$c) $failures++;
}

// 1. size persistence
tplCheck(
    str_contains($api, "\$decoded['width'] = \$canvasWidth;")
        && str_contains($api, "\$decoded['height'] = \$canvasHeight;"),
    'the save API folds the posted canvas size into the stored JSON'
);
tplCheck(
    str_contains($ed, 'if (data.width) {')
        && str_contains($ed, "canvasWidth = data.width; canvasHeight = data.height;"),
    'the editor reads the canvas size back off the saved JSON'
);
tplCheck(
    str_contains($ed, "if ([...sizeSel.options].some(o => o.value === want)) sizeSel.value = want;"),
    'the size selector restores to the size actually on screen'
);

// 2. safe area
tplCheck(str_contains($ed, 'function drawSafeArea()'), 'the editor draws a safe area');
tplCheck(
    str_contains($ed, "excludeFromExport: true"),
    'the guide is excluded from export, so it never enters a saved template'
);
tplCheck(
    str_contains($ed, 'selectable: false, evented: false')
        && str_contains($ed, "name: 'guide_safe_area',"),
    'the guide cannot be selected, moved or deleted by mistake'
);
tplCheck(
    substr_count($ed, 'drawSafeArea();') >= 5,
    'the guide is drawn on every entry path, blank, saved and background',
    substr_count($ed, 'drawSafeArea();') . ' call sites'
);
tplCheck(
    str_contains($ed, 'const SAFE_MM = 3, CARD_MM_W = 88.9;')
        && str_contains($ed, 'canvasWidth * (SAFE_MM / CARD_MM_W)'),
    'the inset is derived from the live canvas width, so it survives a size change'
);

// 3. fonts
tplCheck(
    str_contains($ed, '<option>Cairo</option>'),
    'the font list offers a face that draws Arabic'
);
tplCheck(
    str_contains($ed, "t('printshoptpl.font_group_bilingual')"),
    'the Arabic-capable group is labelled rather than left to guesswork'
);
foreach (['font_group_bilingual', 'font_group_brand', 'font_group_system', 'safe_area_note'] as $k) {
    tplCheck(isset($en[$k], $ar[$k]), "both locales define {$k}");
}

// 4. no third-party fetch in the editor
tplCheck(
    !preg_match('#src="https?://[^"]*(fabric|qrcode|html2canvas)[^"]*"#i', $ed),
    'the editor loads its canvas engine locally, not from a CDN'
);
tplCheck(
    is_file($root . '/assets/js/fabric-5.3.1.min.js')
        && filesize($root . '/assets/js/fabric-5.3.1.min.js') > 100000,
    'the vendored Fabric is the library, not an error page'
);

$emDash = "\xE2\x80\x94";
tplCheck(!str_contains($ed, $emDash), 'printshop/template-editor.php contains no em dash');

echo $failures === 0 ? "\nALL PASS\n" : "\n{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
