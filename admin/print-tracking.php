<?php
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/admin-layout.php';

requireAdmin();
$companyId = getCurrentCompanyId();
if (!$companyId) { header('Location: ' . getBasePath() . 'login.php'); exit; }

$basePath = getAdminBasePath();
$isCompanyAdmin = defined('COMPANY_ADMIN_BASE') || !empty($_SESSION['company_slug']);
$ext = $isCompanyAdmin ? '' : '.php';

$db = Database::getInstance();
$userId = (string)(Auth::getCurrentUser()['id'] ?? ($_SESSION['user_id'] ?? ''));
$message = '';
$error = '';

/**
 * Store an uploaded attachment (PO / delivery note / proof). Returns the
 * web-relative path or '' when no/invalid file. Real-MIME validated.
 */
function pt_store_attachment(string $companyId, string $field): string {
    if (empty($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return '';
    }
    $f = $_FILES[$field];
    if ($f['error'] !== UPLOAD_ERR_OK) { return ''; }
    if ($f['size'] > 15 * 1024 * 1024) { return ''; } // 15MB cap
    $finfo = new \finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($f['tmp_name']);
    $map = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];
    if (!isset($map[$mime])) { return ''; }
    $dir = COMPANIES_UPLOADS_DIR . '/' . $companyId . '/print-tracking';
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    $name = bin2hex(random_bytes(8)) . '.' . $map[$mime];
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) { return ''; }
    return 'uploads/companies/' . $companyId . '/print-tracking/' . $name;
}

// ---- POST handlers ----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRFToken($_POST['csrf_token'] ?? '');
    $action = $_POST['action'] ?? '';

    if ($action === 'add_po') {
        $qty = max(0, (int)($_POST['quantity'] ?? 0));
        if ($qty <= 0) {
            $error = 'Enter a quantity greater than zero.';
        } else {
            $unitPrice = (float)($_POST['unit_price'] ?? 0);
            $db->insert('company_print_pos', [
                'id'              => generateUUID(),
                'company_id'      => $companyId,
                'po_number'       => trim((string)($_POST['po_number'] ?? '')) ?: null,
                'quantity'        => $qty,
                'unit_price'      => $unitPrice > 0 ? round($unitPrice, 4) : null,
                'note'            => trim((string)($_POST['note'] ?? '')) ?: null,
                'attachment_path' => pt_store_attachment($companyId, 'attachment') ?: null,
                'created_by'      => $userId,
            ]);
            $message = 'PO added.';
            }
    } elseif ($action === 'bill_po') {
        // Raise ONE consolidated invoice in BHD-ERP for all unbilled production
        // orders on this PO (Layer 2). No double-billing (the ERP marks the
        // source orders converted).
        require_once INCLUDES_DIR . '/ERPSync.php';
        $poNum  = trim((string)($_POST['po_number'] ?? ''));
        $comp   = $db->fetchOne("SELECT name, erp_client_name FROM companies WHERE id = :c", ['c' => $companyId]);
        $client = trim((string)($comp['erp_client_name'] ?? '')) ?: trim((string)($comp['name'] ?? ''));
        if ($poNum === '' || $client === '') {
            $error = 'Need a PO number and an ERP client to bill.';
        } else {
            $r = ERPSync::createConsolidatedInvoice($client, $poNum);
            if (!empty($r['success'])) {
                $n = (int)($r['data']['invoiced'] ?? 0);
                $message = $n > 0
                    ? ('Invoiced ' . $n . ' production order(s): ' . ($r['data']['invoiceNumber'] ?? ''))
                    : 'Nothing new to bill on this PO (all production orders already invoiced).';
            } else {
                $error = 'Billing failed: ' . ($r['message'] ?? 'unknown error');
            }
        }
    } elseif ($action === 'log_run') {
        $qty = max(0, (int)($_POST['quantity'] ?? 0));
        if ($qty <= 0) {
            $error = 'Enter a printed quantity greater than zero.';
        } else {
            $empId = trim((string)($_POST['employee_id'] ?? ''));
            $db->insert('company_print_runs', [
                'id'              => generateUUID(),
                'company_id'      => $companyId,
                'employee_id'     => $empId !== '' ? $empId : null,
                'quantity'        => $qty,
                'note'            => trim((string)($_POST['note'] ?? '')) ?: null,
                'attachment_path' => pt_store_attachment($companyId, 'attachment') ?: null,
                'created_by'      => $userId,
            ]);
            $message = 'Print run logged.';
        }
    } elseif ($action === 'delete_run') {
        $db->delete('company_print_runs', 'id = :id AND company_id = :cid',
            ['id' => (string)($_POST['id'] ?? ''), 'cid' => $companyId]);
        $message = 'Print run removed.';
    } elseif ($action === 'delete_po') {
        $db->delete('company_print_pos', 'id = :id AND company_id = :cid',
            ['id' => (string)($_POST['id'] ?? ''), 'cid' => $companyId]);
        $message = 'PO removed.';
    }
}

// ---- Data -------------------------------------------------------------------
$pos = $db->fetchAll("SELECT * FROM company_print_pos WHERE company_id = :cid ORDER BY created_at DESC", ['cid' => $companyId]);
$runs = $db->fetchAll(
    "SELECT r.*, e.name_en AS emp_name_en, e.name_ar AS emp_name_ar
     FROM company_print_runs r
     LEFT JOIN employees e ON e.id = r.employee_id
     WHERE r.company_id = :cid ORDER BY r.created_at DESC",
    ['cid' => $companyId]
);
$employees = $db->fetchAll("SELECT id, name_en FROM employees WHERE company_id = :cid ORDER BY name_en", ['cid' => $companyId]);

$totalOrdered = 0; foreach ($pos as $p) { $totalOrdered += (int)$p['quantity']; }
$totalPrinted = 0; foreach ($runs as $r) { $totalPrinted += (int)$r['quantity']; }
$remaining = $totalOrdered - $totalPrinted;
$pct = $totalOrdered > 0 ? min(100, round($totalPrinted / $totalOrdered * 100)) : 0;

adminHeader(t('print_tracking.title'), 'print-tracking');
?>

<div class="max-w-6xl mx-auto px-4 sm:px-6 py-6" x-data="{ poModal:false, runModal:false }">

    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900"><?= htmlspecialchars(t('print_tracking.title')) ?></h1>
            <p class="text-gray-500 text-sm mt-1"><?= htmlspecialchars(t('print_tracking.subtitle')) ?></p>
        </div>
        <div class="flex gap-2">
            <button @click="poModal=true" class="px-4 py-2 bg-gray-50 text-gray-700 border border-gray-200 rounded-lg hover:bg-gray-100 text-sm font-medium flex items-center gap-2">
                <i class="fa-solid fa-file-circle-plus"></i><?= htmlspecialchars(t('print_tracking.add_po')) ?>
            </button>
            <button @click="runModal=true" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 text-sm font-medium flex items-center gap-2">
                <i class="fa-solid fa-print"></i><?= htmlspecialchars(t('print_tracking.log_run')) ?>
            </button>
            <?php $primaryPo = ''; foreach ($pos as $p) { if (!empty($p['po_number'])) { $primaryPo = (string)$p['po_number']; break; } } ?>
            <?php if ($primaryPo !== ''): ?>
            <form method="post" onsubmit="return confirm('Raise one consolidated BHD invoice for all unbilled production orders on PO <?= htmlspecialchars($primaryPo, ENT_QUOTES) ?>?');">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="bill_po">
                <input type="hidden" name="po_number" value="<?= htmlspecialchars($primaryPo, ENT_QUOTES) ?>">
                <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 text-sm font-medium flex items-center gap-2">
                    <i class="fa-solid fa-file-invoice-dollar"></i>Bill this PO
                </button>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($message): ?>
    <div class="mb-4 px-4 py-3 rounded-lg bg-green-50 text-green-700 text-sm"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="mb-4 px-4 py-3 rounded-lg bg-red-50 text-red-700 text-sm"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <!-- Summary cards -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <p class="text-gray-500 text-sm"><?= htmlspecialchars(t('print_tracking.ordered')) ?></p>
            <p class="text-3xl font-bold text-gray-900 mt-1"><?= number_format($totalOrdered) ?></p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <p class="text-gray-500 text-sm"><?= htmlspecialchars(t('print_tracking.printed')) ?></p>
            <p class="text-3xl font-bold text-blue-600 mt-1"><?= number_format($totalPrinted) ?></p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <p class="text-gray-500 text-sm"><?= htmlspecialchars(t('print_tracking.remaining')) ?></p>
            <p class="text-3xl font-bold <?= $remaining < 0 ? 'text-red-600' : 'text-green-600' ?> mt-1"><?= number_format($remaining) ?></p>
        </div>
    </div>

    <!-- Progress -->
    <?php if ($totalOrdered > 0): ?>
    <div class="bg-white rounded-xl border border-gray-200 p-5 mb-6">
        <div class="flex justify-between text-sm text-gray-600 mb-2">
            <span><?= number_format($totalPrinted) ?> / <?= number_format($totalOrdered) ?></span>
            <span><?= $pct ?>%</span>
        </div>
        <div class="w-full bg-gray-100 rounded-full h-3 overflow-hidden">
            <div class="h-3 rounded-full <?= $remaining < 0 ? 'bg-red-500' : 'bg-blue-600' ?>" style="width: <?= $pct ?>%"></div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Print runs table -->
    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden mb-6">
        <div class="px-5 py-4 border-b border-gray-100">
            <h2 class="font-semibold text-gray-900"><?= htmlspecialchars(t('print_tracking.runs')) ?></h2>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
                    <tr>
                        <th class="px-5 py-3 text-left"><?= htmlspecialchars(t('print_tracking.date')) ?></th>
                        <th class="px-5 py-3 text-left"><?= htmlspecialchars(t('print_tracking.employee')) ?></th>
                        <th class="px-5 py-3 text-right"><?= htmlspecialchars(t('print_tracking.qty')) ?></th>
                        <th class="px-5 py-3 text-left"><?= htmlspecialchars(t('print_tracking.note')) ?></th>
                        <th class="px-5 py-3 text-left"><?= htmlspecialchars(t('print_tracking.attachment')) ?></th>
                        <th class="px-5 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php if (empty($runs)): ?>
                    <tr><td colspan="6" class="px-5 py-10 text-center text-gray-400"><?= htmlspecialchars(t('print_tracking.no_runs')) ?></td></tr>
                    <?php endif; ?>
                    <?php foreach ($runs as $r): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-5 py-3 text-gray-600 whitespace-nowrap"><?= htmlspecialchars(date('d M Y', strtotime($r['created_at']))) ?></td>
                        <td class="px-5 py-3 text-gray-900"><?= htmlspecialchars($r['emp_name_en'] ?? '') ?: '<span class="text-gray-400">-</span>' ?></td>
                        <td class="px-5 py-3 text-right font-semibold text-gray-900"><?= number_format((int)$r['quantity']) ?></td>
                        <td class="px-5 py-3 text-gray-600"><?= htmlspecialchars($r['note'] ?? '') ?: '<span class="text-gray-300">-</span>' ?></td>
                        <td class="px-5 py-3">
                            <?php if (!empty($r['attachment_path'])): ?>
                            <a href="<?= htmlspecialchars(getBasePath() . $r['attachment_path'], ENT_QUOTES) ?>" target="_blank" class="text-blue-600 hover:underline inline-flex items-center gap-1">
                                <i class="fa-solid fa-paperclip"></i><?= htmlspecialchars(t('print_tracking.view')) ?>
                            </a>
                            <?php else: ?><span class="text-gray-300">-</span><?php endif; ?>
                        </td>
                        <td class="px-5 py-3 text-right">
                            <form method="post" class="inline" onsubmit="return confirm('Remove this print run?')">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="delete_run">
                                <input type="hidden" name="id" value="<?= htmlspecialchars($r['id'], ENT_QUOTES) ?>">
                                <button type="submit" class="text-gray-400 hover:text-red-600 p-1" title="<?= htmlspecialchars(t('print_tracking.remove')) ?>"><i class="fa-solid fa-trash-can"></i></button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- POs list -->
    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="px-5 py-4 border-b border-gray-100">
            <h2 class="font-semibold text-gray-900"><?= htmlspecialchars(t('print_tracking.pos')) ?></h2>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
                    <tr>
                        <th class="px-5 py-3 text-left"><?= htmlspecialchars(t('print_tracking.date')) ?></th>
                        <th class="px-5 py-3 text-left"><?= htmlspecialchars(t('print_tracking.po_number')) ?></th>
                        <th class="px-5 py-3 text-right"><?= htmlspecialchars(t('print_tracking.qty')) ?></th>
                        <th class="px-5 py-3 text-left"><?= htmlspecialchars(t('print_tracking.note')) ?></th>
                        <th class="px-5 py-3 text-left"><?= htmlspecialchars(t('print_tracking.attachment')) ?></th>
                        <th class="px-5 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php if (empty($pos)): ?>
                    <tr><td colspan="6" class="px-5 py-10 text-center text-gray-400"><?= htmlspecialchars(t('print_tracking.no_pos')) ?></td></tr>
                    <?php endif; ?>
                    <?php foreach ($pos as $p): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-5 py-3 text-gray-600 whitespace-nowrap"><?= htmlspecialchars(date('d M Y', strtotime($p['created_at']))) ?></td>
                        <td class="px-5 py-3 text-gray-900"><?= htmlspecialchars($p['po_number'] ?? '') ?: '<span class="text-gray-300">-</span>' ?></td>
                        <td class="px-5 py-3 text-right font-semibold text-gray-900"><?= number_format((int)$p['quantity']) ?></td>
                        <td class="px-5 py-3 text-gray-600"><?= htmlspecialchars($p['note'] ?? '') ?: '<span class="text-gray-300">-</span>' ?></td>
                        <td class="px-5 py-3">
                            <?php if (!empty($p['attachment_path'])): ?>
                            <a href="<?= htmlspecialchars(getBasePath() . $p['attachment_path'], ENT_QUOTES) ?>" target="_blank" class="text-blue-600 hover:underline inline-flex items-center gap-1">
                                <i class="fa-solid fa-paperclip"></i><?= htmlspecialchars(t('print_tracking.view')) ?>
                            </a>
                            <?php else: ?><span class="text-gray-300">-</span><?php endif; ?>
                        </td>
                        <td class="px-5 py-3 text-right">
                            <form method="post" class="inline" onsubmit="return confirm('Remove this PO?')">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="delete_po">
                                <input type="hidden" name="id" value="<?= htmlspecialchars($p['id'], ENT_QUOTES) ?>">
                                <button type="submit" class="text-gray-400 hover:text-red-600 p-1" title="<?= htmlspecialchars(t('print_tracking.remove')) ?>"><i class="fa-solid fa-trash-can"></i></button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Add PO modal -->
    <div x-show="poModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" @keydown.escape.window="poModal=false">
        <div class="absolute inset-0 bg-gray-900/50" @click="poModal=false"></div>
        <div class="relative bg-white rounded-2xl shadow-xl w-full max-w-lg">
            <div class="p-6 border-b border-gray-100"><h3 class="text-lg font-bold text-gray-900"><?= htmlspecialchars(t('print_tracking.add_po')) ?></h3></div>
            <form method="post" enctype="multipart/form-data" class="p-6 space-y-4">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="add_po">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1"><?= htmlspecialchars(t('print_tracking.quantity')) ?> *</label>
                    <input type="number" name="quantity" min="1" required placeholder="50000" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1"><?= htmlspecialchars(t('print_tracking.po_number')) ?></label>
                    <input type="text" name="po_number" placeholder="PO-2026-001" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Price per card (OMR, ex-VAT)</label>
                    <input type="number" name="unit_price" min="0" step="0.0001" placeholder="0.040" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <p class="text-xs text-gray-400 mt-1">Used when sending cards to print + billing the PO. 5% VAT is added by the ERP.</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1"><?= htmlspecialchars(t('print_tracking.note')) ?></label>
                    <input type="text" name="note" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1"><?= htmlspecialchars(t('print_tracking.attachment')) ?> <span class="text-gray-400">(PDF/JPG/PNG)</span></label>
                    <input type="file" name="attachment" accept=".pdf,image/jpeg,image/png" class="w-full text-sm">
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" @click="poModal=false" class="px-4 py-2 text-gray-600 hover:bg-gray-100 rounded-lg text-sm"><?= htmlspecialchars(t('print_tracking.cancel')) ?></button>
                    <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 text-sm font-medium"><?= htmlspecialchars(t('print_tracking.save')) ?></button>
                </div>
            </form>
        </div>
    </div>

    <!-- Log run modal -->
    <div x-show="runModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" @keydown.escape.window="runModal=false">
        <div class="absolute inset-0 bg-gray-900/50" @click="runModal=false"></div>
        <div class="relative bg-white rounded-2xl shadow-xl w-full max-w-lg">
            <div class="p-6 border-b border-gray-100"><h3 class="text-lg font-bold text-gray-900"><?= htmlspecialchars(t('print_tracking.log_run')) ?></h3></div>
            <form method="post" enctype="multipart/form-data" class="p-6 space-y-4">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="log_run">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1"><?= htmlspecialchars(t('print_tracking.quantity')) ?> *</label>
                    <input type="number" name="quantity" min="1" required placeholder="100" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1"><?= htmlspecialchars(t('print_tracking.employee')) ?></label>
                    <select name="employee_id" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                        <option value=""><?= htmlspecialchars(t('print_tracking.employee_general')) ?></option>
                        <?php foreach ($employees as $e): ?>
                        <option value="<?= htmlspecialchars($e['id'], ENT_QUOTES) ?>"><?= htmlspecialchars($e['name_en'] ?? '') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1"><?= htmlspecialchars(t('print_tracking.note')) ?></label>
                    <input type="text" name="note" placeholder="for Vishal" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1"><?= htmlspecialchars(t('print_tracking.attachment')) ?> <span class="text-gray-400">(PDF/JPG/PNG)</span></label>
                    <input type="file" name="attachment" accept=".pdf,image/jpeg,image/png" class="w-full text-sm">
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" @click="runModal=false" class="px-4 py-2 text-gray-600 hover:bg-gray-100 rounded-lg text-sm"><?= htmlspecialchars(t('print_tracking.cancel')) ?></button>
                    <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 text-sm font-medium"><?= htmlspecialchars(t('print_tracking.save')) ?></button>
                </div>
            </form>
        </div>
    </div>

</div>

<?php adminFooter(); ?>
