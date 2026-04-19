<?php
/**
 * Customer Dashboard - Cardify
 * Order history, card designs, account settings
 */
require_once __DIR__ . '/../config.php';
requireAdmin();
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/Currency.php';
require_once INCLUDES_DIR . '/admin-layout.php';

$db = Database::getInstance();
$companyId = getCurrentCompanyId();

if (!$companyId) {
    header('Location: ' . getBasePath() . 'login.php');
    exit;
}

$company = $db->fetchOne("SELECT * FROM companies WHERE id = :id", ['id' => $companyId]);
$currency = $company['currency'] ?? 'OMR';

$message = null;
$messageType = 'success';

// Handle account settings update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = 'Invalid request token. Please refresh and try again.';
        $messageType = 'error';
    } else {
        $action = $_POST['action'];

        if ($action === 'update_account') {
            $updateData = [
                'name'           => trim($_POST['name'] ?? ''),
                'billing_email'  => trim($_POST['billing_email'] ?? ''),
                'updated_at'     => date('Y-m-d H:i:s'),
            ];
            // Normalize phone to E.164 when provided; allow empty to clear it (opt-out).
            // intl-tel-input posts the canonical international number into
            // `phone_e164` — prefer that over the visible field, which only
            // contains the national-format number when separateDialCode is on.
            if (isset($_POST['phone']) || isset($_POST['phone_e164'])) {
                $rawPhone = trim($_POST['phone_e164'] ?? $_POST['phone'] ?? '');
                if ($rawPhone === '') {
                    $updateData['phone'] = '';
                } else {
                    require_once INCLUDES_DIR . '/WhatsApp.php';
                    $digits = WhatsApp::normalizePhone($rawPhone);
                    $updateData['phone'] = strlen($digits) >= 8 ? '+' . $digits : $rawPhone;
                }
            }
            // Optional fields that may or may not exist
            if (isset($_POST['address'])) $updateData['address'] = trim($_POST['address']);
            if (isset($_POST['website'])) $updateData['website'] = trim($_POST['website']);

            try {
                $db->update('companies', $updateData, 'id = :id', ['id' => $companyId]);
                $company = $db->fetchOne("SELECT * FROM companies WHERE id = :id", ['id' => $companyId]);
                $message = 'Account settings saved.';
            } catch (Exception $e) {
                $message = 'Failed to save: ' . $e->getMessage();
                $messageType = 'error';
            }
        }
    }
}

// ─── Stats ────────────────────────────────────────────────────────────────────
$orderCount = 0;
$totalSpend  = 0;
try {
    $r = $db->fetchOne(
        "SELECT COUNT(*) as cnt, COALESCE(SUM(total), 0) as tot FROM print_orders WHERE company_id = :id",
        ['id' => $companyId]
    );
    $orderCount = $r['cnt'] ?? 0;
    $totalSpend  = $r['tot'] ?? 0;
} catch (Exception $e) {}

$employeeCount = 0;
try {
    $r = $db->fetchOne("SELECT COUNT(*) as cnt FROM employees WHERE company_id = :id", ['id' => $companyId]);
    $employeeCount = $r['cnt'] ?? 0;
} catch (Exception $e) {}

$designCount = 0;
try {
    $r = $db->fetchOne("SELECT COUNT(*) as cnt FROM generated_cards WHERE company_id = :id", ['id' => $companyId]);
    $designCount = $r['cnt'] ?? 0;
} catch (Exception $e) {}

// ─── Order History ────────────────────────────────────────────────────────────
$orders = [];
try {
    $orders = $db->fetchAll(
        "SELECT po.*,
                COALESCE(e.name_en, e.name_ar, e.email, 'Unknown') as employee_name,
                COALESCE(e.position_en, e.position_ar, '') as employee_position
         FROM print_orders po
         LEFT JOIN employees e ON po.employee_id = e.id
         WHERE po.company_id = :id
         ORDER BY po.created_at DESC",
        ['id' => $companyId]
    );
} catch (Exception $e) {}

// ─── Card Designs ─────────────────────────────────────────────────────────────
$generatedCards = [];
try {
    $generatedCards = $db->fetchAll(
        "SELECT gc.*,
                COALESCE(e.name_en, e.name_ar, e.email, 'Unknown') as employee_name,
                tf.name as front_template_name,
                tb.name as back_template_name
         FROM generated_cards gc
         LEFT JOIN employees e ON gc.employee_id = e.id
         LEFT JOIN templates tf ON gc.front_template_id = tf.id
         LEFT JOIN templates tb ON gc.back_template_id = tb.id
         WHERE gc.company_id = :id
         ORDER BY gc.generated_at DESC
         LIMIT 50",
        ['id' => $companyId]
    );
} catch (Exception $e) {}

// ─── Saved Templates ──────────────────────────────────────────────────────────
$templates = [];
try {
    $templates = $db->fetchAll(
        "SELECT * FROM templates WHERE company_id = :id ORDER BY name",
        ['id' => $companyId]
    );
} catch (Exception $e) {}

// ─── Company Theme ────────────────────────────────────────────────────────────
$theme = null;
try {
    $theme = $db->fetchOne("SELECT * FROM company_themes WHERE company_id = :id", ['id' => $companyId]);
} catch (Exception $e) {}

// Active tab
$tab = $_GET['tab'] ?? 'overview';

// Base path helper
$basePath = getAdminBasePath();
$isCompanyAdmin = defined('COMPANY_ADMIN_BASE') || !empty($_SESSION['company_slug']);
$ext = $isCompanyAdmin ? '' : '.php';

adminHeader('My Dashboard', 'customer-dashboard');
?>

<?php if ($message): ?>
<div class="mb-4 p-4 rounded-xl flex items-center gap-3 <?= $messageType === 'success' ? 'bg-green-50 border border-green-200 text-green-700' : 'bg-red-50 border border-red-200 text-red-700'; ?>">
    <i class="<?= $messageType === 'success' ? 'fa-solid fa-circle-check' : 'fa-solid fa-circle-exclamation'; ?>"></i>
    <?= sanitize($message); ?>
</div>
<?php endif; ?>

<!-- Page header -->
<div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">My Dashboard</h1>
        <p class="text-sm text-gray-500 mt-0.5"><?= sanitize($company['name'] ?? ''); ?></p>
    </div>
    <a href="<?= $basePath ?>print<?= $ext ?>" class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700 transition-colors">
        <i class="fa-solid fa-plus"></i> New Print Order
    </a>
</div>

<!-- Tabs -->
<div class="border-b border-gray-200 mb-6">
    <nav class="-mb-px flex gap-6 overflow-x-auto" id="dashboard-tabs">
        <?php
        $tabs = [
            'overview' => ['label' => 'Overview',        'icon' => 'fa-solid fa-chart-pie'],
            'orders'   => ['label' => 'Order History',   'icon' => 'fa-solid fa-box'],
            'designs'  => ['label' => 'Card Designs',    'icon' => 'fa-solid fa-id-card'],
            'account'  => ['label' => 'Account Settings','icon' => 'fa-solid fa-gear'],
        ];
        foreach ($tabs as $key => $t):
            $active = $tab === $key;
        ?>
        <a href="?tab=<?= $key ?>"
           class="whitespace-nowrap pb-3 px-1 text-sm font-medium flex items-center gap-1.5 border-b-2 transition-colors
                  <?= $active ? 'border-blue-600 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?>">
            <i class="<?= $t['icon']; ?>"></i> <?= $t['label']; ?>
        </a>
        <?php endforeach; ?>
    </nav>
</div>

<!-- ═══════════════════════════════════════════════════════════ OVERVIEW TAB -->
<?php if ($tab === 'overview'): ?>

<!-- Stats -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
    <div class="bg-white rounded-xl ring-1 ring-gray-200/70 shadow-sm p-5 hover:shadow-md transition-shadow">
        <div class="flex items-center justify-between mb-2.5">
            <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Total Orders</span>
            <span class="w-9 h-9 rounded-lg bg-blue-50 text-blue-600 flex items-center justify-center"><i class="fa-solid fa-box"></i></span>
        </div>
        <p class="text-3xl font-bold tracking-tight text-gray-900"><?= number_format($orderCount); ?></p>
    </div>
    <div class="bg-white rounded-xl ring-1 ring-gray-200/70 shadow-sm p-5 hover:shadow-md transition-shadow">
        <div class="flex items-center justify-between mb-2.5">
            <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Total Spend</span>
            <span class="w-9 h-9 rounded-lg bg-green-50 text-green-600 flex items-center justify-center"><i class="fa-solid fa-coins"></i></span>
        </div>
        <p class="text-2xl font-bold tracking-tight text-gray-900"><?= Currency::formatHtml($totalSpend, $currency, 'lg'); ?></p>
    </div>
    <div class="bg-white rounded-xl ring-1 ring-gray-200/70 shadow-sm p-5 hover:shadow-md transition-shadow">
        <div class="flex items-center justify-between mb-2.5">
            <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Generated Cards</span>
            <span class="w-9 h-9 rounded-lg bg-purple-50 text-purple-600 flex items-center justify-center"><i class="fa-solid fa-id-card"></i></span>
        </div>
        <p class="text-3xl font-bold tracking-tight text-gray-900"><?= number_format($designCount); ?></p>
    </div>
    <div class="bg-white rounded-xl ring-1 ring-gray-200/70 shadow-sm p-5 hover:shadow-md transition-shadow">
        <div class="flex items-center justify-between mb-2.5">
            <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Employees</span>
            <span class="w-9 h-9 rounded-lg bg-amber-50 text-amber-600 flex items-center justify-center"><i class="fa-solid fa-users"></i></span>
        </div>
        <p class="text-3xl font-bold tracking-tight text-gray-900"><?= number_format($employeeCount); ?></p>
    </div>
</div>

<div class="grid lg:grid-cols-2 gap-6">
    <!-- Recent Orders -->
    <div class="bg-white rounded-xl ring-1 ring-gray-200/70 shadow-sm overflow-hidden">
        <div class="p-4 border-b border-gray-100 flex items-center justify-between">
            <h3 class="font-semibold text-gray-900">Recent Orders</h3>
            <a href="?tab=orders" class="text-sm text-blue-600 hover:text-blue-800">View all</a>
        </div>
        <div class="divide-y divide-gray-100">
            <?php $recent = array_slice($orders, 0, 5); ?>
            <?php if (empty($recent)): ?>
            <div class="p-6 text-center text-gray-500 text-sm">
                No orders yet. <a href="<?= $basePath ?>print<?= $ext ?>" class="text-blue-600 hover:underline">Place your first order</a>
            </div>
            <?php else: ?>
            <?php foreach ($recent as $o): ?>
            <div class="p-4 flex items-center justify-between">
                <div class="min-w-0 flex-1">
                    <p class="font-medium text-gray-900 text-sm">#<?= sanitize($o['order_number']); ?></p>
                    <p class="text-xs text-gray-500 truncate"><?= sanitize($o['employee_name']); ?> &middot; <?= number_format($o['quantity']); ?> cards</p>
                    <p class="text-xs text-gray-400"><?= date('M j, Y', strtotime($o['created_at'])); ?></p>
                </div>
                <div class="text-right ml-3 flex-shrink-0">
                    <p class="font-semibold text-gray-900 text-sm"><?= Currency::formatHtml($o['total'], $currency, 'sm'); ?></p>
                    <?php
                    $sc = match($o['status']) {
                        'delivered'  => 'bg-green-100 text-green-700',
                        'shipped'    => 'bg-cyan-100 text-cyan-700',
                        'printing'   => 'bg-amber-100 text-amber-700',
                        'processing' => 'bg-purple-100 text-purple-700',
                        'confirmed'  => 'bg-blue-100 text-blue-700',
                        'cancelled'  => 'bg-red-100 text-red-700',
                        default      => 'bg-gray-100 text-gray-700',
                    };
                    ?>
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium <?= $sc; ?>">
                        <?= ucfirst($o['status']); ?>
                    </span>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Recent Designs -->
    <div class="bg-white rounded-xl ring-1 ring-gray-200/70 shadow-sm overflow-hidden">
        <div class="p-4 border-b border-gray-100 flex items-center justify-between">
            <h3 class="font-semibold text-gray-900">Recent Card Designs</h3>
            <a href="?tab=designs" class="text-sm text-blue-600 hover:text-blue-800">View all</a>
        </div>
        <div class="divide-y divide-gray-100">
            <?php $recentCards = array_slice($generatedCards, 0, 5); ?>
            <?php if (empty($recentCards)): ?>
            <div class="p-6 text-center text-gray-500 text-sm">
                No cards generated yet. <a href="<?= $basePath ?>generated<?= $ext ?>" class="text-blue-600 hover:underline">Generate cards</a>
            </div>
            <?php else: ?>
            <?php foreach ($recentCards as $card): ?>
            <div class="p-4 flex items-center gap-3">
                <?php if (!empty($card['front_file_path'])): ?>
                <img src="<?= imageUrl($card['front_file_path']); ?>" alt="Card" class="w-16 h-9 object-cover rounded border border-gray-200 flex-shrink-0">
                <?php else: ?>
                <div class="w-16 h-9 rounded border border-gray-200 bg-gray-100 flex items-center justify-center flex-shrink-0">
                    <i class="fa-solid fa-id-card text-gray-400 text-sm"></i>
                </div>
                <?php endif; ?>
                <div class="min-w-0 flex-1">
                    <p class="font-medium text-gray-900 text-sm truncate"><?= sanitize($card['employee_name']); ?></p>
                    <?php if (!empty($card['front_template_name'])): ?>
                    <p class="text-xs text-gray-500 truncate"><?= sanitize($card['front_template_name']); ?></p>
                    <?php endif; ?>
                    <p class="text-xs text-gray-400"><?= date('M j, Y', strtotime($card['generated_at'])); ?></p>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════ ORDER HISTORY TAB -->
<?php elseif ($tab === 'orders'): ?>

<div class="bg-white rounded-xl ring-1 ring-gray-200/70 shadow-sm overflow-hidden">
    <div class="p-4 border-b border-gray-100 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div>
            <h3 class="font-semibold text-gray-900">Order History</h3>
            <p class="text-sm text-gray-500"><?= number_format(count($orders)); ?> orders total</p>
        </div>
        <a href="<?= $basePath ?>print<?= $ext ?>" class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700 transition-colors">
            <i class="fa-solid fa-plus"></i> New Order
        </a>
    </div>

    <?php if (empty($orders)): ?>
    <div class="p-12 text-center text-gray-500">
        <i class="fa-solid fa-box text-4xl opacity-20 mb-3"></i>
        <p class="font-medium">No orders yet</p>
        <p class="text-sm mt-1">Place your first print order to get started.</p>
        <a href="<?= $basePath ?>print<?= $ext ?>" class="mt-4 inline-flex items-center gap-2 px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700 transition-colors">
            <i class="fa-solid fa-print"></i> Order Cards
        </a>
    </div>
    <?php else: ?>

    <!-- Mobile: stacked cards -->
    <div class="sm:hidden divide-y divide-gray-100">
        <?php foreach ($orders as $o): ?>
        <?php
        $sc = match($o['status']) {
            'delivered'  => 'bg-green-100 text-green-700',
            'shipped'    => 'bg-cyan-100 text-cyan-700',
            'printing'   => 'bg-amber-100 text-amber-700',
            'processing' => 'bg-purple-100 text-purple-700',
            'confirmed'  => 'bg-blue-100 text-blue-700',
            'cancelled'  => 'bg-red-100 text-red-700',
            default      => 'bg-gray-100 text-gray-700',
        };
        $reorderUrl = $basePath . 'print' . $ext . '?reorder=' . urlencode($o['id'])
            . '&qty=' . (int)$o['quantity']
            . '&paper=' . urlencode($o['paper_type'] ?? 'matte')
            . '&finish=' . urlencode($o['finish'] ?? 'standard')
            . (!empty($o['employee_id']) ? '&employee_id=' . urlencode($o['employee_id']) : '');
        ?>
        <div class="p-4">
            <div class="flex justify-between items-start mb-2">
                <div>
                    <p class="font-semibold text-gray-900">#<?= sanitize($o['order_number']); ?></p>
                    <p class="text-sm text-gray-500"><?= date('M j, Y', strtotime($o['created_at'])); ?></p>
                </div>
                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium <?= $sc; ?>">
                    <?= ucfirst($o['status']); ?>
                </span>
            </div>
            <p class="text-sm text-gray-700 mb-1"><?= sanitize($o['employee_name']); ?><?= !empty($o['employee_position']) ? ' &mdash; ' . sanitize($o['employee_position']) : ''; ?></p>
            <div class="flex flex-wrap gap-2 text-xs text-gray-500 mb-3">
                <span><?= number_format($o['quantity']); ?> cards</span>
                <span>&middot;</span>
                <span><?= sanitize($o['paper_type'] ?? 'matte'); ?></span>
                <?php if (!empty($o['finish']) && $o['finish'] !== 'standard'): ?>
                <span>&middot;</span><span><?= sanitize($o['finish']); ?></span>
                <?php endif; ?>
            </div>
            <div class="flex items-center justify-between">
                <p class="font-bold text-gray-900"><?= Currency::formatHtml($o['total'], $currency, 'sm'); ?></p>
                <div class="flex gap-2">
                    <a href="<?= $basePath ?>order_detail<?= $ext ?>?id=<?= (int)$o['id']; ?>"
                       class="text-xs px-3 py-1.5 border border-gray-200 rounded-lg text-gray-600 hover:border-gray-300 transition-colors">
                        View
                    </a>
                    <?php if ($o['status'] !== 'cancelled'): ?>
                    <a href="<?= htmlspecialchars($reorderUrl); ?>"
                       class="text-xs px-3 py-1.5 bg-blue-50 border border-blue-200 rounded-lg text-blue-700 hover:bg-blue-100 transition-colors">
                        <i class="fa-solid fa-rotate-right mr-1"></i>Reorder
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Desktop: table -->
    <div class="hidden sm:block overflow-x-auto">
        <table class="w-full">
            <thead class="bg-gray-50 border-b border-gray-200">
                <tr>
                    <th class="text-left text-xs font-semibold text-gray-500 uppercase tracking-wide px-4 py-3">Order</th>
                    <th class="text-left text-xs font-semibold text-gray-500 uppercase tracking-wide px-4 py-3">Employee</th>
                    <th class="text-left text-xs font-semibold text-gray-500 uppercase tracking-wide px-4 py-3">Details</th>
                    <th class="text-left text-xs font-semibold text-gray-500 uppercase tracking-wide px-4 py-3">Status</th>
                    <th class="text-right text-xs font-semibold text-gray-500 uppercase tracking-wide px-4 py-3">Total</th>
                    <th class="text-right text-xs font-semibold text-gray-500 uppercase tracking-wide px-4 py-3">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <?php foreach ($orders as $o): ?>
                <?php
                $sc = match($o['status']) {
                    'delivered'  => 'bg-green-100 text-green-700',
                    'shipped'    => 'bg-cyan-100 text-cyan-700',
                    'printing'   => 'bg-amber-100 text-amber-700',
                    'processing' => 'bg-purple-100 text-purple-700',
                    'confirmed'  => 'bg-blue-100 text-blue-700',
                    'cancelled'  => 'bg-red-100 text-red-700',
                    default      => 'bg-gray-100 text-gray-700',
                };
                $reorderUrl = $basePath . 'print' . $ext . '?reorder=' . urlencode($o['id'])
                    . '&qty=' . (int)$o['quantity']
                    . '&paper=' . urlencode($o['paper_type'] ?? 'matte')
                    . '&finish=' . urlencode($o['finish'] ?? 'standard')
                    . (!empty($o['employee_id']) ? '&employee_id=' . urlencode($o['employee_id']) : '');
                ?>
                <tr class="hover:bg-gray-50 transition-colors">
                    <td class="px-4 py-3">
                        <p class="font-medium text-gray-900 text-sm">#<?= sanitize($o['order_number']); ?></p>
                        <p class="text-xs text-gray-400"><?= date('M j, Y', strtotime($o['created_at'])); ?></p>
                    </td>
                    <td class="px-4 py-3">
                        <p class="text-sm text-gray-900"><?= sanitize($o['employee_name']); ?></p>
                        <?php if (!empty($o['employee_position'])): ?>
                        <p class="text-xs text-gray-500"><?= sanitize($o['employee_position']); ?></p>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-sm text-gray-600">
                        <?= number_format($o['quantity']); ?> cards
                        &middot; <?= sanitize($o['paper_type'] ?? 'matte'); ?>
                        <?php if (!empty($o['finish']) && $o['finish'] !== 'standard'): ?>
                        &middot; <?= sanitize($o['finish']); ?>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3">
                        <div class="flex flex-col gap-1">
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium <?= $sc; ?> w-fit">
                                <?= ucfirst($o['status']); ?>
                            </span>
                            <?php if (!empty($o['tracking_number'])): ?>
                            <p class="text-xs text-gray-500">Track: <?= sanitize($o['tracking_number']); ?></p>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td class="px-4 py-3 text-right">
                        <p class="font-semibold text-gray-900 text-sm"><?= Currency::formatHtml($o['total'], $currency, 'sm'); ?></p>
                        <?php if ($o['payment_status'] === 'paid'): ?>
                        <p class="text-xs text-green-600">Paid</p>
                        <?php elseif ($o['payment_status'] === 'pending'): ?>
                        <p class="text-xs text-amber-600">Unpaid</p>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-right">
                        <div class="flex items-center justify-end gap-2">
                            <a href="<?= $basePath ?>order_detail<?= $ext ?>?id=<?= (int)$o['id']; ?>"
                               class="text-xs px-3 py-1.5 border border-gray-200 rounded-lg text-gray-600 hover:border-gray-300 transition-colors">
                                View
                            </a>
                            <?php if ($o['status'] !== 'cancelled'): ?>
                            <a href="<?= htmlspecialchars($reorderUrl); ?>"
                               title="Reorder with same settings"
                               class="text-xs px-3 py-1.5 bg-blue-50 border border-blue-200 rounded-lg text-blue-700 hover:bg-blue-100 transition-colors">
                                <i class="fa-solid fa-rotate-right mr-1"></i>Reorder
                            </a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- ══════════════════════════════════════════════════ CARD DESIGNS TAB -->
<?php elseif ($tab === 'designs'): ?>

<!-- Templates library -->
<?php if (!empty($templates)): ?>
<div class="mb-6">
    <div class="flex items-center justify-between mb-3">
        <h3 class="font-semibold text-gray-900">Templates</h3>
        <span class="text-sm text-gray-500"><?= count($templates); ?> saved</span>
    </div>
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4">
        <?php foreach ($templates as $tpl): ?>
        <div class="bg-white rounded-xl ring-1 ring-gray-200/70 shadow-sm overflow-hidden hover:border-blue-300 hover:shadow-sm transition-all group">
            <?php if (!empty($tpl['background_image_path'])): ?>
            <div class="aspect-video bg-gray-100 overflow-hidden">
                <img src="<?= imageUrl($tpl['background_image_path']); ?>" alt="<?= sanitize($tpl['name']); ?>" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-200">
            </div>
            <?php else: ?>
            <div class="aspect-video bg-gradient-to-br from-blue-50 to-blue-100 flex items-center justify-center">
                <i class="fa-solid fa-id-card text-blue-300 text-2xl"></i>
            </div>
            <?php endif; ?>
            <div class="p-3">
                <p class="font-medium text-gray-900 text-sm truncate"><?= sanitize($tpl['name']); ?></p>
                <div class="flex items-center justify-between mt-1">
                    <span class="text-xs text-gray-500"><?= ucfirst($tpl['side'] ?? 'front'); ?> side</span>
                    <span class="text-xs px-1.5 py-0.5 rounded-full <?= $tpl['is_active'] ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500'; ?>">
                        <?= $tpl['is_active'] ? 'Active' : 'Inactive'; ?>
                    </span>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Generated cards -->
<div class="bg-white rounded-xl ring-1 ring-gray-200/70 shadow-sm overflow-hidden">
    <div class="p-4 border-b border-gray-100 flex items-center justify-between">
        <div>
            <h3 class="font-semibold text-gray-900">Generated Cards</h3>
            <p class="text-sm text-gray-500"><?= number_format(count($generatedCards)); ?> total</p>
        </div>
        <a href="<?= $basePath ?>generated<?= $ext ?>" class="text-sm text-blue-600 hover:text-blue-800">Manage</a>
    </div>

    <?php if (empty($generatedCards)): ?>
    <div class="p-12 text-center text-gray-500">
        <i class="fa-solid fa-id-card text-4xl opacity-20 mb-3"></i>
        <p class="font-medium">No cards generated yet</p>
        <p class="text-sm mt-1">Generate cards for your employees to build your library.</p>
        <a href="<?= $basePath ?>generated<?= $ext ?>" class="mt-4 inline-flex items-center gap-2 px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700 transition-colors">
            <i class="fa-solid fa-wand-magic-sparkles"></i> Generate Cards
        </a>
    </div>
    <?php else: ?>
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4 p-4">
        <?php foreach ($generatedCards as $card): ?>
        <div class="bg-gray-50 rounded-lg border border-gray-200 overflow-hidden hover:border-blue-300 hover:shadow-sm transition-all group">
            <?php if (!empty($card['front_file_path'])): ?>
            <div class="aspect-video overflow-hidden bg-gray-100">
                <img src="<?= imageUrl($card['front_file_path']); ?>" alt="Card" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-200">
            </div>
            <?php else: ?>
            <div class="aspect-video bg-gradient-to-br from-gray-100 to-gray-200 flex items-center justify-center">
                <i class="fa-solid fa-id-card text-gray-400 text-xl"></i>
            </div>
            <?php endif; ?>
            <div class="p-3">
                <p class="font-medium text-gray-900 text-xs truncate"><?= sanitize($card['employee_name']); ?></p>
                <?php if (!empty($card['front_template_name'])): ?>
                <p class="text-xs text-gray-500 truncate"><?= sanitize($card['front_template_name']); ?></p>
                <?php endif; ?>
                <p class="text-xs text-gray-400 mt-0.5"><?= date('M j, Y', strtotime($card['generated_at'])); ?></p>
                <!-- Actions -->
                <div class="mt-2 flex gap-1.5">
                    <?php if (!empty($card['front_file_path'])): ?>
                    <a href="<?= getBasePath(); ?>download_card.php?id=<?= urlencode($card['id']); ?>&type=front"
                       class="flex-1 text-center text-xs py-1 bg-white border border-gray-200 rounded text-gray-600 hover:border-gray-300 transition-colors" title="Download front">
                        <i class="fa-solid fa-download"></i>
                    </a>
                    <?php endif; ?>
                    <?php if (!empty($card['pdf_file_path'])): ?>
                    <a href="<?= imageUrl($card['pdf_file_path']); ?>"
                       class="flex-1 text-center text-xs py-1 bg-white border border-gray-200 rounded text-gray-600 hover:border-gray-300 transition-colors" title="Download PDF">
                        PDF
                    </a>
                    <?php endif; ?>
                    <a href="<?= $basePath ?>print<?= $ext ?>?employee_id=<?= urlencode($card['employee_id'] ?? ''); ?>"
                       class="flex-1 text-center text-xs py-1 bg-blue-50 border border-blue-200 rounded text-blue-700 hover:bg-blue-100 transition-colors" title="Print this card">
                        <i class="fa-solid fa-print"></i>
                    </a>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- ════════════════════════════════════════════════ ACCOUNT SETTINGS TAB -->
<?php elseif ($tab === 'account'): ?>

<div class="max-w-2xl space-y-6">

    <!-- Company Info -->
    <div class="bg-white rounded-xl ring-1 ring-gray-200/70 shadow-sm overflow-hidden">
        <div class="p-4 border-b border-gray-100">
            <h3 class="font-semibold text-gray-900">Company Information</h3>
            <p class="text-sm text-gray-500">Basic details about your organisation</p>
        </div>
        <form method="post" class="p-6 space-y-4">
            <?= csrfField(); ?>
            <input type="hidden" name="action" value="update_account">

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Company Name</label>
                <input type="text" name="name" value="<?= sanitize($company['name'] ?? ''); ?>" required
                       class="w-full px-4 py-2 bg-gray-50 border border-gray-200 rounded-lg focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 text-sm">
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Billing Email</label>
                <input type="email" name="billing_email" value="<?= sanitize($company['billing_email'] ?? $company['admin_email'] ?? ''); ?>"
                       class="w-full px-4 py-2 bg-gray-50 border border-gray-200 rounded-lg focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 text-sm">
            </div>

            <?php
            // Optional columns that may not exist on legacy installs
            $hasAddress = array_key_exists('address', $company);
            $hasWebsite = array_key_exists('website', $company);
            ?>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">
                    WhatsApp / Phone
                    <span class="text-gray-400 font-normal text-xs">(for order updates)</span>
                </label>
                <input type="tel" name="phone" id="bhd224-phone-edit" value="<?= sanitize($company['phone'] ?? ''); ?>"
                       autocomplete="tel" inputmode="tel"
                       placeholder="9XXX XXXX"
                       class="w-full px-4 py-2 bg-gray-50 border border-gray-200 rounded-lg focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 text-sm">
                <input type="hidden" name="phone_e164" id="bhd224-phone-e164" value="">
                <p class="mt-1 text-xs text-gray-500">Used for order status updates and onboarding. Leave blank to opt out.</p>
            </div>

            <?php if ($hasWebsite): ?>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Website</label>
                <input type="url" name="website" value="<?= sanitize($company['website'] ?? ''); ?>"
                       class="w-full px-4 py-2 bg-gray-50 border border-gray-200 rounded-lg focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 text-sm"
                       placeholder="https://yourcompany.com">
            </div>
            <?php endif; ?>

            <?php if ($hasAddress): ?>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Address</label>
                <textarea name="address" rows="2"
                          class="w-full px-4 py-2 bg-gray-50 border border-gray-200 rounded-lg focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 text-sm"><?= sanitize($company['address'] ?? ''); ?></textarea>
            </div>
            <?php endif; ?>

            <div class="pt-2">
                <button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700 transition-colors">
                    Save Changes
                </button>
            </div>
        </form>
    </div>

    <!-- Logo & Branding -->
    <div class="bg-white rounded-xl ring-1 ring-gray-200/70 shadow-sm overflow-hidden">
        <div class="p-4 border-b border-gray-100 flex items-center justify-between">
            <div>
                <h3 class="font-semibold text-gray-900">Logo & Branding</h3>
                <p class="text-sm text-gray-500">Customise your portal appearance</p>
            </div>
            <a href="<?= $basePath ?>theme<?= $ext ?>" class="text-sm text-blue-600 hover:text-blue-800">
                Manage Theme <i class="fa-solid fa-arrow-right ml-1"></i>
            </a>
        </div>
        <div class="p-6">
            <div class="flex items-start gap-4">
                <!-- Current Logo -->
                <div class="flex-shrink-0">
                    <?php if ($theme && !empty($theme['logo_path'])): ?>
                    <img src="<?= imageUrl($theme['logo_path']); ?>" alt="Logo"
                         class="h-16 w-auto rounded-lg border border-gray-200 bg-gray-50 p-1">
                    <?php else: ?>
                    <div class="w-16 h-16 rounded-lg border border-dashed border-gray-300 bg-gray-50 flex items-center justify-center">
                        <i class="fa-solid fa-image text-gray-400 text-xl"></i>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="flex-1">
                    <?php if ($theme): ?>
                    <div class="flex items-center gap-3 mb-2">
                        <div class="w-8 h-8 rounded-lg border border-gray-200" style="background:<?= sanitize($theme['primary_color'] ?? '#2563eb'); ?>;" title="Primary color"></div>
                        <div class="w-8 h-8 rounded-lg border border-gray-200" style="background:<?= sanitize($theme['secondary_color'] ?? '#0f3460'); ?>;" title="Secondary color"></div>
                        <span class="text-sm text-gray-500">Brand colors</span>
                    </div>
                    <?php endif; ?>
                    <a href="<?= $basePath ?>theme<?= $ext ?>" class="inline-flex items-center gap-2 px-4 py-2 border border-gray-200 rounded-lg text-sm text-gray-700 hover:border-gray-300 transition-colors">
                        <i class="fa-solid fa-palette"></i> Edit Logo & Colors
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Account Info (read-only) -->
    <div class="bg-white rounded-xl ring-1 ring-gray-200/70 shadow-sm overflow-hidden">
        <div class="p-4 border-b border-gray-100">
            <h3 class="font-semibold text-gray-900">Account Details</h3>
        </div>
        <div class="divide-y divide-gray-100">
            <div class="px-6 py-3 flex items-center justify-between">
                <span class="text-sm text-gray-500">Plan</span>
                <span class="text-sm font-medium text-gray-900 capitalize"><?= sanitize($company['plan'] ?? 'free'); ?></span>
            </div>
            <div class="px-6 py-3 flex items-center justify-between">
                <span class="text-sm text-gray-500">Currency</span>
                <span class="text-sm font-medium text-gray-900"><?= sanitize($company['currency'] ?? 'OMR'); ?></span>
            </div>
            <div class="px-6 py-3 flex items-center justify-between">
                <span class="text-sm text-gray-500">Company Slug</span>
                <code class="text-sm text-gray-700 bg-gray-100 px-2 py-0.5 rounded"><?= sanitize($company['slug'] ?? ''); ?></code>
            </div>
            <div class="px-6 py-3 flex items-center justify-between">
                <span class="text-sm text-gray-500">Member Since</span>
                <span class="text-sm text-gray-700"><?= date('M j, Y', strtotime($company['created_at'] ?? 'now')); ?></span>
            </div>
        </div>
        <div class="p-4 border-t border-gray-100">
            <a href="<?= $basePath ?>billing<?= $ext ?>" class="text-sm text-blue-600 hover:text-blue-800">
                Manage subscription <i class="fa-solid fa-arrow-right ml-1"></i>
            </a>
        </div>
    </div>

</div>

<?php endif; ?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/intl-tel-input@23.8.2/build/css/intlTelInput.css">
<style>
    #bhd224-phone-edit + .iti, .iti:has(> #bhd224-phone-edit) { width: 100%; display: block; }
    #bhd224-phone-edit.iti__tel-input { padding-left: 3.5rem !important; }
</style>
<script src="https://cdn.jsdelivr.net/npm/intl-tel-input@23.8.2/build/js/intlTelInput.min.js"></script>
<script>
(function () {
    var phoneEl = document.getElementById('bhd224-phone-edit');
    var hiddenEl = document.getElementById('bhd224-phone-e164');
    if (!phoneEl || !window.intlTelInput) return;
    var iti = window.intlTelInput(phoneEl, {
        initialCountry: 'om',
        preferredCountries: ['om', 'ae', 'sa', 'qa', 'bh', 'kw'],
        separateDialCode: true,
        autoPlaceholder: 'aggressive',
        utilsScript: 'https://cdn.jsdelivr.net/npm/intl-tel-input@23.8.2/build/js/utils.js'
    });
    var form = phoneEl.closest('form');
    if (form) {
        form.addEventListener('submit', function () {
            try {
                if (phoneEl.value.trim() === '') { hiddenEl.value = ''; return; }
                hiddenEl.value = iti.isValidNumber() ? iti.getNumber() : (iti.getNumber() || phoneEl.value.trim());
            } catch (err) {
                hiddenEl.value = phoneEl.value.trim();
            }
        });
    }
})();
</script>
<?php adminFooter(); ?>
