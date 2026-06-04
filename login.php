<?php
/**
 * Cardify - Unified Login Page
 * Auto-detects user type (super_admin, company admin, employee)
 * Redirects to signup if email not found
 */
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/Auth.php';

// Tenant subdomains use OTP-only login
if (file_exists(__DIR__ . '/includes/TenantHost.php')) {
    require_once __DIR__ . '/includes/TenantHost.php';
    if (TenantHost::isTenantHost()) {
        require __DIR__ . '/tenant_login.php';
        exit;
    }
}

// Get redirect URL from query string (for company portal redirects)
// Validate redirect is a safe relative path (no protocol-relative URLs like //evil.com)
$redirectUrl = $_GET['redirect'] ?? null;
if ($redirectUrl && !preg_match('#^/[a-zA-Z0-9/_.\-?&=%]*$#', $redirectUrl)) {
    $redirectUrl = null; // Reject suspicious redirects
}

// If caller reports an unauthorized access, clear auth state so we don't
// redirect the user straight back to the page that rejected them (loop).
// Keep the session alive so the CSRF token on the rendered form still
// matches on submit (session_destroy + re-start mid-request breaks CSRF).
if (($_GET['error'] ?? '') === 'unauthorized' && Auth::isLoggedIn()) {
    foreach (['user_id','user_email','user_name','user_role','user_company_id',
              'company_id','company_slug','company_name','employee_id',
              'current_company_slug'] as $_k) {
        unset($_SESSION[$_k]);
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        @session_regenerate_id(true);
    }
    $info = 'You were signed out. Please sign in again.';
}

// Redirect if already logged in
if (Auth::isLoggedIn()) {
    if ($redirectUrl) {
        // Use provided redirect, already validated above
        header('Location: ' . getBasePath() . ltrim($redirectUrl, '/'));
    } else {
        $role = Auth::getCurrentRole();
        $companySlug = $_SESSION['company_slug'] ?? null;
        
        if ($role === 'super_admin') {
            header('Location: ' . getBasePath() . 'admin/');
        } elseif ($role === 'print_shop') {
            header('Location: ' . getBasePath() . 'printshop/dashboard.php');
        } elseif ($companySlug) {
            header('Location: ' . getTenantUrl($companySlug, '/admin/'));
        } else {
            header('Location: ' . getBasePath() . 'admin/');
        }
    }
    exit;
}

$error = null;
$info = null;
$prefillEmail = $_GET['email'] ?? '';
$brandName = defined('SITE_NAME') ? SITE_NAME : 'Cardify';
$pageTitle = t('auth.sign_in');
$htmlClass = 'h-full bg-white';
$bodyClass = 'h-full';
$minimalFooter = true; // use compact footer on auth pages
$extraHead = <<<HTML
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
            border-color: #009bc1;
            box-shadow: 0 0 0 3px rgba(0, 155, 193, 0.12);
        }
        .form-input::placeholder {
            color: #9ca3af;
        }
    </style>
HTML;

// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please try again.';
    } else {
    $email = sanitizeEmail($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($email) || empty($password)) {
        $error = 'Please enter email and password';
    } else {
        // Use unified login with auto-detection
        $result = Auth::unifiedLogin($email, $password);
        
        if ($result['success']) {
            // Use provided redirect URL if available (already validated above), otherwise use default
            if ($redirectUrl) {
                header('Location: ' . getBasePath() . ltrim($redirectUrl, '/'));
            } else {
                header('Location: ' . $result['redirect']);
            }
            exit;
        } else {
            // Anti-enumeration: do not branch on 'not_found'. Previously, an
            // unknown email triggered a redirect to /company/register.php
            // with the email pre-filled, while a known email kept the user
            // on /login.php with an error message. The differing responses
            // let attackers probe arbitrary email addresses to learn which
            // ones are registered Cardify accounts. Surface the same generic
            // 'Invalid credentials' message either way; a legitimate new
            // user can still hit /company/register.php from the link in the
            // header.
            $error = $result['error'] ?? 'Invalid credentials';
        }
    }
    } // end CSRF else
}
?>
<?php require_once INCLUDES_DIR . '/ui-header.php'; ?>
    <div class="flex min-h-full">
        <!-- Left Side - Form -->
        <div class="flex flex-1 flex-col justify-center px-4 py-12 sm:px-6 lg:flex-none lg:px-20 xl:px-24">
            <div class="mx-auto w-full max-w-sm lg:w-96">
                <!-- Logo & Header -->
                <div>
                    <a href="<?php echo getBasePath(); ?>" class="flex items-center gap-3">
                        <img src="<?php echo assetUrl('images/logo.svg'); ?>" class="h-10 w-auto" alt="<?php echo $brandName; ?>">
                    </a>
                    <h1 class="mt-8 text-2xl font-bold tracking-tight text-gray-900">
                        <?= htmlspecialchars(t('auth.sign_in_headline')) ?>
                    </h1>
                    <p class="mt-2 text-sm text-gray-600">
                        <?= htmlspecialchars(t('auth.not_member')) ?>
                        <a href="<?php echo getBasePath(); ?>company/register.php" class="font-semibold text-blue-600 hover:text-blue-500"><?= htmlspecialchars(t('auth.as_company')) ?></a>
                        <?= htmlspecialchars(t('auth.or')) ?>
                        <a href="<?php echo getBasePath(); ?>printshop/register.php" class="font-semibold text-purple-600 hover:text-purple-500"><?= htmlspecialchars(t('auth.as_printshop')) ?></a>
                    </p>
                </div>

                <!-- Error Message -->
                <?php if ($error): ?>
                <div class="mt-6 flex items-center gap-3 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-800">
                    <i class="fa-solid fa-circle-exclamation flex-shrink-0"></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
                <?php endif; ?>

                <!-- Info Message -->
                <?php if ($info): ?>
                <div class="mt-6 flex items-center gap-3 rounded-lg bg-blue-50 px-4 py-3 text-sm text-blue-800">
                    <i class="fa-solid fa-circle-info flex-shrink-0"></i>
                    <span><?php echo htmlspecialchars($info); ?></span>
                </div>
                <?php endif; ?>

                <!-- Unified Login Form -->
                <form method="POST" class="mt-10 space-y-6">
                    <?php echo csrfField(); ?>
                    <div>
                        <label for="email" class="block text-sm font-medium text-gray-900">
                            <?= htmlspecialchars(t('auth.email')) ?>
                        </label>
                        <div class="mt-2">
                            <input type="email" name="email" id="email" autocomplete="email"
                                   value="<?php echo htmlspecialchars($_POST['email'] ?? $prefillEmail); ?>"
                                   class="form-input"
                                   placeholder="<?= htmlspecialchars(t('auth.placeholder_email')) ?>" required>
                        </div>
                    </div>

                    <div>
                        <label for="password" class="block text-sm font-medium text-gray-900">
                            <?= htmlspecialchars(t('auth.password')) ?>
                        </label>
                        <div class="mt-2">
                            <input type="password" name="password" id="password" autocomplete="current-password"
                                   class="form-input"
                                   placeholder="<?= htmlspecialchars(t('auth.placeholder_password')) ?>" required>
                        </div>
                    </div>

                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <input id="remember" name="remember" type="checkbox"
                                   class="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-600">
                            <label for="remember" class="text-sm text-gray-900"><?= htmlspecialchars(t('auth.remember_me')) ?></label>
                        </div>
                        <a href="<?php echo getBasePath(); ?>forgot-password.php" class="text-sm font-semibold text-blue-600 hover:text-blue-500">
                            <?= htmlspecialchars(t('auth.forgot_password')) ?>
                        </a>
                    </div>

                    <div>
                        <button type="submit" class="flex w-full justify-center rounded-lg bg-blue-600 px-4 py-3 text-sm font-semibold text-white shadow-sm hover:bg-blue-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600 transition-colors">
                            <?= htmlspecialchars(t('auth.sign_in')) ?>
                            <i class="fa-solid fa-arrow-right ml-2"></i>
                        </button>
                    </div>
                </form>

                <!-- Info Text -->
                <div class="mt-8 p-4 bg-gradient-to-r from-blue-50 to-purple-50 rounded-xl border border-blue-100">
                    <p class="text-sm text-gray-700 text-center font-medium mb-2">
                        <i class="fa-solid fa-wand-magic-sparkles text-blue-500 mr-2"></i>
                        <?= htmlspecialchars(t('auth.one_login_title')) ?>
                    </p>
                    <div class="flex justify-center gap-4 text-xs text-gray-500">
                        <span><i class="fa-solid fa-building text-blue-500 mr-1"></i> <?= htmlspecialchars(t('auth.role_companies')) ?></span>
                        <span><i class="fa-solid fa-store text-purple-500 mr-1"></i> <?= htmlspecialchars(t('auth.role_printshops')) ?></span>
                        <span><i class="fa-solid fa-shield text-green-500 mr-1"></i> <?= htmlspecialchars(t('auth.role_admins')) ?></span>
                    </div>
                </div>

                <!-- Back to Home -->
                <p class="mt-10 text-center text-sm text-gray-500">
                    <a href="<?php echo getBasePath(); ?>" class="font-medium text-gray-700 hover:text-gray-900">
                        <i class="fa-solid fa-arrow-left mr-1"></i>
                        <?= htmlspecialchars(t('auth.back_home')) ?>
                    </a>
                </p>
            </div>
        </div>

        <!-- Right Side - Background Image -->
        <div class="relative hidden w-0 flex-1 lg:block">
            <img class="absolute inset-0 h-full w-full object-cover" 
                 src="<?php echo assetUrl('images/authentication/login.jpg'); ?>" 
                 alt="">
            <!-- Overlay -->
            <div class="absolute inset-0 bg-gradient-to-t from-gray-900/80 via-gray-800/50 to-transparent"></div>
            
            <!-- Content on image -->
            <div class="absolute inset-0 flex flex-col justify-end p-12 text-white">
                <div class="max-w-lg">
                    <h3 class="text-3xl font-bold">
                        <?= htmlspecialchars(t('auth.panel_welcome_back', ['brand' => $brandName])) ?>
                    </h3>
                    <p class="mt-4 text-lg text-gray-200 leading-relaxed">
                        <?= htmlspecialchars(t('auth.panel_tagline')) ?>
                    </p>

                    <!-- Trust signals -->
                    <div class="mt-8 flex flex-wrap gap-6">
                        <div class="flex items-center gap-2 text-sm">
                            <svg class="w-5 h-5 text-blue-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 21v-4m0 0V5a2 2 0 012-2h6.5l1 1H21l-3 6 3 6h-8.5l-1-1H5a2 2 0 00-2 2zm9-13.5V9"/></svg>
                            <span><?= htmlspecialchars(t('auth.trust_oman')) ?></span>
                        </div>
                        <div class="flex items-center gap-2 text-sm">
                            <svg class="w-5 h-5 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                            <span><?= htmlspecialchars(t('auth.trust_paymob')) ?></span>
                        </div>
                        <div class="flex items-center gap-2 text-sm">
                            <svg class="w-5 h-5 text-yellow-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                            <span><?= htmlspecialchars(t('auth.trust_realtime')) ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
