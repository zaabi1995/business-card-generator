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

// Redirect if already logged in
if (Auth::isLoggedIn()) {
    header('Location: ' . getBasePath() . 'admin/');
    exit;
}

$error = null;
$info = null;
$prefillEmail = $_GET['email'] ?? '';
$prefillName = $_GET['name'] ?? '';
$isBusinessDomain = false;
$suggestedSlug = '';
$existingCompany = null;

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
$pageTitle = 'Create Account';
$htmlClass = 'h-full bg-white';
$bodyClass = 'h-full';
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
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }
        .form-input::placeholder {
            color: #9ca3af;
        }
        .form-input:disabled {
            background-color: #f3f4f6;
            color: #6b7280;
            cursor: not-allowed;
        }
    </style>
    <script>
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
        $error = 'Invalid request. Please try again.';
    } else {
    $name = trim($_POST['company_name'] ?? '');
    $email = sanitizeEmail($_POST['admin_email'] ?? '');
    $password = $_POST['password'] ?? '';
    $customSlug = trim($_POST['company_slug'] ?? '');
    $userName = trim($_POST['user_name'] ?? '');

    // Validation
    if (empty($name)) {
        $error = 'Company name is required';
    } elseif (empty($email) || !isValidEmail($email)) {
        $error = 'Valid email address is required';
    } elseif (empty($password) || strlen($password) < 8) {
        $error = 'Password must be at least 8 characters';
    } else {
        // Check if email already exists
        $existsCheck = Auth::emailExists($email);
        if ($existsCheck['exists']) {
            $error = 'This email is already registered. Please sign in instead.';
        } else {
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
                
                if ($existingCompany) {
                    // Company exists - add user as employee instead
                    $employeeData = [
                        'id' => generateUUID(),
                        'name_en' => $userName ?: $name,
                        'email' => $email,
                        'password_hash' => password_hash($password, PASSWORD_BCRYPT),
                        'company_id' => $existingCompany['id'],
                        'status' => 'pending', // Requires admin approval
                        'created_at' => date('Y-m-d H:i:s')
                    ];
                    
                    $empResult = addEmployee($employeeData, $existingCompany['id']);
                    if ($empResult['success'] ?? false) {
                        $info = 'Your request to join ' . htmlspecialchars($existingCompany['name']) . ' has been submitted. You will be notified once approved.';
                        // Don't redirect - show message
                    } else {
                        $error = 'Failed to submit join request. Please contact the company administrator.';
                    }
                }
            }
            
            // Create new company if no existing company found
            if (!$existingCompany && !$error) {
                // Store domain for the company
                $domain = extractEmailDomain($email);
                
                $result = createCompany($name, $email, $password, null, $customSlug);
                if (!empty($result['success'])) {
                    $company = $result['company'];
                    
                    // Update company with domain
                    if ($isBusinessDomain && class_exists('DatabaseAdapter') && DatabaseAdapter::useDatabase()) {
                        try {
                            $db = Database::getInstance();
                            $db->update('companies', 
                                ['domain' => $domain],
                                'id = :id',
                                ['id' => $company['id']]
                            );
                        } catch (Exception $e) {
                            // Column might not exist, ignore
                        }
                    }
                    
                    // Create user record for the admin
                    $userResult = Auth::createUser($email, $password, $userName ?: $name, 'company', $company['id']);
                    
                    // Send welcome email
                    $siteName = defined('SITE_NAME') ? SITE_NAME : 'Cardify';
                    $companySlug = $company['slug'] ?? '';
                    Mailer::sendTemplate($email, 'welcome_company', [
                        'site_name' => $siteName,
                        'admin_name' => $userName ?: $name,
                        'company_name' => $name,
                        'admin_url' => getBaseUrl() . $companySlug . '/admin/',
                        'portal_url' => getBaseUrl() . $companySlug . '/portal'
                    ]);
                    
                    // Login and redirect
                    Auth::unifiedLogin($email, $password);
                    header('Location: ' . getBasePath() . 'admin/');
                    exit;
                }
                $error = $result['error'] ?? 'Failed to create company';
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
                        <span class="text-xl font-bold text-gray-900"><?php echo $brandName; ?></span>
                    </a>
                    <h2 class="mt-8 text-2xl font-bold tracking-tight text-gray-900">
                        Get started for free
                    </h2>
                    <p class="mt-2 text-sm text-gray-600">
                        Already registered?
                        <a href="<?php echo getBasePath(); ?>login.php" class="font-semibold text-blue-600 hover:text-blue-500">
                            Sign in
                        </a>
                        to your account.
                    </p>
                </div>

                <!-- Error Message -->
                <?php if ($error): ?>
                <div class="mt-6 flex items-center gap-3 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-800">
                    <i class="fa-solid fa-circle-exclamation flex-shrink-0"></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
                <?php endif; ?>

                <!-- Info/Success Message -->
                <?php if ($info): ?>
                <div class="mt-6 flex items-start gap-3 rounded-lg bg-green-50 px-4 py-3 text-sm text-green-800">
                    <i class="fa-solid fa-circle-check flex-shrink-0 mt-0.5"></i>
                    <div>
                        <span><?php echo htmlspecialchars($info); ?></span>
                        <p class="mt-2">
                            <a href="<?php echo getBasePath(); ?>login.php" class="font-semibold underline">Go to Sign In</a>
                        </p>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Existing Company Notice -->
                <?php if ($existingCompany && !$info): ?>
                <div class="mt-6 flex items-start gap-3 rounded-lg bg-blue-50 px-4 py-3 text-sm text-blue-800">
                    <i class="fa-solid fa-building flex-shrink-0 mt-0.5"></i>
                    <div>
                        <strong><?php echo htmlspecialchars($existingCompany['name']); ?></strong> already exists with this domain.
                        <p class="mt-1">You can request to join as an employee.</p>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Registration Form -->
                <form method="POST" class="mt-10 space-y-6" <?php echo $info ? 'style="display:none;"' : ''; ?>>
                    <?php echo csrfField(); ?>
                    <div>
                        <label for="admin_email" class="block text-sm font-medium text-gray-900">
                            Your email address
                        </label>
                        <div class="mt-2">
                            <input type="email" name="admin_email" id="admin_email" 
                                   value="<?php echo htmlspecialchars($_POST['admin_email'] ?? $prefillEmail); ?>"
                                   class="form-input" 
                                   placeholder="you@company.com" required
                                   onchange="checkEmailDomain()" onkeyup="checkEmailDomain()">
                        </div>
                    </div>

                    <div>
                        <label for="user_name" class="block text-sm font-medium text-gray-900">
                            Your name
                        </label>
                        <div class="mt-2">
                            <input type="text" name="user_name" id="user_name" 
                                   value="<?php echo htmlspecialchars($_POST['user_name'] ?? $prefillName); ?>"
                                   class="form-input" 
                                   placeholder="John Smith" required>
                        </div>
                    </div>

                    <div>
                        <label for="company_name" class="block text-sm font-medium text-gray-900">
                            Company name
                        </label>
                        <div class="mt-2">
                            <input type="text" name="company_name" id="company_name" 
                                   value="<?php echo htmlspecialchars($_POST['company_name'] ?? ''); ?>"
                                   class="form-input" 
                                   placeholder="Acme Corporation" required>
                        </div>
                    </div>

                    <div id="slug-wrapper">
                        <label for="company_slug" class="block text-sm font-medium text-gray-900">
                            Company URL
                        </label>
                        <div class="mt-2 flex items-center">
                            <span class="text-sm text-gray-500 mr-1"><?php echo $_SERVER['HTTP_HOST'] ?? 'cardify.om'; ?>/</span>
                            <input type="text" name="company_slug" id="company_slug" 
                                   value="<?php echo htmlspecialchars($_POST['company_slug'] ?? $suggestedSlug); ?>"
                                   class="form-input flex-1" 
                                   placeholder="<?php echo $suggestedSlug ?: 'your-company'; ?>"
                                   <?php echo ($isBusinessDomain && $suggestedSlug) ? '' : ''; ?>>
                        </div>
                        <p id="domain-info" class="mt-1.5 text-xs text-gray-500">
                            <?php if ($isBusinessDomain): ?>
                            <i class="fa-solid fa-building text-green-500 mr-1"></i>Business domain detected. URL auto-set from your domain.
                            <?php else: ?>
                            Choose a unique URL for your company
                            <?php endif; ?>
                        </p>
                    </div>

                    <div>
                        <label for="password" class="block text-sm font-medium text-gray-900">
                            Password
                        </label>
                        <div class="mt-2">
                            <input type="password" name="password" id="password" 
                                   class="form-input" 
                                   placeholder="••••••••" required minlength="8">
                        </div>
                        <p class="mt-1.5 text-xs text-gray-500">Minimum 8 characters</p>
                    </div>

                    <div class="flex items-start gap-3">
                        <div class="flex h-6 shrink-0 items-center">
                            <input id="terms" name="terms" type="checkbox" required
                                   class="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-600">
                        </div>
                        <label for="terms" class="text-sm text-gray-600">
                            I accept the 
                            <a href="#" class="font-semibold text-blue-600 hover:text-blue-500">Terms and Conditions</a>
                            and
                            <a href="#" class="font-semibold text-blue-600 hover:text-blue-500">Privacy Policy</a>
                        </label>
                    </div>

                    <div>
                        <button type="submit" class="flex w-full justify-center rounded-lg bg-blue-600 px-4 py-3 text-sm font-semibold text-white shadow-sm hover:bg-blue-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600 transition-colors">
                            Create account
                            <i class="fa-solid fa-arrow-right ml-2"></i>
                        </button>
                    </div>
                </form>

                <!-- Divider -->
                <div class="mt-10">
                    <div class="relative">
                        <div class="absolute inset-0 flex items-center" aria-hidden="true">
                            <div class="w-full border-t border-gray-200"></div>
                        </div>
                        <div class="relative flex justify-center text-sm font-medium">
                            <span class="bg-white px-6 text-gray-500">What you'll get</span>
                        </div>
                    </div>

                    <!-- Features -->
                    <ul class="mt-6 space-y-3 text-sm text-gray-600">
                        <li class="flex items-center gap-3">
                            <i class="fa-solid fa-circle-check text-green-500"></i>
                            <span>Unlimited digital business cards</span>
                        </li>
                        <li class="flex items-center gap-3">
                            <i class="fa-solid fa-circle-check text-green-500"></i>
                            <span>Custom branding & templates</span>
                        </li>
                        <li class="flex items-center gap-3">
                            <i class="fa-solid fa-circle-check text-green-500"></i>
                            <span>Team management dashboard</span>
                        </li>
                        <li class="flex items-center gap-3">
                            <i class="fa-solid fa-circle-check text-green-500"></i>
                            <span>14-day free trial, no credit card</span>
                        </li>
                    </ul>
                </div>

                <!-- Back to Home -->
                <p class="mt-10 text-center text-sm text-gray-500">
                    <a href="<?php echo getBasePath(); ?>" class="font-medium text-gray-700 hover:text-gray-900">
                        <i class="fa-solid fa-arrow-left mr-1"></i>
                        Back to homepage
                    </a>
                </p>
            </div>
        </div>

        <!-- Right Side - Background Image -->
        <div class="relative hidden w-0 flex-1 lg:block">
            <img class="absolute inset-0 h-full w-full object-cover" 
                 src="<?php echo assetUrl('images/salient/background-auth.jpg'); ?>" 
                 alt="">
            <!-- Overlay -->
            <div class="absolute inset-0 bg-gradient-to-t from-blue-900/90 via-blue-800/70 to-blue-600/50"></div>
            
            <!-- Content on image -->
            <div class="absolute inset-0 flex flex-col justify-end p-12 text-white">
                <div class="max-w-lg">
                    <blockquote class="text-xl font-medium leading-relaxed">
                        "Cardify transformed how we manage business cards across our organization. Setup took minutes and our team loves it."
                    </blockquote>
                    <div class="mt-6 flex items-center gap-4">
                        <img class="h-12 w-12 rounded-full object-cover ring-2 ring-white/30" 
                             src="<?php echo assetUrl('images/users/bonnie-green.png'); ?>" 
                             alt="">
                        <div>
                            <p class="font-semibold">Sarah Johnson</p>
                            <p class="text-sm text-blue-200">Head of Marketing, TechCorp</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
