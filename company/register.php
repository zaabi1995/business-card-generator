<?php
/**
 * Cardify - Company Registration
 * Features domain-based company creation:
 * - Business domains auto-set slug from domain
 * - Common domains (gmail, etc.) require manual company name
 * - If company with domain exists, user is added as employee
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/Mailer.php';
require_once INCLUDES_DIR . '/Referral.php';
require_once INCLUDES_DIR . '/ScanClaimTicket.php';

// Redirect if already logged in
if (Auth::isLoggedIn()) {
    header('Location: ' . getBasePath() . 'admin/');
    exit;
}

$error = null;
$info = null;
// Set when the submitted email domain is already claimed by another
// tenant. The submission is NOT written in that case: the user is asked
// which of the two things they meant, because the two outcomes are not
// interchangeable and only they know which one they want.
$needsDomainChoice = false;
$claimTicket = trim((string) ($_POST['claim_ticket'] ?? $_GET['claim_ticket'] ?? ''));
if ($claimTicket !== '') {
    header('Cache-Control: private, no-store');
    header('Referrer-Policy: no-referrer');
}
$claimPreview = null;
if ($claimTicket !== '') {
    try {
        $claimPreview = ScanClaimTicket::findValid(Database::getInstance(), $claimTicket);
    } catch (Throwable $e) {
        error_log('[register] claim ticket lookup failed: ' . $e->getMessage());
    }
}
$claimParsed = $claimPreview
    ? (json_decode((string) ($claimPreview['best_parsed'] ?? ''), true) ?: [])
    : [];
$prefillEmail = $claimPreview['email_primary'] ?? ($_GET['email'] ?? '');
$prefillName = $claimParsed['name_en'] ?? ($_GET['name'] ?? '');
if ($claimTicket !== '' && !$claimPreview) {
    $error = 'This claim verification has expired. Return to your card and request a new code.';
}
$isBusinessDomain = false;
$suggestedSlug = '';
$existingCompany = null;

// Capture referral source (e.g. ref=bhd from BHD landing page)
$refSource = $claimPreview ? 'scan_claim' : ($_GET['ref'] ?? null);
if ($refSource) {
    $_SESSION['pending_referral'] = preg_replace('/[^a-z0-9_\-]/i', '', $refSource);
}
$pendingReferral = $_SESSION['pending_referral'] ?? null;

// BHD-234: user-level referral code (distinct from `ref=` source tag). Accept
// both ?ref_code= and the r.php-captured session/cookie.
$incomingRefCode = $_GET['ref_code'] ?? null;
if ($incomingRefCode) {
    Referral::capturePending((string)$incomingRefCode);
}
$pendingRefCode = Referral::readPending();

// Check if prefilled email has business domain
if (!empty($prefillEmail) && isValidEmail($prefillEmail)) {
    $isBusinessDomain = isBusinessEmailDomain($prefillEmail);
    if ($isBusinessDomain) {
        $suggestedSlug = generateSlugFromEmail($prefillEmail);
        // Check if company with this domain already exists
        $domain = extractEmailDomain($prefillEmail);
        $existingCompany = findCompanyByDomain($domain);
    }
}

$brandName = defined('SITE_NAME') ? SITE_NAME : 'Cardify';
$pageTitle = t('register.page_title');
$htmlClass = 'h-full bg-white';
$bodyClass = 'h-full';
// A heredoc does not run PHP tags, so the nonce is interpolated as a value.
// SecurityHeaders is required here rather than relied on: this block is built
// BEFORE ui-header.php runs, so cspNonceAttr() would not exist yet and the
// script would ship with no nonce and be refused by the policy.
require_once INCLUDES_DIR . '/SecurityHeaders.php';
$cardifyNonce = cspNonceAttr();
$extraHead = <<<HTML
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/intl-tel-input@23.8.2/build/css/intlTelInput.css">
    <style>
        .form-input {
            display: block;
            width: 100%;
            border-radius: 0.5rem;
            background-color: #f9fafb;
            border: 1px solid #d1d5db;
            padding: 0.625rem 0.875rem;
            font-size: 0.875rem;
            color: #111827;
            outline: none;
            transition: all 0.15s ease;
        }
        .form-input:focus {
            border-color: #00718c;
            box-shadow: 0 0 0 3px rgba(0, 155, 193, 0.12);
        }
        .form-input::placeholder {
            color: #9ca3af;
        }
        .form-input:disabled {
            background-color: #f3f4f6;
            color: #6b7280;
            cursor: not-allowed;
        }
        .iti { width: 100%; display: block; }
        .iti__tel-input.form-input { padding-left: 3.5rem; }
    </style>
    <script{$cardifyNonce}>
        function checkEmailDomain() {
            const email = document.getElementById('admin_email').value;
            const slugField = document.getElementById('company_slug');
            const slugWrapper = document.getElementById('slug-wrapper');
            const domainInfo = document.getElementById('domain-info');
            
            // Common email domains
            const commonDomains = ['gmail.com', 'googlemail.com', 'hotmail.com', 'outlook.com', 
                'live.com', 'msn.com', 'yahoo.com', 'ymail.com', 'icloud.com', 'me.com', 
                'aol.com', 'protonmail.com', 'proton.me', 'mail.com', 'zoho.com'];
            
            if (email && email.includes('@')) {
                const domain = email.split('@')[1]?.toLowerCase();
                const isCommon = commonDomains.includes(domain);
                
                if (isCommon) {
                    slugField.disabled = false;
                    slugField.placeholder = 'your-company-name';
                    slugField.required = true;
                    domainInfo.innerHTML = '<i class="fa-solid fa-info-circle text-blue-500 mr-2"></i>Personal email detected. Please choose a unique company URL.';
                    domainInfo.className = 'mt-1.5 text-xs text-gray-600';
                } else {
                    // Business domain - auto-suggest slug
                    let slug = domain.replace(/\.(com|org|net|co|io|tech|app|dev|ai)$/i, '');
                    slug = slug.replace(/\.(co\.|com\.)?[a-z]{2}$/i, '');
                    slug = slug.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
                    
                    slugField.value = slug;
                    slugField.placeholder = slug;
                    domainInfo.innerHTML = '<i class="fa-solid fa-building text-green-500 mr-2"></i>Business domain detected. URL auto-set from your domain.';
                    domainInfo.className = 'mt-1.5 text-xs text-green-600';
                }
            }
        }
    </script>
HTML;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = t('register.err_invalid_request');
    } else {
    // Per-IP signup throttle: a public POST that creates a tenant + sends a
    // welcome email. Without this a bot could mass-create companies and spam
    // mail. 8/hour/IP is generous for shared-NAT offices but stops bulk abuse.
    // The slot is refunded below if the submission fails validation, so a user
    // fixing a typo and resubmitting is never penalised. (BHD loop audit iter 6.)
    require_once INCLUDES_DIR . '/RateLimiter.php';
    require_once INCLUDES_DIR . '/UrlSafety.php';
    $signupIp = getClientIp();
    $rateOk = RateLimiter::check('company_signup_ip', $signupIp, 8, 3600);
    if (!$rateOk) {
        $error = t('register.err_rate_limited');
    } else {
    $name = trim($_POST['company_name'] ?? '');
    $email = sanitizeEmail($_POST['admin_email'] ?? '');
    $password = $_POST['password'] ?? '';
    $customSlug = trim($_POST['company_slug'] ?? '');
    $userName = trim($_POST['user_name'] ?? '');
    // Phone capture: prefer the canonical E.164 string from intl-tel-input,
    // fall back to the raw `phone` field for non-JS clients. `phone_skipped=1`
    // means the user explicitly hit "Skip for now" in the widget, honour it
    // and ship an empty string so signup never blocks (per BHD-224 spec:
    // required-with-skip).
    $phoneSkipped = ($_POST['phone_skipped'] ?? '') === '1';
    $phoneRaw = $phoneSkipped ? '' : trim($_POST['phone_e164'] ?? $_POST['phone'] ?? '');
    $phone = '';
    if ($phoneRaw !== '') {
        require_once INCLUDES_DIR . '/WhatsApp.php';
        $digits = WhatsApp::normalizePhone($phoneRaw);
        // Require at least 8 digits (Oman local) after normalization.
        if (strlen($digits) >= 8) {
            $phone = '+' . $digits;
        }
    }

    // Validation
    require_once INCLUDES_DIR . '/Recaptcha.php';
    $captcha = Recaptcha::verify((string) ($_POST['recaptcha_token'] ?? ''), 'signup');
    if ($claimTicket !== '' && !$claimPreview) {
        $error = 'This claim verification has expired. Return to your card and request a new code.';
    } elseif (empty($captcha['ok'])) {
        $error = t('register.err_captcha');
    } elseif (empty($name)) {
        $error = t('register.err_company_required');
    } elseif (empty($email) || !isValidEmail($email)) {
        $error = t('register.err_email_invalid');
    } elseif (empty($password) || strlen($password) < 8) {
        $error = t('register.err_password_short');
    } else {
        // Check if email already exists
        $existsCheck = Auth::emailExists($email);
        if ($existsCheck['exists']) {
            $error = t('register.err_email_exists');
        } else {
            $claimRegistration = null;
            $claimDb = null;
            if ($claimTicket !== '') {
                try {
                    $claimDb = Database::getInstance();
                    $claimRegistration = ScanClaimTicket::lockForRegistration($claimDb, $claimTicket);
                    if (!$claimRegistration) {
                        $error = 'This claim verification has expired. Return to your card and request a new code.';
                    }
                } catch (Throwable $e) {
                    if ($claimDb instanceof Database) {
                        ScanClaimTicket::rollBackClaimTransaction($claimDb);
                    }
                    error_log('[register] claim ticket lock failed: ' . $e->getMessage());
                    $error = t('register.err_invalid_request');
                }
            }

            if (!$error) {
            // Determine slug based on email domain
            $isBusinessDomain = isBusinessEmailDomain($email);
            
            if ($isBusinessDomain && empty($customSlug)) {
                // Auto-generate slug from business domain
                $customSlug = generateSlugFromEmail($email);
            }
            
            // Check if company with this domain already exists (for business domains)
            if ($isBusinessDomain) {
                $domain = extractEmailDomain($email);
                $existingCompany = findCompanyByDomain($domain);
                
                // A match here means some existing tenant already claims this
                // email domain, either on companies.domain or just because its
                // admin signed up with an address at that domain (43 domains
                // claim signups that way today). This used to silently convert
                // a "create my company" submission into a pending join request
                // inside that other tenant: the company name and URL the user
                // typed were dropped on the floor, their password hash was
                // written into a company they had never heard of, and the reply
                // named that company to an anonymous submitter. Neither the
                // discard nor the disclosure is ours to make, so ask.
                $domainChoice = $_POST['domain_choice'] ?? '';
                if ($existingCompany && $domainChoice !== 'join' && $domainChoice !== 'create') {
                    $needsDomainChoice = true;
                } elseif ($existingCompany && $domainChoice === 'create') {
                    // They want their own company. Honour the name and slug they
                    // typed and fall through to the create branch below.
                    $existingCompany = null;
                }

                if ($existingCompany && !$needsDomainChoice) {
                    // Company exists - add user as employee instead
                    $employeeData = [
                        'id' => generateUUID(),
                        'name_en' => $userName ?: $name,
                        'email' => $email,
                        'password_hash' => password_hash($password, PASSWORD_BCRYPT),
                        'company_id' => $existingCompany['id'],
                        'status' => 'pending', // Requires admin approval
                        'created_at' => dbNow()
                    ];
                    
                    if ($phone !== '') {
                        $employeeData['phone'] = $phone;
                    }
                    try {
                        $empResult = addEmployee($employeeData, $existingCompany['id']);
                        if ($empResult['success'] ?? false) {
                            if ($claimRegistration && $claimDb instanceof Database) {
                                ScanClaimTicket::completeRegistration(
                                    $claimDb,
                                    $claimRegistration,
                                    (string) $existingCompany['id'],
                                    !empty($empResult['id']) ? (string) $empResult['id'] : null
                                );
                            }
                            // No company name here. The submitter has not
                            // proved control of the mailbox, so naming the
                            // organisation would confirm its existence to
                            // anyone who can guess an email domain.
                            $info = t('register.info_join_submitted');
                            // Don't redirect - show message
                        } else {
                            if ($claimDb instanceof Database) {
                                ScanClaimTicket::rollBackClaimTransaction($claimDb);
                            }
                            $error = t('register.err_join_failed');
                        }
                    } catch (Throwable $e) {
                        if ($claimDb instanceof Database) {
                            ScanClaimTicket::rollBackClaimTransaction($claimDb);
                        }
                        error_log('[register] claimed employee creation failed: ' . $e->getMessage());
                        $error = t('register.err_join_failed');
                    }
                }
            }
            
            // Create new company if no existing company found
            if (!$existingCompany && !$error && !$needsDomainChoice) {
                // Store domain for the company
                $domain = extractEmailDomain($email);
                
                $result = createCompany($name, $email, $password, null, $customSlug);
                if (!empty($result['success'])) {
                    $company = $result['company'];
                    
                    // Update company with domain + referral source
                    if (class_exists('DatabaseAdapter') && DatabaseAdapter::useDatabase()) {
                        try {
                            $db = Database::getInstance();
                            $updates = [];
                            if ($isBusinessDomain) {
                                $updates['domain'] = $domain;
                            }
                            if ($pendingReferral) {
                                $updates['referral_source'] = $pendingReferral;
                            }
                            if ($phone !== '') {
                                $updates['phone'] = $phone;
                            }
                            if (!empty($updates)) {
                                $db->update('companies', $updates, 'id = :id', ['id' => $company['id']]);
                            }
                        } catch (Exception $e) {
                            // Column might not exist yet, ignore
                        }
                    }

                    // Create the admin before consuming a claim ticket. When a claim
                    // is present, its transaction rolls both records back on failure.
                    $userResult = [];
                    try {
                        $userResult = Auth::createUser(
                            $email,
                            $password,
                            $userName ?: $name,
                            'company',
                            $company['id']
                        );
                        if (empty($userResult['user_id'])) {
                            throw new RuntimeException('Admin user creation failed');
                        }
                        if ($claimRegistration && $claimDb instanceof Database) {
                            ScanClaimTicket::completeRegistration(
                                $claimDb,
                                $claimRegistration,
                                (string) $company['id']
                            );
                        }
                    } catch (Throwable $e) {
                        if ($claimDb instanceof Database) {
                            ScanClaimTicket::rollBackClaimTransaction($claimDb);
                        }
                        error_log('[register] claimed company creation failed: ' . $e->getMessage());
                        $error = t('register.err_create_failed');
                    }

                    if (!$error) {
                    // Best-effort: attach phone to the user row so admin-side prompts
                    // and per-user notifications can target it. Column may not exist
                    // on legacy installs, silently ignore.
                    if ($phone !== '' && !empty($userResult['user_id'])) {
                        try {
                            $db = Database::getInstance();
                            $db->update('users', ['phone' => $phone], 'id = :id', ['id' => $userResult['user_id']]);
                        } catch (Exception $e) {
                            // column missing, migration 074 adds it
                        }
                    }

                    // Pre-seed onboarding wizard state with admin's own
                    // name/email/phone so step 4 (first_employee) pre-fills
                    // + wizard header greets them by name on first visit.
                    try {
                        require_once INCLUDES_DIR . '/Onboarding.php';
                        Onboarding::saveMeta($company['id'], [
                            'admin_name'    => $userName ?: $name,
                            'admin_email'   => $email,
                            'admin_phone'   => $phone !== '' ? $phone : null,
                            'company_name'  => $name,
                            'first_employee' => [
                                'name'  => $userName ?: $name,
                                'email' => $email,
                                'phone' => $phone !== '' ? $phone : '',
                                'title' => '',
                            ],
                        ]);
                    } catch (Throwable $e) {
                        error_log('[register] Onboarding::saveMeta failed: ' . $e->getMessage());
                    }

                    // BHD-234: give the new user a referral code and attribute them
                    // back to the referrer (if they came in via /r/<code>).
                    if (!empty($userResult['user_id'])) {
                        try {
                            Referral::ensureCodeForUser($userResult['user_id']);
                            if ($pendingRefCode) {
                                Referral::attributeSignup($userResult['user_id'], $pendingRefCode);
                                Referral::clearPending();
                            }
                        } catch (Throwable $e) {
                            error_log('[register] referral wiring failed: ' . $e->getMessage());
                        }
                    }

                    // BHD-244: seed a starter template immediately so every new
                    // signup lands on a dashboard with a usable template. The
                    // function is idempotent (no-op if templates already exist),
                    // so the onboarding wizard's own seedStarterTemplate() call
                    // with the user's chosen variant still short-circuits safely.
                    try {
                        seedStarterTemplate(Database::getInstance(), $company['id'], 'bhd-classic');
                    } catch (Throwable $e) {
                        error_log('[register] seedStarterTemplate failed: ' . $e->getMessage());
                    }

                    // Send welcome email
                    $siteName = defined('SITE_NAME') ? SITE_NAME : 'Cardify';
                    $companySlug = $company['slug'] ?? '';
                    $onboardingData = [
                        'site_name' => $siteName,
                        'admin_name' => $userName ?: $name,
                        'company_name' => $name,
                        'admin_url' => getTenantUrl($companySlug, '/admin/'),
                        'portal_url' => getTenantUrl($companySlug, '/portal')
                    ];
                    Mailer::sendTemplate($email, 'welcome_company', $onboardingData);

                    // Queue day-2 and day-5 onboarding drip emails
                    Mailer::queueOnboardingEmails($company['id'], $email, $onboardingData);

                    // Fire signup confirmation (email + WhatsApp)
                    try {
                        require_once INCLUDES_DIR . '/Notifier.php';
                        $baseHost = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'cardify.om');
                        Notifier::send('signup', [
                            'name'       => $userName ?: $name,
                            'email'      => $email,
                            'phone'      => $phone, // normalized E.164, empty string if user skipped
                            'company_id' => $company['id'] ?? null,
                        ], [
                            'name'        => $userName ?: $name,
                            'companyName' => $name,
                            'loginUrl'    => $baseHost . getBasePath() . 'login.php',
                            'dashboardUrl'=> !empty($company['slug'])
                                                ? getTenantUrl($company['slug'], '/admin/')
                                                : $baseHost . getBasePath() . 'admin/',
                            'companySlug' => $company['slug'] ?? '',
                        ]);
                    } catch (Throwable $e) {
                        error_log('[register] Notifier failed: ' . $e->getMessage());
                        // Don't block signup on notification failure
                    }

                    // Ops alert: ping Slack so the team sees new tenants
                    // as they land. Fail-open, no-op when unconfigured.
                    try {
                        require_once INCLUDES_DIR . '/SlackAlert.php';
                        SlackAlert::tenantSignup($name, $email, $phone, 'web-password');
                    } catch (Throwable $e) {
                        error_log('[register] Slack alert failed: ' . $e->getMessage());
                    }

                    // Login the new user
                    Auth::unifiedLogin($email, $password);

                    // Redirect all new signups to the onboarding wizard.
                    // BHD-referral cohort keeps the legacy onboarding.php flow
                    // (tailored content, drip emails); everyone else goes to
                    // the v2.0 wizard at /{slug}/admin/onboarding.
                    if ($pendingReferral === 'bhd') {
                        unset($_SESSION['pending_referral']);
                        header('Location: ' . getBasePath() . 'onboarding.php?source=bhd');
                    } else {
                        $companySlugForRedirect = $company['slug'] ?? '';
                        if ($companySlugForRedirect) {
                            header('Location: ' . getTenantUrl($companySlugForRedirect, '/admin/onboarding'));
                        } else {
                            header('Location: ' . getBasePath() . 'admin/onboarding.php');
                        }
                    }
                    exit;
                    }
                } else {
                    if ($claimDb instanceof Database) {
                        ScanClaimTicket::rollBackClaimTransaction($claimDb);
                    }
                    $error = $result['error'] ?? t('register.err_create_failed');
                }
            }
            }
        }
    }
    
    // Re-check domain status after form submission
    if (!empty($email)) {
        $isBusinessDomain = isBusinessEmailDomain($email);
        if ($isBusinessDomain) {
            $suggestedSlug = generateSlugFromEmail($email);
        }
    }

    // Refund the rate-limit slot: a successful signup always exit()s above, so
    // reaching here means validation failed. Don't make a user fixing a typo
    // burn through their hourly quota.
    if (!empty($error) || $needsDomainChoice) {
        RateLimiter::refund('company_signup_ip', $signupIp, 3600);
    }
    } // end rate-limit else
    } // end CSRF else
}
$minimalFooter = true; // compact footer for auth page
// r20-6: the last auth page with no canonical. /company/register.php is
// reachable with a referral, a claim ticket and a plan query string, so
// without this every variant is its own indexable URL. Same shape as
// login.php: self-canonical plus noindex,follow.
$canonicalUrl = 'https://cardify.om/company/register.php';
$metaRobots   = 'noindex,follow';
require_once INCLUDES_DIR . '/ui-header.php';
?>
    <div class="flex min-h-full">
        <!-- Left Side - Form -->
        <div class="flex flex-1 flex-col justify-center px-4 py-12 sm:px-6 lg:flex-none lg:px-20 xl:px-24">
            <div class="mx-auto w-full max-w-sm lg:w-96">
                <!-- Logo & Header -->
                <div>
                    <a href="<?php echo getBasePath(); ?>" class="flex items-center gap-3">
                        <img src="<?php echo assetUrl('images/logo.svg'); ?>" class="h-10 w-auto" alt="<?php echo $brandName; ?>">
                    </a>
                    <?php if ($pendingReferral === 'bhd'): ?>
                    <div class="mt-6 flex items-center gap-3 rounded-xl bg-blue-50 border border-blue-200 px-4 py-3">
                        <div class="w-8 h-8 rounded-full bg-blue-600 flex items-center justify-center flex-shrink-0">
                            <i class="fa-solid fa-print text-white text-xs"></i>
                        </div>
                        <div>
                            <p class="text-sm font-semibold text-blue-900"><?= htmlspecialchars(t('register.bhd_badge_title')) ?></p>
                            <p class="text-xs text-blue-600"><?= htmlspecialchars(t('register.bhd_badge_body')) ?></p>
                        </div>
                    </div>
                    <?php endif; ?>
                    <h1 class="mt-8 text-2xl font-bold tracking-tight text-gray-900">
                        <?= htmlspecialchars(t($pendingReferral === 'bhd' ? 'register.headline_bhd' : 'register.headline_default')) ?>
                    </h1>
                    <p class="mt-2 text-sm text-gray-600">
                        <?= htmlspecialchars(t('register.already_registered')) ?>
                        <a href="<?php echo getBasePath(); ?>login.php" class="font-semibold text-blue-600 hover:text-blue-500">
                            <?= htmlspecialchars(t('register.sign_in_to_account')) ?>
                        </a>
                    </p>
                </div>

                <!-- Error Message -->
                <?php if ($error): ?>
                <div class="mt-6 flex items-center gap-3 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-800" role="alert" aria-live="assertive">
                    <i class="fa-solid fa-circle-exclamation flex-shrink-0" aria-hidden="true"></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
                <?php endif; ?>

                <!-- Info/Success Message -->
                <?php if ($info): ?>
                <div class="mt-6 flex items-start gap-3 rounded-lg bg-green-50 px-4 py-3 text-sm text-green-800" role="status" aria-live="polite">
                    <i class="fa-solid fa-circle-check flex-shrink-0 mt-0.5"></i>
                    <div>
                        <span><?php echo htmlspecialchars($info); ?></span>
                        <p class="mt-2">
                            <a href="<?php echo getBasePath(); ?>login.php" class="font-semibold underline"><?= htmlspecialchars(t('register.info_go_signin')) ?></a>
                        </p>
                    </div>
                </div>
                <?php endif; ?>

                <?php
                // Existing-domain notice. It used to print the other tenant's
                // company name, which tells anyone who can type an email domain
                // whether that organisation is on Cardify and what it is called.
                // The visitor has proved nothing at this point, so the notice
                // says that the domain is taken and stops there.
                ?>
                <?php if ($existingCompany && !$info && !$needsDomainChoice): ?>
                <div class="mt-6 flex items-start gap-3 rounded-lg bg-blue-50 px-4 py-3 text-sm text-blue-800">
                    <i class="fa-solid fa-building flex-shrink-0 mt-0.5"></i>
                    <div>
                        <p><?= htmlspecialchars(t('register.existing_company')) ?></p>
                        <p class="mt-1"><?= htmlspecialchars(t('register.existing_company_join')) ?></p>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Registration Form -->
                <form method="POST" id="register-form" class="mt-10 space-y-6" <?php echo $info ? 'style="display:none;"' : ''; ?>>
                <input type="hidden" name="recaptcha_token" id="recaptcha_token" value="">
                <?php
                // The join-or-create answer rides in a hidden field, not in the
                // value of a named submit button. The reCAPTCHA handler below
                // re-submits with form.submit(), which does not carry the
                // clicked button's name and value, so a named button would send
                // nothing and leave the person on the question forever.
                ?>
                <input type="hidden" name="domain_choice" id="domain_choice" value="">
                <?php if ($claimTicket !== ''): ?>
                    <input type="hidden" name="claim_ticket" value="<?= htmlspecialchars($claimTicket, ENT_QUOTES) ?>">
                <?php endif; ?>
                    <?php echo csrfField(); ?>
                    <div>
                        <label for="admin_email" class="block text-sm font-medium text-gray-900">
                            <?= htmlspecialchars(t('register.your_email')) ?>
                        </label>
                        <div class="mt-2">
                            <input type="email" name="admin_email" id="admin_email"
                                   value="<?php echo htmlspecialchars($_POST['admin_email'] ?? $prefillEmail); ?>"
                                   class="form-input" autocomplete="email" aria-required="true"
                                   placeholder="<?= htmlspecialchars(t('register.placeholder_email')) ?>" required
                                   data-cardify-change-fn="checkEmailDomain" data-cardify-keyup-fn="checkEmailDomain">
                        </div>
                    </div>

                    <div>
                        <label for="user_name" class="block text-sm font-medium text-gray-900">
                            <?= htmlspecialchars(t('register.your_name')) ?>
                        </label>
                        <div class="mt-2">
                            <input type="text" name="user_name" id="user_name"
                                   value="<?php echo htmlspecialchars($_POST['user_name'] ?? $prefillName); ?>"
                                   class="form-input" autocomplete="name" aria-required="true"
                                   placeholder="<?= htmlspecialchars(t('register.placeholder_name')) ?>" required>
                        </div>
                    </div>

                    <div>
                        <label for="company_name" class="block text-sm font-medium text-gray-900">
                            <?= htmlspecialchars(t('register.company_name')) ?>
                        </label>
                        <div class="mt-2">
                            <input type="text" name="company_name" id="company_name"
                                   value="<?php echo htmlspecialchars($_POST['company_name'] ?? ''); ?>"
                                   class="form-input" autocomplete="organization" aria-required="true"
                                   placeholder="<?= htmlspecialchars(t('register.placeholder_company')) ?>" required>
                        </div>
                    </div>

                    <div id="slug-wrapper">
                        <label for="company_slug" class="block text-sm font-medium text-gray-900">
                            <?= htmlspecialchars(t('register.company_url')) ?>
                        </label>
                        <div class="mt-2 flex items-center" dir="ltr">
                            <input type="text" name="company_slug" id="company_slug"
                                   value="<?php echo htmlspecialchars($_POST['company_slug'] ?? $suggestedSlug); ?>"
                                   class="form-input flex-1"
                                   placeholder="<?php echo htmlspecialchars($suggestedSlug ?: t('register.placeholder_slug')); ?>">
                            <span class="text-sm text-gray-500 ms-1 whitespace-nowrap">.<?php echo defined('APP_HOST') ? APP_HOST : 'cardify.om'; ?></span>
                        </div>
                        <p id="domain-info" class="mt-1.5 text-xs text-gray-500">
                            <?php if ($isBusinessDomain): ?>
                            <i class="fa-solid fa-building text-green-500 mr-1"></i><?= htmlspecialchars(t('register.domain_detected')) ?>
                            <?php else: ?>
                            <?= htmlspecialchars(t('register.slug_hint')) ?>
                            <?php endif; ?>
                        </p>
                    </div>

                    <div id="phone-field-wrapper">
                        <label for="phone" class="block text-sm font-medium text-gray-900">
                            <?= htmlspecialchars(t('register.whatsapp_number')) ?>
                            <span class="text-gray-400 font-normal" id="phone-required-hint"><?= htmlspecialchars(t('register.whatsapp_required_hint')) ?></span>
                        </label>
                        <div class="mt-2">
                            <input type="tel" name="phone" id="phone"
                                   value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>"
                                   class="form-input"
                                   autocomplete="tel"
                                   inputmode="tel"
                                   placeholder="<?= htmlspecialchars(t('register.phone_placeholder')) ?>"
                                   required>
                            <input type="hidden" name="phone_e164" id="phone_e164" value="">
                            <input type="hidden" name="phone_skipped" id="phone_skipped" value="0">
                        </div>
                        <p class="mt-1.5 text-xs text-gray-500" id="phone-help">
                            <?= htmlspecialchars(t('register.phone_help')) ?>
                            <a href="#" id="phone-skip-link" class="ml-1 font-medium text-gray-700 hover:text-gray-900"><?= htmlspecialchars(t('register.phone_skip')) ?></a>
                        </p>
                    </div>

                    <div>
                        <label for="password" class="block text-sm font-medium text-gray-900">
                            <?= htmlspecialchars(t('register.password')) ?>
                        </label>
                        <div class="mt-2">
                            <input type="password" name="password" id="password"
                                   class="form-input" autocomplete="new-password" aria-required="true"
                                   aria-describedby="password-hint"
                                   <?php echo $needsDomainChoice ? 'autofocus' : ''; ?>
                                   placeholder="<?= htmlspecialchars(t('register.placeholder_password')) ?>" required minlength="8">
                        </div>
                        <?php
                        // A password field is never repopulated, so the second
                        // pass through this form starts with it empty and
                        // required. Without this line the browser blocks the
                        // submit on a field the person cannot see from the
                        // buttons at the bottom, and the click looks dead.
                        ?>
                        <p id="password-hint" class="mt-1.5 text-xs <?php echo $needsDomainChoice ? 'font-medium text-amber-700' : 'text-gray-500'; ?>">
                            <?php if ($needsDomainChoice): ?>
                                <i class="fa-solid fa-arrow-turn-up fa-rotate-90 me-1"></i><?= htmlspecialchars(t('register.password_reenter')) ?>
                            <?php else: ?>
                                <?= htmlspecialchars(t('register.password_hint')) ?>
                            <?php endif; ?>
                        </p>
                    </div>

                    <div>
                        <label for="referral_code" class="block text-sm font-medium text-gray-900">
                            <?= htmlspecialchars(t('register.referral_code')) ?>
                            <span class="text-gray-400 text-xs font-normal"><?= htmlspecialchars(t('register.referral_optional')) ?></span>
                        </label>
                        <div class="mt-2">
                            <input type="text" name="referral_code" id="referral_code"
                                   value="<?php echo htmlspecialchars($_POST['referral_code'] ?? $_GET['ref'] ?? ''); ?>"
                                   class="form-input"
                                   placeholder="<?= htmlspecialchars(t('register.referral_placeholder')) ?>"
                                   maxlength="32">
                        </div>
                    </div>

                    <div class="flex items-start gap-3">
                        <div class="flex h-6 shrink-0 items-center">
                            <input id="terms" name="terms" type="checkbox" required
                                   <?php echo !empty($_POST['terms']) ? 'checked' : ''; ?>
                                   class="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-600">
                        </div>
                        <label for="terms" class="text-sm text-gray-600">
                            <?= htmlspecialchars(t('register.accept_terms')) ?>
                            <a href="<?php echo getBasePath(); ?>terms.php" target="_blank" class="font-semibold text-blue-600 hover:text-blue-500"><?= htmlspecialchars(t('register.terms')) ?></a>
                            <?= htmlspecialchars(t('register.and')) ?>
                            <a href="<?php echo getBasePath(); ?>privacy.php" target="_blank" class="font-semibold text-blue-600 hover:text-blue-500"><?= htmlspecialchars(t('register.privacy')) ?></a>
                        </label>
                    </div>

                    <p class="text-xs text-gray-500 flex items-start gap-2">
                        <i class="fa-solid fa-shield-halved mt-0.5 text-emerald-600"></i>
                        <span><?= htmlspecialchars(t('register.pdpl_notice')) ?></span>
                    </p>

                    <?php if ($needsDomainChoice): ?>
                    <?php
                    // The domain is already claimed. Both outcomes are legitimate
                    // and only the person filling the form knows which they mean,
                    // so both are offered and neither is taken by default. Every
                    // field above kept its submitted value, so whichever they
                    // pick carries their own details forward.
                    ?>
                    <div class="rounded-xl border border-amber-200 bg-amber-50 p-4">
                        <p class="text-sm font-semibold text-amber-900">
                            <i class="fa-solid fa-circle-question me-1"></i>
                            <?= htmlspecialchars(t('register.domain_choice_title')) ?>
                        </p>
                        <p class="mt-1 text-sm text-amber-900/80"><?= htmlspecialchars(t('register.domain_choice_body')) ?></p>
                        <div class="mt-4 flex flex-col gap-3">
                            <button type="submit" data-domain-choice="join"
                                    class="w-full rounded-lg bg-blue-600 px-4 py-3 text-sm font-semibold text-white hover:bg-blue-500 transition-colors">
                                <?= htmlspecialchars(t('register.domain_choice_join')) ?>
                            </button>
                            <button type="submit" data-domain-choice="create"
                                    class="w-full rounded-lg border border-gray-300 bg-white px-4 py-3 text-sm font-semibold text-gray-800 hover:bg-gray-50 transition-colors">
                                <?= htmlspecialchars(t('register.domain_choice_create')) ?>
                            </button>
                        </div>
                    </div>
                    <?php else: ?>
                    <div>
                        <button type="submit" class="flex w-full justify-center rounded-lg bg-blue-600 px-4 py-3 text-sm font-semibold text-white shadow-sm hover:bg-blue-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600 transition-colors">
                            <?= htmlspecialchars(t('register.submit')) ?>
                            <i class="fa-solid fa-arrow-right ml-2"></i>
                        </button>
                    </div>
                    <?php endif; ?>
                </form>

                <!-- Divider -->
                <div class="mt-10">
                    <div class="relative">
                        <div class="absolute inset-0 flex items-center" aria-hidden="true">
                            <div class="w-full border-t border-gray-200"></div>
                        </div>
                        <div class="relative flex justify-center text-sm font-medium">
                            <span class="bg-white px-6 text-gray-500"><?= htmlspecialchars(t('register.what_you_get')) ?></span>
                        </div>
                    </div>

                    <!-- Features -->
                    <ul class="mt-6 space-y-3 text-sm text-gray-600">
                        <li class="flex items-center gap-3">
                            <i class="fa-solid fa-circle-check text-green-500"></i>
                            <span><?= htmlspecialchars(t('register.feat_unlimited')) ?></span>
                        </li>
                        <li class="flex items-center gap-3">
                            <i class="fa-solid fa-circle-check text-green-500"></i>
                            <span><?= htmlspecialchars(t('register.feat_branding')) ?></span>
                        </li>
                        <li class="flex items-center gap-3">
                            <i class="fa-solid fa-circle-check text-green-500"></i>
                            <span><?= htmlspecialchars(t('register.feat_team')) ?></span>
                        </li>
                        <li class="flex items-center gap-3">
                            <i class="fa-solid fa-circle-check text-green-500"></i>
                            <span><?= htmlspecialchars(t('register.feat_free')) ?></span>
                        </li>
                    </ul>
                </div>

                <!-- Back to Home -->
                <p class="mt-10 text-center text-sm text-gray-500">
                    <a href="<?php echo getBasePath(); ?>" class="font-medium text-gray-700 hover:text-gray-900">
                        <i class="fa-solid fa-arrow-left mr-1"></i>
                        <?= htmlspecialchars(t('register.back_home')) ?>
                    </a>
                </p>
            </div>
        </div>

        <?php
        // Right side. This panel used to carry an attributed customer quote over
        // a stock portrait; the customer did not exist, so it is gone. What
        // replaces it is checkable, and it sits at the TOP because that is where
        // the form is: the old gradient ran dark-to-light downward, so at the
        // scroll position where people actually read, the panel was a flat slab.
        // Kept as a PHP comment, not an HTML one: an HTML comment ships the
        // removed name back to every visitor in the page source.
        ?>
        <div class="relative hidden w-0 flex-1 lg:block">
            <img class="absolute inset-0 h-full w-full object-cover"
                 src="<?php echo assetUrl('images/salient/background-auth.jpg'); ?>"
                 alt="">
            <div class="absolute inset-0 bg-gradient-to-b from-[#04384a]/95 via-[#04384a]/80 to-[#04384a]/55"></div>

            <div class="absolute inset-0 flex flex-col justify-start p-12 pt-20 text-white">
                <div class="max-w-md">
                    <p class="text-3xl font-bold leading-tight"><?= htmlspecialchars(t('register.panel_title')) ?></p>
                    <p class="mt-4 text-base leading-relaxed text-white/80"><?= htmlspecialchars(t('register.panel_sub')) ?></p>
                    <ul class="mt-10 space-y-4 text-[15px]">
                        <li class="flex items-start gap-3">
                            <i class="fa-solid fa-language mt-1 text-cyan-300"></i>
                            <span><?= htmlspecialchars(t('register.panel_point_bilingual')) ?></span>
                        </li>
                        <li class="flex items-start gap-3">
                            <i class="fa-solid fa-qrcode mt-1 text-cyan-300"></i>
                            <span><?= htmlspecialchars(t('register.panel_point_noapp')) ?></span>
                        </li>
                        <li class="flex items-start gap-3">
                            <i class="fa-solid fa-print mt-1 text-cyan-300"></i>
                            <span><?= htmlspecialchars(t('register.panel_point_print')) ?></span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
<script src="https://cdn.jsdelivr.net/npm/intl-tel-input@23.8.2/build/js/intlTelInput.min.js"></script>
<script<?= cspNonceAttr() ?>>
(function () {
    var phoneEl = document.getElementById('phone');
    var hiddenEl = document.getElementById('phone_e164');
    var skippedEl = document.getElementById('phone_skipped');
    var skipLink = document.getElementById('phone-skip-link');
    var helpEl = document.getElementById('phone-help');
    var requiredHint = document.getElementById('phone-required-hint');
    if (!phoneEl || !window.intlTelInput) return;

    var iti = window.intlTelInput(phoneEl, {
        initialCountry: 'om',
        preferredCountries: ['om', 'ae', 'sa', 'qa', 'bh', 'kw'],
        separateDialCode: true,
        autoPlaceholder: 'aggressive',
        utilsScript: 'https://cdn.jsdelivr.net/npm/intl-tel-input@23.8.2/build/js/utils.js'
    });

    if (skipLink) {
        skipLink.addEventListener('click', function (e) {
            e.preventDefault();
            phoneEl.value = '';
            hiddenEl.value = '';
            skippedEl.value = '1';
            phoneEl.required = false;
            phoneEl.disabled = true;
            phoneEl.placeholder = 'Skipped, add later from your dashboard';
            if (requiredHint) { requiredHint.textContent = '(skipped, you can add it later)'; }
            if (helpEl) { helpEl.textContent = "We'll prompt you again from your dashboard. No phone, no WA messages."; }
        });
    }

    var form = phoneEl.closest('form');
    if (form) {
        form.addEventListener('submit', function () {
            if (skippedEl.value === '1') { hiddenEl.value = ''; return; }
            try {
                if (iti.isValidNumber()) {
                    hiddenEl.value = iti.getNumber();
                } else if (phoneEl.value.trim() !== '') {
                    // Best effort, let server-side normalization decide.
                    hiddenEl.value = iti.getNumber() || phoneEl.value.trim();
                } else {
                    hiddenEl.value = '';
                }
            } catch (err) {
                hiddenEl.value = phoneEl.value.trim();
            }
        });
    }
})();
</script>
<script<?= cspNonceAttr() ?>>
// Join-or-create: record the answer in the hidden field before the form goes.
// Written as a click handler rather than a named submit button because the
// reCAPTCHA path re-submits with form.submit(), which drops button values.
(function(){
    var form = document.getElementById('register-form');
    var field = document.getElementById('domain_choice');
    if (!form || !field) return;
    form.addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('[data-domain-choice]') : null;
        if (btn) field.value = btn.getAttribute('data-domain-choice');
    });
})();
</script>
<?php @include __DIR__ . '/../views/partials/trust_logo_strip.php'; ?>
<?php require_once INCLUDES_DIR . '/Recaptcha.php'; if (Recaptcha::isConfigured()): $siteKey = Recaptcha::siteKey(); ?>
<script src="https://www.google.com/recaptcha/api.js?render=<?= htmlspecialchars($siteKey) ?>"></script>
<script<?= cspNonceAttr() ?>>
(function(){
    var form = document.getElementById('register-form');
    if (!form) return;
    // This handler owns the only path to submitting the form, so anything that
    // stops it stops signup outright. It used to have no catch and no timeout:
    // if grecaptcha.execute() rejected or never settled, the button did nothing
    // at all, with no message and no spinner. Observed live on the second
    // submit of a session. Both failure modes now fall through and let the
    // server answer, which fails closed with a visible message rather than
    // silently.
    var submitted = false;
    function sendForm() {
        if (submitted) return;
        submitted = true;
        form.dataset.captchaDone = '1';
        form.submit();
    }
    form.addEventListener('submit', function(e){
        if (form.dataset.captchaDone === '1') return;
        e.preventDefault();
        var btns = form.querySelectorAll('button[type=submit]');
        for (var i = 0; i < btns.length; i++) { btns[i].disabled = true; btns[i].style.opacity = '0.6'; }
        setTimeout(sendForm, 6000);
        try {
            grecaptcha.ready(function(){
                grecaptcha.execute(<?= json_encode($siteKey) ?>, {action: 'signup'}).then(function(token){
                    document.getElementById('recaptcha_token').value = token;
                    sendForm();
                }).catch(function(){ sendForm(); });
            });
        } catch (err) {
            sendForm();
        }
    });
})();
</script>
<?php endif; ?>
<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
