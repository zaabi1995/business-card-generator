<?php
/**
 * r71: <title> fitting must never end inside a word.
 *
 * seo_fit_desc has cut on a word boundary since it was written; seo_fit_title,
 * in the same file, cut at a raw character offset, and 47 of 187 sampled live
 * titles ended in a stump ("Large and Medium Ente…", "Digital Busi…"). The
 * cost was not only cosmetic: numeric_gate reported cardify.om's most-published
 * figure as unprovenanced on two surfaces because the served title had lost
 * the noun "Enterprises" that told a reader what 2,500+ counts.
 */
require_once __DIR__ . '/../../includes/seo_title.php';

$fails = 0;
function check(string $name, bool $cond, string $got = ''): void
{
    global $fails;
    if (!$cond) { $fails++; echo "  [FAIL] $name  got: $got\n"; }
    else        { echo "  [ok]   $name\n"; }
}

/**
 * True when the ellipsis lands INSIDE a word of the source.
 *
 * "Large and Medium…" is a clean cut: the source continues with a space.
 * "Large and Medium Ente…" is not: the source continues with "rprises". The
 * test is against the input, not against the output alone, because a letter
 * standing before the ellipsis is normal and correct.
 */
function cuts_mid_word(string $in, string $out): bool
{
    $i = mb_strpos($out, '…', 0, 'UTF-8');
    if ($i === false || $i === 0) return false;
    $head = mb_substr($out, 0, $i, 'UTF-8');
    $head = rtrim($head, " ،,.-:;");
    if ($head === '') return false;
    $at = mb_strpos($in, $head, 0, 'UTF-8');
    if ($at === false) return false;          // head was re-punctuated, not cut
    $next = mb_substr($in, $at + mb_strlen($head, 'UTF-8'), 1, 'UTF-8');
    return $next !== '' && (bool) preg_match('/[\p{L}\p{N}]/u', $next);
}

// The exact live strings the census found, one per branch of seo_fit_title.
$cases = [
    'pipe branch (the OBI title as authored before r71)'
        => 'Oman Business Index 2026: 2,500+ Large and Medium Enterprises | Cardify',
    'comma branch (every blog post title)'
        => 'The ROI of Professional Business Cards: Why Quality Matters, Cardify Blog',
    'final fallback (no comma, no pipe)'
        => 'Digital Business Cards in the GCC What Works What Fails and How to Choose',
    'arabic legal name'
        => 'الشركة الوطنية العمانية لتنمية الثروة الحيوانية ش . م . ع . ع | كارديفاي',
];
foreach ($cases as $name => $in) {
    $out = seo_fit_title($in, 65);
    check("no mid-word cut: $name", !cuts_mid_word($in, $out), $out);
    check("still within the band: $name", mb_strlen($out, 'UTF-8') <= 65,
          $out . ' (' . mb_strlen($out, 'UTF-8') . ')');
}

// A title already inside the band is returned untouched.
$short = 'Oman Business Index: 2,500+ Large & Medium Enterprises | Cardify';
check('short title untouched', seo_fit_title($short, 65) === $short, seo_fit_title($short, 65));
check('short title keeps its noun', str_contains(seo_fit_title($short, 65), 'Enterprises'));

// One unbroken token longer than the budget must still be cut, or the head
// disappears and takes the page's subject with it.
$mono = str_repeat('A', 90) . ' | Cardify';
$out  = seo_fit_title($mono, 65);
check('one long token is still cut', mb_strlen($out, 'UTF-8') <= 65 && str_contains($out, '…'), $out);
check('one long token keeps the brand', str_contains($out, 'Cardify'), $out);

// The composed path, which is what pages actually call.
$c = seo_compose_title('Oman Business Index: 2,500+ Large & Medium Enterprises', 'Cardify', 65);
check('composed title keeps the figure and its noun',
      str_contains($c, '2,500+') && str_contains($c, 'Enterprises'), $c);

echo $fails === 0 ? "\nPASS\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
