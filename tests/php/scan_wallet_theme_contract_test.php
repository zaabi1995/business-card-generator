<?php
$root = dirname(__DIR__, 2);
$failures = 0;

function walletContractCheck(string $label, bool $condition): void
{
    global $failures;
    echo ($condition ? 'PASS' : 'FAIL') . " $label\n";
    if (!$condition) {
        $failures++;
    }
}

function walletContractSource(string $path): string
{
    if (!is_file($path)) {
        return '';
    }
    $source = file_get_contents($path);
    return $source === false ? '' : $source;
}

$catalog = walletContractSource($root . '/includes/WalletThemeCatalog.php');
$endpoint = walletContractSource($root . '/api/scan/wallet-themes.php');
$deleteAccount = walletContractSource($root . '/api/scan/delete-account.php');

walletContractCheck('shared Wallet theme catalog exists', $catalog !== '');
walletContractCheck('authenticated Wallet theme endpoint exists', $endpoint !== '');
walletContractCheck(
    'catalog lists only global and current-company themes',
    strpos($catalog, 'company_id IS NULL OR company_id = :company_id') !== false
        && strpos($catalog, 'is_active = 1') !== false
);
walletContractCheck(
    'preferences are scoped to the authenticated profile and tenant',
    strpos(
        $catalog,
        'employee_id = :employee_id AND company_id = :company_id'
    ) !== false
        && strpos($catalog, 'FOR UPDATE') !== false
);
walletContractCheck(
    'selection revalidates tenant visibility inside a transaction',
    strpos($catalog, 'beginTransaction()') !== false
        && strpos($catalog, 'WalletThemePolicy::isVisible') !== false
        && strpos($catalog, 'commit()') !== false
        && strpos($catalog, 'rollBack()') !== false
);
walletContractCheck(
    'GET and POST use the correct immutable-account authentication',
    strpos($endpoint, "REQUEST_METHOD") !== false
        && strpos($endpoint, 'ScanAuth::requireEmployeeMutation()') !== false
        && strpos($endpoint, 'ScanAuth::requireEmployee()') !== false
);
walletContractCheck(
    'Wallet theme mutations are rate limited by the shared backend',
    strpos($endpoint, "scanRateLimit(\$ctx, 'wallet_themes', 120)") !== false
);
walletContractCheck(
    'endpoint exposes and persists the shared catalog',
    strpos($endpoint, 'WalletThemeCatalog::listForProfile') !== false
        && strpos($endpoint, 'WalletThemeCatalog::resolvePreference') !== false
        && strpos($endpoint, 'WalletThemeCatalog::savePreference') !== false
);
walletContractCheck(
    'theme changes reuse the stable Wallet pass update lifecycle',
    strpos($endpoint, 'ScanPassService::onCardChanged') !== false
        && strpos($endpoint, 'pushPassUpdates') !== false
);
walletContractCheck(
    'account deletion removes only owned profile Wallet preferences',
    strpos(
        $deleteAccount,
        'DELETE FROM profile_wallet_preferences WHERE employee_id = ?'
    ) !== false
);

echo $failures === 0 ? "ALL PASS\n" : "$failures FAILED\n";
exit($failures === 0 ? 0 : 1);
