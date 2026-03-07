<?php
/**
 * Employee View - Profile editor, card preview, order history
 */
require_once INCLUDES_DIR . '/Currency.php';

$employeeId = $_SESSION['employee_id'] ?? $_SESSION['user_id'] ?? null;
$message = null;
$messageType = 'success';

// Get employee data
$employee = null;
if ($employeeId) {
    $employee = $db->fetchOne("SELECT * FROM employees WHERE id = :id AND company_id = :cid", [
        'id' => $employeeId,
        'cid' => $company['id']
    ]);
}

if (!$employee) {
    // User is logged in but not an employee of this company
    header('Location: ' . getBasePath() . $companySlug . '/');
    exit;
}

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    $updateData = [
        'name_en' => trim($_POST['name_en'] ?? ''),
        'name_ar' => trim($_POST['name_ar'] ?? ''),
        'position_en' => trim($_POST['position_en'] ?? ''),
        'position_ar' => trim($_POST['position_ar'] ?? ''),
        'phone' => trim($_POST['phone'] ?? ''),
        'mobile' => trim($_POST['mobile'] ?? ''),
        'website' => trim($_POST['website'] ?? ''),
        'address' => trim($_POST['address'] ?? ''),
        'updated_at' => date('Y-m-d H:i:s')
    ];
    
    try {
        $db->update('employees', $updateData, 'id = :id', ['id' => $employeeId]);
        $message = 'Profile updated successfully!';
        
        // Refresh employee data
        $employee = $db->fetchOne("SELECT * FROM employees WHERE id = :id", ['id' => $employeeId]);
        
        // Audit log
        if (class_exists('AuditLog')) {
            AuditLog::log('update', 'employee', $employeeId, null, $updateData, $company['id']);
        }
    } catch (Exception $e) {
        $message = 'Failed to update profile: ' . $e->getMessage();
        $messageType = 'error';
    }
}

// Get employee's generated cards
$generatedCards = [];
try {
    $generatedCards = $db->fetchAll(
        "SELECT * FROM generated_cards WHERE employee_id = :id ORDER BY generated_at DESC LIMIT 10",
        ['id' => $employeeId]
    );
} catch (Exception $e) {}

// Get employee's orders
$employeeOrders = [];
try {
    $employeeOrders = $db->fetchAll(
        "SELECT o.*, oi.quantity 
         FROM orders o 
         JOIN order_items oi ON o.id = oi.order_id 
         WHERE oi.employee_id = :id 
         ORDER BY o.created_at DESC LIMIT 10",
        ['id' => $employeeId]
    );
} catch (Exception $e) {}

$currency = $company['currency'] ?? 'OMR';

$extraHead = '<style>
    :root {
        --primary-color: ' . $primaryColor . ';
        --secondary-color: ' . $secondaryColor . ';
    }
    body { font-family: "Plus Jakarta Sans", ui-sans-serif, system-ui, sans-serif; }
    .btn-primary { 
        background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
        color: white;
    }
    ' . (!empty($companyTheme['custom_css']) ? $companyTheme['custom_css'] : '') . '
</style>';
require_once INCLUDES_DIR . '/ui-header.php';
?>

<div class="min-h-screen bg-gray-50">
    <!-- Header -->
    <header class="bg-white border-b border-gray-200">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-4">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-4">
                    <?php if ($companyTheme && !empty($companyTheme['logo_path'])): ?>
                    <img src="<?php echo imageUrl($companyTheme['logo_path']); ?>" alt="<?php echo sanitize($company['name']); ?>" class="h-10">
                    <?php else: ?>
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-blue-500 to-blue-600 flex items-center justify-center text-white font-bold">
                        <?php echo strtoupper(substr($company['name'], 0, 2)); ?>
                    </div>
                    <?php endif; ?>
                    <div>
                        <h1 class="text-lg font-bold text-gray-900"><?php echo sanitize($company['name']); ?></h1>
                        <p class="text-sm text-gray-500">Employee Portal</p>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <span class="text-sm text-gray-600"><?php echo sanitize($employee['email']); ?></span>
                    <a href="<?php echo getBasePath(); ?>logout.php" class="text-sm text-red-600 hover:text-red-800">
                        Sign Out
                    </a>
                </div>
            </div>
        </div>
    </header>

    <main class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <?php if ($message): ?>
        <div class="mb-6 p-4 rounded-xl flex items-center gap-3 <?php echo $messageType === 'success' ? 'bg-green-50 border border-green-200 text-green-700' : 'bg-red-50 border border-red-200 text-red-700'; ?>">
            <i class="<?php echo $messageType === 'success' ? 'fa-solid fa-circle-check' : 'fa-solid fa-circle-exclamation'; ?>"></i>
            <?php echo sanitize($message); ?>
        </div>
        <?php endif; ?>

        <div class="grid lg:grid-cols-3 gap-6">
            <!-- Profile Form -->
            <div class="lg:col-span-2">
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
                    <div class="p-4 border-b border-gray-100">
                        <h2 class="font-semibold text-gray-900">Your Profile</h2>
                        <p class="text-sm text-gray-500">Update your information for your business card</p>
                    </div>
                    
                    <form method="post" class="p-6">
                        <input type="hidden" name="action" value="update_profile">
                        
                        <div class="grid md:grid-cols-2 gap-4 mb-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Name (English)</label>
                                <input type="text" name="name_en" value="<?php echo sanitize($employee['name_en'] ?? ''); ?>"
                                       class="w-full px-4 py-2 bg-gray-50 border border-gray-200 rounded-lg focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Name (Arabic)</label>
                                <input type="text" name="name_ar" value="<?php echo sanitize($employee['name_ar'] ?? ''); ?>" dir="rtl"
                                       class="w-full px-4 py-2 bg-gray-50 border border-gray-200 rounded-lg focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                            </div>
                        </div>
                        
                        <div class="grid md:grid-cols-2 gap-4 mb-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Position (English)</label>
                                <input type="text" name="position_en" value="<?php echo sanitize($employee['position_en'] ?? ''); ?>"
                                       class="w-full px-4 py-2 bg-gray-50 border border-gray-200 rounded-lg focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Position (Arabic)</label>
                                <input type="text" name="position_ar" value="<?php echo sanitize($employee['position_ar'] ?? ''); ?>" dir="rtl"
                                       class="w-full px-4 py-2 bg-gray-50 border border-gray-200 rounded-lg focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                            </div>
                        </div>
                        
                        <div class="grid md:grid-cols-2 gap-4 mb-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Phone</label>
                                <input type="tel" name="phone" value="<?php echo sanitize($employee['phone'] ?? ''); ?>"
                                       class="w-full px-4 py-2 bg-gray-50 border border-gray-200 rounded-lg focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Mobile</label>
                                <input type="tel" name="mobile" value="<?php echo sanitize($employee['mobile'] ?? ''); ?>"
                                       class="w-full px-4 py-2 bg-gray-50 border border-gray-200 rounded-lg focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                            </div>
                        </div>
                        
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Website</label>
                            <input type="url" name="website" value="<?php echo sanitize($employee['website'] ?? ''); ?>"
                                   class="w-full px-4 py-2 bg-gray-50 border border-gray-200 rounded-lg focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                        </div>
                        
                        <div class="mb-6">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Address</label>
                            <textarea name="address" rows="2"
                                      class="w-full px-4 py-2 bg-gray-50 border border-gray-200 rounded-lg focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20"><?php echo sanitize($employee['address'] ?? ''); ?></textarea>
                        </div>
                        
                        <button type="submit" class="px-6 py-2 btn-primary rounded-lg font-medium">
                            Save Changes
                        </button>
                    </form>
                </div>
            </div>

            <!-- Sidebar -->
            <div class="space-y-6">
                <!-- Card Preview -->
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
                    <div class="p-4 border-b border-gray-100">
                        <h3 class="font-semibold text-gray-900">Your Card</h3>
                    </div>
                    <div class="p-4">
                        <?php if (!empty($generatedCards)): ?>
                        <?php $latestCard = $generatedCards[0]; ?>
                        <?php if (!empty($latestCard['front_file_path'])): ?>
                        <img src="<?php echo imageUrl($latestCard['front_file_path']); ?>" alt="Your Card" class="w-full rounded-lg shadow-sm mb-3">
                        <?php endif; ?>
                        <p class="text-sm text-gray-500">Generated <?php echo date('M j, Y', strtotime($latestCard['generated_at'])); ?></p>
                        <?php else: ?>
                        <div class="text-center py-6 text-gray-500">
                            <i class="fa-solid fa-id-card text-3xl opacity-30 mb-2"></i>
                            <p class="text-sm">Your card hasn't been generated yet.</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Order History -->
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
                    <div class="p-4 border-b border-gray-100">
                        <h3 class="font-semibold text-gray-900">Your Orders</h3>
                    </div>
                    <div class="divide-y divide-gray-100">
                        <?php if (empty($employeeOrders)): ?>
                        <div class="p-4 text-center text-gray-500 text-sm">
                            No orders yet.
                        </div>
                        <?php else: ?>
                        <?php foreach ($employeeOrders as $order): ?>
                        <div class="p-4">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="font-medium text-gray-900 text-sm">#<?php echo sanitize($order['order_number']); ?></p>
                                    <p class="text-xs text-gray-500"><?php echo $order['quantity']; ?> cards</p>
                                </div>
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium
                                    <?php echo match($order['status']) {
                                        'completed' => 'bg-green-100 text-green-700',
                                        'pending' => 'bg-amber-100 text-amber-700',
                                        'cancelled' => 'bg-red-100 text-red-700',
                                        default => 'bg-gray-100 text-gray-700'
                                    }; ?>">
                                    <?php echo ucfirst($order['status']); ?>
                                </span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
