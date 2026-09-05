<?php
/**
 * Card Requests Management - Admin Dashboard
 * View, approve, or reject employee card requests
 */
require_once __DIR__ . '/../config.php';
requireAdmin();
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/admin-layout.php';
require_once INCLUDES_DIR . '/Mailer.php';
require_once INCLUDES_DIR . '/RequestApproval.php';

$db = Database::getInstance();
$companyId = getCurrentCompanyId();
$company = findCompanyById($companyId);
$companyName = $company['name_en'] ?? $company['name'] ?? 'Company';
$companySlug = $company['slug'] ?? '';

// Get departments for display
$departments = [];
if ($companyId) {
    $deptRows = $db->fetchAll(
        "SELECT id, name FROM departments WHERE company_id = :id ORDER BY name",
        ['id' => $companyId]
    );
    foreach ($deptRows as $d) {
        $departments[$d['id']] = $d['name'];
    }
}

$message = null;
$messageType = 'success';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { die('Invalid request'); }
    $action = $_POST['action'] ?? '';
    $requestId = $_POST['request_id'] ?? '';
    
    if ($requestId && $companyId) {
        // Get the request
        $request = $db->fetchOne(
            "SELECT * FROM card_requests WHERE id = :id AND company_id = :cid",
            ['id' => $requestId, 'cid' => $companyId]
        );
        
        if ($request) {
            switch ($action) {
                case 'approve':
                    $approval = approveRequestChain($request, $company, $companyId, $_SESSION['user_id'] ?? null, false);

                    if ($approval['success'] && $approval['employee_id']) {
                        $message = "Request approved! {$approval['status_msg']}. Redirecting to generate cards...";
                        $messageType = 'success';

                        // Redirect to batch generate with this employee pre-selected
                        $redirectBase = defined('COMPANY_ADMIN_BASE') ? COMPANY_ADMIN_BASE : getBasePath() . 'admin/';
                        header('Location: ' . $redirectBase . 'batch_generate?employee_id=' . urlencode($approval['employee_id']) . '&auto_generate=1&send_email=1');
                        exit;
                    } else {
                        $message = 'Failed to process employee: ' . ($approval['error'] ?? 'Unknown error');
                        $messageType = 'error';
                    }
                    break;
                    
                case 'reject':
                    $notes = trim($_POST['admin_notes'] ?? '');
                    
                    try {
                        $db->query(
                            "UPDATE card_requests SET status = 'rejected', admin_notes = :notes, reviewed_at = NOW(), reviewed_by = :uid WHERE id = :id",
                            ['id' => $requestId, 'notes' => $notes, 'uid' => $_SESSION['user_id'] ?? null]
                        );
                        
                        // Send rejection email
                        $employeeName = $request['name_en'] ?: $request['name_ar'];
                        Mailer::sendTemplate($request['email'], 'request_rejected', [
                            'employee_name' => $employeeName,
                            'company_name' => $companyName,
                            'rejection_reason' => $notes ?: 'No specific reason provided. Please contact your administrator for more information.'
                        ]);
                        
                        $message = 'Request rejected. Employee has been notified.';
                        $messageType = 'success';
                    } catch (Exception $e) {
                        error_log("Error rejecting request: " . $e->getMessage());
                        $message = 'Error rejecting request.';
                        $messageType = 'error';
                    }
                    break;
                    
                case 'delete':
                    try {
                        // Delete photo if exists
                        if (!empty($request['photo']) && file_exists(BASE_DIR . '/' . $request['photo'])) {
                            @unlink(BASE_DIR . '/' . $request['photo']);
                        }
                        
                        $db->query("DELETE FROM card_requests WHERE id = :id", ['id' => $requestId]);
                        $message = 'Request deleted.';
                    } catch (Exception $e) {
                        error_log("Error deleting request: " . $e->getMessage());
                        $message = 'Error deleting request.';
                        $messageType = 'error';
                    }
                    break;
            }
        }
    }
}

// Get filter
$statusFilter = $_GET['status'] ?? 'pending';
$validStatuses = ['all', 'pending', 'approved', 'rejected'];
if (!in_array($statusFilter, $validStatuses)) {
    $statusFilter = 'pending';
}

// Get requests
$requests = [];
if ($companyId) {
    $sql = "SELECT * FROM card_requests WHERE company_id = :cid";
    $params = ['cid' => $companyId];
    
    if ($statusFilter !== 'all') {
        $sql .= " AND status = :status";
        $params['status'] = $statusFilter;
    }
    
    $sql .= " ORDER BY submitted_at DESC";
    $requests = $db->fetchAll($sql, $params);
}

// Count by status
$counts = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
if ($companyId) {
    $countRows = $db->fetchAll(
        "SELECT status, COUNT(*) as cnt FROM card_requests WHERE company_id = :cid GROUP BY status",
        ['cid' => $companyId]
    );
    foreach ($countRows as $row) {
        $counts[$row['status']] = (int)$row['cnt'];
    }
}

$pageTitle = 'Card Requests';
$currentPage = 'requests';

// Get admin base path for navigation
$adminBase = defined('COMPANY_ADMIN_BASE') ? COMPANY_ADMIN_BASE : getBasePath() . 'admin/';
?>
<?php adminHeader($pageTitle, $currentPage); ?>

<div class="p-4 sm:p-6 lg:p-8">
    <!-- Header -->
    <div class="mb-6">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">Card Requests</h1>
                <p class="text-sm text-gray-500 mt-1">Review and approve employee business card requests</p>
            </div>
            <div class="flex items-center gap-2">
                <a href="<?php echo $adminBase; ?>employees" class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50">
                    <i class="fa-solid fa-users mr-2"></i>View Employees
                </a>
            </div>
        </div>
    </div>
    
    <?php if ($message): ?>
    <div class="mb-6 p-4 rounded-lg <?php echo $messageType === 'error' ? 'bg-red-50 border border-red-200 text-red-700' : 'bg-green-50 border border-green-200 text-green-700'; ?>">
        <div class="flex items-center">
            <i class="fa-solid <?php echo $messageType === 'error' ? 'fa-circle-exclamation' : 'fa-circle-check'; ?> mr-2"></i>
            <?php echo htmlspecialchars($message); ?>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Portal Link -->
    <?php if ($companySlug): ?>
    <div class="mb-6 bg-blue-50 border border-blue-200 rounded-lg p-4">
        <div class="flex items-start">
            <i class="fa-solid fa-link text-blue-600 mt-0.5 mr-3"></i>
            <div class="flex-1">
                <h3 class="font-semibold text-blue-900">Employee Portal Link</h3>
                <p class="text-sm text-blue-700 mt-1">Share this link with employees to let them submit their card requests:</p>
                <div class="mt-2 flex items-center gap-2">
                    <code class="bg-blue-100 px-3 py-1.5 rounded text-sm text-blue-800 font-mono">
                        <?php echo getTenantUrl($companySlug, '/portal'); ?>
                    </code>
                    <button data-cardify-action="call" data-fn="copyPortalLink" class="px-3 py-1.5 bg-blue-600 text-white text-sm rounded hover:bg-blue-700">
                        <i class="fa-solid fa-copy mr-1"></i>Copy
                    </button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Stats -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
        <a href="?status=pending" class="block p-4 rounded-lg border-2 <?php echo $statusFilter === 'pending' ? 'border-yellow-500 bg-yellow-50' : 'border-gray-200 bg-white hover:border-yellow-300'; ?>">
            <div class="flex items-center">
                <div class="p-2 rounded-lg bg-yellow-100 text-yellow-600">
                    <i class="fa-solid fa-clock"></i>
                </div>
                <div class="ml-3">
                    <p class="text-2xl font-bold text-gray-900"><?php echo $counts['pending']; ?></p>
                    <p class="text-sm text-gray-500">Pending</p>
                </div>
            </div>
        </a>
        <a href="?status=approved" class="block p-4 rounded-lg border-2 <?php echo $statusFilter === 'approved' ? 'border-green-500 bg-green-50' : 'border-gray-200 bg-white hover:border-green-300'; ?>">
            <div class="flex items-center">
                <div class="p-2 rounded-lg bg-green-100 text-green-600">
                    <i class="fa-solid fa-check"></i>
                </div>
                <div class="ml-3">
                    <p class="text-2xl font-bold text-gray-900"><?php echo $counts['approved']; ?></p>
                    <p class="text-sm text-gray-500">Approved</p>
                </div>
            </div>
        </a>
        <a href="?status=rejected" class="block p-4 rounded-lg border-2 <?php echo $statusFilter === 'rejected' ? 'border-red-500 bg-red-50' : 'border-gray-200 bg-white hover:border-red-300'; ?>">
            <div class="flex items-center">
                <div class="p-2 rounded-lg bg-red-100 text-red-600">
                    <i class="fa-solid fa-times"></i>
                </div>
                <div class="ml-3">
                    <p class="text-2xl font-bold text-gray-900"><?php echo $counts['rejected']; ?></p>
                    <p class="text-sm text-gray-500">Rejected</p>
                </div>
            </div>
        </a>
    </div>
    
    <!-- Requests List -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
        <div class="px-4 py-3 border-b border-gray-200 flex items-center justify-between">
            <h2 class="font-semibold text-gray-900">
                <?php echo ucfirst($statusFilter); ?> Requests
                <span class="text-gray-400 font-normal">(<?php echo count($requests); ?>)</span>
            </h2>
            <a href="?status=all" class="text-sm text-blue-600 hover:text-blue-700">View All</a>
        </div>
        
        <?php if (empty($requests)): ?>
        <div class="p-8 text-center">
            <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
                <i class="fa-solid fa-inbox text-gray-400 text-2xl"></i>
            </div>
            <p class="text-gray-500"><?= htmlspecialchars($statusFilter !== 'all' ? str_replace(':filter', $statusFilter, t('emptystates.no_requests_h')) : t('emptystates.no_requests_all_h')) ?></p>
            <?php if ($companySlug): ?>
            <p class="text-sm text-gray-400 mt-2">Share the portal link with employees to receive requests.</p>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="divide-y divide-gray-200">
            <?php foreach ($requests as $req): ?>
            <?php
                $employeeName = $req['name_en'] ?: $req['name_ar'] ?: 'Unknown';
                $position = $req['position_en'] ?: $req['position_ar'] ?: '';
                $deptName = !empty($req['department_id']) && isset($departments[$req['department_id']]) ? $departments[$req['department_id']] : '';
                $submittedDate = date('M j, Y g:i A', dbTs($req['submitted_at']));
            ?>
            <div class="p-4 hover:bg-gray-50" x-data="{ expanded: false, showRejectModal: false }">
                <div class="flex items-start gap-4">
                    <!-- Photo -->
                    <div class="flex-shrink-0">
                        <?php if (!empty($req['photo'])): ?>
                        <img src="<?php echo getBasePath() . $req['photo']; ?>" alt="" class="w-14 h-14 rounded-full object-cover border-2 border-gray-200">
                        <?php else: ?>
                        <div class="w-14 h-14 rounded-full bg-gray-200 flex items-center justify-center text-gray-500 text-xl font-semibold">
                            <?php echo strtoupper(substr($employeeName, 0, 1)); ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Info -->
                    <div class="flex-1 min-w-0">
                        <div class="flex items-start justify-between gap-2">
                            <div>
                                <h3 class="font-semibold text-gray-900"><?php echo htmlspecialchars($employeeName); ?></h3>
                                <p class="text-sm text-gray-500"><?php echo htmlspecialchars($req['email']); ?></p>
                            </div>
                            <div class="flex items-center gap-2 flex-wrap">
                                <?php 
                                $requestType = $req['request_type'] ?? 'new';
                                if ($req['status'] === 'pending'): 
                                    if ($requestType === 'reprint'): ?>
                                <span class="px-2 py-1 text-xs font-medium bg-purple-100 text-purple-700 rounded-full">
                                    <i class="fa-solid fa-print mr-1"></i>Reprint
                                </span>
                                    <?php elseif ($requestType === 'update' || !empty($req['employee_id'])): ?>
                                <span class="px-2 py-1 text-xs font-medium bg-blue-100 text-blue-700 rounded-full">
                                    <i class="fa-solid fa-pen mr-1"></i>Update
                                </span>
                                    <?php else: ?>
                                <span class="px-2 py-1 text-xs font-medium bg-green-100 text-green-700 rounded-full">
                                    <i class="fa-solid fa-user-plus mr-1"></i>New
                                </span>
                                    <?php endif; ?>
                                <?php endif; ?>
                                
                                <?php if (($req['quantity_requested'] ?? 1) > 1): ?>
                                <span class="px-2 py-1 text-xs font-medium bg-gray-100 text-gray-600 rounded-full">
                                    <i class="fa-solid fa-layer-group mr-1"></i><?php echo (int)$req['quantity_requested']; ?> pcs
                                </span>
                                <?php endif; ?>
                                
                                <?php if ($req['status'] === 'pending'): ?>
                                <span class="px-2 py-1 text-xs font-medium bg-yellow-100 text-yellow-700 rounded-full">Pending</span>
                                <?php elseif ($req['status'] === 'approved'): ?>
                                <span class="px-2 py-1 text-xs font-medium bg-green-100 text-green-700 rounded-full">Approved</span>
                                <?php else: ?>
                                <span class="px-2 py-1 text-xs font-medium bg-red-100 text-red-700 rounded-full">Rejected</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="mt-1 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-gray-600">
                            <?php if ($position): ?>
                            <span><i class="fa-solid fa-briefcase mr-1 text-gray-400"></i><?php echo htmlspecialchars($position); ?></span>
                            <?php endif; ?>
                            <?php if ($deptName): ?>
                            <span><i class="fa-solid fa-building mr-1 text-gray-400"></i><?php echo htmlspecialchars($deptName); ?></span>
                            <?php endif; ?>
                            <span><i class="fa-solid fa-calendar mr-1 text-gray-400"></i><?php echo $submittedDate; ?></span>
                        </div>
                        
                        <!-- Expanded Details -->
                        <div x-show="expanded" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0" class="mt-4 pt-4 border-t border-gray-200">
                            <?php if (!empty($req['request_notes'])): ?>
                            <div class="mb-4 p-3 bg-blue-50 rounded-lg">
                                <p class="text-xs font-medium text-blue-700 mb-1">
                                    <i class="fa-solid fa-comment mr-1"></i>Employee Notes:
                                </p>
                                <p class="text-sm text-blue-800"><?php echo htmlspecialchars($req['request_notes']); ?></p>
                            </div>
                            <?php endif; ?>
                            
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                                <?php if ($req['name_ar']): ?>
                                <div>
                                    <span class="text-gray-500">Name (Arabic):</span>
                                    <span class="ml-2 font-medium" dir="rtl"><?php echo htmlspecialchars($req['name_ar']); ?></span>
                                </div>
                                <?php endif; ?>
                                <?php if ($req['position_ar']): ?>
                                <div>
                                    <span class="text-gray-500">Position (Arabic):</span>
                                    <span class="ml-2 font-medium" dir="rtl"><?php echo htmlspecialchars($req['position_ar']); ?></span>
                                </div>
                                <?php endif; ?>
                                <?php if ($req['phone']): ?>
                                <div>
                                    <span class="text-gray-500">Phone:</span>
                                    <span class="ml-2"><?php echo htmlspecialchars($req['phone']); ?></span>
                                </div>
                                <?php endif; ?>
                                <?php if ($req['mobile']): ?>
                                <div>
                                    <span class="text-gray-500">Mobile:</span>
                                    <span class="ml-2"><?php echo htmlspecialchars($req['mobile']); ?></span>
                                </div>
                                <?php endif; ?>
                                <?php if ($req['address_en']): ?>
                                <div class="sm:col-span-2">
                                    <span class="text-gray-500">Address:</span>
                                    <span class="ml-2"><?php echo htmlspecialchars($req['address_en']); ?></span>
                                </div>
                                <?php endif; ?>
                                <?php if ($req['admin_notes']): ?>
                                <div class="sm:col-span-2">
                                    <span class="text-gray-500">Admin Notes:</span>
                                    <span class="ml-2 text-red-600"><?php echo htmlspecialchars($req['admin_notes']); ?></span>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Actions -->
                        <div class="mt-3 flex flex-wrap items-center gap-2">
                            <button @click="expanded = !expanded" class="px-3 py-1.5 text-xs font-medium text-gray-600 bg-gray-100 rounded hover:bg-gray-200">
                                <i class="fa-solid" :class="expanded ? 'fa-chevron-up' : 'fa-chevron-down'"></i>
                                <span x-text="expanded ? 'Less' : 'More'"></span>
                            </button>
                            
                            <!-- Preview Card Button -->
                            <button type="button" data-cardify-action="call" data-fn="previewCard" data-args="<?= htmlspecialchars(json_encode([[
                                'name_en' => $req['name_en'],
                                'name_ar' => $req['name_ar'],
                                'position_en' => $req['position_en'],
                                'position_ar' => $req['position_ar'],
                                'email' => $req['email'],
                                'phone' => $req['phone'],
                                'phone_ar' => $req['phone_ar'] ?? '',
                                'mobile' => $req['mobile'],
                                'mobile_ar' => $req['mobile_ar'] ?? '',
                                'address_en' => $req['address_en'] ?? '',
                                'address_ar' => $req['address_ar'] ?? '',
                                'photo' => $req['photo'] ?? '',
                                'preview_front' => $req['preview_front'] ?? '',
                                'preview_back' => $req['preview_back'] ?? ''
                            ]]), ENT_QUOTES) ?>" class="px-3 py-1.5 text-xs font-medium text-purple-700 bg-purple-100 rounded hover:bg-purple-200">
                                <i class="fa-solid fa-eye mr-1"></i>Preview Card
                            </button>
                            
                            <?php if ($req['status'] === 'pending'): ?>
                            <form method="POST" class="inline">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="approve">
                                <input type="hidden" name="request_id" value="<?php echo $req['id']; ?>">
                                <button type="submit" class="px-3 py-1.5 text-xs font-medium text-white bg-green-600 rounded hover:bg-green-700">
                                    <i class="fa-solid fa-check mr-1"></i>Approve & Generate
                                </button>
                            </form>
                            <button @click="showRejectModal = true" class="px-3 py-1.5 text-xs font-medium text-white bg-red-600 rounded hover:bg-red-700">
                                <i class="fa-solid fa-times mr-1"></i>Reject
                            </button>
                            <?php endif; ?>
                            
                            <?php if ($req['status'] === 'approved' && !empty($req['employee_id'])): ?>
                            <a href="<?php echo $adminBase; ?>batch_generate?employee_id=<?php echo urlencode($req['employee_id']); ?>" class="px-3 py-1.5 text-xs font-medium text-white bg-blue-600 rounded hover:bg-blue-700">
                                <i class="fa-solid fa-id-card mr-1"></i>Generate Card
                            </a>
                            <?php endif; ?>
                            
                            <form method="POST" class="inline" data-cardify-confirm="Delete this request?">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="request_id" value="<?php echo $req['id']; ?>">
                                <button type="submit" class="px-3 py-1.5 text-xs font-medium text-gray-600 bg-gray-100 rounded hover:bg-gray-200">
                                    <i class="fa-solid fa-trash"></i>
                                </button>
                            </form>
                        </div>
                        
                        <!-- Reject Modal -->
                        <div x-show="showRejectModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50" @click.self="showRejectModal = false">
                            <div class="bg-white rounded-xl shadow-xl w-full max-w-md p-6" @click.stop>
                                <h3 class="text-lg font-semibold text-gray-900 mb-4">Reject Request</h3>
                                <form method="POST">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="action" value="reject">
                                    <input type="hidden" name="request_id" value="<?php echo $req['id']; ?>">
                                    <div class="mb-4">
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Reason (optional)</label>
                                        <textarea name="admin_notes" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="Provide a reason for rejection..."></textarea>
                                        <p class="text-xs text-gray-500 mt-1">This will be included in the email to the employee.</p>
                                    </div>
                                    <div class="flex justify-end gap-2">
                                        <button type="button" @click="showRejectModal = false" class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200">Cancel</button>
                                        <button type="submit" class="px-4 py-2 text-sm font-medium text-white bg-red-600 rounded-lg hover:bg-red-700">Reject Request</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Card Preview Modal -->
<div id="previewModal" class="fixed inset-0 z-50 hidden items-center justify-center p-4 bg-black/60" data-cardify-action="call" data-fn="closePreviewModal" data-arg="event">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-4xl max-h-[90vh] overflow-hidden" data-cardify-action="stop">
        <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200 bg-gradient-to-r from-purple-600 to-blue-600">
            <h3 class="text-lg font-semibold text-white">
                <i class="fa-solid fa-id-card mr-2"></i>Request Details
            </h3>
            <button data-cardify-action="call" data-fn="closePreviewModal" class="text-white/80 hover:text-white">
                <i class="fa-solid fa-times text-xl"></i>
            </button>
        </div>
        <div class="p-6 overflow-y-auto" style="max-height: calc(90vh - 140px);">
            <!-- Card Templates -->
            <div class="grid md:grid-cols-2 gap-6 mb-6">
                <!-- Front Card -->
                <div>
                    <h4 class="text-sm font-semibold text-gray-700 mb-3 flex items-center">
                        <i class="fa-solid fa-image mr-2 text-blue-500"></i>Front Template
                    </h4>
                    <div id="previewFront" class="bg-gray-100 rounded-lg flex items-center justify-center min-h-[180px] p-4">
                        <div class="text-center text-gray-400">
                            <i class="fa-solid fa-spinner fa-spin text-2xl mb-2"></i>
                            <p>Loading...</p>
                        </div>
                    </div>
                </div>
                <!-- Back Card -->
                <div>
                    <h4 class="text-sm font-semibold text-gray-700 mb-3 flex items-center">
                        <i class="fa-solid fa-image mr-2 text-green-500"></i>Back Template
                    </h4>
                    <div id="previewBack" class="bg-gray-100 rounded-lg flex items-center justify-center min-h-[180px] p-4">
                        <div class="text-center text-gray-400">
                            <i class="fa-solid fa-spinner fa-spin text-2xl mb-2"></i>
                            <p>Loading...</p>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Employee Data -->
            <div class="bg-gray-50 rounded-lg p-4 border border-gray-200">
                <h4 class="text-sm font-semibold text-gray-700 mb-3 flex items-center">
                    <i class="fa-solid fa-user mr-2 text-purple-500"></i>Employee Information
                </h4>
                <div id="previewEmployeeData" class="grid grid-cols-2 gap-3 text-sm">
                    <!-- Filled by JavaScript -->
                </div>
            </div>
            
            <div id="previewInfoMessage" class="text-xs text-center mt-4 rounded-lg p-3">
                <!-- Updated dynamically by JavaScript -->
            </div>
        </div>
        <div class="px-6 py-4 border-t border-gray-200 bg-gray-50 flex justify-end">
            <button data-cardify-action="call" data-fn="closePreviewModal" class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50">
                Close
            </button>
        </div>
    </div>
</div>

<script<?= cspNonceAttr() ?>>
function copyPortalLink() {
    const link = '<?php echo getTenantUrl($companySlug, '/portal'); ?>';
    navigator.clipboard.writeText(link).then(() => {
        alert('Portal link copied to clipboard!');
    });
}

// Get template data for preview
const companyId = '<?php echo $companyId; ?>';
const basePath = '<?php echo getBasePath(); ?>';
let frontTemplate = null;
let backTemplate = null;

// Load templates on page load
document.addEventListener('DOMContentLoaded', async function() {
    try {
        // Fetch templates - use admin base for company admin context
        const adminBase = '<?php echo $adminBase; ?>';
        const response = await fetch(adminBase + 'get_templates');
        if (response.ok) {
            const data = await response.json();
            if (data.templates) {
                for (const tpl of data.templates) {
                    if (tpl.id === data.activeFrontId) frontTemplate = tpl;
                    if (tpl.id === data.activeBackId) backTemplate = tpl;
                }
            }
        }
    } catch (e) {
        console.log('Could not load templates for preview:', e);
    }
});

function previewCard(employeeData) {
    const modal = document.getElementById('previewModal');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    
    const frontDiv = document.getElementById('previewFront');
    const backDiv = document.getElementById('previewBack');
    const dataDiv = document.getElementById('previewEmployeeData');
    
    // Show loading state
    frontDiv.innerHTML = '<div class="text-center text-gray-400 py-8"><i class="fa-solid fa-spinner fa-spin text-2xl mb-2"></i><p>Loading...</p></div>';
    backDiv.innerHTML = '<div class="text-center text-gray-400 py-8"><i class="fa-solid fa-spinner fa-spin text-2xl mb-2"></i><p>Loading...</p></div>';
    
    // Generate preview cards
    setTimeout(() => {
        generatePreview('front', frontDiv, employeeData);
        generatePreview('back', backDiv, employeeData);
        showEmployeeData(dataDiv, employeeData);
    }, 100);
}

function showEmployeeData(container, employee) {
    const fields = [
        { label: 'Name (English)', value: employee.name_en },
        { label: 'Name (Arabic)', value: employee.name_ar, dir: 'rtl' },
        { label: 'Position (English)', value: employee.position_en },
        { label: 'Position (Arabic)', value: employee.position_ar, dir: 'rtl' },
        { label: 'Email', value: employee.email },
        { label: 'Phone', value: employee.phone },
        { label: 'Mobile', value: employee.mobile },
        { label: 'Address', value: employee.address_en },
    ].filter(f => f.value);
    
    container.innerHTML = fields.map(f => `
        <div class="bg-white rounded px-3 py-2 border border-gray-100">
            <span class="text-gray-500 text-xs block">${f.label}</span>
            <span class="font-medium text-gray-900" ${f.dir ? `dir="${f.dir}"` : ''}>${escapeHtml(f.value)}</span>
        </div>
    `).join('');
    
    // Update info message based on preview availability
    const infoDiv = document.getElementById('previewInfoMessage');
    if (infoDiv) {
        const hasPreview = employee.preview_front || employee.preview_back;
        if (hasPreview) {
            infoDiv.className = 'text-xs text-center mt-4 rounded-lg p-3 bg-green-50 text-green-700';
            infoDiv.innerHTML = '<i class="fa-solid fa-check-circle mr-1"></i>This is the actual generated card preview. Approve and generate the final high-resolution card.';
        } else {
            infoDiv.className = 'text-xs text-center mt-4 rounded-lg p-3 bg-blue-50 text-gray-500';
            infoDiv.innerHTML = '<i class="fa-solid fa-info-circle mr-1 text-blue-500"></i>This shows the card template and employee data. Preview will be generated on submission.';
        }
    }
}

function generatePreview(side, container, employee) {
    // Check if we have a generated preview image
    const previewUrl = side === 'front' ? employee.preview_front : employee.preview_back;
    
    if (previewUrl) {
        // Show the actual generated card preview
        let imgUrl = previewUrl;
        if (!imgUrl.startsWith('http')) {
            imgUrl = basePath + (imgUrl.startsWith('/') ? imgUrl.substring(1) : imgUrl);
        }
        
        const cardHtml = `
            <div class="flex flex-col items-center">
                <div class="relative bg-white rounded-lg shadow-lg overflow-hidden" style="aspect-ratio: 3.5/2; max-width: 400px; width: 100%;">
                    <img src="${imgUrl}" class="w-full h-full object-contain" data-cardify-error-html="<div class='text-center text-gray-400 p-8'><i class='fa-solid fa-image-slash text-2xl mb-2'></i><p class='text-sm'>Preview not available</p></div>">
                </div>
                <p class="text-xs text-green-600 text-center mt-2 flex items-center gap-1">
                    <i class="fa-solid fa-check-circle"></i>
                    ${side === 'front' ? 'Front' : 'Back'} Card Preview
                </p>
            </div>
        `;
        
        container.innerHTML = cardHtml;
        return;
    }
    
    // Fall back to template-only preview
    const template = side === 'front' ? frontTemplate : backTemplate;
    
    if (!template || !template.backgroundImage) {
        container.innerHTML = `
            <div class="text-center text-gray-400 py-8">
                <i class="fa-solid fa-image-slash text-3xl mb-2"></i>
                <p class="text-sm">No ${side} preview available</p>
                <p class="text-xs mt-1">Preview will be generated when request is submitted</p>
            </div>`;
        return;
    }
    
    // Create card preview HTML - ensure proper URL format
    let bgUrl = template.backgroundImage;
    if (!bgUrl.startsWith('http')) {
        bgUrl = basePath + (bgUrl.startsWith('/') ? bgUrl.substring(1) : bgUrl);
    }
    
    // Just show the template design - actual field positioning requires generating the card
    const cardHtml = `
        <div class="flex flex-col items-center">
            <div class="relative bg-white rounded-lg shadow-lg overflow-hidden" style="aspect-ratio: 3.5/2; max-width: 350px; width: 100%;">
                <img src="${bgUrl}" class="w-full h-full object-cover" data-cardify-error-bg="#f3f4f6">
            </div>
            <p class="text-xs text-gray-400 text-center mt-2">${side === 'front' ? 'Front' : 'Back'} Template Only</p>
        </div>
    `;
    
    container.innerHTML = cardHtml;
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function closePreviewModal(event) {
    if (event && event.target !== event.currentTarget) return;
    const modal = document.getElementById('previewModal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}

// Close on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closePreviewModal();
});
</script>

<style>
.text-shadow { text-shadow: 0 1px 3px rgba(0,0,0,0.3); }
</style>

<?php adminFooter(); ?>
