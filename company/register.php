<?php
/**
 * Company Registration (Multi-tenant)
 */
require_once __DIR__ . '/../config.php';

$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = $_POST['company_name'] ?? '';
    $email = $_POST['admin_email'] ?? '';
    $password = $_POST['password'] ?? '';
    $customSlug = $_POST['company_slug'] ?? null;

    $result = createCompany($name, $email, $password, null, $customSlug);
    if (!empty($result['success'])) {
        $company = $result['company'];
        // auto-login
        companyAdminLogin($company['slug'], $password);
        header('Location: ' . getBasePath() . 'admin/');
        exit;
    }
    $error = $result['error'] ?? 'Failed to create company';
}
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Company | <?php echo SITE_NAME; ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo assetUrl('css/tailwind.css'); ?>?v=<?php echo filemtime(ASSETS_DIR . '/css/tailwind.css'); ?>">
    <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>
        .glass-card { background: rgba(255, 255, 255, 0.03); backdrop-filter: blur(20px); border: 1px solid rgba(255, 255, 255, 0.1); box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.6); }
        .input-bhd { background: rgba(255, 255, 255, 0.05); border: 1px solid rgba(255, 255, 255, 0.15); transition: all 0.3s ease; }
        .input-bhd:focus { background: rgba(255, 255, 255, 0.08); border-color: rgba(212, 175, 55, 0.6); box-shadow: 0 0 0 4px rgba(212, 175, 55, 0.1); }
        .btn-bhd { background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%); transition: all 0.3s ease; border: 1px solid rgba(212, 175, 55, 0.3); }
        .btn-bhd:hover { box-shadow: 0 0 30px rgba(212, 175, 55, 0.3); border-color: rgba(212, 175, 55, 0.6); transform: translateY(-1px); }
        .ambient-bg { position: fixed; inset: 0; pointer-events: none; background: radial-gradient(ellipse at 20% 30%, rgba(212, 175, 55, 0.04) 0%, transparent 50%), radial-gradient(ellipse at 80% 70%, rgba(15, 52, 96, 0.06) 0%, transparent 50%); }
        .slug-preview { background: rgba(212, 175, 55, 0.1); border: 1px solid rgba(212, 175, 55, 0.3); }
        [x-cloak] { display: none !important; }
    </style>
</head>
<body class="bg-alzayani-dark text-white font-sans min-h-screen antialiased">
    <div class="ambient-bg"></div>

    <div class="min-h-screen flex items-center justify-center px-4 py-12">
        <div class="w-full max-w-lg">
            <div class="text-center mb-8">
                <h1 class="text-3xl font-bold text-white">Create your company</h1>
                <p class="text-gray-400 mt-2">Set up your portal to manage employees & templates</p>
            </div>

            <div class="glass-card rounded-2xl p-8">
                <?php if ($error): ?>
                <div class="mb-6 p-4 rounded-xl bg-red-500/10 border border-red-500/30 text-red-400 text-sm">
                    <?php echo sanitize($error); ?>
                </div>
                <?php endif; ?>

                <form method="post" class="space-y-5" x-data="companyRegistration()" @submit.prevent="submitForm()">
                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-2">Company name *</label>
                        <input 
                            name="company_name" 
                            x-model="companyName"
                            @input="generateSlug()"
                            required 
                            class="input-bhd w-full px-5 py-4 rounded-xl text-white" 
                            placeholder="e.g., Mohsin Haider Darwish"
                        >
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-2">Company URL abbreviation *</label>
                        <div class="flex items-center space-x-2">
                            <span class="text-gray-400 text-sm whitespace-nowrap"><?php echo getBaseUrl(); ?></span>
                            <input 
                                type="text" 
                                name="company_slug" 
                                x-model="companySlug"
                                @input="checkSlugAvailability()"
                                @blur="validateSlug()"
                                required
                                pattern="[a-z0-9-]+"
                                maxlength="50"
                                class="input-bhd flex-1 px-5 py-4 rounded-xl text-white font-mono" 
                                placeholder="mhd"
                            >
                            <span class="text-gray-400 text-sm whitespace-nowrap">/</span>
                        </div>
                        <p class="text-xs text-gray-500 mt-2">
                            <span x-show="slugStatus === 'checking'">Checking availability...</span>
                            <span x-show="slugStatus === 'available'" class="text-green-400">✓ Available! Your URL: <code class="bg-black/30 px-1 rounded"><?php echo getBaseUrl(); ?><span x-text="companySlug"></span>/</code></span>
                            <span x-show="slugStatus === 'taken'" class="text-red-400">✗ This abbreviation is already taken</span>
                            <span x-show="slugStatus === 'invalid'" class="text-red-400">✗ Invalid format (use lowercase letters, numbers, and hyphens only)</span>
                            <span x-show="slugStatus === 'empty'" class="text-gray-500">Auto-generated from company name</span>
                        </p>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-2">Admin email *</label>
                        <input type="email" name="admin_email" required class="input-bhd w-full px-5 py-4 rounded-xl text-white" placeholder="admin@company.com">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-2">Password *</label>
                        <input type="password" name="password" required minlength="6" class="input-bhd w-full px-5 py-4 rounded-xl text-white" placeholder="Min 6 characters">
                    </div>
                    <button 
                        type="submit" 
                        :disabled="slugStatus === 'taken' || slugStatus === 'invalid' || slugStatus === 'checking'"
                        class="btn-bhd w-full py-4 rounded-xl text-white font-bold text-lg disabled:opacity-50 disabled:cursor-not-allowed"
                    >
                        Create Company
                    </button>
                </form>
                
                <script>
                    function companyRegistration() {
                        return {
                            companyName: '',
                            companySlug: '',
                            slugStatus: 'empty',
                            checkTimeout: null,
                            
                            generateSlug() {
                                if (!this.companyName) {
                                    this.companySlug = '';
                                    this.slugStatus = 'empty';
                                    return;
                                }
                                
                                // Generate abbreviation from company name
                                // Example: "Mohsin Haider Darwish" -> "mhd"
                                const words = this.companyName.trim().split(/\s+/);
                                let abbreviation = '';
                                
                                if (words.length >= 2) {
                                    // Take first letter of each word (alphanumeric only)
                                    words.forEach(word => {
                                        const firstChar = word.charAt(0);
                                        if (/[a-zA-Z0-9]/.test(firstChar)) {
                                            abbreviation += firstChar.toLowerCase();
                                        }
                                    });
                                } else if (words.length === 1) {
                                    // Single word: take first 3-5 alphanumeric characters
                                    const word = words[0];
                                    for (let i = 0; i < Math.min(5, word.length); i++) {
                                        const char = word.charAt(i);
                                        if (/[a-zA-Z0-9]/.test(char)) {
                                            abbreviation += char.toLowerCase();
                                        }
                                    }
                                }
                                
                                // Clean up: only lowercase letters and numbers
                                abbreviation = abbreviation.replace(/[^a-z0-9]/g, '');
                                
                                // If user hasn't manually edited, update slug
                                if (!this.companySlug || this.companySlug === this.lastAutoSlug) {
                                    this.companySlug = abbreviation;
                                    this.lastAutoSlug = abbreviation;
                                    this.checkSlugAvailability();
                                }
                            },
                            
                            validateSlug() {
                                if (!this.companySlug) {
                                    this.slugStatus = 'empty';
                                    return;
                                }
                                
                                // Check format: only lowercase letters, numbers, hyphens
                                if (!/^[a-z0-9-]+$/.test(this.companySlug)) {
                                    this.slugStatus = 'invalid';
                                    return;
                                }
                                
                                // Check length
                                if (this.companySlug.length < 2) {
                                    this.slugStatus = 'invalid';
                                    return;
                                }
                                
                                this.checkSlugAvailability();
                            },
                            
                            checkSlugAvailability() {
                                if (!this.companySlug || this.companySlug.length < 2) {
                                    this.slugStatus = 'empty';
                                    return;
                                }
                                
                                // Clear previous timeout
                                if (this.checkTimeout) {
                                    clearTimeout(this.checkTimeout);
                                }
                                
                                this.slugStatus = 'checking';
                                
                                // Debounce: wait 500ms after user stops typing
                                this.checkTimeout = setTimeout(() => {
                                    fetch('<?php echo getBasePath(); ?>api/check-slug.php?slug=' + encodeURIComponent(this.companySlug))
                                        .then(response => response.json())
                                        .then(data => {
                                            this.slugStatus = data.available ? 'available' : 'taken';
                                        })
                                        .catch(() => {
                                            this.slugStatus = 'available'; // Assume available on error
                                        });
                                }, 500);
                            },
                            
                            submitForm() {
                                if (this.slugStatus === 'available' || this.slugStatus === 'empty') {
                                    this.$el.submit();
                                }
                            }
                        };
                    }
                </script>
            </div>

            <div class="text-center mt-6 text-sm text-gray-500">
                Already have a company? <a class="text-amber-400 hover:text-amber-300" href="<?php echo getBasePath(); ?>company/login.php">Login</a>
            </div>
        </div>
    </div>
</body>
</html>


