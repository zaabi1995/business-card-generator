<?php
/**
 * Smoke test for includes/LogoLibrary.php helpers.
 * Does NOT require a DB connection (all static helpers).
 * Run: php scripts/smoke-logo-library.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once __DIR__ . '/../includes/LogoLibrary.php';

$ok = 0; $fail = 0;
function check(string $name, bool $pass): void {
    global $ok, $fail;
    echo ($pass ? "  ✓ " : "  ✗ ") . $name . PHP_EOL;
    if ($pass) $ok++; else $fail++;
}

echo "LogoLibrary::deriveDomain\n";
check("https://www.bhd.om/about → bhd.om",   LogoLibrary::deriveDomain('https://www.bhd.om/about') === 'bhd.om');
check("http://cardify.om → cardify.om",       LogoLibrary::deriveDomain('http://cardify.om') === 'cardify.om');
check("cardify.om → cardify.om",              LogoLibrary::deriveDomain('cardify.om') === 'cardify.om');
check("empty → null",                         LogoLibrary::deriveDomain('') === null);
check("null → null",                          LogoLibrary::deriveDomain(null) === null);
check("whitespace → null",                    LogoLibrary::deriveDomain('   ') === null);
check("uppercase WWW → lowercase stripped",   LogoLibrary::deriveDomain('https://WWW.BHD.OM/') === 'bhd.om');

echo "\nLogoLibrary::emailDomainMatchesCompany\n";
check("match: ali@bhd.om vs bhd.om",          LogoLibrary::emailDomainMatchesCompany('ali@bhd.om', 'bhd.om') === true);
check("generic blocked: gmail",               LogoLibrary::emailDomainMatchesCompany('ali@gmail.com', 'bhd.om') === false);
check("generic blocked: outlook",             LogoLibrary::emailDomainMatchesCompany('x@outlook.com', 'outlook.com') === false);
check("case insensitive",                     LogoLibrary::emailDomainMatchesCompany('ali@BHD.OM', 'bhd.om') === true);
check("mismatch: other.om vs bhd.om",         LogoLibrary::emailDomainMatchesCompany('ali@other.om', 'bhd.om') === false);
check("null company domain → false",          LogoLibrary::emailDomainMatchesCompany('ali@bhd.om', null) === false);
check("no @ sign → false",                    LogoLibrary::emailDomainMatchesCompany('malformed', 'bhd.om') === false);

echo "\nLogoLibrary::statusBadge\n";
check("verified label",                       LogoLibrary::statusBadge('verified')['label'] === 'Verified');
check("indexed label",                        LogoLibrary::statusBadge('indexed')['label']  === 'Indexed');
check("pending label",                        LogoLibrary::statusBadge('pending')['label']  === 'Pending');
check("unknown → No Logo",                    LogoLibrary::statusBadge('xyz')['label']      === 'No Logo');

echo "\nLogoLibrary::canDownload\n";
check("verified → true",                      LogoLibrary::canDownload(['logo_status'=>'verified']) === true);
check("indexed → false",                      LogoLibrary::canDownload(['logo_status'=>'indexed'])  === false);
check("none → false",                         LogoLibrary::canDownload(['logo_status'=>'none'])     === false);
check("missing status → false",               LogoLibrary::canDownload([])                          === false);

echo "\nLogoLibrary::ipHash / uaHash\n";
$_SERVER['REMOTE_ADDR'] = '1.2.3.4';
$_SERVER['HTTP_USER_AGENT'] = 'TestAgent/1.0';
check("ipHash is 64 hex chars",               strlen(LogoLibrary::ipHash()) === 64);
check("uaHash is 64 hex chars",               strlen(LogoLibrary::uaHash()) === 64);
check("ipHash deterministic",                 LogoLibrary::ipHash() === LogoLibrary::ipHash());

echo "\n";
echo "Passed: $ok  Failed: $fail\n";
exit($fail === 0 ? 0 : 1);
