<?php
/**
 * Foreign entity IDs must stay references. Their owning domains publish the
 * descriptive bodies, which prevents one identity from drifting across sites.
 */
require_once dirname(__DIR__, 2) . '/includes/Seo.php';

$failures = 0;

function assertReference(bool $condition, string $label): void
{
    global $failures;
    echo ($condition ? 'PASS  ' : 'FAIL  ') . $label . "\n";
    if (!$condition) {
        $failures++;
    }
}

function foreignNodes($value): array
{
    if (!is_array($value)) {
        return [];
    }

    $found = [];
    $id = $value['@id'] ?? null;
    if (is_string($id) && strpos($id, 'https://bhd.om/#') === 0) {
        $found[] = $value;
    }
    foreach ($value as $child) {
        $found = array_merge($found, foreignNodes($child));
    }
    return $found;
}

function isTypedReference(array $node): bool
{
    $keys = array_keys($node);
    sort($keys);
    return $keys === ['@id', '@type']
        && is_string($node['@id'])
        && (is_string($node['@type']) || is_array($node['@type']));
}

$owner = Seo::organizationNode();
$foreign = foreignNodes($owner);

assertReference(
    ($owner['@id'] ?? null) === 'https://cardify.om/#organization' && count($owner) > 10,
    'Cardify keeps one substantive local organization body'
);
assertReference(count($foreign) === 2, 'the Cardify owner has exactly two BHD identity edges');
assertReference(
    array_reduce($foreign, fn (bool $ok, array $node): bool => $ok && isTypedReference($node), true),
    'every BHD identity edge is a typed reference with no foreign body'
);

$redFixture = [
    '@id' => 'https://bhd.om/#organization',
    '@type' => 'Organization',
    'name' => 'BHD Group',
];
assertReference(!isTypedReference($redFixture), 'a copied foreign name is detected as a body');

echo $failures === 0 ? "\nALL PASS\n" : "\n$failures FAILED\n";
exit($failures === 0 ? 0 : 1);
