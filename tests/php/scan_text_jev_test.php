<?php
/**
 * ScanTextJev: Jev picks card lines, code takes phones, emails and the website.
 * Every returned value must be a line of the card (nothing invented), Arabic
 * questions must only offer Arabic lines, and Jev down must mean null so the
 * model fallback runs as before.
 *
 * Run: php tests/php/scan_text_jev_test.php
 */

require_once __DIR__ . '/../../includes/ScanTextJev.php';

$fails = 0;
function check(bool $ok, string $what): void
{
    global $fails;
    echo ($ok ? 'ok   ' : 'FAIL ') . $what . "\n";
    if (!$ok) $fails++;
}

$text = "BHD Printing & Designing\nAli Adnan Haider Darwish\nChief Executive Officer\nعلي عدنان حيدر درويش\nالرئيس التنفيذي\nM: +968 7161 6161\nTel: +968 2400 0000\nali@bhd.om\nwww.bhd.om\nAl Khuwair, Muscat";
$lines = ScanTextJev::lines($text);

$q = ScanTextJev::questions($lines);
$arOptions = array_values(array_diff_key($q['title_ar']['criteria'], ['none' => 1]));
check($arOptions === ['علي عدنان حيدر درويش', 'الرئيس التنفيذي'], 'Arabic questions offer only the Arabic lines');
$enOptions = array_values(array_diff_key($q['name_en']['criteria'], ['none' => 1]));
check(!in_array('ali@bhd.om', $enOptions, true) && !in_array('www.bhd.om', $enOptions, true) && !in_array('M: +968 7161 6161', $enOptions, true),
    'emails, websites and phone lines are never options');
check(strpos($q['name_en']['instructions'], '`card_lines`') !== false, 'the question names the state field');

$idx = fn (string $line) => 'l' . array_search($line, $lines, true);
$fake = function ($state, $questions) use ($idx) {
    return [
        'name_en' => ['choice' => $idx('Ali Adnan Haider Darwish'), 'confidence' => 0.97],
        'name_ar' => ['choice' => $idx('علي عدنان حيدر درويش'), 'confidence' => 0.95],
        'title_en' => ['choice' => $idx('Chief Executive Officer'), 'confidence' => 0.9],
        'title_ar' => ['choice' => $idx('الرئيس التنفيذي'), 'confidence' => 0.9],
        'company_en' => ['choice' => $idx('BHD Printing & Designing'), 'confidence' => 0.59],
        'company_ar' => ['choice' => 'none', 'confidence' => 0.9],
        'address_en' => ['choice' => $idx('Al Khuwair, Muscat'), 'confidence' => 0.8],
        'address_ar' => ['choice' => 'l99', 'confidence' => 0.99],
    ];
};
$p = ScanTextJev::parse($text, $fake);
check($p['name_en'] === 'Ali Adnan Haider Darwish' && $p['name_ar'] === 'علي عدنان حيدر درويش', 'names come back verbatim');
check($p['company_en'] === '', 'a pick under 0.6 is dropped');
check($p['address_ar'] === '', 'a choice that is not a card line is dropped');
check($p['emails'] === ['ali@bhd.om'] && $p['website'] === 'www.bhd.om', 'email and website come from patterns');
check(count($p['phones']) === 2 && $p['phones'][0]['type'] === 'mobile' && $p['phones'][1]['type'] === 'work', 'phones typed from their line');
foreach (['name_en', 'name_ar', 'title_en', 'title_ar', 'company_en', 'company_ar', 'address_en', 'address_ar'] as $k) {
    if ($p[$k] !== '' && !in_array($p[$k], $lines, true)) { check(false, "$k is not a card line"); }
}
check(ScanTextJev::parse($text, fn () => null) === null, 'Jev down gives null (the model fallback runs)');
check(ScanTextJev::parse($text, fn () => ['name_en' => ['choice' => 'none', 'confidence' => 1], 'name_ar' => ['choice' => 'none', 'confidence' => 1]]) === null,
    'no name in either language gives null');
check(ScanTextJev::phones(['Fax: ٢٤٠٠٠٠٠١ ٩٦٨'])[0]['type'] === 'fax', 'Arabic-Indic digits and fax lines');

echo $fails ? "\n$fails failure(s)\n" : "\nall passed\n";
exit($fails ? 1 : 0);
