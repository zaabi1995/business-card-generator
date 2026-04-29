<?php
/**
 * Per-Card Analytics Dashboard
 *
 * URL: /admin/card-analytics.php?employee=<id>&days=<7|14|30|60|90>
 *
 * Scope:
 * - Requires admin login.
 * - company_admin / admin roles: scoped to their own company (getCurrentCompanyId()).
 * - super_admin: may view any employee (still scoped via their active company context).
 */

require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/CardAnalytics.php';
require_once INCLUDES_DIR . '/admin-layout.php';

requireAdmin();
$companyId = getCurrentCompanyId();
if (!$companyId) {
    header('Location: ' . getBasePath() . 'login.php');
    exit;
}

$days = isset($_GET['days']) ? (int) $_GET['days'] : 30;
$days = in_array($days, [7, 14, 30, 60, 90], true) ? $days : 30;

$employeeId = trim($_GET['employee'] ?? '');
$employees  = loadEmployees($companyId);

// Default: first employee if none selected
if ($employeeId === '' && !empty($employees)) {
    $employeeId = $employees[0]['id'];
}

$employee = $employeeId ? findEmployeeById($employeeId, $companyId) : null;
if (!$employee && $employeeId !== '') {
    // Out-of-scope, silently drop and fall back to first employee
    $employee = !empty($employees) ? findEmployeeById($employees[0]['id'], $companyId) : null;
    $employeeId = $employee['id'] ?? '';
}

$stats = $employee
    ? CardAnalytics::getEmployeeStats($employee['id'], $days)
    : ['days' => $days, 'kpis' => ['views' => 0, 'clicks' => 0, 'unique_visitors' => 0, 'qr_scans' => 0, 'saves' => 0, 'wallet_adds' => 0], 'series' => [], 'cta' => [], 'devices' => [], 'referrers' => [], 'countries' => []];

$pageTitle = $employee
    ? t('adminchrome.card_analytics_prefix', ['name' => $employee['name_en'] ?? $employee['email'] ?? 'Employee'])
    : t('adminchrome.card_analytics');

function ca_flag($code)
{
    if (empty($code) || strlen($code) !== 2) {
        return '🌍';
    }
    $code = strtoupper($code);
    $out = '';
    for ($i = 0; $i < 2; $i++) {
        $out .= mb_chr(ord($code[$i]) + 127397);
    }
    return $out;
}

function ca_cta_label($type)
{
    $map = [
        'click_phone'    => t('analytics.ca_cta_phone'),
        'click_mobile'   => t('analytics.ca_cta_mobile'),
        'click_whatsapp' => t('analytics.ca_cta_whatsapp'),
        'click_email'    => t('analytics.ca_cta_email'),
        'click_website'  => t('analytics.ca_cta_website'),
        'click_map'      => t('analytics.ca_cta_map'),
        'click_social'   => t('analytics.ca_cta_social'),
    ];
    return $map[$type] ?? ucfirst(str_replace('click_', '', $type));
}

adminHeader($pageTitle, 'analytics');
?>

<div>
    <!-- Header & filters -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-8">
        <div>
            <p class="text-gray-600"><?= htmlspecialchars(t('analytics.ca_page_sub')) ?></p>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <select onchange="caFilterEmployee(this.value)" class="px-3 py-2 bg-white border border-gray-200 rounded-lg text-sm">
                <?php foreach ($employees as $emp): ?>
                    <option value="<?php echo sanitize($emp['id']); ?>" <?php echo $employeeId === $emp['id'] ? 'selected' : ''; ?>>
                        <?php echo sanitize($emp['name_en'] ?? $emp['email']); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select onchange="caFilterDays(this.value)" class="px-3 py-2 bg-white border border-gray-200 rounded-lg text-sm">
                <?php foreach ([7, 14, 30, 60, 90] as $d): ?>
                    <option value="<?php echo $d; ?>" <?php echo $days === $d ? 'selected' : ''; ?>><?= htmlspecialchars(str_replace(':n', (string) $d, t('analytics.last_days'))) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <?php if (!$employee): ?>
        <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-12 text-center">
            <p class="text-gray-500"><?= htmlspecialchars(t('analytics.ca_no_employees')) ?></p>
        </div>
    <?php else: ?>

    <!-- 5 KPI cards -->
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-4 mb-8">
        <?php
        $kpiCards = [
            [t('analytics.ca_kpi_views'),    $stats['kpis']['views'],           'fa-eye',              'blue'],
            [t('analytics.ca_kpi_clicks'),   $stats['kpis']['clicks'],          'fa-mouse-pointer',    'green'],
            [t('analytics.ca_kpi_unique'),   $stats['kpis']['unique_visitors'], 'fa-users',            'purple'],
            [t('analytics.ca_kpi_qr_scans'), $stats['kpis']['qr_scans'],        'fa-qrcode',           'orange'],
            [t('analytics.ca_kpi_saves'),    $stats['kpis']['saves'],           'fa-address-book',     'pink'],
        ];
        foreach ($kpiCards as [$label, $value, $icon, $color]):
        ?>
        <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs text-gray-500 uppercase tracking-wider"><?php echo htmlspecialchars($label); ?></p>
                    <p class="text-2xl font-bold text-gray-900"><?php echo number_format((int) $value); ?></p>
                </div>
                <div class="w-10 h-10 bg-<?php echo $color; ?>-50 rounded-lg flex items-center justify-center">
                    <i class="fa-solid <?php echo $icon; ?> text-<?php echo $color; ?>-600"></i>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Line + Bar -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4"><?= htmlspecialchars(str_replace(':n', (string)(int) $days, t('analytics.ca_activity_h3'))) ?></h3>
            <div class="h-64"><canvas id="caLineChart"></canvas></div>
        </div>
        <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4"><?= htmlspecialchars(t('analytics.ca_cta_h3')) ?></h3>
            <div class="h-64"><canvas id="caBarChart"></canvas></div>
        </div>
    </div>

    <!-- Doughnut + Tables -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4"><?= htmlspecialchars(t('analytics.ca_device_h3')) ?></h3>
            <div class="h-64"><canvas id="caDoughnut"></canvas></div>
        </div>

        <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4"><?= htmlspecialchars(t('analytics.ca_referrers_h3')) ?></h3>
            <?php if (!empty($stats['referrers'])): ?>
            <div class="space-y-3">
                <?php foreach ($stats['referrers'] as $r): ?>
                <div class="flex items-center justify-between gap-3">
                    <span class="text-gray-700 text-sm truncate" title="<?php echo sanitize($r['referrer']); ?>">
                        <?php echo sanitize(parse_url($r['referrer'], PHP_URL_HOST) ?: $r['referrer']); ?>
                    </span>
                    <span class="text-gray-500 font-medium"><?php echo number_format((int) $r['c']); ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <p class="text-gray-500 text-center py-8 text-sm"><?= htmlspecialchars(t('analytics.ca_no_referrers')) ?></p>
            <?php endif; ?>
        </div>

        <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4"><?= htmlspecialchars(t('analytics.top_countries')) ?></h3>
            <?php if (!empty($stats['countries'])): ?>
            <div class="space-y-3">
                <?php foreach ($stats['countries'] as $c): ?>
                <div class="flex items-center justify-between gap-3">
                    <div class="flex items-center gap-2 min-w-0">
                        <span class="text-lg"><?php echo ca_flag($c['country_code']); ?></span>
                        <span class="text-gray-700 text-sm truncate"><?php echo sanitize($c['country_name'] ?: t('analytics.unknown')); ?></span>
                    </div>
                    <span class="text-gray-500 font-medium"><?php echo number_format((int) $c['c']); ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <p class="text-gray-500 text-center py-8 text-sm"><?= htmlspecialchars(t('analytics.ca_no_countries')) ?></p>
            <?php endif; ?>
        </div>
    </div>

    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
function caFilterEmployee(id) {
    const u = new URL(window.location);
    u.searchParams.set('employee', id);
    window.location = u;
}
function caFilterDays(d) {
    const u = new URL(window.location);
    u.searchParams.set('days', d);
    window.location = u;
}

document.addEventListener('DOMContentLoaded', function () {
    const series = <?php echo json_encode($stats['series'] ?? []); ?>;
    const cta    = <?php echo json_encode($stats['cta'] ?? []); ?>;
    const dev    = <?php echo json_encode($stats['devices'] ?? []); ?>;

    // Line, views + clicks per day
    const lineEl = document.getElementById('caLineChart');
    if (lineEl && series.length) {
        const labels = series.map(d => {
            const dt = new Date(d.date);
            return dt.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
        });
        new Chart(lineEl, {
            type: 'line',
            data: {
                labels,
                datasets: [
                    {
                        label: <?= json_encode(t('analytics.ca_kpi_views'), JSON_UNESCAPED_UNICODE) ?>,
                        data: series.map(d => d.views),
                        borderColor: 'rgb(59,130,246)',
                        backgroundColor: 'rgba(59,130,246,0.1)',
                        fill: true,
                        tension: 0.3
                    },
                    {
                        label: <?= json_encode(t('analytics.ca_kpi_clicks'), JSON_UNESCAPED_UNICODE) ?>,
                        data: series.map(d => d.clicks),
                        borderColor: 'rgb(16,185,129)',
                        backgroundColor: 'rgba(16,185,129,0.1)',
                        fill: true,
                        tension: 0.3
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom' } },
                scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
            }
        });
    }

    // Bar, CTA breakdown
    const barEl = document.getElementById('caBarChart');
    if (barEl) {
        const ctaLabels = <?php echo json_encode(array_map(fn($r) => ca_cta_label($r['event_type']), $stats['cta'] ?? [])); ?>;
        const ctaData   = cta.map(r => parseInt(r.c, 10) || 0);
        new Chart(barEl, {
            type: 'bar',
            data: {
                labels: ctaLabels,
                datasets: [{
                    label: <?= json_encode(t('analytics.ca_kpi_clicks'), JSON_UNESCAPED_UNICODE) ?>,
                    data: ctaData,
                    backgroundColor: 'rgba(16,185,129,0.8)'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
            }
        });
    }

    // Doughnut, device type
    const dEl = document.getElementById('caDoughnut');
    if (dEl) {
        new Chart(dEl, {
            type: 'doughnut',
            data: {
                labels: dev.map(d => (d.device_type || 'unknown').replace(/^./, c => c.toUpperCase())),
                datasets: [{
                    data: dev.map(d => parseInt(d.c, 10) || 0),
                    backgroundColor: ['rgb(59,130,246)', 'rgb(16,185,129)', 'rgb(245,158,11)', 'rgb(156,163,175)']
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom' } }
            }
        });
    }
});
</script>

<?php adminFooter(); ?>
