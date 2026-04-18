<?php
/**
 * Super Admin — Takedown review queue.
 */
require_once __DIR__ . '/../../../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/admin-layout.php';
require_once INCLUDES_DIR . '/LogoTakedownService.php';

Auth::requireRole('super_admin');

$db = Database::getInstance();
$me = Auth::getCurrentUser();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && validateCSRFToken($_POST['csrf_token'] ?? '')) {
    $id     = (int) ($_POST['takedown_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $notes  = trim($_POST['notes'] ?? '');
    $flash  = null;
    if ($action === 'hide' && $id) {
        $res = LogoTakedownService::hideLogo($db, $id, $me['id'], $notes ?: null);
        if (!$res['ok']) $flash = 'hide_failed:' . $res['error'];
    } elseif ($action === 'reject' && $id) {
        LogoTakedownService::reject($db, $id, $me['id'], $notes ?: null);
    }
    // Pass error message as a session flash so the redirect preserves it.
    if ($flash) $_SESSION['logo_takedowns_flash'] = $flash;
    header('Location: /admin/super/logos/takedowns.php');
    exit;
}
$flash = $_SESSION['logo_takedowns_flash'] ?? null;
unset($_SESSION['logo_takedowns_flash']);

$rows = $db->fetchAll(
    "SELECT t.*, co.name_en AS company_name, co.slug AS company_slug
       FROM logo_takedowns t
  LEFT JOIN om_companies co ON co.id = t.company_id
      WHERE t.status IN ('received', 'under_review')
      ORDER BY t.created_at ASC"
);
$csrfToken = generateCSRFToken();

adminHeader('Takedown queue');

function esc($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
?>
<h1 class="text-2xl font-bold">Takedowns (<?= count($rows) ?>)</h1>

<?php if ($flash && str_starts_with($flash, 'hide_failed:')): ?>
  <div class="mt-4 p-3 bg-amber-50 border border-amber-300 rounded text-sm text-amber-900">
    Could not hide logo: <?= esc(substr($flash, strlen('hide_failed:'))) ?>
  </div>
<?php endif; ?>

<div class="space-y-4 mt-6">
  <?php foreach ($rows as $r): ?>
    <div class="bg-white border rounded p-4">
      <div class="flex flex-col md:flex-row justify-between gap-4">
        <div class="flex-1 min-w-0">
          <?php if ($r['company_slug']): ?>
            <a href="/companies/<?= esc($r['company_slug']) ?>" target="_blank"
               class="font-semibold underline"><?= esc($r['company_name']) ?></a>
          <?php else: ?>
            <span class="font-semibold text-amber-700">
              Unmatched company — resolve before deciding
            </span>
          <?php endif; ?>
          <div class="text-sm text-gray-600 mt-1">
            From: <?= esc($r['requester_name']) ?>
            &lt;<?= esc($r['requester_email']) ?>&gt;
            · Role: <?= esc($r['requester_role'] ?: '—') ?>
          </div>
          <div class="text-sm mt-2 whitespace-pre-wrap"><?= esc($r['claim_basis']) ?></div>
          <?php if ($r['related_urls']): ?>
            <div class="text-xs text-gray-500 mt-2"><?= nl2br(esc($r['related_urls'])) ?></div>
          <?php endif; ?>
          <div class="text-xs text-gray-500 mt-2"><?= esc($r['created_at']) ?></div>
        </div>
        <form method="post" class="space-y-2 min-w-[260px]">
          <input type="hidden" name="csrf_token" value="<?= esc($csrfToken) ?>">
          <input type="hidden" name="takedown_id" value="<?= (int) $r['id'] ?>">
          <textarea name="notes" rows="2" placeholder="Resolution notes"
                    class="w-full border rounded px-2 py-1 text-sm"></textarea>
          <div class="flex gap-2">
            <button name="action" value="hide"
                    class="flex-1 px-3 py-1.5 bg-red-600 text-white text-sm rounded">Hide logo</button>
            <button name="action" value="reject"
                    class="flex-1 px-3 py-1.5 bg-gray-200 text-sm rounded">Reject</button>
          </div>
        </form>
      </div>
    </div>
  <?php endforeach; ?>

  <?php if (!$rows): ?>
    <p class="text-gray-500">No open takedowns.</p>
  <?php endif; ?>
</div>
<?php adminFooter();
