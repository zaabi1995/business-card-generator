<?php

$path = dirname(__DIR__, 2) . '/database/migrations/140_scan_account_identity.php';
$failures = 0;

function migrationCheck(string $label, bool $condition): void
{
    global $failures;
    echo ($condition ? 'PASS' : 'FAIL') . " $label\n";
    if (!$condition) {
        $failures++;
    }
}

$source = is_file($path) ? (string) file_get_contents($path) : '';

migrationCheck('migration 140 exists', $source !== '');
migrationCheck('immutable accounts table exists', strpos($source, 'scan_accounts') !== false);
migrationCheck('explicit memberships table exists', strpos($source, 'scan_account_memberships') !== false);
migrationCheck('verified login aliases table exists', strpos($source, 'scan_account_identifiers') !== false);
migrationCheck('token account binding is added', strpos($source, 'scan_api_tokens') !== false
    && strpos($source, 'account_id') !== false);
migrationCheck('password-hash merges are auditable', strpos($source, 'scan_identity_migration_audit') !== false
    && strpos($source, 'credential_hash_merge') !== false);
migrationCheck(
    'web user links require exact credential proof and are audited',
    strpos($source, 'BINARY u.password_hash = BINARY e.password_hash') !== false
        && strpos($source, 'scan_identity_user_link_audit') !== false
);
migrationCheck('migration does not merge identity by profile email', strpos($source, 'LOWER(TRIM(e.email))') === false);
migrationCheck('migration does not merge identity by profile phone', strpos($source, 'Phone::normalize') === false);
migrationCheck('orphan legacy tokens are revoked', strpos($source, "SET revoked = 1") !== false);

echo $failures === 0 ? "ALL PASS\n" : "$failures FAILED\n";
exit($failures === 0 ? 0 : 1);
