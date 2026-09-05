<?php
/**
 * Shared cancel-order modal for any print-shop page.
 *
 * Listens on `window` for `ps:cancel-order` CustomEvent with `detail: {id, ref}`.
 * Any cancel button on the page can dispatch the event:
 *
 *   a button that dispatches the CustomEvent 'ps:cancel-order' with
 *   detail { id: 123, ref: '#123' }
 *
 * Posts to printshop/cancel-order.php with csrf_token, order_id, reason.
 *
 * Caller is responsible for first checking PrintShopAuth::can('cancel_order')
 * and only emitting this partial when allowed; the markup itself is hidden
 * when no PrintShopAuth context exists.
 */
if (!class_exists('PrintShopAuth')) {
    require_once __DIR__ . '/PrintShopAuth.php';
}
if (!PrintShopAuth::can('cancel_order')) {
    return;
}
?>
<div x-data="cancelOrderModal()" @ps:cancel-order.window="open($event.detail)"
     class="fixed inset-0 z-[110] items-center justify-center px-4"
     style="display: none;"
     :style="visible ? 'display: flex; background: rgba(15,23,42,0.55);' : 'display: none;'">
    <div @click.outside="if (!busy) close()" class="bg-white rounded-2xl shadow-lg w-full max-w-lg p-6 sm:p-8 relative">
        <button type="button" @click="close()" :disabled="busy"
                aria-label="<?= htmlspecialchars(t('printshopdash.cancel_keep')) ?>"
                title="<?= htmlspecialchars(t('printshopdash.cancel_keep')) ?>"
                class="absolute top-4 right-4 text-gray-400 hover:text-gray-700 rounded-full p-1 hover:bg-gray-100 transition-colors">
            <i class="fa-solid fa-xmark text-lg"></i>
        </button>

        <div x-show="!success" class="space-y-4">
            <div class="flex items-start gap-3">
                <div class="w-10 h-10 rounded-full bg-red-100 flex items-center justify-center flex-shrink-0">
                    <i class="fa-solid fa-triangle-exclamation text-red-600"></i>
                </div>
                <div>
                    <h3 class="text-lg font-bold text-gray-900" x-text="psStrtr('<?= htmlspecialchars(t('printshopdash.cancel_confirm_h')) ?>', ':id', orderRef)"></h3>
                    <p class="text-sm text-gray-500 mt-1"><?= htmlspecialchars(t('printshopdash.cancel_confirm_b')) ?></p>
                </div>
            </div>

            <form @submit.prevent="submit()" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5"><?= htmlspecialchars(t('printshopdash.cancel_reason_label')) ?></label>
                    <textarea x-model="reason" required rows="3" maxlength="1000"
                              placeholder="<?= htmlspecialchars(t('printshopdash.cancel_reason_ph')) ?>"
                              class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-red-500/30 focus:border-red-500"></textarea>
                </div>
                <div x-show="error" x-cloak class="p-3 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm" style="display: none;">
                    <div class="flex items-start gap-2">
                        <i class="fa-solid fa-circle-exclamation mt-0.5"></i><span x-text="error"></span>
                    </div>
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" class="ps-btn-secondary text-sm" @click="close()" :disabled="busy"><?= htmlspecialchars(t('printshopdash.cancel_keep')) ?></button>
                    <button type="submit" class="text-sm font-semibold inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-red-600 hover:bg-red-700 text-white" :disabled="busy || !reason.trim()">
                        <i class="fa-solid" :class="busy ? 'fa-circle-notch fa-spin' : 'fa-xmark'"></i>
                        <span x-text="busy ? '<?= htmlspecialchars(t('printshopdash.cancel_busy')) ?>' : '<?= htmlspecialchars(t('printshopdash.cancel_submit')) ?>'"></span>
                    </button>
                </div>
            </form>
        </div>

        <div x-show="success" x-cloak class="space-y-4">
            <div class="p-4 rounded-lg bg-green-50 border border-green-200 text-green-800 text-sm flex items-center gap-2">
                <i class="fa-solid fa-circle-check"></i>
                <span x-text="psStrtr('<?= htmlspecialchars(t('printshopdash.cancel_success')) ?>', ':id', orderRef)"></span>
            </div>
            <div class="flex justify-end pt-2">
                <button type="button" class="ps-btn-primary text-sm" @click="reload()">OK</button>
            </div>
        </div>
    </div>
</div>

<script<?= cspNonceAttr() ?>>
if (typeof window.psStrtr !== 'function') {
    window.psStrtr = function (str, k, v) { return String(str).split(k).join(v); };
}
if (typeof window.cancelOrderModal !== 'function') {
    window.cancelOrderModal = function () {
        return {
            visible: false, busy: false, success: false, error: '',
            orderId: 0, orderRef: '', reason: '',
            csrfToken: <?= json_encode(generateCSRFToken()) ?>,
            open(detail) {
                this.orderId = (detail && detail.id) ? parseInt(detail.id, 10) : 0;
                this.orderRef = (detail && detail.ref) ? String(detail.ref) : ('#' + this.orderId);
                this.reason = ''; this.error = ''; this.success = false; this.busy = false;
                this.visible = true;
            },
            close() { this.visible = false; },
            reload() { window.location.reload(); },
            async submit() {
                if (!this.reason.trim()) return;
                this.busy = true; this.error = '';
                try {
                    var fd = new FormData();
                    fd.set('csrf_token', this.csrfToken);
                    fd.set('order_id',   this.orderId);
                    fd.set('reason',     this.reason.trim());
                    var res = await fetch('cancel-order.php', { method: 'POST', body: fd, credentials: 'same-origin' });
                    var json = null;
                    try { json = await res.json(); } catch (_) {}
                    if (!res.ok || !json || !json.ok) {
                        this.error = (json && json.error) || ('HTTP ' + res.status);
                        this.busy = false;
                        return;
                    }
                    this.success = true;
                    this.busy = false;
                } catch (e) {
                    this.error = String(e && e.message ? e.message : e);
                    this.busy = false;
                }
            }
        };
    };
}
</script>
