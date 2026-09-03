<?php
/**
 * Print partner creates a client company tenant.
 * The new company is a normal Cardify tenant. The shop then uses
 * the existing company admin (employees, CSV, locked template,
 * bulk generate). No parallel card editor.
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/PrintShopAuth.php';
require_once INCLUDES_DIR . '/PrintShopClients.php';
require_once INCLUDES_DIR . '/Currency.php';

$ctx = PrintShopAuth::requireLogin();
$shop = $ctx['shop'];
$op = $ctx['operator'];
$opRole = $op['role'] ?? 'admin';
if ($opRole === 'viewer' || !PrintShopClients::canOperateClientTenants($shop)) {
    http_response_code(403);
    echo '<h1>403</h1><p>' . htmlspecialchars(t('printshopinternal.create_forbidden')) . '</p>';
    exit;
}

$error = null;
$old = [
    'name' => '',
    'slug' => '',
    'country' => $shop['country'] ?? 'OM',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = t('printshopinternal.invalid_request');
    } else {
        $old['name'] = trim((string) ($_POST['name'] ?? ''));
        $old['slug'] = strtolower(trim((string) ($_POST['slug'] ?? '')));
        $old['country'] = strtoupper(trim((string) ($_POST['country'] ?? '')));
        $result = PrintShopClients::createClientCompany($shop, $old);
        if (!empty($result['success']) && !empty($result['company']['slug'])) {
            PrintShopClients::adoptClientContext($result['company']);
            header('Location: ' . getTenantUrl($result['company']['slug'], '/admin/employees'));
            exit;
        }
        $error = $result['error'] ?? t('printshopinternal.create_failed');
    }
}

require_once INCLUDES_DIR . '/printshop-layout.php';
printshopHeader(t('printshopinternal.create_title', ['shop' => $shop['name']]), 'clients');
?>
    <div class="mb-2 text-sm text-gray-500">
        <a href="clients.php" class="hover:underline">
            <i class="fa-solid fa-arrow-left me-1"></i><?= htmlspecialchars(t('printshopinternal.back_to_clients')) ?>
        </a>
    </div>

    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900"><?= htmlspecialchars(t('printshopinternal.create_heading')) ?></h1>
        <p class="text-gray-600 mt-1 max-w-2xl"><?= htmlspecialchars(t('printshopinternal.create_subheading')) ?></p>
    </div>

    <?php if ($error): ?>
    <div class="mb-6 p-4 rounded-xl bg-red-50 border border-red-200 text-red-700"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post" class="bg-white rounded-2xl border border-gray-200 shadow-sm max-w-xl overflow-hidden">
        <?= csrfField() ?>
        <div class="p-6 space-y-4">
            <div>
                <label for="client-name" class="block text-sm font-semibold text-gray-700 mb-2"><?= htmlspecialchars(t('printshopinternal.field_company_name')) ?></label>
                <input id="client-name" type="text" name="name" required
                       value="<?= sanitize($old['name']) ?>"
                       class="w-full px-4 py-3 border border-gray-200 rounded-xl"
                       placeholder="<?= htmlspecialchars(t('printshopinternal.field_company_name_ph')) ?>">
            </div>
            <div>
                <label for="client-slug" class="block text-sm font-semibold text-gray-700 mb-2"><?= htmlspecialchars(t('printshopinternal.field_company_slug')) ?></label>
                <input id="client-slug" type="text" name="slug" pattern="[a-z0-9]+(?:-[a-z0-9]+)*"
                       value="<?= sanitize($old['slug']) ?>"
                       class="w-full px-4 py-3 border border-gray-200 rounded-xl"
                       placeholder="<?= htmlspecialchars(t('printshopinternal.field_company_slug_ph')) ?>">
                <p class="mt-1 text-xs text-gray-500"><?= htmlspecialchars(t('printshopinternal.field_company_slug_help')) ?></p>
            </div>
            <div>
                <label for="client-country" class="block text-sm font-semibold text-gray-700 mb-2"><?= htmlspecialchars(t('printshopinternal.field_company_country')) ?></label>
                <select id="client-country" name="country" class="w-full px-4 py-3 border border-gray-200 rounded-xl bg-white">
                    <?php echo Currency::getCountryOptions($old['country'] ?: 'OM'); ?>
                </select>
            </div>
        </div>
        <div class="px-6 py-4 bg-gray-50 border-t border-gray-100">
            <button type="submit" class="w-full py-3 bg-[#009bc1] hover:bg-[#0086a6] text-white rounded-xl font-semibold">
                <?= htmlspecialchars(t('printshopinternal.create_submit')) ?>
            </button>
        </div>
    </form>
<?php printshopFooter(); ?>
