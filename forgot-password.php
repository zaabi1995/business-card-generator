<?php
/**
 * Cardify - Forgot Password
 * Handles password reset requests for all user types
 */
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/Mailer.php';

$brandName = defined('SITE_NAME') ? SITE_NAME : 'Cardify';
$pageTitle = t('forgot.page_title');
$htmlClass = 'h-full bg-white';
$bodyClass = 'h-full';

$message = null;
$messageType = 'success';
$emailSent = false;

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
            border-color: #009bc1;
            box-shadow: 0 0 0 3px rgba(0, 155, 193, 0.12);
        }
        .form-input::placeholder {
            color: #9ca3af;
        }
    </style>
HTML;

/**
 * Find user by email across all user tables
 * Returns user info and type if found
 */
function findUserByEmail($db, $email) {
    // Check users table (super_admin, print_shop, etc.)
    $user = $db->fetchOne(
        "SELECT id, email, name, role FROM users WHERE email = :email AND status = 'active'",
        ['email' => $email]
    );
    if ($user) {
        return ['user' => $user, 'type' => 'user', 'id' => $user['id']];
    }
    
    // Check companies table (company admin)
    $company = $db->fetchOne(
        "SELECT id, admin_email as email, name FROM companies WHERE admin_email = :email AND status = 'active'",
        ['email' => $email]
    );
    if ($company) {
        return ['user' => $company, 'type' => 'company', 'id' => $company['id']];
    }
    
    // Check print_shops table directly by email (print shop's own email field)
    try {
        $printShop = $db->fetchOne(
            "SELECT ps.id, ps.email, ps.name, ps.user_id
             FROM print_shops ps 
             WHERE ps.email = :email AND (ps.status = 'active' OR ps.status IS NULL)",
            ['email' => $email]
        );
        if ($printShop) {
            // Print shop found - we need to update the linked user's password
            // If there's a user_id, use that. Otherwise, try to find user by original email.
            $userId = $printShop['user_id'];
            
            if ($userId) {
                // Get the user record to ensure it exists
                $linkedUser = $db->fetchOne("SELECT id, email FROM users WHERE id = :id", ['id' => $userId]);
                if ($linkedUser) {
                    // Also update the user's email to match print shop email if different
                    if ($linkedUser['email'] !== $email) {
                        try {
                            $db->update('users', ['email' => $email], 'id = :id', ['id' => $userId]);
                        } catch (Exception $e) {
                            // Email might conflict, ignore
                        }
                    }
                    return ['user' => $printShop, 'type' => 'print_shop', 'id' => $userId, 'print_shop_id' => $printShop['id']];
                }
            }
            
            // No valid user link - return with print_shop prefix for handling
            return ['user' => $printShop, 'type' => 'print_shop', 'id' => 'ps_' . $printShop['id'], 'print_shop_id' => $printShop['id']];
        }
    } catch (Exception $e) {
        // print_shops table might not exist
        error_log("Print shop lookup error: " . $e->getMessage());
    }
    
    // Check employees table
    $employee = $db->fetchOne(
        "SELECT id, email, name_en as name FROM employees WHERE email = :email AND status = 'active'",
        ['email' => $email]
    );
    if ($employee) {
        return ['user' => $employee, 'type' => 'employee', 'id' => $employee['id']];
    }
    
    return null;
}

/**
 * Generate and store password reset token
 */
function createPasswordResetToken($db, $email, $userType, $userId) {
    // Delete any existing tokens for this email
    try {
        $db->query(
            "DELETE FROM password_reset_tokens WHERE email = :email",
            ['email' => $email]
        );
    } catch (Exception $e) {
        // Table might not exist, will be created on first use
    }
    
    // Generate secure token
    $token = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));
    
    try {
        // Store hashed token in DB, send plain token to user via email
        $hashedToken = hash('sha256', $token);
        $db->insert('password_reset_tokens', [
            'email' => $email,
            'token' => $hashedToken,
            'user_type' => $userType,
            'user_id' => $userId,
            'expires_at' => $expiresAt
        ]);
        return $token;
    } catch (Exception $e) {
        error_log("Failed to create password reset token: " . $e->getMessage());
        return null;
    }
}

/**
 * Send password reset email (via Notifier, email-only, no WhatsApp)
 */
function sendPasswordResetEmail($email, $name, $token) {
    $resetUrl = getBaseUrl() . 'reset-password.php?token=' . urlencode($token);

    try {
        require_once INCLUDES_DIR . '/Notifier.php';
        $result = Notifier::send('password_reset', [
            'name'  => $name ?: 'there',
            'email' => $email,
        ], [
            'name'             => $name ?: 'there',
            'resetUrl'         => $resetUrl,
            'expiresInMinutes' => 60,
        ], ['email']); // email-only, no WhatsApp for password reset
        // Notifier::send returns ['email' => bool, 'whatsapp' => bool]
        return !empty($result['email']);
    } catch (Throwable $e) {
        error_log('[password_reset] Notifier failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Send email when no account is found
 */
function sendNoAccountEmail($email) {
    $siteName = defined('SITE_NAME') ? SITE_NAME : 'Cardify';
    $registerUrl = getBaseUrl() . 'company/register.php';
    $contactUrl = getBaseUrl() . 'contact.php';
    
    $subject = t('forgot.noacc_subject', ['site' => $siteName]);
    $h2              = t('forgot.noacc_h2');
    $hi              = t('forgot.noacc_hi');
    $intro           = t('forgot.noacc_intro', ['email' => '<strong>' . htmlspecialchars($email) . '</strong>', 'site' => $siteName]);
    $boxTitle        = t('forgot.noacc_box_title');
    $boxBody         = t('forgot.noacc_box_body');
    $couldMean       = t('forgot.noacc_could_mean');
    $reason1         = t('forgot.noacc_reason_1');
    $reason2         = t('forgot.noacc_reason_2');
    $reason3         = t('forgot.noacc_reason_3');
    $whatToDo        = t('forgot.noacc_what_to_do');
    $ifAdmin         = t('forgot.noacc_if_admin');
    $registerBtn     = t('forgot.noacc_register_btn');
    $ifEmployee      = t('forgot.noacc_if_employee');
    $employeeMsg     = t('forgot.noacc_employee_msg');
    $needHelp        = t('forgot.noacc_need_help');
    $contactLink     = '<a href="' . htmlspecialchars($contactUrl) . '" style="color: #009bc1;">' . t('forgot.noacc_contact_link') . '</a>';
    $contactMsg      = t('forgot.noacc_contact_msg', ['contactlink' => $contactLink]);
    $security        = t('forgot.noacc_security');
    $securityMsg     = t('forgot.noacc_security_msg');
    $signoff         = t('forgot.noacc_signoff');
    $team            = t('forgot.noacc_team', ['site' => $siteName]);

    $body = <<<HTML
<h2>{$h2}</h2>
<p>{$hi}</p>
<p>{$intro}</p>

<div class="warning-box" style="background: #fffbeb; border-left: 4px solid #f59e0b; padding: 15px; margin: 15px 0; border-radius: 0 4px 4px 0;">
    <strong>{$boxTitle}</strong><br>
    {$boxBody}
</div>

<p>{$couldMean}</p>
<ul style="margin: 15px 0; padding-left: 20px;">
    <li>{$reason1}</li>
    <li>{$reason2}</li>
    <li>{$reason3}</li>
</ul>

<h3>{$whatToDo}</h3>

<p><strong>{$ifAdmin}</strong></p>
<p style="text-align: center; margin: 20px 0;">
    <a href="{$registerUrl}" class="btn" style="display: inline-block; padding: 12px 24px; background: #009bc1; color: white; text-decoration: none; border-radius: 6px; font-weight: 500;">
        {$registerBtn}
    </a>
</p>

<p><strong>{$ifEmployee}</strong></p>
<p>{$employeeMsg}</p>

<p><strong>{$needHelp}</strong></p>
<p>{$contactMsg}</p>

<div class="info-box" style="background: #e6f7fb; border-left: 4px solid #009bc1; padding: 15px; margin: 15px 0; border-radius: 0 4px 4px 0;">
    <strong>{$security}</strong><br>
    {$securityMsg}
</div>

<p>{$signoff}<br>{$team}</p>
HTML;

    return Mailer::send($email, $subject, $body);
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = t('forgot.invalid_csrf');
        $messageType = 'error';
    } else {
    $email = sanitizeEmail($_POST['email'] ?? '');

    if (empty($email)) {
        $message = t('forgot.enter_email');
        $messageType = 'error';
    } else {
        if (DatabaseAdapter::useDatabase()) {
            try {
                $db = Database::getInstance();
                $userInfo = findUserByEmail($db, $email);
                
                if ($userInfo) {
                    // User found - generate token and send password reset email
                    error_log("=== FORGOT PASSWORD ===");
                    error_log("User found: " . json_encode($userInfo));
                    error_log("Creating token with type={$userInfo['type']}, id={$userInfo['id']}");
                    
                    $token = createPasswordResetToken(
                        $db, 
                        $email, 
                        $userInfo['type'], 
                        $userInfo['id']
                    );
                    
                    if ($token) {
                        $name = $userInfo['user']['name'] ?? 'User';
                        $emailResult = sendPasswordResetEmail($email, $name, $token);
                        
                        if (!$emailResult) {
                            error_log("Failed to send password reset email to: $email");
                        }
                    }
                } else {
                    // No account found - send informational email
                    $emailResult = sendNoAccountEmail($email);
                    
                    if (!$emailResult) {
                        error_log("Failed to send no-account email to: $email");
                    }
                }
                
                // Always show same message for security (don't reveal if email exists)
                $emailSent = true;
                $message = t('forgot.sent_generic');

            } catch (Exception $e) {
                error_log("Password reset error: " . $e->getMessage());
                $message = t('forgot.generic_error');
                $messageType = 'error';
            }
        } else {
            $message = t('forgot.not_available');
            $messageType = 'error';
        }
    }
    } // end CSRF else
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
                <?php if ($emailSent): ?>
                <!-- Success State -->
                <div class="text-center">
                    <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-6">
                        <i class="fa-solid fa-envelope-circle-check text-3xl text-green-600"></i>
                    </div>
                    <h2 class="mb-3 text-2xl font-bold text-gray-900">
                        <?php echo htmlspecialchars(t('forgot.check_email_h1')); ?>
                    </h2>
                    <p class="text-gray-500 mb-6">
                        <?php echo htmlspecialchars($message); ?>
                    </p>
                    <p class="text-sm text-gray-400 mb-6">
                        <?php echo htmlspecialchars(t('forgot.check_spam_hint')); ?>
                    </p>
                    <a href="<?php echo getBasePath(); ?>login.php" class="inline-flex items-center justify-center w-full px-5 py-3 text-base font-medium text-white bg-blue-600 rounded-lg hover:bg-blue-700 focus:ring-4 focus:ring-blue-300 transition-colors">
                        <i class="fa-solid fa-arrow-left mr-2"></i>
                        <?php echo htmlspecialchars(t('forgot.back_to_sign_in')); ?>
                    </a>
                </div>
                <?php else: ?>
                <!-- Form State -->
                <h2 class="mb-3 text-2xl font-bold text-gray-900">
                    <?php echo htmlspecialchars(t('forgot.form_h1')); ?>
                </h2>
                <p class="text-gray-500 mb-6">
                    <?php echo htmlspecialchars(t('forgot.form_sub')); ?>
                </p>
                
                <?php if ($message && $messageType === 'error'): ?>
                <div class="mb-6 flex items-center gap-3 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-800">
                    <i class="fa-solid fa-circle-exclamation flex-shrink-0"></i>
                    <span><?php echo htmlspecialchars($message); ?></span>
                </div>
                <?php endif; ?>
                
                <form method="POST" class="space-y-6">
                    <?php echo csrfField(); ?>
                    <div>
                        <label for="email" class="block mb-2 text-sm font-medium text-gray-900">
                            <?php echo htmlspecialchars(t('forgot.email_label')); ?>
                        </label>
                        <input type="email" name="email" id="email"
                               value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                               class="form-input"
                               placeholder="<?php echo htmlspecialchars(t('forgot.email_placeholder')); ?>" required>
                    </div>

                    <button type="submit" class="w-full px-5 py-3 text-base font-medium text-white bg-blue-600 rounded-lg hover:bg-blue-700 focus:ring-4 focus:ring-blue-300 transition-colors">
                        <?php echo htmlspecialchars(t('forgot.submit_button')); ?>
                        <i class="fa-solid fa-paper-plane ml-2"></i>
                    </button>
                </form>

                <p class="mt-6 text-center text-sm text-gray-500">
                    <?php echo htmlspecialchars(t('forgot.remember_prompt')); ?>
                    <a href="<?php echo getBasePath(); ?>login.php" class="font-semibold text-blue-600 hover:text-blue-500">
                        <?php echo htmlspecialchars(t('forgot.sign_in_link')); ?>
                    </a>
                </p>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Back to Home -->
        <p class="mt-8 text-center text-sm text-gray-500">
            <a href="<?php echo getBasePath(); ?>" class="font-medium text-gray-700 hover:text-gray-900">
                <i class="fa-solid fa-arrow-left mr-1"></i>
                <?php echo htmlspecialchars(t('forgot.back_home')); ?>
            </a>
        </p>
    </div>
<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
