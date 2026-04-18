<?php
/**
 * Super Admin — Per-company logo tools.
 * Upload/replace logo, force verify, revert, hide.
 */
require_once __DIR__ . '/../../../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/admin-layout.php';
require_once INCLUDES_DIR . '/LogoLibrary.php';

Auth::requireRole('super_admin');

$db = Database::getInstance();
$id = (int) ($_GET['id'] ?? 0);
$company = $id
    ? $db->fetchOne("SELECT * FROM om_companies WHERE id = :id", [':id' => $id])
    : null;
if (!$company) {
    http_response_code(404);
    die('Not found');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && validateCSRFToken($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';
    if ($action === 'force_verify') {
        $db->getConnection()->prepare(
            "UPDATE om_companies SET logo_status = 'verified', logo_verified_at = NOW(), logo_updated_at = NOW() WHERE id = :id"
        )->execute([':id' => $id]);
    } elseif ($action === 'revert') {
        $db->getConnection()->prepare(
            "UPDATE om_companies SET
                logo_status = 'indexed',
                logo_claimed_by_user_id = NULL,
                logo_claimed_at = NULL,
                logo_verified_at = NULL,
                logo_updated_at = NOW()
             WHERE id = :id"
        )->execute([':id' => $id]);
    } elseif ($action === 'hide') {
        // Full takedown: clear paths + delete files on disk, matching
        // LogoTakedownService::hideLogo(). Flipping status alone would leave
        // files publicly fetchable to anyone with a previously-seen URL.
        $paths = $db->fetchOne(
            "SELECT logo_svg_path, logo_png_path, logo_png_512_path,
                    logo_png_2048_path, logo_webp_path, logo_url
               FROM om_companies WHERE id = :id",
            [':id' => $id]
        ) ?: [];
        $db->getConnection()->prepare(
            "UPDATE om_companies SET
                logo_status        = 'takedown',
                logo_svg_path      = NULL,
                logo_png_path      = NULL,
                logo_png_512_path  = NULL,
                logo_png_2048_path = NULL,
                logo_webp_path     = NULL,
                logo_url           = NULL,
                logo_updated_at    = NOW()
             WHERE id = :id"
        )->execute([':id' => $id]);
        $rootRepo = dirname(__DIR__, 3);
        foreach ($paths as $relPath) {
            if (!is_string($relPath) || $relPath === '') continue;
            if (strpos($relPath, '/storage/logos/') !== 0) continue;
            $abs = $rootRepo . $relPath;
            if (is_file($abs)) @unlink($abs);
        }
    } elseif ($action === 'upload' && !empty($_FILES['logo']['tmp_name'])) {
        $ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['svg', 'png', 'jpg', 'jpeg', 'webp'], true)) {
            $root = dirname(__DIR__, 3);
            @mkdir($root . '/storage/logos/verified', 0755, true);
            $dest = "/storage/logos/verified/{$id}.{$ext}";
            if (!move_uploaded_file($_FILES['logo']['tmp_name'], $root . $dest)) {
                // Abort the whole action — don't clear existing variant paths
                // or point the row at a file we never wrote. Disk full, perms,
                // or rejected move would otherwise nuke the live logo silently.
                header("Location: /admin/super/logos/company.php?id=$id&upload_error=1");
                exit;
            }

            // Normalize JPEG → PNG so logo_png_path actually points at a PNG
            // (downstream: imagecreatefrompng, image/png MIME). If GD can't
            // decode the JPEG, abort — we must never write a .jpg into a
            // column downstream code treats as PNG.
            if (in_array($ext, ['jpg', 'jpeg'], true)) {
                $jpgImg = @imagecreatefromjpeg($root . $dest);
                if (!$jpgImg) {
                    @unlink($root . $dest);
                    header("Location: /admin/super/logos/company.php?id=$id&upload_error=1");
                    exit;
                }
                $pngDest = "/storage/logos/verified/{$id}.png";
                imagepng($jpgImg, $root . $pngDest, 9);
                imagedestroy($jpgImg);
                @unlink($root . $dest);
                $dest = $pngDest;
                $ext = 'png';
            }

            $col = match ($ext) {
                'svg'  => 'logo_svg_path',
                'webp' => 'logo_webp_path',
                default => 'logo_png_path',
            };
            // Admin replace: clear ALL variant columns + size-specific PNGs +
            // WebP, then write the new column. Otherwise stale paths keep
            // appearing in hero/ZIP/OG pipelines.
            $db->getConnection()->prepare(
                "UPDATE om_companies SET
                    logo_svg_path       = NULL,
                    logo_png_path       = NULL,
                    logo_png_512_path   = NULL,
                    logo_png_2048_path  = NULL,
                    logo_webp_path      = NULL,
                    $col                = :p,
                    logo_source         = 'admin_upload',
                    logo_updated_at     = NOW()
                 WHERE id = :id"
            )->execute([':p' => $dest, ':id' => $id]);
        }
    }
    header("Location: /admin/super/logos/company.php?id=$id&saved=1");
    exit;
}

$csrfToken = generateCSRFToken();
$src = $company['logo_svg_path']
    ?: $company['logo_png_path']
    ?: $company['logo_webp_path'];

adminHeader('Logo: ' . $company['name_en']);

function esc($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
?>
<h1 class="text-2xl font-bold"><?= esc($company['name_en']) ?></h1>
<p class="text-sm text-gray-600">
  <a class="underline" href="/companies/<?= esc($company['slug']) ?>" target="_blank">View profile</a>
  · status: <strong><?= esc($company['logo_status']) ?></strong>
  · source: <?= esc($company['logo_source'] ?: '—') ?>
</p>

<?php if (isset($_GET['saved'])): ?>
  <div class="mt-4 p-2 bg-green-50 border border-green-300 rounded text-sm">Saved.</div>
<?php endif; ?>
<?php if (isset($_GET['upload_error'])): ?>
  <div class="mt-4 p-2 bg-red-50 border border-red-300 rounded text-sm text-red-800">
    Upload failed (move_uploaded_file returned false). Existing logo preserved.
    Check file permissions on /storage/logos/verified/ and disk space.
  </div>
<?php endif; ?>

<div class="mt-6 grid grid-cols-1 md:grid-cols-2 gap-6">
  <div class="bg-white border rounded p-6 text-center">
    <?php if ($src): ?>
      <img src="<?= esc($src) ?>" class="max-h-48 mx-auto">
    <?php else: ?>
      <p class="text-gray-400">No logo</p>
    <?php endif; ?>
  </div>
  <div class="space-y-4">
    <form method="post" enctype="multipart/form-data" class="space-y-2 bg-white border rounded p-4">
      <input type="hidden" name="csrf_token" value="<?= esc($csrfToken) ?>">
      <label class="block text-sm font-medium">Upload new logo (SVG/PNG/JPG/WebP)</label>
      <input type="file" name="logo" accept=".svg,.png,.jpg,.jpeg,.webp" class="block w-full text-sm">
      <button name="action" value="upload" class="px-3 py-1.5 bg-cyan-600 text-white rounded text-sm">Upload</button>
    </form>
    <form method="post" class="flex flex-wrap gap-2 bg-white border rounded p-4">
      <input type="hidden" name="csrf_token" value="<?= esc($csrfToken) ?>">
      <button name="action" value="force_verify" class="px-3 py-1.5 bg-green-600 text-white rounded text-sm">Force verify</button>
      <button name="action" value="revert" class="px-3 py-1.5 bg-gray-200 rounded text-sm">Revert to indexed</button>
      <button name="action" value="hide" class="px-3 py-1.5 bg-red-600 text-white rounded text-sm">Hide (takedown)</button>
    </form>
  </div>
</div>
<?php adminFooter();
