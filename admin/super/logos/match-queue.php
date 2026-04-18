<?php
/**
 * Super Admin — Fuzzy-match review queue.
 * Shows rows where seed's fuzzy match landed in 0.75–0.89 band.
 */
require_once __DIR__ . '/../../../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/admin-layout.php';

Auth::requireRole('super_admin');

$db = Database::getInstance();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && validateCSRFToken($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';
    $id     = (int) ($_POST['id'] ?? 0);
    if ($action === 'confirm' && $id) {
        $db->getConnection()->prepare(
            "UPDATE om_companies SET logo_match_pending = 0 WHERE id = :id"
        )->execute([':id' => $id]);
    } elseif ($action === 'reject' && $id) {
        $db->getConnection()->prepare(
            "UPDATE om_companies SET
                logo_status = 'none',
                logo_svg_path = NULL, logo_png_path = NULL, logo_webp_path = NULL,
                logo_png_512_path = NULL, logo_png_2048_path = NULL,
                logo_match_pending = 0
             WHERE id = :id"
        )->execute([':id' => $id]);
    }
    header('Location: /admin/super/logos/match-queue.php');
    exit;
}

$rows = $db->fetchAll(
    "SELECT id, slug, name_en, name_ar, sector, logo_source_url, logo_png_path, logo_svg_path
     FROM om_companies
     WHERE logo_match_pending = 1
     ORDER BY id DESC LIMIT 200"
);
$csrfToken = generateCSRFToken();

adminHeader('Match queue');

function esc($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
?>
<h1 class="text-2xl font-bold">Match queue (<?= count($rows) ?>)</h1>
<p class="text-sm text-gray-600 mt-1">
  Fuzzy-matched 0.75–0.89 confidence band. Confirm → logo stays linked.
  Reject → unlink and clear files.
</p>

<div class="mt-6 grid grid-cols-1 md:grid-cols-2 gap-4">
  <?php foreach ($rows as $r): $src = $r['logo_svg_path'] ?: $r['logo_png_path']; ?>
    <div class="flex items-center gap-4 bg-white border rounded p-3">
      <?php if ($src): ?>
        <img src="<?= esc($src) ?>" class="w-16 h-16 object-contain bg-gray-50 p-1 rounded">
      <?php else: ?>
        <div class="w-16 h-16 bg-gray-100 rounded flex items-center justify-center text-gray-400">?</div>
      <?php endif; ?>
      <div class="flex-1 text-sm">
        <div class="font-semibold"><?= esc($r['name_en']) ?></div>
        <div class="text-gray-500"><?= esc($r['name_ar']) ?></div>
        <div class="text-xs mt-1">
          sector: <?= esc($r['sector']) ?> ·
          <?php if ($r['logo_source_url']): ?>
            <a class="underline" target="_blank" href="<?= esc($r['logo_source_url']) ?>">source</a> ·
          <?php endif; ?>
          <a class="underline" target="_blank" href="/companies/<?= esc($r['slug']) ?>">profile</a>
        </div>
      </div>
      <form method="post" class="flex gap-2">
        <input type="hidden" name="csrf_token" value="<?= esc($csrfToken) ?>">
        <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
        <button name="action" value="confirm" class="px-3 py-1.5 bg-green-600 text-white text-sm rounded">Confirm</button>
        <button name="action" value="reject" class="px-3 py-1.5 bg-gray-200 text-sm rounded">Reject</button>
      </form>
    </div>
  <?php endforeach; ?>

  <?php if (!$rows): ?>
    <p class="col-span-2 text-gray-500">Queue empty.</p>
  <?php endif; ?>
</div>
<?php adminFooter();
