<?php
/**
 * Division sign in.
 *
 * A division head has no account here. They type the address their approvals
 * already go to, and a one-time link is sent to that address: holding the link
 * is the sign-in. The page answers the same way whether or not the address is
 * on a division, so it cannot be used to find out who approves what.
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/SecurityHeaders.php';
SecurityHeaders::send();
require_once INCLUDES_DIR . '/TenantHost.php';
require_once INCLUDES_DIR . '/AdminApprovalView.php';
require_once INCLUDES_DIR . '/DivisionLoginToken.php';
require_once INCLUDES_DIR . '/MhdMailer.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$slug = $_GET['company'] ?? ($_SESSION['company_slug'] ?? '');
if ($slug === '' && TenantHost::isTenantHost()) {
    $slug = (string) TenantHost::slug();
}
$company = $slug !== '' ? findCompanyBySlug($slug) : null;
if (!$company) {
    http_response_code(404);
    exit('Unknown company');
}

$error = null;
$sent  = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = t('divisionsettings.login_bad_email');
    } else {
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = t('divisionsettings.login_bad_email');
        } else {
            // Always the same answer. Only a division that already carries this
            // address gets a link, and the link goes to that address, never to
            // whatever was typed.
            $sent = true;
            $dept = DivisionLoginToken::divisionFor($company['id'], $email);
            if ($dept) {
                try {
                    $ip    = (string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '');
                    $token = DivisionLoginToken::mint($company['id'], $dept['id'], $email, $ip);
                    $link  = getTenantUrl($company['slug'], '/admin/division-settings?t=' . urlencode($token));
                    $e     = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);
                    $html  = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#222;line-height:1.6">'
                           . '<p>Here is your sign-in link for <strong>' . $e($dept['name']) . '</strong>.</p>'
                           . '<p style="margin:26px 0"><a href="' . $e($link) . '" style="background:#0f4c81;color:#fff;'
                           . 'padding:13px 30px;border-radius:6px;text-decoration:none;font-weight:bold;display:inline-block">'
                           . 'Open division settings</a></p>'
                           . '<p style="color:#6b7280;font-size:13px">It works once and lasts 30 minutes. '
                           . 'If you did not ask for it, nothing has changed and you can ignore this.</p>'
                           . '</div>';
                    MhdMailer::sendRaw([$email], [], 'Sign in to ' . $dept['name'] . ' settings', $html);
                } catch (Throwable $ex) {
                    error_log('[division login] ' . $ex->getMessage());
                }
            }
        }
    }
}

aat_page_open(t('divisionsettings.login_title'), isRtl());
?>
<div class="card">
    <div class="brandbar"></div>
    <?php if ($sent): ?>
        <h1><?= htmlspecialchars(t('divisionsettings.login_sent_h')) ?></h1>
        <p class="sub"><?= htmlspecialchars(t('divisionsettings.login_sent_b')) ?></p>
    <?php else: ?>
        <h1><?= htmlspecialchars(t('divisionsettings.login_title')) ?></h1>
        <p class="sub"><?= htmlspecialchars(t('divisionsettings.login_lead')) ?></p>
        <?php if ($error): ?>
            <p class="sub" style="color:#b91c1c"><?= htmlspecialchars($error) ?></p>
        <?php endif; ?>
        <form method="POST" style="margin-top:18px">
            <?= csrfField() ?>
            <label for="email" style="display:block;font-size:13px;color:#6b7280;margin-bottom:6px">
                <?= htmlspecialchars(t('divisionsettings.login_email')) ?>
            </label>
            <input type="email" name="email" id="email" required autofocus
                   style="width:100%;padding:11px 13px;border:1px solid #d1d5db;border-radius:8px;font-size:15px">
            <button type="submit" class="btn btn-primary" style="margin-top:14px">
                <?= htmlspecialchars(t('divisionsettings.login_button')) ?>
            </button>
        </form>
    <?php endif; ?>
</div>
<?php aat_page_close(); ?>
