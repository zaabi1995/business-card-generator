<?php
/**
 * Company profile SEO and entity contract.
 *
 * This test stays database-free so it can guard the shared profile template,
 * both locale dictionaries and Cardify's canonical owner graph in CI.
 */
require_once dirname(__DIR__, 2) . '/includes/seo_title.php';
require_once dirname(__DIR__, 2) . '/includes/Seo.php';

$root = dirname(__DIR__, 2);
$companies = file_get_contents($root . '/companies.php');
$footer = file_get_contents($root . '/includes/ui-footer.php');
$relatedLogos = file_get_contents($root . '/views/partials/company_logo_related.php');
$llms = file_get_contents($root . '/llms.txt');
$en = require $root . '/lang/en/companies.php';
$ar = require $root . '/lang/ar/companies.php';

$failures = 0;
function companySeoCheck(bool $condition, string $label, string $detail = ''): void
{
    global $failures;
    echo ($condition ? 'PASS  ' : 'FAIL  ') . $label;
    if (!$condition && $detail !== '') echo ' (' . $detail . ')';
    echo "\n";
    if (!$condition) $failures++;
}

function companySeoTranslate(array $dict, string $key, array $params = []): string
{
    $text = (string) ($dict[$key] ?? '');
    foreach ($params as $name => $value) {
        $text = str_replace(':' . $name, (string) $value, $text);
    }
    return $text;
}

$enLogo = seo_pick_title([
    companySeoTranslate($en, 'logo_page_title', ['name' => 'Ministry of Education']),
    companySeoTranslate($en, 'logo_page_title_mid', ['name' => 'Ministry of Education']),
    companySeoTranslate($en, 'logo_page_title_short', ['name' => 'Ministry of Education']),
], 'Cardify');
$arLogo = seo_pick_title([
    companySeoTranslate($ar, 'logo_page_title', ['name' => 'وزارة التربية والتعليم']),
    companySeoTranslate($ar, 'logo_page_title_mid', ['name' => 'وزارة التربية والتعليم']),
    companySeoTranslate($ar, 'logo_page_title_short', ['name' => 'وزارة التربية والتعليم']),
], 'Cardify');

companySeoCheck(
    str_starts_with($enLogo, 'Ministry of Education Logo'),
    'English logo title keeps the searched entity and logo intent first',
    $enLogo
);
companySeoCheck(
    str_starts_with($arLogo, 'شعار وزارة التربية والتعليم'),
    'Arabic logo title keeps the searched entity and logo intent first',
    $arLogo
);
companySeoCheck(
    mb_strlen(seo_compose_title($enLogo, 'Cardify'), 'UTF-8') <= 65
        && mb_strlen(seo_compose_title($arLogo, 'Cardify'), 'UTF-8') <= 65,
    'logo titles stay inside the shared title limit'
);
companySeoCheck(
    str_contains(seo_compose_title($arLogo, 'Cardify'), 'كارديفاي'),
    'Arabic logo title keeps the Arabic Cardify brand form',
    seo_compose_title($arLogo, 'Cardify')
);
companySeoCheck(
    !str_contains($en['logo_page_title'], ':formats')
        && !str_contains($ar['logo_page_title'], ':formats'),
    'download formats cannot truncate the entity name in titles'
);

companySeoCheck(
    array_keys($en) === array_keys($ar),
    'English and Arabic company dictionaries have exact key parity'
);
companySeoCheck(
    str_contains($en['company_page_desc_fallback'], 'MoCIIP public register')
        && str_contains($ar['company_page_desc_fallback'], 'السجل العام لوزارة التجارة'),
    'uncurated meta descriptions identify the public-register source'
);
companySeoCheck(
    !str_contains($ar['company_page_desc_fallback'], 'يستخدم فريقها')
        && str_contains($en['profile_independence'], 'commercial relationship')
        && str_contains($ar['profile_independence'], 'ولا يدل على وجود علاقة تجارية')
        && !str_contains($en['profile_independence'], ':name')
        && !str_contains($ar['profile_independence'], ':name'),
    'directory copy does not imply a listed company uses Cardify'
);

companySeoCheck(
    !str_contains($companies, 'SECTOR_CONTENT')
        && !str_contains($companies, 'WILAYAT_CONTENT')
        && !str_contains($companies, "['what_they_do']")
        && !str_contains($companies, "['team_reality']")
        && !array_key_exists('about_register_line', $en)
        && !array_key_exists('about_register_line', $ar),
    'uncurated profiles cannot inherit generic sector or governorate narratives'
);
companySeoCheck(
    str_contains($companies, "t('companies.profile_snapshot'")
        && str_contains($companies, "t('companies.profile_independence'")
        && str_contains($companies, "ArTwins::navLink('contact', '/', \$isAr)")
        && str_contains($companies, '<?php if ($aboutParas): ?>')
        && str_contains($companies, "t('companies.visit_website')"),
    'visible answer block carries source context, correction and any verified website without duplicate fallback copy'
);
companySeoCheck(
    !str_contains($companies, 'ORDER BY RAND()')
        && !str_contains($relatedLogos, 'ORDER BY RAND()')
        && str_contains($relatedLogos, '$basePrefix . \'/\' . $r[\'slug\']'),
    'related entity links are deterministic and remain in the reader locale'
);
companySeoCheck(
    str_contains($companies, "\$companyEntityId = \$companyEntityUrl . '#organization'")
        && str_contains($companies, "if (!empty(\$company['name_ar'])) \$orgLd['alternateName']")
        && str_contains($companies, "\$GLOBALS['pageSchemaType'] = 'ProfilePage'")
        && str_contains($companies, "\$GLOBALS['pageSchemaMainEntity'] = ['@id' => \$companyEntityId]")
        && str_contains($footer, "\$__pageNode['mainEntity']"),
    'ProfilePage schema binds each locale page to one stable company entity ID'
);
companySeoCheck(
    str_contains($companies, "\$ogType = 'website'")
        && !str_contains($companies, "\$ogType = 'profile'"),
    'organization pages do not use the person-only Open Graph profile type'
);
companySeoCheck(
    str_contains($companies, 'I18n::formatDate($sourceUpdatedTs, $lang)'),
    'source verification date uses the active English or Arabic locale'
);

$owner = Seo::organizationNode();
$parent = $owner['parentOrganization'] ?? [];
companySeoCheck(
    ($owner['@id'] ?? '') === 'https://cardify.om/#organization'
        && ($parent['@id'] ?? '') === 'https://bhd.om/#organization'
        && ($parent['@type'] ?? '') === 'Organization',
    'Cardify remains the local entity with BHD as its typed parent reference'
);

companySeoCheck(
    str_contains($llms, 'https://cardify.om/oman-business-index')
        && str_contains($llms, 'https://cardify.om/companies')
        && str_contains($llms, 'https://cardify.om/ar/companies')
        && !str_contains(strtolower($llms), 'largest structured public index'),
    'AI discovery file links both directory locales without an unsupported ranking claim'
);

$edited = [
    'companies.php' => $companies,
    'includes/ui-footer.php' => $footer,
    'views/partials/company_logo_related.php' => $relatedLogos,
    'lang/en/companies.php' => file_get_contents($root . '/lang/en/companies.php'),
    'lang/ar/companies.php' => file_get_contents($root . '/lang/ar/companies.php'),
    'llms.txt' => $llms,
];
$withEmDash = [];
$emDash = "\xE2\x80\x94";
foreach ($edited as $path => $source) {
    if (str_contains($source, $emDash)) $withEmDash[] = $path;
}
companySeoCheck($withEmDash === [], 'edited production files contain no em dash', implode(', ', $withEmDash));

echo $failures === 0 ? "\nALL PASS\n" : "\n$failures FAILED\n";
exit($failures === 0 ? 0 : 1);
