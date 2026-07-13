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

echo "ALL PASS\n";
