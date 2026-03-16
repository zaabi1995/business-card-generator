<?php
/**
 * Print Shop Analytics - Revenue, order trends, and performance metrics
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/PrintShop.php';
require_once INCLUDES_DIR . '/Currency.php';

Auth::requireLogin();
$user = Auth::getCurrentUser();

if ($user['role'] !== 'print_shop' && $user['role'] !== 'super_admin') {
    header('Location: ' . getBasePath() . 'login.php');
    exit;
}

$printShop = PrintShop::getByUserId($user['id']);
if (!$printShop && $user['role'] !== 'super_admin') {
    header('Location: ' . getBasePath() . 'printshop/register.php');
    exit;
}

if ($user['role'] === 'super_admin' && isset($_GET['shop'])) {
    $printShop = PrintShop::getById((int)$_GET['shop']);
}

if (!$printShop) {
    header('Location: ' . getBasePath() . 'admin/print_shops.php');
    exit;
}

$shopId = $printShop['id'];
$db = Database::getInstance();

// Period: default last 12 months
$period = $_GET['period'] ?? '12m';
$periodMap = ['3m' => 3, '6m' => 6, '12m' => 12, '24m' => 24];
$months = $periodMap[$period] ?? 12;

// ---- Revenue & orders by month ----
$monthlyData = $db->fetchAll("
    SELECT
        DATE_FORMAT(created_at, '%Y-%m') AS month,
        COUNT(*) AS total_orders,
        SUM(CASE WHEN status != 'cancelled' THEN total ELSE 0 END) AS revenue,
        SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) AS delivered,
        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled
    FROM print_orders
    WHERE print_shop_id = :shop
      AND created_at >= DATE_SUB(NOW(), INTERVAL :months MONTH)
    GROUP BY month
    ORDER BY month ASC
", ['shop' => $shopId, 'months' => $months]);

// ---- Status breakdown ----
$statusBreakdown = $db->fetchAll("
    SELECT status, COUNT(*) AS cnt, SUM(total) AS revenue
    FROM print_orders
    WHERE print_shop_id = :shop
      AND created_at >= DATE_SUB(NOW(), INTERVAL :months MONTH)
    GROUP BY status
    ORDER BY cnt DESC
", ['shop' => $shopId, 'months' => $months]);

// ---- Top customers ----
$topCustomers = $db->fetchAll("
    SELECT
        c.name AS company_name,
        COUNT(po.id) AS order_count,
        SUM(po.total) AS total_spent,
        MAX(po.created_at) AS last_order
    FROM print_orders po
    LEFT JOIN companies c ON c.id = po.company_id
    WHERE po.print_shop_id = :shop
      AND po.status != 'cancelled'
      AND po.created_at >= DATE_SUB(NOW(), INTERVAL :months MONTH)
    GROUP BY po.company_id, c.name
    ORDER BY total_spent DESC
    LIMIT 10
", ['shop' => $shopId, 'months' => $months]);

// ---- Paper type breakdown ----
$paperBreakdown = $db->fetchAll("
    SELECT paper_type, COUNT(*) AS cnt, SUM(quantity) AS total_qty
    FROM print_orders
    WHERE print_shop_id = :shop
      AND status != 'cancelled'
      AND created_at >= DATE_SUB(NOW(), INTERVAL :months MONTH)
    GROUP BY paper_type
    ORDER BY cnt DESC
", ['shop' => $shopId, 'months' => $months]);

// ---- Summary KPIs ----
$kpis = $db->fetchOne("
    SELECT
        COUNT(*) AS total_orders,
        SUM(CASE WHEN status != 'cancelled' THEN total ELSE 0 END) AS total_revenue,
        SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) AS completed,
        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled,
        AVG(CASE WHEN status != 'cancelled' THEN total ELSE NULL END) AS avg_order_value,
        SUM(CASE WHEN status != 'cancelled' THEN quantity ELSE 0 END) AS total_cards
    FROM print_orders
    WHERE print_shop_id = :shop
      AND created_at >= DATE_SUB(NOW(), INTERVAL :months MONTH)
", ['shop' => $shopId, 'months' => $months]);

$currency = $printShop['currency'] ?? 'OMR';

// Build chart data
$chartMonths   = array_column($monthlyData, 'month');
$chartRevenue  = array_column($monthlyData, 'revenue');
$chartOrders   = array_column($monthlyData, 'total_orders');

$pageTitle = 'Analytics - ' . $printShop['name'];
$bodyClass = 'bg-gray-50';
require_once INCLUDES_DIR . '/ui-header.php';
?>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

<div class="min-h-screen">
    <!-- Top Nav -->
    <nav class="bg-white border-b border-gray-200 fixed top-0 left-0 right-0 z-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-16">
                <div class="flex items-center gap-4">
                    <a href="<?= getBasePath() ?>" class="flex items-center gap-2">
                        <img src="<?= getBasePath() ?>assets/images/logo.svg" alt="Cardify" class="h-8 w-auto">
                    </a>
                    <span class="text-gray-300">|</span>
                    <a href="dashboard.php" class="font-semibold text-gray-900 hover:text-blue-600">
                        <?= sanitize($printShop['name']) ?>
                    </a>
                </div>
                <div class="flex items-center gap-4 text-sm">
                    <a href="dashboard.php" class="text-gray-500 hover:text-gray-700"><i class="fa-solid fa-chart-pie mr-1"></i>Dashboard</a>
                    <a href="orders.php" class="text-gray-500 hover:text-gray-700"><i class="fa-solid fa-box mr-1"></i>Orders</a>
                    <a href="analytics.php" class="text-blue-600 font-medium"><i class="fa-solid fa-chart-line mr-1"></i>Analytics</a>
                    <a href="credit-accounts.php" class="text-gray-500 hover:text-gray-700"><i class="fa-solid fa-building-columns mr-1"></i>Credit</a>
                    <a href="settings.php" class="text-gray-500 hover:text-gray-700"><i class="fa-solid fa-cog"></i></a>
                    <a href="<?= getBasePath() ?>logout.php" class="text-gray-500 hover:text-red-600"><i class="fa-solid fa-sign-out-alt"></i></a>
                </div>
            </div>
        </div>
    </nav>

    <div class="pt-20 pb-12 px-4 sm:px-6 lg:px-8 max-w-7xl mx-auto">

        <!-- Header + period selector -->
        <div class="flex items-center justify-between mb-6">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">Analytics</h1>
                <p class="text-gray-500">Revenue and performance overview</p>
            </div>
            <div class="flex items-center gap-2">
                <?php foreach (['3m' => '3 Months', '6m' => '6 Months', '12m' => '12 Months', '24m' => '2 Years'] as $val => $label): ?>
                <a href="?period=<?= $val ?>" class="px-3 py-1.5 rounded-lg text-sm font-medium <?= $period === $val ? 'bg-blue-600 text-white' : 'bg-white border border-gray-200 text-gray-600 hover:bg-gray-50' ?>">
                    <?= $label ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- KPI Cards -->
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4 mb-6">
            <?php
            $kpiCards = [
                ['label' => 'Total Revenue', 'value' => number_format($kpis['total_revenue'] ?? 0, 3) . ' ' . $currency, 'icon' => 'fa-coins', 'color' => 'blue'],
                ['label' => 'Total Orders', 'value' => number_format($kpis['total_orders'] ?? 0), 'icon' => 'fa-box', 'color' => 'purple'],
                ['label' => 'Completed', 'value' => number_format($kpis['completed'] ?? 0), 'icon' => 'fa-circle-check', 'color' => 'green'],
                ['label' => 'Cancelled', 'value' => number_format($kpis['cancelled'] ?? 0), 'icon' => 'fa-circle-xmark', 'color' => 'red'],
                ['label' => 'Avg Order Value', 'value' => number_format($kpis['avg_order_value'] ?? 0, 3) . ' ' . $currency, 'icon' => 'fa-chart-bar', 'color' => 'amber'],
                ['label' => 'Cards Printed', 'value' => number_format($kpis['total_cards'] ?? 0), 'icon' => 'fa-id-card', 'color' => 'teal'],
            ];
            $colorMap = [
                'blue'   => 'bg-blue-50 text-blue-600',
                'purple' => 'bg-purple-50 text-purple-600',
                'green'  => 'bg-green-50 text-green-600',
                'red'    => 'bg-red-50 text-red-600',
                'amber'  => 'bg-amber-50 text-amber-600',
                'teal'   => 'bg-teal-50 text-teal-600',
            ];
            foreach ($kpiCards as $card):
            ?>
            <div class="bg-white rounded-xl border border-gray-200 p-4 shadow-sm">
                <div class="w-10 h-10 rounded-xl <?= $colorMap[$card['color']] ?> flex items-center justify-center mb-3">
                    <i class="fa-solid <?= $card['icon'] ?>"></i>
                </div>
                <p class="text-xl font-bold text-gray-900 leading-tight"><?= $card['value'] ?></p>
                <p class="text-xs text-gray-500 mt-1"><?= $card['label'] ?></p>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Revenue Chart -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
            <div class="lg:col-span-2 bg-white rounded-xl border border-gray-200 shadow-sm p-6">
                <h2 class="text-base font-semibold text-gray-900 mb-4">Revenue Over Time</h2>
                <?php if (empty($monthlyData)): ?>
                <div class="flex items-center justify-center h-48 text-gray-400">
                    <div class="text-center"><i class="fa-solid fa-chart-line text-4xl mb-2"></i><p>No data yet</p></div>
                </div>
                <?php else: ?>
                <canvas id="revenueChart" height="120"></canvas>
                <?php endif; ?>
            </div>

            <!-- Status Breakdown -->
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
                <h2 class="text-base font-semibold text-gray-900 mb-4">Order Status</h2>
                <?php if (empty($statusBreakdown)): ?>
                <div class="flex items-center justify-center h-48 text-gray-400 text-sm">No data</div>
                <?php else: ?>
                <canvas id="statusChart" height="200"></canvas>
                <div class="mt-4 space-y-2">
                    <?php
                    $statusColors2 = [
                        'pending'    => '#6b7280',
                        'confirmed'  => '#3b82f6',
                        'processing' => '#8b5cf6',
                        'printing'   => '#f59e0b',
                        'shipped'    => '#06b6d4',
                        'delivered'  => '#10b981',
                        'cancelled'  => '#ef4444',
                    ];
                    foreach ($statusBreakdown as $row):
                        $clr = $statusColors2[$row['status']] ?? '#9ca3af';
                    ?>
                    <div class="flex items-center justify-between text-sm">
                        <div class="flex items-center gap-2">
                            <span class="w-3 h-3 rounded-full" style="background:<?= $clr ?>"></span>
                            <span class="text-gray-700"><?= ucfirst($row['status']) ?></span>
                        </div>
                        <span class="font-medium text-gray-900"><?= number_format($row['cnt']) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Orders Volume Chart -->
        <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6 mb-6">
            <h2 class="text-base font-semibold text-gray-900 mb-4">Order Volume by Month</h2>
            <?php if (empty($monthlyData)): ?>
            <div class="flex items-center justify-center h-32 text-gray-400 text-sm">No data</div>
            <?php else: ?>
            <canvas id="ordersChart" height="80"></canvas>
            <?php endif; ?>
        </div>

        <!-- Bottom row: Top Customers + Paper Types -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

            <!-- Top Customers -->
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-100">
                    <h2 class="text-base font-semibold text-gray-900">Top Customers</h2>
                </div>
                <?php if (empty($topCustomers)): ?>
                <div class="p-6 text-center text-gray-400 text-sm">No orders yet</div>
                <?php else: ?>
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-xs font-semibold text-gray-500 uppercase">
                        <tr>
                            <th class="px-6 py-3 text-left">Company</th>
                            <th class="px-6 py-3 text-right">Orders</th>
                            <th class="px-6 py-3 text-right">Revenue</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php foreach ($topCustomers as $i => $cust): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-3">
                                <div class="flex items-center gap-2">
                                    <span class="w-6 h-6 rounded-full bg-blue-100 text-blue-600 text-xs font-bold flex items-center justify-center"><?= $i + 1 ?></span>
                                    <span class="font-medium text-gray-900"><?= sanitize($cust['company_name'] ?? 'Unknown') ?></span>
                                </div>
                            </td>
                            <td class="px-6 py-3 text-right text-gray-700"><?= number_format($cust['order_count']) ?></td>
                            <td class="px-6 py-3 text-right font-medium text-gray-900"><?= number_format($cust['total_spent'], 3) ?> <?= $currency ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>

            <!-- Paper Type & Finish Breakdown -->
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-100">
                    <h2 class="text-base font-semibold text-gray-900">Paper Types</h2>
                </div>
                <?php if (empty($paperBreakdown)): ?>
                <div class="p-6 text-center text-gray-400 text-sm">No orders yet</div>
                <?php else: ?>
                <div class="p-6 space-y-4">
                    <?php
                    $maxQty = max(array_column($paperBreakdown, 'total_qty') ?: [1]);
                    $barColors = ['#3b82f6','#8b5cf6','#10b981','#f59e0b','#ef4444','#06b6d4'];
                    foreach ($paperBreakdown as $i => $paper):
                        $pct = $maxQty > 0 ? round(($paper['total_qty'] / $maxQty) * 100) : 0;
                        $clr = $barColors[$i % count($barColors)];
                    ?>
                    <div>
                        <div class="flex justify-between text-sm mb-1">
                            <span class="font-medium text-gray-700"><?= ucfirst($paper['paper_type'] ?? 'Unknown') ?></span>
                            <span class="text-gray-500"><?= number_format($paper['total_qty']) ?> cards &bull; <?= number_format($paper['cnt']) ?> orders</span>
                        </div>
                        <div class="h-2 bg-gray-100 rounded-full overflow-hidden">
                            <div class="h-2 rounded-full" style="width:<?= $pct ?>%;background:<?= $clr ?>"></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

    </div>
</div>

<script>
<?php if (!empty($monthlyData)): ?>
const months = <?= json_encode(array_map(function($m) { return date('M Y', strtotime($m . '-01')); }, $chartMonths)) ?>;
const revenueData = <?= json_encode(array_map('floatval', $chartRevenue)) ?>;
const ordersData  = <?= json_encode(array_map('intval', $chartOrders)) ?>;

// Revenue chart
new Chart(document.getElementById('revenueChart'), {
    type: 'line',
    data: {
        labels: months,
        datasets: [{
            label: 'Revenue (<?= $currency ?>)',
            data: revenueData,
            borderColor: '#3b82f6',
            backgroundColor: 'rgba(59,130,246,0.08)',
            borderWidth: 2,
            tension: 0.4,
            fill: true,
            pointBackgroundColor: '#3b82f6',
            pointRadius: 4
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: {
            y: { beginAtZero: true, grid: { color: '#f3f4f6' } },
            x: { grid: { display: false } }
        }
    }
});

// Orders volume chart
new Chart(document.getElementById('ordersChart'), {
    type: 'bar',
    data: {
        labels: months,
        datasets: [{
            label: 'Orders',
            data: ordersData,
            backgroundColor: 'rgba(139,92,246,0.7)',
            borderRadius: 6,
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: {
            y: { beginAtZero: true, grid: { color: '#f3f4f6' }, ticks: { stepSize: 1 } },
            x: { grid: { display: false } }
        }
    }
});
<?php endif; ?>

<?php if (!empty($statusBreakdown)): ?>
const statusLabels = <?= json_encode(array_map(fn($r) => ucfirst($r['status']), $statusBreakdown)) ?>;
const statusCounts = <?= json_encode(array_column($statusBreakdown, 'cnt')) ?>;
const statusColorMap = {
    'Pending':'#6b7280','Confirmed':'#3b82f6','Processing':'#8b5cf6',
    'Printing':'#f59e0b','Shipped':'#06b6d4','Delivered':'#10b981','Cancelled':'#ef4444'
};
new Chart(document.getElementById('statusChart'), {
    type: 'doughnut',
    data: {
        labels: statusLabels,
        datasets: [{
            data: statusCounts,
            backgroundColor: statusLabels.map(l => statusColorMap[l] || '#9ca3af'),
            borderWidth: 2,
            borderColor: '#fff'
        }]
    },
    options: {
        responsive: true,
        cutout: '65%',
        plugins: { legend: { display: false } }
    }
});
<?php endif; ?>
</script>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
