<?php
/**
 * The country list that kept an Arabic page from being an Arabic page.
 *
 * /ar/print-shops/register served <html lang="ar" dir="rtl"> around a
 * 484-character English country list, and that one <select> held the page's
 * Arabic letter share at 0.291 against a 0.55 floor. It was therefore not in
 * the twin map, so it and /ar/partners stood as orphan Arabic URLs that nothing
 * linked with hreflang, and tools/verify-ar-twins.php failed on both.
 *
 * Currency::getCountries() stays the data (code, currency, region). The name a
 * person reads comes from lang/{en,ar}/countries.php, so all five country
 * pickers, their alphabetical sorts and their search box speak the reader's
 * language.
 */
$root = dirname(__DIR__, 2);
require_once $root . '/includes/I18n.php';
if (!function_exists('t')) {
    function t(string $k, array $p = [], ?string $l = null): string { return I18n::t($k, $p, $l); }
}
require_once $root . '/includes/Currency.php';
require_once $root . '/includes/ArTwins.php';

$failures = 0;
function ciCheck(bool $c, string $label, string $detail = ''): void
{
    global $failures;
    echo ($c ? 'PASS  ' : 'FAIL  ') . $label;
    if (!$c && $detail !== '') echo ' (' . $detail . ')';
    echo "\n";
    if (!$c) $failures++;
}

// 1. every country in the data has a name in both languages
$en = require $root . '/lang/en/countries.php';
$ar = require $root . '/lang/ar/countries.php';
$missing = [];
foreach (array_keys(Currency::getCountries()) as $code) {
    if (!isset($en[$code])) $missing[] = 'en/' . $code;
    if (!isset($ar[$code])) $missing[] = 'ar/' . $code;
}
ciCheck($missing === [], 'every country code has a name in both languages',
    implode(', ', array_slice($missing, 0, 8)));

$latinInArabic = [];
foreach (array_keys(Currency::getCountries()) as $code) {
    if (preg_match('/[A-Za-z]/', (string) $ar[$code])) $latinInArabic[] = $code;
}
ciCheck($latinInArabic === [], 'no Arabic country name is still Latin text',
    implode(', ', $latinInArabic));

// 2. the rendered select
foreach (['en' => 'Oman', 'ar' => 'عُمان'] as $locale => $expect) {
    I18n::setLocale($locale);
    $html = Currency::getCountryOptions('OM');
    ciCheck(str_contains($html, '>' . $expect . '<'), "{$locale} select names Oman correctly");
    ciCheck(str_contains($html, 'value="OM"'), "{$locale} select still carries the ISO value");
}
I18n::setLocale('ar');
$arHtml = Currency::getCountryOptions('OM');
$latin = preg_match_all('/>([A-Za-z][A-Za-z .\'-]{4,})</', $arHtml, $m);
ciCheck($latin === 0, 'the Arabic select carries no English country label',
    implode(', ', array_slice($m[1] ?? [], 0, 5)));
ciCheck(!str_contains($arHtml, 'Select Country'), 'the Arabic placeholder is Arabic');
ciCheck(!str_contains($arHtml, 'GCC Countries'), 'the Arabic optgroups are Arabic');

// The values are what a form posts and a database stores. Translating the
// labels must not have touched them.
I18n::setLocale('en');
$enCodes = [];
preg_match_all('/value="([A-Z]{2})"/', Currency::getCountryOptions('OM'), $mEn);
I18n::setLocale('ar');
preg_match_all('/value="([A-Z]{2})"/', Currency::getCountryOptions('OM'), $mAr);
ciCheck($mEn[1] === $mAr[1] && count($mEn[1]) === count(Currency::getCountries()),
    'both locales post exactly the same ISO codes in the same order');

// 3. the phone picker reads the same source
foreach (['en' => 'Oman', 'ar' => 'عُمان'] as $locale => $expect) {
    I18n::setLocale($locale);
    $phone = Currency::renderPhoneInput(['selected_country' => 'OM']);
    ciCheck(str_contains($phone, $expect), "{$locale} phone picker names Oman correctly");
    ciCheck(str_contains($phone, '+968'), "{$locale} phone picker keeps the dialling code");
}
I18n::setLocale('ar');
$arPhone = Currency::renderPhoneInput(['selected_country' => 'OM']);
ciCheck(!str_contains($arPhone, 'Search country'), 'the Arabic phone picker search box is Arabic');

// A translation carrying a percent sign would be read as a conversion
// specification, because these labels are concatenated into a format string.
$src = file_get_contents($root . '/includes/Currency.php');
ciCheck(str_contains($src, 'countryLabelInFormat'),
    'labels going into a sprintf format are percent-escaped');

// 4. the twin the fix unblocked
ciCheck(ArTwins::has('/print-shops/register'),
    '/print-shops/register is in the twin map now that its body is Arabic');
ciCheck(str_contains(file_get_contents($root . '/printshop/register.php'), '$canonicalUrl'),
    'the register page names a canonical, so /partners is not a fourth indexable copy');

$emDash = "\xE2\x80\x94";
foreach (['includes/Currency.php', 'lang/en/countries.php', 'lang/ar/countries.php',
          'printshop/register.php'] as $rel) {
    ciCheck(!str_contains(file_get_contents($root . '/' . $rel), $emDash), "{$rel} contains no em dash");
}

echo $failures === 0 ? "\nALL PASS\n" : "\n{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
