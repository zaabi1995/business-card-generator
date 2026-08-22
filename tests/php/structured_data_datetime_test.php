<?php
/**
 * Structured-data DateTime regression tests.
 *
 * Run: php tests/php/structured_data_datetime_test.php
 */
require_once dirname(__DIR__, 2) . '/includes/StructuredDataDate.php';

date_default_timezone_set('Asia/Muscat');

$failures = 0;
function structuredDateCheck(bool $condition, string $label, string $detail = ''): void
{
    global $failures;
    echo ($condition ? 'PASS  ' : 'FAIL  ') . $label;
    if (!$condition && $detail !== '') echo ' (' . $detail . ')';
    echo "\n";
    if (!$condition) $failures++;
}

$valid = [
    'bare database timestamp is UTC, independent of the PHP display zone' => [
        '2026-07-30 12:34:56',
        '2026-07-30T12:34:56Z',
    ],
    'explicit Muscat offset converts to the same UTC instant' => [
        '2026-07-30T16:34:56+04:00',
        '2026-07-30T12:34:56Z',
    ],
    'basic ISO offset converts to the same UTC instant' => [
        '2026-07-30T16:34:56+0400',
        '2026-07-30T12:34:56Z',
    ],
    'negative offset converts to the same UTC instant' => [
        '2026-07-30T07:34:56-05:00',
        '2026-07-30T12:34:56Z',
    ],
    'Zulu DateTime remains in UTC' => [
        '2026-07-30T12:34:56Z',
        '2026-07-30T12:34:56Z',
    ],
    'fractional seconds stay factual at schema second precision' => [
        '2026-07-30T12:34:56.123Z',
        '2026-07-30T12:34:56Z',
    ],
];
foreach ($valid as $label => $case) {
    $actual = StructuredDataDate::fromDatabase($case[0]);
    structuredDateCheck($actual === $case[1], $label, (string) $actual);
}

structuredDateCheck(
    StructuredDataDate::fromUnixTimestamp(1785414896) === '2026-07-30T12:34:56Z',
    'factual file timestamp formats as explicit UTC DateTime'
);
structuredDateCheck(
    StructuredDataDate::fromUnixTimestamp('1785414896') === null,
    'a stringified file timestamp is refused rather than guessed'
);

$invalid = [
    null,
    '',
    '0000-00-00 00:00:00',
    '2026-07-30',
    '2026-07-30 12:34',
    '2026-02-30 12:34:56',
    '2026-13-01 12:34:56',
    '2026-07-30T12:34:56',
    '2026-07-30T12:34:56+24:00',
    'now',
    'today',
    0,
    1785414896,
];
foreach ($invalid as $value) {
    structuredDateCheck(
        StructuredDataDate::fromDatabase($value) === null,
        'invalid or non-database value is omitted',
        var_export($value, true)
    );
}

$baseNode = [
    '@context' => 'https://schema.org',
    '@type' => 'ProfilePage',
    'url' => 'https://cardify.om/companies/example',
    'inLanguage' => 'en',
    'name' => 'Example Company',
    'description' => 'Example profile description.',
    'mainEntity' => ['@id' => 'https://cardify.om/companies/example#organization'],
    'dateModified' => 'not-a-date',
];
$withDate = StructuredDataDate::withDateModified($baseNode, '2026-07-30 12:34:56');
$withoutInvalid = StructuredDataDate::withDateModified($baseNode, '2026-07-30');
$withoutNull = StructuredDataDate::withDateModified($baseNode, null);

structuredDateCheck(
    ($withDate['dateModified'] ?? null) === '2026-07-30T12:34:56Z',
    'a valid source adds canonical ProfilePage dateModified'
);
structuredDateCheck(
    ($withDate['url'] ?? '') === $baseNode['url']
        && ($withDate['inLanguage'] ?? '') === $baseNode['inLanguage']
        && ($withDate['name'] ?? '') === $baseNode['name']
        && ($withDate['description'] ?? '') === $baseNode['description']
        && ($withDate['mainEntity']['@id'] ?? '') === $baseNode['mainEntity']['@id'],
    'DateTime normalization preserves the complete ProfilePage identity'
);
structuredDateCheck(
    !array_key_exists('dateModified', $withoutInvalid)
        && ($withoutInvalid['mainEntity']['@id'] ?? '') === $baseNode['mainEntity']['@id']
        && ($withoutInvalid['url'] ?? '') === $baseNode['url']
        && ($withoutInvalid['inLanguage'] ?? '') === $baseNode['inLanguage'],
    'an invalid optional date is removed without suppressing the ProfilePage'
);
structuredDateCheck(
    !array_key_exists('dateModified', $withoutNull)
        && ($withoutNull['@type'] ?? '') === 'ProfilePage',
    'a null optional date is omitted without suppressing the ProfilePage'
);

echo $failures === 0 ? "\nALL PASS\n" : "\n$failures FAILED\n";
exit($failures === 0 ? 0 : 1);
