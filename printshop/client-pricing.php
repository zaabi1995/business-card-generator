<?php
/**
 * Print Shop, Client Pricing
 *
 * Lets a print shop set per-client price overrides on top of its
 * default tier table. Use case: BHD honoring a quoted price for
 * Otech without having to change global pricing for everyone.
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

$overrides = PrintShop::listClientPricing($shopId);
$candidates = PrintShop::getClientCompanies($shopId);

// Strip companies that already have an override from the candidate list
$existingCompanyIds = array_column($overrides, 'company_id');
$candidates = array_values(array_filter($candidates, function ($c) use ($existingCompanyIds) {
    return !in_array($c['id'], $existingCompanyIds, true);
}));

$pageTitle = t('printshopclientpricing.page_title', ['shop' => $printShop['name']]);
$bodyClass = 'bg-gray-50';
require_once INCLUDES_DIR . '/ui-header.php';

$flashMessage = $_SESSION['client_pricing_flash'] ?? null;
unset($_SESSION['client_pricing_flash']);
?>

<div class="min-h-screen">
<nav class="bg-white border-b border-gray-200 fixed top-0 left-0 right-0 z-50">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">
            <div class="flex items-center gap-4">
                <a href="<?= getBasePath() ?>printshop/dashboard.php" class="flex items-center gap-2">
                    <img src="<?= getBasePath() ?>assets/images/logo.svg" alt="Cardify" class="h-8 w-auto">
                </a>
                <span class="text-gray-300">|</span>
                <span class="font-semibold text-gray-900"><?= sanitize($printShop['name']) ?></span>
            </div>
            <div class="flex items-center gap-4 text-sm">
                <a href="dashboard.php" class="text-gray-500 hover:text-gray-700"><i class="fa-solid fa-chart-pie mr-1"></i><?= htmlspecialchars(t('printshopdash.nav_dashboard')) ?></a>
                <a href="orders.php" class="text-gray-500 hover:text-gray-700"><i class="fa-solid fa-box mr-1"></i><?= htmlspecialchars(t('printshopdash.nav_orders')) ?></a>
                <a href="credit-accounts.php" class="text-gray-500 hover:text-gray-700"><i class="fa-solid fa-building-columns mr-1"></i><?= htmlspecialchars(t('printshopdash.nav_credit')) ?></a>
                <a href="client-pricing.php" class="text-blue-600 font-medium"><i class="fa-solid fa-tags mr-1"></i><?= htmlspecialchars(t('printshopclientpricing.nav_label')) ?></a>
                <a href="settings.php" class="text-gray-500 hover:text-gray-700"><i class="fa-solid fa-cog"></i></a>
            </div>
        </div>
    </div>
</nav>

<div class="pt-20 pb-12 px-4 sm:px-6 lg:px-8 max-w-7xl mx-auto"
     x-data="clientPricing()"
     x-init="init()">

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
            $rowTiers   = $rowPricing['quantity_tiers'] ?? [];
            ksort($rowTiers, SORT_NUMERIC);
        ?>
            <div class="p-4 sm:p-5 flex flex-wrap items-start justify-between gap-4">
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2 mb-2">
                        <span class="font-semibold text-gray-900"><?= sanitize($row['company_name'] ?? t('printshopclientpricing.unknown_company')) ?></span>
                        <?php if (!empty($row['company_slug'])): ?>
                        <span class="text-xs text-gray-400">/<?= sanitize($row['company_slug']) ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if (empty($rowTiers)): ?>
                    <p class="text-sm text-gray-500 italic"><?= htmlspecialchars(t('printshopclientpricing.no_tiers_set')) ?></p>
                    <?php else: ?>
                    <div class="flex flex-wrap gap-2 mb-2">
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
                    <?php endif; ?>
                    <?php if (!empty($row['notes'])): ?>
                    <p class="text-xs text-gray-500 mt-1"><i class="fa-solid fa-note-sticky mr-1 text-gray-400"></i><?= sanitize($row['notes']) ?></p>
                    <?php endif; ?>
                </div>
                <div class="flex items-center gap-2 shrink-0">
                    <button type="button"
                            @click='openEdit(<?= json_encode([
                                "company_id"   => $row["company_id"],
                                "company_name" => $row["company_name"],
                                "tiers"        => $rowTiers,
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
        <div class="bg-white rounded-2xl shadow-xl max-w-2xl w-full max-h-[90vh] overflow-y-auto">
            <form method="POST" action="save-client-pricing.php">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="save">

                <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                    <h3 class="text-lg font-semibold text-gray-900">
                        <span x-show="mode === 'add'"><?= htmlspecialchars(t('printshopclientpricing.modal_add_h')) ?></span>
                        <span x-show="mode === 'edit'"><?= htmlspecialchars(t('printshopclientpricing.modal_edit_h')) ?></span>
                    </h3>
                    <button type="button" @click="open = false" class="text-gray-400 hover:text-gray-600">
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
                                x-model="companyId"
                                :required="mode === 'add'"
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

                    <!-- Tier table -->
                    <div>
                        <div class="flex items-end justify-between mb-2">
                            <label class="block text-sm font-medium text-gray-700">
                                <?= htmlspecialchars(t('printshopclientpricing.field_tiers')) ?>
                            </label>
                            <button type="button"
                                    @click="copyDefault()"
                                    class="text-xs text-blue-600 hover:text-blue-800 font-medium">
                                <i class="fa-solid fa-copy mr-1"></i><?= htmlspecialchars(t('printshopclientpricing.copy_default_btn')) ?>
                            </button>
                        </div>
                        <p class="text-xs text-gray-500 mb-3"><?= htmlspecialchars(t('printshopclientpricing.tiers_help', ['currency' => $currency])) ?></p>

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
                                                <input type="number"
                                                       :name="'tier_qty[' + idx + ']'"
                                                       x-model.number="tier.qty"
                                                       min="1"
                                                       step="1"
                                                       required
                                                       class="w-24 px-2 py-1 border border-gray-300 rounded">
                                            </td>
                                            <td class="px-3 py-2">
                                                <input type="number"
                                                       :name="'tier_price[' + idx + ']'"
                                                       x-model.number="tier.price"
                                                       min="0"
                                                       step="0.001"
                                                       required
                                                       class="w-32 px-2 py-1 border border-gray-300 rounded">
                                            </td>
                                            <td class="px-3 py-2 text-gray-500 text-xs"
                                                x-text="(tier.qty > 0 ? (tier.price / tier.qty).toFixed(<?= $decimals ?>) : '0')">
                                            </td>
                                            <td class="px-3 py-2 text-end">
                                                <button type="button"
                                                        @click="removeTier(idx)"
                                                        class="text-gray-400 hover:text-red-600">
                                                    <i class="fa-solid fa-trash"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                            <button type="button"
                                    @click="addTier()"
                                    class="w-full px-3 py-2 text-sm text-blue-600 hover:bg-blue-50 border-t border-gray-200">
                                <i class="fa-solid fa-plus mr-1"></i><?= htmlspecialchars(t('printshopclientpricing.add_tier_btn')) ?>
                            </button>
                        </div>
                    </div>

                    <!-- Notes -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5">
                            <?= htmlspecialchars(t('printshopclientpricing.field_notes')) ?>
                            <span class="text-gray-400 font-normal text-xs">(<?= htmlspecialchars(t('printshopclientpricing.optional')) ?>)</span>
                        </label>
                        <input type="text"
                               name="notes"
                               x-model="notes"
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
    return {
        open: false,
        mode: 'add',
        companyId: '',
        companyName: '',
        notes: '',
        tiers: [],
        defaultTiers: <?php
            $defaultTierRows = [];
            foreach ($shopTiers as $qty => $val) {
                $price = is_array($val) ? ($val['price'] ?? 0) : ((float)$val * (int)$qty);
                $defaultTierRows[] = ['qty' => (int)$qty, 'price' => round((float)$price, 3)];
            }
            usort($defaultTierRows, fn($a, $b) => $a['qty'] <=> $b['qty']);
            echo json_encode($defaultTierRows);
        ?>,

        init() {},

        openAdd() {
            this.mode = 'add';
            this.companyId = '';
            this.companyName = '';
            this.notes = '';
            this.tiers = JSON.parse(JSON.stringify(this.defaultTiers));
            if (this.tiers.length === 0) this.addTier();
            this.open = true;
        },

        openEdit(payload) {
            this.mode = 'edit';
            this.companyId = payload.company_id;
            this.companyName = payload.company_name || '';
            this.notes = payload.notes || '';
            const t = [];
            for (const [qty, val] of Object.entries(payload.tiers || {})) {
                const q = parseInt(qty);
                const price = (val && typeof val === 'object')
                    ? parseFloat(val.price || 0)
                    : (parseFloat(val) * q);
                t.push({ qty: q, price: parseFloat(price.toFixed(3)) });
            }
            t.sort((a, b) => a.qty - b.qty);
            this.tiers = t.length ? t : [{ qty: 100, price: 0 }];
            this.open = true;
        },

        copyDefault() {
            this.tiers = JSON.parse(JSON.stringify(this.defaultTiers));
            if (this.tiers.length === 0) this.addTier();
        },

        addTier() {
            const last = this.tiers[this.tiers.length - 1];
            const nextQty = last ? last.qty * 2 : 100;
            this.tiers.push({ qty: nextQty, price: 0 });
        },

        removeTier(idx) {
            this.tiers.splice(idx, 1);
            if (this.tiers.length === 0) this.addTier();
        }
    }
}
</script>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
