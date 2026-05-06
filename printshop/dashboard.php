<?php
/**
 * Print Shop Dashboard, Press Floor edition.
 *
 * Internal-provider operations console. Tenant Console is the core
 * surface: every Cardify tenant with employee/template/card counts
 * and per-row Generate/Sheet/Order actions. KPIs, action queue,
 * revenue trend, credit risk and recent activity flank it.
 *
 * Aesthetic: cream newsprint paper, deep ink, vermillion accent,
 * Bricolage Grotesque display + JetBrains Mono numerals + IBM Plex
 * Sans body. Crop-mark corners, ink-stamp pills, tabular numerals.
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/PrintShop.php';
require_once INCLUDES_DIR . '/PrintShopAuth.php';
require_once INCLUDES_DIR . '/Currency.php';

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

// Status update flow (preserved verbatim)
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

// Greeting derived from server time (GMT+4 set in config.php)
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

// Tenant console enrichments computed in PHP, simpler than SQL CASEs
$tenantStats = ['active30' => 0, 'dormant60' => 0, 'unprinted' => 0];
$now = time();
foreach ($tenants as $t) {
    $last = $t['last_order_at'] ? strtotime($t['last_order_at']) : 0;
    if ($last && ($now - $last) <= 30 * 86400) $tenantStats['active30']++;
    if ($last && ($now - $last) >= 60 * 86400) $tenantStats['dormant60']++;
    elseif (!$last && (int) $t['employee_count'] > 0) $tenantStats['dormant60']++; // never ordered counts too
    if ((int) $t['generated_count'] < (int) $t['employee_count']) $tenantStats['unprinted']++;
}

$stageMeta = [
    'submitted'  => ['next' => 'processing', 'tone' => 'press-blue', 'icon' => 'fa-inbox'],
    'processing' => ['next' => 'printing',   'tone' => 'press-blue', 'icon' => 'fa-gears'],
    'printing'   => ['next' => 'shipped',    'tone' => 'vermillion', 'icon' => 'fa-print'],
    'shipped'    => ['next' => 'delivered',  'tone' => 'ink',        'icon' => 'fa-truck'],
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
    'erp_failed'         => 'fa-circle-exclamation',
    'credit_requested'   => 'fa-credit-card',
    'template_requested' => 'fa-file-lines',
    'new_order'          => 'fa-cart-plus',
];

$pageTitle = t('printshoppages.title_dashboard', ['shop' => $printShop['name']]);
$bodyClass = 'press-floor-body';
require_once INCLUDES_DIR . '/ui-header.php';
?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@10..48,400;10..48,500;10..48,600;10..48,700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">

<style>
  /* Press Floor scoping. Site-wide chrome (header/footer) untouched. */
  body.press-floor-body { background: #f8f6f1; }
  .press-floor {
    --paper:        #f8f6f1;
    --paper-2:      #fffefb;
    --ink:          #0a0a0c;
    --ink-2:        #2a2a2e;
    --ink-3:        #6b6b73;
    --ink-4:        #a3a3a8;
    --rule:         #e2dfd6;
    --rule-2:       #d4d0c2;
    --vermillion:   #dc2626;
    --vermillion-2: #b91c1c;
    --press-blue:   #1e3a8a;
    --canary:       #f5d447;
    --moss:         #4d7c0f;
    --plum:         #6b21a8;
    color: var(--ink);
    font-family: "IBM Plex Sans", system-ui, -apple-system, sans-serif;
  }
  .press-floor .display { font-family: "Bricolage Grotesque", "IBM Plex Sans", sans-serif; letter-spacing: -0.02em; font-feature-settings: "ss01","ss02"; }
  .press-floor .mono    { font-family: "JetBrains Mono", "IBM Plex Mono", ui-monospace, monospace; font-variant-numeric: tabular-nums; letter-spacing: -0.04em; }
  .press-floor .num     { font-variant-numeric: tabular-nums; letter-spacing: -0.01em; }
  .press-floor .ucase   { letter-spacing: 0.18em; text-transform: uppercase; font-weight: 600; }

  /* Paper texture (subtle grain via repeating SVG) */
  .pf-paper {
    background-color: var(--paper-2);
    background-image:
      url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='160' height='160' viewBox='0 0 160 160'><filter id='n'><feTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='1' stitchTiles='stitch'/><feColorMatrix values='0 0 0 0 0.06 0 0 0 0 0.06 0 0 0 0 0.07 0 0 0 0.05 0'/></filter><rect width='100%25' height='100%25' filter='url(%23n)'/></svg>");
    border: 1px solid var(--rule);
  }

  /* Crop-mark corners on hero */
  .pf-cropmarks { position: relative; }
  .pf-cropmarks::before, .pf-cropmarks::after,
  .pf-cropmarks > .pf-cm-tr, .pf-cropmarks > .pf-cm-br {
    content: ""; position: absolute; width: 14px; height: 14px;
    border-color: var(--ink-3);
  }
  .pf-cropmarks::before { top: -1px; left: -1px;  border-left: 1.5px solid var(--ink-3); border-top: 1.5px solid var(--ink-3); }
  .pf-cropmarks::after  { bottom: -1px; left: -1px; border-left: 1.5px solid var(--ink-3); border-bottom: 1.5px solid var(--ink-3); }
  .pf-cropmarks .pf-cm-tr { top: -1px; right: -1px; border-right: 1.5px solid var(--ink-3); border-top: 1.5px solid var(--ink-3); }
  .pf-cropmarks .pf-cm-br { bottom: -1px; right: -1px; border-right: 1.5px solid var(--ink-3); border-bottom: 1.5px solid var(--ink-3); }

  /* Registration mark dot */
  .pf-reg { display:inline-block; width:14px; height:14px; border:1.5px solid var(--ink); border-radius:50%; position:relative; }
  .pf-reg::after { content:""; position:absolute; inset:5px; border-radius:50%; background: var(--vermillion); }

  /* Stat tile, big number above small label, ink rule below */
  .pf-stat {
    background: var(--paper-2);
    border: 1px solid var(--rule);
    padding: 22px 22px 20px;
    position: relative;
  }
  .pf-stat .num-xl { font-family:"Bricolage Grotesque",sans-serif; font-size: 56px; line-height: 0.9; font-weight: 700; letter-spacing: -0.04em; color: var(--ink); font-variant-numeric: tabular-nums; }
  .pf-stat .lbl { font-size: 11px; color: var(--ink-3); margin-top: 14px; letter-spacing: 0.16em; text-transform: uppercase; font-weight: 600; }
  .pf-stat .delta { font-family:"JetBrains Mono",monospace; font-size: 11px; margin-top: 4px; }
  .pf-stat .badge-corner { position:absolute; top:14px; right:16px; font-family:"JetBrains Mono",monospace; font-size:10px; color: var(--ink-4); letter-spacing: 0.1em; }
  .pf-stat-alert { border-color: var(--vermillion); }
  .pf-stat-alert .num-xl { color: var(--vermillion); }
  .pf-stat-alert .lbl { color: var(--vermillion-2); }

  /* Section header rule */
  .pf-section-rule {
    border-top: 1.5px solid var(--ink);
    border-bottom: 1px solid var(--rule);
    padding: 14px 0 10px;
    display:flex; align-items:end; justify-content:space-between; gap: 16px; flex-wrap: wrap;
    margin-bottom: 16px;
  }
  .pf-section-rule h2 {
    font-family: "Bricolage Grotesque", sans-serif;
    font-size: 28px; line-height: 1; letter-spacing: -0.02em; font-weight: 700;
  }
  .pf-section-rule .lede { color: var(--ink-3); font-size: 13px; max-width: 56ch; }
  .pf-section-rule .meta { font-family:"JetBrains Mono",monospace; font-size: 11px; color: var(--ink-4); letter-spacing: 0.1em; }

  /* Ink-stamp pill */
  .pf-stamp {
    display:inline-flex; align-items:center; gap: 6px; padding: 3px 9px; font-size: 10.5px; font-weight: 600;
    letter-spacing: 0.16em; text-transform: uppercase;
    border: 1.2px solid currentColor; border-radius: 2px;
    background: rgba(255,255,255,0.4); backdrop-filter: saturate(1.1);
  }
  .pf-stamp.tone-vermillion { color: var(--vermillion); }
  .pf-stamp.tone-blue       { color: var(--press-blue); }
  .pf-stamp.tone-ink        { color: var(--ink); }
  .pf-stamp.tone-moss       { color: var(--moss); }
  .pf-stamp.tone-plum       { color: var(--plum); }
  .pf-stamp.tone-mute       { color: var(--ink-4); }

  /* Tenant row */
  .pf-tenant-row { display: grid; grid-template-columns: 44px minmax(0, 1.5fr) 0.8fr 0.8fr 0.8fr 280px; gap: 16px; align-items: center; padding: 14px 18px; border-top: 1px solid var(--rule); }
  .pf-tenant-row:first-of-type { border-top-color: var(--ink); border-top-width: 1.5px; }
  .pf-tenant-row:hover { background: rgba(220, 38, 38, 0.025); }
  .pf-tenant-row[hidden] { display: none; }
  .pf-tenant-logo { width: 44px; height: 44px; border: 1px solid var(--rule); background: var(--paper); display:flex; align-items:center; justify-content:center; font-family:"Bricolage Grotesque",sans-serif; font-weight: 700; font-size: 18px; color: var(--ink-2); overflow: hidden; }
  .pf-tenant-logo img { max-width: 80%; max-height: 80%; }
  .pf-tenant-name { font-weight: 600; font-size: 15px; color: var(--ink); line-height: 1.2; }
  .pf-tenant-slug { font-family:"JetBrains Mono",monospace; color: var(--ink-4); font-size: 11px; margin-top: 2px; letter-spacing: -0.02em; }
  .pf-tenant-stat .v { font-family:"Bricolage Grotesque",sans-serif; font-size: 22px; font-weight: 700; line-height: 1; font-variant-numeric: tabular-nums; }
  .pf-tenant-stat .v.muted { color: var(--ink-4); font-weight: 500; }
  .pf-tenant-stat .l { font-size: 10.5px; color: var(--ink-3); margin-top: 4px; letter-spacing: 0.1em; text-transform: uppercase; font-weight: 600; }
  .pf-action-btn {
    display:inline-flex; align-items:center; justify-content:center; gap: 6px; padding: 7px 11px;
    font-size: 12px; font-weight: 600; letter-spacing: 0.04em; line-height: 1;
    border: 1px solid var(--ink); color: var(--ink); background: var(--paper-2);
    transition: all 120ms ease; white-space: nowrap;
  }
  .pf-action-btn:hover { background: var(--ink); color: var(--paper-2); }
  .pf-action-btn.primary { background: var(--vermillion); color: white; border-color: var(--vermillion); }
  .pf-action-btn.primary:hover { background: var(--ink); border-color: var(--ink); }
  .pf-action-btn.ghost { border-color: var(--rule-2); color: var(--ink-2); background: transparent; }
  .pf-action-btn.ghost:hover { border-color: var(--ink); background: var(--paper-2); color: var(--ink); }

  /* Filter chip */
  .pf-chip {
    display:inline-flex; align-items:center; gap: 6px; padding: 6px 12px; font-size: 12px; font-weight: 600;
    letter-spacing: 0.06em; border: 1px solid var(--rule-2); color: var(--ink-2); background: var(--paper-2); cursor: pointer;
  }
  .pf-chip[aria-selected="true"] { background: var(--ink); color: var(--paper-2); border-color: var(--ink); }
  .pf-chip .count { font-family:"JetBrains Mono",monospace; opacity: 0.7; font-size: 11px; margin-left: 2px; }

  /* Search input */
  .pf-search {
    display: flex; align-items: center; gap: 10px;
    border: 1.5px solid var(--ink); background: var(--paper-2); padding: 10px 14px;
    transition: border-color 120ms ease;
  }
  .pf-search:focus-within { border-color: var(--vermillion); }
  .pf-search input { flex:1; border:0; outline:0; background: transparent; font-family: inherit; font-size: 14px; color: var(--ink); }
  .pf-search input::placeholder { color: var(--ink-4); }

  /* Action queue compact */
  .pf-queue-col { background: var(--paper-2); border: 1px solid var(--rule); }
  .pf-queue-col-h { padding: 10px 14px; border-bottom: 1px solid var(--rule); display:flex; justify-content:space-between; align-items:center; gap: 10px; }
  .pf-queue-col-h .stage { font-family:"Bricolage Grotesque",sans-serif; font-size: 14px; font-weight: 700; letter-spacing: -0.01em; }
  .pf-queue-card { padding: 12px 14px; border-top: 1px dashed var(--rule); }
  .pf-queue-card:first-child { border-top: 0; }
  .pf-queue-empty { padding: 28px 14px; text-align:center; color: var(--ink-4); font-size: 12px; font-family:"JetBrains Mono",monospace; }

  /* Sparkline bar chart */
  .pf-bars { display:flex; align-items:flex-end; gap: 6px; height: 80px; }
  .pf-bars .bar { flex:1; background: var(--ink); position: relative; transition: background 120ms ease; }
  .pf-bars .bar.has-revenue { background: var(--vermillion); }
  .pf-bars .bar:hover { background: var(--press-blue); }
  .pf-bars .bar-day { font-family:"JetBrains Mono",monospace; font-size: 10px; color: var(--ink-4); text-align:center; margin-top: 6px; letter-spacing: -0.02em; }

  /* Greeting bar */
  .pf-greet h1 { font-family:"Bricolage Grotesque",sans-serif; font-size: clamp(36px, 6vw, 64px); font-weight: 700; letter-spacing: -0.035em; line-height: 0.95; }
  .pf-greet .sub { color: var(--ink-3); font-size: 15px; margin-top: 14px; }
  .pf-greet .clock { font-family:"JetBrains Mono",monospace; font-size: 13px; color: var(--ink-2); letter-spacing: 0.04em; }

  /* Banner overrides for the cream paper */
  .pf-banner { padding: 14px 16px; border: 1px solid currentColor; background: rgba(255,255,255,0.5); display:flex; align-items:center; gap: 12px; }
  .pf-banner.amber { color: #92400e; }
  .pf-banner.red   { color: var(--vermillion-2); }
  .pf-banner.green { color: var(--moss); }

  /* Top nav recolor inside .press-floor */
  .press-floor .pf-nav { background: var(--paper); border-bottom: 1.5px solid var(--ink); }
  .press-floor .pf-nav a { color: var(--ink-2); font-weight: 500; }
  .press-floor .pf-nav a:hover { color: var(--ink); }
  .press-floor .pf-nav a[data-active="1"] { color: var(--ink); border-bottom: 2px solid var(--vermillion); }
  .press-floor .pf-nav-shop { font-family:"Bricolage Grotesque",sans-serif; font-weight: 600; font-size: 14px; }

  /* Stagger fade-in on load */
  @keyframes pf-rise { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: none; } }
  .pf-fade > * { opacity: 0; animation: pf-rise 480ms ease forwards; }
  .pf-fade > *:nth-child(1) { animation-delay: 0ms; }
  .pf-fade > *:nth-child(2) { animation-delay: 60ms; }
  .pf-fade > *:nth-child(3) { animation-delay: 120ms; }
  .pf-fade > *:nth-child(4) { animation-delay: 180ms; }
  .pf-fade > *:nth-child(5) { animation-delay: 240ms; }
  .pf-fade > *:nth-child(6) { animation-delay: 300ms; }
  .pf-fade > *:nth-child(7) { animation-delay: 360ms; }

  /* Mobile collapse for tenant row */
  @media (max-width: 1024px) {
    .pf-tenant-row { grid-template-columns: 44px 1fr; row-gap: 8px; }
    .pf-tenant-row .pf-tenant-stat,
    .pf-tenant-row .pf-tenant-actions { grid-column: 1 / -1; }
    .pf-tenant-row .pf-tenant-actions { display: flex; gap: 8px; flex-wrap: wrap; }
    .pf-tenant-row .pf-tenant-stat { display: flex; gap: 18px; }
  }

  /* RTL: keep numbers LTR. Search icon / dot on the other side. */
  [dir="rtl"] .press-floor .mono,
  [dir="rtl"] .press-floor .num,
  [dir="rtl"] .press-floor .num-xl { direction: ltr; unicode-bidi: isolate; }
</style>

<div class="press-floor">

    <!-- Top Nav -->
    <nav class="pf-nav fixed top-0 left-0 right-0 z-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 flex items-center justify-between h-16">
            <div class="flex items-center gap-4">
                <a href="<?= getBasePath() ?>" class="flex items-center gap-2">
                    <img src="<?= getBasePath() ?>assets/images/logo.svg" alt="Cardify" class="h-7 w-auto">
                </a>
                <span class="pf-reg" aria-hidden="true"></span>
                <span class="pf-nav-shop"><?= sanitize($printShop['name']) ?></span>
                <?php if ($isInternalProvider): ?>
                <span class="pf-stamp tone-vermillion ml-1"><?= htmlspecialchars(t('printshopdash.badge_internal_provider')) ?></span>
                <?php elseif (!empty($printShop['is_verified']) || !empty($printShop['verified'])): ?>
                <span class="pf-stamp tone-blue ml-1"><?= htmlspecialchars(t('printshopdash.nav_verified')) ?></span>
                <?php endif; ?>
            </div>
            <div class="flex items-center gap-5 text-sm">
                <a href="dashboard.php" data-active="1"><i class="fa-solid fa-chart-pie mr-1"></i><span class="hidden sm:inline"><?= htmlspecialchars(t('printshopdash.nav_dashboard')) ?></span></a>
                <a href="orders.php"><i class="fa-solid fa-box mr-1"></i><span class="hidden sm:inline"><?= htmlspecialchars(t('printshopdash.nav_orders')) ?></span></a>
                <?php if ($isInternalProvider): ?>
                <a href="clients.php" class="hidden md:inline-flex"><i class="fa-solid fa-building mr-1"></i><?= htmlspecialchars(t('printshopdash.nav_clients')) ?></a>
                <?php endif; ?>
                <a href="analytics.php" class="hidden md:inline-flex"><i class="fa-solid fa-chart-line mr-1"></i><?= htmlspecialchars(t('printshopdash.nav_analytics')) ?></a>
                <a href="credit-accounts.php" class="hidden md:inline-flex"><i class="fa-solid fa-building-columns mr-1"></i><?= htmlspecialchars(t('printshopdash.nav_credit')) ?></a>
                <a href="settings.php" aria-label="<?= htmlspecialchars(t('printshopdash.nav_settings')) ?>"><i class="fa-solid fa-cog"></i></a>
            </div>
        </div>
    </nav>

    <main class="pt-24 pb-20 px-4 sm:px-6 lg:px-8 max-w-7xl mx-auto pf-fade">

        <!-- Status banners -->
        <?php if ($printShop['status'] === 'pending'): ?>
        <div class="pf-banner amber mb-6"><i class="fa-solid fa-clock"></i><div><strong class="display"><?= htmlspecialchars(t('printshopdash.pending_approval_h')) ?>.</strong> <?= htmlspecialchars(t('printshopdash.pending_approval_b')) ?></div></div>
        <?php elseif ($printShop['status'] === 'suspended'): ?>
        <div class="pf-banner red mb-6"><i class="fa-solid fa-ban"></i><div><strong class="display"><?= htmlspecialchars(t('printshopdash.suspended_h')) ?>.</strong> <?= htmlspecialchars(t('printshopdash.suspended_b')) ?></div></div>
        <?php endif; ?>
        <?php if ($message): ?>
        <div class="pf-banner <?= $messageType === 'success' ? 'green' : 'red' ?> mb-6">
            <i class="fa-solid <?= $messageType === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i>
            <span><?= sanitize($message) ?></span>
        </div>
        <?php endif; ?>

        <!-- 1. Greeting / hero -->
        <section class="pf-greet pf-cropmarks pf-paper p-7 sm:p-10 mb-8" aria-label="Operator overview">
            <div class="pf-cm-tr"></div><div class="pf-cm-br"></div>
            <div class="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-6">
                <div>
                    <p class="ucase mono mb-3" style="color: var(--vermillion); font-size: 11px;"><?= htmlspecialchars(date('l, M j, Y')) ?> &nbsp;&middot;&nbsp; <?= htmlspecialchars(t('printshopdash.console_label')) ?></p>
                    <h1><?= htmlspecialchars(strtr(t($greetingKey), [':name' => $operatorName])) ?>.</h1>
                    <p class="sub max-w-xl">
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
                <div class="flex flex-col items-start lg:items-end gap-3">
                    <span class="clock"><span id="pfClock"><?= date('H:i:s') ?></span> &nbsp;GMT+4</span>
                    <?php if ($isInternalProvider): ?>
                    <a href="#tenant-console" class="pf-action-btn primary">
                        <i class="fa-solid fa-arrow-down"></i>
                        <span><?= htmlspecialchars(t('printshopdash.jump_to_tenants')) ?></span>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <!-- 2. Operational tiles, only the four that matter -->
        <section class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-10">
            <div class="pf-stat <?= $kpis['awaiting_action'] > 0 ? 'pf-stat-alert' : '' ?>">
                <span class="badge-corner">01</span>
                <div class="num-xl"><?= (int) $kpis['awaiting_action'] ?></div>
                <div class="lbl"><?= htmlspecialchars(t('printshopdash.kpi_awaiting_h')) ?></div>
            </div>
            <div class="pf-stat">
                <span class="badge-corner">02</span>
                <div class="num-xl"><?= (int) $kpis['in_production'] ?></div>
                <div class="lbl"><?= htmlspecialchars(t('printshopdash.kpi_in_production_h')) ?></div>
            </div>
            <div class="pf-stat">
                <span class="badge-corner">03</span>
                <div class="num-xl"><?= (int) $kpis['shipped_this_week'] ?></div>
                <div class="lbl"><?= htmlspecialchars(t('printshopdash.kpi_shipped_week_h')) ?></div>
            </div>
            <div class="pf-stat">
                <span class="badge-corner">04</span>
                <div class="num-xl"><?= Currency::formatHtml((float) $kpis['revenue_30d'], $currency, 'sm') ?></div>
                <div class="lbl"><?= htmlspecialchars(t('printshopdash.kpi_revenue_30d_h')) ?></div>
                <?php if ($revenue30dDelta): ?>
                <div class="delta" style="color: <?= $revenue30dDelta['sign'] === 'up' ? 'var(--moss)' : ($revenue30dDelta['sign'] === 'down' ? 'var(--vermillion)' : 'var(--ink-3)') ?>;">
                    <?php
                    if ($revenue30dDelta['sign'] === 'up')   echo '+' . number_format($revenue30dDelta['pct'], 0) . '% / 30d';
                    elseif ($revenue30dDelta['sign'] === 'down') echo number_format($revenue30dDelta['pct'], 0) . '% / 30d';
                    elseif ($revenue30dDelta['sign'] === 'flat') echo '~ flat / 30d';
                    elseif ($revenue30dDelta['sign'] === 'new')  echo '+ new revenue';
                    ?>
                </div>
                <?php endif; ?>
            </div>
        </section>

        <!-- 3. TENANT CONSOLE: the operational core -->
        <?php if ($isInternalProvider): ?>
        <section id="tenant-console" class="mb-12" x-data="tenantConsole()">
            <div class="pf-section-rule">
                <div>
                    <h2><?= htmlspecialchars(t('printshopdash.tenants_h')) ?></h2>
                    <p class="lede"><?= htmlspecialchars(t('printshopdash.tenants_lede')) ?></p>
                </div>
                <div class="meta">
                    <span class="num"><?= count($tenants) ?></span> <?= htmlspecialchars(t('printshopdash.tenants_total')) ?>
                </div>
            </div>

            <!-- Search + filters -->
            <div class="grid grid-cols-1 lg:grid-cols-[1fr_auto] gap-3 mb-3">
                <div class="pf-search">
                    <i class="fa-solid fa-magnifying-glass" style="color: var(--ink-3);"></i>
                    <input type="text" x-model="q" @input="apply()" placeholder="<?= htmlspecialchars(t('printshopdash.tenants_search_ph')) ?>" autocomplete="off">
                    <span class="mono" style="color: var(--ink-4); font-size: 11px;" x-show="q"
                          x-text="visible + ' / <?= count($tenants) ?>'"></span>
                </div>
                <div class="flex flex-wrap gap-2 items-center">
                    <button type="button" class="pf-chip" :aria-selected="filter === 'all'"   @click="filter='all';   apply()"><?= htmlspecialchars(t('printshopdash.tenants_filter_all')) ?> <span class="count num"><?= count($tenants) ?></span></button>
                    <button type="button" class="pf-chip" :aria-selected="filter === 'active'"@click="filter='active';apply()"><?= htmlspecialchars(t('printshopdash.tenants_filter_active')) ?> <span class="count num"><?= $tenantStats['active30'] ?></span></button>
                    <button type="button" class="pf-chip" :aria-selected="filter === 'dormant'"@click="filter='dormant';apply()"><?= htmlspecialchars(t('printshopdash.tenants_filter_dormant')) ?> <span class="count num"><?= $tenantStats['dormant60'] ?></span></button>
                    <button type="button" class="pf-chip" :aria-selected="filter === 'unprinted'"@click="filter='unprinted';apply()"><?= htmlspecialchars(t('printshopdash.tenants_filter_unprinted')) ?> <span class="count num"><?= $tenantStats['unprinted'] ?></span></button>
                </div>
            </div>

            <!-- Tenant list -->
            <div class="pf-paper">
                <div class="hidden lg:grid pf-tenant-row" style="border-top:0; padding-top:8px; padding-bottom:8px; background: var(--paper);">
                    <span></span>
                    <span class="ucase" style="font-size: 10.5px; color: var(--ink-3);"><?= htmlspecialchars(t('printshopdash.tenants_col_name')) ?></span>
                    <span class="ucase" style="font-size: 10.5px; color: var(--ink-3);"><?= htmlspecialchars(t('printshopdash.tenants_col_employees')) ?></span>
                    <span class="ucase" style="font-size: 10.5px; color: var(--ink-3);"><?= htmlspecialchars(t('printshopdash.tenants_col_cards')) ?></span>
                    <span class="ucase" style="font-size: 10.5px; color: var(--ink-3);"><?= htmlspecialchars(t('printshopdash.tenants_col_last_order')) ?></span>
                    <span class="ucase" style="font-size: 10.5px; color: var(--ink-3); text-align: right;"><?= htmlspecialchars(t('printshopdash.tenants_col_actions')) ?></span>
                </div>

                <?php if (empty($tenants)): ?>
                <div class="pf-queue-empty"><?= htmlspecialchars(t('printshopdash.tenants_empty')) ?></div>
                <?php else: foreach ($tenants as $t):
                    $emp = (int) $t['employee_count'];
                    $gen = (int) $t['generated_count'];
                    $tpl = (int) $t['template_count'];
                    $ord = (int) $t['order_count'];
                    $last = $t['last_order_at'] ?? null;
                    $lastDays = $last ? (int) round((time() - strtotime($last)) / 86400) : null;

                    $isActive    = $last && ($lastDays !== null && $lastDays <= 30);
                    $isDormant   = ($last && $lastDays !== null && $lastDays >= 60) || (!$last && $emp > 0);
                    $unprinted   = ($emp > $gen);
                    $logoSrc = !empty($t['logo_path']) ? getBasePath() . ltrim((string) $t['logo_path'], '/') : null;
                    $initials = '';
                    foreach (array_slice(preg_split('/\s+/', trim((string) $t['name'])), 0, 2) as $p) { $initials .= mb_substr($p, 0, 1); }
                    $initials = mb_strtoupper($initials) ?: '?';
                ?>
                <div class="pf-tenant-row"
                     data-name="<?= htmlspecialchars(mb_strtolower($t['name'] . ' ' . ($t['slug'] ?? ''))) ?>"
                     data-active="<?= $isActive ? '1' : '0' ?>"
                     data-dormant="<?= $isDormant ? '1' : '0' ?>"
                     data-unprinted="<?= $unprinted ? '1' : '0' ?>"
                     x-ref="row">
                    <div class="pf-tenant-logo" <?= !empty($t['primary_color']) ? 'style="border-color: ' . htmlspecialchars($t['primary_color']) . '33;"' : '' ?>>
                        <?php if ($logoSrc): ?>
                            <img src="<?= htmlspecialchars($logoSrc) ?>" alt="" loading="lazy">
                        <?php else: ?>
                            <span><?= htmlspecialchars($initials) ?></span>
                        <?php endif; ?>
                    </div>
                    <div>
                        <div class="pf-tenant-name"><?= sanitize($t['name']) ?></div>
                        <div class="pf-tenant-slug">
                            <?= sanitize($t['slug'] ?? '') ?>
                            <?php if ($isActive): ?>
                                <span class="pf-stamp tone-moss ml-2"><?= htmlspecialchars(t('printshopdash.tenant_status_active')) ?></span>
                            <?php elseif ($isDormant): ?>
                                <span class="pf-stamp tone-mute ml-2"><?= htmlspecialchars(t('printshopdash.tenant_status_dormant')) ?></span>
                            <?php elseif ($emp === 0): ?>
                                <span class="pf-stamp tone-mute ml-2"><?= htmlspecialchars(t('printshopdash.tenant_status_empty')) ?></span>
                            <?php endif; ?>
                            <?php if ($tpl > 0): ?>
                                <span class="mono ml-2" style="color: var(--ink-4);">&middot; <?= $tpl ?> <?= htmlspecialchars(t('printshopdash.tenants_unit_tpl')) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="pf-tenant-stat">
                        <div class="v <?= $emp === 0 ? 'muted' : '' ?>"><?= $emp ?></div>
                        <div class="l lg:hidden"><?= htmlspecialchars(t('printshopdash.tenants_col_employees')) ?></div>
                    </div>
                    <div class="pf-tenant-stat">
                        <div class="v <?= $gen === 0 ? 'muted' : '' ?>"><?= $gen ?><?php if ($emp > 0 && $gen < $emp): ?><span style="font-size: 13px; color: var(--vermillion); font-weight:500;"> / <?= $emp ?></span><?php endif; ?></div>
                        <div class="l lg:hidden"><?= htmlspecialchars(t('printshopdash.tenants_col_cards')) ?></div>
                    </div>
                    <div class="pf-tenant-stat">
                        <div class="v mono" style="font-size: 14px; line-height: 1.3;">
                            <?php if ($last): ?>
                                <?= htmlspecialchars(date('M j, Y', strtotime($last))) ?>
                                <div class="l" style="margin-top: 2px;">
                                    <?= htmlspecialchars(strtr(t('printshopdash.tenants_orders_n'), [':n' => (string) $ord])) ?>
                                </div>
                            <?php else: ?>
                                <span class="muted" style="font-style: italic;"><?= htmlspecialchars(t('printshopdash.tenants_no_orders')) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="pf-tenant-actions flex justify-end gap-2">
                        <a href="client.php?company=<?= urlencode($t['id']) ?>" class="pf-action-btn primary">
                            <i class="fa-solid fa-id-card"></i><span><?= htmlspecialchars(t('printshopdash.tenants_btn_open')) ?></span>
                        </a>
                        <a href="client.php?company=<?= urlencode($t['id']) ?>#sheet" class="pf-action-btn ghost" title="<?= htmlspecialchars(t('printshopdash.tenants_btn_sheet')) ?>">
                            <i class="fa-solid fa-file-pdf"></i><span class="hidden xl:inline"><?= htmlspecialchars(t('printshopdash.tenants_btn_sheet')) ?></span>
                        </a>
                        <a href="client.php?company=<?= urlencode($t['id']) ?>#order" class="pf-action-btn" title="<?= htmlspecialchars(t('printshopdash.tenants_btn_order')) ?>">
                            <i class="fa-solid fa-cart-plus"></i><span class="hidden xl:inline"><?= htmlspecialchars(t('printshopdash.tenants_btn_order')) ?></span>
                        </a>
                    </div>
                </div>
                <?php endforeach; endif; ?>
            </div>

            <!-- Footer link to full clients browser -->
            <div class="flex items-center justify-between mt-3 px-1">
                <a href="clients.php" class="mono text-sm" style="color: var(--ink-2); text-decoration: underline; text-decoration-color: var(--vermillion); text-underline-offset: 4px;">
                    <?= htmlspecialchars(t('printshopdash.tenants_view_all')) ?> &rarr;
                </a>
                <a href="order-on-behalf.php" class="pf-action-btn primary">
                    <i class="fa-solid fa-plus"></i><span><?= htmlspecialchars(t('printshopdash.internal_order_on_behalf')) ?></span>
                </a>
            </div>
        </section>
        <?php endif; ?>

        <!-- 4. Action queue -->
        <section class="mb-12">
            <div class="pf-section-rule">
                <div>
                    <h2><?= htmlspecialchars(t('printshopdash.queue_h')) ?></h2>
                    <p class="lede"><?= htmlspecialchars(t('printshopdash.queue_sub')) ?></p>
                </div>
                <a href="orders.php" class="mono text-sm" style="color: var(--ink-2); text-decoration: underline; text-decoration-color: var(--vermillion); text-underline-offset: 4px;">
                    <?= htmlspecialchars(t('printshopdash.view_all')) ?> &rarr;
                </a>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-3">
                <?php foreach ($stageMeta as $stage => $meta):
                    $orders = $queue[$stage] ?? [];
                    $count = count($orders);
                    $countLabel = $count === 1
                        ? t('printshopdash.queue_count_one')
                        : strtr(t('printshopdash.queue_count_many'), [':n' => (string) $count]);
                ?>
                <div class="pf-queue-col">
                    <div class="pf-queue-col-h">
                        <span class="stage"><i class="fa-solid <?= $meta['icon'] ?> mr-1.5" style="color: var(--<?= $meta['tone'] ?>);"></i><?= htmlspecialchars(t('printshopdash.queue_stage_' . $stage)) ?></span>
                        <span class="mono" style="font-size: 11px; color: var(--ink-3);"><?= htmlspecialchars($countLabel) ?></span>
                    </div>
                    <?php if (empty($orders)): ?>
                        <div class="pf-queue-empty"><?= htmlspecialchars(t('printshopdash.queue_empty')) ?></div>
                    <?php else: foreach ($orders as $order): ?>
                        <div class="pf-queue-card">
                            <div class="flex items-start justify-between gap-2 mb-1.5">
                                <div class="min-w-0">
                                    <div class="font-semibold text-[13px]" style="color: var(--ink);"><?= sanitize($order['company_name'] ?? t('printshopdash.unknown_company')) ?></div>
                                    <div class="mono" style="font-size: 11px; color: var(--ink-4);">
                                        #<?= (int) $order['id'] ?>
                                        <?php if (!empty($order['employee_name'])): ?>
                                            &middot; <?= sanitize($order['employee_name']) ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <span class="mono" style="font-size: 12px; color: var(--ink); white-space: nowrap;"><?= Currency::formatHtml((float) $order['total'], $currency, 'xs') ?></span>
                            </div>
                            <div class="mono mb-2.5" style="font-size: 11px; color: var(--ink-3);">
                                <?= htmlspecialchars(strtr(t('printshopdash.order_meta'), [
                                    ':n'     => (string) $order['quantity'],
                                    ':paper' => ucfirst($order['paper_type'] ?? 'standard'),
                                ])) ?>
                            </div>
                            <form method="post" class="flex items-center gap-2">
                                <?= csrfField() ?>
                                <input type="hidden" name="action"   value="update_status">
                                <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                                <input type="hidden" name="status"   value="<?= htmlspecialchars($meta['next']) ?>">
                                <button type="submit" class="pf-action-btn primary flex-1">
                                    <i class="fa-solid fa-arrow-right"></i>
                                    <span><?= htmlspecialchars(t('printshopdash.queue_advance_' . $meta['next'])) ?></span>
                                </button>
                                <a href="orders.php?id=<?= (int) $order['id'] ?>" class="pf-action-btn ghost" title="<?= htmlspecialchars(t('printshopdash.queue_open')) ?>">
                                    <i class="fa-solid fa-eye"></i>
                                </a>
                            </form>
                        </div>
                    <?php endforeach; endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- 5. Revenue trend (always rendered for visual continuity, even when zero) -->
        <section class="mb-12 pf-paper p-6">
            <div class="flex items-end justify-between mb-4 gap-4 flex-wrap">
                <div>
                    <h3 class="display" style="font-size: 22px; line-height: 1; font-weight: 700; letter-spacing: -0.02em;"><?= htmlspecialchars(t('printshopdash.revenue_widget_h')) ?></h3>
                    <p class="text-xs mt-1" style="color: var(--ink-3);"><?= htmlspecialchars(t('printshopdash.revenue_widget_sub')) ?></p>
                </div>
                <a href="analytics.php" class="mono" style="font-size: 11px; color: var(--ink-2); text-decoration: underline; text-decoration-color: var(--vermillion); text-underline-offset: 3px;">
                    <?= htmlspecialchars(t('printshopdash.revenue_full_analytics')) ?> &rarr;
                </a>
            </div>
            <div class="grid grid-cols-3 gap-4 mb-5">
                <div>
                    <div class="display num" style="font-size: 26px; font-weight: 700; line-height: 1;"><?= Currency::formatHtml((float) $kpis['today_revenue'], $currency, 'xs') ?></div>
                    <div class="ucase mt-1" style="font-size: 10px; color: var(--ink-3);"><?= htmlspecialchars(t('printshopdash.revenue_period_today')) ?></div>
                </div>
                <div>
                    <div class="display num" style="font-size: 26px; font-weight: 700; line-height: 1;"><?= Currency::formatHtml((float) $revenue7d, $currency, 'xs') ?></div>
                    <div class="ucase mt-1" style="font-size: 10px; color: var(--ink-3);"><?= htmlspecialchars(t('printshopdash.revenue_period_7d')) ?></div>
                </div>
                <div>
                    <div class="display num" style="font-size: 26px; font-weight: 700; line-height: 1;"><?= Currency::formatHtml((float) $kpis['revenue_30d'], $currency, 'xs') ?></div>
                    <div class="ucase mt-1" style="font-size: 10px; color: var(--ink-3);"><?= htmlspecialchars(t('printshopdash.revenue_period_30d')) ?></div>
                </div>
            </div>
            <div class="pf-bars">
                <?php foreach ($sparkline as $date => $rev):
                    $pct = $maxDailyRev > 0 ? max(3, round($rev / $maxDailyRev * 100)) : 3;
                    $title = date('D, M j', strtotime($date)) . ' / ' . Currency::format((float) $rev, $currency);
                ?>
                <div class="flex flex-col flex-1">
                    <div class="bar <?= $rev > 0 ? 'has-revenue' : '' ?>" style="height: <?= $pct ?>%" title="<?= htmlspecialchars($title) ?>"></div>
                    <div class="bar-day"><?= htmlspecialchars(date('D', strtotime($date))) ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- 6. Two-col footer: Credit + Activity -->
        <section class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-12">

            <!-- Credit exposure -->
            <div class="pf-paper p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="display" style="font-size: 22px; line-height: 1; font-weight: 700; letter-spacing: -0.02em;"><?= htmlspecialchars(t('printshopdash.credit_risk_h')) ?></h3>
                    <a href="credit-accounts.php" class="mono" style="font-size: 11px; color: var(--ink-2); text-decoration: underline; text-decoration-color: var(--vermillion); text-underline-offset: 3px;"><?= htmlspecialchars(t('printshopdash.credit_risk_review')) ?> &rarr;</a>
                </div>
                <?php if ($creditRisk['account_count'] === 0): ?>
                    <p class="text-sm" style="color: var(--ink-3);"><?= htmlspecialchars(t('printshopdash.credit_risk_no_accounts')) ?></p>
                <?php else: ?>
                <p class="mono mb-2" style="font-size: 13px; color: var(--ink-2);">
                    <?= htmlspecialchars(strtr(t('printshopdash.credit_risk_total'), [
                        ':used'  => Currency::format((float) $kpis['outstanding_credit'], $currency),
                        ':limit' => Currency::format((float) $kpis['credit_limit_total'], $currency),
                    ])) ?>
                </p>
                <div style="height: 6px; background: var(--rule); margin-bottom: 18px;">
                    <div style="height: 100%; width: <?= $creditUtilPct ?>%; background: <?= $creditUtilPct >= 80 ? 'var(--vermillion)' : ($creditUtilPct >= 50 ? '#d97706' : 'var(--moss)') ?>;"></div>
                </div>
                <?php if (!empty($creditRisk['top_exposed'])): ?>
                <p class="ucase" style="font-size: 10px; color: var(--ink-3); margin-bottom: 8px;"><?= htmlspecialchars(t('printshopdash.credit_risk_top_exposed')) ?></p>
                <ul class="space-y-2.5">
                    <?php foreach ($creditRisk['top_exposed'] as $acct):
                        $limit = max(0.001, (float) $acct['credit_limit']);
                        $used  = (float) $acct['balance_used'];
                        $pct   = min(100, (int) round(($used / $limit) * 100));
                        $terms = preg_replace('/\D/', '', (string) $acct['payment_terms']);
                    ?>
                    <li class="flex items-center gap-3">
                        <div class="flex-1 min-w-0">
                            <div class="font-medium text-sm" style="color: var(--ink);"><?= sanitize($acct['company_name']) ?></div>
                            <div style="height: 3px; background: var(--rule); margin-top: 4px;">
                                <div style="height: 100%; width: <?= $pct ?>%; background: <?= $pct >= 80 ? 'var(--vermillion)' : ($pct >= 50 ? '#d97706' : 'var(--moss)') ?>;"></div>
                            </div>
                        </div>
                        <div class="text-right whitespace-nowrap">
                            <div class="mono font-semibold" style="font-size: 12px;"><?= $pct ?>%</div>
                            <div class="ucase" style="font-size: 9.5px; color: var(--ink-4);"><?= htmlspecialchars(strtr(t('printshopdash.credit_risk_terms'), [':n' => $terms])) ?></div>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
                <?php endif; ?>
            </div>

            <!-- Activity feed -->
            <div class="pf-paper p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="display" style="font-size: 22px; line-height: 1; font-weight: 700; letter-spacing: -0.02em;"><?= htmlspecialchars(t('printshopdash.activity_h')) ?></h3>
                </div>
                <?php if (empty($activity)): ?>
                    <p class="text-sm" style="color: var(--ink-3);"><?= htmlspecialchars(t('printshopdash.activity_empty')) ?></p>
                <?php else: ?>
                <ul class="space-y-3">
                    <?php foreach ($activity as $item):
                        $icon = $activityIcons[$item['type']] ?? 'fa-circle-info';
                        $msgKey = 'printshopdash.activity_' . $item['type'];
                        $ref = $item['ref_label'] ?: ('#' . ($item['ref_id'] ?? ''));
                        $msg = strtr(t($msgKey), [':ref' => (string) $ref]);
                        $tone = $item['type'] === 'erp_failed' ? 'var(--vermillion)' : 'var(--ink-2)';
                    ?>
                    <li class="flex items-start gap-3">
                        <i class="fa-solid <?= $icon ?> mt-1" style="color: <?= $tone ?>; font-size: 13px;"></i>
                        <div class="flex-1 min-w-0">
                            <div class="text-sm" style="color: var(--ink);"><?= sanitize($msg) ?></div>
                            <?php if (!empty($item['context'])): ?>
                            <div class="mono" style="font-size: 11px; color: var(--ink-4);"><?= sanitize($item['context']) ?></div>
                            <?php endif; ?>
                        </div>
                        <span class="mono" style="font-size: 10px; color: var(--ink-4); white-space: nowrap; padding-top: 2px;"><?= htmlspecialchars($timeAgo($item['at'])) ?></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
        </section>

        <!-- 7. Operator activity (only when 2+ ops) -->
        <?php if ($opActivity !== null && !empty($opActivity)): ?>
        <section class="mb-12">
            <div class="pf-section-rule">
                <div>
                    <h2><?= htmlspecialchars(t('printshopdash.operator_activity_h')) ?></h2>
                </div>
                <a href="operators.php" class="mono text-sm" style="color: var(--ink-2); text-decoration: underline; text-decoration-color: var(--vermillion); text-underline-offset: 4px;">
                    <?= htmlspecialchars(t('printshopdash.operator_view_team')) ?> &rarr;
                </a>
            </div>
            <div class="pf-paper">
                <ul class="divide-y" style="--tw-divide-opacity:1; border-color: var(--rule);">
                    <?php foreach ($opActivity as $op):
                        $initials = '';
                        $parts = preg_split('/\s+/', trim((string) $op['name']));
                        foreach (array_slice($parts, 0, 2) as $p) { $initials .= mb_substr($p, 0, 1); }
                        $initials = mb_strtoupper($initials) ?: '?';
                        $count = (int) $op['orders_week'];
                        $countLabel = $count === 0
                            ? t('printshopdash.operator_no_activity')
                            : ($count === 1 ? t('printshopdash.operator_orders_unit_one')
                                            : strtr(t('printshopdash.operator_orders_unit'), [':n' => (string) $count]));
                    ?>
                    <li class="flex items-center gap-3 p-4" style="border-top: 1px solid var(--rule);">
                        <span style="width: 36px; height: 36px; border: 1px solid var(--ink); display:flex; align-items:center; justify-content:center; font-family:'Bricolage Grotesque',sans-serif; font-weight:700; font-size: 13px;"><?= htmlspecialchars($initials) ?></span>
                        <div class="flex-1 min-w-0">
                            <div class="font-semibold text-sm"><?= sanitize($op['name']) ?></div>
                            <div class="mono" style="font-size: 11px; color: var(--ink-4);"><?= htmlspecialchars($countLabel) ?> &middot; <?= Currency::format((float) $op['revenue_week'], $currency) ?></div>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </section>
        <?php endif; ?>

    </main>
</div>

<script>
    // Live clock
    (function () {
        var el = document.getElementById('pfClock');
        if (!el) return;
        function tick() {
            var d = new Date();
            var pad = function (n) { return n < 10 ? '0' + n : n; };
            el.textContent = pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds());
        }
        setInterval(tick, 1000);
    })();

    // Tenant console search + filter (Alpine x-data)
    function tenantConsole() {
        return {
            q: '',
            filter: 'all',
            visible: 0,
            init() { this.apply(); },
            apply() {
                var q = (this.q || '').toLowerCase().trim();
                var f = this.filter;
                var rows = document.querySelectorAll('#tenant-console .pf-tenant-row[data-name]');
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
            }
        };
    }
</script>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
