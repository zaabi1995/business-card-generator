<?php
/**
 * Cardify public status page (Cat T action 458).
 *
 * Live component health + recent incidents. Bilingual EN/AR via the
 * `status` lang namespace. Pulls app / DB / storage health from the
 * same logic /api/health uses, ERP sync health from the token probe,
 * and incident history from a new `status_incidents` table.
 */
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/Seo.php';

$baseUrl = 'https://cardify.om';
$lang    = ($_GET['lang'] ?? '') === 'ar' ? 'ar' : 'en';
$isAr    = $lang === 'ar';
$db      = Database::getInstance();

// ---- Live component checks (same checks as /api/health + a few more) ----
$components = [];

$components[] = self_status_db_check($db);
$components[] = self_status_app_check();
$components[] = self_status_storage_check();
$components[] = self_status_erp_check();
$components[] = self_status_paymob_check();

// Overall summary
$allUp = true;
$anyDown = false;
foreach ($components as $c) {
    if ($c['state'] === 'degraded') $allUp = false;
    if ($c['state'] === 'down')     { $allUp = false; $anyDown = true; }
}
$overall = $anyDown ? 'down' : ($allUp ? 'up' : 'degraded');

// ---- Recent incidents (last 30 days) ----
$incidents = [];
try {
    $incidents = $db->fetchAll(
        "SELECT id, title_en, title_ar, body_en, body_ar, component, severity, status,
                started_at, resolved_at
           FROM status_incidents
          WHERE started_at >= (NOW() - INTERVAL 30 DAY)
       ORDER BY started_at DESC
          LIMIT 20"
    );
} catch (Throwable $_) { /* table may not exist yet */ }

// --- Component check helpers (file-level functions) ---
function self_status_db_check($db): array {
    $ok = false;
    try { $ok = !empty($db->fetchOne("SELECT 1 AS ok")); } catch (Throwable $_) {}
    return [
        'key'   => 'database',
        'state' => $ok ? 'up' : 'down',
    ];
}
function self_status_app_check(): array {
    // This file running means PHP-FPM is up. State is always 'up' at render
    // time; ops who want a deeper signal should rely on external uptime polls.
    return ['key' => 'app', 'state' => 'up'];
}
function self_status_storage_check(): array {
    $dir = (defined('BASE_DIR') ? BASE_DIR : dirname(__DIR__)) . '/data/cache';
    return ['key' => 'storage', 'state' => is_writable($dir) ? 'up' : 'degraded'];
}
function self_status_erp_check(): array {
    try {
        require_once INCLUDES_DIR . '/ERPSync.php';
        if (!ERPSync::isEnabled()) return ['key' => 'erp_sync', 'state' => 'up']; // off = not counted as down
        $settings = ERPSync::getSettings();
        $url = rtrim($settings['erp_api_url'] ?? '', '/') . '/api/admin/cardify/record-payment';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => '{}',
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . ($settings['erp_api_token'] ?? ''),
                'Content-Type: application/json',
            ],
        ]);
        curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($http >= 500 || $http === 0) return ['key' => 'erp_sync', 'state' => 'down'];
        if ($http === 401 || $http === 403) return ['key' => 'erp_sync', 'state' => 'degraded'];
        return ['key' => 'erp_sync', 'state' => 'up'];
    } catch (Throwable $_) {
        return ['key' => 'erp_sync', 'state' => 'degraded'];
    }
}
function self_status_paymob_check(): array {
    // TCP check on port 443 to oman.paymob.com. 2s budget.
    $fp = @fsockopen('tls://oman.paymob.com', 443, $errno, $errstr, 2);
    if (!$fp) return ['key' => 'paymob', 'state' => 'down'];
    fclose($fp);
    return ['key' => 'paymob', 'state' => 'up'];
}

// ---- Page metadata ----
$brandName       = defined('SITE_NAME') ? SITE_NAME : 'Cardify';
$pageTitle       = t('status.page_title');
$pageDescription = t('status.page_desc');
$canonicalUrl    = $baseUrl . ($isAr ? '/ar' : '') . '/status';
$showNavigation  = true;
$bodyClass       = 'bg-gray-50' . ($isAr ? ' font-arabic' : '');
$bodyAttributes  = $isAr ? 'dir="rtl" lang="ar"' : '';

header('Cache-Control: no-store, max-age=0');
require_once INCLUDES_DIR . '/ui-header.php';

Seo::breadcrumbs([
    [$isAr ? 'الرئيسية' : 'Home', '/'],
    [t('status.page_title'), $canonicalUrl],
]);

$overallBg = [
    'up'       => 'from-green-500 to-emerald-600',
    'degraded' => 'from-amber-400 to-amber-500',
    'down'     => 'from-red-500 to-rose-600',
][$overall];
$overallIcon = [
    'up'       => 'fa-circle-check',
    'degraded' => 'fa-triangle-exclamation',
    'down'     => 'fa-circle-xmark',
][$overall];
?>
<main class="min-h-screen bg-gray-50 pt-20 pb-16">
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">

        <!-- Overall banner -->
        <header class="bg-gradient-to-br <?= $overallBg ?> text-white rounded-2xl p-8 mb-6 flex items-center gap-4">
            <i class="fa-solid <?= $overallIcon ?> text-4xl opacity-90"></i>
            <div>
                <h1 class="text-2xl font-extrabold"><?= htmlspecialchars(t('status.overall_' . $overall)) ?></h1>
                <p class="text-white/80 text-sm mt-1"><?= htmlspecialchars(t('status.overall_sub')) ?></p>
            </div>
        </header>

        <!-- Components -->
        <section class="bg-white rounded-2xl border border-gray-200 divide-y divide-gray-100 mb-8">
        <?php
            $statePill = [
                'up'       => ['label' => 'status.state_up',       'cls' => 'bg-green-50 text-green-700 border-green-200', 'icon' => 'fa-circle-check'],
                'degraded' => ['label' => 'status.state_degraded', 'cls' => 'bg-amber-50 text-amber-700 border-amber-200', 'icon' => 'fa-triangle-exclamation'],
                'down'     => ['label' => 'status.state_down',     'cls' => 'bg-red-50 text-red-700 border-red-200',       'icon' => 'fa-circle-xmark'],
            ];
            foreach ($components as $c):
                $s = $statePill[$c['state']];
        ?>
            <div class="flex items-center justify-between px-6 py-4">
                <div>
                    <p class="font-semibold text-gray-900"><?= htmlspecialchars(t('status.comp_' . $c['key'])) ?></p>
                    <p class="text-xs text-gray-500"><?= htmlspecialchars(t('status.compdesc_' . $c['key'])) ?></p>
                </div>
                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold border <?= $s['cls'] ?>">
                    <i class="fa-solid <?= $s['icon'] ?>"></i>
                    <?= htmlspecialchars(t($s['label'])) ?>
                </span>
            </div>
        <?php endforeach; ?>
        </section>

        <!-- Incidents -->
        <section class="bg-white rounded-2xl border border-gray-200 p-6">
            <h2 class="font-bold text-gray-900 mb-4"><?= htmlspecialchars(t('status.incidents_h')) ?></h2>
            <?php if (empty($incidents)): ?>
                <div class="text-center py-8 text-gray-500">
                    <i class="fa-regular fa-face-smile text-3xl text-green-400 mb-2"></i>
                    <p class="text-sm"><?= htmlspecialchars(t('status.incidents_empty')) ?></p>
                </div>
            <?php else: ?>
                <ol class="relative <?= $isAr ? 'pr-4 border-r-2' : 'pl-4 border-l-2' ?> border-gray-200 space-y-6">
                    <?php foreach ($incidents as $inc):
                        $title = $isAr && !empty($inc['title_ar']) ? $inc['title_ar'] : $inc['title_en'];
                        $body  = $isAr && !empty($inc['body_ar'])  ? $inc['body_ar']  : $inc['body_en'];
                        $sev   = $inc['severity'] ?? 'minor';
                        $sevCls = ['minor' => 'bg-amber-50 text-amber-700', 'major' => 'bg-red-50 text-red-700', 'info' => 'bg-blue-50 text-blue-700'][$sev] ?? 'bg-gray-50 text-gray-700';
                    ?>
                    <li>
                        <span class="<?= $isAr ? '-right-2' : '-left-2' ?> absolute -mt-1 w-3 h-3 rounded-full bg-gray-300"></span>
                        <div class="flex items-center gap-2 text-xs text-gray-500 mb-1">
                            <time datetime="<?= date('c', dbTs($inc['started_at'])) ?>"><?= htmlspecialchars(I18n::formatDate(strtotime($inc['started_at']))) ?></time>
                            <span class="inline-block px-2 py-0.5 rounded-full <?= $sevCls ?> font-semibold"><?= htmlspecialchars(t('status.sev_' . $sev)) ?></span>
                            <?php if (!empty($inc['resolved_at'])): ?>
                            <span class="text-green-700 font-semibold">• <?= htmlspecialchars(t('status.resolved')) ?></span>
                            <?php else: ?>
                            <span class="text-amber-700 font-semibold">• <?= htmlspecialchars(t('status.open')) ?></span>
                            <?php endif; ?>
                        </div>
                        <p class="font-semibold text-gray-900"><?= htmlspecialchars($title) ?></p>
                        <?php if ($body): ?>
                        <p class="text-sm text-gray-600 leading-relaxed mt-1 whitespace-pre-line"><?= htmlspecialchars($body) ?></p>
                        <?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
        </section>

        <p class="text-center text-xs text-gray-400 mt-8">
            <?= htmlspecialchars(t('status.footer_note', ['endpoint' => '/api/health'])) ?>
        </p>
    </div>
</main>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
