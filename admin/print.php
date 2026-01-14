<?php
/**
 * Print Orders Management
 * Send business cards to print
 */
require_once __DIR__ . '/../config.php';
requireAdmin();
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/WhatsApp.php';

$db = Database::getInstance();
$companyId = getCurrentCompanyId();

if (!$companyId) {
    header('Location: ' . getBasePath() . 'login.php');
    exit;
}

$message = null;

// Get employees
$employees = $db->fetchAll(
    "SELECT * FROM employees WHERE company_id = :id ORDER BY name_en, name_ar",
    ['id' => $companyId]
);

// Get templates
$templates = $db->fetchAll(
    "SELECT * FROM templates WHERE company_id = :id AND is_active = 1 ORDER BY name",
    ['id' => $companyId]
);

// Get print orders
$orders = $db->fetchAll(
    "SELECT * FROM print_orders WHERE company_id = :id ORDER BY created_at DESC LIMIT 20",
    ['id' => $companyId]
);

// Handle print order creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_order') {
    $employeeIds = $_POST['employee_ids'] ?? [];
    $templateId = $_POST['template_id'] ?? '';
    $quantity = (int)($_POST['quantity'] ?? 1);
    $notes = $_POST['notes'] ?? '';
    $poFilePath = null;
    
    // Handle P.O. file upload
    if (isset($_FILES['po_file']) && $_FILES['po_file']['error'] === UPLOAD_ERR_OK) {
        $allowedTypes = ['application/pdf', 'image/jpeg', 'image/png', 'image/jpg'];
        $fileType = $_FILES['po_file']['type'];
        
        if (in_array($fileType, $allowedTypes)) {
            $uploadDir = getCompanyUploadsDir($companyId) . '/po';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            $fileName = 'po_' . date('Ymd_His') . '_' . uniqid() . '_' . basename($_FILES['po_file']['name']);
            $filePath = $uploadDir . '/' . $fileName;
            
            if (move_uploaded_file($_FILES['po_file']['tmp_name'], $filePath)) {
                $poFilePath = getWebPath($filePath);
            }
        }
    }
    
    if (!empty($employeeIds) && !empty($templateId)) {
        $orderId = generateUUID();
        $orderNumber = 'PRINT-' . strtoupper(substr($orderId, 0, 8));
        
        $insertData = [
            'id' => $orderId,
            'company_id' => $companyId,
            'order_number' => $orderNumber,
            'employee_ids' => json_encode($employeeIds),
            'template_id' => $templateId,
            'quantity' => $quantity,
            'status' => 'pending',
            'notes' => $notes,
            'created_by' => $_SESSION['user_id'] ?? null
        ];
        
        if ($poFilePath) {
            $insertData['po_file_path'] = $poFilePath;
        }
        
        $db->insert('print_orders', $insertData);
        
        // Send WhatsApp confirmation if enabled
        $whatsappSent = false;
        if (WhatsApp::isEnabled()) {
            $company = $db->fetchOne(
                "SELECT * FROM companies WHERE id = :id",
                ['id' => $companyId]
            );
            
            if ($company) {
                $whatsappResult = WhatsApp::sendPrintOrderConfirmation(
                    array_merge($insertData, ['order_number' => $orderNumber]),
                    $company
                );
                $whatsappSent = $whatsappResult['success'];
            }
        }
        
        $message = 'Print order created successfully! Order #' . $orderNumber;
        if ($whatsappSent) {
            $message .= ' WhatsApp confirmation sent.';
        }
        
        // Reload orders
        $orders = $db->fetchAll(
            "SELECT * FROM print_orders WHERE company_id = :id ORDER BY created_at DESC LIMIT 20",
            ['id' => $companyId]
        );
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Print Orders | <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="<?php echo assetUrl('css/tailwind.css'); ?>">
    <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>
        .glass-card { background: rgba(255, 255, 255, 0.03); backdrop-filter: blur(20px); border: 1px solid rgba(255, 255, 255, 0.1); }
    </style>
</head>
<body class="bg-alzayani-dark text-white font-sans min-h-screen" x-data="{ selectedEmployees: [] }">
    <div class="min-h-screen">
        <header class="glass-card border-b border-white/10">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-2xl font-bold text-white">Print Orders</h1>
                        <p class="text-gray-400 text-sm">Send business cards to print</p>
                    </div>
                    <a href="index.php" class="px-4 py-2 rounded-lg bg-white/5 hover:bg-white/10 transition-colors">
                        ← Back
                    </a>
                </div>
            </div>
        </header>
        
        <main class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
            <?php if ($message): ?>
            <div class="mb-6 p-4 rounded-xl bg-green-500/10 border border-green-500/30 text-green-400">
                <?php echo sanitize($message); ?>
            </div>
            <?php endif; ?>
            
            <!-- Create Print Order -->
            <div class="glass-card rounded-xl p-6 mb-8">
                <h2 class="text-xl font-bold mb-4">Create Print Order</h2>
                <form method="post" enctype="multipart/form-data" class="space-y-4">
                    <input type="hidden" name="action" value="create_order">
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-2">Select Employees</label>
                        <div class="max-h-60 overflow-y-auto border border-white/10 rounded-lg p-4 bg-white/5">
                            <?php foreach ($employees as $employee): ?>
                            <label class="flex items-center space-x-3 p-2 hover:bg-white/5 rounded cursor-pointer">
                                <input type="checkbox" name="employee_ids[]" value="<?php echo sanitize($employee['id']); ?>" 
                                       x-model="selectedEmployees"
                                       class="rounded border-white/20">
                                <span><?php echo sanitize($employee['name_en'] ?? $employee['name_ar'] ?? $employee['email']); ?></span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                        <p class="text-xs text-gray-400 mt-2">Selected: <span x-text="selectedEmployees.length"></span> employees</p>
                    </div>
                    
                    <div class="grid md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-2">Template</label>
                            <select name="template_id" required class="w-full px-4 py-2 rounded-lg bg-white/5 border border-white/10 text-white">
                                <option value="">Select Template</option>
                                <?php foreach ($templates as $template): ?>
                                <option value="<?php echo sanitize($template['id']); ?>"><?php echo sanitize($template['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-2">Quantity per Employee</label>
                            <input type="number" name="quantity" value="1" min="1" required class="w-full px-4 py-2 rounded-lg bg-white/5 border border-white/10 text-white">
                        </div>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-2">Notes (Optional)</label>
                        <textarea name="notes" rows="3" class="w-full px-4 py-2 rounded-lg bg-white/5 border border-white/10 text-white" placeholder="Special instructions for printer..."></textarea>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-2">Purchase Order (P.O.) Document (Optional)</label>
                        <input type="file" name="po_file" accept=".pdf,.jpg,.jpeg,.png" class="w-full px-4 py-2 rounded-lg bg-white/5 border border-white/10 text-white file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-amber-500/20 file:text-amber-400 hover:file:bg-amber-500/30">
                        <p class="text-xs text-gray-400 mt-1">Accepted formats: PDF, JPG, PNG (Max 10MB)</p>
                    </div>
                    
                    <button type="submit" class="px-6 py-2 rounded-lg bg-gradient-to-r from-amber-500 to-amber-600 text-white font-bold hover:from-amber-600 hover:to-amber-700 transition-colors">
                        Create Print Order
                    </button>
                </form>
            </div>
            
            <!-- Print Orders List -->
            <div class="glass-card rounded-xl p-6">
                <h2 class="text-xl font-bold mb-4">Print Orders</h2>
                <div class="space-y-4">
                    <?php if (empty($orders)): ?>
                    <p class="text-gray-400 text-center py-8">No print orders yet.</p>
                    <?php else: ?>
                    <?php foreach ($orders as $order): ?>
                    <div class="p-4 rounded-lg bg-white/5">
                        <div class="flex items-start justify-between">
                            <div>
                                <div class="font-semibold mb-2">Order #<?php echo sanitize($order['order_number']); ?></div>
                                <div class="text-sm text-gray-400 space-y-1">
                                    <div>Employees: <?php echo count(json_decode($order['employee_ids'], true)); ?></div>
                                    <div>Quantity: <?php echo $order['quantity']; ?> per employee</div>
                                    <div>Status: <span class="text-amber-400"><?php echo sanitize($order['status']); ?></span></div>
                                    <?php if ($order['notes']): ?>
                                    <div>Notes: <?php echo sanitize($order['notes']); ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($order['po_file_path'])): ?>
                                    <div>
                                        <a href="<?php echo imageUrl($order['po_file_path']); ?>" target="_blank" class="text-amber-400 hover:text-amber-300 underline">
                                            📄 View P.O. Document
                                        </a>
                                    </div>
                                    <?php endif; ?>
                                    <?php if (isset($order['po_required']) && $order['po_required']): ?>
                                    <div class="text-amber-400">
                                        ⚠ PO Required for Processing
                                        <?php if (isset($order['po_approved']) && $order['po_approved']): ?>
                                        <span class="text-green-400">✓ Approved</span>
                                        <?php elseif (!empty($order['po_file_path'])): ?>
                                        <span class="text-yellow-400">⏳ Pending Approval</span>
                                        <?php else: ?>
                                        <span class="text-red-400">✗ Missing</span>
                                        <?php endif; ?>
                                    </div>
                                    <?php endif; ?>
                                    <div>Created: <?php echo date('M d, Y H:i', strtotime($order['created_at'])); ?></div>
                                </div>
                            </div>
                            <div>
                                <span class="px-3 py-1 rounded-full text-xs font-semibold 
                                    <?php echo $order['status'] === 'completed' ? 'bg-green-500/20 text-green-400' : 
                                               ($order['status'] === 'processing' ? 'bg-blue-500/20 text-blue-400' : 'bg-amber-500/20 text-amber-400'); ?>">
                                    <?php echo sanitize($order['status']); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>
</body>
</html>
