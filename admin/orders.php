<?php
/**
 * Orders: one surface for Card Requests, Print Orders and Appointments.
 *
 * Commit 1 (sidebar IA consolidation) ships this as a hub page that links
 * out to the existing controllers. Commit 4 (per the plan) merges the three
 * data sources into a single filterable table with a ?type= switcher.
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/admin-layout.php';

requireAdmin();
$companyId = getCurrentCompanyId();
if (!$companyId) { header('Location: ' . getBasePath() . 'login.php'); exit; }

$basePath = getAdminBasePath();
$isCompanyAdmin = defined('COMPANY_ADMIN_BASE') || !empty($_SESSION['company_slug']);
$ext = $isCompanyAdmin ? '' : '.php';

$type = $_GET['type'] ?? 'all';

// Pending counts for each workflow (drives the badge + lead-with-pending sort)
$db = Database::getInstance();
$pendingRequests = (int)($db->fetchOne(
    "SELECT COUNT(*) c FROM card_requests WHERE company_id = :cid AND status = 'pending'",
    ['cid' => $companyId]
)['c'] ?? 0);

$pendingPrint = 0;
try {
    $pendingPrint = (int)($db->fetchOne(
        "SELECT COUNT(*) c FROM print_orders WHERE company_id = :cid AND status IN ('pending','processing')",
        ['cid' => $companyId]
    )['c'] ?? 0);
} catch (Throwable $e) { /* table may not exist on some installs */ }

$upcomingAppts = 0;
try {
    $upcomingAppts = (int)($db->fetchOne(
        "SELECT COUNT(*) c FROM appointments WHERE company_id = :cid AND scheduled_at > NOW() AND status IN ('pending','confirmed')",
        ['cid' => $companyId]
    )['c'] ?? 0);
} catch (Throwable $e) { /* table may not exist */ }

adminHeader('Orders', 'orders');
?>

<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <header class="mb-8">
        <h1 class="text-2xl font-bold text-gray-900"><?= htmlspecialchars(t('ordershub.title')) ?></h1>
        <p class="text-sm text-gray-500 mt-1"><?= htmlspecialchars(t('ordershub.lead')) ?></p>
    </header>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <a href="<?= htmlspecialchars($basePath . 'requests' . $ext, ENT_QUOTES) ?>"
           class="group p-6 bg-white rounded-2xl border border-gray-200 hover:border-blue-300 hover:shadow-sm transition-all">
            <div class="flex items-start justify-between mb-3">
                <div class="w-10 h-10 rounded-xl bg-blue-50 flex items-center justify-center group-hover:bg-blue-100 transition-colors">
                    <i class="fa-solid fa-inbox text-blue-600"></i>
                </div>
                <?php if ($pendingRequests > 0): ?>
                    <span class="px-2 py-0.5 text-xs font-semibold text-white bg-red-500 rounded-full"><?= $pendingRequests ?> pending</span>
                <?php endif; ?>
            </div>
            <h3 class="font-semibold text-gray-900 mb-1"><?= htmlspecialchars(t('ordershub.card_requests')) ?></h3>
            <p class="text-sm text-gray-500"><?= htmlspecialchars(t('ordershub.card_requests_sub')) ?></p>
        </a>

        <a href="<?= htmlspecialchars($basePath . 'print' . $ext, ENT_QUOTES) ?>"
           class="group p-6 bg-white rounded-2xl border border-gray-200 hover:border-blue-300 hover:shadow-sm transition-all">
            <div class="flex items-start justify-between mb-3">
                <div class="w-10 h-10 rounded-xl bg-emerald-50 flex items-center justify-center group-hover:bg-emerald-100 transition-colors">
                    <i class="fa-solid fa-print text-emerald-600"></i>
                </div>
                <?php if ($pendingPrint > 0): ?>
                    <span class="px-2 py-0.5 text-xs font-semibold text-white bg-amber-500 rounded-full"><?= $pendingPrint ?> in flight</span>
                <?php endif; ?>
            </div>
            <h3 class="font-semibold text-gray-900 mb-1"><?= htmlspecialchars(t('ordershub.print_orders')) ?></h3>
            <p class="text-sm text-gray-500"><?= htmlspecialchars(t('ordershub.print_orders_sub')) ?></p>
        </a>

        <a href="<?= htmlspecialchars($basePath . 'appointments' . $ext, ENT_QUOTES) ?>"
           class="group p-6 bg-white rounded-2xl border border-gray-200 hover:border-blue-300 hover:shadow-sm transition-all">
            <div class="flex items-start justify-between mb-3">
                <div class="w-10 h-10 rounded-xl bg-purple-50 flex items-center justify-center group-hover:bg-purple-100 transition-colors">
                    <i class="fa-solid fa-calendar-check text-purple-600"></i>
                </div>
                <?php if ($upcomingAppts > 0): ?>
                    <span class="px-2 py-0.5 text-xs font-semibold text-white bg-purple-500 rounded-full"><?= $upcomingAppts ?> upcoming</span>
                <?php endif; ?>
            </div>
            <h3 class="font-semibold text-gray-900 mb-1"><?= htmlspecialchars(t('ordershub.appointments')) ?></h3>
            <p class="text-sm text-gray-500"><?= htmlspecialchars(t('ordershub.appointments_sub')) ?></p>
        </a>
    </div>
</div>

<?php adminFooter(); ?>
