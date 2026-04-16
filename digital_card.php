<?php
/**
 * Digital Card Page
 * Displays a branded digital business card with flip animation, contact actions, and vCard download.
 * URL: /{company_slug}/card/{employee_id} (via nginx rewrite)
 */

ob_start();

set_error_handler(function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    require_once __DIR__ . '/config.php';
    require_once INCLUDES_DIR . '/QRTracker.php';
    require_once INCLUDES_DIR . '/CardSections.php';
    require_once INCLUDES_DIR . '/Appointments.php';
    require_once INCLUDES_DIR . '/CardAnalytics.php';

    /**
     * Normalize asset path — ensure uploaded/theme assets resolve from site root, not
     * relative to the current /{slug}/card/{eid} URL. DB rows historically stored
     * paths in three shapes: "/uploads/..", "uploads/..", and bare "companies/..".
     */
    if (!function_exists('cardifyAssetUrl')) {
        function cardifyAssetUrl($path) {
            $p = trim((string)$path);
            if ($p === '') return '';
            if (preg_match('#^(https?:)?//#i', $p)) return $p; // absolute URL
            if ($p[0] === '/') return $p;                     // already site-root
            // Bare relative — prepend /uploads/ for company theme uploads
            if (strpos($p, 'uploads/') === 0) return '/' . $p;
            return '/uploads/' . $p;
        }
    }

    $companySlug = trim($_GET['company_slug'] ?? '');
    $employeeId = trim($_GET['employee_id'] ?? '');

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

    // Look up employee scoped to company
    $employee = findEmployeeById($employeeId, $company['id']);
    if (!$employee) {
        // Try to load theme for branded 404
        $theme = loadCompanyTheme($company['id']);
        http_response_code(404);
        renderBranded404($company, $theme);
        exit;
    }

    // Load company theme
    $theme = loadCompanyTheme($company['id']);

    // Load latest generated card
    $db = Database::getInstance();
    $card = $db->fetchOne(
        "SELECT * FROM generated_cards WHERE employee_id = :eid AND company_id = :cid ORDER BY generated_at DESC LIMIT 1",
        ['eid' => $employee['id'], 'cid' => $company['id']]
    );

    // Log QR scan (non-fatal)
    try {
        QRTracker::logScan($employee['id'], $company['id']);
    } catch (Throwable $e) {
        error_log("QR tracking failed: " . $e->getMessage());
    }

    // Log view / QR-scan event (non-fatal) — per-card analytics
    try {
        CardAnalytics::logView($employee['id'], $company['id']);
    } catch (Throwable $e) {
        error_log("CardAnalytics logView failed: " . $e->getMessage());
    }

    // Helper to build a tracked CTA URL
    $__eid = $employee['id'];
    $cardClickUrl = function ($cta, $dest) use ($__eid) {
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

    // Card image paths — DB stores filenames, construct full web path
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

    // Build VCF download URL — short format (?i=id) produces smaller QR codes
    $vcfUrl = '/qr.php?i=' . urlencode($employee['id']);

    // ---- Locale resolution (sets cookie if ?lang= present) -------------
    $locale = CardSections::resolveLocale();
    $isRtl = CardSections::isRtl($locale);

    // Employee contact data — localized with EN fallback
    $name = CardSections::tColumn($employee, 'name', $locale);
    if (trim((string)$name) === '') $name = $employee['name'] ?? 'Employee';
    $position = CardSections::tColumn($employee, 'position', $locale);
    if (trim((string)$position) === '') $position = $employee['position'] ?? $employee['job_title'] ?? '';
    $companyName = CardSections::tColumn($company, 'name', $locale);
    if (trim((string)$companyName) === '') $companyName = $company['name'] ?? '';
    $phone = $employee['phone'] ?? '';
    $mobile = $employee['mobile'] ?? '';
    $email = $employee['email'] ?? '';
    $website = $company['website'] ?? '';
    $address = $company['address'] ?? '';

    // Phone for WhatsApp (strip + and non-digits)
    $waPhone = preg_replace('/[^0-9]/', '', $mobile ?: $phone);

    // Logo path
    $logoPath = ($theme && !empty($theme['logo_path'])) ? cardifyAssetUrl($theme['logo_path']) : '';

    // Public card sections (localized with EN fallback)
    $sectionMaster = CardSections::loadMaster($employee['id'], $company['id']);
    $bioText = ($locale === 'ar' && trim((string)($sectionMaster['bio_text_ar'] ?? '')) !== '')
        ? $sectionMaster['bio_text_ar']
        : ($sectionMaster['bio_text'] ?? '');
    $sectionServices = !empty($sectionMaster['services_enabled']) ? CardSections::loadServicesLocalized($employee['id'], $locale) : [];
    $sectionGallery = !empty($sectionMaster['gallery_enabled']) ? CardSections::loadGallery($employee['id']) : [];
    // Approved-only testimonials, then overlay AR translations.
    if (!empty($sectionMaster['testimonials_enabled'])) {
        $sectionTestimonials = CardSections::loadApprovedTestimonials($employee['id']);
        if ($locale === 'ar' && !empty($sectionTestimonials)) {
            // Apply AR overlay (mirrors loadTestimonialsLocalized but on already-filtered set).
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
    $sectionOffers = !empty($sectionMaster['offers_enabled']) ? CardSections::loadOffers($employee['id'], true) : [];
    $sectionOrder = array_values(array_filter(array_map('trim', explode(',', $sectionMaster['section_order'] ?? implode(',', CardSections::SECTION_KEYS)))));
    if (!in_array('offers', $sectionOrder, true)) {
        $sectionOrder[] = 'offers';
    }
    if (!in_array('location', $sectionOrder, true)) {
        $sectionOrder[] = 'location';
    }

    // Appointment booking settings (rendered as its own section after card sections)
    $apptSettings = Appointments::loadSettings($employee['id'], $company['id']);
    $apptEnabled = !empty($apptSettings['enabled']);

    // Wallet pass endpoints (feature-flagged — buttons render only when enabled)
    require_once INCLUDES_DIR . '/AppleWalletPass.php';
    require_once INCLUDES_DIR . '/GoogleWalletPass.php';
    $appleWalletEnabled  = AppleWalletPass::isEnabled();
    $googleWalletEnabled = GoogleWalletPass::isEnabled();
    $appleWalletUrl  = '/wallet_apple.php?i='  . urlencode($employee['id']) . '&c=' . urlencode($companySlug);
    $googleWalletUrl = '/wallet_google.php?i=' . urlencode($employee['id']) . '&c=' . urlencode($companySlug);

} catch (Throwable $e) {
    while (ob_get_level()) { ob_end_clean(); }
    http_response_code(500);
    echo 'Error loading card.';
    error_log("digital_card.php error: " . $e->getMessage());
    exit;
}

/**
 * Load company theme from database
 */
function loadCompanyTheme($companyId) {
    try {
        $db = Database::getInstance();
        return $db->fetchOne("SELECT * FROM company_themes WHERE company_id = :cid", ['cid' => $companyId]);
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Render branded 404 page
 */
function renderBranded404($company, $theme) {
    $accentColor = ($theme && !empty($theme['primary_color'])) ? $theme['primary_color'] : '#d4af37';
    $logoPath = ($theme && !empty($theme['logo_path'])) ? cardifyAssetUrl($theme['logo_path']) : '';
    $companyName = $company ? ($company['name'] ?? '') : '';
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>Card Not Available<?php echo $companyName ? ' - ' . htmlspecialchars($companyName) : ''; ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { min-height: 100vh; display: flex; align-items: center; justify-content: center; background: linear-gradient(to bottom, #141421, #1a1a2e); font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; color: #eee; padding: 24px; }
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
        <h1>This card is no longer available</h1>
        <p>The business card you're looking for may have been removed or the link is invalid.</p>
        <div class="footer">Powered by <a href="/">Cardify</a></div>
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
?>
<!DOCTYPE html>
<html lang="<?php echo $isRtl ? 'ar' : 'en'; ?>"<?php echo $isRtl ? ' dir="rtl"' : ''; ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo htmlspecialchars($name); ?> - <?php echo htmlspecialchars($companyName); ?></title>
    <meta name="description" content="<?php echo htmlspecialchars($name . ' - ' . $position . ' at ' . $companyName); ?>">
    <meta property="og:title" content="<?php echo htmlspecialchars($name . ' - ' . $companyName); ?>">
    <meta property="og:description" content="<?php echo htmlspecialchars($position . ' at ' . $companyName); ?>">
    <?php if ($frontImage): ?>
    <meta property="og:image" content="<?php echo htmlspecialchars($frontImage); ?>">
    <?php endif; ?>
    <link rel="icon" href="<?php echo $logoPath ? htmlspecialchars($logoPath) : '/favicon.svg'; ?>">
    <?php if ($isRtl): ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Arabic:wght@400;500;600;700&display=swap" rel="stylesheet">
    <?php endif; ?>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body { overflow-x: hidden; }
        /* Honeypot anti-spam fields — visually hidden without causing document overflow (esp. in RTL) */
        .hp, .lead-form .hp { position: absolute !important; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0,0,0,0); clip-path: inset(50%); white-space: nowrap; border: 0; left: auto !important; }
        body {
            min-height: 100vh;
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
            max-width: 440px;
            margin: 0 auto;
            padding: 24px 16px 40px;
        }

        /* Company Logo */
        .company-logo {
            text-align: center;
            margin-bottom: 20px;
        }
        .company-logo img {
            max-width: 120px;
            height: auto;
            border-radius: 8px;
        }

        /* Card Flip */
        .card-flip-container {
            perspective: 1000px;
            max-width: 400px;
            margin: 0 auto 8px;
            cursor: pointer;
            -webkit-tap-highlight-color: transparent;
        }
        .card-flip-inner {
            position: relative;
            width: 100%;
            aspect-ratio: 1050/600;
            transition: transform 0.7s cubic-bezier(0.4, 0, 0.2, 1);
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
            object-fit: cover;
            display: block;
        }
        .card-back-face {
            transform: rotateY(180deg);
        }
        .tap-hint {
            text-align: center;
            font-size: 11px;
            color: <?php echo $isDarkPage ? '#666' : '#999'; ?>;
            margin-top: 8px;
            transition: opacity 0.5s;
        }

        /* Employee Info */
        .employee-info {
            text-align: center;
            margin: 20px auto 16px;
            max-width: 400px;
        }
        .employee-name {
            font-size: 22px;
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
            margin: 0 auto 18px;
        }
        .action-btn {
            flex: 1;
            padding: 12px 8px;
            border-radius: 10px;
            text-align: center;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            color: white;
            display: block;
            transition: opacity 0.2s;
        }
        .action-btn:active { opacity: 0.8; }
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
            padding: 12px 16px;
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
            font-size: 16px;
            flex-shrink: 0;
        }
        .contact-value {
            flex: 1;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        /* Bottom Buttons */
        .bottom-buttons {
            display: flex;
            gap: 10px;
            max-width: 400px;
            margin: 18px auto 0;
        }
        .bottom-btn {
            flex: 1;
            padding: 13px;
            border-radius: 10px;
            text-align: center;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            display: block;
            cursor: pointer;
            border: none;
            transition: opacity 0.2s;
        }
        .bottom-btn:active { opacity: 0.8; }
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
        /* Wallet buttons */
        .wallet-buttons {
            display: flex;
            gap: 10px;
            max-width: 400px;
            margin: 10px auto 0;
            flex-direction: row;
        }
        .wallet-buttons .wallet-btn {
            flex: 1;
            padding: 11px 14px;
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
            transition: opacity 0.2s;
        }
        .wallet-buttons .wallet-btn:active { opacity: 0.8; }
        .wallet-buttons .wallet-btn.google {
            background: #fff;
            color: #1f1f1f;
            border: 1px solid #dadce0;
        }
        .wallet-buttons .wallet-btn svg { flex-shrink: 0; }
        .wallet-buttons.order-google-first .wallet-btn.apple  { order: 2; }
        .wallet-buttons.order-google-first .wallet-btn.google { order: 1; }

        /* Footer */
        .page-footer {
            text-align: center;
            margin-top: 24px;
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
            transition: all 0.3s;
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
        /* Appointment widget — inherits light/dark theme */
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
        .lang-switcher {
            position: absolute;
            top: 12px;
            <?php echo $isRtl ? 'left' : 'right'; ?>: 12px;
            display: flex;
            gap: 4px;
            font-size: 12px;
            font-weight: 600;
            background: rgba(0,0,0,0.06);
            padding: 4px 6px;
            border-radius: 999px;
            backdrop-filter: blur(8px);
            z-index: 50;
        }
        .lang-switcher a {
            text-decoration: none;
            padding: 2px 8px;
            border-radius: 999px;
            color: <?php echo $isDarkPage ? '#bbb' : '#555'; ?>;
            transition: all 0.15s;
        }
        .lang-switcher a.active {
            background: <?php echo $isDarkPage ? '#fff' : '#1a1a2e'; ?>;
            color: <?php echo $isDarkPage ? '#1a1a2e' : '#fff'; ?>;
        }
    </style>
</head>
<body>
    <!-- Language switcher -->
    <nav class="lang-switcher" aria-label="Language">
        <a href="<?php echo $switchEnUrl; ?>" class="<?php echo $locale === 'en' ? 'active' : ''; ?>" hreflang="en">EN</a>
        <a href="<?php echo $switchArUrl; ?>" class="<?php echo $locale === 'ar' ? 'active' : ''; ?>" hreflang="ar">عربي</a>
    </nav>
    <div class="page-container">
        <!-- Company Logo -->
        <?php if ($logoPath): ?>
        <div class="company-logo">
            <img src="<?php echo htmlspecialchars($logoPath); ?>" alt="<?php echo htmlspecialchars($companyName); ?>">
        </div>
        <?php endif; ?>

        <!-- Flippable Card -->
        <?php if ($frontImage): ?>
        <div class="card-flip-container" id="cardFlip">
            <div class="card-flip-inner" id="cardInner">
                <div class="card-face">
                    <img src="<?php echo htmlspecialchars($frontImage); ?>" alt="Card Front" loading="lazy">
                </div>
                <?php if ($backImage): ?>
                <div class="card-face card-back-face">
                    <img src="<?php echo htmlspecialchars($backImage); ?>" alt="Card Back" loading="lazy">
                </div>
                <?php endif; ?>
            </div>
            <?php if ($backImage): ?>
            <div class="tap-hint" id="tapHint">Tap card to flip</div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Employee Info -->
        <div class="employee-info">
            <div class="employee-name"><?php echo htmlspecialchars($name); ?></div>
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
            <a href="<?php echo htmlspecialchars($cardClickUrl($mobile ? 'click_mobile' : 'click_phone', 'tel:' . ($mobile ?: $phone))); ?>" class="action-btn btn-call">Call</a>
            <?php endif; ?>

            <?php if ($waPhone): ?>
            <a href="<?php echo htmlspecialchars($cardClickUrl('click_whatsapp', 'https://api.whatsapp.com/send?phone=' . $waPhone)); ?>" class="action-btn btn-whatsapp" target="_blank" rel="noopener">WhatsApp</a>
            <?php endif; ?>

            <?php if ($email): ?>
            <a href="<?php echo htmlspecialchars($cardClickUrl('click_email', 'mailto:' . $email)); ?>" class="action-btn btn-email">Email</a>
            <?php endif; ?>
        </div>

        <!-- Contact Details -->
        <div class="contact-card">
            <?php if ($phone): ?>
            <a href="<?php echo htmlspecialchars($cardClickUrl('click_phone', 'tel:' . $phone)); ?>" class="contact-row">
                <span class="contact-icon">&#128222;</span>
                <span class="contact-value"><?php echo htmlspecialchars($phone); ?></span>
            </a>
            <?php endif; ?>

            <?php if ($mobile && $mobile !== $phone): ?>
            <a href="<?php echo htmlspecialchars($cardClickUrl('click_mobile', 'tel:' . $mobile)); ?>" class="contact-row">
                <span class="contact-icon">&#128241;</span>
                <span class="contact-value"><?php echo htmlspecialchars($mobile); ?></span>
            </a>
            <?php endif; ?>

            <?php if ($email): ?>
            <a href="<?php echo htmlspecialchars($cardClickUrl('click_email', 'mailto:' . $email)); ?>" class="contact-row">
                <span class="contact-icon">&#9993;</span>
                <span class="contact-value"><?php echo htmlspecialchars($email); ?></span>
            </a>
            <?php endif; ?>

            <?php if ($website): ?>
            <?php $__webDest = strpos($website, 'http') === 0 ? $website : 'https://' . $website; ?>
            <a href="<?php echo htmlspecialchars($cardClickUrl('click_website', $__webDest)); ?>" class="contact-row" target="_blank" rel="noopener">
                <span class="contact-icon">&#127760;</span>
                <span class="contact-value"><?php echo htmlspecialchars($website); ?></span>
            </a>
            <?php endif; ?>

            <?php if ($address): ?>
            <a href="<?php echo htmlspecialchars($cardClickUrl('click_map', 'https://maps.google.com/?q=' . urlencode($address))); ?>" class="contact-row" target="_blank" rel="noopener">
                <span class="contact-icon">&#128205;</span>
                <span class="contact-value"><?php echo htmlspecialchars($address); ?></span>
            </a>
            <?php endif; ?>
        </div>

        <!-- Save & Share -->
        <div class="bottom-buttons">
            <?php if ($email): ?>
            <a href="<?php echo htmlspecialchars($cardClickUrl('save_contact', $vcfUrl)); ?>" class="bottom-btn btn-save" download>Save Contact</a>
            <?php endif; ?>
            <button class="bottom-btn btn-share" onclick="shareCard()">Share</button>
        </div>

        <!-- Public Card Sections -->
        <?php foreach ($sectionOrder as $__sec): ?>
            <?php if ($__sec === 'bio' && !empty($sectionMaster['bio_enabled']) && !empty($bioText)): ?>
                <div class="card-section">
                    <h3>About</h3>
                    <div class="section-bio"><?php echo CardSections::renderBioHtml($bioText); ?></div>
                </div>
            <?php elseif ($__sec === 'services' && !empty($sectionMaster['services_enabled']) && !empty($sectionServices)): ?>
                <div class="card-section">
                    <h3>Services</h3>
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
                    <h3>Gallery</h3>
                    <div class="gallery-grid">
                        <?php foreach ($sectionGallery as $img): ?>
                            <img src="<?php echo htmlspecialchars(cardifyAssetUrl($img['file_path'])); ?>" alt="<?php echo htmlspecialchars($img['caption'] ?? ''); ?>" loading="lazy" onclick="window.open(this.src,'_blank')">
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php elseif ($__sec === 'testimonials' && !empty($sectionMaster['testimonials_enabled'])): ?>
                <div class="card-section">
                    <h3>Testimonials</h3>
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
                        <div style="font-size:13px; opacity:0.65; padding:8px 0 12px;">Be the first to leave a testimonial.</div>
                    <?php endif; ?>

                    <button type="button" class="testimonial-toggle" id="testimonialToggle" onclick="(function(b){var f=document.getElementById('testimonialFormWrap');var open=f.style.display==='block';f.style.display=open?'none':'block';b.textContent=open?'Leave a testimonial':'Cancel';})(this);">Leave a testimonial</button>
                    <div id="testimonialFormWrap" style="display:none; margin-top:12px;">
                        <form id="testimonialForm" class="lead-form" enctype="multipart/form-data" autocomplete="off">
                            <input type="hidden" name="employee_id" value="<?php echo htmlspecialchars($employee['id']); ?>">
                            <div class="hp" style="position:absolute;left:-10000px;"><label>Website<input type="text" name="website_url" tabindex="-1" autocomplete="off"></label></div>
                            <div class="lead-error" id="testimonialError" style="display:none;"></div>
                            <label>Your name<input type="text" name="name" required maxlength="255"></label>
                            <label>Email (optional)<input type="email" name="email" maxlength="255"></label>
                            <label>Rating
                                <div class="star-picker" id="starPicker" role="radiogroup" aria-label="Rating">
                                    <input type="hidden" name="rating" id="ratingInput" value="">
                                    <?php for($i=1;$i<=5;$i++): ?>
                                    <span class="star" data-val="<?php echo $i; ?>" role="radio" aria-checked="false" tabindex="0">&#9733;</span>
                                    <?php endfor; ?>
                                </div>
                            </label>
                            <label>Your testimonial<textarea name="quote" required maxlength="2000"></textarea></label>
                            <label>Photo (optional)<input type="file" name="photo" accept="image/jpeg,image/png,image/webp"></label>
                            <button type="submit" id="testimonialSubmit">Submit for review</button>
                        </form>
                        <div class="lead-success" id="testimonialSuccess" style="display:none;">Thanks! Your testimonial is pending review.</div>
                    </div>
                </div>
            <?php elseif ($__sec === 'offers' && !empty($sectionMaster['offers_enabled']) && !empty($sectionOffers)): ?>
                <div class="card-section">
                    <h3>Offers</h3>
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
                                    <a href="/api/offer/redeem.php?oid=<?php echo urlencode($offer['id']); ?>&amp;eid=<?php echo urlencode($employee['id']); ?>"
                                       class="offer-redeem-btn"
                                       style="background: <?php echo htmlspecialchars($offer['badge_color'] ?: '#009bc1'); ?>;"
                                       rel="nofollow">Redeem</a>
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
            <?php elseif ($__sec === 'lead_form' && !empty($sectionMaster['lead_form_enabled'])): ?>
                <div class="card-section">
                    <h3>Get in Touch</h3>
                    <form class="lead-form" id="leadForm" autocomplete="off">
                        <input type="hidden" name="employee_id" value="<?php echo htmlspecialchars($employee['id']); ?>">
                        <div class="hp"><label>Website<input type="text" name="website_url" tabindex="-1" autocomplete="off"></label></div>
                        <div class="lead-error" id="leadError" style="display:none;"></div>
                        <label>Your name<input type="text" name="name" required maxlength="255"></label>
                        <label>Email<input type="email" name="email" maxlength="255"></label>
                        <label>Phone<input type="tel" name="phone" maxlength="50"></label>
                        <label>Message<textarea name="message" maxlength="4000"></textarea></label>
                        <button type="submit" id="leadSubmit">Send</button>
                    </form>
                    <div class="lead-success" id="leadSuccess" style="display:none;">Thanks! Your message has been sent.</div>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>

        <?php if ($apptEnabled): ?>
        <!-- Appointment Booking Widget -->
        <div class="card-section" id="apptSection">
            <h3>Book a meeting</h3>
            <div id="apptStep1">
                <label class="appt-label">Choose a date</label>
                <input type="date" id="apptDate" class="appt-input">
                <div id="apptSlots" class="appt-slots"></div>
                <div id="apptSlotsEmpty" class="appt-empty">No slots available on this day.</div>
            </div>
            <form id="apptForm" style="display:none;margin-top:12px;" autocomplete="off">
                <div id="apptChosen" class="appt-chosen"></div>
                <input type="hidden" name="employee_id" value="<?php echo htmlspecialchars($employee['id']); ?>">
                <input type="hidden" name="slot_start" id="apptSlotStart">
                <div class="hp" style="position:absolute;left:-9999px;"><label>Website<input type="text" name="website_url" tabindex="-1" autocomplete="off"></label></div>
                <div id="apptError" style="display:none;color:#ef4444;font-size:13px;margin-bottom:8px;"></div>
                <input type="text" name="name" placeholder="Your name" required maxlength="255" class="appt-input">
                <input type="email" name="email" placeholder="Email" maxlength="255" class="appt-input">
                <input type="tel" name="phone" placeholder="Phone" maxlength="50" class="appt-input">
                <textarea name="notes" placeholder="Notes (optional)" maxlength="4000" rows="3" class="appt-textarea"></textarea>
                <div style="display:flex;gap:8px;">
                    <button type="button" id="apptBack" class="appt-back-btn">Back</button>
                    <button type="submit" id="apptSubmit" class="appt-submit-btn">Confirm booking</button>
                </div>
            </form>
            <div id="apptSuccess" style="display:none;text-align:center;padding:18px;">
                <div style="font-size:32px;margin-bottom:6px;color:<?php echo htmlspecialchars($accentColor); ?>;">&#10003;</div>
                <div style="font-weight:600;">Request sent!</div>
                <div class="appt-success-msg">You'll get a confirmation email shortly.</div>
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
                slotsEl.innerHTML = '<div style="grid-column:1/-1;text-align:center;opacity:0.7;font-size:13px;padding:8px 0;">Loading...</div>';
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
            <a href="<?php echo htmlspecialchars($appleWalletUrl); ?>" class="wallet-btn apple" aria-label="Add to Apple Wallet">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M17.05 12.54c-.03-2.9 2.37-4.3 2.48-4.37-1.36-1.98-3.47-2.26-4.22-2.29-1.8-.18-3.51 1.06-4.43 1.06-.93 0-2.33-1.03-3.83-1-1.97.03-3.79 1.15-4.8 2.91-2.05 3.56-.52 8.82 1.48 11.71.98 1.41 2.15 2.99 3.68 2.94 1.48-.06 2.04-.96 3.83-.96s2.3.96 3.86.93c1.59-.03 2.6-1.44 3.57-2.86 1.13-1.64 1.59-3.24 1.62-3.32-.04-.02-3.11-1.2-3.14-4.75zM14.12 3.79c.8-.97 1.34-2.31 1.19-3.65-1.15.05-2.55.77-3.37 1.73-.74.85-1.39 2.21-1.22 3.53 1.29.1 2.59-.65 3.4-1.61z"/></svg>
                Apple Wallet
            </a>
            <?php endif; ?>
            <?php if ($googleWalletEnabled): ?>
            <a href="<?php echo htmlspecialchars($googleWalletUrl); ?>" class="wallet-btn google" aria-label="Add to Google Wallet">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="6" width="20" height="13" rx="2"/><path d="M2 10h20"/><path d="M6 15h4"/></svg>
                Google Wallet
            </a>
            <?php endif; ?>
        </div>
        <script>
            // UA detection: Android → Google first, iOS/macOS → Apple first, desktop → as-is
            (function () {
                var w = document.getElementById('walletButtons');
                if (!w) return;
                var ua = navigator.userAgent || '';
                var isAndroid = /Android/i.test(ua);
                var isAppleOS = /iPad|iPhone|iPod|Macintosh/i.test(ua);
                if (isAndroid && !isAppleOS) {
                    w.classList.add('order-google-first');
                }
            })();
        </script>
        <?php endif; ?>

        <!-- Powered by Cardify -->
        <div style="text-align: center; padding: 24px 0 16px;">
            <a href="https://cardify.om?ref=digital_card&utm_source=card&utm_medium=qr&utm_campaign=powered_by"
               style="display: inline-flex; align-items: center; gap: 6px; color: #fff; text-decoration: none; font-size: 12px; font-family: -apple-system, system-ui, sans-serif; letter-spacing: 0.3px; background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.15); border-radius: 20px; padding: 6px 14px;"
               target="_blank" rel="noopener">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="opacity:0.7"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/></svg>
                <span style="opacity:0.7;">Digital card by</span> <strong style="opacity:1;">Cardify.om</strong>
            </a>
        </div>
    </div>

    <div class="copy-toast" id="copyToast">Link copied!</div>

    <script>
        // Card flip
        const cardFlip = document.getElementById('cardFlip');
        const cardInner = document.getElementById('cardInner');
        const tapHint = document.getElementById('tapHint');

        if (cardFlip && cardInner) {
            cardFlip.addEventListener('click', function() {
                cardInner.classList.toggle('flipped');
                if (tapHint) tapHint.style.opacity = '0';
            });
        }

        // Share
        function shareCard() {
            const shareData = {
                title: <?php echo json_encode($name . ' - ' . $companyName); ?>,
                text: <?php echo json_encode($name . ' - ' . $position . ' at ' . $companyName); ?>,
                url: window.location.href
            };

            if (navigator.share) {
                navigator.share(shareData).catch(() => {});
            } else {
                // Fallback: copy to clipboard
                navigator.clipboard.writeText(window.location.href).then(() => {
                    const toast = document.getElementById('copyToast');
                    toast.classList.add('show');
                    setTimeout(() => toast.classList.remove('show'), 2000);
                }).catch(() => {
                    // Final fallback
                    const input = document.createElement('input');
                    input.value = window.location.href;
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

<script type="application/ld+json">
<?= json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'Person',
    'name' => $employee['name_en'] ?? $employee['name'] ?? '',
    'jobTitle' => $employee['position_en'] ?? $employee['position'] ?? '',
    'worksFor' => [
        '@type' => 'Organization',
        'name' => $company['name_en'] ?? $company['name'] ?? ''
    ],
    'email' => $employee['email'] ?? '',
    'telephone' => $employee['phone'] ?? $employee['mobile'] ?? '',
    'url' => 'https://cardify.om/' . ($company['slug'] ?? '') . '/card/' . ($employee['id'] ?? '')
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?>
</script>
</body>
</html>
