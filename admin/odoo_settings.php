<?php
/**
 * Odoo ERP Integration Settings - Cardify
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/OdooIntegration.php';
require_once INCLUDES_DIR . '/admin-layout.php';

Auth::requireRole('super_admin');

$db = Database::getInstance();
$message = null;
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { die('Invalid request'); }
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_settings') {
        $url = trim($_POST['odoo_url'] ?? '');
        $database = trim($_POST['odoo_database'] ?? '');
        $username = trim($_POST['odoo_username'] ?? '');
        $password = trim($_POST['odoo_password'] ?? '');
        $enabled = isset($_POST['enabled']) && $_POST['enabled'] === '1';
        
        if (empty($password)) {
            $currentSettings = OdooIntegration::getSettings();
            $password = $currentSettings['password'] === '***' ? '' : $currentSettings['password'];
        }
        
        $result = OdooIntegration::updateSettings($url, $database, $username, $password, $enabled);
        $message = $result['success'] ? 'Odoo settings updated successfully!' : 'Error: ' . ($result['error'] ?? 'Unknown error');
        $messageType = $result['success'] ? 'success' : 'error';
    } elseif ($action === 'test_connection') {
        $testResult = OdooIntegration::testConnection();
        $message = $testResult['success'] ? 'Connection successful! UID: ' . $testResult['uid'] : 'Connection failed: ' . ($testResult['error'] ?? 'Unknown error');
        $messageType = $testResult['success'] ? 'success' : 'error';
    }
}

$settings = OdooIntegration::getSettings();

adminHeader(t('adminchrome.odoo_integration'), 'odoo');
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
    
    <!-- Connection Settings -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <div class="p-4 border-b border-gray-100">
            <h3 class="font-semibold text-gray-900 flex items-center gap-2">
                <i class="fa-solid fa-plug text-purple-600"></i>
                Connection Settings
            </h3>
        </div>
        <div class="p-6 space-y-4">
            <div class="flex items-center justify-between mb-4">
                <div>
                    <p class="font-medium text-gray-900">Enable Odoo Integration</p>
                    <p class="text-sm text-gray-500">Sync print orders and documents with Odoo ERP</p>
                </div>
                <label class="relative inline-flex items-center cursor-pointer">
                    <input type="checkbox" name="enabled" value="1" <?php echo ($settings['enabled'] ?? false) ? 'checked' : ''; ?> class="sr-only peer">
                    <div class="w-11 h-6 bg-gray-200 rounded-full peer peer-checked:after:translate-x-full after:content-[''] after:absolute after:top-0.5 after:left-[2px] after:bg-white after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-purple-600"></div>
                </label>
            </div>
            
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-2">Odoo Server URL</label>
                <input type="url" name="odoo_url" value="<?php echo sanitize($settings['url'] ?? ''); ?>" 
                       class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20"
                       placeholder="https://your-odoo-instance.com">
            </div>
            
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-2">Database Name</label>
                <input type="text" name="odoo_database" value="<?php echo sanitize($settings['database'] ?? ''); ?>" 
                       class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20"
                       placeholder="odoo_production">
            </div>
            
            <div class="grid md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Username</label>
                    <input type="text" name="odoo_username" value="<?php echo sanitize($settings['username'] ?? ''); ?>" 
                           class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20"
                           placeholder="admin@example.com">
                </div>
                
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Password / API Key</label>
                    <input type="password" name="odoo_password" 
                           class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20"
                           placeholder="Leave blank to keep current">
                </div>
            </div>
        </div>
    </div>

    <!-- Actions -->
    <div class="flex items-center justify-between">
        <button type="submit" name="action" value="test_connection" 
                class="px-4 py-2.5 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg font-medium transition-colors flex items-center gap-2">
            <i class="fa-solid fa-plug-circle-check"></i>
            Test Connection
        </button>
        
        <button type="submit" class="px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition-colors flex items-center gap-2">
            <i class="fa-solid fa-floppy-disk"></i>
            Save Settings
        </button>
    </div>
</form>

<!-- Status -->
<div class="mt-6 p-4 rounded-xl bg-gray-50 border border-gray-200">
    <h4 class="font-medium text-gray-700 mb-2">Connection Status</h4>
    <div class="flex items-center gap-4">
        <span class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full text-sm font-medium <?php echo ($settings['enabled'] ?? false) ? 'bg-purple-100 text-purple-700' : 'bg-gray-100 text-gray-600'; ?>">
            <span class="w-2 h-2 rounded-full <?php echo ($settings['enabled'] ?? false) ? 'bg-purple-500' : 'bg-gray-400'; ?>"></span>
            <?php echo ($settings['enabled'] ?? false) ? 'Enabled' : 'Disabled'; ?>
        </span>
        <span class="text-sm text-gray-500">
            Server: <?php echo !empty($settings['url']) ? sanitize(parse_url($settings['url'], PHP_URL_HOST)) : 'Not configured'; ?>
        </span>
    </div>
</div>

<?php adminFooter(); ?>
