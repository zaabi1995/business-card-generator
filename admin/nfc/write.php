<?php
/**
 * NFC Tag Writer — print-tech mobile page (Chrome Android).
 * Loads one employee, exposes a "Tap to program" button which uses the
 * Web NFC API to write the card URL to a held tag.
 */
require_once __DIR__ . '/../../config.php';
requireAdmin();
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/admin-layout.php';

$db = Database::getInstance();
$companyId = getCurrentCompanyId();
$isSuperAdmin = Auth::isSuperAdmin();

$eid = isset($_GET['eid']) ? trim((string)$_GET['eid']) : '';
$batchId = isset($_GET['batch']) ? trim((string)$_GET['batch']) : '';

if ($eid === '') {
    header('Location: ' . getBasePath() . 'admin/nfc/batch.php');
    exit;
}

$employee = findEmployeeById($eid, $isSuperAdmin ? null : $companyId);
if (!$employee) {
    http_response_code(404);
    echo 'Employee not found';
    exit;
}

// Build card URL — match wallet pattern: {host}/{slug}/card/{employeeId}
$company = null;
if (DatabaseAdapter::useDatabase()) {
    $company = $db->fetchOne(
        "SELECT id, slug, name FROM companies WHERE id = :id",
        ['id' => $employee['company_id']]
    );
}
$slug = $company['slug'] ?? ($_SESSION['company_slug'] ?? '');

$scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'cardify.om';
$cardUrl = $scheme . '://' . $host . '/' . rawurlencode($slug) . '/card/' . rawurlencode($employee['id']);

$pageTitle = 'NFC Writer — ' . ($employee['name'] ?? '');
adminHeader($pageTitle, 'nfc-tags');
?>
<style>
@keyframes flashOk { 0% { box-shadow: 0 0 0 0 rgba(34,197,94,.7); } 100% { box-shadow: 0 0 0 40px rgba(34,197,94,0); } }
.flash-ok { animation: flashOk .8s ease-out; }
</style>
<div class="max-w-md mx-auto p-4">
    <div class="bg-white rounded-2xl shadow p-6">
        <h1 class="text-2xl font-bold mb-1"><?= htmlspecialchars($employee['name'] ?? 'Employee') ?></h1>
        <p class="text-gray-500 mb-4"><?= htmlspecialchars($employee['title'] ?? '') ?></p>
        <div class="bg-gray-50 rounded-lg p-3 mb-4 break-all text-sm font-mono text-gray-700" id="cardUrlBox">
            <?= htmlspecialchars($cardUrl) ?>
        </div>

        <div id="unsupportedBox" class="hidden bg-amber-50 border border-amber-200 text-amber-800 rounded-lg p-4 mb-4">
            <strong>Web NFC not available.</strong><br>
            Open this page in <strong>Chrome on Android</strong> to program tags. On desktop, print the QR sheet and scan from your phone.
        </div>

        <button id="writeBtn" class="w-full py-6 bg-blue-600 hover:bg-blue-700 text-white text-xl font-bold rounded-2xl shadow-lg transition active:scale-95">
            Tap to program tag
        </button>

        <div id="status" class="mt-4 text-center text-sm text-gray-600">Hold an NFC tag near your phone after tapping.</div>

        <button id="undoBtn" class="hidden w-full mt-3 py-3 bg-gray-200 hover:bg-gray-300 text-gray-800 rounded-lg">
            Undo last write (5s)
        </button>

        <div class="mt-6 text-center">
            <a href="<?= htmlspecialchars(getBasePath()) ?>admin/nfc/batch.php<?= $batchId ? '?batch=' . urlencode($batchId) : '' ?>" class="text-blue-600 hover:underline text-sm">&larr; Back to batch</a>
        </div>

        <div class="mt-4 text-xs text-gray-400 text-center">
            Programmed: <span id="countBadge">0</span> tag(s) this session
        </div>
    </div>
</div>

<script>
(function () {
    const cardUrl = <?= json_encode($cardUrl) ?>;
    const employeeId = <?= json_encode($employee['id']) ?>;
    const batchId = <?= json_encode($batchId) ?>;
    const csrf = <?= json_encode($_SESSION['csrf_token'] ?? '') ?>;
    const markEndpoint = <?= json_encode(getBasePath() . 'admin/nfc/mark-programmed.php') ?>;

    const btn = document.getElementById('writeBtn');
    const status = document.getElementById('status');
    const unsupported = document.getElementById('unsupportedBox');
    const undoBtn = document.getElementById('undoBtn');
    const countBadge = document.getElementById('countBadge');
    let count = 0;
    let lastRowId = null;
    let undoTimer = null;

    if (!('NDEFReader' in window)) {
        unsupported.classList.remove('hidden');
        btn.disabled = true;
        btn.classList.add('opacity-50', 'cursor-not-allowed');
        return;
    }

    function beep() {
        try {
            const ctx = new (window.AudioContext || window.webkitAudioContext)();
            const o = ctx.createOscillator();
            const g = ctx.createGain();
            o.connect(g); g.connect(ctx.destination);
            o.frequency.value = 880; o.type = 'sine';
            g.gain.setValueAtTime(0.001, ctx.currentTime);
            g.gain.exponentialRampToValueAtTime(0.3, ctx.currentTime + 0.01);
            g.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.18);
            o.start(); o.stop(ctx.currentTime + 0.2);
        } catch (e) {}
    }

    async function markProgrammed(tagUid) {
        try {
            const fd = new FormData();
            fd.append('eid', employeeId);
            fd.append('batch_id', batchId);
            if (tagUid) fd.append('tag_uid', tagUid);
            fd.append('csrf', csrf);
            const r = await fetch(markEndpoint, { method: 'POST', body: fd, credentials: 'same-origin' });
            const j = await r.json();
            if (j.ok && j.id) lastRowId = j.id;
        } catch (e) { console.error('mark failed', e); }
    }

    btn.addEventListener('click', async () => {
        status.textContent = 'Hold a tag against your phone...';
        btn.disabled = true;
        try {
            const ndef = new NDEFReader();
            await ndef.write({ records: [{ recordType: 'url', data: cardUrl }] });
            count++;
            countBadge.textContent = count;
            status.textContent = 'Success — tag programmed.';
            btn.classList.add('flash-ok');
            beep();
            setTimeout(() => btn.classList.remove('flash-ok'), 800);

            await markProgrammed(null);

            // Undo window: 5s
            undoBtn.classList.remove('hidden');
            clearTimeout(undoTimer);
            undoTimer = setTimeout(() => undoBtn.classList.add('hidden'), 5000);
        } catch (err) {
            status.textContent = 'Failed: ' + (err && err.message ? err.message : err);
        } finally {
            btn.disabled = false;
        }
    });

    undoBtn.addEventListener('click', async () => {
        if (!lastRowId) return;
        try {
            const fd = new FormData();
            fd.append('undo_id', lastRowId);
            fd.append('csrf', csrf);
            await fetch(markEndpoint, { method: 'POST', body: fd, credentials: 'same-origin' });
            status.textContent = 'Undone.';
            count = Math.max(0, count - 1);
            countBadge.textContent = count;
            undoBtn.classList.add('hidden');
            lastRowId = null;
        } catch (e) {}
    });
})();
</script>
<?php adminFooter(); ?>
