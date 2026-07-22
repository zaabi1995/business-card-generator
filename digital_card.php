<?php
/**
 * Digital Card Page
 * Displays a branded digital business card with flip animation, contact actions, and vCard download.
 * URL: /{company_slug}/card/{employee_id} (via nginx rewrite)
 */

ob_start();

// Stream the response: above-the-fold HTML is pushed to the browser the moment
// it is rendered (after the bottom-buttons div), then the below-the-fold section
// queries run and the rest of the body flushes after. Nginx defaults to buffering
// FastCGI responses; this header tells it to pass our flushed chunks straight to
// the client. Cloudflare preserves streaming responses unchanged.
header('X-Accel-Buffering: no');

set_error_handler(function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    require_once __DIR__ . '/config.php';
    require_once INCLUDES_DIR . '/QRTracker.php';
    require_once INCLUDES_DIR . '/CardifyConvention.php';
    require_once INCLUDES_DIR . '/CardSections.php';
    require_once INCLUDES_DIR . '/Appointments.php';
    require_once INCLUDES_DIR . '/EmployeeSocials.php';
    require_once INCLUDES_DIR . '/CardAnalytics.php';
    require_once INCLUDES_DIR . '/ColorContrast.php';

    /**
     * Normalize asset path, ensure uploaded/theme assets resolve from site root, not
     * relative to the current /{slug}/card/{eid} URL. DB rows historically stored
     * paths in three shapes: "/uploads/..", "uploads/..", and bare "companies/..".
     */
    if (!function_exists('cardifyAssetUrl')) {
        function cardifyAssetUrl($path) {
            $p = trim((string)$path);
            if ($p === '') return '';
            if (preg_match('#^(https?:)?//#i', $p)) return $p; // absolute URL
            if ($p[0] === '/') return $p;                     // already site-root
            // Bare relative, prepend /uploads/ for company theme uploads
            if (strpos($p, 'uploads/') === 0) return '/' . $p;
            return '/uploads/' . $p;
        }
    }

    $companySlug = trim($_GET['company_slug'] ?? '');
    $employeeId = trim($_GET['employee_id'] ?? '');

    // On a tenant subdomain the slug comes from the host, not the URL.
    if ($companySlug === '') {
        require_once INCLUDES_DIR . '/TenantHost.php';
        if (TenantHost::isTenantHost()) {
            $companySlug = (string) TenantHost::slug();
        }
    }

    if (empty($companySlug) || empty($employeeId)) {
        throw new Exception('Missing parameters');
    }

    // Look up company
    $company = findCompanyBySlug($companySlug);
    if (!$company) {
        http_response_code(404);
        renderBranded404(null, null);
        exit;
    }

    // Canonicalize: /{slug}/card/{id} on apex -> {slug}.cardify.om/card/{id}.
    // Preserve query string so ?lang=ar (and any future query params) survive
    // the redirect; without this, RTL detection on the subdomain falls back
    // to default English.
    $__h = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? ''));
    if (in_array($__h, ['cardify.om', 'www.cardify.om'], true) && ($company['status'] ?? 'active') === 'active') {
        $__qs = $_SERVER['QUERY_STRING'] ?? '';
        parse_str($__qs, $__qsParams);
        unset($__qsParams['company_slug'], $__qsParams['employee_id']);
        $__qsOut = http_build_query($__qsParams);
        // Resolve to the pretty bare share URL when the token routes bare;
        // employeeShareUrl falls back to /card/<id> for non-routable localparts.
        $__redirEmp = CardifyConvention::resolveEmployeeToken($employeeId, $company['id']);
        $__target = ($__redirEmp
                ? CardifyConvention::employeeShareUrl($companySlug, $__redirEmp)
                : getTenantUrl($companySlug, '/card/' . rawurlencode($employeeId)))
            . ($__qsOut ? '?' . $__qsOut : '');
        header('Location: ' . $__target, true, 301);
        exit;
    }

    // Look up employee scoped to company.
    // The URL segment can be an employee_id, an email, OR the email localpart
    // (e.g. /jarwish9 or /firstname.lastname). The nginx tenant rewrite routes
    // both single tokens and dotted localparts here, so we try all three in
    // turn: explicit UUID → full email → localpart (with `.`/`_`/`-` variants).
    // Shared with api/scan/resolve-card.php so both surfaces resolve a token
    // to an employee identically instead of drifting apart.
    $employee = CardifyConvention::resolveEmployeeToken($employeeId, $company['id']);
    // Fall back to the latest pending/approved card_request so a freshly
    // submitted request still resolves to an E-Card page; the actual VCF
    // download button below also honours this fallback.
    if (!$employee) {
        $db2 = Database::getInstance();
        $req = null;
        if (strpos($employeeId, '@') !== false) {
            $req = $db2->fetchOne(
                "SELECT * FROM card_requests
                  WHERE company_id = :cid AND LOWER(email) = LOWER(:em)
                    AND status IN ('pending','approved')
                  ORDER BY submitted_at DESC LIMIT 1",
                ['cid' => $company['id'], 'em' => $employeeId]
            );
        } else {
            $req = $db2->fetchOne(
                "SELECT * FROM card_requests WHERE id = :id AND company_id = :cid LIMIT 1",
                ['id' => $employeeId, 'cid' => $company['id']]
            );
        }
        if ($req) {
            $employee = [
                'id'           => $req['id'],
                'email'        => $req['email'],
                'name_en'      => $req['name_en']      ?? '',
                'name_ar'      => $req['name_ar']      ?? '',
                'position_en'  => $req['position_en']  ?? '',
                'position_ar'  => $req['position_ar']  ?? '',
                'phone'        => $req['phone']        ?? '',
                'phone_ar'     => $req['phone_ar']     ?? '',
                'mobile'       => $req['mobile']       ?? '',
                'mobile_ar'    => $req['mobile_ar']    ?? '',
                'website'      => $req['website']      ?? '',
                'website_ar'   => $req['website_ar']   ?? '',
                'address_en'   => $req['address_en']   ?? '',
                'address_ar'   => $req['address_ar']   ?? '',
                'address_2_en' => $req['address_2_en'] ?? '',
                'address_2_ar' => $req['address_2_ar'] ?? '',
                'company_en'   => $req['company_en']   ?? $company['name'] ?? '',
                'company_ar'   => $req['company_ar']   ?? '',
                'status'       => 'pending_approval',
                'photo'        => $req['photo']        ?? null,
                'department_id'=> $req['department_id']?? null,
            ];
        }
    }
    if (!$employee) {
        // Try to load theme for branded 404
        $theme = loadCompanyTheme($company['id']);
        http_response_code(404);
        renderBranded404($company, $theme);
        exit;
    }

    // Load company theme
    $theme = loadCompanyTheme($company['id']);

    // Instant/demo cards carry a per-employee brand colour + verified flag in
    // demo_meta. Let the chosen colour override the (shared demo tenant) theme so
    // each demo card shows the colour its owner picked in the homepage hero.
    $demoMeta = (!empty($employee['demo_meta'])) ? json_decode((string)$employee['demo_meta'], true) : null;
    $isDemoUnverified = false;
    if (is_array($demoMeta)) {
        if (!empty($demoMeta['brand_color']) && preg_match('/^#[0-9a-fA-F]{6}$/', (string)$demoMeta['brand_color'])) {
            if (!is_array($theme)) { $theme = []; }
            $theme['primary_color'] = $demoMeta['brand_color'];
        }
        $isDemoUnverified = empty($demoMeta['verified']);
    }

    // Load latest generated card
    $db = Database::getInstance();
    $card = $db->fetchOne(
        "SELECT * FROM generated_cards WHERE employee_id = :eid AND company_id = :cid ORDER BY generated_at DESC LIMIT 1",
        ['eid' => $employee['id'], 'cid' => $company['id']]
    );

    // Resolve canonical card aspect ratio from the admin-designed template
    // (settings_json customWidth/Height). Same source CardRenderer reads, so
    // the public page, card-pdf.php, wallet passes, and admin editor all show
    // identical proportions. Fallback = standard business card 1.545:1.
    require_once INCLUDES_DIR . '/CardRenderer.php';
    $cardAspect = 1.545;
    try {
        $rendererCtx = CardRenderer::forEmployee((string)$employee['id']);
        if ($rendererCtx && !empty($rendererCtx['aspect_ratio'])) {
            $cardAspect = (float)$rendererCtx['aspect_ratio'];
        }
    } catch (Throwable $e) {
        error_log('digital_card aspect lookup: ' . $e->getMessage());
    }
    $cardAspectCss = number_format($cardAspect, 4, '.', '') . ' / 1';

    // Log QR scan (non-fatal)
    // QRTracker::logScan and CardAnalytics::logView are deferred until after
    // the response is sent (see the fastcgi_finish_request block at the very
    // bottom of this file). They are inserts; the user does not need to wait
    // on them. Trims another ~5-15ms off TTFB.

    // Pending-approval card_requests don't live in `employees`, so the
    // /card_click.php tracker (employee-only) would 400 on every button.
    // For those, skip the tracker and emit the destination URL directly.
    $isPendingPreview = (($employee['status'] ?? '') === 'pending_approval');

    // Helper to build a tracked CTA URL (falls back to the raw destination
    // for pending previews so Call / WhatsApp / Email / Save Contact work).
    $__eid = $employee['id'];
    $cardClickUrl = function ($cta, $dest) use ($__eid, $isPendingPreview) {
        if ($isPendingPreview) return $dest;
        return '/card_click.php?eid=' . urlencode($__eid)
            . '&cta=' . urlencode($cta)
            . '&dest=' . urlencode($dest);
    };

    // Determine theme mode (dark card = light page, light card = dark page)
    $themeMode = 'dark'; // default: dark page
    if ($card && !empty($card['theme_mode'])) {
        $themeMode = $card['theme_mode']; // 'light' or 'dark'
    } elseif ($theme && !empty($theme['secondary_color'])) {
        // Fallback: use company secondary color luminance
        $hex = ltrim($theme['secondary_color'], '#');
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        $luminance = 0.299 * $r + 0.587 * $g + 0.114 * $b;
        $themeMode = $luminance < 128 ? 'dark' : 'light';
    }

    // Theme mode from card means: 'dark' = dark card, so show LIGHT page. 'light' = light card, so show DARK page.
    $isDarkPage = ($themeMode === 'light'); // light card -> dark page
    $accentColor = ($theme && !empty($theme['primary_color'])) ? $theme['primary_color'] : '#d4af37';
    // Guard against a near-white brand colour (e.g. a logo's white background
    // auto-picked as primary_color) that would render the Call / Save Contact
    // buttons and section headers invisible. No-op for readable brand colours.
    $accentColor = ColorContrast::safeAccent($accentColor);

    // Visitor-facing dark mode toggle (migration 057). Default ON; owner can
    // disable per employee OR at the company level (admin > theme settings).
    $themeToggleEnabled = !isset($employee['card_dark_mode_toggle'])
        || $employee['card_dark_mode_toggle'] === null
        || (int)$employee['card_dark_mode_toggle'] === 1;
    if (isset($company['ecard_theme_toggle_enabled']) && (int)$company['ecard_theme_toggle_enabled'] === 0) {
        $themeToggleEnabled = false;
    }

    // Company-level E-Card switches (admin > theme settings)
    $ecardBilingual       = !isset($company['ecard_bilingual']) || (int)$company['ecard_bilingual'] === 1;
    // "Made with Cardify" footer is always shown, company-wide, no opt-out.
    // Viral growth is a first-class product value; Pro tier's hide_cardify_branding
    // flag is also ignored here intentionally.
    $ecardShowViralFooter = true;
    $ecardDefaultTheme    = $company['ecard_default_theme'] ?? 'auto';
    if ($ecardDefaultTheme === 'dark')  $isDarkPage = true;
    if ($ecardDefaultTheme === 'light') $isDarkPage = false;

    // Cookie override (only when toggle is enabled), keeps SSR theme in sync with visitor choice.
    $cookieTheme = $_COOKIE['cardify_card_theme'] ?? '';
    if ($themeToggleEnabled && in_array($cookieTheme, ['light', 'dark'], true)) {
        $isDarkPage = ($cookieTheme === 'dark');
    }
    $defaultThemeMode = $isDarkPage ? 'dark' : 'light';

    // Card image paths, DB stores filenames, construct full web path
    $frontImage = '';
    $backImage = '';
    if ($card) {
        $cardBasePath = '/uploads/companies/' . $company['id'] . '/cards/';
        $frontRaw = $card['front_web_path'] ?: ($card['front_file_path'] ?? '');
        $backRaw = $card['back_web_path'] ?: ($card['back_file_path'] ?? '');
        // If path is just a filename (no slashes), prepend the directory
        $frontImage = $frontRaw ? (strpos($frontRaw, '/') === false ? $cardBasePath . $frontRaw : $frontRaw) : '';
        $backImage = $backRaw ? (strpos($backRaw, '/') === false ? $cardBasePath . $backRaw : $backRaw) : '';
    }

    // Build VCF download URL. Always point at /vcf.php, which unconditionally
    // serves the text/vcard file. Do NOT use /qr.php?i= here: that endpoint
    // doubles as the printed-card QR target and 302-redirects to the owner's
    // qr_redirect_url when one is set, so the Save Contact button would loop
    // back to the card page (Safari then "saves the page") instead of saving
    // the contact. vcf.php has a card_requests fallback so pending previews work too.
    $vcfUrl = '/vcf.php?company=' . urlencode($company['slug'])
           . '&email=' . urlencode($employee['email']);

    // ---- Locale resolution (sets cookie if ?lang= present) -------------
    $locale = CardSections::resolveLocale();
    $isRtl = CardSections::isRtl($locale);

    // ---- Optional per-tenant THIRD language (opt-in) -------------------
    // A company can add ONE extra card language on top of EN/AR. Field
    // values live in generic *_l3 columns (CardSections reads them via
    // locale 'l3', EN fallback); chrome uses the real ISO code's lang files.
    // Zero effect on any tenant that has not configured a third language.
    $thirdCode  = trim((string)($company['ecard_third_lang'] ?? ''));
    $thirdLabel = trim((string)($company['ecard_third_lang_label'] ?? ''));
    $thirdRtl   = (int)($company['ecard_third_lang_rtl'] ?? 0) === 1;
    $isThird = false;
    if ($thirdCode !== '' && $thirdLabel !== '') {
        $want = $_GET['lang'] ?? ($_COOKIE['cardify_lang_v3'] ?? '');
        if ($want === $thirdCode) {
            $isThird = true;
            $locale  = 'l3';        // CardSections reads *_l3 columns
            $isRtl   = $thirdRtl;
            if (class_exists('I18n')) { I18n::allow($thirdCode, $thirdRtl); I18n::setLocale($thirdCode); }
        }
    }
    $htmlLang = $isThird ? $thirdCode : ($isRtl ? 'ar' : 'en');

    // Employee contact data, localized with EN fallback
    $name = CardSections::tColumn($employee, 'name', $locale);
    if (trim((string)$name) === '') $name = $employee['name'] ?? 'Employee';
    // OTECH: show first + family name only on the digital card (vCard keeps full name)
    if (($company['slug'] ?? '') === 'otech') $name = CardSections::displayShortName($name);
    $position = CardSections::tColumn($employee, 'position', $locale);
    if (trim((string)$position) === '') $position = $employee['position'] ?? $employee['job_title'] ?? '';
    $companyName = CardSections::tColumn($company, 'name', $locale);
    // Company name has no *_l3 column; use the employee's company_l3 for the 3rd language.
    if ($isThird && !empty($employee['company_l3'])) $companyName = $employee['company_l3'];
    if (trim((string)$companyName) === '') $companyName = $company['name'] ?? '';
    $phone = $employee['phone'] ?? '';
    $mobile = $employee['mobile'] ?? '';
    // Same number stored in both phone + mobile (just different formatting)
    // rendered as two identical rows. Compare digits-only so e.g.
    // "+96871616161" == "+968 71616161" and show the number once.
    if ($phone !== '' && $mobile !== ''
        && preg_replace('/\D+/', '', (string) $phone) === preg_replace('/\D+/', '', (string) $mobile)) {
        $phone = '';
    }
    $email = $employee['email'] ?? '';
    // Fax / website / address come from the EMPLOYEE first. `companies` has no
    // bare `address` / `website` column (only default_address_en / default_website
    // / default_fax), so the old $company['address'] / $company['website'] reads
    // were always empty. Fall back to the company-wide default when the employee
    // has none. Address is locale-aware.
    $fax = trim((string)($employee['fax'] ?? ''));
    if ($fax === '') $fax = trim((string)($company['default_fax'] ?? ''));
    // Locale-aware (en / ar / l3 third language), all with an EN fallback.
    $website = trim((string) CardSections::tColumn($employee, 'website', $locale));
    if ($website === '') $website = trim((string)($company['default_website'] ?? ''));
    $address = trim((string) CardSections::tColumn($employee, 'address', $locale));
    if ($address === '') $address = trim((string)($company['default_address_en'] ?? ''));

    // Phone for WhatsApp (strip + and non-digits)
    $waPhone = preg_replace('/[^0-9]/', '', $mobile ?: $phone);

    // Logo path
    $logoPath = ($theme && !empty($theme['logo_path'])) ? cardifyAssetUrl($theme['logo_path']) : '';

    // Social links are rendered in the hero (above the fold), so they must
    // load before the early flush. Every OTHER section-data query has been
    // moved past the flush boundary (search "DEFERRED-LOAD" below) so the
    // hero + Save/Download/Share buttons can paint before those queries run.
    $socialLinks = EmployeeSocials::loadForEmployee($employee['id']);

    // ---- Profile-photo (vCard) layout -------------------------------------
    // Per-employee switch (employees.card_page_layout):
    //   'auto'  = photo-led when a photo exists, else the printed card (default,
    //             so existing cards with no photo are unchanged)
    //   'card'  = always the printed business card (opt a person out)
    //   'photo' = always the photo-led layout (initials fallback if no photo)
    // When photo-led, the printed card is still reachable behind a small
    // "View business card" reveal so it is never lost.
    $photoUrl = !empty($employee['photo']) ? cardifyAssetUrl($employee['photo']) : '';
    $cardLayout = strtolower(trim((string)($employee['card_page_layout'] ?? 'auto')));
    if (!in_array($cardLayout, ['auto', 'card', 'photo'], true)) $cardLayout = 'auto';
    $leadWithPhoto = ($cardLayout === 'photo') || ($cardLayout === 'auto' && $photoUrl !== '');
    // Initials fallback for a forced photo layout with no photo on file.
    $initials = '';
    if ($leadWithPhoto && $photoUrl === '') {
        $__nm = trim((string)$name) !== '' ? trim((string)$name) : trim((string)($employee['name_en'] ?? ''));
        $__pp = preg_split('/\s+/', $__nm, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($__pp) {
            $initials = mb_strtoupper(mb_substr($__pp[0], 0, 1));
            if (count($__pp) > 1) $initials .= mb_strtoupper(mb_substr($__pp[count($__pp) - 1], 0, 1));
        }
    }

} catch (Throwable $e) {
    while (ob_get_level()) { ob_end_clean(); }
    http_response_code(500);
    echo 'Error loading card.';
    error_log("digital_card.php error: " . $e->getMessage());
    exit;
}

// loadCompanyTheme() now lives in includes/functions.php (shared with the wallet
// endpoints). Kept out of here to avoid a duplicate definition.

/**
 * Render branded 404 page
 */
function renderBranded404($company, $theme) {
    $accentColor = ($theme && !empty($theme['primary_color'])) ? $theme['primary_color'] : '#d4af37';
    // Guard against a near-white brand colour (e.g. a logo's white background
    // auto-picked as primary_color) that would render the Call / Save Contact
    // buttons and section headers invisible. No-op for readable brand colours.
    $accentColor = ColorContrast::safeAccent($accentColor);
    $logoPath = ($theme && !empty($theme['logo_path'])) ? cardifyAssetUrl($theme['logo_path']) : '';
    $companyName = $company ? ($company['name'] ?? '') : '';
    // Demo/instant cards: show the person's typed company (employee.company_en),
    // not the shared `demo` tenant name, so the card reads as their own.
    if (!empty($demoMeta) && !empty($employee['company_en'])) {
        $companyName = $employee['company_en'];
    }
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title><?= htmlspecialchars(t('digitalcard.unavailable_title')) ?><?php echo $companyName ? ' - ' . htmlspecialchars($companyName) : ''; ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { min-height: 100vh; min-height: 100dvh; display: flex; align-items: center; justify-content: center; background: linear-gradient(to bottom, #141421, #1a1a2e); font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; color: #eee; padding: 24px; }
        .container { text-align: center; max-width: 360px; }
        .logo { margin-bottom: 24px; }
        .logo img { max-width: 120px; height: auto; border-radius: 8px; }
        h1 { font-size: 20px; margin-bottom: 8px; }
        p { color: #888; font-size: 14px; line-height: 1.6; }
        .footer { margin-top: 32px; font-size: 11px; color: #444; }
        .footer a { color: <?php echo htmlspecialchars($accentColor); ?>; text-decoration: none; }
    </style>
</head>
<body>
    <div class="container">
        <?php if ($logoPath): ?>
            <div class="logo"><img src="<?php echo htmlspecialchars($logoPath); ?>" alt="<?php echo htmlspecialchars($companyName); ?>"></div>
        <?php endif; ?>
        <h1><?= htmlspecialchars(t('digitalcard.unavailable_h1')) ?></h1>
        <p><?= htmlspecialchars(t('digitalcard.unavailable_body')) ?></p>
        <div class="footer"><?= htmlspecialchars(t('digitalcard.powered_by')) ?> <a href="/">Cardify</a></div>
    </div>
</body>
</html>
    <?php
    return;
}

// Render the digital card page
ob_end_clean();
// Build language switcher URLs (preserve current path, swap lang param)
$__currentPath = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
$__qs = $_GET;
unset($__qs['lang'], $__qs['company_slug'], $__qs['employee_id']);
$__qBase = $__qs ? ('?' . http_build_query($__qs) . '&') : '?';
$switchEnUrl = htmlspecialchars($__currentPath . $__qBase . 'lang=en', ENT_QUOTES);
$switchArUrl = htmlspecialchars($__currentPath . $__qBase . 'lang=ar', ENT_QUOTES);
$switchThirdUrl = ($thirdCode !== '' && $thirdLabel !== '')
    ? htmlspecialchars($__currentPath . $__qBase . 'lang=' . rawurlencode($thirdCode), ENT_QUOTES)
    : '';
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($htmlLang, ENT_QUOTES); ?>"<?php echo $isRtl ? ' dir="rtl"' : ''; ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo htmlspecialchars($name); ?> - <?php echo htmlspecialchars($companyName); ?></title>
    <meta name="description" content="<?php echo htmlspecialchars($name . ' - ' . $position . ' at ' . $companyName); ?>">
    <meta property="og:title" content="<?php echo htmlspecialchars($name . ' - ' . $companyName); ?>">
    <meta property="og:description" content="<?php echo htmlspecialchars($position . ' at ' . $companyName); ?>">
    <meta property="og:type" content="profile">
    <?php
        // OG scrapers (WhatsApp/Facebook) drop RELATIVE og:image URLs, so the
        // shared card lost its preview. Absolutize against the current host.
        $__ogScheme = ((($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')) ? 'https' : 'http';
        $__ogHost   = $_SERVER['HTTP_HOST'] ?? (defined('APP_HOST') ? APP_HOST : 'cardify.om');
        // Prefer the printed card front; for a photo-led card with no card image
        // fall back to the profile photo so the WhatsApp/link preview still shows.
        $__ogSrc    = $frontImage ?: ($photoUrl ?? '');
        $__ogImage  = $__ogSrc ? ((strpos($__ogSrc, 'http') === 0) ? $__ogSrc : ($__ogScheme . '://' . $__ogHost . '/' . ltrim($__ogSrc, '/'))) : '';
        // Canonical = the pretty bare share URL (e.g. /sami.alismaili), never
        // the /card/<id> form, so shares + previews normalize to one URL.
        $__shareUrl = (class_exists('CardifyConvention') && !empty($company['slug']))
            ? CardifyConvention::employeeShareUrl($company['slug'], $employee)
            : ($__ogScheme . '://' . $__ogHost . strtok($_SERVER['REQUEST_URI'] ?? '/', '?'));
    ?>
    <link rel="canonical" href="<?php echo htmlspecialchars($__shareUrl, ENT_QUOTES); ?>">
    <meta property="og:url" content="<?php echo htmlspecialchars($__shareUrl, ENT_QUOTES); ?>">
    <?php if ($__ogImage): ?>
    <meta property="og:image" content="<?php echo htmlspecialchars($__ogImage, ENT_QUOTES); ?>">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:image" content="<?php echo htmlspecialchars($__ogImage, ENT_QUOTES); ?>">
    <?php endif; ?>
    <link rel="icon" type="image/png" href="<?php echo (!empty($theme['favicon_path'])) ? htmlspecialchars(cardifyAssetUrl($theme['favicon_path'])) : ($logoPath ? htmlspecialchars($logoPath) : '/favicon.svg'); ?>">
    <?php if ($isRtl): ?>
    <link rel="preconnect" href="https://fonts.bhd.om" crossorigin>
    <link rel="stylesheet" href="https://fonts.bhd.om/css2?family=Noto+Sans+Arabic:wght@400;500;600;700&display=swap" media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="https://fonts.bhd.om/css2?family=Noto+Sans+Arabic:wght@400;500;600;700&display=swap"></noscript>
    <?php endif; ?>
    <!-- Icons load async (media=print -> all on load) so text paints immediately; icons fill in a beat later.
         Font Awesome is self-hosted on cardify.om: no third-party DNS lookup, reuses the existing HTTP/2
         connection to the same origin, and Cloudflare cdn-cache + brotli applies the same as for the HTML. -->
    <link rel="preload" href="https://design.bhd.om/fa/v7.2.0/webfonts/fa-solid-900.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="https://design.bhd.om/fa/v7.2.0/webfonts/fa-brands-400.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="https://design.bhd.om/fa/v7.2.0/css/fontawesome.min.css?v=7.2.0" media="print" onload="this.media='all'">
    <link rel="stylesheet" href="https://design.bhd.om/fa/v7.2.0/css/solid.min.css?v=7.2.0" media="print" onload="this.media='all'">
    <link rel="stylesheet" href="https://design.bhd.om/fa/v7.2.0/css/brands.min.css?v=7.2.0" media="print" onload="this.media='all'">
    <noscript>
        <link rel="stylesheet" href="https://design.bhd.om/fa/v7.2.0/css/fontawesome.min.css?v=7.2.0">
        <link rel="stylesheet" href="https://design.bhd.om/fa/v7.2.0/css/solid.min.css?v=7.2.0">
        <link rel="stylesheet" href="https://design.bhd.om/fa/v7.2.0/css/brands.min.css?v=7.2.0">
    </noscript>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body { overflow-x: hidden; }
        /* Honeypot anti-spam fields, visually hidden without causing document overflow (esp. in RTL) */
        .hp, .lead-form .hp { position: absolute !important; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0,0,0,0); clip-path: inset(50%); white-space: nowrap; border: 0; left: auto !important; }
        body {
            /* 100dvh (dynamic viewport) tracks the currently-visible height as
               the iOS Safari toolbar shows/hides; plain 100vh resolves to the
               LARGE viewport (toolbar-hidden height), so content gets sized
               against more height than is actually visible = the classic iOS
               "fits then scrolls when the bar appears" jump. 100vh kept first
               as the fallback for browsers without dvh. */
            min-height: 100vh;
            min-height: 100dvh;
            /* Centre the (intentionally compact) card column so the leftover
               viewport height splits top/bottom instead of dumping as empty
               space below the footer on tall screens. margin:auto on the child
               (below) is overflow-safe: long multi-section cards collapse the
               auto margins to 0 and top-align + scroll normally. The top
               lang/theme controls are position:absolute, so they stay pinned
               in the corner and are unaffected by the centring. */
            display: flex;
            flex-direction: column;
            font-family: <?php echo $isRtl ? "'Noto Sans Arabic', " : ''; ?>-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            -webkit-font-smoothing: antialiased;
            <?php if ($isDarkPage): ?>
            background: linear-gradient(to bottom, #141421, #1a1a2e);
            color: #eee;
            <?php else: ?>
            background: linear-gradient(to bottom, #f5f6f8, #ebedf0);
            color: #1a1a2e;
            <?php endif; ?>
        }
        .page-container {
            max-width: 420px;
            margin: auto;
            padding: 12px 16px 12px;
            width: 100%;
        }

        /* Company Logo */
        .company-logo {
            text-align: center;
            margin-bottom: 10px;
        }
        .company-logo img {
            max-width: 96px;
            height: auto;
            border-radius: 8px;
        }

        /* Motion tokens: stronger than the built-in CSS easings, which lack punch. */
        :root {
            --ease-out: cubic-bezier(0.23, 1, 0.32, 1);
            --ease-in-out: cubic-bezier(0.77, 0, 0.175, 1);
        }

        /* Card Flip (~10% smaller than the control column for a lighter hero) */
        .card-flip-container {
            perspective: 1000px;
            max-width: 352px;
            margin: 0 auto 6px;
            cursor: pointer;
            -webkit-tap-highlight-color: transparent;
        }
        /* One-sided card: no flip, no pointer affordance */
        .card-flip-container.no-back { cursor: default; perspective: none; }
        .card-flip-inner {
            position: relative;
            width: 100%;
            aspect-ratio: var(--card-aspect, 1.545 / 1);
            transition: transform 0.6s var(--ease-in-out);
            transform-style: preserve-3d;
        }
        .card-flip-inner.flipped {
            transform: rotateY(180deg);
        }
        .card-face {
            position: absolute;
            width: 100%;
            height: 100%;
            backface-visibility: hidden;
            -webkit-backface-visibility: hidden;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: <?php echo $isDarkPage ? '0 6px 24px rgba(0,0,0,0.35)' : '0 4px 20px rgba(0,0,0,0.12)'; ?>;
        }
        .card-face img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            display: block;
        }
        .card-back-face {
            transform: rotateY(180deg);
        }

        /* Reduced motion: keep the flip + opacity feedback, drop the movement. */
        @media (prefers-reduced-motion: reduce) {
            .card-flip-inner { transition-duration: 0.01ms; }
            .action-btn:active,
            .bottom-btn:active,
            .wallet-buttons .wallet-btn:active,
            .social-link:active { transform: none; }
            @media (hover: hover) and (pointer: fine) {
                .social-link:hover { transform: none; }
            }
            .copy-toast { transition-duration: 0.01ms; }
        }
        .tap-hint {
            text-align: center;
            font-size: 11px;
            color: <?php echo $isDarkPage ? '#666' : '#999'; ?>;
            margin-top: 4px;
            transition: opacity 0.5s;
        }

        /* Profile photo (vCard) hero */
        .avatar-hero {
            display: flex;
            justify-content: center;
            margin: 4px auto 10px;
        }
        .avatar-photo {
            width: 124px;
            height: 124px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid <?php echo $isDarkPage ? 'rgba(255,255,255,0.14)' : '#ffffff'; ?>;
            box-shadow: <?php echo $isDarkPage ? '0 6px 24px rgba(0,0,0,0.4)' : '0 4px 20px rgba(0,0,0,0.15)'; ?>;
            background: <?php echo $isDarkPage ? '#1a1a1a' : '#f0f0f0'; ?>;
        }
        .avatar-initials {
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ffffff;
            font-size: 44px;
            font-weight: 700;
            letter-spacing: 1px;
        }
        /* "View business card" reveal shown under the photo */
        .view-card-toggle {
            max-width: 352px;
            margin: 0 auto 10px;
            text-align: center;
        }
        .view-card-summary {
            list-style: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            font-weight: 600;
            padding: 6px 14px;
            border-radius: 999px;
            color: <?php echo $isDarkPage ? '#bbb' : '#555'; ?>;
            background: <?php echo $isDarkPage ? 'rgba(255,255,255,0.06)' : 'rgba(0,0,0,0.04)'; ?>;
            -webkit-tap-highlight-color: transparent;
            transition: background 0.2s var(--ease-out), transform 0.15s var(--ease-out);
        }
        .view-card-summary::-webkit-details-marker { display: none; }
        .view-card-summary:active { transform: scale(0.97); }
        .view-card-toggle[open] .view-card-summary { margin-bottom: 8px; }

        /* Employee Info */
        .employee-info {
            text-align: center;
            margin: 8px auto 12px;
            max-width: 400px;
        }
        .employee-name {
            font-size: 21px;
            font-weight: 700;
            <?php if ($isDarkPage): ?>
            color: #f0f0f0;
            <?php else: ?>
            color: #1a1a2e;
            <?php endif; ?>
        }
        .employee-title {
            font-size: 13px;
            margin-top: 3px;
            <?php if ($isDarkPage): ?>
            color: #777;
            <?php else: ?>
            color: #666;
            <?php endif; ?>
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 10px;
            max-width: 400px;
            margin: 0 auto 10px;
        }
        .action-btn {
            flex: 1;
            padding: 10px 8px;
            border-radius: 10px;
            text-align: center;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            color: white;
            display: block;
            transition: transform 0.16s var(--ease-out), opacity 0.16s var(--ease-out);
        }
        .action-btn:active { opacity: 0.85; transform: scale(0.97); }
        .btn-call { background: <?php echo htmlspecialchars($accentColor); ?>; }
        .btn-whatsapp { background: #25d366; }
        .btn-email {
            <?php if ($isDarkPage): ?>
            background: rgba(255,255,255,0.1);
            color: #ddd;
            <?php else: ?>
            background: #1a1a2e;
            <?php endif; ?>
        }

        /* Contact Details */
        .contact-card {
            max-width: 400px;
            margin: 0 auto;
            border-radius: 12px;
            overflow: hidden;
            <?php if ($isDarkPage): ?>
            background: rgba(255,255,255,0.06);
            border: 1px solid rgba(255,255,255,0.08);
            <?php else: ?>
            background: white;
            box-shadow: 0 1px 4px rgba(0,0,0,0.06);
            <?php endif; ?>
        }
        .contact-row {
            display: flex;
            align-items: center;
            padding: 10px 16px;
            font-size: 13px;
            text-decoration: none;
            transition: background 0.15s;
            <?php if ($isDarkPage): ?>
            color: #ddd;
            border-bottom: 1px solid rgba(255,255,255,0.06);
            <?php else: ?>
            color: #333;
            border-bottom: 1px solid #f0f0f0;
            <?php endif; ?>
        }
        .contact-row:last-child { border-bottom: none; }
        .contact-row:active {
            <?php echo $isDarkPage ? 'background: rgba(255,255,255,0.04);' : 'background: #f8f8f8;'; ?>
        }
        .contact-icon {
            width: 24px;
            font-size: 15px;
            flex-shrink: 0;
            text-align: center;
            color: <?php echo htmlspecialchars($accentColor); ?>;
        }
        .contact-value {
            flex: 1;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        /* Addresses are long: let them wrap in full instead of truncating. */
        .contact-value.wrap {
            overflow: visible;
            text-overflow: clip;
            white-space: normal;
            line-height: 1.35;
        }

        /* Social Links — branded pills, mobile + desktop friendly */
        .social-links {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 12px;
            max-width: 400px;
            margin: 16px auto 0;
        }
        .social-link {
            width: 48px;
            height: 48px;
            border-radius: 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #ffffff;
            color: #111827;
            text-decoration: none;
            box-shadow: 0 1px 2px rgba(17,24,39,.06), 0 4px 12px rgba(17,24,39,.05);
            transition: transform .18s ease, box-shadow .18s ease, background .18s ease, color .18s ease;
        }
        .social-link i { font-size: 20px; line-height: 1; }
        .social-link:focus-visible {
            box-shadow: 0 4px 8px rgba(17,24,39,.10), 0 12px 24px rgba(17,24,39,.10);
            outline: none;
        }
        /* Lift only on real hover hardware; on touch this would stick after a tap. */
        @media (hover: hover) and (pointer: fine) {
            .social-link:hover {
                transform: translateY(-2px);
                box-shadow: 0 4px 8px rgba(17,24,39,.10), 0 12px 24px rgba(17,24,39,.10);
            }
        }
        .social-link:active { transform: scale(0.96); }
        /* Brand-tinted hover, keeps idle state clean white */
        .social-link[data-platform="linkedin"]:hover    { background: #0a66c2; color: #fff; }
        .social-link[data-platform="twitter"]:hover     { background: #000;    color: #fff; }
        .social-link[data-platform="facebook"]:hover    { background: #1877f2; color: #fff; }
        .social-link[data-platform="instagram"]:hover   { background: linear-gradient(45deg,#f09433,#e6683c,#dc2743,#cc2366,#bc1888); color: #fff; }
        .social-link[data-platform="youtube"]:hover     { background: #ff0000; color: #fff; }
        .social-link[data-platform="tiktok"]:hover      { background: #000;    color: #fff; }
        .social-link[data-platform="whatsapp"]:hover    { background: #25d366; color: #fff; }
        .social-link[data-platform="telegram"]:hover    { background: #229ed9; color: #fff; }
        .social-link[data-platform="snapchat"]:hover    { background: #fffc00; color: #111827; }
        .social-link[data-platform="github"]:hover      { background: #111827; color: #fff; }
        .social-link[data-platform="website"]:hover,
        .social-link[data-platform="email"]:hover       { background: #111827; color: #fff; }
        <?php if ($isDarkPage): ?>
        .social-link { background: rgba(255,255,255,.08); color: #f5f5f5; box-shadow: none; }
        .social-link:hover, .social-link:focus-visible { box-shadow: 0 4px 12px rgba(0,0,0,.3); }
        <?php endif; ?>

        /* Bottom Buttons */
        .bottom-buttons {
            display: flex;
            gap: 10px;
            max-width: 400px;
            margin: 10px auto 0;
        }
        .bottom-btn {
            flex: 1;
            padding: 10px;
            border-radius: 10px;
            text-align: center;
            font-size: 14px;
            font-weight: 600;
            /* <button> elements don't inherit body's font-family in most
               browsers; they fall back to a UA-specific font. In Arabic this
               showed the Share label in a different face than Save/Call/etc.
               Forcing inherit keeps the whole row in Noto Sans Arabic. */
            font-family: inherit;
            text-decoration: none;
            display: block;
            cursor: pointer;
            border: none;
            transition: opacity 0.2s;
        }
        /* Same safeguard for any other <button> on the page (Share,
           testimonial-toggle, appointment submit, lead form submit, etc.). */
        button { font-family: inherit; }
        .bottom-btn { transition: transform 0.16s var(--ease-out), opacity 0.16s var(--ease-out); }
        .bottom-btn:active { opacity: 0.85; transform: scale(0.97); }
        .btn-save {
            background: <?php echo htmlspecialchars($accentColor); ?>;
            color: white;
        }
        .btn-share {
            <?php if ($isDarkPage): ?>
            background: rgba(255,255,255,0.08);
            color: #ddd;
            border: 1px solid rgba(255,255,255,0.12);
            <?php else: ?>
            background: white;
            color: #333;
            border: 1px solid #ddd;
            <?php endif; ?>
        }
        .btn-pdf {
            <?php if ($isDarkPage): ?>
            background: rgba(255,255,255,0.12);
            color: #fff;
            border: 1px solid rgba(255,255,255,0.2);
            <?php else: ?>
            background: #f3f4f6;
            color: #111;
            border: 1px solid #d1d5db;
            <?php endif; ?>
        }
        /* Wallet buttons */
        .wallet-buttons {
            display: flex;
            gap: 10px;
            max-width: 400px;
            margin: 9px auto 0;
            flex-direction: row;
        }
        .wallet-buttons .wallet-btn {
            flex: 1;
            padding: 10px 14px;
            border-radius: 10px;
            text-align: center;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            cursor: pointer;
            border: 1px solid rgba(0,0,0,0.08);
            background: #000;
            color: #fff;
            transition: transform 0.16s var(--ease-out), opacity 0.16s var(--ease-out);
        }
        .wallet-buttons .wallet-btn:active { opacity: 0.85; transform: scale(0.97); }
        .wallet-buttons .wallet-btn.google {
            background: #fff;
            color: #1f1f1f;
            border: 1px solid #dadce0;
        }
        .wallet-buttons .wallet-btn svg { flex-shrink: 0; }
        /* Show only the current platform's wallet (JS adds the class). */
        .wallet-buttons.plat-apple  .wallet-btn.google { display: none; }
        .wallet-buttons.plat-google .wallet-btn.apple  { display: none; }

        /* Footer */
        .page-footer {
            text-align: center;
            margin-top: 14px;
            font-size: 11px;
            color: <?php echo $isDarkPage ? '#444' : '#bbb'; ?>;
        }
        .page-footer a {
            color: <?php echo htmlspecialchars($accentColor); ?>;
            text-decoration: none;
            font-weight: 600;
        }

        /* Copy toast */
        .copy-toast {
            position: fixed;
            bottom: 24px;
            left: 50%;
            transform: translateX(-50%) translateY(20px);
            background: rgba(0,0,0,0.85);
            color: white;
            padding: 10px 20px;
            border-radius: 8px;
            font-size: 13px;
            opacity: 0;
            transition: transform 0.3s var(--ease-out), opacity 0.3s var(--ease-out);
            pointer-events: none;
            z-index: 100;
        }
        .copy-toast.show {
            opacity: 1;
            transform: translateX(-50%) translateY(0);
        }

        /* Public Card Sections */
        .card-section { max-width: 400px; margin: 24px auto 0; padding: 20px; border-radius: 14px;
            <?php if ($isDarkPage): ?>background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.08);<?php else: ?>background: white; box-shadow: 0 1px 4px rgba(0,0,0,0.06);<?php endif; ?>
        }
        .card-section h3 { font-size: 13px; letter-spacing: 0.6px; text-transform: uppercase; font-weight: 700; margin-bottom: 12px; color: <?php echo htmlspecialchars($accentColor); ?>; }
        .section-bio { font-size: 14px; line-height: 1.6; <?php echo $isDarkPage ? 'color:#ccc;' : 'color:#333;'; ?> }
        .service-row { display: flex; gap: 12px; padding: 10px 0; <?php echo $isDarkPage ? 'border-bottom: 1px solid rgba(255,255,255,0.06);' : 'border-bottom: 1px solid #f0f0f0;'; ?> }
        .service-row:last-child { border-bottom: none; }
        .service-icon { width: 36px; height: 36px; flex-shrink: 0; border-radius: 10px; display: flex; align-items: center; justify-content: center; background: <?php echo htmlspecialchars($accentColor); ?>22; color: <?php echo htmlspecialchars($accentColor); ?>; font-size: 16px; }
        .service-body .service-title { font-size: 14px; font-weight: 600; <?php echo $isDarkPage ? 'color:#eee;' : 'color:#1a1a2e;'; ?> }
        .service-body .service-desc  { font-size: 12px; <?php echo $isDarkPage ? 'color:#888;' : 'color:#666;'; ?> margin-top: 2px; line-height: 1.45; }
        .gallery-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px; }
        .gallery-grid img { width: 100%; aspect-ratio: 1; object-fit: cover; border-radius: 8px; cursor: zoom-in; }
        .video-frame { position: relative; width: 100%; aspect-ratio: 16/9; border-radius: 10px; overflow: hidden; background: #000; }
        .video-frame iframe, .video-frame video { position: absolute; inset: 0; width: 100%; height: 100%; border: 0; display: block; }
        .video-link-btn { display: inline-flex; align-items: center; gap: 8px; padding: 10px 16px; border-radius: 10px; background: <?php echo htmlspecialchars($accentColor); ?>; color: #fff; font-size: 14px; font-weight: 600; text-decoration: none; }
        .faq-list { display: flex; flex-direction: column; gap: 8px; }
        .faq-item { border-radius: 10px; <?php echo $isDarkPage ? 'background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.08);' : 'background: #fafafa; border: 1px solid #ececec;'; ?> overflow: hidden; }
        .faq-item[open] { <?php echo $isDarkPage ? 'background: rgba(255,255,255,0.06);' : 'background: #fff; border-color: #e0e0e0;'; ?> }
        .faq-q { list-style: none; cursor: pointer; display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 12px 14px; font-size: 14px; font-weight: 600; <?php echo $isDarkPage ? 'color:#eee;' : 'color:#1a1a2e;'; ?> user-select: none; }
        .faq-q::-webkit-details-marker { display: none; }
        .faq-q-text { flex: 1; line-height: 1.4; <?php echo $isRtl ? 'text-align:right;' : ''; ?> }
        .faq-icon { flex-shrink: 0; width: 22px; height: 22px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; background: <?php echo htmlspecialchars($accentColor); ?>22; color: <?php echo htmlspecialchars($accentColor); ?>; font-size: 11px; transition: transform 0.2s ease; }
        .faq-item[open] .faq-icon i::before { content: "\f068"; /* fa-minus */ }
        .faq-a { padding: 0 14px 14px; font-size: 13px; line-height: 1.55; <?php echo $isDarkPage ? 'color:#bbb;' : 'color:#555;'; ?> <?php echo $isRtl ? 'text-align:right;' : ''; ?> }
        .offers-list { display: flex; flex-direction: column; gap: 10px; }
        .offer-card { padding: 12px; border-radius: 12px; <?php echo $isDarkPage ? 'background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.08);' : 'background: #fafafa; border: 1px solid #ececec;'; ?> }
        .offer-head { display: flex; align-items: center; gap: 10px; margin-bottom: 6px; flex-wrap: wrap; }
        .offer-badge { display: inline-block; padding: 4px 10px; border-radius: 999px; font-size: 11px; font-weight: 700; color: #fff; letter-spacing: 0.3px; text-transform: uppercase; }
        .offer-title { font-size: 14px; font-weight: 600; <?php echo $isDarkPage ? 'color:#eee;' : 'color:#1a1a2e;'; ?> }
        .offer-desc { font-size: 13px; line-height: 1.5; <?php echo $isDarkPage ? 'color:#bbb;' : 'color:#555;'; ?> margin-bottom: 8px; }
        .offer-foot { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; }
        .offer-valid { font-size: 11px; color: #888; }
        .offer-redeem-btn { display: inline-block; padding: 8px 16px; border-radius: 8px; color: #fff; font-size: 12px; font-weight: 600; text-decoration: none; margin-left: auto; }
        .offer-redeem-btn:hover { opacity: 0.9; }
        .products-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; }
        @media (min-width: 640px) { .products-grid { grid-template-columns: repeat(3, 1fr); } }
        .product-card { <?php echo $isDarkPage ? 'background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.08);' : 'background: #fafafa; border: 1px solid #ececec;'; ?> border-radius: 12px; overflow: hidden; }
        .product-card[open] { <?php echo $isDarkPage ? 'background: rgba(255,255,255,0.06);' : 'background: #fff;'; ?> }
        .product-summary { list-style: none; cursor: pointer; padding: 0; display: block; }
        .product-summary::-webkit-details-marker { display: none; }
        .product-img { display: block; width: 100%; aspect-ratio: 1 / 1; object-fit: cover; }
        .product-img-ph { display: flex; align-items: center; justify-content: center; background: rgba(127,127,127,0.12); color: #888; font-size: 26px; }
        .product-meta { padding: 8px 10px; }
        .product-title { font-size: 13px; font-weight: 600; line-height: 1.3; <?php echo $isDarkPage ? 'color:#eee;' : 'color:#1a1a2e;'; ?> }
        .product-price { font-size: 12px; font-weight: 700; margin-top: 3px; color: <?php echo htmlspecialchars($accentColor); ?>; }
        .product-desc { padding: 0 10px 10px; font-size: 12px; line-height: 1.5; <?php echo $isDarkPage ? 'color:#bbb;' : 'color:#555;'; ?> }
        .product-order-btn { display: flex; align-items: center; justify-content: center; gap: 6px; margin: 0 10px 10px; padding: 8px 10px; border-radius: 8px; background: #25d366; color: #fff; font-size: 12px; font-weight: 600; text-decoration: none; }
        .product-order-btn:hover { opacity: 0.92; }
        .testimonial-item { padding: 12px 0; <?php echo $isDarkPage ? 'border-bottom: 1px solid rgba(255,255,255,0.06);' : 'border-bottom: 1px solid #f0f0f0;'; ?> }
        .testimonial-item:last-child { border-bottom: none; }
        .testimonial-head { display: flex; align-items: center; gap: 10px; margin-bottom: 6px; }
        .testimonial-head img, .testimonial-head .ph-placeholder { width: 36px; height: 36px; border-radius: 50%; object-fit: cover; background: rgba(127,127,127,0.15); display: flex; align-items: center; justify-content: center; color: #888; font-size: 14px; }
        .testimonial-name { font-size: 13px; font-weight: 600; <?php echo $isDarkPage ? 'color:#eee;' : 'color:#1a1a2e;'; ?> }
        .testimonial-quote { font-size: 13px; font-style: italic; line-height: 1.55; <?php echo $isDarkPage ? 'color:#bbb;' : 'color:#555;'; ?> }
        .testimonial-stars { font-size: 14px; line-height: 1; margin: 4px 0 6px; letter-spacing: 1px; }
        .testimonial-toggle { display: inline-block; margin-top: 8px; padding: 8px 14px; font-size: 13px; border-radius: 8px; border: 1px solid <?php echo $isDarkPage ? 'rgba(255,255,255,0.2)' : 'rgba(0,0,0,0.12)'; ?>; background: transparent; color: inherit; cursor: pointer; }
        .testimonial-toggle:hover { background: <?php echo $isDarkPage ? 'rgba(255,255,255,0.06)' : 'rgba(0,0,0,0.04)'; ?>; }
        .star-picker { display: inline-flex; gap: 4px; font-size: 22px; line-height: 1; user-select: none; }
        .star-picker .star { color: rgba(127,127,127,0.35); cursor: pointer; transition: color 0.1s; }
        .star-picker .star.active { color: #f5b400; }
        .lead-form label { display: block; font-size: 12px; font-weight: 600; margin-bottom: 4px; <?php echo $isDarkPage ? 'color:#bbb;' : 'color:#555;'; ?> }
        .lead-form input, .lead-form textarea { width: 100%; padding: 10px 12px; border-radius: 8px; font-size: 14px; margin-bottom: 10px; font-family: inherit; box-sizing: border-box;
            <?php if ($isDarkPage): ?>background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.1); color: #eee;<?php else: ?>background: #f7f7f9; border: 1px solid #e5e7eb; color: #1a1a2e;<?php endif; ?>
        }
        .lead-form input:focus, .lead-form textarea:focus { outline: none; border-color: <?php echo htmlspecialchars($accentColor); ?>; }
        .lead-form textarea { resize: vertical; min-height: 80px; }
        .lead-form button { width: 100%; padding: 12px; border-radius: 10px; border: none; background: <?php echo htmlspecialchars($accentColor); ?>; color: white; font-size: 14px; font-weight: 600; cursor: pointer; }
        .lead-form button:disabled { opacity: 0.6; cursor: not-allowed; }
        .lead-form .hp { position: absolute; left: -9999px; }
        .lead-success { text-align: center; padding: 20px; font-size: 14px; color: <?php echo htmlspecialchars($accentColor); ?>; }
        .lead-error { color: #ef4444; font-size: 13px; margin-bottom: 8px; }
        /* Appointment widget, inherits light/dark theme */
        .appt-label { display:block; font-size:12px; font-weight:600; margin-bottom:6px; <?php echo $isDarkPage ? 'color:#bbb;' : 'color:#555;'; ?> }
        .appt-input, .appt-textarea { width:100%; padding:10px 12px; border-radius:8px; font-size:14px; font-family:inherit; box-sizing:border-box; margin-bottom:8px;
            <?php if ($isDarkPage): ?>background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.1); color: #eee;<?php else: ?>background: #f7f7f9; border: 1px solid #e5e7eb; color: #1a1a2e;<?php endif; ?>
        }
        .appt-input:focus, .appt-textarea:focus { outline:none; border-color: <?php echo htmlspecialchars($accentColor); ?>; }
        .appt-textarea { resize:vertical; min-height:72px; }
        .appt-slots { margin-top:14px; display:grid; grid-template-columns:repeat(3,1fr); gap:8px; }
        .appt-slot { padding:8px 6px; border-radius:8px; font-size:13px; font-weight:600; cursor:pointer; text-align:center; transition:all 0.15s; border:1px solid <?php echo $isDarkPage ? 'rgba(255,255,255,0.12)' : '#e5e7eb'; ?>; background: <?php echo $isDarkPage ? 'rgba(255,255,255,0.04)' : '#fafafa'; ?>; color:inherit; }
        .appt-slot:hover { border-color: <?php echo htmlspecialchars($accentColor); ?>; background: <?php echo $isDarkPage ? 'rgba(255,255,255,0.08)' : '#fff'; ?>; }
        .appt-empty { display:none; text-align:center; font-size:13px; padding:14px 0; <?php echo $isDarkPage ? 'color:#aaa;' : 'color:#888;'; ?> }
        .appt-chosen { padding:10px; border-radius:8px; font-size:13px; margin-bottom:10px;
            <?php if ($isDarkPage): ?>background:rgba(255,255,255,0.08);<?php else: ?>background:#f0f9ff; color:#0c4a6e;<?php endif; ?>
        }
        .appt-back-btn { flex:0 0 auto; padding:10px 14px; border-radius:8px; background:transparent; color:inherit; cursor:pointer;
            border:1px solid <?php echo $isDarkPage ? 'rgba(255,255,255,0.15)' : '#e5e7eb'; ?>;
        }
        .appt-submit-btn { flex:1; padding:12px 14px; border-radius:8px; border:none; background: <?php echo htmlspecialchars($accentColor); ?>; color:#fff; font-weight:600; cursor:pointer; }
        .appt-success-msg { font-size:13px; margin-top:4px; <?php echo $isDarkPage ? 'color:#aaa;' : 'color:#666;'; ?> }
    </style>
    <style>
        /* Corner controls: theme toggle + language switcher live in a shared
           flex row so they never overlap regardless of locale width. */
        .card-top-controls {
            position: absolute;
            top: 12px;
            <?php echo $isRtl ? 'left' : 'right'; ?>: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
            z-index: 50;
        }
        .lang-switcher {
            display: flex;
            gap: 4px;
            font-size: 12px;
            font-weight: 600;
            background: rgba(0,0,0,0.06);
            padding: 4px 6px;
            border-radius: 999px;
            backdrop-filter: blur(8px);
        }
        .lang-switcher a {
            text-decoration: none;
            padding: 2px 8px;
            border-radius: 999px;
            color: <?php echo $isDarkPage ? '#bbb' : '#555'; ?>;
            transition: background 0.15s var(--ease-out), color 0.15s var(--ease-out);
        }
        .lang-switcher a.active {
            background: <?php echo $isDarkPage ? '#fff' : '#1a1a2e'; ?>;
            color: <?php echo $isDarkPage ? '#1a1a2e' : '#fff'; ?>;
        }

        /* Visitor-facing theme toggle (sun/moon), sits alongside the language switcher. */
        .theme-toggle {
            position: static;
            width: 32px;
            height: 32px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: none;
            cursor: pointer;
            background: rgba(0,0,0,0.06);
            color: <?php echo $isDarkPage ? '#e8e8e8' : '#1a1a2e'; ?>;
            border-radius: 999px;
            backdrop-filter: blur(8px);
            z-index: 50;
            padding: 0;
            transition: background 0.15s, color 0.15s, transform 0.15s;
            -webkit-tap-highlight-color: transparent;
        }
        .theme-toggle:hover { background: rgba(0,0,0,0.12); }
        .theme-toggle:active { transform: scale(0.92); }
        .theme-toggle .theme-icon { display: none; }
        /* Show SUN when we're currently dark (click to go light); MOON when currently light. */
        body.force-dark .theme-toggle .theme-icon-sun { display: block; }
        body.force-light .theme-toggle .theme-icon-moon { display: block; }

        /* Viral "Made with Cardify" footer, appears on every public card so
           each scan becomes a Cardify impression. Tasteful, small, always there
           (think "Designed in Figma"). Pro-tier users can hide via admin. */
        .cardify-viral-footer {
            display: flex;
            justify-content: center;
            padding: 9px 0 8px;
            margin-top: 3px;
        }
        .cardify-viral-footer .viral-link {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            min-height: 40px;
            padding: 9px 16px;
            font-size: 12.5px;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            letter-spacing: 0.2px;
            text-decoration: none;
            color: <?php echo $isDarkPage ? 'rgba(255,255,255,0.55)' : 'rgba(26,26,46,0.55)'; ?>;
            background: <?php echo $isDarkPage ? 'rgba(255,255,255,0.04)' : 'rgba(0,0,0,0.03)'; ?>;
            border: 1px solid <?php echo $isDarkPage ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.06)'; ?>;
            border-radius: 999px;
            transition: color 0.18s ease, background 0.18s ease, border-color 0.18s ease;
            -webkit-tap-highlight-color: transparent;
        }
        .cardify-viral-footer .viral-link strong {
            font-weight: 600;
            color: <?php echo $isDarkPage ? 'rgba(255,255,255,0.82)' : 'rgba(26,26,46,0.82)'; ?>;
            transition: color 0.18s ease;
        }
        .cardify-viral-footer .viral-logo {
            display: inline-flex;
            align-items: center;
            color: #009bc1; /* Cardify / BHD brand blue */
            opacity: 0.78;
            transition: opacity 0.18s ease;
        }
        .cardify-viral-footer .viral-link:hover {
            color: <?php echo $isDarkPage ? 'rgba(255,255,255,0.88)' : 'rgba(26,26,46,0.88)'; ?>;
            border-color: rgba(0,155,193,0.35);
            background: <?php echo $isDarkPage ? 'rgba(0,155,193,0.08)' : 'rgba(0,155,193,0.06)'; ?>;
        }
        .cardify-viral-footer .viral-link:hover strong { color: #009bc1; }
        .cardify-viral-footer .viral-link:hover .viral-logo { opacity: 1; }
        .cardify-viral-footer .viral-link:focus-visible {
            outline: 2px solid #009bc1;
            outline-offset: 2px;
        }
        @media (max-width: 420px) {
            .cardify-viral-footer { padding: 6px 12px 4px; }
            .cardify-viral-footer .viral-link { font-size: 12px; width: 100%; justify-content: center; min-height: 40px; }
        }

        /* Noto Sans Arabic has much taller line-leading than the Latin stack, so
           the same markup overflowed the iPhone viewport in Arabic (+~98px) even
           though English fit. Tighten the line-height on text-bearing controls
           (harmless for Latin) and shave a few RTL-only gaps so the whole card,
           including the Apple Wallet row on iOS, fits ~390x700 without scrolling. */
        .employee-name { line-height: 1.12; }
        .employee-title { line-height: 1.3; }
        .action-btn, .bottom-btn, .wallet-buttons .wallet-btn { line-height: 1.2; }
        [dir="rtl"] .employee-info { margin: 4px auto 6px; }
        [dir="rtl"] .action-buttons { margin-bottom: 4px; }
        [dir="rtl"] .bottom-buttons { margin-top: 6px; }
        [dir="rtl"] .wallet-buttons { margin-top: 6px; }
        [dir="rtl"] .social-links { margin-top: 8px; }

        /* ---- Demo/instant card: banner, controls offset, styled card design ---- */
        .cf-demo-banner {
            background: #0c1418; color: #fff; text-align: center;
            font: 600 12.5px/1.4 -apple-system, 'IBM Plex Sans Arabic', sans-serif;
            padding: 9px 14px; display: flex; gap: 8px; align-items: center;
            justify-content: center; flex-wrap: wrap; position: relative; z-index: 60;
        }
        .cf-demo-banner a { color: #26b4d3; text-decoration: none; font-weight: 700; }
        .is-demo .card-top-controls { top: 56px; }   /* drop controls below the banner */
        /* Top-align demo cards (the body centres compact cards, which left a big
           gap above the demo card). Clear the absolute controls with padding. */
        .is-demo { justify-content: flex-start; }
        .is-demo .page-container { margin-top: 0; padding-top: 50px; }
        .demo-card-design {
            max-width: 360px; margin: 0 auto 8px; border-radius: 22px; padding: 26px 24px;
            color: #fff; position: relative; aspect-ratio: 1.66 / 1;
            display: flex; flex-direction: column; justify-content: center;
            background: linear-gradient(150deg, var(--dc), color-mix(in srgb, var(--dc) 42%, #04141b));
            box-shadow: 0 30px 64px -28px rgba(0,0,0,.5);
        }
        .dcd-live { position: absolute; top: 18px; inset-inline-end: 20px; font-size: 11px; font-weight: 700; letter-spacing: .1em; background: rgba(255,255,255,.16); padding: 5px 10px; border-radius: 999px; }
        .dcd-co { font-size: 12px; font-weight: 700; letter-spacing: .16em; opacity: .85; text-transform: uppercase; }
        .dcd-name { font-size: 24px; font-weight: 800; line-height: 1.12; margin-top: 12px; }
        .dcd-title { opacity: .85; margin-top: 4px; font-size: 15px; }
        .dcd-contact { margin-top: 16px; display: flex; flex-direction: column; gap: 5px; font-size: 13.5px; opacity: .92; }
        .dcd-contact i { width: 15px; opacity: .8; font-size: 12px; }
    </style>
</head>
<body class="<?php echo $isDarkPage ? 'force-dark' : 'force-light'; echo !empty($demoMeta) ? ' is-demo' : ''; ?>">
    <?php if (!empty($isDemoUnverified)): $__demoAr = (($locale ?? 'en') === 'ar'); ?>
    <div class="cf-demo-banner" dir="<?= $__demoAr ? 'rtl' : 'ltr' ?>">
        <span><?= $__demoAr ? 'بطاقة تجريبية — فعّل بريدك للاحتفاظ بها' : 'Demo card — verify your email to keep it' ?></span>
        <a href="https://cardify.om">cardify.om →</a>
    </div>
    <?php endif; ?>
    <div class="card-top-controls">
        <?php if ($themeToggleEnabled): ?>
        <!-- Theme toggle (visitor override, persisted via cookie, 7d) -->
        <button type="button"
                class="theme-toggle"
                id="themeToggle"
                aria-label="<?php echo $isDarkPage ? 'Switch to light mode' : 'Switch to dark mode'; ?>"
                title="<?php echo htmlspecialchars($isDarkPage ? t('digitalcard.switch_light') : t('digitalcard.switch_dark')); ?>"
                data-mode="<?php echo $defaultThemeMode; ?>">
            <svg class="theme-icon theme-icon-sun" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.93 4.93 1.41 1.41"/><path d="m17.66 17.66 1.41 1.41"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="m6.34 17.66-1.41 1.41"/><path d="m19.07 4.93-1.41 1.41"/></svg>
            <svg class="theme-icon theme-icon-moon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/></svg>
        </button>
        <?php endif; ?>
        <?php if ($ecardBilingual || $switchThirdUrl !== ''): ?>
        <nav class="lang-switcher" aria-label="Language">
            <a href="<?php echo $switchEnUrl; ?>" class="<?php echo (!$isThird && $locale === 'en') ? 'active' : ''; ?>" hreflang="en">EN</a>
            <?php if ($ecardBilingual): ?>
            <a href="<?php echo $switchArUrl; ?>" class="<?php echo (!$isThird && $locale === 'ar') ? 'active' : ''; ?>" hreflang="ar">عربي</a>
            <?php endif; ?>
            <?php if ($switchThirdUrl !== ''): ?>
            <a href="<?php echo $switchThirdUrl; ?>" class="<?php echo $isThird ? 'active' : ''; ?>" hreflang="<?php echo htmlspecialchars($thirdCode, ENT_QUOTES); ?>"><?php echo htmlspecialchars($thirdLabel); ?></a>
            <?php endif; ?>
        </nav>
        <?php endif; ?>
    </div>
    <div class="page-container">
        <!-- Company Logo -->
        <?php if ($logoPath): ?>
        <div class="company-logo">
            <img src="<?php echo htmlspecialchars($logoPath); ?>" alt="<?php echo htmlspecialchars($companyName); ?>">
        </div>
        <?php endif; ?>

        <?php if ($leadWithPhoto): ?>
        <!-- Profile photo hero (vCard layout) -->
        <div class="avatar-hero">
            <?php if ($photoUrl !== ''): ?>
            <img class="avatar-photo" src="<?php echo htmlspecialchars($photoUrl); ?>" alt="<?php echo htmlspecialchars($name); ?>" loading="eager">
            <?php else: ?>
            <div class="avatar-photo avatar-initials" style="background: <?php echo htmlspecialchars($accentColor, ENT_QUOTES); ?>;"><?php echo $initials !== '' ? htmlspecialchars($initials) : '<i class="fa-solid fa-user"></i>'; ?></div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Flippable Card -->
        <?php if ($frontImage): ?>
        <?php if ($leadWithPhoto): ?>
        <details class="view-card-toggle">
            <summary class="view-card-summary"><i class="fa-solid fa-id-card" aria-hidden="true"></i> <?= htmlspecialchars(t('digitalcard.view_card')) ?></summary>
        <?php endif; ?>
        <div class="card-flip-container<?php echo $backImage ? '' : ' no-back'; ?>" id="cardFlip">
            <div class="card-flip-inner" id="cardInner" style="--card-aspect: <?php echo htmlspecialchars($cardAspectCss, ENT_QUOTES); ?>;">
                <div class="card-face">
                    <img src="<?php echo htmlspecialchars($frontImage); ?>" alt="<?= htmlspecialchars(t('digitalcard.alt_card_front')) ?>" loading="lazy">
                </div>
                <?php if ($backImage): ?>
                <div class="card-face card-back-face">
                    <img src="<?php echo htmlspecialchars($backImage); ?>" alt="<?= htmlspecialchars(t('digitalcard.alt_card_back')) ?>" loading="lazy">
                </div>
                <?php endif; ?>
            </div>
            <?php if ($backImage): ?>
            <div class="tap-hint" id="tapHint"><?= htmlspecialchars(t('digitalcard.tap_to_flip')) ?></div>
            <?php endif; ?>
        </div>
        <?php if ($leadWithPhoto): ?>
        </details>
        <?php endif; ?>
        <?php elseif (!empty($demoMeta) && !$leadWithPhoto):
            // Demo/instant cards have no Fabric-generated card image; render a styled
            // card design at the top so the page never shows an empty gap.
            $__dcColor = (!empty($demoMeta['brand_color']) && preg_match('/^#[0-9a-fA-F]{6}$/', (string)$demoMeta['brand_color'])) ? $demoMeta['brand_color'] : ($accentColor ?: '#009bc1');
            $__dcCompany = trim((string)($employee['company_en'] ?? $companyName));
            $__dcPhone = trim((string)($employee['mobile'] ?? $employee['phone'] ?? ''));
            $__dcEmail = trim((string)($employee['email'] ?? ''));
        ?>
        <div class="demo-card-design" style="--dc: <?= htmlspecialchars($__dcColor, ENT_QUOTES) ?>;">
            <span class="dcd-live">LIVE</span>
            <?php if ($__dcCompany !== ''): ?><div class="dcd-co"><?= htmlspecialchars($__dcCompany) ?></div><?php endif; ?>
            <div class="dcd-name"><?= htmlspecialchars($name) ?></div>
            <?php if ($position !== ''): ?><div class="dcd-title"><?= htmlspecialchars($position) ?></div><?php endif; ?>
            <div class="dcd-contact">
                <?php if ($__dcPhone !== ''): ?><div dir="ltr"><i class="fa-solid fa-phone"></i> <?= htmlspecialchars($__dcPhone) ?></div><?php endif; ?>
                <?php if ($__dcEmail !== ''): ?><div dir="ltr"><i class="fa-solid fa-envelope"></i> <?= htmlspecialchars($__dcEmail) ?></div><?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Employee Info -->
        <div class="employee-info">
            <div class="employee-name" role="heading" aria-level="1"><?php echo htmlspecialchars($name); ?></div>
            <?php
            // Demo/instant cards: render the person's typed company (not the shared
            // `demo` tenant name). Applied here at display so it can't be clobbered
            // by an earlier derivation of $companyName.
            if (!empty($demoMeta) && !empty($employee['company_en'])) { $companyName = $employee['company_en']; }
            ?>
            <?php if ($position || $companyName): ?>
            <div class="employee-title">
                <?php
                $parts = [];
                if ($position) $parts[] = htmlspecialchars($position);
                if ($companyName) $parts[] = htmlspecialchars($companyName);
                echo implode(' &middot; ', $parts);
                ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Action Buttons -->
        <div class="action-buttons">
            <?php if ($mobile || $phone): ?>
            <a href="<?php echo htmlspecialchars($cardClickUrl($mobile ? 'click_mobile' : 'click_phone', 'tel:' . ($mobile ?: $phone))); ?>" class="action-btn btn-call"><?= htmlspecialchars(t('digitalcard.btn_call')) ?></a>
            <?php endif; ?>

            <?php if ($waPhone): ?>
            <a href="<?php echo htmlspecialchars($cardClickUrl('click_whatsapp', 'https://api.whatsapp.com/send?phone=' . $waPhone)); ?>" class="action-btn btn-whatsapp" target="_blank" rel="noopener"><?= htmlspecialchars(t('digitalcard.btn_whatsapp')) ?></a>
            <?php endif; ?>

            <?php if ($email): ?>
            <a href="<?php echo htmlspecialchars($cardClickUrl('click_email', 'mailto:' . $email)); ?>" class="action-btn btn-email"><?= htmlspecialchars(t('digitalcard.btn_email')) ?></a>
            <?php endif; ?>
        </div>

        <!-- Contact Details -->
        <div class="contact-card">
            <?php
            // Phone / email / website values render LTR even when the page is
            // RTL: digits and "+" are bidi-weak (no strong character), so an
            // RTL paragraph reorders "+968 9946 9942" into "9942 9946 968+".
            // Explicit dir="ltr" on the value span forces left-to-right reading
            // regardless of paragraph direction. Address span stays without
            // dir so Arabic addresses render correctly.
            ?>
            <?php if ($phone): ?>
            <a href="<?php echo htmlspecialchars($cardClickUrl('click_phone', 'tel:' . $phone)); ?>" class="contact-row">
                <span class="contact-icon"><i class="fa-solid fa-phone" aria-hidden="true"></i></span>
                <span class="contact-value" dir="ltr"><?php echo htmlspecialchars($phone); ?></span>
            </a>
            <?php endif; ?>

            <?php if ($mobile && $mobile !== $phone): ?>
            <a href="<?php echo htmlspecialchars($cardClickUrl('click_mobile', 'tel:' . $mobile)); ?>" class="contact-row">
                <span class="contact-icon"><i class="fa-solid fa-mobile-screen-button" aria-hidden="true"></i></span>
                <span class="contact-value" dir="ltr"><?php echo htmlspecialchars($mobile); ?></span>
            </a>
            <?php endif; ?>

            <?php if ($fax): ?>
            <a href="<?php echo htmlspecialchars($cardClickUrl('click_fax', 'tel:' . preg_replace('/[^0-9+]/', '', $fax))); ?>" class="contact-row">
                <span class="contact-icon"><i class="fa-solid fa-fax" aria-hidden="true"></i></span>
                <span class="contact-value" dir="ltr"><?php echo htmlspecialchars($fax); ?></span>
            </a>
            <?php endif; ?>

            <?php if ($email): ?>
            <a href="<?php echo htmlspecialchars($cardClickUrl('click_email', 'mailto:' . $email)); ?>" class="contact-row">
                <span class="contact-icon"><i class="fa-solid fa-envelope" aria-hidden="true"></i></span>
                <span class="contact-value" dir="ltr"><?php echo htmlspecialchars($email); ?></span>
            </a>
            <?php endif; ?>

            <?php if ($website): ?>
            <?php $__webDest = strpos($website, 'http') === 0 ? $website : 'https://' . $website; ?>
            <a href="<?php echo htmlspecialchars($cardClickUrl('click_website', $__webDest)); ?>" class="contact-row" target="_blank" rel="noopener">
                <span class="contact-icon"><i class="fa-solid fa-globe" aria-hidden="true"></i></span>
                <span class="contact-value" dir="ltr"><?php echo htmlspecialchars($website); ?></span>
            </a>
            <?php endif; ?>

            <?php if ($address): ?>
            <a href="<?php echo htmlspecialchars($cardClickUrl('click_map', 'https://maps.google.com/?q=' . urlencode($address))); ?>" class="contact-row" target="_blank" rel="noopener">
                <span class="contact-icon"><i class="fa-solid fa-location-dot" aria-hidden="true"></i></span>
                <span class="contact-value wrap"><?php echo htmlspecialchars($address); ?></span>
            </a>
            <?php endif; ?>
        </div>

        <!-- Social Links -->
        <?php if (!empty($socialLinks)): ?>
        <div class="social-links" aria-label="Social profiles">
            <?php foreach ($socialLinks as $sl): ?>
            <?php $__href = EmployeeSocials::hrefFor($sl['platform'], $sl['url']); ?>
            <a href="<?php echo htmlspecialchars($cardClickUrl('click_social', $__href)); ?>"
               class="social-link"
               data-platform="<?php echo htmlspecialchars($sl['platform']); ?>"
               target="_blank"
               rel="noopener"
               title="<?php echo htmlspecialchars($sl['label']); ?>"
               aria-label="<?php echo htmlspecialchars($sl['label']); ?>">
                <i class="<?php echo htmlspecialchars($sl['icon']); ?>"></i>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Save & Share -->
        <?php $pdfUrl = '/card-pdf.php?i=' . urlencode($employee['id']); ?>
        <div class="bottom-buttons">
            <?php if ($email): ?>
            <a href="<?php echo htmlspecialchars($cardClickUrl('save_contact', $vcfUrl)); ?>" class="bottom-btn btn-save" download><?= htmlspecialchars(t('digitalcard.btn_save_contact')) ?></a>
            <?php endif; ?>
            <?php if (!$isPendingPreview && $frontImage): // PDF is the printed-card design; hide when there is no card (e.g. photo-led vCard) ?>
            <a href="<?php echo htmlspecialchars($cardClickUrl('download_pdf', $pdfUrl)); ?>" class="bottom-btn btn-pdf" download><?= htmlspecialchars(t('digitalcard.btn_download_pdf')) ?></a>
            <?php endif; ?>
            <button class="bottom-btn btn-share" onclick="shareCard()"><?= htmlspecialchars(t('digitalcard.btn_share')) ?></button>
        </div>

        <?php
        // ================================================================
        // EARLY FLUSH BOUNDARY
        // ================================================================
        // The hero (name, position, contact buttons, contact rows, social
        // links, Save/Download/Share) is now on the wire. Push it to the
        // browser before we touch the DB for any below-the-fold section.
        // X-Accel-Buffering: no (sent at the top of this file) tells nginx
        // not to buffer; Cloudflare forwards streaming responses unchanged.
        if (function_exists('ob_flush')) { @ob_flush(); }
        @flush();

        // ================================================================
        // DEFERRED-LOAD: section data, appointments, wallet
        // ================================================================
        // These queries used to run before the first byte of HTML left the
        // server. They now run AFTER the hero is painted, so a phone scanning
        // a printed card sees the contact info instantly while the section
        // queries (which only matter for the part the user has to scroll to)
        // hit the DB in the background.
        try {
            $sectionMaster   = CardSections::loadMaster($employee['id'], $company['id']);
            $bioText         = ($locale === 'ar' && trim((string)($sectionMaster['bio_text_ar'] ?? '')) !== '')
                ? $sectionMaster['bio_text_ar']
                : ($sectionMaster['bio_text'] ?? '');
            $sectionServices = !empty($sectionMaster['services_enabled']) ? CardSections::loadServicesLocalized($employee['id'], $locale) : [];
            $sectionGallery  = !empty($sectionMaster['gallery_enabled'])  ? CardSections::loadGallery($employee['id']) : [];
            // Approved-only testimonials, then overlay AR translations.
            if (!empty($sectionMaster['testimonials_enabled'])) {
                $sectionTestimonials = CardSections::loadApprovedTestimonials($employee['id']);
                if ($locale === 'ar' && !empty($sectionTestimonials)) {
                    $ids = array_column($sectionTestimonials, 'id');
                    $placeholders = implode(',', array_fill(0, count($ids), '?'));
                    $stmt = Database::getInstance()->getConnection()->prepare(
                        "SELECT testimonial_id, name, quote FROM employee_card_testimonials_i18n
                         WHERE locale = ? AND testimonial_id IN ($placeholders)"
                    );
                    $stmt->execute(array_merge(['ar'], $ids));
                    $byId = [];
                    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) { $byId[$r['testimonial_id']] = $r; }
                    foreach ($sectionTestimonials as &$r) {
                        if (isset($byId[$r['id']])) {
                            if (trim((string)$byId[$r['id']]['name'])  !== '') $r['name']  = $byId[$r['id']]['name'];
                            if (trim((string)$byId[$r['id']]['quote']) !== '') $r['quote'] = $byId[$r['id']]['quote'];
                        }
                    }
                    unset($r);
                }
            } else {
                $sectionTestimonials = [];
            }
            $sectionOffers   = !empty($sectionMaster['offers_enabled'])   ? CardSections::loadOffers($employee['id'], true) : [];
            $sectionProducts = !empty($sectionMaster['products_enabled']) ? CardSections::loadProducts($employee['id'], true) : [];
            $businessHours   = !empty($sectionMaster['hours_enabled'])    ? CardSections::loadBusinessHours($employee['id']) : [];
            $businessTz      = $sectionMaster['hours_timezone'] ?? 'Asia/Muscat';
            $hoursStatus     = !empty($businessHours) ? CardSections::computeOpenStatus($businessHours, $businessTz) : null;
            $sectionFaqs     = !empty($sectionMaster['faq_enabled'])      ? CardSections::loadFaqsLocalized($employee['id'], $locale) : [];
            $sectionOrder    = array_values(array_filter(array_map('trim', explode(',', $sectionMaster['section_order'] ?? implode(',', CardSections::SECTION_KEYS)))));
            foreach (['offers', 'location', 'products', 'hours', 'faq'] as $__defaultSec) {
                if (!in_array($__defaultSec, $sectionOrder, true)) { $sectionOrder[] = $__defaultSec; }
            }

            // Appointment booking settings (rendered as its own section after card sections)
            $apptSettings = Appointments::loadSettings($employee['id'], $company['id']);
            $apptEnabled  = !empty($apptSettings['enabled']);

            // Wallet pass endpoints (feature-flagged, buttons render only when enabled)
            require_once INCLUDES_DIR . '/AppleWalletPass.php';
            require_once INCLUDES_DIR . '/GoogleWalletPass.php';
            $appleWalletEnabled  = AppleWalletPass::isEnabled();
            $googleWalletEnabled = GoogleWalletPass::isEnabled();
            $walletLang = (currentLocale() === 'ar') ? 'ar' : 'en'; // pass language follows the site language
            $appleWalletUrl  = '/wallet_apple.php?i='  . urlencode($employee['id']) . '&c=' . urlencode($companySlug) . '&lang=' . $walletLang;
            $googleWalletUrl = '/wallet_google.php?i=' . urlencode($employee['id']) . '&c=' . urlencode($companySlug) . '&lang=' . $walletLang;
        } catch (Throwable $e) {
            // Above-the-fold has already shipped; degrade gracefully by hiding
            // every below-the-fold section instead of 500ing a half-rendered page.
            error_log('digital_card.php deferred-load failed: ' . $e->getMessage());
            $sectionMaster       = [];
            $bioText             = '';
            $sectionServices     = [];
            $sectionGallery      = [];
            $sectionTestimonials = [];
            $sectionOffers       = [];
            $sectionProducts     = [];
            $businessHours       = [];
            $businessTz          = 'Asia/Muscat';
            $hoursStatus         = null;
            $sectionFaqs         = [];
            $sectionOrder        = [];
            $apptSettings        = ['enabled' => false];
            $apptEnabled         = false;
            $appleWalletEnabled  = false;
            $googleWalletEnabled = false;
            $appleWalletUrl      = '';
            $googleWalletUrl     = '';
        }
        ?>

        <!-- Public Card Sections -->
        <?php foreach ($sectionOrder as $__sec): ?>
            <?php if ($__sec === 'bio' && !empty($sectionMaster['bio_enabled']) && !empty($bioText)): ?>
                <div class="card-section">
                    <h3><?= htmlspecialchars(t('digitalcard.section_about')) ?></h3>
                    <div class="section-bio"><?php echo CardSections::renderBioHtml($bioText); ?></div>
                </div>
            <?php elseif ($__sec === 'services' && !empty($sectionMaster['services_enabled']) && !empty($sectionServices)): ?>
                <div class="card-section">
                    <h3><?= htmlspecialchars(t('digitalcard.section_services')) ?></h3>
                    <?php foreach ($sectionServices as $svc): ?>
                        <div class="service-row">
                            <div class="service-icon"><i class="<?php echo htmlspecialchars($svc['icon']); ?>"></i></div>
                            <div class="service-body">
                                <div class="service-title"><?php echo htmlspecialchars($svc['title']); ?></div>
                                <?php if (!empty($svc['description'])): ?>
                                <div class="service-desc"><?php echo nl2br(htmlspecialchars($svc['description'])); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php elseif ($__sec === 'gallery' && !empty($sectionMaster['gallery_enabled']) && !empty($sectionGallery)): ?>
                <div class="card-section">
                    <h3><?= htmlspecialchars(t('digitalcard.section_gallery')) ?></h3>
                    <div class="gallery-grid">
                        <?php foreach ($sectionGallery as $img): ?>
                            <img src="<?php echo htmlspecialchars(cardifyAssetUrl($img['file_path'])); ?>" alt="<?php echo htmlspecialchars($img['caption'] ?? ''); ?>" loading="lazy" onclick="window.open(this.src,'_blank')">
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php elseif ($__sec === 'testimonials' && !empty($sectionMaster['testimonials_enabled'])): ?>
                <div class="card-section">
                    <h3><?= htmlspecialchars(t('digitalcard.section_testimonials')) ?></h3>
                    <?php if (!empty($sectionTestimonials)): ?>
                    <?php foreach ($sectionTestimonials as $t): ?>
                        <div class="testimonial-item">
                            <div class="testimonial-head">
                                <?php if (!empty($t['photo_path'])): ?>
                                <img src="<?php echo htmlspecialchars(cardifyAssetUrl($t['photo_path'])); ?>" alt="<?php echo htmlspecialchars($t['name']); ?>">
                                <?php else: ?>
                                <span class="ph-placeholder">&#128100;</span>
                                <?php endif; ?>
                                <div class="testimonial-name"><?php echo htmlspecialchars($t['name']); ?></div>
                            </div>
                            <?php if (!empty($t['rating']) && (int)$t['rating'] > 0): ?>
                            <div class="testimonial-stars" aria-label="<?php echo (int)$t['rating']; ?> out of 5">
                                <?php $r=(int)$t['rating']; for($i=1;$i<=5;$i++): ?>
                                    <span style="color:<?php echo $i<=$r?'#f5b400':'rgba(127,127,127,0.3)'; ?>;">&#9733;</span>
                                <?php endfor; ?>
                            </div>
                            <?php endif; ?>
                            <div class="testimonial-quote">&ldquo;<?php echo nl2br(htmlspecialchars($t['quote'])); ?>&rdquo;</div>
                        </div>
                    <?php endforeach; ?>
                    <?php else: ?>
                        <div style="font-size:13px; opacity:0.65; padding:8px 0 12px;"><?= htmlspecialchars(t('digitalcard.test_empty')) ?></div>
                    <?php endif; ?>

                    <button type="button" class="testimonial-toggle" id="testimonialToggle" data-label-open="<?= htmlspecialchars(t('digitalcard.test_leave'), ENT_QUOTES) ?>" data-label-close="<?= htmlspecialchars(t('digitalcard.test_cancel'), ENT_QUOTES) ?>" onclick="(function(b){var f=document.getElementById('testimonialFormWrap');var open=f.style.display==='block';f.style.display=open?'none':'block';b.textContent=open?b.dataset.labelOpen:b.dataset.labelClose;})(this);"><?= htmlspecialchars(t('digitalcard.test_leave')) ?></button>
                    <div id="testimonialFormWrap" style="display:none; margin-top:12px;">
                        <form id="testimonialForm" class="lead-form" enctype="multipart/form-data" autocomplete="off">
                            <input type="hidden" name="employee_id" value="<?php echo htmlspecialchars($employee['id']); ?>">
                            <div class="hp" style="position:absolute;left:-10000px;"><label><?= htmlspecialchars(t('digitalcard.hp_honeypot')) ?><input type="text" name="website_url" tabindex="-1" autocomplete="off"></label></div>
                            <div class="lead-error" id="testimonialError" style="display:none;"></div>
                            <label><?= htmlspecialchars(t('digitalcard.test_field_name')) ?><input type="text" name="name" required maxlength="255"></label>
                            <label><?= htmlspecialchars(t('digitalcard.test_field_email')) ?><input type="email" name="email" maxlength="255"></label>
                            <label>Rating
                                <div class="star-picker" id="starPicker" role="radiogroup" aria-label="Rating">
                                    <input type="hidden" name="rating" id="ratingInput" value="">
                                    <?php for($i=1;$i<=5;$i++): ?>
                                    <span class="star" data-val="<?php echo $i; ?>" role="radio" aria-checked="false" tabindex="0">&#9733;</span>
                                    <?php endfor; ?>
                                </div>
                            </label>
                            <label><?= htmlspecialchars(t('digitalcard.test_field_quote')) ?><textarea name="quote" required maxlength="2000"></textarea></label>
                            <label><?= htmlspecialchars(t('digitalcard.test_field_photo')) ?><input type="file" name="photo" accept="image/jpeg,image/png,image/webp"></label>
                            <button type="submit" id="testimonialSubmit"><?= htmlspecialchars(t('digitalcard.test_submit')) ?></button>
                        </form>
                        <div class="lead-success" id="testimonialSuccess" style="display:none;"><?= htmlspecialchars(t('digitalcard.test_thanks')) ?></div>
                    </div>
                </div>
            <?php elseif ($__sec === 'offers' && !empty($sectionMaster['offers_enabled']) && !empty($sectionOffers)): ?>
                <div class="card-section">
                    <h3><?= htmlspecialchars(t('digitalcard.section_offers')) ?></h3>
                    <div class="offers-list">
                        <?php foreach ($sectionOffers as $offer): ?>
                            <div class="offer-card">
                                <div class="offer-head">
                                    <?php if (!empty($offer['discount_label'])): ?>
                                    <span class="offer-badge" style="background: <?php echo htmlspecialchars($offer['badge_color'] ?: '#009bc1'); ?>;">
                                        <?php echo htmlspecialchars($offer['discount_label']); ?>
                                    </span>
                                    <?php endif; ?>
                                    <div class="offer-title"><?php echo htmlspecialchars($offer['title']); ?></div>
                                </div>
                                <?php if (!empty($offer['description'])): ?>
                                <div class="offer-desc"><?php echo nl2br(htmlspecialchars($offer['description'])); ?></div>
                                <?php endif; ?>
                                <div class="offer-foot">
                                    <?php if (!empty($offer['valid_until'])): ?>
                                    <span class="offer-valid">Valid until <?php echo htmlspecialchars($offer['valid_until']); ?></span>
                                    <?php endif; ?>
                                    <form method="post" action="/api/offer/redeem.php" style="margin:0;display:inline;">
                                        <input type="hidden" name="oid" value="<?php echo htmlspecialchars($offer['id']); ?>">
                                        <input type="hidden" name="eid" value="<?php echo htmlspecialchars($employee['id']); ?>">
                                        <button type="submit"
                                                class="offer-redeem-btn"
                                                style="background: <?php echo htmlspecialchars($offer['badge_color'] ?: '#009bc1'); ?>;border:0;cursor:pointer;">Redeem</button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php elseif ($__sec === 'video' && !empty($sectionMaster['video_enabled'])
                && ($__videoSpec = CardSections::parseVideoEmbed($sectionMaster['video_url'] ?? ''))): ?>
                <div class="card-section">
                    <h3><?php echo !empty($sectionMaster['video_title'])
                        ? htmlspecialchars($sectionMaster['video_title'])
                        : 'Video'; ?></h3>
                    <?php if ($__videoSpec['type'] === 'youtube' || $__videoSpec['type'] === 'vimeo'): ?>
                        <div class="video-frame">
                            <iframe src="<?php echo htmlspecialchars($__videoSpec['embed']); ?>"
                                    title="<?php echo htmlspecialchars($sectionMaster['video_title'] ?: 'Embedded video'); ?>"
                                    loading="lazy"
                                    referrerpolicy="strict-origin-when-cross-origin"
                                    allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
                                    allowfullscreen></iframe>
                        </div>
                    <?php elseif ($__videoSpec['type'] === 'file'): ?>
                        <div class="video-frame">
                            <video controls preload="metadata" playsinline>
                                <source src="<?php echo htmlspecialchars($__videoSpec['embed']); ?>">
                                Your browser does not support embedded video.
                            </video>
                        </div>
                    <?php else: ?>
                        <a href="<?php echo htmlspecialchars($__videoSpec['embed']); ?>" target="_blank" rel="noopener" class="video-link-btn">
                            <i class="fa-solid fa-play"></i> Watch video
                        </a>
                    <?php endif; ?>
                </div>
            <?php elseif ($__sec === 'location' && !empty($sectionMaster['location_enabled']) && !empty(trim((string)($sectionMaster['location_address'] ?? '')))): ?>
                <?php
                    $locAddr = trim((string)$sectionMaster['location_address']);
                    $locLabel = trim((string)($sectionMaster['location_label'] ?? ''));
                    $mapsQ = urlencode($locAddr);
                    $embedUrl = 'https://www.google.com/maps?q=' . $mapsQ . '&output=embed';
                    $directionsUrl = 'https://www.google.com/maps?q=' . $mapsQ;
                    $directionsLabel = ($locale === 'ar') ? 'احصل على الاتجاهات' : 'Get Directions';
                    $locTitle = ($locale === 'ar') ? 'الموقع' : 'Location';
                ?>
                <div class="card-section">
                    <h3><?php echo htmlspecialchars($locTitle); ?></h3>
                    <?php if ($locLabel !== ''): ?>
                    <div style="font-size:13px; color:#555; margin-bottom:8px;<?php echo $isRtl ? 'text-align:right;' : ''; ?>">
                        <i class="fa-solid fa-location-dot" style="color:<?php echo htmlspecialchars($accentColor); ?>;"></i>
                        <?php echo htmlspecialchars($locLabel); ?>
                    </div>
                    <?php endif; ?>
                    <div style="position:relative; width:100%; padding-bottom:56.25%; border-radius:12px; overflow:hidden; border:1px solid rgba(0,0,0,0.08); background:#f3f4f6;">
                        <iframe
                            src="<?php echo htmlspecialchars($embedUrl); ?>"
                            style="position:absolute; top:0; left:0; width:100%; height:100%; border:0;"
                            loading="lazy"
                            referrerpolicy="no-referrer-when-downgrade"
                            allowfullscreen
                            title="<?php echo htmlspecialchars($locTitle); ?>"></iframe>
                    </div>
                    <div style="font-size:13px; color:#6b7280; margin-top:8px;<?php echo $isRtl ? 'text-align:right;' : ''; ?>">
                        <?php echo htmlspecialchars($locAddr); ?>
                    </div>
                    <a href="<?php echo htmlspecialchars($directionsUrl); ?>"
                       target="_blank" rel="noopener"
                       style="display:inline-flex; align-items:center; gap:8px; margin-top:10px; padding:10px 16px; border-radius:10px; background:<?php echo htmlspecialchars($accentColor); ?>; color:#fff; font-weight:600; font-size:14px; text-decoration:none;">
                        <i class="fa-solid fa-diamond-turn-right"></i>
                        <?php echo htmlspecialchars($directionsLabel); ?>
                    </a>
                </div>
            <?php elseif ($__sec === 'products' && !empty($sectionMaster['products_enabled']) && !empty($sectionProducts)): ?>
                <?php
                    $prodTitle = ($locale === 'ar') ? 'المنتجات' : 'Products';
                    $orderLabel = ($locale === 'ar') ? 'اطلب عبر واتساب' : 'Order via WhatsApp';
                    $prodWaPhone = preg_replace('/[^0-9]/', '', $mobile ?: $phone);
                ?>
                <div class="card-section">
                    <h3><?php echo htmlspecialchars($prodTitle); ?></h3>
                    <div class="products-grid">
                        <?php foreach ($sectionProducts as $prod): ?>
                            <?php
                                $waUrl = $prodWaPhone
                                    ? CardSections::buildProductWhatsappUrl($prodWaPhone, $prod, $locale)
                                    : '';
                                $priceLabel = CardSections::formatPrice($prod['price'], $prod['currency'] ?? 'OMR');
                                $prodSlug = 'prod-' . substr(preg_replace('/[^a-zA-Z0-9]/', '', $prod['id']), 0, 16);
                            ?>
                            <details class="product-card" id="<?php echo htmlspecialchars($prodSlug); ?>">
                                <summary class="product-summary">
                                    <?php if (!empty($prod['image_path'])): ?>
                                    <img class="product-img" src="<?php echo htmlspecialchars($prod['image_path']); ?>"
                                         alt="<?php echo htmlspecialchars($prod['title']); ?>" loading="lazy">
                                    <?php else: ?>
                                    <div class="product-img product-img-ph"><i class="fa-solid fa-image"></i></div>
                                    <?php endif; ?>
                                    <div class="product-meta">
                                        <div class="product-title"><?php echo htmlspecialchars($prod['title']); ?></div>
                                        <div class="product-price" dir="ltr"><?php echo htmlspecialchars($priceLabel); ?></div>
                                    </div>
                                </summary>
                                <?php if (!empty($prod['description'])): ?>
                                <div class="product-desc"><?php echo nl2br(htmlspecialchars($prod['description'])); ?></div>
                                <?php endif; ?>
                                <?php if ($waUrl !== ''): ?>
                                <a class="product-order-btn"
                                   href="<?php echo htmlspecialchars($cardClickUrl('product_order_click', $waUrl)); ?>"
                                   target="_blank" rel="noopener"
                                   data-product-id="<?php echo htmlspecialchars($prod['id']); ?>">
                                    <i class="fa-brands fa-whatsapp"></i>
                                    <?php echo htmlspecialchars($orderLabel); ?>
                                </a>
                                <?php endif; ?>
                            </details>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php elseif ($__sec === 'hours' && !empty($sectionMaster['hours_enabled']) && !empty($businessHours)): ?>
                <?php
                    $__hoursTitle = ($locale === 'ar') ? 'ساعات العمل' : 'Business Hours';
                    $__openLabel = ($locale === 'ar') ? 'مفتوح الآن' : 'Open now';
                    $__closedLabel = ($locale === 'ar') ? 'مغلق' : 'Closed';
                    $__breakLabel = ($locale === 'ar') ? 'في استراحة' : 'On break';
                    $__todayLabel = ($locale === 'ar') ? 'اليوم' : 'Today';
                    $__tzLabel = ($locale === 'ar') ? 'المنطقة الزمنية' : 'Timezone';
                    $__closesAt = ($locale === 'ar') ? 'يغلق في' : 'Closes at';
                    $__opensAt = ($locale === 'ar') ? 'يفتح في' : 'Opens at';
                    $__viewWeek = ($locale === 'ar') ? 'عرض الجدول الأسبوعي' : 'View weekly schedule';
                    $__hideWeek = ($locale === 'ar') ? 'إخفاء الجدول' : 'Hide schedule';

                    $__statusColor = !empty($hoursStatus['is_open']) ? '#16a34a' : (!empty($hoursStatus['on_break']) ? '#f59e0b' : '#ef4444');
                    $__statusText = $__closedLabel;
                    $__statusDetail = '';
                    if (!empty($hoursStatus)) {
                        if (!empty($hoursStatus['is_open'])) {
                            $__statusText = $__openLabel;
                            if (!empty($hoursStatus['closes_at'])) {
                                $__statusDetail = $__closesAt . ' ' . htmlspecialchars($hoursStatus['closes_at']);
                            }
                        } elseif (!empty($hoursStatus['on_break'])) {
                            $__statusText = $__breakLabel;
                            if (!empty($hoursStatus['opens_at'])) {
                                $__statusDetail = $__opensAt . ' ' . htmlspecialchars($hoursStatus['opens_at']);
                            }
                        } else {
                            $__statusText = $__closedLabel;
                            if (!empty($hoursStatus['opens_at'])) {
                                $__dayName = CardSections::dayName($hoursStatus['opens_day'] ?? '', $locale);
                                if (!empty($hoursStatus['same_day'])) {
                                    $__statusDetail = $__opensAt . ' ' . htmlspecialchars($hoursStatus['opens_at']);
                                } else {
                                    $__statusDetail = $__opensAt . ' ' . htmlspecialchars($hoursStatus['opens_at']) . ' · ' . htmlspecialchars($__dayName);
                                }
                            }
                        }
                    }
                    $__todayKey = $hoursStatus['today_key'] ?? 'mon';
                ?>
                <div class="card-section" data-hours-section>
                    <h3><?php echo htmlspecialchars($__hoursTitle); ?></h3>
                    <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;<?php echo $isRtl ? 'flex-direction:row-reverse;' : ''; ?>">
                        <span style="display:inline-flex; align-items:center; gap:6px; padding:6px 12px; border-radius:999px; background:<?php echo $__statusColor; ?>22; color:<?php echo $__statusColor; ?>; font-size:13px; font-weight:600;">
                            <span style="width:8px; height:8px; border-radius:50%; background:<?php echo $__statusColor; ?>;"></span>
                            <?php echo htmlspecialchars($__statusText); ?>
                        </span>
                        <?php if ($__statusDetail !== ''): ?>
                        <span style="font-size:13px; color:#6b7280;"><?php echo $__statusDetail; ?></span>
                        <?php endif; ?>
                    </div>
                    <button type="button"
                            data-hours-toggle
                            data-show-label="<?php echo htmlspecialchars($__viewWeek, ENT_QUOTES); ?>"
                            data-hide-label="<?php echo htmlspecialchars($__hideWeek, ENT_QUOTES); ?>"
                            style="margin-top:10px; display:inline-flex; align-items:center; gap:6px; background:transparent; border:1px solid rgba(0,0,0,0.12); color:inherit; padding:8px 14px; border-radius:8px; font-size:13px; cursor:pointer;">
                        <i class="fa-solid fa-calendar-week"></i>
                        <span data-hours-toggle-label><?php echo htmlspecialchars($__viewWeek); ?></span>
                    </button>
                    <div data-hours-week style="display:none; margin-top:10px; border-top:1px solid rgba(0,0,0,0.08); padding-top:10px;">
                        <?php foreach (CardSections::DAY_KEYS as $__d):
                            $__row = $businessHours[$__d] ?? ['is_closed' => true];
                            $__isToday = ($__d === $__todayKey);
                            $__dayName = CardSections::dayName($__d, $locale);
                            $__closedText = ($locale === 'ar') ? 'مغلق' : 'Closed';
                            $__rowBg = $__isToday ? 'background:' . $accentColor . '15;' : '';
                        ?>
                        <div style="display:flex; justify-content:space-between; align-items:center; padding:8px 10px; border-radius:8px; margin-bottom:4px; <?php echo $__rowBg; ?> font-size:13px;<?php echo $isRtl ? 'flex-direction:row-reverse;' : ''; ?>">
                            <div style="font-weight:<?php echo $__isToday ? '600' : '500'; ?>; color:<?php echo $__isToday ? $accentColor : 'inherit'; ?>;">
                                <?php echo htmlspecialchars($__dayName); ?>
                                <?php if ($__isToday): ?><span style="font-size:10px; margin-<?php echo $isRtl ? 'right' : 'left'; ?>:6px; text-transform:uppercase; opacity:0.7;"><?php echo htmlspecialchars($__todayLabel); ?></span><?php endif; ?>
                            </div>
                            <div style="color:#6b7280; font-variant-numeric:tabular-nums;">
                                <?php if (!empty($__row['is_closed']) || empty($__row['open_time']) || empty($__row['close_time'])): ?>
                                    <?php echo htmlspecialchars($__closedText); ?>
                                <?php else: ?>
                                    <?php echo htmlspecialchars(substr($__row['open_time'], 0, 5)); ?>–<?php echo htmlspecialchars(substr($__row['close_time'], 0, 5)); ?>
                                    <?php if (!empty($__row['break_start']) && !empty($__row['break_end'])): ?>
                                        <span style="font-size:11px; opacity:0.7; margin-<?php echo $isRtl ? 'right' : 'left'; ?>:4px;">
                                            (<?php echo $locale === 'ar' ? 'استراحة' : 'break'; ?> <?php echo htmlspecialchars(substr($__row['break_start'], 0, 5)); ?>–<?php echo htmlspecialchars(substr($__row['break_end'], 0, 5)); ?>)
                                        </span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <div style="margin-top:6px; font-size:11px; color:#9ca3af;<?php echo $isRtl ? 'text-align:right;' : ''; ?>">
                            <?php echo htmlspecialchars($__tzLabel); ?>: <?php echo htmlspecialchars($businessTz); ?>
                        </div>
                    </div>
                </div>
            <?php elseif ($__sec === 'faq' && !empty($sectionMaster['faq_enabled']) && !empty($sectionFaqs)): ?>
                <?php $faqTitle = ($locale === 'ar') ? 'الأسئلة الشائعة' : 'FAQ'; ?>
                <div class="card-section">
                    <h3><?php echo htmlspecialchars($faqTitle); ?></h3>
                    <div class="faq-list">
                        <?php foreach ($sectionFaqs as $__faq): ?>
                        <details class="faq-item">
                            <summary class="faq-q">
                                <span class="faq-q-text"><?php echo htmlspecialchars($__faq['question']); ?></span>
                                <span class="faq-icon" aria-hidden="true"><i class="fa-solid fa-plus"></i></span>
                            </summary>
                            <div class="faq-a"><?php echo nl2br(htmlspecialchars($__faq['answer'])); ?></div>
                        </details>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php elseif ($__sec === 'lead_form' && !empty($sectionMaster['lead_form_enabled'])): ?>
                <div class="card-section">
                    <h3><?= htmlspecialchars(t('digitalcard.section_contact')) ?></h3>
                    <form class="lead-form" id="leadForm" autocomplete="off">
                        <input type="hidden" name="employee_id" value="<?php echo htmlspecialchars($employee['id']); ?>">
                        <div class="hp"><label><?= htmlspecialchars(t('digitalcard.hp_honeypot')) ?><input type="text" name="website_url" tabindex="-1" autocomplete="off"></label></div>
                        <div class="lead-error" id="leadError" style="display:none;"></div>
                        <label><?= htmlspecialchars(t('digitalcard.lead_field_name')) ?><input type="text" name="name" required maxlength="255"></label>
                        <label><?= htmlspecialchars(t('digitalcard.lead_field_email')) ?><input type="email" name="email" maxlength="255"></label>
                        <label><?= htmlspecialchars(t('digitalcard.lead_field_phone')) ?><input type="tel" name="phone" maxlength="50"></label>
                        <label><?= htmlspecialchars(t('digitalcard.lead_field_message')) ?><textarea name="message" maxlength="4000"></textarea></label>
                        <button type="submit" id="leadSubmit"><?= htmlspecialchars(t('digitalcard.lead_send')) ?></button>
                    </form>
                    <div class="lead-success" id="leadSuccess" style="display:none;"><?= htmlspecialchars(t('digitalcard.lead_thanks')) ?></div>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>

        <?php if ($apptEnabled): ?>
        <!-- Appointment Booking Widget -->
        <div class="card-section" id="apptSection">
            <h3><?= htmlspecialchars(t('digitalcard.section_book')) ?></h3>
            <div id="apptStep1">
                <label class="appt-label"><?= htmlspecialchars(t('digitalcard.appt_choose_date')) ?></label>
                <input type="date" id="apptDate" class="appt-input">
                <div id="apptSlots" class="appt-slots"></div>
                <div id="apptSlotsEmpty" class="appt-empty"><?= htmlspecialchars(t('digitalcard.appt_no_slots')) ?></div>
            </div>
            <form id="apptForm" style="display:none;margin-top:12px;" autocomplete="off">
                <div id="apptChosen" class="appt-chosen"></div>
                <input type="hidden" name="employee_id" value="<?php echo htmlspecialchars($employee['id']); ?>">
                <input type="hidden" name="slot_start" id="apptSlotStart">
                <div class="hp" style="position:absolute;left:-9999px;"><label><?= htmlspecialchars(t('digitalcard.hp_honeypot')) ?><input type="text" name="website_url" tabindex="-1" autocomplete="off"></label></div>
                <div id="apptError" style="display:none;color:#ef4444;font-size:13px;margin-bottom:8px;"></div>
                <input type="text" name="name" placeholder="<?= htmlspecialchars(t('digitalcard.appt_ph_name')) ?>" required maxlength="255" class="appt-input">
                <input type="email" name="email" placeholder="<?= htmlspecialchars(t('digitalcard.appt_ph_email')) ?>" maxlength="255" class="appt-input">
                <input type="tel" name="phone" placeholder="<?= htmlspecialchars(t('digitalcard.appt_ph_phone')) ?>" maxlength="50" class="appt-input">
                <textarea name="notes" placeholder="<?= htmlspecialchars(t('digitalcard.appt_ph_notes')) ?>" maxlength="4000" rows="3" class="appt-textarea"></textarea>
                <div style="display:flex;gap:8px;">
                    <button type="button" id="apptBack" class="appt-back-btn"><?= htmlspecialchars(t('digitalcard.appt_back')) ?></button>
                    <button type="submit" id="apptSubmit" class="appt-submit-btn"><?= htmlspecialchars(t('digitalcard.appt_confirm')) ?></button>
                </div>
            </form>
            <div id="apptSuccess" style="display:none;text-align:center;padding:18px;">
                <div style="font-size:32px;margin-bottom:6px;color:<?php echo htmlspecialchars($accentColor); ?>;">&#10003;</div>
                <div style="font-weight:600;"><?= htmlspecialchars(t('digitalcard.appt_sent_h')) ?></div>
                <div class="appt-success-msg"><?= htmlspecialchars(t('digitalcard.appt_sent_body')) ?></div>
            </div>
        </div>
        <script>
        (function(){
            var EID = <?php echo json_encode($employee['id']); ?>;
            var MAX_ADV = <?php echo (int)$apptSettings['max_advance_days']; ?>;
            var dateInput = document.getElementById('apptDate');
            var slotsEl   = document.getElementById('apptSlots');
            var emptyEl   = document.getElementById('apptSlotsEmpty');
            var step1     = document.getElementById('apptStep1');
            var form      = document.getElementById('apptForm');
            var slotInput = document.getElementById('apptSlotStart');
            var chosenEl  = document.getElementById('apptChosen');
            var backBtn   = document.getElementById('apptBack');
            var errEl     = document.getElementById('apptError');
            var submitBtn = document.getElementById('apptSubmit');
            var successEl = document.getElementById('apptSuccess');

            function pad(n){return n<10?'0'+n:''+n;}
            var today = new Date();
            var max = new Date(); max.setDate(max.getDate() + MAX_ADV);
            dateInput.min = today.getFullYear()+'-'+pad(today.getMonth()+1)+'-'+pad(today.getDate());
            dateInput.max = max.getFullYear()+'-'+pad(max.getMonth()+1)+'-'+pad(max.getDate());
            dateInput.value = dateInput.min;

            function loadSlots() {
                slotsEl.innerHTML = '<div style="grid-column:1/-1;text-align:center;opacity:0.7;font-size:13px;padding:8px 0;"><?= htmlspecialchars(t('digitalcard.appt_loading'), ENT_QUOTES) ?></div>';
                emptyEl.style.display = 'none';
                fetch('/api/appointment/slots.php?eid='+encodeURIComponent(EID)+'&date='+encodeURIComponent(dateInput.value))
                    .then(function(r){return r.json();})
                    .then(function(res){
                        slotsEl.innerHTML = '';
                        if (!res.success || !res.slots || !res.slots.length) {
                            emptyEl.style.display = 'block';
                            return;
                        }
                        res.slots.forEach(function(s){
                            var b = document.createElement('button');
                            b.type = 'button';
                            b.textContent = s.label;
                            b.className = 'appt-slot';
                            b.addEventListener('click', function(){
                                slotInput.value = s.start;
                                chosenEl.textContent = dateInput.value + ' at ' + s.label;
                                step1.style.display = 'none';
                                form.style.display = 'block';
                            });
                            slotsEl.appendChild(b);
                        });
                    })
                    .catch(function(){
                        slotsEl.innerHTML = '';
                        emptyEl.textContent = 'Could not load slots. Try again.';
                        emptyEl.style.display = 'block';
                    });
            }
            dateInput.addEventListener('change', loadSlots);
            loadSlots();

            backBtn.addEventListener('click', function(){
                form.style.display = 'none';
                step1.style.display = 'block';
            });

            form.addEventListener('submit', function(e){
                e.preventDefault();
                errEl.style.display = 'none';
                submitBtn.disabled = true;
                submitBtn.textContent = 'Booking...';
                var data = new FormData(form);
                fetch('/api/appointment/book.php', { method: 'POST', body: data })
                    .then(function(r){return r.json().catch(function(){return {success:false,error:'Network error'};});})
                    .then(function(res){
                        if (res && res.success) {
                            form.style.display = 'none';
                            step1.style.display = 'none';
                            successEl.style.display = 'block';
                        } else {
                            errEl.textContent = (res && res.error) || 'Booking failed.';
                            errEl.style.display = 'block';
                            submitBtn.disabled = false;
                            submitBtn.textContent = 'Confirm booking';
                        }
                    })
                    .catch(function(){
                        errEl.textContent = 'Network error. Try again.';
                        errEl.style.display = 'block';
                        submitBtn.disabled = false;
                        submitBtn.textContent = 'Confirm booking';
                    });
            });
        })();
        </script>
        <?php endif; ?>

        <?php if ($appleWalletEnabled || $googleWalletEnabled): ?>
        <!-- Add to Wallet -->
        <div class="wallet-buttons" id="walletButtons">
            <?php if ($appleWalletEnabled): ?>
            <a href="<?php echo htmlspecialchars($appleWalletUrl); ?>" class="wallet-btn apple" aria-label="<?php echo htmlspecialchars(t('digitalcard.wallet_apple_aria')); ?>">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M17.05 12.54c-.03-2.9 2.37-4.3 2.48-4.37-1.36-1.98-3.47-2.26-4.22-2.29-1.8-.18-3.51 1.06-4.43 1.06-.93 0-2.33-1.03-3.83-1-1.97.03-3.79 1.15-4.8 2.91-2.05 3.56-.52 8.82 1.48 11.71.98 1.41 2.15 2.99 3.68 2.94 1.48-.06 2.04-.96 3.83-.96s2.3.96 3.86.93c1.59-.03 2.6-1.44 3.57-2.86 1.13-1.64 1.59-3.24 1.62-3.32-.04-.02-3.11-1.2-3.14-4.75zM14.12 3.79c.8-.97 1.34-2.31 1.19-3.65-1.15.05-2.55.77-3.37 1.73-.74.85-1.39 2.21-1.22 3.53 1.29.1 2.59-.65 3.4-1.61z"/></svg>
                Apple Wallet
            </a>
            <?php endif; ?>
            <?php if ($googleWalletEnabled): ?>
            <a href="<?php echo htmlspecialchars($googleWalletUrl); ?>" class="wallet-btn google" aria-label="<?php echo htmlspecialchars(t('digitalcard.wallet_google_aria')); ?>">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="6" width="20" height="13" rx="2"/><path d="M2 10h20"/><path d="M6 15h4"/></svg>
                Google Wallet
            </a>
            <?php endif; ?>
        </div>
        <script>
            // Apple Wallet (.pkpass) only adds on iOS mobile (iPhone/iPad), so the
            // Apple button shows ONLY there. Google Wallet shows everywhere except
            // iOS. If nothing is left for the platform, drop the block so it leaves
            // no empty gap.
            (function () {
                var w = document.getElementById('walletButtons');
                if (!w) return;
                var ua = navigator.userAgent || '';
                var isIOS = /iPad|iPhone|iPod/i.test(ua) ||
                            (/Macintosh/i.test(ua) && typeof document !== 'undefined' && 'ontouchend' in document);
                var apple = w.querySelector('.wallet-btn.apple');
                var google = w.querySelector('.wallet-btn.google');
                if (apple && !isIOS) apple.style.display = 'none';   // Apple = iOS mobile only
                if (google && isIOS) google.style.display = 'none';  // Google never on iOS
                var anyVisible = (apple && apple.style.display !== 'none') ||
                                 (google && google.style.display !== 'none');
                if (!anyVisible) w.style.display = 'none';
            })();
        </script>
        <?php endif; ?>

        <!-- Viral "Made with Cardify" footer, every public card scan becomes a
             Cardify impression. Owner can hide via admin (Pro tier only).
             Links through /card_click.php so we measure conversion. -->
        <?php
            // Hide-branding flag (retained from the tier model); platform policy
            // is that Cardify branding always renders, so this gate is informational.
            // Column may be absent pre-migration 065, coalesce safely.
            $__hideBranding = (int)($employee['hide_cardify_branding'] ?? 0) === 1;
            $__brandingPaid = false;
            try {
                require_once INCLUDES_DIR . '/Billing.php';
                $__brandingPaid = Billing::hasFeature($company['id'], 'custom_branding');
            } catch (Throwable $e) {
                $__brandingPaid = false;
            }
            // Always show, ignore pro-tier hide_cardify_branding on purpose.
            $__showViralFooter = true;
        ?>
        <?php if ($__showViralFooter): ?>
            <?php
                $__claimDest  = '/claim?utm_source=card&utm_medium=viral_footer'
                              . '&utm_campaign=made_with_cardify'
                              . '&utm_content=' . urlencode($employee['id']);
                $__claimHref  = $cardClickUrl('viral_footer_click', $__claimDest);
                $__viralLabelHtml = $isRtl
                    ? 'أُنشئ بـ <strong>Cardify</strong> · أنشئ بطاقتك مجاناً'
                    : 'Made with <strong>Cardify</strong> · Create yours free';
            ?>
            <div class="cardify-viral-footer">
                <a href="<?php echo htmlspecialchars($__claimHref, ENT_QUOTES); ?>"
                   class="viral-link"
                   aria-label="<?php echo $isRtl ? 'أُنشئ بطاقتك مجاناً مع Cardify' : 'Made with Cardify, create yours free'; ?>"
                   rel="noopener">
                    <span class="viral-logo" aria-hidden="true">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                             stroke="currentColor" stroke-width="2.2"
                             stroke-linecap="round" stroke-linejoin="round">
                            <rect x="2" y="6" width="20" height="13" rx="2.5"/>
                            <path d="M6 11h6"/>
                            <path d="M6 14h4"/>
                            <circle cx="17" cy="13" r="1.6" fill="currentColor" stroke="none"/>
                        </svg>
                    </span>
                    <span class="viral-text"><?php echo $__viralLabelHtml; ?></span>
                </a>
            </div>
        <?php endif; ?>
    </div>

    <div class="copy-toast" id="copyToast">Link copied!</div>

    <script>
        // ---- Theme toggle (visitor override) ----
        (function(){
            var COOKIE = 'cardify_card_theme';
            var COOKIE_MAX_AGE = 7 * 24 * 60 * 60; // 7 days

            function readCookie(name) {
                var parts = ('; ' + document.cookie).split('; ' + name + '=');
                if (parts.length === 2) return decodeURIComponent(parts.pop().split(';').shift());
                return '';
            }
            function writeCookie(value) {
                // SameSite=Lax keeps the cookie on cross-site reloads (e.g. from QR scanners).
                // Secure flag auto-applied over HTTPS (Codex round-2 Finding 7).
                var secure = (location.protocol === 'https:') ? '; Secure' : '';
                document.cookie = COOKIE + '=' + encodeURIComponent(value) +
                    '; Max-Age=' + COOKIE_MAX_AGE + '; Path=/; SameSite=Lax' + secure;
            }

            var btn = document.getElementById('themeToggle');
            if (!btn) return;

            // On first load with NO cookie, honour prefers-color-scheme if it disagrees with the SSR default.
            var existing = readCookie(COOKIE);
            var current = btn.getAttribute('data-mode'); // 'light' | 'dark' as rendered
            if (!existing && window.matchMedia) {
                try {
                    var prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
                    var preferred = prefersDark ? 'dark' : 'light';
                    if (preferred !== current) {
                        writeCookie(preferred);
                        window.location.reload();
                        return;
                    }
                } catch (e) { /* ignore */ }
            }

            btn.addEventListener('click', function(){
                var next = current === 'dark' ? 'light' : 'dark';
                writeCookie(next);
                // Optimistic class swap for an instant tactile response before reload completes.
                document.body.classList.remove('force-light', 'force-dark');
                document.body.classList.add('force-' + next);
                // Full reload so SSR re-renders every section with the correct $isDarkPage.
                window.location.reload();
            });
        })();

        // Card flip
        const cardFlip = document.getElementById('cardFlip');
        const cardInner = document.getElementById('cardInner');
        const tapHint = document.getElementById('tapHint');

        // Only wire the flip handler when a back face is actually present
        if (cardFlip && cardInner && !cardFlip.classList.contains('no-back')) {
            cardFlip.addEventListener('click', function() {
                cardInner.classList.toggle('flipped');
                if (tapHint) tapHint.style.opacity = '0';
            });
        }

        // Share. Prefer the clean tenant-slug URL (e.g. adnan.cardify.om/jarwish9)
        // over the long /card/{employee_id} variant the visitor may have landed
        // on. Falls back to window.location.href when no clean URL is known.
        const __shareUrl = <?php
            $emailLocal = '';
            if (!empty($email) && strpos($email, '@') !== false) {
                $emailLocal = strtolower(substr($email, 0, strpos($email, '@')));
                $emailLocal = preg_replace('/[^a-z0-9._-]/', '', $emailLocal);
            }
            $tenantSlug = $company['slug'] ?? '';
            if ($tenantSlug && $emailLocal) {
                echo json_encode('https://' . $tenantSlug . '.cardify.om/' . $emailLocal);
            } else {
                echo 'window.location.href';
            }
        ?>;
        function shareCard() {
            const shareData = {
                title: <?php echo json_encode($name . ' - ' . $companyName); ?>,
                text: <?php echo json_encode($name . ' - ' . $position . ' at ' . $companyName); ?>,
                url: __shareUrl
            };

            if (navigator.share) {
                navigator.share(shareData).catch(() => {});
            } else {
                // Fallback: copy to clipboard
                navigator.clipboard.writeText(__shareUrl).then(() => {
                    const toast = document.getElementById('copyToast');
                    toast.classList.add('show');
                    setTimeout(() => toast.classList.remove('show'), 2000);
                }).catch(() => {
                    // Final fallback
                    const input = document.createElement('input');
                    input.value = __shareUrl;
                    document.body.appendChild(input);
                    input.select();
                    document.execCommand('copy');
                    document.body.removeChild(input);
                    const toast = document.getElementById('copyToast');
                    toast.classList.add('show');
                    setTimeout(() => toast.classList.remove('show'), 2000);
                });
            }
        }

        // Star picker for testimonial form
        (function(){
            var picker = document.getElementById('starPicker');
            if (!picker) return;
            var input = document.getElementById('ratingInput');
            var stars = picker.querySelectorAll('.star');
            function setVal(v) {
                input.value = v;
                stars.forEach(function(s){
                    var sv = parseInt(s.getAttribute('data-val'),10);
                    s.classList.toggle('active', sv <= v);
                    s.setAttribute('aria-checked', sv === v ? 'true' : 'false');
                });
            }
            stars.forEach(function(s){
                s.addEventListener('click', function(){ setVal(parseInt(s.getAttribute('data-val'),10)); });
                s.addEventListener('keydown', function(e){
                    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); setVal(parseInt(s.getAttribute('data-val'),10)); }
                });
            });
        })();

        // Testimonial submit
        (function(){
            var form = document.getElementById('testimonialForm');
            if (!form) return;
            form.addEventListener('submit', function(e){
                e.preventDefault();
                var btn = document.getElementById('testimonialSubmit');
                var err = document.getElementById('testimonialError');
                err.style.display = 'none';
                btn.disabled = true;
                btn.textContent = 'Submitting...';
                var data = new FormData(form);
                fetch('/api/testimonial.php', { method: 'POST', body: data })
                    .then(function(r){ return r.json().catch(function(){ return { success:false, error:'Network error' }; }); })
                    .then(function(res){
                        if (res && res.success) {
                            form.style.display = 'none';
                            document.getElementById('testimonialSuccess').style.display = 'block';
                        } else {
                            err.textContent = (res && res.error) || 'Failed to submit. Please try again.';
                            err.style.display = 'block';
                            btn.disabled = false;
                            btn.textContent = 'Submit for review';
                        }
                    })
                    .catch(function(){
                        err.textContent = 'Network error. Try again.';
                        err.style.display = 'block';
                        btn.disabled = false;
                        btn.textContent = 'Submit for review';
                    });
            });
        })();

        // Lead form submit
        (function(){
            var form = document.getElementById('leadForm');
            if (!form) return;
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                var btn = document.getElementById('leadSubmit');
                var err = document.getElementById('leadError');
                err.style.display = 'none';
                btn.disabled = true;
                btn.textContent = 'Sending...';
                var data = new FormData(form);
                fetch('/api/lead.php', { method: 'POST', body: data })
                    .then(function(r){ return r.json().catch(function(){ return { success:false, error:'Network error' }; }); })
                    .then(function(res){
                        if (res && res.success) {
                            form.style.display = 'none';
                            document.getElementById('leadSuccess').style.display = 'block';
                        } else {
                            err.textContent = (res && res.error) || 'Failed to send. Please try again.';
                            err.style.display = 'block';
                            btn.disabled = false;
                            btn.textContent = 'Send';
                        }
                    })
                    .catch(function(){
                        err.textContent = 'Network error. Try again.';
                        err.style.display = 'block';
                        btn.disabled = false;
                        btn.textContent = 'Send';
                    });
            });
        })();
    </script>

    <script>
        // Business Hours: expand/collapse weekly schedule
        (function () {
            var toggles = document.querySelectorAll('[data-hours-toggle]');
            toggles.forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var section = btn.closest('[data-hours-section]');
                    if (!section) return;
                    var week = section.querySelector('[data-hours-week]');
                    var label = section.querySelector('[data-hours-toggle-label]');
                    if (!week) return;
                    var isOpen = week.style.display !== 'none';
                    week.style.display = isOpen ? 'none' : 'block';
                    if (label) {
                        label.textContent = isOpen
                            ? (btn.getAttribute('data-show-label') || 'View weekly schedule')
                            : (btn.getAttribute('data-hide-label') || 'Hide schedule');
                    }
                });
            });
        })();
    </script>

<?php
    // Build Person JSON-LD (always emitted) and optionally a LocalBusiness
    // sub-graph when business hours are published.
    $__jsonLdGraph = [
        [
            '@type' => 'Person',
            'name' => $employee['name_en'] ?? $employee['name'] ?? '',
            'jobTitle' => $employee['position_en'] ?? $employee['position'] ?? '',
            'worksFor' => [
                '@type' => 'Organization',
                'name' => $company['name_en'] ?? $company['name'] ?? '',
            ],
            'email' => $employee['email'] ?? '',
            'telephone' => $employee['phone'] ?? $employee['mobile'] ?? '',
            'url' => getTenantCardUrl($company['slug'] ?? null, 'card/' . ($employee['id'] ?? '')),
        ],
    ];
    if (!empty($sectionMaster['hours_enabled']) && !empty($businessHours)) {
        $__openingSpecs = CardSections::buildSchemaOpeningHours($businessHours);
        if (!empty($__openingSpecs)) {
            $__localBiz = [
                '@type' => 'LocalBusiness',
                'name' => $company['name_en'] ?? $company['name'] ?? '',
                'url' => getTenantCardUrl($company['slug'] ?? null, 'card/' . ($employee['id'] ?? '')),
                'openingHours' => $__openingSpecs,
            ];
            if (!empty(trim((string)($sectionMaster['location_address'] ?? '')))) {
                $__localBiz['address'] = trim((string)$sectionMaster['location_address']);
            }
            if (!empty($sectionMaster['location_lat']) && !empty($sectionMaster['location_lng'])) {
                $__localBiz['geo'] = [
                    '@type' => 'GeoCoordinates',
                    'latitude'  => (float)$sectionMaster['location_lat'],
                    'longitude' => (float)$sectionMaster['location_lng'],
                ];
            }
            $__jsonLdGraph[] = $__localBiz;
        }
    }
?>
<script type="application/ld+json">
<?= json_encode([
    '@context' => 'https://schema.org',
    '@graph'   => $__jsonLdGraph,
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?>
</script>
<?php if (!empty($sectionMaster['faq_enabled']) && !empty($sectionFaqs)): ?>
<script type="application/ld+json">
<?= json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'FAQPage',
    'mainEntity' => array_map(function ($f) {
        return [
            '@type' => 'Question',
            'name' => (string)($f['question'] ?? ''),
            'acceptedAnswer' => [
                '@type' => 'Answer',
                'text' => (string)($f['answer'] ?? ''),
            ],
        ];
    }, $sectionFaqs),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?>
</script>
<?php endif; ?>
</body>
</html>
<?php
// ============================================================================
// POST-RESPONSE: analytics inserts run AFTER the client connection is closed.
// ============================================================================
// fastcgi_finish_request() flushes the response and closes the FastCGI socket
// to nginx, so the user's browser stops waiting on us. PHP keeps running this
// block in the background. The page has already been delivered, so the
// analytics inserts (QR scan + card view) no longer add to TTFB or total time.
if (isset($employee['id'], $company['id'])) {
    if (function_exists('fastcgi_finish_request')) {
        while (ob_get_level()) { @ob_end_flush(); }
        @flush();
        @fastcgi_finish_request();
    }
    try { QRTracker::logScan($employee['id'], $company['id']); }
    catch (Throwable $e) { error_log('QR tracking failed (post-response): ' . $e->getMessage()); }
    try { CardAnalytics::logView($employee['id'], $company['id']); }
    catch (Throwable $e) { error_log('CardAnalytics logView failed (post-response): ' . $e->getMessage()); }
}
?>
