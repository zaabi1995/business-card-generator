<?php
/**
 * Database-free contract for the bilingual NFC commercial landing page.
 */
require_once dirname(__DIR__, 2) . '/includes/ArTwins.php';
require_once dirname(__DIR__, 2) . '/includes/seo_title.php';

$root = dirname(__DIR__, 2);
$page = file_get_contents($root . '/nfc-business-card.php');
$twins = file_get_contents($root . '/includes/ArTwins.php');
$apache = file_get_contents($root . '/.htaccess');
$nginxHeadTerms = file_get_contents($root . '/docs/head-terms-nginx-rewrites.conf');
$nginxTwins = file_get_contents($root . '/docs/ar-twins-nginx-rewrites.conf');
$sitemap = file_get_contents($root . '/sitemap.php');
$pricing = file_get_contents($root . '/pricing.php');
$llms = file_get_contents($root . '/llms.txt');
$en = require $root . '/lang/en/nfc.php';
$ar = require $root . '/lang/ar/nfc.php';

$failures = 0;
function nfcContractCheck(bool $condition, string $label, string $detail = ''): void
{
    global $failures;
    echo ($condition ? 'PASS  ' : 'FAIL  ') . $label;
    if (!$condition && $detail !== '') echo ' (' . $detail . ')';
    echo "\n";
    if (!$condition) $failures++;
}

function nfcArabicShare(array $copy): float
{
    $text = implode(' ', array_map(static fn($v) => is_string($v) ? strip_tags($v) : '', $copy));
    $ar = preg_match_all('/[\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{08A0}-\x{08FF}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]/u', $text);
    $la = preg_match_all('/[A-Za-z]/u', $text);
    return ($ar + $la) > 0 ? $ar / ($ar + $la) : 0.0;
}

nfcContractCheck(
    array_keys($en) === array_keys($ar),
    'English and Arabic NFC dictionaries have exact key parity'
);

$share = nfcArabicShare($ar);
nfcContractCheck(
    $share >= 0.55,
    'Arabic NFC body stays above the bilingual body gate',
    number_format($share, 3)
);

nfcContractCheck(
    ArTwins::arPath('/nfc-business-card') === '/ar/nfc-business-card'
        && !ArTwins::isEnglishOnly('/nfc-business-card')
        && count(ArTwins::alternates('/nfc-business-card')) === 3,
    'NFC landing page is one reciprocal EN and AR hreflang pair'
);

nfcContractCheck(
    str_contains($apache, 'RewriteRule ^ar/nfc-business-card/?$ nfc-business-card.php?lang=ar [L,QSA]')
        && str_contains($nginxHeadTerms, 'rewrite ^/ar/nfc-business-card/?$')
        && str_contains($nginxTwins, 'rewrite ^/ar/nfc-business-card/?$'),
    'Apache intent and both nginx records route the Arabic body'
);

nfcContractCheck(
    str_contains($sitemap, "['/nfc-business-card',       'monthly', '0.9']")
        && str_contains($sitemap, 'ArTwins::arPath($loc)'),
    'sitemap source lists the NFC canonical through the shared bilingual emitter'
);

nfcContractCheck(
    str_contains($page, "t('nfc.page_title')")
        && str_contains($page, "(\$isAr ? '/ar' : '') . '/nfc-business-card'")
        && str_contains($page, "'ar-OM' : 'en-OM'")
        && str_contains($page, "['@id' => \$baseUrl . '/pricing#product-nfc']"),
    'page metadata, locale and product entity reference share one route decision'
);

nfcContractCheck(
    str_contains($page, 'data-speakable="summary"')
        && str_contains($page, 'data-speakable="article-body"')
        && str_contains($page, 'Seo::faqNode($faq)')
        && str_contains($page, 'developer.apple.com/documentation/corenfc')
        && str_contains($page, 'developer.android.com/develop/connectivity/nfc/nfc')
        && str_contains($page, 'www.nxp.com/products/NTAG213_215_216'),
    'page provides extractable answers, FAQ schema and primary technical sources'
);

nfcContractCheck(
    preg_match("/Seo::product\('nfc',\s*'pricing\.product_nfc_name',\s*'pricing\.product_nfc_spec',\s*'25'/", $pricing) === 1
        && str_contains($en['page_desc'], 'OMR 25.000')
        && str_contains($ar['page_desc'], '25.000'),
    'NFC price copy is copied from the canonical pricing source'
);

nfcContractCheck(
    str_contains($llms, 'https://cardify.om/nfc-business-card')
        && str_contains($llms, 'https://cardify.om/ar/nfc-business-card')
        && str_contains($llms, 'Standard OMR 5.000 per 100')
        && str_contains($llms, 'Premium OMR 6.000 per 100')
        && !str_contains($llms, 'Premium OMR 8.000 per 100'),
    'AI discovery names both NFC locales and matches canonical print pricing'
);

nfcContractCheck(
    str_contains($apache, 'AddDefaultCharset UTF-8')
        && str_contains($nginxTwins, 'charset utf-8;'),
    'Apache and nginx deployment records declare UTF-8 for Arabic AI discovery text'
);

$enTitle = seo_compose_title($en['page_title'], 'Cardify');
$arTitle = seo_compose_title($ar['page_title'], 'Cardify');
nfcContractCheck(
    mb_strlen($enTitle, 'UTF-8') <= 65 && mb_strlen($arTitle, 'UTF-8') <= 65,
    'both rendered titles fit the shared search title ceiling',
    $enTitle . ' | ' . $arTitle
);

$arSnippet = preg_replace('/\p{Mn}+/u', '', $ar['page_title'] . ' ' . $ar['page_desc']);
nfcContractCheck(
    str_contains($en['page_title'], 'NFC Business Cards')
        && str_contains($ar['page_title'], 'NFC')
        && str_contains($en['page_desc'], 'Oman')
        && str_contains($arSnippet, 'عمان'),
    'both snippets lead with the same commercial product and Oman intent'
);

$edited = [
    'nfc-business-card.php' => $page,
    'includes/ArTwins.php' => $twins,
    '.htaccess' => $apache,
    'docs/head-terms-nginx-rewrites.conf' => $nginxHeadTerms,
    'docs/ar-twins-nginx-rewrites.conf' => $nginxTwins,
    'lang/en/nfc.php' => file_get_contents($root . '/lang/en/nfc.php'),
    'lang/ar/nfc.php' => file_get_contents($root . '/lang/ar/nfc.php'),
    'llms.txt' => $llms,
];
$emDash = "\xE2\x80\x94";
$bad = [];
foreach ($edited as $path => $source) {
    if (str_contains($source, $emDash)) $bad[] = $path;
}
nfcContractCheck($bad === [], 'edited production files contain no em dash', implode(', ', $bad));

echo $failures === 0 ? "\nALL PASS\n" : "\n{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
