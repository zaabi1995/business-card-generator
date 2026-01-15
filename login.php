<?php
/**
 * Cardify - Login Page
 * Design: Tailwind UI Split Screen + Flowbite + Salient
 */
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/Auth.php';

// Redirect if already logged in
if (Auth::isLoggedIn()) {
    $role = Auth::getCurrentRole();
    $redirect = $role === 'super_admin' ? getBasePath() . 'admin/super/' : getBasePath() . 'admin/';
    header('Location: ' . $redirect);
    exit;
}

$error = null;
$showCompanyField = false;
$brandName = defined('SITE_NAME') ? SITE_NAME : 'Cardify';

// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = sanitizeEmail($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $companySlug = $_POST['company'] ?? null;
    
    if (empty($email) || empty($password)) {
        $error = 'Please enter email and password';
    } else {
        if (empty($companySlug) && DatabaseAdapter::useDatabase()) {
            try {
                $db = Database::getInstance();
                $company = $db->fetchOne(
                    "SELECT slug FROM companies WHERE admin_email = :email AND status = 'active'",
                    ['email' => $email]
                );
                if ($company && !empty($company['slug'])) {
                    $companySlug = $company['slug'];
                }
            } catch (Exception $e) {}
        }

        $result = Auth::login($email, $password, $companySlug);
        
        if ($result['success']) {
            header('Location: ' . $result['redirect']);
            exit;
        } else {
            $error = $result['error'] ?? 'Invalid credentials';
            $showCompanyField = true;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-white">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In - <?php echo $brandName; ?></title>
    <link rel="icon" href="<?php echo getBasePath(); ?>favicon.svg" type="image/svg+xml">
    
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="<?php echo getBasePath(); ?>assets/vendor/css/all.css">
    
    <!-- Flowbite CSS -->
    <link rel="stylesheet" href="<?php echo assetUrl('flowbite/app.css'); ?>">
    
    <style>
        body { font-family: 'Inter', sans-serif; }
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
    </style>
</head>
<body class="h-full">
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
                        Sign in to your account
                    </h2>
                    <p class="mt-2 text-sm text-gray-600">
                        Not a member?
                        <a href="<?php echo getBasePath(); ?>company/register.php" class="font-semibold text-blue-600 hover:text-blue-500">
                            Start a 14 day free trial
                        </a>
                    </p>
                </div>

                <!-- Error Message -->
                <?php if ($error): ?>
                <div class="mt-6 flex items-center gap-3 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-800">
                    <i class="fa-solid fa-circle-exclamation flex-shrink-0"></i>
                    <div>
                        <span><?php echo htmlspecialchars($error); ?></span>
                        <?php if ($showCompanyField): ?>
                        <p class="mt-1 text-xs">If you're an employee, please enter your company code below.</p>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Login Form -->
                <form method="POST" class="mt-10 space-y-6">
                    <div>
                        <label for="email" class="block text-sm font-medium text-gray-900">
                            Email address
                        </label>
                        <div class="mt-2">
                            <input type="email" name="email" id="email" autocomplete="email"
                                   value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                                   class="form-input" 
                                   placeholder="name@company.com" required>
                        </div>
                    </div>

                    <div>
                        <label for="password" class="block text-sm font-medium text-gray-900">
                            Password
                        </label>
                        <div class="mt-2">
                            <input type="password" name="password" id="password" autocomplete="current-password"
                                   class="form-input" 
                                   placeholder="••••••••" required>
                        </div>
                    </div>

                    <?php if ($showCompanyField || isset($_GET['employee'])): ?>
                    <div>
                        <label for="company" class="block text-sm font-medium text-gray-900">
                            Company code
                            <span class="font-normal text-gray-500">(for employees)</span>
                        </label>
                        <div class="mt-2">
                            <input type="text" name="company" id="company" 
                                   value="<?php echo htmlspecialchars($_POST['company'] ?? $_GET['company'] ?? ''); ?>"
                                   class="form-input" 
                                   placeholder="e.g., my-company">
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <input id="remember" name="remember" type="checkbox"
                                   class="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-600">
                            <label for="remember" class="text-sm text-gray-900">Remember me</label>
                        </div>
                        <a href="#" class="text-sm font-semibold text-blue-600 hover:text-blue-500">
                            Forgot password?
                        </a>
                    </div>

                    <div>
                        <button type="submit" class="flex w-full justify-center rounded-lg bg-blue-600 px-4 py-3 text-sm font-semibold text-white shadow-sm hover:bg-blue-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600 transition-colors">
                            Sign in
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
                            <span class="bg-white px-6 text-gray-500">Or continue with</span>
                        </div>
                    </div>

                    <div class="mt-6">
                        <?php if (!$showCompanyField && !isset($_GET['employee'])): ?>
                        <a href="?employee=1" class="flex w-full items-center justify-center gap-3 rounded-lg bg-gray-50 px-4 py-3 text-sm font-semibold text-gray-700 ring-1 ring-gray-200 hover:bg-gray-100 transition-colors">
                            <i class="fa-solid fa-id-badge text-blue-600"></i>
                            Sign in as Employee
                        </a>
                        <?php else: ?>
                        <a href="<?php echo getBasePath(); ?>login.php" class="flex w-full items-center justify-center gap-3 rounded-lg bg-gray-50 px-4 py-3 text-sm font-semibold text-gray-700 ring-1 ring-gray-200 hover:bg-gray-100 transition-colors">
                            <i class="fa-solid fa-building text-blue-600"></i>
                            Sign in as Company Admin
                        </a>
                        <?php endif; ?>
                    </div>
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
                 src="<?php echo assetUrl('images/authentication/login.jpg'); ?>" 
                 alt="">
            <!-- Overlay -->
            <div class="absolute inset-0 bg-gradient-to-t from-gray-900/80 via-gray-800/50 to-transparent"></div>
            
            <!-- Content on image -->
            <div class="absolute inset-0 flex flex-col justify-end p-12 text-white">
                <div class="max-w-lg">
                    <h3 class="text-3xl font-bold">
                        Welcome back to <?php echo $brandName; ?>
                    </h3>
                    <p class="mt-4 text-lg text-gray-200 leading-relaxed">
                        Manage your digital business cards, employees, and company branding all in one place.
                    </p>
                    
                    <!-- Stats -->
                    <div class="mt-8 flex gap-8">
                        <div>
                            <p class="text-3xl font-bold">10k+</p>
                            <p class="text-sm text-gray-300">Active users</p>
                        </div>
                        <div>
                            <p class="text-3xl font-bold">500+</p>
                            <p class="text-sm text-gray-300">Companies</p>
                        </div>
                        <div>
                            <p class="text-3xl font-bold">99.9%</p>
                            <p class="text-sm text-gray-300">Uptime</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Flowbite JS -->
    <script src="<?php echo assetUrl('flowbite/app.bundle.js'); ?>"></script>
</body>
</html>
