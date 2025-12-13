<?php
/**
 * BHD Business Cards - Main Entry Point
 * User enters email to generate their business card
 */
require_once __DIR__ . '/config.php';

$error = null;
$employee = null;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['email'])) {
    $email = sanitizeEmail($_POST['email']);
    
    if (!isValidEmail($email)) {
        $error = 'Please enter a valid email address.';
    } else {
        $employee = findEmployeeByEmail($email);
        
        if (!$employee) {
            $error = 'Email not found. Please contact your administrator to be added to the system.';
        }
    }
}

// If employee found, redirect to card generation
if ($employee) {
    $params = http_build_query([
        'id' => $employee['id'],
        'email' => $employee['email']
    ]);
    header('Location: generate_card_html.php?' . $params);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo SITE_NAME; ?></title>
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Cairo:wght@400;500;600;700&display=swap" rel="stylesheet">
    
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
            transition: all 0.3s ease;
            border: 1px solid rgba(212, 175, 55, 0.3);
        }
        
        .btn-bhd:hover {
            box-shadow: 0 0 30px rgba(212, 175, 55, 0.3);
            border-color: rgba(212, 175, 55, 0.6);
            transform: translateY(-2px);
        }
        
        .ambient-bg {
            position: fixed;
            inset: 0;
            pointer-events: none;
            background: 
                radial-gradient(ellipse at 20% 30%, rgba(212, 175, 55, 0.04) 0%, transparent 50%),
                radial-gradient(ellipse at 80% 70%, rgba(15, 52, 96, 0.06) 0%, transparent 50%);
        }
        
        .card-preview {
            perspective: 1000px;
        }
        
        .card-inner {
            transition: transform 0.6s;
            transform-style: preserve-3d;
        }
        
        .card-preview:hover .card-inner {
            transform: rotateY(10deg) rotateX(5deg);
        }
    </style>
</head>
<body class="bg-alzayani-dark text-white font-sans min-h-screen antialiased">
    <div class="ambient-bg"></div>
    
    <div class="min-h-screen flex items-center justify-center px-4 py-12">
        <div class="w-full max-w-lg">
            <!-- Header -->
            <div class="text-center mb-10">
                <div class="card-preview inline-block mb-6">
                    <div class="card-inner">
                        <div class="w-24 h-16 mx-auto rounded-lg bg-gradient-to-br from-amber-500/30 to-amber-600/10 flex items-center justify-center border border-amber-500/40 shadow-xl">
                            <svg class="w-10 h-6 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V8a2 2 0 00-2-2h-5m-4 0V5a2 2 0 114 0v1m-4 0a2 2 0 104 0m-5 8a2 2 0 100-4 2 2 0 000 4zm0 0c1.306 0 2.417.835 2.83 2M9 14a3.001 3.001 0 00-2.83 2M15 11h3m-3 4h2"></path>
                            </svg>
                        </div>
                    </div>
                </div>
                <h1 class="text-4xl font-bold text-white mb-3"><?php echo SITE_NAME; ?></h1>
                <p class="text-gray-400 text-lg"><?php echo SITE_DESCRIPTION; ?></p>
            </div>
            
            <!-- Main Card -->
            <div class="glass-card rounded-2xl p-8 md:p-10">
                <div class="text-center mb-8">
                    <h2 class="text-xl font-semibold text-white mb-2">Generate Your Business Card</h2>
                    <p class="text-gray-400 text-sm">Enter your company email to create your personalized business card</p>
                </div>
                
                <?php if ($error): ?>
                <div class="mb-6 p-4 rounded-xl bg-red-500/10 border border-red-500/30">
                    <div class="flex items-center space-x-3">
                        <svg class="w-5 h-5 text-red-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        <span class="text-red-400 text-sm"><?php echo sanitize($error); ?></span>
                    </div>
                </div>
                <?php endif; ?>
                
                <form method="post" class="space-y-6">
                    <div>
                        <label for="email" class="block text-sm font-medium text-gray-300 mb-2">
                            Email Address
                        </label>
                        <input 
                            type="email" 
                            id="email" 
                            name="email" 
                            required
                            autofocus
                            placeholder="your.name@company.com"
                            class="input-bhd w-full px-5 py-4 rounded-xl text-white placeholder-gray-500 focus:outline-none text-lg"
                            value="<?php echo isset($_POST['email']) ? sanitize($_POST['email']) : ''; ?>"
                        >
                    </div>
                    
                    <button 
                        type="submit" 
                        class="btn-bhd w-full py-4 rounded-xl text-white font-bold text-lg flex items-center justify-center space-x-3"
                    >
                        <span>Generate Business Card</span>
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"></path>
                        </svg>
                    </button>
                </form>
                
                <!-- Info -->
                <div class="mt-8 pt-6 border-t border-white/10">
                    <div class="flex items-start space-x-3 text-sm text-gray-500">
                        <svg class="w-5 h-5 text-amber-500/60 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        <p>Your business card will be generated with the information on file. If you need to update your details, please contact your administrator.</p>
                    </div>
                </div>
            </div>
            
            <!-- Admin Link -->
            <div class="text-center mt-8">
                <a href="<?php echo getBasePath(); ?>admin/" class="text-gray-600 hover:text-amber-400 transition-colors text-sm">
                    Admin Panel
                </a>
            </div>
            
            <!-- Footer -->
            <div class="text-center mt-6 text-gray-600 text-xs">
                &copy; <?php echo date('Y'); ?> <?php echo SITE_NAME; ?>. All rights reserved.
            </div>
        </div>
    </div>
</body>
</html>

