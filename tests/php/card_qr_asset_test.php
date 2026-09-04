<?php
/**
 * The QR code is the whole point of a Cardify card: it is how a printed card
 * reaches the digital one. It used to be loaded from
 * cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/dist/qrcode.min.js, a path that
 * does not exist in that package. The CDN answered 404 with a 73 byte error
 * page, the generator guarded the library with "typeof qrcode !== 'undefined'"
 * and skipped the QR step, and every card produced by the onboarding wizard and
 * the bulk generator was saved with an empty square where the code belongs.
 * Verified on a live card generated 4 Sep 2026.
 *
 * This test keeps the library local and the failure loud.
 */
$root = dirname(__DIR__, 2);
$failures = 0;
function qrCheck(bool $cond, string $label, string $detail = ''): void
{
    global $failures;
    echo ($cond ? 'PASS  ' : 'FAIL  ') . $label;
    if (!$cond && $detail !== '') echo ' (' . $detail . ')';
    echo "\n";
    if (!$cond) $failures++;
}

$vendor = $root . '/assets/js/qrcode-generator-1.4.4.min.js';
qrCheck(is_file($vendor), 'the QR library is vendored, not fetched at generate time');
qrCheck(
    is_file($vendor) && filesize($vendor) > 10000,
    'the vendored QR library is the library, not an error page',
    is_file($vendor) ? (filesize($vendor) . ' bytes') : 'missing'
);
qrCheck(is_file($root . '/assets/js/html2canvas-1.4.1.min.js'), 'the card rasteriser is vendored too');
qrCheck(is_file($root . '/assets/js/qrcode-generator-1.4.4.LICENSE.txt'), 'the vendored QR library ships its licence');

$generators = [
    'admin/auto_generate.php',
    'admin/batch-auto-generate.php',
    'admin/nfc/batch.php',
];
foreach ($generators as $rel) {
    $src = file_get_contents($root . '/' . $rel);
    qrCheck(
        !preg_match('#src="https?://[^"]*qrcode[^"]*"#i', $src),
        "{$rel} does not fetch the QR library from a third party"
    );
    qrCheck(
        str_contains($src, 'assets/js/qrcode-generator-1.4.4.min.js'),
        "{$rel} loads the vendored QR library"
    );
}

$autogen = file_get_contents($root . '/admin/auto_generate.php');
qrCheck(
    str_contains($autogen, "typeof qrcode === 'undefined'")
        && str_contains($autogen, 'qr_library_missing')
        && !str_contains($autogen, "typeof qrcode !== 'undefined'"),
    'a missing QR library stops generation instead of skipping the code'
);
qrCheck(
    str_contains($autogen, 'if (!injected)') && str_contains($autogen, 'qr_slot_missing'),
    'a design with nowhere to put the QR stops generation too'
);

$en = require $root . '/lang/en/autogen.php';
$ar = require $root . '/lang/ar/autogen.php';
qrCheck(
    isset($en['js_qr_library_missing'], $en['js_qr_slot_missing'],
          $ar['js_qr_library_missing'], $ar['js_qr_slot_missing']),
    'both failures are explained in English and Arabic'
);

echo $failures === 0 ? "\nALL PASS\n" : "\n{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
