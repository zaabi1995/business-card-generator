<?php
/**
 * Print Shop Dashboard.
 *
 * Operator command center: greeting + 4 ops KPIs + Tenant Console
 * (internal-provider only) + Action Queue + Revenue trend + Credit
 * risk + Recent activity. Same operational surface as before, in
 * Cardify's standard design language (BHD teal, white cards, Inter).
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/PrintShop.php';
require_once INCLUDES_DIR . '/PrintShopAuth.php';
require_once INCLUDES_DIR . '/Currency.php';

// Resolve shop + operator. Super-admin can view any shop via ?shop=N.
$user = Auth::isLoggedIn() ? Auth::getCurrentUser() : null;
$isSuperAdmin = $user && ($user['role'] ?? '') === 'super_admin';

if ($isSuperAdmin && isset($_GET['shop'])) {
    $printShop = PrintShop::getById((int) $_GET['shop']);
    $operator = null;
    if (!$printShop) { header('Location: ' . getBasePath() . 'admin/print_shops.php'); exit; }
} else {
    $ctx = PrintShopAuth::requireLogin();
    $printShop = $ctx['shop'];
    $operator = $ctx['operator'];
}
if (!$printShop) { header('Location: ' . getBasePath() . 'printshop/login.php'); exit; }

$shopId = (int) $printShop['id'];
$operatorId = $operator['id'] ?? null;
$operatorName = $operator['name'] ?? ($printShop['name'] ?? '');
$currency = $printShop['currency'] ?? 'OMR';
$isInternalProvider = !empty($printShop['is_internal_provider']);

// Status update flow (preserved verbatim from prior versions)
$message = null; $messageType = 'success';
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
            $stl = t($stk); if ($stl === $stk) $stl = ucfirst($newStatus);
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
$creditRisk = $data['credit_risk'];
$opActivity = $data['operator_activity'];
$activity = $data['recent_activity'];
$tenants = $data['tenant_console'] ?? [];

$canCancel = PrintShopAuth::can('cancel_order', isset($ctx) ? $ctx : null);

// Greeting
$hour = (int) date('G');
if      ($hour < 12) $greetingKey = 'printshopdash.greeting_morning';
else if ($hour < 17) $greetingKey = 'printshopdash.greeting_afternoon';
else                 $greetingKey = 'printshopdash.greeting_evening';

// Revenue trend delta
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

$revenue7d = 0; foreach ($sparkline as $v) { $revenue7d += $v; }
$maxDailyRev = 0; foreach ($sparkline as $v) { if ($v > $maxDailyRev) $maxDailyRev = $v; }

// Tenant filter counts
$tenantStats = ['active30' => 0, 'dormant60' => 0, 'unprinted' => 0];
$now = time();
foreach ($tenants as $t) {
    $last = $t['last_order_at'] ? strtotime($t['last_order_at']) : 0;
    if ($last && ($now - $last) <= 30 * 86400) $tenantStats['active30']++;
    if ($last && ($now - $last) >= 60 * 86400) $tenantStats['dormant60']++;
    elseif (!$last && (int) $t['employee_count'] > 0) $tenantStats['dormant60']++;
    if ((int) $t['generated_count'] < (int) $t['employee_count']) $tenantStats['unprinted']++;
}

$stageMeta = [
    'submitted'  => ['next' => 'processing', 'tone' => 'blue',   'icon' => 'fa-inbox'],
    'processing' => ['next' => 'printing',   'tone' => 'purple', 'icon' => 'fa-gears'],
    'printing'   => ['next' => 'shipped',    'tone' => 'amber',  'icon' => 'fa-print'],
    'shipped'    => ['next' => 'delivered',  'tone' => 'cyan',   'icon' => 'fa-truck'],
];

$timeAgo = function ($timestamp) {
    if (!$timestamp) return '';
    $diff = time() - strtotime($timestamp);
    if ($diff < 60)    return t('printshopdash.activity_just_now');
    if ($diff < 3600)  return strtr(t('printshopdash.activity_minutes_ago'), [':n' => (string) (int) ($diff / 60)]);
    if ($diff < 86400) return strtr(t('printshopdash.activity_hours_ago'),   [':n' => (string) (int) ($diff / 3600)]);
    return strtr(t('printshopdash.activity_days_ago'), [':n' => (string) (int) ($diff / 86400)]);
};

$activityIcons = [
    'erp_failed'         => ['icon' => 'fa-circle-exclamation', 'color' => 'red'],
    'credit_requested'   => ['icon' => 'fa-credit-card',        'color' => 'blue'],
    'template_requested' => ['icon' => 'fa-file-lines',         'color' => 'purple'],
    'new_order'          => ['icon' => 'fa-cart-plus',          'color' => 'green'],
];

require_once INCLUDES_DIR . '/printshop-layout.php';
printshopHeader(t('printshoppages.title_dashboard', ['shop' => $printShop['name']]), 'dashboard');
?>

<!-- 0. Status banners -->
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
    <span><?= sanitize($message) ?></span>
</div>
<?php endif; ?>

<!-- 1. Greeting -->
<div class="mb-8 flex flex-col md:flex-row md:items-end md:justify-between gap-3">
    <div>
        <h1 class="text-2xl sm:text-3xl font-bold text-gray-900 tracking-tight">
            <?= htmlspecialchars(strtr(t($greetingKey), [':name' => $operatorName])) ?>
        </h1>
        <p class="text-sm text-gray-500 mt-1.5">
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
    <?php if ($isInternalProvider): ?>
    <div class="flex items-center gap-2">
        <a href="#tenant-console" class="ps-btn-secondary text-sm">
            <i class="fa-solid fa-arrow-down"></i><?= htmlspecialchars(t('printshopdash.jump_to_tenants')) ?>
        </a>
        <a href="order-on-behalf.php" class="ps-btn-primary text-sm">
            <i class="fa-solid fa-plus"></i><?= htmlspecialchars(t('printshopdash.internal_order_on_behalf')) ?>
        </a>
    </div>
    <?php endif; ?>
</div>

<!-- 2. KPI strip (4 ops tiles, admin-style) -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
    <?php
    $alertAwait = $kpis['awaiting_action'] > 0;
    $tiles = [
        ['label' => t('printshopdash.kpi_awaiting_h'),      'val' => $kpis['awaiting_action'],     'fmt' => 'int',
         'iconBg' => $alertAwait ? 'bg-red-100' : 'bg-blue-100',
         'iconClr'=> $alertAwait ? 'text-red-600' : 'text-blue-600',
         'numClr' => $alertAwait ? 'text-red-600' : 'text-gray-900',
         'icon'   => 'fa-clock'],
        ['label' => t('printshopdash.kpi_in_production_h'), 'val' => $kpis['in_production'],       'fmt' => 'int',
         'iconBg' => 'bg-purple-100', 'iconClr' => 'text-purple-600', 'numClr' => 'text-gray-900', 'icon' => 'fa-print'],
        ['label' => t('printshopdash.kpi_shipped_week_h'),  'val' => $kpis['shipped_this_week'],   'fmt' => 'int',
         'iconBg' => 'bg-cyan-100',   'iconClr' => 'text-cyan-600',   'numClr' => 'text-gray-900', 'icon' => 'fa-truck'],
        ['label' => t('printshopdash.kpi_revenue_30d_h'),   'val' => $kpis['revenue_30d'],         'fmt' => 'money',
         'iconBg' => 'bg-green-100',  'iconClr' => 'text-green-600',  'numClr' => 'text-gray-900', 'icon' => 'fa-coins',
         'delta'  => $revenue30dDelta],
    ];
    foreach ($tiles as $tile):
        $deltaSign = $tile['delta']['sign'] ?? null;
        $deltaText = null;
        if ($deltaSign === 'up')        $deltaText = '+' . number_format(abs($tile['delta']['pct']), 0) . '% vs prior 30d';
        elseif ($deltaSign === 'down')  $deltaText = '-' . number_format(abs($tile['delta']['pct']), 0) . '% vs prior 30d';
        elseif ($deltaSign === 'flat')  $deltaText = htmlspecialchars(t('printshopdash.kpi_delta_flat'));
        elseif ($deltaSign === 'new')   $deltaText = htmlspecialchars(t('printshopdash.kpi_delta_new'));
    ?>
    <div class="bg-white rounded-xl p-6 border border-gray-100 shadow-sm hover:shadow-md transition-shadow">
        <div class="flex items-center gap-4">
            <div class="w-12 h-12 rounded-xl <?= $tile['iconBg'] ?> flex items-center justify-center flex-shrink-0">
                <i class="fa-solid <?= $tile['icon'] ?> <?= $tile['iconClr'] ?> text-xl"></i>
            </div>
            <div class="min-w-0">
                <p class="text-3xl font-bold <?= $tile['numClr'] ?> tracking-tight leading-none">
                    <?php if ($tile['fmt'] === 'money'): ?>
                        <?= Currency::formatHtml((float) $tile['val'], $currency, 'sm') ?>
                    <?php else: ?>
                        <?= (int) $tile['val'] ?>
                    <?php endif; ?>
                </p>
                <p class="text-gray-500 text-sm mt-1.5"><?= htmlspecialchars($tile['label']) ?></p>
                <?php if ($deltaText): ?>
                <p class="mt-1 text-xs font-medium <?= $deltaSign === 'up' ? 'text-green-600' : ($deltaSign === 'down' ? 'text-red-600' : 'text-gray-500') ?>"><?= $deltaText ?></p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- 3. Tenant Console (internal-provider only) -->
<?php if ($isInternalProvider): ?>
<section id="tenant-console" class="mb-8 bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden" x-data="tenantConsole()">
    <div class="p-5 border-b border-gray-100 flex items-center justify-between flex-wrap gap-2">
        <div>
            <h2 class="text-lg font-semibold text-gray-900"><?= htmlspecialchars(t('printshopdash.tenants_h')) ?></h2>
            <p class="text-sm text-gray-500"><?= htmlspecialchars(t('printshopdash.tenants_lede')) ?></p>
        </div>
        <a href="clients.php" class="text-sm font-medium text-cardify-primary hover:underline"><?= htmlspecialchars(t('printshopdash.tenants_view_all')) ?> &rarr;</a>
    </div>

    <!-- Search + filter chips -->
    <div class="p-5 border-b border-gray-100 flex flex-col lg:flex-row gap-3 lg:items-center">
        <div class="flex-1 flex items-center gap-2 px-3 py-2 bg-white border border-gray-300 rounded-lg focus-within:ring-2 focus-within:ring-cardify-primary focus-within:border-cardify-primary">
            <i class="fa-solid fa-magnifying-glass text-gray-400"></i>
            <input type="text" x-model="q" @input="apply()" placeholder="<?= htmlspecialchars(t('printshopdash.tenants_search_ph')) ?>" class="flex-1 border-0 outline-none text-sm" autocomplete="off">
            <span x-show="q" class="text-xs text-gray-400" x-text="visible + ' / <?= count($tenants) ?>'"></span>
        </div>
        <div class="flex flex-wrap gap-2">
            <button type="button" class="cardify-chip text-sm" :aria-pressed="filter === 'all'"   @click="filter='all'; apply()"><?= htmlspecialchars(t('printshopdash.tenants_filter_all')) ?> <span class="ml-1 text-gray-400"><?= count($tenants) ?></span></button>
            <button type="button" class="cardify-chip text-sm" :aria-pressed="filter === 'active'" @click="filter='active'; apply()"><?= htmlspecialchars(t('printshopdash.tenants_filter_active')) ?> <span class="ml-1 text-gray-400"><?= $tenantStats['active30'] ?></span></button>
            <button type="button" class="cardify-chip text-sm" :aria-pressed="filter === 'dormant'" @click="filter='dormant'; apply()"><?= htmlspecialchars(t('printshopdash.tenants_filter_dormant')) ?> <span class="ml-1 text-gray-400"><?= $tenantStats['dormant60'] ?></span></button>
            <button type="button" class="cardify-chip text-sm" :aria-pressed="filter === 'unprinted'" @click="filter='unprinted'; apply()"><?= htmlspecialchars(t('printshopdash.tenants_filter_unprinted')) ?> <span class="ml-1 text-gray-400"><?= $tenantStats['unprinted'] ?></span></button>
        </div>
    </div>

    <!-- Tenant rows -->
    <?php if (empty($tenants)): ?>
    <div class="p-12 text-center text-sm text-gray-500"><?= htmlspecialchars(t('printshopdash.tenants_empty')) ?></div>
    <?php else: ?>
    <div class="divide-y divide-gray-100">
        <?php foreach ($tenants as $t):
            $emp = (int) $t['employee_count'];
            $gen = (int) $t['generated_count'];
            $tpl = (int) $t['template_count'];
            $ord = (int) $t['order_count'];
            $last = $t['last_order_at'] ?? null;
            $lastDays = $last ? (int) round((time() - strtotime($last)) / 86400) : null;
            $isActive    = $last && ($lastDays !== null && $lastDays <= 30);
            $isDormant   = ($last && $lastDays !== null && $lastDays >= 60) || (!$last && $emp > 0);
            $unprinted   = ($emp > $gen);
            // Resolve logo_path defensively: legacy rows store bare-relative
            // paths like "companies/<id>/theme/logo.svg" (no /uploads/ prefix).
            // Mirrors TenantHost::theme() + admin-layout.php normalization.
            $logoSrc = null;
            if (!empty($t['logo_path'])) {
                $lp = (string) $t['logo_path'];
                if (preg_match('#^https?://#i', $lp)) {
                    $logoSrc = $lp;
                } else {
                    if ($lp[0] !== '/') $lp = '/uploads/' . ltrim($lp, '/');
                    $logoSrc = getBasePath() . ltrim($lp, '/');
                }
            }
            $initials = '';
            foreach (array_slice(preg_split('/\s+/', trim((string) $t['name'])), 0, 2) as $p) { $initials .= mb_substr($p, 0, 1); }
            $initials = mb_strtoupper($initials) ?: '?';
        ?>
        <div class="p-4 hover:bg-gray-50 transition-colors flex items-center gap-4 flex-wrap"
             data-name="<?= htmlspecialchars(mb_strtolower($t['name'] . ' ' . ($t['slug'] ?? ''))) ?>"
             data-active="<?= $isActive ? '1' : '0' ?>"
             data-dormant="<?= $isDormant ? '1' : '0' ?>"
             data-unprinted="<?= $unprinted ? '1' : '0' ?>">
            <!-- Logo / initials with onerror fallback for stale logo paths -->
            <div class="w-10 h-10 rounded-lg bg-gray-100 border border-gray-200 flex items-center justify-center flex-shrink-0 overflow-hidden relative">
                <span class="text-xs font-bold text-gray-500 absolute inset-0 flex items-center justify-center"><?= htmlspecialchars($initials) ?></span>
                <?php if ($logoSrc): ?>
                    <img src="<?= htmlspecialchars($logoSrc) ?>" alt="" loading="lazy"
                         class="max-w-[80%] max-h-[80%] relative z-10 bg-gray-100"
                         onerror="this.style.display='none'">
                <?php endif; ?>
            </div>
            <!-- Name + slug + status -->
            <div class="flex-1 min-w-0">
                <p class="font-semibold text-gray-900 truncate"><?= sanitize($t['name']) ?></p>
                <p class="text-xs text-gray-500 truncate flex items-center gap-2 flex-wrap mt-0.5">
                    <span><?= sanitize($t['slug'] ?? '') ?></span>
                    <?php if ($isActive): ?>
                        <span class="cardify-badge cardify-badge--success"><?= htmlspecialchars(t('printshopdash.tenant_status_active')) ?></span>
                    <?php elseif ($isDormant): ?>
                        <span class="cardify-badge"><?= htmlspecialchars(t('printshopdash.tenant_status_dormant')) ?></span>
                    <?php elseif ($emp === 0): ?>
                        <span class="cardify-badge"><?= htmlspecialchars(t('printshopdash.tenant_status_empty')) ?></span>
                    <?php endif; ?>
                </p>
            </div>
            <!-- Stats: employees, cards ready, last order -->
            <div class="hidden lg:flex items-center gap-6 text-sm">
                <div class="text-center">
                    <p class="text-lg font-bold text-gray-900 leading-none"><?= $emp ?></p>
                    <p class="text-[11px] uppercase tracking-wide text-gray-400 font-semibold mt-1"><?= htmlspecialchars(t('printshopdash.tenants_col_employees')) ?></p>
                </div>
                <div class="text-center">
                    <p class="text-lg font-bold leading-none <?= $unprinted ? 'text-amber-600' : 'text-gray-900' ?>">
                        <?= $gen ?><?php if ($emp > 0 && $gen < $emp): ?><span class="text-sm font-medium"> / <?= $emp ?></span><?php endif; ?>
                    </p>
                    <p class="text-[11px] uppercase tracking-wide text-gray-400 font-semibold mt-1"><?= htmlspecialchars(t('printshopdash.tenants_col_cards')) ?></p>
                </div>
                <div class="text-center min-w-[80px]">
                    <p class="text-sm font-medium text-gray-900 leading-none">
                        <?= $last ? htmlspecialchars(date('M j, Y', strtotime($last))) : '<span class="text-gray-400 italic font-normal">' . htmlspecialchars(t('printshopdash.tenants_no_orders')) . '</span>' ?>
                    </p>
                    <?php if ($last): ?>
                    <p class="text-[11px] text-gray-400 mt-1"><?= htmlspecialchars(strtr(t('printshopdash.tenants_orders_n'), [':n' => (string) $ord])) ?></p>
                    <?php endif; ?>
                </div>
            </div>
            <!-- Actions -->
            <div class="flex items-center gap-2">
                <button type="button" class="ps-btn-secondary text-sm" style="padding: 6px 12px;"
                        @click="openUpload(<?= htmlspecialchars(json_encode(['id' => $t['id'], 'name' => $t['name']]), ENT_QUOTES) ?>)"
                        title="<?= htmlspecialchars(t('printshopdash.tenants_btn_upload_pdf')) ?>">
                    <i class="fa-solid fa-file-arrow-up"></i><span class="hidden xl:inline"><?= htmlspecialchars(t('printshopdash.tenants_btn_upload_pdf')) ?></span>
                </button>
                <a href="client.php?company=<?= urlencode($t['id']) ?>" class="ps-btn-primary text-sm" style="padding: 6px 12px;">
                    <i class="fa-solid fa-id-card"></i><span><?= htmlspecialchars(t('printshopdash.tenants_btn_open')) ?></span>
                </a>
                <a href="client.php?company=<?= urlencode($t['id']) ?>#order" class="ps-btn-secondary text-sm" style="padding: 6px 12px;" title="<?= htmlspecialchars(t('printshopdash.tenants_btn_order')) ?>">
                    <i class="fa-solid fa-cart-plus"></i><span class="hidden xl:inline"><?= htmlspecialchars(t('printshopdash.tenants_btn_order')) ?></span>
                </a>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Upload PDF modal (Alpine, shared across all tenant rows) -->
    <div x-cloak x-show="upload.open" x-transition.opacity @keydown.escape.window="if (!upload.busy) upload.open = false"
         class="fixed inset-0 z-[100] items-center justify-center px-4" style="display: none; background: rgba(15,23,42,0.5);"
         :style="upload.open ? 'display: flex; background: rgba(15,23,42,0.5);' : 'display: none;'">
        <div @click.outside="if (!upload.busy) upload.open = false"
             class="bg-white rounded-2xl shadow-lg w-full max-w-xl p-6 sm:p-8 relative">
            <button type="button" @click="upload.open = false" :disabled="upload.busy"
                    class="absolute top-4 right-4 text-gray-400 hover:text-gray-700">
                <i class="fa-solid fa-xmark text-lg"></i>
            </button>
            <h3 class="text-xl font-bold text-gray-900 tracking-tight" x-text="'<?= htmlspecialchars(t('printshopdash.tenants_btn_upload_pdf')) ?>: ' + (upload.companyName || '')"></h3>
            <p class="text-sm text-gray-500 mt-1.5 mb-5"><?= htmlspecialchars(t('printshopdash.upload_modal_lede')) ?></p>

            <!-- 4-step indicator -->
            <ol class="grid grid-cols-4 gap-2 mb-5">
                <template x-for="(label, i) in ['Upload','Parse','Classify','Save']" :key="i">
                    <li class="flex flex-col gap-1.5 text-xs">
                        <span class="block h-1 rounded-full"
                              :class="upload.step > i ? 'bg-green-500' : (upload.step === i && upload.busy ? 'bg-cardify-primary-700' : 'bg-gray-200')"
                              style="background-color: var(--state-color, currentColor);"></span>
                        <span :class="upload.step >= i ? 'text-gray-900 font-medium' : 'text-gray-400'" x-text="(i+1) + '. ' + label"></span>
                    </li>
                </template>
            </ol>

            <form x-show="!upload.success" @submit.prevent="submitUpload($event)" class="space-y-4">
                <input type="hidden" name="csrf_token" :value="csrfToken">
                <input type="hidden" name="company_id" :value="upload.companyId">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5"><?= htmlspecialchars(t('printshopdash.upload_field_name')) ?></label>
                    <input type="text" name="name" x-model="upload.name" required
                           placeholder="<?= htmlspecialchars(t('printshopdash.upload_field_name_ph')) ?>"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-cardify-primary focus:border-cardify-primary">
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5"><?= htmlspecialchars(t('printshopdash.upload_field_front')) ?></label>
                        <input type="file" name="front_pdf" accept="application/pdf" required
                               @change="upload.frontFile = $event.target.files[0]?.name || ''"
                               class="block w-full text-xs file:mr-2 file:py-1.5 file:px-3 file:rounded-md file:border-0 file:bg-cardify-primary-50 file:text-cardify-primary-700">
                        <span x-show="upload.frontFile" class="block mt-1 text-xs text-green-600" x-text="upload.frontFile"></span>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5"><?= htmlspecialchars(t('printshopdash.upload_field_back')) ?></label>
                        <input type="file" name="back_pdf" accept="application/pdf"
                               @change="upload.backFile = $event.target.files[0]?.name || ''"
                               class="block w-full text-xs file:mr-2 file:py-1.5 file:px-3 file:rounded-md file:border-0 file:bg-gray-100 file:text-gray-700">
                        <span x-show="upload.backFile" class="block mt-1 text-xs text-green-600" x-text="upload.backFile"></span>
                    </div>
                </div>
                <div x-show="upload.error" x-cloak class="p-3 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm" style="display: none;">
                    <div class="flex items-start gap-2">
                        <i class="fa-solid fa-circle-exclamation mt-0.5"></i><span x-text="upload.error"></span>
                    </div>
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" class="ps-btn-secondary text-sm" @click="upload.open = false" :disabled="upload.busy"><?= htmlspecialchars(t('printshopdash.upload_cancel')) ?></button>
                    <button type="submit" class="ps-btn-primary text-sm" :disabled="upload.busy">
                        <i class="fa-solid" :class="upload.busy ? 'fa-circle-notch fa-spin' : 'fa-arrow-right'"></i>
                        <span x-text="upload.busy ? '<?= htmlspecialchars(t('printshopdash.upload_busy')) ?>' : '<?= htmlspecialchars(t('printshopdash.upload_submit')) ?>'"></span>
                    </button>
                </div>
            </form>

            <div x-show="upload.success" x-cloak class="space-y-4">
                <div class="p-3 rounded-lg bg-green-50 border border-green-200 text-green-700 text-sm flex items-center gap-2">
                    <i class="fa-solid fa-circle-check"></i><?= htmlspecialchars(t('printshopdash.upload_success')) ?>
                </div>
                <template x-if="upload.missingFonts && upload.missingFonts.length > 0">
                    <div class="p-3 rounded-lg bg-amber-50 border border-amber-200 text-amber-800 text-sm">
                        <p class="font-semibold mb-1"><?= htmlspecialchars(t('printshopdash.upload_missing_fonts_h')) ?></p>
                        <p class="text-xs"><?= htmlspecialchars(t('printshopdash.upload_missing_fonts_b')) ?></p>
                        <ul class="text-xs mt-2 space-y-0.5">
                            <template x-for="f in upload.missingFonts" :key="f"><li>&middot; <span x-text="f"></span></li></template>
                        </ul>
                    </div>
                </template>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" class="ps-btn-secondary text-sm" @click="resetUpload()"><?= htmlspecialchars(t('printshopdash.upload_another')) ?></button>
                    <a class="ps-btn-primary text-sm" :href="'client.php?company=' + encodeURIComponent(upload.companyId)">
                        <i class="fa-solid fa-arrow-right"></i><?= htmlspecialchars(t('printshopdash.upload_open_tenant')) ?>
                    </a>
                </div>
            </div>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- 4. Action queue -->
<section class="mb-8">
    <div class="mb-4 flex items-end justify-between flex-wrap gap-2">
        <div>
            <h2 class="text-lg font-semibold text-gray-900"><?= htmlspecialchars(t('printshopdash.queue_h')) ?></h2>
            <p class="text-sm text-gray-500"><?= htmlspecialchars(t('printshopdash.queue_sub')) ?></p>
        </div>
        <a href="orders.php" class="text-sm font-medium text-cardify-primary hover:underline"><?= htmlspecialchars(t('printshopdash.view_all')) ?> &rarr;</a>
    </div>
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
        <?php foreach ($stageMeta as $stage => $meta):
            $orders = $queue[$stage] ?? [];
            $count = count($orders);
            $countLabel = $count === 1 ? t('printshopdash.queue_count_one') : strtr(t('printshopdash.queue_count_many'), [':n' => (string) $count]);
        ?>
        <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
            <div class="px-4 py-3 border-b border-gray-100 flex items-center justify-between bg-<?= $meta['tone'] ?>-50">
                <span class="font-semibold text-sm text-gray-900 flex items-center gap-1.5">
                    <i class="fa-solid <?= $meta['icon'] ?> text-<?= $meta['tone'] ?>-600"></i>
                    <?= htmlspecialchars(t('printshopdash.queue_stage_' . $stage)) ?>
                </span>
                <span class="text-xs text-gray-500"><?= htmlspecialchars($countLabel) ?></span>
            </div>
            <?php if (empty($orders)): ?>
            <div class="p-6 text-center text-xs text-gray-400"><?= htmlspecialchars(t('printshopdash.queue_empty')) ?></div>
            <?php else: ?>
            <div class="divide-y divide-gray-100">
                <?php foreach ($orders as $order): ?>
                <div class="p-3.5">
                    <div class="flex items-start justify-between gap-2 mb-1.5">
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-gray-900 truncate"><?= sanitize($order['company_name'] ?? t('printshopdash.unknown_company')) ?></p>
                            <p class="text-xs text-gray-500 truncate">#<?= (int) $order['id'] ?>
                                <?php if (!empty($order['employee_name'])): ?>&middot; <?= sanitize($order['employee_name']) ?><?php endif; ?>
                            </p>
                        </div>
                        <span class="text-xs font-semibold text-gray-700 whitespace-nowrap"><?= Currency::formatHtml((float) $order['total'], $currency, 'xs') ?></span>
                    </div>
                    <p class="text-xs text-gray-500 mb-2.5">
                        <?= htmlspecialchars(strtr(t('printshopdash.order_meta'), [
                            ':n' => (string) $order['quantity'],
                            ':paper' => ucfirst($order['paper_type'] ?? 'standard'),
                        ])) ?>
                    </p>
                    <form method="post" class="flex items-center gap-2">
                        <?= csrfField() ?>
                        <input type="hidden" name="action"   value="update_status">
                        <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                        <input type="hidden" name="status"   value="<?= htmlspecialchars($meta['next']) ?>">
                        <button type="submit" class="flex-1 ps-btn-primary text-xs" style="padding: 6px 10px;">
                            <i class="fa-solid fa-arrow-right text-[10px]"></i>
                            <span><?= htmlspecialchars(t('printshopdash.queue_advance_' . $meta['next'])) ?></span>
                        </button>
                        <a href="orders.php?id=<?= (int) $order['id'] ?>" class="ps-btn-secondary text-xs" style="padding: 6px 10px;" title="<?= htmlspecialchars(t('printshopdash.queue_open')) ?>">
                            <i class="fa-solid fa-eye"></i>
                        </a>
                        <?php if ($canCancel): ?>
                        <button type="button"
                                onclick="window.dispatchEvent(new CustomEvent('ps:cancel-order', {detail: {id: <?= (int) $order['id'] ?>, ref: <?= json_encode('#' . (int) $order['id']) ?>}}))"
                                class="ps-btn-secondary text-xs" style="padding: 6px 8px; color: #b91c1c; border-color: #fecaca;"
                                title="<?= htmlspecialchars(t('printshopdash.cancel_btn')) ?>">
                            <i class="fa-solid fa-xmark"></i>
                        </button>
                        <?php endif; ?>
                    </form>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</section>

<!-- 5. Revenue trend -->
<?php if ($kpis['revenue_30d'] > 0.001 || $maxDailyRev > 0.001): ?>
<section class="mb-8 bg-white rounded-xl border border-gray-200 shadow-sm p-6">
    <div class="flex items-center justify-between mb-4 flex-wrap gap-3">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-cyan-100 flex items-center justify-center">
                <i class="fa-solid fa-chart-line text-cyan-600"></i>
            </div>
            <div>
                <h3 class="font-semibold text-gray-900 text-sm"><?= htmlspecialchars(t('printshopdash.revenue_widget_h')) ?></h3>
                <p class="text-xs text-gray-500"><?= htmlspecialchars(t('printshopdash.revenue_widget_sub')) ?></p>
            </div>
        </div>
        <a href="analytics.php" class="text-sm font-medium text-cardify-primary hover:underline"><?= htmlspecialchars(t('printshopdash.revenue_full_analytics')) ?> &rarr;</a>
    </div>
    <div class="grid grid-cols-3 gap-4 mb-5">
        <div class="text-center p-3 rounded-lg bg-gray-50">
            <p class="text-xl font-bold text-gray-900"><?= Currency::formatHtml((float) $kpis['today_revenue'], $currency, 'xs') ?></p>
            <p class="text-xs text-gray-500 mt-0.5"><?= htmlspecialchars(t('printshopdash.revenue_period_today')) ?></p>
        </div>
        <div class="text-center p-3 rounded-lg bg-gray-50">
            <p class="text-xl font-bold text-gray-900"><?= Currency::formatHtml((float) $revenue7d, $currency, 'xs') ?></p>
            <p class="text-xs text-gray-500 mt-0.5"><?= htmlspecialchars(t('printshopdash.revenue_period_7d')) ?></p>
        </div>
        <div class="text-center p-3 rounded-lg bg-gray-50">
            <p class="text-xl font-bold text-gray-900"><?= Currency::formatHtml((float) $kpis['revenue_30d'], $currency, 'xs') ?></p>
            <p class="text-xs text-gray-500 mt-0.5"><?= htmlspecialchars(t('printshopdash.revenue_period_30d')) ?></p>
        </div>
    </div>
    <div class="flex items-end gap-1.5 h-20">
        <?php foreach ($sparkline as $date => $rev):
            $pct = $maxDailyRev > 0 ? max(4, round($rev / $maxDailyRev * 100)) : 4;
            $title = date('D, M j', strtotime($date)) . ': ' . Currency::format((float) $rev, $currency);
        ?>
        <div class="flex-1 flex flex-col items-center gap-1">
            <div class="w-full rounded-t transition-colors hover:opacity-80" style="height: <?= $pct ?>%; background: <?= $rev > 0 ? 'var(--cardify-primary-500)' : '#e5e7eb' ?>;" title="<?= htmlspecialchars($title) ?>"></div>
            <span class="text-[10px] text-gray-400 leading-none"><?= date('D', strtotime($date)) ?></span>
        </div>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<!-- 6. Two-col footer: Credit + Activity -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-8">

    <!-- Credit risk -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <div class="p-5 border-b border-gray-100 flex items-center justify-between">
            <h3 class="font-semibold text-gray-900"><?= htmlspecialchars(t('printshopdash.credit_risk_h')) ?></h3>
            <a href="credit-accounts.php" class="text-sm font-medium text-cardify-primary hover:underline"><?= htmlspecialchars(t('printshopdash.credit_risk_review')) ?></a>
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
                <div class="h-full <?= $creditUtilPct >= 80 ? 'bg-red-500' : ($creditUtilPct >= 50 ? 'bg-amber-500' : 'bg-green-500') ?>" style="width: <?= $creditUtilPct ?>%"></div>
            </div>
            <?php if (!empty($creditRisk['top_exposed'])): ?>
            <p class="text-xs uppercase tracking-wide text-gray-500 font-semibold mb-2"><?= htmlspecialchars(t('printshopdash.credit_risk_top_exposed')) ?></p>
            <ul class="space-y-2.5">
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
                            <div class="h-full <?= $pct >= 80 ? 'bg-red-500' : ($pct >= 50 ? 'bg-amber-500' : 'bg-green-500') ?>" style="width: <?= $pct ?>%"></div>
                        </div>
                    </div>
                    <div class="text-right whitespace-nowrap">
                        <p class="text-xs font-semibold text-gray-700"><?= $pct ?>%</p>
                        <p class="text-[11px] text-gray-400"><?= htmlspecialchars(strtr(t('printshopdash.credit_risk_terms'), [':n' => $terms])) ?></p>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Activity feed -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <div class="p-5 border-b border-gray-100">
            <h3 class="font-semibold text-gray-900"><?= htmlspecialchars(t('printshopdash.activity_h')) ?></h3>
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
                <span class="w-8 h-8 rounded-lg bg-<?= $meta['color'] ?>-50 text-<?= $meta['color'] ?>-600 flex items-center justify-center flex-shrink-0">
                    <i class="fa-solid <?= $meta['icon'] ?> text-xs"></i>
                </span>
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

</div>

<!-- 7. Operator activity (only when 2+ ops) -->
<?php if ($opActivity !== null && !empty($opActivity)): ?>
<section class="mb-8 bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
    <div class="p-5 border-b border-gray-100 flex items-center justify-between">
        <h3 class="font-semibold text-gray-900"><?= htmlspecialchars(t('printshopdash.operator_activity_h')) ?></h3>
        <a href="operators.php" class="text-sm font-medium text-cardify-primary hover:underline"><?= htmlspecialchars(t('printshopdash.operator_view_team')) ?></a>
    </div>
    <ul class="divide-y divide-gray-100">
        <?php foreach ($opActivity as $op):
            $initials = '';
            $parts = preg_split('/\s+/', trim((string) $op['name']));
            foreach (array_slice($parts, 0, 2) as $p) { $initials .= mb_substr($p, 0, 1); }
            $initials = mb_strtoupper($initials) ?: '?';
            $count = (int) $op['orders_week'];
            $countLabel = $count === 0 ? t('printshopdash.operator_no_activity')
                       : ($count === 1 ? t('printshopdash.operator_orders_unit_one')
                                       : strtr(t('printshopdash.operator_orders_unit'), [':n' => (string) $count]));
        ?>
        <li class="p-4 flex items-center gap-3">
            <span class="w-9 h-9 rounded-full bg-cardify-primary-50 text-cardify-primary-700 flex items-center justify-center text-sm font-bold"><?= htmlspecialchars($initials) ?></span>
            <div class="flex-1 min-w-0">
                <p class="text-sm font-semibold text-gray-900 truncate"><?= sanitize($op['name']) ?></p>
                <p class="text-xs text-gray-500"><?= htmlspecialchars($countLabel) ?> &middot; <?= Currency::format((float) $op['revenue_week'], $currency) ?></p>
            </div>
        </li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>

<script>
function tenantConsole() {
    return {
        q: '',
        filter: 'all',
        visible: 0,
        csrfToken: <?= json_encode(generateCSRFToken()) ?>,
        upload: {
            open: false, busy: false, step: 0,
            companyId: '', companyName: '', name: '',
            frontFile: '', backFile: '',
            error: '', success: false, missingFonts: []
        },
        init() { this.apply(); },
        apply() {
            var q = (this.q || '').toLowerCase().trim();
            var f = this.filter;
            var rows = document.querySelectorAll('#tenant-console [data-name]');
            var n = 0;
            rows.forEach(function (r) {
                var name = r.getAttribute('data-name') || '';
                var matchQ = !q || name.indexOf(q) !== -1;
                var matchF = (
                    f === 'all' ||
                    (f === 'active'    && r.getAttribute('data-active') === '1') ||
                    (f === 'dormant'   && r.getAttribute('data-dormant') === '1') ||
                    (f === 'unprinted' && r.getAttribute('data-unprinted') === '1')
                );
                if (matchQ && matchF) { r.hidden = false; n++; }
                else r.hidden = true;
            });
            this.visible = n;
        },
        openUpload(tenant) {
            this.resetUpload();
            this.upload.companyId = tenant.id;
            this.upload.companyName = tenant.name;
            this.upload.name = tenant.name + ' card';
            this.upload.open = true;
        },
        resetUpload() {
            this.upload.busy = false; this.upload.step = 0;
            this.upload.error = ''; this.upload.success = false;
            this.upload.missingFonts = [];
            this.upload.frontFile = ''; this.upload.backFile = '';
            this.upload.name = this.upload.companyName ? (this.upload.companyName + ' card') : '';
        },
        async submitUpload(ev) {
            this.upload.error = '';
            this.upload.busy = true;
            this.upload.step = 0;

            var fd = new FormData(ev.target);
            fd.set('csrf_token', this.csrfToken);
            fd.set('company_id', this.upload.companyId);
            if (!fd.get('name') || !fd.get('name').trim()) fd.set('name', this.upload.name);

            var self = this;
            var stepTimer = setInterval(function () { if (self.upload.step < 3) self.upload.step++; }, 1500);
            try {
                var res = await fetch('create-design-for-client.php', { method: 'POST', body: fd, credentials: 'same-origin' });
                var json = null;
                try { json = await res.json(); } catch (_) {}
                clearInterval(stepTimer);
                if (!res.ok || !json || !json.ok) {
                    this.upload.error = (json && json.error) || ('HTTP ' + res.status);
                    this.upload.busy = false;
                    this.upload.step = 0;
                    return;
                }
                this.upload.step = 4;
                this.upload.success = true;
                this.upload.missingFonts = (json.missing_fonts || []).map(function (f) {
                    return typeof f === 'string' ? f : (f.family || f.name || JSON.stringify(f));
                });
                this.upload.busy = false;
            } catch (e) {
                clearInterval(stepTimer);
                this.upload.error = String(e && e.message ? e.message : e);
                this.upload.busy = false;
                this.upload.step = 0;
            }
        }
    };
}
</script>

<?php require_once INCLUDES_DIR . '/printshop-cancel-modal.php'; ?>

<?php printshopFooter(); ?>
