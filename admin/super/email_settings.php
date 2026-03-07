<?php
/**
 * Super Admin - Email Settings
 * Configure SMTP settings, test email delivery
 */
require_once __DIR__ . '/../../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/AuditLog.php';
require_once INCLUDES_DIR . '/Mailer.php';
require_once INCLUDES_DIR . '/admin-layout.php';

Auth::requireRole('super_admin');

$db = Database::getInstance();
$message = '';
$messageType = '';
$testResult = null;

// Check if system_settings table exists
$systemSettingsExists = false;
try {
    $systemSettingsExists = $db->tableExists('system_settings');
} catch (Exception $e) {
    $systemSettingsExists = false;
}

// Get current settings from database (with config.php fallbacks)
function getEmailSetting($db, $key, $default = '') {
    global $systemSettingsExists;
    if (!$systemSettingsExists) {
        return $default;
    }
    
    try {
        $result = $db->fetchOne(
            "SELECT setting_value FROM system_settings WHERE setting_key = :key",
            ['key' => $key]
        );
        if ($result && isset($result['setting_value'])) {
            return $result['setting_value'];
        }
    } catch (Exception $e) {
        // Table might not exist
    }
    return $default;
}

function saveEmailSetting($db, $key, $value) {
    global $systemSettingsExists;
    if (!$systemSettingsExists) {
        return false;
    }
    
    try {
        // Check if setting exists
        $existing = $db->fetchOne(
            "SELECT setting_key FROM system_settings WHERE setting_key = :key",
            ['key' => $key]
        );
        
        if ($existing) {
            // Update existing
            $sql = "UPDATE system_settings SET setting_value = :value, updated_at = NOW() WHERE setting_key = :key";
            $db->query($sql, ['value' => $value, 'key' => $key]);
        } else {
            // Insert new
            $sql = "INSERT INTO system_settings (setting_key, setting_value, setting_type, description, created_at, updated_at) 
                    VALUES (:key, :value, 'string', :desc, NOW(), NOW())";
            $description = 'Email configuration: ' . $key;
            $db->query($sql, ['key' => $key, 'value' => $value, 'desc' => $description]);
        }
        
        return true;
    } catch (Exception $e) {
        error_log("Failed to save email setting [{$key}]: " . $e->getMessage());
        return false;
    }
}

// Current values
$settings = [
    'mail_host' => getEmailSetting($db, 'mail_host', defined('MAIL_HOST') ? MAIL_HOST : ''),
    'mail_port' => getEmailSetting($db, 'mail_port', defined('MAIL_PORT') ? MAIL_PORT : '587'),
    'mail_username' => getEmailSetting($db, 'mail_username', defined('MAIL_USERNAME') ? MAIL_USERNAME : ''),
    'mail_password' => getEmailSetting($db, 'mail_password', defined('MAIL_PASSWORD') ? MAIL_PASSWORD : ''),
    'mail_encryption' => getEmailSetting($db, 'mail_encryption', defined('MAIL_ENCRYPTION') ? MAIL_ENCRYPTION : 'tls'),
    'mail_from_email' => getEmailSetting($db, 'mail_from_email', defined('MAIL_FROM_EMAIL') ? MAIL_FROM_EMAIL : ''),
    'mail_from_name' => getEmailSetting($db, 'mail_from_name', defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : 'Cardify'),
    'mail_support_email' => getEmailSetting($db, 'mail_support_email', 'support@cardify.om'),
    'mail_info_email' => getEmailSetting($db, 'mail_info_email', 'info@cardify.om'),
];

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !validateCSRFToken($_POST['csrf_token'] ?? '')) {
    die('Invalid request');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'save_settings') {
        $oldSettings = $settings;
        
        $newSettings = [
            'mail_host' => trim($_POST['mail_host'] ?? ''),
            'mail_port' => trim($_POST['mail_port'] ?? '587'),
            'mail_username' => trim($_POST['mail_username'] ?? ''),
            'mail_encryption' => $_POST['mail_encryption'] ?? 'tls',
            'mail_from_email' => trim($_POST['mail_from_email'] ?? ''),
            'mail_from_name' => trim($_POST['mail_from_name'] ?? 'Cardify'),
            'mail_support_email' => trim($_POST['mail_support_email'] ?? 'support@cardify.om'),
            'mail_info_email' => trim($_POST['mail_info_email'] ?? 'info@cardify.om'),
        ];
        
        // Only update password if a new one is provided
        if (!empty($_POST['mail_password'])) {
            $newSettings['mail_password'] = $_POST['mail_password'];
        } else {
            $newSettings['mail_password'] = $settings['mail_password'];
        }
        
        $allSaved = true;
        foreach ($newSettings as $key => $value) {
            if (!saveEmailSetting($db, $key, $value)) {
                $allSaved = false;
            }
        }
        
        if ($allSaved) {
            AuditLog::log('update', 'email_settings', null, 
                ['settings' => 'email configuration'], 
                ['settings' => 'email configuration updated']
            );
            $message = 'Email settings saved successfully';
            $messageType = 'success';
            
            // Refresh settings
            $settings = $newSettings;
        } else {
            $message = 'Failed to save some settings';
            $messageType = 'error';
        }
    } elseif ($action === 'test_email') {
        $testTo = sanitizeEmail($_POST['test_email'] ?? '');
        
        if (empty($testTo)) {
            $message = 'Please enter a valid email address';
            $messageType = 'error';
        } else {
            // First test connection
            $connectionTest = Mailer::testConnection();
            
            if (!$connectionTest['success']) {
                $testResult = [
                    'success' => false,
                    'stage' => 'connection',
                    'error' => $connectionTest['error']
                ];
                $message = 'Connection test failed: ' . $connectionTest['error'];
                $messageType = 'error';
            } else {
                // Try to send test email
                $siteName = defined('SITE_NAME') ? SITE_NAME : 'Cardify';
                $testSubject = "Test Email from {$siteName}";
                $testBody = "
                    <h2>Email Configuration Test</h2>
                    <p>This is a test email from your {$siteName} installation.</p>
                    <div style='background: #f0fdf4; border-left: 4px solid #22c55e; padding: 15px; margin: 15px 0;'>
                        <strong>Success!</strong><br>
                        Your email configuration is working correctly.
                    </div>
                    <p><strong>Configuration Details:</strong></p>
                    <table style='width: 100%; border-collapse: collapse;'>
                        <tr><td style='padding: 8px; border-bottom: 1px solid #e5e7eb;'>SMTP Host</td><td style='padding: 8px; border-bottom: 1px solid #e5e7eb;'>" . htmlspecialchars($settings['mail_host']) . "</td></tr>
                        <tr><td style='padding: 8px; border-bottom: 1px solid #e5e7eb;'>SMTP Port</td><td style='padding: 8px; border-bottom: 1px solid #e5e7eb;'>" . htmlspecialchars($settings['mail_port']) . "</td></tr>
                        <tr><td style='padding: 8px; border-bottom: 1px solid #e5e7eb;'>Encryption</td><td style='padding: 8px; border-bottom: 1px solid #e5e7eb;'>" . strtoupper(htmlspecialchars($settings['mail_encryption'])) . "</td></tr>
                        <tr><td style='padding: 8px; border-bottom: 1px solid #e5e7eb;'>From Email</td><td style='padding: 8px; border-bottom: 1px solid #e5e7eb;'>" . htmlspecialchars($settings['mail_from_email']) . "</td></tr>
                    </table>
                    <p style='color: #6b7280; font-size: 12px; margin-top: 20px;'>
                        Sent at: " . date('Y-m-d H:i:s') . "<br>
                        Server: " . php_uname('n') . "
                    </p>
                ";
                
                $success = Mailer::send($testTo, $testSubject, $testBody);
                
                if ($success) {
                    $testResult = [
                        'success' => true,
                        'message' => "Test email sent successfully to {$testTo}"
                    ];
                    $message = "Test email sent successfully to {$testTo}";
                    $messageType = 'success';
                    
                    AuditLog::log('test', 'email_settings', null, null, ['test_email' => $testTo, 'result' => 'success']);
                } else {
                    $error = Mailer::getLastError();
                    $testResult = [
                        'success' => false,
                        'stage' => 'send',
                        'error' => $error
                    ];
                    $message = "Failed to send test email: {$error}";
                    $messageType = 'error';
                    
                    AuditLog::log('test', 'email_settings', null, null, ['test_email' => $testTo, 'result' => 'failed', 'error' => $error]);
                }
            }
        }
    }
}

// Check current configuration status
$configStatus = [];
$configStatus['host_configured'] = !empty($settings['mail_host']);
$configStatus['credentials_configured'] = !empty($settings['mail_username']) && !empty($settings['mail_password']);
$configStatus['from_configured'] = !empty($settings['mail_from_email']);

// Get email stats
$emailStats = [
    'total' => 0,
    'sent' => 0,
    'failed' => 0,
    'today' => 0
];
try {
    if ($db->tableExists('email_logs')) {
        $emailStats['total'] = $db->fetchOne("SELECT COUNT(*) as c FROM email_logs")['c'] ?? 0;
        $emailStats['sent'] = $db->fetchOne("SELECT COUNT(*) as c FROM email_logs WHERE status = 'sent'")['c'] ?? 0;
        $emailStats['failed'] = $db->fetchOne("SELECT COUNT(*) as c FROM email_logs WHERE status = 'failed'")['c'] ?? 0;
        $emailStats['today'] = $db->fetchOne("SELECT COUNT(*) as c FROM email_logs WHERE DATE(created_at) = CURDATE()")['c'] ?? 0;
    }
} catch (Exception $e) {}

adminHeader('Email Settings', 'email-settings');
?>

<?php if (!$systemSettingsExists): ?>
<div class="mb-6 p-4 rounded-lg bg-amber-100 text-amber-800 border border-amber-200">
    <div class="flex items-center">
        <i class="fa-solid fa-exclamation-triangle mr-2"></i>
        <div>
            <strong>Database table not found.</strong> 
            The <code>system_settings</code> table doesn't exist. Please run database migrations first.
            Settings will use values from <code>config.php</code>.
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($message): ?>
<div class="mb-6 p-4 rounded-lg <?php echo $messageType === 'success' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'; ?>">
    <div class="flex items-center">
        <i class="fa-solid <?php echo $messageType === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?> mr-2"></i>
        <?php echo htmlspecialchars($message); ?>
    </div>
</div>
<?php endif; ?>

<!-- Status Cards -->
<div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
    <div class="bg-white rounded-lg shadow p-4">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm text-gray-500">Configuration</p>
                <p class="text-lg font-semibold <?php echo ($configStatus['host_configured'] && $configStatus['credentials_configured']) ? 'text-green-600' : 'text-amber-600'; ?>">
                    <?php echo ($configStatus['host_configured'] && $configStatus['credentials_configured']) ? 'Complete' : 'Incomplete'; ?>
                </p>
            </div>
            <div class="w-10 h-10 rounded-lg <?php echo ($configStatus['host_configured'] && $configStatus['credentials_configured']) ? 'bg-green-100 text-green-600' : 'bg-amber-100 text-amber-600'; ?> flex items-center justify-center">
                <i class="fa-solid fa-cog"></i>
            </div>
        </div>
    </div>
    
    <div class="bg-white rounded-lg shadow p-4">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm text-gray-500">Emails Sent</p>
                <p class="text-2xl font-semibold text-gray-900"><?php echo number_format($emailStats['sent']); ?></p>
            </div>
            <div class="w-10 h-10 rounded-lg bg-blue-100 text-blue-600 flex items-center justify-center">
                <i class="fa-solid fa-paper-plane"></i>
            </div>
        </div>
    </div>
    
    <div class="bg-white rounded-lg shadow p-4">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm text-gray-500">Failed</p>
                <p class="text-2xl font-semibold <?php echo $emailStats['failed'] > 0 ? 'text-red-600' : 'text-gray-900'; ?>"><?php echo number_format($emailStats['failed']); ?></p>
            </div>
            <div class="w-10 h-10 rounded-lg <?php echo $emailStats['failed'] > 0 ? 'bg-red-100 text-red-600' : 'bg-gray-100 text-gray-600'; ?> flex items-center justify-center">
                <i class="fa-solid fa-exclamation-triangle"></i>
            </div>
        </div>
    </div>
    
    <div class="bg-white rounded-lg shadow p-4">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm text-gray-500">Today</p>
                <p class="text-2xl font-semibold text-gray-900"><?php echo number_format($emailStats['today']); ?></p>
            </div>
            <div class="w-10 h-10 rounded-lg bg-purple-100 text-purple-600 flex items-center justify-center">
                <i class="fa-solid fa-calendar-day"></i>
            </div>
        </div>
    </div>
</div>

<div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
    <!-- SMTP Settings -->
    <div class="xl:col-span-2">
        <div class="bg-white rounded-lg shadow">
            <div class="p-6 border-b border-gray-100">
                <h3 class="text-lg font-semibold text-gray-900">SMTP Configuration</h3>
                <p class="text-sm text-gray-500 mt-1">Configure your email server settings for sending notifications.</p>
            </div>
            <form method="POST" class="p-6">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="save_settings">
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">SMTP Host</label>
                        <input type="text" name="mail_host" value="<?php echo htmlspecialchars($settings['mail_host']); ?>"
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                               placeholder="smtp.example.com">
                        <p class="text-xs text-gray-500 mt-1">e.g., smtp.gmail.com, smtp.office365.com</p>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">SMTP Port</label>
                        <input type="text" name="mail_port" value="<?php echo htmlspecialchars($settings['mail_port']); ?>"
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                               placeholder="587">
                        <p class="text-xs text-gray-500 mt-1">Common: 587 (TLS), 465 (SSL), 25 (none)</p>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">SMTP Username</label>
                        <input type="text" name="mail_username" value="<?php echo htmlspecialchars($settings['mail_username']); ?>"
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                               placeholder="your-email@example.com">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">SMTP Password</label>
                        <input type="password" name="mail_password" 
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                               placeholder="<?php echo !empty($settings['mail_password']) ? '••••••••' : 'Enter password'; ?>">
                        <p class="text-xs text-gray-500 mt-1">Leave blank to keep current password</p>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Encryption</label>
                        <select name="mail_encryption" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            <option value="tls" <?php echo $settings['mail_encryption'] === 'tls' ? 'selected' : ''; ?>>TLS (Recommended)</option>
                            <option value="ssl" <?php echo $settings['mail_encryption'] === 'ssl' ? 'selected' : ''; ?>>SSL</option>
                            <option value="" <?php echo empty($settings['mail_encryption']) ? 'selected' : ''; ?>>None</option>
                        </select>
                    </div>
                </div>
                
                <hr class="my-6">
                
                <h4 class="font-medium text-gray-900 mb-4">Sender Information</h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">From Email (No-Reply)</label>
                        <input type="email" name="mail_from_email" value="<?php echo htmlspecialchars($settings['mail_from_email']); ?>"
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                               placeholder="no-reply@cardify.om">
                        <p class="text-xs text-gray-500 mt-1">Email address used to send emails</p>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">From Name</label>
                        <input type="text" name="mail_from_name" value="<?php echo htmlspecialchars($settings['mail_from_name']); ?>"
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                               placeholder="Cardify">
                        <p class="text-xs text-gray-500 mt-1">Display name shown as sender</p>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Support Email</label>
                        <input type="email" name="mail_support_email" value="<?php echo htmlspecialchars($settings['mail_support_email']); ?>"
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                               placeholder="support@cardify.om">
                        <p class="text-xs text-gray-500 mt-1">Shown in email footer for support</p>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Info Email</label>
                        <input type="email" name="mail_info_email" value="<?php echo htmlspecialchars($settings['mail_info_email']); ?>"
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                               placeholder="info@cardify.om">
                        <p class="text-xs text-gray-500 mt-1">General information contact</p>
                    </div>
                </div>
                
                <div class="mt-6 flex justify-end">
                    <button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                        <i class="fa-solid fa-save mr-2"></i>Save Settings
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Test Email & Info -->
    <div class="space-y-6">
        <!-- Test Email -->
        <div class="bg-white rounded-lg shadow">
            <div class="p-6 border-b border-gray-100">
                <h3 class="text-lg font-semibold text-gray-900">Test Email</h3>
                <p class="text-sm text-gray-500 mt-1">Send a test email to verify your configuration.</p>
            </div>
            <form method="POST" class="p-6">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="test_email">
                
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Send Test To</label>
                    <input type="email" name="test_email" value="<?php echo htmlspecialchars($_SESSION['user_email'] ?? ''); ?>"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                           placeholder="your@email.com" required>
                </div>
                
                <button type="submit" class="w-full px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors">
                    <i class="fa-solid fa-paper-plane mr-2"></i>Send Test Email
                </button>
                
                <?php if ($testResult): ?>
                <div class="mt-4 p-4 rounded-lg <?php echo $testResult['success'] ? 'bg-green-50 border border-green-200' : 'bg-red-50 border border-red-200'; ?>">
                    <?php if ($testResult['success']): ?>
                    <div class="flex items-center text-green-700">
                        <i class="fa-solid fa-check-circle mr-2"></i>
                        <span class="font-medium">Email sent successfully!</span>
                    </div>
                    <?php else: ?>
                    <div class="text-red-700">
                        <div class="flex items-center mb-2">
                            <i class="fa-solid fa-times-circle mr-2"></i>
                            <span class="font-medium">Test failed at: <?php echo htmlspecialchars($testResult['stage'] ?? 'unknown'); ?></span>
                        </div>
                        <p class="text-sm"><?php echo htmlspecialchars($testResult['error'] ?? 'Unknown error'); ?></p>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </form>
        </div>
        
        <!-- Configuration Guide -->
        <div class="bg-white rounded-lg shadow">
            <div class="p-6 border-b border-gray-100">
                <h3 class="text-lg font-semibold text-gray-900">Common SMTP Servers</h3>
            </div>
            <div class="p-6 space-y-4 text-sm">
                <div class="p-3 bg-gray-50 rounded-lg">
                    <p class="font-medium text-gray-900">Gmail</p>
                    <p class="text-gray-600">Host: smtp.gmail.com</p>
                    <p class="text-gray-600">Port: 587 (TLS)</p>
                    <p class="text-xs text-amber-600 mt-1">Requires App Password</p>
                </div>
                
                <div class="p-3 bg-gray-50 rounded-lg">
                    <p class="font-medium text-gray-900">Microsoft 365</p>
                    <p class="text-gray-600">Host: smtp.office365.com</p>
                    <p class="text-gray-600">Port: 587 (TLS)</p>
                </div>
                
                <div class="p-3 bg-gray-50 rounded-lg">
                    <p class="font-medium text-gray-900">SendGrid</p>
                    <p class="text-gray-600">Host: smtp.sendgrid.net</p>
                    <p class="text-gray-600">Port: 587 (TLS)</p>
                </div>
                
                <div class="p-3 bg-gray-50 rounded-lg">
                    <p class="font-medium text-gray-900">Mailgun</p>
                    <p class="text-gray-600">Host: smtp.mailgun.org</p>
                    <p class="text-gray-600">Port: 587 (TLS)</p>
                </div>
            </div>
        </div>
        
        <!-- Email Preview -->
        <div class="bg-white rounded-lg shadow">
            <div class="p-6 border-b border-gray-100">
                <h3 class="text-lg font-semibold text-gray-900">Email Preview</h3>
                <p class="text-sm text-gray-500 mt-1">See how your emails will look.</p>
            </div>
            <div class="p-6">
                <button type="button" onclick="openPreview()" class="w-full px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition-colors">
                    <i class="fa-solid fa-eye mr-2"></i>Preview Email Template
                </button>
            </div>
        </div>
        
        <!-- Quick Links -->
        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="font-semibold text-gray-900 mb-4">Related</h3>
            <div class="space-y-2">
                <a href="email_logs.php" class="flex items-center text-blue-600 hover:text-blue-800">
                    <i class="fa-solid fa-envelope-open-text w-5 mr-2"></i>
                    View Email Logs
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Email Preview Modal -->
<div id="previewModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 overflow-y-auto">
    <div class="min-h-screen px-4 text-center">
        <div class="inline-block w-full max-w-4xl my-8 text-left align-middle transition-all transform bg-white shadow-xl rounded-2xl">
            <div class="flex items-center justify-between p-6 border-b">
                <h3 class="text-lg font-semibold text-gray-900">Email Template Preview</h3>
                <button onclick="closePreview()" class="text-gray-400 hover:text-gray-600">
                    <i class="fa-solid fa-times text-xl"></i>
                </button>
            </div>
            <div class="p-6">
                <iframe id="previewFrame" class="w-full h-[600px] border rounded-lg" src="email_preview.php"></iframe>
            </div>
        </div>
    </div>
</div>

<script>
function openPreview() {
    document.getElementById('previewModal').classList.remove('hidden');
    document.getElementById('previewFrame').src = 'email_preview.php?t=' + Date.now();
    document.body.style.overflow = 'hidden';
}

function closePreview() {
    document.getElementById('previewModal').classList.add('hidden');
    document.body.style.overflow = '';
}

// Close on escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closePreview();
});

// Close on backdrop click
document.getElementById('previewModal').addEventListener('click', function(e) {
    if (e.target === this) closePreview();
});
</script>

<?php adminFooter(); ?>
