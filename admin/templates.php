<?php
/**
 * Card Designs gallery. A company admin picks a ready-made preset (auto-branded
 * with their logo + colours, in English or bilingual), or uploads their own
 * card PDF. Applying a preset bakes a card for every employee.
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/admin-layout.php';
require_once INCLUDES_DIR . '/CardPresets.php';

requireAdmin();
$companyId = getCurrentCompanyId();
if (!$companyId) { header('Location: ' . getBasePath() . 'login.php'); exit; }

$basePath = getAdminBasePath();
$isCompanyAdmin = defined('COMPANY_ADMIN_BASE') || !empty($_SESSION['company_slug']);
$ext = $isCompanyAdmin ? '' : '.php';
$db = Database::getInstance();
$message = ''; $error = '';

// ---- Apply a preset (POST) -------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'apply_preset') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = t('templates.err_csrf');
    } else {
        $preset = preg_replace('/[^a-z_]/', '', (string)($_POST['preset'] ?? ''));
        $res = CardPresets::apply($companyId, $preset);
        if (!empty($res['ok'])) {
            header('Location: ' . $basePath . 'templates' . $ext . '?applied=' . urlencode($preset)
                . '&n=' . (int)($res['employees'] ?? 0));
            exit;
        }
        $error = t('templates.err_apply') . ' (' . htmlspecialchars($res['error'] ?? '') . ')';
    }
}
if (isset($_GET['applied'])) {
    $appliedCount = (int)($_GET['n'] ?? 0);
    $message = str_replace([':preset', ':n'],
        [htmlspecialchars((string)$_GET['applied']), $appliedCount],
        t($appliedCount === 1 ? 'templates.applied_ok_one' : 'templates.applied_ok'));
}

$company = $db->fetchOne("SELECT * FROM companies WHERE id = :id", ['id' => $companyId]);
$theme = function_exists('loadCompanyTheme') ? loadCompanyTheme($companyId) : null;
$hasLogo = !empty($theme['logo_path']);
$empCount = (int)($db->fetchOne(
    "SELECT COUNT(*) n FROM employees WHERE company_id = :c AND deleted_at IS NULL",
    ['c' => $companyId])['n'] ?? 0);

// Existing saved design pairs (their own / imported designs).
$savedPairs = $db->fetchAll(
    "SELECT pair_id, MAX(name) AS name, MAX(updated_at) AS updated_at,
            MAX(CASE WHEN side='front' THEN background_image_path END) AS front_bg
     FROM templates
     WHERE company_id = :c AND deleted_at IS NULL AND pair_id IS NOT NULL AND pair_id <> '0'
     GROUP BY pair_id ORDER BY MAX(updated_at) DESC",
    ['c' => $companyId]);

$presets = CardPresets::all();
$dir = currentDir();
adminHeader(t('templates.page_title'), 'templates');
?>
<div class="max-w-6xl mx-auto px-4 sm:px-6 py-6" dir="<?= $dir ?>">

  <div class="mb-6">
    <h1 class="text-2xl font-bold text-gray-900"><?= htmlspecialchars(t('templates.page_title')) ?></h1>
    <p class="text-gray-500 mt-1"><?= htmlspecialchars(t('templates.page_sub')) ?></p>
  </div>

  <?php if ($message): ?>
    <div role="alert" class="mb-5 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-800 px-4 py-3 text-sm"><?= $message ?></div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div role="alert" class="mb-5 rounded-xl bg-red-50 border border-red-200 text-red-700 px-4 py-3 text-sm"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <?php if (!$hasLogo): ?>
    <div class="mb-6 rounded-xl bg-amber-50 border border-amber-200 text-amber-800 px-4 py-3 text-sm flex items-center justify-between gap-3">
      <span><i class="fa-solid fa-triangle-exclamation me-2"></i><?= htmlspecialchars(t('templates.no_logo_warn')) ?></span>
      <a href="<?= $basePath ?>theme<?= $ext ?>" class="font-semibold underline whitespace-nowrap"><?= htmlspecialchars(t('templates.add_logo')) ?></a>
    </div>
  <?php endif; ?>

  <!-- Two paths: pick a design, or upload your own -->
  <div class="grid sm:grid-cols-2 gap-4 mb-8">
    <div class="rounded-2xl border-2 border-[#009bc1] bg-cyan-50/40 p-5">
      <div class="text-lg font-bold text-gray-900"><i class="fa-solid fa-wand-magic-sparkles text-[#00718c] me-2"></i><?= htmlspecialchars(t('templates.path_pick_title')) ?></div>
      <p class="text-sm text-gray-600 mt-1"><?= htmlspecialchars(t('templates.path_pick_sub')) ?></p>
    </div>
    <div class="rounded-2xl border border-gray-200 bg-white p-5">
      <div class="text-lg font-bold text-gray-900"><i class="fa-solid fa-file-arrow-up text-gray-500 me-2"></i><?= htmlspecialchars(t('templates.path_upload_title')) ?></div>
      <p class="text-sm text-gray-600 mt-1 mb-3"><?= htmlspecialchars(t('templates.path_upload_sub')) ?></p>
      <form method="post" action="<?= $basePath ?>create_design_from_pdf<?= $ext ?>" enctype="multipart/form-data" class="flex flex-wrap items-center gap-2">
        <?= csrfField() ?>
        <input type="hidden" name="name" value="<?= htmlspecialchars(($company['name'] ?? 'My') . ' Card') ?>">
        <input type="file" name="front_pdf" accept="application/pdf" required
               class="text-sm file:me-3 file:py-2 file:px-3 file:rounded-lg file:border-0 file:bg-gray-900 file:text-white">
        <button type="submit" class="px-4 py-2 rounded-lg bg-gray-900 text-white text-sm font-semibold"><?= htmlspecialchars(t('templates.upload_btn')) ?></button>
      </form>
    </div>
  </div>

  <!-- Preset gallery -->
  <h2 class="text-lg font-bold text-gray-900 mb-3"><?= htmlspecialchars(t('templates.gallery_title')) ?></h2>
  <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-5 mb-10">
    <?php foreach ($presets as $p): ?>
      <div class="rounded-2xl border border-gray-200 bg-white overflow-hidden flex flex-col">
        <div class="bg-gray-50 border-b border-gray-100">
          <img loading="lazy" src="<?= $basePath ?>preset-thumb<?= $ext ?>?preset=<?= urlencode($p['id']) ?>"
               alt="<?= htmlspecialchars($p['label']) ?>" class="w-full aspect-[1050/600] object-contain">
        </div>
        <div class="p-4 flex items-center justify-between gap-3 mt-auto">
          <div>
            <div class="font-semibold text-gray-900 text-sm"><?= htmlspecialchars(t('templates.preset_' . $p['id'], [], null) ?: $p['label']) ?></div>
            <span class="inline-block mt-1 text-[11px] font-semibold px-2 py-0.5 rounded-full <?= $p['bilingual'] ? 'bg-violet-100 text-violet-700' : 'bg-gray-100 text-gray-600' ?>">
              <?= $p['bilingual'] ? 'AR + EN' : 'EN' ?>
            </span>
          </div>
          <form method="post" data-cardify-confirm="<?= htmlspecialchars((string)(htmlspecialchars(t('templates.apply_confirm'))), ENT_QUOTES) ?>">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="apply_preset">
            <input type="hidden" name="preset" value="<?= htmlspecialchars($p['id']) ?>">
            <button type="submit" class="px-4 py-2 rounded-lg bg-[#00718c] hover:bg-[#005b73] text-white text-sm font-semibold whitespace-nowrap"><?= htmlspecialchars(t('templates.use_btn')) ?></button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  <p class="text-xs text-gray-400 -mt-6 mb-10"><i class="fa-solid fa-circle-info me-1"></i><?= htmlspecialchars(str_replace(':n', (string)$empCount, t('templates.apply_note'))) ?></p>

  <!-- Saved designs -->
  <?php if (!empty($savedPairs)): ?>
    <h2 class="text-lg font-bold text-gray-900 mb-3"><?= htmlspecialchars(t('templates.saved_title')) ?></h2>
    <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-5">
      <?php foreach ($savedPairs as $sp): ?>
        <div class="rounded-2xl border border-gray-200 bg-white overflow-hidden">
          <div class="bg-gray-50 border-b border-gray-100 aspect-[1050/600] flex items-center justify-center">
            <?php if (!empty($sp['front_bg'])): ?>
              <img loading="lazy" src="<?= htmlspecialchars($sp['front_bg']) ?>" alt="" class="w-full h-full object-contain">
            <?php else: ?>
              <span class="text-gray-300 text-sm"><?= htmlspecialchars(t('templates.no_preview')) ?></span>
            <?php endif; ?>
          </div>
          <div class="p-4">
            <div class="font-semibold text-gray-900 text-sm truncate"><?= htmlspecialchars($sp['name'] ?: t('templates.untitled')) ?></div>
            <div class="text-xs text-gray-400 mt-0.5"><?= htmlspecialchars(substr((string)$sp['updated_at'], 0, 10)) ?></div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

</div>
<?php adminFooter(); ?>
