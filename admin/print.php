<?php
/**
 * Print Orders Management
 * Send business cards to print - Company Admin Interface
 */
require_once __DIR__ . '/../config.php';
requireAdmin();
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/WhatsApp.php';
require_once INCLUDES_DIR . '/Currency.php';
require_once INCLUDES_DIR . '/admin-layout.php';

// Include print shop classes if available
if (file_exists(INCLUDES_DIR . '/PrintShop.php')) {
    require_once INCLUDES_DIR . '/PrintShop.php';
}
if (file_exists(INCLUDES_DIR . '/PrintShopIntegration.php')) {
    require_once INCLUDES_DIR . '/PrintShopIntegration.php';
}

$db = Database::getInstance();
$companyId = getCurrentCompanyId();

if (!$companyId) {
    header('Location: ' . getBasePath() . 'login.php');
    exit;
}

$message = null;
$messageType = 'success';

// Get company info for currency
$company = $db->fetchOne("SELECT * FROM companies WHERE id = :id", ['id' => $companyId]);
$companyCurrency = $company['currency'] ?? 'OMR';

// Get current tab
$tab = $_GET['tab'] ?? 'orders';

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

// Get print orders - fetch more for better history
$statusFilter = $_GET['status'] ?? '';
$ordersSql = "SELECT * FROM print_orders WHERE company_id = :id";
$ordersParams = ['id' => $companyId];

if ($statusFilter) {
    $ordersSql .= " AND status = :status";
    $ordersParams['status'] = $statusFilter;
}
$ordersSql .= " ORDER BY created_at DESC LIMIT 50";
$orders = $db->fetchAll($ordersSql, $ordersParams);

// Get order stats
$orderStats = [
    'total' => 0,
    'pending' => 0,
    'processing' => 0,
    'shipped' => 0,
    'delivered' => 0
];
try {
    $statsResult = $db->fetchAll(
        "SELECT status, COUNT(*) as count FROM print_orders WHERE company_id = :id GROUP BY status",
        ['id' => $companyId]
    );
    foreach ($statsResult as $stat) {
        $orderStats[$stat['status']] = (int)$stat['count'];
        $orderStats['total'] += (int)$stat['count'];
    }
} catch (Exception $e) {
    // Ignore
}

// Get print shops
$printShops = [];
try {
    if (class_exists('PrintShop')) {
        $printShops = PrintShop::getAll('active', 20);
    }
} catch (Exception $e) {
    // Table may not exist
}

// Handle quotation request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'request_quotation') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { die('Invalid request'); }
    $orderId = $_POST['order_id'] ?? '';
    if (!empty($orderId)) {
        $db->update('print_orders',
            ['quotation_requested' => 1],
            'id = :id AND company_id = :company_id',
            ['id' => $orderId, 'company_id' => $companyId]
        );
        $message = 'Quotation requested successfully!';
        
        // Reload orders
        $orders = $db->fetchAll($ordersSql, $ordersParams);
    }
}

// Handle print order creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_order') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { die('Invalid request'); }
    $employeeIds = $_POST['employee_ids'] ?? [];
    $templateId = $_POST['template_id'] ?? '';
    $quantity = (int)($_POST['quantity'] ?? 1);
    $notes = $_POST['notes'] ?? '';
    $poFilePath = null;
    
    // Handle P.O. file upload
    if (isset($_FILES['po_file']) && $_FILES['po_file']['error'] === UPLOAD_ERR_OK) {
        // SECURITY: detect real MIME from contents (never $_FILES['type']) and
        // build the filename from a verified extension, not the uploaded name
        // (which could be po_..._evil.php). BHD loop audit iter 4, 2 Jun 2026.
        $mimeToExt = [
            'application/pdf' => 'pdf',
            'image/jpeg'      => 'jpg',
            'image/png'       => 'png',
        ];
        $realMime = '';
        if (class_exists('finfo')) {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $realMime = (string) $finfo->file($_FILES['po_file']['tmp_name']);
        }

        if (isset($mimeToExt[$realMime])) {
            $uploadDir = getCompanyUploadsDir($companyId) . '/po';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            $fileName = 'po_' . date('Ymd_His') . '_' . uniqid() . '.' . $mimeToExt[$realMime];
            $filePath = $uploadDir . '/' . $fileName;

            if (move_uploaded_file($_FILES['po_file']['tmp_name'], $filePath)) {
                @chmod($filePath, 0644);
                $poFilePath = getWebPath($filePath);
            }
        }
    }
    
    if (!empty($employeeIds) && !empty($templateId)) {
        $orderId = generateUUID();
        $orderNumber = 'PRINT-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid()), 0, 5));
        
        // Use correct column names for definitive schema
        $insertData = [
            'order_number' => $orderNumber,
            'company_id' => $companyId,
            'employee_id' => !empty($employeeIds) ? $employeeIds[0] : null, // Single employee
            'user_id' => $_SESSION['user_id'] ?? null,
            'card_template_id' => $templateId,
            'quantity' => $quantity,
            'status' => 'pending',
            'notes' => $notes,
            'created_at' => dbNow()
        ];
        
        if ($poFilePath) {
            $insertData['po_file_path'] = $poFilePath;
        }
        
        $db->insert('print_orders', $insertData);
        
        // Get company and employee data for notifications
        $company = $db->fetchOne("SELECT * FROM companies WHERE id = :id", ['id' => $companyId]);
        $employee = null;
        if (!empty($employeeIds[0])) {
            $employee = $db->fetchOne("SELECT * FROM employees WHERE id = :id", ['id' => $employeeIds[0]]);
        }
        
        // Send email confirmation to company admin
        $emailSent = false;
        if ($company && !empty($company['admin_email'])) {
            try {
                require_once INCLUDES_DIR . '/Mailer.php';
                require_once INCLUDES_DIR . '/Currency.php';
                $siteName = defined('SITE_NAME') ? SITE_NAME : 'Cardify';
                
                Mailer::sendTemplate($company['admin_email'], 'print_order_submitted', [
                    'site_name' => $siteName,
                    'contact_name' => $company['name_en'] ?? $company['name'] ?? 'Admin',
                    'company_name' => $company['name_en'] ?? $company['name'] ?? 'Your Company',
                    'order_id' => $orderNumber,
                    'quantity' => $quantity,
                    'paper_type' => 'Standard',
                    'finish' => 'Standard',
                    'total' => 'To be calculated',
                    'print_shop_name' => 'Internal',
                    'print_shop_location' => ''
                ]);
                $emailSent = true;
            } catch (Exception $e) {
                error_log("Failed to send print order confirmation email: " . $e->getMessage());
            }
        }
        
        // Send WhatsApp confirmation if enabled
        $whatsappSent = false;
        if (WhatsApp::isEnabled() && $company) {
            $whatsappResult = WhatsApp::sendPrintOrderConfirmation(
                array_merge($insertData, ['order_number' => $orderNumber]),
                $company
            );
            $whatsappSent = $whatsappResult['success'];
        }

        // Fire print_order_placed notification (email + WhatsApp via Notifier)
        try {
            require_once INCLUDES_DIR . '/Notifier.php';
            require_once INCLUDES_DIR . '/Currency.php';
            require_once INCLUDES_DIR . '/functions.php';

            $order = array_merge($insertData, ['id' => $orderId, 'order_number' => $orderNumber]);
            $userCur = Currency::getUserCurrency();
            $orderTotal = (float)($order['total_amount'] ?? 0);
            $displayAmount = Currency::format(Currency::convert($orderTotal, $userCur), $userCur);
            $omrAmount = Currency::format($orderTotal, 'OMR');

            Notifier::send('print_order_placed', [
                'name'       => $company['name_en'] ?? $company['name'] ?? 'Customer',
                'email'      => $company['admin_email'] ?? $company['email'] ?? null,
                'phone'      => $company['phone'] ?? null,
                'company_id' => $company['id'] ?? $companyId,
            ], [
                'name'          => $company['name_en'] ?? $company['name'] ?? 'Customer',
                'orderNumber'   => $orderNumber,
                'displayAmount' => $displayAmount,
                'omrAmount'     => $omrAmount,
                'quantity'      => (int)$quantity,
            ]);
        } catch (Throwable $e) {
            error_log('[print_order] Notifier failed: ' . $e->getMessage());
        }

        $message = 'Print order created successfully! Order #' . $orderNumber;
        if ($emailSent) {
            $message .= ' Email confirmation sent.';
        }
        if ($whatsappSent) {
            $message .= ' WhatsApp confirmation sent.';
        }
        
        // Reload orders
        $orders = $db->fetchAll($ordersSql, $ordersParams);
        $tab = 'orders'; // Switch to orders tab
    } else {
        $message = 'Please select at least one employee and a template.';
        $messageType = 'error';
    }
}

// Status colors
$statusColors = [
    'pending' => 'bg-amber-100 text-amber-700',
    'submitted' => 'bg-blue-100 text-blue-700',
    'processing' => 'bg-indigo-100 text-indigo-700',
    'printing' => 'bg-purple-100 text-purple-700',
    'shipped' => 'bg-cyan-100 text-cyan-700',
    'delivered' => 'bg-green-100 text-green-700',
    'completed' => 'bg-green-100 text-green-700',
    'cancelled' => 'bg-red-100 text-red-700'
];

// Get base path for links
$basePath = getAdminBasePath();
$isCompanyAdmin = defined('COMPANY_ADMIN_BASE') || !empty($_SESSION['company_slug']);
$ext = $isCompanyAdmin ? '' : '.php';

adminHeader('Print Orders', 'print');
?>

<!-- Alert Message -->
<?php if ($message): ?>
<div class="mb-6 p-4 rounded-xl flex items-center gap-3 <?php echo $messageType === 'success' ? 'bg-green-50 border border-green-200 text-green-700' : 'bg-red-50 border border-red-200 text-red-700'; ?>">
    <i class="<?php echo $messageType === 'success' ? 'fa-solid fa-circle-check' : 'fa-solid fa-circle-exclamation'; ?>"></i>
    <?php echo sanitize($message); ?>
</div>
<?php endif; ?>

<!-- Stats Cards -->
<div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-6">
    <a href="?tab=orders" class="p-4 rounded-xl border <?php echo empty($statusFilter) && $tab === 'orders' ? 'border-blue-500 bg-blue-50' : 'border-gray-200 bg-white hover:border-gray-300'; ?> transition-all">
        <p class="text-2xl font-bold text-blue-600"><?php echo $orderStats['total']; ?></p>
        <p class="text-sm text-gray-500">Total Orders</p>
    </a>
    <a href="?tab=orders&status=pending" class="p-4 rounded-xl border <?php echo $statusFilter === 'pending' ? 'border-amber-500 bg-amber-50' : 'border-gray-200 bg-white hover:border-gray-300'; ?> transition-all">
        <p class="text-2xl font-bold text-amber-600"><?php echo $orderStats['pending']; ?></p>
        <p class="text-sm text-gray-500">Pending</p>
    </a>
    <a href="?tab=orders&status=processing" class="p-4 rounded-xl border <?php echo $statusFilter === 'processing' ? 'border-indigo-500 bg-indigo-50' : 'border-gray-200 bg-white hover:border-gray-300'; ?> transition-all">
        <p class="text-2xl font-bold text-indigo-600"><?php echo $orderStats['processing']; ?></p>
        <p class="text-sm text-gray-500">Processing</p>
    </a>
    <a href="?tab=orders&status=shipped" class="p-4 rounded-xl border <?php echo $statusFilter === 'shipped' ? 'border-cyan-500 bg-cyan-50' : 'border-gray-200 bg-white hover:border-gray-300'; ?> transition-all">
        <p class="text-2xl font-bold text-cyan-600"><?php echo $orderStats['shipped']; ?></p>
        <p class="text-sm text-gray-500">Shipped</p>
    </a>
    <a href="?tab=orders&status=delivered" class="p-4 rounded-xl border <?php echo $statusFilter === 'delivered' ? 'border-green-500 bg-green-50' : 'border-gray-200 bg-white hover:border-gray-300'; ?> transition-all">
        <p class="text-2xl font-bold text-green-600"><?php echo $orderStats['delivered']; ?></p>
        <p class="text-sm text-gray-500">Delivered</p>
    </a>
</div>

<!-- Tabs -->
<div class="border-b border-gray-200 mb-6">
    <nav class="-mb-px flex space-x-8">
        <a href="?tab=orders" class="<?php echo $tab === 'orders' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?> whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm flex items-center gap-2">
            <i class="fa-solid fa-box"></i>
            My Orders
            <?php if ($orderStats['total'] > 0): ?>
            <span class="bg-gray-100 text-gray-600 px-2 py-0.5 rounded-full text-xs"><?php echo $orderStats['total']; ?></span>
            <?php endif; ?>
        </a>
        <a href="?tab=create" class="<?php echo $tab === 'create' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?> whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm flex items-center gap-2">
            <i class="fa-solid fa-plus"></i>
            Create Order
        </a>
        <a href="?tab=shops" class="<?php echo $tab === 'shops' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?> whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm flex items-center gap-2">
            <i class="fa-solid fa-store"></i>
            Print Shops
            <?php if (count($printShops) > 0): ?>
            <span class="bg-green-100 text-green-600 px-2 py-0.5 rounded-full text-xs"><?php echo count($printShops); ?></span>
            <?php endif; ?>
        </a>
        <a href="<?= $basePath ?>credit-accounts<?= $ext ?>" class="border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm flex items-center gap-2">
            <i class="fa-solid fa-building-columns"></i>
            Credit
        </a>
    </nav>
</div>

<?php if ($tab === 'orders'): ?>
<!-- Orders List -->
<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
    <div class="p-4 border-b border-gray-100 flex items-center justify-between">
        <h2 class="text-lg font-semibold text-gray-900">
            <?php echo $statusFilter ? ucfirst($statusFilter) . ' Orders' : 'All Orders'; ?>
        </h2>
        <a href="?tab=create" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium transition-colors flex items-center gap-2">
            <i class="fa-solid fa-plus"></i>
            New Order
        </a>
    </div>
    
    <?php if (empty($orders)): ?>
    <div class="p-12 text-center">
        <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
            <i class="fa-solid fa-print text-2xl text-gray-400"></i>
        </div>
        <h3 class="text-lg font-medium text-gray-900 mb-2"><?= htmlspecialchars(t('emptystates.no_orders_h')) ?></h3>
        <p class="text-gray-500 mb-4"><?= htmlspecialchars(t('emptystates.no_orders_sub')) ?></p>
        <a href="?tab=create" class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium transition-colors">
            <i class="fa-solid fa-plus"></i>
            Create Order
        </a>
    </div>
    <?php else: ?>
    <div class="divide-y divide-gray-100">
        <?php foreach ($orders as $order): 
            // Handle both old (employee_ids JSON) and new (employee_id single) schemas
            $employeeCount = 0;
            $isGenericOrder = true;
            if (!empty($order['employee_ids'])) {
                $employeeIds = json_decode($order['employee_ids'], true);
                $employeeCount = is_array($employeeIds) ? count($employeeIds) : 0;
                $isGenericOrder = ($employeeCount == 0);
            } elseif (!empty($order['employee_id'])) {
                $employeeCount = 1;
                $isGenericOrder = false;
            }
            $statusClass = $statusColors[$order['status']] ?? 'bg-gray-100 text-gray-700';
        ?>
        <div class="p-4 hover:bg-gray-50 transition-colors">
            <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
                <!-- Order Info -->
                <div class="flex-1">
                    <div class="flex items-center gap-3 mb-2 flex-wrap">
                        <span class="font-semibold text-gray-900">#<?php echo sanitize($order['order_number'] ?? $order['id']); ?></span>
                        <span class="px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $statusClass; ?>">
                            <?php echo ucfirst(sanitize($order['status'])); ?>
                        </span>
                        
                        <!-- Document Status Indicators -->
                        <?php if (!empty($order['quotation_file_path'])): ?>
                        <span class="px-2 py-0.5 rounded text-xs <?php echo !empty($order['quotation_accepted']) ? 'bg-green-100 text-green-700' : 'bg-blue-100 text-blue-700'; ?>" title="Quotation">
                            <i class="fa-solid fa-file-invoice mr-1"></i>Quote <?php echo !empty($order['quotation_accepted']) ? '✓' : ''; ?>
                        </span>
                        <?php endif; ?>
                        
                        <?php if (!empty($order['po_required']) && empty($order['po_file_path'])): ?>
                        <span class="px-2 py-0.5 rounded text-xs bg-amber-100 text-amber-700" title="PO Required">
                            <i class="fa-solid fa-exclamation-triangle mr-1"></i>PO Required
                        </span>
                        <?php elseif (!empty($order['po_file_path'])): ?>
                        <span class="px-2 py-0.5 rounded text-xs bg-green-100 text-green-700" title="PO Received">
                            <i class="fa-solid fa-file-contract mr-1"></i>PO ✓
                        </span>
                        <?php endif; ?>
                        
                        <?php if (!empty($order['invoice_file_path'])): ?>
                        <span class="px-2 py-0.5 rounded text-xs <?php echo !empty($order['invoice_paid']) ? 'bg-green-100 text-green-700' : 'bg-amber-100 text-amber-700'; ?>" title="Invoice">
                            <i class="fa-solid fa-file-invoice-dollar mr-1"></i>Invoice <?php echo !empty($order['invoice_paid']) ? '✓' : ''; ?>
                        </span>
                        <?php endif; ?>
                        
                        <?php if (!empty($order['tracking_number'])): ?>
                        <span class="text-xs text-gray-500">
                            <i class="fa-solid fa-truck mr-1"></i>
                            <?php echo sanitize($order['tracking_number']); ?>
                        </span>
                        <?php endif; ?>

                        <?php
                        $payStatus = $order['payment_status'] ?? 'pending';
                        if ($payStatus === 'paid'): ?>
                        <span class="px-2 py-0.5 rounded text-xs bg-green-100 text-green-700" title="Payment">
                            <i class="fa-solid fa-credit-card mr-1"></i>Paid <?= !empty($order['payment_method']) ? '(' . ucfirst($order['payment_method']) . ')' : '' ?>
                        </span>
                        <?php elseif (!empty($order['total']) && (float)$order['total'] > 0): ?>
                        <span class="px-2 py-0.5 rounded text-xs bg-orange-100 text-orange-700" title="Payment Pending">
                            <i class="fa-solid fa-credit-card mr-1"></i>Unpaid
                        </span>
                        <?php endif; ?>
                    </div>
                    <div class="text-sm text-gray-600 space-y-1">
                        <div class="flex items-center gap-4">
                            <?php if ($isGenericOrder): ?>
                            <span><i class="fa-solid fa-building text-gray-400 mr-1"></i> Company cards</span>
                            <?php else: ?>
                            <span><i class="fa-solid fa-users text-gray-400 mr-1"></i> <?php echo $employeeCount; ?> employee<?php echo $employeeCount !== 1 ? 's' : ''; ?></span>
                            <?php endif; ?>
                            <span><i class="fa-solid fa-copy text-gray-400 mr-1"></i> <?php echo (int)($order['quantity'] ?? 1); ?> cards</span>
                            <?php if (!empty($order['total'])): ?>
                            <span class="font-medium text-gray-900"><i class="fa-solid fa-tag text-gray-400 mr-1"></i> <?php echo Currency::formatHtml($order['total'], $companyCurrency, 'sm'); ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($order['notes'])): ?>
                        <p class="text-gray-500 text-xs"><i class="fa-solid fa-comment text-gray-400 mr-1"></i> <?php echo sanitize($order['notes']); ?></p>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Order Meta & Actions -->
                <div class="flex items-center gap-4">
                    <div class="text-right text-sm">
                        <div class="text-gray-900"><?php echo date('M d, Y', dbTs($order['created_at'])); ?></div>
                        <div class="text-gray-500 text-xs"><?php echo date('h:i A', dbTs($order['created_at'])); ?></div>
                    </div>
                    
                    <!-- Action Buttons -->
                    <div class="flex items-center gap-2">
                        <?php if (!empty($order['po_file_path'])): ?>
                        <a href="<?php echo imageUrl($order['po_file_path']); ?>" target="_blank" 
                           class="p-2 text-gray-500 hover:text-blue-600 hover:bg-blue-50 rounded-lg transition-colors" title="View P.O.">
                            <i class="fa-solid fa-file-invoice"></i>
                        </a>
                        <?php endif; ?>
                        
                        <?php if (!empty($order['quotation_file_path'])): ?>
                        <a href="<?php echo imageUrl($order['quotation_file_path']); ?>" target="_blank" 
                           class="p-2 text-gray-500 hover:text-green-600 hover:bg-green-50 rounded-lg transition-colors" title="View Quotation">
                            <i class="fa-solid fa-file-lines"></i>
                        </a>
                        <?php endif; ?>
                        
                        <?php if (!empty($order['invoice_file_path'])): ?>
                        <a href="<?php echo imageUrl($order['invoice_file_path']); ?>" target="_blank" 
                           class="p-2 text-gray-500 hover:text-green-600 hover:bg-green-50 rounded-lg transition-colors" title="View Invoice">
                            <i class="fa-solid fa-file-invoice-dollar"></i>
                        </a>
                        <?php endif; ?>
                        
                        <?php if (empty($order['quotation_requested']) && $order['status'] === 'pending'): ?>
                        <form method="post" class="inline">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="action" value="request_quotation">
                            <input type="hidden" name="order_id" value="<?php echo sanitize($order['id']); ?>">
                            <button type="submit" class="px-3 py-1.5 bg-blue-50 text-blue-700 hover:bg-blue-100 rounded-lg transition-colors text-xs font-medium">
                                Request Quote
                            </button>
                        </form>
                        <?php endif; ?>
                        
                        <?php if (($order['payment_status'] ?? 'pending') !== 'paid' && !empty($order['total']) && (float)$order['total'] > 0): ?>
                        <a href="<?= $basePath ?>order-checkout<?= $ext ?>?order=<?php echo (int)$order['id']; ?>"
                           class="px-3 py-1.5 bg-blue-600 text-white hover:bg-blue-700 rounded-lg transition-colors text-xs font-medium flex items-center gap-1">
                            <i class="fa-solid fa-credit-card"></i>
                            Pay
                        </a>
                        <?php endif; ?>

                        <a href="order_detail.php?id=<?php echo (int)$order['id']; ?>"
                           class="px-3 py-1.5 bg-gray-100 text-gray-700 hover:bg-gray-200 rounded-lg transition-colors text-xs font-medium flex items-center gap-1">
                            <i class="fa-solid fa-eye"></i>
                            View Details
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<?php elseif ($tab === 'create'): ?>
<!-- Create Order Form -->
<div x-data="{ selectedEmployees: [] }" class="grid lg:grid-cols-3 gap-6">
    <!-- Main Form -->
    <div class="lg:col-span-2">
        <div class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
            <div class="p-4 border-b border-gray-100">
                <h2 class="text-lg font-semibold text-gray-900">Create Print Order</h2>
                <p class="text-sm text-gray-500 mt-1">Order business card prints for your employees</p>
            </div>
            <form method="post" enctype="multipart/form-data" class="p-6 space-y-6">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="create_order">
                
                <!-- Employee Selection -->
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Select Employees *</label>
                    <div class="max-h-60 overflow-y-auto border border-gray-200 rounded-xl bg-gray-50">
                        <?php if (empty($employees)): ?>
                        <div class="p-4 text-center text-gray-500">
                            <i class="fa-solid fa-users mb-2 text-2xl"></i>
                            <p><?= htmlspecialchars(t('emptystates.no_employees_sub')) ?></p>
                        </div>
                        <?php else: ?>
                        <?php foreach ($employees as $employee): ?>
                        <label class="flex items-center gap-3 p-3 hover:bg-white border-b border-gray-100 last:border-0 cursor-pointer">
                            <input type="checkbox" name="employee_ids[]" value="<?php echo sanitize($employee['id']); ?>" 
                                   x-model="selectedEmployees"
                                   class="w-4 h-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                            <div class="flex-1">
                                <span class="font-medium text-gray-900"><?php echo sanitize($employee['name_en'] ?? ''); ?></span>
                                <?php if (!empty($employee['name_ar'])): ?>
                                <span class="text-gray-500 mr-2"><?php echo sanitize($employee['name_ar']); ?></span>
                                <?php endif; ?>
                                <span class="text-xs text-gray-500"><?php echo sanitize($employee['position_en'] ?? $employee['email'] ?? ''); ?></span>
                            </div>
                        </label>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <p class="text-xs text-gray-500 mt-2 flex items-center gap-2">
                        <span class="px-2 py-0.5 bg-blue-100 text-blue-700 rounded-full" x-text="selectedEmployees.length"></span>
                        <span>employees selected</span>
                    </p>
                </div>
                
                <!-- Template & Quantity -->
                <div class="grid md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Template *</label>
                        <select name="template_id" required class="w-full px-4 py-2.5 rounded-xl border border-gray-200 bg-white text-gray-900 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            <option value="">Select Template</option>
                            <?php foreach ($templates as $template): ?>
                            <option value="<?php echo sanitize($template['id']); ?>"><?php echo sanitize($template['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Quantity per Employee</label>
                        <input type="number" name="quantity" value="<?php echo max(50, (int)($_GET['qty'] ?? 100)); ?>" min="50" step="50" required
                               class="w-full px-4 py-2.5 rounded-xl border border-gray-200 bg-white text-gray-900 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        <p class="text-xs text-gray-500 mt-1">Minimum 50 cards per employee</p>
                    </div>
                </div>
                
                <!-- Notes -->
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Notes (Optional)</label>
                    <textarea name="notes" rows="3" 
                              class="w-full px-4 py-2.5 rounded-xl border border-gray-200 bg-white text-gray-900 focus:ring-2 focus:ring-blue-500 focus:border-blue-500" 
                              placeholder="Special instructions for the print shop..."></textarea>
                </div>
                
                <!-- P.O. File -->
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Purchase Order (P.O.) Document</label>
                    <input type="file" name="po_file" accept=".pdf,.jpg,.jpeg,.png" 
                           class="w-full px-4 py-2.5 rounded-xl border border-gray-200 bg-white text-gray-700 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100">
                    <p class="text-xs text-gray-500 mt-1">Optional. Accepted: PDF, JPG, PNG (Max 10MB)</p>
                </div>
                
                <!-- Submit -->
                <div class="flex items-center gap-4 pt-4 border-t border-gray-100">
                    <button type="submit" class="px-6 py-2.5 rounded-xl bg-blue-600 text-white font-semibold hover:bg-blue-700 transition-colors flex items-center gap-2">
                        <i class="fa-solid fa-paper-plane"></i>
                        Create Order
                    </button>
                    <a href="?tab=orders" class="px-6 py-2.5 rounded-xl bg-gray-100 text-gray-700 font-medium hover:bg-gray-200 transition-colors">
                        Cancel
                    </a>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Sidebar - Order from Print Shop -->
    <div class="lg:col-span-1">
        <div class="bg-gradient-to-br from-blue-50 to-indigo-50 border border-blue-200 rounded-xl p-6">
            <div class="w-12 h-12 bg-blue-100 rounded-xl flex items-center justify-center mb-4">
                <i class="fa-solid fa-store text-blue-600 text-xl"></i>
            </div>
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Order from Print Shop</h3>
            <p class="text-sm text-gray-600 mb-4">
                Order directly from verified print shops with instant pricing and tracking.
            </p>
            <ul class="text-sm text-gray-600 space-y-2 mb-6">
                <li class="flex items-center gap-2">
                    <i class="fa-solid fa-check text-green-500"></i>
                    Real-time pricing
                </li>
                <li class="flex items-center gap-2">
                    <i class="fa-solid fa-check text-green-500"></i>
                    Order tracking
                </li>
                <li class="flex items-center gap-2">
                    <i class="fa-solid fa-check text-green-500"></i>
                    Multiple paper options
                </li>
                <li class="flex items-center gap-2">
                    <i class="fa-solid fa-check text-green-500"></i>
                    Fast delivery
                </li>
            </ul>
            <a href="<?php echo $basePath; ?>order_print<?php echo $ext; ?>" class="block w-full py-2.5 bg-blue-600 hover:bg-blue-700 text-white text-center rounded-xl font-medium transition-colors">
                Order from Print Shop
            </a>
        </div>
        
        <?php if (!empty($printShops)): ?>
        <div class="mt-4 bg-white border border-gray-200 rounded-xl p-4">
            <h4 class="font-medium text-gray-900 mb-3">Available Print Shops</h4>
            <div class="space-y-3">
                <?php foreach (array_slice($printShops, 0, 3) as $shop): ?>
                <a href="<?php echo $basePath; ?>order_print<?php echo $ext; ?>?shop=<?php echo $shop['id']; ?>" 
                   class="flex items-center gap-3 p-2 rounded-lg hover:bg-gray-50 transition-colors">
                    <div class="w-10 h-10 bg-gray-100 rounded-lg flex items-center justify-center">
                        <i class="fa-solid fa-store text-gray-400"></i>
                    </div>
                    <div class="flex-1">
                        <div class="font-medium text-gray-900 text-sm"><?php echo sanitize($shop['name']); ?></div>
                        <div class="text-xs text-gray-500"><?php echo sanitize($shop['city'] ?? 'Location N/A'); ?></div>
                    </div>
                    <?php if (!empty($shop['verified'])): ?>
                    <span class="text-green-500 text-xs"><i class="fa-solid fa-badge-check"></i></span>
                    <?php endif; ?>
                </a>
                <?php endforeach; ?>
            </div>
            <?php if (count($printShops) > 3): ?>
            <a href="?tab=shops" class="block text-center text-sm text-blue-600 hover:text-blue-700 mt-3">
                View all <?php echo count($printShops); ?> shops
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php elseif ($tab === 'shops'): ?>
<!-- Print Shops List -->
<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
    <div class="p-4 border-b border-gray-100 flex items-center justify-between">
        <div>
            <h2 class="text-lg font-semibold text-gray-900">Print Shops</h2>
            <p class="text-sm text-gray-500">Browse verified print shops and place orders</p>
        </div>
        <a href="<?php echo getBasePath(); ?>print-shops.php" target="_blank" class="text-sm text-blue-600 hover:text-blue-700 flex items-center gap-1">
            View Marketplace
            <i class="fa-solid fa-external-link"></i>
        </a>
    </div>
    
    <?php if (empty($printShops)): ?>
    <div class="p-12 text-center">
        <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
            <i class="fa-solid fa-store text-2xl text-gray-400"></i>
        </div>
        <h3 class="text-lg font-medium text-gray-900 mb-2">No print shops available</h3>
        <p class="text-gray-500 mb-4">Print shops will appear here once they're registered and approved.</p>
        <a href="<?php echo getBasePath(); ?>printshop/register.php" class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium transition-colors">
            <i class="fa-solid fa-store"></i>
            Register Print Shop
        </a>
    </div>
    <?php else: ?>
    <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-4 p-4">
        <?php foreach ($printShops as $shop): ?>
        <div class="border border-gray-200 rounded-xl p-4 hover:shadow-md transition-shadow">
            <div class="flex items-start gap-4">
                <div class="w-12 h-12 bg-gradient-to-br from-blue-100 to-indigo-100 rounded-xl flex items-center justify-center flex-shrink-0">
                    <?php if (!empty($shop['logo_url'])): ?>
                    <img src="<?php echo getBasePath() . ltrim(sanitize($shop['logo_url']), '/'); ?>" alt="<?php echo sanitize($shop['name']); ?>" class="w-full h-full object-cover rounded-xl">
                    <?php else: ?>
                    <i class="fa-solid fa-store text-blue-600"></i>
                    <?php endif; ?>
                </div>
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2">
                        <h3 class="font-semibold text-gray-900 truncate"><?php echo sanitize($shop['name']); ?></h3>
                        <?php if (!empty($shop['verified'])): ?>
                        <span class="text-green-500" title="Verified"><i class="fa-solid fa-badge-check"></i></span>
                        <?php endif; ?>
                        <?php if (!empty($shop['featured'])): ?>
                        <span class="text-amber-500" title="Featured"><i class="fa-solid fa-star"></i></span>
                        <?php endif; ?>
                    </div>
                    <p class="text-sm text-gray-500"><?php echo sanitize($shop['city'] ?? ''); ?><?php echo !empty($shop['country']) ? ', ' . sanitize($shop['country']) : ''; ?></p>
                    <?php if (!empty($shop['rating'])): ?>
                    <div class="flex items-center gap-1 mt-1">
                        <i class="fa-solid fa-star text-amber-400 text-xs"></i>
                        <span class="text-sm text-gray-600"><?php echo number_format($shop['rating'], 1); ?></span>
                        <span class="text-xs text-gray-400">(<?php echo $shop['total_orders'] ?? 0; ?> orders)</span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <?php if (!empty($shop['description'])): ?>
            <p class="text-sm text-gray-600 mt-3 line-clamp-2"><?php echo sanitize($shop['description']); ?></p>
            <?php endif; ?>
            
            <div class="flex items-center gap-2 mt-4">
                <a href="<?php echo $basePath; ?>order_print<?php echo $ext; ?>?shop=<?php echo $shop['id']; ?>" 
                   class="flex-1 py-2 bg-blue-600 hover:bg-blue-700 text-white text-center rounded-lg text-sm font-medium transition-colors">
                    Order Now
                </a>
                <?php if (!empty($shop['website'])): ?>
                <a href="<?php echo sanitize($shop['website']); ?>" target="_blank" 
                   class="p-2 text-gray-500 hover:text-gray-700 hover:bg-gray-100 rounded-lg transition-colors" title="Visit Website">
                    <i class="fa-solid fa-globe"></i>
                </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php adminFooter(); ?>
