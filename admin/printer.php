<?php
/**
 * Printer Settings - Cardify
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/admin-layout.php';

Auth::requireRole('admin');

$db = Database::getInstance();
$companyId = getCurrentCompanyId();
$message = null;
$messageType = 'success';

// Get printer settings
$settings = $db->fetchOne(
    "SELECT * FROM company_settings WHERE company_id = :id",
    ['id' => $companyId]
);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = 'Invalid request. Please try again.';
        $messageType = 'error';
    }

    if ($messageType !== 'error') {
        $printerEnabled = isset($_POST['printer_enabled']) ? 1 : 0;
        $printerName = $_POST['printer_name'] ?? '';
        $printerApi = $_POST['printer_api'] ?? '';
        $printerApiKey = $_POST['printer_api_key'] ?? '';

        if ($settings) {
            $db->update('company_settings', [
                'printer_enabled' => $printerEnabled,
                'printer_name' => $printerName,
                'printer_api' => $printerApi,
                'printer_api_key' => $printerApiKey
            ], 'company_id = :id', ['id' => $companyId]);
        } else {
            $db->insert('company_settings', [
                'id' => generateUUID(),
                'company_id' => $companyId,
                'printer_enabled' => $printerEnabled,
                'printer_name' => $printerName,
                'printer_api' => $printerApi,
                'printer_api_key' => $printerApiKey
            ]);
        }

        $message = 'Printer settings saved successfully!';
        $settings = $db->fetchOne("SELECT * FROM company_settings WHERE company_id = :id", ['id' => $companyId]);
    }
}

adminHeader('Printer Settings', 'printer');
?>

<!-- Alert Message -->
<?php if ($message): ?>
<div class="mb-6 p-4 rounded-xl flex items-center gap-3 bg-green-50 border border-green-200 text-green-700">
    <i class="fa-solid fa-circle-check"></i>
    <?php echo sanitize($message); ?>
</div>
<?php endif; ?>

<form method="post" class="space-y-6">
    <?php echo csrfField(); ?>
    <!-- Enable Printer -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <div class="p-4 border-b border-gray-100">
            <h3 class="font-semibold text-gray-900 flex items-center gap-2">
                <i class="fa-solid fa-print text-blue-600"></i>
                Printer Integration
            </h3>
        </div>
        <div class="p-6">
            <label class="flex items-center gap-3 cursor-pointer">
                <input type="checkbox" name="printer_enabled" value="1" 
                       <?php echo ($settings['printer_enabled'] ?? 0) ? 'checked' : ''; ?>
                       class="w-5 h-5 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                <div>
                    <p class="font-medium text-gray-900">Enable Printer Integration</p>
                    <p class="text-sm text-gray-500">Allow employees to send cards directly to printer</p>
                </div>
            </label>
        </div>
    </div>

    <!-- Printer Configuration -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <div class="p-4 border-b border-gray-100">
            <h3 class="font-semibold text-gray-900 flex items-center gap-2">
                <i class="fa-solid fa-gear text-blue-600"></i>
                Configuration
            </h3>
        </div>
        <div class="p-6 space-y-4">
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-2">Printer Name</label>
                <input type="text" name="printer_name" value="<?php echo sanitize($settings['printer_name'] ?? ''); ?>" 
                       class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20"
                       placeholder="e.g., Office Printer">
            </div>
            
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-2">Printer API Endpoint</label>
                <input type="url" name="printer_api" value="<?php echo sanitize($settings['printer_api'] ?? ''); ?>" 
                       class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20"
                       placeholder="https://api.printer-service.com/print">
            </div>
            
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-2">API Key</label>
                <input type="password" name="printer_api_key" value="<?php echo sanitize($settings['printer_api_key'] ?? ''); ?>" 
                       class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20"
                       placeholder="Enter your printer API key">
            </div>
        </div>
    </div>

    <!-- Save Button -->
    <div class="flex justify-end">
        <button type="submit" class="px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition-colors flex items-center gap-2">
            <i class="fa-solid fa-floppy-disk"></i>
            Save Settings
        </button>
    </div>
</form>

<?php adminFooter(); ?>
