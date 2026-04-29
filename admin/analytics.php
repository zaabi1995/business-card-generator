<?php
/**
 * QR Code Scan Analytics Dashboard
 * View statistics for QR code scans across all employees
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/admin-layout.php';

// Ensure QRTracker is loaded
if (!class_exists('QRTracker')) {
    require_once INCLUDES_DIR . '/QRTracker.php';
}

// Require authentication
requireAdmin();
$currentUser = Auth::getCurrentUser();
$companyId = getCurrentCompanyId();

if (!$companyId) {
    header('Location: ' . getBasePath() . 'login.php');
    exit;
}

// Get time period filter
$days = isset($_GET['days']) ? (int)$_GET['days'] : 30;
$days = in_array($days, [7, 14, 30, 60, 90]) ? $days : 30;

// Get employee filter
$employeeId = $_GET['employee'] ?? null;

// Get statistics
if ($employeeId) {
    $employee = findEmployeeById($employeeId, $companyId);
    if (!$employee) {
        header('Location: ' . getBasePath() . 'admin/analytics.php');
        exit;
    }
    $stats = QRTracker::getEmployeeStats($employeeId, $days);
    $pageTitle = t('adminchrome.analytics_prefix', ['name' => $employee['name_en'] ?? $employee['email']]);
} else {
    $stats = QRTracker::getCompanyStats($companyId, $days);
    $pageTitle = t('adminchrome.qr_scan_analytics');
}

// Get all employees for dropdown
$employees = loadEmployees($companyId);

// Helper function for country flags
function getCountryFlag($code) {
    if (empty($code) || strlen($code) !== 2) return '🌍';
    $code = strtoupper($code);
    $flag = '';
    for ($i = 0; $i < 2; $i++) {
        $flag .= mb_chr(ord($code[$i]) + 127397);
    }
    return $flag;
}

// Helper function for device icons
function getDeviceIcon($type) {
    return match($type) {
        'mobile' => 'fa-mobile-screen',
        'tablet' => 'fa-tablet-screen-button',
        'desktop' => 'fa-desktop',
        default => 'fa-question'
    };
}

// Helper function for browser icons
function getBrowserIcon($browser) {
    return match(strtolower($browser ?? '')) {
        'chrome' => 'fa-chrome',
        'firefox' => 'fa-firefox-browser',
        'safari' => 'fa-safari',
        'edge' => 'fa-edge',
        'opera' => 'fa-opera',
        'ie' => 'fa-internet-explorer',
        default => 'fa-globe'
    };
}

adminHeader($pageTitle, 'analytics');
?>

<div x-data="analyticsPage()" x-init="init()">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-8">
        <div>
            <p class="text-gray-600"><?= htmlspecialchars(t('analytics.qr_page_sub')) ?></p>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <!-- Employee Filter -->
            <select onchange="filterByEmployee(this.value)" class="px-3 py-2 bg-white border border-gray-200 rounded-lg text-sm">
                <option value=""><?= htmlspecialchars(t('analytics.all_employees')) ?></option>
                <?php foreach ($employees as $emp): ?>
                <option value="<?php echo sanitize($emp['id']); ?>" <?php echo $employeeId === $emp['id'] ? 'selected' : ''; ?>>
                    <?php echo sanitize($emp['name_en'] ?? $emp['email']); ?>
                </option>
                <?php endforeach; ?>
            </select>

            <!-- Time Period Filter -->
            <select onchange="filterByDays(this.value)" class="px-3 py-2 bg-white border border-gray-200 rounded-lg text-sm">
                <?php foreach ([7, 14, 30, 60, 90] as $d): ?>
                <option value="<?= $d ?>" <?= $days === $d ? 'selected' : '' ?>><?= htmlspecialchars(str_replace(':n', (string) $d, t('analytics.last_days'))) ?></option>
                <?php endforeach; ?>
            </select>

            <!-- Export Button -->
            <button @click="exportData()" class="px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg text-sm font-medium transition-colors">
                <i class="fa-solid fa-download mr-2"></i><?= htmlspecialchars(t('analytics.export_btn')) ?>
            </button>
        </div>
    </div>
    
    <!-- Stats Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
        <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-500"><?= htmlspecialchars(t('analytics.stat_total_scans')) ?></p>
                    <p class="text-3xl font-bold text-gray-900"><?php echo number_format($stats['total_scans']); ?></p>
                </div>
                <div class="w-12 h-12 bg-blue-50 rounded-xl flex items-center justify-center">
                    <i class="fa-solid fa-qrcode text-blue-600 text-xl"></i>
                </div>
            </div>
        </div>
        
        <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-500"><?= htmlspecialchars(str_replace(':n', (string) $days, t('analytics.stat_period_scans'))) ?></p>
                    <p class="text-3xl font-bold text-gray-900"><?php echo number_format($stats['period_scans']); ?></p>
                </div>
                <div class="w-12 h-12 bg-green-50 rounded-xl flex items-center justify-center">
                    <i class="fa-solid fa-chart-line text-green-600 text-xl"></i>
                </div>
            </div>
        </div>
        
        <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-500"><?= htmlspecialchars(t('analytics.stat_unique_visitors')) ?></p>
                    <p class="text-3xl font-bold text-gray-900"><?php echo number_format($stats['unique_visitors']); ?></p>
                </div>
                <div class="w-12 h-12 bg-purple-50 rounded-xl flex items-center justify-center">
                    <i class="fa-solid fa-users text-purple-600 text-xl"></i>
                </div>
            </div>
        </div>
        
        <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-500"><?= htmlspecialchars(t('analytics.stat_daily_avg')) ?></p>
                    <p class="text-3xl font-bold text-gray-900"><?php echo $days > 0 ? number_format($stats['period_scans'] / $days, 1) : 0; ?></p>
                </div>
                <div class="w-12 h-12 bg-orange-50 rounded-xl flex items-center justify-center">
                    <i class="fa-solid fa-calendar-day text-orange-600 text-xl"></i>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Charts Row -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
        <!-- Daily Scans Chart -->
        <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4"><?= htmlspecialchars(t('analytics.chart_daily_scans')) ?></h3>
            <div class="h-64">
                <canvas id="dailyChart"></canvas>
            </div>
        </div>
        
        <!-- Device Distribution -->
        <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4"><?= htmlspecialchars(t('analytics.chart_device_dist')) ?></h3>
            <div class="h-64 flex items-center justify-center">
                <canvas id="deviceChart"></canvas>
            </div>
        </div>
    </div>
    
    <!-- Details Row -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Top Countries -->
        <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4"><?= htmlspecialchars(t('analytics.top_countries')) ?></h3>
            <?php if (!empty($stats['countries'])): ?>
            <div class="space-y-3">
                <?php foreach ($stats['countries'] as $country): ?>
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span class="text-lg"><?php echo getCountryFlag($country['country_code']); ?></span>
                        <span class="text-gray-700"><?php echo sanitize($country['country_name'] ?? t('analytics.unknown')); ?></span>
                    </div>
                    <span class="text-gray-500 font-medium"><?php echo number_format($country['count']); ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <p class="text-gray-500 text-center py-8"><?= htmlspecialchars(t('analytics.no_location')) ?></p>
            <?php endif; ?>
        </div>
        
        <?php if (!$employeeId && !empty($stats['top_employees'])): ?>
        <!-- Top Employees -->
        <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4"><?= htmlspecialchars(t('analytics.top_employees_h3')) ?></h3>
            <div class="space-y-3">
                <?php foreach ($stats['top_employees'] as $emp): ?>
                <a href="?employee=<?php echo urlencode($emp['employee_id']); ?>&days=<?php echo $days; ?>" 
                   class="flex items-center justify-between hover:bg-gray-50 -mx-2 px-2 py-1 rounded transition-colors">
                    <div>
                        <p class="text-gray-700 font-medium"><?php echo sanitize($emp['name_en'] ?? t('analytics.unknown')); ?></p>
                        <p class="text-gray-400 text-sm"><?php echo sanitize($emp['email'] ?? ''); ?></p>
                    </div>
                    <span class="text-blue-600 font-medium"><?php echo number_format($emp['scan_count']); ?></span>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Browser Stats -->
        <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">
                <?php echo htmlspecialchars($employeeId ? t('analytics.browsers_h3') : t('analytics.browser_dist_h3')); ?>
            </h3>
            <?php 
            $browserStats = $employeeId ? ($stats['browsers'] ?? []) : [];
            if (!$employeeId && !empty($stats['devices'])):
                // For company stats, show device breakdown instead
            ?>
            <div class="space-y-3">
                <?php foreach ($stats['devices'] as $device): ?>
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <i class="fa-solid <?php echo getDeviceIcon($device['device_type']); ?> text-gray-400"></i>
                        <span class="text-gray-700 capitalize"><?php echo sanitize($device['device_type']); ?></span>
                    </div>
                    <span class="text-gray-500 font-medium"><?php echo number_format($device['count']); ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php elseif (!empty($browserStats)): ?>
            <div class="space-y-3">
                <?php foreach ($browserStats as $browser): ?>
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <i class="fa-brands <?php echo getBrowserIcon($browser['browser']); ?> text-gray-400"></i>
                        <span class="text-gray-700"><?php echo sanitize($browser['browser'] ?? t('analytics.unknown')); ?></span>
                    </div>
                    <span class="text-gray-500 font-medium"><?php echo number_format($browser['count']); ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <p class="text-gray-500 text-center py-8"><?= htmlspecialchars(t('analytics.no_browser')) ?></p>
            <?php endif; ?>
        </div>
    </div>
    
    <?php if ($employeeId && !empty($stats['recent'])): ?>
    <!-- Recent Scans Table -->
    <div class="mt-8 bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100">
            <h3 class="text-lg font-semibold text-gray-900"><?= htmlspecialchars(t('analytics.recent_scans_h3')) ?></h3>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider"><?= htmlspecialchars(t('analytics.col_time')) ?></th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider"><?= htmlspecialchars(t('analytics.col_device')) ?></th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider"><?= htmlspecialchars(t('analytics.col_browser')) ?></th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider"><?= htmlspecialchars(t('analytics.col_location')) ?></th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider"><?= htmlspecialchars(t('analytics.col_ip')) ?></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php foreach ($stats['recent'] as $scan): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700">
                            <?php echo date('M j, Y g:i A', strtotime($scan['scanned_at'])); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm">
                            <span class="inline-flex items-center gap-1.5">
                                <i class="fa-solid <?php echo getDeviceIcon($scan['device_type']); ?> text-gray-400"></i>
                                <span class="capitalize"><?php echo sanitize($scan['device_type']); ?></span>
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700">
                            <?php echo sanitize(($scan['browser'] ?? t('analytics.unknown')) . ' / ' . ($scan['os'] ?? t('analytics.unknown'))); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700">
                            <?php 
                            $location = array_filter([$scan['city'], $scan['country_name']]);
                            echo sanitize(implode(', ', $location) ?: t('analytics.unknown'));
                            ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 font-mono">
                            <?php echo sanitize($scan['ip_address'] ?? '-'); ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

<script>
// Helper functions
function filterByEmployee(employeeId) {
    const url = new URL(window.location);
    if (employeeId) {
        url.searchParams.set('employee', employeeId);
    } else {
        url.searchParams.delete('employee');
    }
    window.location = url;
}

function filterByDays(days) {
    const url = new URL(window.location);
    url.searchParams.set('days', days);
    window.location = url;
}

function analyticsPage() {
    return {
        dailyChart: null,
        deviceChart: null,
        
        init() {
            // Small delay to ensure DOM is ready
            this.$nextTick(() => {
                this.initDailyChart();
                this.initDeviceChart();
            });
        },
        
        initDailyChart() {
            const ctx = document.getElementById('dailyChart');
            if (!ctx) return;
            
            // Destroy existing chart if any
            const existingChart = Chart.getChart(ctx);
            if (existingChart) {
                existingChart.destroy();
            }
            
            const dailyData = <?php echo json_encode($stats['daily'] ?? []); ?>;
            
            const labels = dailyData.map(d => {
                const date = new Date(d.scan_date);
                return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
            });
            
            const data = dailyData.map(d => d.total_scans || 0);
            
            this.dailyChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                        label: <?= json_encode(t('analytics.series_scans'), JSON_UNESCAPED_UNICODE) ?>,
                        data: data,
                        borderColor: 'rgb(59, 130, 246)',
                        backgroundColor: 'rgba(59, 130, 246, 0.1)',
                        fill: true,
                        tension: 0.3
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false }
                    },
                    scales: {
                        y: { beginAtZero: true }
                    }
                }
            });
        },
        
        initDeviceChart() {
            const ctx = document.getElementById('deviceChart');
            if (!ctx) return;
            
            // Destroy existing chart if any
            const existingChart = Chart.getChart(ctx);
            if (existingChart) {
                existingChart.destroy();
            }
            
            const devices = <?php echo json_encode($stats['devices'] ?? []); ?>;
            
            const labels = devices.map(d => d.device_type.charAt(0).toUpperCase() + d.device_type.slice(1));
            const data = devices.map(d => d.count);
            
            this.deviceChart = new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: labels,
                    datasets: [{
                        data: data,
                        backgroundColor: [
                            'rgb(59, 130, 246)',
                            'rgb(16, 185, 129)',
                            'rgb(245, 158, 11)',
                            'rgb(156, 163, 175)'
                        ]
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        }
                    }
                }
            });
        },
        
        exportData() {
            // Export as CSV
            let csv = 'Date,Total Scans,Unique Visitors\n';
            const dailyData = <?php echo json_encode($stats['daily'] ?? []); ?>;
            dailyData.forEach(d => {
                csv += `${d.scan_date},${d.total_scans || 0},${d.unique_visitors || 0}\n`;
            });
            
            const blob = new Blob([csv], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'qr-analytics-<?php echo date('Y-m-d'); ?>.csv';
            a.click();
            window.URL.revokeObjectURL(url);
        }
    };
}
</script>

<?php adminFooter(); ?>
