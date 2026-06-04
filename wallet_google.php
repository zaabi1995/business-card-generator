<?php
/**
 * Google Wallet Save Endpoint
 *
 * GET /wallet_google.php?i=<employee_id>[&c=<company_slug>]
 *
 * 302-redirects to https://pay.google.com/gp/v/save/<signed_jwt>.
 *
 * When GOOGLE_WALLET_ENABLED is false OR credentials aren't present,
 * returns 503 with an admin-facing message.
 */

ob_start();

set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    require_once __DIR__ . '/config.php';
    require_once INCLUDES_DIR . '/GoogleWalletPass.php';
    require_once INCLUDES_DIR . '/CardRenderer.php';

    $employeeId  = trim($_GET['i'] ?? $_GET['employee_id'] ?? '');
    $companySlug = trim($_GET['c'] ?? $_GET['company_slug'] ?? '');

    if ($employeeId === '') {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Missing employee id.\n";
        exit;
    }

    if (!GoogleWalletPass::isEnabled()) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Google Wallet passes are not yet configured for this installation.\n\n";
        echo "Admin: set GOOGLE_WALLET_ENABLED=true and provide GOOGLE_WALLET_SERVICE_ACCOUNT_JSON, ";
        echo "GOOGLE_WALLET_ISSUER_ID, and GOOGLE_WALLET_CLASS_ID in config.php.\n";
        echo "See docs/superpowers/plans/2026-04-16-wallet-passes.md\n";
        exit;
    }

    $company = null;
    if ($companySlug !== '') {
        $company = findCompanyBySlug($companySlug);
    }
    $employee = findEmployeeById($employeeId, $company ? $company['id'] : null);
    if (!$employee) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Card not found.\n";
        exit;
    }
    if (!$company) {
        $company = findCompanyById($employee['company_id']);
    }
    $theme = $company ? loadCompanyTheme($company['id']) : null;

    // Pass language follows the CARDIFY SITE language (?lang=en|ar from the button),
    // matching wallet_apple.php. One deterministic single-language pass.
    $lang = strtolower(trim((string)($_GET['lang'] ?? 'en')));
    if ($lang !== 'ar') { $lang = 'en'; }
    $isAr = ($lang === 'ar');

    $nameEn    = $employee['name_en'] ?? $employee['name'] ?? 'Employee';
    $nameAr    = trim((string)($employee['name_ar'] ?? ''));
    $posEn     = $employee['position_en'] ?? $employee['position'] ?? $employee['job_title'] ?? '';
    $posAr     = trim((string)($employee['position_ar'] ?? ''));
    $name      = ($isAr && $nameAr !== '') ? $nameAr : $nameEn;
    $position  = ($isAr && $posAr !== '')  ? $posAr  : $posEn;
    $companyNm = $company['name'] ?? '';
    $phone     = $employee['mobile'] ?? $employee['phone'] ?? '';
    $emailAddr = $employee['email'] ?? '';
    $website   = $company['website'] ?? '';

    // Localized labels for the text/links modules.
    $L = $isAr
        ? ['phone' => 'الهاتف', 'email' => 'البريد الإلكتروني', 'web' => 'الموقع الإلكتروني',
           'callDesc' => 'اتصل بـ ', 'emailDesc' => 'راسل ', 'card' => 'البطاقة الرقمية', 'logo' => ' شعار']
        : ['phone' => 'PHONE', 'email' => 'EMAIL', 'web' => 'Website',
           'callDesc' => 'Call ', 'emailDesc' => 'Email ', 'card' => 'Business Card', 'logo' => ' logo'];

    $slug   = $company['slug'] ?? $companySlug;

    // Tenant subdomain canonical URL (no double-slug regardless of host).
    $cardUrl = getTenantUrl($slug, '/' . rawurlencode($employee['id']));

    $hexBg = ($theme && !empty($theme['primary_color'])) ? $theme['primary_color'] : '#1a1a1a';
    if (strlen($hexBg) === 4) { // #rgb → #rrggbb
        $hexBg = '#' . $hexBg[1] . $hexBg[1] . $hexBg[2] . $hexBg[2] . $hexBg[3] . $hexBg[3];
    }

    // Public origin Google Wallet uses to fetch hero/logo images. APP_HOST
    // is the canonical project host (CLAUDE.md, "URLs + security").
    $publicOrigin = 'https://' . (defined('APP_HOST') ? APP_HOST : 'cardify.om');

    // Prefer a tenant REVERSE logo ("<name>-dark.<ext>" sibling of logo_path) since
    // the Google pass background is the brand colour; fall back to the normal logo.
    $logoUri = null;
    if ($theme && !empty($theme['logo_path'])) {
        $logoRel = $theme['logo_path'];
        $darkFs  = preg_replace('/(\.[A-Za-z0-9]+)$/', '-dark$1', BASE_DIR . '/' . ltrim($logoRel, '/'));
        if ($darkFs && is_readable($darkFs)) {
            $logoRel = preg_replace('/(\.[A-Za-z0-9]+)$/', '-dark$1', $logoRel);
        }
        $logoUri = $publicOrigin . '/' . ltrim($logoRel, '/');
    }

    // Hero image, the canonical card design. Same PNG that /muhammed.ali shows,
    // wallet_apple.php embeds as strip.png, card-pdf.php prints. Source of
    // truth is CardRenderer::forEmployee. When absent the pass still saves
    // with logo only, audit-card-surfaces.php flags the gap.
    $heroUri = null;
    try {
        $ctx = CardRenderer::forEmployee((string)$employee['id']);
        if ($ctx && !empty($ctx['front_url'])) {
            $u = $ctx['front_url'];
            $heroUri = (preg_match('#^https?://#', $u) === 1) ? $u : ($publicOrigin . '/' . ltrim($u, '/'));
        }
    } catch (Throwable $e) {
        error_log('wallet_google hero image: ' . $e->getMessage());
    }

    $textModules = [];
    if ($phone !== '') {
        $textModules[] = ['id' => 'phone', 'header' => $L['phone'], 'body' => $phone];
    }
    if ($emailAddr !== '') {
        $textModules[] = ['id' => 'email', 'header' => $L['email'], 'body' => $emailAddr];
    }

    $linksUris = [];
    if ($website !== '') {
        $linksUris[] = ['uri' => (stripos($website, 'http') === 0 ? $website : 'https://' . $website),
                        'description' => $L['web']];
    }
    if ($emailAddr !== '') {
        $linksUris[] = ['uri' => 'mailto:' . $emailAddr, 'description' => $L['emailDesc'] . $name];
    }
    if ($phone !== '') {
        $linksUris[] = ['uri' => 'tel:' . preg_replace('/[^+0-9]/', '', $phone), 'description' => $L['callDesc'] . $name];
    }

    $object = [
        // Language-specific object id: Google creates the object from the JWT only if it
        // doesn't already exist, so en + ar need distinct ids or the first-saved language
        // would stick.
        'id'                => GoogleWalletPass::objectResourceId((string)$employee['id'] . '_' . $lang),
        'classId'           => GoogleWalletPass::classResourceId(),
        'state'             => 'ACTIVE',
        'cardTitle'         => ['defaultValue' => ['language' => $lang, 'value' => $companyNm ?: $L['card']]],
        'subheader'         => ['defaultValue' => ['language' => $lang, 'value' => $position]],
        'header'            => ['defaultValue' => ['language' => $lang, 'value' => $name]],
        'hexBackgroundColor' => $hexBg,
        'barcode'           => [
            'type'         => 'QR_CODE',
            'value'        => $cardUrl,
            'alternateText' => $cardUrl,
        ],
        'textModulesData' => $textModules,
    ];
    if (!empty($linksUris)) {
        $object['linksModuleData'] = ['uris' => $linksUris];
    }
    if ($logoUri) {
        $object['logo'] = [
            'sourceUri'         => ['uri' => $logoUri],
            'contentDescription' => ['defaultValue' => ['language' => $lang, 'value' => $companyNm . $L['logo']]],
        ];
    }
    if ($heroUri) {
        $object['heroImage'] = [
            'sourceUri'         => ['uri' => $heroUri],
            'contentDescription' => ['defaultValue' => ['language' => $lang, 'value' => $name]],
        ];
    }

    $saveUrl = GoogleWalletPass::buildSaveUrl($object);

    while (ob_get_level()) { ob_end_clean(); }
    header('Location: ' . $saveUrl, true, 302);
    exit;

} catch (Throwable $e) {
    while (ob_get_level()) { ob_end_clean(); }
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    error_log('wallet_google.php: ' . $e->getMessage());
    echo "Could not generate Google Wallet pass.\n";
    exit;
}
