<?php

$root = dirname(__DIR__, 2);
$page = file_get_contents($root . '/delete-account.php');
$routes = file_get_contents($root . '/.htaccess');

function deletionPageCheck(string $label, bool $condition): void
{
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $label . "\n";
    if (!$condition) {
        exit(1);
    }
}

deletionPageCheck(
    'public deletion page exists',
    is_string($page) && $page !== ''
);
deletionPageCheck(
    'clean deletion URL is routed',
    is_string($routes)
        && str_contains($routes, 'RewriteRule ^delete-account/?$ delete-account.php [L,QSA]')
);
deletionPageCheck(
    'page names the Cardify app',
    str_contains($page, 'Cardify account and data deletion')
);
deletionPageCheck(
    'page documents the in-app deletion path',
    str_contains($page, 'Settings')
        && str_contains($page, 'Delete account')
);
deletionPageCheck(
    'page explains deleted and retained data',
    str_contains($page, 'Data deleted')
        && str_contains($page, 'Data retained')
        && str_contains($page, '30 days')
);
deletionPageCheck(
    'page provides an external deletion request route',
    str_contains($page, 'privacy@cardify.om')
);
deletionPageCheck(
    'page provides Arabic deletion guidance',
    str_contains($page, 'حذف حساب وبيانات كارديفاي')
        && str_contains($page, 'حذف الحساب')
);

echo "ALL PASS\n";
