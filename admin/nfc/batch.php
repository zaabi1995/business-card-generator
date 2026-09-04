<?php
/**
 * NFC Batch, list employees, generate QR codes that point to the
 * mobile writer page, and show programming progress per batch.
 */
require_once __DIR__ . '/../../config.php';
requireAdmin();
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/admin-layout.php';

$db = Database::getInstance();
$companyId = getCurrentCompanyId();
$isSuperAdmin = Auth::isSuperAdmin();

if (!$companyId && !$isSuperAdmin) {
    header('Location: ' . getBasePath() . 'login.php');
    exit;
}

$message = null;
$messageType = 'success';

// Handle CSV upload OR "create batch from all employees"
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $batchId = 'B' . date('YmdHis') . substr(bin2hex(random_bytes(3)), 0, 6);

    $allEmployees = loadEmployees($companyId);
    $byEmail = [];
    $byId = [];
    foreach ($allEmployees as $e) {
        if (!empty($e['email'])) $byEmail[strtolower($e['email'])] = $e;
        if (!empty($e['id'])) $byId[$e['id']] = $e;
    }

    $selected = [];

    if ($action === 'all') {
        $selected = $allEmployees;
    } elseif ($action === 'csv' && !empty($_FILES['csv']['tmp_name'])) {
        $fh = fopen($_FILES['csv']['tmp_name'], 'r');
        if ($fh) {
            while (($row = fgetcsv($fh)) !== false) {
                foreach ($row as $cell) {
                    $cell = trim((string)$cell);
                    if ($cell === '') continue;
                    $key = strtolower($cell);
                    if (isset($byEmail[$key])) $selected[] = $byEmail[$key];
                    elseif (isset($byId[$cell])) $selected[] = $byId[$cell];
                }
            }
            fclose($fh);
        }
    }

    // Pre-create rows (programmed=0) so progress can be measured
    if (!empty($selected)) {
        $stmt = $db->getConnection()->prepare(
            "INSERT INTO nfc_cards (employee_id, company_id, batch_id, programmed) VALUES (:eid, :cid, :bid, 0)"
        );
        foreach ($selected as $emp) {
            try {
                $stmt->execute([
                    'eid' => $emp['id'],
                    'cid' => $emp['company_id'] ?? $companyId,
                    'bid' => $batchId,
                ]);
            } catch (Exception $e) { /* ignore */ }
        }
        header('Location: ' . getBasePath() . 'admin/nfc/batch.php?batch=' . urlencode($batchId));
        exit;
    }

    $message = 'No employees matched.';
    $messageType = 'error';
}

// Show single batch detail OR list of batches
$batchFilter = isset($_GET['batch']) ? trim((string)$_GET['batch']) : '';

$batches = [];
$batchEmployees = [];

try {
    if ($batchFilter !== '') {
        $sql = "SELECT n.*, e.name AS emp_name, e.title AS emp_title, e.email AS emp_email
                FROM nfc_cards n
                LEFT JOIN employees e ON e.id = n.employee_id
                WHERE n.batch_id = :bid";
        $params = ['bid' => $batchFilter];
        if (!$isSuperAdmin) { $sql .= " AND n.company_id = :cid"; $params['cid'] = $companyId; }
        $sql .= " ORDER BY n.created_at ASC";
        $batchEmployees = $db->fetchAll($sql, $params);
    } else {
        $sql = "SELECT batch_id, COUNT(*) AS total,
                       SUM(CASE WHEN programmed=1 THEN 1 ELSE 0 END) AS programmed,
                       MIN(created_at) AS created_at
                FROM nfc_cards WHERE batch_id IS NOT NULL";
        $params = [];
        if (!$isSuperAdmin) { $sql .= " AND company_id = :cid"; $params['cid'] = $companyId; }
        $sql .= " GROUP BY batch_id ORDER BY created_at DESC LIMIT 50";
        $batches = $db->fetchAll($sql, $params);
    }
} catch (Exception $e) {
    $message = 'DB error: ' . $e->getMessage();
    $messageType = 'error';
}

adminHeader('NFC Tags', 'nfc-tags');
?>
<div class="p-4 sm:p-6 max-w-6xl mx-auto">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">NFC Tag Provisioning</h1>
            <p class="text-gray-500 text-sm">Program physical NFC tags with employee card URLs.</p>
        </div>
        <?php if ($batchFilter): ?>
            <a href="<?= htmlspecialchars(getBasePath()) ?>admin/nfc/batch.php" class="text-blue-600 hover:underline text-sm">&larr; All batches</a>
        <?php endif; ?>
    </div>

    <?php if ($message): ?>
        <div class="mb-4 p-3 rounded-lg <?= $messageType === 'error' ? 'bg-red-50 text-red-700' : 'bg-green-50 text-green-700' ?>">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <?php if (!$batchFilter): ?>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-8">
        <form method="POST" class="bg-white rounded-xl shadow p-5">
            <h3 class="font-semibold mb-2">All Employees</h3>
            <p class="text-sm text-gray-500 mb-3">Create a batch with QR codes for every employee in the company.</p>
            <input type="hidden" name="action" value="all">
            <button class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg">Create batch</button>
        </form>
        <form method="POST" enctype="multipart/form-data" class="bg-white rounded-xl shadow p-5">
            <h3 class="font-semibold mb-2">CSV Upload</h3>
            <p class="text-sm text-gray-500 mb-3">One employee email or ID per line.</p>
            <input type="hidden" name="action" value="csv">
            <input type="file" name="csv" accept=".csv,text/csv" required class="block w-full text-sm mb-3">
            <button class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg">Upload &amp; create batch</button>
        </form>
    </div>

    <h2 class="text-lg font-semibold mb-3">Recent batches</h2>
    <div class="bg-white rounded-xl shadow overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-left text-gray-600">
                <tr><th class="p-3">Batch</th><th class="p-3">Created</th><th class="p-3">Progress</th><th class="p-3"></th></tr>
            </thead>
            <tbody>
            <?php if (empty($batches)): ?>
                <tr><td colspan="4" class="p-6 text-center text-gray-400">No batches yet.</td></tr>
            <?php else: foreach ($batches as $b): $pct = $b['total'] > 0 ? round(100 * $b['programmed'] / $b['total']) : 0; ?>
                <tr class="border-t">
                    <td class="p-3 font-mono text-xs"><?= htmlspecialchars($b['batch_id']) ?></td>
                    <td class="p-3 text-gray-500"><?= htmlspecialchars($b['created_at']) ?></td>
                    <td class="p-3">
                        <div class="flex items-center gap-2">
                            <div class="flex-1 bg-gray-100 rounded-full h-2 overflow-hidden">
                                <div class="bg-green-500 h-full" style="width: <?= $pct ?>%"></div>
                            </div>
                            <span class="text-xs text-gray-600 w-20 text-right"><?= (int)$b['programmed'] ?> / <?= (int)$b['total'] ?></span>
                        </div>
                    </td>
                    <td class="p-3 text-right">
                        <a href="?batch=<?= urlencode($b['batch_id']) ?>" class="text-blue-600 hover:underline">Open</a>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <?php else: ?>
    <?php
    $total = count($batchEmployees);
    $done = 0;
    foreach ($batchEmployees as $r) if (!empty($r['programmed'])) $done++;
    $pct = $total ? round(100 * $done / $total) : 0;

    $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'cardify.om';
    $writerBase = $scheme . '://' . $host . getBasePath() . 'admin/nfc/write.php';
    ?>
    <div class="bg-white rounded-xl shadow p-5 mb-6">
        <div class="flex items-center justify-between mb-3">
            <div>
                <h2 class="font-semibold">Batch <span class="font-mono text-sm text-gray-500"><?= htmlspecialchars($batchFilter) ?></span></h2>
                <p class="text-sm text-gray-500"><?= $done ?> / <?= $total ?> programmed (<?= $pct ?>%)</p>
            </div>
            <button onclick="window.print()" class="px-3 py-2 bg-gray-100 hover:bg-gray-200 rounded-lg text-sm">Print QR sheet</button>
        </div>
        <div class="bg-gray-100 rounded-full h-2 overflow-hidden">
            <div class="bg-green-500 h-full" style="width: <?= $pct ?>%"></div>
        </div>
    </div>

    <div id="qr-grid" class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-4 print:grid-cols-3">
    <?php foreach ($batchEmployees as $row): if (!$row['emp_name']) continue; ?>
        <?php $writeUrl = $writerBase . '?eid=' . urlencode($row['employee_id']) . '&batch=' . urlencode($batchFilter); ?>
        <div class="bg-white rounded-xl shadow p-3 text-center break-inside-avoid">
            <div class="qr-target mx-auto mb-2" data-url="<?= htmlspecialchars($writeUrl) ?>"></div>
            <div class="text-sm font-semibold text-gray-900 truncate"><?= htmlspecialchars($row['emp_name']) ?></div>
            <div class="text-xs text-gray-500 truncate"><?= htmlspecialchars($row['emp_title'] ?? '') ?></div>
            <?php if ($row['programmed']): ?>
                <span class="inline-block mt-1 text-xs px-2 py-0.5 rounded-full bg-green-100 text-green-700">Programmed</span>
            <?php else: ?>
                <span class="inline-block mt-1 text-xs px-2 py-0.5 rounded-full bg-gray-100 text-gray-600">Pending</span>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
    </div>

    <script src="<?= htmlspecialchars(getBasePath()) ?>assets/js/qrcode-generator-1.4.4.min.js"></script>
    <script>
    document.querySelectorAll('.qr-target').forEach(function (el) {
        var url = el.getAttribute('data-url');
        var qr = qrcode(0, 'M');
        qr.addData(url);
        qr.make();
        el.innerHTML = qr.createImgTag(4, 4);
        var img = el.querySelector('img');
        if (img) { img.style.width = '100%'; img.style.height = 'auto'; img.style.maxWidth = '180px'; }
    });
    </script>
    <style>
    @media print {
        aside, nav, header, .print\:hidden { display: none !important; }
        body { background: white !important; }
    }
    </style>
    <?php endif; ?>
</div>
<?php adminFooter(); ?>
