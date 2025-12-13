<?php
/**
 * Admin Login Page - BHD Business Cards
 */
require_once __DIR__ . '/../config.php';

// Redirect if already logged in
if (isMultiTenantEnabled() ? isCompanyAdminLoggedIn() : isAdminLoggedIn()) {
    header('Location: index.php');
    exit;
}

$error = null;

// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    $password = $_POST['password'];

    // Multi-tenant: company admin login (company slug + password)
    if (isMultiTenantEnabled()) {
        $company = $_POST['company'] ?? '';
        $result = companyAdminLogin($company, $password);
        if (!empty($result['success'])) {
            header('Location: index.php');
            exit;
        }
        $error = $result['error'] ?? 'Invalid company or password. Please try again.';
    } else {
        // Single-tenant legacy login
        if (loginAdmin($password)) {
            header('Location: index.php');
            exit;
        } else {
            $error = 'Invalid password. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login | <?php echo SITE_NAME; ?></title>
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Tailwind CSS -->
    <link rel="stylesheet" href="<?php echo assetUrl('css/tailwind.css'); ?>?v=<?php echo filemtime(ASSETS_DIR . '/css/tailwind.css'); ?>">
    
    <style>
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
            border-color: rgba(212, 175, 55, 0.6);
            box-shadow: 0 0 0 4px rgba(212, 175, 55, 0.1);
        }
        
        .btn-bhd {
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);
            background-size: 200% 100%;
            transition: all 0.3s ease;
            border: 1px solid rgba(212, 175, 55, 0.3);
        }
        
        .btn-bhd:hover {
            animation: shimmer 1.5s linear infinite;
            box-shadow: 0 0 30px rgba(212, 175, 55, 0.3);
            border-color: rgba(212, 175, 55, 0.6);
        }
        
        @keyframes shimmer {
            0% { background-position: -200% 0; }
            100% { background-position: 200% 0; }
        }
        
        .ambient-bg {
            position: fixed;
            inset: 0;
            pointer-events: none;
            background: 
                radial-gradient(ellipse at 30% 40%, rgba(212, 175, 55, 0.05) 0%, transparent 50%),
                radial-gradient(ellipse at 70% 60%, rgba(15, 52, 96, 0.08) 0%, transparent 50%);
        }
    </style>
</head>
<body class="bg-alzayani-dark text-white font-sans min-h-screen antialiased">
    <div class="ambient-bg"></div>
    
    <div class="min-h-screen flex items-center justify-center px-4">
        <div class="w-full max-w-md">
            <!-- Logo -->
            <div class="text-center mb-8">
                <div class="w-20 h-20 mx-auto mb-6 rounded-2xl bg-gradient-to-br from-amber-500/20 to-amber-600/10 flex items-center justify-center border border-amber-500/30">
                    <svg class="w-10 h-10 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V8a2 2 0 00-2-2h-5m-4 0V5a2 2 0 114 0v1m-4 0a2 2 0 104 0m-5 8a2 2 0 100-4 2 2 0 000 4zm0 0c1.306 0 2.417.835 2.83 2M9 14a3.001 3.001 0 00-2.83 2M15 11h3m-3 4h2"></path>
                    </svg>
                </div>
                <h1 class="text-3xl font-bold text-white"><?php echo SITE_NAME; ?></h1>
                <p class="text-gray-400 mt-2">Admin Panel Login</p>
            </div>
            
            <!-- Login Card -->
            <div class="glass-card rounded-2xl p-8">
                <?php if ($error): ?>
                <div class="mb-6 p-4 rounded-xl bg-red-500/10 border border-red-500/30">
                    <div class="flex items-center space-x-3">
                        <svg class="w-5 h-5 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        <span class="text-red-400 text-sm"><?php echo sanitize($error); ?></span>
                    </div>
                </div>
                <?php endif; ?>
                
                <form method="post" class="space-y-6">
                    <?php if (isMultiTenantEnabled()): ?>
                    <div>
                        <label for="company" class="block text-sm font-medium text-gray-300 mb-2">
                            Company Code
                        </label>
                        <input 
                            type="text" 
                            id="company" 
                            name="company" 
                            required
                            placeholder="e.g., acme"
                            class="input-bhd w-full px-5 py-4 rounded-xl text-white placeholder-gray-500 focus:outline-none"
                            value="<?php echo isset($_POST['company']) ? sanitize($_POST['company']) : ''; ?>"
                        >
                    </div>
                    <?php endif; ?>
                    <div>
                        <label for="password" class="block text-sm font-medium text-gray-300 mb-2">
                            Password
                        </label>
                        <input 
                            type="password" 
                            id="password" 
                            name="password" 
                            required
                            autofocus
                            placeholder="Enter admin password"
                            class="input-bhd w-full px-5 py-4 rounded-xl text-white placeholder-gray-500 focus:outline-none"
                        >
                    </div>
                    
                    <button 
                        type="submit" 
                        class="btn-bhd w-full py-4 rounded-xl text-white font-bold text-lg"
                    >
                        Sign In
                    </button>
                </form>
            </div>
            
            <!-- Back Link -->
            <div class="text-center mt-6">
                <a href="<?php echo getBasePath(); ?>" class="text-gray-500 hover:text-amber-400 transition-colors text-sm">
                    ← Back to Card Generator
                </a>
            </div>
        </div>
    </div>
</body>
</html>

