<?php
declare(strict_types=1);

function incarnationCheck(string $label, bool $condition): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
    echo "PASS: {$label}\n";
}

function incarnationSource(string $relativePath): string
{
    $source = file_get_contents(__DIR__ . '/../../' . $relativePath);
    if ($source === false) {
        fwrite(STDERR, "FAIL: cannot read {$relativePath}\n");
        exit(1);
    }
    return $source;
}

$login = incarnationSource('api/scan/login.php');
$signup = incarnationSource('api/scan/signup.php');
$otp = incarnationSource('api/scan/otp-verify.php');
$switch = incarnationSource('api/scan/switch-company.php');
$create = incarnationSource('api/scan/create-company.php');
$companies = incarnationSource('api/scan/companies.php');

incarnationCheck(
    'every password login session returns immutable account_id',
    substr_count($login, "'account_id' => \$accountId") >= 3
);
incarnationCheck(
    'signup session returns immutable account_id',
    strpos($signup, "'account_id' => \$accountId") !== false
);
incarnationCheck(
    'every OTP session returns immutable account_id',
    substr_count($otp, "'account_id' => \$accountId") >= 2
);
incarnationCheck(
    'company switch session returns the current immutable account_id',
    strpos($switch, "'account_id' => \$accountId") !== false
);
incarnationCheck(
    'company creation session returns the current immutable account_id',
    substr_count($create, "'account_id' => \$ctx['account_id']") >= 2
);
incarnationCheck(
    'company listing exposes immutable account_id for legacy session upgrade',
    strpos($companies, "'account_id' => \$accountId") !== false
);
