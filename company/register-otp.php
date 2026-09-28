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
require_once INCLUDES_DIR . '/Recaptcha.php';

if (session_status() === PHP_SESSION_NONE) session_start();

// Seconds before the code screen offers "Resend code". The countdown on the
// page and the server check below read the same value.
const OTP_SIGNUP_RESEND_SECONDS = 30;

// t()'s third argument is a locale, not a fallback string, so look the
// specific message up and fall back to the generic one by hand.
$otpMsg = static function (string $key, string $fallbackKey): string {
    $msg = t($key);
    return ($msg === '' || $msg === $key) ? t($fallbackKey) : $msg;
};

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

            $captchaToken = (string) ($_POST['recaptcha_token'] ?? '');
            $captcha = Recaptcha::verify($captchaToken, 'signup');
            if (empty($captcha['ok'])) {
                $error = t('register_otp.err_captcha_failed');
            } elseif ($companyName === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
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
                    $error = $otpMsg('register_otp.err_send_' . ($res['error'] ?? 'failed'), 'register_otp.err_send_failed');
                }
            }
        } elseif ($action === 'change') {
            // "Change email or number": drop the pending code and go back to
            // the first screen with the fields still filled in.
            $prev = $_SESSION['otp_signup'] ?? [];
            unset($_SESSION['otp_signup']);
            $companyName = (string) ($prev['company_name'] ?? $companyName);
            $adminName   = (string) ($prev['admin_name'] ?? $adminName);
            $email       = (string) ($prev['email'] ?? $email);
            $phone       = (string) ($prev['phone'] ?? $phone);
            $stage = 'request';
        } elseif ($action === 'resend' || $action === 'resend_email') {
            $state = $_SESSION['otp_signup'] ?? null;
            if (!$state) {
                $error = t('register_otp.err_expired');
            } else {
                $stage = 'verify';
                $wait  = OTP_SIGNUP_RESEND_SECONDS - (time() - (int) ($state['sent_at'] ?? 0));
                if ($wait > 0) {
                    $error = t('register_otp.err_resend_wait', ['seconds' => $wait]);
                } else {
                    $channel    = ($action === 'resend_email' || ($state['phone'] ?? '') === '') ? 'email' : ($state['channel'] ?? 'email');
                    $identifier = $channel === 'whatsapp' ? $state['phone'] : $state['email'];
                    $res = OtpService::send($identifier, $channel, 'signup');
                    if (!empty($res['ok'])) {
                        $_SESSION['otp_signup']['channel']    = $channel;
                        $_SESSION['otp_signup']['identifier'] = $identifier;
                        $_SESSION['otp_signup']['sent_at']    = time();
                        $info = t('register_otp.info_sent_' . $channel);
                    } else {
                        $error = $otpMsg('register_otp.err_send_' . ($res['error'] ?? 'failed'), 'register_otp.err_send_failed');
                    }
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
                    $error = $otpMsg('register_otp.err_' . ($ver['error'] ?? 'wrong_code'), 'register_otp.err_wrong_code');
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

                            try {
                                require_once INCLUDES_DIR . '/SlackAlert.php';
                                SlackAlert::tenantSignup(
                                    $state['company_name'],
                                    $state['email'],
                                    $state['phone'] !== '' ? $state['phone'] : null,
                                    'web-otp'
                                );
                            } catch (Throwable $e) {
                                error_log('[register-otp] Slack alert failed: ' . $e->getMessage());
                            }

                            unset($_SESSION['otp_signup']);

                            // Send straight to the tenant subdomain so the
                            // admin sees their branded host from minute one.
                            $slug = $company['slug'] ?? '';
                            $target = $slug
                                ? getTenantUrl($slug, '/admin/onboarding')
                                : getBasePath() . 'admin/onboarding.php';
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

$resendIn = 0;
if ($stage === 'verify' && isset($_SESSION['otp_signup'])) {
    $resendIn = max(0, OTP_SIGNUP_RESEND_SECONDS - (time() - (int) ($_SESSION['otp_signup']['sent_at'] ?? 0)));
}
$otpChannel    = (string) ($_SESSION['otp_signup']['channel'] ?? 'email');
$otpIdentifier = (string) ($_SESSION['otp_signup']['identifier'] ?? '');
$otpHasEmail   = !empty($_SESSION['otp_signup']['email']);

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
            <form method="POST" class="space-y-4" id="otp-request-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action" value="request">
                <input type="hidden" name="recaptcha_token" id="recaptcha_token" value="">
                <div>
                    <label for="otp_email" class="block text-sm font-medium text-gray-700 mb-1"><?= htmlspecialchars(t('register_otp.admin_email')) ?></label>
                    <input type="email" id="otp_email" name="email" required autocomplete="email" autocapitalize="off" spellcheck="false" value="<?= htmlspecialchars($email) ?>" dir="ltr"
                           class="w-full px-4 py-3 text-base rounded-lg border border-gray-200 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                </div>
                <div>
                    <label for="otp_phone" class="block text-sm font-medium text-gray-700 mb-1"><?= htmlspecialchars(t('register_otp.admin_phone')) ?> <span class="text-gray-400 font-normal"><?= htmlspecialchars(t('register_otp.optional')) ?></span></label>
                    <input type="tel" id="otp_phone" name="phone" autocomplete="tel" inputmode="tel" value="<?= htmlspecialchars($phone) ?>" dir="ltr" placeholder="+968 90000000" aria-describedby="otp_phone_hint"
                           class="w-full px-4 py-3 text-base rounded-lg border border-gray-200 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <p id="otp_phone_hint" class="text-xs text-gray-500 mt-1"><?= htmlspecialchars(t('register_otp.phone_hint')) ?></p>
                </div>
                <div>
                    <label for="otp_admin_name" class="block text-sm font-medium text-gray-700 mb-1"><?= htmlspecialchars(t('register_otp.admin_name')) ?> <span class="text-gray-400 font-normal"><?= htmlspecialchars(t('register_otp.optional')) ?></span></label>
                    <input type="text" id="otp_admin_name" name="admin_name" maxlength="120" autocomplete="name" value="<?= htmlspecialchars($adminName) ?>"
                           class="w-full px-4 py-3 text-base rounded-lg border border-gray-200 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                </div>
                <div>
                    <label for="otp_company" class="block text-sm font-medium text-gray-700 mb-1"><?= htmlspecialchars(t('register_otp.company_name')) ?></label>
                    <input type="text" id="otp_company" name="company_name" required maxlength="150" autocomplete="organization" value="<?= htmlspecialchars($companyName) ?>" aria-describedby="otp_company_hint"
                           class="w-full px-4 py-3 text-base rounded-lg border border-gray-200 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <p id="otp_company_hint" class="text-xs text-gray-500 mt-1"><?= htmlspecialchars(t('register_otp.company_hint')) ?></p>
                </div>
                <button type="submit" class="w-full min-h-[48px] py-3 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-lg">
                    <?= htmlspecialchars(t('register_otp.send_code')) ?>
                </button>
                <p class="text-sm text-center text-gray-500 mt-2">
                    <a href="<?= htmlspecialchars(getBasePath()) ?>login.php" class="inline-flex items-center min-h-[44px] text-blue-600 hover:underline"><?= htmlspecialchars(t('register_otp.have_account')) ?></a>
                </p>
                <p class="text-xs text-center text-gray-500">
                    <a href="<?= htmlspecialchars(getBasePath()) ?>company/register.php" class="inline-flex items-center min-h-[44px] text-gray-600 underline hover:text-gray-900"><?= htmlspecialchars(t('register_otp.full_form_link')) ?></a>
                </p>
            </form>
        <?php else: /* verify */ ?>
            <form method="POST" class="space-y-4" id="otp-verify-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action" value="verify">
                <p class="text-sm text-gray-600 text-center">
                    <?php
                    // Escape the sentence, then put the address back in bold.
                    $__sent = htmlspecialchars(t('register_otp.code_sent_to', ['where' => '%%WHERE%%']));
                    echo str_replace('%%WHERE%%', '<strong class="font-semibold text-gray-900" dir="ltr">' . htmlspecialchars($otpIdentifier) . '</strong>', $__sent);
                    ?>
                </p>
                <div>
                    <label for="otp_code" class="block text-sm font-medium text-gray-700 mb-1 text-center"><?= htmlspecialchars(t('register_otp.code_label')) ?></label>
                    <input type="text" id="otp_code" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required autofocus dir="ltr"
                           class="w-full tracking-widest text-center text-2xl font-mono px-4 py-3 rounded-lg border border-gray-200 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <p class="text-xs text-gray-500 mt-2 text-center"><?= htmlspecialchars(t('register_otp.delay_note')) ?></p>
                </div>
                <button type="submit" class="w-full min-h-[48px] py-3 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-lg">
                    <?= htmlspecialchars(t('register_otp.verify_cta')) ?>
                </button>
            </form>
            <div class="mt-4 space-y-2 text-center">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="action" value="resend">
                    <p id="otp_resend_wait" class="text-sm text-gray-500 min-h-[44px] flex items-center justify-center"<?= $resendIn > 0 ? '' : ' hidden' ?>>
                        <span><?= htmlspecialchars(t('register_otp.resend_in')) ?> <span dir="ltr"><span id="otp_resend_seconds"><?= (int) $resendIn ?></span><?= htmlspecialchars(t('register_otp.seconds_suffix')) ?></span></span>
                    </p>
                    <button type="submit" id="otp_resend_btn" class="w-full min-h-[48px] py-3 border border-gray-300 text-gray-900 font-semibold rounded-lg hover:bg-gray-50"<?= $resendIn > 0 ? ' hidden' : '' ?>>
                        <?= htmlspecialchars(t('register_otp.resend')) ?>
                    </button>
                </form>
                <?php if ($otpChannel === 'whatsapp' && $otpHasEmail): ?>
                <form method="POST" id="otp_email_form"<?= $resendIn > 0 ? ' hidden' : '' ?>>
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="action" value="resend_email">
                    <button type="submit" class="min-h-[44px] text-sm text-blue-600 hover:underline"><?= htmlspecialchars(t('register_otp.send_by_email')) ?></button>
                </form>
                <?php endif; ?>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="action" value="change">
                    <button type="submit" class="min-h-[44px] text-sm text-gray-600 underline hover:text-gray-900"><?= htmlspecialchars(t('register_otp.change_identifier')) ?></button>
                </form>
            </div>
            <script<?= cspNonceAttr() ?>>
            (function () {
                // Submit on the sixth digit, so a code pasted or offered by the
                // iPhone keyboard (one-time-code) needs no extra tap.
                var code = document.getElementById('otp_code');
                var form = document.getElementById('otp-verify-form');
                if (code && form) {
                    code.addEventListener('input', function () {
                        var digits = code.value.replace(/\D+/g, '').slice(0, 6);
                        if (digits !== code.value) code.value = digits;
                        if (digits.length === 6 && !form.dataset.sent) {
                            form.dataset.sent = '1';
                            form.submit();
                        }
                    });
                }
                // Resend countdown. The server enforces the same wait.
                var left = <?= (int) $resendIn ?>;
                var wait = document.getElementById('otp_resend_wait');
                var secs = document.getElementById('otp_resend_seconds');
                var btn  = document.getElementById('otp_resend_btn');
                var alt  = document.getElementById('otp_email_form');
                if (left > 0 && wait && secs && btn) {
                    var timer = setInterval(function () {
                        left -= 1;
                        secs.textContent = String(Math.max(left, 0));
                        if (left <= 0) {
                            clearInterval(timer);
                            wait.hidden = true;
                            btn.hidden = false;
                            if (alt) alt.hidden = false;
                        }
                    }, 1000);
                }
            })();
            </script>
        <?php endif; ?>
    </div>
</main>
<?php @include __DIR__ . '/../views/partials/trust_logo_strip.php'; ?>
<?php if (Recaptcha::isConfigured()): $siteKey = Recaptcha::siteKey(); ?>
<script src="https://www.google.com/recaptcha/api.js?render=<?= htmlspecialchars($siteKey) ?>"></script>
<script<?= cspNonceAttr() ?>>
(function(){
    var form = document.getElementById('otp-request-form');
    if (!form) return;
    form.addEventListener('submit', function(e){
        if (form.dataset.captchaDone === '1') return;
        e.preventDefault();
        grecaptcha.ready(function(){
            grecaptcha.execute(<?= json_encode($siteKey) ?>, {action: 'signup'}).then(function(token){
                document.getElementById('recaptcha_token').value = token;
                form.dataset.captchaDone = '1';
                form.submit();
            });
        });
    });
})();
</script>
<?php endif; ?>
<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
