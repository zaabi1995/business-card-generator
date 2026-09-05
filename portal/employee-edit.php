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
require_once INCLUDES_DIR . '/QRTracker.php';

// Accept both `token` (legacy /portal/employee-edit.php?token=) and `t`
// (pretty magic-link /{employee_slug}/edit?t=, rewritten here by .htaccess).
$token = trim($_GET['token'] ?? ($_GET['t'] ?? ''));
$employee = EmployeeEditToken::verify($token);

$existingSocials = [];
$publicCardUrl = '';
$departments = [];
$currentDepartmentName = '';
$pendingDeptRequest = null;
$scanStats = ['periodScans' => 0, 'totalScans' => 0];
$customFieldDefs = [];
$customFieldValues = [];
$pendingReprint = false;
$pendingLeave   = false;
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

        $company = $db->fetchOne("SELECT slug FROM companies WHERE id = :id", ['id' => $employee['company_id']]);
        $companySlug = $company['slug'] ?? '';
        // Build from the real employee row (id + email), never the name slug,
        // which matches neither the id nor the localpart and 404s to the portal.
        if (!class_exists('CardifyConvention')) {
            require_once INCLUDES_DIR . '/CardifyConvention.php';
        }
        if ($companySlug) {
            $publicCardUrl = CardifyConvention::employeeShareUrl($companySlug, $employee);
        }

        $departments = $db->fetchAll(
            "SELECT id, name FROM departments WHERE company_id = :cid ORDER BY name ASC",
            ['cid' => $employee['company_id']]
        );
        if (!empty($employee['department_id'])) {
            foreach ($departments as $d) {
                if ($d['id'] === $employee['department_id']) { $currentDepartmentName = $d['name']; break; }
            }
        }
        $pending = $db->fetchOne(
            "SELECT id, requested_value, requested_label FROM employee_edit_requests
             WHERE employee_id = :eid AND field = 'department' AND status = 'pending'
             ORDER BY created_at DESC LIMIT 1",
            ['eid' => $employee['id']]
        );
        if ($pending) {
            $pendingDeptRequest = [
                'id'    => $pending['id'],
                'value' => $pending['requested_value'],
                'label' => $pending['requested_label'],
            ];
        }

        try {
            $stats = QRTracker::getEmployeeStats($employee['id'], 30);
            if (is_array($stats)) {
                $scanStats['periodScans'] = (int) ($stats['periodScans'] ?? 0);
                $scanStats['totalScans']  = (int) ($stats['totalScans']  ?? 0);
            }
        } catch (Throwable $e) { /* stats optional */ }

        try {
            $cRow = $db->fetchOne("SELECT custom_fields FROM companies WHERE id = :id", ['id' => $employee['company_id']]);
            $defs = $cRow && !empty($cRow['custom_fields']) ? json_decode($cRow['custom_fields'], true) : [];
            if (is_array($defs)) {
                foreach ($defs as $d) {
                    if (empty($d['key']) || empty($d['label'])) continue;
                    $customFieldDefs[] = [
                        'key'      => (string) $d['key'],
                        'label'    => (string) $d['label'],
                        'label_ar' => (string) ($d['label_ar'] ?? $d['label']),
                        'type'     => in_array(($d['type'] ?? 'text'), ['text','email','url','tel'], true) ? $d['type'] : 'text',
                    ];
                }
            }
            $eVals = !empty($employee['custom_fields']) ? json_decode($employee['custom_fields'], true) : [];
            if (is_array($eVals)) $customFieldValues = $eVals;
        } catch (Throwable $e) { /* column may not exist in minimal envs */ }

        try {
            $row = $db->fetchOne(
                "SELECT id FROM employee_edit_requests
                 WHERE employee_id = :eid AND field = 'reprint' AND status = 'pending' LIMIT 1",
                ['eid' => $employee['id']]
            );
            $pendingReprint = !empty($row);
            $rowL = $db->fetchOne(
                "SELECT id FROM employee_edit_requests
                 WHERE employee_id = :eid AND field = 'leave_company' AND status = 'pending' LIMIT 1",
                ['eid' => $employee['id']]
            );
            $pendingLeave = !empty($rowL);
        } catch (Throwable $e) { /* table may not exist in minimal envs */ }
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
    <link rel="preconnect" href="https://fonts.bhd.om">
    <link rel="preconnect" href="https://fonts.bhd.om" crossorigin>
    <link href="https://fonts.bhd.om/css2?family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&display=swap" rel="stylesheet">
    <?php endif; ?>
    <style>
        body { font-family: <?= $isAr ? "'IBM Plex Sans Arabic'" : "system-ui,-apple-system,'Segoe UI'" ?>,sans-serif; background:#f5f7fb; }
        .form-input { width:100%; padding:0.625rem 0.875rem; border:1px solid #e2e8f0; border-radius:0.5rem; background:#fff; font-size:0.95rem; }
        .form-input:focus { outline:none; border-color:#00718c; box-shadow:0 0 0 3px rgba(0,155,193,0.15); }
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
              'preferred_contact_action' => $employee['preferred_contact_action'] ?? 'save_contact',
              // Without this the new layout select binds to undefined and
              // silently saves nothing.
              'card_page_layout' => $employee['card_page_layout'] ?? 'auto',
          ],
          'socials' => $existingSocials,
          'customFields' => (object) $customFieldValues,
          'deptPending' => $pendingDeptRequest,
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
            <?php if ($scanStats['periodScans'] > 0 || $scanStats['totalScans'] > 0): ?>
                <div class="inline-flex items-center gap-2 mt-3 px-3 py-1.5 rounded-full bg-[#00718c]/10 text-[#007a99] text-xs font-medium">
                    <i class="fa-solid fa-chart-line"></i>
                    <span><?= htmlspecialchars(t('portal.stats_month', ['n' => $scanStats['periodScans']])) ?></span>
                    <?php if ($scanStats['totalScans'] > $scanStats['periodScans']): ?>
                        <span class="text-gray-400">·</span>
                        <span class="text-gray-500"><?= htmlspecialchars(t('portal.stats_total', ['n' => $scanStats['totalScans']])) ?></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Live card preview -->
        <div class="rounded-2xl p-5 mb-5 text-white bg-gradient-to-br from-[#009bc1] to-[#824598]">
            <div class="text-xs uppercase tracking-widest opacity-75" x-text="labels.my_card"></div>
            <div class="text-xl font-bold mt-1" x-text="data.name_en || ','"></div>
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
                       :class="photoDragActive ? 'border-[#009bc1] bg-[#00718c]/5' : 'border-gray-200'"
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
            <!-- What leads the page. Was admin-only, so a person could upload a
                 photo but not decide whether their page showed it or the printed
                 card. The printed card is always still reachable behind a small
                 reveal, so choosing the photo never hides it. -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1"><?= htmlspecialchars(t('portal.layout_label')) ?></label>
                <select x-model="data.card_page_layout" @change="save()" class="form-input"
                        aria-label="<?= htmlspecialchars(t('portal.layout_label')) ?>">
                    <option value="auto"><?= htmlspecialchars(t('portal.layout_auto')) ?></option>
                    <option value="photo"><?= htmlspecialchars(t('portal.layout_photo')) ?></option>
                    <option value="card"><?= htmlspecialchars(t('portal.layout_card')) ?></option>
                </select>
                <p class="mt-1 text-xs text-gray-400"><?= htmlspecialchars(t('portal.layout_hint')) ?></p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1"><?= htmlspecialchars(t('portal.first_name')) ?></label>
                <input type="text" x-model="data.name_en" @input.debounce.800ms="save()" class="form-input" aria-label="<?= htmlspecialchars(t('portal.first_name')) ?>">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1"><?= htmlspecialchars(t('portal.job_title')) ?></label>
                <input type="text" x-model="data.position_en" @input.debounce.800ms="save()" class="form-input" aria-label="<?= htmlspecialchars(t('portal.job_title')) ?>">
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1"><?= htmlspecialchars(t('common.phone')) ?></label>
                    <input type="tel" x-model="data.phone" @input.debounce.800ms="save()" class="form-input" dir="ltr" aria-label="<?= htmlspecialchars(t('common.phone')) ?>">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1"><?= htmlspecialchars(t('portal.mobile')) ?></label>
                    <input type="tel" x-model="data.mobile" @input.debounce.800ms="save()" class="form-input" dir="ltr" aria-label="<?= htmlspecialchars(t('portal.mobile')) ?>">
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1"><?= htmlspecialchars(t('common.email')) ?></label>
                <input type="email" x-model="data.email" @input.debounce.800ms="save()" class="form-input" dir="ltr" aria-label="<?= htmlspecialchars(t('common.email')) ?>">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1"><?= htmlspecialchars(t('portal.website')) ?></label>
                <input type="url" x-model="data.website" @input.debounce.800ms="save()" class="form-input" dir="ltr" placeholder="https://">
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1"><?= htmlspecialchars(t('portal.primary_tap')) ?></label>
                <select x-model="data.preferred_contact_action" @change="save()" class="form-input" dir="<?= htmlspecialchars($dir) ?>" aria-label="<?= htmlspecialchars(t('portal.primary_tap')) ?>">
                    <option value="save_contact"><?= htmlspecialchars(t('portal.primary_tap_save')) ?></option>
                    <option value="whatsapp"><?= htmlspecialchars(t('portal.primary_tap_whatsapp')) ?></option>
                    <option value="call"><?= htmlspecialchars(t('portal.primary_tap_call')) ?></option>
                </select>
                <p class="text-xs text-gray-500 mt-1"><?= htmlspecialchars(t('portal.primary_tap_hint')) ?></p>
            </div>

            <?php if (!empty($customFieldDefs)): ?>
            <div class="pt-4 border-t border-gray-100 space-y-3">
                <label class="block text-sm font-medium text-gray-700"><?= htmlspecialchars(t('portal.custom_fields')) ?></label>
                <?php foreach ($customFieldDefs as $def): $fk = $def['key']; $flabel = $isAr ? $def['label_ar'] : $def['label']; ?>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1"><?= htmlspecialchars($flabel) ?></label>
                        <input type="<?= htmlspecialchars($def['type']) ?>"
                               x-model="customFields[<?= htmlspecialchars(json_encode($fk), ENT_QUOTES) ?>]"
                               @input.debounce.800ms="save()"
                               class="form-input"
                               <?= in_array($def['type'], ['email','url','tel'], true) ? 'dir="ltr"' : '' ?>>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($departments)): ?>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1"><?= htmlspecialchars(t('portal.department')) ?></label>
                <div class="flex items-center gap-2">
                    <select x-model="deptRequestValue" class="form-input flex-1" dir="<?= htmlspecialchars($dir) ?>" aria-label="<?= htmlspecialchars(t('portal.department')) ?>">
                        <option value=""><?= htmlspecialchars(t('portal.department_current', ['name' => $currentDepartmentName ?: t('portal.department_none')])) ?></option>
                        <?php foreach ($departments as $d): if ($d['id'] === ($employee['department_id'] ?? null)) continue; ?>
                            <option value="<?= htmlspecialchars($d['id']) ?>"><?= htmlspecialchars($d['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button"
                            :disabled="!deptRequestValue || deptPending"
                            @click="requestDepartment()"
                            class="px-4 py-2 bg-amber-500 hover:bg-amber-600 disabled:bg-gray-200 disabled:text-gray-400 text-white text-xs font-semibold rounded-lg whitespace-nowrap">
                        <?= htmlspecialchars(t('portal.request_change')) ?>
                    </button>
                </div>
                <p class="text-xs text-gray-500 mt-1" x-show="!deptPendingLabel">
                    <?= htmlspecialchars(t('portal.request_change_hint')) ?>
                </p>
                <p class="text-xs text-amber-700 mt-1" x-show="deptPendingLabel" x-cloak>
                    <i class="fa-solid fa-hourglass-half mr-1"></i>
                    <span x-text="<?= htmlspecialchars(json_encode(t('portal.request_pending_label', ['label' => ':pending'])), ENT_QUOTES) ?>.replace(':pending', deptPendingLabel)"></span>
                </p>
            </div>
            <?php endif; ?>

            <div class="pt-4 border-t border-gray-100">
                <div class="flex items-center justify-between mb-2">
                    <label class="text-sm font-medium text-gray-700"><?= htmlspecialchars(t('portal.social_links')) ?></label>
                    <button type="button" @click="addSocial()" class="text-xs font-semibold text-[#00718c] hover:text-[#007a99]">
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

        <div class="mt-3 bg-white rounded-2xl shadow-sm border border-gray-100 p-4"
             x-data='reprintCard(<?= htmlspecialchars(json_encode([
                 'token' => $token,
                 'csrf'  => $csrf,
                 'pending' => $pendingReprint,
             ]), ENT_QUOTES) ?>)'>
            <div class="flex items-start justify-between gap-3">
                <div class="flex items-start gap-3">
                    <i class="fa-solid fa-print text-2xl text-orange-500 mt-0.5"></i>
                    <div>
                        <p class="text-sm font-semibold text-gray-900"><?= htmlspecialchars(t('portal.reprint_title')) ?></p>
                        <p class="text-xs text-gray-500"><?= htmlspecialchars(t('portal.reprint_hint')) ?></p>
                    </div>
                </div>
                <button type="button" @click="toggle()"
                        :disabled="pending"
                        class="px-4 py-2 bg-orange-500 hover:bg-orange-600 disabled:bg-gray-200 disabled:text-gray-400 text-white text-xs font-semibold rounded-lg whitespace-nowrap"
                        x-text="pending ? <?= htmlspecialchars(json_encode(t('portal.reprint_pending'), JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?> : (open ? <?= htmlspecialchars(json_encode(t('portal.reprint_cancel'), JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?> : <?= htmlspecialchars(json_encode(t('portal.reprint_cta'), JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>)"></button>
            </div>
            <div x-show="open && !pending" x-cloak class="mt-3 pt-3 border-t border-gray-100">
                <label class="block text-xs text-gray-600 mb-1"><?= htmlspecialchars(t('portal.reprint_note_label')) ?></label>
                <textarea x-model="note" rows="2" maxlength="255" class="form-input text-sm"
                          :placeholder="<?= htmlspecialchars(json_encode(t('portal.reprint_note_ph')), ENT_QUOTES) ?>"></textarea>
                <button type="button" @click="submit()"
                        class="mt-2 px-4 py-2 bg-[#00718c] hover:bg-[#005b73] text-white text-xs font-semibold rounded-lg">
                    <?= htmlspecialchars(t('portal.reprint_submit')) ?>
                </button>
            </div>
        </div>

        <?php if ($publicCardUrl): ?>
        <div class="mt-5 bg-white rounded-2xl shadow-sm border border-gray-100 p-4"
             x-data="{ open: false, copied: false,
                       copyUrl() { navigator.clipboard.writeText(<?= htmlspecialchars(json_encode($publicCardUrl), ENT_QUOTES) ?>).then(() => { this.copied = true; setTimeout(() => this.copied = false, 1500); }); } }">
            <div class="flex items-center justify-between gap-3">
                <div class="flex items-center gap-3">
                    <i class="fa-solid fa-wifi rotate-90 text-2xl text-purple-500"></i>
                    <div>
                        <p class="text-sm font-semibold text-gray-900"><?= htmlspecialchars(t('portal.nfc_title')) ?></p>
                        <p class="text-xs text-gray-500"><?= htmlspecialchars(t('portal.nfc_hint')) ?></p>
                    </div>
                </div>
                <button type="button" @click="open = !open"
                        class="px-4 py-2 bg-purple-500 hover:bg-purple-600 text-white text-xs font-semibold rounded-lg whitespace-nowrap">
                    <span x-text="open ? <?= htmlspecialchars(json_encode(t('portal.nfc_hide'), JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?> : <?= htmlspecialchars(json_encode(t('portal.nfc_cta'), JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>"></span>
                </button>
            </div>
            <div x-show="open" x-cloak class="mt-4 pt-4 border-t border-gray-100 space-y-3">
                <div class="flex flex-col items-center gap-2">
                    <img src="https://api.qrserver.com/v1/create-qr-code/?size=240x240&margin=2&data=<?= htmlspecialchars(urlencode($publicCardUrl)) ?>"
                         alt="NFC QR"
                         class="w-60 h-60 rounded-lg bg-white border border-gray-200 p-2">
                    <code class="text-xs text-gray-700 break-all text-center" dir="ltr"><?= htmlspecialchars($publicCardUrl) ?></code>
                    <button type="button" @click="copyUrl()"
                            class="text-xs font-semibold text-[#00718c] hover:text-[#007a99]">
                        <span x-text="copied ? <?= htmlspecialchars(json_encode(t('onboarding.copied'), JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?> : <?= htmlspecialchars(json_encode(t('portal.nfc_copy_url'), JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>"></span>
                    </button>
                </div>
                <ol class="text-xs text-gray-600 list-decimal ps-5 space-y-1">
                    <li><?= htmlspecialchars(t('portal.nfc_step1')) ?></li>
                    <li><?= htmlspecialchars(t('portal.nfc_step2')) ?></li>
                    <li><?= htmlspecialchars(t('portal.nfc_step3')) ?></li>
                </ol>
            </div>
        </div>
        <?php endif; ?>

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
               class="px-4 py-2 bg-[#00718c] hover:bg-[#005b73] text-white text-xs font-semibold rounded-lg whitespace-nowrap">
                <?= htmlspecialchars(t('portal.download_pdf_cta')) ?>
            </a>
        </div>

        <?php if ($publicCardUrl): ?>
        <div class="mt-3 bg-white rounded-2xl shadow-sm border border-gray-100 p-4"
             x-data="shareCard(<?= htmlspecialchars(json_encode([
                 'url'   => $publicCardUrl,
                 'name'  => $employee['name_en'] ?? '',
                 'text'  => t('portal.share_default_text', ['name' => $employee['name_en'] ?? '']),
             ]), ENT_QUOTES) ?>)">
            <div class="flex items-center justify-between gap-3">
                <div class="flex items-center gap-3">
                    <i class="fa-solid fa-share-nodes text-2xl text-[#00718c]"></i>
                    <div>
                        <p class="text-sm font-semibold text-gray-900"><?= htmlspecialchars(t('portal.share_title')) ?></p>
                        <p class="text-xs text-gray-500"><?= htmlspecialchars(t('portal.share_hint')) ?></p>
                    </div>
                </div>
                <button type="button" @click="share()"
                        class="px-4 py-2 bg-[#00718c] hover:bg-[#005b73] text-white text-xs font-semibold rounded-lg whitespace-nowrap">
                    <?= htmlspecialchars(t('portal.share_cta')) ?>
                </button>
            </div>
            <div x-show="showFallback" x-cloak class="mt-3 grid grid-cols-4 gap-2 text-center text-xs">
                <a :href="waUrl" target="_blank" rel="noopener" class="py-2 rounded-lg border border-gray-200 hover:border-green-500 text-gray-700">
                    <i class="fa-brands fa-whatsapp text-green-500 text-lg block"></i>WhatsApp
                </a>
                <a :href="smsUrl" class="py-2 rounded-lg border border-gray-200 hover:border-blue-500 text-gray-700">
                    <i class="fa-solid fa-message text-blue-500 text-lg block"></i>SMS
                </a>
                <a :href="emailUrl" class="py-2 rounded-lg border border-gray-200 hover:border-purple-500 text-gray-700">
                    <i class="fa-solid fa-envelope text-purple-500 text-lg block"></i>Email
                </a>
                <button type="button" @click="copyLink()" class="py-2 rounded-lg border border-gray-200 hover:border-gray-500 text-gray-700">
                    <i class="fa-solid fa-copy text-gray-600 text-lg block"></i>
                    <span x-text="copied ? <?= htmlspecialchars(json_encode(t('onboarding.copied'), JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?> : <?= htmlspecialchars(json_encode(t('onboarding.copy'), JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>"></span>
                </button>
            </div>
        </div>
        <?php endif; ?>

        <div class="mt-5 bg-white rounded-2xl shadow-sm border border-red-100 p-4"
             x-data='leaveCard(<?= htmlspecialchars(json_encode([
                 'token' => $token,
                 'csrf'  => $csrf,
                 'pending' => $pendingLeave,
             ]), ENT_QUOTES) ?>)'>
            <div class="flex items-start justify-between gap-3">
                <div class="flex items-start gap-3">
                    <i class="fa-solid fa-door-open text-2xl text-red-500 mt-0.5"></i>
                    <div>
                        <p class="text-sm font-semibold text-gray-900"><?= htmlspecialchars(t('portal.leave_title')) ?></p>
                        <p class="text-xs text-gray-500"><?= htmlspecialchars(t('portal.leave_hint')) ?></p>
                    </div>
                </div>
                <button type="button" @click="toggle()" :disabled="pending"
                        class="px-4 py-2 bg-red-500 hover:bg-red-600 disabled:bg-gray-200 disabled:text-gray-400 text-white text-xs font-semibold rounded-lg whitespace-nowrap"
                        x-text="pending ? <?= htmlspecialchars(json_encode(t('portal.leave_pending'), JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?> : (open ? <?= htmlspecialchars(json_encode(t('portal.leave_cancel'), JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?> : <?= htmlspecialchars(json_encode(t('portal.leave_cta'), JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>)"></button>
            </div>
            <div x-show="open && !pending" x-cloak class="mt-3 pt-3 border-t border-red-100">
                <label class="block text-xs text-gray-600 mb-1"><?= htmlspecialchars(t('portal.leave_note_label')) ?></label>
                <textarea x-model="note" rows="2" maxlength="255" class="form-input text-sm"
                          :placeholder="<?= htmlspecialchars(json_encode(t('portal.leave_note_ph')), ENT_QUOTES) ?>"></textarea>
                <p class="text-xs text-red-600 mt-2"><i class="fa-solid fa-triangle-exclamation mr-1"></i><?= htmlspecialchars(t('portal.leave_warning')) ?></p>
                <button type="button" @click="submit()"
                        class="mt-2 px-4 py-2 bg-red-600 hover:bg-red-700 text-white text-xs font-semibold rounded-lg">
                    <?= htmlspecialchars(t('portal.leave_submit')) ?>
                </button>
            </div>
        </div>

        <p class="text-xs text-gray-400 text-center mt-5">
            <i class="fa-solid fa-shield-halved mr-1"></i>
            <?= $isAr
                ? 'التعديلات تُحفَظ تلقائياً. لا حاجة لتسجيل الدخول.'
                : 'Edits are saved automatically. No sign-in needed.' ?>
        </p>
    </div>

    <script<?= cspNonceAttr() ?>>
    function leaveCard(init) {
        return {
            open: false,
            note: '',
            pending: !!init.pending,
            token: init.token,
            csrf: init.csrf,
            toggle() { if (!this.pending) this.open = !this.open; },
            async submit() {
                if (!confirm(<?= json_encode(t('portal.leave_confirm')) ?>)) return;
                try {
                    const res = await fetch('/portal/employee-edit-request.php', {
                        method: 'POST',
                        headers: {'Content-Type':'application/json','X-CSRF-Token': this.csrf},
                        body: JSON.stringify({ token: this.token, field: 'leave_company', note: this.note })
                    });
                    const j = await res.json();
                    if (j.ok) {
                        this.pending = true;
                        this.open = false;
                        this.note = '';
                    } else {
                        alert(<?= json_encode(t('portal.request_failed')) ?>);
                    }
                } catch (e) {
                    alert(<?= json_encode(t('portal.request_failed')) ?>);
                }
            }
        };
    }
    function reprintCard(init) {
        return {
            open: false,
            note: '',
            pending: !!init.pending,
            token: init.token,
            csrf: init.csrf,
            toggle() { if (!this.pending) this.open = !this.open; },
            async submit() {
                try {
                    const res = await fetch('/portal/employee-edit-request.php', {
                        method: 'POST',
                        headers: {'Content-Type':'application/json','X-CSRF-Token': this.csrf},
                        body: JSON.stringify({ token: this.token, field: 'reprint', note: this.note })
                    });
                    const j = await res.json();
                    if (j.ok) {
                        this.pending = true;
                        this.open = false;
                        this.note = '';
                    } else {
                        alert(<?= json_encode(t('portal.request_failed')) ?>);
                    }
                } catch (e) {
                    alert(<?= json_encode(t('portal.request_failed')) ?>);
                }
            }
        };
    }
    function shareCard(init) {
        const u = encodeURIComponent(init.url);
        const t = encodeURIComponent((init.text || '') + '\n' + init.url);
        return {
            copied: false,
            showFallback: false,
            waUrl: 'https://api.whatsapp.com/send?text=' + t,
            smsUrl: 'sms:?body=' + t,
            emailUrl: 'mailto:?subject=' + encodeURIComponent(init.name || 'My card') + '&body=' + t,
            async share() {
                if (navigator.share) {
                    try {
                        await navigator.share({
                            title: init.name || 'Cardify',
                            text:  init.text || '',
                            url:   init.url,
                        });
                        return;
                    } catch (e) { /* user cancelled or unavailable, fall through */ }
                }
                this.showFallback = true;
            },
            copyLink() {
                navigator.clipboard.writeText(init.url).then(() => {
                    this.copied = true;
                    setTimeout(() => this.copied = false, 1500);
                });
            }
        };
    }
    function employeeEdit(init) {
        return {
            data: Object.assign({}, init.employee),
            socials: Array.isArray(init.socials) ? init.socials : [],
            customFields: init.customFields && typeof init.customFields === 'object' ? init.customFields : {},
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

            deptRequestValue: '',
            deptPending: init.deptPending || false,
            get deptPendingLabel() {
                return this.deptPending && this.deptPending.label ? this.deptPending.label : '';
            },
            async requestDepartment() {
                if (!this.deptRequestValue) return;
                try {
                    const res = await fetch('/portal/employee-edit-request.php', {
                        method: 'POST',
                        headers: {'Content-Type':'application/json','X-CSRF-Token': this.csrf},
                        body: JSON.stringify({ token: this.token, field: 'department', value: this.deptRequestValue })
                    });
                    const j = await res.json();
                    if (j.ok && j.request) {
                        this.deptPending = { id: j.request.id, value: j.request.value || this.deptRequestValue, label: j.request.label || '' };
                        this.deptRequestValue = '';
                    } else {
                        alert(<?= json_encode(t('portal.request_failed')) ?>);
                    }
                } catch (e) {
                    alert(<?= json_encode(t('portal.request_failed')) ?>);
                }
            },
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
                            custom_fields: this.customFields,
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

    <link rel="stylesheet" href="https://design.bhd.om/fa/v7.2.0/css/fontawesome.min.css">
    <link rel="stylesheet" href="https://design.bhd.om/fa/v7.2.0/css/solid.min.css">
    <link rel="stylesheet" href="https://design.bhd.om/fa/v7.2.0/css/brands.min.css">
</body>
</html>
