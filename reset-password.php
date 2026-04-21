<?php
/**
 * Cardify - Reset Password
 * Handles password reset using a valid token
 */
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/Mailer.php';

$brandName = defined('SITE_NAME') ? SITE_NAME : 'Cardify';
$pageTitle = 'Reset Password';
$htmlClass = 'h-full bg-white';
$bodyClass = 'h-full';

$message = null;
$messageType = 'success';
$resetComplete = false;
$tokenValid = false;
$tokenData = null;
$debugInfo = [];

$token = $_GET['token'] ?? $_POST['token'] ?? '';

$extraHead = <<<HTML
    <style>
        .form-input {
            display: block;
            width: 100%;
            border-radius: 0.5rem;
            background-color: #f9fafb;
            border: 1px solid #d1d5db;
            padding: 0.625rem 0.875rem;
            font-size: 0.875rem;
            color: #111827;
            outline: none;
            transition: all 0.15s ease;
        }
        .form-input:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }
        .form-input::placeholder {
            color: #9ca3af;
        }
    </style>
HTML;

/**
 * Validate the reset token
 */
function validateResetToken($db, $token) {
    if (empty($token)) {
        return null;
    }
    
    try {
        // Token is stored hashed — hash the incoming token to compare
        $hashedToken = hash('sha256', $token);
        $tokenData = $db->fetchOne(
            "SELECT * FROM password_reset_tokens
             WHERE token = :token
             AND expires_at > NOW()
             AND used_at IS NULL",
            ['token' => $hashedToken]
        );
        return $tokenData;
    } catch (Exception $e) {
        error_log("Token validation error: " . $e->getMessage());
        return null;
    }
}

/**
 * Update user password based on user type
 */
function updateUserPassword($db, $tokenData, $newPassword) {
    global $debugInfo;
    $passwordHash = password_hash($newPassword, PASSWORD_BCRYPT);
    $userType = $tokenData['user_type'];
    $userId = $tokenData['user_id'];
    $email = $tokenData['email'];
    
    $debugInfo['user_type'] = $userType;
    $debugInfo['user_id'] = $userId;
    $debugInfo['email'] = $email;
    $debugInfo['steps'] = [];
    
    try {
        switch ($userType) {
            case 'user':
                // Update users table
                $db->update('users', 
                    ['password_hash' => $passwordHash],
                    'id = :id',
                    ['id' => $userId]
                );
                break;
                
            case 'print_shop':
                $debugInfo['steps'][] = "Entered print_shop case";
                
                // Print shop - check if user_id is prefixed (ps_123) meaning we need to find user via print_shop
                if (strpos($userId, 'ps_') === 0) {
                    $printShopId = substr($userId, 3); // Remove 'ps_' prefix
                    $debugInfo['steps'][] = "Has ps_ prefix, extracted printShopId: {$printShopId}";
                    
                    // Get the print shop's linked user_id
                    $printShop = $db->fetchOne(
                        "SELECT id, user_id, email, name FROM print_shops WHERE id = :id",
                        ['id' => $printShopId]
                    );
                    
                    $debugInfo['print_shop_lookup'] = $printShop;
                    
                    if ($printShop && !empty($printShop['user_id'])) {
                        $debugInfo['steps'][] = "Print shop has user_id: {$printShop['user_id']}";
                        // Update the linked user's password
                        $rowsUpdated = $db->update('users',
                            ['password_hash' => $passwordHash],
                            'id = :id',
                            ['id' => $printShop['user_id']]
                        );
                        $debugInfo['rows_updated'] = $rowsUpdated;
                        // Also sync the user's email to match print shop email
                        try {
                            $db->update('users',
                                ['email' => $email],
                                'id = :id',
                                ['id' => $printShop['user_id']]
                            );
                        } catch (Exception $e) {
                            $debugInfo['email_sync_error'] = $e->getMessage();
                        }
                    } else {
                        // No linked user - try by email as fallback
                        $debugInfo['steps'][] = "No user_id linked, trying email lookup";
                        $user = $db->fetchOne("SELECT id FROM users WHERE email = :email", ['email' => $email]);
                        $debugInfo['user_by_email'] = $user;
                        
                        if ($user) {
                            $db->update('users',
                                ['password_hash' => $passwordHash],
                                'id = :id',
                                ['id' => $user['id']]
                            );
                            // Link this user to the print shop
                            $db->update('print_shops',
                                ['user_id' => $user['id']],
                                'id = :id',
                                ['id' => $printShopId]
                            );
                            $debugInfo['steps'][] = "Updated user by email lookup and linked to print shop";
                        } else {
                            // No user exists at all - CREATE a new user account for this print shop
                            $debugInfo['steps'][] = "No user exists - creating new user account";
                            
                            // Generate UUID for the new user
                            $newUserId = generateUUID();
                            
                            $db->insert('users', [
                                'id' => $newUserId,
                                'email' => $email,
                                'password_hash' => $passwordHash,
                                'name' => $printShop['name'] ?? 'Print Shop',
                                'role' => 'print_shop',
                                'status' => 'active',
                                'created_at' => date('Y-m-d H:i:s')
                            ]);
                            
                            $debugInfo['new_user_id'] = $newUserId;
                            
                            // Link the new user to the print shop
                            $db->update('print_shops',
                                ['user_id' => $newUserId],
                                'id = :id',
                                ['id' => $printShopId]
                            );
                            $debugInfo['steps'][] = "Created user (id: {$newUserId}) and linked to print shop";
                        }
                    }
                } else {
                    // Has direct users table entry (no ps_ prefix)
                    $debugInfo['steps'][] = "No ps_ prefix, direct user update for id: {$userId}";
                    $rowsUpdated = $db->update('users', 
                        ['password_hash' => $passwordHash],
                        'id = :id',
                        ['id' => $userId]
                    );
                    $debugInfo['rows_updated'] = $rowsUpdated;
                    if ($rowsUpdated === 0) {
                        $debugInfo['steps'][] = "FAILED: No rows updated - user id might not exist";
                        return false;
                    }
                }
                break;
                
            case 'company':
                // Update companies table
                $db->update('companies',
                    ['password_hash' => $passwordHash],
                    'id = :id',
                    ['id' => $userId]
                );
                break;
                
            case 'employee':
                // Update employees table
                $db->update('employees',
                    ['password_hash' => $passwordHash],
                    'id = :id',
                    ['id' => $userId]
                );
                break;
                
            default:
                $debugInfo['steps'][] = "FAILED: Unknown user type: {$userType}";
                return false;
        }
        
        $debugInfo['steps'][] = "Password updated successfully, marking token as used";
        
        // Mark token as used
        $db->update('password_reset_tokens',
            ['used_at' => date('Y-m-d H:i:s')],
            'token = :token',
            ['token' => $tokenData['token']]
        );
        
        $debugInfo['steps'][] = "SUCCESS";
        return true;
    } catch (Exception $e) {
        $debugInfo['exception'] = $e->getMessage();
        $debugInfo['steps'][] = "EXCEPTION: " . $e->getMessage();
        return false;
    }
}

/**
 * Send password changed confirmation email
 */
function sendPasswordChangedEmail($email, $name) {
    $siteName = defined('SITE_NAME') ? SITE_NAME : 'Cardify';
    $loginUrl = getBaseUrl() . 'login.php';
    
    $subject = "Your Password Has Been Changed - {$siteName}";
    $body = <<<HTML
<h2>Password Changed Successfully</h2>
<p>Hi {$name},</p>
<p>This is a confirmation that the password for your account (<strong>{$email}</strong>) has been changed.</p>

<div class="success-box" style="background: #f0fdf4; border-left: 4px solid #22c55e; padding: 15px; margin: 15px 0; border-radius: 0 4px 4px 0;">
    <strong>✓ Your password has been updated</strong><br>
    You can now sign in with your new password.
</div>

<p style="text-align: center; margin: 30px 0;">
    <a href="{$loginUrl}" class="btn" style="display: inline-block; padding: 12px 24px; background: #2563eb; color: white; text-decoration: none; border-radius: 6px; font-weight: 500;">
        Sign In
    </a>
</p>

<div class="warning-box" style="background: #fffbeb; border-left: 4px solid #f59e0b; padding: 15px; margin: 15px 0; border-radius: 0 4px 4px 0;">
    <strong>Didn't change your password?</strong><br>
    If you didn't make this change, please contact us immediately. Someone may have accessed your account.
</div>

<p>Best regards,<br>The {$siteName} Team</p>
HTML;
    
    return Mailer::send($email, $subject, $body);
}

// Check database availability
if (!DatabaseAdapter::useDatabase()) {
    $message = 'Password reset is not available.';
    $messageType = 'error';
} else {
    $db = Database::getInstance();
    
    // Validate token
    if (!empty($token)) {
        $tokenData = validateResetToken($db, $token);
        $tokenValid = !empty($tokenData);
    }
    
    // Handle form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tokenValid) {
        if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
            $message = 'Invalid request. Please try again.';
            $messageType = 'error';
        } else {
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        
        if (empty($password) || empty($confirmPassword)) {
            $message = 'Please fill in all fields';
            $messageType = 'error';
        } elseif (strlen($password) < 8) {
            $message = 'Password must be at least 8 characters';
            $messageType = 'error';
        } elseif ($password !== $confirmPassword) {
            $message = 'Passwords do not match';
            $messageType = 'error';
        } else {
            // Update password
            global $debugInfo;
            $debugInfo = [];
            $debugInfo['token_data'] = $tokenData;
            $updated = updateUserPassword($db, $tokenData, $password);
            
            if ($updated) {
                $resetComplete = true;
                $message = 'Your password has been reset successfully!';
                
                // Get user name for email
                $userName = 'User';
                try {
                    switch ($tokenData['user_type']) {
                        case 'user':
                            $user = $db->fetchOne("SELECT name FROM users WHERE id = :id", ['id' => $tokenData['user_id']]);
                            $userName = $user['name'] ?? 'User';
                            break;
                        case 'print_shop':
                            // Try to get name from print_shops table by email
                            $printShop = $db->fetchOne("SELECT name FROM print_shops WHERE email = :email", ['email' => $tokenData['email']]);
                            $userName = $printShop['name'] ?? 'Print Shop';
                            break;
                        case 'company':
                            $company = $db->fetchOne("SELECT name FROM companies WHERE id = :id", ['id' => $tokenData['user_id']]);
                            $userName = $company['name'] ?? 'Admin';
                            break;
                        case 'employee':
                            $employee = $db->fetchOne("SELECT name_en FROM employees WHERE id = :id", ['id' => $tokenData['user_id']]);
                            $userName = $employee['name_en'] ?? 'Employee';
                            break;
                    }
                } catch (Exception $e) {
                    // Use default name
                }
                
                // Send confirmation email
                sendPasswordChangedEmail($tokenData['email'], $userName);
            } else {
                $message = 'Failed to reset password. Please try again.';
                $messageType = 'error';
                // Log debug info server-side only
                if (!empty($debugInfo)) {
                    error_log('Password reset failure debug: ' . json_encode($debugInfo));
                }
            }
        }
        } // end CSRF else
    }
}
$minimalFooter = true; // compact footer for auth page
require_once INCLUDES_DIR . '/ui-header.php';
?>
    <div class="flex flex-col justify-center items-center px-6 pt-8 mx-auto min-h-screen">
        <a href="<?php echo getBasePath(); ?>" class="flex items-center gap-3 mb-8 lg:mb-10">
            <img src="<?php echo assetUrl('images/logo.svg'); ?>" class="h-10 w-auto" alt="<?php echo $brandName; ?>">
        </a>
        
        <!-- Card -->
        <div class="w-full bg-white rounded-2xl shadow-lg border border-gray-100 sm:max-w-md xl:p-0">
            <div class="p-6 sm:p-8">
                <?php if ($resetComplete): ?>
                <!-- Success State -->
                <div class="text-center">
                    <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-6">
                        <i class="fa-solid fa-circle-check text-3xl text-green-600"></i>
                    </div>
                    <h2 class="mb-3 text-2xl font-bold text-gray-900">
                        Password reset complete
                    </h2>
                    <p class="text-gray-500 mb-6">
                        <?php echo htmlspecialchars($message); ?> You can now sign in with your new password.
                    </p>
                    <a href="<?php echo getBasePath(); ?>login.php" class="inline-flex items-center justify-center w-full px-5 py-3 text-base font-medium text-white bg-blue-600 rounded-lg hover:bg-blue-700 focus:ring-4 focus:ring-blue-300 transition-colors">
                        Sign in
                        <i class="fa-solid fa-arrow-right ml-2"></i>
                    </a>
                </div>
                <?php elseif (!$tokenValid): ?>
                <!-- Invalid Token State -->
                <div class="text-center">
                    <div class="w-16 h-16 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-6">
                        <i class="fa-solid fa-link-slash text-3xl text-red-600"></i>
                    </div>
                    <h2 class="mb-3 text-2xl font-bold text-gray-900">
                        Invalid or expired link
                    </h2>
                    <p class="text-gray-500 mb-6">
                        This password reset link is invalid or has expired. Reset links are valid for 1 hour. Please request a new one.
                    </p>
                    <a href="<?php echo getBasePath(); ?>forgot-password.php" class="inline-flex items-center justify-center w-full px-5 py-3 text-base font-medium text-white bg-blue-600 rounded-lg hover:bg-blue-700 focus:ring-4 focus:ring-blue-300 transition-colors">
                        Request new link
                        <i class="fa-solid fa-arrow-right ml-2"></i>
                    </a>
                </div>
                <?php else: ?>
                <!-- Form State -->
                <h2 class="mb-3 text-2xl font-bold text-gray-900">
                    Reset your password
                </h2>
                <p class="text-gray-500 mb-6">
                    Enter your new password below. Make sure it's at least 8 characters.
                </p>
                
                <?php if ($message && $messageType === 'error'): ?>
                <div class="mb-6 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-800">
                    <div class="flex items-center gap-3">
                        <i class="fa-solid fa-circle-exclamation flex-shrink-0"></i>
                        <span><?php echo $message; ?></span>
                    </div>
                </div>
                <?php endif; ?>
                
                <form method="POST" class="space-y-6">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                    
                    <div>
                        <label for="password" class="block mb-2 text-sm font-medium text-gray-900">
                            New password
                        </label>
                        <input type="password" name="password" id="password" 
                               class="form-input" 
                               placeholder="••••••••" required minlength="8"
                               autocomplete="new-password">
                        <p class="mt-1.5 text-xs text-gray-500">Minimum 8 characters</p>
                    </div>
                    
                    <div>
                        <label for="confirm_password" class="block mb-2 text-sm font-medium text-gray-900">
                            Confirm new password
                        </label>
                        <input type="password" name="confirm_password" id="confirm_password" 
                               class="form-input" 
                               placeholder="••••••••" required minlength="8"
                               autocomplete="new-password">
                    </div>
                    
                    <button type="submit" class="w-full px-5 py-3 text-base font-medium text-white bg-blue-600 rounded-lg hover:bg-blue-700 focus:ring-4 focus:ring-blue-300 transition-colors">
                        Reset password
                        <i class="fa-solid fa-check ml-2"></i>
                    </button>
                </form>
                
                <p class="mt-6 text-center text-sm text-gray-500">
                    Remember your password?
                    <a href="<?php echo getBasePath(); ?>login.php" class="font-semibold text-blue-600 hover:text-blue-500">
                        Sign in
                    </a>
                </p>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Back to Home -->
        <p class="mt-8 text-center text-sm text-gray-500">
            <a href="<?php echo getBasePath(); ?>" class="font-medium text-gray-700 hover:text-gray-900">
                <i class="fa-solid fa-arrow-left mr-1"></i>
                Back to homepage
            </a>
        </p>
    </div>
<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
