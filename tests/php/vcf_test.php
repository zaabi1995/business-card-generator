<?php
// tests/php/vcf_test.php
// Covers includes/VCF.php (generator A, the public/web path used by vcf.php and
// qr.php) plus the shared includes/VCardRfc.php folding + name policy.
require_once __DIR__ . '/../../includes/VCF.php';

function check($label, $actual, $expected) {
    $ok = $actual === $expected;
    echo ($ok ? "PASS" : "FAIL") . " $label\n";
    if (!$ok) { var_dump($actual, $expected); exit(1); }
}

/**
 * Unfold per RFC 2426 section 2.6: remove every CRLF that is followed by a
 * single space or tab, together with that one character. Independent of the
 * folding code under test, so fold() -> unfold() round-tripping is real proof.
 */
function unfold($vcf) {
    return preg_replace("/\r\n[ \t]/", '', $vcf);
}

function physicalLines($vcf) {
    return explode("\r\n", rtrim($vcf, "\r\n"));
}

// ---------------------------------------------------------------------------
// VCardRfc::fold, the octet arithmetic in isolation
// ---------------------------------------------------------------------------

check('fold leaves a 75-octet line alone', VCardRfc::fold(str_repeat('a', 75)), str_repeat('a', 75));
check('fold splits a 76-octet line', VCardRfc::fold(str_repeat('a', 76)), str_repeat('a', 75) . "\r\n" . ' a');
check('fold is a no-op on the empty string', VCardRfc::fold(''), '');

// Continuation lines pay one octet for their leading space, so a 150-octet
// ASCII line is 75 + 74 + 1, i.e. three physical lines, not two.
$long = VCardRfc::fold(str_repeat('a', 150));
$segs = explode("\r\n", $long);
check('150 ascii octets fold into 3 physical lines', count($segs), 3);
check('  segment 1 is 75 octets', strlen($segs[0]), 75);
check('  segment 2 is 75 octets including its space', strlen($segs[1]), 75);
check('  every continuation starts with one space', $segs[1][0] . $segs[2][0], '  ');
check('fold round-trips exactly', unfold($long), str_repeat('a', 150));

// The whole point: Arabic is 2 octets per letter, so a byte-slice at octet 75
// lands mid-sequence. Every emitted segment must still be valid UTF-8.
$arabic = 'ADR;TYPE=WORK;LANGUAGE=ar:;;' . str_repeat('ش', 120);
$foldedAr = VCardRfc::fold($arabic);
$allValid = true; $allWithinLimit = true;
foreach (explode("\r\n", $foldedAr) as $seg) {
    if (!mb_check_encoding($seg, 'UTF-8')) { $allValid = false; }
    if (strlen($seg) > 75) { $allWithinLimit = false; }
}
check('arabic fold: no segment splits a UTF-8 sequence', $allValid, true);
check('arabic fold: no segment exceeds 75 octets', $allWithinLimit, true);
check('arabic fold round-trips exactly', unfold($foldedAr), $arabic);

// A 4-octet sequence (emoji) must not be split either.
$emoji = str_repeat('x', 74) . '🇴🇲' . str_repeat('y', 40);
$foldedEmoji = VCardRfc::fold($emoji);
$emojiValid = true;
foreach (explode("\r\n", $foldedEmoji) as $seg) {
    if (!mb_check_encoding($seg, 'UTF-8')) { $emojiValid = false; }
}
check('4-octet sequence is never split', $emojiValid, true);
check('emoji fold round-trips exactly', unfold($foldedEmoji), $emoji);

// ---------------------------------------------------------------------------
// VCardRfc::splitName, the documented policy
// ---------------------------------------------------------------------------

check('1 token -> all given, no family',
    VCardRfc::splitName('Cher'), ['given' => 'Cher', 'middle' => '', 'family' => '']);
check('2 tokens -> given + family',
    VCardRfc::splitName('Sara AlHabsi'), ['given' => 'Sara', 'middle' => '', 'family' => 'AlHabsi']);
check('2 arabic tokens -> given + family',
    VCardRfc::splitName('سارة الحبسية'), ['given' => 'سارة', 'middle' => '', 'family' => 'الحبسية']);
// 3+ tokens: LAST token is the family name. This is what Apple's own fallback
// splitter does, and what Omani names need.
check('3 tokens -> given + middle + family(last)',
    VCardRfc::splitName('Abdul Rahman al-Balushi'),
    ['given' => 'Abdul', 'middle' => 'Rahman', 'family' => 'al-Balushi']);
check('4 tokens -> the owner of this product keeps his family name',
    VCardRfc::splitName('Ali Adnan Haider Darwish'),
    ['given' => 'Ali', 'middle' => 'Adnan Haider', 'family' => 'Darwish']);
check('4 arabic tokens -> family name is the last token',
    VCardRfc::splitName('علي عدنان حيدر درويش'),
    ['given' => 'علي', 'middle' => 'عدنان حيدر', 'family' => 'درويش']);
// The ONE case where declining to guess is right: an explicit patronymic chain.
check('arabic patronymic chain (بن) -> whole name is given, no family',
    VCardRfc::splitName('علي بن عدنان بن حيدر درويش'),
    ['given' => 'علي بن عدنان بن حيدر درويش', 'middle' => '', 'family' => '']);
check('latin patronymic chain (bin) -> whole name is given, no family',
    VCardRfc::splitName('Ali bin Adnan bin Haider Darwish'),
    ['given' => 'Ali bin Adnan bin Haider Darwish', 'middle' => '', 'family' => '']);
check('ibn is a particle too',
    VCardRfc::splitName('Ahmed ibn Rashid Al Said'),
    ['given' => 'Ahmed ibn Rashid Al Said', 'middle' => '', 'family' => '']);
check('bint is a particle too',
    VCardRfc::splitName('Fatma bint Salim Al Harthy'),
    ['given' => 'Fatma bint Salim Al Harthy', 'middle' => '', 'family' => '']);
// Particles are matched as whole TOKENS, never substrings.
check('"Robin" contains bin but is not a particle',
    VCardRfc::splitName('Robin Mark Hood'),
    ['given' => 'Robin', 'middle' => 'Mark', 'family' => 'Hood']);
check('"Ben" is a given name, not a particle',
    VCardRfc::splitName('Ben Jonathan Carter'),
    ['given' => 'Ben', 'middle' => 'Jonathan', 'family' => 'Carter']);
check('runs of whitespace collapse', VCardRfc::splitName("  Sara \t AlHabsi  "),
    ['given' => 'Sara', 'middle' => '', 'family' => 'AlHabsi']);
check('empty name yields empty parts', VCardRfc::splitName(''),
    ['given' => '', 'middle' => '', 'family' => '']);

// ---------------------------------------------------------------------------
// (a) English-only card
// ---------------------------------------------------------------------------

$en = [
    'id' => 'emp-1',
    'name_en' => 'Sara AlHabsi',
    'position_en' => 'Chief Executive Officer',
    'email' => 'sara@example.om',
    'mobile' => '+96891234567',
    'phone' => '+96824123456',
    'address_en' => 'Building 123, Sultan Qaboos Street',
    'city' => 'Muscat',
    'country' => 'Oman',
];
$vEn = VCF::generate($en, ['name' => 'Example LLC', 'website' => 'example.om']);

check('en: begins with BEGIN:VCARD', strpos($vEn, "BEGIN:VCARD\r\n"), 0);
check('en: has VERSION 3.0', strpos($vEn, "VERSION:3.0\r\n") !== false, true);
check('en: ends with END:VCARD', substr($vEn, -9), 'END:VCARD');
check('en: FN is the printed name', strpos($vEn, 'FN:Sara AlHabsi') !== false, true);
check('en: N splits on two tokens', strpos($vEn, 'N:AlHabsi;Sara;;;') !== false, true);
check('en: ORG present', strpos($vEn, 'ORG:Example LLC') !== false, true);
check('en: TITLE present', strpos($vEn, 'TITLE:Chief Executive Officer') !== false, true);
check('en: work tel', strpos($vEn, 'TEL;TYPE=WORK,VOICE:+96824123456') !== false, true);
check('en: cell tel', strpos($vEn, 'TEL;TYPE=CELL,VOICE:+96891234567') !== false, true);
check('en: no X-ALT-NAME when there is no arabic name', strpos($vEn, 'X-ALT-NAME') !== false, false);
check('en: X-PHONETIC-LAST-NAME is gone (it is an iOS sort key, not a name)',
    strpos($vEn, 'X-PHONETIC') !== false, false);

// ---------------------------------------------------------------------------
// (b) Arabic-only card. The regression that mattered: FN used to fall through
//     to the email address, so the contact had no readable name in iOS/Android.
// ---------------------------------------------------------------------------

$ar = [
    'id' => 'emp-2',
    'name_en' => '',
    'name_ar' => 'سارة الحبسية',
    'position_ar' => 'الرئيس التنفيذي',
    'email' => 'sara@example.om',
    'mobile' => '+96891234567',
    'address_ar' => 'مبنى رقم ١٢٣، شارع السلطان قابوس، الخوير، محافظة مسقط، سلطنة عُمان',
    'city_ar' => 'مسقط',
    'country_ar' => 'سلطنة عُمان',
];
$vAr = VCF::generate($ar, ['name_ar' => 'شركة المثال ش.م.م']);
$vArFlat = unfold($vAr);

check('ar-only: FN carries the arabic name, not the email',
    strpos($vArFlat, 'FN:سارة الحبسية') !== false, true);
check('ar-only: FN is not the email address',
    strpos($vArFlat, 'FN:sara@example.om') !== false, false);
check('ar-only: N carries the arabic name',
    strpos($vArFlat, 'N:الحبسية;سارة;;;') !== false, true);
check('ar-only: ORG carries the arabic company',
    strpos($vArFlat, 'ORG:شركة المثال ش.م.م') !== false, true);
check('ar-only: TITLE carries the arabic title',
    strpos($vArFlat, 'TITLE:الرئيس التنفيذي') !== false, true);
check('ar-only: ADR carries the arabic address',
    strpos($vArFlat, 'مبنى رقم ١٢٣') !== false, true);
// Arabic is already the primary, so the X- extras must not repeat it.
check('ar-only: no X-ALT-NAME duplicate', strpos($vArFlat, 'X-ALT-NAME') !== false, false);
check('ar-only: no X-ORG duplicate', strpos($vArFlat, 'X-ORG') !== false, false);
check('ar-only: no X-TITLE duplicate', strpos($vArFlat, 'X-TITLE') !== false, false);

// ---------------------------------------------------------------------------
// (c) Bilingual card with a long Arabic address and two phones
// ---------------------------------------------------------------------------

$bi = [
    'id' => 'emp-3',
    'name_en' => 'Sara AlHabsi',
    'name_ar' => 'سارة الحبسية',
    'position_en' => 'Chief Executive Officer',
    'position_ar' => 'الرئيس التنفيذي',
    'email' => 'sara@example.om',
    'phone' => '+96824123456',
    'mobile' => '+96891234567',
    'address_en' => 'Building 123, Sultan Qaboos Street, Al Khuwair',
    'city' => 'Muscat',
    'country' => 'Oman',
    'address_ar' => 'مبنى رقم ١٢٣، شارع السلطان قابوس، الخوير، محافظة مسقط، سلطنة عُمان',
    'city_ar' => 'مسقط',
    'country_ar' => 'سلطنة عُمان',
];
$vBi = VCF::generate($bi, ['name' => 'Example LLC', 'name_ar' => 'شركة المثال ش.م.م']);
$vBiFlat = unfold($vBi);

check('bi: FN is english', strpos($vBiFlat, 'FN:Sara AlHabsi') !== false, true);
check('bi: ORG is english', strpos($vBiFlat, 'ORG:Example LLC') !== false, true);
check('bi: TITLE is english', strpos($vBiFlat, 'TITLE:Chief Executive Officer') !== false, true);
// Arabic is a genuine alternate here, so the X- extras carry it.
check('bi: X-ALT-NAME carries the arabic name',
    strpos($vBiFlat, 'X-ALT-NAME;LANGUAGE=ar:سارة الحبسية') !== false, true);
check('bi: X-ORG carries the arabic company',
    strpos($vBiFlat, 'X-ORG;LANGUAGE=ar:شركة المثال ش.م.م') !== false, true);
check('bi: X-TITLE carries the arabic title',
    strpos($vBiFlat, 'X-TITLE;LANGUAGE=ar:الرئيس التنفيذي') !== false, true);
check('bi: both phones survive',
    substr_count($vBiFlat, 'TEL;TYPE=WORK,VOICE:+96824123456')
    + substr_count($vBiFlat, 'TEL;TYPE=CELL,VOICE:+96891234567'), 2);
check('bi: arabic ADR present', strpos($vBiFlat, 'LANGUAGE=ar:;;مبنى رقم ١٢٣') !== false, true);

// The whole-vCard folding invariant, across all three cards.
foreach (['en' => $vEn, 'ar' => $vAr, 'bi' => $vBi] as $label => $card) {
    $over = 0; $bad = 0;
    foreach (physicalLines($card) as $line) {
        if (strlen($line) > 75) { $over++; }
        if (!mb_check_encoding($line, 'UTF-8')) { $bad++; }
    }
    check("$label: no physical line exceeds 75 octets", $over, 0);
    check("$label: every physical line is valid UTF-8", $bad, 0);
}
// And the arabic card really did need folding, so the assertion above is not vacuous.
check('ar: the card actually contains a fold', strpos($vAr, "\r\n ") !== false, true);
check('bi: the card actually contains a fold', strpos($vBi, "\r\n ") !== false, true);

// ---------------------------------------------------------------------------
// Escaping, and the literal "0" that empty() used to eat
// ---------------------------------------------------------------------------

$zero = VCF::generate(
    ['name_en' => 'Zero Building', 'email' => 'z@example.om', 'address_en' => '0', 'city' => 'Muscat'],
    ['name' => 'Example LLC']
);
check('a street address of "0" survives', strpos($zero, 'ADR;TYPE=WORK:;;0;Muscat;;;') !== false, true);

$zeroOnly = VCF::generate(
    ['name_en' => 'PO Box Zero', 'email' => 'z@example.om', 'postal_code' => '0'],
    ['name' => 'Example LLC']
);
check('an address whose ONLY component is "0" is still emitted',
    strpos($zeroOnly, 'ADR;TYPE=WORK:;;;;;0;') !== false, true);
// The postal code is shared between the two language blocks. On its own it must
// not conjure an arabic ADR onto a card with no arabic data. (Caught by the
// vobject round-trip after the "0" fix, not by reading the code.)
check('a shared postal code alone does not create an arabic ADR',
    strpos($zeroOnly, 'LANGUAGE=ar') !== false, false);
check('same for the english-only card with a postal code',
    strpos(VCF::generate($en + ['postal_code' => '0'], ['name' => 'Example LLC']), 'LANGUAGE=ar') !== false, false);
// An arabic component still produces the arabic ADR, postal code included.
$arPostal = VCF::generate(
    ['name_en' => 'X', 'email' => 'x@example.om', 'city_ar' => 'مسقط', 'postal_code' => '0'],
    ['name' => 'Example LLC']
);
check('an arabic component does produce the arabic ADR, carrying the shared postal code',
    strpos($arPostal, 'ADR;TYPE=WORK;LANGUAGE=ar:;;;مسقط;;0;') !== false, true);

$esc = VCF::generate(
    ['name_en' => 'Back\\slash, Semi;Colon', 'email' => 'e@example.om'],
    ['name' => 'A,B;C']
);
check('backslash is escaped first (single literal becomes double)',
    strpos($esc, 'FN:Back\\\\slash\\, Semi\\;Colon') !== false, true);
check('comma and semicolon in ORG are escaped',
    strpos($esc, 'ORG:A\\,B\\;C') !== false, true);

$crlf = VCF::generate(
    ['name_en' => "Line\r\nBreak", 'email' => 'e@example.om'], ['name' => 'X']
);
check('a CR/LF inside a value cannot break the line structure',
    strpos($crlf, 'FN:Line\\nBreak') !== false, true);
check('no stray CR survives outside the CRLF line breaks',
    substr_count($crlf, "\r"), count(physicalLines($crlf)) - 1);

// ---------------------------------------------------------------------------
// Explicit first_name/last_name columns stay authoritative
// ---------------------------------------------------------------------------

$explicit = VCF::generate(
    ['first_name' => 'Abdul Rahman', 'last_name' => 'al-Balushi', 'email' => 'a@example.om'],
    ['name' => 'X']
);
check('explicit name columns are used verbatim',
    strpos($explicit, 'N:al-Balushi;Abdul Rahman;;;') !== false, true);
check('explicit columns still build FN',
    strpos($explicit, 'FN:Abdul Rahman al-Balushi') !== false, true);

// Derived (no explicit columns): 3+ tokens take the last token as the family
// name, matching Apple's own fallback splitter.
$derived = VCF::generate(['name_en' => 'Abdul Rahman al-Balushi', 'email' => 'a@example.om'], ['name' => 'X']);
check('derived 3-token name keeps the real family name',
    strpos($derived, 'N:al-Balushi;Abdul;Rahman;;') !== false, true);
check('derived FN is still the full printed name',
    strpos($derived, 'FN:Abdul Rahman al-Balushi') !== false, true);

$owner = VCF::generate(['name_en' => 'Ali Adnan Haider Darwish', 'email' => 'ali@bhd.om'], ['name' => 'X']);
check('4-token Omani name -> family Darwish, middle Adnan Haider',
    strpos($owner, 'N:Darwish;Ali;Adnan Haider;;') !== false, true);

$chainCard = VCF::generate(['name_en' => 'Ali bin Adnan bin Haider Darwish', 'email' => 'ali@bhd.om'], ['name' => 'X']);
check('patronymic chain asserts no family name',
    strpos($chainCard, 'N:;Ali bin Adnan bin Haider Darwish;;;') !== false, true);

// ---------------------------------------------------------------------------
// Strict-empty across EVERY field, not just ADR. empty() is true for "0", so
// each of these used to be dropped silently.
// ---------------------------------------------------------------------------

$zeros = VCF::generate([
    'id' => '0', 'name_en' => 'Zero Everything',
    'phone' => '0', 'mobile' => '00', 'fax' => '0',
    'phone_ar' => '0', 'mobile_ar' => '0',
    'email' => '0', 'website' => '0', 'website_ar' => '0',
    'note' => '0', 'department' => '0',
    'linkedin' => '0', 'twitter' => '0',
    'photo' => '0',
], ['name' => '0']);
foreach ([
    'ORG'                        => 'ORG:0',
    'TEL work'                   => 'TEL;TYPE=WORK,VOICE:0',
    'TEL cell'                   => 'TEL;TYPE=CELL,VOICE:00',
    'TEL fax'                    => 'TEL;TYPE=FAX:0',
    'X-TEL-AR work'              => 'X-TEL-AR;TYPE=WORK:0',
    'X-TEL-AR cell'              => 'X-TEL-AR;TYPE=CELL:0',
    'EMAIL'                      => 'EMAIL;TYPE=INTERNET,WORK:0',
    'URL (protocol prepended)'   => 'URL:https://0',
    'X-URL'                      => 'X-URL;LANGUAGE=ar:https://0',
    'NOTE'                       => 'NOTE:0',
    'X-DEPARTMENT'               => 'X-DEPARTMENT:0',
    'X-SOCIALPROFILE linkedin'   => 'X-SOCIALPROFILE;TYPE=linkedin:0',
    'X-SOCIALPROFILE twitter'    => 'X-SOCIALPROFILE;TYPE=twitter:0',
    'UID'                        => 'UID:0',
] as $label => $needle) {
    check("a value of \"0\" survives in $label", strpos($zeros, $needle) !== false, true);
}
// PHOTO is the one "0" that must still be dropped: it is not a valid URL.
check('a photo of "0" is still dropped, it is not a URL', strpos($zeros, 'PHOTO') !== false, false);
// And a genuinely absent field stays absent.
$absent = VCF::generate(['name_en' => 'Nothing Set', 'email' => 'n@example.om'], ['name' => 'X']);
foreach (['TEL', 'X-TEL-AR', 'X-URL', 'NOTE', 'X-DEPARTMENT', 'X-SOCIALPROFILE', 'PHOTO', 'ADR'] as $prop) {
    check("an absent $prop is not emitted", strpos($absent, $prop . ':') !== false || strpos($absent, $prop . ';') !== false, false);
}

// This file must not emit CHARSET at all: it is a vCard 2.1 parameter, undefined
// in the RFC 2426 the file declares, and measured as a no-op on iOS.
foreach (['en' => $vEn, 'ar' => $vAr, 'bi' => $vBi, 'zeros' => $zeros] as $label => $card) {
    check("$label: no CHARSET parameter is emitted", strpos($card, 'CHARSET') !== false, false);
}

echo "ALL PASS\n";
