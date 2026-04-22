<?php
/**
 * Print Shop Registration
 * Allows print shops to register and offer services
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/PrintShop.php';
require_once INCLUDES_DIR . '/Currency.php';
require_once INCLUDES_DIR . '/Mailer.php';

$message = null;
$messageType = 'success';
$registrationComplete = false;

// Check if already logged in as print shop
if (Auth::isLoggedIn()) {
    $user = Auth::getCurrentUser();
    if ($user['role'] === 'print_shop') {
        header('Location: ' . getBasePath() . 'printshop/dashboard.php');
        exit;
    }
}

// Handle registration
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !validateCSRFToken($_POST['csrf_token'] ?? '')) {
    die('Invalid request');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $shopName = trim($_POST['shop_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $phone = trim($_POST['phone'] ?? '');
    $website = trim($_POST['website'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $state = trim($_POST['state'] ?? '');
    $country = trim($_POST['country'] ?? '');
    $postalCode = trim($_POST['postal_code'] ?? '');
    $currency = strtoupper(trim($_POST['currency'] ?? 'USD'));
    
    // Validation
    $errors = [];
    
    if (empty($shopName)) {
        $errors[] = 'Shop name is required';
    }
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Valid email is required';
    }
    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters';
    }
    if ($password !== $confirmPassword) {
        $errors[] = 'Passwords do not match';
    }
    if (empty($city) || empty($country)) {
        $errors[] = 'City and country are required';
    }
    
    // Check if email exists
    if (empty($errors) && Auth::emailExists($email)) {
        $errors[] = 'This email is already registered';
    }
    
    if (empty($errors)) {
        $db = Database::getInstance();
        $pdo = $db->getConnection();
        
        try {
            $pdo->beginTransaction();
            
            // Generate UUID for user
            $userId = generateUUID();
            
            // Create user account
            $stmt = $pdo->prepare("INSERT INTO users (id, email, password_hash, name, role, status, created_at) VALUES (?, ?, ?, ?, 'print_shop', 'active', NOW())");
            $stmt->execute([$userId, $email, password_hash($password, PASSWORD_DEFAULT), $shopName]);
            
            // Create print shop
            $result = PrintShop::create([
                'name' => $shopName,
                'email' => $email,
                'phone' => $phone,
                'website' => $website,
                'description' => $description,
                'address' => $address,
                'city' => $city,
                'state' => $state,
                'country' => $country,
                'postal_code' => $postalCode,
                'currency' => $currency,
                'user_id' => $userId,
                'status' => 'pending' // Requires admin approval
            ]);
            
            if ($result['success']) {
                $pdo->commit();
                $registrationComplete = true;
                $message = 'Registration successful! Your print shop is pending approval.';
                $messageType = 'success';
                
                // Send welcome email
                $siteName = defined('SITE_NAME') ? SITE_NAME : 'Cardify';
                $location = implode(', ', array_filter([$city, $state, $country]));
                Mailer::sendTemplate($email, 'welcome_print_shop', [
                    'site_name' => $siteName,
                    'shop_name' => $shopName,
                    'email' => $email,
                    'location' => $location,
                    'dashboard_url' => getBaseUrl() . 'printshop/dashboard.php'
                ]);
            } else {
                throw new Exception($result['error']);
            }
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $message = 'Registration failed: ' . $e->getMessage();
            $messageType = 'error';
        }
    } else {
        $message = implode('<br>', $errors);
        $messageType = 'error';
    }
}

$pageTitle = t('printshoppages.title_register');
$bodyClass = 'bg-gray-50';
$minimalFooter = true; // compact footer for auth page
require_once INCLUDES_DIR . '/ui-header.php';
?>

<div class="min-h-screen flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8">
    <div class="max-w-2xl w-full">
        <!-- Header -->
        <div class="text-center mb-8">
            <a href="<?php echo getBasePath(); ?>" class="inline-flex items-center gap-2 text-2xl font-bold text-gray-900 mb-4">
                <img src="<?php echo getBasePath(); ?>assets/images/logo.svg" alt="Cardify" class="h-10 w-auto">
            </a>
            <h1 class="text-3xl font-bold text-gray-900"><?= htmlspecialchars(t("printshoppages.h1_register")) ?></h1>
            <p class="mt-2 text-gray-600">Join our marketplace and receive business card orders from companies</p>
        </div>
        
        <?php if ($message): ?>
        <div class="mb-6 p-4 rounded-xl <?php echo $messageType === 'success' ? 'bg-green-50 border border-green-200 text-green-700' : 'bg-red-50 border border-red-200 text-red-700'; ?>">
            <div class="flex items-center gap-3">
                <i class="fa-solid <?php echo $messageType === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
                <span><?php echo $message; ?></span>
            </div>
        </div>
        <?php endif; ?>
        
        <?php if ($registrationComplete): ?>
        <div class="bg-white rounded-2xl shadow-lg p-8 text-center">
            <div class="w-20 h-20 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-6">
                <i class="fa-solid fa-check text-green-600 text-3xl"></i>
            </div>
            <h2 class="text-2xl font-bold text-gray-900 mb-4">Registration Submitted!</h2>
            <p class="text-gray-600 mb-6">
                Your print shop registration is pending approval. We'll review your application and notify you via email once approved.
            </p>
            <div class="flex items-center justify-center gap-4">
                <a href="<?php echo getBasePath(); ?>login.php" class="px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white rounded-xl font-medium transition-colors">
                    Sign In
                </a>
                <a href="<?php echo getBasePath(); ?>" class="px-6 py-3 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-xl font-medium transition-colors">
                    Back to Home
                </a>
            </div>
        </div>
        <?php else: ?>
        <form method="post" class="bg-white rounded-2xl shadow-lg overflow-hidden">
            <?php echo csrfField(); ?>
            <!-- Shop Information -->
            <div class="p-6 border-b border-gray-100">
                <h3 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
                    <i class="fa-solid fa-store text-blue-600"></i>
                    Shop Information
                </h3>
            </div>
            <div class="p-6 space-y-4">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Shop Name *</label>
                    <input type="text" name="shop_name" required
                           value="<?php echo sanitize($_POST['shop_name'] ?? ''); ?>"
                           class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20"
                           placeholder="ABC Print Services">
                </div>
                
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Description</label>
                    <textarea name="description" rows="3"
                              class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20"
                              placeholder="Tell companies about your print shop and services..."><?php echo sanitize($_POST['description'] ?? ''); ?></textarea>
                </div>
                
                <div class="grid md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Email *</label>
                        <input type="email" name="email" required
                               value="<?php echo sanitize($_POST['email'] ?? ''); ?>"
                               class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20"
                               placeholder="orders@printshop.com">
                    </div>
                    <div>
                        <?php echo Currency::renderSimplePhoneInput([
                            'name' => 'phone',
                            'id' => 'shop-phone',
                            'country_name' => 'phone_country',
                            'country_id' => 'phone-country',
                            'selected_country' => $_POST['phone_country'] ?? 'OM',
                            'value' => $_POST['phone'] ?? '',
                            'placeholder' => 'Enter phone number',
                            'label' => 'Phone',
                            'show_label' => true
                        ]); ?>
                    </div>
                </div>
                
                <div class="grid md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Website</label>
                        <input type="url" name="website"
                               value="<?php echo sanitize($_POST['website'] ?? ''); ?>"
                               class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20"
                               placeholder="https://www.yourprintshop.com">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Currency</label>
                        <select name="currency" id="register-currency" 
                                class="w-full px-4 py-3 bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-xl focus:ring-blue-500 focus:border-blue-500">
                            <?php echo Currency::getCurrencyOptions($_POST['currency'] ?? 'OMR'); ?>
                        </select>
                    </div>
                </div>
            </div>
            
            <!-- Location -->
            <div class="p-6 border-t border-b border-gray-100 bg-gray-50">
                <h3 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
                    <i class="fa-solid fa-location-dot text-green-600"></i>
                    Location
                </h3>
            </div>
            <div class="p-6 space-y-4">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Address</label>
                    <input type="text" name="address"
                           value="<?php echo sanitize($_POST['address'] ?? ''); ?>"
                           class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20"
                           placeholder="123 Print Street">
                </div>
                
                <div class="grid md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">City *</label>
                        <input type="text" name="city" required
                               value="<?php echo sanitize($_POST['city'] ?? ''); ?>"
                               class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20"
                               placeholder="New York">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">State/Province</label>
                        <input type="text" name="state"
                               value="<?php echo sanitize($_POST['state'] ?? ''); ?>"
                               class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20"
                               placeholder="NY">
                    </div>
                </div>
                
                <div class="grid md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Country *</label>
                        <select name="country" id="register-country" required
                                onchange="CardifyGeo.updateCurrencyFromCountry(this.value, 'register-currency')"
                                class="w-full px-4 py-3 bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-xl focus:ring-blue-500 focus:border-blue-500">
                            <?php echo Currency::getCountryOptions($_POST['country'] ?? 'OM'); ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Postal Code</label>
                        <input type="text" name="postal_code"
                               value="<?php echo sanitize($_POST['postal_code'] ?? ''); ?>"
                               class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20"
                               placeholder="10001">
                    </div>
                </div>
            </div>
            
            <!-- Account -->
            <div class="p-6 border-t border-b border-gray-100 bg-gray-50">
                <h3 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
                    <i class="fa-solid fa-lock text-purple-600"></i>
                    Account Credentials
                </h3>
            </div>
            <div class="p-6 space-y-4">
                <div class="grid md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Password *</label>
                        <input type="password" name="password" required minlength="8" autocomplete="new-password"
                               class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20"
                               placeholder="Min 8 characters">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Confirm Password *</label>
                        <input type="password" name="confirm_password" required autocomplete="new-password"
                               class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20"
                               placeholder="Confirm password">
                    </div>
                </div>
            </div>
            
            <!-- Submit -->
            <div class="p-6 bg-gray-50 border-t border-gray-100">
                <button type="submit" class="w-full py-3 bg-blue-600 hover:bg-blue-700 text-white rounded-xl font-semibold transition-colors flex items-center justify-center gap-2">
                    <i class="fa-solid fa-paper-plane"></i>
                    Submit Registration
                </button>
                <p class="text-center text-sm text-gray-500 mt-4">
                    Already have an account? <a href="<?php echo getBasePath(); ?>login.php" class="text-blue-600 hover:text-blue-700 font-medium">Sign in</a>
                </p>
            </div>
        </form>
        <?php endif; ?>
        
        <!-- Benefits -->
        <div class="mt-8 grid md:grid-cols-3 gap-4">
            <div class="bg-white rounded-xl p-4 text-center">
                <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center mx-auto mb-3">
                    <i class="fa-solid fa-users text-blue-600"></i>
                </div>
                <h4 class="font-semibold text-gray-900">Reach Companies</h4>
                <p class="text-sm text-gray-500 mt-1">Connect with businesses looking for print services</p>
            </div>
            <div class="bg-white rounded-xl p-4 text-center">
                <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center mx-auto mb-3">
                    <i class="fa-solid fa-chart-line text-green-600"></i>
                </div>
                <h4 class="font-semibold text-gray-900">Grow Revenue</h4>
                <p class="text-sm text-gray-500 mt-1">Receive orders directly through the platform</p>
            </div>
            <div class="bg-white rounded-xl p-4 text-center">
                <div class="w-12 h-12 bg-purple-100 rounded-lg flex items-center justify-center mx-auto mb-3">
                    <i class="fa-solid fa-bolt text-purple-600"></i>
                </div>
                <h4 class="font-semibold text-gray-900">Easy Management</h4>
                <p class="text-sm text-gray-500 mt-1">Manage orders and track deliveries easily</p>
            </div>
        </div>
    </div>
</div>

<?php echo Currency::getPhoneInputJavaScript(); ?>
<?php echo Currency::getJavaScriptHelper(); ?>
<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
