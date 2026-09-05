<?php
/**
 * Every share URL a customer can print or scan has to resolve.
 *
 * nginx routes a bare token on a tenant host only when it is a single word
 * with no dot, or first.last. An id like "abdalah.ah.tm" matches neither, so
 * https://abdalah-ah-tm.cardify.om/abdalah.ah.tm answers 404 while
 * /card/abdalah.ah.tm answers 200. Measured live on 5 Sep 2026.
 *
 * CardifyConvention::employeeShareUrl() already knew all of this and falls back
 * to the canonical /card/<id>. card-pdf.php did not use it: it built
 * '/' . rawurlencode($employee['id']) by hand, printed that on the downloaded
 * PDF and encoded the same string into the QR code on it. A printed card
 * leading nowhere is the worst version of this defect, because the paper
 * outlives the fix.
 */
$root = dirname(__DIR__, 2);
require_once $root . '/includes/CardifyConvention.php';

$failures = 0;
function urlCheck(bool $c, string $label, string $detail = ''): void
{
    global $failures;
    echo ($c ? 'PASS  ' : 'FAIL  ') . $label;
    if (!$c && $detail !== '') echo ' (' . $detail . ')';
    echo "\n";
    if (!$c) $failures++;
}

$u = static fn(array $e): string => CardifyConvention::employeeShareUrl('acme', $e);

// A localpart nginx routes bare.
urlCheck($u(['id' => 'x1', 'email' => 'sara@acme.om']) === 'https://acme.cardify.om/sara',
    'a single-word localpart uses the pretty bare URL', $u(['id' => 'x1', 'email' => 'sara@acme.om']));
urlCheck($u(['id' => 'x2', 'email' => 'sara.ahmed@acme.om']) === 'https://acme.cardify.om/sara.ahmed',
    'first.last uses the pretty bare URL', $u(['id' => 'x2', 'email' => 'sara.ahmed@acme.om']));

// The shapes nginx will NOT route bare, which is the whole point.
urlCheck($u(['id' => 'abdalah.ah.tm', 'email' => 'abdalah.ah.tm@gmail.com'])
    === 'https://acme.cardify.om/card/abdalah.ah.tm',
    'a multi-dot localpart falls back to the canonical /card/ form',
    $u(['id' => 'abdalah.ah.tm', 'email' => 'abdalah.ah.tm@gmail.com']));
urlCheck($u(['id' => 'x3', 'email' => 'sara+cards@acme.om']) === 'https://acme.cardify.om/card/x3',
    'a plus-addressed localpart falls back');
urlCheck($u(['id' => 'x4', 'email' => 'SARA.AHMED@ACME.OM']) === 'https://acme.cardify.om/sara.ahmed',
    'the localpart is lowercased before it becomes a path');
urlCheck($u(['id' => '0859015A-373F-4707-8279-E0F6FBD47631'])
    === 'https://acme.cardify.om/card/0859015a-373f-4707-8279-e0f6fbd47631',
    'a UUID with no email uses /card/ and is lowercased');
urlCheck($u(['id' => 'x5', 'email' => '']) === 'https://acme.cardify.om/card/x5',
    'no email at all still produces a resolvable URL');
urlCheck($u([]) === 'https://acme.cardify.om/',
    'no id and no email falls back to the tenant root rather than a broken path');

// A localpart that collides with a real route must never win.
foreach (['admin', 'login', 'portal', 'card'] as $reserved) {
    $out = $u(['id' => 'x9', 'email' => $reserved . '@acme.om']);
    urlCheck($out === 'https://acme.cardify.om/card/x9',
        "the reserved path '{$reserved}' is not used as a card URL", $out);
}

// The PDF is the surface that prints and encodes it.
$pdf = file_get_contents($root . '/card-pdf.php');
urlCheck(str_contains($pdf, 'CardifyConvention::employeeShareUrl('),
    'card-pdf.php builds its URL through the convention');
urlCheck(!preg_match("/getTenantUrl\(\\\$company\['slug'\], '\/' \. rawurlencode/", $pdf),
    'card-pdf.php no longer hand-builds a bare id path');
urlCheck(str_contains($pdf, 'QRcode::png($cardUrl'),
    'the QR on the PDF encodes that same URL');

// Nothing else in the tree should hand-build one either.
$handBuilt = [];
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($rii as $file) {
    if ($file->getExtension() !== 'php') continue;
    $rel = str_replace($root . '/', '', $file->getPathname());
    foreach (['tests/', 'vendor/', 'node_modules/', '.git/', '.worktrees/'] as $skip) {
        if (str_starts_with($rel, $skip)) continue 2;
    }
    $src = @file_get_contents($file->getPathname());
    if ($src === false) continue;
    if (preg_match("/getTenantUrl\([^,]+,\s*'\/'\s*\.\s*rawurlencode\(\\\$employee\['id'\]\)\)/", $src)) {
        $handBuilt[] = $rel;
    }
}
urlCheck($handBuilt === [], 'no file hand-builds a bare employee path', implode(', ', $handBuilt));

urlCheck(str_contains($pdf, 'CARD_PDF_LAYOUT_VERSION'),
    'the PDF cache key carries a layout version, so a content change invalidates it');

$emDash = "\xE2\x80\x94";
urlCheck(!str_contains($pdf, $emDash), 'card-pdf.php contains no em dash');

echo $failures === 0 ? "\nALL PASS\n" : "\n{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
