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
echo "ALL PASS\n";
