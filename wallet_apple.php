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
    $position  = $employee['position'] ?? $employee['job_title'] ?? '';
    $companyNm = $company['name'] ?? '';
    $phone     = $employee['mobile'] ?? $employee['phone'] ?? '';
    $emailAddr = $employee['email'] ?? '';
    $website   = $company['website'] ?? '';

    // Digital card URL for QR
    $slug   = $company['slug'] ?? $companySlug;

    // Tenant subdomain canonical URL (no double-slug regardless of host).
    $cardUrl = getTenantUrl($slug, '/' . rawurlencode($employee['id']));

    // Colors (hex → "rgb(r,g,b)" per PKPass spec)
    $primaryHex   = ($theme && !empty($theme['primary_color']))   ? $theme['primary_color']   : '#1a1a1a';
    $secondaryHex = ($theme && !empty($theme['secondary_color'])) ? $theme['secondary_color'] : '#d4af37';
    $hexToRgb = function (string $hex): string {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        return "rgb($r, $g, $b)";
    };

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

    $pass = [
        'formatVersion'       => 1,
        'passTypeIdentifier'  => APPLE_WALLET_PASS_TYPE_ID,
        'serialNumber'        => (string)$employee['id'],
        'teamIdentifier'      => APPLE_WALLET_TEAM_ID,
        'organizationName'    => defined('APPLE_WALLET_ORG_NAME') ? APPLE_WALLET_ORG_NAME : 'Cardify',
        'description'         => $name . ', ' . $companyNm,
        'foregroundColor'     => 'rgb(255, 255, 255)',
        'backgroundColor'     => $hexToRgb($primaryHex),
        'labelColor'          => $hexToRgb($secondaryHex),
        'barcodes' => [[
            'format'          => 'PKBarcodeFormatQR',
            'message'         => $cardUrl,
            'messageEncoding' => 'iso-8859-1',
            'altText'         => $cardUrl,
        ]],
        'generic' => [
            'primaryFields' => [[
                'key'   => 'name',
                'label' => 'Name',
                'value' => $name,
            ]],
            'secondaryFields' => array_values(array_filter([
                $position !== ''  ? ['key' => 'title',   'label' => 'Title',   'value' => $position]  : null,
                $companyNm !== '' ? ['key' => 'company', 'label' => 'Company', 'value' => $companyNm] : null,
            ])),
            'backFields' => $backFields,
        ],
    ];

    // ---- Build pass ----
    $passObj = new AppleWalletPass($pass);

    // Bundle logo if we have one in the theme (Apple requires icon.png minimum).
    // Apple expects icon.png (29pt) + icon@2x.png (58pt). We use the same file for both
    // if only one size is available; Apple is tolerant of single-resolution art.
    $logoBytes = null;
    if ($theme && !empty($theme['logo_path'])) {
        $logoFs = BASE_DIR . '/' . ltrim($theme['logo_path'], '/');
        if (is_readable($logoFs)) {
            $logoBytes = @file_get_contents($logoFs);
        }
    }
    if ($logoBytes === null || $logoBytes === false || $logoBytes === '') {
        // Minimal 1x1 transparent PNG fallback so the manifest always has icon.png
        $logoBytes = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='
        );
    }
    $passObj->addAsset('icon.png',    $logoBytes);
    $passObj->addAsset('icon@2x.png', $logoBytes);
    $passObj->addAsset('logo.png',    $logoBytes);
    $passObj->addAsset('logo@2x.png', $logoBytes);

    // Strip image, the canonical card design from CardRenderer. Same PNG that
    // /muhammed.ali serves, that wallet_google.php embeds, that card-pdf.php
    // prints. Source of truth is templates.fields_json + admin upload, exported
    // by the Fabric.js editor via save_card_*.php. When the canonical PNG is
    // missing the pass still builds (icon/logo only) so the user gets a usable
    // wallet card, audit-card-surfaces.php will flag the missing render.
    try {
        $ctx = CardRenderer::forEmployee((string)$employee['id']);
        if ($ctx && !empty($ctx['front_fs']) && is_readable($ctx['front_fs'])) {
            $stripBytes = @file_get_contents($ctx['front_fs']);
            if ($stripBytes !== false && $stripBytes !== '') {
                $passObj->addAsset('strip.png',    $stripBytes);
                $passObj->addAsset('strip@2x.png', $stripBytes);
                $passObj->addAsset('thumbnail.png',    $stripBytes);
                $passObj->addAsset('thumbnail@2x.png', $stripBytes);
            }
        }
    } catch (Throwable $e) {
        error_log('wallet_apple strip image: ' . $e->getMessage());
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
