<?php
$root = dirname(__DIR__, 2);
$failures = 0;

function pushRegistrationCheck(string $label, bool $condition): void
{
    global $failures;
    echo ($condition ? 'PASS' : 'FAIL') . " $label\n";
    if (!$condition) {
        $failures++;
    }
}

$registerPush = (string) file_get_contents($root . '/api/scan/register-push.php');

pushRegistrationCheck(
    'push registration remains authenticated and POST-only',
    strpos($registerPush, "ScanAuth::requireEmployee()") !== false
        && strpos($registerPush, "\$_SERVER['REQUEST_METHOD'] !== 'POST'") !== false
);

pushRegistrationCheck(
    'push token validation still rejects empty and oversized tokens',
    strpos($registerPush, "\$token === '' || strlen(\$token) > 255") !== false
        && strpos($registerPush, "'invalid_token'") !== false
);

pushRegistrationCheck(
    'unregister is enabled only by a JSON boolean true',
    strpos(
        $registerPush,
        "\$unregister = (\$body['unregister'] ?? false) === true;"
    ) !== false
);

pushRegistrationCheck(
    'unregister deletes only the authenticated employee matching token',
    preg_match(
        '/DELETE FROM push_tokens\s+WHERE employee_id = :e AND token = :t/s',
        $registerPush
    ) === 1
        && strpos(
            $registerPush,
            "execute(['e' => \$ctx['employee_id'], 't' => \$token])"
        ) !== false
);

pushRegistrationCheck(
    'normal registration keeps token upsert behavior',
    strpos($registerPush, 'INSERT INTO push_tokens') !== false
        && strpos($registerPush, 'ON DUPLICATE KEY UPDATE') !== false
        && strpos($registerPush, 'employee_id = VALUES(employee_id)') !== false
);

echo $failures === 0 ? "ALL PASS\n" : "$failures FAILED\n";
exit($failures === 0 ? 0 : 1);
