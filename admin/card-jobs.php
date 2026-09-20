<?php
/**
 * Card jobs: one console for every card order and every document it carries.
 *
 * A division approver sees their own division. BHD staff, and anyone given
 * viewer_scope 'all', see every division. Nothing here mutates a job: the flow
 * moves on its own, and this is where you find out where it has reached and why.
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/admin-layout.php';
require_once INCLUDES_DIR . '/CardJob.php';

requireAdmin();
$companyId = getCurrentCompanyId();
if (!$companyId) { header('Location: ' . getBasePath() . 'login.php'); exit; }

$db        = Database::getInstance();
$basePath  = getAdminBasePath();
$isCompany = defined('COMPANY_ADMIN_BASE') || !empty($_SESSION['company_slug']);
$ext       = $isCompany ? '' : '.php';

// Who sees what. viewer_scope lives on the user; a super admin and a company
// admin see everything in the company they are looking at, an approver sees the
// divisions they are responsible for.
$me    = strtolower(trim((string)($_SESSION['user_email'] ?? '')));
$role  = Auth::getCurrentRole() ?? 'admin';

// A session that came from a link in an approval email belongs to one
// division's approver, whatever role it carries. It used to read as a tenant
// admin, which handed every division's jobs, purchase orders and invoices to
// anyone who clicked an approval link.
$fromMagicLink = !empty($_SESSION['magic_link']);
if ($fromMagicLink) {
    $me = strtolower(trim((string)($_SESSION['magic_link_email'] ?? $me)));
}

$scope = 'division';
if (!$fromMagicLink && in_array($role, ['super_admin', 'admin', 'company'], true)) {
    $scope = 'all';
} elseif (!$fromMagicLink) {
    try {
        $u = $db->fetchOne("SELECT viewer_scope FROM users WHERE LOWER(email) = :e LIMIT 1", ['e' => $me]);
        if (($u['viewer_scope'] ?? '') === 'all') { $scope = 'all'; }
    } catch (Throwable $e) { /* column added by migration 159 */ }
}

// Closing the job. Printed cards are dispatched and then delivered, and until
// now nothing wrote either state, so every finished job sat at in_production
// for ever. Only a viewer who sees the whole tenant may close one.
$notice = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        http_response_code(400);
        exit('Invalid request');
    }
    $moveId = (string)($_POST['job_id'] ?? '');
    $moveTo = (string)($_POST['move_to'] ?? '');
    $canClose = !$fromMagicLink && in_array($role, ['super_admin', 'admin', 'company'], true);
    if ($canClose && in_array($moveTo, ['dispatched', 'delivered'], true)) {
        $owned = $db->fetchOne("SELECT id FROM card_requests WHERE id = :i AND company_id = :c",
                               ['i' => $moveId, 'c' => $companyId]);
        if ($owned && CardJob::transition($moveId, $moveTo, ['actor' => $me ?: 'admin'])) {
            $notice = t('cardjobs.moved_' . $moveTo);
        } else {
            $notice = t('cardjobs.move_refused');
        }
    }
}

$q      = trim((string)($_GET['q'] ?? ''));
$where  = "cr.company_id = :cid AND cr.deleted_at IS NULL";
$params = ['cid' => $companyId];
if ($scope !== 'all') {
    $where .= " AND (LOWER(d.responsible_email) = :me OR LOWER(d.head_email) = :me2)";
    $params['me']  = $me;
    $params['me2'] = $me;
}
if ($q !== '') {
    $where .= " AND (cr.name_en LIKE :q OR cr.name_ar LIKE :q2 OR d.name LIKE :q3
                     OR cr.job_ref LIKE :q4 OR cr.po_number LIKE :q5)";
    foreach (['q', 'q2', 'q3', 'q4', 'q5'] as $k) { $params[$k] = '%' . $q . '%'; }
}

$jobs = $db->fetchAll(
    "SELECT cr.*, d.name AS division, d.responsible_email,
            po.erp_invoice_number, po.erp_quote_id, po.erp_invoice_id,
            po.delivery_note_external_id, po.total AS order_total
       FROM card_requests cr
       LEFT JOIN departments  d  ON d.id  = cr.department_id
       LEFT JOIN print_orders po ON po.id = cr.erp_order_id
      WHERE {$where}
      ORDER BY cr.submitted_at DESC
      LIMIT 200", $params);

$stateLabel = function (string $s): string {
    $key = 'cardjobs.state_' . $s;
    $out = t($key);
    return $out === $key ? ucfirst(str_replace('_', ' ', $s)) : $out;
};
$stateClass = [
    'submitted'     => 'bg-amber-100 text-amber-800',
    'approved'      => 'bg-blue-100 text-blue-800',
    'quoted'        => 'bg-indigo-100 text-indigo-800',
    'po_received'   => 'bg-purple-100 text-purple-800',
    'in_production' => 'bg-cyan-100 text-cyan-800',
    'dispatched'    => 'bg-teal-100 text-teal-800',
    'delivered'     => 'bg-emerald-100 text-emerald-800',
    'rejected'      => 'bg-rose-100 text-rose-800',
];

adminHeader(t('cardjobs.title'), 'orders');
?>
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <header class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900"><?= htmlspecialchars(t('cardjobs.title')) ?></h1>
        <p class="text-sm text-gray-500 mt-1"><?= htmlspecialchars(t('cardjobs.lead')) ?></p>
        <p class="text-xs text-gray-400 mt-1">
            <?= htmlspecialchars($scope === 'all' ? t('cardjobs.scope_all') : t('cardjobs.scope_division')) ?>
            &middot; <?= count($jobs) ?> <?= htmlspecialchars(t('cardjobs.total_jobs')) ?>
        </p>
    </header>

    <?php if ($notice): ?>
        <div class="mb-5 px-4 py-3 rounded-xl bg-blue-50 text-blue-800 text-sm"><?= htmlspecialchars($notice) ?></div>
    <?php endif; ?>

    <form method="GET" class="mb-5">
        <?php if (!$isCompany): ?><input type="hidden" name="page" value="card-jobs"><?php endif; ?>
        <input type="search" name="q" value="<?= htmlspecialchars($q, ENT_QUOTES) ?>"
               placeholder="<?= htmlspecialchars(t('cardjobs.search'), ENT_QUOTES) ?>"
               class="w-full sm:w-96 px-4 py-2 border border-gray-200 rounded-xl text-sm">
    </form>

    <?php if (!$jobs): ?>
        <div class="p-8 bg-white rounded-2xl border border-gray-200 text-center text-gray-500">
            <?= htmlspecialchars(t('cardjobs.none')) ?>
        </div>
    <?php else: ?>
        <div class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
            <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-gray-500">
                    <tr>
                        <th class="text-start px-4 py-3 font-medium"><?= htmlspecialchars(t('cardjobs.employee')) ?></th>
                        <th class="text-start px-4 py-3 font-medium"><?= htmlspecialchars(t('cardjobs.division')) ?></th>
                        <th class="text-start px-4 py-3 font-medium"><?= htmlspecialchars(t('cardjobs.state')) ?></th>
                        <th class="text-start px-4 py-3 font-medium"><?= htmlspecialchars(t('cardjobs.quantity')) ?></th>
                        <th class="text-start px-4 py-3 font-medium"><?= htmlspecialchars(t('cardjobs.po')) ?></th>
                        <th class="text-start px-4 py-3 font-medium"><?= htmlspecialchars(t('cardjobs.invoice')) ?></th>
                        <th class="text-start px-4 py-3 font-medium"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                <?php foreach ($jobs as $j):
                    $state  = (string)($j['fulfilment_state'] ?? 'submitted');
                    $rowId  = 'job-' . preg_replace('/[^A-Za-z0-9_-]/', '', (string)$j['id']);
                    $events = CardJob::events((string)$j['id']);
                    $fileUrl = function (string $kind) use ($basePath, $ext, $j) {
                        return $basePath . 'card-job-file' . $ext . '?id=' . urlencode((string)$j['id']) . '&kind=' . $kind;
                    };
                ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3">
                            <div class="font-medium text-gray-900"><?= htmlspecialchars((string)($j['name_en'] ?: $j['name_ar'])) ?></div>
                            <div class="text-xs text-gray-400"><?= htmlspecialchars((string)($j['job_ref'] ?? '')) ?></div>
                        </td>
                        <td class="px-4 py-3 text-gray-700"><?= htmlspecialchars((string)($j['division'] ?? '')) ?></td>
                        <td class="px-4 py-3">
                            <span class="px-2 py-1 rounded-full text-xs font-medium <?= $stateClass[$state] ?? 'bg-gray-100 text-gray-700' ?>">
                                <?= htmlspecialchars($stateLabel($state)) ?>
                            </span>
                        </td>
                        <td class="px-4 py-3 text-gray-700">
                            <?= (int)($j['quantity_ordered'] ?? 0) ?: '&mdash;' ?>
                        </td>
                        <td class="px-4 py-3 text-gray-700"><?= htmlspecialchars((string)($j['po_number'] ?? '')) ?: '&mdash;' ?></td>
                        <td class="px-4 py-3 text-gray-700"><?= htmlspecialchars((string)($j['erp_invoice_number'] ?? '')) ?: '&mdash;' ?></td>
                        <td class="px-4 py-3 text-end">
                            <button type="button" class="text-blue-600 text-xs font-medium"
                                    data-card-job-target="<?= htmlspecialchars($rowId, ENT_QUOTES) ?>" aria-expanded="false">
                                <?= htmlspecialchars(t('cardjobs.open')) ?>
                            </button>
                        </td>
                    </tr>
                    <tr id="<?= $rowId ?>" class="hidden bg-gray-50">
                        <td colspan="7" class="px-4 py-4">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <h4 class="text-xs font-semibold text-gray-500 uppercase mb-2"><?= htmlspecialchars(t('cardjobs.documents')) ?></h4>
                                    <ul class="space-y-1 text-sm">
                                        <?php if (!empty($j['erp_quote_id'])): ?>
                                            <li><a class="text-blue-600" href="<?= htmlspecialchars($fileUrl('quote'), ENT_QUOTES) ?>"><?= htmlspecialchars(t('cardjobs.quotation')) ?></a></li>
                                        <?php endif; ?>
                                        <?php if (!empty($j['po_file'])): ?>
                                            <li><a class="text-blue-600" href="<?= htmlspecialchars($fileUrl('po'), ENT_QUOTES) ?>"><?= htmlspecialchars(t('cardjobs.po')) ?></a></li>
                                        <?php endif; ?>
                                        <?php if (!empty($j['erp_invoice_id'])): ?>
                                            <li><a class="text-blue-600" href="<?= htmlspecialchars($fileUrl('invoice'), ENT_QUOTES) ?>"><?= htmlspecialchars(t('cardjobs.invoice')) ?></a></li>
                                        <?php endif; ?>
                                        <?php if (!empty($j['delivery_note_external_id'])): ?>
                                            <li><a class="text-blue-600" href="<?= htmlspecialchars($fileUrl('deliverynote'), ENT_QUOTES) ?>"><?= htmlspecialchars(t('cardjobs.delivery_note')) ?></a></li>
                                            <li><a class="text-blue-600" href="<?= htmlspecialchars($fileUrl('signed'), ENT_QUOTES) ?>"><?= htmlspecialchars(t('cardjobs.signed_dn')) ?></a></li>
                                        <?php endif; ?>
                                        <?php if (!empty($j['employee_id'])): ?>
                                            <li><a class="text-blue-600" href="<?= htmlspecialchars($fileUrl('artwork'), ENT_QUOTES) ?>"><?= htmlspecialchars(t('cardjobs.artwork')) ?></a></li>
                                        <?php endif; ?>
                                    </ul>
                                    <?php if (!empty($j['responsible_email'])): ?>
                                        <p class="text-xs text-gray-400 mt-3"><?= htmlspecialchars((string)$j['responsible_email']) ?></p>
                                    <?php endif; ?>
                                    <?php
                                    $canClose = !$fromMagicLink && in_array($role, ['super_admin', 'admin', 'company'], true);
                                    $next = ['in_production' => 'dispatched', 'dispatched' => 'delivered'][$state] ?? null;
                                    if ($canClose && $next): ?>
                                        <form method="POST" class="mt-4">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="job_id" value="<?= htmlspecialchars((string)$j['id'], ENT_QUOTES) ?>">
                                            <input type="hidden" name="move_to" value="<?= $next ?>">
                                            <button type="submit" class="px-3 py-1.5 rounded-lg bg-gray-900 text-white text-xs font-medium">
                                                <?= htmlspecialchars(t('cardjobs.mark_' . $next)) ?>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <h4 class="text-xs font-semibold text-gray-500 uppercase mb-2"><?= htmlspecialchars(t('cardjobs.history')) ?></h4>
                                    <ol class="space-y-1 text-sm text-gray-600">
                                        <?php foreach ($events as $e): ?>
                                            <li>
                                                <span class="text-gray-400"><?= htmlspecialchars(date('d/m H:i', strtotime((string)$e['created_at']))) ?></span>
                                                <?= htmlspecialchars(str_replace('note:', '', (string)$e['to_state'])) ?>
                                                <?php if (!empty($e['actor'])): ?>
                                                    <span class="text-gray-400">&middot; <?= htmlspecialchars((string)$e['actor']) ?></span>
                                                <?php endif; ?>
                                            </li>
                                        <?php endforeach; ?>
                                        <?php if (!$events): ?><li class="text-gray-400">&mdash;</li><?php endif; ?>
                                    </ol>
                                </div>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
    <?php endif; ?>
</div>
<script<?= cspNonceAttr() ?>>
document.querySelectorAll('[data-card-job-target]').forEach(function (button) {
    button.addEventListener('click', function () {
        const row = document.getElementById(button.dataset.cardJobTarget);
        if (!row) return;
        const hidden = row.classList.toggle('hidden');
        button.setAttribute('aria-expanded', String(!hidden));
    });
});
</script>
<?php adminFooter(); ?>
