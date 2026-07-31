<?php
$root = dirname(__DIR__, 2);
$failures = 0;

function walletThemeCheck(string $label, bool $condition): void
{
    global $failures;
    echo ($condition ? 'PASS' : 'FAIL') . " $label\n";
    if (!$condition) {
        $failures++;
    }
}

function walletThemeThrowsCode(callable $callback, string $expected): bool
{
    try {
        $callback();
    } catch (InvalidArgumentException $e) {
        return $e->getMessage() === $expected;
    }
    return false;
}

$policyPath = $root . '/includes/WalletThemePolicy.php';
$migrationPath = $root . '/database/migrations/148_profile_wallet_themes.php';

walletThemeCheck('Wallet theme policy exists', is_file($policyPath));
walletThemeCheck('Wallet theme migration exists', is_file($migrationPath));

if (is_file($policyPath)) {
    require_once $policyPath;
    walletThemeCheck(
        'global theme is visible to every profile',
        WalletThemePolicy::isVisible(null, 'company-a')
    );
    walletThemeCheck(
        'company theme is visible to a matching profile',
        WalletThemePolicy::isVisible('company-a', 'company-a')
    );
    walletThemeCheck(
        'company theme is hidden from another tenant',
        !WalletThemePolicy::isVisible('company-b', 'company-a')
    );
    walletThemeCheck(
        'three-digit color is normalized',
        WalletThemePolicy::normalizeColor('#09c') === '#0099cc'
    );
    walletThemeCheck(
        'six-digit color is normalized to lowercase',
        WalletThemePolicy::normalizeColor('#AABBCC') === '#aabbcc'
    );
    walletThemeCheck(
        'RGB conversion is deterministic',
        WalletThemePolicy::rgb('#0099cc') === 'rgb(0, 153, 204)'
    );
    walletThemeCheck(
        'unsupported Wallet style is rejected',
        walletThemeThrowsCode(
            static function (): void {
                WalletThemePolicy::validateTheme([
                    'style' => 'coupon',
                    'background_color' => '#000000',
                    'foreground_color' => '#ffffff',
                    'label_color' => '#ffffff',
                    'logo_mode' => 'company',
                ]);
            },
            'wallet_theme_invalid'
        )
    );
    walletThemeCheck(
        'unknown override key is rejected',
        walletThemeThrowsCode(
            static function (): void {
                WalletThemePolicy::validateOverrides(['asset_path' => '../private']);
            },
            'wallet_theme_invalid'
        )
    );
    walletThemeCheck(
        'low-contrast text is rejected',
        walletThemeThrowsCode(
            static function (): void {
                WalletThemePolicy::validateTheme([
                    'style' => 'generic',
                    'background_color' => '#ffffff',
                    'foreground_color' => '#eeeeee',
                    'label_color' => '#ffffff',
                    'logo_mode' => 'none',
                ]);
            },
            'wallet_theme_invalid'
        )
    );
}

if (is_file($migrationPath)) {
    $migration = (string) file_get_contents($migrationPath);
    walletThemeCheck(
        'migration creates the tenant-scoped Wallet theme catalog',
        strpos($migration, 'CREATE TABLE IF NOT EXISTS wallet_themes') !== false
            && strpos(
                $migration,
                'KEY idx_wallet_theme_scope (company_id, is_active, sort_order)'
            ) !== false
    );
    walletThemeCheck(
        'migration creates one Wallet preference per profile',
        strpos(
            $migration,
            'CREATE TABLE IF NOT EXISTS profile_wallet_preferences'
        ) !== false
            && strpos(
                $migration,
                'UNIQUE KEY uniq_profile_wallet_preference (employee_id)'
            ) !== false
    );
}

echo $failures === 0 ? "ALL PASS\n" : "$failures FAILED\n";
exit($failures === 0 ? 0 : 1);
