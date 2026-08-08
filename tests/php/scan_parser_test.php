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

// The device splits an extension off the number and sends it as its own field.
// sanitizeDraft used to rebuild each phone as {number,type} only, so the extension
// was dropped here; since sync overwrites the local parse from the server, it
// disappeared from the device on the next pull. These pin the round trip.
$ext = fn($p) => ScanParser::sanitizeDraft(['phones' => [$p]])['phones'][0];
check('extension survives the round trip',
    $ext(['number' => '+96824556677', 'type' => 'work', 'ext' => '214']),
    ['number' => '+96824556677', 'type' => 'work', 'ext' => '214']);
// A card without an extension must serialise exactly as it did before this change,
// so no previously stored parse shifts shape.
check('no extension means no ext key',
    $ext(['number' => '+96891234567', 'type' => 'mobile']),
    ['number' => '+96891234567', 'type' => 'mobile']);
check('extension is reduced to digits',
    $ext(['number' => '+96824556677', 'type' => 'work', 'ext' => 'x 2-1-4'])['ext'], '214');
// An extension with no digits is not an extension. Drop it, keep the number.
check('non-numeric extension is dropped, number kept',
    $ext(['number' => '+96824556677', 'type' => 'work', 'ext' => 'abc']),
    ['number' => '+96824556677', 'type' => 'work']);
check('extension is length-capped',
    $ext(['number' => '+96824556677', 'type' => 'work', 'ext' => '1234567890123'])['ext'], '12345678');
check('unknown sibling keys are still dropped',
    isset($ext(['number' => '+96824556677', 'type' => 'work', 'evil' => 'x'])['evil']), false);

// The device routes a LinkedIn profile URL to its own field so it stops landing in
// `website` on a card that prints no website. sanitizeDraft dropped the key, and because
// sync overwrites the local parse from the server, the value was wiped on the next pull.
$li = fn($v) => ScanParser::sanitizeDraft(['linkedin' => $v, 'name_en' => 'Salma']);
check('linkedin survives the round trip',
    $li('linkedin.com/in/salma-al-hashmi')['linkedin'], 'linkedin.com/in/salma-al-hashmi');
// A card with no LinkedIn must serialise exactly as it did before this change.
check('no linkedin means no linkedin key',
    array_key_exists('linkedin', ScanParser::sanitizeDraft(['name_en' => 'Salma'])), false);
check('empty linkedin is dropped, not stored blank',
    array_key_exists('linkedin', $li('')), false);
check('non-scalar linkedin is dropped', array_key_exists('linkedin', $li(['evil' => 1])), false);
check('linkedin is length-capped', strlen($li(str_repeat('x', 900))['linkedin']), 500);
// Both device-added fields must survive the SAME draft; they are handled separately.
$both = ScanParser::sanitizeDraft([
    'phones' => [['number' => '+96824556677', 'type' => 'work', 'ext' => '214']],
    'linkedin' => 'linkedin.com/in/x',
]);
check('ext and linkedin survive together',
    [$both['phones'][0]['ext'], $both['linkedin']], ['214', 'linkedin.com/in/x']);
// Merge provenance is the only record of what a merge destroyed. The server stores
// it verbatim and never interprets it. The rule that matters most here is the size
// one: truncating a valid JSON array at the column limit yields one with no closing
// bracket, which then fails to parse on EVERY pull forever, and no downstream check
// can catch it because the server itself created the broken value after validating
// a good one. Reject, never truncate.
$mp = fn($v) => ScanParser::mergeProvenanceOrNull($v);
check('provenance keeps a valid JSON array', $mp('[{"op":"a"}]'), '[{"op":"a"}]');
check('provenance keeps a valid JSON object', $mp('{"a":1}'), '{"a":1}');
check('malformed JSON is rejected', $mp('[{"op":'), null);
check('a bare JSON scalar is not a history', $mp('"hello"'), null);
check('empty is rejected', $mp(''), null);
check('non-scalar input is rejected', $mp(['evil']), null);
check('null is rejected', $mp(null), null);
$oversized = json_encode(array_fill(0, 4000, ['op' => 'x', 'kept' => 'yyyyyyyyyyyyyyyy']));
check('oversized valid JSON is REJECTED, not truncated', $mp($oversized), null);
check('  and the input really was over the cap', strlen($oversized) > ScanParser::MERGE_PROVENANCE_MAX, true);
// Exactly at the cap must still be accepted, or the boundary is off by one.
$pad = ScanParser::MERGE_PROVENANCE_MAX - strlen('["",""]') - 2;
$atCap = json_encode(['', str_repeat('a', $pad)]);
check('a payload exactly at the cap is kept', $mp($atCap) !== null, true);
echo "ALL PASS\n";
