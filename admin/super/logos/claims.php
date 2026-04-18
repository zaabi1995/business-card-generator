<?php
/**
 * Super Admin — Pending claim review queue.
 */
require_once __DIR__ . '/../../../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/admin-layout.php';
require_once INCLUDES_DIR . '/LogoClaimService.php';

Auth::requireRole('super_admin');

$db = Database::getInstance();
$me = Auth::getCurrentUser();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && validateCSRFToken($_POST['csrf_token'] ?? '')) {
    $id       = (int) ($_POST['claim_id'] ?? 0);
    $decision = $_POST['decision'] ?? '';
    $notes    = trim($_POST['notes'] ?? '');
    if (in_array($decision, ['approved', 'rejected'], true) && $id) {
        LogoClaimService::decideClaim($db, $id, $me['id'], $decision, $notes ?: null);
    }
    header('Location: /admin/super/logos/claims.php');
    exit;
}

$rows = $db->fetchAll(
    "SELECT c.*, co.name_en AS company_name, co.slug AS company_slug, u.email AS user_email
       FROM logo_claims c
       JOIN om_companies co ON co.id = c.company_id
  LEFT JOIN users u        ON u.id   = c.user_id
      WHERE c.status = 'pending'
      ORDER BY c.created_at ASC"
);
$csrfToken = generateCSRFToken();

adminHeader('Claim queue');

function esc($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
?>
<h1 class="text-2xl font-bold">Claim queue (<?= count($rows) ?>)</h1>

<div class="space-y-4 mt-6">
  <?php foreach ($rows as $r): ?>
    <div class="bg-white border rounded p-4">
      <div class="flex flex-col md:flex-row justify-between gap-4">
        <div class="flex-1 min-w-0">
          <a href="/companies/<?= esc($r['company_slug']) ?>" target="_blank"
             class="font-semibold underline"><?= esc($r['company_name']) ?></a>
          <div class="text-sm text-gray-600 mt-1">
            Claimant: <?= esc($r['user_email']) ?> ·
            Role: <?= esc($r['role_at_company'] ?: '—') ?> ·
            Proof: <?= esc($r['proof_type']) ?>
          </div>
          <?php if ($r['proof_url']): ?>
            <div class="text-sm mt-1">
              <a class="text-cyan-700 underline" href="<?= esc($r['proof_url']) ?>" target="_blank">
                View proof file
              </a>
            </div>
          <?php endif; ?>
          <?php if ($r['note']): ?>
            <div class="text-sm mt-2 italic">"<?= esc($r['note']) ?>"</div>
          <?php endif; ?>
          <div class="text-xs text-gray-500 mt-2"><?= esc($r['created_at']) ?></div>
        </div>
        <form method="post" class="space-y-2 min-w-[260px]">
          <input type="hidden" name="csrf_token" value="<?= esc($csrfToken) ?>">
          <input type="hidden" name="claim_id" value="<?= (int) $r['id'] ?>">
          <textarea name="notes" rows="2" placeholder="Decision notes"
                    class="w-full border rounded px-2 py-1 text-sm"></textarea>
          <div class="flex gap-2">
            <button name="decision" value="approved"
                    class="flex-1 px-3 py-1.5 bg-green-600 text-white text-sm rounded">Approve</button>
            <button name="decision" value="rejected"
                    class="flex-1 px-3 py-1.5 bg-gray-200 text-sm rounded">Reject</button>
          </div>
        </form>
      </div>
    </div>
  <?php endforeach; ?>

  <?php if (!$rows): ?>
    <p class="text-gray-500">No pending claims.</p>
  <?php endif; ?>
</div>
<?php adminFooter();
