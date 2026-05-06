<?php
/**
 * Print Shop Settings - Update shop details and pricing
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/PrintShop.php';
require_once INCLUDES_DIR . '/Currency.php';
require_once INCLUDES_DIR . '/PrintShopOdoo.php';

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

// Handle updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !validateCSRFToken($_POST['csrf_token'] ?? '')) {
    die(htmlspecialchars(t('printshopsettings.invalid_request')));
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'upload_logo') {
        if (isset($_FILES['logo']) && $_FILES['logo']['error'] !== UPLOAD_ERR_NO_FILE) {
            $result = PrintShop::uploadLogo($shopId, $_FILES['logo']);
            $message = $result['success'] ? t('printshopsettings.logo_uploaded') : str_replace(':msg', (string)($result['error'] ?? t('printshopsettings.unknown_error')), t('printshopsettings.error_prefix'));
            $messageType = $result['success'] ? 'success' : 'error';
            $printShop = PrintShop::getById($shopId);
        } else {
            $message = t('printshopsettings.please_select_logo');
            $messageType = 'error';
        }
        
    } elseif ($action === 'delete_logo') {
        $result = PrintShop::deleteLogo($shopId);
        $message = $result['success'] ? t('printshopsettings.logo_deleted') : str_replace(':msg', (string)($result['error'] ?? t('printshopsettings.unknown_error')), t('printshopsettings.error_prefix'));
        $messageType = $result['success'] ? 'success' : 'error';
        $printShop = PrintShop::getById($shopId);
        
    } elseif ($action === 'update_profile') {
        $result = PrintShop::update($shopId, [
            'name' => trim($_POST['name'] ?? ''),
            'description' => trim($_POST['description'] ?? ''),
            'email' => trim($_POST['email'] ?? ''),
            'phone' => trim($_POST['phone'] ?? ''),
            'website' => trim($_POST['website'] ?? ''),
            'address' => trim($_POST['address'] ?? ''),
            'city' => trim($_POST['city'] ?? ''),
            'state' => trim($_POST['state'] ?? ''),
            'country' => trim($_POST['country'] ?? ''),
            'postal_code' => trim($_POST['postal_code'] ?? '')
        ]);
        
        $message = $result['success'] ? t('printshopsettings.profile_updated') : str_replace(':msg', (string)($result['error'] ?? t('printshopsettings.unknown_error')), t('printshopsettings.error_prefix'));
        $messageType = $result['success'] ? 'success' : 'error';
        
        // Refresh data
        $printShop = PrintShop::getById($shopId);
        
    } elseif ($action === 'update_pricing') {
        // Build quantity tiers from form
        $quantityTiers = [];
        $tierQuantities = $_POST['tier_qty'] ?? [];
        $tierPrices = $_POST['tier_price'] ?? [];
        
        for ($i = 0; $i < count($tierQuantities); $i++) {
            $qty = (int)($tierQuantities[$i] ?? 0);
            $price = (float)($tierPrices[$i] ?? 0);
            if ($qty > 0 && $price > 0) {
                $quantityTiers[(string)$qty] = round($price, 3);
            }
        }
        
        $pricing = [
            'per_card' => (float)($_POST['per_card'] ?? 0.10),
            'setup_fee' => (float)($_POST['setup_fee'] ?? 0),
            'shipping_base' => (float)($_POST['shipping_base'] ?? 2.00),
            'quantity_tiers' => $quantityTiers
        ];
        
        $result = PrintShop::update($shopId, [
            'pricing_tiers' => $pricing,
            'base_price_per_card' => (float)($_POST['per_card'] ?? 0.10),
            'setup_fee' => (float)($_POST['setup_fee'] ?? 0),
            'shipping_fee' => (float)($_POST['shipping_base'] ?? 2.00),
            'currency' => strtoupper(trim($_POST['currency'] ?? 'OMR')),
            'min_quantity' => (int)($_POST['min_order_quantity'] ?? 50),
            'turnaround_days' => (int)($_POST['turnaround_days'] ?? 5),
            'offers_express' => isset($_POST['express_available']),
            'express_days' => (int)($_POST['express_days'] ?? 2),
            'express_fee' => (float)($_POST['express_fee'] ?? 0)
        ]);
        
        $message = $result['success'] ? t('printshopsettings.pricing_updated') : str_replace(':msg', (string)($result['error'] ?? t('printshopsettings.unknown_error')), t('printshopsettings.error_prefix'));
        $messageType = $result['success'] ? 'success' : 'error';
        
        $printShop = PrintShop::getById($shopId);
        
    } elseif ($action === 'update_services') {
        $paperTypes = isset($_POST['paper_types']) ? array_keys($_POST['paper_types']) : [];
        $finishes = isset($_POST['finishes']) ? array_keys($_POST['finishes']) : [];
        
        $result = PrintShop::update($shopId, [
            'paper_types' => $paperTypes,
            'finishes' => $finishes
        ]);
        
        $message = $result['success'] ? 'Services updated successfully!' : 'Error: ' . ($result['error'] ?? 'Unknown');
        $messageType = $result['success'] ? 'success' : 'error';
        
        $printShop = PrintShop::getById($shopId);
        
    } elseif ($action === 'update_documents') {
        // Document settings
        $result = PrintShop::update($shopId, [
            'require_quotation' => isset($_POST['require_quotation']),
            'require_po' => isset($_POST['require_po']),
            'auto_invoice' => isset($_POST['auto_invoice']),
            'quotation_validity_days' => (int)($_POST['quotation_validity_days'] ?? 30),
            'payment_terms_days' => (int)($_POST['payment_terms_days'] ?? 30),
            'tax_rate' => (float)($_POST['tax_rate'] ?? 0),
            'tax_number' => trim($_POST['tax_number'] ?? ''),
            'quotation_prefix' => trim($_POST['quotation_prefix'] ?? 'QT-'),
            'invoice_prefix' => trim($_POST['invoice_prefix'] ?? 'INV-'),
            'delivery_note_prefix' => trim($_POST['delivery_note_prefix'] ?? 'DN-')
        ]);
        
        $message = $result['success'] ? 'Document settings updated successfully!' : 'Error: ' . ($result['error'] ?? 'Unknown');
        $messageType = $result['success'] ? 'success' : 'error';
        
        $printShop = PrintShop::getById($shopId);
        
    } elseif ($action === 'update_erp') {
        // ERP Integration settings
        $odoo = new PrintShopOdoo($shopId);
        $result = $odoo->updateSettings([
            'erp_enabled' => isset($_POST['erp_enabled']),
            'erp_system' => $_POST['erp_system'] ?? 'odoo',
            'erp_url' => trim($_POST['erp_url'] ?? ''),
            'erp_database' => trim($_POST['erp_database'] ?? ''),
            'erp_username' => trim($_POST['erp_username'] ?? ''),
            'erp_api_key' => $_POST['erp_api_key'] ?? '',
            'erp_company_id' => trim($_POST['erp_company_id'] ?? ''),
            'erp_sync_enabled' => isset($_POST['erp_sync_enabled'])
        ]);
        
        $message = $result['success'] ? 'ERP settings updated successfully!' : 'Error: ' . ($result['error'] ?? 'Unknown');
        $messageType = $result['success'] ? 'success' : 'error';
        
        $printShop = PrintShop::getById($shopId);
        
    } elseif ($action === 'test_erp') {
        // Test ERP connection
        $odoo = new PrintShopOdoo($shopId);
        $result = $odoo->testConnection();

        $message = $result['success']
            ? 'Connection successful! ' . ($result['message'] ?? '')
            : 'Connection failed: ' . ($result['error'] ?? 'Unknown error');
        $messageType = $result['success'] ? 'success' : 'error';

    } elseif ($action === 'update_capacity') {
        // Capacity & availability settings
        $days = ['monday','tuesday','wednesday','thursday','friday','saturday','sunday'];
        $workingHours = [];
        foreach ($days as $day) {
            $enabled = isset($_POST['wh_' . $day]);
            $open  = trim($_POST['wh_' . $day . '_open']  ?? '08:00');
            $close = trim($_POST['wh_' . $day . '_close'] ?? '17:00');
            $workingHours[$day] = ['enabled' => $enabled, 'open' => $open, 'close' => $close];
        }
        $db = Database::getInstance();
        $db->update('print_shops', [
            'working_hours'    => json_encode($workingHours),
            'max_daily_orders' => (int)($_POST['max_daily_orders'] ?? 0),
            'capacity_notes'   => trim($_POST['capacity_notes'] ?? ''),
            'accepting_orders' => isset($_POST['accepting_orders']) ? 1 : 0,
        ], 'id = :id', ['id' => $shopId]);
        $message = t('printshopsettings.capacity_updated');
        $messageType = 'success';
        $printShop = PrintShop::getById($shopId);
    }
}

// Parse JSON fields
$pricingTiers = json_decode($printShop['pricing_tiers'] ?? '{}', true) ?: [];
$paperTypes = json_decode($printShop['paper_types'] ?? '[]', true) ?: [];
$finishes = json_decode($printShop['finishes'] ?? '[]', true) ?: [];

// Use individual pricing columns or fall back to pricing_tiers for backward compatibility
$basePrice = $printShop['base_price_per_card'] ?? $pricingTiers['per_card'] ?? 0.10;
$setupFee = $printShop['setup_fee'] ?? $pricingTiers['setup_fee'] ?? 0;
$shippingFee = $printShop['shipping_fee'] ?? $pricingTiers['shipping_base'] ?? 2.00;

require_once INCLUDES_DIR . '/printshop-layout.php';
printshopHeader(t('printshoppages.title_settings', ['shop' => $printShop['name']]), 'settings');
?>
    <div class="max-w-4xl mx-auto">
        <!-- Header -->
        <div class="flex items-center justify-between mb-8">
            <div>
                <h1 class="text-2xl font-bold text-gray-900"><?= htmlspecialchars(t("printshoppages.h1_shop_settings")) ?></h1>
                <p class="text-gray-500"><?= htmlspecialchars(t('printshopsettings.page_sub')) ?></p>
            </div>
            <a href="dashboard.php" class="px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg font-medium transition-colors">
                <i class="fa-solid fa-arrow-left mr-2"></i>
                <?= htmlspecialchars(t('printshopsettings.btn_back_dashboard')) ?>
            </a>
        </div>
        
        <?php if ($message): ?>
        <div class="mb-6 p-4 rounded-xl <?php echo $messageType === 'success' ? 'bg-green-50 border border-green-200 text-green-700' : 'bg-red-50 border border-red-200 text-red-700'; ?> flex items-center gap-3">
            <i class="fa-solid <?php echo $messageType === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
            <?php echo sanitize($message); ?>
        </div>
        <?php endif; ?>
        
        <div class="space-y-6">
            
            <!-- Logo Upload -->
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
                <div class="p-6 border-b border-gray-100">
                    <h3 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
                        <i class="fa-solid fa-image text-purple-600"></i>
                        <?= htmlspecialchars(t('printshopsettings.section_logo')) ?>
                    </h3>
                </div>
                <div class="p-6">
                    <div class="flex items-start gap-6">
                        <!-- Current Logo -->
                        <div class="flex-shrink-0">
                            <?php if (!empty($printShop['logo_path'])): ?>
                            <div class="relative group">
                                <img src="<?php echo getBasePath() . ltrim($printShop['logo_path'], '/'); ?>" 
                                     alt="<?php echo sanitize($printShop['name']); ?>" 
                                     class="w-24 h-24 rounded-xl object-cover border border-gray-200 shadow-sm">
                                <form method="post" class="absolute -top-2 -right-2" onsubmit="return confirm('Remove this logo?')">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="action" value="delete_logo">
                                    <button type="submit" class="w-6 h-6 bg-red-500 hover:bg-red-600 text-white rounded-full flex items-center justify-center shadow-sm transition-colors">
                                        <i class="fa-solid fa-times text-xs"></i>
                                    </button>
                                </form>
                            </div>
                            <?php else: ?>
                            <div class="w-24 h-24 bg-gradient-to-br from-purple-500 to-indigo-600 rounded-xl flex items-center justify-center text-white text-2xl font-bold shadow-sm">
                                <?php echo strtoupper(substr($printShop['name'], 0, 2)); ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Upload Form -->
                        <div class="flex-1">
                            <form method="post" enctype="multipart/form-data" class="space-y-4">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="upload_logo">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Upload New Logo</label>
                                    <input type="file" name="logo" accept="image/*"
                                           class="w-full px-4 py-2.5 border border-gray-200 rounded-lg text-sm file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:bg-purple-50 file:text-purple-700 file:font-medium hover:file:bg-purple-100">
                                    <p class="text-xs text-gray-500 mt-1">JPG, PNG, GIF, WebP, or SVG. Max 2MB. Recommended: 200×200px</p>
                                </div>
                                <button type="submit" class="px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-lg text-sm font-medium transition-colors">
                                    <i class="fa-solid fa-upload mr-2"></i>Upload Logo
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Profile -->
            <form method="post" class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="update_profile">
                <div class="p-6 border-b border-gray-100">
                    <h3 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
                        <i class="fa-solid fa-store text-blue-600"></i>
                        <?= htmlspecialchars(t('printshopsettings.section_basic_info')) ?>
                    </h3>
                </div>
                <div class="p-6 space-y-4">
                    <div class="grid md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Shop Name</label>
                            <input type="text" name="name" value="<?php echo sanitize($printShop['name']); ?>" required
                                   class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Email</label>
                            <input type="email" name="email" value="<?php echo sanitize($printShop['email']); ?>" required
                                   class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                        </div>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Description</label>
                        <textarea name="description" rows="3"
                                  class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20"><?php echo sanitize($printShop['description'] ?? ''); ?></textarea>
                    </div>
                    
                    <div class="grid md:grid-cols-2 gap-4">
                        <div>
                            <?php echo Currency::renderSimplePhoneInput([
                                'name' => 'phone',
                                'id' => 'settings-phone',
                                'country_name' => 'phone_country',
                                'country_id' => 'settings-phone-country',
                                'selected_country' => $printShop['country'] ?? 'OM',
                                'value' => $printShop['phone'] ?? '',
                                'label' => 'Phone',
                                'placeholder' => 'Enter phone number'
                            ]); ?>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-900 dark:text-white mb-2">Website</label>
                            <input type="url" name="website" value="<?php echo sanitize($printShop['website'] ?? ''); ?>"
                                   class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                        </div>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Address</label>
                        <input type="text" name="address" value="<?php echo sanitize($printShop['address'] ?? ''); ?>"
                               class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                    </div>
                    
                    <div class="grid md:grid-cols-4 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">City</label>
                            <input type="text" name="city" value="<?php echo sanitize($printShop['city'] ?? ''); ?>"
                                   class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">State</label>
                            <input type="text" name="state" value="<?php echo sanitize($printShop['state'] ?? ''); ?>"
                                   class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-900 dark:text-white mb-2">Country</label>
                            <?php echo Currency::renderCountrySelect([
                                'name' => 'country',
                                'id' => 'settings-country',
                                'selected' => $printShop['country'] ?? 'OM',
                                'currency_id' => 'settings-currency',
                                'class' => 'w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20'
                            ]); ?>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Postal Code</label>
                            <input type="text" name="postal_code" value="<?php echo sanitize($printShop['postal_code'] ?? ''); ?>"
                                   class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                        </div>
                    </div>
                </div>
                <div class="px-6 py-4 bg-gray-50 border-t border-gray-100 flex justify-end">
                    <button type="submit" class="px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition-colors">
                        <?= htmlspecialchars(t('printshopsettings.btn_save_profile')) ?>
                    </button>
                </div>
            </form>
            
            <!-- Pricing -->
            <form method="post" class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="update_pricing">
                <div class="p-6 border-b border-gray-100">
                    <h3 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
                        <i class="fa-solid fa-dollar-sign text-green-600"></i>
                        <?= htmlspecialchars(t('printshopsettings.section_pricing')) ?>
                    </h3>
                </div>
                <div class="p-6 space-y-4">
                    <!-- Currency Selection -->
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Currency</label>
                        <select name="currency" class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 bg-white">
                            <?php echo Currency::getOptions($printShop['currency'] ?? 'USD'); ?>
                        </select>
                    </div>
                    
                    <?php 
                    $currencySymbol = Currency::getSymbol($printShop['currency'] ?? 'OMR'); 
                    $quantityTiers = $pricingTiers['quantity_tiers'] ?? $pricingTiers;
                    // Default tiers if none set
                    if (empty($quantityTiers) || !is_array($quantityTiers)) {
                        $quantityTiers = [
                            '50' => $basePrice,
                            '100' => round($basePrice * 0.95, 3),
                            '200' => round($basePrice * 0.90, 3),
                            '250' => round($basePrice * 0.88, 3),
                            '500' => round($basePrice * 0.85, 3),
                            '1000' => round($basePrice * 0.80, 3),
                            '2000' => round($basePrice * 0.75, 3)
                        ];
                    }
                    ksort($quantityTiers, SORT_NUMERIC);
                    ?>
                    
                    <div class="grid md:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Base Price per Card (<?php echo $currencySymbol; ?>)</label>
                            <input type="number" name="per_card" step="0.001" min="0" value="<?php echo $basePrice; ?>"
                                   class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                            <p class="text-xs text-gray-500 mt-1">Default price if no tier applies</p>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Setup Fee (<?php echo $currencySymbol; ?>)</label>
                            <input type="number" name="setup_fee" step="0.01" min="0" value="<?php echo $setupFee; ?>"
                                   class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                            <p class="text-xs text-gray-500 mt-1">One-time setup charge</p>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Base Shipping (<?php echo $currencySymbol; ?>)</label>
                            <input type="number" name="shipping_base" step="0.01" min="0" value="<?php echo $shippingFee; ?>"
                                   class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                            <p class="text-xs text-gray-500 mt-1">Standard delivery fee</p>
                        </div>
                    </div>
                    
                    <!-- Quantity Tier Pricing -->
                    <div class="mt-6 p-4 bg-green-50 rounded-xl border border-green-100">
                        <h4 class="font-semibold text-green-900 mb-3 flex items-center gap-2">
                            <i class="fa-solid fa-layer-group"></i>
                            Quantity-Based Pricing
                        </h4>
                        <p class="text-sm text-green-700 mb-4">Set different prices per card based on order quantity. Customers ordering more get better rates.</p>
                        
                        <div class="grid grid-cols-3 sm:grid-cols-4 md:grid-cols-7 gap-2" x-data="{ tiers: <?php echo htmlspecialchars(json_encode(array_map(function($k, $v) { return ['qty' => $k, 'price' => $v]; }, array_keys($quantityTiers), array_values($quantityTiers)))); ?> }">
                            <?php foreach ($quantityTiers as $qty => $price): ?>
                            <div class="text-center">
                                <label class="block text-xs font-medium text-green-800 mb-1"><?php echo $qty; ?>+ cards</label>
                                <input type="hidden" name="tier_qty[]" value="<?php echo $qty; ?>">
                                <input type="number" name="tier_price[]" step="0.001" min="0" value="<?php echo $price; ?>"
                                       class="w-full px-2 py-2 text-center border border-green-200 rounded-lg text-sm bg-white focus:border-green-500 focus:ring-1 focus:ring-green-500/20">
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <p class="text-xs text-green-600 mt-3">
                            <i class="fa-solid fa-info-circle mr-1"></i>
                            Price per card in <?php echo $currencySymbol; ?>. Higher quantities should have lower prices.
                        </p>
                    </div>
                    
                    <div class="grid md:grid-cols-2 gap-4 mt-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Minimum Order Quantity</label>
                            <input type="number" name="min_order_quantity" min="1" value="<?php echo $printShop['min_quantity'] ?? 50; ?>"
                                   class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Standard Turnaround (days)</label>
                            <input type="number" name="turnaround_days" min="1" value="<?php echo $printShop['turnaround_days'] ?? 5; ?>"
                                   class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                        </div>
                    </div>
                    
                    <div class="p-4 bg-amber-50 rounded-lg border border-amber-100">
                        <label class="flex items-center gap-3 cursor-pointer">
                            <input type="checkbox" name="express_available" <?php echo !empty($printShop['offers_express']) ? 'checked' : ''; ?>
                                   class="w-5 h-5 rounded text-amber-600 border-gray-300 focus:ring-amber-500">
                            <span class="font-medium text-amber-900">Offer Express Delivery</span>
                        </label>
                        <div class="mt-4 grid md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-amber-800 mb-1">Express Days</label>
                                <input type="number" name="express_days" min="1" value="<?php echo $printShop['express_days'] ?? 2; ?>"
                                       class="w-full px-3 py-2 border border-amber-200 rounded-lg bg-white focus:border-amber-500">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-amber-800 mb-1">Express Fee (<?php echo $currencySymbol; ?>)</label>
                                <input type="number" name="express_fee" step="0.01" min="0" value="<?php echo $printShop['express_fee'] ?? 0; ?>"
                                       class="w-full px-3 py-2 border border-amber-200 rounded-lg bg-white focus:border-amber-500">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="px-6 py-4 bg-gray-50 border-t border-gray-100 flex justify-end">
                    <button type="submit" class="px-6 py-2.5 bg-green-600 hover:bg-green-700 text-white rounded-lg font-medium transition-colors">
                        <?= htmlspecialchars(t('printshopsettings.btn_save_pricing')) ?>
                    </button>
                </div>
            </form>
            
            <!-- Services -->
            <form method="post" class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="update_services">
                <div class="p-6 border-b border-gray-100">
                    <h3 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
                        <i class="fa-solid fa-list-check text-purple-600"></i>
                        <?= htmlspecialchars(t('printshopsettings.section_paper_finish')) ?>
                    </h3>
                </div>
                <div class="p-6 space-y-6">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-3">Paper Types Offered</label>
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                            <?php 
                            $allPaperTypes = ['matte', 'glossy', 'silk', 'uncoated', 'recycled', 'linen', 'cotton'];
                            foreach ($allPaperTypes as $paper): 
                            ?>
                            <label class="flex items-center gap-2 p-3 border border-gray-200 rounded-lg cursor-pointer hover:bg-gray-50">
                                <input type="checkbox" name="paper_types[<?php echo $paper; ?>]" 
                                       <?php echo in_array($paper, $paperTypes) ? 'checked' : ''; ?>
                                       class="rounded text-purple-600 border-gray-300">
                                <span class="text-sm text-gray-700"><?php echo ucfirst($paper); ?></span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-3">Finishes Offered</label>
                        <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
                            <?php 
                            $allFinishes = ['standard', 'rounded_corners', 'spot_uv', 'foil', 'embossed', 'die_cut'];
                            foreach ($allFinishes as $finish): 
                            ?>
                            <label class="flex items-center gap-2 p-3 border border-gray-200 rounded-lg cursor-pointer hover:bg-gray-50">
                                <input type="checkbox" name="finishes[<?php echo $finish; ?>]" 
                                       <?php echo in_array($finish, $finishes) ? 'checked' : ''; ?>
                                       class="rounded text-purple-600 border-gray-300">
                                <span class="text-sm text-gray-700"><?php echo ucfirst(str_replace('_', ' ', $finish)); ?></span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <div class="px-6 py-4 bg-gray-50 border-t border-gray-100 flex justify-end">
                    <button type="submit" class="px-6 py-2.5 bg-purple-600 hover:bg-purple-700 text-white rounded-lg font-medium transition-colors">
                        <?= htmlspecialchars(t('printshopsettings.section_paper_finish')) ?>
                    </button>
                </div>
            </form>
            
            <!-- Document Settings -->
            <form method="post" class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="update_documents">
                <div class="p-6 border-b border-gray-100">
                    <h3 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
                        <i class="fa-solid fa-file-invoice text-indigo-600"></i>
                        <?= htmlspecialchars(t('printshopsettings.section_workflow')) ?>
                    </h3>
                    <p class="text-sm text-gray-500 mt-1">Configure quotations, invoices, and payment terms</p>
                </div>
                <div class="p-6 space-y-6">
                    <!-- Workflow Options -->
                    <div class="space-y-4">
                        <h4 class="font-medium text-gray-900">Order Workflow</h4>
                        <div class="grid md:grid-cols-3 gap-4">
                            <label class="flex items-start gap-3 p-4 border border-gray-200 rounded-lg cursor-pointer hover:bg-gray-50">
                                <input type="checkbox" name="require_quotation" <?php echo ($printShop['require_quotation'] ?? false) ? 'checked' : ''; ?>
                                       class="mt-0.5 rounded text-indigo-600 border-gray-300">
                                <div>
                                    <span class="font-medium text-gray-900">Require Quotation</span>
                                    <p class="text-xs text-gray-500 mt-1">Send quotation before accepting orders</p>
                                </div>
                            </label>
                            <label class="flex items-start gap-3 p-4 border border-gray-200 rounded-lg cursor-pointer hover:bg-gray-50">
                                <input type="checkbox" name="require_po" <?php echo ($printShop['require_po'] ?? false) ? 'checked' : ''; ?>
                                       class="mt-0.5 rounded text-indigo-600 border-gray-300">
                                <div>
                                    <span class="font-medium text-gray-900">Require PO</span>
                                    <p class="text-xs text-gray-500 mt-1">Customer must submit purchase order</p>
                                </div>
                            </label>
                            <label class="flex items-start gap-3 p-4 border border-gray-200 rounded-lg cursor-pointer hover:bg-gray-50">
                                <input type="checkbox" name="auto_invoice" <?php echo ($printShop['auto_invoice'] ?? true) ? 'checked' : ''; ?>
                                       class="mt-0.5 rounded text-indigo-600 border-gray-300">
                                <div>
                                    <span class="font-medium text-gray-900">Auto Invoice</span>
                                    <p class="text-xs text-gray-500 mt-1">Generate invoice when order ships</p>
                                </div>
                            </label>
                        </div>
                    </div>
                    
                    <!-- Document Number Prefixes -->
                    <div class="grid md:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Quotation Prefix</label>
                            <input type="text" name="quotation_prefix" value="<?php echo sanitize($printShop['quotation_prefix'] ?? 'QT-'); ?>"
                                   class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20">
                            <p class="text-xs text-gray-500 mt-1">e.g., QT-2026-0001</p>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Invoice Prefix</label>
                            <input type="text" name="invoice_prefix" value="<?php echo sanitize($printShop['invoice_prefix'] ?? 'INV-'); ?>"
                                   class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20">
                            <p class="text-xs text-gray-500 mt-1">e.g., INV-2026-0001</p>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Delivery Note Prefix</label>
                            <input type="text" name="delivery_note_prefix" value="<?php echo sanitize($printShop['delivery_note_prefix'] ?? 'DN-'); ?>"
                                   class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20">
                            <p class="text-xs text-gray-500 mt-1">e.g., DN-2026-0001</p>
                        </div>
                    </div>
                    
                    <!-- Terms & Tax -->
                    <div class="grid md:grid-cols-4 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Quotation Validity (days)</label>
                            <input type="number" name="quotation_validity_days" min="1" value="<?php echo $printShop['quotation_validity_days'] ?? 30; ?>"
                                   class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Payment Terms (days)</label>
                            <input type="number" name="payment_terms_days" min="0" value="<?php echo $printShop['payment_terms_days'] ?? 30; ?>"
                                   class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Tax Rate (%)</label>
                            <input type="number" name="tax_rate" step="0.01" min="0" max="100" value="<?php echo $printShop['tax_rate'] ?? 0; ?>"
                                   class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Tax Number / VAT</label>
                            <input type="text" name="tax_number" value="<?php echo sanitize($printShop['tax_number'] ?? ''); ?>"
                                   class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20">
                        </div>
                    </div>
                </div>
                <div class="px-6 py-4 bg-gray-50 border-t border-gray-100 flex justify-end">
                    <button type="submit" class="px-6 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg font-medium transition-colors">
                        <?= htmlspecialchars(t('printshopsettings.btn_save_workflow')) ?>
                    </button>
                </div>
            </form>
            
            <!-- ERP Integration -->
            <?php 
            $odoo = new PrintShopOdoo($shopId);
            $erpSettings = $odoo->getSettings();
            $supportedSystems = PrintShopOdoo::getSupportedSystems();
            ?>
            <form method="post" class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden" x-data="{ 
                erpEnabled: <?php echo $erpSettings['erp_enabled'] ? 'true' : 'false'; ?>,
                erpSystem: '<?php echo $erpSettings['erp_system'] ?? 'odoo'; ?>'
            }">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="update_erp">
                <div class="p-6 border-b border-gray-100">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
                                <i class="fa-solid fa-plug text-orange-600"></i>
                                <?= htmlspecialchars(t('printshopsettings.section_erp')) ?>
                            </h3>
                            <p class="text-sm text-gray-500 mt-1">Connect to Odoo or other ERP systems for automatic invoicing</p>
                        </div>
                        <label class="relative inline-flex items-center cursor-pointer">
                            <input type="checkbox" name="erp_enabled" x-model="erpEnabled" class="sr-only peer">
                            <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-orange-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-orange-600"></div>
                        </label>
                    </div>
                </div>
                
                <div class="p-6 space-y-6" x-show="erpEnabled" x-transition>
                    <!-- ERP System Selection -->
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-3">ERP System</label>
                        <div class="grid md:grid-cols-4 gap-3">
                            <?php foreach ($supportedSystems as $key => $system): ?>
                            <label class="relative flex items-center gap-3 p-4 border rounded-lg cursor-pointer transition-all
                                          <?php echo ($system['supported'] ?? false) ? 'border-gray-200 hover:border-orange-300 hover:bg-orange-50' : 'border-gray-100 bg-gray-50 opacity-60 cursor-not-allowed'; ?>"
                                   <?php echo ($system['supported'] ?? false) ? '' : 'title="Coming Soon"'; ?>>
                                <input type="radio" name="erp_system" value="<?php echo $key; ?>" 
                                       x-model="erpSystem"
                                       <?php echo ($system['supported'] ?? false) ? '' : 'disabled'; ?>
                                       class="text-orange-600 border-gray-300 focus:ring-orange-500">
                                <div>
                                    <span class="font-medium text-gray-900"><?php echo $system['name']; ?></span>
                                    <?php if (!empty($system['coming_soon'])): ?>
                                    <span class="ml-2 text-xs bg-gray-200 text-gray-600 px-2 py-0.5 rounded-full">Soon</span>
                                    <?php endif; ?>
                                    <p class="text-xs text-gray-500 mt-0.5"><?php echo $system['description']; ?></p>
                                </div>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <!-- Odoo Configuration -->
                    <div x-show="erpSystem === 'odoo'" class="space-y-4 p-4 bg-orange-50 rounded-lg border border-orange-100">
                        <div class="flex items-center gap-2 text-orange-800">
                            <img src="https://www.odoo.com/web/image/res.company/1/logo?unique=0d3d4f5" alt="Odoo" class="h-6">
                            <span class="font-semibold">Odoo Configuration</span>
                        </div>
                        
                        <div class="grid md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-orange-900 mb-2">Odoo URL</label>
                                <input type="url" name="erp_url" value="<?php echo sanitize($erpSettings['erp_url'] ?? ''); ?>"
                                       placeholder="https://your-instance.odoo.com"
                                       class="w-full px-4 py-2.5 border border-orange-200 rounded-lg bg-white focus:border-orange-500 focus:ring-2 focus:ring-orange-500/20">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-orange-900 mb-2">Database Name</label>
                                <input type="text" name="erp_database" value="<?php echo sanitize($erpSettings['erp_database'] ?? ''); ?>"
                                       placeholder="your_database"
                                       class="w-full px-4 py-2.5 border border-orange-200 rounded-lg bg-white focus:border-orange-500 focus:ring-2 focus:ring-orange-500/20">
                            </div>
                        </div>
                        
                        <div class="grid md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-orange-900 mb-2">Username / Email</label>
                                <input type="text" name="erp_username" value="<?php echo sanitize($erpSettings['erp_username'] ?? ''); ?>"
                                       placeholder="admin@company.com"
                                       class="w-full px-4 py-2.5 border border-orange-200 rounded-lg bg-white focus:border-orange-500 focus:ring-2 focus:ring-orange-500/20">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-orange-900 mb-2">API Key / Password</label>
                                <input type="password" name="erp_api_key" value="<?php echo $erpSettings['erp_api_key'] === '***' ? '' : sanitize($erpSettings['erp_api_key']); ?>"
                                       placeholder="<?php echo $erpSettings['erp_api_key'] === '***' ? '••••••••' : 'Enter API key or password'; ?>"
                                       autocomplete="new-password"
                                       class="w-full px-4 py-2.5 border border-orange-200 rounded-lg bg-white focus:border-orange-500 focus:ring-2 focus:ring-orange-500/20">
                                <p class="text-xs text-orange-700 mt-1">
                                    <i class="fa-solid fa-info-circle mr-1"></i>
                                    Use API key from Odoo Settings → Users → API Keys
                                </p>
                            </div>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-orange-900 mb-2">Company ID (optional)</label>
                            <input type="text" name="erp_company_id" value="<?php echo sanitize($erpSettings['erp_company_id'] ?? ''); ?>"
                                   placeholder="Leave empty for default company"
                                   class="w-full px-4 py-2.5 border border-orange-200 rounded-lg bg-white focus:border-orange-500 focus:ring-2 focus:ring-orange-500/20">
                        </div>
                        
                        <!-- Auto-sync toggle -->
                        <label class="flex items-center gap-3 p-4 bg-white border border-orange-200 rounded-lg cursor-pointer">
                            <input type="checkbox" name="erp_sync_enabled" <?php echo $erpSettings['erp_sync_enabled'] ? 'checked' : ''; ?>
                                   class="rounded text-orange-600 border-orange-300 focus:ring-orange-500">
                            <div>
                                <span class="font-medium text-orange-900">Enable Auto-Sync</span>
                                <p class="text-xs text-orange-700 mt-0.5">Automatically create quotations and invoices in Odoo when orders are placed</p>
                            </div>
                        </label>
                    </div>
                    
                    <!-- Requirements Notice -->
                    <div class="p-4 bg-blue-50 rounded-lg border border-blue-200">
                        <h4 class="font-medium text-blue-900 flex items-center gap-2">
                            <i class="fa-solid fa-circle-info"></i>
                            Requirements
                        </h4>
                        <ul class="mt-2 text-sm text-blue-800 space-y-1">
                            <li><i class="fa-solid fa-check text-blue-600 mr-2"></i>PHP XML-RPC extension must be enabled</li>
                            <li><i class="fa-solid fa-check text-blue-600 mr-2"></i>Odoo user needs Sales & Invoicing permissions</li>
                            <li><i class="fa-solid fa-check text-blue-600 mr-2"></i>XML-RPC must be enabled in Odoo (default)</li>
                        </ul>
                    </div>
                </div>
                
                <div class="px-6 py-4 bg-gray-50 border-t border-gray-100 flex justify-between" x-show="erpEnabled">
                    <button type="submit" name="action" value="test_erp" class="px-4 py-2 border border-orange-300 text-orange-700 hover:bg-orange-50 rounded-lg font-medium transition-colors">
                        <i class="fa-solid fa-plug-circle-check mr-2"></i>Test Connection
                    </button>
                    <button type="submit" class="px-6 py-2.5 bg-orange-600 hover:bg-orange-700 text-white rounded-lg font-medium transition-colors">
                        <?= htmlspecialchars(t('printshopsettings.btn_save_erp')) ?>
                    </button>
                </div>
                
                <div class="p-6 text-center text-gray-500" x-show="!erpEnabled">
                    <i class="fa-solid fa-plug-circle-xmark text-4xl text-gray-300 mb-3"></i>
                    <p>Enable ERP integration to connect your accounting system</p>
                </div>
            </form>
            
        </div>

        <!-- Capacity & Availability -->
        <?php
        $workingHours = json_decode($printShop['working_hours'] ?? 'null', true) ?: [];
        $days = ['monday'=>'Mon','tuesday'=>'Tue','wednesday'=>'Wed','thursday'=>'Thu','friday'=>'Fri','saturday'=>'Sat','sunday'=>'Sun'];
        $defaultHours = ['enabled'=>false,'open'=>'08:00','close'=>'17:00'];
        $maxDaily = (int)($printShop['max_daily_orders'] ?? 0);
        $acceptingOrders = (int)($printShop['accepting_orders'] ?? 1);
        ?>
        <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden mt-8">
            <div class="px-6 py-5 border-b border-gray-100">
                <h2 class="text-lg font-semibold text-gray-900"><i class="fa-solid fa-clock mr-2 text-teal-500"></i><?= htmlspecialchars(t('printshopsettings.section_capacity')) ?></h2>
                <p class="text-sm text-gray-500 mt-1">Set working hours, order limits, and toggle order acceptance</p>
            </div>
            <form method="post" class="p-6 space-y-6">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="update_capacity">

                <!-- Accept orders toggle -->
                <div class="flex items-center justify-between p-4 bg-gray-50 rounded-xl">
                    <div>
                        <p class="font-medium text-gray-900">Accepting Orders</p>
                        <p class="text-sm text-gray-500">Disable to pause new orders temporarily</p>
                    </div>
                    <label class="relative inline-flex items-center cursor-pointer">
                        <input type="checkbox" name="accepting_orders" value="1" class="sr-only peer" <?= $acceptingOrders ? 'checked' : '' ?>>
                        <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-teal-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-teal-500"></div>
                    </label>
                </div>

                <!-- Max daily orders -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Max Daily Orders <span class="text-gray-400 font-normal">(0 = unlimited)</span></label>
                    <input type="number" name="max_daily_orders" value="<?= (int)$maxDaily ?>" min="0" max="9999"
                           class="w-40 border border-gray-200 rounded-xl px-4 py-2 focus:border-teal-500 focus:ring-1 focus:ring-teal-500">
                </div>

                <!-- Working Hours -->
                <div>
                    <p class="text-sm font-medium text-gray-700 mb-3">Working Hours</p>
                    <div class="space-y-2">
                        <?php foreach ($days as $key => $label):
                            $h = $workingHours[$key] ?? $defaultHours;
                        ?>
                        <div class="flex items-center gap-4">
                            <label class="flex items-center gap-2 w-28">
                                <input type="checkbox" name="wh_<?= $key ?>" value="1" class="rounded border-gray-300 text-teal-500 focus:ring-teal-500" <?= !empty($h['enabled']) ? 'checked' : '' ?>>
                                <span class="text-sm font-medium text-gray-700"><?= $label ?></span>
                            </label>
                            <input type="time" name="wh_<?= $key ?>_open" value="<?= htmlspecialchars($h['open'] ?? '08:00') ?>"
                                   class="border border-gray-200 rounded-lg px-3 py-1.5 text-sm focus:border-teal-500">
                            <span class="text-gray-400 text-sm">to</span>
                            <input type="time" name="wh_<?= $key ?>_close" value="<?= htmlspecialchars($h['close'] ?? '17:00') ?>"
                                   class="border border-gray-200 rounded-lg px-3 py-1.5 text-sm focus:border-teal-500">
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Capacity notes -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Capacity Notes <span class="text-gray-400 font-normal">(shown to customers)</span></label>
                    <textarea name="capacity_notes" rows="2" placeholder="e.g. Peak season, expect 7 day turnaround"
                              class="w-full border border-gray-200 rounded-xl px-4 py-2 text-sm focus:border-teal-500 focus:ring-1 focus:ring-teal-500"><?= htmlspecialchars($printShop['capacity_notes'] ?? '') ?></textarea>
                </div>

                <div class="flex justify-end">
                    <button type="submit" class="px-6 py-2.5 bg-teal-600 hover:bg-teal-700 text-white rounded-lg font-medium transition-colors">
                        <?= htmlspecialchars(t('printshopsettings.btn_save_capacity')) ?>
                    </button>
                </div>
            </form>
        </div>

    </div>
</div>

<?php echo Currency::getPhoneInputJavaScript(); ?>
<?php echo Currency::getJavaScriptHelper(); ?>
</div>
<?php printshopFooter(); ?>
