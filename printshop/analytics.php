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

require_once INCLUDES_DIR . '/printshop-layout.php';
printshopHeader(t('printshoppages.title_analytics', ['shop' => $printShop['name']]), 'analytics');
?>
<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
            <!-- Header + period selector -->
        <div class="flex items-center justify-between mb-6">
            <div>
                <h1 class="text-2xl font-bold text-gray-900"><?= htmlspecialchars(t("printshoppages.h1_analytics")) ?></h1>
                <p class="text-gray-500"><?= htmlspecialchars(t('printshopanalytics.page_sub')) ?></p>
            </div>
            <div class="flex items-center gap-2">
                <?php foreach (['3m' => 'period_3m', '6m' => 'period_6m', '12m' => 'period_12m', '24m' => 'period_24m'] as $val => $key): ?>
                <a href="?period=<?= $val ?>" class="px-3 py-1.5 rounded-lg text-sm font-medium <?= $period === $val ? 'bg-blue-600 text-white' : 'bg-white border border-gray-200 text-gray-600 hover:bg-gray-50' ?>">
                    <?= htmlspecialchars(t('printshopanalytics.' . $key)) ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- KPI Cards -->
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4 mb-6">
            <?php
            $kpiCards = [
                ['label' => t('printshopanalytics.kpi_total_revenue'), 'value' => number_format($kpis['total_revenue'] ?? 0, 3) . ' ' . $currency, 'icon' => 'fa-coins', 'color' => 'blue'],
                ['label' => t('printshopanalytics.kpi_total_orders'),  'value' => number_format($kpis['total_orders'] ?? 0), 'icon' => 'fa-box', 'color' => 'purple'],
                ['label' => t('printshopanalytics.kpi_completed'),     'value' => number_format($kpis['completed'] ?? 0), 'icon' => 'fa-circle-check', 'color' => 'green'],
                ['label' => t('printshopanalytics.kpi_cancelled'),     'value' => number_format($kpis['cancelled'] ?? 0), 'icon' => 'fa-circle-xmark', 'color' => 'red'],
                ['label' => t('printshopanalytics.kpi_avg_order'),     'value' => number_format($kpis['avg_order_value'] ?? 0, 3) . ' ' . $currency, 'icon' => 'fa-chart-bar', 'color' => 'amber'],
                ['label' => t('printshopanalytics.kpi_cards_printed'), 'value' => number_format($kpis['total_cards'] ?? 0), 'icon' => 'fa-id-card', 'color' => 'teal'],
            ];
            $colorMap = [
                'blue'   => 'bg-blue-100 text-blue-600',
                'purple' => 'bg-purple-100 text-purple-600',
                'green'  => 'bg-green-100 text-green-600',
                'red'    => 'bg-red-100 text-red-600',
                'amber'  => 'bg-amber-100 text-amber-600',
                'teal'   => 'bg-teal-100 text-teal-600',
            ];
            foreach ($kpiCards as $card):
            ?>
            <div class="bg-white rounded-xl p-5 border border-gray-100 shadow-sm hover:shadow-md hover:border-gray-200 transition-all">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 rounded-xl <?= $colorMap[$card['color']] ?> flex items-center justify-center flex-shrink-0">
                        <i class="fa-solid <?= $card['icon'] ?> text-lg"></i>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-2xl font-bold text-gray-900 tracking-tight leading-none tabular-nums"><?= $card['value'] ?></p>
                        <p class="text-gray-500 text-[13px] mt-2 font-medium"><?= htmlspecialchars($card['label']) ?></p>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Revenue Chart -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
            <div class="lg:col-span-2 bg-white rounded-xl border border-gray-200 shadow-sm p-6">
                <h2 class="text-base font-semibold text-gray-900 mb-4"><?= htmlspecialchars(t("printshoppages.revenue_over_time")) ?></h2>
                <?php if (empty($monthlyData)): ?>
                <div class="flex items-center justify-center h-48 text-gray-400">
                    <div class="text-center"><i class="fa-solid fa-chart-line text-4xl mb-2"></i><p><?= htmlspecialchars(t('printshopanalytics.no_data_yet')) ?></p></div>
                </div>
                <?php else: ?>
                <canvas id="revenueChart" height="120"></canvas>
                <?php endif; ?>
            </div>

            <!-- Status Breakdown -->
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
                <h2 class="text-base font-semibold text-gray-900 mb-4"><?= htmlspecialchars(t("printshoppages.order_status")) ?></h2>
                <?php if (empty($statusBreakdown)): ?>
                <div class="flex items-center justify-center h-48 text-gray-400 text-sm"><?= htmlspecialchars(t('printshopanalytics.no_data')) ?></div>
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
                            <span class="text-gray-700"><?php $stk = 'printshopanalytics.status_' . $row['status']; $stl = t($stk); echo htmlspecialchars($stl === $stk ? ucfirst($row['status']) : $stl); ?></span>
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
            <h2 class="text-base font-semibold text-gray-900 mb-4"><?= htmlspecialchars(t("printshoppages.order_volume_by_month")) ?></h2>
            <?php if (empty($monthlyData)): ?>
            <div class="flex items-center justify-center h-32 text-gray-400 text-sm"><?= htmlspecialchars(t('printshopanalytics.no_data')) ?></div>
            <?php else: ?>
            <canvas id="ordersChart" height="80"></canvas>
            <?php endif; ?>
        </div>

        <!-- Bottom row: Top Customers + Paper Types -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

            <!-- Top Customers -->
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-100">
                    <h2 class="text-base font-semibold text-gray-900"><?= htmlspecialchars(t("printshoppages.top_customers")) ?></h2>
                </div>
                <?php if (empty($topCustomers)): ?>
                <div class="p-6 text-center text-gray-400 text-sm"><?= htmlspecialchars(t('printshopanalytics.no_orders_yet')) ?></div>
                <?php else: ?>
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-xs font-semibold text-gray-500 uppercase">
                        <tr>
                            <th class="px-6 py-3 text-left"><?= htmlspecialchars(t('printshopanalytics.col_company')) ?></th>
                            <th class="px-6 py-3 text-right"><?= htmlspecialchars(t('printshopanalytics.col_orders')) ?></th>
                            <th class="px-6 py-3 text-right"><?= htmlspecialchars(t('printshopanalytics.col_revenue')) ?></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php foreach ($topCustomers as $i => $cust): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-3">
                                <div class="flex items-center gap-2">
                                    <span class="w-6 h-6 rounded-full bg-blue-100 text-blue-600 text-xs font-bold flex items-center justify-center"><?= $i + 1 ?></span>
                                    <span class="font-medium text-gray-900"><?= sanitize($cust['company_name'] ?? t('printshopanalytics.unknown_company')) ?></span>
                                </div>
                            </td>
                            <td class="px-6 py-3 text-right text-gray-700 tabular-nums"><?= number_format($cust['order_count']) ?></td>
                            <td class="px-6 py-3 text-right font-medium text-gray-900 tabular-nums"><?= number_format($cust['total_spent'], 3) ?> <?= $currency ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>

            <!-- Paper Type & Finish Breakdown -->
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-100">
                    <h2 class="text-base font-semibold text-gray-900"><?= htmlspecialchars(t("printshoppages.paper_types")) ?></h2>
                </div>
                <?php if (empty($paperBreakdown)): ?>
                <div class="p-6 text-center text-gray-400 text-sm"><?= htmlspecialchars(t('printshopanalytics.no_orders_yet')) ?></div>
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
                            <span class="font-medium text-gray-700"><?= htmlspecialchars(ucfirst($paper['paper_type'] ?? t('printshopanalytics.unknown_paper'))) ?></span>
                            <span class="text-gray-500"><?= htmlspecialchars(strtr(t('printshopanalytics.paper_meta'), [':n' => number_format($paper['total_qty']), ':orders' => number_format($paper['cnt'])])) ?></span>
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
            label: <?= json_encode(str_replace(':cur', $currency, t('printshopanalytics.series_revenue')), JSON_UNESCAPED_UNICODE) ?>,
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
            label: <?= json_encode(t('printshopanalytics.series_orders'), JSON_UNESCAPED_UNICODE) ?>,
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
<?php
$labelArr = [];
$colorArr = [];
$colorByStatus = [
    'pending' => '#6b7280', 'confirmed' => '#3b82f6', 'processing' => '#8b5cf6',
    'printing' => '#f59e0b', 'shipped' => '#06b6d4', 'delivered' => '#10b981', 'cancelled' => '#ef4444',
];
foreach ($statusBreakdown as $r) {
    $stk = 'printshopanalytics.status_' . $r['status']; $stl = t($stk);
    $labelArr[] = $stl === $stk ? ucfirst($r['status']) : $stl;
    $colorArr[] = $colorByStatus[$r['status']] ?? '#9ca3af';
}
?>
const statusLabels = <?= json_encode($labelArr, JSON_UNESCAPED_UNICODE) ?>;
const statusCounts = <?= json_encode(array_column($statusBreakdown, 'cnt')) ?>;
const statusBgColors = <?= json_encode($colorArr) ?>;
new Chart(document.getElementById('statusChart'), {
    type: 'doughnut',
    data: {
        labels: statusLabels,
        datasets: [{
            data: statusCounts,
            backgroundColor: statusBgColors,
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
<?php printshopFooter(); ?>
