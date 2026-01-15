<?php
/**
 * Cardify - Company Registration
 * Design: Tailwind UI Split Screen + Flowbite + Salient
 */
require_once __DIR__ . '/../config.php';

$error = null;
$brandName = defined('SITE_NAME') ? SITE_NAME : 'Cardify';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = $_POST['company_name'] ?? '';
    $email = $_POST['admin_email'] ?? '';
    $password = $_POST['password'] ?? '';
    $customSlug = $_POST['company_slug'] ?? null;

    $result = createCompany($name, $email, $password, null, $customSlug);
    if (!empty($result['success'])) {
        $company = $result['company'];
        companyAdminLogin($company['slug'], $password);
        header('Location: ' . getBasePath() . 'admin/');
        exit;
    }
    $error = $result['error'] ?? 'Failed to create company';
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-white">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Account - <?php echo $brandName; ?></title>
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

                <!-- Registration Form -->
                <form method="POST" class="mt-10 space-y-6">
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

                    <div>
                        <label for="company_slug" class="block text-sm font-medium text-gray-900">
                            Company URL
                            <span class="font-normal text-gray-500">(optional)</span>
                        </label>
                        <div class="mt-2">
                            <input type="text" name="company_slug" id="company_slug" 
                                   value="<?php echo htmlspecialchars($_POST['company_slug'] ?? ''); ?>"
                                   class="form-input" 
                                   placeholder="acme-corp">
                        </div>
                        <p class="mt-1.5 text-xs text-gray-500">Leave empty to auto-generate from company name</p>
                    </div>

                    <div>
                        <label for="admin_email" class="block text-sm font-medium text-gray-900">
                            Admin email address
                        </label>
                        <div class="mt-2">
                            <input type="email" name="admin_email" id="admin_email" 
                                   value="<?php echo htmlspecialchars($_POST['admin_email'] ?? ''); ?>"
                                   class="form-input" 
                                   placeholder="admin@company.com" required>
                        </div>
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
    
    <!-- Flowbite JS -->
    <script src="<?php echo assetUrl('flowbite/app.bundle.js'); ?>"></script>
</body>
</html>
