<?php
/**
 * Company Theme Management - Cardify
 */
require_once __DIR__ . '/../config.php';
requireAdmin();
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/admin-layout.php';
require_once INCLUDES_DIR . '/WalletThemePolicy.php';

$db = Database::getInstance();
$companyId = getCurrentCompanyId();

if (!$companyId) {
    header('Location: ' . getBasePath() . 'login.php');
    exit;
}

$message = null;
$messageType = 'success';

// Get company data for portal settings
$company = findCompanyById($companyId);
$companySlug = $company['slug'] ?? '';

// Get or create company theme
$theme = $db->fetchOne(
    "SELECT * FROM company_themes WHERE company_id = :id",
    ['id' => $companyId]
);

if (!$theme) {
    $themeId = generateUUID();
    $db->insert('company_themes', [
        'id' => $themeId,
        'company_id' => $companyId,
        'primary_color' => '#009bc1',
        'secondary_color' => '#0f3460'
    ]);
    $theme = $db->fetchOne("SELECT * FROM company_themes WHERE id = :id", ['id' => $themeId]);
}

$walletAction = (string)($_POST['wallet_action'] ?? '');
$isWalletAction = $_SERVER['REQUEST_METHOD'] === 'POST' && $walletAction !== '';
if ($isWalletAction) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        die('Invalid request');
    }
    try {
        $walletThemeId = trim((string)($_POST['wallet_theme_id'] ?? ''));
        if ($walletAction === 'save') {
            $styles = ['eventTicket', 'storeCard', 'generic'];
            $style = (string)($_POST['wallet_style'] ?? 'eventTicket');
            if (!in_array($style, $styles, true)) {
                throw new InvalidArgumentException('wallet_theme_invalid');
            }
            $previewPath = trim((string)($_POST['wallet_preview_path'] ?? ''));
            if (strpos($previewPath, '..') !== false) {
                throw new InvalidArgumentException('wallet_theme_invalid');
            }
            if ($previewPath !== '') {
                $allowedPrefix = '/uploads/companies/' . $companyId . '/';
                if (strpos('/' . ltrim($previewPath, '/'), $allowedPrefix) !== 0) {
                    throw new InvalidArgumentException('wallet_theme_invalid');
                }
                $previewPath = '/' . ltrim($previewPath, '/');
            }
            $validated = WalletThemePolicy::validateTheme([
                'style' => $style,
                'background_color' => (string)($_POST['wallet_background_color'] ?? ''),
                'foreground_color' => (string)($_POST['wallet_foreground_color'] ?? ''),
                'label_color' => (string)($_POST['wallet_label_color'] ?? ''),
                'logo_mode' => (string)($_POST['wallet_logo_mode'] ?? 'company'),
            ]);
            $walletData = [
                'name_en' => mb_substr(trim((string)($_POST['wallet_name_en'] ?? 'Wallet theme')), 0, 120),
                'name_ar' => mb_substr(trim((string)($_POST['wallet_name_ar'] ?? '')), 0, 120),
                'style' => $validated['style'],
                'background_color' => $validated['background_color'],
                'foreground_color' => $validated['foreground_color'],
                'label_color' => $validated['label_color'],
                'logo_mode' => $validated['logo_mode'],
                'preview_path' => $previewPath !== '' ? $previewPath : null,
                'is_default' => isset($_POST['wallet_is_default']) ? 1 : 0,
                'is_active' => isset($_POST['wallet_is_active']) ? 1 : 0,
                'sort_order' => max(0, min(9999, (int)($_POST['wallet_sort_order'] ?? 0))),
            ];
            if ($walletData['is_default'] === 1) {
                $walletData['is_active'] = 1;
            }
            if ($walletData['name_en'] === '') {
                throw new InvalidArgumentException('wallet_theme_invalid');
            }
            $db->beginTransaction();
            try {
                if ($walletData['is_default'] === 1) {
                    $db->query(
                        'UPDATE wallet_themes SET is_default = 0 WHERE company_id = :company_id',
                        ['company_id' => $companyId]
                    );
                }
                if ($walletThemeId !== '') {
                    $owned = $db->fetchOne(
                        'SELECT id FROM wallet_themes
                          WHERE id = :id AND company_id = :company_id
                          FOR UPDATE',
                        ['id' => $walletThemeId, 'company_id' => $companyId]
                    );
                    if (!is_array($owned)) {
                        throw new InvalidArgumentException('wallet_theme_not_found');
                    }
                    $db->update(
                        'wallet_themes',
                        $walletData,
                        'id = :id AND company_id = :company_id',
                        ['id' => $walletThemeId, 'company_id' => $companyId]
                    );
                } else {
                    $walletThemeId = generateUUID();
                    $db->insert('wallet_themes', array_merge($walletData, [
                        'id' => $walletThemeId,
                        'company_id' => $companyId,
                    ]));
                }
                $db->commit();
            } catch (Throwable $error) {
                $db->rollback();
                throw $error;
            }
            $message = 'Apple Wallet theme saved.';
        } elseif (in_array($walletAction, ['publish', 'unpublish', 'archive'], true)) {
            $owned = $db->fetchOne(
                'SELECT id FROM wallet_themes
                  WHERE id = :id AND company_id = :company_id',
                ['id' => $walletThemeId, 'company_id' => $companyId]
            );
            if (!is_array($owned)) {
                throw new InvalidArgumentException('wallet_theme_not_found');
            }
            $active = $walletAction === 'publish' ? 1 : 0;
            $db->update(
                'wallet_themes',
                ['is_active' => $active, 'is_default' => 0],
                'id = :id AND company_id = :company_id',
                ['id' => $walletThemeId, 'company_id' => $companyId]
            );
            $message = $walletAction === 'publish'
                ? 'Apple Wallet theme published.'
                : 'Apple Wallet theme hidden.';
        } else {
            throw new InvalidArgumentException('wallet_theme_invalid');
        }
    } catch (Throwable $error) {
        $message = 'The Apple Wallet theme could not be saved. Check its colors and settings.';
        $messageType = 'error';
        error_log('[admin/theme] wallet theme: ' . $error->getMessage());
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$isWalletAction) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { die('Invalid request'); }
    $primaryColor = $_POST['primary_color'] ?? '#009bc1';
    $secondaryColor = $_POST['secondary_color'] ?? '#0f3460';
    $headerText = $_POST['header_text'] ?? '';
    $footerText = $_POST['footer_text'] ?? '';
    $customCss = $_POST['custom_css'] ?? '';
    
    // Handle logo upload
    $logoPath = $theme['logo_path'] ?? null;
    if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = getCompanyUploadsPath($companyId) . '/theme/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        
        $ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'svg'])) {
            $filename = 'logo_' . time() . '.' . $ext;
            if (move_uploaded_file($_FILES['logo']['tmp_name'], $uploadDir . $filename)) {
                $logoPath = 'companies/' . $companyId . '/theme/' . $filename;
            }
        }
    }
    
    // Handle favicon upload
    $faviconPath = $theme['favicon_path'] ?? null;
    if (isset($_FILES['favicon']) && $_FILES['favicon']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = getCompanyUploadsPath($companyId) . '/theme/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        
        $ext = strtolower(pathinfo($_FILES['favicon']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['ico', 'png', 'jpg', 'jpeg'])) {
            $filename = 'favicon_' . time() . '.' . $ext;
            if (move_uploaded_file($_FILES['favicon']['tmp_name'], $uploadDir . $filename)) {
                $faviconPath = 'companies/' . $companyId . '/theme/' . $filename;
            }
        }
    }
    
    $updateData = [
        'primary_color' => $primaryColor,
        'secondary_color' => $secondaryColor,
        'header_text' => $headerText,
        'footer_text' => $footerText,
        'custom_css' => $customCss
    ];
    if ($logoPath !== ($theme['logo_path'] ?? null)) $updateData['logo_path'] = $logoPath;
    if ($faviconPath !== ($theme['favicon_path'] ?? null)) $updateData['favicon_path'] = $faviconPath;
    
    $db->update('company_themes', $updateData, 'company_id = :id', ['id' => $companyId]);

    // Brand metadata (color/logo/favicon/header/footer/custom-css) just changed.
    // Every employee's cached card PNG is now stale relative to the canonical
    // template + theme source. Clear the cache so digital_card.php, card-pdf.php,
    // wallet passes, and the print-shop preview all pick up the new brand
    // on next view.
    require_once INCLUDES_DIR . '/CardRenderer.php';
    try { CardRenderer::invalidateForCompany((string)$companyId, 'theme-update'); }
    catch (Throwable $e) { error_log('theme.php invalidate: ' . $e->getMessage()); }

    // Update portal + E-Card + bilingual settings in companies table
    $portalEnabled     = isset($_POST['portal_enabled']) ? 1 : 0;
    $portalShowPreview = isset($_POST['portal_show_preview']) ? 1 : 0;
    $portalPasscode    = trim($_POST['portal_passcode'] ?? '');
    $companyNameEn     = trim($_POST['company_name_en'] ?? $company['name']);
    $companyNameAr     = trim($_POST['company_name_ar'] ?? '');
    $sloganEn          = trim($_POST['slogan_en'] ?? '');
    $sloganAr          = trim($_POST['slogan_ar'] ?? '');
    $ecardBilingual        = isset($_POST['ecard_bilingual'])        ? 1 : 0;
    $ecardThemeToggle      = isset($_POST['ecard_theme_toggle'])     ? 1 : 0;
    // "Made with Cardify" footer is always on (viral growth). Not a setting.
    $ecardShowViralFooter  = 1;
    $ecardDefaultTheme     = in_array($_POST['ecard_default_theme'] ?? 'auto', ['auto','light','dark'], true) ? $_POST['ecard_default_theme'] : 'auto';

    $db->query(
        "UPDATE companies SET
            name = :n, name_ar = :nar,
            slogan_en = :sen, slogan_ar = :sar,
            portal_enabled = :enabled,
            portal_show_preview = :preview,
            portal_passcode = :passcode,
            ecard_bilingual = :eb,
            ecard_theme_toggle_enabled = :ett,
            ecard_show_viral_footer = :esvf,
            ecard_default_theme = :edt
         WHERE id = :id",
        [
            'n' => $companyNameEn ?: $company['name'],
            'nar' => $companyNameAr ?: null,
            'sen' => $sloganEn ?: null,
            'sar' => $sloganAr ?: null,
            'enabled' => $portalEnabled,
            'preview' => $portalShowPreview,
            'passcode' => $portalPasscode ?: null,
            'eb' => $ecardBilingual,
            'ett' => $ecardThemeToggle,
            'esvf' => $ecardShowViralFooter,
            'edt' => $ecardDefaultTheme,
            'id' => $companyId,
        ]
    );
    
    // Refresh company data
    $company = findCompanyById($companyId);
    
    $message = 'Settings saved successfully!';
    $theme = $db->fetchOne("SELECT * FROM company_themes WHERE company_id = :id", ['id' => $companyId]);
}

// Pick a real E-Card URL for the Preview button: first employee with an email
// (company-wide settings apply to every employee), falling back to the public
// portal if no employees exist yet.
$walletThemes = $db->fetchAll(
    'SELECT * FROM wallet_themes
      WHERE company_id = :company_id
      ORDER BY is_default DESC, sort_order ASC, updated_at DESC',
    ['company_id' => $companyId]
);

$__firstEmployee = $db->fetchOne(
    "SELECT id, email FROM employees WHERE company_id = :cid AND email <> '' ORDER BY created_at ASC LIMIT 1",
    ['cid' => $companyId]
);
$previewCardUrl = $__firstEmployee
    ? getTenantCardUrl($companySlug, 'card/' . urlencode($__firstEmployee['email']))
    : getTenantUrl($companySlug, '/portal');

adminHeader('Branding & E-Card Settings', 'theme');
?>

<!-- Alert Message -->
<?php if ($message): ?>
<div class="mb-6 p-4 rounded-xl flex items-center gap-3 <?= $messageType === 'error' ? 'bg-red-50 border border-red-200 text-red-700' : 'bg-green-50 border border-green-200 text-green-700' ?>">
    <i class="fa-solid <?= $messageType === 'error' ? 'fa-circle-exclamation' : 'fa-circle-check' ?>"></i>
    <?php echo sanitize($message); ?>
</div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" class="space-y-6">
    <?php echo csrfField(); ?>

    <!-- Company Identity (controls what the E-Card shows) -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <div class="p-4 border-b border-gray-100">
            <h3 class="font-semibold text-gray-900 flex items-center gap-2">
                <i class="fa-solid fa-building text-blue-600"></i>
                Company Identity
            </h3>
            <p class="text-xs text-gray-500 mt-1">Used on the E-Card, portal, vCard, and emails.</p>
        </div>
        <div class="p-6 grid md:grid-cols-2 gap-6">
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-2">Company Name (English)</label>
                <input type="text" name="company_name_en" value="<?php echo sanitize($company['name'] ?? ''); ?>"
                       class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-900">
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-2">Company Name (Arabic)</label>
                <input type="text" name="company_name_ar" value="<?php echo sanitize($company['name_ar'] ?? ''); ?>"
                       class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-900" dir="rtl">
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-2">Tagline (English)</label>
                <input type="text" name="slogan_en" value="<?php echo sanitize($company['slogan_en'] ?? ''); ?>"
                       placeholder="e.g. Your trusted home financing partner"
                       class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-900">
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-2">Tagline (Arabic)</label>
                <input type="text" name="slogan_ar" value="<?php echo sanitize($company['slogan_ar'] ?? ''); ?>"
                       placeholder="مثال: شريكك الموثوق في تمويل المنازل"
                       class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-900" dir="rtl">
            </div>
        </div>
    </div>

    <!-- E-Card Settings -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <div class="p-4 border-b border-gray-100 flex items-center justify-between">
            <div>
                <h3 class="font-semibold text-gray-900 flex items-center gap-2">
                    <i class="fa-solid fa-id-card text-blue-600"></i>
                    E-Card Settings
                </h3>
                <p class="text-xs text-gray-500 mt-1">Controls the digital card each employee shares via QR.</p>
            </div>
            <a href="<?php echo htmlspecialchars($previewCardUrl); ?>"
               target="_blank" rel="noopener"
               class="text-xs inline-flex items-center gap-1.5 px-3 py-1.5 bg-gray-100 hover:bg-gray-200 rounded-lg text-gray-700">
                <i class="fa-solid fa-up-right-from-square"></i> Preview live E-Card
            </a>
        </div>
        <div class="p-6 space-y-4">
            <label class="flex items-start gap-3 cursor-pointer">
                <input type="checkbox" name="ecard_bilingual" value="1"
                       <?php echo !isset($company['ecard_bilingual']) || $company['ecard_bilingual'] ? 'checked' : ''; ?>
                       class="mt-1 w-4 h-4 text-blue-600 rounded border-gray-300">
                <span>
                    <span class="block font-medium text-gray-900 text-sm">Bilingual card</span>
                    <span class="block text-xs text-gray-500">Show the EN / عربي language switcher on the E-Card. Turn off to serve a single language.</span>
                </span>
            </label>

            <label class="flex items-start gap-3 cursor-pointer">
                <input type="checkbox" name="ecard_theme_toggle" value="1"
                       <?php echo !isset($company['ecard_theme_toggle_enabled']) || $company['ecard_theme_toggle_enabled'] ? 'checked' : ''; ?>
                       class="mt-1 w-4 h-4 text-blue-600 rounded border-gray-300">
                <span>
                    <span class="block font-medium text-gray-900 text-sm">Visitor theme toggle (sun / moon)</span>
                    <span class="block text-xs text-gray-500">Let visitors switch the E-Card between light and dark themes.</span>
                </span>
            </label>

            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-2">Default theme</label>
                <?php $__defTheme = $company['ecard_default_theme'] ?? 'auto'; ?>
                <div class="flex gap-2">
                    <?php foreach (['auto' => 'Auto', 'light' => 'Light', 'dark' => 'Dark'] as $__v => $__l): ?>
                    <label class="flex-1 cursor-pointer">
                        <input type="radio" name="ecard_default_theme" value="<?= $__v ?>" class="peer sr-only" <?= $__defTheme === $__v ? 'checked' : '' ?>>
                        <span class="block text-center text-sm px-3 py-2 rounded-lg border border-gray-200 peer-checked:border-blue-600 peer-checked:bg-blue-50 peer-checked:text-blue-700"><?= $__l ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
                <p class="text-xs text-gray-500 mt-2">Auto matches the visitor's OS setting. Override with a fixed theme if needed.</p>
            </div>
        </div>
    </div>

    <!-- Brand Colors -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <div class="p-4 border-b border-gray-100">
            <h3 class="font-semibold text-gray-900 flex items-center gap-2">
                <i class="fa-solid fa-palette text-blue-600"></i>
                Brand Colors
            </h3>
        </div>
        <div class="p-6">
            <div class="grid md:grid-cols-2 gap-6">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Primary Color</label>
                    <div class="flex items-center gap-3">
                        <input type="color" name="primary_color" value="<?php echo sanitize($theme['primary_color'] ?? '#009bc1'); ?>" 
                               class="w-16 h-12 rounded-lg cursor-pointer border border-gray-200">
                        <input type="text" value="<?php echo sanitize($theme['primary_color'] ?? '#009bc1'); ?>" readonly
                               class="flex-1 px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 font-mono text-sm">
                    </div>
                    <p class="text-xs text-gray-500 mt-2">Used for buttons, links, and accents</p>
                </div>
                
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Secondary Color</label>
                    <div class="flex items-center gap-3">
                        <input type="color" name="secondary_color" value="<?php echo sanitize($theme['secondary_color'] ?? '#0f3460'); ?>" 
                               class="w-16 h-12 rounded-lg cursor-pointer border border-gray-200">
                        <input type="text" value="<?php echo sanitize($theme['secondary_color'] ?? '#0f3460'); ?>" readonly
                               class="flex-1 px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 font-mono text-sm">
                    </div>
                    <p class="text-xs text-gray-500 mt-2">Used for headers and backgrounds</p>
                </div>
            </div>
            
            <!-- Color Preview -->
            <div class="mt-6 p-4 rounded-xl bg-gray-50 border border-gray-200">
                <p class="text-sm font-medium text-gray-700 mb-3">Preview</p>
                <div class="flex items-center gap-4">
                    <div class="px-4 py-2 rounded-lg text-white text-sm font-medium" style="background-color: <?php echo sanitize($theme['primary_color'] ?? '#009bc1'); ?>">
                        Primary Button
                    </div>
                    <div class="px-4 py-2 rounded-lg text-white text-sm font-medium" style="background-color: <?php echo sanitize($theme['secondary_color'] ?? '#0f3460'); ?>">
                        Secondary Button
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Logo & Favicon -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <div class="p-4 border-b border-gray-100">
            <h3 class="font-semibold text-gray-900 flex items-center gap-2">
                <i class="fa-solid fa-image text-blue-600"></i>
                Logo & Favicon
            </h3>
        </div>
        <div class="p-6">
            <div class="grid md:grid-cols-2 gap-6">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Company Logo</label>
                    <?php if (!empty($theme['logo_path'])): ?>
                    <div class="mb-3 p-4 bg-gray-50 rounded-xl border border-gray-200 inline-block">
                        <img src="<?php echo getBasePath() . 'uploads/' . ltrim($theme['logo_path'], '/'); ?>" alt="Logo" class="max-h-16">
                    </div>
                    <?php endif; ?>
                    <input type="file" name="logo" accept="image/*" 
                           class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-lg text-black file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:bg-blue-50 file:text-blue-700 file:font-medium hover:file:bg-blue-100">
                    <p class="text-xs text-gray-500 mt-2">Recommended: PNG or SVG, max 500x200px</p>
                </div>
                
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Favicon</label>
                    <?php if (!empty($theme['favicon_path'])): ?>
                    <div class="mb-3 p-4 bg-gray-50 rounded-xl border border-gray-200 inline-block">
                        <img src="<?php echo getBasePath() . 'uploads/' . ltrim($theme['favicon_path'], '/'); ?>" alt="Favicon" class="w-8 h-8">
                    </div>
                    <?php endif; ?>
                    <input type="file" name="favicon" accept=".ico,.png,.jpg,.jpeg" 
                           class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-lg text-black file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:bg-blue-50 file:text-blue-700 file:font-medium hover:file:bg-blue-100">
                    <p class="text-xs text-gray-500 mt-2">Recommended: ICO or PNG, 32x32px or 64x64px</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Text Settings -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <div class="p-4 border-b border-gray-100">
            <h3 class="font-semibold text-gray-900 flex items-center gap-2">
                <i class="fa-solid fa-font text-blue-600"></i>
                Text Settings
            </h3>
        </div>
        <div class="p-6 space-y-4">
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-2">Header Text</label>
                <input type="text" name="header_text" value="<?php echo sanitize($theme['header_text'] ?? ''); ?>" 
                       class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20"
                       placeholder="Custom header text (leave empty to use company name)">
            </div>
            
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-2">Footer Text</label>
                <input type="text" name="footer_text" value="<?php echo sanitize($theme['footer_text'] ?? ''); ?>" 
                       class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20"
                       placeholder="Custom footer text">
            </div>
        </div>
    </div>

    <!-- Portal Settings -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <div class="p-4 border-b border-gray-100">
            <h3 class="font-semibold text-gray-900 flex items-center gap-2">
                <i class="fa-solid fa-door-open text-blue-600"></i>
                Employee Portal Settings
            </h3>
        </div>
        <div class="p-6 space-y-6">
            <!-- Portal Link -->
            <?php if ($companySlug): ?>
            <div class="p-4 bg-blue-50 rounded-xl border border-blue-200">
                <label class="block text-sm font-semibold text-blue-800 mb-2">Portal Link</label>
                <div class="flex items-center gap-2">
                    <input type="text" value="<?php echo getTenantUrl($companySlug, '/portal'); ?>" readonly
                           class="flex-1 px-4 py-2.5 bg-white border border-blue-200 rounded-lg text-gray-900 font-mono text-sm">
                    <button type="button" onclick="copyPortalLink()" class="px-4 py-2.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                        <i class="fa-solid fa-copy"></i>
                    </button>
                </div>
                <p class="text-xs text-blue-600 mt-2">Share this link with employees to let them submit card requests</p>
            </div>
            <?php endif; ?>
            
            <!-- Enable/Disable -->
            <div class="flex items-center justify-between p-4 bg-gray-50 rounded-xl">
                <div>
                    <label class="text-sm font-semibold text-gray-700">Enable Portal</label>
                    <p class="text-xs text-gray-500">Allow employees to submit card requests via the portal</p>
                </div>
                <label class="relative inline-flex items-center cursor-pointer">
                    <input type="checkbox" name="portal_enabled" class="sr-only peer" <?php echo ($company['portal_enabled'] ?? 1) ? 'checked' : ''; ?>>
                    <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                </label>
            </div>
            
            <!-- Show Preview -->
            <div class="flex items-center justify-between p-4 bg-gray-50 rounded-xl">
                <div>
                    <label class="text-sm font-semibold text-gray-700">Show Live Preview</label>
                    <p class="text-xs text-gray-500">Display real-time card preview while filling the form</p>
                </div>
                <label class="relative inline-flex items-center cursor-pointer">
                    <input type="checkbox" name="portal_show_preview" class="sr-only peer" <?php echo ($company['portal_show_preview'] ?? 1) ? 'checked' : ''; ?>>
                    <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                </label>
            </div>
            
            <!-- Passcode Protection -->
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-2">
                    <i class="fa-solid fa-lock text-gray-400 mr-1"></i>
                    Portal Access Code (Optional)
                </label>
                <input type="text" name="portal_passcode" value="<?php echo sanitize($company['portal_passcode'] ?? ''); ?>" 
                       maxlength="4" pattern="[0-9]*" inputmode="numeric"
                       class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 font-mono text-lg tracking-widest"
                       placeholder="e.g., 1234">
                <p class="text-xs text-gray-500 mt-2">4-digit code to protect the company portal. Leave empty for no restriction. Department codes take priority for department portals.</p>
            </div>
        </div>
    </div>

    <!-- Custom CSS -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <div class="p-4 border-b border-gray-100">
            <h3 class="font-semibold text-gray-900 flex items-center gap-2">
                <i class="fa-solid fa-code text-blue-600"></i>
                Custom CSS
            </h3>
        </div>
        <div class="p-6">
            <textarea name="custom_css" rows="8" 
                      class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 font-mono text-sm focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20"
                      placeholder="/* Add your custom CSS here */"><?php echo sanitize($theme['custom_css'] ?? ''); ?></textarea>
            <p class="text-xs text-gray-500 mt-2">Advanced: Add custom CSS to further customize the appearance</p>
        </div>
    </div>

    <!-- Save Button -->
    <div class="flex justify-end">
        <button type="submit" class="px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition-colors flex items-center gap-2">
            <i class="fa-solid fa-floppy-disk"></i>
            Save Theme Settings
        </button>
    </div>
</form>

<section class="mt-8 bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
    <div class="p-5 border-b border-gray-100">
        <h3 class="font-semibold text-gray-900 flex items-center gap-2">
            <i class="fa-brands fa-apple text-gray-900"></i>
            Apple Wallet themes
        </h3>
        <p class="text-sm text-gray-500 mt-1">
            Create company choices for Wallet. Employees can choose any published company or Cardify theme.
        </p>
    </div>
    <div class="p-6 grid xl:grid-cols-[1fr_1.3fr] gap-8">
        <form method="post" id="wallet-theme-form" class="space-y-4">
            <?php echo csrfField(); ?>
            <input type="hidden" name="wallet_action" value="save">
            <input type="hidden" name="wallet_theme_id" id="wallet_theme_id" value="">
            <input type="hidden" name="wallet_preview_path" value="">
            <div class="grid sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Name</label>
                    <input name="wallet_name_en" id="wallet_name_en" maxlength="120" required
                           class="w-full px-3 py-2.5 border border-gray-200 rounded-lg" placeholder="Executive teal">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Arabic name</label>
                    <input name="wallet_name_ar" id="wallet_name_ar" maxlength="120" dir="rtl"
                           class="w-full px-3 py-2.5 border border-gray-200 rounded-lg" placeholder="التنفيذي">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Wallet layout</label>
                    <select name="wallet_style" id="wallet_style" class="w-full px-3 py-2.5 border border-gray-200 rounded-lg">
                        <option value="eventTicket">Event ticket</option>
                        <option value="storeCard">Store card</option>
                        <option value="generic">Generic</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Logo</label>
                    <select name="wallet_logo_mode" id="wallet_logo_mode" class="w-full px-3 py-2.5 border border-gray-200 rounded-lg">
                        <option value="company">Company logo</option>
                        <option value="cardify">Cardify logo</option>
                        <option value="none">No logo</option>
                    </select>
                </div>
            </div>
            <div class="grid grid-cols-3 gap-3">
                <?php foreach ([
                    'background' => ['Background', '#006b7d'],
                    'foreground' => ['Text', '#ffffff'],
                    'label' => ['Labels', '#ffffff'],
                ] as $walletColorKey => [$walletColorLabel, $walletColorDefault]): ?>
                <label class="text-xs font-semibold text-gray-600">
                    <?= sanitize($walletColorLabel) ?>
                    <input type="color" name="wallet_<?= sanitize($walletColorKey) ?>_color"
                           id="wallet_<?= sanitize($walletColorKey) ?>_color"
                           value="<?= sanitize($walletColorDefault) ?>"
                           class="mt-1 w-full h-11 rounded-lg border border-gray-200">
                </label>
                <?php endforeach; ?>
            </div>
            <div class="grid sm:grid-cols-2 gap-4">
                <label class="text-sm text-gray-700">
                    Order
                    <input type="number" name="wallet_sort_order" id="wallet_sort_order" value="0" min="0" max="9999"
                           class="mt-1 w-full px-3 py-2 border border-gray-200 rounded-lg">
                </label>
                <div class="flex items-end gap-5 pb-2">
                    <label class="text-sm text-gray-700"><input type="checkbox" name="wallet_is_active" id="wallet_is_active" checked> Published</label>
                    <label class="text-sm text-gray-700"><input type="checkbox" name="wallet_is_default" id="wallet_is_default"> Default</label>
                </div>
            </div>
            <div class="flex gap-2">
                <button type="submit" class="px-4 py-2.5 bg-blue-600 text-white rounded-lg font-medium">
                    Save Wallet theme
                </button>
                <button type="button" id="wallet-theme-reset" class="px-4 py-2.5 bg-gray-100 text-gray-700 rounded-lg">
                    New theme
                </button>
            </div>
        </form>

        <div class="space-y-3">
            <?php if (!$walletThemes): ?>
                <div class="p-6 rounded-xl bg-gray-50 text-sm text-gray-500">
                    No company Wallet themes yet. Employees still have the global Cardify choices.
                </div>
            <?php endif; ?>
            <?php foreach ($walletThemes as $walletTheme): ?>
                <?php
                $walletThemeEditorData = htmlspecialchars(json_encode([
                    'id' => $walletTheme['id'],
                    'name_en' => $walletTheme['name_en'],
                    'name_ar' => $walletTheme['name_ar'],
                    'style' => $walletTheme['style'],
                    'logo_mode' => $walletTheme['logo_mode'],
                    'background_color' => $walletTheme['background_color'],
                    'foreground_color' => $walletTheme['foreground_color'],
                    'label_color' => $walletTheme['label_color'],
                    'sort_order' => (int)$walletTheme['sort_order'],
                    'is_active' => (int)$walletTheme['is_active'] === 1,
                    'is_default' => (int)$walletTheme['is_default'] === 1,
                ], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
                ?>
                <article class="p-4 border border-gray-200 rounded-xl flex gap-4 items-center">
                    <img src="wallet-theme-preview.php?id=<?= rawurlencode((string)$walletTheme['id']) ?>"
                         alt="" class="w-36 aspect-[1.58] object-cover rounded-lg border border-gray-100">
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2 flex-wrap">
                            <h4 class="font-semibold text-gray-900"><?= sanitize($walletTheme['name_en']) ?></h4>
                            <?php if ((int)$walletTheme['is_default'] === 1): ?>
                                <span class="text-[11px] px-2 py-0.5 bg-blue-50 text-blue-700 rounded-full">Default</span>
                            <?php endif; ?>
                            <span class="text-[11px] px-2 py-0.5 rounded-full <?= (int)$walletTheme['is_active'] === 1 ? 'bg-green-50 text-green-700' : 'bg-gray-100 text-black' ?>">
                                <?= (int)$walletTheme['is_active'] === 1 ? 'Published' : 'Hidden' ?>
                            </span>
                        </div>
                        <p class="text-xs text-gray-500 mt-1"><?= sanitize($walletTheme['style']) ?></p>
                        <div class="mt-3 flex gap-2 flex-wrap">
                            <button type="button" data-wallet-theme="<?= $walletThemeEditorData ?>"
                                    class="wallet-theme-edit text-xs px-3 py-1.5 bg-gray-100 rounded-lg">Edit</button>
                            <form method="post">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="wallet_theme_id" value="<?= sanitize($walletTheme['id']) ?>">
                                <input type="hidden" name="wallet_action" value="<?= (int)$walletTheme['is_active'] === 1 ? 'unpublish' : 'publish' ?>">
                                <button class="text-xs px-3 py-1.5 bg-gray-100 rounded-lg">
                                    <?= (int)$walletTheme['is_active'] === 1 ? 'Hide' : 'Publish' ?>
                                </button>
                            </form>
                            <form method="post" onsubmit="return confirm('Hide this Wallet theme? Existing selections will fall back safely.');">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="wallet_theme_id" value="<?= sanitize($walletTheme['id']) ?>">
                                <input type="hidden" name="wallet_action" value="archive">
                                <button class="text-xs px-3 py-1.5 text-red-600 bg-red-50 rounded-lg">Archive</button>
                            </form>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<script>
// Update color text inputs when color pickers change
document.querySelectorAll('input[type="color"]').forEach(picker => {
    picker.addEventListener('input', function() {
        this.nextElementSibling.value = this.value;
    });
});

// Copy portal link to clipboard
function copyPortalLink() {
    const input = document.querySelector('input[value*="/portal"]');
    if (input) {
        navigator.clipboard.writeText(input.value).then(() => {
            // Show brief feedback
            const btn = event.target.closest('button');
            const icon = btn.querySelector('i');
            icon.className = 'fa-solid fa-check';
            setTimeout(() => {
                icon.className = 'fa-solid fa-copy';
            }, 2000);
        });
    }
}

const walletThemeForm = document.getElementById('wallet-theme-form');
const resetWalletThemeForm = () => {
    walletThemeForm.reset();
    document.getElementById('wallet_theme_id').value = '';
    document.getElementById('wallet_background_color').value = '#006b7d';
    document.getElementById('wallet_foreground_color').value = '#ffffff';
    document.getElementById('wallet_label_color').value = '#ffffff';
    document.getElementById('wallet_is_active').checked = true;
};
document.getElementById('wallet-theme-reset').addEventListener('click', resetWalletThemeForm);
document.querySelectorAll('.wallet-theme-edit').forEach(button => {
    button.addEventListener('click', () => {
        const theme = JSON.parse(button.dataset.walletTheme);
        Object.entries(theme).forEach(([key, value]) => {
            const field = document.getElementById('wallet_' + key);
            if (!field) return;
            if (field.type === 'checkbox') field.checked = Boolean(value);
            else field.value = value ?? '';
        });
        walletThemeForm.scrollIntoView({behavior: 'smooth', block: 'center'});
    });
});
</script>

<?php adminFooter(); ?>
