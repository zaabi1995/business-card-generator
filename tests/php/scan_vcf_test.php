<?php
// tests/php/scan_vcf_test.php
require_once __DIR__ . '/../../includes/ScanVcf.php';

function check($label, $actual, $expected) {
    $ok = $actual === $expected;
    echo ($ok ? "PASS" : "FAIL") . " $label\n";
    if (!$ok) { var_dump($actual); exit(1); }
}

$parsed = [
    'name_en' => 'Sara Al Habsi', 'name_ar' => 'سارة الحبسية',
    'title_en' => 'CEO', 'company_en' => 'Example LLC',
    'phones' => [['number' => '+96891234567', 'type' => 'mobile']],
    'emails' => ['sara@example.om'], 'website' => 'https://example.om',
];
$vcf = ScanVcf::build($parsed, 'Met at COMEX 2026');
check('starts with BEGIN', strpos($vcf, "BEGIN:VCARD\r\n"), 0);
check('has version 3.0', strpos($vcf, "VERSION:3.0\r\n") !== false, true);
check('has FN', strpos($vcf, 'FN;CHARSET=UTF-8:Sara Al Habsi') !== false, true);
check('has CELL tel', strpos($vcf, 'TEL;TYPE=CELL:+96891234567') !== false, true);
check('has arabic in note', strpos($vcf, 'سارة') !== false, true);
check('ends with END', substr($vcf, -11), "END:VCARD\r\n");

// Arabic-only card: *_ar becomes the primary FN/TITLE/ORG, so NOTE must not
// duplicate it. With no context note either, no NOTE line at all.
$arOnly = [
    'name_en' => '', 'name_ar' => 'سارة الحبسية',
    'title_en' => '', 'title_ar' => 'المدير التنفيذي',
    'company_en' => '', 'company_ar' => 'شركة المثال',
    'phones' => [], 'emails' => [], 'website' => '',
];
$vcfAr = ScanVcf::build($arOnly, null);
check('ar-only FN uses arabic name', strpos($vcfAr, 'FN;CHARSET=UTF-8:سارة الحبسية') !== false, true);
check('ar-only has no NOTE duplication', strpos($vcfAr, 'NOTE') !== false, false);
$vcfArNote = ScanVcf::build($arOnly, 'Met at COMEX 2026');
check('ar-only NOTE carries only the context note', strpos($vcfArNote, "NOTE;CHARSET=UTF-8:Met at COMEX 2026\r\n") !== false, true);
check('ar-only NOTE does not repeat name_ar', substr_count($vcfArNote, 'سارة'), 2); // N + FN only

// RFC 2426: backslash is the escape character and must be escaped first.
$bs = ScanVcf::build(['name_en' => 'Back\slash Guy', 'name_ar' => '', 'phones' => [], 'emails' => []], null);
check('literal backslash emits double backslash', strpos($bs, 'FN;CHARSET=UTF-8:Back\\\\slash Guy') !== false, true);
$semi = ScanVcf::build(['name_en' => 'A;B', 'name_ar' => '', 'phones' => [], 'emails' => []], null);
check('semicolon still single-escaped', strpos($semi, 'FN;CHARSET=UTF-8:A\\;B') !== false, true);

// ---------------------------------------------------------------------------
// RFC 2426 section 2.6 line folding
// ---------------------------------------------------------------------------

/** Unfold independently of the code under test: CRLF + exactly one space/tab. */
function unfold($vcf) { return preg_replace("/\r\n[ \t]/", '', $vcf); }
function physicalLines($vcf) { return explode("\r\n", rtrim($vcf, "\r\n")); }

$longAddrAr = 'مبنى رقم ١٢٣، شارع السلطان قابوس، الخوير، محافظة مسقط، سلطنة عُمان';
check('the arabic test address really is over 75 octets', strlen($longAddrAr) > 75, true);

$folded = ScanVcf::build([
    'name_en' => 'Sara AlHabsi', 'name_ar' => 'سارة الحبسية',
    'address_ar' => $longAddrAr,
    'phones' => [], 'emails' => [],
], null);
$over = 0; $bad = 0;
foreach (physicalLines($folded) as $line) {
    if (strlen($line) > 75) { $over++; }
    if (!mb_check_encoding($line, 'UTF-8')) { $bad++; }
}
check('folded: no physical line exceeds 75 octets', $over, 0);
check('folded: no fold lands inside a UTF-8 sequence', $bad, 0);
check('folded: the card actually contains a fold', strpos($folded, "\r\n ") !== false, true);
check('folded: unfolding restores the whole ADR value',
    strpos(unfold($folded), 'ADR;CHARSET=UTF-8;TYPE=WORK:;;' . $longAddrAr . ';;;;') !== false, true);

// An arabic-only card previously emitted no ADR at all: address_ar was ignored.
check('arabic-only card still gets an ADR', strpos(unfold($folded), 'ADR;') !== false, true);

$longAscii = ScanVcf::build([
    'name_en' => 'X',
    'address_en' => 'Building 123, Sultan Qaboos Street, Al Khuwair, Muscat Governorate, Sultanate of Oman',
    'phones' => [], 'emails' => [],
], null);
$overAscii = 0;
foreach (physicalLines($longAscii) as $line) { if (strlen($line) > 75) { $overAscii++; } }
check('long ascii address folds too', $overAscii, 0);
check('english address still wins over arabic when both exist',
    strpos(unfold(ScanVcf::build(
        ['name_en' => 'X', 'address_en' => 'EN Street', 'address_ar' => 'شارع', 'phones' => [], 'emails' => []], null
    )), 'ADR;CHARSET=UTF-8;TYPE=WORK:;;EN Street;;;;') !== false, true);

// ---------------------------------------------------------------------------
// A CR inside a parsed field must not survive into the emitted vCard
// ---------------------------------------------------------------------------

$cr = ScanVcf::build([
    'name_en' => "Bad\rName", 'company_en' => "Acme\r\nLLC",
    'phones' => [['number' => "+968\r91234567", 'type' => 'mobile']], 'emails' => [],
], null);
check('interior CR is stripped from FN', strpos($cr, 'FN;CHARSET=UTF-8:BadName') !== false, true);
check('CRLF in a value becomes an escaped \\n', strpos($cr, 'ORG;CHARSET=UTF-8:Acme\\nLLC') !== false, true);
check('CR is stripped from a phone number', strpos($cr, 'TEL;TYPE=CELL:+96891234567') !== false, true);
check('the only CRs left are the real line breaks',
    substr_count($cr, "\r"), count(physicalLines($cr)));

// ---------------------------------------------------------------------------
// Name policy. FN is always the full printed name; N refuses to guess past two
// tokens. See VCardRfc::splitName().
// ---------------------------------------------------------------------------

$compound = ScanVcf::build(['name_en' => 'Abdul Rahman al-Balushi', 'phones' => [], 'emails' => []], null);
check('compound name: FN is the full printed name',
    strpos($compound, 'FN;CHARSET=UTF-8:Abdul Rahman al-Balushi') !== false, true);
check('compound name: N keeps the real family name in the last token',
    strpos($compound, 'N;CHARSET=UTF-8:al-Balushi;Abdul;Rahman;;') !== false, true);

$owner = ScanVcf::build(['name_en' => 'Ali Adnan Haider Darwish', 'phones' => [], 'emails' => []], null);
check('4-token Omani name: family is Darwish, middle is Adnan Haider',
    strpos($owner, 'N;CHARSET=UTF-8:Darwish;Ali;Adnan Haider;;') !== false, true);

$chain = ScanVcf::build(['name_en' => '', 'name_ar' => 'علي بن عدنان بن حيدر درويش', 'phones' => [], 'emails' => []], null);
check('arabic patronymic chain: FN is the full name',
    strpos(unfold($chain), 'FN;CHARSET=UTF-8:علي بن عدنان بن حيدر درويش') !== false, true);
check('arabic patronymic chain: N asserts no family name',
    strpos(unfold($chain), 'N;CHARSET=UTF-8:;علي بن عدنان بن حيدر درويش;;;') !== false, true);

$chainLatin = ScanVcf::build(['name_en' => 'Ali bin Adnan bin Haider Darwish', 'phones' => [], 'emails' => []], null);
check('latin patronymic chain: N asserts no family name',
    strpos($chainLatin, 'N;CHARSET=UTF-8:;Ali bin Adnan bin Haider Darwish;;;') !== false, true);

$robin = ScanVcf::build(['name_en' => 'Robin Mark Hood', 'phones' => [], 'emails' => []], null);
check('"Robin" contains bin but is not a particle, family is Hood',
    strpos($robin, 'N;CHARSET=UTF-8:Hood;Robin;Mark;;') !== false, true);

$two = ScanVcf::build(['name_en' => 'Sara AlHabsi', 'phones' => [], 'emails' => []], null);
check('two tokens still split into given + family',
    strpos($two, 'N;CHARSET=UTF-8:AlHabsi;Sara;;;') !== false, true);

$one = ScanVcf::build(['name_en' => 'Cher', 'phones' => [], 'emails' => []], null);
check('single token goes to given, family empty',
    strpos($one, 'N;CHARSET=UTF-8:;Cher;;;') !== false, true);

// ---------------------------------------------------------------------------
// Phone TYPE mapping. An office landline must not be labelled mobile.
// ---------------------------------------------------------------------------

// Matching is SUBSTRING and case-insensitive. An exact-match table over English
// keys almost never fires on real scanner output, which returns whatever the
// card printed. Each case below is a label a card actually carries.
$cases = [
    // label                   number          expected
    ['mobile',                 '+96891000001', 'CELL'],
    ['Cell',                   '+96891000002', 'CELL'],
    ['Mobile/WhatsApp',        '+96891000003', 'CELL'],  // combined: CELL must win
    ['Mob:',                   '+96891000004', 'CELL'],
    ['جوال',                   '+96891000005', 'CELL'],
    ['محمول',                  '+96891000006', 'CELL'],
    ['work',                   '+96824000001', 'WORK'],
    ['Office',                 '+96824000002', 'WORK'],
    ['landline',               '+96824000003', 'WORK'],
    [' Direct Line ',          '+96824000004', 'WORK'],
    ['Toll Free',              '+96824000005', 'WORK'],
    ['Ph',                     '+96824000006', 'WORK'],
    ['Hotline',                '+96824000007', 'WORK'],
    ['Ext 204',                '+96824000008', 'WORK'],
    ['reception desk',         '+96824000009', 'WORK'],
    ['هاتف',                   '+96824000010', 'WORK'],
    ['مباشر',                  '+96824000011', 'WORK'],
    ['مجاني',                  '+96824000012', 'WORK'],
    ['fax',                    '+96824000020', 'FAX'],
    ['Tel/Fax',                '+96824000021', 'FAX'],   // fax beats the generic voice words
    ['فاكس',                   '+96824000022', 'FAX'],
    ['home',                   '+96824000030', 'HOME'],
    ['منزل',                   '+96824000031', 'HOME'],
    // Genuinely unclassifiable -> OTHER, never CELL and never VOICE.
    ['Zoom',                   '+96824000040', 'OTHER'],
    ['',                       '+96824000041', 'OTHER'],
];
$phones = ScanVcf::build([
    'name_en' => 'X', 'emails' => [],
    'phones' => array_map(function ($c) { return ['number' => $c[1], 'type' => $c[0]]; }, $cases),
], null);
foreach ($cases as $c) {
    check(sprintf('type %-18s -> %s', '"' . $c[0] . '"', $c[2]),
        strpos($phones, 'TEL;TYPE=' . $c[2] . ':' . $c[1]) !== false, true);
}
// TYPE=VOICE is never emitted: iOS stores it as a CUSTOM label and shows the
// user a phone labelled "VOICE" in raw caps that no locale translates.
check('TYPE=VOICE is never emitted', strpos($phones, 'TYPE=VOICE') !== false, false);
// Nothing falls through to CELL by accident: exactly the 6 CELL cases above.
check('only genuinely mobile labels get CELL', substr_count($phones, 'TYPE=CELL'), 6);

echo "ALL PASS\n";
