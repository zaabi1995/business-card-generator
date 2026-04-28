<?php
/**
 * Admin Dashboard - Cardify
 * Template Management with Fabric.js Visual Editor
 */

require_once __DIR__ . '/../config.php';

// Only show errors in development
if (function_exists('isProduction') && !isProduction()) {
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    error_reporting(E_ALL & ~E_NOTICE);
}
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/admin-layout.php';
require_once INCLUDES_DIR . '/Referral.php';

// Check authentication and role
if (!Auth::isLoggedIn()) {
    header('Location: ' . getBasePath() . 'login.php');
    exit;
}

// Refresh role from DB to catch role/company changes since last session write
// (prevents redirect loops when admin changes a user's role mid-session).
if (!empty($_SESSION['user_id'])) {
    $db = Database::getInstance();
    $fresh = $db->fetchOne("SELECT role, company_id FROM users WHERE id = :id", ['id' => $_SESSION['user_id']]);
    if ($fresh) {
        if (!empty($fresh['role']) && $fresh['role'] !== ($_SESSION['user_role'] ?? null)) {
            $_SESSION['user_role'] = $fresh['role'];
        }
        if (isset($fresh['company_id']) && $fresh['company_id'] !== ($_SESSION['user_company_id'] ?? null)) {
            $_SESSION['user_company_id'] = $fresh['company_id'];
            // Clear stale company_slug if company changed
            unset($_SESSION['company_slug']);
        }
    }
}

// Redirect print shop users to their dashboard
$currentRole = Auth::getCurrentRole();
if ($currentRole === 'print_shop') {
    header('Location: ' . getBasePath() . 'printshop/dashboard.php');
    exit;
}

// Redirect company admins to company-specific admin URL
// Super admins stay on the global admin
if (!Auth::isSuperAdmin()) {
    redirectToCompanyAdmin();
}

$currentUser = Auth::getCurrentUser();
$userRole = Auth::getCurrentRole();

$companyId = getCurrentCompanyId();

// First-login redirect, send admins who have never touched the wizard to it.
// Acknowledged-and-suppressed tenants (skipped within the last 24h, or any
// tenant with completed_at) are handled inside Onboarding::shouldShowWizard().
if ($companyId && !isset($_GET['wizard']) && !isset($_GET['no_onboard'])) {
    require_once INCLUDES_DIR . '/Onboarding.php';
    if (Onboarding::shouldShowWizard($companyId)) {
        $onboardUrl = getAdminBasePath() . 'onboarding' . ((defined('COMPANY_ADMIN_BASE') || !empty($_SESSION['company_slug'])) ? '' : '.php');
        header('Location: ' . $onboardUrl);
        exit;
    }
}

// Get company theme for styling
$companyTheme = null;
if ($companyId && DatabaseAdapter::useDatabase()) {
    $db = Database::getInstance();
    $companyTheme = $db->fetchOne(
        "SELECT * FROM company_themes WHERE company_id = :id",
        ['id' => $companyId]
    );
}

// Apply theme colors
$primaryColor = $companyTheme['primary_color'] ?? '#2563eb';
$secondaryColor = $companyTheme['secondary_color'] ?? '#0f3460';

// Logout is handled by logout.php, GET logout removed for CSRF safety

// Load templates
$templatesConfig = loadTemplates();
$templates = $templatesConfig['templates'] ?? [];
$activeFrontId = $templatesConfig['activeFrontId'] ?? null;
$activeBackId = $templatesConfig['activeBackId'] ?? null;

// Convert legacy field positions for existing templates.
// Modern saves stamp settings.fields_format = 'px' so the legacy
// percentage-to-pixel heuristic is skipped (it mis-fires on real
// small-pixel coords like an Arabic name placed near top-left).
foreach ($templates as &$template) {
    if (isset($template['fields'])) {
        $fmt = $template['settings']['fields_format'] ?? null;
        $template['fields'] = convertLegacyFieldPositions($template['fields'], 1050, 600, $fmt);
    }
}
unset($template);

// Get employees count
$employees = loadEmployees($companyId);
$employeeCount = count($employees);

// Get generated cards count
$generatedLog = loadGeneratedLog();
$generatedCount = count($generatedLog);

// Get departments count
$departments = [];
if ($companyId && DatabaseAdapter::useDatabase()) {
    $departments = $db->fetchAll(
        "SELECT * FROM departments WHERE company_id = :id",
        ['id' => $companyId]
    );
}
$departmentCount = count($departments);

// Separate templates by side
$frontTemplates = array_filter($templates, fn($t) => ($t['side'] ?? 'front') === 'front');
$backTemplates = array_filter($templates, fn($t) => ($t['side'] ?? 'back') === 'back');

// Sample profiles for preview - diverse names/positions to test different text lengths
$sampleProfiles = [
    [
        'name_en' => 'Ahmed Al-Rashid',
        'name_ar' => 'أحمد الراشد',
        'position_en' => 'Chief Executive Officer',
        'position_ar' => 'الرئيس التنفيذي',
        'department_en' => 'Executive Office',
        'department_ar' => 'المكتب التنفيذي',
        'company_en' => 'Oman Investment Group',
        'company_ar' => 'مجموعة عُمان للاستثمار',
        'phone' => '+968 2412 3456',
        'fax' => '+968 2412 3457',
        'mobile' => '+968 9123 4567',
        'email' => 'ahmed.rashid@oig.om',
        'website' => 'www.oig.om',
        'address_en' => 'P.O. Box : 2555, P.C : 112, Ruwi',
        'address_en_2' => 'Muscat, Sultanate of Oman',
        'address_ar' => 'ص.ب: ٢٥٥٥، الرمز البريدي: ١١٢، روي',
        'address_ar_2' => 'مسقط، سلطنة عُمان'
    ],
    [
        'name_en' => 'Sarah Johnson',
        'name_ar' => 'سارة جونسون',
        'position_en' => 'Marketing Director',
        'position_ar' => 'مدير التسويق',
        'department_en' => 'Marketing & Communications',
        'department_ar' => 'التسويق والاتصالات',
        'company_en' => 'Global Solutions LLC',
        'company_ar' => 'الحلول العالمية ش.م.م',
        'phone' => '+968 2498 7654',
        'fax' => '+968 2498 7655',
        'mobile' => '+968 9876 5432',
        'email' => 'sarah.johnson@globalsolutions.com',
        'website' => 'www.globalsolutions.com',
        'address_en' => 'P.O. Box : 2555, P.C : 112, Ruwi',
        'address_en_2' => 'Muscat, Sultanate of Oman',
        'address_ar' => 'ص.ب: ٢٥٥٥، الرمز البريدي: ١١٢، روي',
        'address_ar_2' => 'مسقط، سلطنة عُمان'
    ],
    [
        'name_en' => 'Mohammed bin Khalid Al-Busaidi',
        'name_ar' => 'محمد بن خالد البوسعيدي',
        'position_en' => 'Senior Vice President of Operations',
        'position_ar' => 'نائب الرئيس الأول للعمليات',
        'department_en' => 'Operations & Logistics',
        'department_ar' => 'العمليات والخدمات اللوجستية',
        'company_en' => 'National Development Corporation',
        'company_ar' => 'المؤسسة الوطنية للتنمية',
        'phone' => '+968 2411 1111',
        'fax' => '+968 2411 1112',
        'mobile' => '+968 9111 2222',
        'email' => 'mohammed.busaidi@ndc.om',
        'website' => 'www.ndc.om',
        'address_en' => 'P.O. Box : 2555, P.C : 112, Ruwi',
        'address_en_2' => 'Muscat, Sultanate of Oman',
        'address_ar' => 'ص.ب: ٢٥٥٥، الرمز البريدي: ١١٢، روي',
        'address_ar_2' => 'مسقط، سلطنة عُمان'
    ],
    [
        'name_en' => 'Li Wei',
        'name_ar' => 'لي وي',
        'position_en' => 'Project Manager',
        'position_ar' => 'مدير المشاريع',
        'department_en' => 'Technology',
        'department_ar' => 'التكنولوجيا',
        'company_en' => 'TechStart Innovation Hub',
        'company_ar' => 'مركز تك ستارت للابتكار',
        'phone' => '+968 2455 6789',
        'fax' => '+968 2455 6780',
        'mobile' => '+968 9555 1234',
        'email' => 'li.wei@techstart.io',
        'website' => 'www.techstart.io',
        'address_en' => 'P.O. Box : 2555, P.C : 112, Ruwi',
        'address_en_2' => 'Muscat, Sultanate of Oman',
        'address_ar' => 'ص.ب: ٢٥٥٥، الرمز البريدي: ١١٢، روي',
        'address_ar_2' => 'مسقط، سلطنة عُمان'
    ],
    [
        'name_en' => 'Fatima Al-Harthi',
        'name_ar' => 'فاطمة الحارثية',
        'position_en' => 'Human Resources Manager',
        'position_ar' => 'مدير الموارد البشرية',
        'department_en' => 'Human Resources',
        'department_ar' => 'الموارد البشرية',
        'company_en' => 'Petroleum Development Oman',
        'company_ar' => 'تنمية نفط عُمان',
        'phone' => '+968 2467 8900',
        'fax' => '+968 2467 8901',
        'mobile' => '+968 9234 5678',
        'email' => 'fatima.harthi@pdo.co.om',
        'website' => 'www.pdo.co.om',
        'address_en' => 'P.O. Box : 2555, P.C : 112, Ruwi',
        'address_en_2' => 'Muscat, Sultanate of Oman',
        'address_ar' => 'ص.ب: ٢٥٥٥، الرمز البريدي: ١١٢، روي',
        'address_ar_2' => 'مسقط، سلطنة عُمان'
    ]
];

// Get sample employee for preview - use first employee if exists, otherwise first sample profile
$sampleEmployee = !empty($employees) ? $employees[0] : $sampleProfiles[0];

// Add Arabic phone numbers
if (!isset($sampleEmployee['phone_ar'])) {
    $sampleEmployee['phone_ar'] = strtr($sampleEmployee['phone'] ?? '', ['0'=>'٠','1'=>'١','2'=>'٢','3'=>'٣','4'=>'٤','5'=>'٥','6'=>'٦','7'=>'٧','8'=>'٨','9'=>'٩','+'=>'+']);
}
if (!isset($sampleEmployee['mobile_ar'])) {
    $sampleEmployee['mobile_ar'] = strtr($sampleEmployee['mobile'] ?? '', ['0'=>'٠','1'=>'١','2'=>'٢','3'=>'٣','4'=>'٤','5'=>'٥','6'=>'٦','7'=>'٧','8'=>'٨','9'=>'٩','+'=>'+']);
}
if (!isset($sampleEmployee['website_ar'])) {
    $sampleEmployee['website_ar'] = $sampleEmployee['website'] ?? '';
}
if (!isset($sampleEmployee['address'])) {
    $sampleEmployee['address'] = $sampleEmployee['address_en_2'] ?? 'Muscat, Sultanate of Oman';
}
if (!isset($sampleEmployee['address_en_2'])) {
    $sampleEmployee['address_en_2'] = 'Muscat, Sultanate of Oman';
}
if (!isset($sampleEmployee['address_ar_2'])) {
    $sampleEmployee['address_ar_2'] = 'مسقط، سلطنة عُمان';
}

// Company slug for VCF URL
$companySlug = getCurrentCompanySlug() ?? 'demo';
$baseUrl = getBaseUrl();

// Start admin layout
adminHeader(t('dashboard.page_title'), 'dashboard');

// Get company referral source, plan info for welcome banner + usage indicator
$companyReferralSource = null;
$companyRow = [];
if ($companyId && DatabaseAdapter::useDatabase()) {
    try {
        $companyRow = $db->fetchOne(
            "SELECT referral_source, onboarding_completed, plan, subscription_status, phone, phone_backfill_skips FROM companies WHERE id = :id",
            ['id' => $companyId]
        ) ?: [];
        $companyReferralSource = $companyRow['referral_source'] ?? null;
    } catch (Exception $e) {
        // legacy installs without `phone` / `phone_backfill_skips`, fall back
        try {
            $companyRow = $db->fetchOne(
                "SELECT referral_source, onboarding_completed, plan, subscription_status, phone FROM companies WHERE id = :id",
                ['id' => $companyId]
            ) ?: [];
            $companyReferralSource = $companyRow['referral_source'] ?? null;
        } catch (Exception $e2) {
            try {
                $companyRow = $db->fetchOne(
                    "SELECT referral_source, onboarding_completed, plan, subscription_status FROM companies WHERE id = :id",
                    ['id' => $companyId]
                ) ?: [];
                $companyReferralSource = $companyRow['referral_source'] ?? null;
            } catch (Exception $e3) {}
        }
    }
}

// Phone-capture prompt, shown if admin has no phone on file.
// Dismissal: session-scoped suppression (don't re-pop on every page load) +
// persistent counter on companies.phone_backfill_skips (BHD-224 spec:
// "dismissible after 3 skips", once we hit 3, we never show this banner
// again for this company).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'dismiss_phone_prompt') {
    if (validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['phone_prompt_dismissed'] = true;
        if ($companyId) {
            try {
                // LEAST(...,3) so the counter is bounded, used as a hard suppression cap.
                $db->getConnection()
                    ->prepare("UPDATE companies SET phone_backfill_skips = LEAST(IFNULL(phone_backfill_skips, 0) + 1, 3) WHERE id = :id")
                    ->execute([':id' => $companyId]);
            } catch (Exception $e) {
                // Column missing on legacy install, counter just won't persist.
            }
        }
    }
    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_phone_prompt') {
    if (validateCSRFToken($_POST['csrf_token'] ?? '') && $companyId) {
        // Prefer the canonical E.164 string from intl-tel-input over the
        // visible national-format field.
        $rawPhone = trim($_POST['phone_e164'] ?? $_POST['phone'] ?? '');
        if ($rawPhone !== '') {
            require_once INCLUDES_DIR . '/WhatsApp.php';
            $digits = WhatsApp::normalizePhone($rawPhone);
            $normalized = strlen($digits) >= 8 ? '+' . $digits : $rawPhone;
            try {
                $db->update('companies', ['phone' => $normalized], 'id = :id', ['id' => $companyId]);
                if (!empty($_SESSION['user_id'])) {
                    try {
                        $db->update('users', ['phone' => $normalized], 'id = :id', ['id' => $_SESSION['user_id']]);
                    } catch (Exception $e) { /* users.phone may not exist yet */ }
                }
                $_SESSION['phone_prompt_dismissed'] = true;
            } catch (Exception $e) {
                error_log('[phone-prompt] save failed: ' . $e->getMessage());
            }
        }
    }
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}
$adminHasPhone = !empty(trim($companyRow['phone'] ?? ''));
// Tenant OTP-login users land here with users.phone already set (BHD-style
// passwordless flow). Don't pester them to "add WhatsApp" again.
if (!$adminHasPhone && !empty($_SESSION['user_id'])) {
    try {
        $userPhone = $db->fetchOne('SELECT phone FROM users WHERE id = :id LIMIT 1', ['id' => $_SESSION['user_id']]);
        if (!empty(trim($userPhone['phone'] ?? ''))) {
            $adminHasPhone = true;
        }
    } catch (Throwable $_) { /* users.phone column may not exist yet */ }
}
$adminBackfillSkips = (int) ($companyRow['phone_backfill_skips'] ?? 0);
$showPhonePrompt = (
    !$adminHasPhone
    && $currentRole !== 'super_admin'
    && empty($_SESSION['phone_prompt_dismissed'])
    && array_key_exists('phone', $companyRow) // column must exist (post-migration)
    && $adminBackfillSkips < 3                // honour the persistent skip cap
);
$companyPlan = $companyRow['plan'] ?? 'free';
// Apr 2026 pricing reset: every team is on the free, fully-unlocked platform.
// Card-counting code below is retained as no-op telemetry only.
$isFreePlan = false;
// Cards generated this month (for free plan limit indicator)
$cardsThisMonth = 0;
if ($isFreePlan && $companyId && DatabaseAdapter::useDatabase()) {
    try {
        $r = $db->fetchOne(
            "SELECT COUNT(*) as cnt FROM generated_cards WHERE company_id = :id AND generated_at >= DATE_FORMAT(NOW(),'%Y-%m-01')",
            ['id' => $companyId]
        );
        $cardsThisMonth = (int)($r['cnt'] ?? 0);
    } catch (Exception $e) {}
}
$showWelcome = ($_GET['welcome'] ?? '') === '1';
$onboardingCompleted = (int)($companyRow['onboarding_completed'] ?? 0);

// Card view analytics for dashboard widget (always available, proves value)
$viewsAllTime = 0;
$views30d = 0;
$views7d = 0;
$dailyViews7d = [];
if ($companyId && DatabaseAdapter::useDatabase()) {
    try {
        $r = $db->fetchOne("SELECT COUNT(*) as cnt FROM qr_scans WHERE company_id = :id", ['id' => $companyId]);
        $viewsAllTime = (int)($r['cnt'] ?? 0);
        $r = $db->fetchOne("SELECT COUNT(*) as cnt FROM qr_scans WHERE company_id = :id AND scanned_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)", ['id' => $companyId]);
        $views30d = (int)($r['cnt'] ?? 0);
        $r = $db->fetchOne("SELECT COUNT(*) as cnt FROM qr_scans WHERE company_id = :id AND scanned_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)", ['id' => $companyId]);
        $views7d = (int)($r['cnt'] ?? 0);
        // Daily breakdown for last 7 days (for bar chart)
        $dailyRows = $db->fetchAll(
            "SELECT DATE(scanned_at) as d, COUNT(*) as cnt FROM qr_scans WHERE company_id = :id AND scanned_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY DATE(scanned_at) ORDER BY d ASC",
            ['id' => $companyId]
        ) ?: [];
        // Fill all 7 days (including zero days)
        for ($i = 6; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $dailyViews7d[$date] = 0;
        }
        foreach ($dailyRows as $row) {
            if (isset($dailyViews7d[$row['d']])) {
                $dailyViews7d[$row['d']] = (int)$row['cnt'];
            }
        }
    } catch (Exception $e) {}
}

// Getting started checklist, only for company admins, hidden once all steps done
$hasLogo = !empty($companyTheme['logo_path']);
$hasTemplate = count($frontTemplates) > 0;
$hasEmployee = $employeeCount > 0;
$hasGeneratedCard = $generatedCount > 0;
$hasPrintOrder = false;
if ($companyId && DatabaseAdapter::useDatabase()) {
    try {
        $orderRow = $db->fetchOne("SELECT id FROM print_orders WHERE company_id = :id LIMIT 1", ['id' => $companyId]);
        $hasPrintOrder = !empty($orderRow);
    } catch (Exception $e) {}
}
$_inCompanyCtx = defined('COMPANY_ADMIN_BASE') || !empty($_SESSION['company_slug']);
$_ext = $_inCompanyCtx ? '' : '.php';
// Aligned to the 3-step onboarding (Brand -> Card design -> Launch).
// Once all three are checked the whole checklist auto-hides via $checklistAllDone.
//
// Card design is "done" when EITHER:
//   - the user uploaded a PDF in the wizard (Onboarding state has
//     data.card_design.imported = true), OR
//   - they have a fabric.js template authored in the editor
//     (count($frontTemplates) > 0)
// We accept either path so power users who skip the wizard still
// progress the checklist by creating a template directly.
$cardDesignImported = false;
if ($companyId && class_exists('Onboarding')) {
    try {
        $obState = Onboarding::get($companyId);
        $cd = $obState['data']['card_design'] ?? [];
        $cardDesignImported = !empty($cd['imported']) || !empty($cd['import_token']);
    } catch (Throwable $e) {}
}
$hasCardDesign = $cardDesignImported || $hasTemplate;
$checklistSteps = [
    ['done' => $hasLogo,         'label' => 'Upload your logo',              'url' => getBasePath() . 'onboarding.php'],
    ['done' => $hasCardDesign,   'label' => 'Upload your card design',       'url' => getBasePath() . 'onboarding.php'],
    ['done' => $hasEmployee,     'label' => 'Add your first employee',       'url' => getAdminBasePath() . 'employees' . $_ext],
];
$checklistAllDone = array_reduce($checklistSteps, fn($carry, $s) => $carry && $s['done'], true);
$checklistDoneCount = array_sum(array_column($checklistSteps, 'done'));

// BHD-234: referral share card (every logged-in company admin gets one).
$referralCode = null;
$referralShareUrl = null;
$referralStats = ['signups' => 0, 'paid' => 0];
$referralWhatsAppHref = null;
if ($currentRole !== 'super_admin' && !empty($_SESSION['user_id'])) {
    try {
        $referralCode = Referral::ensureCodeForUser($_SESSION['user_id']);
        if ($referralCode) {
            $referralShareUrl = Referral::shareUrl($referralCode);
            $referralStats = Referral::statsForUser($_SESSION['user_id']);
            $waMsg = "👋 جربت Cardify مؤخراً, بطاقات أعمال رقمية احترافية في دقائق. جرّبها مجاناً:\n{$referralShareUrl}\n\nI've been using Cardify, digital business cards done right. Free to try:\n{$referralShareUrl}";
            $referralWhatsAppHref = 'https://wa.me/?text=' . rawurlencode($waMsg);
        }
    } catch (Throwable $e) {
        error_log('[dashboard] referral card failed: ' . $e->getMessage());
    }
}
?>

<?php if ($showPhonePrompt): ?>
<!-- Phone Backfill Prompt (BHD-224) -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/intl-tel-input@23.8.2/build/css/intlTelInput.css">
<style>
    #bhd224-banner-phone + .iti, .iti:has(#bhd224-banner-phone) { width: 12rem; display: inline-block; }
    #bhd224-banner-phone.iti__tel-input { padding-left: 3.5rem !important; }
</style>
<div class="mb-6 rounded-xl border border-blue-200 bg-blue-50 p-4" id="phone-prompt-banner">
    <form method="post" class="flex flex-col sm:flex-row sm:items-center gap-3">
        <?= csrfField(); ?>
        <input type="hidden" name="action" value="save_phone_prompt">
        <div class="flex items-start gap-3 flex-1">
            <div class="w-9 h-9 rounded-lg bg-blue-100 flex items-center justify-center flex-shrink-0">
                <i class="fa-brands fa-whatsapp text-blue-600"></i>
            </div>
            <div>
                <p class="font-semibold text-blue-900 text-sm"><?= htmlspecialchars(t('dashboard.add_wa_title')) ?></p>
                <p class="text-blue-700 text-xs mt-0.5">We'll message you on WhatsApp for order status and onboarding tips. Leave blank to opt out.</p>
            </div>
        </div>
        <div class="flex items-center gap-2 flex-shrink-0">
            <input type="tel" id="bhd224-banner-phone" name="phone" autocomplete="tel" inputmode="tel" required
                   placeholder="9XXX XXXX"
                   class="px-3 py-2 bg-white border border-blue-200 rounded-lg text-sm w-48 focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
            <input type="hidden" name="phone_e164" id="bhd224-banner-phone-e164" value="">
            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-semibold px-4 py-2 rounded-lg text-sm whitespace-nowrap">
                Save
            </button>
        </div>
    </form>
    <form method="post" class="flex justify-between items-center mt-2">
        <?= csrfField(); ?>
        <input type="hidden" name="action" value="dismiss_phone_prompt">
        <span class="text-[11px] text-blue-700/70">
            <?php if ($adminBackfillSkips > 0): ?>
                Reminded <?= 3 - $adminBackfillSkips ?> more time<?= ($adminBackfillSkips === 2) ? '' : 's' ?> after this, then we'll stop.
            <?php endif; ?>
        </span>
        <button type="submit" class="text-xs text-blue-600 hover:text-blue-800 underline">
            <?= $adminBackfillSkips >= 2 ? 'Stop reminding me' : 'Not now' ?>
        </button>
    </form>
</div>
<script src="https://cdn.jsdelivr.net/npm/intl-tel-input@23.8.2/build/js/intlTelInput.min.js"></script>
<script>
(function () {
    var phoneEl = document.getElementById('bhd224-banner-phone');
    var hiddenEl = document.getElementById('bhd224-banner-phone-e164');
    if (!phoneEl || !window.intlTelInput) return;
    var iti = window.intlTelInput(phoneEl, {
        initialCountry: 'om',
        preferredCountries: ['om', 'ae', 'sa', 'qa', 'bh', 'kw'],
        separateDialCode: true,
        autoPlaceholder: 'aggressive',
        utilsScript: 'https://cdn.jsdelivr.net/npm/intl-tel-input@23.8.2/build/js/utils.js'
    });
    var form = phoneEl.closest('form');
    if (form) {
        form.addEventListener('submit', function () {
            try {
                if (phoneEl.value.trim() === '') { hiddenEl.value = ''; return; }
                hiddenEl.value = iti.isValidNumber() ? iti.getNumber() : (iti.getNumber() || phoneEl.value.trim());
            } catch (err) {
                hiddenEl.value = phoneEl.value.trim();
            }
        });
    }
})();
</script>
<?php endif; ?>

<?php
// Onboarding resume + order-nudge banners (Cardify v2.0 sprint, actions 099 + 110).
$onboardState = null;
if ($companyId) {
    require_once INCLUDES_DIR . '/Onboarding.php';
    $onboardState = Onboarding::get($companyId);
}
$showResumeBanner = $onboardState && !empty($onboardState['started_at']) && empty($onboardState['completed_at']) && (int)$onboardState['step'] > 0 && (int)$onboardState['step'] < Onboarding::TOTAL_STEPS;
$showOrderNudge  = $onboardState && !empty($onboardState['completed_at']) && empty($onboardState['data']['order_cards']['per_person']);
$demoSeededIds = !empty($onboardState['data']['demo_employee_ids']) && is_array($onboardState['data']['demo_employee_ids'])
    ? $onboardState['data']['demo_employee_ids'] : [];
$showDemoBanner = !empty($demoSeededIds);
$adminBase = rtrim(getAdminBasePath(), '/');
$ext = (defined('COMPANY_ADMIN_BASE') || !empty($_SESSION['company_slug'])) ? '' : '.php';
?>

<?php if (!empty($_GET['demo_cleared'])): ?>
<div class="mb-4 rounded-xl bg-emerald-50 border border-emerald-200 p-3 text-sm text-emerald-900"
     x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 5000)">
    <i class="fa-solid fa-check-circle mr-1"></i>
    <?= htmlspecialchars(t('onboarding.demo_cleared_toast', ['n' => (int) $_GET['demo_cleared']])) ?>
</div>
<?php endif; ?>

<?php if ($showDemoBanner): ?>
<div class="mb-4 rounded-xl bg-purple-50 border border-purple-200 p-4 flex items-center justify-between gap-4">
    <div class="flex items-center gap-3 text-purple-900">
        <i class="fa-solid fa-flask text-purple-600"></i>
        <span class="text-sm"><?= htmlspecialchars(t('onboarding.demo_banner')) ?></span>
    </div>
    <form method="POST" action="<?= $adminBase ?>/demo-clear<?= $ext ?>" class="m-0">
        <?= csrfField() ?>
        <button type="submit" class="px-3 py-1.5 text-xs font-semibold text-purple-700 hover:text-purple-900 hover:bg-purple-100 rounded-lg whitespace-nowrap">
            <i class="fa-solid fa-broom mr-1"></i>
            <?= htmlspecialchars(t('onboarding.demo_clear')) ?>
        </button>
    </form>
</div>
<?php endif; ?>

<?php if (isset($_GET['wizard']) && $_GET['wizard'] === 'done'): ?>
<div class="mb-4 rounded-xl bg-gradient-to-r from-emerald-500 to-teal-500 text-white p-4 flex items-center justify-between gap-4 animate-pulse-once"
     x-data="{ show: true }" x-show="show"
     x-init="setTimeout(() => { show = false }, 8000)">
    <div class="flex items-center gap-3">
        <i class="fa-solid fa-party-horn text-2xl"></i>
        <div>
            <p class="font-bold text-lg"><?= htmlspecialchars(t('onboarding.congrats')) ?></p>
            <p class="text-sm text-emerald-100"><?= htmlspecialchars(t('onboarding.congrats_subtitle')) ?></p>
        </div>
    </div>
    <button @click="show = false" class="text-white/80 hover:text-white text-lg"><i class="fa-solid fa-times"></i></button>
</div>
<style>
@keyframes pulseOnce { 0%,100% { transform: scale(1); } 50% { transform: scale(1.01); } }
.animate-pulse-once { animation: pulseOnce 1.5s ease-in-out 2; }
</style>
<script src="<?= getBasePath() ?>assets/js/canvas-confetti.min.js" defer></script>
<script>
// Wizard-done confetti: 3-second burst in 3 volleys from opposing edges,
// honors prefers-reduced-motion. Fires once per page load; no loop.
(function () {
    if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    function start() {
        if (typeof confetti !== 'function') return;
        var end = Date.now() + 3000;
        var colors = ['#009bc1', '#fb0', '#824598', '#45c0ba', '#16a34a'];
        (function frame() {
            confetti({ particleCount: 4, angle: 60,  spread: 55, origin: { x: 0,   y: 0.7 }, colors: colors, disableForReducedMotion: true });
            confetti({ particleCount: 4, angle: 120, spread: 55, origin: { x: 1,   y: 0.7 }, colors: colors, disableForReducedMotion: true });
            if (Date.now() < end) requestAnimationFrame(frame);
        })();
    }
    if (document.readyState === 'complete') start();
    else window.addEventListener('load', start, { once: true });
})();
</script>
<?php endif; ?>

<?php if ($showResumeBanner): ?>
<div class="mb-4 rounded-xl bg-blue-50 border border-blue-200 p-4 flex items-center justify-between gap-4">
    <div class="flex items-center gap-3 text-blue-900">
        <i class="fa-solid fa-list-check text-blue-600"></i>
        <span class="text-sm font-medium">
            <?= htmlspecialchars(t('onboarding.dashboard_resume', ['done' => (int)$onboardState['step'], 'total' => Onboarding::TOTAL_STEPS])) ?>
        </span>
    </div>
    <a href="<?= $adminBase ?>/onboarding<?= $ext ?>" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold rounded-lg whitespace-nowrap">
        <?= htmlspecialchars(t('onboarding.dashboard_resume_cta')) ?>
        <i class="fa-solid fa-arrow-right ml-1"></i>
    </a>
</div>
<?php endif; ?>

<?php if ($showOrderNudge): ?>
<div class="mb-4 rounded-xl bg-amber-50 border border-amber-200 p-4 flex items-center justify-between gap-4">
    <div class="flex items-center gap-3 text-amber-900">
        <i class="fa-solid fa-truck-fast text-amber-600"></i>
        <span class="text-sm font-medium"><?= htmlspecialchars(t('onboarding.dashboard_order_nudge')) ?></span>
    </div>
    <a href="<?= $adminBase ?>/print<?= $ext ?>" class="px-4 py-2 bg-amber-600 hover:bg-amber-700 text-white text-sm font-semibold rounded-lg whitespace-nowrap">
        <?= htmlspecialchars(t('onboarding.dashboard_order_cta')) ?>
        <i class="fa-solid fa-arrow-right ml-1"></i>
    </a>
</div>
<?php endif; ?>

<?php if ($showWelcome): ?>
<!-- Post-onboarding Welcome Banner -->
<div class="mb-6 rounded-2xl p-6 text-white shadow-lg flex items-center justify-between gap-4" id="bhd-welcome-banner" style="background:linear-gradient(to right,var(--tbrand,#2563eb),var(--tbrand-2,#06b6d4));">
    <div class="flex items-center gap-4">
        <div class="w-12 h-12 rounded-full bg-white/20 flex items-center justify-center flex-shrink-0">
            <i class="fa-solid fa-rocket text-white text-xl"></i>
        </div>
        <div>
            <h3 class="font-bold text-lg"><?= htmlspecialchars(t('dashboard.all_set_title')) ?></h3>
            <p class="text-blue-100 text-sm mt-0.5"><?= htmlspecialchars(t('dashboard.all_set_body')) ?></p>
        </div>
    </div>
    <div class="flex items-center gap-3 flex-shrink-0">
        <?php $empExt = defined('COMPANY_ADMIN_BASE') ? '' : '.php'; ?>
        <a href="<?= getAdminBasePath() ?>employees<?= $empExt ?>" class="bg-white text-blue-600 font-semibold px-4 py-2 rounded-lg text-sm hover:bg-blue-50 transition-all whitespace-nowrap flex items-center gap-1.5">
            <i class="fa-solid fa-id-card text-xs"></i>
            Create My Card
        </a>
        <button onclick="document.getElementById('bhd-welcome-banner').remove()" class="text-white/60 hover:text-white">
            <i class="fa-solid fa-xmark text-lg"></i>
        </button>
    </div>
</div>
<?php elseif (!$onboardingCompleted): ?>
<!-- Onboarding incomplete nudge -->
<div class="mb-6 rounded-xl border border-amber-200 bg-amber-50 p-4 flex items-center justify-between gap-4">
    <div class="flex items-center gap-3">
        <div class="w-9 h-9 rounded-lg bg-amber-100 flex items-center justify-center flex-shrink-0">
            <i class="fa-solid fa-circle-exclamation text-amber-500"></i>
        </div>
        <div>
            <p class="font-semibold text-amber-900 text-sm"><?= htmlspecialchars(t('dashboard.complete_setup')) ?></p>
            <p class="text-amber-700 text-xs mt-0.5">Upload your logo and (optionally) your card design PDF, three quick steps.</p>
        </div>
    </div>
    <a href="<?= getBasePath() ?>onboarding.php" class="flex-shrink-0 bg-amber-500 hover:bg-amber-600 text-white font-semibold px-4 py-2 rounded-lg text-sm transition-all whitespace-nowrap">
        Finish Setup
    </a>
</div>
<?php elseif ($hasEmployee && !$hasGeneratedCard && $onboardingCompleted && $currentRole !== 'super_admin'): ?>
<?php $batchExt = defined('COMPANY_ADMIN_BASE') ? '' : '.php'; ?>
<?php if (!$hasTemplate): ?>
<!-- Has employees, no template, point to template editor first -->
<div class="mb-6 rounded-2xl p-5 text-white shadow-lg flex items-center justify-between gap-4" id="generate-cards-nudge" style="background:linear-gradient(to right,#7c3aed,#6366f1);">
    <div class="flex items-center gap-4">
        <div class="w-11 h-11 rounded-full bg-white/20 flex items-center justify-center flex-shrink-0">
            <i class="fa-solid fa-paintbrush text-white text-lg"></i>
        </div>
        <div>
            <h3 class="font-bold"><?= htmlspecialchars(t('dashboard.design_template_h3')) ?></h3>
            <p class="text-purple-100 text-sm mt-0.5">You have <?= $employeeCount ?> employee<?= $employeeCount !== 1 ? 's' : '' ?> ready. Set up a card template and generate all cards in seconds.</p>
        </div>
    </div>
    <div class="flex items-center gap-3 flex-shrink-0">
        <a href="#template-editor" onclick="document.getElementById('generate-cards-nudge').remove()" class="bg-white text-purple-700 font-semibold px-4 py-2 rounded-lg text-sm hover:bg-purple-50 transition-all whitespace-nowrap">
            Design Template
        </a>
        <button onclick="document.getElementById('generate-cards-nudge').remove()" class="text-white/60 hover:text-white">
            <i class="fa-solid fa-xmark text-lg"></i>
        </button>
    </div>
</div>
<?php else: ?>
<!-- Has employees + template but no cards generated, generate nudge -->
<div class="mb-6 rounded-2xl p-5 text-white shadow-lg flex items-center justify-between gap-4" id="generate-cards-nudge" style="background:linear-gradient(to right,#16a34a,#10b981);">
    <div class="flex items-center gap-4">
        <div class="w-11 h-11 rounded-full bg-white/20 flex items-center justify-center flex-shrink-0">
            <i class="fa-solid fa-id-card text-white text-lg"></i>
        </div>
        <div>
            <h3 class="font-bold"><?= htmlspecialchars(t('dashboard.team_ready_h3')) ?></h3>
            <p class="text-green-100 text-sm mt-0.5">You have <?= $employeeCount ?> employee<?= $employeeCount !== 1 ? 's' : '' ?> set up. Generate all cards in one click.</p>
        </div>
    </div>
    <div class="flex items-center gap-3 flex-shrink-0">
        <a href="<?= getAdminBasePath() ?>batch_generate<?= $batchExt ?>" class="bg-white text-green-700 font-semibold px-4 py-2 rounded-lg text-sm hover:bg-green-50 transition-all whitespace-nowrap">
            Generate All Cards
        </a>
        <button onclick="document.getElementById('generate-cards-nudge').remove()" class="text-white/60 hover:text-white">
            <i class="fa-solid fa-xmark text-lg"></i>
        </button>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>

<?php
// Public portal share card, one link per tenant, employees use it to request
// their cards. Always visible to company admins so they can copy/share anytime.
if ($currentRole !== 'super_admin' && !empty($companySlug)):
    $portalShareUrl = getTenantUrl($companySlug, '/portal');
    $portalWaMsg = "Hi team, our Cardify business card portal is ready. Request your card here:\n" . $portalShareUrl;
    $portalWaHref = 'https://wa.me/?text=' . rawurlencode($portalWaMsg);
?>
<div class="mb-8 rounded-2xl overflow-hidden shadow-lg" id="portal-share-card">
    <div class="p-6 sm:p-7 text-white" style="background:linear-gradient(135deg, var(--tbrand,#009bc1) 0%, var(--tbrand-2,#005f78) 100%);">
        <div class="flex flex-col lg:flex-row lg:items-center gap-6">
            <div class="flex-1 min-w-0">
                <div class="flex items-center gap-3 mb-2">
                    <div class="w-10 h-10 rounded-xl bg-white/15 backdrop-blur flex items-center justify-center flex-shrink-0">
                        <i class="fa-solid fa-share-nodes text-white text-lg"></i>
                    </div>
                    <div>
                        <h3 class="font-bold text-lg leading-tight">Your public portal link</h3>
                        <p class="text-white/80 text-sm">Send this link to your team. They request a card, you approve and print.</p>
                    </div>
                </div>

                <div class="mt-4 flex flex-col sm:flex-row gap-2">
                    <div class="flex-1 bg-white/15 backdrop-blur rounded-lg px-3 py-2.5 flex items-center gap-2 min-w-0">
                        <i class="fa-solid fa-link text-white/70 text-xs flex-shrink-0"></i>
                        <input type="text" readonly value="<?= htmlspecialchars($portalShareUrl) ?>" id="portal-share-url"
                               class="flex-1 bg-transparent text-white text-sm font-mono tracking-tight outline-none min-w-0 truncate"
                               onclick="this.select()">
                    </div>
                    <button type="button" onclick="portalCopyShare()" id="portal-copy-btn"
                            class="bg-white font-semibold px-4 py-2.5 rounded-lg text-sm hover:bg-white/90 transition-all whitespace-nowrap flex items-center justify-center gap-2"
                            style="color: var(--tbrand,#007a99);">
                        <i class="fa-solid fa-copy text-xs"></i>
                        <span id="portal-copy-label">Copy link</span>
                    </button>
                    <a href="<?= htmlspecialchars($portalWaHref) ?>" target="_blank" rel="noopener"
                       class="bg-[#25D366] hover:bg-[#1ebe57] text-white font-semibold px-4 py-2.5 rounded-lg text-sm transition-all whitespace-nowrap flex items-center justify-center gap-2">
                        <i class="fa-brands fa-whatsapp"></i>
                        Share on WhatsApp
                    </a>
                    <a href="<?= htmlspecialchars($portalShareUrl) ?>" target="_blank" rel="noopener"
                       class="bg-white/10 hover:bg-white/20 text-white font-semibold px-4 py-2.5 rounded-lg text-sm transition-all whitespace-nowrap flex items-center justify-center gap-2">
                        <i class="fa-solid fa-up-right-from-square text-xs"></i>
                        Preview
                    </a>
                </div>
                <p class="text-white/70 text-xs mt-3">
                    <i class="fa-solid fa-lock-open"></i>
                    Open access &middot;
                    <a href="<?= getAdminBasePath() ?>settings<?= defined('COMPANY_ADMIN_BASE') ? '' : '.php' ?>" class="underline hover:text-white">Set a passcode</a>
                    if you want to gate it.
                </p>
            </div>

            <div class="lg:border-l lg:border-white/15 lg:pl-6 flex-shrink-0 flex items-center justify-center">
                <div class="bg-white p-2 rounded-lg">
                    <img src="https://api.qrserver.com/v1/create-qr-code/?size=140x140&data=<?= urlencode($portalShareUrl) ?>"
                         alt="Portal QR code" width="140" height="140" loading="lazy"
                         onerror="this.style.display='none'">
                </div>
            </div>
        </div>
    </div>
</div>
<script>
(function () {
    window.portalCopyShare = function () {
        var input = document.getElementById('portal-share-url');
        var label = document.getElementById('portal-copy-label');
        if (!input) return;
        try {
            input.select();
            input.setSelectionRange(0, 99999);
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(input.value);
            } else {
                document.execCommand('copy');
            }
            if (label) {
                var prev = label.textContent;
                label.textContent = 'Copied!';
                setTimeout(function () { label.textContent = prev; }, 1600);
            }
        } catch (e) { /* ignore */ }
    };
})();
</script>
<?php endif; ?>

<?php if (!$checklistAllDone && !$showWelcome && $currentRole !== 'super_admin'): ?>
<!-- Getting Started Checklist -->
<div class="mb-8 bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden" id="getting-started-card">
    <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100">
        <div class="flex items-center gap-3">
            <div class="w-8 h-8 rounded-lg bg-blue-100 flex items-center justify-center">
                <i class="fa-solid fa-list-check text-blue-600 text-sm"></i>
            </div>
            <div>
                <h3 class="font-semibold text-gray-900 text-sm"><?= htmlspecialchars(t('dashboard.getting_started')) ?></h3>
                <p class="text-xs text-gray-500"><?= $checklistDoneCount ?>/<?= count($checklistSteps) ?> steps complete</p>
            </div>
        </div>
        <div class="flex items-center gap-3">
            <!-- Progress bar -->
            <div class="w-32 h-1.5 bg-gray-100 rounded-full overflow-hidden">
                <div class="h-full bg-blue-500 rounded-full transition-all" style="width: <?= round($checklistDoneCount / count($checklistSteps) * 100) ?>%"></div>
            </div>
            <button onclick="document.getElementById('getting-started-card').remove()" class="text-gray-300 hover:text-gray-500 transition-colors" title="Dismiss">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
    </div>
    <div class="divide-y divide-gray-50">
        <?php foreach ($checklistSteps as $step): ?>
        <?php if ($step['done']): ?>
        <div class="flex items-center gap-4 px-6 py-3 bg-gray-50/50">
            <div class="w-6 h-6 rounded-full bg-green-100 flex items-center justify-center flex-shrink-0">
                <i class="fa-solid fa-check text-green-600 text-xs"></i>
            </div>
            <span class="text-sm text-gray-400 line-through"><?= htmlspecialchars($step['label']) ?></span>
        </div>
        <?php else: ?>
        <a href="<?= htmlspecialchars($step['url'] ?? '#') ?>" class="flex items-center gap-4 px-6 py-3 hover:bg-blue-50/50 transition-colors group">
            <div class="w-6 h-6 rounded-full border-2 border-gray-200 group-hover:border-blue-400 flex items-center justify-center flex-shrink-0 transition-colors">
            </div>
            <span class="text-sm font-medium text-gray-700 group-hover:text-blue-600 transition-colors"><?= htmlspecialchars($step['label']) ?></span>
            <i class="fa-solid fa-arrow-right text-xs text-gray-300 group-hover:text-blue-400 ml-auto transition-colors"></i>
        </a>
        <?php endif; ?>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($referralCode && $referralShareUrl): ?>
<!-- Referral Share Card (BHD-234) -->
<div class="mb-8 rounded-2xl overflow-hidden shadow-lg relative" id="referral-share-card">
    <div class="p-6 sm:p-7 text-white" style="background: linear-gradient(135deg, #009bc1 0%, #007a99 60%, #005f78 100%);">
        <div class="flex flex-col lg:flex-row lg:items-center gap-6">
            <div class="flex-1 min-w-0">
                <div class="flex items-center gap-3 mb-2">
                    <div class="w-10 h-10 rounded-xl bg-white/15 backdrop-blur flex items-center justify-center flex-shrink-0">
                        <i class="fa-solid fa-gift text-white text-lg"></i>
                    </div>
                    <div>
                        <h3 class="font-bold text-lg leading-tight"><?= htmlspecialchars(t('dashboard.refer_h3')) ?></h3>
                        <p class="text-white/80 text-sm">Send your link to a friend. When they upgrade to paid, you get 3 months on us.</p>
                    </div>
                </div>

                <div class="mt-4 flex flex-col sm:flex-row gap-2">
                    <div class="flex-1 bg-white/15 backdrop-blur rounded-lg px-3 py-2.5 flex items-center gap-2 min-w-0">
                        <i class="fa-solid fa-link text-white/70 text-xs flex-shrink-0"></i>
                        <input type="text" readonly value="<?= htmlspecialchars($referralShareUrl) ?>" id="bhd234-ref-url"
                               class="flex-1 bg-transparent text-white text-sm font-mono tracking-tight outline-none min-w-0 truncate"
                               onclick="this.select()">
                    </div>
                    <button type="button" onclick="bhd234CopyRef()" id="bhd234-copy-btn"
                            class="bg-white font-semibold px-4 py-2.5 rounded-lg text-sm hover:bg-white/90 transition-all whitespace-nowrap flex items-center justify-center gap-2"
                            style="color: #007a99;">
                        <i class="fa-solid fa-copy text-xs"></i>
                        <span id="bhd234-copy-label">Copy link</span>
                    </button>
                    <a href="<?= htmlspecialchars($referralWhatsAppHref) ?>" target="_blank" rel="noopener"
                       class="bg-[#25D366] hover:bg-[#1ebe57] text-white font-semibold px-4 py-2.5 rounded-lg text-sm transition-all whitespace-nowrap flex items-center justify-center gap-2">
                        <i class="fa-brands fa-whatsapp"></i>
                        Share on WhatsApp
                    </a>
                </div>
            </div>

            <div class="lg:border-l lg:border-white/15 lg:pl-6 flex lg:flex-col gap-6 lg:gap-3 justify-start lg:justify-center flex-shrink-0">
                <div class="text-center lg:text-left">
                    <p class="text-3xl font-bold leading-none"><?= (int)$referralStats['signups'] ?></p>
                    <p class="text-white/80 text-xs mt-1">signed up</p>
                </div>
                <div class="text-center lg:text-left">
                    <p class="text-3xl font-bold leading-none"><?= (int)$referralStats['paid'] ?></p>
                    <p class="text-white/80 text-xs mt-1">went paid</p>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
(function () {
    window.bhd234CopyRef = function () {
        var input = document.getElementById('bhd234-ref-url');
        var label = document.getElementById('bhd234-copy-label');
        if (!input) return;
        try {
            input.select();
            input.setSelectionRange(0, 99999);
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(input.value);
            } else {
                document.execCommand('copy');
            }
            if (label) {
                var prev = label.textContent;
                label.textContent = 'Copied!';
                setTimeout(function () { label.textContent = prev; }, 1600);
            }
        } catch (e) { /* ignore */ }
    };
})();
</script>
<?php endif; ?>

<!-- Stats Cards -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
    <div class="bg-white rounded-xl p-6 border border-gray-100 shadow-sm hover:shadow-md transition-shadow">
        <div class="flex items-center gap-4">
            <div class="w-12 h-12 rounded-xl bg-blue-100 flex items-center justify-center">
                <i class="fa-solid fa-id-card text-blue-600 text-xl"></i>
            </div>
            <div>
                <p class="text-3xl font-bold text-gray-900"><?php echo count($frontTemplates); ?></p>
                <p class="text-gray-500 text-sm"><?= htmlspecialchars(t('dashboard.kpi_front_templates')) ?></p>
            </div>
        </div>
    </div>
    
    <div class="bg-white rounded-xl p-6 border border-gray-100 shadow-sm hover:shadow-md transition-shadow">
        <div class="flex items-center gap-4">
            <div class="w-12 h-12 rounded-xl bg-purple-100 flex items-center justify-center">
                <i class="fa-solid fa-clone text-purple-600 text-xl"></i>
            </div>
            <div>
                <p class="text-3xl font-bold text-gray-900"><?php echo count($backTemplates); ?></p>
                <p class="text-gray-500 text-sm"><?= htmlspecialchars(t('dashboard.kpi_back_templates')) ?></p>
            </div>
        </div>
    </div>
    
    <div class="bg-white rounded-xl p-6 border border-gray-100 shadow-sm hover:shadow-md transition-shadow">
        <div class="flex items-center gap-4">
            <div class="w-12 h-12 rounded-xl bg-green-100 flex items-center justify-center">
                <i class="fa-solid fa-users text-green-600 text-xl"></i>
            </div>
            <div>
                <p class="text-3xl font-bold text-gray-900"><?php echo $employeeCount; ?></p>
                <p class="text-gray-500 text-sm"><?= htmlspecialchars(t('dashboard.kpi_employees')) ?></p>
            </div>
        </div>
    </div>
    
    <div class="bg-white rounded-xl p-6 border border-gray-100 shadow-sm hover:shadow-md transition-shadow">
        <div class="flex items-center gap-4">
            <div class="w-12 h-12 rounded-xl bg-amber-100 flex items-center justify-center">
                <i class="fa-solid fa-file-lines text-amber-600 text-xl"></i>
            </div>
            <div>
                <p class="text-3xl font-bold text-gray-900"><?php echo $generatedCount; ?></p>
                <p class="text-gray-500 text-sm"><?= htmlspecialchars(t('dashboard.kpi_generated_cards')) ?></p>
            </div>
        </div>
    </div>
</div>

<!-- Card Views Analytics Widget -->
<?php if ($viewsAllTime > 0 || $generatedCount > 0): ?>
<?php
    $_analyticsUrl = getAdminBasePath() . 'analytics' . ((defined('COMPANY_ADMIN_BASE') || !empty($_SESSION['company_slug'])) ? '' : '.php');
    $maxDaily = max(1, max($dailyViews7d ?: [0]));
?>
<div class="bg-white rounded-xl border border-gray-100 shadow-sm p-6 mb-8">
    <div class="flex items-center justify-between mb-4">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-cyan-100 flex items-center justify-center">
                <i class="fa-solid fa-chart-line text-cyan-600 text-lg"></i>
            </div>
            <div>
                <h3 class="font-semibold text-gray-900 text-sm"><?= htmlspecialchars(t('dashboard.card_views')) ?></h3>
                <p class="text-xs text-gray-500"><?= htmlspecialchars(t('dashboard.views_help')) ?></p>
            </div>
        </div>
        <a href="<?= htmlspecialchars($_analyticsUrl) ?>" class="text-sm text-blue-600 hover:text-blue-700 font-medium flex items-center gap-1">
            <?= htmlspecialchars(t('dashboard.full_analytics')) ?> <i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i>
        </a>
    </div>
    <!-- Period stats -->
    <div class="grid grid-cols-3 gap-4 mb-5">
        <div class="text-center p-3 rounded-lg bg-gray-50">
            <p class="text-2xl font-bold text-gray-900"><?= number_format($views7d) ?></p>
            <p class="text-xs text-gray-500 mt-0.5"><?= htmlspecialchars(t('dashboard.period_7d')) ?></p>
        </div>
        <div class="text-center p-3 rounded-lg bg-gray-50">
            <p class="text-2xl font-bold text-gray-900"><?= number_format($views30d) ?></p>
            <p class="text-xs text-gray-500 mt-0.5"><?= htmlspecialchars(t('dashboard.period_30d')) ?></p>
        </div>
        <div class="text-center p-3 rounded-lg bg-gray-50">
            <p class="text-2xl font-bold text-gray-900"><?= number_format($viewsAllTime) ?></p>
            <p class="text-xs text-gray-500 mt-0.5"><?= htmlspecialchars(t('dashboard.period_all')) ?></p>
        </div>
    </div>
    <!-- 7-day bar chart -->
    <div class="flex items-end gap-1.5 h-20">
        <?php foreach ($dailyViews7d as $date => $count): ?>
        <?php $pct = $maxDaily > 0 ? max(4, round($count / $maxDaily * 100)) : 4; ?>
        <div class="flex-1 flex flex-col items-center gap-1 group relative">
            <div class="w-full rounded-t bg-cyan-500 hover:bg-cyan-600 transition-colors cursor-default" style="height: <?= $pct ?>%"
                 title="<?= date('D, M j', strtotime($date)) ?>: <?= number_format($count) ?> view<?= $count !== 1 ? 's' : '' ?>"></div>
            <span class="text-[10px] text-gray-400 leading-none"><?= date('D', strtotime($date)) ?></span>
        </div>
        <?php endforeach; ?>
    </div>
    <?php if ($viewsAllTime === 0): ?>
    <p class="text-center text-sm text-gray-400 mt-3"><?= htmlspecialchars(t('dashboard.views_empty')) ?></p>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($isFreePlan && $currentRole !== 'super_admin'):
    $empPct  = $employeeCount > 0 ? min(100, round($employeeCount / 5 * 100)) : 0;
    $cardPct = $cardsThisMonth > 0 ? min(100, round($cardsThisMonth / 10 * 100)) : 0;
    $nearLimit = ($employeeCount >= 4 || $cardsThisMonth >= 8);
    $_billingUrl = getAdminBasePath() . 'billing' . (defined('COMPANY_ADMIN_BASE') || !empty($_SESSION['company_slug']) ? '' : '.php');
?>
<!-- Free Plan Usage Indicator -->
<div class="bg-white rounded-xl border <?= $nearLimit ? 'border-amber-300' : 'border-gray-100' ?> shadow-sm p-4 mb-6 flex flex-col sm:flex-row sm:items-center gap-4">
    <div class="flex-1 min-w-0">
        <p class="text-sm font-semibold text-gray-700 mb-2 flex items-center gap-2">
            <i class="fa-solid fa-gauge-simple text-gray-400" aria-hidden="true"></i>
            <?= htmlspecialchars(t('dashboard.free_plan_usage')) ?>
            <?php if ($nearLimit): ?>
            <span class="text-xs bg-amber-100 text-amber-700 px-2 py-0.5 rounded-full font-medium"><?= htmlspecialchars(t('dashboard.near_limit')) ?></span>
            <?php endif; ?>
        </p>
        <div class="flex flex-col sm:flex-row gap-4">
            <div class="flex-1">
                <div class="flex justify-between text-xs text-gray-500 mb-1">
                    <span><?= htmlspecialchars(t('dashboard.kpi_employees')) ?></span>
                    <span class="font-medium <?= $employeeCount >= 5 ? 'text-red-600' : ($employeeCount >= 4 ? 'text-amber-600' : 'text-gray-600') ?>"><?= $employeeCount ?>/5</span>
                </div>
                <div class="h-1.5 bg-gray-100 rounded-full overflow-hidden">
                    <div class="h-full <?= $employeeCount >= 5 ? 'bg-red-500' : ($employeeCount >= 4 ? 'bg-amber-400' : 'bg-blue-500') ?> rounded-full transition-all" style="width:<?= $empPct ?>%"></div>
                </div>
            </div>
            <div class="flex-1">
                <div class="flex justify-between text-xs text-gray-500 mb-1">
                    <span><?= htmlspecialchars(t('dashboard.cards_this_month')) ?></span>
                    <span class="font-medium <?= $cardsThisMonth >= 10 ? 'text-red-600' : ($cardsThisMonth >= 8 ? 'text-amber-600' : 'text-gray-600') ?>"><?= $cardsThisMonth ?>/10</span>
                </div>
                <div class="h-1.5 bg-gray-100 rounded-full overflow-hidden">
                    <div class="h-full <?= $cardsThisMonth >= 10 ? 'bg-red-500' : ($cardsThisMonth >= 8 ? 'bg-amber-400' : 'bg-blue-500') ?> rounded-full transition-all" style="width:<?= $cardPct ?>%"></div>
                </div>
            </div>
        </div>
    </div>
    <a href="<?= htmlspecialchars($_billingUrl) ?>" class="flex-shrink-0 inline-flex items-center gap-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold rounded-lg transition-colors whitespace-nowrap">
        <i class="fa-solid fa-rocket"></i> Upgrade to Pro
    </a>
</div>
<?php endif; ?>

<!-- Quick Actions -->
<div class="grid md:grid-cols-3 gap-6 mb-8">
    <a href="employees.php?action=add" class="bg-white rounded-xl p-6 border border-gray-100 shadow-sm hover:shadow-md hover:border-blue-200 transition-all group">
        <div class="flex items-center gap-4">
            <div class="w-12 h-12 rounded-xl bg-blue-50 group-hover:bg-blue-100 flex items-center justify-center transition-colors">
                <i class="fa-solid fa-user-plus text-blue-600 text-xl"></i>
            </div>
            <div>
                <p class="font-semibold text-gray-900"><?= htmlspecialchars(t('dashboard.qa_add_employee')) ?></p>
                <p class="text-gray-500 text-sm"><?= htmlspecialchars(t('dashboard.qa_add_employee_sub')) ?></p>
            </div>
        </div>
    </a>
    
    <a href="batch_generate.php" class="bg-white rounded-xl p-6 border border-gray-100 shadow-sm hover:shadow-md hover:border-green-200 transition-all group">
        <div class="flex items-center gap-4">
            <div class="w-12 h-12 rounded-xl bg-green-50 group-hover:bg-green-100 flex items-center justify-center transition-colors">
                <i class="fa-solid fa-wand-magic-sparkles text-green-600 text-xl"></i>
            </div>
            <div>
                <p class="font-semibold text-gray-900"><?= htmlspecialchars(t('dashboard.qa_batch_title')) ?></p>
                <p class="text-gray-500 text-sm"><?= htmlspecialchars(t('dashboard.qa_batch_sub')) ?></p>
            </div>
        </div>
    </a>
    
    <a href="share.php" class="bg-white rounded-xl p-6 border border-gray-100 shadow-sm hover:shadow-md hover:border-purple-200 transition-all group">
        <div class="flex items-center gap-4">
            <div class="w-12 h-12 rounded-xl bg-purple-50 group-hover:bg-purple-100 flex items-center justify-center transition-colors">
                <i class="fa-solid fa-share-nodes text-purple-600 text-xl"></i>
            </div>
            <div>
                <p class="font-semibold text-gray-900"><?= htmlspecialchars(t('dashboard.qa_share_title')) ?></p>
                <p class="text-gray-500 text-sm"><?= htmlspecialchars(t('dashboard.qa_share_sub')) ?></p>
            </div>
        </div>
    </a>
</div>

<!-- Template Editor Section -->
<div id="template-editor" x-data="templateEditor()" x-init="init()">
    <!-- Section Header with Tabs -->
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm mb-6">
        <div class="flex items-center justify-between p-4 border-b border-gray-100">
            <h3 class="font-semibold text-gray-900 flex items-center gap-2">
                <i class="fa-solid fa-palette text-blue-600"></i>
                Card Designs
            </h3>
            
            <button 
                @click="showAddModal = !showAddModal"
                class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition-colors flex items-center gap-2"
            >
                <i class="fa-solid fa-plus"></i>
                <span x-text="showAddModal ? 'Cancel' : 'New Card Design'"></span>
            </button>
        </div>
        
        <!-- Add Card Design Form -->
        <div x-show="showAddModal" x-cloak class="p-6 border-b border-gray-100 bg-gray-50">
            <h3 class="text-lg font-semibold text-gray-900 mb-4"><?= htmlspecialchars(t('dashboard.create_new_design')) ?></h3>
            <p class="text-sm text-gray-600 mb-4">This will create both front and back templates for your card. You can upload background designs for each side.</p>
            <form @submit.prevent="addTemplatePair()">
                <div class="grid md:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Design Name</label>
                        <input type="text" x-model="newTemplate.name" required 
                               class="w-full px-4 py-2.5 bg-white border border-gray-200 rounded-lg text-gray-900 focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20" 
                               placeholder="e.g., Modern Blue">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Card Size</label>
                        <select x-model="cardSize" @change="changeCardSize()"
                                class="w-full px-4 py-2.5 bg-white border border-gray-200 rounded-lg text-gray-900 focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                            <option value="standard">US Standard (3.5" × 2")</option>
                            <option value="eu">European (85 × 55 mm)</option>
                            <option value="japanese">Japanese (91 × 55 mm)</option>
                            <option value="square">Square (2.5" × 2.5")</option>
                            <option value="mini">Mini/Slim (70 × 28 mm)</option>
                            <option value="custom">Custom Size...</option>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Orientation</label>
                        <select x-model="cardOrientation" @change="changeCardSize()"
                                class="w-full px-4 py-2.5 bg-white border border-gray-200 rounded-lg text-gray-900 focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                            <option value="landscape">Landscape (Horizontal)</option>
                            <option value="portrait">Portrait (Vertical)</option>
                        </select>
                    </div>
                </div>
                
                <!-- Custom Size (shown when custom selected) -->
                <div x-show="cardSize === 'custom'" x-cloak class="mt-4 p-4 bg-blue-50 rounded-lg border border-blue-100">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Custom Dimensions</label>
                    <div class="flex items-center gap-3">
                        <div class="flex items-center gap-2">
                            <span class="text-sm text-gray-600">Width:</span>
                            <input type="number" x-model.number="customWidth" @change="changeCardSize()"
                                   :step="customUnit === 'mm' ? 1 : 0.1"
                                   :min="customUnit === 'mm' ? 10 : 0.5"
                                   :max="customUnit === 'mm' ? 300 : 12"
                                   class="w-20 px-3 py-2 bg-white border border-gray-200 rounded-lg text-sm">
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="text-sm text-gray-600">Height:</span>
                            <input type="number" x-model.number="customHeight" @change="changeCardSize()"
                                   :step="customUnit === 'mm' ? 1 : 0.1"
                                   :min="customUnit === 'mm' ? 10 : 0.5"
                                   :max="customUnit === 'mm' ? 300 : 12"
                                   class="w-20 px-3 py-2 bg-white border border-gray-200 rounded-lg text-sm">
                        </div>
                        <select x-model="customUnit" @change="changeCardSize()"
                                class="px-3 py-2 bg-white border border-gray-200 rounded-lg text-sm">
                            <option value="in">inches</option>
                            <option value="mm">mm</option>
                        </select>
                    </div>
                </div>
                
                <!-- Quick Start: Background Color -->
                <div class="mt-4 p-4 bg-gray-50 rounded-lg border border-gray-200">
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        <i class="fa-solid fa-palette mr-1"></i> Quick Start, Pick a Background Color
                        <span class="text-xs font-normal text-gray-500 ml-1">(generates a solid-color card instantly)</span>
                    </label>
                    <div class="flex items-center gap-3 flex-wrap">
                        <input type="color" x-model="newTemplate.bgColor" value="#1e3a5f"
                               class="w-10 h-10 rounded cursor-pointer border border-gray-300">
                        <div class="flex gap-2 flex-wrap">
                            <template x-for="c in ['#1e3a5f','#0f172a','#1d4ed8','#065f46','#7c3aed','#9f1239','#78350f','#374151']">
                                <button type="button" class="w-7 h-7 rounded-full border-2 border-white shadow hover:scale-110 transition-transform"
                                        :style="'background:'+c" @click="newTemplate.bgColor = c"></button>
                            </template>
                        </div>
                        <span class="text-xs text-gray-400">or upload your own design below</span>
                    </div>
                </div>

                <!-- Front and Back Image Uploads -->
                <div class="mt-3 grid md:grid-cols-2 gap-4">
                    <div class="p-4 bg-blue-50 rounded-lg border border-blue-100">
                        <label class="block text-sm font-medium text-blue-800 mb-2">
                            <i class="fa-solid fa-id-card mr-1"></i> Front Background (Optional)
                        </label>
                        <input type="file" accept="image/*,.svg,.pdf" @change="handleNewFrontImage($event)"
                               class="w-full px-3 py-2 bg-white border border-blue-200 rounded-lg text-gray-900 text-sm file:mr-3 file:py-1 file:px-2 file:rounded file:border-0 file:text-xs file:bg-blue-100 file:text-blue-700">
                        <p class="text-xs text-blue-600 mt-2">Overrides color above • Can be added later</p>
                    </div>

                    <div class="p-4 bg-purple-50 rounded-lg border border-purple-100">
                        <label class="block text-sm font-medium text-purple-800 mb-2">
                            <i class="fa-solid fa-clone mr-1"></i> Back Background (Optional)
                        </label>
                        <input type="file" accept="image/*,.svg,.pdf" @change="handleNewBackImage($event)"
                               class="w-full px-3 py-2 bg-white border border-purple-200 rounded-lg text-gray-900 text-sm file:mr-3 file:py-1 file:px-2 file:rounded file:border-0 file:text-xs file:bg-purple-100 file:text-purple-700">
                        <p class="text-xs text-purple-600 mt-2">Overrides color above • Can be added later</p>
                    </div>
                </div>
                
                <div class="mt-3 flex flex-wrap items-center gap-4 text-xs text-gray-500">
                    <span><i class="fa-solid fa-ruler mr-1"></i><span x-text="getSizeDetails().inches"></span> / <span x-text="getSizeDetails().mm"></span></span>
                    <span><i class="fa-solid fa-image mr-1"></i><span x-text="getSizeDetails().pixels"></span> @ <span x-text="dpi"></span>dpi</span>
                </div>
                
                <div class="flex items-center justify-end gap-3 mt-4">
                    <button type="button" @click="showAddModal = false" class="px-4 py-2 text-gray-600 hover:text-gray-900 transition-colors">
                        Cancel
                    </button>
                    <button type="submit" class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition-colors">
                        Create Card Design
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Templates Grid -->
    <div class="grid lg:grid-cols-3 gap-6">
        <!-- Card Designs List -->
        <div class="bg-white rounded-xl border border-gray-100 shadow-sm">
            <div class="p-4 border-b border-gray-100">
                <h3 class="font-semibold text-gray-900"><?= htmlspecialchars(t('dashboard.card_designs')) ?></h3>
            </div>
            
            <div class="p-4 space-y-3" x-show="getCardDesigns().length > 0">
                <template x-for="design in getCardDesigns()" :key="design.pair_id || design.front?.id || design.back?.id">
                    <div 
                        @click="selectCardDesign(design)"
                        :class="selectedPairId === design.pair_id ? 'border-blue-300 bg-blue-50' : 'border-gray-200 bg-white hover:bg-gray-50'"
                        class="p-4 rounded-xl border cursor-pointer transition-all"
                    >
                        <div class="flex items-center justify-between mb-3">
                            <div>
                                <p class="font-medium text-gray-900" x-text="design.name"></p>
                                <p class="text-xs text-gray-500">
                                    <span x-show="design.front" class="text-blue-600"><i class="fa-solid fa-check-circle mr-1"></i>Front</span>
                                    <span x-show="design.front && design.back" class="mx-1">|</span>
                                    <span x-show="design.back" class="text-purple-600"><i class="fa-solid fa-check-circle mr-1"></i>Back</span>
                                </p>
                            </div>
                            <div class="flex items-center gap-2">
                                <template x-if="isDesignActive(design)">
                                    <span class="px-2 py-1 text-xs bg-green-100 text-green-700 rounded-full font-medium">Active</span>
                                </template>
                                <button @click.stop="deleteCardDesign(design)" class="p-2 text-gray-400 hover:text-red-600 transition-colors rounded-lg hover:bg-red-50">
                                    <i class="fa-solid fa-trash-can text-sm"></i>
                                </button>
                            </div>
                        </div>
                        <!-- Preview thumbnails -->
                        <div class="flex gap-2">
                            <div class="flex-1">
                                <div class="text-xs text-gray-500 mb-1">Front</div>
                                <div class="w-full h-12 rounded bg-gray-100 overflow-hidden border border-gray-200">
                                    <template x-if="design.front && design.front.backgroundImage">
                                        <img :src="getBackgroundUrl(design.front)" 
                                             class="w-full h-full object-cover"
                                             @error="$event.target.style.display='none'">
                                    </template>
                                    <div x-show="!design.front || !design.front.backgroundImage" class="w-full h-full flex items-center justify-center text-gray-400">
                                        <i class="fa-solid fa-image text-xs"></i>
                                    </div>
                                </div>
                            </div>
                            <div class="flex-1">
                                <div class="text-xs text-gray-500 mb-1">Back</div>
                                <div class="w-full h-12 rounded bg-gray-100 overflow-hidden border border-gray-200">
                                    <template x-if="design.back && design.back.backgroundImage">
                                        <img :src="getBackgroundUrl(design.back)" 
                                             class="w-full h-full object-cover"
                                             @error="$event.target.style.display='none'">
                                    </template>
                                    <div x-show="!design.back || !design.back.backgroundImage" class="w-full h-full flex items-center justify-center text-gray-400">
                                        <i class="fa-solid fa-image text-xs"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
            
            <div x-show="getCardDesigns().length === 0" class="p-12 text-center text-gray-500">
                <i class="fa-solid fa-palette text-4xl mb-4 opacity-30"></i>
                <p class="font-medium text-gray-700"><?= htmlspecialchars(t('dashboard.no_designs')) ?></p>
                <p class="text-sm mt-1 mb-5"><?= htmlspecialchars(t('dashboard.no_designs_cta')) ?></p>
                <button @click="showAddModal = true; $nextTick(() => document.getElementById('template-editor').scrollIntoView({behavior:'smooth'}))"
                    class="inline-flex items-center gap-2 px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-lg text-sm transition-colors">
                    <i class="fa-solid fa-plus"></i>
                    Create Card Design
                </button>
            </div>
        </div>
        
        <!-- Canvas Editor -->
        <div class="lg:col-span-2 bg-white rounded-xl border border-gray-100 shadow-sm" x-show="selectedTemplate" x-cloak>
            <!-- Editor Header -->
            <div class="p-4 border-b border-gray-100">
                <div class="flex items-center justify-between mb-3">
                    <div class="flex items-center gap-3">
                        <h3 class="font-semibold text-gray-900" x-text="selectedTemplate ? selectedTemplate.name : <?= htmlspecialchars(json_encode(t('dashboard.edit_template')), ENT_QUOTES) ?>"></h3>
                        <!-- Front/Back Toggle -->
                        <div class="flex items-center bg-gray-100 rounded-lg p-1">
                            <button 
                                @click="switchToSide('front')"
                                :class="activeTab === 'front' ? 'bg-white text-blue-700 shadow-sm' : 'text-gray-600 hover:text-gray-900'"
                                class="px-3 py-1 rounded-md text-sm font-medium transition-all"
                            >
                                <i class="fa-solid fa-id-card mr-1"></i>Front
                            </button>
                            <button 
                                @click="switchToSide('back')"
                                :class="activeTab === 'back' ? 'bg-white text-purple-700 shadow-sm' : 'text-gray-600 hover:text-gray-900'"
                                class="px-3 py-1 rounded-md text-sm font-medium transition-all"
                            >
                                <i class="fa-solid fa-clone mr-1"></i>Back
                            </button>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <!-- Sample Profile Switcher -->
                        <button @click="nextSampleProfile()" 
                                class="px-3 py-1.5 text-sm bg-amber-50 text-amber-700 rounded-lg hover:bg-amber-100 transition-colors font-medium"
                                title="Cycle through sample profiles to test different text lengths">
                            <i class="fa-solid fa-shuffle mr-1"></i>
                            <span class="hidden sm:inline">Sample</span>
                            <span class="text-xs opacity-75" x-text="'(' + (currentProfileIndex + 1) + '/' + sampleProfiles.length + ')'"></span>
                        </button>
                        <button @click="exportPNG()" class="px-3 py-1.5 text-sm bg-purple-50 text-purple-700 rounded-lg hover:bg-purple-100 transition-colors font-medium">
                            <i class="fa-solid fa-image mr-1"></i>PNG
                        </button>
                        <button @click="exportBestPDF()" class="px-3 py-1.5 text-sm bg-red-50 text-red-700 rounded-lg hover:bg-red-100 transition-colors font-medium" title="Download as PDF">
                            <i class="fa-solid fa-file-pdf mr-1"></i>PDF
                        </button>
                        <button @click="setActiveTemplate()" class="px-3 py-1.5 text-sm bg-green-50 text-green-700 rounded-lg hover:bg-green-100 transition-colors font-medium">
                            <i class="fa-solid fa-check mr-1"></i>Set Active
                        </button>
                        <button @click="setAsCompanyDefault()" class="px-3 py-1.5 text-sm bg-amber-50 text-amber-700 rounded-lg hover:bg-amber-100 transition-colors font-medium">
                            <i class="fa-solid fa-building mr-1"></i><?= htmlspecialchars(t('dashboard.set_default_cta')) ?>
                        </button>
                        <button @click="saveTemplate()" class="px-3 py-1.5 text-sm bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors font-medium">
                            <i class="fa-solid fa-floppy-disk mr-1"></i>Save
                        </button>
                    </div>
                </div>
                
                <!-- Background Image Upload -->
                <div class="mb-3 p-3 rounded-lg" :class="activeTab === 'front' ? 'bg-blue-50 border border-blue-100' : 'bg-purple-50 border border-purple-100'">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <span class="text-sm font-medium" :class="activeTab === 'front' ? 'text-blue-800' : 'text-purple-800'">
                                <i class="fa-solid fa-image mr-1"></i>
                                <span x-text="activeTab === 'front' ? 'Front Background' : 'Back Background'"></span>
                            </span>
                            <span x-show="selectedTemplate && selectedTemplate.backgroundImage" class="text-xs text-gray-500">
                                <i class="fa-solid fa-check-circle text-green-500 mr-1"></i>Image set
                            </span>
                            <span x-show="!selectedTemplate || !selectedTemplate.backgroundImage" class="text-xs text-gray-500">
                                <i class="fa-solid fa-exclamation-circle text-amber-500 mr-1"></i>No image
                            </span>
                        </div>
                        <div class="flex items-center gap-1.5 flex-wrap">
                            <button type="button"
                                    x-show="selectedTemplate && selectedTemplate.backgroundImage"
                                    @click="toggleBackgroundLock()"
                                    class="px-2.5 py-1.5 text-xs rounded-lg font-medium bg-gray-100 text-gray-700 hover:bg-gray-200 transition-colors"
                                    :title="backgroundLocked ? 'Unlock to drag/resize the background' : 'Lock so clicks go to text, not the background'">
                                <i class="fa-solid" :class="backgroundLocked ? 'fa-lock' : 'fa-lock-open'"></i>
                                <span class="ml-1" x-text="backgroundLocked ? 'Locked' : 'Unlocked'"></span>
                            </button>
                            <button type="button"
                                    x-show="selectedTemplate && selectedTemplate.backgroundImage"
                                    @click="centerBackground()"
                                    class="px-2.5 py-1.5 text-xs rounded-lg font-medium bg-gray-100 text-gray-700 hover:bg-gray-200 transition-colors"
                                    title="Center the background on the card">
                                <i class="fa-solid fa-crosshairs"></i>
                            </button>
                            <button type="button"
                                    x-show="selectedTemplate && selectedTemplate.backgroundImage"
                                    @click="resetBackgroundFit()"
                                    class="px-2.5 py-1.5 text-xs rounded-lg font-medium bg-gray-100 text-gray-700 hover:bg-gray-200 transition-colors"
                                    title="Snap the background back to fill the card">
                                <i class="fa-solid fa-expand mr-1"></i>Fit
                            </button>
                            <label class="px-3 py-1.5 text-sm rounded-lg font-medium cursor-pointer transition-colors"
                                   :class="activeTab === 'front' ? 'bg-blue-100 text-blue-700 hover:bg-blue-200' : 'bg-purple-100 text-purple-700 hover:bg-purple-200'">
                                <i class="fa-solid fa-upload mr-1"></i>
                                <span x-text="selectedTemplate && selectedTemplate.backgroundImage ? 'Change' : 'Upload'"></span>
                                <input type="file" accept="image/*,.svg,.pdf" @change="uploadBackground($event)" class="hidden">
                            </label>
                        </div>
                    </div>
                </div>
                
                <!-- Card Size & Orientation Controls -->
                <div class="bg-gray-50 rounded-lg p-3 space-y-3">
                    <!-- Row 1: Size, Orientation, and Bleed -->
                    <div class="flex flex-wrap items-center gap-3">
                        <!-- Size Preset -->
                        <div class="flex items-center gap-2">
                            <label class="text-sm font-medium text-gray-600">Size:</label>
                            <select x-model="cardSize" @change="changeCardSize()" 
                                    class="px-3 py-1.5 bg-white border border-gray-200 rounded-lg text-sm text-gray-700 focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                                <option value="standard">US Standard (3.5" × 2")</option>
                                <option value="eu">European (85 × 55 mm)</option>
                                <option value="japanese">Japanese (91 × 55 mm)</option>
                                <option value="square">Square (2.5" × 2.5")</option>
                                <option value="mini">Mini/Slim (2.75" × 1.1")</option>
                                <option value="custom">Custom Size...</option>
                            </select>
                        </div>
                        
                        <!-- Orientation Toggle -->
                        <div class="flex items-center gap-2">
                            <div class="flex bg-white border border-gray-200 rounded-lg overflow-hidden">
                                <button @click="setOrientation('landscape')" 
                                        :class="cardOrientation === 'landscape' ? 'bg-blue-50 text-blue-700' : 'text-gray-500 hover:bg-gray-50'"
                                        class="px-3 py-1.5 text-sm font-medium transition-colors flex items-center gap-1">
                                    <i class="fa-solid fa-arrows-left-right text-xs"></i>
                                    <span class="hidden sm:inline">Landscape</span>
                                </button>
                                <button @click="setOrientation('portrait')" 
                                        :class="cardOrientation === 'portrait' ? 'bg-blue-50 text-blue-700' : 'text-gray-500 hover:bg-gray-50'"
                                        class="px-3 py-1.5 text-sm font-medium transition-colors flex items-center gap-1 border-l border-gray-200">
                                    <i class="fa-solid fa-arrows-up-down text-xs"></i>
                                    <span class="hidden sm:inline">Portrait</span>
                                </button>
                            </div>
                        </div>
                        
                        <!-- Bleed Toggle -->
                        <div class="flex items-center gap-2">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" x-model="bleedEnabled" @change="changeCardSize()"
                                       class="w-4 h-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                <span class="text-sm font-medium text-gray-600">Bleed</span>
                            </label>
                            <template x-if="bleedEnabled">
                                <div class="flex items-center gap-1">
                                    <input type="number" x-model.number="bleedSize" @change="changeCardSize()"
                                           step="0.01" min="0" max="0.5"
                                           class="w-16 px-2 py-1 bg-white border border-gray-200 rounded text-sm text-gray-700">
                                    <select x-model="bleedUnit" @change="changeCardSize()"
                                            class="px-2 py-1 bg-white border border-gray-200 rounded text-sm text-gray-700">
                                        <option value="in">in</option>
                                        <option value="mm">mm</option>
                                    </select>
                                </div>
                            </template>
                        </div>
                    </div>
                    
                    <!-- Row 2: Custom Size Inputs (shown when custom selected) -->
                    <div x-show="cardSize === 'custom'" x-cloak class="flex flex-wrap items-center gap-3 pt-2 border-t border-gray-200">
                        <span class="text-sm font-medium text-gray-600">Custom:</span>
                        <div class="flex items-center gap-1">
                            <input type="number" x-model.number="customWidth" @change="changeCardSize()"
                                   :step="customUnit === 'mm' ? 1 : 0.1"
                                   :min="customUnit === 'mm' ? 10 : 0.5"
                                   :max="customUnit === 'mm' ? 300 : 12"
                                   class="w-20 px-2 py-1.5 bg-white border border-gray-200 rounded-lg text-sm text-gray-700">
                            <span class="text-gray-400">×</span>
                            <input type="number" x-model.number="customHeight" @change="changeCardSize()"
                                   :step="customUnit === 'mm' ? 1 : 0.1"
                                   :min="customUnit === 'mm' ? 10 : 0.5"
                                   :max="customUnit === 'mm' ? 300 : 12"
                                   class="w-20 px-2 py-1.5 bg-white border border-gray-200 rounded-lg text-sm text-gray-700">
                            <select x-model="customUnit" @change="changeCardSize()"
                                    class="px-2 py-1.5 bg-white border border-gray-200 rounded-lg text-sm text-gray-700">
                                <option value="in">inches</option>
                                <option value="mm">mm</option>
                            </select>
                        </div>
                        <div class="text-xs text-gray-500">
                            <template x-if="customUnit === 'in'">
                                <span>= <span x-text="Math.round(customWidth * 25.4)"></span> × <span x-text="Math.round(customHeight * 25.4)"></span> mm</span>
                            </template>
                            <template x-if="customUnit === 'mm'">
                                <span>= <span x-text="(customWidth / 25.4).toFixed(2)"></span>" × <span x-text="(customHeight / 25.4).toFixed(2)"></span>"</span>
                            </template>
                        </div>
                    </div>
                    
                    <!-- Row 3: Dimensions Display -->
                    <div class="flex flex-wrap items-center justify-between gap-2 pt-2 border-t border-gray-200">
                        <div class="flex items-center gap-4 text-xs">
                            <span class="flex items-center gap-1 text-gray-600">
                                <i class="fa-solid fa-ruler text-gray-400"></i>
                                <span x-text="getSizeDetails().inches"></span>
                            </span>
                            <span class="flex items-center gap-1 text-gray-600">
                                <span x-text="getSizeDetails().mm"></span>
                            </span>
                            <span class="flex items-center gap-1 text-gray-500">
                                <i class="fa-solid fa-image text-gray-400"></i>
                                <span x-text="getSizeDetails().pixels"></span>
                                <span class="text-gray-400">@</span>
                                <span x-text="dpi"></span>dpi
                            </span>
                        </div>
                        <div x-show="bleedEnabled" class="text-xs text-amber-600">
                            <i class="fa-solid fa-scissors mr-1"></i>
                            Bleed: <span x-text="getSizeDetails().bleed"></span> (will be trimmed)
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="p-4">
                <!-- Alignment Toolbar (Illustrator-style) -->
                <div class="mb-3 flex items-center justify-between bg-gray-100 rounded-lg px-3 py-2">
                    <div class="flex items-center gap-0.5">
                        <!-- Horizontal Align -->
                        <button @click="alignSelected('left')" class="p-1.5 text-gray-600 hover:text-blue-600 hover:bg-white rounded transition-colors" title="Align Left Edges">
                            <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor">
                                <rect x="1" y="2" width="2" height="12"/>
                                <rect x="5" y="4" width="8" height="3"/>
                                <rect x="5" y="9" width="5" height="3"/>
                            </svg>
                        </button>
                        <button @click="alignSelected('center-h')" class="p-1.5 text-gray-600 hover:text-blue-600 hover:bg-white rounded transition-colors" title="Align Horizontal Centers">
                            <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor">
                                <rect x="7" y="1" width="2" height="14"/>
                                <rect x="3" y="4" width="10" height="3"/>
                                <rect x="5" y="9" width="6" height="3"/>
                            </svg>
                        </button>
                        <button @click="alignSelected('right')" class="p-1.5 text-gray-600 hover:text-blue-600 hover:bg-white rounded transition-colors" title="Align Right Edges">
                            <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor">
                                <rect x="13" y="2" width="2" height="12"/>
                                <rect x="3" y="4" width="8" height="3"/>
                                <rect x="6" y="9" width="5" height="3"/>
                            </svg>
                        </button>
                        
                        <div class="w-px h-5 bg-gray-300 mx-2"></div>
                        
                        <!-- Vertical Align -->
                        <button @click="alignSelected('top')" class="p-1.5 text-gray-600 hover:text-blue-600 hover:bg-white rounded transition-colors" title="Align Top Edges">
                            <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor">
                                <rect x="2" y="1" width="12" height="2"/>
                                <rect x="4" y="5" width="3" height="8"/>
                                <rect x="9" y="5" width="3" height="5"/>
                            </svg>
                        </button>
                        <button @click="alignSelected('center-v')" class="p-1.5 text-gray-600 hover:text-blue-600 hover:bg-white rounded transition-colors" title="Align Vertical Centers">
                            <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor">
                                <rect x="1" y="7" width="14" height="2"/>
                                <rect x="4" y="3" width="3" height="10"/>
                                <rect x="9" y="5" width="3" height="6"/>
                            </svg>
                        </button>
                        <button @click="alignSelected('bottom')" class="p-1.5 text-gray-600 hover:text-blue-600 hover:bg-white rounded transition-colors" title="Align Bottom Edges">
                            <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor">
                                <rect x="2" y="13" width="12" height="2"/>
                                <rect x="4" y="3" width="3" height="8"/>
                                <rect x="9" y="6" width="3" height="5"/>
                            </svg>
                        </button>
                        
                        <div class="w-px h-5 bg-gray-300 mx-2"></div>
                        
                        <!-- Distribute -->
                        <button @click="distributeSelected('horizontal')" class="p-1.5 text-gray-600 hover:text-green-600 hover:bg-white rounded transition-colors" title="Distribute Horizontally">
                            <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor">
                                <rect x="1" y="4" width="3" height="8"/>
                                <rect x="6.5" y="5" width="3" height="6"/>
                                <rect x="12" y="4" width="3" height="8"/>
                            </svg>
                        </button>
                        <button @click="distributeSelected('vertical')" class="p-1.5 text-gray-600 hover:text-green-600 hover:bg-white rounded transition-colors" title="Distribute Vertically">
                            <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor">
                                <rect x="4" y="1" width="8" height="3"/>
                                <rect x="5" y="6.5" width="6" height="3"/>
                                <rect x="4" y="12" width="8" height="3"/>
                            </svg>
                        </button>
                    </div>
                    <div class="text-xs text-gray-500 flex items-center gap-1">
                        <svg width="12" height="12" viewBox="0 0 16 16" fill="currentColor" class="text-blue-500">
                            <path d="M8 1L6 3h4L8 1zM8 15l2-2H6l2 2zM1 8l2 2V6L1 8zM15 8l-2-2v4l2-2z"/>
                            <circle cx="8" cy="8" r="2"/>
                        </svg>
                        Snap to align
                    </div>
                </div>
                
                <!-- Fabric.js Canvas Container - Centered with proper scaling -->
                <div class="mb-4 flex items-center justify-center checkered-bg rounded-xl p-6 min-h-[300px]">
                    <div class="relative shadow-xl rounded-lg overflow-hidden" 
                         :style="getCanvasContainerStyle()"
                         id="canvasWrapper">
                        <canvas id="cardCanvas"></canvas>
                    </div>
                </div>
                
                <!-- Field Controls -->
                <div class="bg-gray-50 rounded-xl p-4 border border-gray-100">
                    <h4 class="font-medium text-gray-900 mb-3"><?= htmlspecialchars(t('dashboard.field_settings')) ?></h4>
                    <div class="grid grid-cols-2 md:grid-cols-3 gap-3 max-h-64 overflow-y-auto">
                        <template x-for="(field, key) in (selectedTemplate && selectedTemplate.fields ? selectedTemplate.fields : {})" :key="key">
                            <div class="bg-white rounded-lg p-3 border border-gray-200">
                                <div class="flex items-center justify-between mb-2">
                                    <label class="text-sm font-medium text-gray-700 flex items-center gap-2">
                                        <input type="checkbox" 
                                               :checked="field.enabled" 
                                               @change="toggleField(key, $event.target.checked)" 
                                               class="w-4 h-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                        <span x-text="getFieldLabel(key)"></span>
                                    </label>
                                </div>
                                
                                <div x-show="field.enabled && key !== 'qr_code'" class="space-y-2 mt-2">
                                    <div class="flex gap-2 items-end">
                                        <div class="flex-1">
                                            <label class="text-xs text-gray-500 block mb-1 flex items-center gap-1">
                                                <span>Size</span>
                                                <span class="text-[10px] text-gray-400 font-normal">pt (print)</span>
                                            </label>
                                            <div class="relative">
                                                <input type="number"
                                                       :value="pxToPt(field.fontSize)"
                                                       @input="updateFieldProperty(key, 'fontSize', ptToPx(parseFloat($event.target.value) || 0))"
                                                       min="1" max="999" step="0.5"
                                                       class="w-full pl-2 pr-7 py-1 bg-white border border-gray-200 rounded text-xs text-gray-900 [appearance:textfield] [&::-webkit-outer-spin-button]:m-0 [&::-webkit-inner-spin-button]:m-0">
                                                <span class="absolute right-2 top-1/2 -translate-y-1/2 text-[10px] text-gray-400 pointer-events-none">pt</span>
                                            </div>
                                        </div>
                                        <div class="flex-shrink-0">
                                            <label class="text-xs text-gray-500 block mb-1">Color</label>
                                            <div class="flex items-center gap-1">
                                                <input type="color"
                                                       :value="field.fill || field.color || brandPrimary"
                                                       @input="updateFieldProperty(key, 'fill', $event.target.value)"
                                                       class="w-7 h-7 rounded cursor-pointer border border-gray-200 flex-shrink-0"
                                                       title="Pick color">
                                                <input type="text"
                                                       :value="field.fill || field.color || brandPrimary"
                                                       @input="onHexInput(key, $event.target.value)"
                                                       maxlength="7"
                                                       placeholder="#000000"
                                                       spellcheck="false"
                                                       class="w-[5.5rem] px-1.5 py-1 bg-white border border-gray-200 rounded text-xs font-mono uppercase tracking-tight text-gray-900 focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                                                       title="Hex color">
                                                <!-- Brand-token swatches (action 593) -->
                                                <button type="button" @click="updateFieldProperty(key, 'fill', brandPrimary)"
                                                        class="w-5 h-5 rounded border border-gray-300 flex-shrink-0" :style="'background:' + brandPrimary"
                                                        :title="'Brand primary ' + brandPrimary"></button>
                                                <button type="button" @click="updateFieldProperty(key, 'fill', brandSecondary)"
                                                        class="w-5 h-5 rounded border border-gray-300 flex-shrink-0" :style="'background:' + brandSecondary"
                                                        :title="'Brand secondary ' + brandSecondary"></button>
                                                <button type="button" @click="updateFieldProperty(key, 'fill', '#111827')"
                                                        class="w-5 h-5 rounded border border-gray-300 flex-shrink-0 bg-gray-900" title="Near black"></button>
                                                <button type="button" @click="updateFieldProperty(key, 'fill', '#ffffff')"
                                                        class="w-5 h-5 rounded border border-gray-300 flex-shrink-0 bg-white" title="White"></button>
                                            </div>
                                        </div>
                                    </div>
                                    <div x-data="{ fontOpen: false, fontQuery: '' }" @click.outside="fontOpen = false" class="relative">
                                        <label class="text-xs text-gray-500 block mb-1">Font</label>
                                        <!-- Font Selector Button -->
                                        <button type="button" 
                                                @click="fontOpen = !fontOpen"
                                                class="w-full px-2 py-1.5 bg-white border border-gray-200 rounded text-xs text-left flex items-center justify-between hover:border-blue-400 focus:border-blue-500 focus:ring-1 focus:ring-blue-500 transition-colors">
                                            <span :style="'font-family: \'' + field.fontFamily + '\', sans-serif'" x-text="field.fontFamily || 'Select font'" class="truncate"></span>
                                            <svg class="w-3 h-3 text-gray-400 flex-shrink-0 ml-1" :class="fontOpen ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                                            </svg>
                                        </button>
                                        
                                        <!-- Font Dropdown -->
                                        <div x-show="fontOpen" 
                                             x-transition:enter="transition ease-out duration-100"
                                             x-transition:enter-start="opacity-0 scale-95"
                                             x-transition:enter-end="opacity-100 scale-100"
                                             x-transition:leave="transition ease-in duration-75"
                                             x-transition:leave-start="opacity-100 scale-100"
                                             x-transition:leave-end="opacity-0 scale-95"
                                             class="absolute z-50 mt-1 w-64 bg-white border border-gray-200 rounded-lg shadow-lg overflow-hidden"
                                             style="max-height: 320px;">
                                            <!-- Search Input -->
                                            <div class="p-2 border-b border-gray-100 sticky top-0 bg-white">
                                                <div class="relative">
                                                    <svg class="absolute left-2 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                                                    </svg>
                                                    <input type="text" 
                                                           x-model="fontQuery" 
                                                           @click.stop
                                                           placeholder="Search fonts..." 
                                                           class="w-full pl-7 pr-2 py-1.5 text-xs border border-gray-200 rounded focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                                                </div>
                                            </div>
                                            
                                            <!-- Font List -->
                                            <div class="overflow-y-auto" style="max-height: 260px;">
                                                <template x-for="(fonts, category) in filteredFonts(fontQuery)" :key="category">
                                                    <div>
                                                        <!-- Category Header -->
                                                        <div class="px-2 py-1.5 bg-gray-50 text-xs font-semibold text-gray-600 sticky top-0" x-text="category"></div>
                                                        <!-- Font Options -->
                                                        <template x-for="fontName in fonts" :key="fontName">
                                                            <button type="button"
                                                                    @click="selectFont(key, fontName); fontOpen = false; fontQuery = '';"
                                                                    :class="field.fontFamily === fontName ? 'bg-blue-50 text-blue-700' : 'text-gray-700 hover:bg-gray-50'"
                                                                    class="w-full px-3 py-1.5 text-left text-xs flex items-center justify-between transition-colors">
                                                                <span :style="'font-family: \'' + fontName + '\', sans-serif'" x-text="fontName"></span>
                                                                <svg x-show="field.fontFamily === fontName" class="w-3.5 h-3.5 text-blue-600" fill="currentColor" viewBox="0 0 20 20">
                                                                    <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                                                                </svg>
                                                            </button>
                                                        </template>
                                                    </div>
                                                </template>
                                                <!-- No Results -->
                                                <div x-show="Object.keys(filteredFonts(fontQuery)).length === 0" class="px-3 py-4 text-center text-xs text-gray-500">
                                                    <?= htmlspecialchars(t('emptystates.no_fonts_h')) ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="grid grid-cols-3 gap-2 mt-2">
                                        <div class="col-span-2">
                                            <label class="text-xs text-gray-500 block mb-1">Weight</label>
                                            <select
                                                :value="normalizedWeight(field)"
                                                @change="updateFieldProperty(key, 'fontWeight', $event.target.value === 'normal' ? 'normal' : parseInt($event.target.value))"
                                                @input="updateFieldProperty(key, 'fontWeight', $event.target.value === 'normal' ? 'normal' : parseInt($event.target.value))"
                                                class="w-full px-2 py-1 bg-white border border-gray-200 rounded text-xs text-gray-900 focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                                                <option value="300">Light</option>
                                                <option value="normal">Regular</option>
                                                <option value="500">Medium</option>
                                                <option value="600">SemiBold</option>
                                                <option value="700">Bold</option>
                                                <option value="800">ExtraBold</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="text-xs text-gray-500 block mb-1">Style</label>
                                            <button type="button"
                                                    @click="updateFieldProperty(key, 'fontStyle', (field.fontStyle === 'italic') ? 'normal' : 'italic')"
                                                    :class="field.fontStyle === 'italic' ? 'bg-blue-50 border-blue-400 text-blue-700' : 'bg-white border-gray-200 text-gray-700 hover:border-gray-300'"
                                                    class="w-full px-2 py-1 border rounded text-xs italic font-serif transition-colors"
                                                    title="Toggle italic">I</button>
                                        </div>
                                    </div>
                                    <div>
                                        <label class="text-xs text-gray-500 block mb-1">Text Align</label>
                                        <div class="flex gap-1 bg-gray-100 rounded p-0.5">
                                            <button type="button" 
                                                    @click="updateFieldProperty(key, 'textAlign', 'left')" 
                                                    :class="(field.textAlign || 'left') === 'left' ? 'bg-white shadow text-blue-600' : 'text-gray-500 hover:text-gray-700'"
                                                    class="flex-1 px-2 py-1 rounded transition-all" title="Align Left">
                                                <svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor" class="mx-auto">
                                                    <rect x="1" y="2" width="14" height="2"/>
                                                    <rect x="1" y="6" width="10" height="2"/>
                                                    <rect x="1" y="10" width="12" height="2"/>
                                                    <rect x="1" y="14" width="8" height="2"/>
                                                </svg>
                                            </button>
                                            <button type="button" 
                                                    @click="updateFieldProperty(key, 'textAlign', 'center')" 
                                                    :class="field.textAlign === 'center' ? 'bg-white shadow text-blue-600' : 'text-gray-500 hover:text-gray-700'"
                                                    class="flex-1 px-2 py-1 rounded transition-all" title="Align Center">
                                                <svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor" class="mx-auto">
                                                    <rect x="1" y="2" width="14" height="2"/>
                                                    <rect x="3" y="6" width="10" height="2"/>
                                                    <rect x="2" y="10" width="12" height="2"/>
                                                    <rect x="4" y="14" width="8" height="2"/>
                                                </svg>
                                            </button>
                                            <button type="button" 
                                                    @click="updateFieldProperty(key, 'textAlign', 'right')" 
                                                    :class="field.textAlign === 'right' ? 'bg-white shadow text-blue-600' : 'text-gray-500 hover:text-gray-700'"
                                                    class="flex-1 px-2 py-1 rounded transition-all" title="Align Right">
                                                <svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor" class="mx-auto">
                                                    <rect x="1" y="2" width="14" height="2"/>
                                                    <rect x="5" y="6" width="10" height="2"/>
                                                    <rect x="3" y="10" width="12" height="2"/>
                                                    <rect x="7" y="14" width="8" height="2"/>
                                                </svg>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                
                                <div x-show="field.enabled && key === 'qr_code'" class="mt-2">
                                    <label class="text-xs text-gray-500 block mb-1">QR Size</label>
                                    <input type="number" 
                                           :value="field.size" 
                                           @change="updateQRSize(parseInt($event.target.value))" 
                                           min="60" max="200" 
                                           class="w-full px-2 py-1 bg-white border border-gray-200 rounded text-xs text-gray-900">
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Empty State -->
        <div class="lg:col-span-2 bg-white rounded-xl border border-gray-100 shadow-sm flex items-center justify-center p-12" x-show="!selectedTemplate">
            <div class="text-center text-gray-400">
                <i class="fa-solid fa-mouse-pointer text-4xl mb-4 opacity-50"></i>
                <p class="text-gray-600"><?= htmlspecialchars(t('dashboard.select_template')) ?></p>
                <p class="text-sm mt-1"><?= htmlspecialchars(t('dashboard.or_create_new')) ?></p>
            </div>
        </div>
    </div>
    
    <!-- Status Message Toast -->
    <div x-show="statusMessage" x-transition 
         class="fixed bottom-6 right-6 px-4 py-3 rounded-xl shadow-lg flex items-center gap-3 z-50" 
         :class="statusType === 'success' ? 'bg-green-50 text-green-700 border border-green-200' : 'bg-red-50 text-red-700 border border-red-200'">
        <i :class="statusType === 'success' ? 'fa-solid fa-circle-check' : 'fa-solid fa-circle-exclamation'"></i>
        <span x-text="statusMessage"></span>
    </div>
</div>

<script>
    function templateEditor() {
        return {
            csrfToken: '<?php echo generateCSRFToken(); ?>',
            newFormData: function(action) {
                var fd = new FormData();
                fd.append('action', action);
                fd.append('csrf_token', this.csrfToken);
                return fd;
            },
            activeTab: 'front',
            templates: <?php echo json_encode(array_values($templates), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
            activeFrontId: <?php echo json_encode($activeFrontId, JSON_HEX_TAG); ?>,
            activeBackId: <?php echo json_encode($activeBackId, JSON_HEX_TAG); ?>,
            selectedTemplate: null,
            selectedPairId: null,
            currentDesign: null,
            showAddModal: false,
            newTemplate: { name: '', side: 'front', frontImageFile: null, backImageFile: null, bgColor: '#1e3a5f' },
            statusMessage: '',
            statusType: 'success',
            cardEditor: null,
            basePath: '<?php echo getBasePath(); ?>',
            sampleEmployee: <?php echo json_encode($sampleEmployee, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
            sampleProfiles: <?php echo json_encode($sampleProfiles, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
            currentProfileIndex: 0,
            companySlug: '<?php echo $companySlug; ?>',
            apexHost: '<?php echo cardifyApexHost(); ?>',
            baseUrl: '<?php echo $baseUrl; ?>',
            brandPrimary: '<?php echo htmlspecialchars($primaryColor, ENT_QUOTES); ?>',
            brandSecondary: '<?php echo htmlspecialchars($secondaryColor, ENT_QUOTES); ?>',
            fontsLoaded: false,
            initialized: false,
            
            // Card size and orientation
            cardSize: 'standard',
            cardOrientation: 'landscape',
            dpi: 300, // Dots per inch for print quality
            
            // Bleed settings (extra area for trimming)
            bleedEnabled: false,
            bleedSize: 0.125, // inches (3mm = ~0.118")
            bleedUnit: 'in', // 'in' or 'mm'
            
            // Unit conversion constants
            MM_PER_INCH: 25.4,
            
            // Predefined card sizes with both inch and mm measurements
            cardSizes: {
                standard: { 
                    widthIn: 3.5, heightIn: 2, 
                    widthMm: 89, heightMm: 51,
                    label: 'Standard US', region: 'US/Canada'
                },
                eu: { 
                    widthIn: 3.346, heightIn: 2.165, 
                    widthMm: 85, heightMm: 55,
                    label: 'European', region: 'EU/UK'
                },
                japanese: { 
                    widthIn: 3.582, heightIn: 2.165, 
                    widthMm: 91, heightMm: 55,
                    label: 'Japanese', region: 'Japan'
                },
                square: { 
                    widthIn: 2.5, heightIn: 2.5, 
                    widthMm: 64, heightMm: 64,
                    label: 'Square', region: 'Modern'
                },
                mini: { 
                    widthIn: 2.75, heightIn: 1.1, 
                    widthMm: 70, heightMm: 28,
                    label: 'Mini/Slim', region: 'Compact'
                },
                custom: { 
                    widthIn: 3.5, heightIn: 2, 
                    widthMm: 89, heightMm: 51,
                    label: 'Custom', region: 'Your size'
                }
            },
            
            // Custom dimensions (for custom size)
            customWidth: 3.5,
            customHeight: 2,
            customUnit: 'in', // 'in' or 'mm'
            
            // Font categories for the font selector.
            // Myriad Pro isn't available on Google Fonts (Adobe licensed).
            // Source Sans 3 is its closest free cousin (same designer at
            // Adobe) and Hind is another near-twin. Add an Adobe Fonts
            // kit later for Myriad Pro if the license is acquired.
            fontCategories: {
                'Sans-Serif': ['Myriad Pro', 'Tahoma', 'Inter', 'Plus Jakarta Sans', 'Montserrat', 'Roboto', 'Poppins', 'Open Sans', 'Lato', 'Nunito', 'Nunito Sans', 'Raleway', 'Work Sans', 'DM Sans', 'Outfit', 'Manrope', 'Urbanist', 'Lexend', 'Sora', 'Rubik', 'Quicksand', 'Ubuntu', 'Barlow', 'Jost', 'Source Sans 3', 'Hind', 'Mulish', 'PT Sans', 'Cabin', 'Assistant', 'Karla', 'Figtree', 'Red Hat Display', 'Red Hat Text', 'Public Sans', 'Archivo', 'IBM Plex Sans', 'Onest', 'Geist'],
                'Serif': ['Playfair Display', 'Merriweather', 'Lora', 'PT Serif', 'Libre Baskerville', 'EB Garamond', 'Cormorant Garamond', 'Spectral', 'Noto Serif', 'Vollkorn', 'Bodoni Moda', 'Source Serif 4', 'Fraunces', 'Crimson Pro', 'Alegreya', 'Libre Caslon Text', 'Newsreader', 'Roboto Serif'],
                'Display': ['Bebas Neue', 'Oswald', 'Anton', 'Archivo Black', 'Righteous', 'Teko', 'Big Shoulders Display', 'Fredoka', 'Abril Fatface', 'Bungee', 'Comfortaa', 'Josefin Sans', 'Alfa Slab One', 'Chivo', 'Russo One', 'Unbounded'],
                'Script': ['Dancing Script', 'Pacifico', 'Great Vibes', 'Sacramento', 'Allura', 'Lobster', 'Caveat', 'Kaushan Script', 'Satisfy', 'Shadows Into Light', 'Homemade Apple', 'Amatic SC', 'Parisienne'],
                'Arabic العربية': ['Cairo', 'Tajawal', 'Almarai', 'Noto Kufi Arabic', 'IBM Plex Sans Arabic', 'Noto Sans Arabic', 'Readex Pro', 'El Messiri', 'Changa', 'Reem Kufi', 'Amiri', 'Scheherazade New', 'Mada', 'Lalezar', 'Lemonada', 'Aref Ruqaa', 'Mirza', 'Rakkas', 'Baloo Bhaijaan 2', 'Noto Naskh Arabic', 'Noto Nastaliq Urdu', 'Lateef', 'Harmattan', 'Markazi Text', 'Gulzar', 'Vazirmatn', 'Noto Sans Arabic Kufi'],
                'Monospace': ['Roboto Mono', 'JetBrains Mono', 'Fira Code', 'Source Code Pro', 'Space Mono', 'IBM Plex Mono', 'Courier Prime', 'Inconsolata']
            },

            // Font size is stored in canvas backstore pixels so the PDF/
            // PNG exports stay print-accurate at any card size. The UI
            // works in points (pt), the real print unit Illustrator uses:
            //   1pt = 1/72 inch, so at 300 DPI → 4.1667 px/pt.
            // These helpers convert between the two; if the card DPI
            // changes, point values stay stable across sizes.
            // Normalise any stored fontWeight (legacy strings, empty, or
            // numeric) to the set of values the Weight <select> shows, so
            // the dropdown reflects the true state instead of silently
            // falling back to its first option.
            normalizedWeight: function(field) {
                var w = field && field.fontWeight;
                if (w === undefined || w === null || w === '') return 'normal';
                if (w === 'bold' || w === 'bolder') return '700';
                if (w === 'lighter') return '300';
                if (w === 'normal') return 'normal';
                var n = parseInt(w, 10);
                if (isNaN(n)) return 'normal';
                // Snap to the nearest picker value so 450 shows as Regular
                // rather than nothing.
                var values = [300, 400, 500, 600, 700, 800];
                var nearest = values.reduce(function(a, b) {
                    return Math.abs(b - n) < Math.abs(a - n) ? b : a;
                }, 400);
                return nearest === 400 ? 'normal' : String(nearest);
            },

            // Accept hex colour input live. Supports #rgb, #rgba, #rrggbb,
            // #rrggbbaa with or without the leading '#'. Only commits once
            // the entry is a valid colour so typing intermediate values
            // like 'ff' or 'ff00' doesn't flash the preview to black.
            onHexInput: function(key, raw) {
                var v = (raw || '').toString().trim().replace(/^#+/, '');
                if (!/^[0-9a-fA-F]+$/.test(v)) return;
                var hex;
                if (v.length === 3) {
                    hex = '#' + v[0] + v[0] + v[1] + v[1] + v[2] + v[2];
                } else if (v.length === 6) {
                    hex = '#' + v;
                } else if (v.length === 4) {
                    hex = '#' + v[0] + v[0] + v[1] + v[1] + v[2] + v[2] + v[3] + v[3];
                } else if (v.length === 8) {
                    hex = '#' + v;
                } else {
                    return; // wait for more typing
                }
                this.updateFieldProperty(key, 'fill', hex.toLowerCase());
            },

            pxToPt: function(px) {
                var dpi = this.dpi || 300;
                if (!px && px !== 0) return '';
                return Math.round((px * 72 / dpi) * 10) / 10;
            },
            ptToPx: function(pt) {
                var dpi = this.dpi || 300;
                return Math.max(1, Math.round(pt * dpi / 72));
            },

            // Font weights for the Weight picker. Values that are integers
            // map straight through to Fabric's fontWeight. 'normal' stays
            // as the legacy default so existing designs don't shift.
            fontWeights: [
                { value: 300, label: 'Light' },
                { value: 'normal', label: 'Regular' },
                { value: 500, label: 'Medium' },
                { value: 600, label: 'SemiBold' },
                { value: 700, label: 'Bold' },
                { value: 800, label: 'ExtraBold' }
            ],


            maxDisplayWidth: 480, // Maximum width to display in editor
            showSizeModal: false, // For custom size modal
            
            init: function() {
                // Prevent double initialization
                if (this.initialized) {
                    console.log('templateEditor already initialized');
                    return;
                }
                this.initialized = true;

                var self = this;
                var fontsReady = Promise.resolve();

                // Load fonts first
                try {
                    fontsReady = FontLoader.load();
                } catch (e) {
                    console.warn('Font loading error:', e);
                }

                fontsReady = fontsReady.catch(function(e) {
                    console.warn('Font loading error:', e);
                }).then(function() {
                    self.fontsLoaded = true;
                    if (document.fonts && document.fonts.ready) {
                        return document.fonts.ready;
                    }
                    return Promise.resolve();
                });

                fontsReady.then(function() {
                    return self.initCanvas();
                }).then(function() {
                    // Select first card design
                    var designs = self.getCardDesigns();
                    if (designs.length > 0) {
                        return self.selectCardDesign(designs[0]);
                    }
                    return null;
                });

                // Arrow-key nudge for whatever is selected on the canvas.
                document.addEventListener('keydown', function(e) {
                    self.handleCanvasKeydown(e);
                });
            },
            
            initCanvas: function() {
                var canvasEl = document.getElementById('cardCanvas');
                if (!canvasEl) return Promise.resolve();

                // Prevent double initialization
                if (this.cardEditor) {
                    console.log('CardEditor already initialized');
                    return Promise.resolve();
                }

                // Get canvas dimensions based on size and orientation
                var dims = this.getCanvasDimensions();

                // Create CardEditor instance
                var self = this;
                this.cardEditor = new CardEditor('cardCanvas', {
                    width: dims.width,
                    height: dims.height,
                    backgroundColor: '#ffffff',
                    onFieldMove: function(key, position) {
                        if (self.selectedTemplate && self.selectedTemplate.fields[key]) {
                            self.selectedTemplate.fields[key].x = Math.round(position.x);
                            self.selectedTemplate.fields[key].y = Math.round(position.y);
                        }
                    },
                    onFieldSelect: function(key) {
                        // Could highlight field in controls
                    },
                    onBackgroundTransform: function(t) {
                        if (!self.selectedTemplate) return;
                        self.selectedTemplate.backgroundTransform = t;
                        if (!self.selectedTemplate.settings) self.selectedTemplate.settings = {};
                        self.selectedTemplate.settings.backgroundTransform = t;
                        self.persistSettingsDebounced();
                    }
                });

                // Wait for CardEditor to be fully initialized
                return new Promise(function(resolve) {
                    var attempts = 0;
                    var timer = setInterval(function() {
                        attempts += 1;
                        if (self.cardEditor.isReady || attempts >= 100) {
                            clearInterval(timer);
                            if (!self.cardEditor.isReady) {
                                console.error('CardEditor failed to initialize');
                            } else {
                                // Set the display (CSS) size so the canvas
                                // fits the wrapper; internal backstore stays
                                // at full 300 DPI for export quality.
                                self.resizeCanvas();
                            }
                            resolve();
                        }
                    }, 50);
                });
            },
            
            // Convert inches to pixels at current DPI
            inchesToPixels: function(inches) {
                return Math.round(inches * this.dpi);
            },
            
            // Convert mm to pixels at current DPI
            mmToPixels: function(mm) {
                return Math.round((mm / this.MM_PER_INCH) * this.dpi);
            },
            
            // Convert pixels to inches
            pixelsToInches: function(px) {
                return (px / this.dpi).toFixed(3);
            },
            
            // Convert pixels to mm
            pixelsToMm: function(px) {
                return ((px / this.dpi) * this.MM_PER_INCH).toFixed(1);
            },
            
            // Get bleed in pixels
            getBleedPixels: function() {
                if (!this.bleedEnabled) return 0;
                if (this.bleedUnit === 'mm') {
                    return this.mmToPixels(this.bleedSize);
                }
                return this.inchesToPixels(this.bleedSize);
            },
            
            // Get canvas dimensions based on current size, orientation, and bleed
            getCanvasDimensions: function() {
                var widthIn, heightIn;
                var size = this.cardSizes[this.cardSize] || this.cardSizes.standard;
                
                if (this.cardSize === 'custom') {
                    if (this.customUnit === 'mm') {
                        widthIn = this.customWidth / this.MM_PER_INCH;
                        heightIn = this.customHeight / this.MM_PER_INCH;
                    } else {
                        widthIn = this.customWidth;
                        heightIn = this.customHeight;
                    }
                } else {
                    widthIn = size.widthIn;
                    heightIn = size.heightIn;
                }
                
                // Apply orientation (swap for portrait)
                if (this.cardOrientation === 'portrait' && this.cardSize !== 'square') {
                    var tempWidth = widthIn;
                    widthIn = heightIn;
                    heightIn = tempWidth;
                }
                
                // Add bleed if enabled
                var bleedIn = this.bleedEnabled ? (this.bleedUnit === 'mm' ? this.bleedSize / this.MM_PER_INCH : this.bleedSize) : 0;
                widthIn += bleedIn * 2;
                heightIn += bleedIn * 2;
                
                return { 
                    width: this.inchesToPixels(widthIn), 
                    height: this.inchesToPixels(heightIn),
                    widthIn: widthIn,
                    heightIn: heightIn
                };
            },
            
            // Get base card dimensions (without bleed)
            getCardDimensions: function() {
                var size = this.cardSizes[this.cardSize] || this.cardSizes.standard;
                var widthIn, heightIn, widthMm, heightMm;
                
                if (this.cardSize === 'custom') {
                    if (this.customUnit === 'mm') {
                        widthMm = this.customWidth;
                        heightMm = this.customHeight;
                        widthIn = this.customWidth / this.MM_PER_INCH;
                        heightIn = this.customHeight / this.MM_PER_INCH;
                    } else {
                        widthIn = this.customWidth;
                        heightIn = this.customHeight;
                        widthMm = this.customWidth * this.MM_PER_INCH;
                        heightMm = this.customHeight * this.MM_PER_INCH;
                    }
                } else {
                    widthIn = size.widthIn;
                    heightIn = size.heightIn;
                    widthMm = size.widthMm;
                    heightMm = size.heightMm;
                }
                
                // Apply orientation (swap for portrait)
                if (this.cardOrientation === 'portrait' && this.cardSize !== 'square') {
                    var tempIn = widthIn;
                    widthIn = heightIn;
                    heightIn = tempIn;
                    var tempMm = widthMm;
                    widthMm = heightMm;
                    heightMm = tempMm;
                }
                
                return { widthIn: widthIn, heightIn: heightIn, widthMm: widthMm, heightMm: heightMm };
            },
            
            // Get display style for canvas container (scaled to fit editor)
            getCanvasContainerStyle: function() {
                var dims = this.getCanvasDimensions();
                var scale = Math.min(this.maxDisplayWidth / dims.width, 320 / dims.height);
                var displayWidth = Math.round(dims.width * scale);
                var displayHeight = Math.round(dims.height * scale);
                return 'width: ' + displayWidth + 'px; height: ' + displayHeight + 'px;';
            },
            
            // Get text showing current dimensions in both units
            getCanvasDimensionsText: function() {
                var dims = this.getCanvasDimensions();
                var card = this.getCardDimensions();
                var bleedText = this.bleedEnabled ? ' +' + this.bleedSize + this.bleedUnit + ' bleed' : '';
                return card.widthIn.toFixed(2) + '" x ' + card.heightIn.toFixed(2) + '" (' + Math.round(card.widthMm) + ' x ' + Math.round(card.heightMm) + ' mm)' + bleedText;
            },
            
            // Get detailed size info
            getSizeDetails: function() {
                var dims = this.getCanvasDimensions();
                var card = this.getCardDimensions();
                return {
                    pixels: dims.width + ' x ' + dims.height + ' px',
                    inches: card.widthIn.toFixed(2) + '" x ' + card.heightIn.toFixed(2) + '"',
                    mm: Math.round(card.widthMm) + ' x ' + Math.round(card.heightMm) + ' mm',
                    dpi: this.dpi,
                    bleed: this.bleedEnabled ? this.bleedSize + ' ' + this.bleedUnit : 'None'
                };
            },

            // Collect size/orientation settings for persistence
            // Lightweight "settings only" save (no field-position rescan)
            // used to persist background transform after a drag/resize.
            // Debounced so a continuous drag doesn't spam the server.
            _persistTimer: null,
            persistSettingsDebounced: function() {
                var self = this;
                if (this._persistTimer) clearTimeout(this._persistTimer);
                this._persistTimer = setTimeout(function() {
                    if (!self.selectedTemplate) return;
                    var formData = self.newFormData('update');
                    formData.append('id', self.selectedTemplate.id);
                    formData.append('name', self.selectedTemplate.name);
                    formData.append('fields', JSON.stringify(self.selectedTemplate.fields || {}));
                    formData.append('settings', JSON.stringify(self.getTemplateSettings()));
                    fetch('save_template', { method: 'POST', body: formData })
                        .catch(function(e) { console.warn('Auto-save error:', e); });
                }, 600);
            },

            // Reset the uploaded background's position/scale so it fills the
            // canvas again. Handy after the user moves/resizes by mistake.
            resetBackgroundFit: function() {
                if (this.cardEditor && typeof this.cardEditor.resetBackgroundTransform === 'function') {
                    this.cardEditor.resetBackgroundTransform();
                }
            },

            centerBackground: function() {
                if (this.cardEditor && typeof this.cardEditor.centerBackground === 'function') {
                    this.cardEditor.centerBackground();
                }
            },

            backgroundLocked: true,
            toggleBackgroundLock: function() {
                this.backgroundLocked = !this.backgroundLocked;
                if (this.cardEditor && typeof this.cardEditor.setBackgroundLocked === 'function') {
                    this.cardEditor.setBackgroundLocked(this.backgroundLocked);
                }
                this.showStatus(this.backgroundLocked ? 'Background locked, click fields to edit' : 'Background unlocked, drag to reposition', 'success');
            },

            // Arrow-key nudge for whatever is selected on the canvas.
            // Shift = 10px, plain arrow = 1px. Ignored while the user is
            // typing in an input so the editor stays keyboard-friendly.
            handleCanvasKeydown: function(event) {
                var tag = (event.target && event.target.tagName) || '';
                if (tag === 'INPUT' || tag === 'TEXTAREA' || event.target.isContentEditable) return;
                if (!this.cardEditor || !this.cardEditor.canvas) return;
                var step = event.shiftKey ? 10 : 1;
                var dx = 0, dy = 0;
                switch (event.key) {
                    case 'ArrowLeft': dx = -step; break;
                    case 'ArrowRight': dx = step; break;
                    case 'ArrowUp': dy = -step; break;
                    case 'ArrowDown': dy = step; break;
                    default: return;
                }
                event.preventDefault();
                this.cardEditor.nudgeSelected(dx, dy);
            },

            getTemplateSettings: function() {
                var bgT = null;
                if (this.cardEditor && typeof this.cardEditor.getBackgroundTransform === 'function') {
                    bgT = this.cardEditor.getBackgroundTransform();
                }
                if (!bgT && this.selectedTemplate && this.selectedTemplate.backgroundTransform) {
                    bgT = this.selectedTemplate.backgroundTransform;
                }
                return {
                    cardSize: this.cardSize,
                    cardOrientation: this.cardOrientation,
                    dpi: this.dpi,
                    bleedEnabled: this.bleedEnabled,
                    bleedSize: this.bleedSize,
                    bleedUnit: this.bleedUnit,
                    customWidth: this.customWidth,
                    customHeight: this.customHeight,
                    customUnit: this.customUnit,
                    backgroundTransform: bgT
                };
            },

            // Apply saved settings from template (if any)
            applyTemplateSettings: function(template) {
                if (!template || !template.settings) return;

                var settings = template.settings || {};
                if (settings.cardSize) this.cardSize = settings.cardSize;
                if (settings.cardOrientation) this.cardOrientation = settings.cardOrientation;
                if (typeof settings.dpi === 'number') this.dpi = settings.dpi;
                if (typeof settings.bleedEnabled === 'boolean') this.bleedEnabled = settings.bleedEnabled;
                if (typeof settings.bleedSize !== 'undefined') this.bleedSize = settings.bleedSize;
                if (settings.bleedUnit) this.bleedUnit = settings.bleedUnit;
                if (typeof settings.customWidth !== 'undefined') this.customWidth = settings.customWidth;
                if (typeof settings.customHeight !== 'undefined') this.customHeight = settings.customHeight;
                if (settings.customUnit) this.customUnit = settings.customUnit;
                if (settings.backgroundTransform) template.backgroundTransform = settings.backgroundTransform;
            },

            // Resize the canvas to current settings. Internal dimensions
            // stay at 300 DPI for export quality; the CSS size is the
            // scaled-down display size so the wrapper never clips the
            // uploaded artwork.
            resizeCanvas: function() {
                if (!this.cardEditor || !this.cardEditor.canvas) return Promise.resolve();
                var dims = this.getCanvasDimensions();
                var scale = Math.min(this.maxDisplayWidth / dims.width, 320 / dims.height);
                var displayWidth = Math.round(dims.width * scale);
                var displayHeight = Math.round(dims.height * scale);
                if (typeof this.cardEditor.setDimensions === 'function') {
                    this.cardEditor.setDimensions(dims.width, dims.height, displayWidth, displayHeight);
                } else {
                    this.cardEditor.canvas.setDimensions({ width: dims.width, height: dims.height });
                    this.cardEditor.options.width = dims.width;
                    this.cardEditor.options.height = dims.height;
                }
                this.cardEditor.canvas.renderAll();
                return Promise.resolve();
            },
            
            // Change card size
            changeCardSize: function() {
                var self = this;
                var chain = this.resizeCanvas();
                if (this.selectedTemplate) {
                    // Update in-memory settings to avoid reverting
                    this.selectedTemplate.settings = this.getTemplateSettings();
                    chain = chain.then(function() { 
                        return self.selectTemplate(self.selectedTemplate, { skipResize: true, skipApplySettings: true }); 
                    });
                }
                
                return chain.then(function() {
                    self.showStatus('Card size changed to ' + self.cardSizes[self.cardSize].label, 'success');
                });
            },
            
            // Set card orientation
            setOrientation: function(orientation) {
                if (this.cardOrientation === orientation) return Promise.resolve();
                this.cardOrientation = orientation;
                return this.changeCardSize();
            },
            getTemplatesForTab: function() {
                var self = this;
                return this.templates.filter(function(t) {
                    return (t.side || 'front') === self.activeTab;
                });
            },
            
            // Get templates grouped by pair_id as card designs
            getCardDesigns: function() {
                var designs = {};
                var self = this;
                
                this.templates.forEach(function(t) {
                    var pairId = t.pair_id || t.id; // Use id as fallback for unpaired templates
                    if (!designs[pairId]) {
                        designs[pairId] = {
                            pair_id: pairId,
                            name: t.name,
                            front: null,
                            back: null
                        };
                    }
                    if (t.side === 'front') {
                        designs[pairId].front = t;
                    } else if (t.side === 'back') {
                        designs[pairId].back = t;
                    }
                });
                
                return Object.values(designs);
            },
            
            // Select a card design (pair)
            selectCardDesign: function(design) {
                if (!design) return Promise.resolve();
                
                this.selectedPairId = design.pair_id;
                this.currentDesign = design;
                
                // Select the template for the current side (front by default)
                var template = this.activeTab === 'back' ? design.back : design.front;
                if (!template) {
                    template = design.front || design.back;
                    this.activeTab = template ? template.side : 'front';
                }
                
                if (template) {
                    return this.selectTemplate(template);
                }
                return Promise.resolve();
            },
            
            // Switch between front and back within a design
            switchToSide: function(side) {
                if (!this.currentDesign) return;
                
                this.activeTab = side;
                var template = side === 'back' ? this.currentDesign.back : this.currentDesign.front;
                
                if (template) {
                    this.selectTemplate(template);
                } else {
                    // No template for this side yet
                    this.selectedTemplate = null;
                    if (this.cardEditor && this.cardEditor.isReady) {
                        this.cardEditor.clear();
                    }
                }
            },
            
            // Check if a design has active templates
            isDesignActive: function(design) {
                if (!design) return false;
                var frontActive = design.front && design.front.id === this.activeFrontId;
                var backActive = design.back && design.back.id === this.activeBackId;
                return frontActive || backActive;
            },
            
            // Delete entire card design (both front and back)
            deleteCardDesign: function(design) {
                if (!design) return;
                if (!confirm('Delete this card design? This will remove both front and back templates.')) return;
                
                var self = this;
                
                // If has pair_id, delete by pair
                if (design.pair_id) {
                    var formData = this.newFormData('delete_pair');
                    formData.append('pair_id', design.pair_id);
                    
                    fetch('save_template', { method: 'POST', body: formData })
                        .then(function(response) { return response.json(); })
                        .then(function(result) {
                            if (result.success) {
                                // Remove templates from array
                                self.templates = self.templates.filter(function(t) {
                                    return t.pair_id !== design.pair_id;
                                });
                                self.selectedTemplate = null;
                                self.selectedPairId = null;
                                self.currentDesign = null;
                                self.showStatus('Card design deleted', 'success');
                            } else {
                                self.showStatus(result.error || 'Failed to delete', 'error');
                            }
                        })
                        .catch(function(error) {
                            console.error('Delete design error:', error);
                            self.showStatus('Error deleting design', 'error');
                        });
                } else {
                    // Fallback: delete individual templates
                    var promises = [];
                    if (design.front) promises.push(this.deleteTemplateById(design.front.id));
                    if (design.back) promises.push(this.deleteTemplateById(design.back.id));
                    
                    Promise.all(promises).then(function() {
                        self.showStatus('Card design deleted', 'success');
                    });
                }
            },
            
            deleteTemplateById: function(id) {
                var formData = this.newFormData('delete');
                formData.append('id', id);

                var self = this;
                return fetch('save_template', { method: 'POST', body: formData })
                    .then(function(response) { return response.json(); })
                    .then(function(result) {
                        if (result.success) {
                            self.templates = self.templates.filter(function(t) { return t.id !== id; });
                        }
                    });
            },
            
            // Handle front image for new template
            handleNewFrontImage: function(event) {
                var file = event.target.files[0];
                if (file) this.newTemplate.frontImageFile = file;
            },
            
            // Handle back image for new template
            handleNewBackImage: function(event) {
                var file = event.target.files[0];
                if (file) this.newTemplate.backImageFile = file;
            },
            
            // Upload background for current template
            uploadBackground: function(event) {
                var file = event.target.files[0];
                if (!file || !this.selectedTemplate) return;
                
                var formData = this.newFormData('update_background');
                formData.append('id', this.selectedTemplate.id);
                formData.append('image', file);
                
                var self = this;
                this.showStatus('Uploading...', 'success');
                
                fetch('save_template', { method: 'POST', body: formData })
                    .then(function(response) { return response.json(); })
                    .then(function(result) {
                        if (result.success) {
                            self.selectedTemplate.backgroundImage = result.backgroundImage;
                            // Update originalPdf if returned (for vector PDF export)
                            self.selectedTemplate.originalPdf = result.originalPdf || null;
                            // A fresh upload resets any previous crop/position
                            // so the new artwork fits the canvas cleanly.
                            self.selectedTemplate.backgroundTransform = null;
                            if (self.selectedTemplate.settings) {
                                self.selectedTemplate.settings.backgroundTransform = null;
                            }

                            // Update in templates array
                            for (var i = 0; i < self.templates.length; i++) {
                                if (self.templates[i].id === self.selectedTemplate.id) {
                                    self.templates[i].backgroundImage = result.backgroundImage;
                                    self.templates[i].originalPdf = result.originalPdf || null;
                                    self.templates[i].backgroundTransform = null;
                                    break;
                                }
                            }
                            
                            if (result.originalPdf) {
                                console.log('Original PDF saved:', result.originalPdf);
                                self.showStatus('PDF background uploaded - high quality export available!', 'success');
                            }
                            
                            // Reload canvas
                            self.selectTemplate(self.selectedTemplate, { skipApplySettings: true });
                            self.showStatus('Background updated', 'success');
                        } else {
                            self.showStatus(result.error || 'Failed to upload', 'error');
                        }
                    })
                    .catch(function(error) {
                        console.error('Upload error:', error);
                        self.showStatus('Error uploading background', 'error');
                    });
            },
            
            // Add new template pair (front + back)
            addTemplatePair: function() {
                if (!this.newTemplate.name) {
                    this.showStatus('Please enter a design name', 'error');
                    return;
                }
                
                var formData = this.newFormData('add_pair');
                formData.append('name', this.newTemplate.name);
                formData.append('fields', JSON.stringify(<?php echo json_encode(getDefaultFieldSettings(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>));
                formData.append('settings', JSON.stringify(this.getTemplateSettings()));
                
                if (this.newTemplate.frontImageFile) {
                    formData.append('front_image', this.newTemplate.frontImageFile);
                }
                if (this.newTemplate.backImageFile) {
                    formData.append('back_image', this.newTemplate.backImageFile);
                }
                // Pass background color for solid-color generation when no image is uploaded
                if (this.newTemplate.bgColor) {
                    formData.append('bg_color', this.newTemplate.bgColor);
                }
                
                var self = this;
                fetch('save_template', { method: 'POST', body: formData })
                    .then(function(response) { return response.json(); })
                    .then(function(result) {
                        if (result.success) {
                            // Add both templates to the array
                            if (result.front) self.templates.push(result.front);
                            if (result.back) self.templates.push(result.back);
                            
                            self.showAddModal = false;
                            self.newTemplate = { name: '', frontImageFile: null, backImageFile: null, bgColor: '#1e3a5f' };
                            self.showStatus('Card design created', 'success');
                            
                            // Select the new design
                            var designs = self.getCardDesigns();
                            var newDesign = designs.find(function(d) { return d.pair_id === result.pair_id; });
                            if (newDesign) {
                                self.selectCardDesign(newDesign);
                            }
                        } else {
                            self.showStatus(result.error || 'Failed to create design', 'error');
                        }
                    })
                    .catch(function(error) {
                        console.error('Add design error:', error);
                        self.showStatus('Error creating design', 'error');
                    });
            },
            
            switchTab: function(tab) {
                this.activeTab = tab;
                this.selectedTemplate = null;
                if (this.cardEditor && this.cardEditor.isReady) {
                    this.cardEditor.clear();
                }
                
                var tabTemplates = this.getTemplatesForTab();
                if (tabTemplates.length > 0) {
                    this.selectTemplate(tabTemplates[0]);
                }
            },
            
            selectTemplate: function(template, options) {
                if (!template) return Promise.resolve();

                // Ensure fields exist with defaults. Templates imported from
                // a PDF have already gone through the click-to-bind review,
                // so anything the user didn't explicitly map should NOT be
                // rendered on the canvas. We still merge the default field
                // shapes (font size, alignment, etc.) so the field-settings
                // UI can expose them, but every injected default lands
                // disabled so the editor's auto-render skips them.
                var defaultFields = <?php echo json_encode(getDefaultFieldSettings(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
                var isImported = template.settings && template.settings.imported_from === 'pdf';
                if (!template.fields) {
                    template.fields = JSON.parse(JSON.stringify(defaultFields));
                    if (isImported) {
                        for (var dk in template.fields) {
                            if (Object.prototype.hasOwnProperty.call(template.fields, dk)) {
                                template.fields[dk].enabled = false;
                            }
                        }
                    }
                } else {
                    for (var key in defaultFields) {
                        if (!template.fields[key]) {
                            var clone = JSON.parse(JSON.stringify(defaultFields[key]));
                            if (isImported) clone.enabled = false;
                            template.fields[key] = clone;
                        }
                    }
                }
                
                this.selectedTemplate = template;

                // Apply saved size/orientation settings (if any)
                if (!(options && options.skipApplySettings)) {
                    this.applyTemplateSettings(template);
                }
                
                // Load into canvas - check if cardEditor is ready
                if (!(this.cardEditor && this.cardEditor.isReady)) {
                    return Promise.resolve();
                }
                
                var self = this;
                this.cardEditor.clear();
                
                var chain = Promise.resolve();

                if (!(options && options.skipResize)) {
                    chain = chain.then(function() { return self.resizeCanvas(); });
                }
                
                // Load background, restoring any saved transform (position,
                // scale, rotation) the user applied previously.
                var bgUrl = this.getBackgroundUrl(template);
                if (bgUrl) {
                    var savedTransform = template.backgroundTransform
                        || (template.settings && template.settings.backgroundTransform)
                        || null;
                    chain = chain.then(function() {
                        return self.cardEditor.loadBackground(bgUrl, savedTransform);
                    }).then(function() {
                        // Default to locked so clicks go straight to the
                        // text fields; user opts-in via the Lock toggle.
                        if (typeof self.cardEditor.setBackgroundLocked === 'function') {
                            self.cardEditor.setBackgroundLocked(self.backgroundLocked);
                        }
                    }).catch(function(e) {
                        console.warn('Background load error:', e);
                    });
                }
                
                // Wait for fonts to be ready before adding text fields
                chain = chain.then(function() {
                    if (document.fonts && document.fonts.ready) {
                        return document.fonts.ready;
                    }
                    return Promise.resolve();
                });
                
                chain = chain.then(function() {
                    // Add text fields
                    for (var key in template.fields) {
                        if (!Object.prototype.hasOwnProperty.call(template.fields, key)) continue;
                        if (key === 'qr_code') continue;
                        var field = template.fields[key];
                        if (!field.enabled) continue;

                        // Pick the text the field should display in the
                        // sample preview:
                        //   1. is_static fields (brand decoration like
                        //      "An Omantel Company", "@otech") render
                        //      their detected_text verbatim.
                        //   2. typed fields (name_en, mobile, ...) use
                        //      the sample employee profile.
                        //   3. fields the editor has no sample for fall
                        //      back to the field's detected_text if the
                        //      template was imported from a PDF, otherwise
                        //      they are skipped (no more "social" /
                        //      "static_2" key names rendered as text).
                        var textToRender;
                        if (field.is_static) {
                            textToRender = (field.detected_text || '').trim();
                        } else {
                            var sampleMap = self.getSampleText(key);
                            // getSampleText returns the key itself for
                            // unknown fields; treat that as no sample.
                            textToRender = (sampleMap && sampleMap !== key)
                                ? sampleMap
                                : (field.detected_text || '').trim();
                        }
                        if (!textToRender) continue;

                        // Determine text alignment - use stored value or default based on field type
                        var textAlign = field.textAlign || (key.endsWith('_ar') ? 'right' : 'left');
                        var originX = field.originX || (textAlign === 'center' ? 'center' : (textAlign === 'right' ? 'right' : 'left'));

                        self.cardEditor.addTextField(key, {
                            text: textToRender,
                            x: field.x,
                            y: field.y,
                            fontSize: field.fontSize,
                            fontFamily: field.fontFamily,
                            fontWeight: field.fontWeight || 'normal',
                            fontStyle: field.fontStyle || (field.italic ? 'italic' : 'normal'),
                            fill: field.fill || field.color || '#333333',
                            textAlign: textAlign,
                            originX: originX
                        });
                    }
                    
                    // Force re-render after a short delay to ensure fonts are applied
                    setTimeout(function() {
                        if (self.cardEditor && self.cardEditor.canvas) {
                            // Update all text field coords and mark dirty
                            var fields = self.cardEditor.getFields();
                            for (var fieldKey in fields) {
                                var fieldObj = fields[fieldKey];
                                if (fieldObj && fieldObj.fieldType === 'text') {
                                    fieldObj.set('dirty', true);
                                    fieldObj.setCoords();
                                }
                            }
                            self.cardEditor.canvas.requestRenderAll();
                        }
                    }, 150);
                });
                
                // Add QR code if enabled
                if (template.fields.qr_code && template.fields.qr_code.enabled) {
                    chain = chain.then(function() {
                        var vcfUrl = 'https://' + self.companySlug + '.' + self.apexHost + '/' + encodeURIComponent(self.sampleEmployee.email || 'demo@example.com') + '.vcf';
                        return self.cardEditor.addQRCode(vcfUrl, {
                            x: template.fields.qr_code.x,
                            y: template.fields.qr_code.y,
                            size: template.fields.qr_code.size
                        });
                    });
                }
                
                return chain;
            },
            
            getSampleText: function(key) {
                var map = {
                    'name_en': this.sampleEmployee.name_en || 'John Doe',
                    'name_ar': this.sampleEmployee.name_ar || 'جون دو',
                    'position_en': this.sampleEmployee.position_en || 'Position',
                    'position_ar': this.sampleEmployee.position_ar || 'المنصب',
                    'department_en': this.sampleEmployee.department_en || 'Department',
                    'department_ar': this.sampleEmployee.department_ar || 'القسم',
                    'company_en': this.sampleEmployee.company_en || 'Company',
                    'company_ar': this.sampleEmployee.company_ar || 'الشركة',
                    'phone': this.sampleEmployee.phone || '+968 1234 5678',
                    'phone_ar': this.sampleEmployee.phone_ar || '٩٦٨+ ١٢٣٤ ٥٦٧٨',
                    'fax': this.sampleEmployee.fax || '+968 1234 5679',
                    'fax_ar': this.sampleEmployee.fax_ar || '٩٦٨+ ١٢٣٤ ٥٦٧٩',
                    'mobile': this.sampleEmployee.mobile || '+968 9876 5432',
                    'mobile_ar': this.sampleEmployee.mobile_ar || '٩٦٨+ ٩٨٧٦ ٥٤٣٢',
                    'email': this.sampleEmployee.email || 'email@company.com',
                    'website': this.sampleEmployee.website || 'www.company.com',
                    'website_ar': this.sampleEmployee.website_ar || 'www.company.com',
                    'address': this.sampleEmployee.address_en_2 || this.sampleEmployee.address || 'Muscat, Sultanate of Oman',
                    'address_en': this.sampleEmployee.address_en || 'P.O. Box : 2555, P.C : 112, Ruwi',
                    'address_en_2': this.sampleEmployee.address_en_2 || 'Muscat, Sultanate of Oman',
                    'address_ar': this.sampleEmployee.address_ar || 'ص.ب: ٢٥٥٥، الرمز البريدي: ١١٢، روي',
                    'address_ar_2': this.sampleEmployee.address_ar_2 || 'مسقط، سلطنة عُمان'
                };
                return map[key] || key;
            },
            
            // Cycle to next sample profile and refresh preview
            nextSampleProfile: function() {
                if (!this.sampleProfiles || this.sampleProfiles.length === 0) return;
                
                // Move to next profile (loop back to start)
                this.currentProfileIndex = (this.currentProfileIndex + 1) % this.sampleProfiles.length;
                var profile = this.sampleProfiles[this.currentProfileIndex];
                
                // Convert numbers to Arabic numerals for Arabic fields
                var toArabicNumerals = function(str) {
                    if (!str) return str;
                    return str.replace(/[0-9]/g, function(d) {
                        return String.fromCharCode(d.charCodeAt(0) + 1584);
                    });
                };
                
                // Update sampleEmployee with new profile (all fields)
                this.sampleEmployee = {
                    name_en: profile.name_en,
                    name_ar: profile.name_ar,
                    position_en: profile.position_en,
                    position_ar: profile.position_ar,
                    department_en: profile.department_en || '',
                    department_ar: profile.department_ar || '',
                    company_en: profile.company_en,
                    company_ar: profile.company_ar,
                    phone: profile.phone,
                    phone_ar: toArabicNumerals(profile.phone),
                    fax: profile.fax || '',
                    fax_ar: toArabicNumerals(profile.fax || ''),
                    mobile: profile.mobile,
                    mobile_ar: toArabicNumerals(profile.mobile),
                    email: profile.email,
                    website: profile.website,
                    website_ar: profile.website,
                    address: profile.address_en_2 || 'Muscat, Sultanate of Oman',
                    address_en: profile.address_en,
                    address_en_2: profile.address_en_2 || 'Muscat, Sultanate of Oman',
                    address_ar: profile.address_ar,
                    address_ar_2: profile.address_ar_2 || 'مسقط، سلطنة عُمان'
                };
                
                // Refresh canvas with new sample data
                this.refreshPreviewText();
            },
            
            // Refresh preview text on canvas with current sample data.
            // Static fields (brand decoration imported from a PDF) keep
            // their detected_text on every profile cycle so the design
            // never drifts when the user previews different employees.
            refreshPreviewText: function() {
                if (!this.cardEditor || !this.selectedTemplate) return;

                var fields = this.cardEditor.getFields();
                var tplFields = (this.selectedTemplate && this.selectedTemplate.fields) || {};

                for (var key in fields) {
                    var fieldObj = fields[key];
                    if (!fieldObj || fieldObj.fieldType !== 'text') continue;
                    var tplField = tplFields[key];

                    var newText;
                    if (tplField && tplField.is_static) {
                        newText = (tplField.detected_text || '').trim();
                    } else {
                        var sample = this.getSampleText(key);
                        newText = (sample && sample !== key)
                            ? sample
                            : (tplField && (tplField.detected_text || '').trim()) || '';
                    }
                    if (!newText) continue;

                    fieldObj.set('text', newText);
                    fieldObj.set('dirty', true);
                    fieldObj.setCoords();
                }

                this.cardEditor.canvas.requestRenderAll();
            },
            
            getBackgroundUrl: function(template) {
                if (!template || !template.backgroundImage) return '';
                var raw = String(template.backgroundImage);
                // Guard against stray HTML/expressions landing in the field.
                // A render-timing race was producing stray 404s on URLs that
                // looked like HTML fragments; belt-and-braces reject here.
                if (raw.indexOf('<') !== -1 || raw.indexOf('>') !== -1) return '';
                if (!/^[\/A-Za-z0-9][\w\-\.\/:]*$/.test(raw.replace(/^\//, ''))) return '';
                var path = raw.replace(/^\//, '');
                return this.basePath + path;
            },
            
            isTemplateActive: function(template) {
                if (!template) return false;
                if (template.side === 'front') {
                    return template.id === this.activeFrontId;
                }
                return template.id === this.activeBackId;
            },
            
            getFieldLabel: function(key) {
                // Imported PDF designs may attach a friendly label per field
                // (e.g., "Decoration: An Omantel Company") so the static_N
                // keys are readable in the editor's field list.
                if (this.selectedTemplate && this.selectedTemplate.fields
                    && this.selectedTemplate.fields[key]
                    && this.selectedTemplate.fields[key].label) {
                    return this.selectedTemplate.fields[key].label;
                }
                var labels = {
                    'name_en': 'Name (EN)', 'name_ar': 'الاسم (AR)',
                    'position_en': 'Position (EN)', 'position_ar': 'المنصب (AR)',
                    'company_en': 'Company (EN)', 'company_ar': 'الشركة (AR)',
                    'phone': 'Phone (EN)', 'phone_ar': 'الهاتف (AR)',
                    'mobile': 'Mobile (EN)', 'mobile_ar': 'الجوال (AR)',
                    'email': 'Email',
                    'website': 'Website (EN)', 'website_ar': 'الموقع (AR)',
                    'address': 'Address 02', 'address_en': 'Address 01 (EN)', 'address_en_2': 'Address 02 (EN)', 'address_ar': 'Address 01 (AR)', 'address_ar_2': 'Address 02 (AR)',
                    'qr_code': 'QR Code'
                };
                return labels[key] || key;
            },
            
            toggleField: function(key, enabled) {
                if (!this.selectedTemplate || !this.selectedTemplate.fields[key]) return;
                
                this.selectedTemplate.fields[key].enabled = enabled;
                
                if (this.cardEditor) {
                    if (key === 'qr_code') {
                        if (enabled) {
                            var field = this.selectedTemplate.fields.qr_code;
                            var vcfUrl = 'https://' + this.companySlug + '.' + this.apexHost + '/' + encodeURIComponent(this.sampleEmployee.email || 'demo@example.com') + '.vcf';
                            // Use sensible defaults if position is missing or invalid
                            var dims = this.getCanvasDimensions();
                            var x = (field.x > 0 && field.x < dims.width) ? field.x : dims.width - 180;
                            var y = (field.y > 0 && field.y < dims.height) ? field.y : dims.height / 2 - 50;
                            this.cardEditor.addQRCode(vcfUrl, {
                                x: x,
                                y: y,
                                size: field.size || 100
                            });
                            // Update the field with new position
                            this.selectedTemplate.fields.qr_code.x = x;
                            this.selectedTemplate.fields.qr_code.y = y;
                        } else {
                            this.cardEditor.removeField('qr_code');
                        }
                    } else {
                        if (enabled) {
                            var field = this.selectedTemplate.fields[key];
                            var dims = this.getCanvasDimensions();

                            // Alignment/origin first so we can pick a sane
                            // default X relative to the *current* canvas.
                            var textAlign = field.textAlign || (key.endsWith('_ar') ? 'right' : 'left');
                            var originX = field.originX || (textAlign === 'center' ? 'center' : (textAlign === 'right' ? 'right' : 'left'));

                            // Role-based vertical slot so name/position/company
                            // each land in their own lane when first enabled.
                            var verticalSlot = {
                                name_en: 60,      name_ar: 60,
                                position_en: 110, position_ar: 110,
                                department_en: 155, department_ar: 155,
                                company_en: 195,  company_ar: 195,
                                phone: 245,       phone_ar: 245,
                                mobile: 285,      mobile_ar: 285,
                                fax: 325,         fax_ar: 325,
                                email: 365,
                                website: 405,     website_ar: 405,
                                address: 445,     address_en: 445, address_ar: 445
                            };
                            var defaultY = verticalSlot[key] || 60;

                            // Default X depends on alignment so right-aligned
                            // Arabic text actually hugs the right edge of the
                            // current card, not a hard-coded 1000px.
                            var insetX = 60;
                            var defaultX;
                            if (originX === 'right') defaultX = dims.width - insetX;
                            else if (originX === 'center') defaultX = dims.width / 2;
                            else defaultX = insetX;

                            // Trust stored coords only if they fall inside
                            // the current canvas and make sense for this
                            // origin (right-origin needs x > 0, left-origin
                            // needs x < canvas.width).
                            var xValid = typeof field.x === 'number' && field.x > 10 && field.x < dims.width - 10;
                            var yValid = typeof field.y === 'number' && field.y > 0 && field.y < dims.height - 10;
                            var x = xValid ? field.x : defaultX;
                            var y = yValid ? field.y : defaultY;

                            var fontSize = field.fontSize || 16;
                            var fontFamily = field.fontFamily || (key.endsWith('_ar') ? 'Cairo' : 'Inter');
                            var fill = field.fill || field.color || '#333333';
                            
                            // Static / decoration fields keep their detected text
                            // verbatim across every employee preview.
                            var fieldText;
                            if (field.is_static) {
                                fieldText = (field.detected_text || '').trim();
                            } else {
                                var sm = this.getSampleText(key);
                                fieldText = (sm && sm !== key)
                                    ? sm
                                    : (field.detected_text || '').trim();
                            }
                            // No text to render means an unmapped field.
                            // Bail out before touching the canvas; this is
                            // a function body, not a loop, so we return.
                            if (!fieldText) return;

                            this.cardEditor.addTextField(key, {
                                text: fieldText,
                                x: x,
                                y: y,
                                fontSize: fontSize,
                                fontFamily: fontFamily,
                                fontWeight: field.fontWeight || 'normal',
                                fontStyle: field.fontStyle || (field.italic ? 'italic' : 'normal'),
                                fill: fill,
                                textAlign: textAlign,
                                originX: originX
                            });
                            
                            // Update the field with new position if it was missing
                            this.selectedTemplate.fields[key].x = x;
                            this.selectedTemplate.fields[key].y = y;
                            this.selectedTemplate.fields[key].fontSize = fontSize;
                            this.selectedTemplate.fields[key].fontFamily = fontFamily;
                            this.selectedTemplate.fields[key].textAlign = textAlign;
                            this.selectedTemplate.fields[key].originX = originX;
                            this.selectedTemplate.fields[key].fill = fill;
                            
                            // Force re-render to ensure font is applied
                            var self = this;
                            setTimeout(function() {
                                var fieldObj = self.cardEditor.fields[key];
                                if (fieldObj) {
                                    fieldObj.set('dirty', true);
                                    fieldObj.setCoords();
                                    self.cardEditor.canvas.requestRenderAll();
                                }
                            }, 100);
                        } else {
                            this.cardEditor.removeField(key);
                        }
                    }
                }
            },
            
            updateFieldProperty: function(key, property, value) {
                if (!this.selectedTemplate || !this.selectedTemplate.fields[key]) return;
                
                this.selectedTemplate.fields[key][property] = value;
                
                // Also update color for backward compat
                if (property === 'fill') {
                    this.selectedTemplate.fields[key].color = value;
                }
                
                // Update originX when textAlign changes for proper positioning
                if (property === 'textAlign') {
                    var originX = 'left';
                    if (value === 'center') originX = 'center';
                    if (value === 'right') originX = 'right';
                    this.selectedTemplate.fields[key].originX = originX;
                    
                    // Use specialized alignment method if available
                    if (this.cardEditor && this.cardEditor.setFieldAlignment) {
                        this.cardEditor.setFieldAlignment(key, value);
                        return;
                    }
                }
                
                if (this.cardEditor) {
                    var updatePayload = {};
                    updatePayload[property] = value;
                    this.cardEditor.updateField(key, updatePayload);
                }
            },
            
            updateQRSize: function(size) {
                if (!this.selectedTemplate || !this.selectedTemplate.fields.qr_code) return;
                
                this.selectedTemplate.fields.qr_code.size = size;
                
                if (this.cardEditor) {
                    this.cardEditor.updateQRCode({ size: size });
                }
            },
            
            // Font selector methods
            filteredFonts: function(searchTerm) {
                var search = (searchTerm || '').toLowerCase();
                if (!search) return this.fontCategories;
                var result = {};
                var self = this;
                Object.keys(this.fontCategories).forEach(function(cat) {
                    var fonts = self.fontCategories[cat];
                    var filtered = fonts.filter(function(f) { return f.toLowerCase().indexOf(search) !== -1; });
                    if (filtered.length > 0) result[cat] = filtered;
                });
                return result;
            },
            
            selectFont: function(key, fontName) {
                var self = this;
                // Update immediately for responsive UI
                this.updateFieldProperty(key, 'fontFamily', fontName);
                
                // Also trigger canvas re-render after a short delay to ensure font is loaded
                setTimeout(function() {
                    if (self.cardEditor && self.cardEditor.canvas) {
                        self.cardEditor.canvas.requestRenderAll();
                    }
                }, 100);
            },
            
            handleNewTemplateImage: function(event) {
                var file = event.target.files[0];
                if (file) this.newTemplate.imageFile = file;
            },
            
            addTemplate: function() {
                if (!this.newTemplate.name || !this.newTemplate.imageFile) {
                    this.showStatus('Please fill all required fields', 'error');
                    return;
                }
                
                var formData = this.newFormData('add');
                formData.append('name', this.newTemplate.name);
                formData.append('side', this.newTemplate.side);
                formData.append('image', this.newTemplate.imageFile);
                formData.append('fields', JSON.stringify(<?php echo json_encode(getDefaultFieldSettings(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>));
                formData.append('settings', JSON.stringify(this.getTemplateSettings()));
                
                var self = this;
                fetch('save_template', { method: 'POST', body: formData })
                    .then(function(response) { return response.json(); })
                    .then(function(result) {
                        if (result.success) {
                            self.templates.push(result.template);
                            self.showAddModal = false;
                            self.newTemplate = { name: '', side: 'front', imageFile: null };
                            self.showStatus('Template created successfully', 'success');
                            self.selectTemplate(result.template);
                        } else {
                            self.showStatus(result.error || 'Failed to create template', 'error');
                        }
                    })
                    .catch(function(error) {
                        console.error('Add template error:', error);
                        self.showStatus('Error creating template', 'error');
                    });
            },
            
            saveTemplate: function() {
                if (!this.selectedTemplate) return;
                
                // Get updated positions from canvas
                if (this.cardEditor) {
                    var fieldKeys = Object.keys(this.selectedTemplate.fields);
                    for (var i = 0; i < fieldKeys.length; i++) {
                        var key = fieldKeys[i];
                        var pos = this.cardEditor.getFieldPosition(key);
                        if (pos) {
                            this.selectedTemplate.fields[key].x = pos.x;
                            this.selectedTemplate.fields[key].y = pos.y;
                            if (key === 'qr_code' && pos.size) {
                                this.selectedTemplate.fields[key].size = pos.size;
                            } else if (pos.fontSize) {
                                this.selectedTemplate.fields[key].fontSize = pos.fontSize;
                            }
                        }
                    }
                }
                
                var formData = this.newFormData('update');
                formData.append('id', this.selectedTemplate.id);
                formData.append('name', this.selectedTemplate.name);
                formData.append('fields', JSON.stringify(this.selectedTemplate.fields));
                formData.append('settings', JSON.stringify(this.getTemplateSettings()));
                
                var self = this;
                fetch('save_template', { method: 'POST', body: formData })
                    .then(function(response) { return response.json(); })
                    .then(function(result) {
                        self.showStatus(result.success ? 'Template saved successfully' : (result.error || 'Failed to save'), result.success ? 'success' : 'error');
                    })
                    .catch(function(error) {
                        console.error('Save template error:', error);
                        self.showStatus('Error saving template', 'error');
                    });
            },
            
            setActiveTemplate: function() {
                if (!this.selectedTemplate) return;
                
                var formData = this.newFormData('activate');
                formData.append('id', this.selectedTemplate.id);
                formData.append('side', this.selectedTemplate.side);
                
                var self = this;
                fetch('save_template', { method: 'POST', body: formData })
                    .then(function(response) { return response.json(); })
                    .then(function(result) {
                        if (result.success) {
                            if (self.selectedTemplate.side === 'front') {
                                self.activeFrontId = self.selectedTemplate.id;
                            } else {
                                self.activeBackId = self.selectedTemplate.id;
                            }
                            self.showStatus('Template set as active', 'success');
                        } else {
                            self.showStatus(result.error || 'Failed to activate', 'error');
                        }
                    })
                    .catch(function(error) {
                        console.error('Activate template error:', error);
                        self.showStatus('Error activating template', 'error');
                    });
            },
            
            setAsCompanyDefault: function() {
                if (!this.selectedTemplate) {
                    this.showStatus('Select a template first', 'error');
                    return;
                }
                if (!confirm(<?= json_encode(t('dashboard.set_default_confirm')) ?>)) return;
                var formData = this.newFormData('set_as_default');
                formData.append('id', this.selectedTemplate.id);
                formData.append('side', this.selectedTemplate.side);
                var self = this;
                fetch('save_template', { method: 'POST', body: formData })
                    .then(function (r) { return r.json(); })
                    .then(function (j) {
                        if (j.success) {
                            self.showStatus(<?= json_encode(t('dashboard.set_default_success')) ?>, 'success');
                        } else {
                            self.showStatus(j.error || <?= json_encode(t('dashboard.set_default_fail')) ?>, 'error');
                        }
                    })
                    .catch(function (e) {
                        console.error('set_as_default error', e);
                        self.showStatus(<?= json_encode(t('dashboard.set_default_fail')) ?>, 'error');
                    });
            },

            deleteTemplate: function(id) {
                if (!confirm('Are you sure you want to delete this template?')) return;

                var formData = this.newFormData('delete');
                formData.append('id', id);

                var self = this;
                fetch('save_template', { method: 'POST', body: formData })
                    .then(function(response) { return response.json(); })
                    .then(function(result) {
                        if (result.success) {
                            self.templates = self.templates.filter(function(t) { return t.id !== id; });
                            if (self.selectedTemplate && self.selectedTemplate.id === id) {
                                self.selectedTemplate = null;
                                if (self.cardEditor) {
                                    self.cardEditor.clear();
                                }
                            }
                            self.showStatus('Template deleted', 'success');
                        } else {
                            self.showStatus(result.error || 'Failed to delete', 'error');
                        }
                    })
                    .catch(function(error) {
                        console.error('Delete template error:', error);
                        self.showStatus('Error deleting template', 'error');
                    });
            },
            
            exportPNG: function() {
                if (!this.cardEditor || !this.selectedTemplate) return;
                
                // Export at 3x for print quality (~300 DPI)
                var dataUrl = this.cardEditor.exportPNG(3);
                var link = document.createElement('a');
                link.download = (this.selectedTemplate.name || 'card') + '.png';
                link.href = dataUrl;
                link.click();
                
                this.showStatus('PNG exported successfully', 'success');
            },
            
            exportPDF: function() {
                if (!this.cardEditor || !this.selectedTemplate) return;
                
                try {
                    this.cardEditor.exportPDF((this.selectedTemplate.name || 'card') + '.pdf');
                    this.showStatus('PDF downloaded', 'success');
                } catch (e) {
                    console.error('PDF export error:', e);
                    this.showStatus('Error downloading PDF', 'error');
                }
            },
            
            // Automatically uses best quality available (hybrid: vector bg + raster text)
            exportBestPDF: async function() {
                if (!this.cardEditor || !this.selectedTemplate) {
                    return;
                }
                
                var self = this;
                var originalPdfUrl = this.selectedTemplate.originalPdf;
                var filename = (this.selectedTemplate.name || 'card') + '.pdf';
                
                // If original PDF exists and PDF-lib is loaded, use hybrid export
                if (originalPdfUrl && typeof PDFLib !== 'undefined') {
                    try {
                        self.showStatus('Generating high-quality PDF...', 'info');
                        
                        // Use hybrid export: vector PDF background + 600 DPI text overlay
                        const pdfBlob = await this.cardEditor.exportHybridPDFBlob(originalPdfUrl);
                        
                        if (pdfBlob) {
                            // Download the PDF
                            const link = document.createElement('a');
                            link.href = URL.createObjectURL(pdfBlob);
                            link.download = filename;
                            link.click();
                            URL.revokeObjectURL(link.href);
                            self.showStatus('High-quality PDF downloaded', 'success');
                        } else {
                            // Fallback to standard PDF
                            this.exportPDF();
                        }
                    } catch (e) {
                        console.error('Hybrid PDF export error:', e);
                        // Fallback to standard PDF
                        this.exportPDF();
                    }
                } else {
                    // Standard PDF export (rasterized)
                    this.exportPDF();
                }
            },
            
            // Align selected objects
            alignSelected: function(direction) {
                console.log('=== alignSelected called with direction:', direction, '===');
                
                if (!this.cardEditor || !this.cardEditor.canvas) {
                    console.log('No canvas!');
                    return;
                }
                
                var canvas = this.cardEditor.canvas;
                var activeObjects = canvas.getActiveObjects();
                
                console.log('Active objects:', activeObjects.length);
                
                if (activeObjects.length === 0) {
                    this.showStatus('Click a field on the card first, then press the align button', 'error');
                    return;
                }
                
                var canvasWidth = canvas.width;
                var canvasHeight = canvas.height;
                console.log('Canvas size:', canvasWidth, 'x', canvasHeight);
                
                if (activeObjects.length === 1) {
                    // Align to canvas
                    var obj = activeObjects[0];
                    var bounds = obj.getBoundingRect();
                    var originX = obj.originX || 'left';
                    var originY = obj.originY || 'top';
                    var textAlign = obj.textAlign || 'left';
                    
                    console.log('Object details:', {
                        fieldKey: obj.fieldKey,
                        originX: originX,
                        textAlign: textAlign,
                        currentLeft: obj.left,
                        boundsWidth: bounds.width
                    });
                    
                    switch(direction) {
                        case 'left':
                            if (originX === 'center') {
                                obj.set('left', 20 + bounds.width / 2);
                            } else if (originX === 'right') {
                                obj.set('left', 20 + bounds.width);
                            } else {
                                obj.set('left', 20);
                            }
                            break;
                        case 'center-h':
                            // For center alignment, position depends on originX
                            var newLeft;
                            if (originX === 'center') {
                                newLeft = canvasWidth / 2;
                                console.log('originX is center -> setting left to canvasWidth/2 =', newLeft);
                            } else if (originX === 'right') {
                                newLeft = canvasWidth / 2 + bounds.width / 2;
                                console.log('originX is right -> setting left to', newLeft);
                            } else {
                                newLeft = (canvasWidth - bounds.width) / 2;
                                console.log('originX is left -> setting left to (canvasWidth - boundsWidth) / 2 =', newLeft);
                            }
                            obj.set('left', newLeft);
                            break;
                        case 'right':
                            if (originX === 'center') {
                                obj.set('left', canvasWidth - 20 - bounds.width / 2);
                            } else if (originX === 'right') {
                                obj.set('left', canvasWidth - 20);
                            } else {
                                obj.set('left', canvasWidth - bounds.width - 20);
                            }
                            break;
                        case 'top':
                            if (originY === 'center') {
                                obj.set('top', 20 + bounds.height / 2);
                            } else if (originY === 'bottom') {
                                obj.set('top', 20 + bounds.height);
                            } else {
                                obj.set('top', 20);
                            }
                            break;
                        case 'center-v':
                            if (originY === 'center') {
                                obj.set('top', canvasHeight / 2);
                            } else if (originY === 'bottom') {
                                obj.set('top', canvasHeight / 2 + bounds.height / 2);
                            } else {
                                obj.set('top', (canvasHeight - bounds.height) / 2);
                            }
                            break;
                        case 'bottom':
                            if (originY === 'center') {
                                obj.set('top', canvasHeight - 20 - bounds.height / 2);
                            } else if (originY === 'bottom') {
                                obj.set('top', canvasHeight - 20);
                            } else {
                                obj.set('top', canvasHeight - bounds.height - 20);
                            }
                            break;
                    }
                } else {
                    // Align to each other (use first selected as reference)
                    var refObj = activeObjects[0];
                    var refBounds = refObj.getBoundingRect();
                    
                    for (var i = 1; i < activeObjects.length; i++) {
                        var obj = activeObjects[i];
                        var bounds = obj.getBoundingRect();
                        
                        switch(direction) {
                            case 'left':
                                obj.set('left', refObj.left);
                                break;
                            case 'center-h':
                                obj.set('left', refObj.left + (refBounds.width - bounds.width) / 2);
                                break;
                            case 'right':
                                obj.set('left', refObj.left + refBounds.width - bounds.width);
                                break;
                            case 'top':
                                obj.set('top', refObj.top);
                                break;
                            case 'center-v':
                                obj.set('top', refObj.top + (refBounds.height - bounds.height) / 2);
                                break;
                            case 'bottom':
                                obj.set('top', refObj.top + refBounds.height - bounds.height);
                                break;
                        }
                    }
                }
                
                // Update template field positions for all aligned objects
                var self = this;
                activeObjects.forEach(function(obj) {
                    if (obj.fieldKey && self.selectedTemplate && self.selectedTemplate.fields[obj.fieldKey]) {
                        var oldX = self.selectedTemplate.fields[obj.fieldKey].x;
                        var oldY = self.selectedTemplate.fields[obj.fieldKey].y;
                        self.selectedTemplate.fields[obj.fieldKey].x = Math.round(obj.left);
                        self.selectedTemplate.fields[obj.fieldKey].y = Math.round(obj.top);
                        console.log('Updated field "' + obj.fieldKey + '": x=' + oldX + ' -> ' + Math.round(obj.left) + ', y=' + oldY + ' -> ' + Math.round(obj.top));
                    }
                });
                
                canvas.renderAll();
                this.showStatus('Elements aligned', 'success');
            },
            
            // Distribute selected objects evenly
            distributeSelected: function(direction) {
                if (!this.cardEditor || !this.cardEditor.canvas) return;
                
                var canvas = this.cardEditor.canvas;
                var activeObjects = canvas.getActiveObjects();
                
                if (activeObjects.length < 3) {
                    this.showStatus('Select 3 or more elements to distribute', 'error');
                    return;
                }
                
                // Sort objects by position
                if (direction === 'horizontal') {
                    activeObjects.sort(function(a, b) { return a.left - b.left; });
                    
                    var first = activeObjects[0];
                    var last = activeObjects[activeObjects.length - 1];
                    var totalWidth = 0;
                    
                    activeObjects.forEach(function(obj) {
                        totalWidth += obj.getBoundingRect().width;
                    });
                    
                    var availableSpace = (last.left + last.getBoundingRect().width) - first.left - totalWidth;
                    var spacing = availableSpace / (activeObjects.length - 1);
                    
                    var currentX = first.left + first.getBoundingRect().width + spacing;
                    for (var i = 1; i < activeObjects.length - 1; i++) {
                        activeObjects[i].set('left', currentX);
                        currentX += activeObjects[i].getBoundingRect().width + spacing;
                    }
                } else {
                    activeObjects.sort(function(a, b) { return a.top - b.top; });
                    
                    var first = activeObjects[0];
                    var last = activeObjects[activeObjects.length - 1];
                    var totalHeight = 0;
                    
                    activeObjects.forEach(function(obj) {
                        totalHeight += obj.getBoundingRect().height;
                    });
                    
                    var availableSpace = (last.top + last.getBoundingRect().height) - first.top - totalHeight;
                    var spacing = availableSpace / (activeObjects.length - 1);
                    
                    var currentY = first.top + first.getBoundingRect().height + spacing;
                    for (var i = 1; i < activeObjects.length - 1; i++) {
                        activeObjects[i].set('top', currentY);
                        currentY += activeObjects[i].getBoundingRect().height + spacing;
                    }
                }
                
                canvas.renderAll();
                this.showStatus('Elements distributed', 'success');
            },
            
            showStatus: function(message, type) {
                var self = this;
                self.statusMessage = message;
                self.statusType = type;
                setTimeout(function() { self.statusMessage = ''; }, 3000);
            }
        };
    }
</script>

<?php adminFooter(); ?>
