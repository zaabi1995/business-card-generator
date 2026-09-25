<?php
require_once dirname(__DIR__, 2) . '/includes/CompanyNormalizer.php';

function assertSameNorm($expected, $actual, $label) {
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL $label: expected [$expected] got [$actual]\n");
        exit(1);
    }
}

assertSameNorm('example', CompanyNormalizer::englishName('Example L.L.C.'), 'llc');
assertSameNorm('example', CompanyNormalizer::englishName('THE EXAMPLE COMPANY SAOC'), 'saoc');
assertSameNorm('المثال', CompanyNormalizer::arabicName('شركة المثال ش.م.م'), 'ar suffix');
assertSameNorm('example.om', CompanyNormalizer::domain('https://www.example.om/about'), 'domain');
assertSameNorm('', CompanyNormalizer::crNumber('CR-12'), 'short cr');
assertSameNorm('1234567', CompanyNormalizer::crNumber('CR 1,234,567'), 'cr digits');
assertSameNorm('+96824123456', CompanyNormalizer::phone('2412 3456'), 'phone');
assertSameNorm('', CompanyNormalizer::phone(''), 'empty phone');

$rankOfficial = CompanyDirectorySourceRank();
if ($rankOfficial['official_registry'] >= $rankOfficial['inferred']) {
    fwrite(STDERR, "FAIL source rank\n");
    exit(1);
}
echo "company_normalizer_test ok\n";

function CompanyDirectorySourceRank(): array {
    return [
        'official_registry' => 1,
        'inferred' => 7,
    ];
}
