<?php
/**
 * WhatsApp API Settings - Cardify
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/WhatsApp.php';
require_once INCLUDES_DIR . '/admin-layout.php';

Auth::requireRole('super_admin');

$db = Database::getInstance();
$message = null;
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { die('Invalid request'); }
    $action = $_POST['action'] ?? '';

    if ($action === 'update_config') {
        $token     = trim($_POST['whatsapp_token'] ?? '');
        $apiUrl    = trim($_POST['whatsapp_api_url'] ?? '');
        $sessionId = trim($_POST['whatsapp_session_id'] ?? '');

        $errors = [];

        if (!empty($token) && $token !== '***hidden***') {
            $r = WhatsApp::updateToken($token);
            if (!$r['success']) { $errors[] = 'Token: ' . ($r['error'] ?? 'Unknown error'); }
        }

        if (!empty($apiUrl)) {
            $r = WhatsApp::updateApiUrl($apiUrl);
            if (!$r['success']) { $errors[] = 'API URL: ' . ($r['error'] ?? 'Unknown error'); }
        }

        if (!empty($sessionId)) {
            $r = WhatsApp::updateSessionId($sessionId);
            if (!$r['success']) { $errors[] = 'Session ID: ' . ($r['error'] ?? 'Unknown error'); }
        }

        if (empty($errors)) {
            $message = 'WhatsApp configuration saved successfully!';
            $messageType = 'success';
        } else {
            $message = 'Errors: ' . implode(', ', $errors);
            $messageType = 'error';
        }

    } elseif ($action === 'toggle_enabled') {
        $enabled = isset($_POST['enabled']) && $_POST['enabled'] === '1';
        $result = WhatsApp::updateEnabled($enabled);
        $message = $result['success']
            ? ($enabled ? 'WhatsApp notifications enabled!' : 'WhatsApp notifications disabled!')
            : 'Error: ' . ($result['error'] ?? 'Unknown error');
        $messageType = $result['success'] ? 'success' : 'error';

    } elseif ($action === 'toggle_scan_invite') {
        // Per-COMPANY, unlike the settings above it, which are global config.
        // Governs api/scan/invite.php: one unsolicited WhatsApp to a person who
        // was scanned, once ever, unrecallable. Off unless deliberately enabled.
        $on = isset($_POST['scan_invite_enabled']) ? 1 : 0;
        $cid = getCurrentCompanyId();
        $res = $cid
            ? DatabaseAdapter::updateCompanySettings($cid, ['scan_invite_enabled' => $on])
            : ['success' => false, 'error' => 'No company in session'];
        $message = !empty($res['success'])
            ? ($on ? 'Scan invites enabled for this company.' : 'Scan invites disabled.')
            : 'Error: ' . ($res['error'] ?? 'Unknown error');
        $messageType = !empty($res['success']) ? 'success' : 'error';

    } elseif ($action === 'test') {
        // Get super admin phone from users table
        $adminUser = $db->fetchOne(
            "SELECT phone FROM users WHERE role = 'super_admin' ORDER BY created_at ASC LIMIT 1"
        );
        $testPhone = $adminUser['phone'] ?? null;

        if (empty($testPhone)) {
            $message = 'No phone number found for super admin. Please set a phone number in your user profile first.';
            $messageType = 'error';
        } else {
            $result = WhatsApp::sendMessage($testPhone, 'Cardify WhatsApp test - connection successful!');
            if ($result['success']) {
                $message = 'Test message sent successfully to ' . htmlspecialchars($testPhone) . '!';
                $messageType = 'success';
            } else {
                $message = 'Test failed: ' . ($result['error'] ?? 'Unknown error');
                $messageType = 'error';
            }
        }
    }
}

$settings = WhatsApp::getSettings();
// Per-company row backing the Cardify Scan invite switch below. A company
// with no settings row reads as disabled, matching the server-side gate in
// api/scan/invite.php.
$companySettings = [];
if ($cidForSettings = getCurrentCompanyId()) {
    $companySettings = $db->fetchOne(
        "SELECT scan_invite_enabled FROM company_settings WHERE company_id = :cid",
        ["cid" => $cidForSettings]
    ) ?: [];
}

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
            <?php echo csrfField(); ?>
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

<!-- Scan app: one-time claim invite (PER COMPANY) -->
<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden mb-6">
    <div class="p-4 border-b border-gray-100">
        <h3 class="font-semibold text-gray-900 flex items-center gap-2">
            <i class="fa-light fa-address-card text-gray-400"></i>
            Cardify Scan, claim invites
        </h3>
    </div>
    <div class="p-4">
        <form method="POST">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="toggle_scan_invite">
            <div class="flex items-center justify-between gap-4">
                <div>
                    <p class="font-medium text-gray-900">Let employees WhatsApp a scanned contact</p>
                    <p class="text-sm text-gray-500">
                        When an employee scans someone's card, they can send that person
                        <strong>one</strong> WhatsApp inviting them to claim a free Cardify card.
                        One message per person, ever. It cannot be recalled, it goes to someone
                        who did not ask to hear from you, and it is sent from your company's
                        number. Off by default.
                    </p>
                </div>
                <label class="relative inline-flex items-center cursor-pointer shrink-0">
                    <input type="checkbox" name="scan_invite_enabled" value="1"
                           <?php echo !empty($companySettings['scan_invite_enabled']) ? 'checked' : ''; ?>
                           onchange="this.form.submit()" class="sr-only peer">
                    <div class="w-11 h-6 bg-gray-200 rounded-full peer peer-checked:after:translate-x-full after:content-[''] after:absolute after:top-0.5 after:left-[2px] after:bg-white after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-green-600"></div>
                </label>
            </div>
        </form>
    </div>
</div>

<!-- API Configuration -->
<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden mb-6">
    <div class="p-4 border-b border-gray-100">
        <h3 class="font-semibold text-gray-900 flex items-center gap-2">
            <i class="fa-solid fa-key text-blue-600"></i>
            Dardasha API Configuration
        </h3>
    </div>
    <div class="p-6">
        <form method="post" class="space-y-5">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="update_config">

            <!-- API URL -->
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-2">API URL</label>
                <input type="text" name="whatsapp_api_url"
                       value="<?php echo htmlspecialchars($settings['api_url'] ?? 'https://dardasha.om/api/send-message'); ?>"
                       class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20"
                       placeholder="https://dardasha.om/api/send-message">
                <p class="text-xs text-gray-500 mt-1">Dardasha REST API endpoint for sending messages</p>
            </div>

            <!-- Session ID -->
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-2">Session ID</label>
                <input type="text" name="whatsapp_session_id"
                       value="<?php echo htmlspecialchars($settings['session_id'] ?? 'anna'); ?>"
                       class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20"
                       placeholder="anna">
                <p class="text-xs text-gray-500 mt-1">Dardasha WhatsApp session/line name (e.g. <code class="bg-gray-100 px-1 rounded">anna</code>)</p>
            </div>

            <!-- API Token -->
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-2">API Token</label>
                <input type="password" name="whatsapp_token"
                       value="<?php echo !empty($settings['token']) ? '***hidden***' : ''; ?>"
                       class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20"
                       placeholder="Enter your Dardasha API token"
                       onfocus="if(this.value==='***hidden***') this.value=''">
                <p class="text-xs text-gray-500 mt-1">Bearer token for authenticating with the Dardasha API</p>
            </div>

            <div class="flex items-center justify-between pt-2">
                <button type="submit" class="px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition-colors flex items-center gap-2">
                    <i class="fa-solid fa-floppy-disk"></i>
                    Save Configuration
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Test & Status -->
<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
    <div class="p-4 border-b border-gray-100">
        <h3 class="font-semibold text-gray-900 flex items-center gap-2">
            <i class="fa-solid fa-circle-info text-gray-500"></i>
            Status &amp; Test
        </h3>
    </div>
    <div class="p-6 space-y-4">
        <!-- Current status -->
        <div class="flex items-center gap-4 flex-wrap">
            <span class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full text-sm font-medium <?php echo ($settings['enabled'] ?? false) ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600'; ?>">
                <span class="w-2 h-2 rounded-full <?php echo ($settings['enabled'] ?? false) ? 'bg-green-500' : 'bg-gray-400'; ?>"></span>
                <?php echo ($settings['enabled'] ?? false) ? 'Active' : 'Disabled'; ?>
            </span>
            <span class="text-sm text-gray-500">
                Token: <?php echo !empty($settings['token']) ? 'Configured' : 'Not set'; ?>
            </span>
            <span class="text-sm text-gray-500">
                Session: <code class="bg-gray-100 px-1 rounded"><?php echo htmlspecialchars($settings['session_id'] ?? 'anna'); ?></code>
            </span>
        </div>

        <!-- Send Test Message -->
        <form method="post">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="test">
            <div class="flex items-center gap-3">
                <button type="submit"
                        class="px-5 py-2.5 bg-green-600 hover:bg-green-700 text-white rounded-lg font-medium transition-colors flex items-center gap-2 <?php echo !($settings['enabled'] ?? false) ? 'opacity-50 cursor-not-allowed' : ''; ?>"
                        <?php echo !($settings['enabled'] ?? false) ? 'disabled' : ''; ?>>
                    <i class="fa-brands fa-whatsapp"></i>
                    Send Test Message
                </button>
                <p class="text-xs text-gray-500">Sends a test message to the super admin's phone number</p>
            </div>
        </form>
    </div>
</div>

<?php adminFooter(); ?>
