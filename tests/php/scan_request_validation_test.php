<?php
require_once dirname(__DIR__, 2) . '/api/scan/_request.php';

$cases = [
    [['confirm' => true], true],
    [['confirm' => false], false],
    [['confirm' => 'true'], false],
    [['confirm' => 'false'], false],
    [['confirm' => 1], false],
    [['confirm' => 0], false],
    [['confirm' => []], false],
    [['confirm' => ['value' => true]], false],
    [[], false],
];

$failures = 0;
foreach ($cases as $index => [$body, $expected]) {
    $actual = scanRequestHasExactTrue($body, 'confirm');
    $passed = $actual === $expected;
    echo ($passed ? 'PASS' : 'FAIL')
        . ' strict confirmation case '
        . ($index + 1)
        . "\n";
    if (!$passed) {
        $failures++;
    }
}

echo $failures === 0 ? "ALL PASS\n" : "$failures FAILED\n";
exit($failures === 0 ? 0 : 1);
