<?php
/**
 * Internal-provider: apply a card design to a client company on their behalf.
 * Same preset engine as the tenant admin gallery (CardPresets), gated to BHD
 * operators. Lets the operator pick a ready-made design for any client tenant.
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/PrintShopAuth.php';
require_once INCLUDES_DIR . '/printshop-layout.php';
require_once INCLUDES_DIR . '/CardPresets.php';

$ctx = PrintShopAuth::requireInternalProvider();
$shop = $ctx['shop'];
$operator = $ctx['operator'] ?? [];

$companyId = trim($_GET['company'] ?? '');
if ($companyId === '') { header('Location: ' . getBasePath() . 'printshop/clients.php'); exit; }

$db = Database::getInstance();
$company = $db->fetchOne("SELECT * FROM companies WHERE id = :id", ['id' => $companyId]);
if (!$company) { header('Location: ' . getBasePath() . 'printshop/clients.php'); exit; }

$message = ''; $error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'apply_preset') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Session expired, please try again.';
    } else {
        $preset = preg_replace('/[^a-z_]/', '', (string)($_POST['preset'] ?? ''));
        $res = CardPresets::apply($companyId, $preset);
        if (!empty($res['ok'])) {
            if (class_exists('AuditLog')) {
                try { AuditLog::log('printshop_apply_preset', [
                    'shop_id' => $shop['id'] ?? null, 'operator_id' => $operator['id'] ?? null,
                    'company_id' => $companyId, 'preset' => $preset, 'employees' => $res['employees'] ?? 0,
                ]); } catch (\Throwable $e) {}
            }
            header('Location: ' . getBasePath() . 'printshop/client-templates.php?company='
                . urlencode($companyId) . '&applied=' . urlencode($preset) . '&n=' . (int)($res['employees'] ?? 0));
            exit;
        }
        $error = 'Could not apply design (' . htmlspecialchars($res['error'] ?? '') . ').';
    }
}
if (isset($_GET['applied'])) {
    $message = 'Applied "' . htmlspecialchars((string)$_GET['applied']) . '" to '
        . (int)($_GET['n'] ?? 0) . ' employee card(s).';
}

$theme = function_exists('loadCompanyTheme') ? loadCompanyTheme($companyId) : null;
$presets = CardPresets::all();
$bp = getBasePath();
printshopHeader($company['name'] . ' , Designs', 'clients');
?>
<div class="pt-20 pb-12 px-4 sm:px-6 lg:px-8 max-w-6xl mx-auto">
  <div class="flex items-center justify-between mb-6">
    <div>
      <a href="<?= $bp ?>printshop/client.php?company=<?= urlencode($companyId) ?>" class="text-sm text-gray-500 hover:text-gray-700"><i class="fa-solid fa-arrow-left me-1"></i>Back to client</a>
      <h1 class="text-2xl font-bold text-gray-900 mt-1">Card designs , <?= htmlspecialchars($company['name']) ?></h1>
      <p class="text-gray-500 text-sm">Pick a design to apply to this client's team cards.</p>
    </div>
  </div>

  <?php if ($message): ?><div class="mb-5 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-800 px-4 py-3 text-sm"><?= $message ?></div><?php endif; ?>
  <?php if ($error): ?><div class="mb-5 rounded-xl bg-red-50 border border-red-200 text-red-700 px-4 py-3 text-sm"><?= $error ?></div><?php endif; ?>
  <?php if (empty($theme['logo_path'])): ?>
    <div class="mb-6 rounded-xl bg-amber-50 border border-amber-200 text-amber-800 px-4 py-3 text-sm">This client has no logo on file yet, designs will render without a logo.</div>
  <?php endif; ?>

  <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-5">
    <?php foreach ($presets as $p): ?>
      <div class="rounded-2xl border border-gray-200 bg-white overflow-hidden flex flex-col">
        <div class="bg-gray-50 border-b border-gray-100">
          <img loading="lazy" src="<?= $bp ?>printshop/preset-thumb.php?company=<?= urlencode($companyId) ?>&preset=<?= urlencode($p['id']) ?>"
               alt="<?= htmlspecialchars($p['label']) ?>" class="w-full aspect-[1050/600] object-contain">
        </div>
        <div class="p-4 flex items-center justify-between gap-3 mt-auto">
          <div>
            <div class="font-semibold text-gray-900 text-sm"><?= htmlspecialchars($p['label']) ?></div>
            <span class="inline-block mt-1 text-[11px] font-semibold px-2 py-0.5 rounded-full <?= $p['bilingual'] ? 'bg-violet-100 text-violet-700' : 'bg-gray-100 text-gray-600' ?>"><?= $p['bilingual'] ? 'AR + EN' : 'EN' ?></span>
          </div>
          <form method="post" data-cardify-confirm="Apply this design to all of <?= htmlspecialchars((string)(htmlspecialchars(addslashes($company['name']))), ENT_QUOTES) ?>\'s cards?">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="apply_preset">
            <input type="hidden" name="preset" value="<?= htmlspecialchars($p['id']) ?>">
            <button type="submit" class="px-4 py-2 rounded-lg bg-[#00718c] hover:bg-[#005b73] text-white text-sm font-semibold whitespace-nowrap">Apply</button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php printshopFooter(); ?>
