<?php
/**
 * Super Admin - Email Logs
 * View all sent email notifications and their status
 */
require_once __DIR__ . '/../../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/Mailer.php';
require_once INCLUDES_DIR . '/admin-layout.php';

Auth::requireRole('super_admin');

$db = Database::getInstance();

// Check if email_logs table exists
$tableExists = $db->tableExists('email_logs');

// Filters
$search = $_GET['search'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$templateFilter = $_GET['template'] ?? '';
$companyFilter = (int)($_GET['company_id'] ?? 0);
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 30;
$offset = ($page - 1) * $perPage;

$logs = [];
$total = 0;
$totalPages = 0;
$templates = [];
$companies = [];
$stats = [];

if ($tableExists) {
    // Build filters
    $filters = [];
    if ($search) $filters['recipient_email'] = $search;
    if ($statusFilter) $filters['status'] = $statusFilter;
    if ($templateFilter) $filters['template'] = $templateFilter;
    if ($companyFilter) $filters['company_id'] = $companyFilter;
    if ($dateFrom) $filters['date_from'] = $dateFrom;
    if ($dateTo) $filters['date_to'] = $dateTo;
    
    // Get logs using Mailer class
    $logs = Mailer::getLogs($filters, $perPage, $offset);
    $total = Mailer::countLogs($filters);
    $totalPages = ceil($total / $perPage);
    
    // Get available templates
    $templates = Mailer::getTemplatesList();
    
    // Get companies for filter
    $companies = $db->fetchAll("SELECT id, name FROM companies ORDER BY name");
    
    // Get stats
    $stats = [
        'total' => $db->fetchOne("SELECT COUNT(*) as c FROM email_logs")['c'] ?? 0,
        'sent' => $db->fetchOne("SELECT COUNT(*) as c FROM email_logs WHERE status = 'sent'")['c'] ?? 0,
        'failed' => $db->fetchOne("SELECT COUNT(*) as c FROM email_logs WHERE status = 'failed'")['c'] ?? 0,
        'today' => $db->fetchOne("SELECT COUNT(*) as c FROM email_logs WHERE DATE(created_at) = CURDATE()")['c'] ?? 0,
    ];
}

adminHeader('Email Logs', 'email_logs');
?>

<?php if (!$tableExists): ?>
<div class="bg-amber-100 border-l-4 border-amber-500 text-amber-700 p-4 mb-6">
    <div class="flex items-center">
        <i class="fa-solid fa-exclamation-triangle mr-3"></i>
        <div>
            <p class="font-bold">Email Logs Table Not Found</p>
            <p>The email_logs table doesn't exist yet. Please run database migrations to create it.</p>
            <a href="../updates.php" class="text-amber-800 underline">Go to Database Updates</a>
        </div>
    </div>
</div>
<?php else: ?>

<!-- Stats -->
<div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
    <div class="bg-white rounded-lg shadow p-4">
        <div class="flex items-center justify-between">
            <div>
                <div class="text-sm text-gray-500">Total Emails</div>
                <div class="text-2xl font-semibold"><?php echo number_format($stats['total']); ?></div>
            </div>
            <div class="w-10 h-10 rounded-lg bg-blue-100 text-blue-600 flex items-center justify-center">
                <i class="fa-solid fa-envelope"></i>
            </div>
        </div>
    </div>
    <div class="bg-white rounded-lg shadow p-4">
        <div class="flex items-center justify-between">
            <div>
                <div class="text-sm text-gray-500">Sent Successfully</div>
                <div class="text-2xl font-semibold text-green-600"><?php echo number_format($stats['sent']); ?></div>
            </div>
            <div class="w-10 h-10 rounded-lg bg-green-100 text-green-600 flex items-center justify-center">
                <i class="fa-solid fa-check-circle"></i>
            </div>
        </div>
    </div>
    <div class="bg-white rounded-lg shadow p-4">
        <div class="flex items-center justify-between">
            <div>
                <div class="text-sm text-gray-500">Failed</div>
                <div class="text-2xl font-semibold text-red-600"><?php echo number_format($stats['failed']); ?></div>
            </div>
            <div class="w-10 h-10 rounded-lg bg-red-100 text-red-600 flex items-center justify-center">
                <i class="fa-solid fa-times-circle"></i>
            </div>
        </div>
    </div>
    <div class="bg-white rounded-lg shadow p-4">
        <div class="flex items-center justify-between">
            <div>
                <div class="text-sm text-gray-500">Today</div>
                <div class="text-2xl font-semibold text-purple-600"><?php echo number_format($stats['today']); ?></div>
            </div>
            <div class="w-10 h-10 rounded-lg bg-purple-100 text-purple-600 flex items-center justify-center">
                <i class="fa-solid fa-calendar-day"></i>
            </div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="bg-white rounded-lg shadow p-4 mb-6">
    <form method="GET" class="space-y-4">
        <div class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-6 gap-4">
            <input type="text" name="search" value="<?php echo sanitize($search); ?>" 
                   placeholder="Search recipient email..." 
                   class="px-4 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
            <select name="status" class="px-4 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                <option value="">All Status</option>
                <option value="sent" <?php echo $statusFilter === 'sent' ? 'selected' : ''; ?>>Sent</option>
                <option value="failed" <?php echo $statusFilter === 'failed' ? 'selected' : ''; ?>>Failed</option>
                <option value="pending" <?php echo $statusFilter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                <option value="bounced" <?php echo $statusFilter === 'bounced' ? 'selected' : ''; ?>>Bounced</option>
            </select>
            <select name="template" class="px-4 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                <option value="">All Templates</option>
                <?php foreach ($templates as $tpl): ?>
                <option value="<?php echo sanitize($tpl); ?>" <?php echo $templateFilter === $tpl ? 'selected' : ''; ?>>
                    <?php echo sanitize(ucwords(str_replace('_', ' ', $tpl))); ?>
                </option>
                <?php endforeach; ?>
            </select>
            <select name="company_id" class="px-4 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                <option value="">All Companies</option>
                <?php foreach ($companies as $c): ?>
                <option value="<?php echo $c['id']; ?>" <?php echo $companyFilter == $c['id'] ? 'selected' : ''; ?>>
                    <?php echo sanitize($c['name']); ?>
                </option>
                <?php endforeach; ?>
            </select>
            <input type="date" name="date_from" value="<?php echo sanitize($dateFrom); ?>" 
                   placeholder="From date"
                   class="px-4 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
            <input type="date" name="date_to" value="<?php echo sanitize($dateTo); ?>" 
                   placeholder="To date"
                   class="px-4 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
        </div>
        <div class="flex gap-3">
            <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                <i class="fa-solid fa-search mr-2"></i>Filter
            </button>
            <?php if ($search || $statusFilter || $templateFilter || $companyFilter || $dateFrom || $dateTo): ?>
            <a href="email_logs.php" class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50">Clear Filters</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- Email Logs Table -->
<div class="bg-white rounded-lg shadow overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-sm text-left text-gray-500">
            <thead class="text-xs text-gray-700 uppercase bg-gray-50">
                <tr>
                    <th class="px-6 py-3">Date/Time</th>
                    <th class="px-6 py-3">Recipient</th>
                    <th class="px-6 py-3">Subject</th>
                    <th class="px-6 py-3">Template</th>
                    <th class="px-6 py-3">Company</th>
                    <th class="px-6 py-3">Status</th>
                    <th class="px-6 py-3">Sent By</th>
                    <th class="px-6 py-3">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                <tr><td colspan="8" class="px-6 py-8 text-center text-gray-500">
                    <?php if ($search || $statusFilter || $templateFilter || $companyFilter || $dateFrom || $dateTo): ?>
                    No emails found matching your filters
                    <?php else: ?>
                    No email logs yet. Emails will appear here after they are sent.
                    <?php endif; ?>
                </td></tr>
                <?php else: ?>
                <?php foreach ($logs as $log): ?>
                <tr class="bg-white border-b hover:bg-gray-50">
                    <td class="px-6 py-4">
                        <div class="font-medium text-gray-900"><?php echo date('M d, Y', dbTs($log['created_at'])); ?></div>
                        <div class="text-xs text-gray-500"><?php echo date('H:i:s', dbTs($log['created_at'])); ?></div>
                    </td>
                    <td class="px-6 py-4">
                        <div class="font-medium text-gray-900"><?php echo sanitize($log['recipient_email']); ?></div>
                        <?php if ($log['recipient_name']): ?>
                        <div class="text-xs text-gray-500"><?php echo sanitize($log['recipient_name']); ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4">
                        <div class="max-w-xs truncate" title="<?php echo sanitize($log['subject']); ?>">
                            <?php echo sanitize($log['subject']); ?>
                        </div>
                    </td>
                    <td class="px-6 py-4">
                        <?php if ($log['template']): ?>
                        <span class="px-2 py-1 bg-blue-50 text-blue-700 rounded text-xs">
                            <?php echo sanitize(ucwords(str_replace('_', ' ', $log['template']))); ?>
                        </span>
                        <?php else: ?>
                        <span class="text-gray-400">Custom</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4">
                        <?php if ($log['company_name']): ?>
                        <a href="companies.php?search=<?php echo urlencode($log['company_name']); ?>" class="text-blue-600 hover:underline">
                            <?php echo sanitize($log['company_name']); ?>
                        </a>
                        <?php else: ?>
                        <span class="text-gray-400">-</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4">
                        <span class="px-2 py-1 rounded-full text-xs font-semibold 
                            <?php 
                            echo match($log['status']) {
                                'sent' => 'bg-green-100 text-green-700',
                                'failed' => 'bg-red-100 text-red-700',
                                'pending' => 'bg-amber-100 text-amber-700',
                                'bounced' => 'bg-red-100 text-red-700',
                                default => 'bg-gray-100 text-gray-700'
                            };
                            ?>">
                            <?php echo sanitize(ucfirst($log['status'])); ?>
                        </span>
                    </td>
                    <td class="px-6 py-4">
                        <?php if ($log['actor_email']): ?>
                        <div class="text-xs">
                            <div><?php echo sanitize($log['actor_email']); ?></div>
                            <div class="text-gray-400"><?php echo sanitize($log['actor_role'] ?? ''); ?></div>
                        </div>
                        <?php else: ?>
                        <span class="text-gray-400">System</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4">
                        <button onclick='showDetails(<?php echo json_encode($log); ?>)' 
                                class="text-blue-600 hover:text-blue-800" title="View Details">
                            <i class="fa-solid fa-eye"></i>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="px-6 py-4 border-t border-gray-100">
        <div class="flex items-center justify-between">
            <div class="text-sm text-gray-500">
                Showing <?php echo $offset + 1; ?> to <?php echo min($offset + $perPage, $total); ?> of <?php echo number_format($total); ?> emails
            </div>
            <div class="flex gap-2">
                <?php 
                $queryParams = http_build_query(array_filter([
                    'search' => $search,
                    'status' => $statusFilter,
                    'template' => $templateFilter,
                    'company_id' => $companyFilter,
                    'date_from' => $dateFrom,
                    'date_to' => $dateTo
                ]));
                ?>
                <?php if ($page > 1): ?>
                <a href="?page=<?php echo $page - 1; ?>&<?php echo $queryParams; ?>" 
                   class="px-3 py-1 border rounded hover:bg-gray-50">Previous</a>
                <?php endif; ?>
                
                <?php
                // Show page numbers
                $startPage = max(1, $page - 2);
                $endPage = min($totalPages, $page + 2);
                for ($i = $startPage; $i <= $endPage; $i++):
                ?>
                <a href="?page=<?php echo $i; ?>&<?php echo $queryParams; ?>" 
                   class="px-3 py-1 border rounded <?php echo $i === $page ? 'bg-blue-600 text-white' : 'hover:bg-gray-50'; ?>">
                    <?php echo $i; ?>
                </a>
                <?php endfor; ?>
                
                <?php if ($page < $totalPages): ?>
                <a href="?page=<?php echo $page + 1; ?>&<?php echo $queryParams; ?>" 
                   class="px-3 py-1 border rounded hover:bg-gray-50">Next</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Details Modal -->
<div id="detailsModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl max-h-[90vh] overflow-y-auto">
        <div class="p-6 border-b flex items-center justify-between">
            <h3 class="text-lg font-semibold">Email Details</h3>
            <button data-cardify-action="call" data-fn="closeModal" class="text-gray-400 hover:text-gray-600">
                <i class="fa-solid fa-times"></i>
            </button>
        </div>
        <div class="p-6 space-y-4">
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="text-xs text-gray-500 uppercase">Recipient</label>
                    <div id="detailRecipient" class="font-medium"></div>
                </div>
                <div>
                    <label class="text-xs text-gray-500 uppercase">Status</label>
                    <div id="detailStatus"></div>
                </div>
            </div>
            <div>
                <label class="text-xs text-gray-500 uppercase">Subject</label>
                <div id="detailSubject" class="font-medium"></div>
            </div>
            <div>
                <label class="text-xs text-gray-500 uppercase">Template</label>
                <div id="detailTemplate"></div>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="text-xs text-gray-500 uppercase">Sent At</label>
                    <div id="detailDate"></div>
                </div>
                <div>
                    <label class="text-xs text-gray-500 uppercase">Company</label>
                    <div id="detailCompany"></div>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="text-xs text-gray-500 uppercase">Sent By</label>
                    <div id="detailActor"></div>
                </div>
                <div>
                    <label class="text-xs text-gray-500 uppercase">IP Address</label>
                    <div id="detailIP"></div>
                </div>
            </div>
            <div id="errorSection" class="hidden">
                <label class="text-xs text-gray-500 uppercase">Error Message</label>
                <div id="detailError" class="bg-red-50 text-red-700 p-3 rounded text-sm"></div>
            </div>
            <div>
                <label class="text-xs text-gray-500 uppercase">Body Preview</label>
                <div id="detailBody" class="bg-gray-50 p-3 rounded text-sm max-h-40 overflow-y-auto whitespace-pre-wrap"></div>
            </div>
        </div>
        <div class="p-6 border-t bg-gray-50">
            <button data-cardify-action="call" data-fn="closeModal" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300">Close</button>
        </div>
    </div>
</div>

<script<?= cspNonceAttr() ?>>
function showDetails(log) {
    document.getElementById('detailRecipient').textContent = log.recipient_email + (log.recipient_name ? ' (' + log.recipient_name + ')' : '');
    
    const statusColors = {
        'sent': 'bg-green-100 text-green-700',
        'failed': 'bg-red-100 text-red-700',
        'pending': 'bg-amber-100 text-amber-700',
        'bounced': 'bg-red-100 text-red-700'
    };
    document.getElementById('detailStatus').innerHTML = '<span class="px-2 py-1 rounded-full text-xs font-semibold ' + (statusColors[log.status] || 'bg-gray-100') + '">' + (log.status || 'Unknown').charAt(0).toUpperCase() + (log.status || 'unknown').slice(1) + '</span>';
    
    document.getElementById('detailSubject').textContent = log.subject;
    document.getElementById('detailTemplate').textContent = log.template ? log.template.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase()) : 'Custom Email';
    document.getElementById('detailDate').textContent = new Date(log.created_at).toLocaleString();
    document.getElementById('detailCompany').textContent = log.company_name || 'N/A';
    document.getElementById('detailActor').textContent = log.actor_email ? log.actor_email + ' (' + (log.actor_role || 'unknown') + ')' : 'System';
    document.getElementById('detailIP').textContent = log.ip_address || 'N/A';
    document.getElementById('detailBody').textContent = log.body_preview || 'No preview available';
    
    if (log.error_message) {
        document.getElementById('errorSection').classList.remove('hidden');
        document.getElementById('detailError').textContent = log.error_message;
    } else {
        document.getElementById('errorSection').classList.add('hidden');
    }
    
    document.getElementById('detailsModal').classList.remove('hidden');
}

function closeModal() {
    document.getElementById('detailsModal').classList.add('hidden');
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeModal();
});
</script>

<?php endif; ?>

<?php adminFooter(); ?>
