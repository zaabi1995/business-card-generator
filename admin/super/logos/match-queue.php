<?php
/**
 * Super Admin, Fuzzy-match review queue.
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
        // Confirm flips logo_status to 'indexed' AND promotes the staged file
        // from /storage/logos/pending/{id}.{ext} into /storage/logos/indexed/.
        // Seeder intentionally staged queued files under pending/ so a verified
        // public logo at /storage/logos/indexed/ couldn't be clobbered on disk.
        //
        // Derived variants (png_512, png_2048) from the OLD logo must be cleared
        //, otherwise public pages keep serving the old 512/2048 renders even
        // after the canonical logo is replaced. Re-render via scripts/render-
        // logo-variants.php --only-missing after confirm.
        $root = dirname(__DIR__, 3);
        // Which extensions does the queued replacement actually provide?
        $pendingExts = [];
        foreach (['svg', 'png', 'webp'] as $ext) {
            if (is_file($root . "/storage/logos/pending/{$id}.{$ext}")) {
                $pendingExts[] = $ext;
            }
        }
        // Delete any EXISTING indexed files that are NOT part of the queued
        // replacement, otherwise confirming a PNG replacement would reattach
        // a stale old SVG because is_file() returns true for it.
        foreach (['svg', 'png', 'webp', 'jpg', 'jpeg'] as $existingExt) {
            if (in_array($existingExt, $pendingExts, true)) continue;
            $stale = $root . "/storage/logos/indexed/{$id}.{$existingExt}";
            if (is_file($stale)) @unlink($stale);
        }
        // Derived size variants are always stale once canonical changes.
        foreach (['512', '2048'] as $size) {
            $stale = $root . "/storage/logos/indexed/{$id}-{$size}.png";
            if (is_file($stale)) @unlink($stale);
        }

        $updates = [
            'logo_png_512_path'  => null,
            'logo_png_2048_path' => null,
            'logo_svg_path'      => null,
            'logo_png_path'      => null,
            'logo_webp_path'     => null,
        ];
        foreach (['svg' => 'logo_svg_path', 'png' => 'logo_png_path', 'webp' => 'logo_webp_path'] as $ext => $col) {
            $pendingRel = "/storage/logos/pending/{$id}.{$ext}";
            $indexedRel = "/storage/logos/indexed/{$id}.{$ext}";
            if (is_file($root . $pendingRel)) {
                @mkdir($root . '/storage/logos/indexed', 0755, true);
                // Overwrite existing indexed file; match-queue is the admin's
                // explicit go-ahead to publish the new asset.
                @rename($root . $pendingRel, $root . $indexedRel);
            }
            if (is_file($root . $indexedRel)) {
                $updates[$col] = $indexedRel;
            }
        }
        $setClauses = ["logo_status = IF(logo_status IN ('verified','takedown'), logo_status, 'indexed')",
                       "logo_match_pending = 0",
                       "logo_updated_at = NOW()"];
        $params = [':id' => $id];
        foreach ($updates as $col => $val) {
            // Use named placeholder per column; NULL passed through.
            $setClauses[] = "$col = :" . $col;
            $params[':' . $col] = $val;
        }
        $db->getConnection()->prepare(
            "UPDATE om_companies SET " . implode(', ', $setClauses) . " WHERE id = :id"
        )->execute($params);
    } elseif ($action === 'reject' && $id) {
        // Delete staged files under /storage/logos/pending/{id}.* that the
        // seeder wrote for this queued row. Public /storage/logos/indexed/
        // files are not touched here, rejecting a queue entry must never
        // delete an already-public logo.
        $root = dirname(__DIR__, 3);
        foreach (['svg', 'png', 'webp', 'jpg', 'jpeg'] as $ext) {
            $abs = $root . "/storage/logos/pending/{$id}.{$ext}";
            if (is_file($abs)) @unlink($abs);
        }
        // Determine if row currently has a LIVE public logo. If so, reject is
        // just "discard the queued replacement", never touch the live asset.
        // Only nuke paths + reset status when there's no existing public logo.
        $row = $db->fetchOne(
            "SELECT logo_status, logo_svg_path, logo_png_path, logo_webp_path
               FROM om_companies WHERE id = :id",
            [':id' => $id]
        ) ?: [];
        $hasLivePath   = !empty($row['logo_svg_path']) || !empty($row['logo_png_path']) || !empty($row['logo_webp_path']);
        $isPublicState = in_array($row['logo_status'] ?? '', ['indexed', 'verified', 'takedown'], true);

        if ($hasLivePath || $isPublicState) {
            // Preserve the existing public logo; just clear the match flag.
            $db->getConnection()->prepare(
                "UPDATE om_companies SET logo_match_pending = 0, logo_updated_at = NOW() WHERE id = :id"
            )->execute([':id' => $id]);
        } else {
            // No public logo to protect, safe to reset.
            $db->getConnection()->prepare(
                "UPDATE om_companies SET
                    logo_status = 'none',
                    logo_svg_path = NULL, logo_png_path = NULL, logo_webp_path = NULL,
                    logo_png_512_path = NULL, logo_png_2048_path = NULL,
                    logo_match_pending = 0,
                    logo_updated_at = NOW()
                 WHERE id = :id"
            )->execute([':id' => $id]);
        }
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

/**
 * Resolve queue preview. For queued rows the seeder intentionally leaves
 * logo_*_path columns alone (so unreviewed logos can't overwrite a public
 * verified one), so we glob the indexed/ directory to find the staged file.
 */
function queuePreviewSrc(int $companyId, ?string $svgPath, ?string $pngPath): ?string {
    $root = dirname(__DIR__, 3);
    // Prefer the staged pending file via admin-auth'd endpoint (nginx blocks
    // direct /storage/logos/pending/ access). Fall back to indexed/ (the
    // CURRENT live logo) so reviewers can compare against what's already
    // published.
    foreach (['svg', 'png', 'webp', 'jpg', 'jpeg'] as $ext) {
        if (is_file($root . "/storage/logos/pending/{$companyId}.{$ext}")) {
            return "/admin/super/logos/pending-preview.php?id={$companyId}&ext={$ext}";
        }
    }
    if ($svgPath) return $svgPath;
    if ($pngPath) return $pngPath;
    foreach (['svg', 'png', 'webp'] as $ext) {
        $rel = "/storage/logos/indexed/{$companyId}.{$ext}";
        if (is_file($root . $rel)) return $rel;
    }
    return null;
}
?>
<h1 class="text-2xl font-bold">Match queue (<?= count($rows) ?>)</h1>
<p class="text-sm text-gray-600 mt-1">
  Fuzzy-matched 0.75–0.89 confidence band. Confirm → logo stays linked.
  Reject → unlink and clear files.
</p>

<div class="mt-6 grid grid-cols-1 md:grid-cols-2 gap-4">
  <?php foreach ($rows as $r): $src = queuePreviewSrc((int) $r['id'], $r['logo_svg_path'], $r['logo_png_path']); ?>
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
