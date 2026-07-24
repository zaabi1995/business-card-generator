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
$databaseAdapter = source($root . '/includes/DatabaseAdapter.php');
$companies = source($root . '/api/scan/companies.php');
$switchCompany = source($root . '/api/scan/switch-company.php');
$linkVerify = source($root . '/api/scan/link-verify.php');
$otpVerify = source($root . '/api/scan/otp-verify.php');
$login = source($root . '/api/scan/login.php');
$signup = source($root . '/api/scan/signup.php');
$createCompany = source($root . '/api/scan/create-company.php');
$companyCreateMigration = source(
    $root . '/database/migrations/143_scan_company_create_operations.php'
);
$accountDeleteMigration = source(
    $root . '/database/migrations/144_scan_account_delete_operations.php'
);
$accountDeleteCleanupMigration = source(
    $root . '/database/migrations/145_scan_account_deletion_cleanup.php'
);
$accountDeleteCleanup = source(
    $root . '/includes/ScanAccountDeletionCleanup.php'
);
$proStatus = source($root . '/api/scan/pro-status.php');
$rateLimit = source($root . '/api/scan/_ratelimit.php');
$brandGuard = source($root . '/api/scan/_brand_guard.php');
$deleteAccount = source($root . '/api/scan/delete-account.php');
$cardRender = source($root . '/api/scan/card-render.php');
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
    'native mutations acquire one account lock and reauthenticate after waiting',
    strpos($scanAuth, 'requireEmployeeMutation') !== false
        && strpos($scanAuth, 'acquireAccountMutationLock') !== false
        && strpos($scanAuth, 'SELECT GET_LOCK(?, 15)') !== false
        && strpos($scanAuth, 'SELECT RELEASE_LOCK(?)') !== false
        && preg_match(
            '/requireEmployeeMutation.*requireEmployee\\(\\).*'
                . 'acquireAccountMutationLock.*requireEmployee\\(\\)/s',
            $scanAuth
        ) === 1
        && strpos($scanAuth, 'register_shutdown_function($releaseLock)') !== false
        && strpos($scanAuth, 'if ($released)') !== false
);
$mutationEndpointNames = [
    'card-render.php',
    'create-company.php',
    'create.php',
    'delete-account.php',
    'delete.php',
    'designs.php',
    'invite.php',
    'link-request.php',
    'link-verify.php',
    'my-card-logo.php',
    'my-card.php',
    'pro-report.php',
    'refine.php',
    'register-push.php',
    'resolve-card.php',
    'switch-company.php',
    'sync.php',
    'update.php',
    'upload.php',
];
foreach ($mutationEndpointNames as $mutationEndpointName) {
    contractCheck(
        'account lock covers native mutation ' . $mutationEndpointName,
        strpos(
            source($root . '/api/scan/' . $mutationEndpointName),
            'requireEmployeeMutation'
        ) !== false
    );
}
contractCheck(
    'card rendering locks both GET cache writes and POST preset writes',
    strpos($cardRender, '$ctx = ScanAuth::requireEmployeeMutation();') !== false
        && strpos($cardRender, 'ScanAuth::requireEmployee();') === false
);
contractCheck(
    'password login locks and revalidates existing accounts after proof',
    substr_count($login, 'ScanAuth::acquireAccountMutationLock') >= 3
        && preg_match(
            '/password_verify\\(.*acquireAccountMutationLock.*'
                . 'findAccountByIdentifier.*primaryEmployee.*issueToken/s',
            $login
        ) === 1
        && preg_match(
            '/uniqueAccountId.*acquireAccountMutationLock.*'
                . 'membershipForEmployee.*issueToken/s',
            $login
        ) === 1
);
contractCheck(
    'OTP login locks and revalidates accounts before linking or issuing tokens',
    substr_count($otpVerify, 'ScanAuth::acquireAccountMutationLock') >= 3
        && preg_match(
            '/OtpService::verify.*acquireAccountMutationLock.*'
                . 'findAccountByIdentifier.*primaryEmployee.*'
                . 'linkVerifiedIdentifier.*issueToken/s',
            $otpVerify
        ) === 1
        && preg_match(
            '/createAccountForEmployee.*acquireAccountMutationLock.*'
                . 'membershipForEmployee.*issueToken/s',
            $otpVerify
        ) === 1
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
    'OTP account provisioning is atomic through token issuance',
    preg_match(
        '/beginTransaction\\(\\).*createCompany\\(.*addEmployee\\(.*'
            . 'createAccountForEmployee\\(.*issueToken\\(.*commit\\(\\)/s',
        $otpVerify
    ) === 1
        && strpos($otpVerify, '$pdo->rollBack()') !== false
);
contractCheck(
    'employee primary-key races retry only the global ID allocation',
    strpos($databaseAdapter, 'isEmployeePrimaryKeyCollision') !== false
        && strpos($databaseAdapter, '$primaryCollisionRetries >= 2') !== false
        && strpos($databaseAdapter, 'CardifyConvention::employeeIdFromEmail') !== false
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
    'native company creation accepts only UUID v4 operation identifiers',
    strpos($createCompany, "'invalid_operation_id'") !== false
        && strpos($createCompany, '4[0-9a-f]{3}-[89ab]') !== false
);
contractCheck(
    'native company creation reserves and locks one globally owned operation',
    strpos($createCompany, 'INSERT IGNORE INTO scan_company_create_operations')
        !== false
        && strpos($createCompany, 'FOR UPDATE') !== false
        && strpos($createCompany, "'operation_conflict'") !== false
        && strpos($createCompany, "'operation_owner_conflict'") !== false
        && strpos($createCompany, "'operation_account_deleted'") !== false
);
contractCheck(
    'native company creation serializes quota and deletion on the account row',
    preg_match(
        '/beginTransaction\\(\\).*FROM scan_accounts.*FOR UPDATE.*'
            . 'INSERT IGNORE INTO scan_company_create_operations.*'
            . 'SELECT COUNT\\(\\*\\) AS n/s',
        $createCompany
    ) === 1
);
contractCheck(
    'native company creation commits its company, membership, token, and operation atomically',
    preg_match(
        '/beginTransaction\\(\\).*createCompany\\(.*addEmployee\\(.*'
            . 'attachEmployee\\(.*UPDATE scan_company_create_operations.*'
            . 'issueToken\\(.*commit\\(\\)/s',
        $createCompany
    ) === 1
        && strpos($createCompany, '$pdo->rollBack()') !== false
);
contractCheck(
    'completed company operations recover the same target with a fresh token',
    strpos($createCompany, "'recovered' => true") !== false
        && strpos($createCompany, "'operation_target_unavailable'") !== false
        && preg_match(
            '/if \\(!\\$createdOperation\\).*issueToken\\(.*'
                . "'operation_id'.*'recovered' => true/s",
            $createCompany
        ) === 1
);
contractCheck(
    'company operation migration uses a global binary UUID replay key',
    strpos(
        $companyCreateMigration,
        'PRIMARY KEY (operation_id)'
    ) !== false
        && strpos($companyCreateMigration, 'account_id CHAR(36) NULL') !== false
        && strpos($companyCreateMigration, 'company_name VARCHAR(100) NULL') !== false
        && strpos(
            $companyCreateMigration,
            'idx_scan_company_operation_account'
        ) !== false
        && strpos($companyCreateMigration, 'COLLATE ascii_bin') !== false
        && strpos($companyCreateMigration, "DEFAULT 'pending'") !== false
);
contractCheck(
    'company creation never returns database adapter details to clients',
    strpos($createCompany, "'detail' =>") === false
        && strpos($createCompany, "error_log('[scan/create-company] createCompany: '") !== false
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
        && strpos($deleteAccount, 'scan_company_create_operations') !== false
        && strpos($deleteAccount, 'DELETE FROM scans WHERE employee_id = ?') !== false
        && strpos($deleteAccount, 'DELETE FROM card_designs WHERE employee_id = ?') !== false
        && strpos($deleteAccount, 'DELETE FROM scan_passes WHERE employee_id = ?') !== false
);
contractCheck(
    'account deletion requires an exact JSON boolean confirmation',
    strpos($deleteAccount, "scanRequestHasExactTrue(\$body, 'confirm')") !== false
        && strpos($deleteAccount, "empty(\$body['confirm'])") === false
);
contractCheck(
    'account deletion strictly validates supplied UUID v4 operations',
    strpos($deleteAccount, "array_key_exists('operation_id', \$body)") !== false
        && strpos($deleteAccount, "'invalid_operation_id'") !== false
        && preg_match(
            '/4\\[0-9a-f\\]\\{3\\}.*\\[89ab\\]/s',
            $deleteAccount
        ) === 1
);
contractCheck(
    'legacy account deletion generates an operation only after authentication',
    preg_match(
        '/requireEmployeeMutation\\(\\).*'
            . 'if \\(!\\$operationIdProvided\\).*generateOperationId/s',
        $deleteAccount
    ) === 1
);
contractCheck(
    'unauthenticated deletion confirmation is IP limited before its lookup',
    preg_match(
        '/RateLimiter::check\\(.*'
            . 'scan_delete_account_confirmation.*'
            . 'scan_account_delete_operations/s',
        $deleteAccount
    ) === 1
);
contractCheck(
    'account deletion serializes with company creation before membership reads',
    preg_match(
        '/beginTransaction\\(\\).*FROM scan_accounts.*FOR UPDATE.*'
            . 'FROM scan_account_memberships/s',
        $deleteAccount
    ) === 1
);
contractCheck(
    'account deletion anonymizes operation UUID tombstones instead of freeing them',
    strpos($deleteAccount, 'UPDATE scan_company_create_operations') !== false
        && strpos($deleteAccount, "status = 'account_deleted'") !== false
        && strpos($deleteAccount, 'account_id = NULL') !== false
        && strpos(
            $deleteAccount,
            'DELETE FROM scan_company_create_operations'
        ) === false
);
contractCheck(
    'account deletion can confirm a token-bound operation without a live session',
    strpos(
        $accountDeleteMigration,
        'CREATE TABLE IF NOT EXISTS scan_account_delete_operations'
    ) !== false
        && strpos($accountDeleteMigration, 'PRIMARY KEY (operation_id)') !== false
        && preg_match(
            '/SELECT operation_id, status.*'
                . 'scan_account_delete_operations.*'
                . 'confirmation_token_hash.*'
                . 'requireEmployeeMutation/s',
            $deleteAccount
        ) === 1
        && strpos($deleteAccount, "'deletion_confirmed' => true") !== false
);
contractCheck(
    'account deletion tombstone returns immutable cleanup ownership',
    strpos($accountDeleteCleanupMigration, 'deleted_account_id CHAR(36) NULL')
        !== false
        && strpos($accountDeleteCleanupMigration, 'deleted_employee_ids LONGTEXT NULL')
        !== false
        && strpos($deleteAccount, "'account_id' => \$deletedAccountId") !== false
        && strpos(
            $deleteAccount,
            "'deleted_employee_ids' => \$deletedEmployeeIds"
        ) !== false
);
contractCheck(
    'account deletion operation completes atomically with the account sweep',
    preg_match(
        '/beginTransaction\\(\\).*'
            . 'INSERT IGNORE INTO scan_account_delete_operations.*'
            . 'DELETE FROM scan_accounts.*'
            . 'UPDATE scan_account_delete_operations.*'
            . "status = 'completed'.*commit\\(\\)/s",
        $deleteAccount
    ) === 1
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
    'account deletion durably queues guarded scan image cleanup',
    strpos(
        $accountDeleteCleanupMigration,
        'CREATE TABLE IF NOT EXISTS scan_account_delete_files'
    ) !== false
        && strpos($accountDeleteCleanupMigration, 'UNIQUE KEY uniq_scan_delete_file')
            !== false
        && strpos($deleteAccount, 'queueOwnedPath') !== false
        && strpos($deleteAccount, 'image_path_back') !== false
        && strpos($deleteAccount, 'processOperation') !== false
        && strpos($accountDeleteCleanup, "uploads\\/scans\\/") !== false
        && strpos($accountDeleteCleanup, 'realpath') !== false
        && strpos($accountDeleteCleanup, 'is_link') !== false
);
contractCheck(
    'account deletion anonymizes only orphaned unclaimed shadow profiles',
    preg_match(
        '/DELETE FROM scan_claim_tickets.*WHERE shadow_profile_id = \\?/s',
        $deleteAccount
    ) === 1
        && strpos($deleteAccount, 'UPDATE shadow_profiles') !== false
        && strpos($deleteAccount, 'phone_primary = NULL') !== false
        && strpos($deleteAccount, 'best_parsed = NULL') !== false
        && strpos($deleteAccount, '$legitimatelyClaimed') !== false
        && strpos($deleteAccount, 'claimed_at IS NOT NULL') !== false
        && strpos($deleteAccount, "TRIM(claimed_company_id) <> ''") !== false
        && strpos($deleteAccount, 'NOT EXISTS') !== false
        && strpos($deleteAccount, 'remaining_scan') !== false
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
