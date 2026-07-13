<?php
/**
 * Regression test: CardifyConvention::employeeToScanCard() maps an employee
 * (+ company) row to the canonical parsed-card shape api/scan/resolve-card.php
 * hands back to the Scan mobile app, the same shape ScanParser::emptyParsed()
 * and ScanVcf::build() already use elsewhere in the Scan feature.
 *
 * Pure-logic test: no DB needed, the function takes plain arrays.
 *
 * Run: php tests/php/resolve_card_test.php
 */

require_once __DIR__ . '/../../includes/CardifyConvention.php';

$fails = 0;
function check($label, $got, $want) {
    global $fails;
    $ok = ($got === $want);
    if (!$ok) { $fails++; }
    printf("[%s] %s  (got=%s want=%s)\n", $ok ? 'PASS' : 'FAIL', $label,
        var_export($got, true), var_export($want, true));
}

// 1) Full bilingual employee, local (non-E.164) phone numbers, own
//    company_en override that beats the company row's name.
$employee = [
    'name_en' => 'Sara Al Habsi', 'name_ar' => 'سارة الحبسية',
    'position_en' => 'CEO', 'position_ar' => 'المدير التنفيذي',
    'company_en' => 'Example LLC', 'company_ar' => 'شركة المثال',
    'mobile' => '91234567', 'phone' => '24123456', 'fax' => '',
    'email' => 'sara@example.om', 'website' => 'https://example.om',
    'address_en' => 'Muscat, Oman', 'address_ar' => 'مسقط، عمان',
];
$company = ['name' => 'Fallback Co', 'slug' => 'example'];
$card = CardifyConvention::employeeToScanCard($employee, $company);

check('name_en', $card['name_en'], 'Sara Al Habsi');
check('name_ar', $card['name_ar'], 'سارة الحبسية');
check('title_en maps from position_en', $card['title_en'], 'CEO');
check('title_ar maps from position_ar', $card['title_ar'], 'المدير التنفيذي');
check('company_en prefers employee override', $card['company_en'], 'Example LLC');
check('company_ar', $card['company_ar'], 'شركة المثال');
check('mobile is E.164-normalized', $card['phones'][0], ['number' => '+96891234567', 'type' => 'mobile']);
check('work phone is E.164-normalized', $card['phones'][1], ['number' => '+96824123456', 'type' => 'work']);
check('empty fax is skipped, only 2 phones', count($card['phones']), 2);
check('emails wraps the single address', $card['emails'], ['sara@example.om']);
check('website passes through', $card['website'], 'https://example.om');
check('address_en', $card['address_en'], 'Muscat, Oman');
check('address_ar', $card['address_ar'], 'مسقط، عمان');

// 2) Bare minimum employee: no company_en override -> falls back to the
//    company row's name; no email/phones -> empty arrays, not blank entries.
$minimal = ['name_en' => 'John Doe'];
$companyFallback = ['name' => 'Acme LLC'];
$cardMin = CardifyConvention::employeeToScanCard($minimal, $companyFallback);
check('company_en falls back to company name', $cardMin['company_en'], 'Acme LLC');
check('no email -> empty emails array', $cardMin['emails'], []);
check('no phones -> empty phones array', $cardMin['phones'], []);
check('missing name_ar -> empty string, not null', $cardMin['name_ar'], '');

// 3) A phone number that already has a country code prefix but no '+',
//    and one that cannot be coerced into E.164 at all, passes through
//    verbatim rather than being dropped.
$oddPhones = [
    'name_en' => 'Odd Numbers',
    'mobile' => '968 9123 4567',   // spaced, no leading +, but 968-prefixed
    'phone' => 'ext.104',           // not a phone shape at all
    'fax' => '00968 24123456',      // 00-prefixed international form
];
$cardOdd = CardifyConvention::employeeToScanCard($oddPhones, []);
check('968-prefixed no-plus mobile normalizes', $cardOdd['phones'][0]['number'], '+96891234567');
check('unparseable phone passes through verbatim', $cardOdd['phones'][1]['number'], 'ext.104');
check('unparseable phone keeps its type', $cardOdd['phones'][1]['type'], 'work');
check('00-prefixed fax normalizes to E.164', $cardOdd['phones'][2]['number'], '+96824123456');

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
