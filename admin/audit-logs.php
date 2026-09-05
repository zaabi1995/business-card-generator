<?php
/**
 * Audit Logs (Super Admin) - Cardify
 * Enhanced with quick filters, statistics, and export
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/admin-layout.php';

Auth::requireRole('super_admin');

$db = Database::getInstance();

// Handle export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $exportFilters = [];
    if (!empty($_GET['company_id'])) $exportFilters['company_id'] = $_GET['company_id'];
    if (!empty($_GET['action'])) $exportFilters['action'] = $_GET['action'];
    if (!empty($_GET['entity_type'])) $exportFilters['entity_type'] = $_GET['entity_type'];
    if (!empty($_GET['date_from'])) $exportFilters['date_from'] = $_GET['date_from'] . ' 00:00:00';
    if (!empty($_GET['date_to'])) $exportFilters['date_to'] = $_GET['date_to'] . ' 23:59:59';
    if (!empty($_GET['actor'])) $exportFilters['actor'] = $_GET['actor'];
    
    $exportLogs = AuditLog::getLogs($exportFilters, 10000, 0);
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="audit-logs-' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Time', 'Actor Email', 'Actor Role', 'Action', 'Entity Type', 'Entity ID', 'Company', 'IP Address']);
    
    $companyMap = [];
    foreach ($db->fetchAll("SELECT id, name FROM companies") as $c) {
        $companyMap[$c['id']] = $c['name'];
    }
    
    // CSV injection guard (CWE-1236): audit rows include user-controlled data
    // (actor_email, entity_id, action). Neutralise formula-trigger leading chars
    // so a payload can't execute when a super-admin opens the export in Excel.
    $csvSafe = function ($v) {
        $v = (string)$v;
        if ($v !== '' && strpos("=+-@\t\r", $v[0]) !== false) {
            $v = "'" . $v;
        }
        return $v;
    };
    foreach ($exportLogs as $log) {
        fputcsv($output, array_map($csvSafe, [
            $log['created_at'],
            $log['actor_email'] ?? 'System',
            $log['actor_role'] ?? '-',
            $log['action'],
            $log['entity_type'],
            $log['entity_id'] ?? '-',
            $companyMap[$log['company_id']] ?? 'Global',
            $log['ip_address'] ?? '-'
        ]));
    }
    fclose($output);
    exit;
}

// Pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

// Quick filter presets
$quickFilter = $_GET['quick'] ?? '';
$presetFilters = [
    'auth' => ['action' => 'login,logout'],
    'logins' => ['action' => 'login'],
    'logouts' => ['action' => 'logout'],
    'creates' => ['action' => 'create'],
    'updates' => ['action' => 'update'],
    'deletes' => ['action' => 'delete'],
    'today' => ['date_from' => date('Y-m-d'), 'date_to' => date('Y-m-d')],
];

// Filters
$filters = [];
if (!empty($_GET['company_id'])) {
    $filters['company_id'] = $_GET['company_id'];
}
if (!empty($_GET['action'])) {
    // Support comma-separated actions
    $filters['action'] = $_GET['action'];
}
if (!empty($_GET['entity_type'])) {
    $filters['entity_type'] = $_GET['entity_type'];
}
if (!empty($_GET['date_from'])) {
    $filters['date_from'] = $_GET['date_from'] . ' 00:00:00';
}
if (!empty($_GET['date_to'])) {
    $filters['date_to'] = $_GET['date_to'] . ' 23:59:59';
}
if (!empty($_GET['actor'])) {
    $filters['actor'] = $_GET['actor'];
}

// Apply quick filter presets
if ($quickFilter && isset($presetFilters[$quickFilter])) {
    $filters = array_merge($filters, $presetFilters[$quickFilter]);
    if (isset($presetFilters[$quickFilter]['date_from'])) {
        $filters['date_from'] = $presetFilters[$quickFilter]['date_from'] . ' 00:00:00';
    }
    if (isset($presetFilters[$quickFilter]['date_to'])) {
        $filters['date_to'] = $presetFilters[$quickFilter]['date_to'] . ' 23:59:59';
    }
}

// Get logs with enhanced filtering
$logs = [];
$totalLogs = 0;

if (class_exists('AuditLog')) {
    $logs = AuditLog::getLogs($filters, $perPage, $offset);
    $totalLogs = AuditLog::countLogs($filters);
}

$totalPages = ceil($totalLogs / $perPage);

// Get companies for filter
$companies = $db->fetchAll("SELECT id, name FROM companies ORDER BY name");

// Get distinct actions and entity types
$actions = [];
$entityTypes = [];
try {
    $actionRows = $db->fetchAll("SELECT DISTINCT action FROM audit_logs ORDER BY action");
    $actions = array_column($actionRows, 'action');
    
    $entityRows = $db->fetchAll("SELECT DISTINCT entity_type FROM audit_logs ORDER BY entity_type");
    $entityTypes = array_column($entityRows, 'entity_type');
} catch (Exception $e) {
    // Table might not exist yet
}

// Get statistics
$stats = [
    'total' => 0,
    'today' => 0,
    'logins' => 0,
    'logouts' => 0,
    'creates' => 0,
    'updates' => 0,
    'deletes' => 0,
];
try {
    $stats['total'] = $db->fetchOne("SELECT COUNT(*) as count FROM audit_logs")['count'] ?? 0;
    $stats['today'] = $db->fetchOne("SELECT COUNT(*) as count FROM audit_logs WHERE DATE(created_at) = CURDATE()")['count'] ?? 0;
    $stats['logins'] = $db->fetchOne("SELECT COUNT(*) as count FROM audit_logs WHERE action = 'login'")['count'] ?? 0;
    $stats['logouts'] = $db->fetchOne("SELECT COUNT(*) as count FROM audit_logs WHERE action = 'logout'")['count'] ?? 0;
    $stats['creates'] = $db->fetchOne("SELECT COUNT(*) as count FROM audit_logs WHERE action = 'create'")['count'] ?? 0;
    $stats['updates'] = $db->fetchOne("SELECT COUNT(*) as count FROM audit_logs WHERE action = 'update'")['count'] ?? 0;
    $stats['deletes'] = $db->fetchOne("SELECT COUNT(*) as count FROM audit_logs WHERE action = 'delete'")['count'] ?? 0;
} catch (Exception $e) {
    // Table might not exist
}

adminHeader(t('adminchrome.audit_logs'), 'audit-logs');
?>

<!-- Statistics Cards -->
<div class="grid grid-cols-2 md:grid-cols-4 xl:grid-cols-7 gap-4 mb-6">
    <a href="?quick=today" class="bg-white rounded-lg shadow p-4 hover:shadow-md transition-shadow <?php echo $quickFilter === 'today' ? 'ring-2 ring-blue-500' : ''; ?>">
        <div class="text-2xl font-bold text-gray-900"><?php echo number_format($stats['today']); ?></div>
        <div class="text-xs text-gray-500"><?= htmlspecialchars(t('auditlogs.stat_today')) ?></div>
    </a>
    <a href="?" class="bg-white rounded-lg shadow p-4 hover:shadow-md transition-shadow <?php echo empty($quickFilter) && empty($_GET['action']) ? 'ring-2 ring-blue-500' : ''; ?>">
        <div class="text-2xl font-bold text-gray-900"><?php echo number_format($stats['total']); ?></div>
        <div class="text-xs text-gray-500"><?= htmlspecialchars(t('auditlogs.stat_total')) ?></div>
    </a>
    <a href="?quick=auth" class="bg-white rounded-lg shadow p-4 hover:shadow-md transition-shadow <?php echo $quickFilter === 'auth' ? 'ring-2 ring-purple-500' : ''; ?>">
        <div class="text-2xl font-bold text-purple-600"><?php echo number_format($stats['logins'] + $stats['logouts']); ?></div>
        <div class="text-xs text-gray-500"><?= htmlspecialchars(t('auditlogs.stat_auth')) ?></div>
    </a>
    <a href="?quick=logins" class="bg-white rounded-lg shadow p-4 hover:shadow-md transition-shadow <?php echo $quickFilter === 'logins' ? 'ring-2 ring-green-500' : ''; ?>">
        <div class="text-2xl font-bold text-green-600"><?php echo number_format($stats['logins']); ?></div>
        <div class="text-xs text-gray-500"><?= htmlspecialchars(t('auditlogs.stat_logins')) ?></div>
    </a>
    <a href="?quick=logouts" class="bg-white rounded-lg shadow p-4 hover:shadow-md transition-shadow <?php echo $quickFilter === 'logouts' ? 'ring-2 ring-gray-500' : ''; ?>">
        <div class="text-2xl font-bold text-gray-600"><?php echo number_format($stats['logouts']); ?></div>
        <div class="text-xs text-gray-500"><?= htmlspecialchars(t('auditlogs.stat_logouts')) ?></div>
    </a>
    <a href="?quick=creates" class="bg-white rounded-lg shadow p-4 hover:shadow-md transition-shadow <?php echo $quickFilter === 'creates' ? 'ring-2 ring-emerald-500' : ''; ?>">
        <div class="text-2xl font-bold text-emerald-600"><?php echo number_format($stats['creates']); ?></div>
        <div class="text-xs text-gray-500"><?= htmlspecialchars(t('auditlogs.stat_creates')) ?></div>
    </a>
    <a href="?quick=updates" class="bg-white rounded-lg shadow p-4 hover:shadow-md transition-shadow <?php echo $quickFilter === 'updates' ? 'ring-2 ring-blue-500' : ''; ?>">
        <div class="text-2xl font-bold text-blue-600"><?php echo number_format($stats['updates']); ?></div>
        <div class="text-xs text-gray-500"><?= htmlspecialchars(t('auditlogs.stat_updates')) ?></div>
    </a>
</div>

<!-- Filters -->
<div class="bg-white rounded-xl border border-gray-200 shadow-sm p-4 mb-6">
    <form method="get" class="space-y-4">
        <div class="flex flex-wrap items-end gap-4">
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1"><?= htmlspecialchars(t('auditlogs.filter_actor')) ?></label>
                <input type="text" name="actor" value="<?php echo sanitize($_GET['actor'] ?? ''); ?>"
                       placeholder="<?= htmlspecialchars(t('auditlogs.filter_actor_ph')) ?>"
                       class="px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg text-sm w-48">
            </div>
            
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1"><?= htmlspecialchars(t('auditlogs.filter_company')) ?></label>
                <select name="company_id" class="px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg text-sm">
                    <option value=""><?= htmlspecialchars(t('auditlogs.all_companies')) ?></option>
                    <?php foreach ($companies as $company): ?>
                    <option value="<?php echo $company['id']; ?>" <?php echo ($_GET['company_id'] ?? '') === $company['id'] ? 'selected' : ''; ?>>
                        <?php echo sanitize($company['name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1"><?= htmlspecialchars(t('auditlogs.filter_action')) ?></label>
                <select name="action" class="px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg text-sm">
                    <option value=""><?= htmlspecialchars(t('auditlogs.all_actions')) ?></option>
                    <option value="login,logout" <?php echo ($_GET['action'] ?? '') === 'login,logout' ? 'selected' : ''; ?>><?= htmlspecialchars(t('auditlogs.auth_option')) ?></option>
                    <?php foreach ($actions as $action): ?>
                    <option value="<?php echo sanitize($action); ?>" <?php echo ($_GET['action'] ?? '') === $action ? 'selected' : ''; ?>>
                        <?php echo sanitize($action); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1"><?= htmlspecialchars(t('auditlogs.filter_entity')) ?></label>
                <select name="entity_type" class="px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg text-sm">
                    <option value=""><?= htmlspecialchars(t('auditlogs.all_types')) ?></option>
                    <?php foreach ($entityTypes as $type): ?>
                    <option value="<?php echo sanitize($type); ?>" <?php echo ($_GET['entity_type'] ?? '') === $type ? 'selected' : ''; ?>>
                        <?php echo sanitize($type); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1"><?= htmlspecialchars(t('auditlogs.filter_from')) ?></label>
                <input type="date" name="date_from" value="<?php echo htmlspecialchars($_GET['date_from'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                       class="px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg text-sm">
            </div>

            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1"><?= htmlspecialchars(t('auditlogs.filter_to')) ?></label>
                <input type="date" name="date_to" value="<?php echo htmlspecialchars($_GET['date_to'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                       class="px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg text-sm">
            </div>
            
            <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium transition-colors">
                <i class="fa-solid fa-filter mr-1"></i> <?= htmlspecialchars(t('auditlogs.btn_filter')) ?>
            </button>

            <a href="audit-logs.php" class="px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg text-sm font-medium transition-colors">
                <?= htmlspecialchars(t('auditlogs.btn_clear')) ?>
            </a>

            <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'csv'])); ?>"
               class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg text-sm font-medium transition-colors ml-auto">
                <i class="fa-solid fa-download mr-1"></i> <?= htmlspecialchars(t('auditlogs.btn_export_csv')) ?>
            </a>
        </div>
    </form>
</div>

<!-- Stats -->
<div class="mb-4 text-sm text-gray-600">
    <?= htmlspecialchars(strtr(t('auditlogs.showing_of'), [':shown' => (string) count($logs), ':total' => number_format($totalLogs)])) ?>
</div>

<!-- Logs Table -->
<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead class="bg-gray-50 border-b border-gray-200">
                <tr>
                    <th class="text-left px-4 py-3 text-gray-600 font-semibold text-xs uppercase"><?= htmlspecialchars(t('auditlogs.col_time')) ?></th>
                    <th class="text-left px-4 py-3 text-gray-600 font-semibold text-xs uppercase"><?= htmlspecialchars(t('auditlogs.col_actor')) ?></th>
                    <th class="text-left px-4 py-3 text-gray-600 font-semibold text-xs uppercase"><?= htmlspecialchars(t('auditlogs.col_action')) ?></th>
                    <th class="text-left px-4 py-3 text-gray-600 font-semibold text-xs uppercase"><?= htmlspecialchars(t('auditlogs.col_entity')) ?></th>
                    <th class="text-left px-4 py-3 text-gray-600 font-semibold text-xs uppercase"><?= htmlspecialchars(t('auditlogs.col_company')) ?></th>
                    <th class="text-left px-4 py-3 text-gray-600 font-semibold text-xs uppercase"><?= htmlspecialchars(t('auditlogs.col_details')) ?></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <?php if (empty($logs)): ?>
                <tr>
                    <td colspan="6" class="px-4 py-12 text-center text-gray-500">
                        <i class="fa-solid fa-clipboard-list text-4xl mb-3 opacity-30"></i>
                        <p><?= htmlspecialchars(t('auditlogs.empty')) ?></p>
                    </td>
                </tr>
                <?php endif; ?>
                
                <?php foreach ($logs as $log): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 text-sm text-gray-600 whitespace-nowrap">
                        <?php echo date('M j, Y H:i', dbTs($log['created_at'])); ?>
                    </td>
                    <td class="px-4 py-3">
                        <div class="text-sm">
                            <p class="text-gray-900"><?php echo sanitize($log['actor_email'] ?? t('auditlogs.system_actor')); ?></p>
                            <p class="text-gray-500 text-xs"><?php echo sanitize($log['actor_role'] ?? '-'); ?></p>
                        </div>
                    </td>
                    <td class="px-4 py-3">
                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium
                            <?php echo match($log['action']) {
                                'create' => 'bg-green-100 text-green-700',
                                'update' => 'bg-blue-100 text-blue-700',
                                'delete' => 'bg-red-100 text-red-700',
                                'login' => 'bg-purple-100 text-purple-700',
                                default => 'bg-gray-100 text-gray-700'
                            }; ?>">
                            <?php echo sanitize($log['action']); ?>
                        </span>
                    </td>
                    <td class="px-4 py-3">
                        <div class="text-sm">
                            <p class="text-gray-900"><?php echo sanitize($log['entity_type']); ?></p>
                            <p class="text-gray-500 text-xs font-mono"><?php echo substr($log['entity_id'] ?? '-', 0, 8); ?>...</p>
                        </div>
                    </td>
                    <td class="px-4 py-3 text-sm text-gray-600">
                        <?php 
                        if ($log['company_id']) {
                            $companyName = '-';
                            foreach ($companies as $c) {
                                if ($c['id'] === $log['company_id']) {
                                    $companyName = $c['name'];
                                    break;
                                }
                            }
                            echo sanitize($companyName);
                        } else {
                            echo '<span class="text-gray-400">' . htmlspecialchars(t('auditlogs.global_scope')) . '</span>';
                        }
                        ?>
                    </td>
                    <td class="px-4 py-3">
                        <?php if ($log['before_data'] || $log['after_data']): ?>
                        <button data-cardify-action="call" data-fn="showLogDetails" data-args="<?= htmlspecialchars(json_encode([$log]), ENT_QUOTES) ?>"
                                class="text-blue-600 hover:text-blue-800 text-sm">
                            <?= htmlspecialchars(t('auditlogs.view_changes')) ?>
                        </button>
                        <?php else: ?>
                        <span class="text-gray-400 text-sm">-</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <?php if ($totalPages > 1): ?>
    <div class="px-4 py-3 bg-gray-50 border-t border-gray-200 flex items-center justify-between">
        <div class="text-sm text-gray-600">
            <?= htmlspecialchars(strtr(t('auditlogs.page_n_of_m'), [':cur' => (string) $page, ':tot' => (string) $totalPages])) ?>
        </div>
        <div class="flex gap-2">
            <?php if ($page > 1): ?>
            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>"
               class="px-3 py-1 bg-white border border-gray-200 rounded text-sm hover:bg-gray-50">
                <?= htmlspecialchars(t('auditlogs.prev')) ?>
            </a>
            <?php endif; ?>

            <?php if ($page < $totalPages): ?>
            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>"
               class="px-3 py-1 bg-white border border-gray-200 rounded text-sm hover:bg-gray-50">
                <?= htmlspecialchars(t('auditlogs.next')) ?>
            </a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Details Modal -->
<div id="logModal" class="fixed inset-0 z-50 hidden items-center justify-center p-4">
    <div class="absolute inset-0 bg-gray-900/50 backdrop-blur-sm" data-cardify-action="call" data-fn="closeLogModal"></div>
    <div class="relative bg-white rounded-2xl shadow-xl w-full max-w-2xl max-h-[90vh] overflow-y-auto">
        <div class="p-6 border-b border-gray-100 flex items-center justify-between">
            <h3 class="text-xl font-bold text-gray-900"><?= htmlspecialchars(t('auditlogs.modal_title')) ?></h3>
            <button data-cardify-action="call" data-fn="closeLogModal" class="text-gray-400 hover:text-gray-600">
                <i class="fa-solid fa-times"></i>
            </button>
        </div>
        <div class="p-6">
            <div class="grid md:grid-cols-2 gap-6">
                <div>
                    <h4 class="font-semibold text-gray-700 mb-2"><?= htmlspecialchars(t('auditlogs.before')) ?></h4>
                    <pre id="beforeData" class="bg-gray-50 p-4 rounded-lg text-xs overflow-x-auto max-h-80"></pre>
                </div>
                <div>
                    <h4 class="font-semibold text-gray-700 mb-2"><?= htmlspecialchars(t('auditlogs.after')) ?></h4>
                    <pre id="afterData" class="bg-gray-50 p-4 rounded-lg text-xs overflow-x-auto max-h-80"></pre>
                </div>
            </div>
            <div class="mt-4 text-sm text-gray-500">
                <p><strong><?= htmlspecialchars(t('auditlogs.ip_label')) ?></strong> <span id="logIp">-</span></p>
                <p><strong><?= htmlspecialchars(t('auditlogs.ua_label')) ?></strong> <span id="logUserAgent">-</span></p>
            </div>
        </div>
    </div>
</div>

<script<?= cspNonceAttr() ?>>
function showLogDetails(log) {
    const modal = document.getElementById('logModal');
    const beforeData = document.getElementById('beforeData');
    const afterData = document.getElementById('afterData');
    const logIp = document.getElementById('logIp');
    const logUserAgent = document.getElementById('logUserAgent');
    
    try {
        const before = log.before_data ? JSON.parse(log.before_data) : null;
        const after = log.after_data ? JSON.parse(log.after_data) : null;
        
        beforeData.textContent = before ? JSON.stringify(before, null, 2) : <?= json_encode(t('auditlogs.no_data'), JSON_UNESCAPED_UNICODE) ?>;
        afterData.textContent = after ? JSON.stringify(after, null, 2) : <?= json_encode(t('auditlogs.no_data'), JSON_UNESCAPED_UNICODE) ?>;
    } catch (e) {
        beforeData.textContent = log.before_data || <?= json_encode(t('auditlogs.no_data'), JSON_UNESCAPED_UNICODE) ?>;
        afterData.textContent = log.after_data || <?= json_encode(t('auditlogs.no_data'), JSON_UNESCAPED_UNICODE) ?>;
    }
    
    logIp.textContent = log.ip_address || '-';
    logUserAgent.textContent = log.user_agent || '-';
    
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}

function closeLogModal() {
    const modal = document.getElementById('logModal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeLogModal();
    }
});
</script>

<?php adminFooter(); ?>
