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

    // ---- Per-device localization (Ali's choice, 4 Jun 2026) ----
    // pass.json field values + labels are KEYS resolved from <lang>.lproj/pass.strings;
    // iOS picks en vs ar by the DEVICE system language, so an Arabic iPhone shows the
    // WHOLE pass in Arabic and iOS auto-mirrors the chrome to RTL (logo moves right,
    // fields right-align). PKTextAlignmentNatural also right-aligns Arabic by script.
    // No documented per-pass RTL toggle exists (deep-research verified) - RTL follows
    // the device language. We always define each key in BOTH tables so nothing leaks.
    $strings = ['en' => [], 'ar' => []];
    $tr = function (string $key, string $en, string $ar) use (&$strings): string {
        $strings['en'][$key] = $en;
        $strings['ar'][$key] = ($ar !== '' ? $ar : $en);
        return $key;
    };
    $NAT = 'PKTextAlignmentNatural';

    // A back field whose value is tappable. Apple renders a tiny HTML subset in
    // attributedValue (mainly <a href>), so phone/email/url become real links on
    // the pass back (validated against TimOliver/PassKit-Business-Card). `value`
    // stays as a plain-text fallback for accessibility + older iOS.
    $linkField = function (string $key, string $labelKey, string $display, string $href) use ($NAT) {
        return [
            'key'             => $key,
            'label'           => $labelKey,
            'value'           => $display,
            'attributedValue' => '<a href="' . htmlspecialchars($href, ENT_QUOTES) . '">'
                                 . htmlspecialchars($display, ENT_QUOTES) . '</a>',
            'textAlignment'   => $NAT,
        ];
    };

    $backFields = [];
    // Tappable contact links on the back, labels localized per device language.
    if ($phone !== '') {
        $backFields[] = $linkField('back_phone', $tr('LBL_PHONE_B', 'Phone', 'الهاتف'), $phone, 'tel:' . preg_replace('/[^\d+]/', '', $phone));
    }
    if ($emailAddr !== '') {
        $backFields[] = $linkField('back_email', $tr('LBL_EMAIL_B', 'Email', 'البريد الإلكتروني'), $emailAddr, 'mailto:' . $emailAddr);
    }
    if ($website !== '') {
        $webHref = preg_match('#^https?://#i', $website) ? $website : ('https://' . $website);
        $backFields[] = $linkField('website', $tr('LBL_WEBSITE', 'Website', 'الموقع الإلكتروني'), $website, $webHref);
    }
    // Social profiles, rendered as tappable links on the pass back.
    try {
        foreach (EmployeeSocials::loadForEmployee($employee['id']) as $i => $sl) {
            $href = EmployeeSocials::hrefFor($sl['platform'] ?? '', $sl['url'] ?? '');
            if ($href !== '') {
                $label = $sl['label'] ?? ucfirst((string)($sl['platform'] ?? 'Link'));
                $backFields[] = $linkField('social_' . ($sl['platform'] ?? $i), $label, $href, $href);
            }
        }
    } catch (Throwable $e) {
        error_log('wallet_apple socials: ' . $e->getMessage());
    }
    $backFields[] = $linkField('card', $tr('LBL_CARD', 'Digital Card', 'البطاقة الرقمية'), $cardUrl, $cardUrl);

    // Header tagline, shown top-right next to the logo (e.g. "An Omantel Company").
    // Comes from company_themes.tagline; a text field renders in a single colour.
    $headerFields = [];
    $tagline = trim((string)($theme['tagline'] ?? ''));
    if ($tagline !== '') {
        $headerFields[] = ['key' => 'tagline', 'label' => '', 'value' => $tagline, 'textAlignment' => $NAT];
    }

    // Store Card front: name (primary) + title (secondary) + phone/email (auxiliary).
    // Values are localization keys, so each device shows its own language; the back
    // carries the tappable full contact set. PKTextAlignmentNatural makes Arabic
    // right-align (RTL) and English left-align automatically.
    $primaryFields = [[
        'key' => 'name', 'label' => '',
        'value' => $tr('FLD_NAME', $name, $nameAr),
        'textAlignment' => $NAT,
    ]];
    $secondaryFields = [];
    if ($position !== '') {
        $secondaryFields[] = [
            'key' => 'title', 'label' => '',
            'value' => $tr('FLD_TITLE', $position, $positionAr),
            'textAlignment' => $NAT,
        ];
    }
    // Contacts on the FRONT (auxiliary row): phone + email, labels localized.
    $auxFields = [];
    if ($phone !== '') {
        $auxFields[] = ['key' => 'phone', 'label' => $tr('LBL_PHONE', 'PHONE', 'الهاتف'), 'value' => $phone, 'textAlignment' => $NAT];
    }
    if ($emailAddr !== '') {
        $auxFields[] = ['key' => 'email', 'label' => $tr('LBL_EMAIL', 'EMAIL', 'البريد الإلكتروني'), 'value' => $emailAddr, 'textAlignment' => $NAT];
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
        $bytes = WalletImage::fitPng($logoFs, $dim[0], $dim[1], $knock, true); // left-aligned in canvas
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

    // Localization tables: en.lproj/pass.strings + ar.lproj/pass.strings. Apple
    // requires UTF-16 for non-ASCII (Arabic), so both are emitted as UTF-16 (BOM +
    // big-endian). Field values/labels above are keys resolved from these at display
    // time; a missing key would fall back to the literal, but $tr always writes both.
    foreach ($strings as $lang => $map) {
        if (!$map) { continue; }
        $lines = '';
        foreach ($map as $k => $v) {
            $ev = str_replace(['\\', '"', "\r", "\n"], ['\\\\', '\\"', '', '\\n'], (string)$v);
            $lines .= '"' . $k . '" = "' . $ev . '";' . "\n";
        }
        $u16 = @iconv('UTF-8', 'UTF-16', $lines);
        if ($u16 !== false && $u16 !== '') {
            $passObj->addAsset($lang . '.lproj/pass.strings', $u16);
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
