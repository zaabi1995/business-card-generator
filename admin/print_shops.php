<?php
/**
 * Print Shops Management - Super Admin
 * Manage registered print shops
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/PrintShop.php';
require_once INCLUDES_DIR . '/Currency.php';
require_once INCLUDES_DIR . '/admin-layout.php';

Auth::requireRole('super_admin');

$db = Database::getInstance();
$message = null;
$messageType = 'success';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { die('Invalid request'); }
    $action = $_POST['action'] ?? '';
    $shopId = (int)($_POST['shop_id'] ?? 0);
    
    if ($action === 'approve' && $shopId > 0) {
        $user = Auth::getCurrentUser();
        $result = PrintShop::approve($shopId, $user['id'] ?? null);
        $message = $result['success'] ? 'Print shop approved successfully!' : 'Error: ' . ($result['error'] ?? 'Unknown');
        $messageType = $result['success'] ? 'success' : 'error';
        
    } elseif ($action === 'suspend' && $shopId > 0) {
        $result = PrintShop::suspend($shopId);
        $message = $result['success'] ? 'Print shop suspended' : 'Error: ' . ($result['error'] ?? 'Unknown');
        $messageType = $result['success'] ? 'success' : 'error';
        
    } elseif ($action === 'activate' && $shopId > 0) {
        $result = PrintShop::update($shopId, ['status' => 'active']);
        $message = $result['success'] ? 'Print shop activated' : 'Error: ' . ($result['error'] ?? 'Unknown');
        $messageType = $result['success'] ? 'success' : 'error';
        
    } elseif ($action === 'feature' && $shopId > 0) {
        $featured = (int)($_POST['featured'] ?? 0);
        $result = PrintShop::update($shopId, ['featured' => $featured]);
        $message = $result['success'] ? ($featured ? 'Print shop featured' : 'Print shop unfeatured') : 'Error';
        $messageType = $result['success'] ? 'success' : 'error';
        
    } elseif ($action === 'verify' && $shopId > 0) {
        $result = PrintShop::update($shopId, ['verified' => 1]);
        $message = $result['success'] ? 'Print shop verified' : 'Error';
        $messageType = $result['success'] ? 'success' : 'error';
        
    } elseif ($action === 'delete' && $shopId > 0) {
        try {
            $pdo = $db->getConnection();
            $stmt = $pdo->prepare("DELETE FROM print_shops WHERE id = ?");
            $stmt->execute([$shopId]);
            $message = 'Print shop deleted successfully';
            $messageType = 'success';
        } catch (Exception $e) {
            $message = 'Error deleting print shop: ' . $e->getMessage();
            $messageType = 'error';
        }
        
    } elseif ($action === 'add' || $action === 'edit') {
        // Build pricing JSON
        $pricing = [
            'per_card' => (float)($_POST['price_per_card'] ?? 0.10),
            'setup_fee' => (float)($_POST['setup_fee'] ?? 5),
            'shipping_base' => (float)($_POST['shipping_base'] ?? 10),
            'express_multiplier' => (float)($_POST['express_multiplier'] ?? 1.5)
        ];
        
        // Build services array
        $services = [];
        if (!empty($_POST['services'])) {
            $services = array_map('trim', explode(',', $_POST['services']));
        }
        
        // Build paper types array
        $paperTypes = [];
        if (!empty($_POST['paper_types'])) {
            $paperTypes = array_map('trim', explode(',', $_POST['paper_types']));
        }
        
        // Build finishes array
        $finishes = [];
        if (!empty($_POST['finishes'])) {
            $finishes = array_map('trim', explode(',', $_POST['finishes']));
        }
        
        $data = [
            'name' => trim($_POST['name'] ?? ''),
            'email' => trim($_POST['email'] ?? ''),
            'phone' => trim($_POST['phone'] ?? ''),
            'website' => trim($_POST['website'] ?? ''),
            'description' => trim($_POST['description'] ?? ''),
            'address' => trim($_POST['address'] ?? ''),
            'city' => trim($_POST['city'] ?? ''),
            'state' => trim($_POST['state'] ?? ''),
            'country' => trim($_POST['country'] ?? ''),
            'postal_code' => trim($_POST['postal_code'] ?? ''),
            'currency' => trim($_POST['currency'] ?? 'OMR'),
            'min_order_quantity' => (int)($_POST['min_order_quantity'] ?? 50),
            'turnaround_days' => (int)($_POST['turnaround_days'] ?? 5),
            'express_available' => isset($_POST['express_available']) ? 1 : 0,
            'express_days' => (int)($_POST['express_days'] ?? 2),
            'pricing' => json_encode($pricing),
            'services' => json_encode($services),
            'paper_types' => json_encode($paperTypes),
            'finishes' => json_encode($finishes),
            'status' => $_POST['status'] ?? 'active',
            'verified' => isset($_POST['verified']) ? 1 : 0,
            'featured' => isset($_POST['featured']) ? 1 : 0
        ];
        
        if ($action === 'add') {
            $result = PrintShop::create($data);
            $message = $result['success'] ? 'Print shop added successfully!' : 'Error: ' . ($result['error'] ?? 'Unknown');
        } else {
            $result = PrintShop::update($shopId, $data);
            $message = $result['success'] ? 'Print shop updated successfully!' : 'Error: ' . ($result['error'] ?? 'Unknown');
        }
        $messageType = $result['success'] ? 'success' : 'error';
    }
}

// Get filter
$statusFilter = $_GET['status'] ?? '';

// Get print shops
try {
    $pdo = $db->getConnection();
    $sql = "SELECT * FROM print_shops";
    $params = [];
    
    if ($statusFilter) {
        $sql .= " WHERE status = ?";
        $params[] = $statusFilter;
    }
    
    $sql .= " ORDER BY featured DESC, verified DESC, created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $shops = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $shops = [];
}

// Count by status
$counts = ['pending' => 0, 'active' => 0, 'suspended' => 0, 'inactive' => 0];
try {
    $stmt = $pdo->query("SELECT status, COUNT(*) as cnt FROM print_shops GROUP BY status");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $counts[$row['status']] = (int)$row['cnt'];
    }
} catch (PDOException $e) {}

$statusColors = [
    'pending' => 'bg-amber-100 text-amber-700',
    'active' => 'bg-green-100 text-green-700',
    'suspended' => 'bg-red-100 text-red-700',
    'inactive' => 'bg-gray-100 text-gray-600'
];

adminHeader('Print Shops', 'print_shops');
?>

<div x-data="printShopManager()">

<!-- Alert Message -->
<?php if ($message): ?>
<div class="mb-6 p-4 rounded-xl flex items-center gap-3 <?php echo $messageType === 'success' ? 'bg-green-50 border border-green-200 text-green-700' : 'bg-red-50 border border-red-200 text-red-700'; ?>">
    <i class="<?php echo $messageType === 'success' ? 'fa-solid fa-circle-check' : 'fa-solid fa-circle-exclamation'; ?>"></i>
    <?php echo sanitize($message); ?>
</div>
<?php endif; ?>

<!-- Stats -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
    <a href="?status=pending" class="p-4 rounded-xl border <?php echo $statusFilter === 'pending' ? 'border-amber-500 bg-amber-50' : 'border-gray-200 bg-white hover:border-gray-300'; ?> transition-all">
        <p class="text-3xl font-bold text-amber-600"><?php echo $counts['pending']; ?></p>
        <p class="text-sm text-gray-500">Pending Approval</p>
    </a>
    <a href="?status=active" class="p-4 rounded-xl border <?php echo $statusFilter === 'active' ? 'border-green-500 bg-green-50' : 'border-gray-200 bg-white hover:border-gray-300'; ?> transition-all">
        <p class="text-3xl font-bold text-green-600"><?php echo $counts['active']; ?></p>
        <p class="text-sm text-gray-500">Active</p>
    </a>
    <a href="?status=suspended" class="p-4 rounded-xl border <?php echo $statusFilter === 'suspended' ? 'border-red-500 bg-red-50' : 'border-gray-200 bg-white hover:border-gray-300'; ?> transition-all">
        <p class="text-3xl font-bold text-red-600"><?php echo $counts['suspended']; ?></p>
        <p class="text-sm text-gray-500">Suspended</p>
    </a>
    <a href="print_shops.php" class="p-4 rounded-xl border <?php echo empty($statusFilter) ? 'border-blue-500 bg-blue-50' : 'border-gray-200 bg-white hover:border-gray-300'; ?> transition-all">
        <p class="text-3xl font-bold text-blue-600"><?php echo array_sum($counts); ?></p>
        <p class="text-sm text-gray-500">Total</p>
    </a>
</div>

<!-- Header Actions -->
<div class="flex items-center justify-between mb-6">
    <div>
        <h2 class="text-lg font-semibold text-gray-900">
            <?php echo $statusFilter ? ucfirst($statusFilter) . ' Print Shops' : 'All Print Shops'; ?>
        </h2>
    </div>
    <button @click="openAddModal()" 
            class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition-colors flex items-center gap-2">
        <i class="fa-solid fa-plus"></i>
        Add Print Shop
    </button>
</div>

<!-- Print Shops List -->
<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
    <?php if (empty($shops)): ?>
    <div class="p-12 text-center">
        <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
            <i class="fa-solid fa-store text-gray-400 text-2xl"></i>
        </div>
        <h3 class="text-lg font-medium text-gray-900 mb-2">No Print Shops</h3>
        <p class="text-gray-500 mb-4">Print shops will appear here when they register or you add them.</p>
        <button @click="openAddModal()" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition-colors">
            <i class="fa-solid fa-plus mr-2"></i>Add Print Shop
        </button>
    </div>
    <?php else: ?>
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead class="bg-gray-50 border-b border-gray-200">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Shop</th>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Location</th>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Pricing</th>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Status</th>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Orders</th>
                    <th class="px-6 py-3 text-right text-xs font-semibold text-gray-500 uppercase">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                <?php foreach ($shops as $shop): 
                    $shopPricing = json_decode($shop['pricing'] ?? '{}', true);
                ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-6 py-4">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center text-blue-600 font-bold">
                                <?php echo strtoupper(substr($shop['name'], 0, 2)); ?>
                            </div>
                            <div>
                                <p class="font-semibold text-gray-900">
                                    <?php echo sanitize($shop['name']); ?>
                                    <?php if ($shop['verified']): ?>
                                    <i class="fa-solid fa-circle-check text-blue-500 text-xs ml-1" title="Verified"></i>
                                    <?php endif; ?>
                                    <?php if ($shop['featured']): ?>
                                    <i class="fa-solid fa-star text-amber-500 text-xs ml-1" title="Featured"></i>
                                    <?php endif; ?>
                                </p>
                                <p class="text-sm text-gray-500"><?php echo sanitize($shop['email']); ?></p>
                            </div>
                        </div>
                    </td>
                    <td class="px-6 py-4 text-sm text-gray-600">
                        <?php echo sanitize($shop['city']); ?><?php echo $shop['country'] ? ', ' . Currency::getCountryName($shop['country']) : ''; ?>
                    </td>
                    <td class="px-6 py-4">
                        <p class="text-sm font-medium text-gray-900"><?php echo Currency::formatHtml($shopPricing['per_card'] ?? 0.10, $shop['currency'] ?? 'OMR', 'sm'); ?>/card</p>
                        <p class="text-xs text-gray-500">Min <?php echo $shop['min_order_quantity'] ?? 50; ?> • <?php echo $shop['turnaround_days'] ?? 5; ?> days</p>
                    </td>
                    <td class="px-6 py-4">
                        <span class="inline-flex px-2.5 py-1 rounded-full text-xs font-medium <?php echo $statusColors[$shop['status']] ?? 'bg-gray-100 text-gray-600'; ?>">
                            <?php echo ucfirst($shop['status']); ?>
                        </span>
                    </td>
                    <td class="px-6 py-4 text-sm text-gray-600">
                        <?php echo $shop['total_orders'] ?? 0; ?> orders
                    </td>
                    <td class="px-6 py-4 text-right">
                        <div class="flex items-center justify-end gap-1">
                            <!-- Edit Button -->
                            <button @click='openEditModal(<?php echo json_encode($shop); ?>)' 
                                    class="p-2 text-gray-500 hover:text-blue-600 hover:bg-blue-50 rounded-lg transition-colors" title="Edit">
                                <i class="fa-solid fa-pen"></i>
                            </button>
                            
                            <?php if ($shop['status'] === 'pending'): ?>
                            <form method="post" class="inline">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="approve">
                                <input type="hidden" name="shop_id" value="<?php echo $shop['id']; ?>">
                                <button type="submit" class="px-3 py-1.5 bg-green-100 hover:bg-green-200 text-green-700 rounded-lg text-sm font-medium transition-colors">
                                    <i class="fa-solid fa-check mr-1"></i> Approve
                                </button>
                            </form>
                            <?php elseif ($shop['status'] === 'active'): ?>
                            <form method="post" class="inline">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="suspend">
                                <input type="hidden" name="shop_id" value="<?php echo $shop['id']; ?>">
                                <button type="submit" class="p-2 text-gray-500 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors" title="Suspend">
                                    <i class="fa-solid fa-ban"></i>
                                </button>
                            </form>
                            <?php elseif ($shop['status'] === 'suspended'): ?>
                            <form method="post" class="inline">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="activate">
                                <input type="hidden" name="shop_id" value="<?php echo $shop['id']; ?>">
                                <button type="submit" class="px-3 py-1.5 bg-green-100 hover:bg-green-200 text-green-700 rounded-lg text-sm font-medium transition-colors">
                                    Reactivate
                                </button>
                            </form>
                            <?php endif; ?>
                            
                            <?php if ($shop['status'] === 'active'): ?>
                            <form method="post" class="inline">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="feature">
                                <input type="hidden" name="shop_id" value="<?php echo $shop['id']; ?>">
                                <input type="hidden" name="featured" value="<?php echo $shop['featured'] ? 0 : 1; ?>">
                                <button type="submit" class="p-2 <?php echo $shop['featured'] ? 'text-amber-500' : 'text-gray-400'; ?> hover:text-amber-600 hover:bg-amber-50 rounded-lg transition-colors" title="<?php echo $shop['featured'] ? 'Remove featured' : 'Feature'; ?>">
                                    <i class="fa-solid fa-star"></i>
                                </button>
                            </form>
                            
                            <?php if (!$shop['verified']): ?>
                            <form method="post" class="inline">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="verify">
                                <input type="hidden" name="shop_id" value="<?php echo $shop['id']; ?>">
                                <button type="submit" class="p-2 text-gray-400 hover:text-blue-600 hover:bg-blue-50 rounded-lg transition-colors" title="Verify">
                                    <i class="fa-solid fa-circle-check"></i>
                                </button>
                            </form>
                            <?php endif; ?>
                            <?php endif; ?>
                            
                            <!-- Delete Button -->
                            <form method="post" class="inline" onsubmit="return confirm('Delete this print shop? This cannot be undone.')">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="shop_id" value="<?php echo $shop['id']; ?>">
                                <button type="submit" class="p-2 text-gray-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors" title="Delete">
                                    <i class="fa-solid fa-trash"></i>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- Add/Edit Modal -->
<div x-show="showModal" x-cloak class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4" @click.self="showModal = false">
    <div class="bg-white rounded-2xl shadow-xl max-w-3xl w-full max-h-[90vh] overflow-y-auto" @click.stop>
        <div class="p-6 border-b border-gray-200 flex items-center justify-between sticky top-0 bg-white z-10">
            <h3 class="text-lg font-semibold text-gray-900" x-text="isEditing ? 'Edit Print Shop' : 'Add Print Shop'"></h3>
            <button @click="showModal = false" class="text-gray-400 hover:text-gray-600">
                <i class="fa-solid fa-xmark text-xl"></i>
            </button>
        </div>
        
        <form method="post" class="p-6 space-y-6">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" :value="isEditing ? 'edit' : 'add'">
            <input type="hidden" name="shop_id" :value="formData.id">
            
            <!-- Basic Info -->
            <div class="space-y-4">
                <h4 class="font-semibold text-gray-900 flex items-center gap-2">
                    <i class="fa-solid fa-store text-blue-600"></i>
                    Basic Information
                </h4>
                
                <div class="grid md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Shop Name *</label>
                        <input type="text" name="name" x-model="formData.name" required 
                               class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Email *</label>
                        <input type="email" name="email" x-model="formData.email" required 
                               class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                    </div>
                </div>
                
                <div class="grid md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Phone</label>
                        <input type="tel" name="phone" x-model="formData.phone" 
                               class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Website</label>
                        <input type="url" name="website" x-model="formData.website" 
                               class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                    </div>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                    <textarea name="description" x-model="formData.description" rows="2" 
                              class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20"></textarea>
                </div>
            </div>
            
            <!-- Location -->
            <div class="space-y-4">
                <h4 class="font-semibold text-gray-900 flex items-center gap-2">
                    <i class="fa-solid fa-location-dot text-green-600"></i>
                    Location
                </h4>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Address</label>
                    <input type="text" name="address" x-model="formData.address" 
                           class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                </div>
                
                <div class="grid md:grid-cols-4 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">City *</label>
                        <input type="text" name="city" x-model="formData.city" required 
                               class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">State/Region</label>
                        <input type="text" name="state" x-model="formData.state" 
                               class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Country *</label>
                        <select name="country" x-model="formData.country" required 
                                @change="formData.currency = $event.target.selectedOptions[0].dataset.currency || formData.currency"
                                class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                            <?php echo Currency::getCountryOptions('OM'); ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Postal Code</label>
                        <input type="text" name="postal_code" x-model="formData.postal_code" 
                               class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                    </div>
                </div>
            </div>
            
            <!-- Pricing -->
            <div class="space-y-4">
                <h4 class="font-semibold text-gray-900 flex items-center gap-2">
                    <i class="fa-solid fa-tag text-purple-600"></i>
                    Pricing
                </h4>
                
                <div class="grid md:grid-cols-5 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Currency</label>
                        <select name="currency" x-model="formData.currency" 
                                class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                            <?php echo Currency::getCurrencyOptions('OMR'); ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Price per Card</label>
                        <input type="number" name="price_per_card" x-model="formData.price_per_card" step="0.001" min="0" 
                               class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Setup Fee</label>
                        <input type="number" name="setup_fee" x-model="formData.setup_fee" step="0.01" min="0" 
                               class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Shipping Base</label>
                        <input type="number" name="shipping_base" x-model="formData.shipping_base" step="0.01" min="0" 
                               class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Min Order Qty</label>
                        <input type="number" name="min_order_quantity" x-model="formData.min_order_quantity" min="1" 
                               class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                    </div>
                </div>
            </div>
            
            <!-- Turnaround -->
            <div class="space-y-4">
                <h4 class="font-semibold text-gray-900 flex items-center gap-2">
                    <i class="fa-solid fa-clock text-amber-600"></i>
                    Turnaround Time
                </h4>
                
                <div class="grid md:grid-cols-4 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Standard Days</label>
                        <input type="number" name="turnaround_days" x-model="formData.turnaround_days" min="1" 
                               class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Express Days</label>
                        <input type="number" name="express_days" x-model="formData.express_days" min="1" 
                               class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Express Multiplier</label>
                        <input type="number" name="express_multiplier" x-model="formData.express_multiplier" step="0.1" min="1" 
                               class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                    </div>
                    <div class="flex items-end pb-1">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" name="express_available" x-model="formData.express_available" 
                                   class="w-5 h-5 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                            <span class="text-sm font-medium text-gray-700">Express Available</span>
                        </label>
                    </div>
                </div>
            </div>
            
            <!-- Services & Options -->
            <div class="space-y-4">
                <h4 class="font-semibold text-gray-900 flex items-center gap-2">
                    <i class="fa-solid fa-list-check text-indigo-600"></i>
                    Services & Options
                </h4>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Services (comma-separated)</label>
                    <input type="text" name="services" x-model="formData.services" 
                           placeholder="business_cards, flyers, brochures, banners"
                           class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Paper Types (comma-separated)</label>
                    <input type="text" name="paper_types" x-model="formData.paper_types" 
                           placeholder="matte, glossy, silk, uncoated, recycled"
                           class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Finishes (comma-separated)</label>
                    <input type="text" name="finishes" x-model="formData.finishes" 
                           placeholder="standard, rounded_corners, spot_uv, foil, embossed"
                           class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                </div>
            </div>
            
            <!-- Status & Badges -->
            <div class="space-y-4">
                <h4 class="font-semibold text-gray-900 flex items-center gap-2">
                    <i class="fa-solid fa-shield-check text-cyan-600"></i>
                    Status & Badges
                </h4>
                
                <div class="grid md:grid-cols-4 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                        <select name="status" x-model="formData.status" 
                                class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                            <option value="pending">Pending</option>
                            <option value="active">Active</option>
                            <option value="suspended">Suspended</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                    <div class="flex items-end pb-1">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" name="verified" x-model="formData.verified" 
                                   class="w-5 h-5 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                            <span class="text-sm font-medium text-gray-700">Verified</span>
                        </label>
                    </div>
                    <div class="flex items-end pb-1">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" name="featured" x-model="formData.featured" 
                                   class="w-5 h-5 rounded border-gray-300 text-amber-600 focus:ring-amber-500">
                            <span class="text-sm font-medium text-gray-700">Featured</span>
                        </label>
                    </div>
                </div>
            </div>
            
            <!-- Actions -->
            <div class="flex justify-end gap-3 pt-4 border-t border-gray-200">
                <button type="button" @click="showModal = false" class="px-4 py-2.5 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg font-medium transition-colors">
                    Cancel
                </button>
                <button type="submit" class="px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition-colors flex items-center gap-2">
                    <i class="fa-solid fa-check"></i>
                    <span x-text="isEditing ? 'Update' : 'Add Print Shop'"></span>
                </button>
            </div>
        </form>
    </div>
</div>

</div>

<script>
function printShopManager() {
    return {
        showModal: false,
        isEditing: false,
        formData: {
            id: '',
            name: '',
            email: '',
            phone: '',
            website: '',
            description: '',
            address: '',
            city: '',
            state: '',
            country: 'OM',
            postal_code: '',
            currency: 'OMR',
            price_per_card: 0.100,
            setup_fee: 5,
            shipping_base: 2,
            express_multiplier: 1.5,
            min_order_quantity: 50,
            turnaround_days: 5,
            express_days: 2,
            express_available: true,
            services: 'business_cards',
            paper_types: 'matte, glossy, silk',
            finishes: 'standard, rounded_corners, spot_uv, foil',
            status: 'active',
            verified: false,
            featured: false
        },
        
        openAddModal() {
            this.isEditing = false;
            this.formData = {
                id: '',
                name: '',
                email: '',
                phone: '',
                website: '',
                description: '',
                address: '',
                city: '',
                state: '',
                country: 'OM',
                postal_code: '',
                currency: 'OMR',
                price_per_card: 0.100,
                setup_fee: 5,
                shipping_base: 2,
                express_multiplier: 1.5,
                min_order_quantity: 50,
                turnaround_days: 5,
                express_days: 2,
                express_available: true,
                services: 'business_cards',
                paper_types: 'matte, glossy, silk',
                finishes: 'standard, rounded_corners, spot_uv, foil',
                status: 'active',
                verified: false,
                featured: false
            };
            this.showModal = true;
        },
        
        openEditModal(shop) {
            this.isEditing = true;
            
            // Parse pricing JSON
            let pricing = {};
            try {
                pricing = typeof shop.pricing === 'string' ? JSON.parse(shop.pricing) : (shop.pricing || {});
            } catch(e) {}
            
            // Parse arrays
            let services = '';
            let paperTypes = '';
            let finishes = '';
            try {
                const servicesArr = typeof shop.services === 'string' ? JSON.parse(shop.services) : (shop.services || []);
                services = Array.isArray(servicesArr) ? servicesArr.join(', ') : '';
            } catch(e) {}
            try {
                const paperArr = typeof shop.paper_types === 'string' ? JSON.parse(shop.paper_types) : (shop.paper_types || []);
                paperTypes = Array.isArray(paperArr) ? paperArr.join(', ') : '';
            } catch(e) {}
            try {
                const finishArr = typeof shop.finishes === 'string' ? JSON.parse(shop.finishes) : (shop.finishes || []);
                finishes = Array.isArray(finishArr) ? finishArr.join(', ') : '';
            } catch(e) {}
            
            this.formData = {
                id: shop.id || '',
                name: shop.name || '',
                email: shop.email || '',
                phone: shop.phone || '',
                website: shop.website || '',
                description: shop.description || '',
                address: shop.address || '',
                city: shop.city || '',
                state: shop.state || '',
                country: shop.country || 'OM',
                postal_code: shop.postal_code || '',
                currency: shop.currency || 'OMR',
                price_per_card: pricing.per_card || 0.100,
                setup_fee: pricing.setup_fee || 5,
                shipping_base: pricing.shipping_base || 2,
                express_multiplier: pricing.express_multiplier || 1.5,
                min_order_quantity: shop.min_order_quantity || 50,
                turnaround_days: shop.turnaround_days || 5,
                express_days: shop.express_days || 2,
                express_available: !!shop.express_available,
                services: services,
                paper_types: paperTypes,
                finishes: finishes,
                status: shop.status || 'active',
                verified: !!shop.verified,
                featured: !!shop.featured
            };
            this.showModal = true;
        }
    };
}
</script>

<?php adminFooter(); ?>
