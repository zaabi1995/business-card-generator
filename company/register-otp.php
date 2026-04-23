<?php
/**
 * 3-field OTP signup: company_name + admin_phone + admin_email. Sends a
 * 6-digit code to the phone (WhatsApp), falls back to email if phone is
 * absent, verifies the code, provisions the tenant with a random
 * unrevealed password, logs the user in via Auth::loginUser, and
 * redirects straight to the onboarding wizard.
 *
 * Password fallback is preserved: admins can always set a password later
 * from admin/settings-security.php. Existing password-based signups
 * continue to work through company/register.php.
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/OtpService.php';
require_once INCLUDES_DIR . '/Onboarding.php';
require_once INCLUDES_DIR . '/I18n.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$stage = 'request';      // request | verify
$error = null;
$info  = null;

$companyName = trim((string) ($_SESSION['otp_signup']['company_name'] ?? ($_POST['company_name'] ?? '')));
$adminName   = trim((string) ($_SESSION['otp_signup']['admin_name']   ?? ($_POST['admin_name']   ?? '')));
$email       = trim((string) ($_SESSION['otp_signup']['email']        ?? ($_POST['email']        ?? '')));
$phone       = trim((string) ($_SESSION['otp_signup']['phone']        ?? ($_POST['phone']        ?? '')));

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = t('register_otp.err_csrf');
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'request') {
            $companyName = trim($_POST['company_name'] ?? '');
            $adminName   = trim($_POST['admin_name'] ?? '');
            $email       = trim($_POST['email'] ?? '');
            $phone       = trim($_POST['phone'] ?? '');

            if ($companyName === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = t('register_otp.err_missing_fields');
            } elseif (Auth::emailExists($email)['exists'] ?? false) {
                $error = t('register_otp.err_email_taken');
            } else {
                $channel    = $phone !== '' ? 'whatsapp' : 'email';
                $identifier = $channel === 'whatsapp' ? $phone : $email;
                $res = OtpService::send($identifier, $channel, 'signup');
                if (!empty($res['ok'])) {
                    $_SESSION['otp_signup'] = [
                        'company_name' => $companyName,
                        'admin_name'   => $adminName,
                        'email'        => $email,
                        'phone'        => $phone,
                        'channel'      => $channel,
                        'identifier'   => $identifier,
                        'sent_at'      => time(),
                    ];
                    $stage = 'verify';
                    $info  = t('register_otp.info_sent_' . $channel);
                } else {
                    $error = t('register_otp.err_send_' . ($res['error'] ?? 'failed'), [], t('register_otp.err_send_failed'));
                }
            }
        } elseif ($action === 'verify') {
            $state = $_SESSION['otp_signup'] ?? null;
            if (!$state) {
                $error = t('register_otp.err_expired');
            } else {
                $code = preg_replace('/\D+/', '', (string) ($_POST['code'] ?? ''));
                $ver  = OtpService::verify($state['identifier'], $code, 'signup');
                if (empty($ver['ok'])) {
                    $error = t('register_otp.err_' . ($ver['error'] ?? 'wrong_code'), [], t('register_otp.err_wrong_code'));
                    $stage = 'verify';
                } else {
                    $randomPw = bin2hex(random_bytes(16));
                    $result = createCompany($state['company_name'], $state['email'], $randomPw);
                    if (empty($result['success'])) {
                        $error = t('register_otp.err_provision_failed');
                        $stage = 'verify';
                    } else {
                        $company = $result['company'];
                        $userRes = Auth::createUser(
                            $state['email'],
                            $randomPw,
                            $state['admin_name'] ?: $state['company_name'],
                            'company',
                            $company['id']
                        );
                        if (!empty($userRes['user_id'])) {
                            try {
                                $db = Database::getInstance();
                                if ($state['phone'] !== '') {
                                    $db->update('users', ['phone' => $state['phone']], 'id = :id', ['id' => $userRes['user_id']]);
                                    $db->update('companies', ['phone' => $state['phone']], 'id = :id', ['id' => $company['id']]);
                                }
                            } catch (Throwable $e) { /* columns optional */ }

                            Onboarding::saveMeta($company['id'], [
                                'admin_name'    => $state['admin_name'] ?: $state['company_name'],
                                'admin_email'   => $state['email'],
                                'admin_phone'   => $state['phone'] !== '' ? $state['phone'] : null,
                                'company_name'  => $state['company_name'],
                                'first_employee' => [
                                    'name'  => $state['admin_name'] ?: $state['company_name'],
                                    'email' => $state['email'],
                                    'phone' => $state['phone'],
                                    'title' => '',
                                ],
                                'otp_signup' => true,
                            ]);

                            $user = $db->fetchOne("SELECT * FROM users WHERE id = :id", ['id' => $userRes['user_id']]);
                            if ($user) Auth::loginUser($user);

                            unset($_SESSION['otp_signup']);

                            $slug = $company['slug'] ?? '';
                            $target = getBasePath() . ($slug ? "{$slug}/admin/onboarding" : 'admin/onboarding.php');
                            header('Location: ' . $target);
                            exit;
                        }
                        $error = t('register_otp.err_provision_failed');
                        $stage = 'verify';
                    }
                }
            }
        }
    }
}

if (isset($_SESSION['otp_signup']) && $stage === 'request' && !$error) {
    $stage = 'verify';
}

$pageTitle = t('register_otp.page_title');
$csrfToken = generateCSRFToken();
$dir       = currentDir();
$isRtl     = $dir === 'rtl';

require_once INCLUDES_DIR . '/ui-header.php';
?>
<main class="min-h-screen flex items-center justify-center bg-gray-50 px-4 py-12" dir="<?= htmlspecialchars($dir) ?>">
    <div class="w-full max-w-md bg-white rounded-2xl shadow-sm border border-gray-200 p-8">
        <div class="text-center mb-6">
            <h1 class="text-2xl font-bold text-gray-900"><?= htmlspecialchars(t('register_otp.headline')) ?></h1>
            <p class="text-sm text-gray-500 mt-1"><?= htmlspecialchars(t('register_otp.subhead')) ?></p>
        </div>

        <?php if ($error): ?>
            <div class="mb-4 rounded-lg bg-red-50 border border-red-200 text-red-800 text-sm p-3">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>
        <?php if ($info): ?>
            <div class="mb-4 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm p-3">
                <?= htmlspecialchars($info) ?>
            </div>
        <?php endif; ?>

        <?php if ($stage === 'request'): ?>
            <form method="POST" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action" value="request">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1"><?= htmlspecialchars(t('register_otp.company_name')) ?></label>
                    <input type="text" name="company_name" required maxlength="150" value="<?= htmlspecialchars($companyName) ?>"
                           class="w-full px-4 py-2.5 rounded-lg border border-gray-200 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1"><?= htmlspecialchars(t('register_otp.admin_name')) ?></label>
                    <input type="text" name="admin_name" maxlength="120" value="<?= htmlspecialchars($adminName) ?>"
                           class="w-full px-4 py-2.5 rounded-lg border border-gray-200 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1"><?= htmlspecialchars(t('register_otp.admin_email')) ?></label>
                    <input type="email" name="email" required value="<?= htmlspecialchars($email) ?>" dir="ltr"
                           class="w-full px-4 py-2.5 rounded-lg border border-gray-200 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1"><?= htmlspecialchars(t('register_otp.admin_phone')) ?></label>
                    <input type="tel" name="phone" value="<?= htmlspecialchars($phone) ?>" dir="ltr" placeholder="+968 90000000"
                           class="w-full px-4 py-2.5 rounded-lg border border-gray-200 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <p class="text-xs text-gray-500 mt-1"><?= htmlspecialchars(t('register_otp.phone_hint')) ?></p>
                </div>
                <button type="submit" class="w-full py-3 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-lg">
                    <?= htmlspecialchars(t('register_otp.send_code')) ?>
                </button>
                <p class="text-xs text-center text-gray-500 mt-2">
                    <a href="<?= htmlspecialchars(getBasePath()) ?>login.php" class="text-blue-600 hover:underline"><?= htmlspecialchars(t('register_otp.have_account')) ?></a>
                </p>
            </form>
        <?php else: /* verify */ ?>
            <form method="POST" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action" value="verify">
                <p class="text-sm text-gray-600 text-center">
                    <?= htmlspecialchars(t('register_otp.code_sent_to', ['where' => ($_SESSION['otp_signup']['identifier'] ?? '')])) ?>
                </p>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1 text-center"><?= htmlspecialchars(t('register_otp.code_label')) ?></label>
                    <input type="text" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autofocus dir="ltr"
                           class="w-full tracking-widest text-center text-2xl font-mono px-4 py-3 rounded-lg border border-gray-200 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                </div>
                <button type="submit" class="w-full py-3 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-lg">
                    <?= htmlspecialchars(t('register_otp.verify_cta')) ?>
                </button>
                <p class="text-xs text-center text-gray-500">
                    <a href="?resend=1" class="text-blue-600 hover:underline"><?= htmlspecialchars(t('register_otp.resend')) ?></a>
                </p>
            </form>
        <?php endif; ?>
    </div>
</main>
<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
