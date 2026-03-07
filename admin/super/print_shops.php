<?php
/**
 * Super Admin - Print Shops Management
 * View, edit, and manage all print shops
 */
require_once __DIR__ . '/../../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/AuditLog.php';
require_once INCLUDES_DIR . '/Currency.php';
require_once INCLUDES_DIR . '/admin-layout.php';

Auth::requireRole('super_admin');

$db = Database::getInstance();
$message = '';
$messageType = '';

// Check if print_shops table exists
$tableExists = $db->tableExists('print_shops');

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !validateCSRFToken($_POST['csrf_token'] ?? '')) {
    die('Invalid request');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tableExists) {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_shop') {
        $shopId = (int)$_POST['shop_id'];
        $shop = $db->fetchOne("SELECT * FROM print_shops WHERE id = :id", ['id' => $shopId]);
        
        if ($shop) {
            $updateData = [
                'name' => sanitize($_POST['name']),
                'email' => sanitizeEmail($_POST['email']),
                'phone' => sanitize($_POST['phone']),
                'address' => sanitize($_POST['address']),
                'city' => sanitize($_POST['city']),
                'country' => sanitize($_POST['country'] ?? ''),
                'currency' => sanitize($_POST['currency'] ?? 'USD'),
                'status' => sanitize($_POST['status']),
                'featured' => isset($_POST['featured']) ? 1 : 0,
                'verified' => isset($_POST['verified']) ? 1 : 0,
                'updated_at' => date('Y-m-d H:i:s')
            ];
            
            $db->update('print_shops', $updateData, 'id = :id', ['id' => $shopId]);
            
            AuditLog::log('update', 'print_shop', $shopId, $shop, array_merge($shop, $updateData));
            
            $message = 'Print shop updated successfully';
            $messageType = 'success';
        }
    } elseif ($action === 'delete_shop') {
        $shopId = (int)$_POST['shop_id'];
        $shop = $db->fetchOne("SELECT * FROM print_shops WHERE id = :id", ['id' => $shopId]);
        
        if ($shop) {
            // Delete associated user account if exists
            if (!empty($shop['user_id'])) {
                try {
                    $db->delete('users', 'id = :id', ['id' => $shop['user_id']]);
                } catch (Exception $e) {
                    // User might not exist, ignore
                }
            }
            
            $db->delete('print_shops', 'id = :id', ['id' => $shopId]);
            AuditLog::log('delete', 'print_shop', $shopId, $shop, null);
            
            $message = 'Print shop and associated user account deleted successfully';
            $messageType = 'success';
        }
    } elseif ($action === 'approve_shop') {
        $shopId = (int)$_POST['shop_id'];
        $shop = $db->fetchOne("SELECT * FROM print_shops WHERE id = :id", ['id' => $shopId]);
        
        if ($shop) {
            $db->update('print_shops', [
                'status' => 'active',
                'approved_at' => date('Y-m-d H:i:s'),
                'approved_by' => $_SESSION['user_id'] ?? null,
                'updated_at' => date('Y-m-d H:i:s')
            ], 'id = :id', ['id' => $shopId]);
            
            AuditLog::log('approve', 'print_shop', $shopId, $shop, ['status' => 'active']);
            
            $message = 'Print shop approved successfully';
            $messageType = 'success';
        }
    } elseif ($action === 'suspend_shop') {
        $shopId = (int)$_POST['shop_id'];
        $shop = $db->fetchOne("SELECT * FROM print_shops WHERE id = :id", ['id' => $shopId]);
        
        if ($shop) {
            $db->update('print_shops', [
                'status' => 'suspended',
                'updated_at' => date('Y-m-d H:i:s')
            ], 'id = :id', ['id' => $shopId]);
            
            AuditLog::log('suspend', 'print_shop', $shopId, $shop, ['status' => 'suspended']);
            
            $message = 'Print shop suspended';
            $messageType = 'success';
        }
    } elseif ($action === 'create_shop') {
        $name = sanitize($_POST['name']);
        $email = sanitizeEmail($_POST['email']);
        $password = $_POST['password'] ?? '';
        $phone = sanitize($_POST['phone'] ?? '');
        $address = sanitize($_POST['address'] ?? '');
        $city = sanitize($_POST['city'] ?? '');
        $country = sanitize($_POST['country'] ?? '');
        $currency = sanitize($_POST['currency'] ?? 'USD');
        $status = sanitize($_POST['status'] ?? 'active');
        
        // Validation
        if (empty($name) || empty($email) || empty($password)) {
            $message = 'Name, email and password are required';
            $messageType = 'error';
        } elseif (strlen($password) < 8) {
            $message = 'Password must be at least 8 characters';
            $messageType = 'error';
        } else {
            // Check if print shop with this email exists
            $existingShop = $db->fetchOne("SELECT id FROM print_shops WHERE email = :email", ['email' => $email]);
            
            if ($existingShop) {
                $message = 'A print shop with this email already exists';
                $messageType = 'error';
            } else {
                try {
                    $pdo = $db->getConnection();
                    $pdo->beginTransaction();
                    
                    // Check if user with this email exists (orphaned from deleted print shop)
                    $existingUser = $db->fetchOne("SELECT id, role FROM users WHERE email = :email", ['email' => $email]);
                    
                    if ($existingUser) {
                        // Reuse existing user - update their password and ensure role is print_shop
                        $userId = $existingUser['id'];
                        $db->update('users', [
                            'password_hash' => password_hash($password, PASSWORD_BCRYPT),
                            'name' => $name,
                            'role' => 'print_shop',
                            'status' => 'active'
                        ], 'id = :id', ['id' => $userId]);
                    } else {
                        // Generate UUID for new user
                        $userId = generateUUID();
                        
                        // Create user account
                        $db->insert('users', [
                            'id' => $userId,
                            'email' => $email,
                            'password_hash' => password_hash($password, PASSWORD_BCRYPT),
                            'name' => $name,
                            'role' => 'print_shop',
                            'status' => 'active',
                            'created_at' => date('Y-m-d H:i:s')
                        ]);
                    }
                    
                    // Generate slug
                    $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name));
                    $slug = trim($slug, '-');
                    
                    // Create print shop
                    $shopId = $db->insert('print_shops', [
                        'name' => $name,
                        'slug' => $slug,
                        'email' => $email,
                        'phone' => $phone,
                        'address' => $address,
                        'city' => $city,
                        'country' => $country,
                        'currency' => $currency,
                        'user_id' => $userId,
                        'status' => $status,
                        'created_at' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s')
                    ]);
                    
                    $pdo->commit();
                    
                    AuditLog::log('create', 'print_shop', $shopId, null, ['name' => $name, 'email' => $email]);
                    
                    $message = 'Print shop created successfully. They can now log in with the provided email and password.';
                    $messageType = 'success';
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $message = 'Failed to create print shop: ' . $e->getMessage();
                    $messageType = 'error';
                }
            }
        }
    } elseif ($action === 'reset_password') {
        $shopId = (int)$_POST['shop_id'];
        $newPassword = $_POST['new_password'] ?? '';
        
        if (strlen($newPassword) < 8) {
            $message = 'Password must be at least 8 characters';
            $messageType = 'error';
        } else {
            $shop = $db->fetchOne("SELECT * FROM print_shops WHERE id = :id", ['id' => $shopId]);
            
            if ($shop && $shop['user_id']) {
                $db->update('users', 
                    ['password_hash' => password_hash($newPassword, PASSWORD_BCRYPT)],
                    'id = :id',
                    ['id' => $shop['user_id']]
                );
                
                AuditLog::log('password_reset', 'print_shop', $shopId, null, ['by' => 'super_admin']);
                
                $message = 'Password reset successfully';
                $messageType = 'success';
            } elseif ($shop) {
                // Print shop has no user - create one
                $userId = generateUUID();
                
                $db->insert('users', [
                    'id' => $userId,
                    'email' => $shop['email'],
                    'password_hash' => password_hash($newPassword, PASSWORD_BCRYPT),
                    'name' => $shop['name'],
                    'role' => 'print_shop',
                    'status' => 'active',
                    'created_at' => date('Y-m-d H:i:s')
                ]);
                
                $db->update('print_shops', ['user_id' => $userId], 'id = :id', ['id' => $shopId]);
                
                AuditLog::log('user_created', 'print_shop', $shopId, null, ['user_id' => $userId]);
                
                $message = 'User account created and password set successfully';
                $messageType = 'success';
            } else {
                $message = 'Print shop not found';
                $messageType = 'error';
            }
        }
    }
}

// Filters
$search = $_GET['search'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

$shops = [];
$total = 0;
$totalPages = 0;

if ($tableExists) {
    // Build query
    $where = [];
    $params = [];
    
    if ($search) {
        $where[] = "(name LIKE :search OR email LIKE :search OR city LIKE :search)";
        $params['search'] = "%{$search}%";
    }
    
    if ($statusFilter) {
        $where[] = "status = :status";
        $params['status'] = $statusFilter;
    }
    
    $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
    
    // Get total count
    $totalResult = $db->fetchOne("SELECT COUNT(*) as count FROM print_shops {$whereClause}", $params);
    $total = (int)($totalResult['count'] ?? 0);
    $totalPages = ceil($total / $perPage);
    
    // Get print shops
    $shops = $db->fetchAll(
        "SELECT ps.*,
                (SELECT COUNT(*) FROM print_orders WHERE print_shop_id = ps.id) as order_count
         FROM print_shops ps
         {$whereClause} 
         ORDER BY ps.created_at DESC 
         LIMIT {$perPage} OFFSET {$offset}",
        $params
    );
}

adminHeader('Print Shops Management', 'print_shops');
?>

<?php if (!$tableExists): ?>
<div class="bg-amber-100 border-l-4 border-amber-500 text-amber-700 p-4 mb-6">
    <div class="flex items-center">
        <i class="fa-solid fa-exclamation-triangle mr-3"></i>
        <div>
            <p class="font-bold">Print Shops Table Not Found</p>
            <p>The print_shops table doesn't exist yet. Please run database migrations to create it.</p>
            <a href="../updates.php" class="text-amber-800 underline">Go to Database Updates</a>
        </div>
    </div>
</div>
<?php else: ?>

<?php if ($message): ?>
<div class="mb-4 p-4 rounded-lg <?php echo $messageType === 'success' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'; ?>">
    <?php echo sanitize($message); ?>
</div>
<?php endif; ?>

<!-- Filters -->
<div class="bg-white rounded-lg shadow p-4 mb-6">
    <div class="flex flex-col lg:flex-row gap-4 items-center justify-between">
        <form method="GET" class="flex flex-col lg:flex-row gap-4 flex-1">
            <input type="text" name="search" value="<?php echo sanitize($search); ?>" 
                   placeholder="Search by name, email, or city..." 
                   class="flex-1 px-4 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
            <select name="status" class="px-4 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                <option value="">All Status</option>
                <option value="pending" <?php echo $statusFilter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                <option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>>Active</option>
                <option value="suspended" <?php echo $statusFilter === 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                <option value="inactive" <?php echo $statusFilter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
            </select>
            <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                <i class="fa-solid fa-search mr-2"></i>Filter
            </button>
            <?php if ($search || $statusFilter): ?>
            <a href="print_shops.php" class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50 text-center">Clear</a>
            <?php endif; ?>
        </form>
        <button onclick="openCreateModal()" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 whitespace-nowrap">
            <i class="fa-solid fa-plus mr-2"></i>Add Print Shop
        </button>
    </div>
</div>

<!-- Stats -->
<div class="grid grid-cols-1 md:grid-cols-5 gap-4 mb-6">
    <div class="bg-white rounded-lg shadow p-4">
        <div class="text-sm text-gray-500">Total Shops</div>
        <div class="text-2xl font-semibold"><?php echo $db->fetchOne("SELECT COUNT(*) as c FROM print_shops")['c'] ?? 0; ?></div>
    </div>
    <div class="bg-white rounded-lg shadow p-4">
        <div class="text-sm text-gray-500">Pending Approval</div>
        <div class="text-2xl font-semibold text-amber-600">
            <?php echo $db->fetchOne("SELECT COUNT(*) as c FROM print_shops WHERE status = 'pending'")['c'] ?? 0; ?>
        </div>
    </div>
    <div class="bg-white rounded-lg shadow p-4">
        <div class="text-sm text-gray-500">Active</div>
        <div class="text-2xl font-semibold text-green-600">
            <?php echo $db->fetchOne("SELECT COUNT(*) as c FROM print_shops WHERE status = 'active'")['c'] ?? 0; ?>
        </div>
    </div>
    <div class="bg-white rounded-lg shadow p-4">
        <div class="text-sm text-gray-500">Verified</div>
        <div class="text-2xl font-semibold text-blue-600">
            <?php echo $db->fetchOne("SELECT COUNT(*) as c FROM print_shops WHERE verified = 1")['c'] ?? 0; ?>
        </div>
    </div>
    <div class="bg-white rounded-lg shadow p-4">
        <div class="text-sm text-gray-500">Featured</div>
        <div class="text-2xl font-semibold text-purple-600">
            <?php echo $db->fetchOne("SELECT COUNT(*) as c FROM print_shops WHERE featured = 1")['c'] ?? 0; ?>
        </div>
    </div>
</div>

<!-- Print Shops Table -->
<div class="bg-white rounded-lg shadow overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-sm text-left text-gray-500">
            <thead class="text-xs text-gray-700 uppercase bg-gray-50">
                <tr>
                    <th class="px-6 py-3">Shop</th>
                    <th class="px-6 py-3">Contact</th>
                    <th class="px-6 py-3">Location</th>
                    <th class="px-6 py-3">Status</th>
                    <th class="px-6 py-3">Badges</th>
                    <th class="px-6 py-3">Orders</th>
                    <th class="px-6 py-3">Rating</th>
                    <th class="px-6 py-3">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($shops)): ?>
                <tr><td colspan="8" class="px-6 py-8 text-center text-gray-500">No print shops found</td></tr>
                <?php else: ?>
                <?php foreach ($shops as $shop): ?>
                <tr class="bg-white border-b hover:bg-gray-50">
                    <td class="px-6 py-4">
                        <div class="font-medium text-gray-900"><?php echo sanitize($shop['name']); ?></div>
                        <div class="text-xs text-gray-500"><?php echo sanitize($shop['slug'] ?? ''); ?></div>
                    </td>
                    <td class="px-6 py-4">
                        <div><?php echo sanitize($shop['email']); ?></div>
                        <div class="text-xs text-gray-500"><?php echo sanitize($shop['phone'] ?? ''); ?></div>
                    </td>
                    <td class="px-6 py-4">
                        <div><?php echo sanitize($shop['city'] ?? 'N/A'); ?></div>
                        <div class="text-xs text-gray-500"><?php echo Currency::getCountryName($shop['country'] ?? ''); ?></div>
                    </td>
                    <td class="px-6 py-4">
                        <span class="px-2 py-1 rounded-full text-xs font-semibold 
                            <?php 
                            echo match($shop['status'] ?? 'pending') {
                                'active' => 'bg-green-100 text-green-700',
                                'suspended' => 'bg-red-100 text-red-700',
                                'pending' => 'bg-amber-100 text-amber-700',
                                default => 'bg-gray-100 text-gray-700'
                            };
                            ?>">
                            <?php echo sanitize(ucfirst($shop['status'] ?? 'pending')); ?>
                        </span>
                    </td>
                    <td class="px-6 py-4">
                        <?php if (!empty($shop['verified'])): ?>
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-700 mr-1">
                            <i class="fa-solid fa-check-circle mr-1"></i>Verified
                        </span>
                        <?php endif; ?>
                        <?php if (!empty($shop['featured'])): ?>
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-purple-100 text-purple-700">
                            <i class="fa-solid fa-star mr-1"></i>Featured
                        </span>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4"><?php echo $shop['order_count'] ?? 0; ?></td>
                    <td class="px-6 py-4">
                        <?php if (!empty($shop['rating'])): ?>
                        <div class="flex items-center">
                            <i class="fa-solid fa-star text-amber-400 mr-1"></i>
                            <?php echo number_format($shop['rating'], 1); ?>
                        </div>
                        <?php else: ?>
                        <span class="text-gray-400">-</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4">
                        <div class="flex items-center gap-2">
                            <button onclick='openEditModal(<?php echo json_encode($shop); ?>)' 
                                    class="text-blue-600 hover:text-blue-800" title="Edit">
                                <i class="fa-solid fa-edit"></i>
                            </button>
                            <button onclick="openResetPasswordModal(<?php echo $shop['id']; ?>, '<?php echo sanitize($shop['name']); ?>')" 
                                    class="text-purple-600 hover:text-purple-800" title="Reset Password">
                                <i class="fa-solid fa-key"></i>
                            </button>
                            <?php if (($shop['status'] ?? 'pending') === 'pending'): ?>
                            <form method="POST" class="inline">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="approve_shop">
                                <input type="hidden" name="shop_id" value="<?php echo $shop['id']; ?>">
                                <button type="submit" class="text-green-600 hover:text-green-800" title="Approve">
                                    <i class="fa-solid fa-check"></i>
                                </button>
                            </form>
                            <?php endif; ?>
                            <?php if (($shop['status'] ?? '') === 'active'): ?>
                            <form method="POST" class="inline" onsubmit="return confirm('Suspend this print shop?')">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="suspend_shop">
                                <input type="hidden" name="shop_id" value="<?php echo $shop['id']; ?>">
                                <button type="submit" class="text-amber-600 hover:text-amber-800" title="Suspend">
                                    <i class="fa-solid fa-ban"></i>
                                </button>
                            </form>
                            <?php endif; ?>
                            <button onclick="confirmDelete(<?php echo $shop['id']; ?>, '<?php echo sanitize($shop['name']); ?>')" 
                                    class="text-red-600 hover:text-red-800" title="Delete">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="px-6 py-4 border-t border-gray-100">
        <div class="flex items-center justify-between">
            <div class="text-sm text-gray-500">
                Showing <?php echo $offset + 1; ?> to <?php echo min($offset + $perPage, $total); ?> of <?php echo $total; ?> shops
            </div>
            <div class="flex gap-2">
                <?php if ($page > 1): ?>
                <a href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($statusFilter); ?>" 
                   class="px-3 py-1 border rounded hover:bg-gray-50">Previous</a>
                <?php endif; ?>
                <?php if ($page < $totalPages): ?>
                <a href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($statusFilter); ?>" 
                   class="px-3 py-1 border rounded hover:bg-gray-50">Next</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Edit Modal -->
<div id="editModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-lg max-h-[90vh] overflow-y-auto">
        <div class="p-6 border-b">
            <h3 class="text-lg font-semibold">Edit Print Shop</h3>
        </div>
        <form method="POST">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="update_shop">
            <input type="hidden" name="shop_id" id="shopId">
            <div class="p-6 space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Shop Name</label>
                    <input type="text" name="name" id="shopName" required
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                    <input type="email" name="email" id="shopEmail" required
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                </div>
                <div>
                    <?php echo Currency::renderSimplePhoneInput([
                        'name' => 'phone',
                        'id' => 'edit-phone',
                        'country_name' => 'phone_country',
                        'country_id' => 'shopPhoneCountry',
                        'selected_country' => 'OM',
                        'placeholder' => 'Enter phone number',
                        'label' => 'Phone'
                    ]); ?>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Address</label>
                    <input type="text" name="address" id="shopAddress"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">City</label>
                    <input type="text" name="city" id="shopCity"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Country</label>
                    <?php echo Currency::renderCountrySelect([
                        'name' => 'country',
                        'id' => 'shopCountry',
                        'currency_id' => 'shopCurrency'
                    ]); ?>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Currency</label>
                    <?php echo Currency::renderCurrencySelect([
                        'name' => 'currency',
                        'id' => 'shopCurrency',
                        'selected' => 'USD'
                    ]); ?>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                    <select name="status" id="shopStatus" required
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                        <option value="pending">Pending</option>
                        <option value="active">Active</option>
                        <option value="suspended">Suspended</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
                <div class="flex items-center gap-6">
                    <label class="flex items-center">
                        <input type="checkbox" name="verified" id="shopVerified" class="rounded border-gray-300 text-blue-600 mr-2">
                        <span class="text-sm text-gray-700">Verified</span>
                    </label>
                    <label class="flex items-center">
                        <input type="checkbox" name="featured" id="shopFeatured" class="rounded border-gray-300 text-purple-600 mr-2">
                        <span class="text-sm text-gray-700">Featured</span>
                    </label>
                </div>
            </div>
            <div class="p-6 border-t bg-gray-50 flex justify-end gap-3">
                <button type="button" onclick="closeModal()" class="px-4 py-2 border rounded-lg hover:bg-gray-100">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div id="deleteModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-md">
        <div class="p-6">
            <h3 class="text-lg font-semibold text-red-600 mb-2">Delete Print Shop</h3>
            <p class="text-gray-600">Are you sure you want to delete <strong id="deleteShopName"></strong>? This action cannot be undone.</p>
        </div>
        <form method="POST" class="p-6 border-t bg-gray-50 flex justify-end gap-3">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="delete_shop">
            <input type="hidden" name="shop_id" id="deleteShopId">
            <button type="button" onclick="closeDeleteModal()" class="px-4 py-2 border rounded-lg hover:bg-gray-100">Cancel</button>
            <button type="submit" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700">Delete Shop</button>
        </form>
    </div>
</div>

<!-- Create Print Shop Modal -->
<div id="createModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-lg max-h-[90vh] overflow-y-auto">
        <div class="p-6 border-b">
            <h3 class="text-lg font-semibold">Add New Print Shop</h3>
            <p class="text-sm text-gray-500 mt-1">Create a new print shop with a login account</p>
        </div>
        <form method="POST">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="create_shop">
            <div class="p-6 space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Shop Name <span class="text-red-500">*</span></label>
                    <input type="text" name="name" required
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Email <span class="text-red-500">*</span></label>
                    <input type="email" name="email" required
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                    <p class="text-xs text-gray-500 mt-1">This will be used for login</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Password <span class="text-red-500">*</span></label>
                    <input type="password" name="password" required minlength="8" autocomplete="new-password"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                    <p class="text-xs text-gray-500 mt-1">Minimum 8 characters</p>
                </div>
                <div>
                    <?php echo Currency::renderSimplePhoneInput([
                        'name' => 'phone',
                        'id' => 'create-phone',
                        'country_name' => 'phone_country',
                        'country_id' => 'createPhoneCountry',
                        'selected_country' => 'OM',
                        'placeholder' => 'Enter phone number',
                        'label' => 'Phone'
                    ]); ?>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">City</label>
                    <input type="text" name="city"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Country</label>
                    <?php echo Currency::renderCountrySelect([
                        'name' => 'country',
                        'id' => 'createCountry',
                        'currency_id' => 'createCurrency'
                    ]); ?>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Currency</label>
                    <?php echo Currency::renderCurrencySelect([
                        'name' => 'currency',
                        'id' => 'createCurrency',
                        'selected' => 'USD'
                    ]); ?>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Address</label>
                    <input type="text" name="address"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                    <select name="status" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                        <option value="active">Active</option>
                        <option value="pending">Pending</option>
                    </select>
                </div>
            </div>
            <div class="p-6 border-t bg-gray-50 flex justify-end gap-3">
                <button type="button" onclick="closeCreateModal()" class="px-4 py-2 border rounded-lg hover:bg-gray-100">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">Create Print Shop</button>
            </div>
        </form>
    </div>
</div>

<!-- Reset Password Modal -->
<div id="resetPasswordModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-md">
        <div class="p-6 border-b">
            <h3 class="text-lg font-semibold">Reset Password</h3>
            <p class="text-sm text-gray-500 mt-1">Set a new password for <strong id="resetShopName"></strong></p>
        </div>
        <form method="POST">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="reset_password">
            <input type="hidden" name="shop_id" id="resetShopId">
            <div class="p-6">
                <label class="block text-sm font-medium text-gray-700 mb-1">New Password</label>
                <input type="password" name="new_password" required minlength="8" autocomplete="new-password"
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                <p class="text-xs text-gray-500 mt-1">Minimum 8 characters</p>
            </div>
            <div class="p-6 border-t bg-gray-50 flex justify-end gap-3">
                <button type="button" onclick="closeResetPasswordModal()" class="px-4 py-2 border rounded-lg hover:bg-gray-100">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700">Reset Password</button>
            </div>
        </form>
    </div>
</div>

<script>
function openEditModal(shop) {
    document.getElementById('shopId').value = shop.id;
    document.getElementById('shopName').value = shop.name || '';
    document.getElementById('shopEmail').value = shop.email || '';
    document.getElementById('edit-phone').value = shop.phone || '';
    document.getElementById('shopAddress').value = shop.address || '';
    document.getElementById('shopCity').value = shop.city || '';
    document.getElementById('shopCountry').value = shop.country || 'OM';
    document.getElementById('shopCurrency').value = shop.currency || 'OMR';
    document.getElementById('shopStatus').value = shop.status || 'pending';
    document.getElementById('shopVerified').checked = shop.verified == 1;
    document.getElementById('shopFeatured').checked = shop.featured == 1;
    
    // Update phone country display if country is set
    if (shop.country) {
        const countrySelect = document.getElementById('shopPhoneCountry');
        if (countrySelect) countrySelect.value = shop.country;
    }
    
    document.getElementById('editModal').classList.remove('hidden');
}

function closeModal() {
    document.getElementById('editModal').classList.add('hidden');
}

function confirmDelete(id, name) {
    document.getElementById('deleteShopId').value = id;
    document.getElementById('deleteShopName').textContent = name;
    document.getElementById('deleteModal').classList.remove('hidden');
}

function closeDeleteModal() {
    document.getElementById('deleteModal').classList.add('hidden');
}

function openCreateModal() {
    document.getElementById('createModal').classList.remove('hidden');
}

function closeCreateModal() {
    document.getElementById('createModal').classList.add('hidden');
}

function openResetPasswordModal(id, name) {
    document.getElementById('resetShopId').value = id;
    document.getElementById('resetShopName').textContent = name;
    document.getElementById('resetPasswordModal').classList.remove('hidden');
}

function closeResetPasswordModal() {
    document.getElementById('resetPasswordModal').classList.add('hidden');
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeModal();
        closeDeleteModal();
        closeCreateModal();
        closeResetPasswordModal();
    }
});

</script>

<?php echo Currency::getJavaScriptHelper(); ?>
<?php echo Currency::getPhoneInputJavaScript(); ?>

<?php endif; ?>

<?php adminFooter(); ?>
