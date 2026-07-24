<?php

$root = dirname(__DIR__, 2);
$failures = 0;

function contractCheck(string $label, bool $condition): void
{
    global $failures;
    echo ($condition ? 'PASS' : 'FAIL') . " $label\n";
    if (!$condition) {
        $failures++;
    }
}

function source(string $path): string
{
    $value = file_get_contents($path);
    if ($value === false) {
        throw new RuntimeException('Unable to read ' . $path);
    }
    return $value;
}

$scanAuth = source($root . '/includes/ScanAuth.php');
$scanIdentity = source($root . '/includes/ScanIdentity.php');
$companies = source($root . '/api/scan/companies.php');
$switchCompany = source($root . '/api/scan/switch-company.php');
$linkVerify = source($root . '/api/scan/link-verify.php');
$otpVerify = source($root . '/api/scan/otp-verify.php');
$login = source($root . '/api/scan/login.php');
$signup = source($root . '/api/scan/signup.php');
$createCompany = source($root . '/api/scan/create-company.php');
$proStatus = source($root . '/api/scan/pro-status.php');
$rateLimit = source($root . '/api/scan/_ratelimit.php');
$brandGuard = source($root . '/api/scan/_brand_guard.php');
$deleteAccount = source($root . '/api/scan/delete-account.php');
$proReport = source($root . '/api/scan/pro-report.php');
$storeKitVerify = source($root . '/api/scan/AppleStoreKitVerify.php');
$refine = source($root . '/api/scan/refine.php');
$deleteMatches = [];
preg_match_all(
    '/DELETE\s+FROM\s+([a-zA-Z0-9_]+)/i',
    $deleteAccount,
    $deleteMatches
);
$deletedTables = array_values(array_unique(array_map(
    'strtolower',
    $deleteMatches[1] ?? []
)));
$allowedDeleteTables = [
    'scan_pass_registrations',
    'scan_pass_changes',
    'scan_claim_tickets',
    'push_tokens',
    'card_designs',
    'scans',
    'scan_passes',
    'scan_pro_receipts',
    'scan_account_entitlements',
    'scan_api_tokens',
    'scan_account_identifiers',
    'scan_identity_user_link_audit',
    'scan_identity_migration_audit',
    'scan_account_memberships',
    'scan_accounts',
];

contractCheck(
    'bearer tokens are bound to an immutable account',
    strpos($scanAuth, 't.account_id') !== false
        && strpos($scanAuth, "'account_id'") !== false
);
contractCheck(
    'bearer authorization requires membership or linked super-admin identity',
    strpos($scanAuth, 'scan_account_memberships') !== false
        && strpos($scanAuth, 'users u ON u.id = a.user_id') !== false
);
contractCheck(
    'company list is membership-based',
    strpos($companies, 'scan_account_memberships') !== false
        && strpos($companies, 'LOWER(TRIM(e.email))') === false
        && strpos($companies, 'Phone::normalize') === false
);
contractCheck(
    'company switching is membership-based',
    strpos($switchCompany, 'membershipForEmployee') !== false
        && strpos($switchCompany, '$sameEmail') === false
        && strpos($switchCompany, '$samePhone') === false
);
contractCheck(
    'OTP link verification consumes OtpService ok result',
    strpos($linkVerify, "empty(\$verify['ok'])") !== false
        && strpos($linkVerify, "empty(\$verify['success'])") === false
);
contractCheck(
    'linking a login alias does not overwrite card contact fields',
    strpos($linkVerify, 'linkVerifiedIdentifier') !== false
        && strpos($linkVerify, 'UPDATE employees SET') === false
);
contractCheck(
    'only verified aliases are globally authoritative',
    strpos($scanIdentity, 'AND i.verified_at IS NOT NULL') !== false
        && strpos($scanIdentity, '$verifiedOnly') === false
);
contractCheck(
    'unverified aliases cannot be persisted by password flows',
    strpos($scanIdentity, 'if (!$verified)') !== false
        && strpos($scanIdentity, "'verification_required'") !== false
);
contractCheck(
    'verified alias ownership cannot be rebound to another account',
    strpos($scanIdentity, "if (!empty(\$existing['verified_at']))") !== false
        && strpos($scanIdentity, 'hash_equals($accountId, $existingAccountId)') !== false
        && strpos($scanIdentity, "'identifier_taken'") !== false
        && strpos(
            $scanIdentity,
            'identifier_hash = :where_hash AND verified_at IS NULL'
        ) !== false
);
contractCheck(
    'OTP login no longer selects an account from editable profile PII',
    strpos($otpVerify, 'findActiveEmployee') === false
        && strpos($otpVerify, 'findAccountByIdentifier') !== false
);
contractCheck(
    'password login resolves an account and denies ambiguous legacy proof',
    strpos($login, 'findAccountByIdentifier') !== false
        && strpos($login, 'uniqueAccountId') !== false
        && strpos($login, 'ambiguous_identity') !== false
);
contractCheck(
    'password fallback never reserves an editable profile email as an alias',
    preg_match(
        '/createAccountForEmployee\(\s*\$db,\s*'
            . '\(string\) \$legacy\[\'id\'\],\s*'
            . '\(string\) \$legacy\[\'password_hash\'\],\s*'
            . 'null,\s*null,\s*false,/s',
        $login
    ) === 1
        && strpos($login, 'ScanIdentity::linkIdentifier') === false
);
contractCheck(
    'signup creates an immutable account before issuing a token',
    strpos($signup, 'createAccountForEmployee') !== false
);
contractCheck(
    'password signup does not reserve its editable email as an alias',
    preg_match(
        '/createAccountForEmployee\(\s*\$db,\s*\$employeeId,\s*'
            . '\$passwordHash,\s*null,\s*null,\s*false,/s',
        $signup
    ) === 1
        && strpos($signup, 'ScanIdentity::linkIdentifier') === false
);
contractCheck(
    'new companies attach through the authenticated account',
    strpos($createCompany, 'attachEmployee') !== false
        && strpos($createCompany, "\$ctx['account_id']") !== false
);
contractCheck(
    'linked identifiers shown in settings come from account aliases',
    strpos($proStatus, 'linkedIdentifiers') !== false
        && strpos($proStatus, 'SELECT email, mobile, phone') === false
);
contractCheck(
    'scan abuse budgets follow the immutable account across company switches',
    strpos($rateLimit, "\$ctx['account_id']") !== false
        && strpos($rateLimit, "\$ctx['employee_id']") === false
);
contractCheck(
    'brand authority uses membership role and immutable super-admin linkage',
    strpos($brandGuard, 'membership_role') !== false
        && strpos($brandGuard, 'isLinkedSuperAdmin') !== false
        && strpos($brandGuard, 'admin_email') === false
        && strpos($brandGuard, 'LOWER(TRIM(e.email))') === false
);
contractCheck(
    'account deletion removes only immutable-account and native-app data',
    strpos($deleteAccount, "\$ctx['account_id']") !== false
        && strpos($deleteAccount, 'scan_account_memberships') !== false
        && strpos($deleteAccount, 'scan_account_identifiers') !== false
        && strpos($deleteAccount, 'scan_account_entitlements') !== false
        && strpos($deleteAccount, 'scan_api_tokens') !== false
        && strpos($deleteAccount, 'scan_pro_receipts') !== false
        && strpos($deleteAccount, 'DELETE FROM scans WHERE employee_id = ?') !== false
        && strpos($deleteAccount, 'DELETE FROM card_designs WHERE employee_id = ?') !== false
        && strpos($deleteAccount, 'DELETE FROM scan_passes WHERE employee_id = ?') !== false
);
contractCheck(
    'account deletion preserves tenant, employee, and web-user records',
    strpos($deleteAccount, 'DELETE FROM companies') === false
        && strpos($deleteAccount, 'DELETE FROM employees') === false
        && strpos($deleteAccount, 'DELETE FROM users') === false
        && strpos($deleteAccount, 'companyTables') === false
        && strpos($deleteAccount, 'employeeTables') === false
        && strpos($deleteAccount, 'information_schema.columns') === false
        && strpos($deleteAccount, "'companies_preserved' => true") !== false
        && strpos($deleteAccount, "'employees_preserved' => true") !== false
);
contractCheck(
    'account deletion has a fixed native-data deletion allowlist',
    empty(array_diff($deletedTables, $allowedDeleteTables))
        && strpos($deleteAccount, 'DELETE FROM `$table`') === false
);
contractCheck(
    'StoreKit entitlement ownership is immutable-account scoped',
    strpos($proReport, 'scan_account_entitlements') !== false
        && strpos($proReport, 'original_transaction_id, employee_id, account_id') !== false
        && strpos($proReport, "\$ctx['account_id']") !== false
);
contractCheck(
    'StoreKit grace periods require two independently verified matching Apple JWS values',
    strpos($proReport, 'renewal_info_jws') !== false
        && strpos($proReport, 'verifyTransaction') !== false
        && strpos($proReport, 'verifyRenewalInfo') !== false
        && strpos($proReport, 'hash_equals($originalTransactionId, $renewalOriginalId)') !== false
        && strpos($proReport, '$transactionProductId !== $renewalProductId') !== false
        && strpos($proReport, '$transactionEnvironment !== $renewalEnvironment') !== false
);
contractCheck(
    'StoreKit grace access is bounded by signed retry and grace-expiry claims',
    strpos($proReport, 'gracePeriodExpiresDate') !== false
        && strpos($proReport, 'isInBillingRetryPeriod') !== false
        && strpos($proReport, '$transactionExpiresAt + (60 * 86400)') !== false
);
contractCheck(
    'StoreKit transaction verification rejects revoked receipts',
    strpos($storeKitVerify, "!empty(\$payload['revocationDate'])") !== false
        && strpos($storeKitVerify, 'verifySignedPayload') !== false
);
contractCheck(
    'paid refinement follows account entitlement and account rate limits',
    strpos($refine, 'scan_account_entitlements') !== false
        && strpos($refine, "\$ctx['account_id']") !== false
);

echo $failures === 0 ? "ALL PASS\n" : "$failures FAILED\n";
exit($failures === 0 ? 0 : 1);
