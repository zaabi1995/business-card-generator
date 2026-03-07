<?php
/**
 * Print Status Webhook - Receives order status updates from print shops
 * 
 * Authentication: Requires X-Webhook-Signature header with HMAC-SHA256 of body.
 * Signature format: sha256=<hex_digest>
 * Secret is per-print-shop (webhook_secret column) or global (PRINT_WEBHOOK_SECRET config).
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/PrintShopIntegration.php';

// Set JSON response header
header('Content-Type: application/json');

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Get raw POST data
$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON payload']);
    exit;
}

// Log webhook for debugging
error_log("Print Webhook received: " . substr($rawInput, 0, 500));

// Validate required fields
$orderId = $data['order_id'] ?? $data['external_order_id'] ?? null;
$status = $data['status'] ?? null;
$trackingNumber = $data['tracking_number'] ?? $data['tracking'] ?? null;

if (!$orderId || !$status) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing order_id or status']);
    exit;
}

// --- Webhook Signature Verification ---
$signatureHeader = $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '';

// Find order and its print shop to get the webhook secret
$db = Database::getInstance();
$pdo = $db->getConnection();

try {
    // Fetch order with print shop's webhook_secret
    $stmt = $pdo->prepare(
        "SELECT po.id, po.print_shop_id, ps.webhook_secret 
         FROM print_orders po 
         LEFT JOIN print_shops ps ON po.print_shop_id = ps.id 
         WHERE po.id = ? OR po.external_order_id = ?"
    );
    $stmt->execute([$orderId, $orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        http_response_code(404);
        echo json_encode(['error' => 'Order not found']);
        exit;
    }
    
    // Determine the webhook secret: per-shop first, then global fallback
    $webhookSecret = !empty($order['webhook_secret']) ? $order['webhook_secret'] : null;
    if (!$webhookSecret && defined('PRINT_WEBHOOK_SECRET') && !empty(PRINT_WEBHOOK_SECRET)) {
        $webhookSecret = PRINT_WEBHOOK_SECRET;
    }
    
    // If a secret is configured, verify the signature
    if ($webhookSecret) {
        if (empty($signatureHeader)) {
            http_response_code(401);
            echo json_encode(['error' => 'Missing X-Webhook-Signature header']);
            exit;
        }
        
        // Expected format: sha256=<hex>
        $expectedSignature = 'sha256=' . hash_hmac('sha256', $rawInput, $webhookSecret);
        
        if (!hash_equals($expectedSignature, $signatureHeader)) {
            error_log("Print Webhook: Invalid signature for order {$orderId}");
            http_response_code(401);
            echo json_encode(['error' => 'Invalid webhook signature']);
            exit;
        }
    } else {
        // No secret configured — reject unauthenticated webhooks
        error_log("Print Webhook: No webhook secret configured for order {$orderId} (print_shop_id: " . ($order['print_shop_id'] ?? 'null') . "). Rejecting.");
        http_response_code(401);
        echo json_encode(['error' => 'Webhook authentication not configured. Set a webhook secret in print shop settings or PRINT_WEBHOOK_SECRET in config.']);
        exit;
    }
    
    // --- Signature verified, process the webhook ---
    
    // Map external status to our status
    $statusMap = [
        'pending' => 'pending',
        'received' => 'submitted',
        'submitted' => 'submitted',
        'accepted' => 'processing',
        'processing' => 'processing',
        'in_production' => 'printing',
        'printing' => 'printing',
        'printed' => 'printing',
        'shipped' => 'shipped',
        'in_transit' => 'shipped',
        'out_for_delivery' => 'shipped',
        'delivered' => 'delivered',
        'completed' => 'delivered',
        'cancelled' => 'cancelled',
        'failed' => 'cancelled',
        'refunded' => 'cancelled'
    ];
    
    $mappedStatus = $statusMap[strtolower($status)] ?? $status;
    
    // Update order status
    $result = PrintShopIntegration::updateOrderStatus($order['id'], $mappedStatus, $trackingNumber);
    
    if ($result['success']) {
        echo json_encode([
            'success' => true,
            'message' => 'Order status updated',
            'order_id' => $order['id'],
            'status' => $mappedStatus
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => $result['error'] ?? 'Failed to update order']);
    }
    
} catch (PDOException $e) {
    error_log("Print Webhook error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
}
