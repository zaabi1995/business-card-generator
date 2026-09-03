<?php
/**
 * PrintShop::create() INSERT must match the live print_shops table.
 * Signup POST fails when the SQL names logo_path / pricing_tiers /
 * min_quantity, which are not columns on cardify.om.
 *
 * Run: php tests/php/print_shop_create_schema_test.php
 */

$root = dirname(__DIR__, 2);
$fails = 0;

function createSchemaCheck(string $label, $ok, string $detail = ''): void
{
    global $fails;
    if (!$ok) {
        $fails++;
    }
    printf("[%s] %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail !== '' ? ' , ' . $detail : '');
}

$fixturePath = $root . '/tests/fixtures/print_shops_columns.php';
createSchemaCheck('documented print_shops column fixture exists', is_file($fixturePath));
$fixture = is_file($fixturePath) ? require $fixturePath : [];
createSchemaCheck('fixture is a non-empty list of column names', is_array($fixture) && $fixture !== []);

$src = (string) file_get_contents($root . '/includes/PrintShop.php');
createSchemaCheck(
    'PrintShop::create exists',
    (bool) preg_match('/function create\s*\(/', $src)
);

if (!preg_match(
    '/public static function create\s*\(.*?\bINSERT INTO print_shops\s*\((.*?)\)\s*VALUES/s',
    $src,
    $m
)) {
    echo "FAIL create() INSERT INTO print_shops column list is parseable\n";
    exit(1);
}

$cols = preg_split('/\s*,\s*/', trim(preg_replace('/\s+/', ' ', $m[1])));
$cols = array_values(array_filter($cols));

createSchemaCheck(
    'create() INSERT names logo_url, not logo_path',
    in_array('logo_url', $cols, true) && !in_array('logo_path', $cols, true)
);
createSchemaCheck(
    'create() INSERT names pricing, not pricing_tiers',
    in_array('pricing', $cols, true) && !in_array('pricing_tiers', $cols, true)
);
createSchemaCheck(
    'create() INSERT names min_order_quantity, not min_quantity',
    in_array('min_order_quantity', $cols, true) && !in_array('min_quantity', $cols, true)
);

$unknown = array_values(array_diff($cols, $fixture));
createSchemaCheck(
    'create() INSERT columns are all in the documented fixture',
    $unknown === [],
    $unknown === [] ? '' : 'unknown: ' . implode(', ', $unknown)
);

if ($fails > 0) {
    echo "FAILED {$fails}\n";
    exit(1);
}

echo "ALL PASS\n";
