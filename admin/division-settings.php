<?php
/**
 * A division's own settings, opened from the link in its sign-in email.
 *
 * Ali, 17 Sep 2026: a division should be able to sign in with its own email and
 * set its own master details, including which ERP account its quotation,
 * invoice and delivery note are issued against.
 *
 * The link signs the caller in for this one division. Every change is recorded
 * with who made it, and BHD and the previous address are told, because these
 * settings decide where a card request goes and who is billed for it.
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/SecurityHeaders.php';
SecurityHeaders::send();
require_once INCLUDES_DIR . '/AdminApprovalView.php';
require_once INCLUDES_DIR . '/DivisionLoginToken.php';
require_once INCLUDES_DIR . '/ERPSync.php';
require_once INCLUDES_DIR . '/MhdMailer.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$db = Database::getInstance();
$ip = (string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '');

// The link signs you in; after that the session carries it, so a save does not
// need the token again.
$token = (string)($_GET['t'] ?? '');
if ($token !== '') {
    $row = DivisionLoginToken::verify($token);
    if (!$row || !DivisionLoginToken::consume($token)) {
        aat_message_page(
            'Link expired - Cardify', "\xE2\x9A\xA0",
            t('divisionsettings.expired_h'), t('divisionsettings.expired_b'),
            t('divisionsettings.expired_h'), t('divisionsettings.expired_b')
        );
        exit;
    }
    session_regenerate_id(true);
    $_SESSION['division_admin'] = [
        'company_id'    => $row['company_id'],
        'department_id' => $row['department_id'],
        'email'         => $row['email'],
        'since'         => time(),
    ];
}

$sessionDiv = $_SESSION['division_admin'] ?? null;
// Four hours is a working session; after that the mailbox proves it again.
if (!$sessionDiv || (time() - (int)($sessionDiv['since'] ?? 0)) > 4 * 3600) {
    unset($_SESSION['division_admin']);
    aat_message_page(
        'Sign in - Cardify', "\xE2\x9C\x89",
        t('divisionsettings.expired_h'), t('divisionsettings.expired_b'),
        t('divisionsettings.expired_h'), t('divisionsettings.expired_b'),
        getBasePath() . 'admin/division-login', t('divisionsettings.login_button')
    );
    exit;
}

$dept = $db->fetchOne("SELECT * FROM departments WHERE id = :d AND company_id = :c",
                      ['d' => $sessionDiv['department_id'], 'c' => $sessionDiv['company_id']]);
if (!$dept) {
    unset($_SESSION['division_admin']);
    http_response_code(404);
    exit('Unknown division');
}

$notice = null;
$error  = null;

// The accounts they may choose between. Read from the ERP by the tenant's own
// name, so the list is real accounts and a quote can never mint a duplicate.
$company  = findCompanyById($dept['company_id']);
$accounts = ERPSync::searchClients((string)($company['name_en'] ?? $company['name'] ?? ''), 40);
$accountNames = array_column($accounts, 'name');
if ($dept['erp_client_name'] && !in_array($dept['erp_client_name'], $accountNames, true)) {
    array_unshift($accountNames, $dept['erp_client_name']);   // keep what it has
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request';
    } else {
        $head = strtolower(trim((string)($_POST['head_email'] ?? '')));
        $box  = strtolower(trim((string)($_POST['responsible_email'] ?? '')));
        $cc   = trim((string)($_POST['cc_emails'] ?? ''));
        $erp  = trim((string)($_POST['erp_client_name'] ?? ''));
        $qr   = !empty($_POST['include_qr_default']) ? 1 : 0;

        $bad = [];
        foreach (['head_email' => $head, 'responsible_email' => $box] as $field => $addr) {
            if ($addr !== '' && !filter_var($addr, FILTER_VALIDATE_EMAIL)) { $bad[] = $field; }
        }
        foreach (preg_split('/[,;]+/', $cc) as $one) {
            $one = trim($one);
            if ($one !== '' && !filter_var($one, FILTER_VALIDATE_EMAIL)) { $bad[] = 'cc_emails'; }
        }
        // Only an account the ERP actually has, so a typo cannot send the next
        // invoice to a client that does not exist.
        if ($erp !== '' && $accountNames && !in_array($erp, $accountNames, true)) {
            $bad[] = 'erp_client_name';
        }

        if ($bad) {
            $error = t('divisionsettings.invalid_email');
        } else {
            $changes = [];
            foreach ([
                'head_email'         => $head,
                'responsible_email'  => $box,
                'cc_emails'          => $cc,
                'erp_client_name'    => $erp !== '' ? $erp : $dept['erp_client_name'],
                'include_qr_default' => $qr,
            ] as $field => $value) {
                if ((string)($dept[$field] ?? '') === (string)$value) { continue; }
                $changes[$field] = [(string)($dept[$field] ?? ''), (string)$value];
            }

            if (!$changes) {
                $notice = t('divisionsettings.nothing_changed');
            } else {
                foreach ($changes as $field => [$old, $new]) {
                    $db->query("UPDATE departments SET `{$field}` = ? WHERE id = ?", [$new, $dept['id']]);
                    DivisionLoginToken::logChange($dept, (string)$sessionDiv['email'], $field, $old, $new, $ip);
                }

                // Tell BHD and whoever used to receive the approvals. A change
                // of approver is exactly the change nobody should be able to
                // make quietly.
                try {
                    $e    = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);
                    $rows = '';
                    foreach ($changes as $field => [$old, $new]) {
                        $rows .= '<tr><td style="padding:3px 14px 3px 0;color:#6b7280">' . $e(str_replace('_', ' ', $field))
                               . '</td><td style="padding:3px 0">' . $e($old !== '' ? $old : 'not set')
                               . ' &rarr; <strong>' . $e($new !== '' ? $new : 'not set') . '</strong></td></tr>';
                    }
                    $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#222;line-height:1.6">'
                          . '<p><strong>' . $e($sessionDiv['email']) . '</strong> changed the settings of '
                          . '<strong>' . $e($dept['name']) . '</strong>.</p>'
                          . '<table style="border-collapse:collapse;margin:12px 0">' . $rows . '</table>'
                          . '<p style="color:#6b7280;font-size:13px">Recorded ' . $e(date('d/m/Y H:i')) . ' GMT+4'
                          . ($ip !== '' ? ', IP ' . $e($ip) : '') . '.</p></div>';
                    $tell = array_values(array_unique(array_filter([
                        'sales@bhdoman.com',
                        $changes['head_email'][0] ?? '',
                        $changes['responsible_email'][0] ?? '',
                    ])));
                    MhdMailer::sendRaw($tell, [], 'Division settings changed: ' . $dept['name'], $html);
                } catch (Throwable $ex) {
                    error_log('[division settings] ' . $ex->getMessage());
                }

                $dept   = $db->fetchOne("SELECT * FROM departments WHERE id = :d", ['d' => $dept['id']]);
                $notice = t('divisionsettings.saved');
            }
        }
    }
}

$history = $db->fetchAll(
    "SELECT * FROM division_setting_changes WHERE department_id = :d ORDER BY created_at DESC LIMIT 10",
    ['d' => $dept['id']]);

aat_page_open(t('divisionsettings.title'), isRtl());
$e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);
?>
<div class="card" style="max-width:640px;text-align:start">
    <div class="brandbar"></div>
    <h1 style="text-align:start"><?= $e(t('divisionsettings.title')) ?></h1>
    <p class="sub" style="text-align:start"><?= $e(t('divisionsettings.lead')) ?></p>
    <p class="note" style="text-align:start"><?= $e(t('divisionsettings.division')) ?>:
        <strong><?= $e($dept['name']) ?></strong> &middot;
        <?= $e(t('divisionsettings.signed_in_as')) ?> <?= $e($sessionDiv['email']) ?></p>

    <?php if ($notice): ?>
        <p style="background:#ecfdf5;color:#065f46;padding:10px 14px;border-radius:8px"><?= $e($notice) ?></p>
    <?php endif; ?>
    <?php if ($error): ?>
        <p style="background:#fef2f2;color:#b91c1c;padding:10px 14px;border-radius:8px"><?= $e($error) ?></p>
    <?php endif; ?>

    <form method="POST" style="margin-top:14px">
        <?= csrfField() ?>
        <?php
        $fields = [
            ['head_email', t('divisionsettings.head'), t('divisionsettings.head_hint'), 'email'],
            ['responsible_email', t('divisionsettings.mailbox'), t('divisionsettings.mailbox_hint'), 'email'],
            ['cc_emails', t('divisionsettings.cc'), t('divisionsettings.cc_hint'), 'text'],
        ];
        foreach ($fields as [$name, $label, $hint, $type]): ?>
            <label style="display:block;margin-top:14px;font-size:13px;color:#374151"><?= $e($label) ?></label>
            <input type="<?= $type ?>" name="<?= $name ?>" value="<?= $e($dept[$name] ?? '') ?>"
                   style="width:100%;padding:10px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:14px">
            <span style="display:block;font-size:12px;color:#9ca3af;margin-top:4px"><?= $e($hint) ?></span>
        <?php endforeach; ?>

        <label style="display:block;margin-top:16px;font-size:13px;color:#374151"><?= $e(t('divisionsettings.erp_account')) ?></label>
        <?php if ($accountNames): ?>
            <select name="erp_client_name" style="width:100%;padding:10px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:14px">
                <?php foreach ($accountNames as $name): ?>
                    <option value="<?= $e($name) ?>"<?= $name === ($dept['erp_client_name'] ?? '') ? ' selected' : '' ?>><?= $e($name) ?></option>
                <?php endforeach; ?>
            </select>
            <span style="display:block;font-size:12px;color:#9ca3af;margin-top:4px"><?= $e(t('divisionsettings.erp_account_hint')) ?></span>
        <?php else: ?>
            <input type="hidden" name="erp_client_name" value="<?= $e($dept['erp_client_name'] ?? '') ?>">
            <p style="font-size:13px;color:#6b7280"><?= $e($dept['erp_client_name'] ?: '—') ?><br>
               <span style="color:#9ca3af"><?= $e(t('divisionsettings.no_erp_accounts')) ?></span></p>
        <?php endif; ?>

        <label style="display:flex;align-items:center;gap:8px;margin-top:16px;font-size:14px;color:#374151">
            <input type="checkbox" name="include_qr_default" value="1"<?= !empty($dept['include_qr_default']) ? ' checked' : '' ?>>
            <?= $e(t('divisionsettings.qr_default')) ?>
        </label>

        <button type="submit" class="btn btn-primary" style="margin-top:20px"><?= $e(t('divisionsettings.save')) ?></button>
    </form>

    <?php if ($history): ?>
        <h2 style="font-size:14px;color:#6b7280;margin-top:28px"><?= $e(t('divisionsettings.history')) ?></h2>
        <ul style="list-style:none;padding:0;margin:8px 0 0;font-size:13px;color:#4b5563">
            <?php foreach ($history as $h): ?>
                <li style="padding:4px 0;border-top:1px solid #f3f4f6">
                    <span style="color:#9ca3af"><?= $e(date('d/m H:i', strtotime((string)$h['created_at']))) ?></span>
                    <?= $e(str_replace('_', ' ', (string)$h['field'])) ?>:
                    <?= $e($h['old_value'] !== '' ? $h['old_value'] : '—') ?> &rarr; <strong><?= $e($h['new_value']) ?></strong>
                    <span style="color:#9ca3af"><?= $e(t('divisionsettings.changed_by')) ?> <?= $e($h['actor']) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
<?php aat_page_close(); ?>
