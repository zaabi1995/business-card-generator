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

require_once INCLUDES_DIR . '/printshop-layout.php';
printshopHeader(t('printshopinternal.operators_title', ['shop' => $shop['name']]), 'operators');
?>
<div class="max-w-5xl mx-auto">
<div x-data="operatorsPage()" x-init="init()">
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
                        <?= $op['last_login_at'] ? sanitize(date('Y-m-d H:i', dbTs($op['last_login_at']))) : '<span class="text-gray-400">never</span>' ?>
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
    <div x-show="open" x-cloak class="fixed inset-0 z-[60] p-4 bg-black/40" style="display: none;"
         :style="open ? 'display: flex; align-items: center; justify-content: center; background: rgba(0,0,0,0.4);' : 'display: none;'"
         @click.self="open = false">
        <div class="bg-white rounded-2xl shadow-xl max-w-lg w-full">
            <form method="POST" action="save-operator.php">
                <?= csrfField() ?>
                <input type="hidden" name="id" :value="form.id">
                <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                    <h3 class="text-lg font-semibold text-gray-900">
                        <span x-show="mode === 'add'"><?= htmlspecialchars(t('printshopinternal.modal_add_op_h')) ?></span>
                        <span x-show="mode === 'edit'"><?= htmlspecialchars(t('printshopinternal.modal_edit_op_h')) ?></span>
                    </h3>
                    <button type="button" @click="open = false" aria-label="<?= htmlspecialchars(t('printshopinternal.cancel_btn')) ?>" title="<?= htmlspecialchars(t('printshopinternal.cancel_btn')) ?>" class="text-gray-400 hover:text-gray-600 hover:bg-gray-100 rounded-md p-1.5 transition-colors">
                        <i class="fa-solid fa-xmark text-xl" aria-hidden="true"></i>
                    </button>
                </div>
                <div class="p-6 space-y-4">
                    <div>
                        <label for="op_name" class="block text-sm font-medium text-gray-700 mb-1.5"><?= htmlspecialchars(t('printshopinternal.field_name')) ?></label>
                        <input type="text" name="name" id="op_name" x-model="form.name" required
                               aria-label="<?= htmlspecialchars(t('printshopinternal.field_name')) ?>"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                    </div>
                    <div>
                        <label for="op_phone" class="block text-sm font-medium text-gray-700 mb-1.5"><?= htmlspecialchars(t('printshopinternal.field_phone')) ?></label>
                        <input type="text" name="phone" id="op_phone" x-model="form.phone"
                               aria-label="<?= htmlspecialchars(t('printshopinternal.field_phone')) ?>"
                               placeholder="+968 7161 6161"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                        <p class="text-xs text-gray-500 mt-1"><?= htmlspecialchars(t('printshopinternal.phone_help')) ?></p>
                    </div>
                    <div>
                        <label for="op_email" class="block text-sm font-medium text-gray-700 mb-1.5"><?= htmlspecialchars(t('printshopinternal.field_email')) ?></label>
                        <input type="email" name="email" id="op_email" x-model="form.email"
                               aria-label="<?= htmlspecialchars(t('printshopinternal.field_email')) ?>"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                    </div>
                    <div x-show="mode === 'edit'">
                        <label for="op_status" class="block text-sm font-medium text-gray-700 mb-1.5"><?= htmlspecialchars(t('printshopinternal.field_status')) ?></label>
                        <select name="status" id="op_status" x-model="form.status"
                                aria-label="<?= htmlspecialchars(t('printshopinternal.field_status')) ?>"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg">
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

<script<?= cspNonceAttr() ?>>
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
</div>
</div>
<?php printshopFooter(); ?>
