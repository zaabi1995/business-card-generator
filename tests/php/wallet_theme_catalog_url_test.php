<?php
function imageUrl($path)
{
    return '/' . ltrim((string) $path, '/');
}

function getTenantUrl($slug, $path = '/')
{
    return 'https://cardify.om' . $path;
}

require_once __DIR__ . '/../../includes/WalletThemeCatalog.php';

function walletThemeUrlCheck(string $name, bool $condition): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$name}\n");
        exit(1);
    }
}

walletThemeUrlCheck(
    'relative preview paths become canonical Cardify HTTPS URLs',
    WalletThemeCatalog::canonicalPreviewUrl('/assets/wallet/teal.png')
        === 'https://cardify.om/assets/wallet/teal.png'
);
walletThemeUrlCheck(
    'existing Cardify HTTPS URLs stay unchanged',
    WalletThemeCatalog::canonicalPreviewUrl('https://bhdoman.cardify.om/theme.png')
        === 'https://bhdoman.cardify.om/theme.png'
);
walletThemeUrlCheck(
    'empty preview paths remain null',
    WalletThemeCatalog::canonicalPreviewUrl('') === null
);

echo "wallet-theme-catalog-url-ok\n";
