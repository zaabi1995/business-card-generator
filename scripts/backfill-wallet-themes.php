<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/WalletThemePolicy.php';

$dryRun = in_array('--dry-run', $argv, true);
$db = Database::getInstance();
$globalThemes = [
    [
        'id' => '00000000-0000-4000-8000-000000000101',
        'name_en' => 'Cardify Teal',
        'name_ar' => 'كاردفاي تركواز',
        'style' => 'eventTicket',
        'background_color' => '#006b7d',
        'foreground_color' => '#ffffff',
        'label_color' => '#ffffff',
        'logo_mode' => 'cardify',
        'is_default' => 1,
        'sort_order' => 10,
    ],
    [
        'id' => '00000000-0000-4000-8000-000000000102',
        'name_en' => 'Midnight',
        'name_ar' => 'منتصف الليل',
        'style' => 'generic',
        'background_color' => '#10243b',
        'foreground_color' => '#ffffff',
        'label_color' => '#dbeafe',
        'logo_mode' => 'company',
        'is_default' => 0,
        'sort_order' => 20,
    ],
    [
        'id' => '00000000-0000-4000-8000-000000000103',
        'name_en' => 'Warm Sand',
        'name_ar' => 'الرمال الدافئة',
        'style' => 'storeCard',
        'background_color' => '#f2e8d5',
        'foreground_color' => '#1f2937',
        'label_color' => '#374151',
        'logo_mode' => 'company',
        'is_default' => 0,
        'sort_order' => 30,
    ],
];

$seededGlobal = 0;
$seededCompany = 0;
foreach ($globalThemes as $globalTheme) {
    WalletThemePolicy::validateTheme($globalTheme);
    $exists = $db->fetchOne(
        'SELECT id FROM wallet_themes WHERE id = :id LIMIT 1',
        ['id' => $globalTheme['id']]
    );
    if (is_array($exists)) {
        continue;
    }
    $seededGlobal++;
    if (!$dryRun) {
        $db->insert('wallet_themes', array_merge($globalTheme, [
            'company_id' => null,
            'is_active' => 1,
        ]));
    }
}

$companies = $db->fetchAll(
    "SELECT c.id, ct.primary_color
       FROM companies c
       LEFT JOIN company_themes ct ON ct.company_id = c.id
      WHERE c.status = 'active'
        AND NOT EXISTS (
            SELECT 1 FROM wallet_themes wt
             WHERE wt.company_id = c.id AND wt.is_default = 1
        )
      ORDER BY c.id"
);
foreach ($companies as $company) {
    try {
        $background = WalletThemePolicy::normalizeColor(
            (string)($company['primary_color'] ?? '#009bc1')
        );
    } catch (InvalidArgumentException $error) {
        $background = '#009bc1';
    }
    $text = WalletThemePolicy::contrastRatio($background, '#ffffff') >= 3.0
        ? '#ffffff'
        : '#111827';
    $companyTheme = WalletThemePolicy::validateTheme([
        'style' => 'eventTicket',
        'background_color' => $background,
        'foreground_color' => $text,
        'label_color' => $text,
        'logo_mode' => 'company',
    ]);
    $seededCompany++;
    if (!$dryRun) {
        $db->insert('wallet_themes', [
            'id' => generateUUID(),
            'company_id' => $company['id'],
            'name_en' => 'Company',
            'name_ar' => 'الشركة',
            'style' => $companyTheme['style'],
            'background_color' => $companyTheme['background_color'],
            'foreground_color' => $companyTheme['foreground_color'],
            'label_color' => $companyTheme['label_color'],
            'logo_mode' => $companyTheme['logo_mode'],
            'is_default' => 1,
            'is_active' => 1,
            'sort_order' => 0,
        ]);
    }
}

printf(
    "%s global=%d company=%d\n",
    $dryRun ? 'DRY RUN' : 'APPLIED',
    $seededGlobal,
    $seededCompany
);
