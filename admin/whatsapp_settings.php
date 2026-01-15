<?php
/**
 * WhatsApp API Settings - Cardify
 */
require_once __DIR__ . '/../config.php';
Auth::requireRole('super_admin');
require_once INCLUDES_DIR . '/WhatsApp.php';
require_once INCLUDES_DIR . '/admin-layout.php';

$db = Database::getInstance();
$message = null;
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_token') {
        $token = trim($_POST['whatsapp_token'] ?? '');
        if (!empty($token)) {
            $result = WhatsApp::updateToken($token);
            $message = $result['success'] ? 'WhatsApp API token updated successfully!' : 'Error: ' . ($result['error'] ?? 'Unknown error');
            $messageType = $result['success'] ? 'success' : 'error';
        } else {
            $message = 'Token cannot be empty';
            $messageType = 'error';
        }
    } elseif ($action === 'toggle_enabled') {
        $enabled = isset($_POST['enabled']) && $_POST['enabled'] === '1';
        $result = WhatsApp::updateEnabled($enabled);
        $message = $result['success'] ? ($enabled ? 'WhatsApp notifications enabled!' : 'WhatsApp notifications disabled!') : 'Error: ' . ($result['error'] ?? 'Unknown error');
        $messageType = $result['success'] ? 'success' : 'error';
    }
}

$settings = WhatsApp::getSettings();

adminHeader('WhatsApp Settings', 'whatsapp');
?>

<!-- Alert Message -->
<?php if ($message): ?>
<div class="mb-6 p-4 rounded-xl flex items-center gap-3 <?php echo $messageType === 'success' ? 'bg-green-50 border border-green-200 text-green-700' : 'bg-red-50 border border-red-200 text-red-700'; ?>">
    <i class="<?php echo $messageType === 'success' ? 'fa-solid fa-circle-check' : 'fa-solid fa-circle-exclamation'; ?>"></i>
    <?php echo sanitize($message); ?>
</div>
<?php endif; ?>

<!-- Enable/Disable -->
<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden mb-6">
    <div class="p-4 border-b border-gray-100">
        <h3 class="font-semibold text-gray-900 flex items-center gap-2">
            <i class="fa-brands fa-whatsapp text-green-600 text-xl"></i>
            WhatsApp Notifications
        </h3>
    </div>
    <div class="p-6">
        <form method="post">
            <input type="hidden" name="action" value="toggle_enabled">
            <div class="flex items-center justify-between">
                <div>
                    <p class="font-medium text-gray-900">Enable WhatsApp Notifications</p>
                    <p class="text-sm text-gray-500">Send order confirmations and updates via WhatsApp</p>
                </div>
                <label class="relative inline-flex items-center cursor-pointer">
                    <input type="checkbox" name="enabled" value="1" <?php echo ($settings['enabled'] ?? false) ? 'checked' : ''; ?> 
                           onchange="this.form.submit()" class="sr-only peer">
                    <div class="w-11 h-6 bg-gray-200 rounded-full peer peer-checked:after:translate-x-full after:content-[''] after:absolute after:top-0.5 after:left-[2px] after:bg-white after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-green-600"></div>
                </label>
            </div>
        </form>
    </div>
</div>

<!-- API Token -->
<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
    <div class="p-4 border-b border-gray-100">
        <h3 class="font-semibold text-gray-900 flex items-center gap-2">
            <i class="fa-solid fa-key text-blue-600"></i>
            API Configuration
        </h3>
    </div>
    <div class="p-6">
        <form method="post" class="space-y-4">
            <input type="hidden" name="action" value="update_token">
            
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-2">WhatsApp API Token</label>
                <input type="password" name="whatsapp_token" 
                       value="<?php echo !empty($settings['token']) ? '***hidden***' : ''; ?>"
                       class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20"
                       placeholder="Enter your WhatsApp Business API token">
                <p class="text-xs text-gray-500 mt-2">Get your token from the WhatsApp Business API dashboard</p>
            </div>
            
            <div class="flex justify-end">
                <button type="submit" class="px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition-colors flex items-center gap-2">
                    <i class="fa-solid fa-floppy-disk"></i>
                    Save Token
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Status -->
<div class="mt-6 p-4 rounded-xl bg-gray-50 border border-gray-200">
    <h4 class="font-medium text-gray-700 mb-2">Current Status</h4>
    <div class="flex items-center gap-4">
        <span class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full text-sm font-medium <?php echo ($settings['enabled'] ?? false) ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600'; ?>">
            <span class="w-2 h-2 rounded-full <?php echo ($settings['enabled'] ?? false) ? 'bg-green-500' : 'bg-gray-400'; ?>"></span>
            <?php echo ($settings['enabled'] ?? false) ? 'Active' : 'Disabled'; ?>
        </span>
        <span class="text-sm text-gray-500">
            Token: <?php echo !empty($settings['token']) ? 'Configured' : 'Not set'; ?>
        </span>
    </div>
</div>

<?php adminFooter(); ?>
