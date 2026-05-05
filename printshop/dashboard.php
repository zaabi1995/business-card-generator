<?php
/**
 * Print Shop Dashboard
 *
 * Operator command center: greeting, time-windowed KPIs, action queue
 * grouped by stage, internal-provider client roster (when applicable),
 * credit-risk panel, operator activity, and a recent-activity feed.
 *
 * Auth: PrintShopAuth::requireLogin() handles both legacy owner login
 * and operator OTP login. Super-admins viewing via ?shop=N are routed
 * through the legacy Auth path.
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/PrintShop.php';
require_once INCLUDES_DIR . '/PrintShopAuth.php';
require_once INCLUDES_DIR . '/Currency.php';

// Resolve shop + operator. Super admin can view any shop via ?shop=N.
$user = Auth::isLoggedIn() ? Auth::getCurrentUser() : null;
$isSuperAdmin = $user && ($user['role'] ?? '') === 'super_admin';

if ($isSuperAdmin && isset($_GET['shop'])) {
    $printShop = PrintShop::getById((int) $_GET['shop']);
    $operator = null;
    if (!$printShop) {
        header('Location: ' . getBasePath() . 'admin/print_shops.php');
        exit;
    }
} else {
    $ctx = PrintShopAuth::requireLogin();
    $printShop = $ctx['shop'];
    $operator = $ctx['operator'];
}

if (!$printShop) {
    header('Location: ' . getBasePath() . 'printshop/login.php');
    exit;
}

$shopId = (int) $printShop['id'];
$operatorId = $operator['id'] ?? null;
$operatorName = $operator['name'] ?? ($printShop['name'] ?? '');
$currency = $printShop['currency'] ?? 'OMR';
$isInternalProvider = !empty($printShop['is_internal_provider']);

// Handle status updates (existing flow, preserved verbatim)
$message = null;
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !validateCSRFToken($_POST['csrf_token'] ?? '')) {
    die(htmlspecialchars(t('printshopdash.invalid_request')));
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $orderId = (int) ($_POST['order_id'] ?? 0);

    if ($action === 'update_status' && $orderId > 0) {
        require_once INCLUDES_DIR . '/PrintShopIntegration.php';
        $newStatus = $_POST['status'] ?? '';
        $trackingNumber = trim($_POST['tracking_number'] ?? '');

        $result = PrintShopIntegration::updateOrderStatus($orderId, $newStatus, $trackingNumber ?: null);

        if ($result['success']) {
            $stk = 'printshopdash.status_' . $newStatus;
            $stl = t($stk);
            if ($stl === $stk) $stl = ucfirst($newStatus);
            $message = strtr(t('printshopdash.order_updated'), [':id' => (string) $orderId, ':status' => $stl]);
        } else {
            $message = str_replace(':msg', (string) ($result['error'] ?? t('printshopdash.unknown_error')), t('printshopdash.update_error'));
            $messageType = 'error';
        }
    }
}

$data = PrintShop::getDashboardData($shopId, $operatorId);
$kpis = $data['kpis'];
$sparkline = $data['revenue_sparkline'] ?? [];
$queue = $data['action_queue'];
$internal = $data['internal_provider'];
$creditRisk = $data['credit_risk'];
$opActivity = $data['operator_activity'];
$activity = $data['recent_activity'];

// Helpers, kept page-local since they're only used here.
$hour = (int) date('G');
if      ($hour < 12) $greetingKey = 'printshopdash.greeting_morning';
else if ($hour < 17) $greetingKey = 'printshopdash.greeting_afternoon';
else                 $greetingKey = 'printshopdash.greeting_evening';

$revenue30dDelta = null;
if ($kpis['revenue_30d_prev'] > 0.001) {
    $pct = (($kpis['revenue_30d'] - $kpis['revenue_30d_prev']) / $kpis['revenue_30d_prev']) * 100.0;
    $revenue30dDelta = ['pct' => $pct, 'sign' => $pct > 1 ? 'up' : ($pct < -1 ? 'down' : 'flat')];
} elseif ($kpis['revenue_30d'] > 0.001) {
    $revenue30dDelta = ['pct' => null, 'sign' => 'new'];
}

$creditUtilPct = 0;
if ($kpis['credit_limit_total'] > 0.001) {
    $creditUtilPct = min(100, (int) round(($kpis['outstanding_credit'] / $kpis['credit_limit_total']) * 100));
}

$statusColors = [
    'pending'    => 'bg-gray-100 text-gray-700',
    'submitted'  => 'bg-blue-100 text-blue-700',
    'processing' => 'bg-purple-100 text-purple-700',
    'printing'   => 'bg-amber-100 text-amber-700',
    'shipped'    => 'bg-cyan-100 text-cyan-700',
    'delivered'  => 'bg-green-100 text-green-700',
    'cancelled'  => 'bg-red-100 text-red-700',
];

$stageMeta = [
    'submitted'  => ['next' => 'processing', 'color' => 'blue',   'icon' => 'fa-inbox'],
    'processing' => ['next' => 'printing',   'color' => 'purple', 'icon' => 'fa-gears'],
    'printing'   => ['next' => 'shipped',    'color' => 'amber',  'icon' => 'fa-print'],
    'shipped'    => ['next' => 'delivered',  'color' => 'cyan',   'icon' => 'fa-truck'],
];

$timeAgo = function ($timestamp) {
    if (!$timestamp) return '';
    $diff = time() - strtotime($timestamp);
    if ($diff < 60)        return t('printshopdash.activity_just_now');
    if ($diff < 3600)      return strtr(t('printshopdash.activity_minutes_ago'), [':n' => (string) (int) ($diff / 60)]);
    if ($diff < 86400)     return strtr(t('printshopdash.activity_hours_ago'),   [':n' => (string) (int) ($diff / 3600)]);
    return strtr(t('printshopdash.activity_days_ago'), [':n' => (string) (int) ($diff / 86400)]);
};

$activityIcons = [
    'erp_failed'         => ['icon' => 'fa-circle-exclamation', 'color' => 'red'],
    'credit_requested'   => ['icon' => 'fa-credit-card',        'color' => 'blue'],
    'template_requested' => ['icon' => 'fa-file-lines',         'color' => 'purple'],
    'new_order'          => ['icon' => 'fa-cart-plus',          'color' => 'green'],
];

$pageTitle = t('printshoppages.title_dashboard', ['shop' => $printShop['name']]);
$bodyClass = 'bg-gray-50';
require_once INCLUDES_DIR . '/ui-header.php';
?>

<div class="min-h-screen">
    <!-- Top Nav -->
    <nav class="bg-white/90 backdrop-blur-md border-b border-gray-200/80 shadow-sm fixed top-0 left-0 right-0 z-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-16">
                <div class="flex items-center gap-3">
                    <a href="<?= getBasePath() ?>" class="flex items-center">
                        <img src="<?= getBasePath() ?>assets/images/logo.svg" alt="Cardify" class="h-8 w-auto">
                    </a>
                    <span class="h-5 w-px bg-gray-200"></span>
                    <span class="font-semibold text-gray-900 text-sm"><?= sanitize($printShop['name']) ?></span>
                    <?php if (!empty($printShop['is_verified']) || !empty($printShop['verified'])): ?>
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-semibold bg-blue-50 text-blue-700 ring-1 ring-blue-100">
                        <i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars(t('printshopdash.nav_verified')) ?>
                    </span>
                    <?php endif; ?>
                    <?php if ($isInternalProvider): ?>
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-semibold bg-emerald-50 text-emerald-700 ring-1 ring-emerald-100">
                        <i class="fa-solid fa-building"></i> <?= htmlspecialchars(t('printshopdash.badge_internal_provider')) ?>
                    </span>
                    <?php endif; ?>
                </div>
                <div class="flex items-center gap-1 text-sm">
                    <a href="dashboard.php" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-blue-700 bg-blue-50 font-semibold"><i class="fa-solid fa-chart-pie"></i><span class="hidden sm:inline"><?= htmlspecialchars(t('printshopdash.nav_dashboard')) ?></span></a>
                    <a href="orders.php" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-gray-600 hover:text-gray-900 hover:bg-gray-50 font-medium transition-colors"><i class="fa-solid fa-box"></i><span class="hidden sm:inline"><?= htmlspecialchars(t('printshopdash.nav_orders')) ?></span></a>
                    <a href="analytics.php" class="hidden md:inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-gray-600 hover:text-gray-900 hover:bg-gray-50 font-medium transition-colors"><i class="fa-solid fa-chart-line"></i><?= htmlspecialchars(t('printshopdash.nav_analytics')) ?></a>
                    <a href="credit-accounts.php" class="hidden md:inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-gray-600 hover:text-gray-900 hover:bg-gray-50 font-medium transition-colors"><i class="fa-solid fa-building-columns"></i><?= htmlspecialchars(t('printshopdash.nav_credit')) ?></a>
                    <a href="settings.php" class="inline-flex items-center justify-center w-9 h-9 rounded-lg text-gray-500 hover:text-gray-900 hover:bg-gray-50 transition-colors" aria-label="<?= htmlspecialchars(t('printshopdash.nav_settings')) ?>"><i class="fa-solid fa-cog"></i></a>
                </div>
            </div>
        </div>
    </nav>

    <div class="pt-20 pb-12 px-4 sm:px-6 lg:px-8 max-w-7xl mx-auto">

        <!-- Status banners (preserved) -->
        <?php if ($printShop['status'] === 'pending'): ?>
        <div class="mb-6 p-4 rounded-xl bg-amber-50 border border-amber-200 text-amber-700 flex items-center gap-3">
            <i class="fa-solid fa-clock text-xl"></i>
            <div>
                <p class="font-semibold"><?= htmlspecialchars(t('printshopdash.pending_approval_h')) ?></p>
                <p class="text-sm"><?= htmlspecialchars(t('printshopdash.pending_approval_b')) ?></p>
            </div>
        </div>
        <?php elseif ($printShop['status'] === 'suspended'): ?>
        <div class="mb-6 p-4 rounded-xl bg-red-50 border border-red-200 text-red-700 flex items-center gap-3">
            <i class="fa-solid fa-ban text-xl"></i>
            <div>
                <p class="font-semibold"><?= htmlspecialchars(t('printshopdash.suspended_h')) ?></p>
                <p class="text-sm"><?= htmlspecialchars(t('printshopdash.suspended_b')) ?></p>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($message): ?>
        <div class="mb-6 p-4 rounded-xl <?= $messageType === 'success' ? 'bg-green-50 border border-green-200 text-green-700' : 'bg-red-50 border border-red-200 text-red-700' ?> flex items-center gap-3">
            <i class="fa-solid <?= $messageType === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i>
            <?= sanitize($message) ?>
        </div>
        <?php endif; ?>

        <!-- 1. Greeting bar -->
        <div class="mb-6 flex flex-col md:flex-row md:items-end md:justify-between gap-2">
            <div>
                <h1 class="text-2xl sm:text-3xl font-bold text-gray-900 tracking-tight">
                    <?= htmlspecialchars(strtr(t($greetingKey), [':name' => $operatorName])) ?>
                </h1>
                <p class="text-sm text-gray-500 mt-1">
                    <?php if ($kpis['today_orders'] > 0): ?>
                        <?= htmlspecialchars(strtr(t('printshopdash.greeting_today_summary'), [
                            ':n'   => (string) $kpis['today_orders'],
                            ':rev' => Currency::format($kpis['today_revenue'], $currency),
                        ])) ?>
                    <?php else: ?>
                        <?= htmlspecialchars(t('printshopdash.greeting_today_empty')) ?>
                    <?php endif; ?>
                </p>
            </div>
        </div>

        <!-- 2. KPI strip (6 tiles, admin-style) -->
        <div class="grid grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-6 mb-8">
            <?php
            $tiles = [
                ['label' => t('printshopdash.kpi_today_h'),         'value' => $kpis['today_orders'],      'fmt' => 'int',   'color' => 'blue',   'icon' => 'fa-calendar-day'],
                ['label' => t('printshopdash.kpi_awaiting_h'),      'value' => $kpis['awaiting_action'],   'fmt' => 'int',   'color' => 'amber',  'icon' => 'fa-clock'],
                ['label' => t('printshopdash.kpi_in_production_h'), 'value' => $kpis['in_production'],     'fmt' => 'int',   'color' => 'purple', 'icon' => 'fa-print'],
                ['label' => t('printshopdash.kpi_shipped_week_h'),  'value' => $kpis['shipped_this_week'], 'fmt' => 'int',   'color' => 'cyan',   'icon' => 'fa-truck'],
                ['label' => t('printshopdash.kpi_revenue_30d_h'),   'value' => $kpis['revenue_30d'],       'fmt' => 'money', 'color' => 'green',  'icon' => 'fa-coins',           'delta' => $revenue30dDelta],
                ['label' => t('printshopdash.kpi_outstanding_h'),   'value' => $kpis['outstanding_credit'],'fmt' => 'money', 'color' => 'red',    'icon' => 'fa-building-columns'],
            ];
            foreach ($tiles as $tile):
                $deltaSign = $tile['delta']['sign'] ?? null;
                $deltaText = null;
                if ($deltaSign === 'up') {
                    $deltaText = strtr(t('printshopdash.kpi_delta_up'),   [':pct' => number_format(abs($tile['delta']['pct']), 0)]);
                } elseif ($deltaSign === 'down') {
                    $deltaText = strtr(t('printshopdash.kpi_delta_down'), [':pct' => '-' . number_format(abs($tile['delta']['pct']), 0)]);
                } elseif ($deltaSign === 'flat') {
                    $deltaText = t('printshopdash.kpi_delta_flat');
                } elseif ($deltaSign === 'new') {
                    $deltaText = t('printshopdash.kpi_delta_new');
                }
            ?>
            <div class="bg-white rounded-xl p-6 border border-gray-100 shadow-sm hover:shadow-md transition-shadow">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 rounded-xl bg-<?= $tile['color'] ?>-100 flex items-center justify-center flex-shrink-0">
                        <i class="fa-solid <?= $tile['icon'] ?> text-<?= $tile['color'] ?>-600 text-xl"></i>
                    </div>
                    <div class="min-w-0">
                        <p class="text-3xl font-bold text-gray-900 tracking-tight leading-none">
                            <?php if ($tile['fmt'] === 'money'): ?>
                                <?= Currency::formatHtml((float) $tile['value'], $currency, 'sm') ?>
                            <?php else: ?>
                                <?= (int) $tile['value'] ?>
                            <?php endif; ?>
                        </p>
                        <p class="text-gray-500 text-sm mt-1.5"><?= htmlspecialchars($tile['label']) ?></p>
                        <?php if ($deltaText): ?>
                        <p class="mt-1 text-xs font-medium <?= $deltaSign === 'up' ? 'text-green-600' : ($deltaSign === 'down' ? 'text-red-600' : 'text-gray-500') ?>">
                            <?= htmlspecialchars($deltaText) ?>
                        </p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- 2b. Revenue trend widget (mirrors admin Card Views Analytics) -->
        <?php
        $maxDailyRev = 0;
        foreach ($sparkline as $v) { if ($v > $maxDailyRev) $maxDailyRev = $v; }
        $hasRevenue = $kpis['revenue_30d'] > 0.001 || $maxDailyRev > 0.001;
        $revenue7d = 0;
        foreach ($sparkline as $v) { $revenue7d += $v; }
        ?>
        <?php if ($hasRevenue): ?>
        <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-6 mb-8">
            <div class="flex items-center justify-between mb-4">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-green-100 flex items-center justify-center">
                        <i class="fa-solid fa-chart-line text-green-600 text-lg"></i>
                    </div>
                    <div>
                        <h3 class="font-semibold text-gray-900 text-sm"><?= htmlspecialchars(t('printshopdash.revenue_widget_h')) ?></h3>
                        <p class="text-xs text-gray-500"><?= htmlspecialchars(t('printshopdash.revenue_widget_sub')) ?></p>
                    </div>
                </div>
                <a href="analytics.php" class="text-sm text-blue-600 hover:text-blue-700 font-medium flex items-center gap-1">
                    <?= htmlspecialchars(t('printshopdash.revenue_full_analytics')) ?> <i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i>
                </a>
            </div>
            <!-- Period stats -->
            <div class="grid grid-cols-3 gap-4 mb-5">
                <div class="text-center p-3 rounded-lg bg-gray-50">
                    <p class="text-2xl font-bold text-gray-900"><?= Currency::formatHtml((float) $kpis['today_revenue'], $currency, 'sm') ?></p>
                    <p class="text-xs text-gray-500 mt-0.5"><?= htmlspecialchars(t('printshopdash.revenue_period_today')) ?></p>
                </div>
                <div class="text-center p-3 rounded-lg bg-gray-50">
                    <p class="text-2xl font-bold text-gray-900"><?= Currency::formatHtml((float) $revenue7d, $currency, 'sm') ?></p>
                    <p class="text-xs text-gray-500 mt-0.5"><?= htmlspecialchars(t('printshopdash.revenue_period_7d')) ?></p>
                </div>
                <div class="text-center p-3 rounded-lg bg-gray-50">
                    <p class="text-2xl font-bold text-gray-900"><?= Currency::formatHtml((float) $kpis['revenue_30d'], $currency, 'sm') ?></p>
                    <p class="text-xs text-gray-500 mt-0.5"><?= htmlspecialchars(t('printshopdash.revenue_period_30d')) ?></p>
                </div>
            </div>
            <!-- 7-day bar chart -->
            <div class="flex items-end gap-1.5 h-20">
                <?php foreach ($sparkline as $date => $rev):
                    $pct = $maxDailyRev > 0 ? max(4, round($rev / $maxDailyRev * 100)) : 4;
                    $title = date('D, M j', strtotime($date)) . ': ' . Currency::format((float) $rev, $currency);
                ?>
                <div class="flex-1 flex flex-col items-center gap-1 group relative">
                    <div class="w-full rounded-t bg-green-500 hover:bg-green-600 transition-colors cursor-default" style="height: <?= $pct ?>%"
                         title="<?= htmlspecialchars($title) ?>"></div>
                    <span class="text-[10px] text-gray-400 leading-none"><?= date('D', strtotime($date)) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- 3. Action queue -->
        <div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden mb-8">
            <div class="p-5 border-b border-gray-100 flex items-center justify-between flex-wrap gap-2">
                <div>
                    <h2 class="text-lg font-semibold text-gray-900"><?= htmlspecialchars(t('printshopdash.queue_h')) ?></h2>
                    <p class="text-sm text-gray-500"><?= htmlspecialchars(t('printshopdash.queue_sub')) ?></p>
                </div>
                <a href="orders.php" class="text-sm text-blue-600 hover:text-blue-700 font-medium"><?= htmlspecialchars(t('printshopdash.view_all')) ?> <i class="fa-solid fa-arrow-right text-xs ml-1"></i></a>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 divide-y md:divide-y-0 md:divide-x divide-gray-100">
                <?php foreach ($stageMeta as $stage => $meta):
                    $orders = $queue[$stage] ?? [];
                    $count = count($orders);
                    $countLabel = $count === 1
                        ? t('printshopdash.queue_count_one')
                        : strtr(t('printshopdash.queue_count_many'), [':n' => (string) $count]);
                    $advanceLabelKey = 'printshopdash.queue_advance_' . $meta['next'];
                ?>
                <div class="flex flex-col">
                    <div class="px-4 py-3 bg-<?= $meta['color'] ?>-50/40 border-b border-<?= $meta['color'] ?>-100/60">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <span class="w-7 h-7 rounded-lg bg-<?= $meta['color'] ?>-100 text-<?= $meta['color'] ?>-700 flex items-center justify-center text-xs"><i class="fa-solid <?= $meta['icon'] ?>"></i></span>
                                <span class="font-semibold text-sm text-gray-900"><?= htmlspecialchars(t('printshopdash.queue_stage_' . $stage)) ?></span>
                            </div>
                            <span class="text-xs font-medium text-gray-600"><?= htmlspecialchars($countLabel) ?></span>
                        </div>
                    </div>
                    <div class="flex-1 divide-y divide-gray-50">
                        <?php if (empty($orders)): ?>
                            <div class="p-4 text-xs text-gray-400 text-center"><?= htmlspecialchars(t('printshopdash.queue_empty')) ?></div>
                        <?php else: foreach ($orders as $order): ?>
                            <div class="p-4">
                                <div class="flex items-start justify-between gap-2 mb-2">
                                    <div class="min-w-0">
                                        <p class="text-sm font-semibold text-gray-900 truncate"><?= sanitize($order['company_name'] ?? t('printshopdash.unknown_company')) ?></p>
                                        <p class="text-xs text-gray-500 truncate">
                                            #<?= (int) $order['id'] ?>
                                            <?php if (!empty($order['employee_name'])): ?>
                                                &middot; <?= sanitize($order['employee_name']) ?>
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                    <span class="text-xs font-semibold text-gray-700 whitespace-nowrap"><?= Currency::formatHtml((float) $order['total'], $currency, 'xs') ?></span>
                                </div>
                                <p class="text-[11px] text-gray-500 mb-2">
                                    <?= htmlspecialchars(strtr(t('printshopdash.order_meta'), [
                                        ':n'     => (string) $order['quantity'],
                                        ':paper' => ucfirst($order['paper_type'] ?? 'standard'),
                                    ])) ?>
                                </p>
                                <form method="post" class="flex items-center gap-2">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action"   value="update_status">
                                    <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                                    <input type="hidden" name="status"   value="<?= htmlspecialchars($meta['next']) ?>">
                                    <button type="submit" class="flex-1 inline-flex items-center justify-center gap-1 text-[11px] font-semibold text-<?= $meta['color'] ?>-700 bg-<?= $meta['color'] ?>-50 hover:bg-<?= $meta['color'] ?>-100 px-2 py-1.5 rounded-lg transition-colors">
                                        <i class="fa-solid fa-arrow-right text-[10px]"></i>
                                        <span><?= htmlspecialchars(t($advanceLabelKey)) ?></span>
                                    </button>
                                    <a href="orders.php?id=<?= (int) $order['id'] ?>" class="text-[11px] text-gray-500 hover:text-gray-700 px-2 py-1.5"><?= htmlspecialchars(t('printshopdash.queue_open')) ?></a>
                                </form>
                            </div>
                        <?php endforeach; endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- 4. Internal provider panel (rendered only for internal providers) -->
        <?php if ($internal !== null): ?>
        <div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden mb-8">
            <div class="p-5 border-b border-gray-100 flex items-center justify-between flex-wrap gap-2">
                <div>
                    <h2 class="text-lg font-semibold text-gray-900"><?= htmlspecialchars(t('printshopdash.internal_h')) ?></h2>
                    <p class="text-sm text-gray-500"><?= htmlspecialchars(t('printshopdash.internal_top_clients')) ?></p>
                </div>
                <div class="flex items-center gap-2">
                    <a href="clients.php" class="text-sm text-blue-600 hover:text-blue-700 font-medium"><?= htmlspecialchars(t('printshopdash.internal_browse_clients')) ?></a>
                    <a href="order-on-behalf.php" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-emerald-600 text-white text-sm font-semibold hover:bg-emerald-700 transition-colors">
                        <i class="fa-solid fa-plus"></i><?= htmlspecialchars(t('printshopdash.internal_order_on_behalf')) ?>
                    </a>
                </div>
            </div>
            <?php if (empty($internal['top_clients_30d'])): ?>
                <div class="p-8 text-center text-sm text-gray-500"><?= htmlspecialchars(t('printshopdash.internal_no_recent')) ?></div>
            <?php else: ?>
            <ul class="divide-y divide-gray-100">
                <?php foreach ($internal['top_clients_30d'] as $i => $client): ?>
                <li class="p-4 flex items-center gap-3 hover:bg-gray-50 transition-colors">
                    <span class="w-8 h-8 rounded-lg bg-blue-50 text-blue-700 flex items-center justify-center text-xs font-bold">#<?= $i + 1 ?></span>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-semibold text-gray-900 truncate"><?= sanitize($client['company_name']) ?></p>
                        <p class="text-xs text-gray-500">
                            <?php $oc = (int) $client['order_count']; ?>
                            <?= htmlspecialchars($oc === 1
                                ? t('printshopdash.queue_count_one')
                                : strtr(t('printshopdash.queue_count_many'), [':n' => (string) $oc])) ?>
                            &middot; <?= Currency::format((float) $client['revenue'], $currency) ?>
                        </p>
                    </div>
                    <?php if (!empty($client['company_slug'])): ?>
                    <a href="client.php?company=<?= urlencode((string) $client['company_id']) ?>" class="text-xs text-gray-500 hover:text-blue-600">
                        <i class="fa-solid fa-arrow-right"></i>
                    </a>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
            <?php if ($internal['dormant_count'] > 0): ?>
            <div class="px-5 py-3 bg-amber-50/40 border-t border-amber-100/60 text-xs text-amber-800">
                <i class="fa-solid fa-bell mr-1"></i>
                <?= htmlspecialchars(strtr(t('printshopdash.internal_dormant'), [':n' => (string) $internal['dormant_count']])) ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Two-column row: credit risk + operator activity (or activity feed when no operator panel) -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-8">

            <!-- 5. Credit risk panel -->
            <div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
                <div class="p-5 border-b border-gray-100 flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-gray-900"><?= htmlspecialchars(t('printshopdash.credit_risk_h')) ?></h2>
                    <a href="credit-accounts.php" class="text-sm text-blue-600 hover:text-blue-700 font-medium"><?= htmlspecialchars(t('printshopdash.credit_risk_review')) ?></a>
                </div>
                <?php if ($creditRisk['account_count'] === 0): ?>
                    <div class="p-8 text-center text-sm text-gray-500"><?= htmlspecialchars(t('printshopdash.credit_risk_no_accounts')) ?></div>
                <?php else: ?>
                <div class="p-5">
                    <p class="text-sm text-gray-700 mb-2">
                        <?= htmlspecialchars(strtr(t('printshopdash.credit_risk_total'), [
                            ':used'  => Currency::format((float) $kpis['outstanding_credit'], $currency),
                            ':limit' => Currency::format((float) $kpis['credit_limit_total'], $currency),
                        ])) ?>
                    </p>
                    <div class="h-2 bg-gray-100 rounded-full overflow-hidden mb-4">
                        <div class="h-full <?= $creditUtilPct >= 80 ? 'bg-red-500' : ($creditUtilPct >= 50 ? 'bg-amber-500' : 'bg-emerald-500') ?>" style="width: <?= $creditUtilPct ?>%"></div>
                    </div>
                    <?php if (!empty($creditRisk['top_exposed'])): ?>
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-2"><?= htmlspecialchars(t('printshopdash.credit_risk_top_exposed')) ?></p>
                    <ul class="space-y-2">
                        <?php foreach ($creditRisk['top_exposed'] as $acct):
                            $limit = max(0.001, (float) $acct['credit_limit']);
                            $used  = (float) $acct['balance_used'];
                            $pct   = min(100, (int) round(($used / $limit) * 100));
                            $terms = preg_replace('/\D/', '', (string) $acct['payment_terms']);
                        ?>
                        <li class="flex items-center gap-3 text-sm">
                            <div class="flex-1 min-w-0">
                                <p class="font-medium text-gray-900 truncate"><?= sanitize($acct['company_name']) ?></p>
                                <div class="h-1 bg-gray-100 rounded-full overflow-hidden mt-1">
                                    <div class="h-full <?= $pct >= 80 ? 'bg-red-500' : ($pct >= 50 ? 'bg-amber-500' : 'bg-emerald-500') ?>" style="width: <?= $pct ?>%"></div>
                                </div>
                            </div>
                            <div class="text-right whitespace-nowrap">
                                <p class="text-xs font-semibold text-gray-700"><?= $pct ?>%</p>
                                <p class="text-[11px] text-gray-500"><?= htmlspecialchars(strtr(t('printshopdash.credit_risk_terms'), [':n' => $terms])) ?></p>
                            </div>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- 6. Operator activity (only when 2+ active operators) -->
            <?php if ($opActivity !== null): ?>
            <div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
                <div class="p-5 border-b border-gray-100 flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-gray-900"><?= htmlspecialchars(t('printshopdash.operator_activity_h')) ?></h2>
                    <a href="operators.php" class="text-sm text-blue-600 hover:text-blue-700 font-medium"><?= htmlspecialchars(t('printshopdash.operator_view_team')) ?></a>
                </div>
                <ul class="divide-y divide-gray-100">
                    <?php foreach ($opActivity as $op):
                        $initials = '';
                        $parts = preg_split('/\s+/', trim((string) $op['name']));
                        foreach (array_slice($parts, 0, 2) as $p) { $initials .= mb_substr($p, 0, 1); }
                        $initials = strtoupper($initials) ?: '?';
                        $count = (int) $op['orders_week'];
                        $countLabel = $count === 0
                            ? t('printshopdash.operator_no_activity')
                            : ($count === 1
                                ? t('printshopdash.operator_orders_unit_one')
                                : strtr(t('printshopdash.operator_orders_unit'), [':n' => (string) $count]));
                    ?>
                    <li class="p-4 flex items-center gap-3">
                        <span class="w-9 h-9 rounded-full bg-blue-100 text-blue-700 flex items-center justify-center text-sm font-bold"><?= htmlspecialchars($initials) ?></span>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-semibold text-gray-900 truncate"><?= sanitize($op['name']) ?></p>
                            <p class="text-xs text-gray-500"><?= htmlspecialchars($countLabel) ?> &middot; <?= Currency::format((float) $op['revenue_week'], $currency) ?></p>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php else: ?>
            <!-- Activity feed takes the right slot when no operator panel -->
            <div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
                <div class="p-5 border-b border-gray-100">
                    <h2 class="text-lg font-semibold text-gray-900"><?= htmlspecialchars(t('printshopdash.activity_h')) ?></h2>
                </div>
                <?php if (empty($activity)): ?>
                    <div class="p-8 text-center text-sm text-gray-500"><?= htmlspecialchars(t('printshopdash.activity_empty')) ?></div>
                <?php else: ?>
                <ul class="divide-y divide-gray-100">
                    <?php foreach ($activity as $item):
                        $meta = $activityIcons[$item['type']] ?? ['icon' => 'fa-circle-info', 'color' => 'gray'];
                        $msgKey = 'printshopdash.activity_' . $item['type'];
                        $ref = $item['ref_label'] ?: ('#' . ($item['ref_id'] ?? ''));
                        $msg = strtr(t($msgKey), [':ref' => (string) $ref]);
                    ?>
                    <li class="p-4 flex items-start gap-3">
                        <span class="w-8 h-8 rounded-lg bg-<?= $meta['color'] ?>-50 text-<?= $meta['color'] ?>-600 flex items-center justify-center text-xs flex-shrink-0"><i class="fa-solid <?= $meta['icon'] ?>"></i></span>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm text-gray-900 truncate"><?= sanitize($msg) ?></p>
                            <?php if (!empty($item['context'])): ?>
                            <p class="text-xs text-gray-500 truncate"><?= sanitize($item['context']) ?></p>
                            <?php endif; ?>
                        </div>
                        <span class="text-[11px] text-gray-400 whitespace-nowrap"><?= htmlspecialchars($timeAgo($item['at'])) ?></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
            <?php endif; ?>

        </div>

        <!-- 7. Recent activity feed (full width, only rendered if operator panel was shown) -->
        <?php if ($opActivity !== null): ?>
        <div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
            <div class="p-5 border-b border-gray-100">
                <h2 class="text-lg font-semibold text-gray-900"><?= htmlspecialchars(t('printshopdash.activity_h')) ?></h2>
            </div>
            <?php if (empty($activity)): ?>
                <div class="p-8 text-center text-sm text-gray-500"><?= htmlspecialchars(t('printshopdash.activity_empty')) ?></div>
            <?php else: ?>
            <ul class="divide-y divide-gray-100">
                <?php foreach ($activity as $item):
                    $meta = $activityIcons[$item['type']] ?? ['icon' => 'fa-circle-info', 'color' => 'gray'];
                    $msgKey = 'printshopdash.activity_' . $item['type'];
                    $ref = $item['ref_label'] ?: ('#' . ($item['ref_id'] ?? ''));
                    $msg = strtr(t($msgKey), [':ref' => (string) $ref]);
                ?>
                <li class="p-4 flex items-start gap-3">
                    <span class="w-8 h-8 rounded-lg bg-<?= $meta['color'] ?>-50 text-<?= $meta['color'] ?>-600 flex items-center justify-center text-xs flex-shrink-0"><i class="fa-solid <?= $meta['icon'] ?>"></i></span>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm text-gray-900 truncate"><?= sanitize($msg) ?></p>
                        <?php if (!empty($item['context'])): ?>
                        <p class="text-xs text-gray-500 truncate"><?= sanitize($item['context']) ?></p>
                        <?php endif; ?>
                    </div>
                    <span class="text-[11px] text-gray-400 whitespace-nowrap"><?= htmlspecialchars($timeAgo($item['at'])) ?></span>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
        <?php endif; ?>

    </div>
</div>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
