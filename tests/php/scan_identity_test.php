<?php
require_once __DIR__ . '/../../includes/ScanIdentity.php';

$failures = 0;

function identityCheck(string $label, $actual, $expected): void
{
    global $failures;
    $ok = $actual === $expected;
    echo ($ok ? 'PASS' : 'FAIL') . " $label\n";
    if (!$ok) {
        $failures++;
        echo '  got: ' . var_export($actual, true) . "\n";
        echo '  expected: ' . var_export($expected, true) . "\n";
    }
}

final class IdentityAliasDb
{
    public $accounts = [];
    public $identifiers = [];
    public $insertCount = 0;

    public function seedAccount(string $accountId, string $passwordHash = ''): void
    {
        $this->accounts[$accountId] = [
            'password_hash' => $passwordHash,
            'user_id' => null,
            'status' => 'active',
        ];
    }

    public function seedIdentifier(
        string $identifier,
        string $type,
        string $accountId,
        ?string $verifiedAt
    ): void {
        $hash = ScanIdentity::identifierHash($identifier, $type);
        $this->identifiers[$hash] = [
            'identifier_hash' => $hash,
            'account_id' => $accountId,
            'identifier_type' => $type,
            'identifier_value' => trim(strtolower($identifier)),
            'verified_at' => $verifiedAt,
            'source' => 'test',
        ];
    }

    public function fetchOne(string $sql, array $params = [])
    {
        if (strpos($sql, 'FROM scan_account_identifiers i') !== false) {
            $row = $this->identifiers[$params['identifier_hash']] ?? null;
            if (!is_array($row)) {
                return false;
            }
            if ($row['identifier_type'] !== $params['identifier_type']) {
                return false;
            }
            if (
                strpos($sql, 'i.verified_at IS NOT NULL') !== false
                && empty($row['verified_at'])
            ) {
                return false;
            }
            $account = $this->accounts[$row['account_id']] ?? null;
            if (!is_array($account) || $account['status'] !== 'active') {
                return false;
            }
            return [
                'account_id' => $row['account_id'],
                'password_hash' => $account['password_hash'],
                'user_id' => $account['user_id'],
                'verified_at' => $row['verified_at'],
            ];
        }

        if (strpos($sql, 'SELECT account_id, verified_at') !== false) {
            $row = $this->identifiers[$params['identifier_hash']] ?? null;
            if (
                !is_array($row)
                || $row['identifier_type'] !== $params['identifier_type']
            ) {
                return false;
            }
            return [
                'account_id' => $row['account_id'],
                'verified_at' => $row['verified_at'],
            ];
        }

        if (strpos($sql, 'SELECT 1 AS found') !== false) {
            foreach ($this->identifiers as $row) {
                if (
                    $row['account_id'] === $params['account_id']
                    && $row['identifier_type'] === $params['identifier_type']
                    && (
                        strpos($sql, 'verified_at IS NOT NULL') === false
                        || !empty($row['verified_at'])
                    )
                ) {
                    return ['found' => 1];
                }
            }
            return false;
        }

        throw new RuntimeException('Unexpected test query');
    }

    public function update(
        string $table,
        array $data,
        string $where,
        array $whereParams = []
    ): int {
        if ($table !== 'scan_account_identifiers') {
            throw new RuntimeException('Unexpected test update');
        }
        $hash = $whereParams['where_hash'];
        $row = $this->identifiers[$hash] ?? null;
        if (!is_array($row)) {
            return 0;
        }
        if (
            strpos($where, 'verified_at IS NULL') !== false
            && !empty($row['verified_at'])
        ) {
            return 0;
        }
        $this->identifiers[$hash] = array_merge($row, $data);
        return 1;
    }

    public function insert(string $table, array $data): void
    {
        if ($table !== 'scan_account_identifiers') {
            throw new RuntimeException('Unexpected test insert');
        }
        $hash = $data['identifier_hash'];
        if (isset($this->identifiers[$hash])) {
            throw new RuntimeException('duplicate identifier');
        }
        $this->insertCount++;
        $this->identifiers[$hash] = $data;
    }
}

identityCheck(
    'email aliases canonicalize before hashing',
    ScanIdentity::identifierHash(' Ali@Example.OM ', 'email'),
    ScanIdentity::identifierHash('ali@example.om', 'email')
);
identityCheck(
    'identifier type is part of the hash namespace',
    ScanIdentity::identifierHash('ali@example.om', 'email')
        === ScanIdentity::identifierHash('ali@example.om', 'phone'),
    false
);
identityCheck(
    'identical non-empty credentials across different companies may merge',
    ScanIdentity::canMergeLegacyMembership(
        ['company_id' => 'company-a', 'password_hash' => '$2y$credential'],
        ['company_id' => 'company-b', 'password_hash' => '$2y$credential']
    ),
    true
);
identityCheck(
    'PII equality never proves a legacy merge',
    ScanIdentity::canMergeLegacyMembership(
        ['company_id' => 'company-a', 'password_hash' => '', 'email' => 'same@example.om'],
        ['company_id' => 'company-b', 'password_hash' => '', 'email' => 'same@example.om']
    ),
    false
);
identityCheck(
    'same-company rows never merge into one account membership',
    ScanIdentity::canMergeLegacyMembership(
        ['company_id' => 'company-a', 'password_hash' => '$2y$credential'],
        ['company_id' => 'company-a', 'password_hash' => '$2y$credential']
    ),
    false
);
identityCheck(
    'one password-matched account resolves',
    ScanIdentity::uniqueAccountId(['account-a', 'account-a']),
    'account-a'
);
identityCheck(
    'ambiguous password-matched accounts deny resolution',
    ScanIdentity::uniqueAccountId(['account-a', 'account-b']),
    null
);
identityCheck(
    'membership authorizes only the same immutable account',
    ScanIdentity::membershipAuthorizes('account-a', ['account_id' => 'account-a'], false),
    true
);
identityCheck(
    'PII-shaped membership data does not authorize another account',
    ScanIdentity::membershipAuthorizes(
        'account-a',
        ['account_id' => 'account-b', 'email' => 'same@example.om'],
        false
    ),
    false
);
identityCheck(
    'linked super-admin authority can act without membership',
    ScanIdentity::membershipAuthorizes('account-a', null, true),
    true
);

$aliasDb = new IdentityAliasDb();
$aliasDb->seedAccount('attacker', 'attacker-password');
$aliasDb->seedAccount('verified-owner', 'owner-password');
$aliasDb->seedAccount('new-owner', 'new-password');
$aliasDb->seedIdentifier('victim@example.om', 'email', 'attacker', null);
identityCheck(
    'unverified aliases never resolve as login identities',
    ScanIdentity::findAccountByIdentifier(
        $aliasDb,
        'victim@example.om',
        'email'
    ),
    null
);
identityCheck(
    'unverified aliases do not reserve an identifier type',
    ScanIdentity::hasIdentifierType($aliasDb, 'attacker', 'email'),
    false
);

$unverifiedLink = ScanIdentity::linkIdentifier(
    $aliasDb,
    'attacker',
    'other@example.om',
    'email',
    false,
    'password_signup'
);
identityCheck(
    'password proof cannot create an unverified alias',
    $unverifiedLink['error'] ?? null,
    'verification_required'
);
identityCheck(
    'rejected unverified aliases are not persisted',
    $aliasDb->insertCount,
    0
);

$verifiedClaim = ScanIdentity::linkVerifiedIdentifier(
    $aliasDb,
    'new-owner',
    'victim@example.om',
    'email',
    'scan_login_otp'
);
$victimHash = ScanIdentity::identifierHash('victim@example.om', 'email');
identityCheck(
    'verified proof replaces a legacy unverified reservation',
    !empty($verifiedClaim['replaced_unverified']),
    true
);
identityCheck(
    'verified proof binds the alias to the proving account',
    $aliasDb->identifiers[$victimHash]['account_id'],
    'new-owner'
);
identityCheck(
    'the claimed alias is marked verified',
    empty($aliasDb->identifiers[$victimHash]['verified_at']),
    false
);

$aliasDb->seedIdentifier(
    'owned@example.om',
    'email',
    'verified-owner',
    '2026-07-24 00:00:00'
);
$verifiedConflict = ScanIdentity::linkVerifiedIdentifier(
    $aliasDb,
    'new-owner',
    'owned@example.om',
    'email',
    'scan_login_otp'
);
$ownedHash = ScanIdentity::identifierHash('owned@example.om', 'email');
identityCheck(
    'verified proof cannot rebind an alias already verified elsewhere',
    $verifiedConflict['error'] ?? null,
    'identifier_taken'
);
identityCheck(
    'a refused rebind preserves the verified owner',
    $aliasDb->identifiers[$ownedHash]['account_id'],
    'verified-owner'
);

$sameOwner = ScanIdentity::linkVerifiedIdentifier(
    $aliasDb,
    'verified-owner',
    'owned@example.om',
    'email',
    'scan_login_otp'
);
identityCheck(
    'verified alias linking is idempotent for its existing owner',
    !empty($sameOwner['already_linked']),
    true
);

$newAlias = ScanIdentity::linkVerifiedIdentifier(
    $aliasDb,
    'new-owner',
    ' Fresh@Example.OM ',
    'email',
    'scan_login_otp'
);
$freshHash = ScanIdentity::identifierHash('fresh@example.om', 'email');
identityCheck(
    'new aliases are persisted only after verification',
    $newAlias['success'] ?? false,
    true
);
identityCheck(
    'verified email aliases persist a normalized value',
    $aliasDb->identifiers[$freshHash]['identifier_value'],
    'fresh@example.om'
);

echo $failures === 0 ? "ALL PASS\n" : "$failures FAILED\n";
exit($failures === 0 ? 0 : 1);
