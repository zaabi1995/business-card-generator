<?php
/**
 * Public canonical URLs own their locale. App surfaces keep the existing
 * query and cookie language behaviour.
 *
 * Run: php tests/php/i18n_url_locale_contract_test.php
 */

require_once __DIR__ . '/../../includes/I18n.php';
require_once __DIR__ . '/../../includes/ArTwins.php';

$fails = 0;

function localeCheck(string $label, $got, $want): void
{
    global $fails;
    $ok = $got === $want;
    if (!$ok) $fails++;
    printf("[%s] %s (got=%s want=%s)\n", $ok ? 'PASS' : 'FAIL', $label,
        var_export($got, true), var_export($want, true));
}

$englishOnly = [
    '/tools/whatsapp-qr-generator',
    '/solutions/nfc-business-cards-oman-executives',
    '/industries/banking',
    '/gcc/uae',
    '/compare/cardify-vs-popl',
    '/glossary/vcard',
    '/blog/digital-business-cards-gcc',
    '/careers/product-designer',
    '/get-started',
    '/digital-business-card',
];

foreach ($englishOnly as $path) {
    localeCheck("English-only classifier: {$path}", ArTwins::isEnglishOnly($path), true);
    localeCheck("Canonical locale: {$path}", I18n::canonicalLocaleForPath($path, 'cardify.om'), 'en');
}

localeCheck('Press alias is English-only', ArTwins::isEnglishOnly('/press'), true);
localeCheck('Media kit alias is English-only', ArTwins::isEnglishOnly('/media-kit'), true);

$bilingualEnglish = ['/tools', '/solutions', '/pricing', '/companies/example'];
foreach ($bilingualEnglish as $path) {
    localeCheck("Bilingual path is not EN-only: {$path}", ArTwins::isEnglishOnly($path), false);
    localeCheck("English twin owns locale: {$path}", I18n::canonicalLocaleForPath($path, 'cardify.om'), 'en');
}

localeCheck('Arabic twin owns locale', I18n::canonicalLocaleForPath('/ar/pricing', 'cardify.om'), 'ar');

$appPaths = ['/admin', '/portal', '/company/dashboard', '/share/example'];
foreach ($appPaths as $path) {
    localeCheck("App path is not EN-only: {$path}", ArTwins::isEnglishOnly($path), false);
    localeCheck("App path keeps preference locale: {$path}", I18n::canonicalLocaleForPath($path, 'cardify.om'), null);
}

localeCheck('Apex host is accepted', ArTwins::isCanonicalSiteHost('cardify.om:443'), true);
localeCheck('WWW host is accepted', ArTwins::isCanonicalSiteHost('WWW.CARDIFY.OM'), true);
localeCheck('Tenant host is rejected', ArTwins::isCanonicalSiteHost('acme.cardify.om'), false);
localeCheck('Tenant EN-only lookalike keeps preference locale',
    I18n::canonicalLocaleForPath('/tools/example', 'acme.cardify.om'), null);

// Every literal public path in the static sitemap must be URL-driven. This
// catches a new English-only landing page that is added to search discovery
// without being added to the locale contract.
$sitemapSource = file_get_contents(__DIR__ . '/../../sitemap.php');
$staticBlock = '';
if (is_string($sitemapSource)) {
    preg_match('~if \(\$part === \'static\'\).*?\$staticPages = \[(.*?)\n    \];~s', $sitemapSource, $match);
    $staticBlock = $match[1] ?? '';
}
preg_match_all("~\['(/[^']*)'~", $staticBlock, $pathMatches);
$staticPaths = array_values(array_unique($pathMatches[1] ?? []));
localeCheck('Static sitemap paths were discovered', count($staticPaths) >= 30, true);
foreach ($staticPaths as $path) {
    localeCheck("Static sitemap path owns locale: {$path}",
        I18n::canonicalLocaleForPath($path, 'cardify.om') !== null, true);
}

echo $fails === 0 ? "\nALL PASS\n" : "\n{$fails} FAILED\n";
exit($fails === 0 ? 0 : 1);
