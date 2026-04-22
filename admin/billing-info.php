<?php
/**
 * Company billing info (Cat S action 446).
 *
 * Lets a company admin set their Oman Commercial Registration number,
 * optional VAT tax ID + flag, and billing address block. These render
 * in the "Bill to" panel on the receipt and are forwarded to BHD-ERP
 * on the next sync so the ERP invoice carries the legal identifiers
 * the Oman Tax Authority requires.
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/admin-layout.php';

requireAdmin();
$companyId = getCurrentCompanyId();
if (!$companyId) { header('Location: ' . getBasePath() . 'login.php'); exit; }

$db = Database::getInstance();
$message = null;
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = t('billing_info.err_csrf');
        $messageType = 'error';
    } else {
        $fields = [
            'cr_number'        => mb_substr(trim($_POST['cr_number'] ?? ''), 0, 40),
            'tax_id'           => mb_substr(trim($_POST['tax_id'] ?? ''), 0, 40),
            'vat_registered'   => !empty($_POST['vat_registered']) ? 1 : 0,
            'billing_address'  => mb_substr(trim($_POST['billing_address'] ?? ''), 0, 500),
            'billing_city'     => mb_substr(trim($_POST['billing_city'] ?? ''), 0, 80),
            'billing_postcode' => mb_substr(trim($_POST['billing_postcode'] ?? ''), 0, 20),
            'billing_country'  => mb_substr(trim($_POST['billing_country'] ?? 'OM'), 0, 2) ?: 'OM',
        ];
        try {
            $db->update('companies', $fields, 'id = :id', ['id' => $companyId]);
            $message = t('billing_info.saved');
        } catch (Throwable $e) {
            $message = t('billing_info.err_save');
            $messageType = 'error';
        }
    }
}

$company = $db->fetchOne(
    "SELECT name, cr_number, tax_id, vat_registered, billing_address, billing_city, billing_postcode, billing_country
       FROM companies WHERE id = :id",
    ['id' => $companyId]
) ?: [];

adminHeader(t('billing_info.page_title'), 'billing');
?>
<div class="max-w-3xl mx-auto px-4 py-6">
    <header class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900"><?= htmlspecialchars(t('billing_info.page_title')) ?></h1>
        <p class="text-gray-600 mt-1 text-sm"><?= htmlspecialchars(t('billing_info.page_sub')) ?></p>
    </header>

    <?php if ($message): ?>
    <div class="mb-4 p-4 rounded-lg text-sm <?= $messageType === 'success' ? 'bg-green-50 text-green-800 border border-green-200' : 'bg-red-50 text-red-800 border border-red-200' ?>">
        <?= htmlspecialchars($message) ?>
    </div>
    <?php endif; ?>

    <form method="POST" class="bg-white rounded-2xl shadow-sm border border-gray-200 p-6 space-y-5">
        <?= csrfField() ?>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1.5"><?= htmlspecialchars(t('billing_info.cr_number')) ?></label>
            <input type="text" name="cr_number" value="<?= htmlspecialchars($company['cr_number'] ?? '') ?>"
                   placeholder="1334733"
                   class="w-full px-4 py-2.5 rounded-lg border border-gray-300 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 font-mono">
            <p class="text-xs text-gray-500 mt-1"><?= htmlspecialchars(t('billing_info.cr_number_hint')) ?></p>
        </div>

        <label class="flex items-center gap-2 cursor-pointer select-none">
            <input type="checkbox" name="vat_registered" value="1" <?= !empty($company['vat_registered']) ? 'checked' : '' ?>
                   class="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
            <span class="text-sm text-gray-700"><?= htmlspecialchars(t('billing_info.vat_registered_label')) ?></span>
        </label>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1.5"><?= htmlspecialchars(t('billing_info.tax_id')) ?></label>
            <input type="text" name="tax_id" value="<?= htmlspecialchars($company['tax_id'] ?? '') ?>"
                   placeholder="OM0000000000"
                   class="w-full px-4 py-2.5 rounded-lg border border-gray-300 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 font-mono">
            <p class="text-xs text-gray-500 mt-1"><?= htmlspecialchars(t('billing_info.tax_id_hint')) ?></p>
        </div>

        <hr class="border-gray-100">

        <h2 class="text-sm font-semibold text-gray-800 uppercase tracking-wide"><?= htmlspecialchars(t('billing_info.billing_address_h')) ?></h2>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1.5"><?= htmlspecialchars(t('billing_info.billing_address')) ?></label>
            <textarea name="billing_address" rows="3"
                      placeholder="<?= htmlspecialchars(t('billing_info.billing_address_ph')) ?>"
                      class="w-full px-4 py-2.5 rounded-lg border border-gray-300 focus:ring-2 focus:ring-blue-500 focus:border-blue-500"><?= htmlspecialchars($company['billing_address'] ?? '') ?></textarea>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div class="sm:col-span-2">
                <label class="block text-sm font-medium text-gray-700 mb-1.5"><?= htmlspecialchars(t('billing_info.billing_city')) ?></label>
                <input type="text" name="billing_city" value="<?= htmlspecialchars($company['billing_city'] ?? '') ?>"
                       class="w-full px-4 py-2.5 rounded-lg border border-gray-300 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5"><?= htmlspecialchars(t('billing_info.billing_postcode')) ?></label>
                <input type="text" name="billing_postcode" value="<?= htmlspecialchars($company['billing_postcode'] ?? '') ?>"
                       class="w-full px-4 py-2.5 rounded-lg border border-gray-300 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 font-mono">
            </div>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1.5"><?= htmlspecialchars(t('billing_info.billing_country')) ?></label>
            <select name="billing_country" class="w-full px-4 py-2.5 rounded-lg border border-gray-300 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                <?php foreach (['OM' => 'Oman', 'AE' => 'UAE', 'SA' => 'Saudi Arabia', 'QA' => 'Qatar', 'BH' => 'Bahrain', 'KW' => 'Kuwait'] as $code => $label): ?>
                    <option value="<?= $code ?>" <?= ($company['billing_country'] ?? 'OM') === $code ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="pt-4 border-t border-gray-100">
            <button type="submit" class="inline-flex items-center gap-2 px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold rounded-lg transition">
                <i class="fa-solid fa-save"></i>
                <?= htmlspecialchars(t('billing_info.save_btn')) ?>
            </button>
        </div>
    </form>
</div>
<?php adminFooter(); ?>
