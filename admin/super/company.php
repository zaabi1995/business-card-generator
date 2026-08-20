<?php
/**
 * Super Admin - Company detail console.
 *
 * One page to see EVERYTHING about a single tenant company and control it:
 * overview + branding, team, designs, cards/credits, engagement, orders,
 * credit accounts, payments, integrations, audit. Plus top actions:
 * impersonate, suspend/activate, reset password, schedule deletion.
 *
 * All mutations reuse the tested writers (DatabaseAdapter, CreditManager,
 * Impersonation, TenantDeletion) and are audit-logged. company.id is a UUID
 * string, never cast to int.
 */
require_once __DIR__ . '/../../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/AuditLog.php';
require_once INCLUDES_DIR . '/CreditManager.php';
require_once INCLUDES_DIR . '/Impersonation.php';
require_once INCLUDES_DIR . '/TenantDeletion.php';
require_once INCLUDES_DIR . '/admin-layout.php';

Auth::requireRole('super_admin');

$db = Database::getInstance();

$companyId = trim($_POST['company_id'] ?? $_GET['id'] ?? '');
$company   = $companyId !== '' ? DatabaseAdapter::findCompanyById($companyId) : null;

// Small display helpers (guarded so they never collide on re-include).
if (!function_exists('cdm_money')) {
    function cdm_money($amount, $currency = 'OMR') {
        $currency = $currency ?: 'OMR';
        if (class_exists('Currency') && method_exists('Currency', 'format')) {
            try { return Currency::format((float)$amount, $currency); } catch (\Throwable $e) {}
        }
        return number_format((float)$amount, 3) . ' ' . $currency;
    }
}
if (!function_exists('cdm_badge')) {
    function cdm_badge($status) {
        $s = strtolower((string)$status);
        $map = [
            'active' => 'bg-green-100 text-green-700', 'approved' => 'bg-green-100 text-green-700',
            'paid' => 'bg-green-100 text-green-700', 'completed' => 'bg-green-100 text-green-700',
            'delivered' => 'bg-green-100 text-green-700',
            'pending' => 'bg-amber-100 text-amber-700', 'quote' => 'bg-amber-100 text-amber-700',
            'quotation' => 'bg-amber-100 text-amber-700', 'in_progress' => 'bg-amber-100 text-amber-700',
            'printing' => 'bg-amber-100 text-amber-700', 'in_production' => 'bg-amber-100 text-amber-700',
            'shipped' => 'bg-blue-100 text-blue-700', 'ready' => 'bg-blue-100 text-blue-700',
            'suspended' => 'bg-red-100 text-red-700', 'rejected' => 'bg-red-100 text-red-700',
            'cancelled' => 'bg-red-100 text-red-700', 'failed' => 'bg-red-100 text-red-700',
            'inactive' => 'bg-gray-100 text-gray-700',
        ];
        return $map[$s] ?? 'bg-blue-100 text-blue-700';
    }
}

// ---------------------------------------------------------------------------
// POST handler (PRG). Runs before any output so header()/redirect work.
// ---------------------------------------------------------------------------
$tab = preg_replace('/[^a-z_]/', '', $_REQUEST['tab'] ?? 'overview') ?: 'overview';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { die('Invalid request'); }
    if (!$company) { header('Location: companies.php'); exit; }

    $action = $_POST['action'] ?? '';
    $flash  = null;

    $self = function ($t = null) use ($companyId, $tab) {
        return 'company.php?id=' . urlencode($companyId) . '&tab=' . urlencode($t ?: $tab);
    };

    switch ($action) {
        case 'update_company':
            $data = [
                'name'            => $_POST['name'] ?? '',
                'slug'            => $_POST['slug'] ?? '',
                'admin_email'     => $_POST['admin_email'] ?? '',
                'plan'            => sanitize($_POST['plan'] ?? ''),
                'currency'        => sanitize($_POST['currency'] ?? 'OMR'),
                'billing_email'   => $_POST['billing_email'] ?? '',
                'erp_client_name' => $_POST['erp_client_name'] ?? '',
            ];
            $res = DatabaseAdapter::updateCompany($companyId, $data);
            if (!empty($res['success'])) {
                AuditLog::logCompany('update', $companyId, $company, $res['company'] ?? null);
                $flash = ['success', 'Company details updated.'];
            } else {
                $flash = ['error', $res['error'] ?? 'Update failed'];
            }
            break;

        case 'update_theme':
            try {
                $themeData = [
                    'primary_color'   => substr(trim($_POST['primary_color'] ?? ''), 0, 7),
                    'secondary_color' => substr(trim($_POST['secondary_color'] ?? ''), 0, 7),
                    'updated_at'      => dbNow(),
                ];
                $existingTheme = $db->fetchOne("SELECT id FROM company_themes WHERE company_id = :cid", ['cid' => $companyId]);
                if ($existingTheme) {
                    $db->update('company_themes', $themeData, 'company_id = :cid', ['cid' => $companyId]);
                } else {
                    $themeData['id'] = generateUUID();
                    $themeData['company_id'] = $companyId;
                    $themeData['created_at'] = date('Y-m-d H:i:s');
                    $db->insert('company_themes', $themeData);
                }
                AuditLog::logCompany('update_theme', $companyId, null, $themeData);
                $flash = ['success', 'Branding colours updated.'];
            } catch (\Throwable $e) {
                $flash = ['error', 'Failed to update branding'];
            }
            break;

        case 'update_settings':
            $settings = [
                'whatsapp_enabled' => isset($_POST['whatsapp_enabled']) ? 1 : 0,
                'whatsapp_token'   => trim($_POST['whatsapp_token'] ?? ''),
                'odoo_enabled'     => isset($_POST['odoo_enabled']) ? 1 : 0,
                'odoo_url'         => trim($_POST['odoo_url'] ?? ''),
                'odoo_database'    => trim($_POST['odoo_database'] ?? ''),
                'odoo_username'    => trim($_POST['odoo_username'] ?? ''),
                'printer_enabled'  => isset($_POST['printer_enabled']) ? 1 : 0,
                'printer_name'     => trim($_POST['printer_name'] ?? ''),
            ];
            // Only overwrite Odoo password when a new one is typed.
            if (!empty($_POST['odoo_password'])) { $settings['odoo_password'] = $_POST['odoo_password']; }
            $res = DatabaseAdapter::updateCompanySettings($companyId, $settings);
            if (!empty($res['success'])) {
                AuditLog::log('update_settings', 'company_settings', $companyId, null, ['updated' => array_keys($settings)], $companyId);
                $flash = ['success', 'Integration settings saved.'];
            } else {
                $flash = ['error', $res['error'] ?? 'Failed to save settings'];
            }
            break;

        case 'add_employee':
            $res = DatabaseAdapter::addEmployee([
                'email'       => $_POST['email'] ?? '',
                'name_en'     => $_POST['name_en'] ?? '',
                'name_ar'     => $_POST['name_ar'] ?? '',
                'position_en' => $_POST['position_en'] ?? '',
                'position_ar' => $_POST['position_ar'] ?? '',
                'mobile'      => $_POST['mobile'] ?? '',
                'phone'       => $_POST['phone'] ?? '',
                'status'      => in_array($_POST['status'] ?? '', ['active', 'pending', 'suspended', 'inactive'], true) ? $_POST['status'] : 'active',
                'skip_invite' => !empty($_POST['skip_invite']),
            ], $companyId);
            if (!empty($res['success'])) {
                AuditLog::logUser('create', (string)($res['id'] ?? ''), null, ['email' => $_POST['email'] ?? ''], $companyId);
                $flash = ['success', 'Employee added.'];
            } else {
                $flash = ['error', $res['error'] ?? 'Failed to add employee'];
            }
            break;

        case 'update_employee':
            $empId  = trim($_POST['employee_id'] ?? '');
            $before = $empId !== '' ? DatabaseAdapter::findEmployeeById($empId, $companyId) : null;
            $res = DatabaseAdapter::updateEmployee($empId, [
                'email'       => $_POST['email'] ?? ($before['email'] ?? ''),
                'name_en'     => $_POST['name_en'] ?? '',
                'name_ar'     => $_POST['name_ar'] ?? '',
                'position_en' => $_POST['position_en'] ?? '',
                'position_ar' => $_POST['position_ar'] ?? '',
                'mobile'      => $_POST['mobile'] ?? '',
                'phone'       => $_POST['phone'] ?? '',
            ], $companyId);
            // Status is not handled by updateEmployee; apply it directly (whitelisted).
            if ($empId !== '' && in_array($_POST['status'] ?? '', ['active', 'pending', 'suspended', 'inactive'], true)) {
                try {
                    $db->update('employees', ['status' => $_POST['status'], 'updated_at' => dbNow()],
                        'id = :id AND company_id = :cid', ['id' => $empId, 'cid' => $companyId]);
                } catch (\Throwable $e) {}
            }
            if (!empty($res['success'])) {
                AuditLog::logUser('update', $empId, $before, null, $companyId);
                $flash = ['success', 'Employee updated.'];
            } else {
                $flash = ['error', $res['error'] ?? 'Failed to update employee'];
            }
            break;

        case 'order_status':
            $orderId = trim($_POST['order_id'] ?? '');
            $res = DatabaseAdapter::updateOrderStatus($orderId, $_POST['status'] ?? '', $_POST['reason'] ?? null);
            if (!empty($res['success'])) {
                AuditLog::logOrder('status_change', $orderId, ['status' => $res['before']], ['status' => $res['after']], $companyId);
                $flash = ['success', 'Order status updated.'];
            } else {
                $flash = ['error', $res['error'] ?? 'Failed to update order'];
            }
            break;

        case 'credit_adjust':
            $caId = trim($_POST['credit_account_id'] ?? '');
            $res = CreditManager::adjustLimit($caId, (float)($_POST['credit_limit'] ?? 0),
                $_POST['payment_terms'] ?: null,
                ($_POST['exposure_limit'] ?? '') !== '' ? (float)$_POST['exposure_limit'] : null);
            if (!empty($res['success'])) {
                AuditLog::log('credit_adjust_limit', 'credit_account', $caId, null, ['credit_limit' => $_POST['credit_limit'] ?? null], $companyId);
                $flash = ['success', 'Credit limit updated.'];
            } else {
                $flash = ['error', $res['error'] ?? 'Failed to adjust credit'];
            }
            break;

        case 'credit_payment':
            $caId = trim($_POST['credit_account_id'] ?? '');
            $res = CreditManager::recordPayment($caId, (float)($_POST['amount'] ?? 0), $_POST['notes'] ?? null, $_SESSION['user_email'] ?? ($_SESSION['user_id'] ?? 'super_admin'));
            if (!empty($res['transaction_id'])) {
                AuditLog::log('credit_payment', 'credit_account', $caId, null, ['amount' => $_POST['amount'] ?? null, 'balance_after' => $res['balance_after']], $companyId);
                $flash = ['success', 'Payment recorded against credit account.'];
            } else {
                $flash = ['error', $res['error'] ?? 'Failed to record payment'];
            }
            break;

        case 'credit_status':
            $caId = trim($_POST['credit_account_id'] ?? '');
            $op   = $_POST['op'] ?? '';
            $ok   = false;
            if ($op === 'suspend')        { $ok = CreditManager::suspend($caId); }
            elseif ($op === 'reactivate') { $ok = CreditManager::reactivate($caId); }
            elseif ($op === 'approve')    { $ok = CreditManager::approve($caId, (float)($_POST['credit_limit'] ?? 0), $_POST['payment_terms'] ?: 'net30', $_SESSION['user_id'] ?? 'super_admin'); }
            elseif ($op === 'reject')     { $ok = CreditManager::reject($caId, $_POST['reason'] ?? 'Rejected by admin', $_SESSION['user_id'] ?? 'super_admin'); }
            AuditLog::log('credit_' . $op, 'credit_account', $caId, null, null, $companyId);
            $flash = $ok ? ['success', 'Credit account ' . $op . 'd.'] : ['error', 'Credit action failed (check account status).'];
            break;

        case 'suspend_company':
            $res = DatabaseAdapter::updateCompany($companyId, ['status' => 'suspended']);
            AuditLog::logCompany('suspend', $companyId, $company, ['status' => 'suspended']);
            $flash = !empty($res['success']) ? ['success', 'Company suspended.'] : ['error', 'Failed to suspend'];
            break;

        case 'activate_company':
            $res = DatabaseAdapter::updateCompany($companyId, ['status' => 'active']);
            AuditLog::logCompany('activate', $companyId, $company, ['status' => 'active']);
            $flash = !empty($res['success']) ? ['success', 'Company activated.'] : ['error', 'Failed to activate'];
            break;

        case 'reset_password':
            if (($_POST['confirm_slug'] ?? '') !== ($company['slug'] ?? '~')) {
                $flash = ['error', 'Confirmation text did not match the slug. Password unchanged.'];
            } elseif (strlen($_POST['new_password'] ?? '') < 8) {
                $flash = ['error', 'Password must be at least 8 characters.'];
            } else {
                $res = DatabaseAdapter::updateCompany($companyId, ['password' => $_POST['new_password']]);
                AuditLog::logCompany('reset_password', $companyId, null, ['admin_email' => $company['admin_email'] ?? null]);
                $flash = !empty($res['success']) ? ['success', 'Admin password reset.'] : ['error', 'Failed to reset password'];
            }
            break;

        case 'schedule_delete':
            if (($_POST['confirm_slug'] ?? '') !== ($company['slug'] ?? '~')) {
                $flash = ['error', 'Confirmation text did not match the slug. Nothing deleted.'];
            } else {
                $res = TenantDeletion::requestDelete($companyId, $_SESSION['user_id'] ?? null, $_POST['reason'] ?? '');
                $flash = !empty($res['success'])
                    ? ['success', 'Deletion scheduled. Company deactivated; purges after ' . date('M d, Y', dbTs($res['purge_after'])) . '.']
                    : ['error', $res['error'] ?? 'Failed to schedule deletion'];
            }
            break;

        case 'cancel_delete':
            $res = TenantDeletion::cancel($companyId);
            $flash = !empty($res['success']) ? ['success', 'Scheduled deletion cancelled; company reactivated.'] : ['error', $res['error'] ?? 'Failed to cancel'];
            break;
    }

    if ($flash) { $_SESSION['cdm_flash'] = $flash; }
    header('Location: ' . $self());
    exit;
}

// ---------------------------------------------------------------------------
// 404 (after POST handling so a bad POST id can redirect cleanly).
// ---------------------------------------------------------------------------
if (!$company) {
    adminHeader('Company not found', 'companies');
    echo '<div class="bg-white rounded-lg shadow p-8 text-center">'
        . '<p class="text-gray-600 mb-4">Company not found.</p>'
        . '<a href="companies.php" class="text-blue-600 hover:underline">Back to companies</a></div>';
    adminFooter();
    exit;
}

// ---------------------------------------------------------------------------
// Load everything (defensive: a missing/secondary table never 500s the page).
// ---------------------------------------------------------------------------
$cid  = $companyId;
$cur  = $company['currency'] ?: 'OMR';
$safe = function (callable $fn, $default = []) {
    try { return $fn(); } catch (\Throwable $e) { error_log('[company.php] ' . $e->getMessage()); return $default; }
};

$flash = $_SESSION['cdm_flash'] ?? null; unset($_SESSION['cdm_flash']);
$pendingDeletion = $safe(fn() => TenantDeletion::pending($cid), null);

$theme    = $safe(fn() => $db->fetchOne("SELECT * FROM company_themes WHERE company_id = :c", ['c' => $cid]) ?: [], []);
$settings = $safe(fn() => $db->fetchOne("SELECT * FROM company_settings WHERE company_id = :c", ['c' => $cid]) ?: [], []);
$onboard  = $safe(fn() => $db->fetchOne("SELECT step, started_at, completed_at, skipped_at FROM company_onboarding WHERE company_id = :c", ['c' => $cid]) ?: [], []);
$deptCount = (int) ($safe(fn() => $db->fetchOne("SELECT COUNT(*) n FROM departments WHERE company_id = :c AND deleted_at IS NULL", ['c' => $cid]), ['n' => 0])['n'] ?? 0);

$employees = $safe(fn() => $db->fetchAll("SELECT * FROM employees WHERE company_id = :c AND deleted_at IS NULL ORDER BY created_at DESC LIMIT 500", ['c' => $cid]));
$empStatusCounts = [];
foreach ($safe(fn() => $db->fetchAll("SELECT status, COUNT(*) n FROM employees WHERE company_id = :c AND deleted_at IS NULL GROUP BY status", ['c' => $cid])) as $r) {
    $empStatusCounts[$r['status'] ?: 'active'] = (int) $r['n'];
}

$templates = $safe(fn() => $db->fetchAll("SELECT id, name, side, pair_id, has_vector_source, current_version, is_active, created_at FROM templates WHERE company_id = :c AND deleted_at IS NULL ORDER BY created_at DESC", ['c' => $cid]));

$cardsGenerated = (int) ($safe(fn() => $db->fetchOne("SELECT COUNT(*) n FROM generated_cards WHERE company_id = :c", ['c' => $cid]), ['n' => 0])['n'] ?? 0);
$cardLedger     = $safe(fn() => $db->fetchAll("SELECT * FROM card_credit_ledger WHERE company_id = :c ORDER BY created_at DESC LIMIT 20", ['c' => $cid]));

$scanCount  = (int) ($safe(fn() => $db->fetchOne("SELECT COUNT(*) n FROM qr_scans WHERE company_id = :c", ['c' => $cid]), ['n' => 0])['n'] ?? 0);
$eventTotals = $safe(fn() => $db->fetchAll("SELECT event_type, COUNT(*) n FROM card_events WHERE company_id = :c GROUP BY event_type ORDER BY n DESC", ['c' => $cid]));
$recentEvents = $safe(fn() => $db->fetchAll("SELECT event_type, cta_target, created_at FROM card_events WHERE company_id = :c ORDER BY created_at DESC LIMIT 15", ['c' => $cid]));

$orders = $safe(fn() => $db->fetchAll("SELECT id, order_number, status, payment_status, total, currency, quantity, erp_invoice_number, invoice_number, created_at FROM print_orders WHERE company_id = :c AND deleted_at IS NULL ORDER BY created_at DESC LIMIT 100", ['c' => $cid]));
$orderAgg = $safe(fn() => $db->fetchOne("SELECT COUNT(*) n, COALESCE(SUM(total),0) gross, COALESCE(SUM(CASE WHEN payment_status='paid' OR invoice_paid=1 THEN total ELSE 0 END),0) paid FROM print_orders WHERE company_id = :c AND deleted_at IS NULL", ['c' => $cid]), ['n' => 0, 'gross' => 0, 'paid' => 0]);

$creditAccounts = $safe(fn() => CreditManager::getCompanyAccounts($cid));
foreach ($creditAccounts as $i => $ca) {
    $creditAccounts[$i]['ledger'] = $safe(fn() => CreditManager::getLedger($ca['id'], 8));
}

$payAgg = $safe(fn() => $db->fetchOne("SELECT COUNT(*) n, COALESCE(SUM(CASE WHEN status IN ('completed','paid') THEN amount ELSE 0 END),0) total, SUM(CASE WHEN status IN ('failed','declined','error') THEN 1 ELSE 0 END) failed FROM payment_transactions WHERE company_id = :c", ['c' => $cid]), ['n' => 0, 'total' => 0, 'failed' => 0]);
$payments = $safe(fn() => $db->fetchAll("SELECT amount, currency, status, payment_method, description, created_at FROM payment_transactions WHERE company_id = :c ORDER BY created_at DESC LIMIT 15", ['c' => $cid]));

$auditLogs = $safe(fn() => AuditLog::getLogs(['company_id' => $cid], 40));

$tenantUrl = getTenantUrl($company['slug']);
$csrf = generateCSRFToken();

adminHeader('Company: ' . $company['name'], 'companies');

$tabs = [
    'overview'     => ['Overview', 'fa-circle-info'],
    'team'         => ['Team', 'fa-users'],
    'designs'      => ['Designs', 'fa-palette'],
    'cards'        => ['Cards & credits', 'fa-id-card'],
    'engagement'   => ['Engagement', 'fa-chart-line'],
    'orders'       => ['Orders', 'fa-print'],
    'credit'       => ['Credit', 'fa-building-columns'],
    'payments'     => ['Payments', 'fa-credit-card'],
    'integrations' => ['Integrations', 'fa-plug'],
    'audit'        => ['Audit', 'fa-clipboard-list'],
];
if (!isset($tabs[$tab])) { $tab = 'overview'; }
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>

<?php if ($flash): ?>
<div class="mb-4 p-4 rounded-lg <?php echo $flash[0] === 'success' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'; ?>">
    <i class="fa-solid <?php echo $flash[0] === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?> mr-2"></i>
    <?php echo $h($flash[1]); ?>
</div>
<?php endif; ?>

<!-- Header + actions -->
<div class="bg-white rounded-lg shadow p-5 mb-6">
    <a href="companies.php" class="text-sm text-gray-500 hover:text-gray-700"><i class="fa-solid fa-arrow-left mr-1"></i>All companies</a>
    <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4 mt-2">
        <div>
            <div class="flex items-center gap-3 flex-wrap">
                <h2 class="text-2xl font-bold text-gray-900"><?php echo $h($company['name']); ?></h2>
                <span class="px-2 py-1 rounded-full text-xs font-semibold <?php echo cdm_badge($company['status']); ?>"><?php echo $h(ucfirst($company['status'])); ?></span>
                <span class="px-2 py-1 rounded-full text-xs font-semibold bg-blue-100 text-blue-700"><?php echo $h(ucfirst($company['plan'] ?? 'free')); ?></span>
            </div>
            <div class="text-sm text-gray-500 mt-1">
                <span class="font-mono"><?php echo $h($company['slug']); ?></span> ·
                <a href="<?php echo $h($tenantUrl); ?>" target="_blank" class="text-blue-600 hover:underline"><?php echo $h($tenantUrl); ?></a> ·
                <?php echo $h($company['admin_email']); ?>
            </div>
        </div>
        <div class="flex items-center gap-2 flex-wrap">
            <?php if ($company['status'] === 'active'): ?>
            <form method="POST" action="<?php echo getBasePath(); ?>admin/impersonate.php?action=start" class="m-0"
                  onsubmit="return confirm('Log in as <?php echo $h($company['name']); ?>? Logged to the audit trail.');">
                <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>">
                <input type="hidden" name="company_id" value="<?php echo $h($company['id']); ?>">
                <button class="px-3 py-2 bg-amber-500 text-white rounded-lg hover:bg-amber-600 text-sm font-medium"><i class="fa-solid fa-user-secret mr-1"></i>Impersonate</button>
            </form>
            <?php endif; ?>
            <a href="<?php echo $h($tenantUrl); ?>" target="_blank" class="px-3 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 text-sm font-medium"><i class="fa-solid fa-external-link mr-1"></i>Visit</a>
            <?php if ($company['status'] === 'active'): ?>
            <form method="POST" class="m-0" onsubmit="return confirm('Suspend this company? Logins will be blocked.');">
                <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>">
                <input type="hidden" name="company_id" value="<?php echo $h($company['id']); ?>">
                <input type="hidden" name="action" value="suspend_company">
                <button class="px-3 py-2 bg-orange-100 text-orange-700 rounded-lg hover:bg-orange-200 text-sm font-medium"><i class="fa-solid fa-pause mr-1"></i>Suspend</button>
            </form>
            <?php else: ?>
            <form method="POST" class="m-0">
                <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>">
                <input type="hidden" name="company_id" value="<?php echo $h($company['id']); ?>">
                <input type="hidden" name="action" value="activate_company">
                <button class="px-3 py-2 bg-green-100 text-green-700 rounded-lg hover:bg-green-200 text-sm font-medium"><i class="fa-solid fa-play mr-1"></i>Activate</button>
            </form>
            <?php endif; ?>
            <button onclick="openModal('resetPwModal')" class="px-3 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 text-sm font-medium"><i class="fa-solid fa-key mr-1"></i>Reset password</button>
            <button onclick="openModal('deleteModal')" class="px-3 py-2 bg-red-100 text-red-700 rounded-lg hover:bg-red-200 text-sm font-medium"><i class="fa-solid fa-trash mr-1"></i>Delete</button>
        </div>
    </div>

    <?php if ($pendingDeletion): ?>
    <div class="mt-4 p-3 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm flex items-center justify-between gap-4 flex-wrap">
        <div><i class="fa-solid fa-triangle-exclamation mr-1"></i> Deletion scheduled, purges on <strong><?php echo $h(date('M d, Y', dbTs($pendingDeletion['purge_after']))); ?></strong>. Company is deactivated.</div>
        <form method="POST" class="m-0">
            <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>">
            <input type="hidden" name="company_id" value="<?php echo $h($company['id']); ?>">
            <input type="hidden" name="action" value="cancel_delete">
            <button class="px-3 py-1.5 bg-white border border-red-300 text-red-700 rounded-lg hover:bg-red-50 font-medium">Cancel deletion</button>
        </form>
    </div>
    <?php endif; ?>
</div>

<!-- Tabs -->
<div class="bg-white rounded-lg shadow mb-6">
    <div class="border-b border-gray-200 overflow-x-auto">
        <nav class="flex gap-1 px-2 min-w-max">
            <?php foreach ($tabs as $key => [$label, $icon]): ?>
            <button type="button" data-tab="<?php echo $key; ?>" onclick="showTab('<?php echo $key; ?>')"
                    class="cdm-tab whitespace-nowrap px-4 py-3 border-b-2 text-sm font-medium <?php echo $tab === $key ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700'; ?>">
                <i class="fa-solid <?php echo $icon; ?> mr-1"></i><?php echo $h($label); ?>
            </button>
            <?php endforeach; ?>
        </nav>
    </div>

    <div class="p-5">
        <!-- OVERVIEW -->
        <div class="cdm-panel" data-tab="overview" <?php echo $tab === 'overview' ? '' : 'hidden'; ?>>
            <div class="grid lg:grid-cols-2 gap-6">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>">
                    <input type="hidden" name="company_id" value="<?php echo $h($company['id']); ?>">
                    <input type="hidden" name="action" value="update_company">
                    <input type="hidden" name="tab" value="overview">
                    <h3 class="font-semibold text-gray-900 mb-3">Company details</h3>
                    <div class="space-y-3">
                        <div><label class="block text-xs font-medium text-gray-500 mb-1">Name</label><input name="name" value="<?php echo $h($company['name']); ?>" class="w-full px-3 py-2 border border-gray-300 rounded-lg"></div>
                        <div><label class="block text-xs font-medium text-gray-500 mb-1">Slug</label><input name="slug" value="<?php echo $h($company['slug']); ?>" pattern="[a-z0-9-]+" class="w-full px-3 py-2 border border-gray-300 rounded-lg"></div>
                        <div><label class="block text-xs font-medium text-gray-500 mb-1">Admin email</label><input type="email" name="admin_email" value="<?php echo $h($company['admin_email']); ?>" class="w-full px-3 py-2 border border-gray-300 rounded-lg"></div>
                        <div class="grid grid-cols-2 gap-3">
                            <div><label class="block text-xs font-medium text-gray-500 mb-1">Plan</label>
                                <select name="plan" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                                    <?php foreach (['free', 'starter', 'professional', 'enterprise'] as $pl): ?>
                                    <option value="<?php echo $pl; ?>" <?php echo ($company['plan'] ?? '') === $pl ? 'selected' : ''; ?>><?php echo ucfirst($pl); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div><label class="block text-xs font-medium text-gray-500 mb-1">Currency</label>
                                <select name="currency" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                                    <?php foreach (['OMR', 'AED', 'USD', 'EUR', 'SAR'] as $cc): ?>
                                    <option value="<?php echo $cc; ?>" <?php echo $cur === $cc ? 'selected' : ''; ?>><?php echo $cc; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div><label class="block text-xs font-medium text-gray-500 mb-1">Billing email</label><input type="email" name="billing_email" value="<?php echo $h($company['billing_email'] ?? ''); ?>" class="w-full px-3 py-2 border border-gray-300 rounded-lg"></div>
                        <div><label class="block text-xs font-medium text-gray-500 mb-1">ERP client name (BHD-ERP override)</label><input name="erp_client_name" value="<?php echo $h($company['erp_client_name'] ?? ''); ?>" class="w-full px-3 py-2 border border-gray-300 rounded-lg"></div>
                        <button class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 text-sm font-medium">Save details</button>
                    </div>
                </form>

                <div class="space-y-6">
                    <div>
                        <h3 class="font-semibold text-gray-900 mb-3">Branding</h3>
                        <form method="POST" class="flex items-end gap-4 flex-wrap">
                            <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>">
                            <input type="hidden" name="company_id" value="<?php echo $h($company['id']); ?>">
                            <input type="hidden" name="action" value="update_theme">
                            <input type="hidden" name="tab" value="overview">
                            <?php if (!empty($theme['logo_path'])): ?>
                            <img src="<?php echo $h((strpos($theme['logo_path'], 'http') === 0 ? '' : '/') . ltrim($theme['logo_path'], '/')); ?>" alt="logo" class="h-12 w-12 object-contain rounded border border-gray-200 bg-gray-50">
                            <?php endif; ?>
                            <div><label class="block text-xs font-medium text-gray-500 mb-1">Primary</label><input type="color" name="primary_color" value="<?php echo $h($theme['primary_color'] ?? '#009bc1'); ?>" class="h-10 w-16 border border-gray-300 rounded"></div>
                            <div><label class="block text-xs font-medium text-gray-500 mb-1">Secondary</label><input type="color" name="secondary_color" value="<?php echo $h($theme['secondary_color'] ?? '#824598'); ?>" class="h-10 w-16 border border-gray-300 rounded"></div>
                            <button class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 text-sm font-medium">Save colours</button>
                        </form>
                        <p class="text-xs text-gray-400 mt-2">Logo upload: use the tenant <a href="<?php echo $h(getBasePath()); ?>admin/theme.php" class="text-blue-600 hover:underline">Theme</a> page (impersonate first) or the onboarding wizard.</p>
                    </div>
                    <div>
                        <h3 class="font-semibold text-gray-900 mb-3">Record</h3>
                        <dl class="text-sm grid grid-cols-2 gap-y-2">
                            <dt class="text-gray-500">Created</dt><dd class="text-gray-900"><?php echo $h(date('M d, Y', dbTs($company['created_at']))); ?></dd>
                            <dt class="text-gray-500">Card credits</dt><dd class="text-gray-900"><?php echo (int)($company['card_credits'] ?? 0); ?></dd>
                            <dt class="text-gray-500">Country</dt><dd class="text-gray-900"><?php echo $h($company['country'] ?? '-'); ?></dd>
                            <dt class="text-gray-500">CR number</dt><dd class="text-gray-900"><?php echo $h($company['cr_number'] ?? '-'); ?></dd>
                            <dt class="text-gray-500">Tax ID</dt><dd class="text-gray-900"><?php echo $h($company['tax_id'] ?? '-'); ?></dd>
                            <dt class="text-gray-500">Onboarding</dt><dd class="text-gray-900"><?php echo !empty($onboard['completed_at']) ? 'Completed' : (isset($onboard['step']) ? 'Step ' . (int)$onboard['step'] : 'Not started'); ?></dd>
                        </dl>
                    </div>
                </div>
            </div>
        </div>

        <!-- TEAM -->
        <div class="cdm-panel" data-tab="team" <?php echo $tab === 'team' ? '' : 'hidden'; ?>>
            <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
                <div class="text-sm text-gray-600">
                    <strong><?php echo count($employees); ?></strong> employees ·
                    <?php foreach ($empStatusCounts as $st => $n): ?><span class="px-2 py-0.5 rounded-full text-xs <?php echo cdm_badge($st); ?> ml-1"><?php echo $h(ucfirst($st)); ?>: <?php echo $n; ?></span><?php endforeach; ?>
                    · <?php echo $deptCount; ?> departments
                </div>
                <div class="flex gap-2">
                    <button onclick="addEmp()" class="px-3 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 text-sm font-medium"><i class="fa-solid fa-plus mr-1"></i>Add employee</button>
                    <a href="employees.php?company_id=<?php echo urlencode($company['id']); ?>" class="px-3 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 text-sm font-medium">Open full list</a>
                </div>
            </div>
            <div class="overflow-x-auto border border-gray-100 rounded-lg">
                <table class="w-full text-sm text-left text-gray-600">
                    <thead class="text-xs uppercase bg-gray-50 text-gray-500"><tr>
                        <th class="px-4 py-2">Name</th><th class="px-4 py-2">Position</th><th class="px-4 py-2">Email</th><th class="px-4 py-2">Mobile</th><th class="px-4 py-2">Scans</th><th class="px-4 py-2">Status</th><th class="px-4 py-2"></th>
                    </tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php if (empty($employees)): ?>
                        <tr><td colspan="7" class="px-4 py-6 text-center text-gray-400">No employees yet</td></tr>
                        <?php else: foreach ($employees as $e): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-2 font-medium text-gray-900"><?php echo $h($e['name_en'] ?: $e['name_ar'] ?: $e['id']); ?></td>
                            <td class="px-4 py-2"><?php echo $h($e['position_en'] ?: $e['position_ar'] ?: '-'); ?></td>
                            <td class="px-4 py-2"><?php echo $h($e['email']); ?></td>
                            <td class="px-4 py-2" dir="ltr"><?php echo $h($e['mobile'] ?: $e['phone'] ?: '-'); ?></td>
                            <td class="px-4 py-2"><?php echo (int)($e['total_scans'] ?? 0); ?></td>
                            <td class="px-4 py-2"><span class="px-2 py-0.5 rounded-full text-xs <?php echo cdm_badge($e['status'] ?: 'active'); ?>"><?php echo $h(ucfirst($e['status'] ?: 'active')); ?></span></td>
                            <td class="px-4 py-2 text-right">
                                <button class="text-blue-600 hover:text-blue-800" title="Edit"
                                        onclick='editEmp(<?php echo json_encode([
                                            'id' => $e['id'], 'name_en' => $e['name_en'], 'name_ar' => $e['name_ar'],
                                            'position_en' => $e['position_en'], 'position_ar' => $e['position_ar'],
                                            'email' => $e['email'], 'mobile' => $e['mobile'], 'phone' => $e['phone'],
                                            'status' => $e['status'] ?: 'active',
                                        ], JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'><i class="fa-solid fa-pen-to-square"></i></button>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- DESIGNS -->
        <div class="cdm-panel" data-tab="designs" <?php echo $tab === 'designs' ? '' : 'hidden'; ?>>
            <?php if (empty($templates)): ?>
            <p class="text-gray-400 text-sm py-6 text-center">No card designs. Cards can't be generated until a template exists.</p>
            <?php else: ?>
            <div class="overflow-x-auto border border-gray-100 rounded-lg">
                <table class="w-full text-sm text-left text-gray-600">
                    <thead class="text-xs uppercase bg-gray-50 text-gray-500"><tr>
                        <th class="px-4 py-2">Name</th><th class="px-4 py-2">Side</th><th class="px-4 py-2">Vector</th><th class="px-4 py-2">Version</th><th class="px-4 py-2">Active</th><th class="px-4 py-2">Created</th>
                    </tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php foreach ($templates as $t): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-2 font-medium text-gray-900"><?php echo $h($t['name']); ?></td>
                            <td class="px-4 py-2"><?php echo $h($t['side']); ?></td>
                            <td class="px-4 py-2"><?php echo !empty($t['has_vector_source']) ? '<span class="text-green-600">vector</span>' : '<span class="text-gray-400">raster</span>'; ?></td>
                            <td class="px-4 py-2">v<?php echo (int)($t['current_version'] ?? 1); ?></td>
                            <td class="px-4 py-2"><?php echo !empty($t['is_active']) ? '<i class="fa-solid fa-check text-green-600"></i>' : ''; ?></td>
                            <td class="px-4 py-2"><?php echo $h(date('M d, Y', dbTs($t['created_at']))); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- CARDS & CREDITS -->
        <div class="cdm-panel" data-tab="cards" <?php echo $tab === 'cards' ? '' : 'hidden'; ?>>
            <div class="grid grid-cols-2 md:grid-cols-3 gap-4 mb-5">
                <div class="bg-gray-50 rounded-lg p-4"><div class="text-2xl font-bold text-gray-900"><?php echo $cardsGenerated; ?></div><div class="text-xs text-gray-500">Cards generated</div></div>
                <div class="bg-gray-50 rounded-lg p-4"><div class="text-2xl font-bold text-gray-900"><?php echo (int)($company['card_credits'] ?? 0); ?></div><div class="text-xs text-gray-500">Card credits balance</div></div>
                <div class="bg-gray-50 rounded-lg p-4"><div class="text-2xl font-bold text-gray-900"><?php echo count($cardLedger); ?></div><div class="text-xs text-gray-500">Recent ledger entries</div></div>
            </div>
            <?php if (!empty($cardLedger)): ?>
            <div class="overflow-x-auto border border-gray-100 rounded-lg">
                <table class="w-full text-sm text-left text-gray-600">
                    <thead class="text-xs uppercase bg-gray-50 text-gray-500"><tr><th class="px-4 py-2">When</th><th class="px-4 py-2">Reason</th><th class="px-4 py-2">Delta</th><th class="px-4 py-2">Balance</th><th class="px-4 py-2">Notes</th></tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php foreach ($cardLedger as $l): ?>
                        <tr><td class="px-4 py-2"><?php echo $h(date('M d, H:i', dbTs($l['created_at']))); ?></td><td class="px-4 py-2"><?php echo $h($l['reason']); ?></td><td class="px-4 py-2 <?php echo (int)$l['delta'] < 0 ? 'text-red-600' : 'text-green-600'; ?>"><?php echo (int)$l['delta']; ?></td><td class="px-4 py-2"><?php echo (int)$l['balance_after']; ?></td><td class="px-4 py-2 text-gray-400"><?php echo $h($l['notes'] ?? ''); ?></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- ENGAGEMENT -->
        <div class="cdm-panel" data-tab="engagement" <?php echo $tab === 'engagement' ? '' : 'hidden'; ?>>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-5">
                <div class="bg-gray-50 rounded-lg p-4"><div class="text-2xl font-bold text-gray-900"><?php echo $scanCount; ?></div><div class="text-xs text-gray-500">QR scans</div></div>
                <?php foreach (array_slice($eventTotals, 0, 3) as $ev): ?>
                <div class="bg-gray-50 rounded-lg p-4"><div class="text-2xl font-bold text-gray-900"><?php echo (int)$ev['n']; ?></div><div class="text-xs text-gray-500"><?php echo $h(str_replace('_', ' ', $ev['event_type'])); ?></div></div>
                <?php endforeach; ?>
            </div>
            <p class="text-xs text-gray-400 mb-2">Counts exclude bot/self traffic (filtered at capture).</p>
            <?php if (!empty($recentEvents)): ?>
            <div class="overflow-x-auto border border-gray-100 rounded-lg">
                <table class="w-full text-sm text-left text-gray-600">
                    <thead class="text-xs uppercase bg-gray-50 text-gray-500"><tr><th class="px-4 py-2">When</th><th class="px-4 py-2">Event</th><th class="px-4 py-2">Target</th></tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php foreach ($recentEvents as $ev): ?>
                        <tr><td class="px-4 py-2"><?php echo $h(date('M d, H:i', dbTs($ev['created_at']))); ?></td><td class="px-4 py-2"><?php echo $h(str_replace('_', ' ', $ev['event_type'])); ?></td><td class="px-4 py-2 text-gray-400 truncate max-w-xs"><?php echo $h($ev['cta_target'] ?? ''); ?></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?><p class="text-gray-400 text-sm py-4">No engagement events recorded.</p><?php endif; ?>
        </div>

        <!-- ORDERS -->
        <div class="cdm-panel" data-tab="orders" <?php echo $tab === 'orders' ? '' : 'hidden'; ?>>
            <div class="grid grid-cols-3 gap-4 mb-5">
                <div class="bg-gray-50 rounded-lg p-4"><div class="text-2xl font-bold text-gray-900"><?php echo (int)$orderAgg['n']; ?></div><div class="text-xs text-gray-500">Orders</div></div>
                <div class="bg-gray-50 rounded-lg p-4"><div class="text-xl font-bold text-gray-900"><?php echo $h(cdm_money($orderAgg['gross'], $cur)); ?></div><div class="text-xs text-gray-500">Gross value</div></div>
                <div class="bg-gray-50 rounded-lg p-4"><div class="text-xl font-bold text-green-700"><?php echo $h(cdm_money($orderAgg['paid'], $cur)); ?></div><div class="text-xs text-gray-500">Paid</div></div>
            </div>
            <?php if (empty($orders)): ?>
            <p class="text-gray-400 text-sm py-4">No print orders.</p>
            <?php else: ?>
            <div class="overflow-x-auto border border-gray-100 rounded-lg">
                <table class="w-full text-sm text-left text-gray-600">
                    <thead class="text-xs uppercase bg-gray-50 text-gray-500"><tr><th class="px-4 py-2">Order</th><th class="px-4 py-2">Qty</th><th class="px-4 py-2">Total</th><th class="px-4 py-2">Payment</th><th class="px-4 py-2">Status</th><th class="px-4 py-2">Invoice</th><th class="px-4 py-2">Created</th><th class="px-4 py-2"></th></tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php foreach ($orders as $o): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-2 font-medium text-gray-900"><?php echo $h($o['order_number'] ?: substr($o['id'], 0, 8)); ?></td>
                            <td class="px-4 py-2"><?php echo (int)$o['quantity']; ?></td>
                            <td class="px-4 py-2"><?php echo $h(cdm_money($o['total'], $o['currency'] ?: $cur)); ?></td>
                            <td class="px-4 py-2"><span class="px-2 py-0.5 rounded-full text-xs <?php echo cdm_badge($o['payment_status'] ?: 'pending'); ?>"><?php echo $h($o['payment_status'] ?: '-'); ?></span></td>
                            <td class="px-4 py-2"><span class="px-2 py-0.5 rounded-full text-xs <?php echo cdm_badge($o['status']); ?>"><?php echo $h($o['status']); ?></span></td>
                            <td class="px-4 py-2 text-gray-400"><?php echo $h($o['erp_invoice_number'] ?: $o['invoice_number'] ?: '-'); ?></td>
                            <td class="px-4 py-2"><?php echo $h(date('M d, Y', dbTs($o['created_at']))); ?></td>
                            <td class="px-4 py-2 text-right"><button class="text-blue-600 hover:text-blue-800" title="Change status" onclick="orderStatus(<?php echo $h(json_encode($o['id'])); ?>, <?php echo $h(json_encode($o['status'])); ?>)"><i class="fa-solid fa-arrows-rotate"></i></button></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- CREDIT -->
        <div class="cdm-panel" data-tab="credit" <?php echo $tab === 'credit' ? '' : 'hidden'; ?>>
            <?php if (empty($creditAccounts)): ?>
            <p class="text-gray-400 text-sm py-4">No credit accounts for this company.</p>
            <?php else: foreach ($creditAccounts as $ca): ?>
            <div class="border border-gray-200 rounded-lg p-4 mb-4">
                <div class="flex items-center justify-between flex-wrap gap-2 mb-3">
                    <div>
                        <span class="font-semibold text-gray-900"><?php echo $h($ca['shop_name'] ?? 'Print shop'); ?></span>
                        <span class="px-2 py-0.5 rounded-full text-xs <?php echo cdm_badge($ca['status']); ?> ml-2"><?php echo $h(ucfirst($ca['status'])); ?></span>
                    </div>
                    <div class="text-sm text-gray-600">
                        Limit <strong><?php echo $h(cdm_money($ca['credit_limit'], $cur)); ?></strong> ·
                        Used <strong><?php echo $h(cdm_money($ca['balance_used'], $cur)); ?></strong> ·
                        Terms <?php echo $h(strtoupper($ca['payment_terms'] ?? '-')); ?>
                    </div>
                </div>
                <div class="grid md:grid-cols-2 gap-3">
                    <form method="POST" class="flex items-end gap-2 flex-wrap bg-gray-50 rounded-lg p-3">
                        <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>">
                        <input type="hidden" name="company_id" value="<?php echo $h($company['id']); ?>">
                        <input type="hidden" name="action" value="credit_adjust"><input type="hidden" name="tab" value="credit">
                        <input type="hidden" name="credit_account_id" value="<?php echo $h($ca['id']); ?>">
                        <div><label class="block text-xs text-gray-500 mb-1">New limit</label><input name="credit_limit" type="number" step="0.001" value="<?php echo $h($ca['credit_limit']); ?>" class="w-28 px-2 py-1.5 border border-gray-300 rounded"></div>
                        <div><label class="block text-xs text-gray-500 mb-1">Exposure</label><input name="exposure_limit" type="number" step="0.001" value="<?php echo $h($ca['exposure_limit']); ?>" class="w-28 px-2 py-1.5 border border-gray-300 rounded"></div>
                        <div><label class="block text-xs text-gray-500 mb-1">Terms</label>
                            <select name="payment_terms" class="px-2 py-1.5 border border-gray-300 rounded">
                                <?php foreach (['net15', 'net30', 'net60', 'net90'] as $tm): ?><option value="<?php echo $tm; ?>" <?php echo ($ca['payment_terms'] ?? '') === $tm ? 'selected' : ''; ?>><?php echo strtoupper($tm); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <button class="px-3 py-1.5 bg-blue-600 text-white rounded text-sm">Adjust</button>
                    </form>
                    <form method="POST" class="flex items-end gap-2 flex-wrap bg-gray-50 rounded-lg p-3">
                        <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>">
                        <input type="hidden" name="company_id" value="<?php echo $h($company['id']); ?>">
                        <input type="hidden" name="action" value="credit_payment"><input type="hidden" name="tab" value="credit">
                        <input type="hidden" name="credit_account_id" value="<?php echo $h($ca['id']); ?>">
                        <div><label class="block text-xs text-gray-500 mb-1">Record payment</label><input name="amount" type="number" step="0.001" placeholder="0.000" class="w-28 px-2 py-1.5 border border-gray-300 rounded"></div>
                        <div class="flex-1"><label class="block text-xs text-gray-500 mb-1">Note</label><input name="notes" class="w-full px-2 py-1.5 border border-gray-300 rounded"></div>
                        <button class="px-3 py-1.5 bg-green-600 text-white rounded text-sm">Record</button>
                    </form>
                </div>
                <div class="flex gap-2 mt-3">
                    <?php if ($ca['status'] === 'approved'): ?>
                    <form method="POST" class="m-0"><input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>"><input type="hidden" name="company_id" value="<?php echo $h($company['id']); ?>"><input type="hidden" name="action" value="credit_status"><input type="hidden" name="tab" value="credit"><input type="hidden" name="op" value="suspend"><input type="hidden" name="credit_account_id" value="<?php echo $h($ca['id']); ?>"><button class="px-3 py-1.5 bg-orange-100 text-orange-700 rounded text-sm">Suspend</button></form>
                    <?php elseif ($ca['status'] === 'suspended'): ?>
                    <form method="POST" class="m-0"><input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>"><input type="hidden" name="company_id" value="<?php echo $h($company['id']); ?>"><input type="hidden" name="action" value="credit_status"><input type="hidden" name="tab" value="credit"><input type="hidden" name="op" value="reactivate"><input type="hidden" name="credit_account_id" value="<?php echo $h($ca['id']); ?>"><button class="px-3 py-1.5 bg-green-100 text-green-700 rounded text-sm">Reactivate</button></form>
                    <?php endif; ?>
                </div>
                <?php if (!empty($ca['ledger'])): ?>
                <div class="mt-3 text-xs">
                    <div class="text-gray-500 mb-1">Recent ledger</div>
                    <?php foreach ($ca['ledger'] as $lx): ?>
                    <div class="flex justify-between border-b border-gray-50 py-1"><span><?php echo $h(date('M d', dbTs($lx['created_at']))); ?> · <?php echo $h($lx['type']); ?></span><span><?php echo $h(cdm_money($lx['amount'], $cur)); ?> (bal <?php echo $h(cdm_money($lx['balance_after'], $cur)); ?>)</span></div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; endif; ?>
        </div>

        <!-- PAYMENTS -->
        <div class="cdm-panel" data-tab="payments" <?php echo $tab === 'payments' ? '' : 'hidden'; ?>>
            <div class="grid grid-cols-3 gap-4 mb-5">
                <div class="bg-gray-50 rounded-lg p-4"><div class="text-2xl font-bold text-gray-900"><?php echo (int)$payAgg['n']; ?></div><div class="text-xs text-gray-500">Transactions</div></div>
                <div class="bg-gray-50 rounded-lg p-4"><div class="text-xl font-bold text-green-700"><?php echo $h(cdm_money($payAgg['total'], $cur)); ?></div><div class="text-xs text-gray-500">Collected</div></div>
                <div class="bg-gray-50 rounded-lg p-4"><div class="text-2xl font-bold text-red-600"><?php echo (int)$payAgg['failed']; ?></div><div class="text-xs text-gray-500">Failed</div></div>
            </div>
            <?php if (!empty($payments)): ?>
            <div class="overflow-x-auto border border-gray-100 rounded-lg">
                <table class="w-full text-sm text-left text-gray-600">
                    <thead class="text-xs uppercase bg-gray-50 text-gray-500"><tr><th class="px-4 py-2">When</th><th class="px-4 py-2">Amount</th><th class="px-4 py-2">Method</th><th class="px-4 py-2">Status</th><th class="px-4 py-2">Description</th></tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php foreach ($payments as $p): ?>
                        <tr><td class="px-4 py-2"><?php echo $h(date('M d, H:i', dbTs($p['created_at']))); ?></td><td class="px-4 py-2"><?php echo $h(cdm_money($p['amount'], $p['currency'] ?: $cur)); ?></td><td class="px-4 py-2"><?php echo $h($p['payment_method'] ?: '-'); ?></td><td class="px-4 py-2"><span class="px-2 py-0.5 rounded-full text-xs <?php echo cdm_badge($p['status']); ?>"><?php echo $h($p['status']); ?></span></td><td class="px-4 py-2 text-gray-400 truncate max-w-xs"><?php echo $h($p['description'] ?? ''); ?></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?><p class="text-gray-400 text-sm py-4">No payment transactions.</p><?php endif; ?>
        </div>

        <!-- INTEGRATIONS -->
        <div class="cdm-panel" data-tab="integrations" <?php echo $tab === 'integrations' ? '' : 'hidden'; ?>>
            <form method="POST" class="max-w-2xl space-y-5">
                <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>">
                <input type="hidden" name="company_id" value="<?php echo $h($company['id']); ?>">
                <input type="hidden" name="action" value="update_settings"><input type="hidden" name="tab" value="integrations">
                <div class="border border-gray-200 rounded-lg p-4">
                    <label class="flex items-center gap-2 font-medium text-gray-900"><input type="checkbox" name="whatsapp_enabled" <?php echo !empty($settings['whatsapp_enabled']) ? 'checked' : ''; ?>> WhatsApp notifications</label>
                    <input name="whatsapp_token" value="<?php echo $h($settings['whatsapp_token'] ?? ''); ?>" placeholder="WhatsApp token" class="mt-2 w-full px-3 py-2 border border-gray-300 rounded-lg">
                </div>
                <div class="border border-gray-200 rounded-lg p-4 space-y-2">
                    <label class="flex items-center gap-2 font-medium text-gray-900"><input type="checkbox" name="odoo_enabled" <?php echo !empty($settings['odoo_enabled']) ? 'checked' : ''; ?>> Odoo integration</label>
                    <div class="grid grid-cols-2 gap-2">
                        <input name="odoo_url" value="<?php echo $h($settings['odoo_url'] ?? ''); ?>" placeholder="Odoo URL" class="px-3 py-2 border border-gray-300 rounded-lg">
                        <input name="odoo_database" value="<?php echo $h($settings['odoo_database'] ?? ''); ?>" placeholder="Database" class="px-3 py-2 border border-gray-300 rounded-lg">
                        <input name="odoo_username" value="<?php echo $h($settings['odoo_username'] ?? ''); ?>" placeholder="Username" class="px-3 py-2 border border-gray-300 rounded-lg">
                        <input name="odoo_password" type="password" placeholder="New password (blank = keep)" class="px-3 py-2 border border-gray-300 rounded-lg">
                    </div>
                </div>
                <div class="border border-gray-200 rounded-lg p-4">
                    <label class="flex items-center gap-2 font-medium text-gray-900"><input type="checkbox" name="printer_enabled" <?php echo !empty($settings['printer_enabled']) ? 'checked' : ''; ?>> External printer</label>
                    <input name="printer_name" value="<?php echo $h($settings['printer_name'] ?? ''); ?>" placeholder="Printer name" class="mt-2 w-full px-3 py-2 border border-gray-300 rounded-lg">
                </div>
                <button class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 text-sm font-medium">Save integrations</button>
            </form>
        </div>

        <!-- AUDIT -->
        <div class="cdm-panel" data-tab="audit" <?php echo $tab === 'audit' ? '' : 'hidden'; ?>>
            <?php if (empty($auditLogs)): ?>
            <p class="text-gray-400 text-sm py-4">No audit entries for this company yet.</p>
            <?php else: ?>
            <div class="overflow-x-auto border border-gray-100 rounded-lg">
                <table class="w-full text-sm text-left text-gray-600">
                    <thead class="text-xs uppercase bg-gray-50 text-gray-500"><tr><th class="px-4 py-2">When</th><th class="px-4 py-2">Actor</th><th class="px-4 py-2">Action</th><th class="px-4 py-2">Entity</th></tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php foreach ($auditLogs as $a): ?>
                        <tr><td class="px-4 py-2"><?php echo $h(date('M d, H:i', dbTs($a['created_at']))); ?></td><td class="px-4 py-2"><?php echo $h($a['actor_email'] ?: $a['actor_id'] ?: 'system'); ?></td><td class="px-4 py-2"><span class="px-2 py-0.5 rounded-full text-xs bg-gray-100 text-gray-700"><?php echo $h($a['action']); ?></span></td><td class="px-4 py-2 text-gray-400"><?php echo $h($a['entity_type']); ?></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Employee modal (add/edit) -->
<div id="empModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-lg">
        <div class="p-5 border-b"><h3 class="text-lg font-semibold" id="empModalTitle">Employee</h3></div>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>">
            <input type="hidden" name="company_id" value="<?php echo $h($company['id']); ?>">
            <input type="hidden" name="action" id="empAction" value="add_employee"><input type="hidden" name="tab" value="team">
            <input type="hidden" name="employee_id" id="empId">
            <div class="p-5 space-y-3">
                <div class="grid grid-cols-2 gap-3">
                    <div><label class="block text-xs text-gray-500 mb-1">Name (EN)</label><input name="name_en" id="empNameEn" class="w-full px-3 py-2 border border-gray-300 rounded-lg"></div>
                    <div><label class="block text-xs text-gray-500 mb-1">Name (AR)</label><input name="name_ar" id="empNameAr" dir="rtl" class="w-full px-3 py-2 border border-gray-300 rounded-lg"></div>
                    <div><label class="block text-xs text-gray-500 mb-1">Position (EN)</label><input name="position_en" id="empPosEn" class="w-full px-3 py-2 border border-gray-300 rounded-lg"></div>
                    <div><label class="block text-xs text-gray-500 mb-1">Position (AR)</label><input name="position_ar" id="empPosAr" dir="rtl" class="w-full px-3 py-2 border border-gray-300 rounded-lg"></div>
                </div>
                <div><label class="block text-xs text-gray-500 mb-1">Email</label><input type="email" name="email" id="empEmail" class="w-full px-3 py-2 border border-gray-300 rounded-lg"></div>
                <div class="grid grid-cols-2 gap-3">
                    <div><label class="block text-xs text-gray-500 mb-1">Mobile</label><input name="mobile" id="empMobile" dir="ltr" class="w-full px-3 py-2 border border-gray-300 rounded-lg"></div>
                    <div><label class="block text-xs text-gray-500 mb-1">Phone</label><input name="phone" id="empPhone" dir="ltr" class="w-full px-3 py-2 border border-gray-300 rounded-lg"></div>
                </div>
                <div><label class="block text-xs text-gray-500 mb-1">Status</label>
                    <select name="status" id="empStatus" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                        <option value="active">Active</option><option value="pending">Pending</option><option value="suspended">Suspended</option><option value="inactive">Inactive</option>
                    </select>
                </div>
                <label class="flex items-center gap-2 text-sm text-gray-600" id="empSkipInviteWrap"><input type="checkbox" name="skip_invite" value="1"> Don't send a card-edit invite</label>
            </div>
            <div class="p-5 border-t bg-gray-50 flex justify-end gap-3">
                <button type="button" onclick="closeModal('empModal')" class="px-4 py-2 border rounded-lg hover:bg-gray-100">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">Save</button>
            </div>
        </form>
    </div>
</div>

<!-- Order status modal -->
<div id="orderModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-md">
        <div class="p-5 border-b"><h3 class="text-lg font-semibold">Change order status</h3></div>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>">
            <input type="hidden" name="company_id" value="<?php echo $h($company['id']); ?>">
            <input type="hidden" name="action" value="order_status"><input type="hidden" name="tab" value="orders">
            <input type="hidden" name="order_id" id="orderId">
            <div class="p-5 space-y-3">
                <select name="status" id="orderStatusSel" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                    <?php foreach (['pending', 'quote', 'confirmed', 'in_production', 'printing', 'ready', 'shipped', 'delivered', 'completed', 'on_hold', 'cancelled', 'rejected'] as $st): ?>
                    <option value="<?php echo $st; ?>"><?php echo ucfirst(str_replace('_', ' ', $st)); ?></option>
                    <?php endforeach; ?>
                </select>
                <input name="reason" placeholder="Reason (for cancel/reject)" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
            </div>
            <div class="p-5 border-t bg-gray-50 flex justify-end gap-3">
                <button type="button" onclick="closeModal('orderModal')" class="px-4 py-2 border rounded-lg hover:bg-gray-100">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">Update</button>
            </div>
        </form>
    </div>
</div>

<!-- Reset password modal -->
<div id="resetPwModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-md">
        <div class="p-5 border-b"><h3 class="text-lg font-semibold">Reset admin password</h3></div>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>">
            <input type="hidden" name="company_id" value="<?php echo $h($company['id']); ?>">
            <input type="hidden" name="action" value="reset_password"><input type="hidden" name="tab" value="overview">
            <div class="p-5 space-y-3">
                <p class="text-sm text-gray-600">Sets a new password for <strong><?php echo $h($company['admin_email']); ?></strong>.</p>
                <input type="text" name="new_password" placeholder="New password (min 8 chars)" minlength="8" required class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                <input name="confirm_slug" placeholder="Type the slug to confirm: <?php echo $h($company['slug']); ?>" required class="w-full px-3 py-2 border border-gray-300 rounded-lg">
            </div>
            <div class="p-5 border-t bg-gray-50 flex justify-end gap-3">
                <button type="button" onclick="closeModal('resetPwModal')" class="px-4 py-2 border rounded-lg hover:bg-gray-100">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-gray-800 text-white rounded-lg hover:bg-black">Reset password</button>
            </div>
        </form>
    </div>
</div>

<!-- Schedule deletion modal -->
<div id="deleteModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-md">
        <div class="p-5 border-b"><h3 class="text-lg font-semibold text-red-600">Schedule deletion</h3></div>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>">
            <input type="hidden" name="company_id" value="<?php echo $h($company['id']); ?>">
            <input type="hidden" name="action" value="schedule_delete"><input type="hidden" name="tab" value="overview">
            <div class="p-5 space-y-3">
                <p class="text-sm text-gray-600"><?php echo $h($company['name']); ?> is <strong>deactivated immediately</strong> and permanently purged after a 30-day grace period. Cancel any time before then.</p>
                <input name="reason" placeholder="Reason (optional)" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                <input name="confirm_slug" placeholder="Type the slug to confirm: <?php echo $h($company['slug']); ?>" required class="w-full px-3 py-2 border border-gray-300 rounded-lg">
            </div>
            <div class="p-5 border-t bg-gray-50 flex justify-end gap-3">
                <button type="button" onclick="closeModal('deleteModal')" class="px-4 py-2 border rounded-lg hover:bg-gray-100">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700">Schedule deletion</button>
            </div>
        </form>
    </div>
</div>

<script>
function showTab(name) {
    document.querySelectorAll('.cdm-panel').forEach(p => p.hidden = (p.dataset.tab !== name));
    document.querySelectorAll('.cdm-tab').forEach(b => {
        const on = b.dataset.tab === name;
        b.classList.toggle('border-blue-500', on);
        b.classList.toggle('text-blue-600', on);
        b.classList.toggle('border-transparent', !on);
        b.classList.toggle('text-gray-500', !on);
    });
    try { history.replaceState(null, '', 'company.php?id=<?php echo urlencode($company['id']); ?>&tab=' + name); } catch (e) {}
}
function openModal(id) { document.getElementById(id).classList.remove('hidden'); }
function closeModal(id) { document.getElementById(id).classList.add('hidden'); }
function addEmp() {
    document.getElementById('empModalTitle').textContent = 'Add employee';
    document.getElementById('empAction').value = 'add_employee';
    document.getElementById('empId').value = '';
    ['empNameEn','empNameAr','empPosEn','empPosAr','empEmail','empMobile','empPhone'].forEach(i => document.getElementById(i).value = '');
    document.getElementById('empStatus').value = 'active';
    document.getElementById('empEmail').removeAttribute('readonly');
    document.getElementById('empSkipInviteWrap').style.display = '';
    openModal('empModal');
}
function editEmp(e) {
    document.getElementById('empModalTitle').textContent = 'Edit employee';
    document.getElementById('empAction').value = 'update_employee';
    document.getElementById('empId').value = e.id || '';
    document.getElementById('empNameEn').value = e.name_en || '';
    document.getElementById('empNameAr').value = e.name_ar || '';
    document.getElementById('empPosEn').value = e.position_en || '';
    document.getElementById('empPosAr').value = e.position_ar || '';
    document.getElementById('empEmail').value = e.email || '';
    document.getElementById('empMobile').value = e.mobile || '';
    document.getElementById('empPhone').value = e.phone || '';
    document.getElementById('empStatus').value = e.status || 'active';
    document.getElementById('empSkipInviteWrap').style.display = 'none';
    openModal('empModal');
}
function orderStatus(id, current) {
    document.getElementById('orderId').value = id;
    if (current) document.getElementById('orderStatusSel').value = current;
    openModal('orderModal');
}
document.addEventListener('keydown', e => { if (e.key === 'Escape') document.querySelectorAll('.fixed.z-50').forEach(m => m.classList.add('hidden')); });
</script>

<?php adminFooter(); ?>

