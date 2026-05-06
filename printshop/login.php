<?php
/**
 * Print Shop Login
 *
 * Two paths: classic email+password (delegates to /login.php for the
 * shop owner record) OR phone/email OTP for operators (Ali, Arshad,
 * Hussain) registered in `print_shop_operators`.
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Phone.php';
require_once INCLUDES_DIR . '/PrintShopAuth.php';

// Already signed in? Bounce to dashboard.
$ctx = PrintShopAuth::context();
if ($ctx['shop']) {
    header('Location: ' . getBasePath() . 'printshop/dashboard.php');
    exit;
}

$pageTitle = t('printshopinternal.login_title');
$bodyClass = 'bg-gray-50';
require_once INCLUDES_DIR . '/ui-header.php';

$flash = $_SESSION['ps_login_flash'] ?? null;
unset($_SESSION['ps_login_flash']);
?>

<div class="min-h-screen flex items-center justify-center px-4 py-12">
    <div class="w-full max-w-md" x-data="psLogin()" x-init="init()">
        <div class="text-center mb-6">
            <a href="<?= getBasePath() ?>" class="inline-block">
                <img src="<?= getBasePath() ?>assets/images/logo.svg" alt="Cardify" class="h-10 w-auto mx-auto">
            </a>
            <h1 class="mt-4 text-xl font-bold text-gray-900"><?= htmlspecialchars(t('printshopinternal.login_heading')) ?></h1>
            <p class="mt-1 text-sm text-gray-500"><?= htmlspecialchars(t('printshopinternal.login_subheading')) ?></p>
        </div>

        <?php if ($flash): ?>
        <div class="mb-4 p-3 rounded-lg bg-amber-50 border border-amber-200 text-amber-800 text-sm">
            <?= sanitize($flash) ?>
        </div>
        <?php endif; ?>

        <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
            <div class="grid grid-cols-2 divide-x divide-gray-200 bg-gray-50">
                <button type="button"
                        @click="tab = 'otp'"
                        :class="tab === 'otp' ? 'bg-white text-blue-600 font-semibold' : 'text-gray-600'"
                        class="px-4 py-3 text-sm transition-colors">
                    <i class="fa-solid fa-mobile-screen mr-2"></i><?= htmlspecialchars(t('printshopinternal.login_tab_otp')) ?>
                </button>
                <button type="button"
                        @click="tab = 'password'"
                        :class="tab === 'password' ? 'bg-white text-blue-600 font-semibold' : 'text-gray-600'"
                        class="px-4 py-3 text-sm transition-colors">
                    <i class="fa-solid fa-key mr-2"></i><?= htmlspecialchars(t('printshopinternal.login_tab_password')) ?>
                </button>
            </div>

            <div x-show="tab === 'otp'" class="p-6">
                <div x-show="step === 'identifier'" class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5"><?= htmlspecialchars(t('printshopinternal.field_identifier')) ?></label>
                        <input type="text"
                               aria-label="<?= htmlspecialchars(t('printshopinternal.field_identifier')) ?>"
                               x-model="identifier"
                               @keydown.enter.prevent="requestOtp()"
                               placeholder="<?= htmlspecialchars(t('printshopinternal.identifier_placeholder')) ?>"
                               autocomplete="username"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2">
                        <p class="text-xs text-gray-500 mt-1"><?= htmlspecialchars(t('printshopinternal.identifier_help')) ?></p>
                    </div>
                    <button type="button"
                            @click="requestOtp()"
                            :disabled="busy || identifier.trim() === ''"
                            :class="(busy || identifier.trim() === '') ? 'opacity-50 cursor-not-allowed' : ''"
                            class="w-full px-4 py-2.5 text-white rounded-lg font-medium">
                        <span x-show="!busy"><i class="fa-solid fa-paper-plane mr-2"></i><?= htmlspecialchars(t('printshopinternal.send_code_btn')) ?></span>
                        <span x-show="busy"><i class="fa-solid fa-spinner fa-spin mr-2"></i><?= htmlspecialchars(t('printshopinternal.sending')) ?></span>
                    </button>
                </div>

                <div x-show="step === 'code'" class="space-y-4">
                    <div class="p-3 bg-blue-50 border border-blue-200 rounded-lg text-sm text-blue-800">
                        <i class="fa-solid fa-circle-info mr-1"></i>
                        <span x-text="codeMessage"></span>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5"><?= htmlspecialchars(t('printshopinternal.field_code')) ?></label>
                        <input type="text"
                               aria-label="<?= htmlspecialchars(t('printshopinternal.field_code')) ?>"
                               x-model="code"
                               @keydown.enter.prevent="verifyOtp()"
                               inputmode="numeric"
                               pattern="[0-9]{6}"
                               maxlength="6"
                               placeholder="000000"
                               autocomplete="one-time-code"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 text-center tracking-widest text-lg font-mono">
                    </div>
                    <label class="flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" x-model="remember" aria-label="<?= htmlspecialchars(t('printshopinternal.remember_me')) ?>" class="rounded">
                        <?= htmlspecialchars(t('printshopinternal.remember_me')) ?>
                    </label>
                    <div class="flex items-center gap-2">
                        <button type="button"
                                @click="step = 'identifier'; code = ''"
                                class="px-3 py-2 text-gray-600 hover:text-gray-900 text-sm font-medium">
                            <i class="fa-solid fa-arrow-left mr-1"></i><?= htmlspecialchars(t('printshopinternal.back')) ?>
                        </button>
                        <button type="button"
                                @click="verifyOtp()"
                                :disabled="busy || code.length !== 6"
                                :class="(busy || code.length !== 6) ? 'opacity-50 cursor-not-allowed' : ''"
                                class="flex-1 px-4 py-2.5 text-white rounded-lg font-medium">
                            <span x-show="!busy"><i class="fa-solid fa-check mr-2"></i><?= htmlspecialchars(t('printshopinternal.verify_btn')) ?></span>
                            <span x-show="busy"><i class="fa-solid fa-spinner fa-spin mr-2"></i><?= htmlspecialchars(t('printshopinternal.verifying')) ?></span>
                        </button>
                    </div>
                </div>

                <div x-show="errorMsg" class="mt-3 p-3 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm">
                    <i class="fa-solid fa-circle-exclamation mr-1"></i><span x-text="errorMsg"></span>
                </div>
            </div>

            <div x-show="tab === 'password'" class="p-6 text-sm text-gray-600 space-y-3">
                <p><?= htmlspecialchars(t('printshopinternal.password_explainer')) ?></p>
                <a href="<?= getBasePath() ?>login.php"
                   class="inline-flex items-center gap-2 px-4 py-2.5 bg-gray-900 hover:bg-gray-800 text-white rounded-lg font-medium">
                    <i class="fa-solid fa-arrow-right-to-bracket"></i>
                    <?= htmlspecialchars(t('printshopinternal.password_btn')) ?>
                </a>
            </div>
        </div>

        <p class="mt-6 text-center text-xs text-gray-500">
            <?= htmlspecialchars(t('printshopinternal.support_hint')) ?>
        </p>
    </div>
</div>

<script>
const PS_CSRF = <?= json_encode(generateCSRFToken()) ?>;
const PS_BASE = <?= json_encode(getBasePath() . 'printshop/') ?>;
function psLogin() {
    return {
        tab: 'otp',
        step: 'identifier',
        identifier: '',
        code: '',
        remember: true,
        busy: false,
        errorMsg: '',
        codeMessage: '',

        init() {},

        async requestOtp() {
            this.errorMsg = '';
            if (this.identifier.trim() === '') return;
            this.busy = true;
            try {
                const fd = new FormData();
                fd.append('csrf_token', PS_CSRF);
                fd.append('identifier', this.identifier.trim());
                const r = await fetch(PS_BASE + 'request-otp.php', { method: 'POST', body: fd });
                const j = await r.json();
                if (j.ok) {
                    this.codeMessage = j.message || ('Code sent to ' + (j.masked || ''));
                    this.step = 'code';
                } else {
                    this.errorMsg = j.error_message || 'Could not send code.';
                }
            } catch (e) {
                this.errorMsg = 'Network error. Please try again.';
            } finally {
                this.busy = false;
            }
        },

        async verifyOtp() {
            this.errorMsg = '';
            if (this.code.length !== 6) return;
            this.busy = true;
            try {
                const fd = new FormData();
                fd.append('csrf_token', PS_CSRF);
                fd.append('identifier', this.identifier.trim());
                fd.append('code', this.code);
                fd.append('remember', this.remember ? '1' : '0');
                const r = await fetch(PS_BASE + 'verify-otp.php', { method: 'POST', body: fd });
                const j = await r.json();
                if (j.ok && j.redirect) {
                    window.location = j.redirect;
                } else {
                    this.errorMsg = j.error_message || 'Invalid code.';
                }
            } catch (e) {
                this.errorMsg = 'Network error. Please try again.';
            } finally {
                this.busy = false;
            }
        }
    }
}
</script>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
