<?php
require_once __DIR__ . '/../../includes/CardifyConvention.php';

$failures = 0;

function conventionCheck(string $label, bool $condition): void
{
    global $failures;
    if ($condition) {
        echo "PASS: {$label}\n";
        return;
    }
    echo "FAIL: {$label}\n";
    $failures++;
}

final class ConventionIdDb
{
    public array $queries = [];
    private array $occupied;

    public function __construct(array $occupied)
    {
        $this->occupied = array_fill_keys($occupied, true);
    }

    public function fetchOne(string $sql, array $params)
    {
        $this->queries[] = ['sql' => $sql, 'params' => $params];
        return isset($this->occupied[$params['i']]) ? ['id' => $params['i']] : false;
    }
}

$crossCompany = new ConventionIdDb(['ali']);
$allocated = CardifyConvention::employeeIdFromEmail(
    'ali@example.com',
    'different-company',
    $crossCompany
);
conventionCheck('employee IDs are allocated globally', $allocated === 'ali2');
conventionCheck(
    'allocator does not scope the primary key lookup by company',
    strpos($crossCompany->queries[0]['sql'], 'company_id') === false
        && !array_key_exists('c', $crossCompany->queries[0]['params'])
);

$base = str_repeat('a', 36);
$longId = new ConventionIdDb([$base]);
$allocatedLong = CardifyConvention::employeeIdFromEmail(
    str_repeat('a', 60) . '@example.com',
    'company-b',
    $longId
);
conventionCheck('base employee IDs respect VARCHAR(36)', strlen($base) === 36);
conventionCheck('collision suffixes respect VARCHAR(36)', strlen($allocatedLong) === 36);
conventionCheck(
    'collision suffix is retained after truncation',
    $allocatedLong === str_repeat('a', 35) . '2'
);

exit($failures === 0 ? 0 : 1);
