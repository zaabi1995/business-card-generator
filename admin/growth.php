<?php
/**
 * Growth Dashboard — per-company adoption & event telemetry
 *
 * URL: /admin/growth.php
 *
 * Goal: one page that tells the truth about what's actually used.
 *  - Top-line KPIs (active cards, events 7d vs prev, new leads, viral clicks, conversion)
 *  - Section adoption (% of active cards with each section ENABLED)
 *  - Event-type breakdown (30d line chart)
 *  - Top performing cards (sortable table)
 *  - Funnel (5 stages)
 *  - Feature / migration health
 *
 * Scope:
 *  - Regular user  -> 302 login
 *  - company_admin -> auto-scoped to their own company, no filter
 *  - super_admin   -> may pick a company via dropdown (null = all companies)
 */

require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/CardAnalytics.php';
require_once INCLUDES_DIR . '/admin-layout.php';

requireAdmin();

$currentRole = Auth::getCurrentRole();
$sessionCompanyId = getCurrentCompanyId();
if (!$sessionCompanyId) {
    header('Location: ' . getBasePath() . 'login.php');
    exit;
}

$db = Database::getInstance();

// --- Scope resolution ---
$isSuper = ($currentRole === 'super_admin');
$requestedCompanyId = trim((string) ($_GET['company_id'] ?? ''));
if ($isSuper) {
    $companyId = $requestedCompanyId !== '' ? $requestedCompanyId : null; // null = all
} else {
    $companyId = $sessionCompanyId;
}

// For super dropdown
$allCompanies = [];
if ($isSuper) {
    try {
        $allCompanies = $db->fetchAll(
            "SELECT id, name FROM companies ORDER BY name ASC"
        ) ?: [];
    } catch (Exception $e) {
        $allCompanies = [];
    }
}

// --- KPI period ---
$kpiDays = 7;
$chartDays = 30;

// --- Pull data ---
$activeCards    = CardAnalytics::getActiveCardCount($companyId, 30);
$eventTotals    = CardAnalytics::getEventTotals($companyId, $kpiDays);
$newLeads       = CardAnalytics::getNewLeadsCount($companyId, $kpiDays);
$viralClicks    = CardAnalytics::getViralClicks($companyId, $kpiDays);
$scanToSave     = CardAnalytics::getScanToSaveRate($companyId, $kpiDays);
$sectionData    = CardAnalytics::getSectionAdoption($companyId);
$timeSeries     = CardAnalytics::getCompanyTimeSeries($companyId, $chartDays);
$topCards       = CardAnalytics::getTopCards($companyId, $kpiDays, 20);
$funnel         = CardAnalytics::getFunnelSummary($companyId, $kpiDays);
$featureHealth  = CardAnalytics::getFeatureHealth();

$pageTitle = t('adminchrome.growth_dashboard');

// Section labels for the bar chart
$sectionLabels = [
    'bio'          => 'Bio',
    'services'     => 'Services',
    'gallery'      => 'Gallery',
    'testimonials' => 'Testimonials',
    'lead_form'    => 'Lead Form',
    'video'        => 'Video',
    'location'     => 'Location',
    'offers'       => 'Offers',
    'faq'          => 'FAQ',
    'hours'        => 'Hours',
    'products'     => 'Products',
];

function gd_pct($n, $d)
{
    if ($d <= 0) {
        return '0%';
    }
    return number_format(100 * $n / $d, 1) . '%';
}

function gd_status_pill($status)
{
    $map = [
        'OK'      => ['bg-green-50',  'text-green-700',  'ring-green-200'],
        'EMPTY'   => ['bg-amber-50',  'text-amber-700',  'ring-amber-200'],
        'MISSING' => ['bg-red-50',    'text-red-700',    'ring-red-200'],
        'ERROR'   => ['bg-red-50',    'text-red-700',    'ring-red-200'],
    ];
    $c = $map[$status] ?? ['bg-gray-50', 'text-gray-700', 'ring-gray-200'];
    return sprintf(
        '<span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium ring-1 ring-inset %s %s %s">%s</span>',
        $c[0], $c[1], $c[2], htmlspecialchars($status)
    );
}

adminHeader($pageTitle, 'growth');
?>

<div>
    <!-- Header + company filter -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
        <div>
            <p class="text-gray-600 text-sm">What's actually being used — last <?php echo (int) $kpiDays; ?> days unless noted.</p>
        </div>
        <?php if ($isSuper): ?>
        <form method="GET" class="flex items-center gap-2">
            <label for="company_id" class="text-xs font-medium text-gray-500 uppercase tracking-wider">Company</label>
            <select id="company_id" name="company_id" onchange="this.form.submit()"
                    class="px-3 py-2 bg-white border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                <option value="">All companies</option>
                <?php foreach ($allCompanies as $c): ?>
                <option value="<?php echo sanitize($c['id']); ?>"
                        <?php echo ($companyId === $c['id']) ? 'selected' : ''; ?>>
                    <?php echo sanitize($c['name']); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </form>
        <?php endif; ?>
    </div>

    <!-- Row 1: KPIs -->
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-4 mb-8">
        <!-- Active cards -->
        <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
            <div class="flex items-center justify-between mb-2">
                <p class="text-xs text-gray-500 uppercase tracking-wider">Active cards</p>
                <div class="w-8 h-8 bg-blue-50 rounded-lg flex items-center justify-center">
                    <i class="fa-solid fa-id-card text-blue-600 text-sm"></i>
                </div>
            </div>
            <p class="text-2xl font-bold text-gray-900"><?php echo number_format($activeCards); ?></p>
            <p class="text-xs text-gray-500 mt-1">Viewed in last 30d</p>
        </div>

        <!-- Events 7d vs prev -->
        <?php
        $d = $eventTotals['delta_pct'];
        $deltaColor = $d > 0 ? 'text-green-600' : ($d < 0 ? 'text-red-600' : 'text-gray-500');
        $deltaIcon  = $d > 0 ? 'fa-arrow-trend-up' : ($d < 0 ? 'fa-arrow-trend-down' : 'fa-minus');
        $deltaSign  = $d > 0 ? '+' : '';
        ?>
        <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
            <div class="flex items-center justify-between mb-2">
                <p class="text-xs text-gray-500 uppercase tracking-wider">Events 7d</p>
                <div class="w-8 h-8 bg-green-50 rounded-lg flex items-center justify-center">
                    <i class="fa-solid fa-bolt text-green-600 text-sm"></i>
                </div>
            </div>
            <p class="text-2xl font-bold text-gray-900"><?php echo number_format($eventTotals['current']); ?></p>
            <p class="text-xs <?php echo $deltaColor; ?> mt-1 flex items-center gap-1">
                <i class="fa-solid <?php echo $deltaIcon; ?>"></i>
                <?php echo $deltaSign . number_format($d, 1); ?>% vs prev 7d
            </p>
        </div>

        <!-- New leads -->
        <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
            <div class="flex items-center justify-between mb-2">
                <p class="text-xs text-gray-500 uppercase tracking-wider">New leads</p>
                <div class="w-8 h-8 bg-purple-50 rounded-lg flex items-center justify-center">
                    <i class="fa-solid fa-user-plus text-purple-600 text-sm"></i>
                </div>
            </div>
            <p class="text-2xl font-bold text-gray-900"><?php echo number_format($newLeads); ?></p>
            <p class="text-xs text-gray-500 mt-1">cardify_signup_leads (7d)</p>
        </div>

        <!-- Viral clicks -->
        <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
            <div class="flex items-center justify-between mb-2">
                <p class="text-xs text-gray-500 uppercase tracking-wider">Viral footer</p>
                <div class="w-8 h-8 bg-pink-50 rounded-lg flex items-center justify-center">
                    <i class="fa-solid fa-share-nodes text-pink-600 text-sm"></i>
                </div>
            </div>
            <p class="text-2xl font-bold text-gray-900"><?php echo number_format($viralClicks); ?></p>
            <p class="text-xs text-gray-500 mt-1">Clicks (7d)</p>
        </div>

        <!-- Scan -> Save -->
        <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
            <div class="flex items-center justify-between mb-2">
                <p class="text-xs text-gray-500 uppercase tracking-wider">Scan &rarr; Save</p>
                <div class="w-8 h-8 bg-orange-50 rounded-lg flex items-center justify-center">
                    <i class="fa-solid fa-address-book text-orange-600 text-sm"></i>
                </div>
            </div>
            <p class="text-2xl font-bold text-gray-900"><?php echo number_format($scanToSave['rate'], 1); ?>%</p>
            <p class="text-xs text-gray-500 mt-1">
                <?php echo number_format($scanToSave['saves']); ?> saves / <?php echo number_format($scanToSave['views']); ?> visitors
            </p>
        </div>
    </div>

    <!-- Row 2: Section adoption -->
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-6 mb-6">
        <div class="flex items-center justify-between mb-4">
            <div>
                <h3 class="text-lg font-semibold text-gray-900">Section adoption</h3>
                <p class="text-xs text-gray-500 mt-1">
                    Of <?php echo number_format($sectionData['total_active']); ?> active cards, % with each section enabled.
                </p>
            </div>
        </div>
        <div class="h-80"><canvas id="gdSectionBar"></canvas></div>
    </div>

    <!-- Row 3: Event type breakdown (line chart) -->
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-6 mb-6">
        <div class="flex items-center justify-between mb-4">
            <div>
                <h3 class="text-lg font-semibold text-gray-900">Event breakdown — last <?php echo (int) $chartDays; ?> days</h3>
                <p class="text-xs text-gray-500 mt-1">Views, CTA clicks, saves and viral footer clicks per day.</p>
            </div>
        </div>
        <div class="h-80"><canvas id="gdLineChart"></canvas></div>
    </div>

    <!-- Row 4: Top performing cards -->
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-6 mb-6">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-semibold text-gray-900">Top performing cards — last <?php echo (int) $kpiDays; ?>d</h3>
            <span class="text-xs text-gray-500"><?php echo count($topCards); ?> cards with activity</span>
        </div>
        <div class="overflow-x-auto">
            <table id="gdTopTable" class="w-full text-sm">
                <thead class="bg-gray-50 text-xs text-gray-500 uppercase tracking-wider">
                    <tr>
                        <th class="px-4 py-3 text-left cursor-pointer select-none" onclick="gdSort(0,'str')">Employee <i class="fa-solid fa-sort text-gray-300"></i></th>
                        <th class="px-4 py-3 text-right cursor-pointer select-none" onclick="gdSort(1,'num')">Views <i class="fa-solid fa-sort text-gray-300"></i></th>
                        <th class="px-4 py-3 text-right cursor-pointer select-none" onclick="gdSort(2,'num')">Clicks <i class="fa-solid fa-sort text-gray-300"></i></th>
                        <th class="px-4 py-3 text-right cursor-pointer select-none" onclick="gdSort(3,'num')">Viral <i class="fa-solid fa-sort text-gray-300"></i></th>
                        <th class="px-4 py-3 text-right cursor-pointer select-none" onclick="gdSort(4,'num')">Saves <i class="fa-solid fa-sort text-gray-300"></i></th>
                        <th class="px-4 py-3 text-right cursor-pointer select-none" onclick="gdSort(5,'num')">Conv % <i class="fa-solid fa-sort text-gray-300"></i></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php if (empty($topCards)): ?>
                    <tr><td colspan="6" class="px-4 py-8 text-center text-gray-500">No card activity yet in the last <?php echo (int) $kpiDays; ?> days.</td></tr>
                    <?php else: foreach ($topCards as $c):
                        $cardUrl = getBasePath() . 'admin/card-analytics.php?employee=' . urlencode($c['employee_id']);
                    ?>
                    <tr class="hover:bg-gray-50 transition-colors">
                        <td class="px-4 py-3">
                            <a href="<?php echo $cardUrl; ?>" class="text-blue-600 hover:text-blue-800 font-medium">
                                <?php echo sanitize($c['name']); ?>
                            </a>
                        </td>
                        <td class="px-4 py-3 text-right text-gray-900" data-val="<?php echo (int) $c['views']; ?>"><?php echo number_format($c['views']); ?></td>
                        <td class="px-4 py-3 text-right text-gray-900" data-val="<?php echo (int) $c['clicks']; ?>"><?php echo number_format($c['clicks']); ?></td>
                        <td class="px-4 py-3 text-right text-gray-900" data-val="<?php echo (int) $c['viral_clicks']; ?>"><?php echo number_format($c['viral_clicks']); ?></td>
                        <td class="px-4 py-3 text-right text-gray-900" data-val="<?php echo (int) $c['saves']; ?>"><?php echo number_format($c['saves']); ?></td>
                        <td class="px-4 py-3 text-right text-gray-900" data-val="<?php echo (float) $c['conversion']; ?>"><?php echo number_format($c['conversion'], 1); ?>%</td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Row 5: Funnel -->
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-6 mb-6">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-semibold text-gray-900">Funnel — last <?php echo (int) $kpiDays; ?>d</h3>
            <p class="text-xs text-gray-500">Views &rarr; QR scans &rarr; CTA clicks &rarr; Saves &rarr; Viral clicks</p>
        </div>
        <?php
        $max = max(1, $funnel['views'], $funnel['qr_scans'], $funnel['cta_clicks'], $funnel['saves'], $funnel['viral_clicks']);
        $stages = [
            ['Views',         $funnel['views'],        'bg-blue-500'],
            ['QR scans',      $funnel['qr_scans'],     'bg-indigo-500'],
            ['CTA clicks',    $funnel['cta_clicks'],   'bg-green-500'],
            ['Saves',         $funnel['saves'],        'bg-orange-500'],
            ['Viral clicks',  $funnel['viral_clicks'], 'bg-pink-500'],
        ];
        ?>
        <div class="space-y-3">
            <?php foreach ($stages as $i => $stage):
                [$label, $value, $color] = $stage;
                $pct = $max > 0 ? ($value / $max) * 100 : 0;
                $dropoff = null;
                if ($i > 0) {
                    $prev = $stages[$i - 1][1];
                    $dropoff = $prev > 0 ? (100 * ($prev - $value) / $prev) : 0;
                }
            ?>
            <div>
                <div class="flex items-center justify-between text-sm mb-1">
                    <span class="font-medium text-gray-700"><?php echo $label; ?></span>
                    <span class="text-gray-600">
                        <?php echo number_format($value); ?>
                        <?php if ($dropoff !== null): ?>
                            <span class="text-xs text-red-500 ml-2"><i class="fa-solid fa-caret-down"></i> <?php echo number_format($dropoff, 1); ?>% drop</span>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="h-6 bg-gray-100 rounded-md overflow-hidden">
                    <div class="h-full <?php echo $color; ?> transition-all" style="width: <?php echo max(2, $pct); ?>%"></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Row 6: Feature / migration health -->
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-semibold text-gray-900">Feature health</h3>
            <p class="text-xs text-gray-500">Feature-backing tables and row counts</p>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-xs text-gray-500 uppercase tracking-wider">
                    <tr>
                        <th class="px-4 py-3 text-left">Table</th>
                        <th class="px-4 py-3 text-right">Rows</th>
                        <th class="px-4 py-3 text-center">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php foreach ($featureHealth as $f): ?>
                    <tr>
                        <td class="px-4 py-3 font-mono text-xs text-gray-700"><?php echo sanitize($f['table']); ?></td>
                        <td class="px-4 py-3 text-right text-gray-900"><?php echo number_format($f['rows']); ?></td>
                        <td class="px-4 py-3 text-center"><?php echo gd_status_pill($f['status']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const sectionLabels = <?php echo json_encode(array_values($sectionLabels)); ?>;
    const sectionData   = <?php echo json_encode(array_map(function ($s) { return (float) $s['percent']; }, $sectionData['sections'])); ?>;
    const sectionEnabled = <?php echo json_encode(array_map(function ($s) { return (int) $s['enabled']; }, $sectionData['sections'])); ?>;
    const sectionTotal   = <?php echo (int) $sectionData['total_active']; ?>;
    const series        = <?php echo json_encode($timeSeries); ?>;

    // Row 2: Horizontal bar — section adoption
    const secEl = document.getElementById('gdSectionBar');
    if (secEl) {
        new Chart(secEl, {
            type: 'bar',
            data: {
                labels: sectionLabels,
                datasets: [{
                    label: '% of active cards',
                    data: sectionData,
                    backgroundColor: 'rgba(59,130,246,0.75)',
                    borderColor: 'rgb(59,130,246)',
                    borderWidth: 1
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                const pct = ctx.parsed.x;
                                const enabled = sectionEnabled[ctx.dataIndex] || 0;
                                return pct.toFixed(1) + '% (' + enabled + ' / ' + sectionTotal + ' cards)';
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        beginAtZero: true,
                        max: 100,
                        ticks: { callback: v => v + '%' }
                    }
                }
            }
        });
    }

    // Row 3: Line — event breakdown
    const lineEl = document.getElementById('gdLineChart');
    if (lineEl) {
        const labels = series.map(d => {
            const dt = new Date(d.date);
            return dt.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
        });
        const mk = (label, key, color) => ({
            label,
            data: series.map(d => d[key] || 0),
            borderColor: color,
            backgroundColor: color.replace('rgb', 'rgba').replace(')', ',0.08)'),
            tension: 0.3,
            fill: false
        });
        new Chart(lineEl, {
            type: 'line',
            data: {
                labels,
                datasets: [
                    mk('Views',        'views',        'rgb(59,130,246)'),
                    mk('Phone',        'c_phone',      'rgb(234,179,8)'),
                    mk('WhatsApp',     'c_whatsapp',   'rgb(34,197,94)'),
                    mk('Email',        'c_email',      'rgb(99,102,241)'),
                    mk('Save contact', 'saves',        'rgb(249,115,22)'),
                    mk('Viral click',  'viral_clicks', 'rgb(236,72,153)'),
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom' } },
                interaction: { mode: 'index', intersect: false },
                scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
            }
        });
    }
});

// Simple column sort for the top-cards table
function gdSort(col, type) {
    const table = document.getElementById('gdTopTable');
    if (!table) return;
    const tbody = table.tBodies[0];
    const rows = Array.from(tbody.rows).filter(r => r.cells.length === table.tHead.rows[0].cells.length);
    const dir = table.dataset.sortCol == col && table.dataset.sortDir === 'asc' ? 'desc' : 'asc';
    rows.sort((a, b) => {
        const av = type === 'num'
            ? parseFloat(a.cells[col].dataset.val || '0')
            : a.cells[col].innerText.trim().toLowerCase();
        const bv = type === 'num'
            ? parseFloat(b.cells[col].dataset.val || '0')
            : b.cells[col].innerText.trim().toLowerCase();
        if (av < bv) return dir === 'asc' ? -1 : 1;
        if (av > bv) return dir === 'asc' ?  1 : -1;
        return 0;
    });
    rows.forEach(r => tbody.appendChild(r));
    table.dataset.sortCol = col;
    table.dataset.sortDir = dir;
}
</script>

<?php adminFooter(); ?>
