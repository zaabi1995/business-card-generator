<?php
/**
 * Company Theme Management - Cardify
 */
require_once __DIR__ . '/../config.php';
requireAdmin();
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/admin-layout.php';

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

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
<div class="mb-6 p-4 rounded-xl flex items-center gap-3 bg-green-50 border border-green-200 text-green-700">
    <i class="fa-solid fa-circle-check"></i>
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
                           class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:bg-blue-50 file:text-blue-700 file:font-medium hover:file:bg-blue-100">
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
                           class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:bg-blue-50 file:text-blue-700 file:font-medium hover:file:bg-blue-100">
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
</script>

<?php adminFooter(); ?>
