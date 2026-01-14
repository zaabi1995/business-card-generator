<?php
/**
 * Unified Login Page
 * Handles login for super admin, company admin, and employees
 * Automatically detects user type and redirects accordingly
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

// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = sanitizeEmail($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $companySlug = $_POST['company'] ?? null;
    
    if (empty($email) || empty($password)) {
        $error = 'Please enter email and password';
    } else {
        $result = Auth::login($email, $password, $companySlug);
        
        if ($result['success']) {
            header('Location: ' . $result['redirect']);
            exit;
        } else {
            $error = $result['error'] ?? 'Invalid credentials';
            // Show company field if login failed (might be company login)
            $showCompanyField = true;
        }
    }
}

// Get company theme if available
$companyTheme = null;
if (isset($_GET['company'])) {
    $company = findCompanyBySlug($_GET['company']);
    if ($company && DatabaseAdapter::useDatabase()) {
        $db = Database::getInstance();
        $companyTheme = $db->fetchOne(
            "SELECT * FROM company_themes WHERE company_id = :id",
            ['id' => $company['id']]
        );
    }
}

// Apply company theme colors
$primaryColor = $companyTheme['primary_color'] ?? '#d4af37';
$secondaryColor = $companyTheme['secondary_color'] ?? '#0f3460';
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | <?php echo SITE_NAME; ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo assetUrl('css/tailwind.css'); ?>?v=<?php echo filemtime(ASSETS_DIR . '/css/tailwind.css'); ?>">
    <style>
        :root {
            --primary-color: <?php echo $primaryColor; ?>;
            --secondary-color: <?php echo $secondaryColor; ?>;
        }
        .glass-card { 
            background: rgba(255, 255, 255, 0.03); 
            backdrop-filter: blur(20px); 
            border: 1px solid rgba(255, 255, 255, 0.1); 
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.6); 
        }
        .input-bhd { 
            background: rgba(255, 255, 255, 0.05); 
            border: 1px solid rgba(255, 255, 255, 0.15); 
            transition: all 0.3s ease; 
        }
        .input-bhd:focus { 
            background: rgba(255, 255, 255, 0.08); 
            border-color: var(--primary-color); 
            box-shadow: 0 0 0 4px rgba(212, 175, 55, 0.1); 
        }
        .btn-bhd { 
            background: linear-gradient(135deg, var(--secondary-color) 0%, #16213e 50%, #0f3460 100%); 
            transition: all 0.3s ease; 
            border: 1px solid var(--primary-color); 
        }
        .btn-bhd:hover { 
            box-shadow: 0 0 30px var(--primary-color); 
            border-color: var(--primary-color); 
            transform: translateY(-1px); 
        }
        .ambient-bg { 
            position: fixed; 
            inset: 0; 
            pointer-events: none; 
            background: 
                radial-gradient(ellipse at 20% 30%, rgba(212, 175, 55, 0.04) 0%, transparent 50%), 
                radial-gradient(ellipse at 80% 70%, rgba(15, 52, 96, 0.06) 0%, transparent 50%); 
        }
        .role-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            background: rgba(212, 175, 55, 0.2);
            color: var(--primary-color);
            border: 1px solid var(--primary-color);
        }
    </style>
</head>
<body class="bg-alzayani-dark text-white font-sans min-h-screen antialiased">
    <div class="ambient-bg"></div>

    <div class="min-h-screen flex items-center justify-center px-4 py-12">
        <div class="w-full max-w-lg">
            <div class="text-center mb-8">
                <?php if ($companyTheme && $companyTheme['logo_path']): ?>
                <img src="<?php echo imageUrl($companyTheme['logo_path']); ?>" alt="Logo" class="h-16 mx-auto mb-4">
                <?php else: ?>
                <div class="w-20 h-20 mx-auto mb-6 rounded-2xl bg-gradient-to-br from-amber-500/20 to-amber-600/10 flex items-center justify-center border border-amber-500/30">
                    <svg class="w-10 h-10 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V8a2 2 0 00-2-2h-5m-4 0V5a2 2 0 114 0v1m-4 0a2 2 0 104 0m-5 8a2 2 0 100-4 2 2 0 000 4zm0 0c1.306 0 2.417.835 2.83 2M9 14a3.001 3.001 0 00-2.83 2M15 11h3m-3 4h2"></path>
                    </svg>
                </div>
                <?php endif; ?>
                <h1 class="text-3xl font-bold text-white"><?php echo $companyTheme['header_text'] ?? SITE_NAME; ?></h1>
                <p class="text-gray-400 mt-2">Sign in to your account</p>
                <?php if (isset($_GET['company'])): ?>
                <span class="role-badge mt-2">Company Login</span>
                <?php endif; ?>
            </div>

            <div class="glass-card rounded-2xl p-8">
                <?php if ($error): ?>
                <div class="mb-6 p-4 rounded-xl bg-red-500/10 border border-red-500/30 text-red-400 text-sm">
                    <div class="flex items-center space-x-2">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        <span><?php echo sanitize($error); ?></span>
                    </div>
                </div>
                <?php endif; ?>

                <form method="post" class="space-y-5">
                    <?php if ($showCompanyField || isset($_GET['company'])): ?>
                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-2">Company Code (Optional)</label>
                        <input 
                            type="text" 
                            name="company" 
                            value="<?php echo sanitize($_GET['company'] ?? $_POST['company'] ?? ''); ?>"
                            class="input-bhd w-full px-5 py-4 rounded-xl text-white" 
                            placeholder="e.g., acme"
                        >
                        <p class="text-xs text-gray-500 mt-2">Leave empty for super admin or employee login</p>
                    </div>
                    <?php endif; ?>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-2">Email</label>
                        <input 
                            type="email" 
                            name="email" 
                            required 
                            autofocus
                            value="<?php echo sanitize($_POST['email'] ?? ''); ?>"
                            class="input-bhd w-full px-5 py-4 rounded-xl text-white" 
                            placeholder="your@email.com"
                        >
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-2">Password</label>
                        <input 
                            type="password" 
                            name="password" 
                            required 
                            class="input-bhd w-full px-5 py-4 rounded-xl text-white" 
                            placeholder="Your password"
                        >
                    </div>
                    
                    <button type="submit" class="btn-bhd w-full py-4 rounded-xl text-white font-bold text-lg">
                        Sign In
                    </button>
                </form>
                
                <div class="mt-6 pt-6 border-t border-white/10">
                    <div class="text-center text-sm text-gray-500 space-y-2">
                        <p>New company? <a class="text-amber-400 hover:text-amber-300" href="<?php echo getBasePath(); ?>company/register.php">Create account</a></p>
                        <p><a class="text-gray-400 hover:text-white" href="<?php echo getBasePath(); ?>">← Back to Card Generator</a></p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
