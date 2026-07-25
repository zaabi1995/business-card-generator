<?php
require_once __DIR__ . '/../../includes/ScanCardLookup.php';

$failures = 0;
function lookupCheck(string $label, bool $condition): void
{
    global $failures;
    echo ($condition ? 'PASS' : 'FAIL') . " $label\n";
    if (!$condition) {
        $failures++;
    }
}

$identifiers = ScanCardLookup::identifiers([
    'emails' => [' Ali@BHD.OM ', 'info@bhd.om', 'not-an-email'],
    'phones' => [
        ['number' => '7161 6161'],
        ['number' => '+968 7161 6161'],
        ['number' => '123'],
    ],
    'name_en' => 'Must not be used for lookup',
]);

lookupCheck('keeps valid personal email only', $identifiers['emails'] === ['ali@bhd.om']);
lookupCheck('normalizes and deduplicates Oman phone', $identifiers['phones'] === ['+96871616161']);

$ali = [
    'id' => 'emp-ali',
    'email' => 'ali@bhd.om',
    'mobile' => '+968 7161 6161',
    'phone' => '',
];
$other = [
    'id' => 'emp-other',
    'email' => 'other@example.om',
    'mobile' => '91234567',
    'phone' => '',
];

lookupCheck('exact email matches', ScanCardLookup::employeeMatches($ali, ['ali@bhd.om'], []));
lookupCheck('normalized phone matches', ScanCardLookup::employeeMatches($ali, [], ['+96871616161']));
lookupCheck('generic office phone never identifies an employee', !ScanCardLookup::employeeMatches([
    'id' => 'emp-office',
    'email' => 'office-person@example.om',
    'mobile' => '91234567',
    'phone' => '+968 7161 6161',
], [], ['+96871616161']));
lookupCheck('name alone never matches', !ScanCardLookup::employeeMatches($ali, [], []));
lookupCheck('unrelated employee does not match', !ScanCardLookup::employeeMatches(
    $other,
    $identifiers['emails'],
    $identifiers['phones']
));

$unique = ScanCardLookup::uniqueMatchedEmployees(
    [$ali, $ali, $other],
    $identifiers['emails'],
    $identifiers['phones']
);
lookupCheck('deduplicates the same employee across identifiers', count($unique) === 1);
lookupCheck('keeps the unique employee id', ($unique[0]['id'] ?? null) === 'emp-ali');

$ambiguous = ScanCardLookup::uniqueMatchedEmployees(
    [
        $ali,
        [
            'id' => 'emp-second',
            'email' => 'second@example.om',
            'mobile' => '71616161',
            'phone' => '',
        ],
    ],
    [],
    ['+96871616161']
);
lookupCheck('reports multiple exact owners as ambiguous candidates', count($ambiguous) === 2);

$endpoint = (string) file_get_contents(__DIR__ . '/../../api/scan/lookup-card.php');
lookupCheck('endpoint is authenticated', strpos($endpoint, 'ScanAuth::requireEmployee()') !== false);
lookupCheck('endpoint is rate limited', strpos($endpoint, "scanRateLimit(\$ctx, 'lookup-card'") !== false);
lookupCheck('endpoint requires active nondeleted employees', strpos($endpoint, "status = 'active'") !== false
    && strpos($endpoint, 'deleted_at IS NULL') !== false);
lookupCheck('endpoint never accepts a name query', strpos($endpoint, "body['name") === false);

echo $failures === 0 ? "ALL PASS\n" : "$failures FAILED\n";
exit($failures === 0 ? 0 : 1);
