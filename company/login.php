<?php
/**
 * Company Admin Login (Multi-tenant)
 */
require_once __DIR__ . '/../config.php';

// If already logged in, go to admin
if (isCompanyAdminLoggedIn()) {
    header('Location: ' . getBasePath() . 'admin/');
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $slug = $_POST['company'] ?? '';
    $password = $_POST['password'] ?? '';

    $result = companyAdminLogin($slug, $password);
    if (!empty($result['success'])) {
        header('Location: ' . getBasePath() . 'admin/');
        exit;
    }
    $error = $result['error'] ?? 'Login failed';
}
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Company Login | <?php echo SITE_NAME; ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo assetUrl('css/tailwind.css'); ?>?v=<?php echo filemtime(ASSETS_DIR . '/css/tailwind.css'); ?>">
    <style>
        .glass-card { background: rgba(255, 255, 255, 0.03); backdrop-filter: blur(20px); border: 1px solid rgba(255, 255, 255, 0.1); box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.6); }
        .input-bhd { background: rgba(255, 255, 255, 0.05); border: 1px solid rgba(255, 255, 255, 0.15); transition: all 0.3s ease; }
        .input-bhd:focus { background: rgba(255, 255, 255, 0.08); border-color: rgba(212, 175, 55, 0.6); box-shadow: 0 0 0 4px rgba(212, 175, 55, 0.1); }
        .btn-bhd { background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%); transition: all 0.3s ease; border: 1px solid rgba(212, 175, 55, 0.3); }
        .btn-bhd:hover { box-shadow: 0 0 30px rgba(212, 175, 55, 0.3); border-color: rgba(212, 175, 55, 0.6); transform: translateY(-1px); }
        .ambient-bg { position: fixed; inset: 0; pointer-events: none; background: radial-gradient(ellipse at 20% 30%, rgba(212, 175, 55, 0.04) 0%, transparent 50%), radial-gradient(ellipse at 80% 70%, rgba(15, 52, 96, 0.06) 0%, transparent 50%); }
    </style>
</head>
<body class="bg-alzayani-dark text-white font-sans min-h-screen antialiased">
    <div class="ambient-bg"></div>

    <div class="min-h-screen flex items-center justify-center px-4 py-12">
        <div class="w-full max-w-lg">
            <div class="text-center mb-8">
                <h1 class="text-3xl font-bold text-white">Company Login</h1>
                <p class="text-gray-400 mt-2">Access your admin dashboard</p>
            </div>

            <div class="glass-card rounded-2xl p-8">
                <?php if ($error): ?>
                <div class="mb-6 p-4 rounded-xl bg-red-500/10 border border-red-500/30 text-red-400 text-sm">
                    <?php echo sanitize($error); ?>
                </div>
                <?php endif; ?>

                <form method="post" class="space-y-5">
                    <?php echo csrfField(); ?>
                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-2">Company code</label>
                        <input name="company" required class="input-bhd w-full px-5 py-4 rounded-xl text-white" placeholder="e.g., acme">
                        <p class="text-xs text-gray-500 mt-2">This is your company slug/code created at signup.</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-2">Password</label>
                        <input type="password" name="password" required class="input-bhd w-full px-5 py-4 rounded-xl text-white" placeholder="Your password">
                    </div>
                    <button type="submit" class="btn-bhd w-full py-4 rounded-xl text-white font-bold text-lg">
                        Sign In
                    </button>
                </form>
            </div>

            <div class="text-center mt-6 text-sm text-gray-500">
                New company? <a class="text-amber-400 hover:text-amber-300" href="<?php echo getBasePath(); ?>company/register.php">Create one</a>
            </div>
        </div>
    </div>
</body>
</html>


