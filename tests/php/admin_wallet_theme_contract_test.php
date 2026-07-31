<?php

$admin = file_get_contents(__DIR__ . '/../../admin/theme.php');
$preview = is_file(__DIR__ . '/../../admin/wallet-theme-preview.php')
    ? file_get_contents(__DIR__ . '/../../admin/wallet-theme-preview.php')
    : '';
$backfill = is_file(__DIR__ . '/../../scripts/backfill-wallet-themes.php')
    ? file_get_contents(__DIR__ . '/../../scripts/backfill-wallet-themes.php')
    : '';
$failed = 0;

function adminWalletCheck(string $name, bool $condition): void
{
    global $failed;
    if (!$condition) {
        $failed++;
        echo "FAIL: {$name}\n";
        return;
    }
    echo "PASS: {$name}\n";
}

adminWalletCheck('admin uses current company scope',
    strpos($admin, 'getCurrentCompanyId()') !== false
    && strpos($admin, 'company_id = :company_id') !== false);
adminWalletCheck('admin validates wallet theme policy',
    strpos($admin, 'WalletThemePolicy::validateTheme') !== false);
adminWalletCheck('admin rejects parent path assets',
    strpos($admin, "strpos(\$previewPath, '..')") !== false);
adminWalletCheck('admin allowlists wallet styles',
    strpos($admin, "['eventTicket', 'storeCard', 'generic']") !== false);
adminWalletCheck('admin writes only tenant owned theme ids',
    strpos($admin, 'id = :id AND company_id = :company_id') !== false);
adminWalletCheck('preview requires admin and tenant scope',
    strpos($preview, 'requireAdmin()') !== false
    && strpos($preview, 'company_id = :company_id') !== false);
adminWalletCheck('preview emits safe svg',
    strpos($preview, 'image/svg+xml') !== false
    && strpos($preview, 'htmlspecialchars') !== false);
adminWalletCheck('backfill supports dry run and fixed global ids',
    strpos($backfill, '--dry-run') !== false
    && substr_count($backfill, '00000000-0000-4000-8000-') >= 3);
adminWalletCheck('backfill creates company defaults from company themes',
    strpos($backfill, 'company_themes') !== false
    && strpos($backfill, 'is_default') !== false);

if ($failed > 0) {
    exit(1);
}
echo "ALL PASS\n";
