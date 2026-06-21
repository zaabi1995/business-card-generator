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

    // Resolve the tenant first: an explicit ?c= slug wins; otherwise derive it
    // from the tenant subdomain so the lookup is ALWAYS company-scoped on a
    // tenant host (stops one tenant requesting another tenant's pass by bare
    // employee id).
    require_once INCLUDES_DIR . '/TenantHost.php';
    $company = null;
    if ($companySlug !== '') {
        $company = findCompanyBySlug($companySlug);
    } elseif (TenantHost::isTenantHost()) {
        $company = TenantHost::resolve();
    }
    $employee = findEmployeeById($employeeId, $company ? $company['id'] : null);
    if (!$employee) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Card not found.\n";
        exit;
    }
    // Don't mint a pass for a card that isn't live yet (mirror digital_card.php).
    if (($employee['status'] ?? 'active') === 'pending_approval') {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Card not found.\n";
        exit;
    }
    if (!$company) {
        $company = findCompanyById($employee['company_id']);
    }
    $theme = $company ? loadCompanyTheme($company['id']) : null;

    // Pass language follows the CARDIFY SITE language (passed as ?lang=en|ar by the
    // button), NOT the device locale. One deterministic single-language pass.
    $lang = strtolower(trim((string)($_GET['lang'] ?? 'en')));
    if ($lang !== 'ar') { $lang = 'en'; }
    $isAr = ($lang === 'ar');

    // ---- Pass data ----
    $name        = $employee['name_en'] ?? $employee['name'] ?? 'Employee';
    $nameAr      = trim((string)($employee['name_ar'] ?? ''));
    $position    = $employee['position_en'] ?? $employee['position'] ?? $employee['job_title'] ?? '';
    $positionAr  = trim((string)($employee['position_ar'] ?? ''));
    // Display values resolved to the chosen language (fall back to the other language
    // if a translation is missing so a field is never blank).
    $nameDisp     = ($isAr && $nameAr !== '')     ? $nameAr     : $name;
    $positionDisp = ($isAr && $positionAr !== '') ? $positionAr : $position;
    $nameRaw  = $nameDisp;   // unwrapped, used to bake the Arabic strip image
    $titleRaw = $positionDisp;
    // Apple renders pass fields with an LTR base paragraph direction on non-Arabic
    // devices and gives NO direction attribute, so a multi-word Arabic name/title can
    // lay out left-to-right. Force RTL base at the character level with Unicode
    // embedding (U+202B RLE ... U+202C PDF). Only wrap the Arabic text fields (NOT
    // phone/email, whose +968 / latin content must stay LTR).
    if ($isAr) {
        $rtl = function (string $s): string {
            return $s === '' ? $s : "\u{202B}" . $s . "\u{202C}";
        };
        $nameDisp     = $rtl($nameDisp);
        $positionDisp = $rtl($positionDisp);
    }
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

    // ---- Single-language pass in the site language ($lang) ----
    // storeCard MERGES secondaryFields + auxiliaryFields into ONE shared row, so
    // name + title + phone + email all competing there truncates the long title.
    // Clean fix: FRONT shows identity only (name primary + title secondary, each
    // full-width); the FULL contact set lives on the back as tappable links.
    // Alignment: Arabic forces RIGHT so the phone/email labels + their LTR digit values
    // hug the right edge (PKTextAlignmentNatural left-aligns LTR digits, which looked
    // wrong on the Arabic pass). English uses Natural.
    $NAT = $isAr ? 'PKTextAlignmentRight' : 'PKTextAlignmentNatural';
    $L = $isAr
        ? ['phone' => 'الهاتف', 'email' => 'البريد الإلكتروني', 'web' => 'الموقع الإلكتروني', 'card' => 'البطاقة الرقمية']
        : ['phone' => 'Phone', 'email' => 'Email', 'web' => 'Website', 'card' => 'Digital Card'];

    // A back field whose value is tappable. Apple renders a tiny HTML subset in
    // attributedValue (mainly <a href>), so phone/email/url become real links on
    // the pass back (validated against TimOliver/PassKit-Business-Card). `value`
    // stays as a plain-text fallback for accessibility + older iOS.
    $linkField = function (string $key, string $label, string $display, string $href) use ($NAT) {
        return [
            'key'             => $key,
            'label'           => $label,
            'value'           => $display,
            'attributedValue' => '<a href="' . htmlspecialchars($href, ENT_QUOTES) . '">'
                                 . htmlspecialchars($display, ENT_QUOTES) . '</a>',
            'textAlignment'   => $NAT,
        ];
    };

    $backFields = [];
    if ($phone !== '') {
        $backFields[] = $linkField('back_phone', $L['phone'], $phone, 'tel:' . preg_replace('/[^\d+]/', '', $phone));
    }
    if ($emailAddr !== '') {
        $backFields[] = $linkField('back_email', $L['email'], $emailAddr, 'mailto:' . $emailAddr);
    }
    if ($website !== '') {
        $webHref = preg_match('#^https?://#i', $website) ? $website : ('https://' . $website);
        $backFields[] = $linkField('website', $L['web'], $website, $webHref);
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
    $backFields[] = $linkField('card', $L['card'], $cardUrl, $cardUrl);

    // Header tagline, shown top-right next to the logo (e.g. "An Omantel Company").
    // Comes from company_themes.tagline; a text field renders in a single colour.
    $headerFields = [];
    $tagline = trim((string)($theme['tagline'] ?? ''));
    if ($tagline !== '') {
        $headerFields[] = ['key' => 'tagline', 'label' => '', 'value' => $tagline, 'textAlignment' => $NAT];
    }

    // Auxiliary contact fields (phone / email), shared by both layouts.
    $auxFields = [];
    if ($phone !== '') {
        $auxFields[] = ['key' => 'phone', 'label' => $L['phone'], 'value' => $phone, 'textAlignment' => $NAT];
    }
    if ($emailAddr !== '') {
        $auxFields[] = ['key' => 'email', 'label' => $L['email'], 'value' => $emailAddr, 'textAlignment' => $NAT];
    }
    // RTL: the row is laid out left-to-right by array order, so reverse it for Arabic
    // (phone becomes the rightmost cell = first in RTL reading order).
    if ($isAr) {
        $auxFields = array_reverse($auxFields);
    }

    // Square QR, no altText so iOS draws no URL caption under it.
    $qr = [
        'format'          => 'PKBarcodeFormatQR',
        'message'         => $cardUrl,
        'messageEncoding' => 'iso-8859-1',
    ];

    // Common pass head (style dict + style-specific assets added per branch below).
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
    ];

    // Resolve the tenant logo file (shared). Apple requires a VALID PNG icon.png; a raw
    // tenant logo can be SVG / JPEG / WebP / oversized, any of which makes iOS reject the
    // pass with the opaque "Cannot add pass", so WalletImage re-encodes to clean PNGs.
    // logo_path is stored inconsistently across tenants: some flows save it WITH the
    // 'uploads/' prefix (onboarding, apply_theme), others WITHOUT it (theme-builder), so
    // try every sensible resolution or the wallet logo silently disappears.
    $iconFs = null;
    if ($theme && !empty($theme['logo_path'])) {
        $lp = ltrim((string) $theme['logo_path'], '/');
        $cands = [BASE_DIR . '/' . $lp, BASE_DIR . '/uploads/' . $lp];
        if (strpos($lp, 'uploads/') === 0) {
            $cands[] = BASE_DIR . '/' . substr($lp, 8);
        }
        foreach ($cands as $c) {
            if (is_readable($c)) { $iconFs = $c; break; }
        }
    }
    // On a dark brand, prefer a "<name>-dark.<ext>" reverse logo (white text + brand
    // accents, e.g. otech-logo-dark.png) if the tenant uploaded one; else the plain logo
    // is auto-knocked out to white.
    $logoFs = $iconFs;
    $logoIsReverse = false;
    if ($iconFs && $isDarkBg) {
        $darkCand = preg_replace('/(\.[A-Za-z0-9]+)$/', '-dark$1', $iconFs);
        if ($darkCand && is_readable($darkCand)) {
            $logoFs = $darkCand;
            $logoIsReverse = true;
        } elseif (($__base = preg_replace('/\.[A-Za-z0-9]+$/', '', $iconFs))) {
            foreach (glob($__base . '-dark.*') ?: [] as $__g) {
                if (is_readable($__g)) { $logoFs = $__g; $logoIsReverse = true; break; }
            }
        }
    }
    $knock = $isDarkBg && !$logoIsReverse;

    // 1x1 transparent PNG, last-resort so the manifest always carries a valid icon.
    $transparentPng = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='
    );

    // ---- Layout choice ----
    // Poster generic (iOS 27 HIG) is the new full-art design. It degrades gracefully to
    // a plain generic pass on older iOS via preferredStyleSchemes. Default OFF (legacy
    // eventTicket stays live for tenants) until device-verified; flip the global on with
    // APPLE_WALLET_POSTER_GENERIC=true in config. Per-request test override: ?poster=1|0.
    $poster = defined('APPLE_WALLET_POSTER_GENERIC') && APPLE_WALLET_POSTER_GENERIC;
    if (isset($_GET['poster'])) {
        $poster = ($_GET['poster'] === '1' || strtolower((string) $_GET['poster']) === 'true');
    }

    if ($poster) {
        // ===== POSTER GENERIC (iOS 27 HIG) =====
        // generic dict + a poster artwork.png + a square QR triggers the poster layout
        // (full-art background, primary fields, footer field). preferredStyleSchemes
        // validates the poster scheme and falls back to the designed generic type if the
        // OS can't render it, so older iOS shows a clean plain-generic pass instead.
        $pass['preferredStyleSchemes'] = ['posterGeneric'];
        if ($companyNm !== '') {
            $pass['logoText'] = $companyNm;
        }

        // Arabic bakes name+title into the artwork (true RTL on any device). English uses
        // accessible text fields and leaves the artwork text-free (Apple: reserve images
        // for visual content).
        $artwork = WalletImage::brandArtwork(
            $primaryHex,
            $isAr ? $nameRaw  : '',
            $isAr ? $titleRaw : '',
            $isAr
        );

        if ($isAr && !empty($artwork)) {
            $primaryFields   = [];
            $secondaryFields = [];
        } else {
            $primaryFields = [[
                'key' => 'name', 'label' => '', 'value' => $nameDisp, 'textAlignment' => $NAT,
            ]];
            $secondaryFields = [];
            if ($positionDisp !== '') {
                $secondaryFields[] = ['key' => 'title', 'label' => '', 'value' => $positionDisp, 'textAlignment' => $NAT];
            }
        }

        $pass['generic'] = [
            'headerFields'    => $headerFields,
            'primaryFields'   => $primaryFields,
            'secondaryFields' => $secondaryFields,
            'auxiliaryFields' => $auxFields,
            'backFields'      => $backFields,
        ];

        $passObj = new AppleWalletPass($pass);

        // icon.png (HIG: 38pt square) for lock screen / Mail / Wallet chrome. Colour logo.
        foreach (['icon.png' => 38, 'icon@2x.png' => 76, 'icon@3x.png' => 114] as $fname => $px) {
            $bytes = $iconFs ? WalletImage::fitPng($iconFs, $px, $px) : null;
            $passObj->addAsset($fname, $bytes ?: $transparentPng);
        }
        // primaryLogo.png (HIG: poster passes use primaryLogo, max 126x30) top-leading,
        // plus logo.png so the older-iOS plain-generic fallback still shows a logo.
        if ($logoFs) {
            $logoSets = [
                'primaryLogo.png'    => [126, 30], 'primaryLogo@2x.png' => [252, 60], 'primaryLogo@3x.png' => [378, 90],
                'logo.png'           => [160, 50], 'logo@2x.png'        => [320, 100], 'logo@3x.png'        => [480, 150],
            ];
            foreach ($logoSets as $fname => $dim) {
                $bytes = WalletImage::fitPng($logoFs, $dim[0], $dim[1], $knock, true);
                if ($bytes) { $passObj->addAsset($fname, $bytes); }
            }
        }
        // artwork.png (HIG: 358x448) full-bleed crisp poster background.
        foreach ($artwork as $asset => $bytes) {
            $passObj->addAsset($asset, $bytes);
        }
    } else {
        // ===== LEGACY eventTicket / storeCard (pre-iOS-27 design) =====
        // Arabic bakes name+title into a taller storeCard strip (true RTL); English uses
        // eventTicket text fields (the only style with SEPARATE secondary + auxiliary rows
        // so a long title never truncates next to phone/email). Both supply a strip image
        // (no background) so it stays crisp.
        $bakedStrip = $isAr ? WalletImage::brandStripWithText($primaryHex, $nameRaw, $titleRaw) : [];
        $arBaked    = !empty($bakedStrip);
        $styleKey   = $arBaked ? 'storeCard' : 'eventTicket';

        if ($arBaked) {
            $primaryFields   = [];
            $secondaryFields = [];
        } else {
            $primaryFields = [[
                'key' => 'name', 'label' => '', 'value' => $nameDisp, 'textAlignment' => 'PKTextAlignmentCenter',
            ]];
            $secondaryFields = [];
            if ($positionDisp !== '') {
                $secondaryFields[] = ['key' => 'title', 'label' => '', 'value' => $positionDisp, 'textAlignment' => $NAT];
            }
        }

        $pass[$styleKey] = [
            'headerFields'    => $headerFields,
            'primaryFields'   => $primaryFields,
            'secondaryFields' => $secondaryFields,
            'auxiliaryFields' => $auxFields,
            'backFields'      => $backFields,
        ];

        $passObj = new AppleWalletPass($pass);

        // icon.png (HIG: 38pt square). Always the colour logo.
        foreach (['icon.png' => 38, 'icon@2x.png' => 76, 'icon@3x.png' => 114] as $fname => $px) {
            $bytes = $iconFs ? WalletImage::fitPng($iconFs, $px, $px) : null;
            $passObj->addAsset($fname, $bytes ?: $transparentPng);
        }
        // logo.png top-left on the brand header.
        if ($logoFs) {
            foreach (['logo.png' => [160, 50], 'logo@2x.png' => [320, 100], 'logo@3x.png' => [480, 150]] as $fname => $dim) {
                $bytes = WalletImage::fitPng($logoFs, $dim[0], $dim[1], $knock, true);
                if ($bytes) { $passObj->addAsset($fname, $bytes); }
            }
        }

        // Strip band. Arabic-baked: the SVG-rendered strip holds the RTL name+title at
        // storeCard dims (375x144). Else a plain dotted brand band at eventTicket dims
        // (375x98), with optional per-tenant override at uploads/companies/<cid>/.
        if ($arBaked) {
            foreach ($bakedStrip as $asset => $bytes) {
                $passObj->addAsset($asset, $bytes);
            }
        } else {
            $cid = (string)($company['id'] ?? '');
            foreach ([
                'strip.png'    => [375, 98, 'wallet-strip.png'],
                'strip@2x.png' => [750, 196, 'wallet-strip@2x.png'],
                'strip@3x.png' => [1125, 294, 'wallet-strip@3x.png'],
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
