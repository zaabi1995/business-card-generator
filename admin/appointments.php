<?php
/**
 * Admin — Appointments dashboard.
 * Lists upcoming/past/all appointments for the current company's employees.
 * Owner can confirm/cancel.
 */
require_once __DIR__ . '/../config.php';
requireAdmin();
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/Appointments.php';
require_once INCLUDES_DIR . '/admin-layout.php';

$db = Database::getInstance();
$companyId = getCurrentCompanyId();
$isSuperAdmin = Auth::isSuperAdmin();

if (!$companyId && !$isSuperAdmin) {
    header('Location: ' . getBasePath() . 'login.php');
    exit;
}

$message = null;
$messageType = 'success';

// Handle status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = 'Invalid request token. Please refresh and try again.';
        $messageType = 'error';
    } else {
        $apptId = trim($_POST['appointment_id'] ?? '');
        $newStatus = $_POST['status'] ?? '';
        $reason = trim($_POST['cancellation_reason'] ?? '');
        $allowed = ['pending','confirmed','cancelled','completed'];
        if (!in_array($newStatus, $allowed, true)) {
            $message = 'Invalid status'; $messageType = 'error';
        } else {
            // Tenant scope
            $where = 'id = :id';
            $params = ['id' => $apptId];
            if (!$isSuperAdmin) {
                $where .= ' AND company_id = :cid';
                $params['cid'] = $companyId;
            }
            $appt = $db->fetchOne("SELECT * FROM appointments WHERE $where", $params);
            if (!$appt) {
                $message = 'Appointment not found'; $messageType = 'error';
            } else {
                $update = ['status' => $newStatus];
                if ($newStatus === 'confirmed') $update['confirmed_at'] = date('Y-m-d H:i:s');
                if ($newStatus === 'cancelled' && $reason !== '') $update['cancellation_reason'] = substr($reason, 0, 500);
                try {
                    $db->update('appointments', $update, 'id = :id', ['id' => $apptId]);
                    $message = 'Appointment updated.';
                } catch (Exception $e) {
                    $message = 'Update failed: ' . $e->getMessage();
                    $messageType = 'error';
                }
            }
        }
    }
}

// Filters
$filter = $_GET['filter'] ?? 'upcoming';
$employeeFilter = trim($_GET['employee_id'] ?? '');

$where = [];
$params = [];
if (!$isSuperAdmin) {
    $where[] = 'a.company_id = :cid';
    $params['cid'] = $companyId;
}
if ($employeeFilter !== '') {
    $where[] = 'a.employee_id = :eid';
    $params['eid'] = $employeeFilter;
}

if ($filter === 'upcoming') {
    $where[] = "a.slot_start >= NOW() AND a.status IN ('pending','confirmed')";
    $orderBy = 'a.slot_start ASC';
} elseif ($filter === 'past') {
    $where[] = "a.slot_start < NOW()";
    $orderBy = 'a.slot_start DESC';
} elseif ($filter === 'pending') {
    $where[] = "a.status = 'pending'";
    $orderBy = 'a.slot_start ASC';
} else { // all
    $orderBy = 'a.slot_start DESC';
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$sql = "SELECT a.*,
               COALESCE(e.name_en, e.name_ar, e.email, 'Unknown') AS employee_name,
               e.email AS employee_email
        FROM appointments a
        LEFT JOIN employees e ON e.id = a.employee_id
        $whereSql
        ORDER BY $orderBy
        LIMIT 200";
$appointments = [];
try { $appointments = $db->fetchAll($sql, $params); } catch (Exception $e) { error_log('appointments list: ' . $e->getMessage()); }

// Counts for tab badges
$counts = ['upcoming' => 0, 'pending' => 0, 'past' => 0, 'all' => 0];
try {
    $tenantSql = $isSuperAdmin ? '' : ' AND company_id = :cid';
    $tenantParams = $isSuperAdmin ? [] : ['cid' => $companyId];
    $counts['upcoming'] = (int)($db->fetchOne("SELECT COUNT(*) c FROM appointments WHERE slot_start >= NOW() AND status IN ('pending','confirmed') $tenantSql", $tenantParams)['c'] ?? 0);
    $counts['pending']  = (int)($db->fetchOne("SELECT COUNT(*) c FROM appointments WHERE status = 'pending' $tenantSql", $tenantParams)['c'] ?? 0);
    $counts['past']     = (int)($db->fetchOne("SELECT COUNT(*) c FROM appointments WHERE slot_start < NOW() $tenantSql", $tenantParams)['c'] ?? 0);
    $counts['all']      = (int)($db->fetchOne("SELECT COUNT(*) c FROM appointments WHERE 1=1 $tenantSql", $tenantParams)['c'] ?? 0);
} catch (Exception $e) {}

// Employees for filter dropdown (company scope)
$employees = [];
try {
    if ($isSuperAdmin) {
        $employees = $db->fetchAll("SELECT id, COALESCE(name_en,name_ar,email,'Unknown') AS name FROM employees ORDER BY name LIMIT 500");
    } else {
        $employees = $db->fetchAll("SELECT id, COALESCE(name_en,name_ar,email,'Unknown') AS name FROM employees WHERE company_id = :cid ORDER BY name", ['cid' => $companyId]);
    }
} catch (Exception $e) {}

$basePath = getAdminBasePath();
adminHeader(t('adminchrome.appointments'), 'appointments');
?>

<?php if ($message): ?>
<div class="mb-4 p-4 rounded-xl flex items-center gap-3 <?= $messageType === 'success' ? 'bg-green-50 border border-green-200 text-green-700' : 'bg-red-50 border border-red-200 text-red-700'; ?>">
    <i class="<?= $messageType === 'success' ? 'fa-solid fa-circle-check' : 'fa-solid fa-circle-exclamation'; ?>"></i>
    <?= sanitize($message); ?>
</div>
<?php endif; ?>

<div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
    <div>
        <h1 class="text-2xl font-bold text-gray-900"><i class="fa-solid fa-calendar-check text-blue-600 mr-2"></i>Appointments</h1>
        <p class="text-sm text-gray-500 mt-0.5">Bookings made through your team's digital cards.</p>
    </div>
    <form method="get" class="flex items-center gap-2">
        <input type="hidden" name="filter" value="<?= sanitize($filter); ?>">
        <select name="employee_id" onchange="this.form.submit()" class="px-3 py-2 bg-white border border-gray-200 rounded-lg text-sm">
            <option value="">All employees</option>
            <?php foreach ($employees as $emp): ?>
            <option value="<?= sanitize($emp['id']); ?>" <?= $employeeFilter === $emp['id'] ? 'selected' : ''; ?>><?= sanitize($emp['name']); ?></option>
            <?php endforeach; ?>
        </select>
    </form>
</div>

<div class="border-b border-gray-200 mb-6">
    <nav class="-mb-px flex gap-6 overflow-x-auto">
        <?php
        $filters = [
            'upcoming' => ['label' => 'Upcoming', 'icon' => 'fa-solid fa-calendar-day'],
            'pending'  => ['label' => 'Pending',  'icon' => 'fa-solid fa-clock'],
            'past'     => ['label' => 'Past',     'icon' => 'fa-solid fa-clock-rotate-left'],
            'all'      => ['label' => 'All',      'icon' => 'fa-solid fa-list'],
        ];
        foreach ($filters as $k => $f):
            $active = $filter === $k;
            $qs = http_build_query(['filter' => $k, 'employee_id' => $employeeFilter]);
        ?>
        <a href="?<?= $qs; ?>" class="whitespace-nowrap pb-3 px-1 text-sm font-medium flex items-center gap-1.5 border-b-2 transition-colors <?= $active ? 'border-blue-600 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?>">
            <i class="<?= $f['icon']; ?>"></i> <?= $f['label']; ?>
            <span class="text-xs <?= $active ? 'bg-blue-100 text-blue-700' : 'bg-gray-100 text-gray-600'; ?> px-2 py-0.5 rounded-full"><?= $counts[$k]; ?></span>
        </a>
        <?php endforeach; ?>
    </nav>
</div>

<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
    <?php if (empty($appointments)): ?>
    <div class="p-12 text-center text-gray-500">
        <i class="fa-solid fa-calendar-xmark text-4xl text-gray-300 mb-3"></i>
        <p class="font-medium">No appointments to show</p>
        <p class="text-sm mt-1">Bookings made through digital cards will appear here.</p>
    </div>
    <?php else: ?>
    <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50 text-xs uppercase text-gray-500 tracking-wide">
                <tr>
                    <th class="px-4 py-3 text-left">When</th>
                    <th class="px-4 py-3 text-left">Visitor</th>
                    <th class="px-4 py-3 text-left">Contact</th>
                    <th class="px-4 py-3 text-left">With</th>
                    <th class="px-4 py-3 text-left">Status</th>
                    <th class="px-4 py-3 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <?php foreach ($appointments as $a):
                    $st = $a['status'];
                    $badge = [
                        'pending'   => 'bg-amber-100 text-amber-700',
                        'confirmed' => 'bg-green-100 text-green-700',
                        'cancelled' => 'bg-gray-200 text-gray-600',
                        'completed' => 'bg-blue-100 text-blue-700',
                    ][$st] ?? 'bg-gray-100 text-gray-700';
                    $when = '';
                    try { $when = (new DateTime($a['slot_start']))->format('D, M j Y · g:i A'); } catch (Throwable $e) { $when = $a['slot_start']; }
                ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 whitespace-nowrap">
                        <div class="font-medium text-gray-900"><?= sanitize($when); ?></div>
                        <div class="text-xs text-gray-500">
                            <?php
                            try {
                                $s = new DateTime($a['slot_start']); $e = new DateTime($a['slot_end']);
                                echo (int)round(($e->getTimestamp() - $s->getTimestamp())/60) . ' min';
                            } catch (Throwable $ex) {}
                            ?>
                        </div>
                    </td>
                    <td class="px-4 py-3"><div class="font-medium text-gray-900"><?= sanitize($a['visitor_name']); ?></div>
                        <?php if (!empty($a['visitor_notes'])): ?>
                        <div class="text-xs text-gray-500 max-w-xs truncate" title="<?= sanitize($a['visitor_notes']); ?>"><?= sanitize($a['visitor_notes']); ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-gray-700">
                        <?php if (!empty($a['visitor_email'])): ?><div><a href="mailto:<?= sanitize($a['visitor_email']); ?>" class="text-blue-600 hover:underline"><?= sanitize($a['visitor_email']); ?></a></div><?php endif; ?>
                        <?php if (!empty($a['visitor_phone'])): ?><div class="text-xs text-gray-500"><?= sanitize($a['visitor_phone']); ?></div><?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-gray-700"><?= sanitize($a['employee_name']); ?></td>
                    <td class="px-4 py-3"><span class="inline-block px-2 py-1 rounded-full text-xs font-medium <?= $badge; ?>"><?= ucfirst($st); ?></span></td>
                    <td class="px-4 py-3 text-right whitespace-nowrap">
                        <?php if ($st === 'pending' || $st === 'confirmed'): ?>
                        <?php if ($st === 'pending'): ?>
                        <form method="post" class="inline">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? ''; ?>">
                            <input type="hidden" name="action" value="update_status">
                            <input type="hidden" name="appointment_id" value="<?= sanitize($a['id']); ?>">
                            <input type="hidden" name="status" value="confirmed">
                            <button class="text-xs px-2 py-1 bg-green-600 text-white rounded hover:bg-green-700"><i class="fa-solid fa-check"></i> Confirm</button>
                        </form>
                        <?php endif; ?>
                        <form method="post" class="inline" onsubmit="return confirm('Cancel this appointment?');">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? ''; ?>">
                            <input type="hidden" name="action" value="update_status">
                            <input type="hidden" name="appointment_id" value="<?= sanitize($a['id']); ?>">
                            <input type="hidden" name="status" value="cancelled">
                            <button class="text-xs px-2 py-1 bg-red-50 text-red-600 border border-red-200 rounded hover:bg-red-100"><i class="fa-solid fa-xmark"></i> Cancel</button>
                        </form>
                        <?php elseif ($st === 'confirmed'): ?>
                        <form method="post" class="inline">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? ''; ?>">
                            <input type="hidden" name="action" value="update_status">
                            <input type="hidden" name="appointment_id" value="<?= sanitize($a['id']); ?>">
                            <input type="hidden" name="status" value="completed">
                            <button class="text-xs px-2 py-1 bg-blue-50 text-blue-700 border border-blue-200 rounded hover:bg-blue-100">Mark complete</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php adminFooter(); ?>
