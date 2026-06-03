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
    $name      = $employee['name_en'] ?? $employee['name'] ?? 'Employee';
    $position  = $employee['position_en'] ?? $employee['position'] ?? $employee['job_title'] ?? '';
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
    if ($phone !== '') {
        $backFields[] = ['key' => 'phone', 'label' => 'Phone', 'value' => $phone];
    }
    if ($emailAddr !== '') {
        $backFields[] = ['key' => 'email', 'label' => 'Email', 'value' => $emailAddr];
    }
    if ($website !== '') {
        $backFields[] = ['key' => 'website', 'label' => 'Website', 'value' => $website];
    }
    $backFields[] = ['key' => 'card', 'label' => 'Digital Card', 'value' => $cardUrl];

    // Front hierarchy: logo+company (header) -> name (primary) -> position
    // (secondary) -> contacts (auxiliary). Everything is conditional, so a sparse
    // card simply shows fewer rows instead of empty captions.
    $secondaryFields = [];
    if ($position !== '') {
        $secondaryFields[] = ['key' => 'position', 'label' => '', 'value' => $position];
    }
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
        'logoText'            => $companyNm !== '' ? $companyNm : $name,
        'foregroundColor'     => $fgColor,
        'backgroundColor'     => $bgRgb,
        'labelColor'          => $labelColor,
        'barcodes'            => [$qr],
        'barcode'             => $qr, // legacy single-barcode key for older iOS
        'generic' => [
            'primaryFields' => [[
                'key'   => 'name',
                'label' => '',
                'value' => $name,
            ]],
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
    $logoFs = null;
    if ($theme && !empty($theme['logo_path'])) {
        $cand = BASE_DIR . '/' . ltrim($theme['logo_path'], '/');
        if (is_readable($cand)) {
            $logoFs = $cand;
        }
    }

    // 1x1 transparent PNG, last-resort so the manifest always carries a valid icon.
    $transparentPng = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='
    );

    // icon.png is REQUIRED (lock screen / notifications); always present.
    foreach (['icon.png' => 29, 'icon@2x.png' => 58, 'icon@3x.png' => 87] as $fname => $px) {
        $bytes = $logoFs ? WalletImage::fitPng($logoFs, $px, $px) : null;
        $passObj->addAsset($fname, $bytes ?: $transparentPng);
    }
    // logo.png is shown top-left on the brand-coloured header. On a dark brand we
    // knock the logo out to white (only if it has transparency) so it reads as a
    // clean reverse mark instead of a colour-clashing badge. Icon stays original.
    foreach (['logo.png' => [160, 50], 'logo@2x.png' => [320, 100], 'logo@3x.png' => [480, 150]] as $fname => $dim) {
        if (!$logoFs) {
            continue;
        }
        $bytes = WalletImage::fitPng($logoFs, $dim[0], $dim[1], $isDarkBg);
        if ($bytes) {
            $passObj->addAsset($fname, $bytes);
        }
    }

    // No strip/thumbnail: the cramped card-image preview is intentionally omitted
    // for a clean, typographic pass (logo + name + position + contacts + QR).

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
