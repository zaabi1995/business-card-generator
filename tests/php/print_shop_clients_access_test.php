<?php
/**
 * Print partners operate only the client companies they created or were
 * attached to. BHD's internal-provider shop keeps its existing
 * cross-tenant browse. Regular shops never see another shop's roster.
 *
 * Run: php tests/php/print_shop_clients_access_test.php
 */

$clientsPath = dirname(__DIR__, 2) . '/includes/PrintShopClients.php';
if (is_file($clientsPath)) {
    require_once $clientsPath;
}

$fails = 0;

function partnerCheck(string $label, $got, $want): void
{
    global $fails;
    $ok = $got === $want;
    if (!$ok) {
        $fails++;
    }
    printf("[%s] %s (got=%s want=%s)\n", $ok ? 'PASS' : 'FAIL', $label,
        var_export($got, true), var_export($want, true));
}

if (!class_exists('PrintShopClients')) {
    echo "FAIL PrintShopClients class is available\n";
    exit(1);
}

$omanShop = [
    'id' => 2,
    'status' => 'active',
    'is_internal_provider' => 1,
    'tier' => 'admin',
];
$londonShop = [
    'id' => 88,
    'status' => 'active',
    'is_internal_provider' => 0,
    'tier' => 'standard',
];
$pendingShop = [
    'id' => 89,
    'status' => 'pending',
    'is_internal_provider' => 0,
    'tier' => 'standard',
];
$suspendedShop = [
    'id' => 90,
    'status' => 'suspended',
    'is_internal_provider' => 0,
    'tier' => 'standard',
];

$clientA = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
$clientB = 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';
$attachedToLondon = [$clientA];

partnerCheck(
    'regular shop can open an attached client in company admin',
    PrintShopClients::canAccessCompanyAdmin($londonShop, $clientA, $attachedToLondon),
    true
);
partnerCheck(
    'regular shop cannot open an unattached client in company admin',
    PrintShopClients::canAccessCompanyAdmin($londonShop, $clientB, $attachedToLondon),
    false
);
partnerCheck(
    'regular shop cannot open an empty company id',
    PrintShopClients::canAccessCompanyAdmin($londonShop, '', $attachedToLondon),
    false
);
partnerCheck(
    'internal provider can open any existing company in company admin',
    PrintShopClients::canAccessCompanyAdmin($omanShop, $clientB, []),
    true
);
partnerCheck(
    'pending shop can still operate an attached client',
    PrintShopClients::canAccessCompanyAdmin($pendingShop, $clientA, $attachedToLondon),
    true
);
partnerCheck(
    'suspended shop cannot operate a client company',
    PrintShopClients::canAccessCompanyAdmin($suspendedShop, $clientA, $attachedToLondon),
    false
);
partnerCheck(
    'regular shop lists only attached clients, not every tenant',
    PrintShopClients::listsAllCompanies($londonShop),
    false
);
partnerCheck(
    'internal provider keeps listing every Cardify company',
    PrintShopClients::listsAllCompanies($omanShop),
    true
);
partnerCheck(
    'active shop may create client companies',
    PrintShopClients::canOperateClientTenants($londonShop),
    true
);
partnerCheck(
    'pending shop may create client companies before marketplace approval',
    PrintShopClients::canOperateClientTenants($pendingShop),
    true
);
partnerCheck(
    'suspended shop may not create client companies',
    PrintShopClients::canOperateClientTenants($suspendedShop),
    false
);
partnerCheck(
    'print_shop role is a partner role',
    PrintShopClients::isPartnerRole('print_shop'),
    true
);
partnerCheck(
    'print_shop_operator role is a partner role',
    PrintShopClients::isPartnerRole('print_shop_operator'),
    true
);
partnerCheck(
    'company_admin is not a partner role',
    PrintShopClients::isPartnerRole('company_admin'),
    false
);

if ($fails > 0) {
    echo "FAILED {$fails}\n";
    exit(1);
}

echo "ALL PASS\n";
