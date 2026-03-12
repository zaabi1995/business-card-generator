<?php
/**
 * Order Detail - Company View
 * View order details, upload PO, download documents
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/Currency.php';
require_once INCLUDES_DIR . '/Mailer.php';
require_once INCLUDES_DIR . '/admin-layout.php';

Auth::requireRole(['company', 'super_admin']);
$user = Auth::getCurrentUser();
$companyId = $_SESSION['company_id'] ?? null;

// Get order ID
$orderId = (int)($_GET['id'] ?? 0);
if ($orderId <= 0) {
    header('Location: print.php');
    exit;
}

$db = Database::getInstance();
$pdo = $db->getConnection();

// Fetch order details
try {
    $stmt = $pdo->prepare("
        SELECT po.*, 
               ps.name as print_shop_name, ps.email as print_shop_email, 
               ps.phone as print_shop_phone, ps.currency as shop_currency,
               COALESCE(e.name_en, e.name_ar, '') as employee_name,
               e.email as employee_email, 
               COALESCE(e.position_en, e.position_ar, '') as employee_position
        FROM print_orders po
        LEFT JOIN print_shops ps ON po.print_shop_id = ps.id
        LEFT JOIN employees e ON po.employee_id = e.id
        WHERE po.id = ?
    ");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $order = null;
}

// Verify this order belongs to this company (unless super admin)
if (!$order || ($user['role'] !== 'super_admin' && $order['company_id'] !== $companyId)) {
    header('Location: print.php');
    exit;
}

$message = null;
$messageType = 'success';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = 'Invalid request. Please try again.';
        $messageType = 'error';
    }
    $action = $_POST['action'] ?? '';
    
    if ($messageType === 'error') {
        // CSRF failed, skip processing
    } elseif ($action === 'upload_po') {
        if (isset($_FILES['po_file']) && $_FILES['po_file']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = 'uploads/orders/' . $orderId . '/';
            $fullUploadDir = BASE_DIR . '/' . $uploadDir;
            if (!is_dir($fullUploadDir)) mkdir($fullUploadDir, 0755, true);
            
            $filename = 'po_' . date('Ymd_His') . '_' . uniqid() . '.' . pathinfo($_FILES['po_file']['name'], PATHINFO_EXTENSION);
            $filePath = $uploadDir . $filename;
            
            if (move_uploaded_file($_FILES['po_file']['tmp_name'], BASE_DIR . '/' . $filePath)) {
                $poNumber = trim($_POST['po_number'] ?? '');
                
                $pdo->prepare("UPDATE print_orders SET 
                    po_file_path = ?, po_number = ?, po_received_at = NOW(), po_required = 0
                    WHERE id = ?")->execute([$filePath, $poNumber, $orderId]);
                
                // Send notification to print shop
                if (!empty($order['print_shop_email'])) {
                    $siteName = defined('SITE_NAME') ? SITE_NAME : 'Cardify';
                    Mailer::sendTemplate($order['print_shop_email'], 'po_received', [
                        'site_name' => $siteName,
                        'contact_name' => $order['print_shop_name'],
                        'po_number' => $poNumber,
                        'order_id' => $orderId,
                        'quantity' => $order['quantity'],
                        'amount' => Currency::format($order['total'], $order['currency'] ?? 'OMR'),
                        'print_shop_name' => $order['print_shop_name']
                    ], [['path' => BASE_DIR . '/' . $filePath, 'name' => basename($filePath)]]);
                }
                
                $message = "Purchase Order uploaded successfully!";
                
                // Refresh order data
                $stmt->execute([$orderId]);
                $order = $stmt->fetch(PDO::FETCH_ASSOC);
            } else {
                $message = "Failed to upload PO file";
                $messageType = 'error';
            }
        }
        
    } elseif ($action === 'accept_quotation') {
        $pdo->prepare("UPDATE print_orders SET quotation_accepted = 1, quotation_accepted_at = NOW() WHERE id = ?")
            ->execute([$orderId]);
        $message = "Quotation accepted! The print shop will be notified.";
        
        // Notify print shop
        if (!empty($order['print_shop_email'])) {
            $siteName = defined('SITE_NAME') ? SITE_NAME : 'Cardify';
            $body = "<h2>Quotation Accepted</h2>
                <p>Good news! The quotation for Order #{$orderId} has been accepted.</p>
                <p><strong>Quotation:</strong> {$order['quotation_number']}</p>
                <p><strong>Amount:</strong> " . Currency::format($order['quotation_amount'], $order['currency'] ?? 'OMR') . "</p>
                <p>You may now proceed with production.</p>";
            Mailer::send($order['print_shop_email'], "Quotation Accepted - Order #{$orderId}", $body);
        }
        
        // Refresh order data
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
    } elseif ($action === 'request_quotation') {
        $pdo->prepare("UPDATE print_orders SET quotation_requested = 1 WHERE id = ?")
            ->execute([$orderId]);
        $message = "Quotation requested! The print shop will send you a quotation soon.";
        
        // Notify print shop
        if (!empty($order['print_shop_email'])) {
            $siteName = defined('SITE_NAME') ? SITE_NAME : 'Cardify';
            $body = "<h2>Quotation Requested</h2>
                <p>A company has requested a quotation for Order #{$orderId}.</p>
                <p><strong>Quantity:</strong> {$order['quantity']} cards</p>
                <p><strong>Paper Type:</strong> " . ucfirst($order['paper_type'] ?? 'standard') . "</p>
                <p>Please log in to your dashboard to create and send the quotation.</p>";
            Mailer::send($order['print_shop_email'], "Quotation Requested - Order #{$orderId}", $body);
        }
        
        // Refresh order data
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

$currency = $order['currency'] ?? $order['shop_currency'] ?? 'OMR';

$statusColors = [
    'pending' => 'bg-gray-100 text-gray-700 border-gray-300',
    'confirmed' => 'bg-blue-100 text-blue-700 border-blue-300',
    'processing' => 'bg-purple-100 text-purple-700 border-purple-300',
    'printing' => 'bg-amber-100 text-amber-700 border-amber-300',
    'shipped' => 'bg-cyan-100 text-cyan-700 border-cyan-300',
    'delivered' => 'bg-green-100 text-green-700 border-green-300',
    'cancelled' => 'bg-red-100 text-red-700 border-red-300'
];

adminHeader('Order #' . $orderId, 'print');
?>

<!-- Back Link -->
<div class="mb-6">
    <a href="print.php?tab=orders" class="text-blue-600 hover:text-blue-700 flex items-center gap-2">
        <i class="fa-solid fa-arrow-left"></i>
        Back to Orders
    </a>
</div>

<!-- Alert Message -->
<?php if ($message): ?>
<div class="mb-6 p-4 rounded-xl flex items-center gap-3 <?php echo $messageType === 'success' ? 'bg-green-50 border border-green-200 text-green-700' : 'bg-red-50 border border-red-200 text-red-700'; ?>">
    <i class="<?php echo $messageType === 'success' ? 'fa-solid fa-circle-check' : 'fa-solid fa-circle-exclamation'; ?>"></i>
    <?php echo sanitize($message); ?>
</div>
<?php endif; ?>

<div class="grid lg:grid-cols-3 gap-6">
    <!-- Main Info -->
    <div class="lg:col-span-2 space-y-6">
        
        <!-- Order Header -->
        <div class="bg-white rounded-xl border border-gray-200 p-6">
            <div class="flex items-start justify-between mb-4">
                <div>
                    <h2 class="text-2xl font-bold text-gray-900">Order #<?php echo $order['order_number'] ?? $orderId; ?></h2>
                    <p class="text-gray-500 mt-1">Placed on <?php echo date('F j, Y', strtotime($order['created_at'])); ?></p>
                </div>
                <span class="px-4 py-2 rounded-full text-sm font-semibold border <?php echo $statusColors[$order['status']] ?? 'bg-gray-100 text-gray-700'; ?>">
                    <?php echo ucfirst($order['status']); ?>
                </span>
            </div>
            
            <div class="grid md:grid-cols-3 gap-4 pt-4 border-t border-gray-100">
                <div>
                    <p class="text-sm text-gray-500">Print Shop</p>
                    <p class="font-semibold text-gray-900"><?php echo sanitize($order['print_shop_name'] ?? 'N/A'); ?></p>
                </div>
                <div>
                    <p class="text-sm text-gray-500">Quantity</p>
                    <p class="font-semibold text-gray-900"><?php echo $order['quantity']; ?> cards</p>
                </div>
                <div>
                    <p class="text-sm text-gray-500">Total</p>
                    <p class="font-semibold text-gray-900"><?php echo Currency::formatHtml($order['total'], $currency); ?></p>
                </div>
            </div>
        </div>
        
        <!-- Card Details -->
        <div class="bg-white rounded-xl border border-gray-200 p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Card Details</h3>
            <div class="grid md:grid-cols-2 gap-6">
                <?php if (!empty($order['card_front_url'])): ?>
                <div>
                    <p class="text-sm font-medium text-gray-700 mb-2">Front</p>
                    <img src="<?php echo getBasePath() . $order['card_front_url']; ?>" 
                         alt="Card Front" class="rounded-lg border border-gray-200 shadow-sm w-full">
                </div>
                <?php endif; ?>
                <?php if (!empty($order['card_back_url'])): ?>
                <div>
                    <p class="text-sm font-medium text-gray-700 mb-2">Back</p>
                    <img src="<?php echo getBasePath() . $order['card_back_url']; ?>" 
                         alt="Card Back" class="rounded-lg border border-gray-200 shadow-sm w-full">
                </div>
                <?php endif; ?>
            </div>
            
            <?php if (!empty($order['employee_name'])): ?>
            <div class="mt-4 pt-4 border-t border-gray-100">
                <p class="text-sm text-gray-500">Employee</p>
                <p class="font-medium text-gray-900"><?php echo sanitize($order['employee_name']); ?></p>
                <?php if (!empty($order['employee_position'])): ?>
                <p class="text-sm text-gray-500"><?php echo sanitize($order['employee_position']); ?></p>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Documents Section -->
        <div class="bg-white rounded-xl border border-gray-200 p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Documents</h3>
            
            <div class="space-y-4">
                <!-- Quotation -->
                <div class="p-4 rounded-lg border border-gray-200 <?php echo !empty($order['quotation_file_path']) ? 'bg-green-50 border-green-200' : 'bg-gray-50'; ?>">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-lg <?php echo !empty($order['quotation_file_path']) ? 'bg-green-100' : 'bg-gray-200'; ?> flex items-center justify-center">
                                <i class="fa-solid fa-file-invoice <?php echo !empty($order['quotation_file_path']) ? 'text-green-600' : 'text-gray-400'; ?>"></i>
                            </div>
                            <div>
                                <p class="font-medium text-gray-900">Quotation</p>
                                <?php if (!empty($order['quotation_number'])): ?>
                                <p class="text-sm text-gray-500">#<?php echo sanitize($order['quotation_number']); ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <?php if (!empty($order['quotation_file_path'])): ?>
                                <a href="<?php echo getBasePath() . $order['quotation_file_path']; ?>" download
                                   class="px-3 py-1.5 bg-green-600 hover:bg-green-700 text-white rounded-lg text-sm font-medium flex items-center gap-1">
                                    <i class="fa-solid fa-download"></i> Download
                                </a>
                                <?php if (empty($order['quotation_accepted'])): ?>
                                <form method="post" class="inline">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="action" value="accept_quotation">
                                    <button type="submit" class="px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium">
                                        Accept
                                    </button>
                                </form>
                                <?php else: ?>
                                <span class="px-3 py-1.5 bg-green-100 text-green-700 rounded-lg text-sm font-medium">
                                    <i class="fa-solid fa-check"></i> Accepted
                                </span>
                                <?php endif; ?>
                            <?php elseif (empty($order['quotation_requested'])): ?>
                                <form method="post">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="action" value="request_quotation">
                                    <button type="submit" class="px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium">
                                        Request Quotation
                                    </button>
                                </form>
                            <?php else: ?>
                                <span class="px-3 py-1.5 bg-amber-100 text-amber-700 rounded-lg text-sm font-medium">
                                    <i class="fa-solid fa-clock"></i> Requested
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if (!empty($order['quotation_amount'])): ?>
                    <div class="mt-3 pt-3 border-t border-gray-200 flex items-center gap-4 text-sm">
                        <span class="text-gray-500">Amount: <strong class="text-gray-900"><?php echo Currency::format($order['quotation_amount'], $currency); ?></strong></span>
                        <?php if (!empty($order['quotation_valid_until'])): ?>
                        <span class="text-gray-500">Valid until: <strong class="text-gray-900"><?php echo date('M j, Y', strtotime($order['quotation_valid_until'])); ?></strong></span>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Purchase Order -->
                <div class="p-4 rounded-lg border border-gray-200 <?php echo !empty($order['po_file_path']) ? 'bg-green-50 border-green-200' : ($order['po_required'] ? 'bg-amber-50 border-amber-200' : 'bg-gray-50'); ?>">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-lg <?php echo !empty($order['po_file_path']) ? 'bg-green-100' : ($order['po_required'] ? 'bg-amber-100' : 'bg-gray-200'); ?> flex items-center justify-center">
                                <i class="fa-solid fa-file-contract <?php echo !empty($order['po_file_path']) ? 'text-green-600' : ($order['po_required'] ? 'text-amber-600' : 'text-gray-400'); ?>"></i>
                            </div>
                            <div>
                                <p class="font-medium text-gray-900">Purchase Order</p>
                                <?php if (!empty($order['po_number'])): ?>
                                <p class="text-sm text-gray-500">#<?php echo sanitize($order['po_number']); ?></p>
                                <?php elseif ($order['po_required']): ?>
                                <p class="text-sm text-amber-600">Required by print shop</p>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <?php if (!empty($order['po_file_path'])): ?>
                                <a href="<?php echo getBasePath() . $order['po_file_path']; ?>" download
                                   class="px-3 py-1.5 bg-green-600 hover:bg-green-700 text-white rounded-lg text-sm font-medium flex items-center gap-1">
                                    <i class="fa-solid fa-download"></i> Download
                                </a>
                                <?php if ($order['po_approved']): ?>
                                <span class="px-3 py-1.5 bg-green-100 text-green-700 rounded-lg text-sm font-medium">
                                    <i class="fa-solid fa-check"></i> Approved
                                </span>
                                <?php endif; ?>
                            <?php else: ?>
                                <button onclick="document.getElementById('poUploadModal').classList.remove('hidden')"
                                        class="px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium">
                                    <i class="fa-solid fa-upload"></i> Upload PO
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Invoice -->
                <div class="p-4 rounded-lg border border-gray-200 <?php echo !empty($order['invoice_file_path']) ? ($order['invoice_paid'] ? 'bg-green-50 border-green-200' : 'bg-amber-50 border-amber-200') : 'bg-gray-50'; ?>">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-lg <?php echo !empty($order['invoice_file_path']) ? ($order['invoice_paid'] ? 'bg-green-100' : 'bg-amber-100') : 'bg-gray-200'; ?> flex items-center justify-center">
                                <i class="fa-solid fa-file-invoice-dollar <?php echo !empty($order['invoice_file_path']) ? ($order['invoice_paid'] ? 'text-green-600' : 'text-amber-600') : 'text-gray-400'; ?>"></i>
                            </div>
                            <div>
                                <p class="font-medium text-gray-900">Invoice</p>
                                <?php if (!empty($order['invoice_number'])): ?>
                                <p class="text-sm text-gray-500">#<?php echo sanitize($order['invoice_number']); ?></p>
                                <?php else: ?>
                                <p class="text-sm text-gray-400">Pending</p>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if (!empty($order['invoice_file_path'])): ?>
                        <div class="flex items-center gap-2">
                            <a href="<?php echo getBasePath() . $order['invoice_file_path']; ?>" download
                               class="px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium flex items-center gap-1">
                                <i class="fa-solid fa-download"></i> Download
                            </a>
                            <?php if ($order['invoice_paid']): ?>
                            <span class="px-3 py-1.5 bg-green-100 text-green-700 rounded-lg text-sm font-medium">
                                <i class="fa-solid fa-check"></i> Paid
                            </span>
                            <?php else: ?>
                            <span class="px-3 py-1.5 bg-amber-100 text-amber-700 rounded-lg text-sm font-medium">
                                Due <?php echo date('M j', strtotime($order['invoice_due_date'])); ?>
                            </span>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($order['invoice_amount'])): ?>
                    <div class="mt-3 pt-3 border-t border-gray-200 flex items-center gap-4 text-sm">
                        <span class="text-gray-500">Amount: <strong class="text-gray-900"><?php echo Currency::format($order['invoice_amount'], $currency); ?></strong></span>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Delivery Note -->
                <?php if (!empty($order['delivery_note_file_path'])): ?>
                <div class="p-4 rounded-lg border border-green-200 bg-green-50">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-lg bg-green-100 flex items-center justify-center">
                                <i class="fa-solid fa-truck text-green-600"></i>
                            </div>
                            <div>
                                <p class="font-medium text-gray-900">Delivery Note</p>
                                <p class="text-sm text-gray-500">#<?php echo sanitize($order['delivery_note_number']); ?></p>
                            </div>
                        </div>
                        <a href="<?php echo getBasePath() . $order['delivery_note_file_path']; ?>" download
                           class="px-3 py-1.5 bg-green-600 hover:bg-green-700 text-white rounded-lg text-sm font-medium flex items-center gap-1">
                            <i class="fa-solid fa-download"></i> Download
                        </a>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Sidebar -->
    <div class="space-y-6">
        <!-- Order Summary -->
        <div class="bg-white rounded-xl border border-gray-200 p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Order Summary</h3>
            <div class="space-y-3 text-sm">
                <div class="flex justify-between">
                    <span class="text-gray-500">Paper Type</span>
                    <span class="font-medium"><?php echo ucfirst($order['paper_type'] ?? 'standard'); ?></span>
                </div>
                <div class="flex justify-between">
                    <span class="text-gray-500">Finish</span>
                    <span class="font-medium"><?php echo ucfirst(str_replace('_', ' ', $order['finish'] ?? 'standard')); ?></span>
                </div>
                <div class="flex justify-between">
                    <span class="text-gray-500">Quantity</span>
                    <span class="font-medium"><?php echo $order['quantity']; ?> cards</span>
                </div>
                <?php if ($order['express_delivery']): ?>
                <div class="flex justify-between">
                    <span class="text-gray-500">Delivery</span>
                    <span class="font-medium text-amber-600">Express</span>
                </div>
                <?php endif; ?>
                
                <hr class="my-3">
                
                <div class="flex justify-between">
                    <span class="text-gray-500">Subtotal</span>
                    <span class="font-medium"><?php echo Currency::format($order['subtotal'] ?? 0, $currency); ?></span>
                </div>
                <?php if (($order['setup_fee'] ?? 0) > 0): ?>
                <div class="flex justify-between">
                    <span class="text-gray-500">Setup Fee</span>
                    <span class="font-medium"><?php echo Currency::format($order['setup_fee'], $currency); ?></span>
                </div>
                <?php endif; ?>
                <div class="flex justify-between">
                    <span class="text-gray-500">Shipping</span>
                    <span class="font-medium"><?php echo Currency::format($order['shipping_fee'] ?? 0, $currency); ?></span>
                </div>
                
                <hr class="my-3">
                
                <div class="flex justify-between text-lg">
                    <span class="font-semibold text-gray-900">Total</span>
                    <span class="font-bold text-gray-900"><?php echo Currency::format($order['total'], $currency); ?></span>
                </div>
            </div>
        </div>
        
        <!-- Shipping Address -->
        <?php if (!empty($order['shipping_name'])): ?>
        <div class="bg-white rounded-xl border border-gray-200 p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Shipping Address</h3>
            <address class="not-italic text-gray-600 text-sm leading-relaxed">
                <strong class="text-gray-900"><?php echo sanitize($order['shipping_name']); ?></strong><br>
                <?php if (!empty($order['shipping_address'])): ?>
                <?php echo nl2br(sanitize($order['shipping_address'])); ?><br>
                <?php endif; ?>
                <?php echo sanitize($order['shipping_city'] ?? ''); ?>
                <?php if (!empty($order['shipping_state'])): ?>, <?php echo sanitize($order['shipping_state']); ?><?php endif; ?>
                <?php if (!empty($order['shipping_postal'])): ?> <?php echo sanitize($order['shipping_postal']); ?><?php endif; ?><br>
                <?php echo sanitize($order['shipping_country'] ?? ''); ?>
                <?php if (!empty($order['shipping_phone'])): ?>
                <br><br><i class="fa-solid fa-phone text-gray-400"></i> <?php echo sanitize($order['shipping_phone']); ?>
                <?php endif; ?>
            </address>
        </div>
        <?php endif; ?>
        
        <!-- Tracking -->
        <?php if (!empty($order['tracking_number'])): ?>
        <div class="bg-white rounded-xl border border-gray-200 p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Tracking</h3>
            <div class="p-4 bg-cyan-50 border border-cyan-200 rounded-lg">
                <p class="text-sm text-gray-500 mb-1">Tracking Number</p>
                <p class="font-mono font-semibold text-cyan-800"><?php echo sanitize($order['tracking_number']); ?></p>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Print Shop Contact -->
        <div class="bg-white rounded-xl border border-gray-200 p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Print Shop</h3>
            <p class="font-medium text-gray-900"><?php echo sanitize($order['print_shop_name'] ?? 'N/A'); ?></p>
            <?php if (!empty($order['print_shop_email'])): ?>
            <p class="text-sm text-gray-500 mt-2">
                <i class="fa-solid fa-envelope text-gray-400 mr-1"></i>
                <a href="mailto:<?php echo sanitize($order['print_shop_email']); ?>" class="text-blue-600 hover:underline">
                    <?php echo sanitize($order['print_shop_email']); ?>
                </a>
            </p>
            <?php endif; ?>
            <?php if (!empty($order['print_shop_phone'])): ?>
            <p class="text-sm text-gray-500 mt-1">
                <i class="fa-solid fa-phone text-gray-400 mr-1"></i>
                <?php echo sanitize($order['print_shop_phone']); ?>
            </p>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- PO Upload Modal -->
<div id="poUploadModal" class="hidden fixed inset-0 z-50 overflow-y-auto">
    <div class="flex items-center justify-center min-h-screen px-4">
        <div class="fixed inset-0 bg-black/50" onclick="document.getElementById('poUploadModal').classList.add('hidden')"></div>
        
        <div class="relative bg-white rounded-2xl shadow-xl max-w-md w-full">
            <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                <h3 class="text-lg font-semibold text-gray-900">Upload Purchase Order</h3>
                <button onclick="document.getElementById('poUploadModal').classList.add('hidden')" 
                        class="text-gray-400 hover:text-gray-600">
                    <i class="fa-solid fa-times text-xl"></i>
                </button>
            </div>
            
            <form method="post" enctype="multipart/form-data" class="p-6 space-y-4">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="upload_po">
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">PO Number</label>
                    <input type="text" name="po_number" required
                           class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20"
                           placeholder="e.g., PO-2026-001">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">PO Document</label>
                    <input type="file" name="po_file" required accept=".pdf,.doc,.docx,.jpg,.jpeg,.png"
                           class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:border-blue-500">
                    <p class="text-xs text-gray-500 mt-1">PDF, DOC, or image files accepted</p>
                </div>
                
                <div class="flex gap-3 pt-4">
                    <button type="button" onclick="document.getElementById('poUploadModal').classList.add('hidden')"
                            class="flex-1 px-4 py-2.5 border border-gray-200 rounded-lg text-gray-700 hover:bg-gray-50 font-medium">
                        Cancel
                    </button>
                    <button type="submit"
                            class="flex-1 px-4 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium">
                        Upload PO
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php adminFooter(); ?>
