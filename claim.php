<?php
/**
 * /claim — viral-footer landing page.
 *
 * Lean high-conversion page linked from every public digital card's
 * "Made with Cardify" footer. Single CTA: drop a phone number, get a
 * WhatsApp link to create your card.
 *
 * - Tracks each visit as a `viral_footer_view` card_event (when utm_content
 *   carries the source employee_id so we can attribute the loop).
 * - Form POSTs back to the same page. On success, writes into
 *   `cardify_signup_leads` (migration 065) and — if possible — fires a
 *   WhatsApp message via the existing WhatsApp helper. Falls back to a
 *   friendly "we'll WhatsApp you" confirmation if the gateway is silent.
 * - Rate-limited per-IP via the shared RateLimiter so bots can't flood
 *   the lead table.
 *
 * Design: mirrors the digital card's aesthetic — BHD blue, soft neutrals,
 * tasteful — NOT an ad. The viral footer is the leverage; this page is
 * the quickest possible conversion.
 */

ob_start();

set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    require_once __DIR__ . '/config.php';
    require_once INCLUDES_DIR . '/CardAnalytics.php';
    require_once INCLUDES_DIR . '/functions.php';
    if (file_exists(INCLUDES_DIR . '/RateLimiter.php')) {
        require_once INCLUDES_DIR . '/RateLimiter.php';
    }
} catch (Throwable $e) {
    while (ob_get_level()) { ob_end_clean(); }
    http_response_code(500);
    echo 'Error loading /claim.';
    error_log('claim.php bootstrap: ' . $e->getMessage());
    exit;
}

// --------------------------------------------------------------------
// Locale — reuse cardify_card_lang cookie so the footer→claim handoff
// keeps the visitor in their language.
// --------------------------------------------------------------------
$locale = 'en';
if (!empty($_GET['lang']) && in_array($_GET['lang'], ['en', 'ar'], true)) {
    $locale = $_GET['lang'];
} elseif (!empty($_COOKIE['cardify_card_lang']) && in_array($_COOKIE['cardify_card_lang'], ['en', 'ar'], true)) {
    $locale = $_COOKIE['cardify_card_lang'];
}
$isRtl = $locale === 'ar';

// --------------------------------------------------------------------
// UTM / attribution — attribute the claim back to the source card when
// the viral footer carried an employee_id through utm_content.
// --------------------------------------------------------------------
$utmSource   = substr((string)($_GET['utm_source']   ?? ''), 0, 64);
$utmMedium   = substr((string)($_GET['utm_medium']   ?? ''), 0, 64);
$utmCampaign = substr((string)($_GET['utm_campaign'] ?? ''), 0, 64);
$utmContent  = substr((string)($_GET['utm_content']  ?? ''), 0, 128);

// --------------------------------------------------------------------
// Track visit as a viral_footer_view event (non-fatal). Only when we
// have a source employee_id to attribute to.
// --------------------------------------------------------------------
if ($utmContent !== '' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $sourceEmp = findEmployeeById($utmContent);
        if ($sourceEmp && !empty($sourceEmp['company_id'])) {
            CardAnalytics::log(
                $sourceEmp['id'],
                $sourceEmp['company_id'],
                'viral_footer_view',
                '/claim'
            );
        }
    } catch (Throwable $e) {
        // silent — analytics never blocks the page
        error_log('claim.php view-log: ' . $e->getMessage());
    }
}

// --------------------------------------------------------------------
// POST handler — capture phone/email lead.
// --------------------------------------------------------------------
$formError   = '';
$formSuccess = false;
$submittedPhone = '';
$submittedEmail = '';

// Codex round-3 finding #3: E2E test runs hit this endpoint from CI. Tag those
// submissions so we can (a) flag them as test leads in the DB, (b) short-circuit
// any WA side-effect if a future revision ever re-introduces one.
$isE2ETest = !empty($_SERVER['HTTP_X_E2E_TEST']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Real client IP — CF takes precedence but we must NOT trust X-Forwarded-For
    // from arbitrary clients because it's the key for our rate limiter and
    // forging it lets an attacker bypass the per-IP cap. Only trust XFF when
    // we're behind a known proxy (CF or local reverse proxy on 127.0.0.1).
    $remote = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $ip = $remote;
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR']) && in_array($remote, ['127.0.0.1', '::1'], true)) {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
    }
    if (strpos($ip, ',') !== false) { $ip = trim(explode(',', $ip)[0]); }

    // Honeypot anti-spam — a bot will fill this; a human never sees it.
    if (!empty($_POST['website_url'])) {
        $formSuccess = true; // silently swallow
    } else {
        // Rate limit: 5 signups per IP per hour.
        $allowed = true;
        if (class_exists('RateLimiter')) {
            $allowed = RateLimiter::check('claim_signup', $ip, 5, 3600);
        }
        if (!$allowed) {
            http_response_code(429);
            $formError = $isRtl ? 'محاولات كثيرة. حاول لاحقاً.' : 'Too many attempts. Please try again later.';
        } else {
            $phoneRaw = trim((string)($_POST['phone'] ?? ''));
            $emailRaw = trim((string)($_POST['email'] ?? ''));
            $submittedPhone = $phoneRaw;
            $submittedEmail = $emailRaw;

            // Normalize phone: keep + and digits only.
            $phone = preg_replace('/[^\d+]/', '', $phoneRaw);
            // If user typed a local OM number (8-digit), prefix +968.
            if ($phone !== '' && $phone[0] !== '+' && strlen($phone) === 8) {
                $phone = '+968' . $phone;
            }
            // Prefix + if all digits and looks international (>=10 digits)
            if ($phone !== '' && $phone[0] !== '+' && preg_match('/^\d{10,15}$/', $phone)) {
                $phone = '+' . $phone;
            }

            $email = filter_var($emailRaw, FILTER_VALIDATE_EMAIL) ? strtolower($emailRaw) : '';

            if ($phone === '' && $email === '') {
                $formError = $isRtl
                    ? 'الرجاء إدخال رقم جوال أو بريد إلكتروني.'
                    : 'Please enter a phone number or email.';
            } elseif ($phone !== '' && !preg_match('/^\+\d{8,15}$/', $phone)) {
                $formError = $isRtl
                    ? 'رقم الجوال غير صحيح.'
                    : 'That phone number doesn\'t look right.';
            } else {
                // Insert lead row.
                try {
                    $db = Database::getInstance();
                    // Codex round-3 finding #1: NEVER send WhatsApp directly
                    // from /claim — that would let any visitor use our Dardasha
                    // line as a spam cannon (rate limit bypassable via XFF).
                    //
                    // Capture the lead into cardify_signup_leads and let Ali
                    // triage from /admin/growth.php, which dispatches WA via the
                    // hardened bulk-claim admin flow.
                    $db->insert('cardify_signup_leads', [
                        'id'              => generateUUID(),
                        'phone'           => $phone !== '' ? $phone : null,
                        'email'           => $email !== '' ? $email : null,
                        'source'          => $isE2ETest ? 'e2e_test' : 'viral_footer',
                        'utm_source'      => $utmSource !== ''   ? $utmSource   : null,
                        'utm_medium'      => $utmMedium !== ''   ? $utmMedium   : null,
                        'utm_campaign'    => $utmCampaign !== '' ? $utmCampaign : null,
                        'utm_content'     => $utmContent !== ''  ? $utmContent  : null,
                        'ref_employee_id' => $utmContent !== ''  ? $utmContent  : null,
                        'locale'          => $locale,
                        'ip_address'      => substr($ip, 0, 64),
                        'user_agent'      => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512),
                        'status'          => $isE2ETest ? 'test' : 'new',
                    ]);

                    $formSuccess = true;
                } catch (Throwable $e) {
                    error_log('claim.php insert lead: ' . $e->getMessage());
                    $formError = $isRtl
                        ? 'حدث خطأ. حاول مرة أخرى.'
                        : 'Something went wrong. Please try again.';
                }
            }
        }
    }
}

ob_end_clean();

// --------------------------------------------------------------------
// Copy bundle — EN / AR.
// --------------------------------------------------------------------
$t = $isRtl
    ? [
        'title'        => 'بطاقة أعمال رقمية مع Cardify',
        'headline'     => 'بطاقتك الرقمية · خلال 60 ثانية',
        'subhead'      => 'جاهزة لـ NFC · دائماً محدثة · مجانية للأبد',
        'phone_label'  => 'رقم الجوال',
        'phone_ph'     => '9999 9999',
        'or'           => 'أو',
        'email_label'  => 'البريد الإلكتروني',
        'email_ph'     => 'you@example.com',
        'cta'          => 'أرسل لي الرابط',
        'success_h'    => 'شكراً!',
        'success_b'    => 'سنتواصل معك عبر واتساب خلال 24 ساعة.',
        'see_example'  => 'شاهد مثال',
        'made_with'    => 'مصنوع بـ Cardify · أنشئ بطاقتك مجاناً',
    ]
    : [
        'title'        => 'Your digital business card — Cardify',
        'headline'     => 'Your digital business card · 60 seconds',
        'subhead'      => 'NFC-ready · always up-to-date · free forever',
        'phone_label'  => 'Phone number',
        'phone_ph'     => '9999 9999',
        'or'           => 'or',
        'email_label'  => 'Email',
        'email_ph'     => 'you@example.com',
        'cta'          => 'Send me the link',
        'success_h'    => 'Thanks!',
        'success_b'    => 'We\'ll WhatsApp you within 24 hours with a link to create your card.',
        'see_example'  => 'See an example card',
        'made_with'    => 'Made with Cardify · Create yours free',
    ];

?><!DOCTYPE html>
<html lang="<?php echo $isRtl ? 'ar' : 'en'; ?>"<?php echo $isRtl ? ' dir="rtl"' : ''; ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?php echo htmlspecialchars($t['subhead']); ?>">
    <title><?php echo htmlspecialchars($t['title']); ?></title>
    <link rel="icon" href="/favicon.ico">
    <?php if ($isRtl): ?>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Arabic:wght@400;500;600;700&display=swap" rel="stylesheet">
    <?php endif; ?>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --brand-blue:   #009bc1;
            --brand-yellow: #fb0;
            --brand-purple: #824598;
            --brand-teal:   #45c0ba;
            --ink:          #141421;
            --muted:        #5a5a6e;
            --line:         rgba(20,20,33,0.08);
            --bg-soft:      #f6f8fb;
        }
        html, body { min-height: 100%; }
        body {
            font-family: <?php echo $isRtl ? "'Noto Sans Arabic', " : ''; ?>-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
            color: var(--ink);
            background:
                radial-gradient(1200px 600px at 50% -10%, rgba(0,155,193,0.08), transparent 60%),
                linear-gradient(180deg, #fff 0%, var(--bg-soft) 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            -webkit-font-smoothing: antialiased;
        }
        .card {
            width: 100%;
            max-width: 440px;
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 20px;
            box-shadow: 0 30px 60px -20px rgba(10,20,40,0.12), 0 2px 6px rgba(10,20,40,0.04);
            padding: 32px 28px 24px;
        }
        .brand {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            color: var(--brand-blue);
            font-size: 14px;
            font-weight: 600;
            letter-spacing: 0.2px;
            margin-bottom: 20px;
        }
        .brand svg { display: block; }
        h1 {
            font-size: 26px;
            line-height: 1.25;
            font-weight: 700;
            letter-spacing: -0.3px;
            margin-bottom: 8px;
            text-align: center;
        }
        .sub {
            color: var(--muted);
            font-size: 14px;
            text-align: center;
            margin-bottom: 24px;
        }
        .chips {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 6px;
            margin-bottom: 24px;
        }
        .chip {
            font-size: 11px;
            color: var(--muted);
            background: var(--bg-soft);
            border: 1px solid var(--line);
            padding: 4px 10px;
            border-radius: 999px;
        }
        form label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            color: var(--muted);
            margin-bottom: 6px;
            letter-spacing: 0.3px;
            text-transform: uppercase;
        }
        .field {
            display: flex;
            align-items: stretch;
            border: 1.5px solid var(--line);
            border-radius: 12px;
            overflow: hidden;
            background: #fff;
            transition: border-color 0.15s, box-shadow 0.15s;
        }
        .field:focus-within {
            border-color: var(--brand-blue);
            box-shadow: 0 0 0 4px rgba(0,155,193,0.12);
        }
        .field .prefix {
            display: inline-flex;
            align-items: center;
            padding: 0 12px;
            background: var(--bg-soft);
            color: var(--muted);
            font-size: 14px;
            font-weight: 600;
            border-<?php echo $isRtl ? 'left' : 'right'; ?>: 1px solid var(--line);
        }
        .field input {
            flex: 1;
            border: 0;
            outline: 0;
            font-size: 16px; /* >=16 to kill iOS zoom */
            padding: 12px 14px;
            color: var(--ink);
            background: transparent;
            font-family: inherit;
            min-width: 0;
        }
        .field input::placeholder { color: #a0a0b0; }
        .or-divider {
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--muted);
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin: 14px 0;
        }
        .or-divider::before,
        .or-divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: var(--line);
        }
        .submit {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            min-height: 48px;
            margin-top: 16px;
            padding: 0 18px;
            border: 0;
            border-radius: 12px;
            background: var(--brand-blue);
            color: #fff;
            font-family: inherit;
            font-size: 15px;
            font-weight: 600;
            letter-spacing: 0.2px;
            cursor: pointer;
            transition: background 0.15s, transform 0.08s;
            -webkit-tap-highlight-color: transparent;
        }
        .submit:hover { background: #007a99; }
        .submit:active { transform: scale(0.985); }
        .error {
            margin-top: 12px;
            color: #b42318;
            background: #fee4e2;
            border: 1px solid #fecdca;
            padding: 10px 12px;
            border-radius: 10px;
            font-size: 13px;
            text-align: center;
        }
        .success {
            text-align: center;
            padding: 8px 0 4px;
        }
        .success .check {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: rgba(69,192,186,0.15);
            color: var(--brand-teal);
            margin-bottom: 12px;
        }
        .success h2 {
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 6px;
        }
        .success p {
            color: var(--muted);
            font-size: 14px;
            line-height: 1.5;
        }
        .footer {
            margin-top: 22px;
            padding-top: 18px;
            border-top: 1px solid var(--line);
            text-align: center;
            font-size: 12px;
            color: var(--muted);
        }
        .footer a { color: var(--brand-blue); text-decoration: none; font-weight: 500; }
        .footer a:hover { text-decoration: underline; }
        .hp { position: absolute; left: -10000px; width: 1px; height: 1px; overflow: hidden; }
        .lang-switcher {
            position: fixed;
            top: 14px;
            <?php echo $isRtl ? 'left' : 'right'; ?>: 14px;
            background: rgba(255,255,255,0.85);
            backdrop-filter: blur(8px);
            border: 1px solid var(--line);
            border-radius: 999px;
            padding: 4px;
            display: inline-flex;
            gap: 2px;
            font-size: 12px;
            font-weight: 600;
        }
        .lang-switcher a {
            padding: 4px 10px;
            border-radius: 999px;
            color: var(--muted);
            text-decoration: none;
        }
        .lang-switcher a.active { background: var(--brand-blue); color: #fff; }
        @media (max-width: 420px) {
            .card { padding: 26px 20px 20px; border-radius: 16px; }
            h1 { font-size: 22px; }
        }
    </style>
</head>
<body>
    <nav class="lang-switcher" aria-label="Language">
        <a href="?lang=en<?php echo $utmContent ? '&utm_content=' . urlencode($utmContent) : ''; ?>" class="<?php echo $locale === 'en' ? 'active' : ''; ?>" hreflang="en">EN</a>
        <a href="?lang=ar<?php echo $utmContent ? '&utm_content=' . urlencode($utmContent) : ''; ?>" class="<?php echo $locale === 'ar' ? 'active' : ''; ?>" hreflang="ar">عربي</a>
    </nav>

    <main class="card">
        <div class="brand" aria-label="Cardify">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <rect x="2" y="6" width="20" height="13" rx="2.5"/>
                <path d="M6 11h6"/>
                <path d="M6 14h4"/>
                <circle cx="17" cy="13" r="1.6" fill="currentColor" stroke="none"/>
            </svg>
            <span>Cardify</span>
        </div>

        <?php if ($formSuccess): ?>
            <div class="success">
                <div class="check" aria-hidden="true">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="20 6 9 17 4 12"/>
                    </svg>
                </div>
                <h2><?php echo htmlspecialchars($t['success_h']); ?></h2>
                <p><?php echo htmlspecialchars($t['success_b']); ?></p>
            </div>
        <?php else: ?>
            <h1><?php echo htmlspecialchars($t['headline']); ?></h1>
            <p class="sub"><?php echo htmlspecialchars($t['subhead']); ?></p>

            <div class="chips" aria-hidden="true">
                <span class="chip">NFC</span>
                <span class="chip">QR</span>
                <span class="chip">Apple &amp; Google Wallet</span>
                <span class="chip">EN · عربي</span>
            </div>

            <form method="post" autocomplete="on" novalidate>
                <div class="hp" aria-hidden="true">
                    <label>Website<input type="text" name="website_url" tabindex="-1" autocomplete="off"></label>
                </div>

                <label for="phone"><?php echo htmlspecialchars($t['phone_label']); ?></label>
                <div class="field">
                    <span class="prefix" dir="ltr">+968</span>
                    <input type="tel"
                           id="phone"
                           name="phone"
                           inputmode="tel"
                           autocomplete="tel"
                           placeholder="<?php echo htmlspecialchars($t['phone_ph']); ?>"
                           value="<?php echo htmlspecialchars($submittedPhone); ?>"
                           maxlength="20">
                </div>

                <div class="or-divider"><?php echo htmlspecialchars($t['or']); ?></div>

                <label for="email"><?php echo htmlspecialchars($t['email_label']); ?></label>
                <div class="field">
                    <input type="email"
                           id="email"
                           name="email"
                           autocomplete="email"
                           placeholder="<?php echo htmlspecialchars($t['email_ph']); ?>"
                           value="<?php echo htmlspecialchars($submittedEmail); ?>"
                           maxlength="255">
                </div>

                <?php if ($formError !== ''): ?>
                    <div class="error" role="alert"><?php echo htmlspecialchars($formError); ?></div>
                <?php endif; ?>

                <button type="submit" class="submit"><?php echo htmlspecialchars($t['cta']); ?></button>
            </form>
        <?php endif; ?>

        <div class="footer">
            <?php echo htmlspecialchars($t['made_with']); ?>
            &nbsp;·&nbsp;
            <a href="/">cardify.om</a>
        </div>
    </main>
</body>
</html>
