<?php
$root = dirname(__DIR__, 2);
$failures = 0;

function claimContractCheck(string $label, bool $condition): void
{
    global $failures;
    echo ($condition ? 'PASS' : 'FAIL') . " $label\n";
    if (!$condition) {
        $failures++;
    }
}

function claimSource(string $path): string
{
    if (!is_file($path)) {
        return '';
    }
    $value = file_get_contents($path);
    return $value === false ? '' : $value;
}

$ticketService = claimSource($root . '/includes/ScanClaimTicket.php');
$claimOtp = claimSource($root . '/api/scan/claim-otp.php');
$register = claimSource($root . '/company/register.php');
$migration = claimSource($root . '/database/migrations/141_scan_feature_schema.php');
$employeeCreatePosition = strpos($register, '$empResult = addEmployee');
$employeeCompletePosition = $employeeCreatePosition === false
    ? false
    : strpos($register, 'ScanClaimTicket::completeRegistration', $employeeCreatePosition);
$userCreatePosition = strpos($register, '$userResult = Auth::createUser');
$userCompletePosition = $userCreatePosition === false
    ? false
    : strpos($register, 'ScanClaimTicket::completeRegistration', $userCreatePosition);

claimContractCheck(
    'claim tickets are opaque, hashed, and short lived',
    strpos($ticketService, 'random_bytes(32)') !== false
        && strpos($ticketService, "hash('sha256'") !== false
        && strpos($ticketService, 'TTL_SECONDS = 900') !== false
        && strpos($ticketService, '$ttlSeconds = self::TTL_SECONDS') !== false
);
claimContractCheck(
    'claim OTP verification issues a ticket without claiming the profile',
    strpos($claimOtp, 'ScanClaimTicket::issue') !== false
        && strpos($claimOtp, 'UPDATE shadow_profiles SET claimed_at') === false
);
claimContractCheck(
    'claim registration carries the ticket through the form',
    strpos($register, "name=\"claim_ticket\"") !== false
        && strpos($register, 'ScanClaimTicket::findValid') !== false
);
claimContractCheck(
    'claim registration locks before provisioning and rolls back failures',
    strpos($register, 'ScanClaimTicket::lockForRegistration') !== false
        && strpos($register, 'rollBackClaimTransaction') !== false
);
claimContractCheck(
    'claim completion follows successful company or employee creation',
    $employeeCreatePosition !== false
        && $employeeCompletePosition !== false
        && $employeeCompletePosition > $employeeCreatePosition
        && $userCreatePosition !== false
        && $userCompletePosition !== false
        && $userCompletePosition > $userCreatePosition
        && strpos($register, 'claimed_company_id') === false
);
claimContractCheck(
    'claim ticket storage supports expiry and one-time consumption',
    strpos($migration, 'CREATE TABLE IF NOT EXISTS scan_claim_tickets') !== false
        && strpos($migration, 'UNIQUE KEY uniq_scan_claim_ticket_hash') !== false
        && strpos($migration, 'expires_at DATETIME NOT NULL') !== false
        && strpos($migration, 'consumed_at DATETIME NULL') !== false
);
claimContractCheck(
    'claim ticket responses are not cached or forwarded as referrers',
    strpos($claimOtp, "header('Cache-Control: no-store')") !== false
        && strpos($register, "header('Referrer-Policy: no-referrer')") !== false
);
claimContractCheck(
    'verified tickets retain the scan claim attribution',
    strpos($register, "\$claimPreview ? 'scan_claim'") !== false
);

echo $failures === 0 ? "ALL PASS\n" : "$failures FAILED\n";
exit($failures === 0 ? 0 : 1);
