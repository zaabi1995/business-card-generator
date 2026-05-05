<?php
/**
 * Internal-provider mode: one client company. Shows employee grid
 * with card thumbnails + per-row buttons to order or download a
 * production sheet.
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/PrintShopAuth.php';

$ctx = PrintShopAuth::requireInternalProvider();
$shop = $ctx['shop'];

$companyId = trim($_GET['company'] ?? '');
if ($companyId === '') {
    header('Location: ' . getBasePath() . 'printshop/clients.php');
    exit;
}

$db  = Database::getInstance();
$pdo = $db->getConnection();

$company = $pdo->prepare("SELECT * FROM companies WHERE id = ? LIMIT 1");
$company->execute([$companyId]);
$company = $company->fetch(PDO::FETCH_ASSOC);
if (!$company) {
    header('Location: ' . getBasePath() . 'printshop/clients.php');
    exit;
}

$empStmt = $pdo->prepare(
    "SELECT id, name_en, name_ar, position_en, position_ar, email, mobile, front_file_path, back_file_path
     FROM employees WHERE company_id = ? ORDER BY name_en ASC"
);
$empStmt->execute([$companyId]);
$employees = $empStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$cardsBase = getBasePath() . 'uploads/companies/' . $companyId . '/cards/';
$companyLogo = $company['logo_path'] ? getBasePath() . ltrim($company['logo_path'], '/') : '';

$pageTitle = $company['name'] . ' , ' . $shop['name'];
$bodyClass = 'bg-gray-50';
require_once INCLUDES_DIR . '/ui-header.php';
?>

<nav class="bg-white border-b border-gray-200 fixed top-0 left-0 right-0 z-50">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">
            <div class="flex items-center gap-4">
                <a href="<?= getBasePath() ?>printshop/dashboard.php" class="flex items-center gap-2">
                    <img src="<?= getBasePath() ?>assets/images/logo.svg" alt="Cardify" class="h-8 w-auto">
                </a>
                <span class="text-gray-300">|</span>
                <span class="font-semibold text-gray-900"><?= sanitize($shop['name']) ?></span>
            </div>
            <div class="flex items-center gap-4 text-sm">
                <a href="dashboard.php" class="text-gray-500 hover:text-gray-700"><i class="fa-solid fa-chart-pie mr-1"></i>Dashboard</a>
                <a href="orders.php" class="text-gray-500 hover:text-gray-700"><i class="fa-solid fa-box mr-1"></i>Orders</a>
                <a href="clients.php" class="text-blue-600 font-medium"><i class="fa-solid fa-building mr-1"></i><?= htmlspecialchars(t('printshopinternal.nav_clients')) ?></a>
                <a href="operators.php" class="text-gray-500 hover:text-gray-700"><i class="fa-solid fa-users-gear mr-1"></i><?= htmlspecialchars(t('printshopinternal.nav_operators')) ?></a>
                <a href="<?= getBasePath() ?>logout.php" class="text-gray-500 hover:text-red-600"><i class="fa-solid fa-sign-out-alt"></i></a>
            </div>
        </div>
    </div>
</nav>

<div class="pt-20 pb-12 px-4 sm:px-6 lg:px-8 max-w-7xl mx-auto">

    <div class="mb-2 text-sm text-gray-500"><a href="clients.php" class="hover:underline"><i class="fa-solid fa-arrow-left mr-1"></i><?= htmlspecialchars(t('printshopinternal.back_to_clients')) ?></a></div>

    <div class="mb-6 flex items-center gap-4">
        <?php if ($companyLogo): ?>
        <img src="<?= sanitize($companyLogo) ?>" alt="" class="w-14 h-14 rounded-lg object-contain bg-white border border-gray-100">
        <?php else: ?>
        <div class="w-14 h-14 rounded-lg bg-gray-100 flex items-center justify-center text-lg font-bold text-gray-500"><?= strtoupper(substr($company['name'], 0, 2)) ?></div>
        <?php endif; ?>
        <div>
            <h1 class="text-2xl font-bold text-gray-900"><?= sanitize($company['name']) ?></h1>
            <p class="text-sm text-gray-500">/<?= sanitize($company['slug']) ?> &middot; <?= count($employees) ?> employees</p>
        </div>
    </div>

    <?php if (empty($employees)): ?>
    <div class="p-12 bg-white rounded-2xl border-2 border-dashed border-gray-200 text-center">
        <i class="fa-solid fa-id-card text-4xl text-gray-300 mb-3"></i>
        <p class="text-gray-700 font-medium"><?= htmlspecialchars(t('printshopinternal.no_employees')) ?></p>
    </div>
    <?php else: ?>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        <?php foreach ($employees as $emp):
            $front = $emp['front_file_path'] ? $cardsBase . $emp['front_file_path'] : '';
            $back  = $emp['back_file_path']  ? $cardsBase . $emp['back_file_path']  : '';
        ?>
        <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
            <div class="aspect-[1.7/1] bg-gray-50 flex items-center justify-center">
                <?php if ($front): ?>
                <img src="<?= sanitize($front) ?>" alt="" class="w-full h-full object-contain">
                <?php else: ?>
                <i class="fa-solid fa-id-card text-3xl text-gray-300"></i>
                <?php endif; ?>
            </div>
            <div class="p-4">
                <p class="font-semibold text-gray-900 truncate"><?= sanitize($emp['name_en'] ?? $emp['name_ar'] ?? 'Employee') ?></p>
                <p class="text-xs text-gray-500 truncate"><?= sanitize($emp['position_en'] ?? $emp['position_ar'] ?? '') ?></p>
                <div class="mt-3 flex items-center gap-2">
                    <a href="order-on-behalf.php?employee=<?= urlencode($emp['id']) ?>&company=<?= urlencode($companyId) ?>"
                       class="flex-1 inline-flex items-center justify-center gap-1 px-3 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-md text-xs font-medium">
                        <i class="fa-solid fa-paper-plane"></i> <?= htmlspecialchars(t('printshopinternal.order_btn')) ?>
                    </a>
                    <a href="print-sheet.php?employee=<?= urlencode($emp['id']) ?>&company=<?= urlencode($companyId) ?>" target="_blank"
                       class="inline-flex items-center justify-center gap-1 px-3 py-2 bg-gray-50 hover:bg-gray-100 text-gray-700 border border-gray-200 rounded-md text-xs font-medium">
                        <i class="fa-solid fa-print"></i> <?= htmlspecialchars(t('printshopinternal.sheet_btn')) ?>
                    </a>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
