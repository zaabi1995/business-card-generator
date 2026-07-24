<?php

$root = dirname(__DIR__, 2);
$failures = 0;

function pagingCheck(string $label, bool $condition): void
{
    global $failures;
    echo ($condition ? 'PASS' : 'FAIL') . " $label\n";
    if (!$condition) {
        $failures++;
    }
}

$sync = (string) file_get_contents($root . '/api/scan/sync.php');
$directory = (string) file_get_contents($root . '/api/scan/company-directory.php');

pagingCheck(
    'scan sync uses a deterministic composite cursor',
    strpos($sync, 'updated_at > :cursor_after') !== false
        && strpos($sync, 'updated_at = :cursor_equal AND id > :cursor_id') !== false
        && strpos($sync, "\$params['cursor_after'] = \$cursorAt") !== false
        && strpos($sync, "\$params['cursor_equal'] = \$cursorAt") !== false
        && substr_count($sync, ':cursor_after') === 1
        && substr_count($sync, ':cursor_equal') === 1
        && strpos($sync, ':cursor_at') === false
        && strpos($sync, 'ORDER BY updated_at ASC, id ASC') !== false
        && strpos($sync, "'next_cursor'") !== false
);

pagingCheck(
    'company directory exposes complete paged results and server search',
    strpos($directory, '$offset + count($contacts)') !== false
        && strpos($directory, "'has_more'") !== false
        && strpos($directory, "\$_GET['q']") !== false
        && strpos($directory, 'name_en LIKE :query_name_en') !== false
        && strpos($directory, 'name_ar LIKE :query_name_ar') !== false
        && strpos($directory, 'email LIKE :query_email') !== false
);

echo $failures === 0 ? "ALL PASS\n" : "$failures FAILED\n";
exit($failures === 0 ? 0 : 1);
