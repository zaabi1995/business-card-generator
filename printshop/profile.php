<?php
/**
 * Print Shop Profile - Manage public profile and account
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/PrintShop.php';
require_once INCLUDES_DIR . '/Currency.php';

Auth::requireLogin();
$user = Auth::getCurrentUser();

if ($user['role'] !== 'print_shop' && $user['role'] !== 'super_admin') {
    header('Location: ' . getBasePath() . 'login.php');
    exit;
}

$printShop = PrintShop::getByUserId($user['id']);
if (!$printShop && $user['role'] !== 'super_admin') {
    header('Location: ' . getBasePath() . 'printshop/register.php');
    exit;
}

// For super admin
if ($user['role'] === 'super_admin' && isset($_GET['shop'])) {
    $printShop = PrintShop::getById((int)$_GET['shop']);
}

if (!$printShop) {
    header('Location: ' . getBasePath() . 'admin/print_shops.php');
    exit;
}

$shopId = $printShop['id'];
$message = null;
$messageType = 'success';

$db = Database::getInstance();
$pdo = $db->getConnection();

// Handle updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !validateCSRFToken($_POST['csrf_token'] ?? '')) {
    die('Invalid request');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_profile') {
        $result = PrintShop::update($shopId, [
            'name' => trim($_POST['name'] ?? ''),
            'description' => trim($_POST['description'] ?? ''),
            'tagline' => trim($_POST['tagline'] ?? ''),
            'email' => trim($_POST['email'] ?? ''),
            'phone' => trim($_POST['phone'] ?? ''),
            'website' => trim($_POST['website'] ?? ''),
            'facebook' => trim($_POST['facebook'] ?? ''),
            'instagram' => trim($_POST['instagram'] ?? ''),
            'twitter' => trim($_POST['twitter'] ?? ''),
            'address' => trim($_POST['address'] ?? ''),
            'city' => trim($_POST['city'] ?? ''),
            'state' => trim($_POST['state'] ?? ''),
            'country' => trim($_POST['country'] ?? ''),
            'postal_code' => trim($_POST['postal_code'] ?? '')
        ]);
        
        $message = $result['success'] ? 'Profile updated successfully!' : 'Error: ' . ($result['error'] ?? 'Unknown');
        $messageType = $result['success'] ? 'success' : 'error';
        $printShop = PrintShop::getById($shopId);
        
    } elseif ($action === 'change_password') {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        
        if (empty($currentPassword) || empty($newPassword)) {
            $message = 'Please fill in all password fields';
            $messageType = 'error';
        } elseif ($newPassword !== $confirmPassword) {
            $message = 'New passwords do not match';
            $messageType = 'error';
        } elseif (strlen($newPassword) < 8) {
            $message = 'Password must be at least 8 characters';
            $messageType = 'error';
        } else {
            // Verify current password
            $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
            $stmt->execute([$user['id']]);
            $userData = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($userData && password_verify($currentPassword, $userData['password_hash'])) {
                $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), $user['id']]);
                $message = 'Password changed successfully!';
            } else {
                $message = 'Current password is incorrect';
                $messageType = 'error';
            }
        }
    } elseif ($action === 'upload_logo') {
        if (isset($_FILES['logo']) && $_FILES['logo']['error'] !== UPLOAD_ERR_NO_FILE) {
            $result = PrintShop::uploadLogo($shopId, $_FILES['logo']);
            if ($result['success']) {
                $message = 'Logo uploaded successfully!';
                $printShop = PrintShop::getById($shopId);
            } else {
                $message = $result['error'] ?? 'Error uploading file';
                $messageType = 'error';
            }
        }
    }
}

require_once INCLUDES_DIR . '/printshop-layout.php';
printshopHeader(t('printshoppages.title_profile', ['shop' => $printShop['name']]), 'settings');
?>
    <div class="max-w-4xl mx-auto">
        <!-- Header -->
        <div class="flex items-center justify-between mb-8">
            <div>
                <h1 class="text-2xl font-bold text-gray-900"><?= htmlspecialchars(t("printshoppages.h1_shop_profile")) ?></h1>
                <p class="text-gray-500">Manage your public profile and account settings</p>
            </div>
            <a href="dashboard.php" class="px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg font-medium transition-colors">
                <i class="fa-solid fa-arrow-left mr-2"></i>
                Dashboard
            </a>
        </div>
        
        <?php if ($message): ?>
        <div class="mb-6 p-4 rounded-xl <?php echo $messageType === 'success' ? 'bg-green-50 border border-green-200 text-green-700' : 'bg-red-50 border border-red-200 text-red-700'; ?> flex items-center gap-3">
            <i class="fa-solid <?php echo $messageType === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
            <?php echo sanitize($message); ?>
        </div>
        <?php endif; ?>
        
        <!-- Public Profile Link -->
        <div class="bg-gradient-to-r from-purple-500 to-blue-500 rounded-xl p-6 mb-6 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="font-semibold text-lg">Your Public Profile</h3>
                    <p class="text-white/80 text-sm mt-1">Share this link with customers</p>
                </div>
                <a href="<?php echo getBasePath(); ?>print-shops.php#<?php echo $printShop['slug']; ?>" target="_blank" 
                   class="px-4 py-2 bg-white/20 hover:bg-white/30 rounded-lg font-medium transition-colors flex items-center gap-2">
                    <i class="fa-solid fa-external-link"></i>
                    View Profile
                </a>
            </div>
        </div>
        
        <div class="space-y-6">
            
            <!-- Logo Upload -->
            <form method="post" enctype="multipart/form-data" class="bg-white rounded-xl border border-gray-200 overflow-hidden">
                <div class="p-6 border-b border-gray-100">
                    <h3 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
                        <i class="fa-solid fa-image text-purple-600"></i>
                        Shop Logo
                    </h3>
                </div>
                <div class="p-6">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="upload_logo">
                    <div class="flex items-center gap-6">
                        <div class="w-24 h-24 bg-gray-100 rounded-xl flex items-center justify-center overflow-hidden border-2 border-gray-200">
                            <?php if (!empty($printShop['logo_path'])): ?>
                            <img src="<?php echo getBasePath() . ltrim(sanitize($printShop['logo_path']), '/'); ?>" alt="Logo" class="w-full h-full object-cover">
                            <?php else: ?>
                            <span class="text-3xl font-bold text-gray-400"><?php echo strtoupper(substr($printShop['name'], 0, 2)); ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="flex-1">
                            <input aria-label="Logo" type="file" name="logo" accept="image/jpeg,image/png,image/webp" 
                                   class="w-full px-4 py-2.5 border border-gray-200 rounded-lg text-gray-700 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-purple-50 file:text-purple-700 hover:file:bg-purple-100">
                            <p class="text-sm text-gray-500 mt-2">JPG, PNG or WebP. Max 2MB. Recommended: 200x200px</p>
                        </div>
                        <button type="submit" class="px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-lg font-medium transition-colors">
                            Upload
                        </button>
                    </div>
                </div>
            </form>
            
            <!-- Public Info -->
            <form method="post" class="bg-white rounded-xl border border-gray-200 overflow-hidden">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="update_profile">
                <div class="p-6 border-b border-gray-100">
                    <h3 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
                        <i class="fa-solid fa-store text-blue-600"></i>
                        Public Information
                    </h3>
                    <p class="text-sm text-gray-500 mt-1">This information is visible to customers</p>
                </div>
                <div class="p-6 space-y-4">
                    <div class="grid md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Shop Name *</label>
                            <input aria-label="Shop Name *" type="text" name="name" value="<?php echo sanitize($printShop['name']); ?>" required
                                   class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Tagline</label>
                            <input aria-label="Tagline" type="text" name="tagline" value="<?php echo sanitize($printShop['tagline'] ?? ''); ?>"
                                   placeholder="Your shop's slogan"
                                   class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                        </div>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Description</label>
                        <textarea aria-label="Description" name="description" rows="4"
                                  placeholder="Tell customers about your print shop, services, and what makes you special..."
                                  class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20"><?php echo sanitize($printShop['description'] ?? ''); ?></textarea>
                    </div>
                    
                    <div class="grid md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Email</label>
                            <input aria-label="Email" type="email" name="email" value="<?php echo sanitize($printShop['email']); ?>" required
                                   class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                        </div>
                        <div>
                            <?php echo Currency::renderSimplePhoneInput([
                                'name' => 'phone',
                                'id' => 'profile-phone',
                                'country_name' => 'phone_country',
                                'country_id' => 'profile-phone-country',
                                'selected_country' => $printShop['country'] ?? 'OM',
                                'value' => $printShop['phone'] ?? '',
                                'label' => 'Phone',
                                'placeholder' => 'Enter phone number'
                            ]); ?>
                        </div>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Website</label>
                        <input aria-label="Website" type="url" name="website" value="<?php echo sanitize($printShop['website'] ?? ''); ?>"
                               placeholder="https://yourshop.com"
                               class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                    </div>
                    
                    <div class="pt-4 border-t border-gray-100">
                        <label class="block text-sm font-semibold text-gray-700 mb-3">Social Media</label>
                        <div class="grid md:grid-cols-3 gap-4">
                            <div class="relative">
                                <span class="absolute left-4 top-1/2 -translate-y-1/2 text-gray-400"><i class="fa-brands fa-facebook"></i></span>
                                <input aria-label="Social Media" type="text" name="facebook" value="<?php echo sanitize($printShop['facebook'] ?? ''); ?>"
                                       placeholder="Facebook URL"
                                       class="w-full pl-11 pr-4 py-2.5 border border-gray-200 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                            </div>
                            <div class="relative">
                                <span class="absolute left-4 top-1/2 -translate-y-1/2 text-gray-400"><i class="fa-brands fa-instagram"></i></span>
                                <input aria-label="Social Media" type="text" name="instagram" value="<?php echo sanitize($printShop['instagram'] ?? ''); ?>"
                                       placeholder="Instagram URL"
                                       class="w-full pl-11 pr-4 py-2.5 border border-gray-200 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                            </div>
                            <div class="relative">
                                <span class="absolute left-4 top-1/2 -translate-y-1/2 text-gray-400"><i class="fa-brands fa-x-twitter"></i></span>
                                <input aria-label="Social Media" type="text" name="twitter" value="<?php echo sanitize($printShop['twitter'] ?? ''); ?>"
                                       placeholder="X/Twitter URL"
                                       class="w-full pl-11 pr-4 py-2.5 border border-gray-200 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                            </div>
                        </div>
                    </div>
                    
                    <div class="pt-4 border-t border-gray-100">
                        <label class="block text-sm font-semibold text-gray-700 mb-3">Location</label>
                        <div>
                            <input aria-label="Location" type="text" name="address" value="<?php echo sanitize($printShop['address'] ?? ''); ?>"
                                   placeholder="Street address"
                                   class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 mb-3">
                        </div>
                        <div class="grid md:grid-cols-4 gap-4">
                            <div>
                                <label class="block mb-2 text-sm font-medium text-gray-900 dark:text-white">City</label>
                                <input aria-label="City" type="text" name="city" value="<?php echo sanitize($printShop['city'] ?? ''); ?>"
                                       placeholder="City" required
                                       class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                            </div>
                            <div>
                                <label class="block mb-2 text-sm font-medium text-gray-900 dark:text-white">State/Region</label>
                                <input aria-label="State/Region" type="text" name="state" value="<?php echo sanitize($printShop['state'] ?? ''); ?>"
                                       placeholder="State/Region"
                                       class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                            </div>
                            <div>
                                <label class="block mb-2 text-sm font-medium text-gray-900 dark:text-white">Country</label>
                                <select aria-label="Country" name="country" id="profile-country" required
                                        class="w-full px-4 py-2.5 bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                                    <?php echo Currency::getCountryOptions($printShop['country'] ?? 'OM'); ?>
                                </select>
                            </div>
                            <div>
                                <label class="block mb-2 text-sm font-medium text-gray-900 dark:text-white">Postal Code</label>
                                <input aria-label="Postal Code" type="text" name="postal_code" value="<?php echo sanitize($printShop['postal_code'] ?? ''); ?>"
                                       placeholder="Postal Code"
                                       class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="px-6 py-4 bg-gray-50 border-t border-gray-100 flex justify-end">
                    <button type="submit" class="px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition-colors">
                        Save Profile
                    </button>
                </div>
            </form>
            
            <!-- Change Password -->
            <form method="post" class="bg-white rounded-xl border border-gray-200 overflow-hidden">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="change_password">
                <div class="p-6 border-b border-gray-100">
                    <h3 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
                        <i class="fa-solid fa-lock text-red-600"></i>
                        Change Password
                    </h3>
                </div>
                <div class="p-6 space-y-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Current Password</label>
                        <input aria-label="Current Password" type="password" name="current_password" autocomplete="current-password"
                               class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                    </div>
                    
                    <div class="grid md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">New Password</label>
                            <input aria-label="New Password" type="password" name="new_password" autocomplete="new-password"
                                   class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Confirm New Password</label>
                            <input aria-label="Confirm New Password" type="password" name="confirm_password" autocomplete="new-password"
                                   class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                        </div>
                    </div>
                    <p class="text-sm text-gray-500">Password must be at least 8 characters</p>
                </div>
                <div class="px-6 py-4 bg-gray-50 border-t border-gray-100 flex justify-end">
                    <button type="submit" class="px-6 py-2.5 bg-red-600 hover:bg-red-700 text-white rounded-lg font-medium transition-colors">
                        Change Password
                    </button>
                </div>
            </form>
            
            <!-- Account Status -->
            <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
                <div class="p-6 border-b border-gray-100">
                    <h3 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
                        <i class="fa-solid fa-shield-check text-green-600"></i>
                        Account Status
                    </h3>
                </div>
                <div class="p-6">
                    <div class="grid md:grid-cols-3 gap-6">
                        <div class="text-center p-4 bg-gray-50 rounded-lg">
                            <p class="text-sm text-gray-500 mb-1">Status</p>
                            <span class="inline-flex px-3 py-1 rounded-full text-sm font-medium <?php 
                                echo $printShop['status'] === 'active' ? 'bg-green-100 text-green-700' : 
                                    ($printShop['status'] === 'pending' ? 'bg-amber-100 text-amber-700' : 'bg-gray-100 text-gray-700'); 
                            ?>">
                                <?php echo ucfirst($printShop['status']); ?>
                            </span>
                        </div>
                        <div class="text-center p-4 bg-gray-50 rounded-lg">
                            <p class="text-sm text-gray-500 mb-1">Verified</p>
                            <?php if (!empty($printShop['is_verified'])): ?>
                            <span class="inline-flex items-center gap-1 text-blue-600 font-medium">
                                <i class="fa-solid fa-circle-check"></i> Yes
                            </span>
                            <?php else: ?>
                            <span class="text-gray-400">Not yet</span>
                            <?php endif; ?>
                        </div>
                        <div class="text-center p-4 bg-gray-50 rounded-lg">
                            <p class="text-sm text-gray-500 mb-1">Member Since</p>
                            <span class="font-medium text-gray-900"><?php echo date('M Y', dbTs($printShop['created_at'])); ?></span>
                        </div>
                    </div>
                </div>
            </div>
            
        </div>
    </div>
</div>

<?php echo Currency::getPhoneInputJavaScript(); ?>
<?php echo Currency::getJavaScriptHelper(); ?>
</div>
<?php printshopFooter(); ?>
