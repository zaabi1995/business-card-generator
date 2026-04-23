<?php
/**
 * Employee Portal - Self-Service Business Card Request
 * Route: /{company_slug}/portal
 * 
 * Employees can submit their information for business card generation.
 * Only emails matching the company domain are allowed.
 */
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/Mailer.php';

// Get company slug and optional department slug from URL
$companySlug = $_GET['company_slug'] ?? '';
$companySlug = trim(strtolower($companySlug));
$departmentSlug = $_GET['department_slug'] ?? '';
$departmentSlug = trim(strtolower($departmentSlug));

if (empty($companySlug)) {
    http_response_code(404);
    include __DIR__ . '/404.php';
    exit;
}

// Find company
$company = findCompanyBySlug($companySlug);
if (!$company) {
    http_response_code(404);
    include __DIR__ . '/404.php';
    exit;
}

// Check if portal is enabled for this company
if (isset($company['portal_enabled']) && !$company['portal_enabled']) {
    $portalDisabled = true;
}

$companyId = $company['id'];

// Check preview setting
$showPreview = ($company['portal_show_preview'] ?? 1) == 1;
$companyName = $company['name_en'] ?? $company['name'] ?? $companySlug;
$companyDomain = $company['email_domain'] ?? extractEmailDomain($company['admin_email'] ?? '');

// Get departments and validate department slug FIRST (before passcode check)
$departments = [];
$selectedDepartment = null;
$companyTheme = null;
$db = Database::getInstance();
if ($db->isConnected()) {
    // Get company theme (for logo)
    try {
        $companyTheme = $db->fetchOne(
            "SELECT * FROM company_themes WHERE company_id = :id",
            ['id' => $companyId]
        );
    } catch (Exception $e) {
        // company_themes table might not exist yet
    }
    
    $departments = $db->fetchAll(
        "SELECT id, name, slug, template_pair_id, portal_passcode FROM departments WHERE company_id = :id ORDER BY name",
        ['id' => $companyId]
    );
    
    // If department slug is provided, find and validate it
    if (!empty($departmentSlug)) {
        foreach ($departments as $dept) {
            if (($dept['slug'] ?? '') === $departmentSlug) {
                $selectedDepartment = $dept;
                break;
            }
        }
        // If department not found, show error
        if (!$selectedDepartment) {
            http_response_code(404);
            include __DIR__ . '/404.php';
            exit;
        }
    }
}

// Check for passcode protection (department passcode takes precedence when viewing department portal)
$portalPasscode = null;
$passcodeType = null; // 'department' or 'company'

// If viewing a department portal, check department passcode first
if ($selectedDepartment && !empty($selectedDepartment['portal_passcode'])) {
    $portalPasscode = $selectedDepartment['portal_passcode'];
    $passcodeType = 'department';
}
// Fall back to company passcode if no department passcode
elseif (!empty($company['portal_passcode'])) {
    $portalPasscode = $company['portal_passcode'];
    $passcodeType = 'company';
}

$passcodeRequired = !empty($portalPasscode);
$passcodeVerified = false;
$passcodeError = null;

if ($passcodeRequired) {
    // Check if passcode is already verified in session
    // Use different session keys for department vs company access
    $sessionKey = $passcodeType === 'department' 
        ? 'portal_passcode_dept_' . $selectedDepartment['id']
        : 'portal_passcode_' . $companyId;
    $passcodeVerified = isset($_SESSION[$sessionKey]) && $_SESSION[$sessionKey] === true;
    
    // Handle passcode form submission
    if (!$passcodeVerified && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['portal_passcode'])) {
        if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
            $passcodeError = 'Invalid request. Please try again.';
        } else {
        $submittedPasscode = trim($_POST['portal_passcode']);
        if (hash_equals($portalPasscode, $submittedPasscode)) {
            $_SESSION[$sessionKey] = true;
            $passcodeVerified = true;
            // Redirect to remove POST data
            header('Location: ' . $_SERVER['REQUEST_URI']);
            exit;
        } else {
            $passcodeError = 'Incorrect access code. Please try again.';
        }
        } // end CSRF else
    }
}

// Get templates - use department-specific template if available, otherwise company default
$activeFrontTemplate = null;
$activeBackTemplate = null;
$templateSource = 'company';

// Check if selected department has a custom template pair
if ($selectedDepartment && !empty($selectedDepartment['template_pair_id']) && $db->isConnected()) {
    // Get templates from department's template pair
    $frontTemplate = $db->fetchOne(
        "SELECT * FROM templates WHERE pair_id = :pid AND side = 'front'",
        ['pid' => $selectedDepartment['template_pair_id']]
    );
    $backTemplate = $db->fetchOne(
        "SELECT * FROM templates WHERE pair_id = :pid AND side = 'back'",
        ['pid' => $selectedDepartment['template_pair_id']]
    );
    
    if ($frontTemplate || $backTemplate) {
        // Parse fields_json for both templates
        if ($frontTemplate) {
            $activeFrontTemplate = [
                'id' => $frontTemplate['id'],
                'pair_id' => $frontTemplate['pair_id'] ?? null,
                'name' => $frontTemplate['name'],
                'side' => 'front',
                'backgroundImage' => $frontTemplate['background_image_path'],
                'originalPdf' => $frontTemplate['original_pdf_path'] ?? null,
                'fields' => json_decode($frontTemplate['fields_json'], true) ?: [],
                'settings' => isset($frontTemplate['settings_json']) ? json_decode($frontTemplate['settings_json'], true) : null
            ];
        }
        if ($backTemplate) {
            $activeBackTemplate = [
                'id' => $backTemplate['id'],
                'pair_id' => $backTemplate['pair_id'] ?? null,
                'name' => $backTemplate['name'],
                'side' => 'back',
                'backgroundImage' => $backTemplate['background_image_path'],
                'originalPdf' => $backTemplate['original_pdf_path'] ?? null,
                'fields' => json_decode($backTemplate['fields_json'], true) ?: [],
                'settings' => isset($backTemplate['settings_json']) ? json_decode($backTemplate['settings_json'], true) : null
            ];
        }
        $templateSource = 'department';
    }
}

// Fall back to company default templates if no department template found
if (!$activeFrontTemplate && !$activeBackTemplate) {
    $activeFrontTemplate = getActiveFrontTemplate($companyId);
    $activeBackTemplate = getActiveBackTemplate($companyId);
}

// Collect enabled fields from templates
$enabledFields = [];
if ($activeFrontTemplate && !empty($activeFrontTemplate['fields'])) {
    foreach ($activeFrontTemplate['fields'] as $key => $field) {
        if (!empty($field['enabled'])) {
            $enabledFields[$key] = true;
        }
    }
}
if ($activeBackTemplate && !empty($activeBackTemplate['fields'])) {
    foreach ($activeBackTemplate['fields'] as $key => $field) {
        if (!empty($field['enabled'])) {
            $enabledFields[$key] = true;
        }
    }
}

// If no templates or no enabled fields, show all common fields
if (empty($enabledFields)) {
    $enabledFields = [
        'name_en' => true,
        'position_en' => true,
        'phone' => true,
        'mobile' => true,
        'email' => true
    ];
}

// Apply theme colors (like main page)
$primaryColor = $companyTheme['primary_color'] ?? '#3b82f6';
$secondaryColor = $companyTheme['secondary_color'] ?? '#036e87';

$success = false;
$error = $error ?? null;

// Prefill form with company-level defaults so staff only type what's personal
// to them. OHB ships with Address 01 = "P.O. Box : 2555, P.C : 112, Ruwi" and
// Address 02 = "Muscat, Sultanate of Oman" seeded on the companies row.
$formData = [
    'address_en'   => $company['default_address_en']   ?? '',
    'address_2_en' => $company['default_address_2_en'] ?? '',
    'address_ar'   => $company['default_address_ar']   ?? '',
    'address_2_ar' => $company['default_address_2_ar'] ?? '',
    'website'      => $company['default_website']      ?? '',
    'fax'          => $company['default_fax']          ?? '',
];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['portal_passcode'])) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please try again.';
    } else {
    $formData = [
        'email' => trim(strtolower($_POST['email'] ?? '')),
        'name_en' => trim($_POST['name_en'] ?? ''),
        'name_ar' => trim($_POST['name_ar'] ?? ''),
        'position_en' => trim($_POST['position_en'] ?? ''),
        'position_ar' => trim($_POST['position_ar'] ?? ''),
        'phone' => trim($_POST['phone'] ?? ''),
        'phone_ar' => trim($_POST['phone_ar'] ?? ''),
        'mobile' => trim($_POST['mobile'] ?? ''),
        'mobile_ar' => trim($_POST['mobile_ar'] ?? ''),
        'fax' => trim($_POST['fax'] ?? ''),
        'fax_ar' => trim($_POST['fax_ar'] ?? ''),
        'website' => trim($_POST['website'] ?? ''),
        'website_ar' => trim($_POST['website_ar'] ?? ''),
        'address_en' => trim($_POST['address_en'] ?? ''),
        'address_2_en' => trim($_POST['address_2_en'] ?? ''),
        'address_ar' => trim($_POST['address_ar'] ?? ''),
        'address_2_ar' => trim($_POST['address_2_ar'] ?? ''),
        'company_en' => trim($_POST['company_en'] ?? '') ?: ($company['name_en'] ?? $companyName),
        'company_ar' => trim($_POST['company_ar'] ?? '') ?: ($company['name_ar'] ?? ''),
        'department_id' => $_POST['department_id'] ?? null,
    ];
    
    // Validate email
    if (empty($formData['email'])) {
        $error = 'Email address is required.';
    } elseif (!isValidEmail($formData['email'])) {
        $error = 'Please enter a valid email address.';
    } else {
        // Check email domain matches company domain
        $emailDomain = extractEmailDomain($formData['email']);
        if ($companyDomain && strtolower($emailDomain) !== strtolower($companyDomain)) {
            $error = "Only @{$companyDomain} email addresses are allowed.";
        }
    }
    
    // Validate name
    if (!$error && empty($formData['name_en'])) {
        $error = 'Name (English) is required.';
    }
    
    // Get request type and notes from form
    $requestType = $_POST['request_type'] ?? 'new';
    $requestNotes = trim($_POST['request_notes'] ?? '');
    $quantityRequested = max(1, (int)($_POST['quantity_requested'] ?? 1));
    
    // Check if email already exists as employee - determine request type
    $existingEmployee = null;
    $isUpdateRequest = false;
    if (!$error) {
        $existingEmployee = findEmployeeByEmail($formData['email'], $companyId);
        if ($existingEmployee) {
            // Validate request type for existing employees
            if (!in_array($requestType, ['update', 'reprint'])) {
                $requestType = 'update'; // Default to update for existing employees
            }
            $isUpdateRequest = true;
        } else {
            // New employees can only submit 'new' requests
            $requestType = 'new';
        }
    }
    
    // Check if there's already a pending request
    if (!$error) {
        $existingRequest = $db->fetchOne(
            "SELECT id FROM card_requests WHERE email = :email AND company_id = :cid AND status = 'pending'",
            ['email' => $formData['email'], 'cid' => $companyId]
        );
        if ($existingRequest) {
            $error = 'You already have a pending request. Please wait for it to be reviewed.';
        }
    }
    
    // Handle photo upload
    $photoPath = null;
    if (!$error && isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $maxSize = 5 * 1024 * 1024; // 5MB
        
        if (!in_array($_FILES['photo']['type'], $allowedTypes)) {
            $error = 'Photo must be a JPEG, PNG, GIF, or WebP image.';
        } elseif ($_FILES['photo']['size'] > $maxSize) {
            $error = 'Photo must be less than 5MB.';
        } else {
            $uploadDir = getCompanyUploadsPath($companyId) . '/photos';
            if (!is_dir($uploadDir)) {
                @mkdir($uploadDir, 0755, true);
            }
            
            $ext = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
            $filename = 'request_' . uniqid() . '.' . $ext;
            $targetPath = $uploadDir . '/' . $filename;
            
            if (move_uploaded_file($_FILES['photo']['tmp_name'], $targetPath)) {
                $photoPath = 'uploads/companies/' . $companyId . '/photos/' . $filename;
            }
        }
    }
    
    // Save request
    if (!$error) {
        $requestId = generateUUID();
        
        // Get preview URLs if generated client-side
        $previewFront = trim($_POST['preview_front'] ?? '');
        $previewBack = trim($_POST['preview_back'] ?? '');
        
        try {
            $insertData = [
                'id' => $requestId,
                'company_id' => $companyId,
                'email' => $formData['email'],
                'name_en' => $formData['name_en'],
                'name_ar' => $formData['name_ar'],
                'position_en' => $formData['position_en'],
                'position_ar' => $formData['position_ar'],
                'phone' => $formData['phone'],
                'phone_ar' => $formData['phone_ar'],
                'mobile' => $formData['mobile'],
                'mobile_ar' => $formData['mobile_ar'],
                'website' => $formData['website'],
                'website_ar' => $formData['website_ar'],
                'address_en' => $formData['address_en'],
                'address_2_en' => $formData['address_2_en'] ?? '',
                'address_ar' => $formData['address_ar'],
                'address_2_ar' => $formData['address_2_ar'] ?? '',
                'company_en' => $formData['company_en'],
                'company_ar' => $formData['company_ar'],
                'department_id' => $formData['department_id'] ?: null,
                'photo' => $photoPath,
                'status' => 'pending',
                'request_type' => $requestType,
                'request_notes' => $requestNotes ?: null,
                'quantity_requested' => $quantityRequested
            ];
            
            // If this is an update/reprint request for existing employee, link them
            if ($isUpdateRequest && $existingEmployee) {
                $insertData['employee_id'] = $existingEmployee['id'];
            }
            
            // Add preview URLs if provided
            if (!empty($previewFront)) {
                $insertData['preview_front'] = $previewFront;
            }
            if (!empty($previewBack)) {
                $insertData['preview_back'] = $previewBack;
            }
            if (!empty($previewFront) || !empty($previewBack)) {
                $insertData['preview_generated_at'] = date('Y-m-d H:i:s');
            }
            
            $db->insert('card_requests', $insertData);
            
            $success = true;
            
            // Send confirmation email to employee
            $employeeName = $formData['name_en'] ?: $formData['name_ar'];
            Mailer::sendTemplate($formData['email'], 'request_submitted', [
                'employee_name' => $employeeName,
                'company_name' => $companyName,
                'position' => $formData['position_en'] ?: $formData['position_ar'],
                'email' => $formData['email'],
                'phone' => $formData['phone'] ?: $formData['mobile']
            ]);
            
            // Send notification to admin
            $adminEmail = $company['notification_email'] ?? $company['admin_email'] ?? '';
            if (!empty($adminEmail)) {
                $deptName = '';
                if (!empty($formData['department_id'])) {
                    foreach ($departments as $d) {
                        if ($d['id'] === $formData['department_id']) {
                            $deptName = $d['name'];
                            break;
                        }
                    }
                }
                
                $adminUrl = getBaseUrl() . $companySlug . '/admin/requests';
                
                Mailer::sendTemplate($adminEmail, 'admin_new_request', [
                    'employee_name' => $employeeName,
                    'company_name' => $companyName,
                    'name_en' => $formData['name_en'],
                    'name_ar' => $formData['name_ar'],
                    'position_en' => $formData['position_en'],
                    'position_ar' => $formData['position_ar'],
                    'email' => $formData['email'],
                    'phone' => $formData['phone'],
                    'mobile' => $formData['mobile'],
                    'department' => $deptName,
                    'admin_url' => $adminUrl
                ]);
            }
            
            // Clear form data on success
            $formData = [];
            
        } catch (Exception $e) {
            error_log("Card request submission error: " . $e->getMessage());
            $error = 'An error occurred while submitting your request. Please try again.';
        }
    }
    } // end CSRF else
}

$brandName = $companyName;
$pageTitle = 'Request Business Card - ' . ($selectedDepartment ? $selectedDepartment['name'] . ' - ' : '') . $companyName;
?>
<!DOCTYPE html>
<?php $__portalLocale = function_exists('currentLocale') ? currentLocale() : 'en'; $__portalDir = function_exists('currentDir') ? currentDir() : ($__portalLocale === 'ar' ? 'rtl' : 'ltr'); ?>
<html lang="<?= htmlspecialchars($__portalLocale) ?>" dir="<?= htmlspecialchars($__portalDir) ?>" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <link rel="icon" href="<?php echo getBasePath(); ?>favicon.svg" type="image/svg+xml">
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Cairo:wght@300;400;500;600;700;800;900&family=IBM+Plex+Sans+Arabic:wght@300;400;500;600;700&family=Tajawal:wght@300;400;500;700;800;900&display=swap" rel="stylesheet">

    <!-- Myriad Pro (matches admin designer so Fabric preview renders the same face) -->
    <style>
        @font-face { font-family: 'Myriad Pro'; font-weight: 300; src: url('<?php echo getBasePath(); ?>assets/fonts/myriad-pro/MyriadPro-Light.otf') format('opentype'); font-display: swap; }
        @font-face { font-family: 'Myriad Pro'; font-weight: 400; src: url('<?php echo getBasePath(); ?>assets/fonts/myriad-pro/MyriadPro-Regular.otf') format('opentype'); font-display: swap; }
        @font-face { font-family: 'Myriad Pro'; font-weight: 600; src: url('<?php echo getBasePath(); ?>assets/fonts/myriad-pro/MyriadPro-SemiBold.otf') format('opentype'); font-display: swap; }
        @font-face { font-family: 'Myriad Pro'; font-weight: 700; src: url('<?php echo getBasePath(); ?>assets/fonts/myriad-pro/MyriadPro-Bold.otf') format('opentype'); font-display: swap; }
    </style>

    <!-- Font Awesome (CDN) -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    
    <!-- Tailwind CSS (Local) -->
    <link rel="stylesheet" href="<?php echo getBasePath(); ?>assets/techwind/css/tailwind.min.css">
    
    <style>
        body { font-family: 'Inter', ui-sans-serif, system-ui, sans-serif; }
        [x-cloak] { display: none !important; }
        .form-input {
            display: block;
            width: 100%;
            border-radius: 0.5rem;
            background-color: #f9fafb;
            border: 1px solid #d1d5db;
            padding: 0.625rem 0.875rem;
            font-size: 0.875rem;
            color: #111827;
            outline: none;
            transition: all 0.15s ease;
        }
        .form-input:focus {
            border-color: #009bc1;
            box-shadow: 0 0 0 3px rgba(0, 155, 193, 0.12);
        }
        .form-input::placeholder {
            color: #9ca3af;
        }
        .rtl-input {
            direction: rtl;
            text-align: right;
        }
        /* Simple collapse animation */
        .collapse-content {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease;
        }
        .collapse-content.open {
            max-height: 500px;
        }
        /* Translate button styles */
        .translate-btn {
            position: absolute;
            right: 0.5rem;
            top: 50%;
            transform: translateY(-50%);
            padding: 0.25rem 0.5rem;
            font-size: 0.7rem;
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            color: white;
            border-radius: 0.375rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.25rem;
            transition: all 0.2s;
            border: none;
        }
        .translate-btn:hover {
            transform: translateY(-50%) scale(1.05);
            box-shadow: 0 2px 8px rgba(59, 130, 246, 0.4);
        }
        .translate-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        .translate-btn.loading {
            pointer-events: none;
        }
        .translate-btn .spinner {
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        .input-with-btn {
            position: relative;
        }
        .input-with-btn .form-input {
            padding-right: 4.5rem;
        }
        .rtl-input.form-input {
            padding-right: 0.875rem;
            padding-left: 4.5rem;
        }
        .rtl-input ~ .translate-btn {
            right: auto;
            left: 0.5rem;
        }
        /* Auto-fill indicator */
        .auto-filled {
            background-color: #f0fdf4 !important;
            border-color: #86efac !important;
        }
        /* Card preview styles */
        .card-preview-container {
            position: relative;
        }
        @media (min-width: 1024px) {
            .card-preview-container {
                position: sticky;
                top: 2rem;
            }
        }
        /* Card Preview Container */
        .card-preview-container {
            max-width: 100%;
        }
        
        /* Preview canvas wrapper - overflow hidden to contain scaled canvas */
        .canvas-preview-wrapper {
            position: relative;
            width: 100%;
            min-width: 100%;
            overflow: hidden;
            border-radius: 0.5rem;
            background-color: #f9fafb;
            /* Default aspect ratio until JS sets height */
            aspect-ratio: 1050 / 600;
        }
        
        /* Fabric.js canvas container - position absolute so it doesn't affect wrapper sizing */
        .canvas-preview-wrapper .canvas-container {
            position: absolute !important;
            top: 0 !important;
            left: 0 !important;
        }
        
        /* Loading overlay */
        #frontLoading,
        #backLoading {
            position: absolute;
            inset: 0;
            z-index: 10;
        }
        
        /* Template preview images */
        .preview-card-inner {
            aspect-ratio: 3.5/2;
            position: relative;
            overflow: hidden;
            border-radius: 0.5rem;
        }
        .preview-card-inner img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        /* Page Loader - consistent with main site */
        .page-loader {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            z-index: 99999;
            transition: opacity 0.4s ease, visibility 0.4s ease;
        }
        .page-loader.hidden {
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
        }
        .page-loader-text {
            margin-top: 24px;
            font-size: 14px;
            color: #64748b;
            font-weight: 500;
            letter-spacing: 0.5px;
        }
        .page-loader-brand {
            margin-top: 8px;
            font-size: 20px;
            font-weight: 700;
            background: linear-gradient(135deg, #009bc1 0%, #0284a1 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        body.loading > *:not(.page-loader) {
            opacity: 0;
        }
        body:not(.loading) > *:not(.page-loader) {
            opacity: 1;
            transition: opacity 0.3s ease;
        }
    </style>
</head>
<body class="h-full bg-gray-50 loading">
    <!-- Page Loader -->
    <div class="page-loader" id="pageLoader">
        <img src="<?php echo getBasePath(); ?>assets/images/cardify-loader.svg" alt="Loading" width="100" height="100">
        <div class="page-loader-text">Loading...</div>
        <div class="page-loader-brand"><?php echo htmlspecialchars($companyName); ?></div>
    </div>
    
    <div class="min-h-full">
        <!-- Header (consistent with main company page) -->
        <header class="bg-white/80 backdrop-blur-md border-b border-gray-100 sticky top-0 z-50">
            <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-4">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <?php 
                        $logoPath = $companyTheme['logo_path'] ?? $company['logo_path'] ?? null;
                        if (!empty($logoPath)): 
                        ?>
                        <img src="<?php echo imageUrl($logoPath); ?>" alt="<?php echo htmlspecialchars($companyName); ?>" class="h-10 w-auto rounded-xl">
                        <?php else: ?>
                        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-blue-500 to-blue-600 flex items-center justify-center text-white font-bold text-sm">
                            <?php echo strtoupper(substr($companyName, 0, 2)); ?>
                        </div>
                        <?php endif; ?>
                        <div>
                            <h1 class="text-lg font-bold text-gray-900"><?php echo htmlspecialchars($companyName); ?></h1>
                            <p class="text-xs text-gray-500">
                                <?php if ($selectedDepartment): ?>
                                    <?php echo htmlspecialchars($selectedDepartment['name']); ?> - <?= htmlspecialchars(t('portal.business_card_portal')) ?>
                                <?php else: ?>
                                    <?= htmlspecialchars(t('portal.business_card_portal')) ?>
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <?php if (function_exists('currentLocale') && file_exists(INCLUDES_DIR . '/lang-switcher.php')): ?>
                            <?php require INCLUDES_DIR . '/lang-switcher.php'; ?>
                        <?php endif; ?>
                        <a href="<?php echo getBasePath() . $companySlug; ?>/admin/login" class="inline-flex items-center gap-2 px-4 py-2 text-sm text-gray-600 hover:text-gray-900 font-medium">
                            <i class="fa-solid fa-lock"></i><span><?= htmlspecialchars(t('portal.admin_login')) ?></span>
                        </a>
                    </div>
                </div>
            </div>
        </header>
        
        <!-- Main Content -->
        <main class="max-w-7xl mx-auto px-4 py-8 sm:px-6 lg:px-8">
            <?php if (isset($portalDisabled) && $portalDisabled): ?>
            <!-- Portal Disabled -->
            <div class="max-w-md mx-auto bg-white rounded-xl shadow-sm border border-gray-200 p-8 text-center">
                <div class="w-16 h-16 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-4">
                    <i class="fa-solid fa-lock text-red-600 text-2xl"></i>
                </div>
                <h2 class="text-xl font-bold text-gray-900 mb-2"><?= htmlspecialchars(t('cardportal.portal_disabled_h2')) ?></h2>
                <p class="text-gray-600"><?= htmlspecialchars(t('cardportal.portal_disabled_body')) ?></p>
                <p class="mt-4">
                    <a href="<?php echo getBasePath() . $companySlug; ?>" class="text-blue-600 hover:text-blue-700 font-medium">
                        <i class="fa-solid fa-arrow-left mr-1"></i> <?= htmlspecialchars(t('cardportal.back_to_company')) ?>
                    </a>
                </p>
            </div>
            
            <?php elseif ($passcodeRequired && !$passcodeVerified): ?>
            <!-- Passcode Entry Form -->
            <div class="max-w-sm mx-auto bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                <div class="bg-gradient-to-r from-blue-600 to-blue-700 px-6 py-6 text-white text-center">
                    <i class="fa-solid fa-lock text-4xl mb-3"></i>
                    <h2 class="text-xl font-bold"><?= htmlspecialchars(t('cardportal.access_code_h2')) ?></h2>
                    <p class="text-blue-100 text-sm mt-1">
                        <?php if ($passcodeType === 'department'): ?>
                            <?= htmlspecialchars(t('cardportal.access_code_dept', ['name' => $selectedDepartment['name']])) ?>
                        <?php else: ?>
                            <?= htmlspecialchars(t('cardportal.access_code_generic')) ?>
                        <?php endif; ?>
                    </p>
                </div>
                
                <?php if ($passcodeError): ?>
                <div class="bg-red-50 border-b border-red-200 p-4">
                    <div class="flex items-center justify-center text-red-700">
                        <i class="fa-solid fa-circle-exclamation mr-2"></i>
                        <span class="text-sm"><?php echo htmlspecialchars($passcodeError); ?></span>
                    </div>
                </div>
                <?php endif; ?>
                
                <form method="POST" class="p-6" id="passcodeForm">
                    <?php echo csrfField(); ?>
                    <div class="mb-6">
                        <label class="block text-sm font-semibold text-gray-700 mb-3 text-center"><?= htmlspecialchars(t('cardportal.enter_code_label')) ?></label>
                        <!-- Hidden input for form submission -->
                        <input type="hidden" name="portal_passcode" id="passcodeInput">
                        <!-- 4-digit code input boxes -->
                        <div class="flex justify-center gap-3" id="codeInputs">
                            <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]*" 
                                   class="w-14 h-14 text-center text-2xl font-bold border-2 border-gray-300 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-200 transition-all"
                                   data-index="0" autofocus>
                            <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]*" 
                                   class="w-14 h-14 text-center text-2xl font-bold border-2 border-gray-300 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-200 transition-all"
                                   data-index="1">
                            <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]*" 
                                   class="w-14 h-14 text-center text-2xl font-bold border-2 border-gray-300 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-200 transition-all"
                                   data-index="2">
                            <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]*" 
                                   class="w-14 h-14 text-center text-2xl font-bold border-2 border-gray-300 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-200 transition-all"
                                   data-index="3">
                        </div>
                    </div>
                    <button type="submit" class="w-full px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-lg transition-colors">
                        <i class="fa-solid fa-unlock mr-2"></i>
                        <?= htmlspecialchars(t('cardportal.access_portal_btn')) ?>
                    </button>
                </form>
                
                <div class="px-6 pb-6 text-center">
                    <a href="<?php echo getBasePath() . $companySlug; ?>" class="text-sm text-gray-500 hover:text-gray-700">
                        <i class="fa-solid fa-arrow-left mr-1"></i> <?= htmlspecialchars(t('cardportal.back_to_company')) ?>
                    </a>
                </div>
            </div>
            
            <script>
            // Handle 4-digit code input
            document.addEventListener('DOMContentLoaded', function() {
                const inputs = document.querySelectorAll('#codeInputs input');
                const hiddenInput = document.getElementById('passcodeInput');
                const form = document.getElementById('passcodeForm');
                
                function updateHiddenInput() {
                    let code = '';
                    inputs.forEach(input => code += input.value);
                    hiddenInput.value = code;
                }
                
                inputs.forEach((input, index) => {
                    // Auto-focus next input on entry
                    input.addEventListener('input', function(e) {
                        const value = e.target.value.replace(/[^0-9]/g, '');
                        e.target.value = value;
                        
                        if (value && index < inputs.length - 1) {
                            inputs[index + 1].focus();
                        }
                        updateHiddenInput();
                        
                        // Auto-submit when all 4 digits entered
                        if (index === inputs.length - 1 && value) {
                            updateHiddenInput();
                            if (hiddenInput.value.length === 4) {
                                form.submit();
                            }
                        }
                    });
                    
                    // Handle backspace
                    input.addEventListener('keydown', function(e) {
                        if (e.key === 'Backspace' && !e.target.value && index > 0) {
                            inputs[index - 1].focus();
                        }
                    });
                    
                    // Handle paste
                    input.addEventListener('paste', function(e) {
                        e.preventDefault();
                        const paste = (e.clipboardData || window.clipboardData).getData('text');
                        const digits = paste.replace(/[^0-9]/g, '').slice(0, 4);
                        
                        digits.split('').forEach((digit, i) => {
                            if (inputs[i]) {
                                inputs[i].value = digit;
                            }
                        });
                        
                        updateHiddenInput();
                        if (digits.length === 4) {
                            form.submit();
                        } else if (inputs[digits.length]) {
                            inputs[digits.length].focus();
                        }
                    });
                });
                
                // Update hidden input before form submit
                form.addEventListener('submit', updateHiddenInput);
            });
            </script>
            
            <?php elseif ($success): ?>
            <!-- Success Message -->
            <div class="max-w-2xl mx-auto bg-white rounded-xl shadow-sm border border-gray-200 p-8 text-center">
                <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
                    <i class="fa-solid fa-check text-green-600 text-2xl"></i>
                </div>
                <h2 class="text-2xl font-bold text-gray-900 mb-2"><?= htmlspecialchars(t('cardportal.request_submitted_h2')) ?></h2>
                <p class="text-gray-600 mb-6">
                    <?= htmlspecialchars(t('cardportal.request_submitted_body')) ?>
                </p>
                <div class="bg-blue-50 rounded-lg p-4 text-left inline-block">
                    <h3 class="font-semibold text-blue-900 mb-2"><?= htmlspecialchars(t('cardportal.whats_next')) ?></h3>
                    <ul class="text-sm text-blue-800 space-y-1">
                        <li><i class="fa-solid fa-envelope mr-2"></i><?= htmlspecialchars(t('cardportal.next_email')) ?></li>
                        <li><i class="fa-solid fa-clock mr-2"></i><?= htmlspecialchars(t('cardportal.next_review')) ?></li>
                        <li><i class="fa-solid fa-id-card mr-2"></i><?= htmlspecialchars(t('cardportal.next_generate')) ?></li>
                        <li><i class="fa-solid fa-paper-plane mr-2"></i><?= htmlspecialchars(t('cardportal.next_deliver')) ?></li>
                    </ul>
                </div>
                <p class="mt-6">
                    <a href="<?php echo getBasePath() . $companySlug . '/portal'; ?>" class="text-blue-600 hover:text-blue-700 font-medium">
                        <i class="fa-solid fa-arrow-left mr-1"></i> <?= htmlspecialchars(t('cardportal.submit_another')) ?>
                    </a>
                </p>
            </div>
            
            <?php else: ?>
            <!-- Two Column Layout: Form + Preview (if enabled) -->
            <div class="grid <?php echo $showPreview ? 'lg:grid-cols-2' : ''; ?> gap-8 items-start max-w-7xl mx-auto">
                <!-- Left Column: Form -->
                <div class="<?php echo $showPreview ? '' : 'max-w-3xl mx-auto'; ?>">
            <!-- Request Form -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                <div class="bg-gradient-to-r from-blue-600 to-blue-700 px-6 py-8 text-white">
                    <h2 class="text-2xl font-bold"><?= htmlspecialchars(t('cardportal.request_form_h2')) ?></h2>
                    <p class="mt-1 text-blue-100"><?= htmlspecialchars(t('cardportal.request_form_sub')) ?></p>
                    <?php if ($companyDomain): ?>
                    <p class="mt-2 text-sm text-blue-200">
                        <i class="fa-solid fa-info-circle mr-1"></i>
                        <?= htmlspecialchars(t('cardportal.domain_restricted', ['domain' => $companyDomain])) ?>
                    </p>
                    <?php endif; ?>
                </div>
                
                <?php if ($error): ?>
                <div class="bg-red-50 border-l-4 border-red-500 p-4 mx-6 mt-6">
                    <div class="flex">
                        <i class="fa-solid fa-circle-exclamation text-red-500 mt-0.5 mr-3"></i>
                        <p class="text-sm text-red-700"><?php echo htmlspecialchars($error); ?></p>
                    </div>
                </div>
                <?php endif; ?>
                
                <form method="POST" enctype="multipart/form-data" class="p-6 space-y-4" id="cardRequestForm">
                    <?php echo csrfField(); ?>
                    <!-- Hidden inputs for preview card URLs (generated client-side) -->
                    <input type="hidden" name="preview_front" id="preview_front_input" value="">
                    <input type="hidden" name="preview_back" id="preview_back_input" value="">
                    
                    <!-- Email (always shown - required for submission) -->
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">
                            <?= htmlspecialchars(t('cardportal.email_label')) ?> <span class="text-red-500">*</span>
                        </label>
                        <input type="email" name="email" id="email" required
                               value="<?php echo htmlspecialchars($formData['email'] ?? ''); ?>"
                               placeholder="your.name@<?php echo htmlspecialchars($companyDomain ?: 'company.com'); ?>"
                               class="form-input"
                               onblur="checkExistingEmployee(this.value)">
                        <?php if ($companyDomain): ?>
                        <p class="mt-1 text-xs text-gray-500"><?= htmlspecialchars(t('cardportal.email_domain_hint', ['domain' => $companyDomain])) ?></p>
                        <?php endif; ?>
                        <div id="existingEmployeeNotice" class="hidden mt-2 p-3 bg-blue-50 border border-blue-200 rounded-lg">
                            <p class="text-sm text-blue-800">
                                <i class="fa-solid fa-info-circle mr-1"></i>
                                <span id="existingEmployeeMessage"><?= htmlspecialchars(t('cardportal.existing_employee')) ?></span>
                            </p>
                        </div>
                    </div>
                    
                    <!-- Request Type (shown for existing employees) -->
                    <div id="requestTypeSection" class="hidden">
                        <label class="block text-sm font-semibold text-gray-700 mb-2">
                            <?= htmlspecialchars(t('cardportal.what_to_do')) ?> <span class="text-red-500">*</span>
                        </label>
                        <div class="space-y-2">
                            <label class="flex items-start p-3 border border-gray-200 rounded-lg cursor-pointer hover:bg-gray-50 transition-colors">
                                <input type="radio" name="request_type" value="update" class="mt-0.5 mr-3" checked>
                                <div>
                                    <span class="font-medium text-gray-900"><?= htmlspecialchars(t('cardportal.update_info')) ?></span>
                                    <p class="text-xs text-gray-500"><?= htmlspecialchars(t('cardportal.update_info_sub')) ?></p>
                                </div>
                            </label>
                            <label class="flex items-start p-3 border border-gray-200 rounded-lg cursor-pointer hover:bg-gray-50 transition-colors">
                                <input type="radio" name="request_type" value="reprint" class="mt-0.5 mr-3">
                                <div>
                                    <span class="font-medium text-gray-900"><?= htmlspecialchars(t('cardportal.request_more')) ?></span>
                                    <p class="text-xs text-gray-500"><?= htmlspecialchars(t('cardportal.request_more_sub')) ?></p>
                                </div>
                            </label>
                        </div>
                    </div>
                    
                    <!-- Request Notes -->
                    <div id="requestNotesSection" class="hidden">
                        <label class="block text-sm font-semibold text-gray-700 mb-2">
                            Reason for Request <span class="text-gray-400">(optional)</span>
                        </label>
                        <textarea name="request_notes" id="request_notes" rows="2"
                                  placeholder="<?= htmlspecialchars(t('portal.request_notes_ph')) ?>"
                                  class="form-input resize-none"></textarea>
                    </div>
                    
                    <!-- Quantity for reprint requests -->
                    <div id="quantitySection" class="hidden">
                        <label class="block text-sm font-semibold text-gray-700 mb-2">
                            <?= htmlspecialchars(t('portal.quantity_label')) ?>
                        </label>
                        <select name="quantity_requested" id="quantity_requested" class="form-input">
                            <option value="1"><?= htmlspecialchars(t('portal.quantity_1')) ?></option>
                            <option value="2"><?= htmlspecialchars(str_replace(':n', '2', t('portal.quantity_n'))) ?></option>
                            <option value="3"><?= htmlspecialchars(str_replace(':n', '3', t('portal.quantity_n'))) ?></option>
                            <option value="5"><?= htmlspecialchars(str_replace(':n', '5', t('portal.quantity_n'))) ?></option>
                        </select>
                        <p class="mt-1 text-xs text-gray-500"><?= htmlspecialchars(t('portal.quantity_hint')) ?></p>
                    </div>
                    
                    <!-- Name English -->
                    <?php if (!empty($enabledFields['name_en'])): ?>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">
                            <?= htmlspecialchars(t('portal.full_name_en')) ?> <span class="text-red-500">*</span>
                        </label>
                        <input type="text" name="name_en" id="name_en" required
                               value="<?php echo htmlspecialchars($formData['name_en'] ?? ''); ?>"
                               placeholder="John Doe"
                               class="form-input">
                    </div>
                    <?php endif; ?>
                    
                    <!-- Name Arabic -->
                    <?php if (!empty($enabledFields['name_ar'])): ?>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">
                            <?= htmlspecialchars(t('portal.full_name_ar')) ?>
                            <button type="button" class="ml-2 text-xs text-blue-600 hover:text-blue-700" onclick="translateField('name_en', 'name_ar', 'name')">
                                <i class="fa-solid fa-wand-magic-sparkles"></i> <?= htmlspecialchars(t('portal.ai_translate')) ?>
                            </button>
                        </label>
                        <input type="text" name="name_ar" id="name_ar"
                               value="<?php echo htmlspecialchars($formData['name_ar'] ?? ''); ?>"
                               placeholder="جون دو"
                               class="form-input rtl-input">
                    </div>
                    <?php endif; ?>
                    
                    <!-- Position English -->
                    <?php if (!empty($enabledFields['position_en'])): ?>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2"><?= htmlspecialchars(t('portal.position_en')) ?></label>
                        <input type="text" name="position_en" id="position_en"
                               value="<?php echo htmlspecialchars($formData['position_en'] ?? ''); ?>"
                               placeholder="Software Engineer"
                               class="form-input">
                    </div>
                    <?php endif; ?>
                    
                    <!-- Position Arabic -->
                    <?php if (!empty($enabledFields['position_ar'])): ?>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">
                            <?= htmlspecialchars(t('portal.position_ar')) ?>
                            <button type="button" class="ml-2 text-xs text-blue-600 hover:text-blue-700" onclick="translateField('position_en', 'position_ar', 'position')">
                                <i class="fa-solid fa-wand-magic-sparkles"></i> <?= htmlspecialchars(t('portal.ai_translate')) ?>
                            </button>
                        </label>
                        <input type="text" name="position_ar" id="position_ar"
                               value="<?php echo htmlspecialchars($formData['position_ar'] ?? ''); ?>"
                               placeholder="مهندس برمجيات"
                               class="form-input rtl-input">
                    </div>
                    <?php endif; ?>
                    
                    <!-- Department -->
                    <?php if (!empty($departments)): ?>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">
                            <?= htmlspecialchars(t('portal.department')) ?>
                            <?php if ($selectedDepartment): ?>
                            <span class="text-xs text-blue-600 font-normal ml-1"><?= htmlspecialchars(t('portal.preselected')) ?></span>
                            <?php endif; ?>
                        </label>
                        <?php if ($selectedDepartment): ?>
                        <input type="hidden" name="department_id" value="<?php echo $selectedDepartment['id']; ?>">
                        <div class="form-input bg-gray-100"><?php echo htmlspecialchars($selectedDepartment['name']); ?></div>
                        <?php else: ?>
                        <select name="department_id" id="department_id" class="form-input">
                            <option value=""><?= htmlspecialchars(t('portal.select_department')) ?></option>
                            <?php foreach ($departments as $dept): ?>
                            <option value="<?php echo $dept['id']; ?>" 
                                    <?php echo ($formData['department_id'] ?? '') === $dept['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($dept['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Phone -->
                    <?php if (!empty($enabledFields['phone'])): ?>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2"><?= htmlspecialchars(t('portal.phone_label')) ?></label>
                        <input type="tel" name="phone" id="phone"
                               value="<?php echo htmlspecialchars($formData['phone'] ?? ''); ?>"
                               placeholder="+968 1234 5678"
                               class="form-input">
                    </div>
                    <?php endif; ?>
                    
                    <!-- Mobile -->
                    <?php if (!empty($enabledFields['mobile'])): ?>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2"><?= htmlspecialchars(t('portal.mobile_label')) ?></label>
                        <input type="tel" name="mobile" id="mobile"
                               value="<?php echo htmlspecialchars($formData['mobile'] ?? ''); ?>"
                               placeholder="+968 9876 5432"
                               class="form-input">
                    </div>
                    <?php endif; ?>
                    
                    <!-- Note: Email for card uses the main email field above -->
                    
                    <!-- Website -->
                    <?php if (!empty($enabledFields['website'])): ?>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2"><?= htmlspecialchars(t('portal.website_label')) ?></label>
                        <input type="text" name="website" id="website"
                               value="<?php echo htmlspecialchars($formData['website'] ?? ''); ?>"
                               placeholder="www.company.com"
                               class="form-input">
                    </div>
                    <?php endif; ?>
                    
                    <!-- Address 01 English (template key address_en) -->
                    <?php if (!empty($enabledFields['address_en'])): ?>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2"><?= htmlspecialchars(t('portal.address_01_en')) ?></label>
                        <textarea name="address_en" id="address_en" rows="2"
                                  placeholder="<?= htmlspecialchars(t('portal.address_ph_en')) ?>"
                                  class="form-input"><?php echo htmlspecialchars($formData['address_en'] ?? ''); ?></textarea>
                    </div>
                    <?php endif; ?>

                    <!-- Address 02 English (template legacy key `address`) -->
                    <?php if (!empty($enabledFields['address'])): ?>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2"><?= htmlspecialchars(t('portal.address_02_en')) ?></label>
                        <textarea name="address_2_en" id="address_2_en" rows="2"
                                  placeholder="<?= htmlspecialchars(t('portal.address_02_ph_en')) ?>"
                                  class="form-input"><?php echo htmlspecialchars($formData['address_2_en'] ?? ''); ?></textarea>
                    </div>
                    <?php endif; ?>

                    <!-- Address 01 Arabic -->
                    <?php if (!empty($enabledFields['address_ar'])): ?>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">
                            <?= htmlspecialchars(t('portal.address_01_ar')) ?>
                            <button type="button" class="ml-2 text-xs text-blue-600 hover:text-blue-700" onclick="translateField('address_en', 'address_ar', 'address')">
                                <i class="fa-solid fa-wand-magic-sparkles"></i> <?= htmlspecialchars(t('portal.ai_translate')) ?>
                            </button>
                        </label>
                        <textarea name="address_ar" id="address_ar" rows="2"
                                  placeholder="<?= htmlspecialchars(t('portal.address_ph_ar')) ?>"
                                  class="form-input rtl-input"><?php echo htmlspecialchars($formData['address_ar'] ?? ''); ?></textarea>
                    </div>
                    <?php endif; ?>

                    <!-- Address 02 Arabic: only render when the designer has an
                         explicit address_2_ar field on the template. No such
                         key exists in OHB's current design, so this stays
                         hidden unless a tenant adds it later. -->
                    <?php if (!empty($enabledFields['address_2_ar'])): ?>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">
                            <?= htmlspecialchars(t('portal.address_02_ar')) ?>
                            <button type="button" class="ml-2 text-xs text-blue-600 hover:text-blue-700" onclick="translateField('address_2_en', 'address_2_ar', 'address')">
                                <i class="fa-solid fa-wand-magic-sparkles"></i> <?= htmlspecialchars(t('portal.ai_translate')) ?>
                            </button>
                        </label>
                        <textarea name="address_2_ar" id="address_2_ar" rows="2"
                                  placeholder="<?= htmlspecialchars(t('portal.address_02_ph_ar')) ?>"
                                  class="form-input rtl-input"><?php echo htmlspecialchars($formData['address_2_ar'] ?? ''); ?></textarea>
                    </div>
                    <?php endif; ?>

                    <!-- Fax (English) -->
                    <?php if (!empty($enabledFields['fax'])): ?>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2"><?= htmlspecialchars(t('portal.fax_label')) ?></label>
                        <input type="tel" name="fax" id="fax"
                               value="<?php echo htmlspecialchars($formData['fax'] ?? ''); ?>"
                               placeholder="+968 1234 5679"
                               class="form-input">
                    </div>
                    <?php endif; ?>

                    <!-- Phone (Arabic) -->
                    <?php if (!empty($enabledFields['phone_ar'])): ?>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2"><?= htmlspecialchars(t('portal.phone_ar_label')) ?></label>
                        <input type="text" name="phone_ar" id="phone_ar"
                               value="<?php echo htmlspecialchars($formData['phone_ar'] ?? ''); ?>"
                               placeholder="٩٦٨+ ١٢٣٤ ٥٦٧٨"
                               class="form-input rtl-input">
                    </div>
                    <?php endif; ?>

                    <!-- Mobile (Arabic) -->
                    <?php if (!empty($enabledFields['mobile_ar'])): ?>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2"><?= htmlspecialchars(t('portal.mobile_ar_label')) ?></label>
                        <input type="text" name="mobile_ar" id="mobile_ar"
                               value="<?php echo htmlspecialchars($formData['mobile_ar'] ?? ''); ?>"
                               placeholder="٩٦٨+ ٩٨٧٦ ٥٤٣٢"
                               class="form-input rtl-input">
                    </div>
                    <?php endif; ?>

                    <!-- Website (Arabic) -->
                    <?php if (!empty($enabledFields['website_ar'])): ?>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2"><?= htmlspecialchars(t('portal.website_ar_label')) ?></label>
                        <input type="text" name="website_ar" id="website_ar"
                               value="<?php echo htmlspecialchars($formData['website_ar'] ?? ''); ?>"
                               placeholder="www.company.com"
                               class="form-input rtl-input">
                    </div>
                    <?php endif; ?>

                    <!-- Company (English) -->
                    <?php if (!empty($enabledFields['company_en'])): ?>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2"><?= htmlspecialchars(t('portal.company_en_label')) ?></label>
                        <input type="text" name="company_en" id="company_en"
                               value="<?php echo htmlspecialchars($formData['company_en'] ?? $companyName); ?>"
                               class="form-input">
                    </div>
                    <?php endif; ?>

                    <!-- Company (Arabic) -->
                    <?php if (!empty($enabledFields['company_ar'])): ?>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2"><?= htmlspecialchars(t('portal.company_ar_label')) ?></label>
                        <input type="text" name="company_ar" id="company_ar"
                               value="<?php echo htmlspecialchars($formData['company_ar'] ?? ''); ?>"
                               class="form-input rtl-input">
                    </div>
                    <?php endif; ?>

                    <!-- Catch-all: render a text input for any OTHER enabled design
                         field we haven't explicitly handled above (custom_*, fax_ar,
                         department_en/_ar, etc). Keeps the portal in sync with the
                         designer without needing a code change per new field. -->
                    <?php
                    $handledKeys = [
                        'name_en','name_ar','position_en','position_ar',
                        'phone','phone_ar','mobile','mobile_ar','fax','fax_ar',
                        'email','website','website_ar',
                        'address','address_en','address_ar','address_2_ar',
                        'company_en','company_ar','department_id',
                        'qr_code',
                    ];
                    foreach ($enabledFields as $__k => $__v):
                        if (in_array($__k, $handledKeys, true)) continue;
                        $__label = ucwords(str_replace('_', ' ', $__k));
                    ?>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2"><?= htmlspecialchars($__label) ?></label>
                        <input type="text" name="<?= htmlspecialchars($__k) ?>" id="<?= htmlspecialchars($__k) ?>"
                               value="<?php echo htmlspecialchars($formData[$__k] ?? ''); ?>"
                               class="form-input">
                    </div>
                    <?php endforeach; ?>

                    <!-- Step 1: Generate Preview Button -->
                    <div class="pt-4 border-t border-gray-200" id="generatePreviewSection">
                        <button type="button" id="generatePreviewBtn" onclick="generatePreview()"
                                class="w-full px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-lg shadow-md transition-colors flex items-center justify-center gap-2">
                            <i class="fa-solid fa-eye"></i>
                            <?= htmlspecialchars(t('portal.btn_generate_preview')) ?>
                        </button>
                        <p class="mt-2 text-xs text-gray-500 text-center">
                            <?= htmlspecialchars(t('portal.generate_preview_hint')) ?>
                        </p>
                    </div>
                    
                    <!-- Step 2: Submit Request (hidden until preview is generated) -->
                    <div class="pt-4 border-t border-gray-200" id="submitSection" style="display: none;">
                        <div class="bg-green-50 border border-green-200 rounded-lg p-3 mb-4">
                            <div class="flex items-center gap-2 text-green-800">
                                <i class="fa-solid fa-check-circle"></i>
                                <span class="font-medium text-sm"><?= htmlspecialchars(t('portal.preview_generated')) ?></span>
                            </div>
                            <p class="text-xs text-green-700 mt-1"><?= htmlspecialchars(t('portal.preview_review_hint')) ?></p>
                        </div>
                        <button type="submit" id="submitRequestBtn"
                                class="w-full px-6 py-3 bg-green-600 hover:bg-green-700 text-white font-semibold rounded-lg shadow-md transition-colors flex items-center justify-center gap-2">
                            <i class="fa-solid fa-paper-plane"></i>
                            <?= htmlspecialchars(t('portal.btn_submit_request')) ?>
                        </button>
                        <button type="button" onclick="editForm()" class="w-full mt-2 px-4 py-2 text-gray-600 hover:text-gray-900 font-medium transition-colors">
                            <i class="fa-solid fa-pencil mr-1"></i> <?= htmlspecialchars(t('portal.btn_edit_details')) ?>
                        </button>
                    </div>
                </form>
            </div>
                </div><!-- End left column -->
                
                <?php if ($showPreview && ($activeFrontTemplate || $activeBackTemplate)): ?>
                <!-- Right Column: Card Preview -->
                <div>
                    <div class="card-preview-container sticky top-20">
                        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4">
                            <h3 class="text-sm font-semibold text-gray-700 mb-4 flex items-center gap-2">
                                <i class="fa-solid fa-id-card text-blue-500"></i>
                                <span id="previewTitle"><?= htmlspecialchars(t('portal.card_template')) ?></span>
                            </h3>
                            
                            <!-- Initial: Show template backgrounds -->
                            <div id="templatePreview" class="space-y-4">
                                <?php if ($activeFrontTemplate && !empty($activeFrontTemplate['backgroundImage'])): ?>
                                <div>
                                    <div class="text-xs text-gray-500 mb-2 flex items-center gap-1">
                                        <i class="fa-solid fa-image text-blue-400"></i> Front
                                    </div>
                                    <div class="rounded-lg overflow-hidden shadow-md border border-gray-200" style="aspect-ratio: 3.5/2;">
                                        <img src="<?php echo imageUrl($activeFrontTemplate['backgroundImage']); ?>" 
                                             alt="Front Card Template" class="w-full h-full object-cover">
                                    </div>
                                </div>
                                <?php endif; ?>
                                
                                <?php if ($activeBackTemplate && !empty($activeBackTemplate['backgroundImage'])): ?>
                                <div>
                                    <div class="text-xs text-gray-500 mb-2 flex items-center gap-1">
                                        <i class="fa-solid fa-image text-green-400"></i> Back
                                    </div>
                                    <div class="rounded-lg overflow-hidden shadow-md border border-gray-200" style="aspect-ratio: 3.5/2;">
                                        <img src="<?php echo imageUrl($activeBackTemplate['backgroundImage']); ?>" 
                                             alt="Back Card Template" class="w-full h-full object-cover">
                                    </div>
                                </div>
                                <?php endif; ?>
                                
                                <div class="bg-blue-50 border border-blue-200 rounded-lg p-3">
                                    <div class="flex items-start gap-2">
                                        <i class="fa-solid fa-info-circle text-blue-600 mt-0.5"></i>
                                        <div>
                                            <p class="text-xs font-medium text-blue-800">Your Info Will Be Added</p>
                                            <p class="text-[10px] text-blue-700 mt-1">Fill in your details and click "Generate Preview" to see your card.</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- After Generate: Show preview with watermark -->
                            <div id="generatedPreview" class="space-y-4" style="display: none;">
                                <div>
                                    <div class="text-xs text-gray-500 mb-2 flex items-center gap-1">
                                        <i class="fa-solid fa-image text-blue-400"></i> Front Preview
                                    </div>
                                    <div class="canvas-preview-wrapper rounded-lg shadow-md border border-gray-200 relative">
                                        <canvas id="previewFrontCanvas"></canvas>
                                        <div id="frontLoading" class="absolute inset-0 bg-gray-100 flex items-center justify-center">
                                            <i class="fa-solid fa-spinner fa-spin text-gray-400 text-2xl"></i>
                                        </div>
                                    </div>
                                </div>
                                
                                <div>
                                    <div class="text-xs text-gray-500 mb-2 flex items-center gap-1">
                                        <i class="fa-solid fa-image text-green-400"></i> Back Preview
                                    </div>
                                    <div class="canvas-preview-wrapper rounded-lg shadow-md border border-gray-200 relative">
                                        <canvas id="previewBackCanvas"></canvas>
                                        <div id="backLoading" class="absolute inset-0 bg-gray-100 flex items-center justify-center">
                                            <i class="fa-solid fa-spinner fa-spin text-gray-400 text-2xl"></i>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-3">
                                    <div class="flex items-start gap-2">
                                        <i class="fa-solid fa-eye text-yellow-600 mt-0.5"></i>
                                        <div>
                                            <p class="text-xs font-medium text-yellow-800">Preview Only</p>
                                            <p class="text-[10px] text-yellow-700 mt-1">This is a watermarked preview. Final card will be generated after approval.</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div><!-- End right column -->
                <?php endif; ?>
            </div><!-- End grid -->
            <?php endif; ?>
        </main>
        
        <!-- Footer (consistent with main company page) -->
        <footer class="border-t border-gray-200 bg-white mt-8">
            <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
                <div class="flex flex-col md:flex-row items-center justify-between gap-4">
                    <div class="flex items-center gap-3">
                        <?php if (!empty($logoPath)): ?>
                        <img src="<?php echo imageUrl($logoPath); ?>" alt="<?php echo htmlspecialchars($companyName); ?>" class="h-8 opacity-60">
                        <?php endif; ?>
                        <p class="text-sm text-gray-500">
                            &copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($companyName); ?>
                        </p>
                    </div>
                    <div class="flex items-center gap-6 text-sm text-gray-500">
                        <a href="<?php echo getBasePath() . $companySlug; ?>" class="hover:text-gray-700">Home</a>
                        <a href="<?php echo getBasePath() . $companySlug; ?>/admin/login" class="hover:text-gray-700">Admin Login</a>
                        <span class="text-gray-300">|</span>
                        <span>Powered by <a href="<?php echo getBasePath(); ?>" class="text-blue-600 hover:underline">Cardify</a></span>
                    </div>
                </div>
            </div>
        </footer>
    </div>
    
    <!-- Alpine.js for interactivity -->
    <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
    
    <!-- Fabric.js 7.x for card preview generation -->
    <script src="https://cdn.jsdelivr.net/npm/fabric@7.1.0/dist/index.min.js"></script>
    <!-- QR Code Generator -->
    <script src="https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.min.js"></script>
    <!-- CardEditor (uses Fabric.js) -->
    <script src="<?php echo getBasePath(); ?>assets/js/card-editor.js"></script>
    
    <script>
    // Template data from PHP
    const basePath = '<?php echo getBasePath(); ?>';
    const companyName = '<?php echo addslashes($companyName); ?>';
    const companySlug = '<?php echo addslashes($companySlug); ?>';
    const frontTemplate = <?php echo json_encode($activeFrontTemplate); ?>;
    const backTemplate = <?php echo json_encode($activeBackTemplate); ?>;
    
    // CardEditor instances
    let frontEditor = null;
    let backEditor = null;
    let previewGenerated = false;
    
    // Scale canvas to fit its wrapper container
    function scaleCanvasToFit(canvasId) {
        const canvas = document.getElementById(canvasId);
        if (!canvas) return;
        
        const wrapper = canvas.closest('.canvas-preview-wrapper');
        if (!wrapper) return;
        
        const canvasContainer = wrapper.querySelector('.canvas-container');
        if (!canvasContainer) return;
        
        // Force reflow
        void wrapper.offsetHeight;
        
        // Get wrapper width (wrapper now uses aspect-ratio CSS, so it has correct dimensions)
        const wrapperWidth = wrapper.offsetWidth;
        
        // Skip if hidden
        if (wrapperWidth <= 0) {
            console.log('Wrapper width is 0, skipping scale for', canvasId);
            return;
        }
        
        const intrinsic = arguments[1] || 1050;
        const scale = wrapperWidth / intrinsic;

        // Apply transform scale to canvas container
        canvasContainer.style.transform = `scale(${scale})`;
        canvasContainer.style.transformOrigin = 'top left';
    }
    
    // Compute the template's intended pixel canvas so Fabric renders at the
    // exact coordinate space the admin designer used. Field positions are
    // stored as absolute px (settings.fields_format === 'px'), so if we use
    // a different canvas size the whole layout drifts.
    function getTemplateDimensions(template) {
        const s = template && template.settings;
        if (s && s.customWidth && s.customHeight && s.dpi) {
            const toMm = { mm: 1, in: 25.4, cm: 10, pt: 25.4/72 };
            const f = toMm[s.customUnit || 'mm'] || 1;
            return {
                width:  Math.round(s.customWidth  * f * s.dpi / 25.4),
                height: Math.round(s.customHeight * f * s.dpi / 25.4)
            };
        }
        return { width: 1050, height: 600 };
    }

    // Initialize editors when DOM is ready
    document.addEventListener('DOMContentLoaded', async function() {
        // Wait a bit for Fabric.js to be ready
        await new Promise(resolve => setTimeout(resolve, 100));

        const frontCanvasEl = document.getElementById('previewFrontCanvas');
        const backCanvasEl = document.getElementById('previewBackCanvas');

        const frontDims = getTemplateDimensions(frontTemplate);
        const backDims  = getTemplateDimensions(backTemplate);

        // Lock wrapper aspect-ratios so the canvas preview matches card shape.
        const frontWrap = frontCanvasEl && frontCanvasEl.closest('.canvas-preview-wrapper');
        if (frontWrap) frontWrap.style.aspectRatio = frontDims.width + ' / ' + frontDims.height;
        const backWrap = backCanvasEl && backCanvasEl.closest('.canvas-preview-wrapper');
        if (backWrap) backWrap.style.aspectRatio = backDims.width + ' / ' + backDims.height;

        if (frontCanvasEl && typeof CardEditor !== 'undefined') {
            frontEditor = new CardEditor('previewFrontCanvas', {
                width: frontDims.width,
                height: frontDims.height,
                backgroundColor: '#ffffff',
                onReady: () => {
                    if (frontEditor.canvas) frontEditor.canvas.selection = false;
                    setTimeout(() => scaleCanvasToFit('previewFrontCanvas', frontDims.width), 50);
                }
            });
        }

        if (backCanvasEl && typeof CardEditor !== 'undefined') {
            backEditor = new CardEditor('previewBackCanvas', {
                width: backDims.width,
                height: backDims.height,
                backgroundColor: '#ffffff',
                onReady: () => {
                    if (backEditor.canvas) backEditor.canvas.selection = false;
                    setTimeout(() => scaleCanvasToFit('previewBackCanvas', backDims.width), 50);
                }
            });
        }

        // Re-scale on window resize
        window.addEventListener('resize', () => {
            scaleCanvasToFit('previewFrontCanvas', frontDims.width);
            scaleCanvasToFit('previewBackCanvas',  backDims.width);
        });
    });
    
    // Generate preview with watermark
    async function generatePreview() {
        const btn = document.getElementById('generatePreviewBtn');
        const email = document.getElementById('email')?.value;
        const nameEn = document.getElementById('name_en')?.value;
        
        // Validate required fields
        if (!email) {
            alert(<?= json_encode(t('portal.enter_email_first')) ?>);
            return;
        }
        // Client-side domain gate so users can't even preview with an
        // outside email. Server-side check still runs on submit.
        const requiredDomain = <?= json_encode($companyDomain ?: '') ?>;
        if (requiredDomain) {
            const atIdx = email.lastIndexOf('@');
            const emailDomain = atIdx >= 0 ? email.slice(atIdx + 1).toLowerCase() : '';
            if (emailDomain !== requiredDomain.toLowerCase()) {
                alert(<?= json_encode(t('cardportal.email_domain_hint', ['domain' => ':domain'])) ?>
                    .replace(':domain', requiredDomain));
                return;
            }
        }
        if (!nameEn) {
            alert(<?= json_encode(t('portal.enter_name_first')) ?>);
            return;
        }
        
        // Show loading state
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-2"></i>' + <?= json_encode(t('portal.generating')) ?>;

        // Switch to generated preview view (null-safe: template preview DOM
        // isn't rendered when the tenant has no front/back templates wired
        // up yet, so every lookup has to guard).
        const _hide = (id) => { const el = document.getElementById(id); if (el) el.style.display = 'none'; };
        const _show = (id, disp='block') => { const el = document.getElementById(id); if (el) el.style.display = disp; };
        _hide('templatePreview');
        _show('generatedPreview');
        const _title = document.getElementById('previewTitle');
        if (_title) _title.textContent = <?= json_encode(t('portal.your_card_preview')) ?>;
        
        // Get form data - use main email field (no separate email_card)
        const formData = {
            name_en: nameEn || '',
            name_ar: document.getElementById('name_ar')?.value || '',
            position_en: document.getElementById('position_en')?.value || '',
            position_ar: document.getElementById('position_ar')?.value || '',
            email: email,
            phone: document.getElementById('phone')?.value || '',
            mobile: document.getElementById('mobile')?.value || '',
            website: document.getElementById('website')?.value || '',
            address_en: document.getElementById('address_en')?.value || '',
            address_2_en: document.getElementById('address_2_en')?.value || '',
            address_ar: document.getElementById('address_ar')?.value || '',
            address_2_ar: document.getElementById('address_2_ar')?.value || '',
            fax: document.getElementById('fax')?.value || '',
            fax_ar: document.getElementById('fax_ar')?.value || '',
            phone_ar: document.getElementById('phone_ar')?.value || '',
            mobile_ar: document.getElementById('mobile_ar')?.value || '',
            website_ar: document.getElementById('website_ar')?.value || '',
            company_ar: document.getElementById('company_ar')?.value || '',
            company_en: document.getElementById('company_en')?.value || companyName
        };
        // Pick up any catch-all custom fields the designer added so they also
        // flow into the preview. Reads every <input>/<textarea> inside the form.
        document.querySelectorAll('#cardRequestForm input[name], #cardRequestForm textarea[name]').forEach(function (el) {
            if (!(el.name in formData)) formData[el.name] = el.value || '';
        });
        
        try {
            // Render front card using CardEditor
            if (frontEditor && frontTemplate) {
                await renderCardWithEditor(frontEditor, frontTemplate, formData, 'front');
            }
            _hide('frontLoading');

            // Render back card using CardEditor
            if (backEditor && backTemplate) {
                await renderCardWithEditor(backEditor, backTemplate, formData, 'back');
            }
            _hide('backLoading');

            // Show submit section
            _hide('generatePreviewSection');
            _show('submitSection');
            previewGenerated = true;
            
            // Scale canvases after browser has completed layout (double RAF + timeout for safety)
            requestAnimationFrame(() => {
                requestAnimationFrame(() => {
                    setTimeout(() => {
                        scaleCanvasToFit('previewFrontCanvas');
                        scaleCanvasToFit('previewBackCanvas');
                    }, 50);
                });
            });
            
        } catch (e) {
            console.error('Preview generation error:', e);
            alert(<?= json_encode(t('portal.preview_error')) ?>);
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-eye mr-2"></i>Generate Preview';
        }
    }
    
    // Render card using CardEditor (same method as main card generation)
    async function renderCardWithEditor(editor, template, data, side) {
        if (!editor || !editor.canvas) {
            console.error('Editor not ready');
            return;
        }

        // Wait for webfonts so Fabric measures text with the right face (matches
        // what the admin designer sees). Without this, Cairo / Myriad Pro fall
        // back to system fonts and positions shift.
        if (document.fonts && document.fonts.ready) {
            try { await document.fonts.ready; } catch (e) { /* best effort */ }
        }

        // Clear existing content
        editor.canvas.clear();
        editor.canvas.backgroundColor = '#ffffff';
        editor.fields = {};
        
        // Load background image with the transform the admin saved (so stretch,
        // offset, rotation all match the design exactly, not a naive fit).
        if (template && template.backgroundImage) {
            const bgUrl = template.backgroundImage.startsWith('http')
                ? template.backgroundImage
                : window.location.origin + (template.backgroundImage.startsWith('/') ? '' : '/') + template.backgroundImage;
            const bgTransform = (template.settings && template.settings.backgroundTransform) || null;
            try {
                await editor.loadBackground(bgUrl, bgTransform);
                editor.setBackgroundLocked && editor.setBackgroundLocked(true);
            } catch (e) {
                console.warn('Could not load background:', e);
            }
        }
        
        // Map form data to template field keys
        const fieldValues = {
            'name_en': data.name_en,
            'name_ar': data.name_ar,
            'position_en': data.position_en,
            'position_ar': data.position_ar,
            'company_en': data.company_en,
            'phone': data.phone,
            'mobile': data.mobile,
            'email': data.email,
            'website': data.website,
            'website_ar': data.website_ar,
            'fax': data.fax,
            'fax_ar': data.fax_ar,
            'phone_ar': data.phone_ar,
            'mobile_ar': data.mobile_ar,
            'company_ar': data.company_ar,
            'address': data.address_2_en,
            'address_en': data.address_en,
            'address_2_en': data.address_2_en,
            'address_ar': data.address_ar,
            'address_2_ar': data.address_2_ar
        };
        // Merge any additional custom fields the admin added to the design
        // so the catch-all inputs also flow through to the Fabric preview.
        Object.keys(data).forEach(function (k) {
            if (!(k in fieldValues)) fieldValues[k] = data[k];
        });
        
        // Add text fields using CardEditor
        if (template && template.fields) {
            for (const [key, field] of Object.entries(template.fields)) {
                if (!field.enabled) continue;
                
                // Handle QR code separately — use the employee's own vCard URL
                // so the QR is dynamic per person. Lock it against user movement
                // on the portal preview (designer is the only place to reposition).
                if (key === 'qr_code') {
                    const vcfUrl = getVcfUrl(data.email);
                    try {
                        const qrObj = await editor.addQRCode(vcfUrl, {
                            x: field.x,
                            y: field.y,
                            size: field.size || 140
                        });
                        if (qrObj) {
                            qrObj.set({
                                selectable: false,
                                evented: false,
                                hasControls: false,
                                hasBorders: false,
                                lockMovementX: true,
                                lockMovementY: true,
                                lockScalingX: true,
                                lockScalingY: true,
                                lockRotation: true,
                                hoverCursor: 'default'
                            });
                            qrObj.setCoords();
                            editor.canvas.requestRenderAll();
                        }
                    } catch (e) {
                        console.warn('QR code generation failed:', e);
                    }
                    continue;
                }
                
                const value = fieldValues[key];
                if (!value) continue;
                
                // Debug: Log field alignment data
                console.log('Field:', key, 'textAlign:', field.textAlign, 'originX:', field.originX);
                
                // Use alignment directly from template (don't override with defaults)
                const textAlign = field.textAlign || 'left';
                const originX = field.originX || (textAlign === 'center' ? 'center' : textAlign === 'right' ? 'right' : 'left');
                
                // Add text field using template values directly (no scaling - canvas is standard 1050x600)
                editor.addTextField(key, {
                    text: value,
                    x: field.x,
                    y: field.y,
                    fontSize: field.fontSize || 14,
                    fontFamily: field.fontFamily || (isArabic ? 'Cairo' : 'Inter'),
                    fontWeight: field.fontWeight || 'normal',
                    fill: field.fill || field.color || '#333333',
                    textAlign: textAlign,
                    originX: originX,
                    originY: field.originY || 'top'
                });
                
                // Make fields non-selectable for preview
                if (editor.fields[key]) {
                    editor.fields[key].set({ selectable: false, evented: false });
                }
            }
        }
        
        // Add PREVIEW watermark (centered on the actual template canvas size)
        const watermark = new fabric.Text('PREVIEW', {
            left: editor.canvas.width / 2,
            top:  editor.canvas.height / 2,
            fontSize: 60,
            fontFamily: 'Arial',
            fontWeight: 'bold',
            fill: 'rgba(128, 128, 128, 0.15)',
            originX: 'center',
            originY: 'center',
            angle: -30,
            selectable: false,
            evented: false
        });
        editor.canvas.add(watermark);
        
        editor.canvas.requestRenderAll();
    }
    
    // Return the E-Card (digital card) URL for this employee. We point the
    // QR at the branded E-Card page (which has its own VCF download button)
    // instead of the raw .vcf file so scanning shows a rich web profile.
    // Uses email in the path; digital_card.php accepts emails and falls back
    // to the latest card_request when the employee row doesn't exist yet.
    function getVcfUrl(email) {
        const host = window.location.origin;
        return host + basePath + companySlug + '/card/' + encodeURIComponent(email);
    }
    
    // Edit form (go back to editing)
    function editForm() {
        document.getElementById('generatePreviewSection').style.display = 'block';
        document.getElementById('submitSection').style.display = 'none';
        document.getElementById('templatePreview').style.display = 'block';
        document.getElementById('generatedPreview').style.display = 'none';
        document.getElementById('previewTitle').textContent = 'Card Template';
        
        const btn = document.getElementById('generatePreviewBtn');
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-magic-wand-sparkles mr-2"></i>Generate Preview';
    }
    
    // Prevent form submission without preview
    document.addEventListener('DOMContentLoaded', function() {
        const form = document.getElementById('cardRequestForm');
        if (form) {
            form.addEventListener('submit', function(e) {
                if (!previewGenerated) {
                    e.preventDefault();
                    alert('Please generate a preview first before submitting.');
                    return false;
                }
            });
        }
    });
    // AI Translation functionality
    const translateApiUrl = '<?php echo getBasePath(); ?>api/translate.php';
    let isTranslating = {};
    
    // Convert Western numerals to Arabic-Indic numerals (real-time)
    // Reverses word order for RTL but keeps digits within groups in order
    function syncArabicPhone(sourceId, targetId) {
        const sourceEl = document.getElementById(sourceId);
        const targetEl = document.getElementById(targetId);
        if (!sourceEl || !targetEl) return;
        
        const text = sourceEl.value.trim();
        if (!text) {
            targetEl.value = '';
            return;
        }
        
        const arabicNumerals = {
            '0': '٠', '1': '١', '2': '٢', '3': '٣', '4': '٤',
            '5': '٥', '6': '٦', '7': '٧', '8': '٨', '9': '٩'
        };
        
        // Convert to Arabic numerals first
        function toArabic(str) {
            let result = '';
            for (let char of str) {
                result += arabicNumerals[char] || char;
            }
            return result;
        }
        
        // Split by spaces, reverse the order of groups
        // +968 9111 7795 -> ["7795", "9111", "+968"] -> ["7795", "9111", "968+"]
        let parts = text.split(/\s+/);
        
        // Handle the + sign - move it from start of first part to end of last part
        let hasPlus = false;
        if (parts.length > 0 && parts[0].startsWith('+')) {
            hasPlus = true;
            parts[0] = parts[0].substring(1); // Remove + from first part
        }
        
        // Reverse the order of parts
        parts = parts.reverse();
        
        // Add + to the end of the last part (which was originally first)
        if (hasPlus && parts.length > 0) {
            parts[parts.length - 1] = parts[parts.length - 1] + '+';
        }
        
        // Convert to Arabic numerals and join
        const converted = parts.map(toArabic).join(' ');
        
        targetEl.value = converted;
    }
    
    // Initialize on page load (for pre-filled values)
    document.addEventListener('DOMContentLoaded', function() {
        syncArabicPhone('phone', 'phone_ar');
        syncArabicPhone('mobile', 'mobile_ar');
        
        // Initialize request type toggle
        initRequestTypeToggle();
        
        // Check existing employee on page load if email is pre-filled
        const emailField = document.getElementById('email');
        if (emailField && emailField.value) {
            checkExistingEmployee(emailField.value);
        }
    });
    
    // Check if employee already exists
    let existingEmployeeData = null;
    let existingEmployeeResult = null;
    async function checkExistingEmployee(email) {
        if (!email || !email.includes('@')) return;
        
        const notice = document.getElementById('existingEmployeeNotice');
        const message = document.getElementById('existingEmployeeMessage');
        const requestTypeSection = document.getElementById('requestTypeSection');
        const requestNotesSection = document.getElementById('requestNotesSection');
        
        try {
            const response = await fetch(basePath + 'api/check-employee.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ 
                    email: email, 
                    company_id: '<?php echo $companyId; ?>' 
                })
            });
            
            const result = await response.json();
            existingEmployeeResult = result;
            
            if (result.exists) {
                existingEmployeeData = result.employee;
                notice.classList.remove('hidden');
                
                // Build detailed message based on history
                let msgHtml = '';
                const name = result.employee.name || 'there';
                
                if (result.source === 'employee') {
                    // Existing employee with cards
                    msgHtml = `<strong>Welcome back, ${name}!</strong><br>`;
                    if (result.cards_generated > 0) {
                        msgHtml += `<span class="text-blue-600">You have ${result.cards_generated} card${result.cards_generated > 1 ? 's' : ''} generated.</span> `;
                    }
                    msgHtml += `Your details have been pre-filled below. You can update your info or request additional cards.`;
                } else if (result.source === 'request') {
                    // Has previous request but not yet an employee
                    msgHtml = `<strong>Welcome back, ${name}!</strong><br>`;
                    if (result.last_request_status === 'pending') {
                        msgHtml += `<span class="text-yellow-600">You have a pending request.</span> `;
                    } else if (result.last_request_status === 'approved') {
                        msgHtml += `<span class="text-green-600">Your previous request was approved.</span> `;
                    }
                    msgHtml += `Your previous details have been pre-filled for easy reordering.`;
                }
                
                if (result.total_requests > 1) {
                    msgHtml += `<br><span class="text-xs text-gray-500">Total requests: ${result.total_requests}</span>`;
                }
                
                message.innerHTML = msgHtml;
                requestTypeSection.classList.remove('hidden');
                requestNotesSection.classList.remove('hidden');
                
                // Pre-fill form with existing data
                prefillFormWithEmployeeData(result.employee);
                
                // Trigger preview update after pre-fill
                setTimeout(() => {
                    if (typeof updateCanvasPreview === 'function') {
                        updateCanvasPreview();
                    }
                }, 100);
            } else {
                existingEmployeeData = null;
                existingEmployeeResult = null;
                notice.classList.add('hidden');
                requestTypeSection.classList.add('hidden');
                requestNotesSection.classList.add('hidden');
                document.getElementById('quantitySection')?.classList.add('hidden');
            }
        } catch (e) {
            console.log('Employee check skipped:', e.message);
        }
    }
    
    // Pre-fill form with existing employee data
    function prefillFormWithEmployeeData(employee) {
        const fields = ['name_en', 'name_ar', 'position_en', 'position_ar', 
                       'phone', 'phone_ar', 'mobile', 'mobile_ar',
                       'website', 'website_ar', 'address_en', 'address_ar'];
        
        fields.forEach(field => {
            const el = document.getElementById(field);
            // Pre-fill even if field has value (use latest data)
            if (el && employee[field]) {
                el.value = employee[field];
            }
        });
    }
    
    // Handle request type toggle
    function initRequestTypeToggle() {
        const radios = document.querySelectorAll('input[name="request_type"]');
        const quantitySection = document.getElementById('quantitySection');
        
        radios.forEach(radio => {
            radio.addEventListener('change', function() {
                if (this.value === 'reprint') {
                    quantitySection?.classList.remove('hidden');
                } else {
                    quantitySection?.classList.add('hidden');
                }
            });
        });
    }
    
    async function translateField(sourceId, targetId, fieldType) {
        const sourceEl = document.getElementById(sourceId);
        const targetEl = document.getElementById(targetId);
        
        if (!sourceEl || !targetEl) return;
        
        const sourceText = sourceEl.value.trim();
        if (!sourceText) {
            showToast(<?= json_encode(t('portal.english_first')) ?>, 'warning');
            sourceEl.focus();
            return;
        }
        
        // Don't translate if already in progress
        if (isTranslating[targetId]) return;
        isTranslating[targetId] = true;
        
        // Find the translate button and show loading
        const btn = targetEl.parentElement.querySelector('.translate-btn');
        if (btn) {
            btn.innerHTML = '<i class="fa-solid fa-spinner spinner"></i>';
            btn.disabled = true;
        }
        
        try {
            const response = await fetch(translateApiUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    text: sourceText,
                    target: 'ar',
                    field_type: fieldType
                })
            });
            
            const data = await response.json();
            
            if (data.error) {
                if (data.code === 'not_configured') {
                    showToast('AI translation not configured. Please add your OpenAI API key.', 'error');
                } else {
                    showToast(data.error, 'error');
                }
                return;
            }
            
            if (data.translation) {
                targetEl.value = data.translation;
                targetEl.classList.add('auto-filled');
                setTimeout(() => targetEl.classList.remove('auto-filled'), 2000);
                showToast('Translated successfully!', 'success');
            }
        } catch (error) {
            console.error('Translation error:', error);
            showToast('Translation failed. Please try again.', 'error');
        } finally {
            isTranslating[targetId] = false;
            if (btn) {
                btn.innerHTML = '<i class="fa-solid fa-language"></i>';
                btn.disabled = false;
            }
        }
    }
    
    // Auto-translate all missing Arabic fields
    async function autoTranslateAll() {
        const fieldsToTranslate = [
            { source: 'name_en', target: 'name_ar', type: 'name' },
            { source: 'position_en', target: 'position_ar', type: 'position' },
            { source: 'address_en', target: 'address_ar', type: 'address' }
        ];
        
        for (const field of fieldsToTranslate) {
            const targetEl = document.getElementById(field.target);
            const sourceEl = document.getElementById(field.source);
            
            // Only translate if source has value and target is empty
            if (sourceEl && sourceEl.value.trim() && targetEl && !targetEl.value.trim()) {
                await translateField(field.source, field.target, field.type);
                // Small delay between translations
                await new Promise(resolve => setTimeout(resolve, 300));
            }
        }
        
        // Phone numbers are auto-synced, just trigger them
        syncArabicPhone('phone', 'phone_ar');
        syncArabicPhone('mobile', 'mobile_ar');
    }
    
    // Simple toast notification
    function showToast(message, type = 'info') {
        const colors = {
            success: 'bg-green-500',
            error: 'bg-red-500',
            warning: 'bg-yellow-500',
            info: 'bg-blue-500'
        };
        
        const toast = document.createElement('div');
        toast.className = `fixed bottom-4 right-4 ${colors[type]} text-white px-4 py-2 rounded-lg shadow-lg z-50 transform transition-all duration-300 translate-y-full opacity-0`;
        toast.innerHTML = `<i class="fa-solid ${type === 'success' ? 'fa-check' : type === 'error' ? 'fa-times' : 'fa-info-circle'} mr-2"></i>${message}`;
        document.body.appendChild(toast);
        
        requestAnimationFrame(() => {
            toast.classList.remove('translate-y-full', 'opacity-0');
        });
        
        setTimeout(() => {
            toast.classList.add('translate-y-full', 'opacity-0');
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }
    
    // Optional: Auto-translate on blur (when user leaves field)
    document.addEventListener('DOMContentLoaded', function() {
        const autoTranslateFields = ['name_en', 'position_en'];
        
        autoTranslateFields.forEach(fieldId => {
            const el = document.getElementById(fieldId);
            if (el) {
                el.addEventListener('blur', function() {
                    const targetId = fieldId.replace('_en', '_ar');
                    const targetEl = document.getElementById(targetId);
                    
                    // Only auto-translate if target is empty and source has value
                    if (targetEl && !targetEl.value.trim() && this.value.trim()) {
                        const fieldType = fieldId.replace('_en', '');
                        translateField(fieldId, targetId, fieldType);
                    }
                });
            }
        });
        
    });
    </script>
    
    <!-- Page Loader Script - consistent with main site -->
    <script>
        (function() {
            const loader = document.getElementById('pageLoader');
            const minLoadTime = 200; // Fast load (0.2 seconds)
            const startTime = performance.timing ? performance.timing.navigationStart : Date.now();
            
            function hideLoader() {
                const elapsed = Date.now() - startTime;
                const remaining = Math.max(0, minLoadTime - elapsed);
                
                setTimeout(() => {
                    if (loader) {
                        loader.classList.add('hidden');
                        document.body.classList.remove('loading');
                    }
                }, remaining);
            }
            
            // Hide loader when everything is loaded
            if (document.readyState === 'complete') {
                hideLoader();
            } else {
                window.addEventListener('load', hideLoader);
            }
            
            // Fallback: hide after 4 seconds max
            setTimeout(() => {
                if (loader && !loader.classList.contains('hidden')) {
                    loader.classList.add('hidden');
                    document.body.classList.remove('loading');
                }
            }, 4000);
        })();
    </script>
</body>
</html>
