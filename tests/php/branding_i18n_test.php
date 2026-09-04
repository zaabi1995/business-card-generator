<?php
/**
 * The Branding page carried 40 hardcoded English strings and three t() calls,
 * so an Arabic admin got a correct right-to-left layout wrapped around an
 * English form: "Company Identity", "Brand Colors", "Enable Portal", and hint
 * lines whose English full stops landed on the wrong side of the sentence
 * because an LTR sentence was rendered inside an RTL block. Seen live 4 Sep 2026.
 */
$root = dirname(__DIR__, 2);
$src  = file_get_contents($root . '/admin/theme.php');
$en   = require $root . '/lang/en/branding.php';
$ar   = require $root . '/lang/ar/branding.php';

$failures = 0;
function brandCheck(bool $c, string $label, string $detail = ''): void
{
    global $failures;
    echo ($c ? 'PASS  ' : 'FAIL  ') . $label;
    if (!$c && $detail !== '') echo ' (' . $detail . ')';
    echo "\n";
    if (!$c) $failures++;
}

brandCheck(array_keys($en) === array_keys($ar), 'the branding dictionaries have exact key parity');

$arabic = 0;
foreach ($ar as $v) {
    if (preg_match('/[\x{0600}-\x{06FF}]/u', $v)) $arabic++;
}
brandCheck($arabic === count($ar), 'every Arabic branding string is actually in Arabic', "{$arabic}/" . count($ar));

$mustBeGone = [
    'Company Identity',
    'Brand Colors',
    'Primary Color',
    'Secondary Color',
    'Company Logo',
    'Enable Portal',
    'Show Live Preview',
    'Default theme',
    'Bilingual card',
    'Used on the E-Card, portal, vCard, and emails.',
    'Controls the digital card each employee shares via QR.',
];
$left = [];
foreach ($mustBeGone as $literal) {
    if (preg_match('/>\s*' . preg_quote($literal, '/') . '\s*</', $src)) $left[] = $literal;
}
brandCheck($left === [], 'no branding label is printed as a hardcoded English literal', implode(' | ', $left));

brandCheck(
    substr_count($src, "t('branding.") >= 30,
    'the page reads its labels from the branding namespace',
    substr_count($src, "t('branding.") . ' calls'
);

brandCheck(
    str_contains($src, "adminHeader(t('branding.page_title'), 'theme')"),
    'even the page heading is translated'
);

$emDash = "\xE2\x80\x94";
brandCheck(!str_contains($src, $emDash), 'admin/theme.php contains no em dash');

echo $failures === 0 ? "\nALL PASS\n" : "\n{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
