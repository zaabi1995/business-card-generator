<?php
/**
 * Super Admin Settings
 * Manage account settings, change email and password
 */
require_once __DIR__ . '/../../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/AuditLog.php';
require_once INCLUDES_DIR . '/admin-layout.php';

Auth::requireRole('super_admin');

$db = Database::getInstance();
$currentUser = Auth::getCurrentUser();
$message = '';
$messageType = '';

if (!$currentUser) {
    header('Location: ' . getBasePath() . 'login.php');
    exit;
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !validateCSRFToken($_POST['csrf_token'] ?? '')) {
    die('Invalid request');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_profile') {
        $newEmail = sanitizeEmail($_POST['email'] ?? '');
        $currentPassword = $_POST['current_password'] ?? '';
        
        // Verify current password
        if (empty($currentPassword)) {
            $message = 'Current password is required to update profile';
            $messageType = 'error';
        } elseif (!password_verify($currentPassword, $currentUser['password_hash'])) {
            $message = 'Current password is incorrect';
            $messageType = 'error';
        } elseif (empty($newEmail)) {
            $message = 'Email address is required';
            $messageType = 'error';
        } else {
            // Check if email is already in use by another user
            $existingUser = $db->fetchOne(
                "SELECT id FROM users WHERE email = :email AND id != :id",
                ['email' => $newEmail, 'id' => $currentUser['id']]
            );
            
            if ($existingUser) {
                $message = 'Email address is already in use by another account';
                $messageType = 'error';
            } else {
                $oldData = ['email' => $currentUser['email']];
                $newData = ['email' => $newEmail];
                
                $db->update('users', [
                    'email' => $newEmail,
                    'updated_at' => dbNow()
                ], 'id = :id', ['id' => $currentUser['id']]);
                
                // Update session
                $_SESSION['user_email'] = $newEmail;
                
                AuditLog::log('update_profile', 'user', $currentUser['id'], $oldData, $newData);
                
                $message = 'Profile updated successfully';
                $messageType = 'success';
                
                // Refresh user data
                $currentUser = Auth::getCurrentUser();
            }
        }
    } elseif ($action === 'change_password') {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        
        if (empty($currentPassword)) {
            $message = 'Current password is required';
            $messageType = 'error';
        } elseif (!password_verify($currentPassword, $currentUser['password_hash'])) {
            $message = 'Current password is incorrect';
            $messageType = 'error';
        } elseif (empty($newPassword)) {
            $message = 'New password is required';
            $messageType = 'error';
        } elseif (strlen($newPassword) < 8) {
            $message = 'New password must be at least 8 characters long';
            $messageType = 'error';
        } elseif ($newPassword !== $confirmPassword) {
            $message = 'New passwords do not match';
            $messageType = 'error';
        } else {
            $db->update('users', [
                'password_hash' => password_hash($newPassword, PASSWORD_BCRYPT),
                'updated_at' => dbNow()
            ], 'id = :id', ['id' => $currentUser['id']]);
            
            AuditLog::log('change_password', 'user', $currentUser['id'], null, ['password_changed' => true]);
            
            $message = 'Password changed successfully';
            $messageType = 'success';
            
            // Refresh user data
            $currentUser = Auth::getCurrentUser();
        }
    } elseif ($action === 'update_name') {
        $newName = sanitize($_POST['name'] ?? '');
        $currentPassword = $_POST['current_password'] ?? '';
        
        if (empty($currentPassword)) {
            $message = 'Current password is required to update profile';
            $messageType = 'error';
        } elseif (!password_verify($currentPassword, $currentUser['password_hash'])) {
            $message = 'Current password is incorrect';
            $messageType = 'error';
        } elseif (empty($newName)) {
            $message = 'Name is required';
            $messageType = 'error';
        } else {
            $oldData = ['name' => $currentUser['name']];
            $newData = ['name' => $newName];
            
            $db->update('users', [
                'name' => $newName,
                'updated_at' => dbNow()
            ], 'id = :id', ['id' => $currentUser['id']]);
            
            // Update session
            $_SESSION['user_name'] = $newName;
            
            AuditLog::log('update_profile', 'user', $currentUser['id'], $oldData, $newData);
            
            $message = 'Name updated successfully';
            $messageType = 'success';
            
            // Refresh user data
            $currentUser = Auth::getCurrentUser();
        }
    }
}

// Get recent activity for this user
$recentActivity = $db->fetchAll(
    "SELECT * FROM audit_logs 
     WHERE actor_id = :user_id 
     ORDER BY created_at DESC 
     LIMIT 10",
    ['user_id' => $currentUser['id']]
);

// Get login history
$loginHistory = $db->fetchAll(
    "SELECT * FROM audit_logs 
     WHERE actor_id = :user_id AND action IN ('login', 'logout')
     ORDER BY created_at DESC 
     LIMIT 20",
    ['user_id' => $currentUser['id']]
);

adminHeader('Account Settings', 'settings');
?>

<?php if ($message): ?>
<div class="mb-6 p-4 rounded-lg <?php echo $messageType === 'success' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'; ?>">
    <div class="flex items-center">
        <i class="fa-solid <?php echo $messageType === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?> mr-2"></i>
        <?php echo sanitize($message); ?>
    </div>
</div>
<?php endif; ?>

<div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
    <!-- Account Overview -->
    <div class="xl:col-span-1">
        <div class="bg-white rounded-lg shadow p-6 mb-6">
            <div class="text-center mb-6">
                <div class="w-20 h-20 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center mx-auto mb-4 text-3xl font-bold">
                    <?php echo strtoupper(substr($currentUser['name'] ?? $currentUser['email'], 0, 2)); ?>
                </div>
                <h2 class="text-xl font-semibold text-gray-900"><?php echo sanitize($currentUser['name'] ?? 'Super Admin'); ?></h2>
                <p class="text-sm text-gray-500"><?php echo sanitize($currentUser['email']); ?></p>
                <span class="inline-flex items-center px-3 py-1 mt-2 rounded-full text-xs font-semibold bg-purple-100 text-purple-700">
                    <i class="fa-solid fa-shield-halved mr-1"></i> Super Admin
                </span>
            </div>
            
            <div class="border-t border-gray-100 pt-4 space-y-3">
                <div class="flex justify-between text-sm">
                    <span class="text-gray-500">Account ID</span>
                    <span class="text-gray-900 font-mono text-xs"><?php echo substr($currentUser['id'], 0, 8); ?>...</span>
                </div>
                <div class="flex justify-between text-sm">
                    <span class="text-gray-500">Status</span>
                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-semibold bg-green-100 text-green-700">
                        <?php echo ucfirst($currentUser['status'] ?? 'active'); ?>
                    </span>
                </div>
                <div class="flex justify-between text-sm">
                    <span class="text-gray-500">Last Login</span>
                    <span class="text-gray-900">
                        <?php echo $currentUser['last_login_at'] ? date('M d, Y H:i', dbTs($currentUser['last_login_at'])) : 'N/A'; ?>
                    </span>
                </div>
                <div class="flex justify-between text-sm">
                    <span class="text-gray-500">Account Created</span>
                    <span class="text-gray-900">
                        <?php echo $currentUser['created_at'] ? date('M d, Y', dbTs($currentUser['created_at'])) : 'N/A'; ?>
                    </span>
                </div>
            </div>
        </div>
        
        <!-- Quick Stats -->
        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="font-semibold text-gray-900 mb-4">Activity Summary</h3>
            <div class="space-y-3">
                <?php
                $totalActions = $db->fetchOne(
                    "SELECT COUNT(*) as count FROM audit_logs WHERE actor_id = :user_id",
                    ['user_id' => $currentUser['id']]
                )['count'] ?? 0;
                
                $todayActions = $db->fetchOne(
                    "SELECT COUNT(*) as count FROM audit_logs WHERE actor_id = :user_id AND DATE(created_at) = CURDATE()",
                    ['user_id' => $currentUser['id']]
                )['count'] ?? 0;
                
                $loginCount = $db->fetchOne(
                    "SELECT COUNT(*) as count FROM audit_logs WHERE actor_id = :user_id AND action = 'login'",
                    ['user_id' => $currentUser['id']]
                )['count'] ?? 0;
                ?>
                <div class="flex justify-between text-sm">
                    <span class="text-gray-500">Total Actions</span>
                    <span class="text-gray-900 font-semibold"><?php echo number_format($totalActions); ?></span>
                </div>
                <div class="flex justify-between text-sm">
                    <span class="text-gray-500">Actions Today</span>
                    <span class="text-gray-900 font-semibold"><?php echo number_format($todayActions); ?></span>
                </div>
                <div class="flex justify-between text-sm">
                    <span class="text-gray-500">Total Logins</span>
                    <span class="text-gray-900 font-semibold"><?php echo number_format($loginCount); ?></span>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Settings Forms -->
    <div class="xl:col-span-2 space-y-6">
        <!-- Update Profile -->
        <div class="bg-white rounded-lg shadow">
            <div class="p-6 border-b border-gray-100">
                <h3 class="text-lg font-semibold text-gray-900">Profile Information</h3>
                <p class="text-sm text-gray-500 mt-1">Update your account's profile information and email address.</p>
            </div>
            <form method="POST" class="p-6 space-y-4">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="update_profile">
                
                <div>
                    <label for="email" class="block text-sm font-medium text-gray-700 mb-1">Email Address</label>
                    <input type="email" id="email" name="email" value="<?php echo sanitize($currentUser['email']); ?>" required
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                </div>
                
                <div>
                    <label for="profile_password" class="block text-sm font-medium text-gray-700 mb-1">Current Password</label>
                    <input type="password" id="profile_password" name="current_password" required
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                           placeholder="Enter current password to confirm changes">
                    <p class="text-xs text-gray-500 mt-1">Required to verify your identity</p>
                </div>
                
                <div class="flex justify-end">
                    <button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                        <i class="fa-solid fa-save mr-2"></i>Save Changes
                    </button>
                </div>
            </form>
        </div>
        
        <!-- Update Name -->
        <div class="bg-white rounded-lg shadow">
            <div class="p-6 border-b border-gray-100">
                <h3 class="text-lg font-semibold text-gray-900">Display Name</h3>
                <p class="text-sm text-gray-500 mt-1">Update your display name shown across the platform.</p>
            </div>
            <form method="POST" class="p-6 space-y-4">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="update_name">
                
                <div>
                    <label for="name" class="block text-sm font-medium text-gray-700 mb-1">Display Name</label>
                    <input type="text" id="name" name="name" value="<?php echo sanitize($currentUser['name'] ?? ''); ?>" required
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                </div>
                
                <div>
                    <label for="name_password" class="block text-sm font-medium text-gray-700 mb-1">Current Password</label>
                    <input type="password" id="name_password" name="current_password" required
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                           placeholder="Enter current password to confirm changes">
                </div>
                
                <div class="flex justify-end">
                    <button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                        <i class="fa-solid fa-save mr-2"></i>Update Name
                    </button>
                </div>
            </form>
        </div>
        
        <!-- Change Password -->
        <div class="bg-white rounded-lg shadow">
            <div class="p-6 border-b border-gray-100">
                <h3 class="text-lg font-semibold text-gray-900">Change Password</h3>
                <p class="text-sm text-gray-500 mt-1">Ensure your account is using a long, random password to stay secure.</p>
            </div>
            <form method="POST" class="p-6 space-y-4">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="change_password">
                
                <div>
                    <label for="current_password" class="block text-sm font-medium text-gray-700 mb-1">Current Password</label>
                    <input type="password" id="current_password" name="current_password" required
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                </div>
                
                <div>
                    <label for="new_password" class="block text-sm font-medium text-gray-700 mb-1">New Password</label>
                    <input type="password" id="new_password" name="new_password" required minlength="8"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <p class="text-xs text-gray-500 mt-1">Minimum 8 characters</p>
                </div>
                
                <div>
                    <label for="confirm_password" class="block text-sm font-medium text-gray-700 mb-1">Confirm New Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" required minlength="8"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                </div>
                
                <div class="flex justify-end">
                    <button type="submit" class="px-6 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors">
                        <i class="fa-solid fa-key mr-2"></i>Change Password
                    </button>
                </div>
            </form>
        </div>
        
        <!-- Login History -->
        <div class="bg-white rounded-lg shadow">
            <div class="p-6 border-b border-gray-100 flex items-center justify-between">
                <div>
                    <h3 class="text-lg font-semibold text-gray-900">Login History</h3>
                    <p class="text-sm text-gray-500 mt-1">Recent authentication events for your account.</p>
                </div>
                <a href="../audit-logs.php?action=login,logout&actor=<?php echo urlencode($currentUser['email']); ?>" 
                   class="text-sm text-blue-600 hover:text-blue-800">
                    View All &rarr;
                </a>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left text-gray-500">
                    <thead class="text-xs text-gray-700 uppercase bg-gray-50">
                        <tr>
                            <th scope="col" class="px-6 py-3">Event</th>
                            <th scope="col" class="px-6 py-3">IP Address</th>
                            <th scope="col" class="px-6 py-3">Date & Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($loginHistory)): ?>
                        <tr>
                            <td colspan="3" class="px-6 py-6 text-center text-gray-500">No login history found.</td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($loginHistory as $event): ?>
                        <tr class="bg-white border-b hover:bg-gray-50">
                            <td class="px-6 py-4">
                                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-semibold 
                                    <?php echo $event['action'] === 'login' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-700'; ?>">
                                    <i class="fa-solid <?php echo $event['action'] === 'login' ? 'fa-sign-in-alt' : 'fa-sign-out-alt'; ?> mr-1"></i>
                                    <?php echo ucfirst($event['action']); ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 font-mono text-xs">
                                <?php echo sanitize($event['ip_address'] ?? 'N/A'); ?>
                            </td>
                            <td class="px-6 py-4">
                                <?php echo date('M d, Y H:i:s', dbTs($event['created_at'])); ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php adminFooter(); ?>
