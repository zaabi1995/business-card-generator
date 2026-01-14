<?php
/**
 * Odoo ERP Integration Settings
 * Configure Odoo connection for syncing orders and documents
 */
require_once __DIR__ . '/../config.php';
Auth::requireRole('super_admin');
require_once INCLUDES_DIR . '/OdooIntegration.php';

$db = Database::getInstance();
$message = null;
$messageType = 'success';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_settings') {
        $url = trim($_POST['odoo_url'] ?? '');
        $database = trim($_POST['odoo_database'] ?? '');
        $username = trim($_POST['odoo_username'] ?? '');
        $password = trim($_POST['odoo_password'] ?? '');
        $enabled = isset($_POST['enabled']) && $_POST['enabled'] === '1';
        
        // If password is empty, keep existing password
        if (empty($password)) {
            $settings = OdooIntegration::getSettings();
            $password = $settings['password'] === '***' ? '' : $settings['password'];
        }
        
        $result = OdooIntegration::updateSettings($url, $database, $username, $password, $enabled);
        if ($result['success']) {
            $message = 'Odoo settings updated successfully!';
        } else {
            $message = 'Error updating settings: ' . ($result['error'] ?? 'Unknown error');
            $messageType = 'error';
        }
    } elseif ($action === 'test_connection') {
        $url = trim($_POST['odoo_url'] ?? '');
        $database = trim($_POST['odoo_database'] ?? '');
        $username = trim($_POST['odoo_username'] ?? '');
        $password = trim($_POST['odoo_password'] ?? '');
        
        if (empty($password)) {
            $settings = OdooIntegration::getSettings();
            $password = $settings['password'] === '***' ? '' : $settings['password'];
        }
        
        // Temporarily set settings for testing
        OdooIntegration::updateSettings($url, $database, $username, $password, true);
        
        $testResult = OdooIntegration::testConnection();
        if ($testResult['success']) {
            $message = 'Connection successful! UID: ' . $testResult['uid'];
        } else {
            $message = 'Connection failed: ' . ($testResult['error'] ?? 'Unknown error');
            $messageType = 'error';
        }
    }
}

// Get current settings
$settings = OdooIntegration::getSettings();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Odoo Integration Settings | <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="<?php echo assetUrl('css/tailwind.css'); ?>">
    <style>
        .glass-card { background: rgba(255, 255, 255, 0.03); backdrop-filter: blur(20px); border: 1px solid rgba(255, 255, 255, 0.1); }
    </style>
</head>
<body class="bg-alzayani-dark text-white font-sans min-h-screen">
    <div class="min-h-screen">
        <header class="glass-card border-b border-white/10">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-2xl font-bold text-white">Odoo ERP Integration</h1>
                        <p class="text-gray-400 text-sm">Connect and sync with Odoo ERP system</p>
                    </div>
                    <a href="super/" class="px-4 py-2 rounded-lg bg-white/5 hover:bg-white/10 transition-colors">
                        ← Back to Super Admin
                    </a>
                </div>
            </div>
        </header>
        
        <main class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
            <?php if ($message): ?>
            <div class="mb-6 p-4 rounded-xl <?php echo $messageType === 'error' ? 'bg-red-500/10 border border-red-500/30 text-red-400' : 'bg-green-500/10 border border-green-500/30 text-green-400'; ?>">
                <?php echo sanitize($message); ?>
            </div>
            <?php endif; ?>
            
            <!-- Odoo Connection Settings -->
            <div class="glass-card rounded-xl p-6 mb-6">
                <h2 class="text-xl font-bold mb-4">Odoo Connection Settings</h2>
                <form method="post" class="space-y-4">
                    <input type="hidden" name="action" value="update_settings">
                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-2">Odoo URL</label>
                        <input type="url" name="odoo_url" value="<?php echo sanitize($settings['url']); ?>" 
                               required class="w-full px-4 py-3 rounded-lg bg-white/5 border border-white/10 text-white"
                               placeholder="https://your-odoo-instance.com">
                        <p class="text-xs text-gray-400 mt-1">Your Odoo instance URL (without trailing slash)</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-2">Database Name</label>
                        <input type="text" name="odoo_database" value="<?php echo sanitize($settings['database']); ?>" 
                               required class="w-full px-4 py-3 rounded-lg bg-white/5 border border-white/10 text-white"
                               placeholder="odoo">
                    </div>
                    <div class="grid md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-2">Username</label>
                            <input type="text" name="odoo_username" value="<?php echo sanitize($settings['username']); ?>" 
                                   required class="w-full px-4 py-3 rounded-lg bg-white/5 border border-white/10 text-white"
                                   placeholder="admin">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-2">Password</label>
                            <input type="password" name="odoo_password" value="" 
                                   class="w-full px-4 py-3 rounded-lg bg-white/5 border border-white/10 text-white"
                                   placeholder="<?php echo $settings['password'] === '***' ? 'Leave empty to keep current' : 'Enter password'; ?>">
                            <p class="text-xs text-gray-400 mt-1">Leave empty to keep current password</p>
                        </div>
                    </div>
                    <div class="flex items-center space-x-4">
                        <label class="flex items-center space-x-3 cursor-pointer">
                            <input type="checkbox" name="enabled" value="1" <?php echo $settings['enabled'] ? 'checked' : ''; ?>
                                   class="w-5 h-5 rounded border-white/20 bg-white/5 text-amber-500 focus:ring-amber-500">
                            <span class="text-gray-300">Enable Odoo integration</span>
                        </label>
                    </div>
                    <div class="flex space-x-3">
                        <button type="submit" class="px-6 py-2 rounded-lg bg-gradient-to-r from-amber-500 to-amber-600 text-white font-bold hover:from-amber-600 hover:to-amber-700 transition-colors">
                            Save Settings
                        </button>
                        <button type="button" onclick="testConnection()" class="px-6 py-2 rounded-lg bg-blue-500/20 text-blue-400 hover:bg-blue-500/30 transition-colors">
                            Test Connection
                        </button>
                    </div>
                </form>
            </div>
            
            <!-- Test Connection Form (Hidden) -->
            <form method="post" id="testForm" style="display: none;">
                <input type="hidden" name="action" value="test_connection">
                <input type="hidden" name="odoo_url" id="test_url">
                <input type="hidden" name="odoo_database" id="test_database">
                <input type="hidden" name="odoo_username" id="test_username">
                <input type="hidden" name="odoo_password" id="test_password">
            </form>
            
            <!-- Status -->
            <div class="glass-card rounded-xl p-6 mb-6">
                <h2 class="text-xl font-bold mb-4">Integration Status</h2>
                <div class="space-y-3">
                    <div class="flex items-center justify-between p-3 rounded-lg bg-white/5">
                        <span class="text-gray-300">Connection Status:</span>
                        <span class="<?php echo OdooIntegration::isEnabled() ? 'text-green-400' : 'text-gray-400'; ?> font-semibold">
                            <?php echo OdooIntegration::isEnabled() ? '✓ Enabled' : '✗ Disabled'; ?>
                        </span>
                    </div>
                    <div class="flex items-center justify-between p-3 rounded-lg bg-white/5">
                        <span class="text-gray-300">URL Configured:</span>
                        <span class="<?php echo !empty($settings['url']) ? 'text-green-400' : 'text-red-400'; ?> font-semibold">
                            <?php echo !empty($settings['url']) ? '✓ Yes' : '✗ No'; ?>
                        </span>
                    </div>
                    <div class="flex items-center justify-between p-3 rounded-lg bg-white/5">
                        <span class="text-gray-300">Database Configured:</span>
                        <span class="<?php echo !empty($settings['database']) ? 'text-green-400' : 'text-red-400'; ?> font-semibold">
                            <?php echo !empty($settings['database']) ? '✓ Yes' : '✗ No'; ?>
                        </span>
                    </div>
                </div>
            </div>
            
            <!-- Instructions -->
            <div class="glass-card rounded-xl p-6">
                <h2 class="text-xl font-bold mb-4">How It Works</h2>
                <div class="text-sm text-gray-400 space-y-3">
                    <p><strong>1. Automatic Sync:</strong> When enabled, the system automatically syncs:</p>
                    <ul class="list-disc list-inside ml-4 space-y-1">
                        <li>Print orders → Odoo Sale Orders</li>
                        <li>Quotations → Attached to Sale Orders</li>
                        <li>Invoices → Odoo Invoices (created from Sale Orders)</li>
                        <li>Delivery Notes → Attached to Sale Orders</li>
                    </ul>
                    <p><strong>2. Customer Management:</strong> Companies are automatically created as Partners in Odoo</p>
                    <p><strong>3. Product:</strong> A "Business Card Printing" product is created in Odoo if it doesn't exist</p>
                    <p><strong>4. File Attachments:</strong> All documents (PO, Quotation, Invoice, Delivery Note) are attached to the corresponding Odoo records</p>
                    <p class="text-amber-400 mt-4"><strong>Note:</strong> Make sure your Odoo instance allows XML-RPC connections and the user has appropriate permissions.</p>
                </div>
            </div>
        </main>
    </div>
    
    <script>
        function testConnection() {
            const form = document.querySelector('form[action=""]');
            const testForm = document.getElementById('testForm');
            
            document.getElementById('test_url').value = form.querySelector('[name="odoo_url"]').value;
            document.getElementById('test_database').value = form.querySelector('[name="odoo_database"]').value;
            document.getElementById('test_username').value = form.querySelector('[name="odoo_username"]').value;
            document.getElementById('test_password').value = form.querySelector('[name="odoo_password"]').value;
            
            testForm.submit();
        }
    </script>
</body>
</html>
