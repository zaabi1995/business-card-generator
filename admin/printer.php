<?php
/**
 * Printer Management - View and manage all print orders
 * Allows printers to mark PO requirements and approve orders
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/OdooIntegration.php';

// Only super admin and admin can access printer management
Auth::requireRole('admin');

$db = Database::getInstance();
$currentUser = Auth::getCurrentUser();
$message = null;
$messageType = 'success';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $orderId = $_POST['order_id'] ?? '';
    
    if ($action === 'toggle_po_required') {
        $poRequired = isset($_POST['po_required']) && $_POST['po_required'] === '1';
        $db->update('print_orders',
            ['po_required' => $poRequired ? 1 : 0],
            'id = :id',
            ['id' => $orderId]
        );
        $message = $poRequired ? 'PO marked as required for this order' : 'PO requirement removed';
    } elseif ($action === 'approve_po') {
        $db->update('print_orders',
            [
                'po_approved' => 1,
                'po_approved_by' => $currentUser['id'] ?? null,
                'po_approved_at' => date('Y-m-d H:i:s')
            ],
            'id = :id',
            ['id' => $orderId]
        );
        $message = 'PO approved successfully';
    } elseif ($action === 'reject_po') {
        $db->update('print_orders',
            [
                'po_approved' => 0,
                'po_approved_by' => null,
                'po_approved_at' => null
            ],
            'id = :id',
            ['id' => $orderId]
        );
        $message = 'PO approval removed';
    } elseif ($action === 'update_status') {
        $status = $_POST['status'] ?? 'pending';
        $db->update('print_orders',
            ['status' => $status],
            'id = :id',
            ['id' => $orderId]
        );
        $message = 'Order status updated';
    } elseif ($action === 'upload_quotation') {
        $order = $db->fetchOne("SELECT * FROM print_orders WHERE id = :id", ['id' => $orderId]);
        if ($order && isset($_FILES['quotation_file']) && $_FILES['quotation_file']['error'] === UPLOAD_ERR_OK) {
            $allowedTypes = ['application/pdf', 'image/jpeg', 'image/png', 'image/jpg'];
            $fileType = $_FILES['quotation_file']['type'];
            
            if (in_array($fileType, $allowedTypes)) {
                $uploadDir = getCompanyUploadsDir($order['company_id']) . '/quotations';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                
                $fileName = 'quotation_' . $order['order_number'] . '_' . date('Ymd_His') . '_' . uniqid() . '.' . pathinfo($_FILES['quotation_file']['name'], PATHINFO_EXTENSION);
                $filePath = $uploadDir . '/' . $fileName;
                
                if (move_uploaded_file($_FILES['quotation_file']['tmp_name'], $filePath)) {
                    $quotationPath = getWebPath($filePath);
                    $db->update('print_orders',
                        ['quotation_file_path' => $quotationPath],
                        'id = :id',
                        ['id' => $orderId]
                    );
                    
                    // Sync to Odoo if enabled
                    if (OdooIntegration::isEnabled()) {
                        $odooResult = OdooIntegration::syncQuotationToOdoo($orderId, $quotationPath);
                        if ($odooResult['success']) {
                            $message = 'Quotation uploaded and synced to Odoo successfully';
                        } else {
                            $message = 'Quotation uploaded successfully (Odoo sync failed: ' . ($odooResult['error'] ?? 'Unknown') . ')';
                        }
                    } else {
                        $message = 'Quotation uploaded successfully';
                    }
                } else {
                    $message = 'Failed to upload quotation';
                    $messageType = 'error';
                }
            }
        }
    } elseif ($action === 'upload_invoice') {
        $order = $db->fetchOne("SELECT * FROM print_orders WHERE id = :id", ['id' => $orderId]);
        if ($order && isset($_FILES['invoice_file']) && $_FILES['invoice_file']['error'] === UPLOAD_ERR_OK) {
            $allowedTypes = ['application/pdf', 'image/jpeg', 'image/png', 'image/jpg'];
            $fileType = $_FILES['invoice_file']['type'];
            
            if (in_array($fileType, $allowedTypes)) {
                $uploadDir = getCompanyUploadsDir($order['company_id']) . '/invoices';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                
                $fileName = 'invoice_' . $order['order_number'] . '_' . date('Ymd_His') . '_' . uniqid() . '.' . pathinfo($_FILES['invoice_file']['name'], PATHINFO_EXTENSION);
                $filePath = $uploadDir . '/' . $fileName;
                
                if (move_uploaded_file($_FILES['invoice_file']['tmp_name'], $filePath)) {
                    $invoicePath = getWebPath($filePath);
                    $db->update('print_orders',
                        ['invoice_file_path' => $invoicePath],
                        'id = :id',
                        ['id' => $orderId]
                    );
                    
                    // Sync to Odoo if enabled
                    if (OdooIntegration::isEnabled()) {
                        $odooResult = OdooIntegration::syncInvoiceToOdoo($orderId, $invoicePath);
                        if ($odooResult['success']) {
                            $message = 'Invoice uploaded and synced to Odoo successfully';
                        } else {
                            $message = 'Invoice uploaded successfully (Odoo sync failed: ' . ($odooResult['error'] ?? 'Unknown') . ')';
                        }
                    } else {
                        $message = 'Invoice uploaded successfully';
                    }
                } else {
                    $message = 'Failed to upload invoice';
                    $messageType = 'error';
                }
            }
        }
    } elseif ($action === 'upload_delivery_note') {
        $order = $db->fetchOne("SELECT * FROM print_orders WHERE id = :id", ['id' => $orderId]);
        if ($order && isset($_FILES['delivery_note_file']) && $_FILES['delivery_note_file']['error'] === UPLOAD_ERR_OK) {
            $allowedTypes = ['application/pdf', 'image/jpeg', 'image/png', 'image/jpg'];
            $fileType = $_FILES['delivery_note_file']['type'];
            
            if (in_array($fileType, $allowedTypes)) {
                $uploadDir = getCompanyUploadsDir($order['company_id']) . '/delivery_notes';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                
                $fileName = 'delivery_' . $order['order_number'] . '_' . date('Ymd_His') . '_' . uniqid() . '.' . pathinfo($_FILES['delivery_note_file']['name'], PATHINFO_EXTENSION);
                $filePath = $uploadDir . '/' . $fileName;
                
                if (move_uploaded_file($_FILES['delivery_note_file']['tmp_name'], $filePath)) {
                    $deliveryNotePath = getWebPath($filePath);
                    $db->update('print_orders',
                        ['delivery_note_file_path' => $deliveryNotePath],
                        'id = :id',
                        ['id' => $orderId]
                    );
                    
                    // Sync to Odoo if enabled
                    if (OdooIntegration::isEnabled()) {
                        $odooResult = OdooIntegration::syncDeliveryNoteToOdoo($orderId, $deliveryNotePath);
                        if ($odooResult['success']) {
                            $message = 'Delivery note uploaded and synced to Odoo successfully';
                        } else {
                            $message = 'Delivery note uploaded successfully (Odoo sync failed: ' . ($odooResult['error'] ?? 'Unknown') . ')';
                        }
                    } else {
                        $message = 'Delivery note uploaded successfully';
                    }
                } else {
                    $message = 'Failed to upload delivery note';
                    $messageType = 'error';
                }
            }
        }
    }
}

// Get filter
$filter = $_GET['filter'] ?? 'all'; // all, po_required, po_pending, po_approved, quotation_requested

// Build query
$query = "SELECT po.*, c.name as company_name, c.slug as company_slug, 
          u.name as approved_by_name
          FROM print_orders po
          LEFT JOIN companies c ON po.company_id = c.id
          LEFT JOIN users u ON po.po_approved_by = u.id";

$params = [];

if ($filter === 'po_required') {
    $query .= " WHERE po.po_required = 1";
} elseif ($filter === 'po_pending') {
    $query .= " WHERE po.po_required = 1 AND (po.po_file_path IS NULL OR po.po_file_path = '' OR po.po_approved = 0)";
} elseif ($filter === 'po_approved') {
    $query .= " WHERE po.po_approved = 1";
} elseif ($filter === 'quotation_requested') {
    $query .= " WHERE po.quotation_requested = 1";
}

$query .= " ORDER BY po.created_at DESC LIMIT 50";

$orders = $db->fetchAll($query, $params);

// Get statistics
$totalOrders = $db->fetchOne("SELECT COUNT(*) as count FROM print_orders")['count'] ?? 0;
$poRequiredOrders = $db->fetchOne("SELECT COUNT(*) as count FROM print_orders WHERE po_required = 1")['count'] ?? 0;
$poPendingResult = $db->fetchOne("SELECT COUNT(*) as count FROM print_orders WHERE po_required = 1 AND (po_file_path IS NULL OR po_file_path = '' OR po_approved = 0)");
$poPendingOrders = $poPendingResult['count'] ?? 0;
$poApprovedOrders = $db->fetchOne("SELECT COUNT(*) as count FROM print_orders WHERE po_approved = 1")['count'] ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Printer Management | <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="<?php echo assetUrl('css/tailwind.css'); ?>">
    <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
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
                        <h1 class="text-2xl font-bold text-white">Printer Management</h1>
                        <p class="text-gray-400 text-sm">Manage all print orders and PO requirements</p>
                    </div>
                    <a href="<?php echo Auth::getCurrentRole() === 'super_admin' ? 'super/' : 'index.php'; ?>" class="px-4 py-2 rounded-lg bg-white/5 hover:bg-white/10 transition-colors">
                        ← Back
                    </a>
                </div>
            </div>
        </header>
        
        <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
            <?php if ($message): ?>
            <div class="mb-6 p-4 rounded-xl <?php echo $messageType === 'error' ? 'bg-red-500/10 border border-red-500/30 text-red-400' : 'bg-green-500/10 border border-green-500/30 text-green-400'; ?>">
                <?php echo sanitize($message); ?>
            </div>
            <?php endif; ?>
            
            <!-- Statistics -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                <div class="glass-card rounded-xl p-4">
                    <div class="text-2xl font-bold text-white"><?php echo $totalOrders; ?></div>
                    <div class="text-gray-400 text-sm">Total Orders</div>
                </div>
                <div class="glass-card rounded-xl p-4">
                    <div class="text-2xl font-bold text-amber-400"><?php echo $poRequiredOrders; ?></div>
                    <div class="text-gray-400 text-sm">PO Required</div>
                </div>
                <div class="glass-card rounded-xl p-4">
                    <div class="text-2xl font-bold text-red-400"><?php echo $poPendingOrders; ?></div>
                    <div class="text-gray-400 text-sm">PO Pending</div>
                </div>
                <div class="glass-card rounded-xl p-4">
                    <div class="text-2xl font-bold text-green-400"><?php echo $poApprovedOrders; ?></div>
                    <div class="text-gray-400 text-sm">PO Approved</div>
                </div>
            </div>
            
            <!-- Filters -->
            <div class="glass-card rounded-xl p-4 mb-6">
                <div class="flex items-center space-x-4">
                    <span class="text-gray-300 font-medium">Filter:</span>
                    <a href="?filter=all" class="px-4 py-2 rounded-lg <?php echo $filter === 'all' ? 'bg-amber-500/20 text-amber-400' : 'bg-white/5 hover:bg-white/10'; ?> transition-colors">
                        All Orders
                    </a>
                    <a href="?filter=po_required" class="px-4 py-2 rounded-lg <?php echo $filter === 'po_required' ? 'bg-amber-500/20 text-amber-400' : 'bg-white/5 hover:bg-white/10'; ?> transition-colors">
                        PO Required
                    </a>
                    <a href="?filter=po_pending" class="px-4 py-2 rounded-lg <?php echo $filter === 'po_pending' ? 'bg-amber-500/20 text-amber-400' : 'bg-white/5 hover:bg-white/10'; ?> transition-colors">
                        PO Pending Approval
                    </a>
                    <a href="?filter=po_approved" class="px-4 py-2 rounded-lg <?php echo $filter === 'po_approved' ? 'bg-amber-500/20 text-amber-400' : 'bg-white/5 hover:bg-white/10'; ?> transition-colors">
                        PO Approved
                    </a>
                    <a href="?filter=quotation_requested" class="px-4 py-2 rounded-lg <?php echo $filter === 'quotation_requested' ? 'bg-amber-500/20 text-amber-400' : 'bg-white/5 hover:bg-white/10'; ?> transition-colors">
                        Quotation Requested
                    </a>
                </div>
            </div>
            
            <!-- Orders List -->
            <div class="glass-card rounded-xl p-6">
                <h2 class="text-xl font-bold mb-4">Print Orders</h2>
                <div class="space-y-4">
                    <?php if (empty($orders)): ?>
                    <p class="text-gray-400 text-center py-8">No orders found.</p>
                    <?php else: ?>
                    <?php foreach ($orders as $order): ?>
                    <div class="p-4 rounded-lg bg-white/5 border <?php echo $order['po_required'] && !$order['po_approved'] ? 'border-red-500/30' : ($order['po_approved'] ? 'border-green-500/30' : 'border-white/10'); ?>">
                        <div class="flex items-start justify-between">
                            <div class="flex-1">
                                <div class="flex items-center space-x-3 mb-2">
                                    <div class="font-semibold">Order #<?php echo sanitize($order['order_number']); ?></div>
                                    <span class="px-2 py-1 rounded-full text-xs font-semibold 
                                        <?php echo $order['status'] === 'completed' ? 'bg-green-500/20 text-green-400' : 
                                                   ($order['status'] === 'processing' ? 'bg-blue-500/20 text-blue-400' : 'bg-amber-500/20 text-amber-400'); ?>">
                                        <?php echo sanitize($order['status']); ?>
                                    </span>
                                    <?php if ($order['po_required']): ?>
                                    <span class="px-2 py-1 rounded-full text-xs font-semibold bg-amber-500/20 text-amber-400">
                                        PO Required
                                    </span>
                                    <?php endif; ?>
                                    <?php if ($order['po_approved']): ?>
                                    <span class="px-2 py-1 rounded-full text-xs font-semibold bg-green-500/20 text-green-400">
                                        ✓ PO Approved
                                    </span>
                                    <?php elseif ($order['po_required'] && empty($order['po_file_path'])): ?>
                                    <span class="px-2 py-1 rounded-full text-xs font-semibold bg-red-500/20 text-red-400">
                                        ⚠ PO Missing
                                    </span>
                                    <?php elseif ($order['po_required'] && !empty($order['po_file_path']) && !$order['po_approved']): ?>
                                    <span class="px-2 py-1 rounded-full text-xs font-semibold bg-yellow-500/20 text-yellow-400">
                                        ⏳ PO Pending Approval
                                    </span>
                                    <?php endif; ?>
                                </div>
                                <div class="text-sm text-gray-400 space-y-1">
                                    <div>Company: <a href="<?php echo getBaseUrl() . $order['company_slug']; ?>/" target="_blank" class="text-amber-400 hover:text-amber-300"><?php echo sanitize($order['company_name']); ?></a></div>
                                    <div>Employees: <?php echo count(json_decode($order['employee_ids'], true)); ?></div>
                                    <div>Quantity: <?php echo $order['quantity']; ?> per employee</div>
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
                                    <?php if (isset($order['quotation_requested']) && $order['quotation_requested']): ?>
                                    <div class="text-blue-400">
                                        📋 Quotation Requested
                                        <?php if (!empty($order['quotation_file_path'])): ?>
                                        <a href="<?php echo imageUrl($order['quotation_file_path']); ?>" target="_blank" class="text-green-400 hover:text-green-300 underline ml-2">
                                            ✓ View Quotation
                                        </a>
                                        <?php else: ?>
                                        <span class="text-yellow-400 ml-2">⏳ Pending</span>
                                        <?php endif; ?>
                                    </div>
                                    <?php endif; ?>
                                    <?php if (!empty($order['quotation_file_path'])): ?>
                                    <div>
                                        <a href="<?php echo imageUrl($order['quotation_file_path']); ?>" target="_blank" class="text-blue-400 hover:text-blue-300 underline">
                                            📋 View Quotation
                                        </a>
                                    </div>
                                    <?php endif; ?>
                                    <?php if (!empty($order['invoice_file_path'])): ?>
                                    <div>
                                        <a href="<?php echo imageUrl($order['invoice_file_path']); ?>" target="_blank" class="text-green-400 hover:text-green-300 underline">
                                            🧾 View Invoice
                                        </a>
                                    </div>
                                    <?php endif; ?>
                                    <?php if (!empty($order['delivery_note_file_path'])): ?>
                                    <div>
                                        <a href="<?php echo imageUrl($order['delivery_note_file_path']); ?>" target="_blank" class="text-green-400 hover:text-green-300 underline">
                                            📦 View Delivery Note
                                        </a>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($order['po_approved'] && $order['approved_by_name']): ?>
                                    <div class="text-green-400">Approved by: <?php echo sanitize($order['approved_by_name']); ?> on <?php echo date('M d, Y H:i', strtotime($order['po_approved_at'])); ?></div>
                                    <?php endif; ?>
                                    <div>Created: <?php echo date('M d, Y H:i', strtotime($order['created_at'])); ?></div>
                                </div>
                            </div>
                            <div class="ml-4 space-y-2">
                                <!-- PO Required Toggle -->
                                <form method="post" class="inline">
                                    <input type="hidden" name="action" value="toggle_po_required">
                                    <input type="hidden" name="order_id" value="<?php echo sanitize($order['id']); ?>">
                                    <label class="flex items-center space-x-2 cursor-pointer">
                                        <input type="checkbox" name="po_required" value="1" <?php echo $order['po_required'] ? 'checked' : ''; ?>
                                               onchange="this.form.submit()"
                                               class="w-4 h-4 rounded border-white/20 bg-white/5 text-amber-500">
                                        <span class="text-xs text-gray-300">PO Required</span>
                                    </label>
                                </form>
                                
                                <!-- PO Approval -->
                                <?php if ($order['po_required'] && !empty($order['po_file_path'])): ?>
                                    <?php if (!$order['po_approved']): ?>
                                    <form method="post" class="inline">
                                        <input type="hidden" name="action" value="approve_po">
                                        <input type="hidden" name="order_id" value="<?php echo sanitize($order['id']); ?>">
                                        <button type="submit" class="w-full px-3 py-1 rounded-lg bg-green-500/20 text-green-400 hover:bg-green-500/30 transition-colors text-xs">
                                            ✓ Approve PO
                                        </button>
                                    </form>
                                    <?php else: ?>
                                    <form method="post" class="inline" onsubmit="return confirm('Remove PO approval?');">
                                        <input type="hidden" name="action" value="reject_po">
                                        <input type="hidden" name="order_id" value="<?php echo sanitize($order['id']); ?>">
                                        <button type="submit" class="w-full px-3 py-1 rounded-lg bg-red-500/20 text-red-400 hover:bg-red-500/30 transition-colors text-xs">
                                            ✗ Reject PO
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                <?php endif; ?>
                                
                                <!-- Status Update -->
                                <form method="post" class="inline">
                                    <input type="hidden" name="action" value="update_status">
                                    <input type="hidden" name="order_id" value="<?php echo sanitize($order['id']); ?>">
                                    <select name="status" onchange="this.form.submit()" class="w-full px-3 py-1 rounded-lg bg-white/5 border border-white/10 text-white text-xs">
                                        <option value="pending" <?php echo $order['status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                        <option value="processing" <?php echo $order['status'] === 'processing' ? 'selected' : ''; ?>>Processing</option>
                                        <option value="completed" <?php echo $order['status'] === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                    </select>
                                </form>
                            </div>
                        </div>
                        
                        <!-- Document Upload Section -->
                        <div class="mt-4 pt-4 border-t border-white/10">
                            <div class="grid md:grid-cols-3 gap-4">
                                <!-- Upload Quotation -->
                                <div>
                                    <form method="post" enctype="multipart/form-data" class="space-y-2">
                                        <input type="hidden" name="action" value="upload_quotation">
                                        <input type="hidden" name="order_id" value="<?php echo sanitize($order['id']); ?>">
                                        <label class="block text-xs text-gray-400 mb-1">Upload Quotation</label>
                                        <input type="file" name="quotation_file" accept=".pdf,.jpg,.jpeg,.png" class="w-full px-2 py-1 rounded-lg bg-white/5 border border-white/10 text-white text-xs file:mr-2 file:py-1 file:px-2 file:rounded file:border-0 file:text-xs file:bg-blue-500/20 file:text-blue-400">
                                        <button type="submit" class="w-full px-2 py-1 rounded-lg bg-blue-500/20 text-blue-400 hover:bg-blue-500/30 transition-colors text-xs">
                                            Upload
                                        </button>
                                    </form>
                                </div>
                                
                                <!-- Upload Invoice -->
                                <div>
                                    <form method="post" enctype="multipart/form-data" class="space-y-2">
                                        <input type="hidden" name="action" value="upload_invoice">
                                        <input type="hidden" name="order_id" value="<?php echo sanitize($order['id']); ?>">
                                        <label class="block text-xs text-gray-400 mb-1">Upload Invoice</label>
                                        <input type="file" name="invoice_file" accept=".pdf,.jpg,.jpeg,.png" class="w-full px-2 py-1 rounded-lg bg-white/5 border border-white/10 text-white text-xs file:mr-2 file:py-1 file:px-2 file:rounded file:border-0 file:text-xs file:bg-green-500/20 file:text-green-400">
                                        <button type="submit" class="w-full px-2 py-1 rounded-lg bg-green-500/20 text-green-400 hover:bg-green-500/30 transition-colors text-xs">
                                            Upload
                                        </button>
                                    </form>
                                </div>
                                
                                <!-- Upload Delivery Note -->
                                <div>
                                    <form method="post" enctype="multipart/form-data" class="space-y-2">
                                        <input type="hidden" name="action" value="upload_delivery_note">
                                        <input type="hidden" name="order_id" value="<?php echo sanitize($order['id']); ?>">
                                        <label class="block text-xs text-gray-400 mb-1">Upload Delivery Note</label>
                                        <input type="file" name="delivery_note_file" accept=".pdf,.jpg,.jpeg,.png" class="w-full px-2 py-1 rounded-lg bg-white/5 border border-white/10 text-white text-xs file:mr-2 file:py-1 file:px-2 file:rounded file:border-0 file:text-xs file:bg-purple-500/20 file:text-purple-400">
                                        <button type="submit" class="w-full px-2 py-1 rounded-lg bg-purple-500/20 text-purple-400 hover:bg-purple-500/30 transition-colors text-xs">
                                            Upload
                                        </button>
                                    </form>
                                </div>
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
