<?php
/**
 * Print Orders Management - Cardify
 * View and manage all print orders
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/PrintShopIntegration.php';
require_once INCLUDES_DIR . '/Currency.php';
require_once INCLUDES_DIR . '/admin-layout.php';

Auth::requireRole('super_admin');

$db = Database::getInstance();
$message = null;
$messageType = 'success';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = 'Invalid request. Please try again.';
        $messageType = 'error';
    }
    $action = $_POST['action'] ?? '';
    $orderId = (int)($_POST['order_id'] ?? 0);
    
    if ($messageType === 'error') {
        // CSRF failed, skip processing
    } elseif ($action === 'update_status' && $orderId > 0) {
        $newStatus = $_POST['status'] ?? '';
        $trackingNumber = trim($_POST['tracking_number'] ?? '');
        
        $result = PrintShopIntegration::updateOrderStatus($orderId, $newStatus, $trackingNumber ?: null);
        
        if ($result['success']) {
            $message = "Order #$orderId status updated to " . ucfirst($newStatus);
            $messageType = 'success';
        } else {
            $message = "Error updating order: " . ($result['error'] ?? 'Unknown error');
            $messageType = 'error';
        }
    }
}

// Get filter
$statusFilter = $_GET['status'] ?? '';
$orders = PrintShopIntegration::getAllOrders(100, $statusFilter ?: null);

// Status colors
$statusColors = [
    'pending' => 'bg-gray-100 text-gray-700',
    'submitted' => 'bg-blue-100 text-blue-700',
    'processing' => 'bg-purple-100 text-purple-700',
    'printing' => 'bg-amber-100 text-amber-700',
    'shipped' => 'bg-cyan-100 text-cyan-700',
    'delivered' => 'bg-green-100 text-green-700',
    'cancelled' => 'bg-red-100 text-red-700'
];

adminHeader('Print Orders', 'print_orders');
?>

<!-- Alert Message -->
<?php if ($message): ?>
<div class="mb-6 p-4 rounded-xl flex items-center gap-3 <?php echo $messageType === 'success' ? 'bg-green-50 border border-green-200 text-green-700' : 'bg-red-50 border border-red-200 text-red-700'; ?>">
    <i class="<?php echo $messageType === 'success' ? 'fa-solid fa-circle-check' : 'fa-solid fa-circle-exclamation'; ?>"></i>
    <?php echo sanitize($message); ?>
</div>
<?php endif; ?>

<!-- Stats -->
<div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-4 mb-6">
    <?php
    $statuses = ['pending', 'submitted', 'processing', 'printing', 'shipped', 'delivered', 'cancelled'];
    foreach ($statuses as $status):
        $count = count(array_filter($orders, fn($o) => $o['status'] === $status));
    ?>
    <a href="?status=<?php echo $status; ?>" 
       class="p-4 rounded-xl border <?php echo $statusFilter === $status ? 'border-blue-500 bg-blue-50' : 'border-gray-200 bg-white hover:border-gray-300'; ?> transition-all">
        <p class="text-2xl font-bold text-gray-900"><?php echo $count; ?></p>
        <p class="text-xs text-gray-500 capitalize"><?php echo $status; ?></p>
    </a>
    <?php endforeach; ?>
</div>

<!-- Filter Bar -->
<div class="bg-white rounded-xl border border-gray-200 p-4 mb-6 flex items-center justify-between">
    <div class="flex items-center gap-2">
        <a href="print_orders.php" class="px-3 py-1.5 rounded-lg text-sm font-medium <?php echo empty($statusFilter) ? 'bg-blue-100 text-blue-700' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'; ?>">
            All Orders
        </a>
        <?php foreach (['pending', 'processing', 'shipped'] as $st): ?>
        <a href="?status=<?php echo $st; ?>" class="px-3 py-1.5 rounded-lg text-sm font-medium <?php echo $statusFilter === $st ? 'bg-blue-100 text-blue-700' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'; ?>">
            <?php echo ucfirst($st); ?>
        </a>
        <?php endforeach; ?>
    </div>
    
    <a href="print_settings.php" class="text-sm text-blue-600 hover:text-blue-700 font-medium flex items-center gap-1">
        <i class="fa-solid fa-cog"></i>
        Settings
    </a>
</div>

<!-- Orders Table -->
<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
    <?php if (empty($orders)): ?>
    <div class="p-12 text-center">
        <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
            <i class="fa-solid fa-box-open text-gray-400 text-2xl"></i>
        </div>
        <h3 class="text-lg font-medium text-gray-900 mb-2">No Print Orders</h3>
        <p class="text-gray-500">Orders will appear here when employees or admins place print orders.</p>
    </div>
    <?php else: ?>
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead class="bg-gray-50 border-b border-gray-200">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Order</th>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Company</th>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Employee</th>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Details</th>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Total</th>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Status</th>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Date</th>
                    <th class="px-6 py-3 text-right text-xs font-semibold text-gray-500 uppercase">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                <?php foreach ($orders as $order): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-6 py-4">
                        <span class="font-semibold text-gray-900">#<?php echo $order['id']; ?></span>
                        <?php if ($order['external_order_id']): ?>
                        <span class="block text-xs text-gray-500">Ext: <?php echo sanitize($order['external_order_id']); ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4 text-sm text-gray-600">
                        <?php echo sanitize($order['company_name'] ?? 'N/A'); ?>
                    </td>
                    <td class="px-6 py-4 text-sm text-gray-600">
                        <?php echo sanitize(trim(($order['first_name'] ?? '') . ' ' . ($order['last_name'] ?? '')) ?: 'N/A'); ?>
                    </td>
                    <td class="px-6 py-4">
                        <span class="text-sm text-gray-900"><?php echo $order['quantity']; ?> cards</span>
                        <span class="block text-xs text-gray-500"><?php echo ucfirst($order['paper_type']); ?> / <?php echo ucfirst(str_replace('_', ' ', $order['finish'])); ?></span>
                    </td>
                    <td class="px-6 py-4">
                        <span class="font-semibold text-gray-900"><?php echo Currency::formatHtml($order['total'], 'OMR', 'sm'); ?></span>
                    </td>
                    <td class="px-6 py-4">
                        <span class="inline-flex px-2.5 py-1 rounded-full text-xs font-medium <?php echo $statusColors[$order['status']] ?? 'bg-gray-100 text-gray-700'; ?>">
                            <?php echo ucfirst($order['status']); ?>
                        </span>
                        <?php if ($order['tracking_number']): ?>
                        <span class="block text-xs text-blue-600 mt-1"><?php echo sanitize($order['tracking_number']); ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4 text-sm text-gray-500">
                        <?php echo date('M j, Y', dbTs($order['created_at'])); ?>
                        <span class="block text-xs"><?php echo date('g:i A', dbTs($order['created_at'])); ?></span>
                    </td>
                    <td class="px-6 py-4 text-right">
                        <button data-cardify-action="call" data-fn="openOrderModal" data-args="<?= htmlspecialchars(json_encode([$order]), ENT_QUOTES) ?>" 
                                class="text-blue-600 hover:text-blue-700 font-medium text-sm">
                            <i class="fa-solid fa-eye mr-1"></i>
                            View
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- Order Detail Modal -->
<div id="orderModal" class="fixed inset-0 bg-black/50 z-50 hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-xl max-w-2xl w-full max-h-[90vh] overflow-y-auto">
        <div class="p-6 border-b border-gray-200 flex items-center justify-between">
            <h3 class="text-lg font-semibold text-gray-900">Order <span id="modal_order_id"></span></h3>
            <button data-cardify-action="call" data-fn="closeOrderModal" class="text-gray-400 hover:text-gray-600">
                <i class="fa-solid fa-xmark text-xl"></i>
            </button>
        </div>
        
        <div class="p-6 space-y-6">
            <!-- Order Info -->
            <div class="grid md:grid-cols-2 gap-4">
                <div>
                    <p class="text-sm font-medium text-gray-500">Company</p>
                    <p class="text-gray-900" id="modal_company"></p>
                </div>
                <div>
                    <p class="text-sm font-medium text-gray-500">Employee</p>
                    <p class="text-gray-900" id="modal_employee"></p>
                </div>
                <div>
                    <p class="text-sm font-medium text-gray-500">Quantity</p>
                    <p class="text-gray-900" id="modal_quantity"></p>
                </div>
                <div>
                    <p class="text-sm font-medium text-gray-500">Options</p>
                    <p class="text-gray-900" id="modal_options"></p>
                </div>
            </div>
            
            <!-- Pricing -->
            <div class="bg-gray-50 rounded-lg p-4">
                <p class="text-sm font-medium text-gray-500 mb-2">Pricing</p>
                <div class="flex items-center justify-between text-sm">
                    <span>Subtotal</span>
                    <span id="modal_subtotal"></span>
                </div>
                <div class="flex items-center justify-between text-sm">
                    <span>Setup Fee</span>
                    <span id="modal_setup"></span>
                </div>
                <div class="flex items-center justify-between text-sm">
                    <span>Shipping</span>
                    <span id="modal_shipping"></span>
                </div>
                <div class="flex items-center justify-between font-semibold mt-2 pt-2 border-t border-gray-200">
                    <span>Total</span>
                    <span id="modal_total"></span>
                </div>
            </div>
            
            <!-- Shipping Address -->
            <div>
                <p class="text-sm font-medium text-gray-500 mb-2">Shipping Address</p>
                <div class="bg-gray-50 rounded-lg p-4 text-sm" id="modal_address"></div>
            </div>
            
            <!-- Update Status Form -->
            <form method="post" class="bg-blue-50 rounded-lg p-4">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="order_id" id="modal_form_order_id">
                
                <p class="text-sm font-medium text-blue-900 mb-3">Update Status</p>
                
                <div class="grid md:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs text-blue-700 mb-1">Status</label>
                        <select name="status" id="modal_status" class="w-full px-3 py-2 border border-blue-200 rounded-lg text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                            <option value="pending">Pending</option>
                            <option value="submitted">Submitted</option>
                            <option value="processing">Processing</option>
                            <option value="printing">Printing</option>
                            <option value="shipped">Shipped</option>
                            <option value="delivered">Delivered</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs text-blue-700 mb-1">Tracking Number</label>
                        <input type="text" name="tracking_number" id="modal_tracking" 
                               class="w-full px-3 py-2 border border-blue-200 rounded-lg text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20"
                               placeholder="Enter tracking number">
                    </div>
                </div>
                
                <button type="submit" class="mt-3 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium transition-colors">
                    Update Order
                </button>
            </form>
        </div>
    </div>
</div>

<script<?= cspNonceAttr() ?>>
function openOrderModal(order) {
    document.getElementById('modal_order_id').textContent = '#' + order.id;
    document.getElementById('modal_form_order_id').value = order.id;
    document.getElementById('modal_company').textContent = order.company_name || 'N/A';
    document.getElementById('modal_employee').textContent = ((order.first_name || '') + ' ' + (order.last_name || '')).trim() || 'N/A';
    document.getElementById('modal_quantity').textContent = order.quantity + ' cards';
    document.getElementById('modal_options').textContent = (order.paper_type || 'Standard').charAt(0).toUpperCase() + (order.paper_type || 'standard').slice(1) + ' / ' + (order.finish || 'standard').replace('_', ' ');
    
    document.getElementById('modal_subtotal').textContent = '$' + parseFloat(order.subtotal || 0).toFixed(2);
    document.getElementById('modal_setup').textContent = '$' + parseFloat(order.setup_fee || 0).toFixed(2);
    document.getElementById('modal_shipping').textContent = '$' + parseFloat(order.shipping_fee || 0).toFixed(2);
    document.getElementById('modal_total').textContent = '$' + parseFloat(order.total || 0).toFixed(2);
    
    let address = [];
    if (order.shipping_name) address.push(order.shipping_name);
    if (order.shipping_address) address.push(order.shipping_address);
    if (order.shipping_city || order.shipping_state) {
        address.push([order.shipping_city, order.shipping_state].filter(Boolean).join(', '));
    }
    if (order.shipping_country) address.push(order.shipping_country);
    if (order.shipping_postal) address.push(order.shipping_postal);
    document.getElementById('modal_address').innerHTML = address.length > 0 ? address.join('<br>') : '<span class="text-gray-400">No address provided</span>';
    
    document.getElementById('modal_status').value = order.status || 'pending';
    document.getElementById('modal_tracking').value = order.tracking_number || '';
    
    document.getElementById('orderModal').classList.remove('hidden');
}

function closeOrderModal() {
    document.getElementById('orderModal').classList.add('hidden');
}

// Close modal on escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeOrderModal();
});

// Close modal on backdrop click
document.getElementById('orderModal').addEventListener('click', function(e) {
    if (e.target === this) closeOrderModal();
});
</script>

<?php adminFooter(); ?>
