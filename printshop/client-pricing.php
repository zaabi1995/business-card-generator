<?php
/**
 * Print Shop, Client Pricing
 *
 * Lets a print shop set per-client price overrides on top of its
 * default tier table. Supports two modes per client:
 *  - Single tier table  (one set of qty -> total-price rows for any paper type)
 *  - Per paper type     (separate tier tables for uncoated / matte / silk)
 *
 * Plus an optional `min_quantity` floor that blocks orders below it.
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/PrintShop.php';

Auth::requireLogin();
$user = Auth::getCurrentUser();

if ($user['role'] !== 'print_shop' && $user['role'] !== 'super_admin') {
    header('Location: ' . getBasePath() . 'login.php');
    exit;
}

$printShop = PrintShop::getByUserId($user['id']);
if (!$printShop && $user['role'] !== 'super_admin') {
    header('Location: ' . getBasePath() . 'printshop/register.php');
    exit;
}
if ($user['role'] === 'super_admin' && isset($_GET['shop'])) {
    $printShop = PrintShop::getById((int)$_GET['shop']);
}
if (!$printShop) {
    header('Location: ' . getBasePath() . 'admin/print_shops.php');
    exit;
}

$shopId      = (int) $printShop['id'];
$shopPricing = json_decode($printShop['pricing'] ?? '{}', true) ?: [];
$shopTiers   = $shopPricing['quantity_tiers'] ?? [];
$currency    = $printShop['currency'] ?? ($shopPricing['currency'] ?? 'OMR');
$decimals    = in_array($currency, ['OMR', 'BHD', 'KWD'], true) ? 3 : 2;

// Paper types Cardify exposes in the order flow. Keep these keys in
// lockstep with admin/order_print.php paperType select values.
$paperTypes = [
    'uncoated' => 'Uncoated (no lamination)',
    'matte'    => 'Matte lamination',
    'silk'     => 'Silk / glossy lamination',
];

$overrides  = PrintShop::listClientPricing($shopId);
$candidates = PrintShop::getClientCompanies($shopId);

// Strip companies that already have an override from the candidate list
$existingCompanyIds = array_column($overrides, 'company_id');
$candidates = array_values(array_filter($candidates, function ($c) use ($existingCompanyIds) {
    return !in_array($c['id'], $existingCompanyIds, true);
}));

// Default tier rows in the shape Alpine consumes
$defaultTierRows = [];
foreach ($shopTiers as $qty => $val) {
    $price = is_array($val) ? ($val['price'] ?? 0) : ((float)$val * (int)$qty);
    $defaultTierRows[] = ['qty' => (int)$qty, 'price' => round((float)$price, 3)];
}
usort($defaultTierRows, fn($a, $b) => $a['qty'] <=> $b['qty']);

$flashMessage = $_SESSION['client_pricing_flash'] ?? null;
unset($_SESSION['client_pricing_flash']);

require_once INCLUDES_DIR . '/printshop-layout.php';
printshopHeader(t('printshopclientpricing.page_title', ['shop' => $printShop['name']]), 'client_pricing');
?>
<div x-data="clientPricing()" x-init="init()">

    <!-- Header -->
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900"><?= htmlspecialchars(t('printshopclientpricing.heading')) ?></h1>
            <p class="text-gray-600 mt-1 max-w-2xl"><?= htmlspecialchars(t('printshopclientpricing.subheading')) ?></p>
        </div>
        <button type="button"
                @click="openAdd()"
                class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium shadow-sm">
            <i class="fa-solid fa-plus"></i>
            <?= htmlspecialchars(t('printshopclientpricing.add_btn')) ?>
        </button>
    </div>

    <?php if ($flashMessage): ?>
    <div class="mb-4 p-3 rounded-lg bg-green-50 border border-green-200 text-green-700 text-sm">
        <i class="fa-solid fa-check-circle mr-2"></i><?= sanitize($flashMessage) ?>
    </div>
    <?php endif; ?>

    <!-- Default tier reference card -->
    <div class="mb-6 p-4 rounded-xl bg-blue-50 border border-blue-100">
        <p class="text-sm font-semibold text-blue-900 mb-2"><i class="fa-solid fa-circle-info mr-2"></i><?= htmlspecialchars(t('printshopclientpricing.default_tiers_h')) ?></p>
        <?php if (empty($shopTiers)): ?>
            <p class="text-sm text-blue-700"><?= htmlspecialchars(t('printshopclientpricing.no_default_tiers')) ?></p>
        <?php else: ?>
        <div class="flex flex-wrap gap-2">
            <?php
            ksort($shopTiers, SORT_NUMERIC);
            foreach ($shopTiers as $qty => $val):
                $price = is_array($val) ? ($val['price'] ?? 0) : ((float)$val * (int)$qty);
            ?>
            <span class="inline-flex items-center gap-2 px-3 py-1 bg-white border border-blue-200 rounded-full text-xs font-medium text-blue-900">
                <span class="text-blue-500"><?= (int)$qty ?></span>
                <i class="fa-solid fa-arrow-right text-[10px] text-blue-400"></i>
                <?= number_format((float)$price, $decimals) ?> <?= htmlspecialchars($currency) ?>
            </span>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Override list -->
    <?php if (empty($overrides)): ?>
    <div class="p-12 bg-white rounded-2xl border-2 border-dashed border-gray-200 text-center">
        <i class="fa-solid fa-tags text-4xl text-gray-300 mb-3"></i>
        <p class="text-gray-700 font-medium"><?= htmlspecialchars(t('printshopclientpricing.empty_h')) ?></p>
        <p class="text-gray-500 text-sm mt-1 max-w-md mx-auto"><?= htmlspecialchars(t('printshopclientpricing.empty_s')) ?></p>
    </div>
    <?php else: ?>
    <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
        <div class="divide-y divide-gray-100">
        <?php foreach ($overrides as $row):
            $rowPricing = json_decode($row['pricing'] ?? '{}', true) ?: [];
            $minQty     = isset($rowPricing['min_quantity']) ? (int) $rowPricing['min_quantity'] : 0;
            $rowTiers   = $rowPricing['quantity_tiers'] ?? [];
            $perPaper   = (!empty($rowPricing['paper_type_pricing']) && is_array($rowPricing['paper_type_pricing']))
                ? $rowPricing['paper_type_pricing']
                : null;
            ksort($rowTiers, SORT_NUMERIC);
        ?>
            <div class="p-4 sm:p-5 flex flex-wrap items-start justify-between gap-4">
                <div class="flex-1 min-w-0">
                    <div class="flex flex-wrap items-center gap-2 mb-2">
                        <span class="font-semibold text-gray-900"><?= sanitize($row['company_name'] ?? t('printshopclientpricing.unknown_company')) ?></span>
                        <?php if (!empty($row['company_slug'])): ?>
                        <span class="text-xs text-gray-400">/<?= sanitize($row['company_slug']) ?></span>
                        <?php endif; ?>
                        <?php if ($minQty > 0): ?>
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 bg-amber-50 border border-amber-200 rounded-full text-[11px] font-medium text-amber-800">
                            <i class="fa-solid fa-circle-arrow-up text-[10px]"></i>
                            <?= htmlspecialchars(t('printshopclientpricing.min_qty_badge', ['n' => $minQty])) ?>
                        </span>
                        <?php endif; ?>
                        <?php if ($perPaper): ?>
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 bg-purple-50 border border-purple-200 rounded-full text-[11px] font-medium text-purple-800">
                            <i class="fa-solid fa-layer-group text-[10px]"></i>
                            <?= htmlspecialchars(t('printshopclientpricing.per_paper_badge')) ?>
                        </span>
                        <?php endif; ?>
                    </div>

                    <?php if ($perPaper): ?>
                        <?php foreach ($paperTypes as $pkey => $pname):
                            $pTiers = $perPaper[$pkey]['quantity_tiers'] ?? null;
                            if (!$pTiers) continue;
                            ksort($pTiers, SORT_NUMERIC);
                        ?>
                        <div class="mt-2">
                            <p class="text-xs font-semibold text-gray-600 mb-1"><?= sanitize($pname) ?></p>
                            <div class="flex flex-wrap gap-2">
                                <?php foreach ($pTiers as $qty => $val):
                                    $price = is_array($val) ? ($val['price'] ?? 0) : ((float)$val * (int)$qty);
                                ?>
                                <span class="inline-flex items-center gap-2 px-2.5 py-1 bg-gray-50 border border-gray-200 rounded-md text-xs">
                                    <span class="font-medium"><?= (int)$qty ?></span>
                                    <span class="text-gray-400">/</span>
                                    <span class="text-gray-900 font-semibold"><?= number_format((float)$price, $decimals) ?> <?= htmlspecialchars($currency) ?></span>
                                </span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php elseif (!empty($rowTiers)): ?>
                    <div class="flex flex-wrap gap-2 mb-1">
                        <?php foreach ($rowTiers as $qty => $val):
                            $price = is_array($val) ? ($val['price'] ?? 0) : ((float)$val * (int)$qty);
                        ?>
                        <span class="inline-flex items-center gap-2 px-2.5 py-1 bg-gray-50 border border-gray-200 rounded-md text-xs">
                            <span class="font-medium"><?= (int)$qty ?></span>
                            <span class="text-gray-400">/</span>
                            <span class="text-gray-900 font-semibold"><?= number_format((float)$price, $decimals) ?> <?= htmlspecialchars($currency) ?></span>
                        </span>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <p class="text-sm text-gray-500 italic"><?= htmlspecialchars(t('printshopclientpricing.no_tiers_set')) ?></p>
                    <?php endif; ?>

                    <?php if (!empty($row['notes'])): ?>
                    <p class="text-xs text-gray-500 mt-2"><i class="fa-solid fa-note-sticky mr-1 text-gray-400"></i><?= sanitize($row['notes']) ?></p>
                    <?php endif; ?>
                </div>
                <div class="flex items-center gap-2 shrink-0">
                    <button type="button"
                            @click='openEdit(<?= json_encode([
                                "company_id"   => $row["company_id"],
                                "company_name" => $row["company_name"],
                                "min_quantity" => $minQty,
                                "tiers"        => $rowTiers,
                                "paper_type_pricing" => $perPaper,
                                "notes"        => $row["notes"] ?? "",
                            ], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-blue-50 hover:bg-blue-100 text-blue-700 rounded-md text-sm font-medium">
                        <i class="fa-solid fa-pen-to-square"></i>
                        <?= htmlspecialchars(t('printshopclientpricing.edit_btn')) ?>
                    </button>
                    <form method="POST" action="save-client-pricing.php" class="inline"
                          onsubmit="return confirm('<?= htmlspecialchars(t('printshopclientpricing.reset_confirm'), ENT_QUOTES) ?>');">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="reset">
                        <input type="hidden" name="company_id" value="<?= sanitize($row['company_id']) ?>">
                        <button type="submit"
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-gray-50 hover:bg-red-50 hover:text-red-700 text-gray-600 rounded-md text-sm font-medium">
                            <i class="fa-solid fa-rotate-left"></i>
                            <?= htmlspecialchars(t('printshopclientpricing.reset_btn')) ?>
                        </button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Modal: add or edit -->
    <div x-show="open"
         x-cloak
         class="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-black/40"
         @click.self="open = false">
        <div class="bg-white rounded-2xl shadow-xl max-w-3xl w-full max-h-[90vh] overflow-y-auto">
            <form method="POST" action="save-client-pricing.php">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="save">

                <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                    <h3 class="text-lg font-semibold text-gray-900">
                        <span x-show="mode === 'add'"><?= htmlspecialchars(t('printshopclientpricing.modal_add_h')) ?></span>
                        <span x-show="mode === 'edit'"><?= htmlspecialchars(t('printshopclientpricing.modal_edit_h')) ?></span>
                    </h3>
                    <button type="button" @click="open = false"
                            aria-label="<?= htmlspecialchars(t('printshopclientpricing.close_btn') ?: 'Close') ?>"
                            title="<?= htmlspecialchars(t('printshopclientpricing.close_btn') ?: 'Close') ?>"
                            class="text-gray-400 hover:text-gray-600 rounded-full p-1 hover:bg-gray-100 transition-colors">
                        <i class="fa-solid fa-xmark text-xl"></i>
                    </button>
                </div>

                <div class="p-6 space-y-5">
                    <!-- Company picker (add mode) -->
                    <div x-show="mode === 'add'">
                        <label class="block text-sm font-medium text-gray-700 mb-1.5">
                            <?= htmlspecialchars(t('printshopclientpricing.field_company')) ?>
                        </label>
                        <?php if (empty($candidates)): ?>
                        <div class="p-3 bg-amber-50 border border-amber-200 rounded-lg text-sm text-amber-700">
                            <?= htmlspecialchars(t('printshopclientpricing.no_candidates')) ?>
                        </div>
                        <?php else: ?>
                        <select name="company_id"
                                id="cp_company_id"
                                x-model="companyId"
                                :required="mode === 'add'"
                                aria-label="<?= htmlspecialchars(t('printshopclientpricing.field_company')) ?>"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                            <option value=""><?= htmlspecialchars(t('printshopclientpricing.select_company')) ?></option>
                            <?php foreach ($candidates as $c): ?>
                            <option value="<?= sanitize($c['id']) ?>"><?= sanitize($c['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php endif; ?>
                    </div>

                    <!-- Company name (edit mode, locked) -->
                    <div x-show="mode === 'edit'">
                        <label class="block text-sm font-medium text-gray-700 mb-1.5">
                            <?= htmlspecialchars(t('printshopclientpricing.field_company')) ?>
                        </label>
                        <input type="hidden" name="company_id" :value="companyId">
                        <div class="px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg text-gray-900" x-text="companyName"></div>
                    </div>

                    <!-- Min quantity -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5" for="cp_min_quantity">
                            <?= htmlspecialchars(t('printshopclientpricing.field_min_qty')) ?>
                            <span class="text-gray-400 font-normal text-xs">(<?= htmlspecialchars(t('printshopclientpricing.optional')) ?>)</span>
                        </label>
                        <input type="number"
                               id="cp_min_quantity"
                               name="min_quantity"
                               x-model.number="minQuantity"
                               min="0"
                               step="1"
                               placeholder="0"
                               aria-label="<?= htmlspecialchars(t('printshopclientpricing.field_min_qty')) ?>"
                               class="w-32 px-3 py-2 border border-gray-300 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                        <p class="text-xs text-gray-500 mt-1"><?= htmlspecialchars(t('printshopclientpricing.min_qty_help')) ?></p>
                    </div>

                    <!-- Pricing mode toggle -->
                    <div class="border border-gray-200 rounded-xl overflow-hidden">
                        <div class="grid grid-cols-2 divide-x divide-gray-200 bg-gray-50">
                            <button type="button"
                                    @click="pricingMode = 'single'"
                                    :class="pricingMode === 'single' ? 'bg-white text-blue-600 font-semibold' : 'text-gray-600'"
                                    class="px-4 py-3 text-sm transition-colors">
                                <i class="fa-solid fa-table mr-2"></i><?= htmlspecialchars(t('printshopclientpricing.mode_single')) ?>
                            </button>
                            <button type="button"
                                    @click="pricingMode = 'per_paper'"
                                    :class="pricingMode === 'per_paper' ? 'bg-white text-blue-600 font-semibold' : 'text-gray-600'"
                                    class="px-4 py-3 text-sm transition-colors">
                                <i class="fa-solid fa-layer-group mr-2"></i><?= htmlspecialchars(t('printshopclientpricing.mode_per_paper')) ?>
                            </button>
                        </div>
                        <input type="hidden" name="pricing_mode" :value="pricingMode">

                        <!-- Single tier table -->
                        <div x-show="pricingMode === 'single'" class="p-4">
                            <div class="flex items-end justify-between mb-2">
                                <p class="text-sm text-gray-600"><?= htmlspecialchars(t('printshopclientpricing.tiers_help', ['currency' => $currency])) ?></p>
                                <button type="button"
                                        @click="copyDefault()"
                                        class="text-xs text-blue-600 hover:text-blue-800 font-medium">
                                    <i class="fa-solid fa-copy mr-1"></i><?= htmlspecialchars(t('printshopclientpricing.copy_default_btn')) ?>
                                </button>
                            </div>
                            <div class="border border-gray-200 rounded-lg overflow-hidden">
                                <table class="w-full text-sm">
                                    <thead class="bg-gray-50 text-gray-600 text-xs uppercase">
                                        <tr>
                                            <th class="px-3 py-2 text-start"><?= htmlspecialchars(t('printshopclientpricing.col_quantity')) ?></th>
                                            <th class="px-3 py-2 text-start"><?= htmlspecialchars(t('printshopclientpricing.col_total_price', ['currency' => $currency])) ?></th>
                                            <th class="px-3 py-2 text-start"><?= htmlspecialchars(t('printshopclientpricing.col_per_card')) ?></th>
                                            <th class="px-3 py-2 w-10"></th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100">
                                        <template x-for="(tier, idx) in tiers" :key="idx">
                                            <tr>
                                                <td class="px-3 py-2">
                                                    <input type="number" :name="'tier_qty[' + idx + ']'" x-model.number="tier.qty"
                                                           min="1" step="1" :required="pricingMode === 'single'"
                                                           class="w-24 px-2 py-1 border border-gray-300 rounded">
                                                </td>
                                                <td class="px-3 py-2">
                                                    <input type="number" :name="'tier_price[' + idx + ']'" x-model.number="tier.price"
                                                           min="0" step="0.001" :required="pricingMode === 'single'"
                                                           class="w-32 px-2 py-1 border border-gray-300 rounded">
                                                </td>
                                                <td class="px-3 py-2 text-gray-500 text-xs"
                                                    x-text="(tier.qty > 0 ? (tier.price / tier.qty).toFixed(<?= $decimals ?>) : '0')"></td>
                                                <td class="px-3 py-2 text-end">
                                                    <button type="button" @click="removeTier(idx)" class="text-gray-400 hover:text-red-600">
                                                        <i class="fa-solid fa-trash"></i>
                                                    </button>
                                                </td>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
                                <button type="button" @click="addTier()" class="w-full px-3 py-2 text-sm text-blue-600 hover:bg-blue-50 border-t border-gray-200">
                                    <i class="fa-solid fa-plus mr-1"></i><?= htmlspecialchars(t('printshopclientpricing.add_tier_btn')) ?>
                                </button>
                            </div>
                        </div>

                        <!-- Per paper-type tier tables -->
                        <div x-show="pricingMode === 'per_paper'" class="p-4 space-y-5">
                            <p class="text-sm text-gray-600"><?= htmlspecialchars(t('printshopclientpricing.per_paper_help', ['currency' => $currency])) ?></p>

                            <?php foreach ($paperTypes as $pkey => $pname): ?>
                            <div class="border border-gray-200 rounded-lg overflow-hidden">
                                <div class="px-3 py-2 bg-gray-50 border-b border-gray-200 flex items-center justify-between">
                                    <p class="text-sm font-semibold text-gray-700"><?= sanitize($pname) ?></p>
                                    <button type="button"
                                            @click="copyDefaultToPaper('<?= $pkey ?>')"
                                            class="text-xs text-blue-600 hover:text-blue-800">
                                        <i class="fa-solid fa-copy mr-1"></i><?= htmlspecialchars(t('printshopclientpricing.copy_default_btn')) ?>
                                    </button>
                                </div>
                                <table class="w-full text-sm">
                                    <thead class="bg-white text-gray-600 text-xs uppercase">
                                        <tr>
                                            <th class="px-3 py-2 text-start"><?= htmlspecialchars(t('printshopclientpricing.col_quantity')) ?></th>
                                            <th class="px-3 py-2 text-start"><?= htmlspecialchars(t('printshopclientpricing.col_total_price', ['currency' => $currency])) ?></th>
                                            <th class="px-3 py-2 text-start"><?= htmlspecialchars(t('printshopclientpricing.col_per_card')) ?></th>
                                            <th class="px-3 py-2 w-10"></th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100">
                                        <template x-for="(tier, idx) in paperTiers['<?= $pkey ?>']" :key="idx">
                                            <tr>
                                                <td class="px-3 py-2">
                                                    <input type="number" :name="'paper_qty[<?= $pkey ?>][' + idx + ']'" x-model.number="tier.qty"
                                                           min="1" step="1"
                                                           class="w-24 px-2 py-1 border border-gray-300 rounded">
                                                </td>
                                                <td class="px-3 py-2">
                                                    <input type="number" :name="'paper_price[<?= $pkey ?>][' + idx + ']'" x-model.number="tier.price"
                                                           min="0" step="0.001"
                                                           class="w-32 px-2 py-1 border border-gray-300 rounded">
                                                </td>
                                                <td class="px-3 py-2 text-gray-500 text-xs"
                                                    x-text="(tier.qty > 0 ? (tier.price / tier.qty).toFixed(<?= $decimals ?>) : '0')"></td>
                                                <td class="px-3 py-2 text-end">
                                                    <button type="button" @click="removePaperTier('<?= $pkey ?>', idx)" class="text-gray-400 hover:text-red-600">
                                                        <i class="fa-solid fa-trash"></i>
                                                    </button>
                                                </td>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
                                <button type="button"
                                        @click="addPaperTier('<?= $pkey ?>')"
                                        class="w-full px-3 py-2 text-sm text-blue-600 hover:bg-blue-50 border-t border-gray-200">
                                    <i class="fa-solid fa-plus mr-1"></i><?= htmlspecialchars(t('printshopclientpricing.add_tier_btn')) ?>
                                </button>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Notes -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5" for="cp_notes">
                            <?= htmlspecialchars(t('printshopclientpricing.field_notes')) ?>
                            <span class="text-gray-400 font-normal text-xs">(<?= htmlspecialchars(t('printshopclientpricing.optional')) ?>)</span>
                        </label>
                        <input type="text"
                               id="cp_notes"
                               name="notes"
                               x-model="notes"
                               aria-label="<?= htmlspecialchars(t('printshopclientpricing.field_notes')) ?>"
                               maxlength="255"
                               placeholder="<?= htmlspecialchars(t('printshopclientpricing.notes_placeholder')) ?>"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                    </div>
                </div>

                <div class="px-6 py-4 border-t border-gray-100 flex items-center justify-end gap-2 bg-gray-50 rounded-b-2xl">
                    <button type="button" @click="open = false"
                            class="px-4 py-2 text-gray-600 hover:text-gray-900 font-medium">
                        <?= htmlspecialchars(t('printshopclientpricing.cancel_btn')) ?>
                    </button>
                    <button type="submit"
                            class="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium">
                        <i class="fa-solid fa-floppy-disk mr-2"></i><?= htmlspecialchars(t('printshopclientpricing.save_btn')) ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
</div>

<script>
function clientPricing() {
    const PAPER_KEYS = ['uncoated', 'matte', 'silk'];
    return {
        open: false,
        mode: 'add',
        pricingMode: 'single',
        companyId: '',
        companyName: '',
        notes: '',
        minQuantity: 0,
        tiers: [],
        paperTiers: { uncoated: [], matte: [], silk: [] },
        defaultTiers: <?= json_encode($defaultTierRows) ?>,

        init() {},

        openAdd() {
            this.mode = 'add';
            this.pricingMode = 'single';
            this.companyId = '';
            this.companyName = '';
            this.notes = '';
            this.minQuantity = 0;
            this.tiers = JSON.parse(JSON.stringify(this.defaultTiers));
            if (this.tiers.length === 0) this.addTier();
            for (const k of PAPER_KEYS) {
                this.paperTiers[k] = JSON.parse(JSON.stringify(this.defaultTiers));
                if (this.paperTiers[k].length === 0) this.paperTiers[k].push({ qty: 100, price: 0 });
            }
            this.open = true;
        },

        openEdit(payload) {
            this.mode = 'edit';
            this.companyId = payload.company_id;
            this.companyName = payload.company_name || '';
            this.notes = payload.notes || '';
            this.minQuantity = parseInt(payload.min_quantity || 0) || 0;

            this.tiers = this._normalizeTiers(payload.tiers);
            if (this.tiers.length === 0) this.tiers = [{ qty: 100, price: 0 }];

            const ppt = payload.paper_type_pricing || null;
            for (const k of PAPER_KEYS) {
                const slice = ppt && ppt[k] && ppt[k].quantity_tiers ? ppt[k].quantity_tiers : null;
                if (slice) {
                    this.paperTiers[k] = this._normalizeTiers(slice);
                } else {
                    this.paperTiers[k] = JSON.parse(JSON.stringify(this.tiers));
                }
                if (this.paperTiers[k].length === 0) this.paperTiers[k] = [{ qty: 100, price: 0 }];
            }

            this.pricingMode = ppt ? 'per_paper' : 'single';
            this.open = true;
        },

        _normalizeTiers(input) {
            const out = [];
            if (!input) return out;
            for (const [qty, val] of Object.entries(input)) {
                const q = parseInt(qty);
                if (!q) continue;
                const price = (val && typeof val === 'object')
                    ? parseFloat(val.price || 0)
                    : (parseFloat(val) * q);
                out.push({ qty: q, price: parseFloat(price.toFixed(3)) });
            }
            out.sort((a, b) => a.qty - b.qty);
            return out;
        },

        copyDefault() {
            this.tiers = JSON.parse(JSON.stringify(this.defaultTiers));
            if (this.tiers.length === 0) this.addTier();
        },

        copyDefaultToPaper(paperKey) {
            this.paperTiers[paperKey] = JSON.parse(JSON.stringify(this.defaultTiers));
            if (this.paperTiers[paperKey].length === 0) {
                this.paperTiers[paperKey].push({ qty: 100, price: 0 });
            }
        },

        addTier() {
            const last = this.tiers[this.tiers.length - 1];
            const nextQty = last ? last.qty * 2 : 100;
            this.tiers.push({ qty: nextQty, price: 0 });
        },

        removeTier(idx) {
            this.tiers.splice(idx, 1);
            if (this.tiers.length === 0) this.addTier();
        },

        addPaperTier(paperKey) {
            const arr = this.paperTiers[paperKey];
            const last = arr[arr.length - 1];
            const nextQty = last ? last.qty * 2 : 100;
            arr.push({ qty: nextQty, price: 0 });
        },

        removePaperTier(paperKey, idx) {
            this.paperTiers[paperKey].splice(idx, 1);
            if (this.paperTiers[paperKey].length === 0) {
                this.paperTiers[paperKey].push({ qty: 100, price: 0 });
            }
        }
    }
}
</script>

</div>
<?php printshopFooter(); ?>
