<?php
/**
 * WhatsApp API Settings
 * Configure WhatsApp API token for sending confirmation messages
 */
require_once __DIR__ . '/../config.php';
Auth::requireRole('super_admin');
require_once INCLUDES_DIR . '/WhatsApp.php';

$db = Database::getInstance();
$message = null;
$messageType = 'success';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_token') {
        $token = trim($_POST['whatsapp_token'] ?? '');
        
        if (!empty($token)) {
            $result = WhatsApp::updateToken($token);
            if ($result['success']) {
                $message = 'WhatsApp API token updated successfully!';
            } else {
                $message = 'Error updating token: ' . ($result['error'] ?? 'Unknown error');
                $messageType = 'error';
            }
        } else {
            $message = 'Token cannot be empty';
            $messageType = 'error';
        }
    } elseif ($action === 'toggle_enabled') {
        $enabled = isset($_POST['enabled']) && $_POST['enabled'] === '1';
        $result = WhatsApp::updateEnabled($enabled);
        if ($result['success']) {
            $message = $enabled ? 'WhatsApp notifications enabled!' : 'WhatsApp notifications disabled!';
        } else {
            $message = 'Error updating settings: ' . ($result['error'] ?? 'Unknown error');
            $messageType = 'error';
        }
    }
}

// Get current settings
$settings = WhatsApp::getSettings();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WhatsApp Settings | <?php echo SITE_NAME; ?></title>
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
                        <h1 class="text-2xl font-bold text-white">WhatsApp Settings</h1>
                        <p class="text-gray-400 text-sm">Configure WhatsApp API for confirmation messages</p>
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
            
            <!-- WhatsApp API Token -->
            <div class="glass-card rounded-xl p-6 mb-6">
                <h2 class="text-xl font-bold mb-4">WhatsApp API Token</h2>
                <form method="post" class="space-y-4">
                    <input type="hidden" name="action" value="update_token">
                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-2">API Token</label>
                        <input type="text" name="whatsapp_token" value="<?php echo sanitize($settings['token']); ?>" 
                               required class="w-full px-4 py-3 rounded-lg bg-white/5 border border-white/10 text-white font-mono text-sm"
                               placeholder="eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...">
                        <p class="text-xs text-gray-400 mt-2">Enter your WhatsApp REST API token (JWT format)</p>
                    </div>
                    <button type="submit" class="px-6 py-2 rounded-lg bg-gradient-to-r from-amber-500 to-amber-600 text-white font-bold hover:from-amber-600 hover:to-amber-700 transition-colors">
                        Save Token
                    </button>
                </form>
            </div>
            
            <!-- Enable/Disable WhatsApp -->
            <div class="glass-card rounded-xl p-6 mb-6">
                <h2 class="text-xl font-bold mb-4">Enable WhatsApp Notifications</h2>
                <form method="post" class="space-y-4">
                    <input type="hidden" name="action" value="toggle_enabled">
                    <div class="flex items-center space-x-4">
                        <label class="flex items-center space-x-3 cursor-pointer">
                            <input type="checkbox" name="enabled" value="1" <?php echo $settings['enabled'] ? 'checked' : ''; ?>
                                   onchange="this.form.submit()"
                                   class="w-5 h-5 rounded border-white/20 bg-white/5 text-amber-500 focus:ring-amber-500">
                            <span class="text-gray-300">Enable WhatsApp confirmation messages for print orders</span>
                        </label>
                    </div>
                    <p class="text-xs text-gray-400 mt-2">
                        When enabled, a WhatsApp confirmation message will be sent automatically when a print order is created.
                    </p>
                </form>
            </div>
            
            <!-- Status -->
            <div class="glass-card rounded-xl p-6">
                <h2 class="text-xl font-bold mb-4">Current Status</h2>
                <div class="space-y-3">
                    <div class="flex items-center justify-between p-3 rounded-lg bg-white/5">
                        <span class="text-gray-300">Token Configured:</span>
                        <span class="<?php echo !empty($settings['token']) ? 'text-green-400' : 'text-red-400'; ?> font-semibold">
                            <?php echo !empty($settings['token']) ? '✓ Yes' : '✗ No'; ?>
                        </span>
                    </div>
                    <div class="flex items-center justify-between p-3 rounded-lg bg-white/5">
                        <span class="text-gray-300">Notifications Enabled:</span>
                        <span class="<?php echo $settings['enabled'] ? 'text-green-400' : 'text-gray-400'; ?> font-semibold">
                            <?php echo $settings['enabled'] ? '✓ Enabled' : '✗ Disabled'; ?>
                        </span>
                    </div>
                    <div class="flex items-center justify-between p-3 rounded-lg bg-white/5">
                        <span class="text-gray-300">Status:</span>
                        <span class="<?php echo WhatsApp::isEnabled() ? 'text-green-400' : 'text-amber-400'; ?> font-semibold">
                            <?php echo WhatsApp::isEnabled() ? '✓ Active' : '⚠ Inactive'; ?>
                        </span>
                    </div>
                </div>
            </div>
            
            <!-- Instructions -->
            <div class="glass-card rounded-xl p-6 mt-6">
                <h2 class="text-xl font-bold mb-4">Instructions</h2>
                <div class="text-sm text-gray-400 space-y-2">
                    <p>1. Obtain your WhatsApp REST API token from your WhatsApp API provider</p>
                    <p>2. Paste the token in the "API Token" field above</p>
                    <p>3. Enable notifications using the toggle switch</p>
                    <p>4. When a print order is created, a confirmation message will be sent automatically to the company's phone number</p>
                    <p class="text-amber-400 mt-4"><strong>Note:</strong> Make sure the company has a valid phone number in their profile for WhatsApp messages to work.</p>
                </div>
            </div>
        </main>
    </div>
</body>
</html>
