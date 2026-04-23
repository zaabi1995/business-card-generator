<?php
/**
 * Passwordless employee self-edit page. Reached via
 *   /portal/employee-edit?token={40-char hex}
 *
 * The token is mailed / WhatsApped to the employee by their admin; no
 * session is required. Autosave is debounced client-side; server-side
 * writes go through portal/employee-edit-save.php.
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/EmployeeEditToken.php';
require_once INCLUDES_DIR . '/AppleWalletPass.php';

$token = trim($_GET['token'] ?? '');
$employee = EmployeeEditToken::verify($token);

$existingSocials = [];
if ($employee) {
    try {
        $db = Database::getInstance();
        $rows = $db->fetchAll(
            "SELECT platform, url FROM employee_socials WHERE employee_id = :eid ORDER BY position ASC, id ASC",
            ['eid' => $employee['id']]
        );
        foreach ($rows as $r) {
            $existingSocials[] = ['platform' => $r['platform'], 'url' => $r['url']];
        }
    } catch (Throwable $e) { /* table may not exist in minimal envs */ }
}

$locale = function_exists('currentLocale') ? currentLocale() : 'en';
$dir    = function_exists('currentDir')    ? currentDir()    : 'ltr';
$isAr   = ($locale === 'ar');
$csrf   = generateCSRFToken();

if (!$employee) {
    http_response_code(410);
    ?><!DOCTYPE html><html lang="<?= htmlspecialchars($locale) ?>" dir="<?= htmlspecialchars($dir) ?>">
    <head>
      <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
      <title><?= $isAr ? 'انتهى الرابط' : 'Link expired' ?></title>
      <script src="https://cdn.tailwindcss.com"></script>
      <style>body{font-family:<?= $isAr ? "'IBM Plex Sans Arabic'" : "system-ui" ?>,sans-serif;background:#f5f7fb;}</style>
    </head>
    <body class="min-h-screen flex items-center justify-center p-4">
      <div class="max-w-md w-full bg-white rounded-2xl shadow-sm border border-gray-100 p-8 text-center">
        <div class="w-12 h-12 mx-auto bg-amber-50 rounded-full flex items-center justify-center mb-4">
          <i class="fa-solid fa-hourglass-end text-amber-500 text-xl"></i>
        </div>
        <h1 class="text-lg font-bold text-gray-900"><?= htmlspecialchars(t('portal.link_expired')) ?></h1>
        <p class="text-sm text-gray-500 mt-2"><?= $isAr ? 'تواصل مع الإدارة ليرسلوا لك رابطاً جديداً.' : 'Ask your admin to send you a fresh link.' ?></p>
      </div>
    </body></html><?php
    exit;
}

$pageTitle = t('portal.edit_my_details');
?><!DOCTYPE html>
<html lang="<?= htmlspecialchars($locale) ?>" dir="<?= htmlspecialchars($dir) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link rel="icon" href="/favicon.svg">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <?php if ($isAr): ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&display=swap" rel="stylesheet">
    <?php endif; ?>
    <style>
        body { font-family: <?= $isAr ? "'IBM Plex Sans Arabic'" : "system-ui,-apple-system,'Segoe UI'" ?>,sans-serif; background:#f5f7fb; }
        .form-input { width:100%; padding:0.625rem 0.875rem; border:1px solid #e2e8f0; border-radius:0.5rem; background:#fff; font-size:0.95rem; }
        .form-input:focus { outline:none; border-color:#009bc1; box-shadow:0 0 0 3px rgba(0,155,193,0.15); }
    </style>
</head>
<body class="min-h-screen"
      x-data='employeeEdit(<?= json_encode([
          'employee' => [
              'id' => $employee['id'],
              'name_en' => $employee['name_en'],
              'name_ar' => $employee['name_ar'],
              'position_en' => $employee['position_en'],
              'phone' => $employee['phone'],
              'mobile' => $employee['mobile'],
              'email' => $employee['email'],
              'website' => $employee['website'],
              'photo' => $employee['photo'] ?? '',
          ],
          'socials' => $existingSocials,
          'token' => $token,
          'csrf'  => $csrf,
          'saveUrl' => '/portal/employee-edit-save.php',
          'locale'  => $locale,
      ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'
      x-init="init()">

    <div class="max-w-lg mx-auto p-4 sm:p-6">
        <!-- Header -->
        <div class="mb-5 text-center">
            <div class="inline-flex items-center gap-2 text-xs font-medium text-purple-600 bg-white border border-purple-200 px-3 py-1 rounded-full shadow-sm">
                <i class="fa-solid fa-id-card"></i>
                <span><?= htmlspecialchars(t('portal.my_card')) ?></span>
            </div>
            <h1 class="text-xl sm:text-2xl font-bold text-gray-900 mt-3"><?= htmlspecialchars(t('portal.edit_my_details')) ?></h1>
            <p class="text-xs text-gray-500 mt-1"
               :class="savingState === 'saved' ? 'text-green-600' : ''"
               x-text="statusText()"></p>
        </div>

        <!-- Live card preview -->
        <div class="rounded-2xl p-5 mb-5 text-white bg-gradient-to-br from-[#009bc1] to-[#824598]">
            <div class="text-xs uppercase tracking-widest opacity-75" x-text="labels.my_card"></div>
            <div class="text-xl font-bold mt-1" x-text="data.name_en || '—'"></div>
            <div class="text-sm opacity-90" x-text="data.position_en"></div>
            <div class="mt-4 text-xs opacity-85 space-y-1" dir="ltr">
                <div x-show="data.email"><i class="fa-solid fa-envelope mr-2 opacity-70"></i><span x-text="data.email"></span></div>
                <div x-show="data.phone"><i class="fa-solid fa-phone mr-2 opacity-70"></i><span x-text="data.phone"></span></div>
                <div x-show="data.mobile"><i class="fa-solid fa-mobile-screen mr-2 opacity-70"></i><span x-text="data.mobile"></span></div>
                <div x-show="data.website"><i class="fa-solid fa-globe mr-2 opacity-70"></i><span x-text="data.website"></span></div>
            </div>
        </div>

        <!-- Form -->
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 sm:p-6 space-y-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2"><?= htmlspecialchars(t('portal.photo')) ?></label>
                <label class="block border-2 border-dashed rounded-xl p-4 text-center cursor-pointer transition hover:border-[#009bc1]"
                       :class="photoDragActive ? 'border-[#009bc1] bg-[#009bc1]/5' : 'border-gray-200'"
                       @dragover.prevent="photoDragActive = true"
                       @dragleave.prevent="photoDragActive = false"
                       @drop.prevent="handlePhotoDrop($event)">
                    <template x-if="data.photo">
                        <div class="flex items-center gap-3">
                            <img :src="data.photo" alt="" class="w-14 h-14 rounded-full object-cover border border-gray-200">
                            <div class="flex-1 text-start">
                                <p class="text-xs font-semibold text-gray-700"><?= htmlspecialchars(t('portal.photo_replace')) ?></p>
                                <p class="text-xs text-gray-400" x-text="photoStatus"></p>
                            </div>
                        </div>
                    </template>
                    <template x-if="!data.photo">
                        <div class="py-3">
                            <i class="fa-solid fa-camera text-2xl text-gray-300 mb-1"></i>
                            <p class="text-sm text-gray-600"><?= htmlspecialchars(t('portal.photo_dropzone')) ?></p>
                            <p class="text-xs text-gray-400 mt-1"><?= htmlspecialchars(t('portal.photo_hint')) ?></p>
                        </div>
                    </template>
                    <input type="file" accept="image/png,image/jpeg,image/webp" class="hidden" @change="handlePhotoInput($event)">
                </label>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1"><?= htmlspecialchars(t('portal.first_name')) ?></label>
                <input type="text" x-model="data.name_en" @input.debounce.800ms="save()" class="form-input">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1"><?= htmlspecialchars(t('portal.job_title')) ?></label>
                <input type="text" x-model="data.position_en" @input.debounce.800ms="save()" class="form-input">
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1"><?= htmlspecialchars(t('common.phone')) ?></label>
                    <input type="tel" x-model="data.phone" @input.debounce.800ms="save()" class="form-input" dir="ltr">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1"><?= htmlspecialchars(t('portal.mobile')) ?></label>
                    <input type="tel" x-model="data.mobile" @input.debounce.800ms="save()" class="form-input" dir="ltr">
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1"><?= htmlspecialchars(t('common.email')) ?></label>
                <input type="email" x-model="data.email" @input.debounce.800ms="save()" class="form-input" dir="ltr">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1"><?= htmlspecialchars(t('portal.website')) ?></label>
                <input type="url" x-model="data.website" @input.debounce.800ms="save()" class="form-input" dir="ltr" placeholder="https://">
            </div>

            <div class="pt-4 border-t border-gray-100">
                <div class="flex items-center justify-between mb-2">
                    <label class="text-sm font-medium text-gray-700"><?= htmlspecialchars(t('portal.social_links')) ?></label>
                    <button type="button" @click="addSocial()" class="text-xs font-semibold text-[#009bc1] hover:text-[#007a99]">
                        <i class="fa-solid fa-plus mr-1"></i><?= htmlspecialchars(t('portal.social_add')) ?>
                    </button>
                </div>
                <p class="text-xs text-gray-500 mb-3"><?= htmlspecialchars(t('portal.social_hint')) ?></p>
                <template x-for="(s, idx) in socials" :key="idx">
                    <div class="flex items-center gap-2 mb-2">
                        <select x-model="s.platform" @change="save()" class="form-input !w-36 !py-2 text-sm" dir="ltr">
                            <template x-for="opt in platforms" :key="opt.key">
                                <option :value="opt.key" x-text="opt.label"></option>
                            </template>
                        </select>
                        <input type="url" x-model="s.url" @input.debounce.800ms="save()"
                               class="form-input flex-1 text-sm" dir="ltr" placeholder="https://">
                        <button type="button" @click="removeSocial(idx)"
                                class="text-gray-400 hover:text-red-500 text-lg px-2" aria-label="Remove">
                            <i class="fa-solid fa-times"></i>
                        </button>
                    </div>
                </template>
            </div>
        </div>

        <?php if (AppleWalletPass::isEnabled()): ?>
        <div class="mt-5 bg-white rounded-2xl shadow-sm border border-gray-100 p-4 flex items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                <i class="fa-brands fa-apple text-2xl text-gray-900"></i>
                <div>
                    <p class="text-sm font-semibold text-gray-900"><?= htmlspecialchars(t('portal.wallet_apple_title')) ?></p>
                    <p class="text-xs text-gray-500"><?= htmlspecialchars(t('portal.wallet_apple_hint')) ?></p>
                </div>
            </div>
            <a href="/wallet_apple.php?i=<?= htmlspecialchars(urlencode($employee['id'])) ?>"
               class="px-4 py-2 bg-gray-900 hover:bg-black text-white text-xs font-semibold rounded-lg whitespace-nowrap">
                <?= htmlspecialchars(t('portal.wallet_apple_cta')) ?>
            </a>
        </div>
        <?php endif; ?>

        <div class="mt-3 bg-white rounded-2xl shadow-sm border border-gray-100 p-4 flex items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                <i class="fa-solid fa-file-pdf text-2xl text-red-500"></i>
                <div>
                    <p class="text-sm font-semibold text-gray-900"><?= htmlspecialchars(t('portal.download_pdf_title')) ?></p>
                    <p class="text-xs text-gray-500"><?= htmlspecialchars(t('portal.download_pdf_hint')) ?></p>
                </div>
            </div>
            <a href="/card-pdf.php?i=<?= htmlspecialchars(urlencode($employee['id'])) ?>"
               download
               class="px-4 py-2 bg-[#009bc1] hover:bg-[#007a99] text-white text-xs font-semibold rounded-lg whitespace-nowrap">
                <?= htmlspecialchars(t('portal.download_pdf_cta')) ?>
            </a>
        </div>

        <p class="text-xs text-gray-400 text-center mt-5">
            <i class="fa-solid fa-shield-halved mr-1"></i>
            <?= $isAr
                ? 'التعديلات تُحفَظ تلقائياً. لا حاجة لتسجيل الدخول.'
                : 'Edits are saved automatically. No sign-in needed.' ?>
        </p>
    </div>

    <script>
    function employeeEdit(init) {
        return {
            data: Object.assign({}, init.employee),
            socials: Array.isArray(init.socials) ? init.socials : [],
            token: init.token,
            csrf: init.csrf,
            saveUrl: init.saveUrl,
            locale: init.locale,
            savingState: 'idle',
            labels: { my_card: <?= json_encode(t('portal.my_card')) ?> },
            platforms: [
                { key: 'linkedin',  label: 'LinkedIn'  },
                { key: 'instagram', label: 'Instagram' },
                { key: 'twitter',   label: 'Twitter / X' },
                { key: 'tiktok',    label: 'TikTok'    },
                { key: 'youtube',   label: 'YouTube'   },
                { key: 'facebook',  label: 'Facebook'  },
                { key: 'snapchat',  label: 'Snapchat'  },
                { key: 'whatsapp',  label: 'WhatsApp'  },
                { key: 'telegram',  label: 'Telegram'  },
                { key: 'github',    label: 'GitHub'    },
                { key: 'other',     label: 'Other'     },
            ],

            init() { /* noop */ },

            addSocial() {
                this.socials.push({ platform: 'linkedin', url: '' });
            },
            removeSocial(idx) {
                this.socials.splice(idx, 1);
                this.save();
            },
            photoDragActive: false,
            photoStatus: '',
            handlePhotoDrop(ev) {
                this.photoDragActive = false;
                const f = ev.dataTransfer && ev.dataTransfer.files && ev.dataTransfer.files[0];
                if (f) this.uploadPhoto(f);
            },
            handlePhotoInput(ev) {
                const f = ev.target && ev.target.files && ev.target.files[0];
                if (f) this.uploadPhoto(f);
            },
            async uploadPhoto(f) {
                if (!/^image\/(png|jpe?g|webp)$/.test(f.type)) {
                    this.photoStatus = <?= json_encode(t('portal.photo_err_type')) ?>;
                    return;
                }
                if (f.size > 5 * 1024 * 1024) {
                    this.photoStatus = <?= json_encode(t('portal.photo_err_size')) ?>;
                    return;
                }
                this.photoStatus = <?= json_encode(t('portal.photo_uploading')) ?>;
                const fd = new FormData();
                fd.append('token', this.token);
                fd.append('csrf_token', this.csrf);
                fd.append('photo', f);
                try {
                    const res = await fetch('/portal/employee-photo-upload.php', {
                        method: 'POST',
                        headers: {'X-CSRF-Token': this.csrf},
                        body: fd,
                    });
                    const j = await res.json();
                    if (j.ok && j.photo) {
                        this.data.photo = j.photo + '?v=' + Date.now();
                        this.photoStatus = <?= json_encode(t('portal.photo_uploaded')) ?>;
                    } else {
                        this.photoStatus = <?= json_encode(t('portal.photo_err_generic')) ?>;
                    }
                } catch (e) {
                    this.photoStatus = <?= json_encode(t('portal.photo_err_generic')) ?>;
                }
            },

            statusText() {
                if (this.savingState === 'saving') return <?= json_encode(t('portal.saved_toast')) ?>.replace(/./, '') || '...';
                if (this.savingState === 'saved')  return <?= json_encode(t('portal.saved_toast')) ?>;
                if (this.savingState === 'error')  return 'Error';
                return '';
            },

            async save() {
                this.savingState = 'saving';
                try {
                    const res = await fetch(this.saveUrl, {
                        method: 'POST',
                        headers: {'Content-Type':'application/json','X-CSRF-Token': this.csrf},
                        body: JSON.stringify({
                            token: this.token,
                            fields: this.data,
                            socials: this.socials.filter(s => s && s.url && s.url.trim() !== ''),
                        })
                    });
                    const j = await res.json();
                    this.savingState = j.ok ? 'saved' : 'error';
                    if (j.ok) {
                        clearTimeout(this._clr);
                        this._clr = setTimeout(() => this.savingState = 'idle', 2000);
                    }
                } catch (e) {
                    this.savingState = 'error';
                }
            }
        };
    }
    </script>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
</body>
</html>
