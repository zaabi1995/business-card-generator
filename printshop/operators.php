<?php
/**
 * Print Shop Operators admin page.
 *
 * Any active operator (or the legacy email-password shop owner) can
 * add, edit, or disable other operators for the same shop. Flat
 * permissions per the spec.
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/PrintShopAuth.php';
require_once INCLUDES_DIR . '/PrintShopOperator.php';
require_once INCLUDES_DIR . '/Phone.php';

$ctx = PrintShopAuth::requireLogin();
$shop = $ctx['shop'];
$shopId = (int) $shop['id'];

$operators = PrintShopOperator::listForShop($shopId);
$flash = $_SESSION['ps_operators_flash'] ?? null;
unset($_SESSION['ps_operators_flash']);

$pageTitle = t('printshopinternal.operators_title', ['shop' => $shop['name']]);
$bodyClass = 'bg-gray-50';
require_once INCLUDES_DIR . '/ui-header.php';
?>

<nav class="bg-white border-b border-gray-200 fixed top-0 left-0 right-0 z-50">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">
            <div class="flex items-center gap-4">
                <a href="<?= getBasePath() ?>printshop/dashboard.php" class="flex items-center gap-2">
                    <img src="<?= getBasePath() ?>assets/images/logo.svg" alt="Cardify" class="h-8 w-auto">
                </a>
                <span class="text-gray-300">|</span>
                <span class="font-semibold text-gray-900"><?= sanitize($shop['name']) ?></span>
            </div>
            <div class="flex items-center gap-4 text-sm">
                <a href="dashboard.php" class="text-gray-500 hover:text-gray-700"><i class="fa-solid fa-chart-pie mr-1"></i>Dashboard</a>
                <a href="orders.php" class="text-gray-500 hover:text-gray-700"><i class="fa-solid fa-box mr-1"></i>Orders</a>
                <?php if (!empty($shop['is_internal_provider'])): ?>
                <a href="clients.php" class="text-gray-500 hover:text-gray-700"><i class="fa-solid fa-building mr-1"></i><?= htmlspecialchars(t('printshopinternal.nav_clients')) ?></a>
                <?php endif; ?>
                <a href="operators.php" class="text-blue-600 font-medium"><i class="fa-solid fa-users-gear mr-1"></i><?= htmlspecialchars(t('printshopinternal.nav_operators')) ?></a>
                <a href="<?= getBasePath() ?>logout.php" class="text-gray-500 hover:text-red-600"><i class="fa-solid fa-sign-out-alt"></i></a>
            </div>
        </div>
    </div>
</nav>

<div class="pt-20 pb-12 px-4 sm:px-6 lg:px-8 max-w-5xl mx-auto"
     x-data="operatorsPage()" x-init="init()">

    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900"><?= htmlspecialchars(t('printshopinternal.operators_heading')) ?></h1>
            <p class="text-gray-600 mt-1 max-w-2xl"><?= htmlspecialchars(t('printshopinternal.operators_subheading')) ?></p>
        </div>
        <button type="button"
                @click="openAdd()"
                class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium shadow-sm">
            <i class="fa-solid fa-plus"></i>
            <?= htmlspecialchars(t('printshopinternal.add_operator_btn')) ?>
        </button>
    </div>

    <?php if ($flash): ?>
    <div class="mb-4 p-3 rounded-lg bg-green-50 border border-green-200 text-green-700 text-sm">
        <i class="fa-solid fa-check-circle mr-2"></i><?= sanitize($flash) ?>
    </div>
    <?php endif; ?>

    <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
        <?php if (empty($operators)): ?>
        <div class="p-12 text-center">
            <i class="fa-solid fa-users text-4xl text-gray-300 mb-3"></i>
            <p class="text-gray-700 font-medium"><?= htmlspecialchars(t('printshopinternal.operators_empty_h')) ?></p>
            <p class="text-gray-500 text-sm mt-1"><?= htmlspecialchars(t('printshopinternal.operators_empty_s')) ?></p>
        </div>
        <?php else: ?>
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-gray-600 text-xs uppercase">
                <tr>
                    <th class="px-4 py-3 text-start"><?= htmlspecialchars(t('printshopinternal.col_name')) ?></th>
                    <th class="px-4 py-3 text-start"><?= htmlspecialchars(t('printshopinternal.col_phone')) ?></th>
                    <th class="px-4 py-3 text-start"><?= htmlspecialchars(t('printshopinternal.col_email')) ?></th>
                    <th class="px-4 py-3 text-start"><?= htmlspecialchars(t('printshopinternal.col_status')) ?></th>
                    <th class="px-4 py-3 text-start"><?= htmlspecialchars(t('printshopinternal.col_last_login')) ?></th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <?php foreach ($operators as $op): ?>
                <tr class="<?= $op['status'] === 'disabled' ? 'opacity-60' : '' ?>">
                    <td class="px-4 py-3 font-medium text-gray-900"><?= sanitize($op['name']) ?></td>
                    <td class="px-4 py-3 text-gray-700"><?= $op['phone'] ? sanitize($op['phone']) : '<span class="text-gray-400">,</span>' ?></td>
                    <td class="px-4 py-3 text-gray-700"><?= $op['email'] ? sanitize($op['email']) : '<span class="text-gray-400">,</span>' ?></td>
                    <td class="px-4 py-3">
                        <?php if ($op['status'] === 'active'): ?>
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 bg-green-50 text-green-700 rounded-full text-xs font-medium"><i class="fa-solid fa-circle text-[6px]"></i> <?= htmlspecialchars(t('printshopinternal.status_active')) ?></span>
                        <?php else: ?>
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 bg-gray-100 text-gray-600 rounded-full text-xs font-medium"><?= htmlspecialchars(t('printshopinternal.status_disabled')) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-gray-500 text-xs">
                        <?= $op['last_login_at'] ? sanitize(date('Y-m-d H:i', strtotime($op['last_login_at']))) : '<span class="text-gray-400">never</span>' ?>
                    </td>
                    <td class="px-4 py-3 text-end">
                        <button type="button"
                                @click='openEdit(<?= json_encode([
                                    "id"     => $op["id"],
                                    "name"   => $op["name"],
                                    "phone"  => $op["phone"],
                                    "email"  => $op["email"],
                                    "status" => $op["status"],
                                ], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'
                                class="text-blue-600 hover:text-blue-800 text-sm font-medium mr-3">
                            <i class="fa-solid fa-pen-to-square mr-1"></i><?= htmlspecialchars(t('printshopinternal.edit_btn')) ?>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <!-- Modal -->
    <div x-show="open" x-cloak class="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-black/40" @click.self="open = false">
        <div class="bg-white rounded-2xl shadow-xl max-w-lg w-full">
            <form method="POST" action="save-operator.php">
                <?= csrfField() ?>
                <input type="hidden" name="id" :value="form.id">
                <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                    <h3 class="text-lg font-semibold text-gray-900">
                        <span x-show="mode === 'add'"><?= htmlspecialchars(t('printshopinternal.modal_add_op_h')) ?></span>
                        <span x-show="mode === 'edit'"><?= htmlspecialchars(t('printshopinternal.modal_edit_op_h')) ?></span>
                    </h3>
                    <button type="button" @click="open = false" class="text-gray-400 hover:text-gray-600">
                        <i class="fa-solid fa-xmark text-xl"></i>
                    </button>
                </div>
                <div class="p-6 space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5"><?= htmlspecialchars(t('printshopinternal.field_name')) ?></label>
                        <input type="text" name="name" x-model="form.name" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5"><?= htmlspecialchars(t('printshopinternal.field_phone')) ?></label>
                        <input type="text" name="phone" x-model="form.phone"
                               placeholder="+968 7161 6161"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                        <p class="text-xs text-gray-500 mt-1"><?= htmlspecialchars(t('printshopinternal.phone_help')) ?></p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5"><?= htmlspecialchars(t('printshopinternal.field_email')) ?></label>
                        <input type="email" name="email" x-model="form.email"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                    </div>
                    <div x-show="mode === 'edit'">
                        <label class="block text-sm font-medium text-gray-700 mb-1.5"><?= htmlspecialchars(t('printshopinternal.field_status')) ?></label>
                        <select name="status" x-model="form.status" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                            <option value="active"><?= htmlspecialchars(t('printshopinternal.status_active')) ?></option>
                            <option value="disabled"><?= htmlspecialchars(t('printshopinternal.status_disabled')) ?></option>
                        </select>
                    </div>
                </div>
                <div class="px-6 py-4 border-t border-gray-100 flex items-center justify-end gap-2 bg-gray-50 rounded-b-2xl">
                    <button type="button" @click="open = false" class="px-4 py-2 text-gray-600 hover:text-gray-900 font-medium"><?= htmlspecialchars(t('printshopinternal.cancel_btn')) ?></button>
                    <button type="submit" class="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium"><i class="fa-solid fa-floppy-disk mr-2"></i><?= htmlspecialchars(t('printshopinternal.save_btn')) ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function operatorsPage() {
    return {
        open: false, mode: 'add',
        form: { id: '', name: '', phone: '', email: '', status: 'active' },
        init() {},
        openAdd() {
            this.mode = 'add';
            this.form = { id: '', name: '', phone: '', email: '', status: 'active' };
            this.open = true;
        },
        openEdit(payload) {
            this.mode = 'edit';
            this.form = {
                id: payload.id,
                name: payload.name || '',
                phone: payload.phone || '',
                email: payload.email || '',
                status: payload.status || 'active',
            };
            this.open = true;
        }
    }
}
</script>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
