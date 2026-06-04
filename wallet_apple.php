<?php
/**
 * Apple Wallet Pass Endpoint
 *
 * GET /wallet_apple.php?i=<employee_id>
 *   or /{company_slug}/card/<employee_id>/wallet/apple  (via nginx rewrite, optional)
 *
 * Returns: application/vnd.apple.pkpass
 *
 * When APPLE_WALLET_ENABLED is false OR credentials aren't present,
 * returns 503 with an admin-facing message (see plans/2026-04-16-wallet-passes.md).
 */

ob_start();

set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    require_once __DIR__ . '/config.php';
    require_once INCLUDES_DIR . '/AppleWalletPass.php';
    require_once INCLUDES_DIR . '/WalletImage.php';
    require_once INCLUDES_DIR . '/CardRenderer.php';
    require_once INCLUDES_DIR . '/EmployeeSocials.php';

    $employeeId  = trim($_GET['i'] ?? $_GET['employee_id'] ?? '');
    $companySlug = trim($_GET['c'] ?? $_GET['company_slug'] ?? '');

    if ($employeeId === '') {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Missing employee id.\n";
        exit;
    }

    if (!AppleWalletPass::isEnabled()) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Apple Wallet passes are not yet configured for this installation.\n\n";
        echo "Admin: set APPLE_WALLET_ENABLED=true and provide APPLE_WALLET_CERT_PATH, ";
        echo "APPLE_WALLET_CERT_PASSWORD, APPLE_WALLET_WWDR_PATH, APPLE_WALLET_PASS_TYPE_ID, ";
        echo "and APPLE_WALLET_TEAM_ID in config.php.\n";
        echo "See docs/superpowers/plans/2026-04-16-wallet-passes.md\n";
        exit;
    }

    // Look up employee (scoped to company if slug supplied)
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

    // ---- Pass data ----
    $name        = $employee['name_en'] ?? $employee['name'] ?? 'Employee';
    $nameAr      = trim((string)($employee['name_ar'] ?? ''));
    $position    = $employee['position_en'] ?? $employee['position'] ?? $employee['job_title'] ?? '';
    $positionAr  = trim((string)($employee['position_ar'] ?? ''));
    // Bilingual stacks: Arabic line on top, English underneath (Apple renders \n as a line break).
    $nameVal     = ($nameAr !== '' && $nameAr !== $name) ? ($nameAr . "\n" . $name) : $name;
    $positionVal = ($positionAr !== '' && $positionAr !== $position) ? ($positionAr . "\n" . $position) : $position;
    $companyNm = $company['name'] ?? '';
    $phone     = $employee['mobile'] ?? $employee['phone'] ?? '';
    $emailAddr = $employee['email'] ?? '';
    $website   = $company['website'] ?? '';

    // Digital card URL for QR
    $slug   = $company['slug'] ?? $companySlug;

    // Tenant subdomain canonical URL (no double-slug regardless of host).
    $cardUrl = getTenantUrl($slug, '/' . rawurlencode($employee['id']));

    // Colors. Background = the tenant's brand colour; text auto-contrasts to its
    // luminance so labels stay crisp on any brand (the old fixed dark labelColor
    // went muddy on mid-tone brands).
    $primaryHex = ($theme && !empty($theme['primary_color'])) ? $theme['primary_color'] : '#1a1a1a';
    $rgbOf = function (string $hex): array {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) { $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2]; }
        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    };
    [$br, $bgc, $bb] = $rgbOf($primaryHex);
    $bgRgb = "rgb($br, $bgc, $bb)";
    $lum   = (0.299 * $br + 0.587 * $bgc + 0.114 * $bb) / 255; // 0 dark .. 1 light
    $isDarkBg   = $lum < 0.62;
    $fgColor    = $isDarkBg ? 'rgb(255, 255, 255)' : 'rgb(17, 24, 39)';
    $labelColor = $fgColor; // same hue as values; Apple sizes labels smaller for hierarchy

    $backFields = [];
    // Back fields DO support multi-line, so the Arabic + English stack lives here.
    if ($nameAr !== '' && $nameAr !== $name) {
        $backFields[] = ['key' => 'name_ar', 'label' => 'Name / الاسم', 'value' => $name . "\n" . $nameAr];
    }
    if ($positionAr !== '' && $positionAr !== $position) {
        $backFields[] = ['key' => 'title_ar', 'label' => 'Title / المسمى', 'value' => $position . "\n" . $positionAr];
    }
    if ($website !== '') {
        $backFields[] = ['key' => 'website', 'label' => 'Website', 'value' => $website];
    }
    // Social profiles, rendered as tappable links on the pass back.
    try {
        foreach (EmployeeSocials::loadForEmployee($employee['id']) as $i => $sl) {
            $href = EmployeeSocials::hrefFor($sl['platform'] ?? '', $sl['url'] ?? '');
            if ($href !== '') {
                $backFields[] = [
                    'key'   => 'social_' . ($sl['platform'] ?? $i),
                    'label' => $sl['label'] ?? ucfirst((string)($sl['platform'] ?? 'Link')),
                    'value' => $href,
                ];
            }
        }
    } catch (Throwable $e) {
        error_log('wallet_apple socials: ' . $e->getMessage());
    }
    $backFields[] = ['key' => 'card', 'label' => 'Digital Card', 'value' => $cardUrl];

    // Header tagline, shown top-right next to the logo (e.g. "An Omantel Company").
    // Comes from company_themes.tagline; a text field renders in a single colour.
    $headerFields = [];
    $tagline = trim((string)($theme['tagline'] ?? ''));
    if ($tagline !== '') {
        $headerFields[] = ['key' => 'tagline', 'label' => '', 'value' => $tagline];
    }

    // Store Card layout: logo + tagline header, brand strip band, then name (primary)
    // and title (secondary) STACKED below. Apple text fields are single-line and don't
    // stack Arabic-over-English, so the FRONT shows English (clean, scannable) and the
    // Arabic name/title live on the back (back fields are multi-line). Faithful bilingual
    // front would require baking the text into the strip image.
    $primaryFields = [['key' => 'name', 'label' => '', 'value' => $name]];
    $secondaryFields = [];
    if ($position !== '') {
        $secondaryFields[] = ['key' => 'title', 'label' => '', 'value' => $position];
    }
    // Contacts on the FRONT (auxiliary row): phone + email.
    $auxFields = [];
    if ($phone !== '') {
        $auxFields[] = ['key' => 'phone', 'label' => 'PHONE', 'value' => $phone];
    }
    if ($emailAddr !== '') {
        $auxFields[] = ['key' => 'email', 'label' => 'EMAIL', 'value' => $emailAddr];
    }

    // Barcode WITHOUT altText so iOS draws no URL caption under the QR.
    $qr = [
        'format'          => 'PKBarcodeFormatQR',
        'message'         => $cardUrl,
        'messageEncoding' => 'iso-8859-1',
    ];

    $pass = [
        'formatVersion'       => 1,
        'passTypeIdentifier'  => APPLE_WALLET_PASS_TYPE_ID,
        'serialNumber'        => (string)$employee['id'],
        'teamIdentifier'      => APPLE_WALLET_TEAM_ID,
        'organizationName'    => defined('APPLE_WALLET_ORG_NAME') ? APPLE_WALLET_ORG_NAME : 'Cardify',
        'description'         => trim($name . ($companyNm !== '' ? ', ' . $companyNm : '')),
        'foregroundColor'     => $fgColor,
        'backgroundColor'     => $bgRgb,
        'labelColor'          => $labelColor,
        'barcodes'            => [$qr],
        'barcode'             => $qr, // legacy single-barcode key for older iOS
        // storeCard: gives a crisp strip band (the design lever) + a clean stacked
        // body below it, matching the corporate/membership cards in Apple's HIG.
        'storeCard' => [
            'headerFields'    => $headerFields,
            'primaryFields'   => $primaryFields,
            'secondaryFields' => $secondaryFields,
            'auxiliaryFields' => $auxFields,
            'backFields'      => $backFields,
        ],
    ];

    // ---- Build pass ----
    $passObj = new AppleWalletPass($pass);

    // Bundle the company logo. Apple requires a VALID PNG icon.png; a raw tenant
    // logo can be SVG / JPEG / WebP / oversized, any of which makes iOS reject
    // the pass with the opaque "Cannot add pass". WalletImage re-encodes to clean
    // PNGs at Apple's expected sizes (icon = square; logo = wide, top-left).
    // Icon (system contexts, often on white) = the original-colour logo.
    $iconFs = null;
    if ($theme && !empty($theme['logo_path'])) {
        $cand = BASE_DIR . '/' . ltrim($theme['logo_path'], '/');
        if (is_readable($cand)) {
            $iconFs = $cand;
        }
    }
    // Header logo can use a tenant REVERSE logo: a "<name>-dark.<ext>" sibling of
    // logo_path (white text + brand accents, e.g. otech-logo-dark.png). If present
    // it's used as-is on a dark brand (orange accents preserved); otherwise the
    // plain logo is auto-knocked out to white.
    $logoFs = $iconFs;
    $logoIsReverse = false;
    if ($iconFs && $isDarkBg) {
        $darkCand = preg_replace('/(\.[A-Za-z0-9]+)$/', '-dark$1', $iconFs);
        if ($darkCand && is_readable($darkCand)) {
            $logoFs = $darkCand;
            $logoIsReverse = true;
        }
    }

    // 1x1 transparent PNG, last-resort so the manifest always carries a valid icon.
    $transparentPng = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='
    );

    // icon.png is REQUIRED (lock screen / notifications); always the colour logo.
    foreach (['icon.png' => 29, 'icon@2x.png' => 58, 'icon@3x.png' => 87] as $fname => $px) {
        $bytes = $iconFs ? WalletImage::fitPng($iconFs, $px, $px) : null;
        $passObj->addAsset($fname, $bytes ?: $transparentPng);
    }
    // logo.png top-left on the brand header. Reverse logo used as-is; otherwise the
    // plain logo is knocked out to white on a dark brand (only if it has alpha).
    $knock = $isDarkBg && !$logoIsReverse;
    foreach (['logo.png' => [160, 50], 'logo@2x.png' => [320, 100], 'logo@3x.png' => [480, 150]] as $fname => $dim) {
        if (!$logoFs) {
            continue;
        }
        $bytes = WalletImage::fitPng($logoFs, $dim[0], $dim[1], $knock);
        if ($bytes) {
            $passObj->addAsset($fname, $bytes);
        }
    }

    // Strip band (storeCard). Per-tenant override at uploads/companies/<cid>/
    // wallet-strip[@2x|@3x].png if present; otherwise an auto-generated dotted brand
    // band from the tenant colour. Apple shows the strip CRISP (not blurred).
    $cid = (string)($company['id'] ?? '');
    foreach ([
        'strip.png'    => [375, 144, 'wallet-strip.png'],
        'strip@2x.png' => [750, 288, 'wallet-strip@2x.png'],
        'strip@3x.png' => [1125, 432, 'wallet-strip@3x.png'],
    ] as $asset => $info) {
        [$sw, $shh, $override] = $info;
        $bytes = null;
        if ($cid !== '') {
            $ov = BASE_DIR . '/uploads/companies/' . $cid . '/' . $override;
            if (is_readable($ov)) {
                $bytes = @file_get_contents($ov);
            }
        }
        if ($bytes === null || $bytes === false || $bytes === '') {
            $bytes = WalletImage::brandBackground($primaryHex, $sw, $shh);
        }
        if ($bytes) {
            $passObj->addAsset($asset, $bytes);
        }
    }

    $bytes = $passObj->build();

    // Output
    while (ob_get_level()) { ob_end_clean(); }
    $filename = preg_replace('/[^A-Za-z0-9_-]+/', '_', $name ?: 'card') . '.pkpass';
    header('Content-Type: application/vnd.apple.pkpass');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($bytes));
    header('Cache-Control: no-store');
    echo $bytes;
    exit;

} catch (Throwable $e) {
    while (ob_get_level()) { ob_end_clean(); }
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    error_log('wallet_apple.php: ' . $e->getMessage());
    echo "Could not generate Apple Wallet pass.\n";
    exit;
}
