<?php
$root = dirname(__DIR__, 2);
$failures = 0;

function walletResolverCheck(string $label, bool $condition): void
{
    global $failures;
    echo ($condition ? 'PASS' : 'FAIL') . " $label\n";
    if (!$condition) {
        $failures++;
    }
}

$resolverPath = $root . '/includes/WalletThemeResolver.php';
walletResolverCheck('Wallet theme resolver exists', is_file($resolverPath));

if (is_file($resolverPath)) {
    require_once $resolverPath;
    $employee = ['id' => 'employee-a', 'company_id' => 'company-a'];
    $company = ['id' => 'company-a'];
    $companyTheme = ['primary_color' => '#005577'];
    $selected = static function (): array {
        return [
            'source' => 'selected',
            'theme_id' => 'global-blue',
            'overrides' => [],
            'resolved' => [
                'id' => 'global-blue',
                'company_id' => null,
                'name_en' => 'Global blue',
                'name_ar' => '',
                'style' => 'generic',
                'background_color' => '#112233',
                'foreground_color' => '#ffffff',
                'label_color' => '#ffffff',
                'logo_mode' => 'cardify',
                'preview_url' => null,
            ],
        ];
    };
    $resolved = WalletThemeResolver::forEmployee(
        $employee,
        $company,
        $companyTheme,
        $selected
    );
    walletResolverCheck(
        'selected global theme wins',
        $resolved['source'] === 'selected'
            && $resolved['style'] === 'generic'
            && $resolved['logo_mode'] === 'cardify'
    );
    walletResolverCheck(
        'allowed overrides win',
        $resolved['background_color'] === '#112233'
    );
    $foreign = static function (): array {
        throw new InvalidArgumentException('wallet_theme_not_found');
    };
    $fallback = WalletThemeResolver::forEmployee(
        $employee,
        $company,
        $companyTheme,
        $foreign
    );
    walletResolverCheck(
        'foreign company theme falls back',
        $fallback['source'] === 'company_default'
            && $fallback['background_color'] === '#005577'
    );
    walletResolverCheck(
        'resolver does not alter profile identity',
        $employee['id'] === 'employee-a'
            && !array_key_exists('serial_number', $resolved)
    );
}

$passService = (string) file_get_contents(
    $root . '/includes/ScanPassService.php'
);
walletResolverCheck(
    'pass existence lookup is profile scoped',
    strpos(
        $passService,
        'public static function existsForEmployee(string $employeeId): bool'
    ) !== false
);

echo $failures === 0 ? "ALL PASS\n" : "$failures FAILED\n";
exit($failures === 0 ? 0 : 1);
