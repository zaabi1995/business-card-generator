<?php
/**
 * Print Shop Integration Settings - Cardify
 * Configure external print shop for ordering physical business cards
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/PrintShopIntegration.php';
require_once INCLUDES_DIR . '/admin-layout.php';

Auth::requireRole('super_admin');

$db = Database::getInstance();
$message = null;
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { die('Invalid request'); }
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_settings') {
        $settings = [
            'enabled' => isset($_POST['enabled']) && $_POST['enabled'] === '1',
            'provider' => $_POST['provider'] ?? 'custom',
            'shop_name' => trim($_POST['shop_name'] ?? ''),
            'shop_email' => trim($_POST['shop_email'] ?? ''),
            'api_endpoint' => trim($_POST['api_endpoint'] ?? ''),
            'api_key' => trim($_POST['api_key'] ?? ''),
            'webhook_url' => trim($_POST['webhook_url'] ?? ''),
            'default_quantity' => (int)($_POST['default_quantity'] ?? 100),
            'default_paper' => $_POST['default_paper'] ?? 'matte',
            'default_finish' => $_POST['default_finish'] ?? 'standard',
            'pricing' => [
                'per_card' => (float)($_POST['price_per_card'] ?? 0.10),
                'setup_fee' => (float)($_POST['setup_fee'] ?? 5.00),
                'shipping' => (float)($_POST['shipping_fee'] ?? 10.00)
            ]
        ];
        
        $result = PrintShopIntegration::updateSettings($settings);
        $message = $result['success'] ? 'Print shop settings updated successfully!' : 'Error: ' . ($result['error'] ?? 'Unknown error');
        $messageType = $result['success'] ? 'success' : 'error';
        
    } elseif ($action === 'test_connection') {
        $testResult = PrintShopIntegration::testConnection();
        if ($testResult['success']) {
            $message = 'Connection successful! Print shop API is responding.';
            $messageType = 'success';
        } else {
            $message = 'Connection failed: ' . ($testResult['error'] ?? 'Unknown error');
            $messageType = 'error';
        }
    }
}

$settings = PrintShopIntegration::getSettings();

adminHeader('Print Shop Settings', 'print');
?>

<!-- Alert Message -->
<?php if ($message): ?>
<div class="mb-6 p-4 rounded-xl flex items-center gap-3 <?php echo $messageType === 'success' ? 'bg-green-50 border border-green-200 text-green-700' : 'bg-red-50 border border-red-200 text-red-700'; ?>">
    <i class="<?php echo $messageType === 'success' ? 'fa-solid fa-circle-check' : 'fa-solid fa-circle-exclamation'; ?>"></i>
    <?php echo sanitize($message); ?>
</div>
<?php endif; ?>

<form method="post" class="space-y-6">
    <?php echo csrfField(); ?>
    <input type="hidden" name="action" value="update_settings">
    
    <!-- Enable Integration -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <div class="p-4 border-b border-gray-100">
            <h3 class="font-semibold text-gray-900 flex items-center gap-2">
                <i class="fa-solid fa-print text-blue-600"></i>
                Print Shop Integration
            </h3>
        </div>
        <div class="p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="font-medium text-gray-900">Enable Print Shop Integration</p>
                    <p class="text-sm text-gray-500">Allow employees and admins to order physical business cards from a print shop</p>
                </div>
                <label class="relative inline-flex items-center cursor-pointer">
                    <input type="checkbox" name="enabled" value="1" <?php echo ($settings['enabled'] ?? false) ? 'checked' : ''; ?> class="sr-only peer">
                    <div class="w-11 h-6 bg-gray-200 rounded-full peer peer-checked:after:translate-x-full after:content-[''] after:absolute after:top-0.5 after:left-[2px] after:bg-white after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                </label>
            </div>
        </div>
    </div>
    
    <!-- Print Shop Details -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <div class="p-4 border-b border-gray-100">
            <h3 class="font-semibold text-gray-900 flex items-center gap-2">
                <i class="fa-solid fa-store text-purple-600"></i>
                Print Shop Details
            </h3>
        </div>
        <div class="p-6 space-y-4">
            <div class="grid md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Print Shop Name</label>
                    <input type="text" name="shop_name" value="<?php echo sanitize($settings['shop_name'] ?? ''); ?>" 
                           class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20"
                           placeholder="e.g., ABC Print Services">
                </div>
                
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Shop Email (for notifications)</label>
                    <input type="email" name="shop_email" value="<?php echo sanitize($settings['shop_email'] ?? ''); ?>" 
                           class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20"
                           placeholder="orders@printshop.com">
                </div>
            </div>
            
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-2">Integration Provider</label>
                <select name="provider" class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                    <option value="custom" <?php echo ($settings['provider'] ?? '') === 'custom' ? 'selected' : ''; ?>>Custom API / Manual</option>
                    <option value="printful" <?php echo ($settings['provider'] ?? '') === 'printful' ? 'selected' : ''; ?>>Printful</option>
                    <option value="vistaprint" <?php echo ($settings['provider'] ?? '') === 'vistaprint' ? 'selected' : ''; ?>>Vistaprint</option>
                    <option value="moo" <?php echo ($settings['provider'] ?? '') === 'moo' ? 'selected' : ''; ?>>MOO</option>
                    <option value="email_only" <?php echo ($settings['provider'] ?? '') === 'email_only' ? 'selected' : ''; ?>>Email Orders Only (No API)</option>
                </select>
                <p class="text-xs text-gray-500 mt-1">Choose "Email Orders Only" to receive order notifications via email without API integration</p>
            </div>
        </div>
    </div>
    
    <!-- API Configuration -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <div class="p-4 border-b border-gray-100">
            <h3 class="font-semibold text-gray-900 flex items-center gap-2">
                <i class="fa-solid fa-plug text-amber-600"></i>
                API Configuration
            </h3>
            <p class="text-sm text-gray-500 mt-1">Optional - Configure if using a print shop API</p>
        </div>
        <div class="p-6 space-y-4">
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-2">API Endpoint URL</label>
                <input type="url" name="api_endpoint" value="<?php echo sanitize($settings['api_endpoint'] ?? ''); ?>" 
                       class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20"
                       placeholder="https://api.printshop.com/v1">
            </div>
            
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-2">API Key</label>
                <input type="password" name="api_key" 
                       class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20"
                       placeholder="<?php echo !empty($settings['api_key_masked']) ? $settings['api_key_masked'] : 'Enter API key'; ?>">
                <p class="text-xs text-gray-500 mt-1">Leave blank to keep current key</p>
            </div>
            
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-2">Webhook URL (for order status updates)</label>
                <div class="flex gap-2">
                    <input type="text" name="webhook_url" value="<?php echo sanitize($settings['webhook_url'] ?? ''); ?>" 
                           class="flex-1 px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20"
                           placeholder="<?php echo getBaseUrl(); ?>webhooks/print-status">
                    <button type="button" onclick="document.querySelector('[name=webhook_url]').value = '<?php echo getBaseUrl(); ?>webhooks/print-status'" 
                            class="px-3 py-2 bg-gray-100 hover:bg-gray-200 text-gray-600 rounded-lg text-sm">
                        Auto-fill
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Default Settings -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <div class="p-4 border-b border-gray-100">
            <h3 class="font-semibold text-gray-900 flex items-center gap-2">
                <i class="fa-solid fa-sliders text-green-600"></i>
                Default Order Settings
            </h3>
        </div>
        <div class="p-6 space-y-4">
            <div class="grid md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Default Quantity</label>
                    <select name="default_quantity" class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                        <option value="50" <?php echo ($settings['default_quantity'] ?? 100) == 50 ? 'selected' : ''; ?>>50 cards</option>
                        <option value="100" <?php echo ($settings['default_quantity'] ?? 100) == 100 ? 'selected' : ''; ?>>100 cards</option>
                        <option value="250" <?php echo ($settings['default_quantity'] ?? 100) == 250 ? 'selected' : ''; ?>>250 cards</option>
                        <option value="500" <?php echo ($settings['default_quantity'] ?? 100) == 500 ? 'selected' : ''; ?>>500 cards</option>
                        <option value="1000" <?php echo ($settings['default_quantity'] ?? 100) == 1000 ? 'selected' : ''; ?>>1000 cards</option>
                    </select>
                </div>
                
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Default Paper Type</label>
                    <select name="default_paper" class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                        <option value="matte" <?php echo ($settings['default_paper'] ?? 'matte') === 'matte' ? 'selected' : ''; ?>>Matte</option>
                        <option value="glossy" <?php echo ($settings['default_paper'] ?? '') === 'glossy' ? 'selected' : ''; ?>>Glossy</option>
                        <option value="silk" <?php echo ($settings['default_paper'] ?? '') === 'silk' ? 'selected' : ''; ?>>Silk</option>
                        <option value="uncoated" <?php echo ($settings['default_paper'] ?? '') === 'uncoated' ? 'selected' : ''; ?>>Uncoated</option>
                        <option value="recycled" <?php echo ($settings['default_paper'] ?? '') === 'recycled' ? 'selected' : ''; ?>>Recycled</option>
                    </select>
                </div>
                
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Default Finish</label>
                    <select name="default_finish" class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                        <option value="standard" <?php echo ($settings['default_finish'] ?? 'standard') === 'standard' ? 'selected' : ''; ?>>Standard</option>
                        <option value="rounded_corners" <?php echo ($settings['default_finish'] ?? '') === 'rounded_corners' ? 'selected' : ''; ?>>Rounded Corners</option>
                        <option value="spot_uv" <?php echo ($settings['default_finish'] ?? '') === 'spot_uv' ? 'selected' : ''; ?>>Spot UV</option>
                        <option value="foil" <?php echo ($settings['default_finish'] ?? '') === 'foil' ? 'selected' : ''; ?>>Foil Stamping</option>
                        <option value="embossed" <?php echo ($settings['default_finish'] ?? '') === 'embossed' ? 'selected' : ''; ?>>Embossed</option>
                    </select>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Pricing -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <div class="p-4 border-b border-gray-100">
            <h3 class="font-semibold text-gray-900 flex items-center gap-2">
                <i class="fa-solid fa-dollar-sign text-emerald-600"></i>
                Pricing Configuration
            </h3>
            <p class="text-sm text-gray-500 mt-1">Set base pricing for print orders (can be used for quotes)</p>
        </div>
        <div class="p-6">
            <div class="grid md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Price per Card ($)</label>
                    <input type="number" name="price_per_card" step="0.01" min="0" 
                           value="<?php echo sanitize($settings['pricing']['per_card'] ?? 0.10); ?>" 
                           class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                </div>
                
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Setup Fee ($)</label>
                    <input type="number" name="setup_fee" step="0.01" min="0" 
                           value="<?php echo sanitize($settings['pricing']['setup_fee'] ?? 5.00); ?>" 
                           class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                </div>
                
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Shipping Fee ($)</label>
                    <input type="number" name="shipping_fee" step="0.01" min="0" 
                           value="<?php echo sanitize($settings['pricing']['shipping'] ?? 10.00); ?>" 
                           class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                </div>
            </div>
            
            <!-- Price Preview -->
            <div class="mt-4 p-4 bg-gray-50 rounded-lg">
                <p class="text-sm font-medium text-gray-700 mb-2">Example Quote (100 cards):</p>
                <div class="flex items-center gap-4 text-sm text-gray-600">
                    <span>Cards: $<span id="preview_cards">10.00</span></span>
                    <span>+</span>
                    <span>Setup: $<span id="preview_setup">5.00</span></span>
                    <span>+</span>
                    <span>Shipping: $<span id="preview_shipping">10.00</span></span>
                    <span>=</span>
                    <span class="font-bold text-gray-900">Total: $<span id="preview_total">25.00</span></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Actions -->
    <div class="flex items-center justify-between">
        <button type="submit" name="action" value="test_connection" 
                class="px-4 py-2.5 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg font-medium transition-colors flex items-center gap-2">
            <i class="fa-solid fa-plug-circle-check"></i>
            Test API Connection
        </button>
        
        <button type="submit" class="px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition-colors flex items-center gap-2">
            <i class="fa-solid fa-floppy-disk"></i>
            Save Settings
        </button>
    </div>
</form>

<!-- Connection Status -->
<div class="mt-6 p-4 rounded-xl bg-gray-50 border border-gray-200">
    <h4 class="font-medium text-gray-700 mb-2">Integration Status</h4>
    <div class="flex items-center gap-4">
        <span class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full text-sm font-medium <?php echo ($settings['enabled'] ?? false) ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600'; ?>">
            <span class="w-2 h-2 rounded-full <?php echo ($settings['enabled'] ?? false) ? 'bg-green-500' : 'bg-gray-400'; ?>"></span>
            <?php echo ($settings['enabled'] ?? false) ? 'Enabled' : 'Disabled'; ?>
        </span>
        <span class="text-sm text-gray-500">
            Provider: <?php echo ucfirst($settings['provider'] ?? 'Not configured'); ?>
        </span>
        <?php if (!empty($settings['shop_name'])): ?>
        <span class="text-sm text-gray-500">
            Shop: <?php echo sanitize($settings['shop_name']); ?>
        </span>
        <?php endif; ?>
    </div>
</div>

<!-- Quick Links -->
<div class="mt-6 grid md:grid-cols-2 gap-4">
    <a href="print_orders.php" class="p-4 bg-white rounded-xl border border-gray-200 hover:border-blue-300 hover:shadow-md transition-all flex items-center gap-4">
        <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center">
            <i class="fa-solid fa-box text-blue-600 text-xl"></i>
        </div>
        <div>
            <p class="font-semibold text-gray-900">View Print Orders</p>
            <p class="text-sm text-gray-500">Manage and track all print orders</p>
        </div>
        <i class="fa-solid fa-chevron-right text-gray-400 ml-auto"></i>
    </a>
    
    <a href="<?php echo getBasePath(); ?>webhooks/print-status" target="_blank" class="p-4 bg-white rounded-xl border border-gray-200 hover:border-purple-300 hover:shadow-md transition-all flex items-center gap-4">
        <div class="w-12 h-12 bg-purple-100 rounded-lg flex items-center justify-center">
            <i class="fa-solid fa-webhook text-purple-600 text-xl"></i>
        </div>
        <div>
            <p class="font-semibold text-gray-900">Webhook Endpoint</p>
            <p class="text-sm text-gray-500">For receiving order status updates</p>
        </div>
        <i class="fa-solid fa-external-link text-gray-400 ml-auto"></i>
    </a>
</div>

<script>
// Update price preview dynamically
function updatePricePreview() {
    const perCard = parseFloat(document.querySelector('[name=price_per_card]').value) || 0;
    const setup = parseFloat(document.querySelector('[name=setup_fee]').value) || 0;
    const shipping = parseFloat(document.querySelector('[name=shipping_fee]').value) || 0;
    
    const cards = (perCard * 100).toFixed(2);
    const total = (perCard * 100 + setup + shipping).toFixed(2);
    
    document.getElementById('preview_cards').textContent = cards;
    document.getElementById('preview_setup').textContent = setup.toFixed(2);
    document.getElementById('preview_shipping').textContent = shipping.toFixed(2);
    document.getElementById('preview_total').textContent = total;
}

document.querySelector('[name=price_per_card]').addEventListener('input', updatePricePreview);
document.querySelector('[name=setup_fee]').addEventListener('input', updatePricePreview);
document.querySelector('[name=shipping_fee]').addEventListener('input', updatePricePreview);

// Initialize preview
updatePricePreview();
</script>

<?php adminFooter(); ?>
