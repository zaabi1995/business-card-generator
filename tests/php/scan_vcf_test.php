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
echo "ALL PASS\n";
