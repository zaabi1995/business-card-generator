<?php
/**
 * Print Shop Order Details
 * View single order with card files and shipping info
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/PrintShop.php';
require_once INCLUDES_DIR . '/PrintShopIntegration.php';
require_once INCLUDES_DIR . '/Currency.php';
require_once INCLUDES_DIR . '/PrintShopOdoo.php';

Auth::requireLogin();
$user = Auth::getCurrentUser();

if ($user['role'] !== 'print_shop' && $user['role'] !== 'super_admin') {
    header('Location: ' . getBasePath() . 'login.php');
    exit;
}

// Include Mailer for email notifications
require_once INCLUDES_DIR . '/Mailer.php';

/**
 * Helper function to send document-related emails
 * @param string $template Email template name
 * @param array $order Order data
 * @param array $printShop Print shop data
 * @param array $extraData Additional template data
 * @param string|null $attachmentPath File to attach
 */
function sendDocumentEmail($template, $order, $printShop, $extraData = [], $attachmentPath = null) {
    try {
        // Determine recipient - try company admin email first, then shipping email
        $recipientEmail = null;
        $recipientName = $order['company_name'] ?? 'Customer';
        
        // Get company admin email
        if (!empty($order['company_id'])) {
            $db = Database::getInstance();
            $company = $db->fetchOne("SELECT admin_email, name FROM companies WHERE id = :id", ['id' => $order['company_id']]);
            if ($company && !empty($company['admin_email'])) {
                $recipientEmail = $company['admin_email'];
                $recipientName = $company['name'] ?? $recipientName;
            }
        }
        
        // Fallback to shipping email if no company email
        if (empty($recipientEmail) && !empty($order['shipping_email'])) {
            $recipientEmail = $order['shipping_email'];
            $recipientName = $order['shipping_name'] ?? $recipientName;
        }
        
        if (empty($recipientEmail)) {
            error_log("sendDocumentEmail: No recipient email for order #{$order['id']}");
            return false;
        }
        
        $siteName = defined('SITE_NAME') ? SITE_NAME : 'Cardify';
        $currency = $order['currency'] ?? $printShop['currency'] ?? 'OMR';
        
        // Build template data
        $data = array_merge([
            'site_name' => $siteName,
            'contact_name' => $recipientName,
            'order_id' => $order['id'],
            'order_number' => $order['order_number'] ?? "ORD-{$order['id']}",
            'company_name' => $order['company_name'] ?? 'Your Company',
            'quantity' => $order['quantity'] ?? 100,
            'paper_type' => ucfirst($order['paper_type'] ?? 'standard'),
            'finish' => ucfirst(str_replace('_', ' ', $order['finish'] ?? 'standard')),
            'print_shop_name' => $printShop['name'] ?? 'Print Shop',
            'shipping_name' => $order['shipping_name'] ?? '',
            'shipping_address' => $order['shipping_address'] ?? '',
            'shipping_city' => $order['shipping_city'] ?? '',
            'shipping_country' => $order['shipping_country'] ?? ''
        ], $extraData);
        
        // Prepare attachment if provided
        $attachments = [];
        if ($attachmentPath && file_exists($attachmentPath)) {
            $attachments[] = [
                'path' => $attachmentPath,
                'name' => basename($attachmentPath)
            ];
        }
        
        // Send email
        return Mailer::sendTemplate($recipientEmail, $template, $data, $attachments, [
            'company_id' => $order['company_id'] ?? null,
            'recipient_name' => $recipientName
        ]);
    } catch (Exception $e) {
        error_log("sendDocumentEmail error: " . $e->getMessage());
        return false;
    }
}

$printShop = PrintShop::getByUserId($user['id']);
if (!$printShop && $user['role'] !== 'super_admin') {
    header('Location: ' . getBasePath() . 'printshop/register.php');
    exit;
}

// Get order ID
$orderId = (int)($_GET['id'] ?? 0);
if ($orderId <= 0) {
    header('Location: orders.php');
    exit;
}

// Fetch order details
$db = Database::getInstance();
$pdo = $db->getConnection();

try {
    $stmt = $pdo->prepare("
        SELECT po.*, 
               COALESCE(c.name, 'Unknown Company') as company_name, 
               c.admin_email as company_email,
               COALESCE(e.name_en, e.name_ar, '') as employee_name,
               e.email as employee_email, 
               COALESCE(e.position_en, e.position_ar, '') as employee_position,
               COALESCE(e.phone, e.mobile, '') as employee_phone,
               ps.name as shop_name, ps.currency
        FROM print_orders po
        LEFT JOIN companies c ON po.company_id = c.id
        LEFT JOIN employees e ON po.employee_id = e.id
        LEFT JOIN print_shops ps ON po.print_shop_id = ps.id
        WHERE po.id = ?
    ");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Order fetch error: " . $e->getMessage());
    $order = null;
}

// Verify this order belongs to this print shop
if (!$order || ($user['role'] !== 'super_admin' && $order['print_shop_id'] != $printShop['id'])) {
    header('Location: orders.php');
    exit;
}

$message = null;
$messageType = 'success';

// Order attachments land under the webroot at /uploads/orders/<id>/ with a
// name whose extension came straight from the client. nginx already refuses to
// execute .php under /uploads/ (extension/00-noindex-sensitive.conf), so this
// was not code execution, but it did let any caller park arbitrary file types
// in a public directory. Constrain to inert document and image types here too,
// per the finfo/MIME rule in CLAUDE.md, rather than relying on one layer.
$ORDER_DOC_EXTS = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'gif', 'doc', 'docx', 'xls', 'xlsx', 'csv'];
$ORDER_DOC_MAX_BYTES = 20 * 1024 * 1024;

// Initialize Odoo integration
$odoo = new PrintShopOdoo($printShop['id']);

// Helper function to refresh order data
function refreshOrderData($pdo, $orderId) {
    $stmt = $pdo->prepare("
        SELECT po.*, 
               COALESCE(c.name, 'Unknown Company') as company_name, 
               c.admin_email as company_email,
               COALESCE(e.name_en, e.name_ar, '') as employee_name,
               e.email as employee_email, 
               COALESCE(e.position_en, e.position_ar, '') as employee_position,
               COALESCE(e.phone, e.mobile, '') as employee_phone,
               ps.name as shop_name, ps.currency
        FROM print_orders po
        LEFT JOIN companies c ON po.company_id = c.id
        LEFT JOIN employees e ON po.employee_id = e.id
        LEFT JOIN print_shops ps ON po.print_shop_id = ps.id
        WHERE po.id = ?
    ");
    $stmt->execute([$orderId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !validateCSRFToken($_POST['csrf_token'] ?? '')) {
    die(htmlspecialchars(t('printshoporder.invalid_request')));
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_status') {
        $newStatus = $_POST['status'] ?? '';
        $trackingNumber = trim($_POST['tracking_number'] ?? '');
        $notes = trim($_POST['internal_notes'] ?? '');
        
        $result = PrintShopIntegration::updateOrderStatus($orderId, $newStatus, $trackingNumber ?: null);
        
        if ($result['success']) {
            if ($notes) {
                $pdo->prepare("UPDATE print_orders SET notes = ? WHERE id = ?")->execute([$notes, $orderId]);
            }
            $stk = 'printshoporder.status_' . $newStatus;
            $stl = t($stk);
            if ($stl === $stk) $stl = ucfirst($newStatus);
            $message = str_replace(':status', $stl, t('printshoporder.order_updated'));
            $order = refreshOrderData($pdo, $orderId);
        } else {
            $message = str_replace(':msg', (string) ($result['error'] ?? t('printshoporder.unknown_error')), t('printshoporder.update_error'));
            $messageType = 'error';
        }
        
    } elseif ($action === 'upload_quotation') {
        if (isset($_FILES['quotation_file']) && $_FILES['quotation_file']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = 'uploads/orders/' . $orderId . '/';
            $fullUploadDir = BASE_DIR . '/' . $uploadDir;
            if (!is_dir($fullUploadDir)) mkdir($fullUploadDir, 0755, true);
            
            $ext = strtolower(pathinfo($_FILES['quotation_file']['name'], PATHINFO_EXTENSION));
            $filename = 'quotation_' . date('Ymd_His') . '_' . uniqid() . '.' . $ext;
            $filePath = $uploadDir . $filename;

            if (!in_array($ext, $ORDER_DOC_EXTS, true)) {
                $message = t('printshoporder.upload_bad_type');
                $messageType = 'error';
            } elseif ((int) $_FILES['quotation_file']['size'] > $ORDER_DOC_MAX_BYTES) {
                $message = t('printshoporder.upload_too_large');
                $messageType = 'error';
            } elseif (move_uploaded_file($_FILES['quotation_file']['tmp_name'], BASE_DIR . '/' . $filePath)) {
                $quotationNumber = trim($_POST['quotation_number'] ?? '') ?: $odoo->generateDocumentNumber('quotation');
                $quotationAmount = floatval($_POST['quotation_amount'] ?? $order['total']);
                $validUntil = !empty($_POST['quotation_valid_until']) ? $_POST['quotation_valid_until'] : date('Y-m-d', strtotime('+30 days'));
                
                $pdo->prepare("UPDATE print_orders SET 
                    quotation_file_path = ?, quotation_number = ?, quotation_amount = ?,
                    quotation_issued_at = NOW(), quotation_valid_until = ?, quotation_requested = 0
                    WHERE id = ?")->execute([$filePath, $quotationNumber, $quotationAmount, $validUntil, $orderId]);
                
                // Sync to Odoo if enabled
                if ($odoo->isSyncEnabled() && !empty($order['quotation_external_id'])) {
                    $odoo->attachFile($order['quotation_external_id'], $filePath, 'Quotation');
                }
                
                // Send email notification to company
                $order = refreshOrderData($pdo, $orderId);
                sendDocumentEmail('quotation_sent', $order, $printShop, [
                    'quotation_number' => $quotationNumber,
                    'amount' => Currency::format($quotationAmount, $order['currency'] ?? 'OMR'),
                    'valid_until' => date('F j, Y', strtotime($validUntil))
                ], BASE_DIR . '/' . $filePath);
                
                $message = "Quotation uploaded and sent to company!";
            } else {
                $message = "Failed to upload quotation file";
                $messageType = 'error';
            }
        }
        
    } elseif ($action === 'upload_po') {
        if (isset($_FILES['po_file']) && $_FILES['po_file']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = 'uploads/orders/' . $orderId . '/';
            $fullUploadDir = BASE_DIR . '/' . $uploadDir;
            if (!is_dir($fullUploadDir)) mkdir($fullUploadDir, 0755, true);
            
            $ext = strtolower(pathinfo($_FILES['po_file']['name'], PATHINFO_EXTENSION));
            $filename = 'po_' . date('Ymd_His') . '_' . uniqid() . '.' . $ext;
            $filePath = $uploadDir . $filename;

            if (!in_array($ext, $ORDER_DOC_EXTS, true)) {
                $message = t('printshoporder.upload_bad_type');
                $messageType = 'error';
            } elseif ((int) $_FILES['po_file']['size'] > $ORDER_DOC_MAX_BYTES) {
                $message = t('printshoporder.upload_too_large');
                $messageType = 'error';
            } elseif (move_uploaded_file($_FILES['po_file']['tmp_name'], BASE_DIR . '/' . $filePath)) {
                $poNumber = trim($_POST['po_number'] ?? '');
                
                $pdo->prepare("UPDATE print_orders SET 
                    po_file_path = ?, po_number = ?, po_received_at = NOW(), po_required = 0
                    WHERE id = ?")->execute([$filePath, $poNumber, $orderId]);
                
                // Send confirmation email to company
                $order = refreshOrderData($pdo, $orderId);
                sendDocumentEmail('po_received', $order, $printShop, [
                    'po_number' => $poNumber,
                    'amount' => Currency::format($order['total'] ?? 0, $order['currency'] ?? 'OMR')
                ]);
                
                $message = "Purchase Order received! Confirmation sent to company.";
            } else {
                $message = "Failed to upload PO file";
                $messageType = 'error';
            }
        }
        
    } elseif ($action === 'approve_po') {
        $pdo->prepare("UPDATE print_orders SET po_approved = 1, po_approved_by = ?, po_approved_at = NOW() WHERE id = ?")
            ->execute([$user['id'], $orderId]);
        $message = "Purchase Order approved!";
        $order = refreshOrderData($pdo, $orderId);
        
    } elseif ($action === 'upload_invoice') {
        if (isset($_FILES['invoice_file']) && $_FILES['invoice_file']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = 'uploads/orders/' . $orderId . '/';
            $fullUploadDir = BASE_DIR . '/' . $uploadDir;
            if (!is_dir($fullUploadDir)) mkdir($fullUploadDir, 0755, true);
            
            $ext = strtolower(pathinfo($_FILES['invoice_file']['name'], PATHINFO_EXTENSION));
            $filename = 'invoice_' . date('Ymd_His') . '_' . uniqid() . '.' . $ext;
            $filePath = $uploadDir . $filename;

            if (!in_array($ext, $ORDER_DOC_EXTS, true)) {
                $message = t('printshoporder.upload_bad_type');
                $messageType = 'error';
            } elseif ((int) $_FILES['invoice_file']['size'] > $ORDER_DOC_MAX_BYTES) {
                $message = t('printshoporder.upload_too_large');
                $messageType = 'error';
            } elseif (move_uploaded_file($_FILES['invoice_file']['tmp_name'], BASE_DIR . '/' . $filePath)) {
                $invoiceNumber = trim($_POST['invoice_number'] ?? '') ?: $odoo->generateDocumentNumber('invoice');
                $invoiceAmount = floatval($_POST['invoice_amount'] ?? $order['total']);
                $dueDate = !empty($_POST['invoice_due_date']) ? $_POST['invoice_due_date'] : date('Y-m-d', strtotime('+30 days'));
                
                $pdo->prepare("UPDATE print_orders SET 
                    invoice_file_path = ?, invoice_number = ?, invoice_amount = ?,
                    invoice_issued_at = NOW(), invoice_due_date = ?
                    WHERE id = ?")->execute([$filePath, $invoiceNumber, $invoiceAmount, $dueDate, $orderId]);
                
                // Send invoice email to company with attachment
                $order = refreshOrderData($pdo, $orderId);
                sendDocumentEmail('invoice_sent', $order, $printShop, [
                    'invoice_number' => $invoiceNumber,
                    'amount' => Currency::format($invoiceAmount, $order['currency'] ?? 'OMR'),
                    'invoice_date' => date('F j, Y'),
                    'due_date' => date('F j, Y', strtotime($dueDate))
                ], BASE_DIR . '/' . $filePath);
                
                $message = "Invoice issued and sent to company!";
            } else {
                $message = "Failed to upload invoice file";
                $messageType = 'error';
            }
        }
        
    } elseif ($action === 'mark_paid') {
        $directMethod = $_POST['direct_method'] ?? 'bank_transfer';
        $directRef    = trim($_POST['direct_ref'] ?? '');
        $directNotes  = trim($_POST['direct_notes'] ?? '');

        $validMethods = ['cash', 'bank_transfer', 'cheque', 'po', 'online', 'credit'];
        if (!in_array($directMethod, $validMethods)) $directMethod = 'bank_transfer';

        $pdo->prepare("UPDATE print_orders SET
            invoice_paid               = 1,
            invoice_paid_at            = NOW(),
            payment_status             = 'paid',
            payment_method             = ?,
            direct_payment_ref         = ?,
            direct_payment_notes       = ?,
            direct_payment_recorded_by = ?,
            direct_payment_recorded_at = NOW()
            WHERE id = ?")->execute([$directMethod, $directRef, $directNotes, $user['id'], $orderId]);

        // Sync to BHD-ERP if enabled
        require_once INCLUDES_DIR . '/ERPSync.php';
        if (ERPSync::isEnabled()) {
            $syncResult = ERPSync::recordPayment($orderId, $directMethod, $directRef, $directNotes, $user['id']);
            if (!$syncResult['success']) {
                error_log("ERPSync failed for order $orderId: " . $syncResult['message']);
                // Non-fatal: order is still marked paid locally
            }
        }

        // Sync to Odoo if enabled (legacy)
        if ($odoo->isSyncEnabled()) {
            $odoo->markInvoicePaid($orderId);
        }

        // Send payment confirmation email
        $order = refreshOrderData($pdo, $orderId);
        sendDocumentEmail('payment_received', $order, $printShop, [
            'invoice_number' => $order['invoice_number'] ?? 'N/A',
            'amount' => Currency::format($order['invoice_amount'] ?? $order['total'], $order['currency'] ?? 'OMR'),
            'payment_date' => date('F j, Y')
        ]);

        $erpNote = isset($syncResult) && $syncResult['success']
            ? ' ERP invoice: ' . ($syncResult['data']['invoiceNumber'] ?? 'synced')
            : '';
        $message = "Payment recorded (" . ucfirst(str_replace('_', ' ', $directMethod)) . ")!$erpNote";
        
    } elseif ($action === 'upload_delivery_note') {
        if (isset($_FILES['delivery_note_file']) && $_FILES['delivery_note_file']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = 'uploads/orders/' . $orderId . '/';
            $fullUploadDir = BASE_DIR . '/' . $uploadDir;
            if (!is_dir($fullUploadDir)) mkdir($fullUploadDir, 0755, true);
            
            $ext = strtolower(pathinfo($_FILES['delivery_note_file']['name'], PATHINFO_EXTENSION));
            $filename = 'dn_' . date('Ymd_His') . '_' . uniqid() . '.' . $ext;
            $filePath = $uploadDir . $filename;

            if (!in_array($ext, $ORDER_DOC_EXTS, true)) {
                $message = t('printshoporder.upload_bad_type');
                $messageType = 'error';
            } elseif ((int) $_FILES['delivery_note_file']['size'] > $ORDER_DOC_MAX_BYTES) {
                $message = t('printshoporder.upload_too_large');
                $messageType = 'error';
            } elseif (move_uploaded_file($_FILES['delivery_note_file']['tmp_name'], BASE_DIR . '/' . $filePath)) {
                $dnNumber = trim($_POST['delivery_note_number'] ?? '') ?: $odoo->generateDocumentNumber('delivery');
                
                $pdo->prepare("UPDATE print_orders SET 
                    delivery_note_file_path = ?, delivery_note_number = ?, delivery_note_issued_at = NOW()
                    WHERE id = ?")->execute([$filePath, $dnNumber, $orderId]);
                
                // Send delivery note email to company with attachment
                $order = refreshOrderData($pdo, $orderId);
                sendDocumentEmail('delivery_note_sent', $order, $printShop, [
                    'dn_number' => $dnNumber
                ], BASE_DIR . '/' . $filePath);
                
                $message = "Delivery Note issued and sent to company!";
            } else {
                $message = "Failed to upload delivery note";
                $messageType = 'error';
            }
        }
        
    } elseif ($action === 'request_po') {
        // Mark PO as required and send request to company
        $pdo->prepare("UPDATE print_orders SET po_required = 1 WHERE id = ?")->execute([$orderId]);
        
        $order = refreshOrderData($pdo, $orderId);
        sendDocumentEmail('po_request', $order, $printShop, [
            'amount' => Currency::format($order['quotation_amount'] ?? $order['total'], $order['currency'] ?? 'OMR')
        ]);
        
        $message = "PO request sent to company!";
        
    } elseif ($action === 'request_quotation') {
        // Mark quotation as requested (from company side - for print shop to create)
        $pdo->prepare("UPDATE print_orders SET quotation_requested = 1 WHERE id = ?")->execute([$orderId]);
        $message = "Quotation requested! You will receive notification when it's ready.";
        $order = refreshOrderData($pdo, $orderId);
        
    } elseif ($action === 'accept_quotation') {
        // Company accepts quotation
        $pdo->prepare("UPDATE print_orders SET quotation_accepted = 1, quotation_accepted_at = NOW() WHERE id = ?")->execute([$orderId]);
        $message = "Quotation accepted!";
        $order = refreshOrderData($pdo, $orderId);
        
    } elseif ($action === 'sync_odoo') {
        if ($odoo->isEnabled()) {
            $result = $odoo->syncOrder($orderId);
            if ($result['success']) {
                $message = "Order synced to Odoo!";
                $order = refreshOrderData($pdo, $orderId);
            } else {
                $message = "Odoo sync failed: " . ($result['error'] ?? 'Unknown');
                $messageType = 'error';
            }
        } else {
            $message = "Odoo integration is not enabled";
            $messageType = 'error';
        }
        
    } elseif ($action === 'create_odoo_quotation') {
        if ($odoo->isEnabled()) {
            $result = $odoo->createQuotation($orderId);
            if ($result['success']) {
                $message = "Quotation created in Odoo: " . ($result['quotation_number'] ?? '');
                $order = refreshOrderData($pdo, $orderId);
            } else {
                $message = "Failed to create quotation: " . ($result['error'] ?? 'Unknown');
                $messageType = 'error';
            }
        }
        
    } elseif ($action === 'create_odoo_invoice') {
        if ($odoo->isEnabled()) {
            $result = $odoo->createInvoice($orderId);
            if ($result['success']) {
                $message = "Invoice created in Odoo: " . ($result['invoice_number'] ?? '');
                $order = refreshOrderData($pdo, $orderId);
            } else {
                $message = "Failed to create invoice: " . ($result['error'] ?? 'Unknown');
                $messageType = 'error';
            }
        }
    }
}

$statusColors = [
    'pending' => 'bg-gray-100 text-gray-700 border-gray-300',
    'submitted' => 'bg-blue-100 text-blue-700 border-blue-300',
    'processing' => 'bg-purple-100 text-purple-700 border-purple-300',
    'printing' => 'bg-amber-100 text-amber-700 border-amber-300',
    'shipped' => 'bg-cyan-100 text-cyan-700 border-cyan-300',
    'delivered' => 'bg-green-100 text-green-700 border-green-300',
    'cancelled' => 'bg-red-100 text-red-700 border-red-300'
];

$statusSteps = ['pending', 'processing', 'printing', 'shipped', 'delivered'];
$currentStepIndex = array_search($order['status'], $statusSteps);

$currency = $order['currency'] ?? $printShop['currency'] ?? 'OMR';

require_once INCLUDES_DIR . '/printshop-layout.php';
printshopHeader(t('printshoppages.title_order_n', ['n' => $orderId, 'shop' => $printShop['name'] ?? 'Print Shop']), 'orders');
?>
    <div class="max-w-6xl mx-auto">
        <!-- Header -->
        <div class="flex items-center justify-between mb-6">
            <div>
                <a href="orders.php" class="text-sm text-blue-600 hover:text-blue-700 mb-1 inline-flex items-center gap-1">
                    <i class="fa-solid fa-arrow-left"></i> <?= htmlspecialchars(t('printshoporder.back_to_orders')) ?>
                </a>
                <h1 class="text-2xl font-bold text-gray-900"><?= htmlspecialchars(t("printshoppages.h1_order_n", ["n" => $orderId])) ?></h1>
                <p class="text-gray-500"><?= htmlspecialchars(str_replace(':date', date('F j, Y \a\t g:i A', dbTs($order['created_at'])), t('printshoporder.placed_on'))) ?></p>
            </div>
            <span class="inline-flex px-4 py-2 rounded-lg text-sm font-semibold border <?php echo $statusColors[$order['status']] ?? 'bg-gray-100 text-gray-700'; ?>">
                <?php $stk = 'printshoporder.status_' . $order['status']; $stl = t($stk); echo htmlspecialchars($stl === $stk ? ucfirst($order['status']) : $stl); ?>
            </span>
        </div>
        
        <?php if ($message): ?>
        <div class="mb-6 p-4 rounded-xl <?php echo $messageType === 'success' ? 'bg-green-50 border border-green-200 text-green-700' : 'bg-red-50 border border-red-200 text-red-700'; ?> flex items-center gap-3">
            <i class="fa-solid <?php echo $messageType === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
            <?php echo sanitize($message); ?>
        </div>
        <?php endif; ?>
        
        <!-- Progress Steps -->
        <div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
            <div class="flex items-center justify-between">
                <?php foreach ($statusSteps as $i => $step): 
                    $isComplete = $currentStepIndex !== false && $i <= $currentStepIndex;
                    $isCurrent = $i === $currentStepIndex;
                ?>
                <div class="flex-1 <?php echo $i < count($statusSteps) - 1 ? 'pr-4' : ''; ?>">
                    <div class="flex items-center">
                        <div class="w-10 h-10 rounded-full flex items-center justify-center text-sm font-bold <?php echo $isComplete ? 'bg-green-500 text-white' : 'bg-gray-200 text-gray-500'; ?> <?php echo $isCurrent ? 'ring-4 ring-green-200' : ''; ?>">
                            <?php if ($isComplete && !$isCurrent): ?>
                            <i class="fa-solid fa-check"></i>
                            <?php else: ?>
                            <?php echo $i + 1; ?>
                            <?php endif; ?>
                        </div>
                        <?php if ($i < count($statusSteps) - 1): ?>
                        <div class="flex-1 h-1 mx-2 <?php echo $isComplete && $i < $currentStepIndex ? 'bg-green-500' : 'bg-gray-200'; ?>"></div>
                        <?php endif; ?>
                    </div>
                    <p class="text-xs mt-2 <?php echo $isCurrent ? 'text-green-600 font-semibold' : 'text-gray-500'; ?>">
                        <?php echo ucfirst($step); ?>
                    </p>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <div class="grid lg:grid-cols-3 gap-6">
            <!-- Main Content -->
            <div class="lg:col-span-2 space-y-6">
                
                <!-- Card Files -->
                <?php
                // Check for high-quality versions
                $frontUrl = $order['card_front_url'] ?? '';
                $backUrl = $order['card_back_url'] ?? '';

                // Verify the canonical PNG actually exists on disk; orders sometimes
                // outlive their card files (cleanup, regeneration, retention sweeps).
                // Empty out the URL so the empty-state branch renders instead of a broken img.
                if (!empty($frontUrl) && !file_exists(BASE_DIR . '/' . ltrim($frontUrl, '/'))) {
                    $frontUrl = '';
                }
                if (!empty($backUrl) && !file_exists(BASE_DIR . '/' . ltrim($backUrl, '/'))) {
                    $backUrl = '';
                }

                // Check for _hq versions (high quality)
                $frontHq = !empty($frontUrl) ? str_replace('.png', '_hq.png', $frontUrl) : '';
                $backHq = !empty($backUrl) ? str_replace('.png', '_hq.png', $backUrl) : '';
                $hasFrontHq = !empty($frontHq) && file_exists(BASE_DIR . '/' . ltrim($frontHq, '/'));
                $hasBackHq = !empty($backHq) && file_exists(BASE_DIR . '/' . ltrim($backHq, '/'));
                
                // Check for PDF versions
                $frontPdf = !empty($frontUrl) ? str_replace('.png', '.pdf', $frontUrl) : '';
                $backPdf = !empty($backUrl) ? str_replace('.png', '.pdf', $backUrl) : '';
                $hasFrontPdf = !empty($frontPdf) && file_exists(BASE_DIR . '/' . ltrim($frontPdf, '/'));
                $hasBackPdf = !empty($backPdf) && file_exists(BASE_DIR . '/' . ltrim($backPdf, '/'));
                
                // Use HQ versions if available, otherwise original
                $frontDownload = $hasFrontHq ? $frontHq : $frontUrl;
                $backDownload = $hasBackHq ? $backHq : $backUrl;
                ?>
                <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
                    <div class="p-6 border-b border-gray-100">
                        <h3 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
                            <i class="fa-solid fa-id-card text-blue-600"></i>
                            <?= htmlspecialchars(t('printshoporder.card_files_h')) ?>
                        </h3>
                        <p class="text-sm text-gray-500 mt-1"><?= htmlspecialchars(t('printshoporder.card_files_sub')) ?></p>
                    </div>
                    
                    <!-- Quality Notice -->
                    <div class="p-4 bg-green-50 border-b border-green-200">
                        <div class="flex items-start gap-3">
                            <i class="fa-solid fa-print text-green-600 text-lg mt-0.5"></i>
                            <div>
                                <p class="font-medium text-green-800"><?= htmlspecialchars(t('printshoporder.hq_notice')) ?></p>
                                <p class="text-sm text-green-700 mt-1">
                                    <?php if ($hasFrontPdf || $hasBackPdf): ?>
                                    <i class="fa-solid fa-check-circle mr-1"></i>PDF files available (recommended for best print quality ~600 DPI)
                                    <?php elseif ($hasFrontHq || $hasBackHq): ?>
                                    <i class="fa-solid fa-check-circle mr-1"></i>High-quality PNG files available (3x resolution)
                                    <?php else: ?>
                                    Standard resolution files. For best results, use PDF if available.
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="p-6">
                        <div class="grid md:grid-cols-2 gap-6">
                            <!-- Front Card -->
                            <div>
                                <div class="flex items-center justify-between mb-2">
                                    <p class="text-sm font-medium text-gray-700"><?= htmlspecialchars(t('printshoporder.front_side')) ?></p>
                                    <?php if ($hasFrontHq || $hasFrontPdf): ?>
                                    <span class="px-2 py-0.5 bg-green-100 text-green-700 rounded text-xs font-medium">
                                        <i class="fa-solid fa-star mr-1"></i>HQ Available
                                    </span>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($frontUrl)): ?>
                                <div class="border border-gray-200 rounded-lg overflow-hidden bg-gray-50">
                                    <img src="<?php echo getBasePath() . ltrim(sanitize($frontUrl), '/'); ?>" alt="Card Front" class="w-full">
                                </div>
                                <div class="mt-3 flex flex-wrap gap-2">
                                    <?php if ($hasFrontPdf): ?>
                                    <a href="<?php echo getBasePath() . ltrim(sanitize($frontPdf), '/'); ?>" download="card-front-order-<?php echo $orderId; ?>.pdf"
                                       class="inline-flex items-center gap-2 px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg text-sm font-medium transition-colors">
                                        <i class="fa-solid fa-file-pdf"></i>
                                        PDF (Best)
                                    </a>
                                    <?php endif; ?>
                                    <a href="<?php echo getBasePath() . ltrim(sanitize($frontDownload), '/'); ?>" download="card-front-order-<?php echo $orderId; ?>.png"
                                       class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium transition-colors">
                                        <i class="fa-solid fa-download"></i>
                                        PNG <?php echo $hasFrontHq ? '(HQ)' : ''; ?>
                                    </a>
                                </div>
                                <?php else: ?>
                                <div class="border-2 border-dashed border-gray-200 rounded-lg p-8 text-center text-gray-400">
                                    <i class="fa-solid fa-image text-3xl mb-2"></i>
                                    <p><?= htmlspecialchars(t('printshoporder.no_front_file')) ?></p>
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Back Card -->
                            <div>
                                <div class="flex items-center justify-between mb-2">
                                    <p class="text-sm font-medium text-gray-700"><?= htmlspecialchars(t('printshoporder.back_side')) ?></p>
                                    <?php if ($hasBackHq || $hasBackPdf): ?>
                                    <span class="px-2 py-0.5 bg-green-100 text-green-700 rounded text-xs font-medium">
                                        <i class="fa-solid fa-star mr-1"></i>HQ Available
                                    </span>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($backUrl)): ?>
                                <div class="border border-gray-200 rounded-lg overflow-hidden bg-gray-50">
                                    <img src="<?php echo getBasePath() . ltrim(sanitize($backUrl), '/'); ?>" alt="Card Back" class="w-full">
                                </div>
                                <div class="mt-3 flex flex-wrap gap-2">
                                    <?php if ($hasBackPdf): ?>
                                    <a href="<?php echo getBasePath() . ltrim(sanitize($backPdf), '/'); ?>" download="card-back-order-<?php echo $orderId; ?>.pdf"
                                       class="inline-flex items-center gap-2 px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg text-sm font-medium transition-colors">
                                        <i class="fa-solid fa-file-pdf"></i>
                                        PDF (Best)
                                    </a>
                                    <?php endif; ?>
                                    <a href="<?php echo getBasePath() . ltrim(sanitize($backDownload), '/'); ?>" download="card-back-order-<?php echo $orderId; ?>.png"
                                       class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium transition-colors">
                                        <i class="fa-solid fa-download"></i>
                                        PNG <?php echo $hasBackHq ? '(HQ)' : ''; ?>
                                    </a>
                                </div>
                                <?php else: ?>
                                <div class="border-2 border-dashed border-gray-200 rounded-lg p-8 text-center text-gray-400">
                                    <i class="fa-solid fa-image text-3xl mb-2"></i>
                                    <p><?= htmlspecialchars(t('printshoporder.no_back_file')) ?></p>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <?php if (!empty($frontUrl) || !empty($backUrl)): ?>
                        <div class="mt-6 pt-6 border-t border-gray-100">
                            <div class="flex flex-wrap gap-3">
                                <a href="#" data-cardify-action="call" data-fn="downloadAllFiles" 
                                   class="inline-flex items-center gap-2 px-6 py-3 bg-green-600 hover:bg-green-700 text-white rounded-lg font-medium transition-colors">
                                    <i class="fa-solid fa-file-zipper"></i>
                                    Download All Files
                                </a>
                                <button type="button" data-cardify-action="call" data-fn="openPrintReadyModal" 
                                   class="inline-flex items-center gap-2 px-6 py-3 bg-purple-600 hover:bg-purple-700 text-white rounded-lg font-medium transition-colors">
                                    <i class="fa-solid fa-print"></i>
                                    Generate Print Sheet (A4)
                                </button>
                            </div>
                            <p class="text-sm text-gray-500 mt-3">
                                <i class="fa-solid fa-info-circle mr-1"></i>
                                Print Sheet generates an A4 PDF with multiple cards and cutting marks for professional printing.
                            </p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Print Specifications -->
                <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
                    <div class="p-6 border-b border-gray-100">
                        <h3 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
                            <i class="fa-solid fa-sliders text-purple-600"></i>
                            <?= htmlspecialchars(t('printshoporder.specs_h')) ?>
                        </h3>
                    </div>
                    <div class="p-6">
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-6">
                            <div>
                                <p class="text-sm text-gray-500"><?= htmlspecialchars(t('printshoporder.specs_quantity')) ?></p>
                                <p class="text-xl font-bold text-gray-900"><?= htmlspecialchars(str_replace(':n', (string) $order['quantity'], t('printshoporder.specs_n_cards'))) ?></p>
                            </div>
                            <div>
                                <p class="text-sm text-gray-500"><?= htmlspecialchars(t('printshoporder.specs_paper')) ?></p>
                                <p class="text-xl font-bold text-gray-900"><?php echo ucfirst($order['paper_type'] ?? 'Standard'); ?></p>
                            </div>
                            <div>
                                <p class="text-sm text-gray-500"><?= htmlspecialchars(t('printshoporder.specs_finish')) ?></p>
                                <p class="text-xl font-bold text-gray-900"><?php echo ucfirst(str_replace('_', ' ', $order['finish'] ?? 'Standard')); ?></p>
                            </div>
                            <div>
                                <p class="text-sm text-gray-500"><?= htmlspecialchars(t('printshoporder.specs_total')) ?></p>
                                <p class="text-xl font-bold text-green-600"><?php echo Currency::formatHtml($order['total'] ?? 0, $currency, 'md'); ?></p>
                            </div>
                        </div>
                        
                        <?php if (!empty($order['notes'])): ?>
                        <div class="mt-6 pt-6 border-t border-gray-100">
                            <p class="text-sm font-medium text-gray-700 mb-2"><?= htmlspecialchars(t('printshoporder.special_instr')) ?></p>
                            <p class="text-gray-600 bg-gray-50 rounded-lg p-4"><?php echo nl2br(sanitize($order['notes'])); ?></p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Shipping Info -->
                <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
                    <div class="p-6 border-b border-gray-100">
                        <h3 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
                            <i class="fa-solid fa-truck text-cyan-600"></i>
                            <?= htmlspecialchars(t('printshoporder.shipping_h')) ?>
                        </h3>
                    </div>
                    <div class="p-6">
                        <div class="grid md:grid-cols-2 gap-6">
                            <div>
                                <p class="text-sm text-gray-500 mb-1"><?= htmlspecialchars(t('printshoporder.recipient')) ?></p>
                                <p class="font-semibold text-gray-900"><?php echo sanitize($order['shipping_name'] ?? t('printshoporder.not_provided')); ?></p>
                                <?php if (!empty($order['shipping_phone'])): ?>
                                <p class="text-gray-600"><?php echo sanitize($order['shipping_phone']); ?></p>
                                <?php endif; ?>
                            </div>
                            <div>
                                <p class="text-sm text-gray-500 mb-1"><?= htmlspecialchars(t('printshoporder.address')) ?></p>
                                <p class="text-gray-900">
                                    <?php echo sanitize($order['shipping_address'] ?? ''); ?><br>
                                    <?php echo sanitize($order['shipping_city'] ?? ''); ?><?php echo !empty($order['shipping_state']) ? ', ' . sanitize($order['shipping_state']) : ''; ?> <?php echo sanitize($order['shipping_postal'] ?? ''); ?><br>
                                    <?php echo sanitize($order['shipping_country'] ?? ''); ?>
                                </p>
                            </div>
                        </div>
                        
                        <?php if (!empty($order['tracking_number'])): ?>
                        <div class="mt-6 pt-6 border-t border-gray-100">
                            <p class="text-sm text-gray-500 mb-1"><?= htmlspecialchars(t('printshoporder.tracking_number')) ?></p>
                            <p class="font-mono text-lg font-bold text-blue-600"><?php echo sanitize($order['tracking_number']); ?></p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Documents & Billing -->
                <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
                    <div class="p-6 border-b border-gray-100">
                        <div class="flex items-center justify-between">
                            <h3 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
                                <i class="fa-solid fa-file-invoice text-indigo-600"></i>
                                Documents & Billing
                            </h3>
                            <?php if ($odoo->isEnabled()): ?>
                            <span class="inline-flex items-center gap-1 text-xs bg-orange-100 text-orange-700 px-2 py-1 rounded-full">
                                <i class="fa-solid fa-plug"></i> Odoo Connected
                            </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="p-6 space-y-6" x-data="{ activeTab: 'quotation' }">
                        
                        <!-- Tab Navigation -->
                        <div class="flex border-b border-gray-200">
                            <button type="button" @click="activeTab = 'quotation'" 
                                    :class="activeTab === 'quotation' ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-gray-500 hover:text-gray-700'"
                                    class="px-4 py-2 border-b-2 font-medium text-sm transition-colors">
                                <i class="fa-solid fa-file-lines mr-1"></i> Quotation
                                <?php if (!empty($order['quotation_number'])): ?>
                                <span class="ml-1 bg-green-100 text-green-700 text-xs px-1.5 py-0.5 rounded">✓</span>
                                <?php endif; ?>
                            </button>
                            <button type="button" @click="activeTab = 'po'" 
                                    :class="activeTab === 'po' ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-gray-500 hover:text-gray-700'"
                                    class="px-4 py-2 border-b-2 font-medium text-sm transition-colors">
                                <i class="fa-solid fa-file-contract mr-1"></i> Purchase Order
                                <?php if (!empty($order['po_number'])): ?>
                                <span class="ml-1 bg-green-100 text-green-700 text-xs px-1.5 py-0.5 rounded">✓</span>
                                <?php endif; ?>
                            </button>
                            <button type="button" @click="activeTab = 'invoice'" 
                                    :class="activeTab === 'invoice' ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-gray-500 hover:text-gray-700'"
                                    class="px-4 py-2 border-b-2 font-medium text-sm transition-colors">
                                <i class="fa-solid fa-file-invoice-dollar mr-1"></i> Invoice
                                <?php if (!empty($order['invoice_number'])): ?>
                                <span class="ml-1 <?php echo ($order['invoice_paid'] ?? false) ? 'bg-green-100 text-green-700' : 'bg-yellow-100 text-yellow-700'; ?> text-xs px-1.5 py-0.5 rounded">
                                    <?php echo ($order['invoice_paid'] ?? false) ? 'Paid' : 'Pending'; ?>
                                </span>
                                <?php endif; ?>
                            </button>
                            <button type="button" @click="activeTab = 'delivery'" 
                                    :class="activeTab === 'delivery' ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-gray-500 hover:text-gray-700'"
                                    class="px-4 py-2 border-b-2 font-medium text-sm transition-colors">
                                <i class="fa-solid fa-truck-fast mr-1"></i> Delivery Note
                                <?php if (!empty($order['delivery_note_number'])): ?>
                                <span class="ml-1 bg-green-100 text-green-700 text-xs px-1.5 py-0.5 rounded">✓</span>
                                <?php endif; ?>
                            </button>
                        </div>
                        
                        <!-- Quotation Tab -->
                        <div x-show="activeTab === 'quotation'" class="space-y-4">
                            <?php if (!empty($order['quotation_number'])): ?>
                            <div class="p-4 bg-green-50 rounded-lg border border-green-200">
                                <div class="flex items-center justify-between mb-2">
                                    <span class="font-semibold text-green-900">Quotation Issued</span>
                                    <span class="text-sm text-green-700"><?php echo sanitize($order['quotation_number']); ?></span>
                                </div>
                                <div class="grid grid-cols-1 sm:grid-cols-3 gap-2 sm:gap-4 text-sm">
                                    <div>
                                        <span class="text-green-700">Amount:</span>
                                        <span class="font-semibold"><?php echo Currency::format($order['quotation_amount'] ?? 0, $currency); ?></span>
                                    </div>
                                    <div>
                                        <span class="text-green-700">Issued:</span>
                                        <span><?php echo $order['quotation_issued_at'] ? date('M j, Y', dbTs($order['quotation_issued_at'])) : '-'; ?></span>
                                    </div>
                                    <div>
                                        <span class="text-green-700">Valid Until:</span>
                                        <span><?php echo $order['quotation_valid_until'] ? date('M j, Y', dbTs($order['quotation_valid_until'])) : '-'; ?></span>
                                    </div>
                                </div>
                                <?php if (!empty($order['quotation_file_path'])): ?>
                                <a href="<?php echo getBasePath() . sanitize($order['quotation_file_path']); ?>" target="_blank"
                                   class="mt-3 inline-flex items-center gap-2 px-3 py-1.5 bg-green-600 hover:bg-green-700 text-white rounded text-sm">
                                    <i class="fa-solid fa-download"></i> Download Quotation
                                </a>
                                <?php endif; ?>
                                <?php if ($order['quotation_accepted'] ?? false): ?>
                                <span class="ml-2 inline-flex items-center gap-1 text-green-700 text-sm">
                                    <i class="fa-solid fa-check-circle"></i> Accepted
                                </span>
                                <?php endif; ?>
                            </div>
                            <?php else: ?>
                            <form method="post" enctype="multipart/form-data" class="space-y-4">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="upload_quotation">
                                <div class="grid md:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Quotation Number</label>
                                        <input aria-label="Quotation Number" type="text" name="quotation_number" placeholder="Auto-generated if empty"
                                               class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Amount (<?php echo $currency; ?>)</label>
                                        <input aria-label="Amount ( )" type="number" name="quotation_amount" step="0.001" value="<?php echo $order['total'] ?? 0; ?>"
                                               class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm">
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Valid Until</label>
                                    <input aria-label="Valid Until" type="date" name="quotation_valid_until" value="<?php echo date('Y-m-d', strtotime('+30 days')); ?>"
                                           class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Upload Quotation PDF</label>
                                    <input aria-label="Upload Quotation PDF" type="file" name="quotation_file" accept=".pdf,.doc,.docx"
                                           class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm file:mr-4 file:py-1 file:px-3 file:rounded file:border-0 file:bg-indigo-50 file:text-indigo-700">
                                </div>
                                <div class="flex gap-2">
                                    <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-sm font-medium">
                                        <i class="fa-solid fa-upload mr-1"></i> Issue Quotation
                                    </button>
                                    <?php if ($odoo->isEnabled()): ?>
                                    <button type="submit" name="action" value="create_odoo_quotation" 
                                            class="px-4 py-2 bg-orange-600 hover:bg-orange-700 text-white rounded-lg text-sm font-medium">
                                        <i class="fa-solid fa-plug mr-1"></i> Create in Odoo
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </form>
                            <?php endif; ?>
                        </div>
                        
                        <!-- PO Tab -->
                        <div x-show="activeTab === 'po'" class="space-y-4">
                            <?php if (!empty($order['po_number'])): ?>
                            <div class="p-4 <?php echo ($order['po_approved'] ?? false) ? 'bg-green-50 border-green-200' : 'bg-yellow-50 border-yellow-200'; ?> rounded-lg border">
                                <div class="flex items-center justify-between mb-2">
                                    <span class="font-semibold <?php echo ($order['po_approved'] ?? false) ? 'text-green-900' : 'text-yellow-900'; ?>">
                                        Purchase Order <?php echo ($order['po_approved'] ?? false) ? 'Approved' : 'Received'; ?>
                                    </span>
                                    <span class="text-sm"><?php echo sanitize($order['po_number']); ?></span>
                                </div>
                                <div class="text-sm">
                                    <span class="text-gray-600">Received:</span>
                                    <span><?php echo $order['po_received_at'] ? date('M j, Y', dbTs($order['po_received_at'])) : '-'; ?></span>
                                </div>
                                <?php if (!empty($order['po_file_path'])): ?>
                                <a href="<?php echo getBasePath() . sanitize($order['po_file_path']); ?>" target="_blank"
                                   class="mt-3 inline-flex items-center gap-2 px-3 py-1.5 bg-gray-600 hover:bg-gray-700 text-white rounded text-sm">
                                    <i class="fa-solid fa-download"></i> Download PO
                                </a>
                                <?php endif; ?>
                                <?php if (!($order['po_approved'] ?? false)): ?>
                                <form method="post" class="inline ml-2">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="action" value="approve_po">
                                    <button type="submit" class="px-3 py-1.5 bg-green-600 hover:bg-green-700 text-white rounded text-sm">
                                        <i class="fa-solid fa-check mr-1"></i> Approve PO
                                    </button>
                                </form>
                                <?php endif; ?>
                            </div>
                            <?php else: ?>
                            <div class="p-4 bg-gray-50 rounded-lg border border-gray-200">
                                <p class="text-gray-600 text-sm mb-4">
                                    <?php echo ($printShop['require_po'] ?? false) ? 'Purchase Order is required for this order.' : 'No Purchase Order received yet.'; ?>
                                </p>
                                <form method="post" enctype="multipart/form-data" class="space-y-4">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="action" value="upload_po">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">PO Number</label>
                                        <input aria-label="PO Number" type="text" name="po_number" placeholder="Customer's PO number"
                                               class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Upload PO Document</label>
                                        <input aria-label="Upload PO Document" type="file" name="po_file" accept=".pdf,.doc,.docx,.jpg,.png"
                                               class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm file:mr-4 file:py-1 file:px-3 file:rounded file:border-0 file:bg-gray-100 file:text-gray-700">
                                    </div>
                                    <button type="submit" class="px-4 py-2 bg-gray-600 hover:bg-gray-700 text-white rounded-lg text-sm font-medium">
                                        <i class="fa-solid fa-upload mr-1"></i> Record PO
                                    </button>
                                </form>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Invoice Tab -->
                        <div x-show="activeTab === 'invoice'" class="space-y-4">
                            <?php if (!empty($order['invoice_number'])): ?>
                            <div class="p-4 <?php echo ($order['invoice_paid'] ?? false) ? 'bg-green-50 border-green-200' : 'bg-yellow-50 border-yellow-200'; ?> rounded-lg border">
                                <div class="flex items-center justify-between mb-2">
                                    <span class="font-semibold <?php echo ($order['invoice_paid'] ?? false) ? 'text-green-900' : 'text-yellow-900'; ?>">
                                        Invoice <?php echo ($order['invoice_paid'] ?? false) ? 'Paid' : 'Issued'; ?>
                                    </span>
                                    <span class="text-sm"><?php echo sanitize($order['invoice_number']); ?></span>
                                </div>
                                <div class="grid grid-cols-1 sm:grid-cols-3 gap-2 sm:gap-4 text-sm">
                                    <div>
                                        <span class="text-gray-600">Amount:</span>
                                        <span class="font-semibold"><?php echo Currency::format($order['invoice_amount'] ?? 0, $currency); ?></span>
                                    </div>
                                    <div>
                                        <span class="text-gray-600">Issued:</span>
                                        <span><?php echo $order['invoice_issued_at'] ? date('M j, Y', dbTs($order['invoice_issued_at'])) : '-'; ?></span>
                                    </div>
                                    <div>
                                        <span class="text-gray-600">Due:</span>
                                        <span><?php echo $order['invoice_due_date'] ? date('M j, Y', dbTs($order['invoice_due_date'])) : '-'; ?></span>
                                    </div>
                                </div>
                                <div class="mt-3 flex gap-2">
                                    <?php if (!empty($order['invoice_file_path'])): ?>
                                    <a href="<?php echo getBasePath() . sanitize($order['invoice_file_path']); ?>" target="_blank"
                                       class="inline-flex items-center gap-2 px-3 py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded text-sm">
                                        <i class="fa-solid fa-download"></i> Download Invoice
                                    </a>
                                    <?php endif; ?>
                                    <?php if (!($order['invoice_paid'] ?? false)): ?>
                                    <!-- Mark as Paid expanded form -->
                                    <div class="mt-4 p-4 bg-gray-50 border border-gray-200 rounded-lg">
                                        <p class="text-sm font-semibold text-gray-700 mb-3"><i class="fa-solid fa-money-bill-wave mr-1 text-green-600"></i>Record Direct Payment</p>
                                        <form method="post" class="space-y-3">
                                            <?php echo csrfField(); ?>
                                            <input type="hidden" name="action" value="mark_paid">
                                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                                <div>
                                                    <label class="block text-xs font-medium text-gray-600 mb-1">Payment Method</label>
                                                    <select aria-label="Payment Method" name="direct_method" required
                                                        class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                                                        <option value="bank_transfer">Bank Transfer</option>
                                                        <option value="cash">Cash</option>
                                                        <option value="cheque">Cheque</option>
                                                        <option value="po">Purchase Order (PO)</option>
                                                        <option value="online">Online (Paymob)</option>
                                                        <option value="credit">Credit Account</option>
                                                    </select>
                                                </div>
                                                <div>
                                                    <label class="block text-xs font-medium text-gray-600 mb-1">Reference / PO Number</label>
                                                    <input aria-label="Reference / PO Number" type="text" name="direct_ref"
                                                        value="<?php echo htmlspecialchars($order['po_number'] ?? ''); ?>"
                                                        placeholder="Bank ref, PO no., cheque no."
                                                        class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                                                </div>
                                            </div>
                                            <div>
                                                <label class="block text-xs font-medium text-gray-600 mb-1">Notes (optional)</label>
                                                <input aria-label="Notes (optional)" type="text" name="direct_notes" placeholder="e.g. Paid by Mohsin Haider Darwish, ref TXN-12345"
                                                    class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                                            </div>
                                            <div class="flex items-center gap-3">
                                                <button type="submit"
                                                    class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg text-sm font-medium transition-colors">
                                                    <i class="fa-solid fa-check mr-1"></i> Mark as Paid
                                                </button>
                                                <?php if (!empty($order['order_number'])): ?>
                                                <span class="text-xs text-gray-400">Will sync to BHD-ERP automatically</span>
                                                <?php endif; ?>
                                            </div>
                                        </form>
                                    </div>
                                    <?php else: ?>
                                    <div class="mt-3 p-3 bg-green-50 border border-green-200 rounded-lg text-sm text-green-800">
                                        <div class="flex items-center gap-2 font-medium">
                                            <i class="fa-solid fa-circle-check"></i> Payment Recorded
                                        </div>
                                        <?php if (!empty($order['payment_method'])): ?>
                                        <div class="mt-1 text-xs text-green-700">
                                            Method: <?php echo ucfirst(str_replace('_', ' ', $order['payment_method'])); ?>
                                            <?php if (!empty($order['direct_payment_ref'])): ?>
                                            &bull; Ref: <?php echo htmlspecialchars($order['direct_payment_ref']); ?>
                                            <?php endif; ?>
                                        </div>
                                        <?php endif; ?>
                                        <?php if (!empty($order['erp_invoice_number'])): ?>
                                        <div class="mt-1 text-xs text-green-600">
                                            <i class="fa-solid fa-link mr-1"></i>ERP: <?php echo htmlspecialchars($order['erp_invoice_number']); ?>
                                        </div>
                                        <?php endif; ?>
                                        <?php if (!empty($order['erp_sync_status']) && $order['erp_sync_status'] === 'error'): ?>
                                        <div class="mt-1 text-xs text-red-600">
                                            <i class="fa-solid fa-triangle-exclamation mr-1"></i>ERP sync failed: <?php echo htmlspecialchars($order['erp_sync_error'] ?? ''); ?>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <span class="text-xs text-green-600 mt-1 block">
                                        Paid on <?php echo $order['invoice_paid_at'] ? date('M j, Y', dbTs($order['invoice_paid_at'])) : '-'; ?>
                                    </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php else: ?>
                            <form method="post" enctype="multipart/form-data" class="space-y-4">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="upload_invoice">
                                <div class="grid md:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Invoice Number</label>
                                        <input aria-label="Invoice Number" type="text" name="invoice_number" placeholder="Auto-generated if empty"
                                               class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Amount (<?php echo $currency; ?>)</label>
                                        <input aria-label="Amount ( )" type="number" name="invoice_amount" step="0.001" value="<?php echo $order['total'] ?? 0; ?>"
                                               class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm">
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Due Date</label>
                                    <input aria-label="Due Date" type="date" name="invoice_due_date" value="<?php echo date('Y-m-d', strtotime('+30 days')); ?>"
                                           class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Upload Invoice PDF</label>
                                    <input aria-label="Upload Invoice PDF" type="file" name="invoice_file" accept=".pdf,.doc,.docx"
                                           class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm file:mr-4 file:py-1 file:px-3 file:rounded file:border-0 file:bg-indigo-50 file:text-indigo-700">
                                </div>
                                <div class="flex gap-2">
                                    <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-sm font-medium">
                                        <i class="fa-solid fa-file-invoice-dollar mr-1"></i> Issue Invoice
                                    </button>
                                    <?php if ($odoo->isEnabled()): ?>
                                    <button type="submit" name="action" value="create_odoo_invoice" 
                                            class="px-4 py-2 bg-orange-600 hover:bg-orange-700 text-white rounded-lg text-sm font-medium">
                                        <i class="fa-solid fa-plug mr-1"></i> Create in Odoo
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </form>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Delivery Note Tab -->
                        <div x-show="activeTab === 'delivery'" class="space-y-4">
                            <?php if (!empty($order['delivery_note_number'])): ?>
                            <div class="p-4 bg-cyan-50 rounded-lg border border-cyan-200">
                                <div class="flex items-center justify-between mb-2">
                                    <span class="font-semibold text-cyan-900">Delivery Note Issued</span>
                                    <span class="text-sm text-cyan-700"><?php echo sanitize($order['delivery_note_number']); ?></span>
                                </div>
                                <div class="text-sm">
                                    <span class="text-cyan-700">Issued:</span>
                                    <span><?php echo $order['delivery_note_issued_at'] ? date('M j, Y', dbTs($order['delivery_note_issued_at'])) : '-'; ?></span>
                                </div>
                                <?php if (!empty($order['delivery_note_file_path'])): ?>
                                <a href="<?php echo getBasePath() . sanitize($order['delivery_note_file_path']); ?>" target="_blank"
                                   class="mt-3 inline-flex items-center gap-2 px-3 py-1.5 bg-cyan-600 hover:bg-cyan-700 text-white rounded text-sm">
                                    <i class="fa-solid fa-download"></i> Download Delivery Note
                                </a>
                                <?php endif; ?>
                            </div>
                            <?php else: ?>
                            <form method="post" enctype="multipart/form-data" class="space-y-4">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="upload_delivery_note">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Delivery Note Number</label>
                                    <input aria-label="Delivery Note Number" type="text" name="delivery_note_number" placeholder="Auto-generated if empty"
                                           class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Upload Delivery Note PDF</label>
                                    <input aria-label="Upload Delivery Note PDF" type="file" name="delivery_note_file" accept=".pdf,.doc,.docx"
                                           class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm file:mr-4 file:py-1 file:px-3 file:rounded file:border-0 file:bg-cyan-50 file:text-cyan-700">
                                </div>
                                <button type="submit" class="px-4 py-2 bg-cyan-600 hover:bg-cyan-700 text-white rounded-lg text-sm font-medium">
                                    <i class="fa-solid fa-truck-fast mr-1"></i> Issue Delivery Note
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                        
                        <?php if ($odoo->isEnabled()): ?>
                        <!-- Odoo Sync Status -->
                        <div class="pt-4 border-t border-gray-200">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-2 text-sm">
                                    <span class="w-2 h-2 rounded-full <?php echo ($order['erp_sync_status'] ?? 'none') === 'synced' ? 'bg-green-500' : 'bg-gray-300'; ?>"></span>
                                    <span class="text-gray-600">
                                        <?php if (($order['erp_sync_status'] ?? 'none') === 'synced'): ?>
                                            Synced to Odoo
                                            <?php if (!empty($order['erp_order_id'])): ?>
                                            (ID: <?php echo sanitize($order['erp_order_id']); ?>)
                                            <?php endif; ?>
                                        <?php else: ?>
                                            Not synced to Odoo
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <form method="post" class="inline">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="action" value="sync_odoo">
                                    <button type="submit" class="px-3 py-1.5 bg-orange-100 hover:bg-orange-200 text-orange-700 rounded text-sm font-medium">
                                        <i class="fa-solid fa-sync mr-1"></i> Sync Now
                                    </button>
                                </form>
                            </div>
                            <?php if (!empty($order['erp_last_sync'])): ?>
                            <p class="text-xs text-gray-500 mt-1">Last sync: <?php echo date('M j, Y g:i A', dbTs($order['erp_last_sync'])); ?></p>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Sidebar -->
            <div class="space-y-6">
                
                <!-- Update Status -->
                <form method="post" class="bg-white rounded-xl border border-gray-200 overflow-hidden">
                    <?php echo csrfField(); ?>
                    <div class="p-6 border-b border-gray-100">
                        <h3 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
                            <i class="fa-solid fa-pen text-amber-600"></i>
                            Update Order
                        </h3>
                    </div>
                    <div class="p-6 space-y-4">
                        <input type="hidden" name="action" value="update_status">
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Status</label>
                            <select aria-label="Status" name="status" class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                                <option value="pending" <?php echo $order['status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="processing" <?php echo $order['status'] === 'processing' ? 'selected' : ''; ?>>Processing</option>
                                <option value="printing" <?php echo $order['status'] === 'printing' ? 'selected' : ''; ?>>Printing</option>
                                <option value="shipped" <?php echo $order['status'] === 'shipped' ? 'selected' : ''; ?>>Shipped</option>
                                <option value="delivered" <?php echo $order['status'] === 'delivered' ? 'selected' : ''; ?>>Delivered</option>
                                <option value="cancelled" <?php echo $order['status'] === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Tracking Number</label>
                            <input aria-label="Tracking Number" type="text" name="tracking_number" value="<?php echo sanitize($order['tracking_number'] ?? ''); ?>"
                                   class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20"
                                   placeholder="Enter tracking number">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Internal Notes</label>
                            <textarea aria-label="Internal Notes" name="internal_notes" rows="3"
                                      class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20"
                                      placeholder="Notes for your reference..."><?php echo sanitize($order['notes'] ?? ''); ?></textarea>
                        </div>
                        
                        <button type="submit" class="w-full py-3 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-lg transition-colors">
                            Update Order
                        </button>
                    </div>
                </form>
                
                <!-- Customer Info -->
                <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
                    <div class="p-6 border-b border-gray-100">
                        <h3 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
                            <i class="fa-solid fa-building text-indigo-600"></i>
                            Customer
                        </h3>
                    </div>
                    <div class="p-6 space-y-4">
                        <div>
                            <p class="text-sm text-gray-500">Company</p>
                            <p class="font-semibold text-gray-900"><?php echo sanitize($order['company_name'] ?? 'Unknown'); ?></p>
                        </div>
                        
                        <?php if (!empty($order['employee_name'])): ?>
                        <div>
                            <p class="text-sm text-gray-500">Employee</p>
                            <p class="font-medium text-gray-900"><?php echo sanitize($order['employee_name']); ?></p>
                            <?php if (!empty($order['employee_position'])): ?>
                            <p class="text-sm text-gray-600"><?php echo sanitize($order['employee_position']); ?></p>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($order['company_email']): ?>
                        <div>
                            <p class="text-sm text-gray-500">Contact</p>
                            <a href="mailto:<?php echo sanitize($order['company_email']); ?>" class="text-blue-600 hover:text-blue-700">
                                <?php echo sanitize($order['company_email']); ?>
                            </a>
                            <?php if ($order['company_phone']): ?>
                            <p class="text-gray-600"><?php echo sanitize($order['company_phone']); ?></p>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Order Timeline -->
                <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
                    <div class="p-6 border-b border-gray-100">
                        <h3 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
                            <i class="fa-solid fa-clock-rotate-left text-gray-600"></i>
                            Timeline
                        </h3>
                    </div>
                    <div class="p-6">
                        <div class="space-y-4">
                            <div class="flex items-start gap-3">
                                <div class="w-2 h-2 bg-green-500 rounded-full mt-2"></div>
                                <div>
                                    <p class="text-sm font-medium text-gray-900">Order Created</p>
                                    <p class="text-xs text-gray-500"><?php echo date('M j, Y g:i A', dbTs($order['created_at'])); ?></p>
                                </div>
                            </div>
                            <?php if (!empty($order['updated_at']) && $order['updated_at'] !== $order['created_at']): ?>
                            <div class="flex items-start gap-3">
                                <div class="w-2 h-2 bg-blue-500 rounded-full mt-2"></div>
                                <div>
                                    <p class="text-sm font-medium text-gray-900">Last Updated</p>
                                    <p class="text-xs text-gray-500"><?php echo date('M j, Y g:i A', dbTs($order['updated_at'])); ?></p>
                                </div>
                            </div>
                            <?php endif; ?>
                            <?php if ($order['status'] === 'shipped' || $order['status'] === 'delivered'): ?>
                            <div class="flex items-start gap-3">
                                <div class="w-2 h-2 bg-cyan-500 rounded-full mt-2"></div>
                                <div>
                                    <p class="text-sm font-medium text-gray-900">Shipped</p>
                                    <?php if (!empty($order['tracking_number'])): ?>
                                    <p class="text-xs text-gray-500">Tracking: <?php echo sanitize($order['tracking_number']); ?></p>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Print Ready Modal -->
<div id="printReadyModal" class="fixed inset-0 z-50 hidden overflow-y-auto" aria-modal="true">
    <div class="flex min-h-screen items-center justify-center p-4">
        <div class="fixed inset-0 bg-gray-900/50 transition-opacity" data-cardify-action="call" data-fn="closePrintReadyModal"></div>
        
        <div class="relative w-full max-w-4xl bg-white rounded-2xl shadow-2xl">
            <!-- Header -->
            <div class="flex items-center justify-between p-6 border-b border-gray-200">
                <div>
                    <h3 class="text-xl font-bold text-gray-900">
                        <i class="fa-solid fa-print text-purple-600 mr-2"></i>
                        Generate Print-Ready Sheet
                    </h3>
                    <p class="text-sm text-gray-500 mt-1">Create A4 PDF with cutting marks for professional printing</p>
                </div>
                <button type="button" data-cardify-action="call" data-fn="closePrintReadyModal" aria-label="Close print-ready modal" title="Close" class="p-2 hover:bg-gray-100 rounded-lg transition-colors">
                    <i class="fa-solid fa-times text-gray-400" aria-hidden="true"></i>
                </button>
            </div>
            
            <!-- Body -->
            <div class="p-6">
                <!-- Settings Row -->
                <div class="grid md:grid-cols-3 gap-6 mb-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Paper Size</label>
                        <select aria-label="Paper Size" id="printPaperSize" data-cardify-change-fn="updatePrintPreview" 
                                class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-purple-500 focus:border-purple-500">
                            <option value="A4" selected>A4 (210×297mm)</option>
                            <option value="A3">A3 (297×420mm)</option>
                            <option value="Letter">Letter (8.5×11")</option>
                            <option value="Legal">Legal (8.5×14")</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Card Size (Auto-detected)</label>
                        <div class="px-4 py-2.5 bg-gray-100 border border-gray-300 rounded-lg text-gray-700">
                            <i class="fa-solid fa-ruler-combined mr-2 text-purple-500"></i>
                            <span id="detectedCardSize">Detecting...</span>
                        </div>
                        <p class="text-xs text-gray-500 mt-1">From card template</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Scale: <span id="scaleValue">100</span>%
                        </label>
                        <input aria-label="Scale: 100 %" type="range" id="printScale" min="90" max="110" value="100" step="1"
                               data-cardify-change-fns="[&quot;updateScaleValue&quot;, &quot;updatePrintPreview&quot;]"
                               class="w-full h-2 bg-gray-200 rounded-lg appearance-none cursor-pointer accent-purple-600">
                        <div class="flex justify-between text-xs text-gray-400 mt-1">
                            <span>90%</span>
                            <span>100%</span>
                            <span>110%</span>
                        </div>
                    </div>
                </div>
                
                <!-- Layout Preview -->
                <div class="bg-gray-50 rounded-xl p-6 mb-6">
                    <div class="flex items-start justify-between mb-4">
                        <div>
                            <h4 class="font-semibold text-gray-900">Layout Preview</h4>
                            <p id="layoutInfo" class="text-sm text-gray-500 mt-1">Loading...</p>
                        </div>
                        <div class="text-right">
                            <p class="text-2xl font-bold text-purple-600" id="cardsPerSheet">--</p>
                            <p class="text-sm text-gray-500">cards per sheet</p>
                        </div>
                    </div>
                    
                    <!-- Visual Preview -->
                    <div class="flex flex-col sm:flex-row justify-center items-center gap-4 sm:gap-6">
                        <!-- Front Sheet Preview -->
                        <div>
                            <p class="text-xs font-medium text-gray-500 text-center mb-2">FRONT SIDE</p>
                            <div id="frontSheetPreview" class="bg-white border-2 border-gray-300 rounded shadow-inner"
                                 style="width: 180px; height: 255px; position: relative;">
                                <!-- Cards will be placed here by JS -->
                            </div>
                        </div>
                        <!-- Back Sheet Preview -->
                        <div>
                            <p class="text-xs font-medium text-gray-500 text-center mb-2">BACK SIDE</p>
                            <div id="backSheetPreview" class="bg-white border-2 border-gray-300 rounded shadow-inner"
                                 style="width: 180px; height: 255px; position: relative;">
                                <!-- Cards will be placed here by JS -->
                            </div>
                        </div>
                    </div>
                    
                    <!-- Legend -->
                    <div class="flex justify-center gap-6 mt-4 text-xs text-gray-500">
                        <span><span class="inline-block w-3 h-3 bg-blue-100 border border-blue-400 rounded mr-1"></span> Card area</span>
                        <span><span class="inline-block w-3 h-0.5 bg-black mr-1"></span> Crop marks</span>
                    </div>
                </div>
                
                <!-- Order Info -->
                <div class="bg-blue-50 rounded-xl p-4 mb-6">
                    <div class="flex items-center gap-3">
                        <i class="fa-solid fa-info-circle text-blue-600"></i>
                        <div>
                            <p class="font-medium text-blue-900">Order #<?php echo $orderId; ?> - <?php echo (int)$order['quantity']; ?> cards</p>
                            <p class="text-sm text-blue-700">
                                Sheets needed: <span id="sheetsNeeded">--</span> 
                                (Front: <span id="frontSheets">--</span>, Back: <span id="backSheets">--</span>)
                            </p>
                        </div>
                    </div>
                </div>
                
                <!-- Generate Buttons -->
                <div class="flex flex-wrap gap-3">
                    <button type="button" data-cardify-action="call" data-fn="generatePrintReadyPDF" data-args="[&quot;both&quot;]" 
                            class="flex-1 inline-flex items-center justify-center gap-2 px-6 py-3 bg-purple-600 hover:bg-purple-700 text-white rounded-lg font-semibold transition-colors">
                        <i class="fa-solid fa-file-pdf"></i>
                        Generate PDF (Front + Back)
                    </button>
                    <button type="button" data-cardify-action="call" data-fn="generatePrintReadyPDF" data-args="[&quot;front&quot;]" 
                            class="inline-flex items-center justify-center gap-2 px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition-colors">
                        <i class="fa-solid fa-image"></i>
                        Front Only
                    </button>
                    <button type="button" data-cardify-action="call" data-fn="generatePrintReadyPDF" data-args="[&quot;back&quot;]" 
                            class="inline-flex items-center justify-center gap-2 px-6 py-3 bg-gray-600 hover:bg-gray-700 text-white rounded-lg font-medium transition-colors">
                        <i class="fa-solid fa-image"></i>
                        Back Only
                    </button>
                </div>
            </div>
            
            <!-- Footer -->
            <div class="px-6 py-4 bg-gray-50 rounded-b-2xl border-t border-gray-200">
                <p class="text-xs text-gray-500 text-center">
                    <i class="fa-solid fa-lightbulb mr-1"></i>
                    The generated PDF includes crop marks for precise cutting. Print at 100% scale without "Fit to Page" for accurate sizing.
                </p>
            </div>
        </div>
    </div>
</div>

<script<?= cspNonceAttr() ?>>
// Order data
const orderData = {
    id: <?php echo $orderId; ?>,
    quantity: <?php echo (int)$order['quantity']; ?>,
    frontUrl: '<?php echo !empty($frontDownload) ? getBasePath() . ltrim(sanitize($frontDownload), '/') : ''; ?>',
    backUrl: '<?php echo !empty($backDownload) ? getBasePath() . ltrim(sanitize($backDownload), '/') : ''; ?>',
    frontHqUrl: '<?php echo $hasFrontHq ? getBasePath() . ltrim(sanitize($frontHq), '/') : ''; ?>',
    backHqUrl: '<?php echo $hasBackHq ? getBasePath() . ltrim(sanitize($backHq), '/') : ''; ?>',
    frontImagePath: '<?php echo !empty($frontUrl) ? ltrim($frontUrl, '/') : ''; ?>'
};

// Paper sizes in mm
const paperSizes = {
    'A4': { width: 210, height: 297 },
    'A3': { width: 297, height: 420 },
    'Letter': { width: 215.9, height: 279.4 },
    'Legal': { width: 215.9, height: 355.6 }
};

// Layout settings (optimized for maximum cards per sheet)
const MARGIN = 5;       // mm
const CARD_GAP = 3;     // mm

let currentLayout = null;
// Card size from template (will be fetched from server)
let detectedCardSize = { width: 85, height: 55 };

function openPrintReadyModal() {
    document.getElementById('printReadyModal').classList.remove('hidden');
    document.getElementById('detectedCardSize').innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-1"></i> Loading from template...';
    
    // Fetch card size from template settings via API
    fetchCardSizeFromTemplate();
}

function closePrintReadyModal() {
    document.getElementById('printReadyModal').classList.add('hidden');
}

function updateScaleValue() {
    const scale = document.getElementById('printScale').value;
    document.getElementById('scaleValue').textContent = scale;
}

// Fetch card size from the order's company template settings
async function fetchCardSizeFromTemplate() {
    try {
        const response = await fetch(`<?php echo getBasePath(); ?>api/print-ready.php?action=detect&order_id=${orderData.id}`);
        const result = await response.json();
        
        if (result.success && result.detected) {
            detectedCardSize = {
                width: result.detected.width,
                height: result.detected.height
            };
            const sizeName = result.detected.name || `${detectedCardSize.width}×${detectedCardSize.height}mm`;
            document.getElementById('detectedCardSize').innerHTML = `<i class="fa-solid fa-check-circle text-green-500 mr-1"></i> ${sizeName}`;
        } else {
            // Default to EU standard
            detectedCardSize = { width: 85, height: 55 };
            document.getElementById('detectedCardSize').innerHTML = `<i class="fa-solid fa-check-circle text-green-500 mr-1"></i> European (85×55mm)`;
        }
    } catch (error) {
        console.error('Error fetching card size:', error);
        detectedCardSize = { width: 85, height: 55 };
        document.getElementById('detectedCardSize').innerHTML = `<i class="fa-solid fa-check-circle text-green-500 mr-1"></i> European (85×55mm)`;
    }
    
    updatePrintPreview();
}

function getCardDimensions() {
    return detectedCardSize;
}

function calculateLayout() {
    const paperSize = document.getElementById('printPaperSize').value;
    const paper = paperSizes[paperSize];
    const card = getCardDimensions();
    
    const availWidth = paper.width - (2 * MARGIN);
    const availHeight = paper.height - (2 * MARGIN);
    
    // Calculate both orientations
    const cardSlotW = card.width + CARD_GAP;
    const cardSlotH = card.height + CARD_GAP;
    
    const cols = Math.floor(availWidth / cardSlotW);
    const rows = Math.floor(availHeight / cardSlotH);
    const total = cols * rows;
    
    // Try rotated
    const colsRot = Math.floor(availWidth / cardSlotH);
    const rowsRot = Math.floor(availHeight / cardSlotW);
    const totalRot = colsRot * rowsRot;
    
    if (totalRot > total) {
        return {
            cols: colsRot,
            rows: rowsRot,
            total: totalRot,
            rotated: true,
            cardWidth: card.height,
            cardHeight: card.width,
            paper: paper
        };
    }
    
    return {
        cols: cols,
        rows: rows,
        total: total,
        rotated: false,
        cardWidth: card.width,
        cardHeight: card.height,
        paper: paper
    };
}

function updatePrintPreview() {
    currentLayout = calculateLayout();
    const layout = currentLayout;
    
    // Update text info
    document.getElementById('cardsPerSheet').textContent = layout.total;
    document.getElementById('layoutInfo').textContent = 
        `${layout.cols} × ${layout.rows} layout (${layout.cardWidth}×${layout.cardHeight}mm cards${layout.rotated ? ', rotated' : ''})`;
    
    // Calculate sheets needed
    const sheetsPerSide = Math.ceil(orderData.quantity / layout.total);
    document.getElementById('sheetsNeeded').textContent = sheetsPerSide * 2;
    document.getElementById('frontSheets').textContent = sheetsPerSide;
    document.getElementById('backSheets').textContent = sheetsPerSide;
    
    // Update visual previews
    updateSheetPreview('frontSheetPreview', layout, orderData.frontUrl);
    updateSheetPreview('backSheetPreview', layout, orderData.backUrl);
}

function updateSheetPreview(containerId, layout, imageUrl) {
    const container = document.getElementById(containerId);
    const containerWidth = 180;
    const containerHeight = 255;
    
    // Scale factor from mm to preview pixels
    const scaleX = containerWidth / layout.paper.width;
    const scaleY = containerHeight / layout.paper.height;
    const scale = Math.min(scaleX, scaleY);
    
    // Calculate card positions
    const totalCardsWidth = (layout.cols * layout.cardWidth) + ((layout.cols - 1) * CARD_GAP);
    const totalCardsHeight = (layout.rows * layout.cardHeight) + ((layout.rows - 1) * CARD_GAP);
    const startX = (layout.paper.width - totalCardsWidth) / 2;
    const startY = (layout.paper.height - totalCardsHeight) / 2;
    
    let html = '';
    
    for (let row = 0; row < layout.rows; row++) {
        for (let col = 0; col < layout.cols; col++) {
            const x = startX + (col * (layout.cardWidth + CARD_GAP));
            const y = startY + (row * (layout.cardHeight + CARD_GAP));
            
            // Trim position (where crop marks go)
            const px = x * scale;
            const py = y * scale;
            const pw = layout.cardWidth * scale;
            const ph = layout.cardHeight * scale;
            
            // Get scale factor from slider (100 = exact fit, >100 = zoom in)
            const imgScale = (document.getElementById('printScale')?.value || 100) / 100;
            const imgW = pw * imgScale;
            const imgH = ph * imgScale;
            const imgX = px + (pw - imgW) / 2;
            const imgY = py + (ph - imgH) / 2;
            
            // Card image (scaled based on slider)
            html += `<div style="position:absolute; left:${imgX}px; top:${imgY}px; 
                              width:${imgW}px; height:${imgH}px; 
                              background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%); 
                              border-radius: 2px;"></div>`;
            
            // Trim line indicator (dashed box showing where to cut - always at card size)
            html += `<div style="position:absolute; left:${px}px; top:${py}px; width:${pw}px; height:${ph}px; 
                              border: 1px dashed #ef4444; box-sizing: border-box;"></div>`;
            
            // Crop marks - corner marks (longer outside 4mm, shorter inside 1mm)
            const outLen = 4 * scale; // 4mm outside
            const inLen = 1 * scale;  // 1mm inside
            
            // Top-left corner
            html += `<div style="position:absolute; left:${px - outLen}px; top:${py}px; width:${outLen + inLen}px; height:1px; background:#000;"></div>`;
            html += `<div style="position:absolute; left:${px}px; top:${py - outLen}px; width:1px; height:${outLen + inLen}px; background:#000;"></div>`;
            
            // Top-right corner
            html += `<div style="position:absolute; left:${px + pw - inLen}px; top:${py}px; width:${outLen + inLen}px; height:1px; background:#000;"></div>`;
            html += `<div style="position:absolute; left:${px + pw}px; top:${py - outLen}px; width:1px; height:${outLen + inLen}px; background:#000;"></div>`;
            
            // Bottom-left corner
            html += `<div style="position:absolute; left:${px - outLen}px; top:${py + ph}px; width:${outLen + inLen}px; height:1px; background:#000;"></div>`;
            html += `<div style="position:absolute; left:${px}px; top:${py + ph - inLen}px; width:1px; height:${outLen + inLen}px; background:#000;"></div>`;
            
            // Bottom-right corner
            html += `<div style="position:absolute; left:${px + pw - inLen}px; top:${py + ph}px; width:${outLen + inLen}px; height:1px; background:#000;"></div>`;
            html += `<div style="position:absolute; left:${px + pw}px; top:${py + ph - inLen}px; width:1px; height:${outLen + inLen}px; background:#000;"></div>`;
        }
    }
    
    container.innerHTML = html;
}

async function generatePrintReadyPDF(side = 'both') {
    const paperSize = document.getElementById('printPaperSize').value;
    const scale = document.getElementById('printScale').value;
    
    // Progress indicator
    const btn = event.target;
    const originalText = btn.innerHTML;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-2"></i>Generating PDF...';
    btn.disabled = true;
    
    try {
        // Call server-side API to generate PDF using TCPDF
        // Card size is auto-detected server-side from the template
        const formData = new FormData();
        formData.append('action', 'generate');
        formData.append('order_id', orderData.id);
        formData.append('side', side);
        formData.append('paper', paperSize);
        formData.append('quantity', orderData.quantity);
        formData.append('scale', scale);
        
        const response = await fetch('<?php echo getBasePath(); ?>api/print-ready.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            // Download the generated PDF
            const a = document.createElement('a');
            a.href = result.download_url;
            a.download = result.filename;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            
            // Update detected size display if returned
            if (result.detected_size) {
                detectedCardSize = result.detected_size;
                document.getElementById('detectedCardSize').innerHTML = 
                    `<i class="fa-solid fa-check-circle text-green-500 mr-1"></i> ${detectedCardSize.width}×${detectedCardSize.height}mm`;
            }
            
            // Show success message
            showNotification(`Print-ready PDF generated! (${result.cards_per_sheet} cards per sheet)`, 'success');
        } else {
            throw new Error(result.error || 'PDF generation failed');
        }
        
    } catch (error) {
        console.error('PDF generation error:', error);
        alert('Error generating PDF: ' + error.message);
    } finally {
        btn.innerHTML = originalText;
        btn.disabled = false;
    }
}

function showNotification(message, type = 'info') {
    // Create notification element
    const notification = document.createElement('div');
    notification.className = `fixed bottom-4 right-4 px-6 py-3 rounded-lg shadow-lg z-50 transition-all transform ${
        type === 'success' ? 'bg-green-600 text-white' : 
        type === 'error' ? 'bg-red-600 text-white' : 
        'bg-blue-600 text-white'
    }`;
    notification.innerHTML = `<i class="fa-solid ${type === 'success' ? 'fa-check-circle' : type === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle'} mr-2"></i>${message}`;
    document.body.appendChild(notification);
    
    // Remove after 3 seconds
    setTimeout(() => {
        notification.style.opacity = '0';
        setTimeout(() => notification.remove(), 300);
    }, 3000);
}

function downloadAllFiles() {
    // Use highest quality versions available (PDF > HQ PNG > Standard PNG)
    const files = [
        <?php if ($hasFrontPdf): ?>
        { url: '<?php echo getBasePath() . ltrim(sanitize($frontPdf), '/'); ?>', name: 'card-front-order-<?php echo $orderId; ?>.pdf' },
        <?php elseif (!empty($frontDownload)): ?>
        { url: '<?php echo getBasePath() . ltrim(sanitize($frontDownload), '/'); ?>', name: 'card-front-order-<?php echo $orderId; ?>.png' },
        <?php endif; ?>
        <?php if ($hasBackPdf): ?>
        { url: '<?php echo getBasePath() . ltrim(sanitize($backPdf), '/'); ?>', name: 'card-back-order-<?php echo $orderId; ?>.pdf' },
        <?php elseif (!empty($backDownload)): ?>
        { url: '<?php echo getBasePath() . ltrim(sanitize($backDownload), '/'); ?>', name: 'card-back-order-<?php echo $orderId; ?>.png' },
        <?php endif; ?>
    ];
    
    files.forEach((file, index) => {
        setTimeout(() => {
            const a = document.createElement('a');
            a.href = file.url;
            a.download = file.name;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
        }, index * 500);
    });
}
</script>
</div>
<?php printshopFooter(); ?>
