<?php
// tests/php/scan_parser_test.php
require_once __DIR__ . '/../../includes/ScanParser.php';

function check($label, $actual, $expected) {
    $ok = $actual === $expected;
    echo ($ok ? "PASS" : "FAIL") . " $label\n";
    if (!$ok) { var_dump($actual); exit(1); }
}

$fenced = "Here is the result:\n```json\n{\"name_en\":\"Ali\",\"phones\":[]}\n```";
$out = ScanParser::extractJson($fenced);
check('parses fenced json', $out['name_en'], 'Ali');

$bare = '{"name_en":"Sara","emails":["s@x.om"]}';
check('parses bare json', ScanParser::extractJson($bare)['emails'][0], 's@x.om');

check('garbage returns null', ScanParser::extractJson('sorry, no card visible'), null);

$empty = ScanParser::emptyParsed();
check('empty shape has phones array', $empty['phones'], []);
check('empty shape has name_en', $empty['name_en'], '');

$dirty = [
    'name_en' => str_repeat('A', 600),
    'evil_key' => 'x',
    'phones' => [['number' => 99887766, 'type' => 'mobile', 'junk' => 1], 'not-an-array'],
    'emails' => ['a@b.om', ['nested']],
    'confidence' => 'high',
];
$clean = ScanParser::sanitizeDraft($dirty);
check('sanitize drops junk keys', isset($clean['evil_key']), false);
check('sanitize caps oversize string', mb_strlen($clean['name_en']), 500);
check('sanitize normalizes phones shape', $clean['phones'], [['number' => '99887766', 'type' => 'mobile']]);
check('sanitize drops non-string emails', $clean['emails'], ['a@b.om']);
check('sanitize forces confidence array', $clean['confidence'], []);
check('sanitize fills missing keys', $clean['website'], '');

// refine() success path now routes model output through sanitizeDraft;
// an off-shape model reply must come out canonical, not stored verbatim.
$offShape = ScanParser::extractJson('{"name_en": {"x":1}, "phones": ["raw"], "junk": 1}');
$refined = ScanParser::sanitizeDraft($offShape);
check('sanitize flattens object name_en', $refined['name_en'], '');
check('sanitize drops junk key from model output', isset($refined['junk']), false);
check('sanitize drops non-array phone entries', $refined['phones'], []);
echo "ALL PASS\n";
