<?php
/**
 * Employee detail — full-page profile + card preview + QR analytics + edit.
 *
 * Replaces the old modal that opened from the employees list. All
 * per-employee context lives here so admins do not need to navigate
 * to a separate Analytics tab.
 *
 * URL: /admin/employee.php?id={employee_id}  (also /{slug}/admin/employee/{id} via company_admin.php)
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/admin-layout.php';
require_once INCLUDES_DIR . '/QRTracker.php';

requireAdmin();
$companyId = getCurrentCompanyId();
if (!$companyId) { header('Location: ' . getBasePath() . 'login.php'); exit; }

$basePath = getAdminBasePath();
$isCompanyAdmin = defined('COMPANY_ADMIN_BASE') || !empty($_SESSION['company_slug']);
$ext = $isCompanyAdmin ? '' : '.php';

$employeeId = trim($_GET['id'] ?? '');
if ($employeeId === '') {
    header('Location: ' . $basePath . 'employees' . $ext);
    exit;
}

$db = Database::getInstance();
$employee = $db->fetchOne(
    "SELECT * FROM employees WHERE id = :id AND company_id = :cid",
    ['id' => $employeeId, 'cid' => $companyId]
);
if (!$employee) {
    header('Location: ' . $basePath . 'employees' . $ext . '?error=not_found');
    exit;
}

// Latest generated card (front + back filenames + URLs)
$card = $db->fetchOne(
    "SELECT * FROM generated_cards WHERE employee_id = :eid AND company_id = :cid ORDER BY generated_at DESC LIMIT 1",
    ['eid' => $employeeId, 'cid' => $companyId]
);
$cardBaseUrl = getBasePath() . 'uploads/companies/' . $companyId . '/cards/';
$frontUrl = ($card && !empty($card['front_file_path'])) ? $cardBaseUrl . $card['front_file_path'] : null;
$backUrl  = ($card && !empty($card['back_file_path']))  ? $cardBaseUrl . $card['back_file_path']  : null;

// Department name
$deptName = '';
if (!empty($employee['department_id'])) {
    $d = $db->fetchOne('SELECT name FROM departments WHERE id = :id', ['id' => $employee['department_id']]);
    $deptName = $d['name'] ?? '';
}

// Per-employee scan stats: today / 7d / 30d / all-time
$stats30 = QRTracker::getEmployeeStats($employeeId, 30, $companyId) ?: [];
$stats7  = QRTracker::getEmployeeStats($employeeId, 7,  $companyId) ?: [];
$statsAll = QRTracker::getEmployeeStats($employeeId, 36500, $companyId) ?: [];

$totalScans30 = (int)($stats30['total_scans'] ?? 0);
$totalScans7  = (int)($stats7['total_scans'] ?? 0);
$totalScansAll = (int)($statsAll['total_scans'] ?? 0);

// Recent scans table (last 10)
$recentScans = [];
try {
    $recentScans = $db->fetchAll(
        "SELECT scanned_at, user_agent, ip_address, country, city
         FROM qr_scans WHERE employee_id = :eid ORDER BY scanned_at DESC LIMIT 10",
        ['eid' => $employeeId]
    ) ?: [];
} catch (Throwable $e) { /* table may not exist */ }

// Company slug + public URL. Use the clean /<email-localpart> URL so it
// matches what users see when sharing (e.g. /jarwish9), not the raw UUID.
$company = $db->fetchOne('SELECT slug, name FROM companies WHERE id = :id', ['id' => $companyId]);
$publicHost = defined('APP_HOST') ? APP_HOST : 'cardify.om';
$__empEmail = strtolower((string)($employee['email'] ?? ''));
$__atPos    = strpos($__empEmail, '@');
$__empSlug  = $__atPos > 0
    ? preg_replace('/[^a-z0-9._-]/', '', substr($__empEmail, 0, $__atPos))
    : '';
$__urlPath  = $__empSlug !== '' ? $__empSlug : urlencode($employeeId);
$publicUrl = 'https://' . ($company['slug'] ?? 'app') . '.' . $publicHost . '/' . $__urlPath;

adminHeader(($employee['name_en'] ?: $employee['email']), 'employees');
?>

<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <!-- Back link -->
    <a href="<?= htmlspecialchars($basePath . 'employees' . $ext, ENT_QUOTES) ?>" class="inline-flex items-center gap-2 text-sm text-gray-500 hover:text-gray-900 mb-4">
        <i class="fa-solid fa-arrow-left"></i> All employees
    </a>

    <!-- Header -->
    <header class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-8">
        <div class="flex items-center gap-4">
            <div class="w-16 h-16 rounded-full bg-gradient-to-br from-blue-500 to-blue-600 flex items-center justify-center text-white text-2xl font-bold">
                <?= strtoupper(substr($employee['name_en'] ?: $employee['email'], 0, 2)) ?>
            </div>
            <div>
                <h1 class="text-2xl font-bold text-gray-900"><?= sanitize($employee['name_en'] ?: '—') ?></h1>
                <?php if (!empty($employee['name_ar'])): ?>
                    <p class="text-lg text-gray-700" dir="rtl"><?= sanitize($employee['name_ar']) ?></p>
                <?php endif; ?>
                <p class="text-sm text-gray-500"><?= sanitize($employee['position_en'] ?: '') ?><?= $deptName ? ' · ' . sanitize($deptName) : '' ?></p>
            </div>
        </div>
        <div class="flex items-center gap-2">
            <a href="<?= htmlspecialchars($publicUrl, ENT_QUOTES) ?>" target="_blank" rel="noopener"
               class="inline-flex items-center gap-2 px-3 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-200 rounded-lg hover:bg-gray-50">
                <i class="fa-solid fa-arrow-up-right-from-square"></i> Open public profile
            </a>
            <button onclick="navigator.clipboard.writeText('<?= addslashes($publicUrl) ?>').then(()=>{this.querySelector('span').textContent='Copied!';setTimeout(()=>this.querySelector('span').textContent='Copy link',1500)})"
                    class="inline-flex items-center gap-2 px-3 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-200 rounded-lg hover:bg-gray-50">
                <i class="fa-solid fa-link"></i> <span>Copy link</span>
            </button>
            <a href="<?= htmlspecialchars($basePath . 'auto_generate' . $ext . '?employee_id=' . urlencode($employeeId) . '&regenerate=1&return=employee', ENT_QUOTES) ?>"
               class="inline-flex items-center gap-2 px-3 py-2 text-sm font-medium text-white bg-blue-600 rounded-lg hover:bg-blue-700">
                <i class="fa-solid fa-rotate"></i> Regenerate card
            </a>
        </div>
    </header>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
        <!-- Card preview -->
        <section class="lg:col-span-2 bg-white rounded-2xl border border-gray-200 overflow-hidden">
            <div class="p-5 border-b border-gray-100 flex items-center justify-between">
                <h2 class="font-semibold text-gray-900">Card preview</h2>
                <?php if ($frontUrl): ?>
                    <a href="<?= htmlspecialchars(getBasePath() . 'card-pdf.php?i=' . urlencode($employeeId) . '&side=front', ENT_QUOTES) ?>" target="_blank"
                       class="text-sm text-blue-600 hover:text-blue-700 font-medium">Download PDF</a>
                <?php endif; ?>
            </div>
            <div class="p-5">
                <?php if ($frontUrl || $backUrl): ?>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <?php if ($frontUrl): ?>
                        <div>
                            <div class="text-xs text-gray-500 uppercase tracking-wide mb-2">Front</div>
                            <img src="<?= htmlspecialchars($frontUrl, ENT_QUOTES) ?>" alt="Front" class="w-full rounded-xl border border-gray-200 shadow-sm">
                        </div>
                    <?php endif; ?>
                    <?php if ($backUrl): ?>
                        <div>
                            <div class="text-xs text-gray-500 uppercase tracking-wide mb-2">Back</div>
                            <img src="<?= htmlspecialchars($backUrl, ENT_QUOTES) ?>" alt="Back" class="w-full rounded-xl border border-gray-200 shadow-sm">
                        </div>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                    <div class="py-12 text-center text-gray-500">
                        <i class="fa-solid fa-image text-4xl text-gray-300 mb-3"></i>
                        <p class="mb-3">No card generated yet.</p>
                        <a href="<?= htmlspecialchars($basePath . 'auto_generate' . $ext . '?employee_id=' . urlencode($employeeId) . '&return=employee', ENT_QUOTES) ?>"
                           class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-lg hover:bg-blue-700">
                            <i class="fa-solid fa-wand-magic-sparkles"></i> Generate now
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <!-- QR Analytics -->
        <section class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
            <div class="p-5 border-b border-gray-100">
                <h2 class="font-semibold text-gray-900">QR scans</h2>
                <p class="text-xs text-gray-500">How often this card has been viewed.</p>
            </div>
            <div class="p-5 space-y-4">
                <div class="flex items-baseline justify-between">
                    <span class="text-sm text-gray-500">Last 7 days</span>
                    <span class="text-2xl font-bold text-gray-900"><?= number_format($totalScans7) ?></span>
                </div>
                <div class="flex items-baseline justify-between">
                    <span class="text-sm text-gray-500">Last 30 days</span>
                    <span class="text-2xl font-bold text-gray-900"><?= number_format($totalScans30) ?></span>
                </div>
                <div class="flex items-baseline justify-between pt-3 border-t border-gray-100">
                    <span class="text-sm text-gray-500">All time</span>
                    <span class="text-3xl font-bold text-blue-600"><?= number_format($totalScansAll) ?></span>
                </div>
            </div>
        </section>
    </div>

    <!-- Contact details -->
    <section class="mb-6 bg-white rounded-2xl border border-gray-200 overflow-hidden">
        <div class="p-5 border-b border-gray-100 flex items-center justify-between">
            <h2 class="font-semibold text-gray-900">Contact details</h2>
            <a href="<?= htmlspecialchars($basePath . 'employees' . $ext . '?edit=' . urlencode($employeeId), ENT_QUOTES) ?>"
               class="text-sm text-blue-600 hover:text-blue-700 font-medium">
                <i class="fa-solid fa-pen-to-square"></i> Edit
            </a>
        </div>
        <dl class="px-5 py-4 grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-3 text-sm">
            <?php foreach ([
                ['Email',    $employee['email']    ?? ''],
                ['Mobile',   $employee['mobile']   ?? ''],
                ['Phone',    $employee['phone']    ?? ''],
                ['Position', $employee['position_en'] ?? ''],
                ['Arabic name',     $employee['name_ar'] ?? ''],
                ['Arabic position', $employee['position_ar'] ?? ''],
                ['Department',   $deptName],
                ['Website',      $employee['website'] ?? ''],
            ] as $row): if (empty($row[1])) continue; ?>
                <div class="flex">
                    <dt class="w-32 text-gray-500"><?= htmlspecialchars($row[0]) ?></dt>
                    <dd class="text-gray-900 flex-1"><?= sanitize($row[1]) ?></dd>
                </div>
            <?php endforeach; ?>
        </dl>
    </section>

    <!-- Recent scans -->
    <?php if (!empty($recentScans)): ?>
    <section class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
        <div class="p-5 border-b border-gray-100">
            <h2 class="font-semibold text-gray-900">Recent scans</h2>
            <p class="text-xs text-gray-500">Last 10 QR scans for this card.</p>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
                    <tr>
                        <th class="text-left px-5 py-3">When</th>
                        <th class="text-left px-5 py-3">Location</th>
                        <th class="text-left px-5 py-3 hidden md:table-cell">Device</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php foreach ($recentScans as $s): ?>
                        <tr>
                            <td class="px-5 py-3 text-gray-900"><?= htmlspecialchars(date('j M, H:i', dbTs($s['scanned_at']))) ?></td>
                            <td class="px-5 py-3 text-gray-600"><?= sanitize(trim(($s['city'] ?? '') . ', ' . ($s['country'] ?? ''), ' ,')) ?: '—' ?></td>
                            <td class="px-5 py-3 text-gray-500 hidden md:table-cell text-xs"><?= sanitize(substr($s['user_agent'] ?? '', 0, 60)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php endif; ?>
</div>

<?php adminFooter(); ?>
