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

$token = trim($_GET['token'] ?? '');
$employee = EmployeeEditToken::verify($token);

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
          ],
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
            token: init.token,
            csrf: init.csrf,
            saveUrl: init.saveUrl,
            locale: init.locale,
            savingState: 'idle',
            labels: { my_card: <?= json_encode(t('portal.my_card')) ?> },

            init() { /* noop */ },

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
                        body: JSON.stringify({ token: this.token, fields: this.data })
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
