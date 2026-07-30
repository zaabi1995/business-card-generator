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
require_once INCLUDES_DIR . '/TenantHost.php';
require_once INCLUDES_DIR . '/AdminApprovalToken.php';

// Get company slug and optional department slug from URL. When the
// request lands on a tenant subdomain (ohb.cardify.om/portal), pull
// the slug from TenantHost so nginx doesn't need to inject it.
$companySlug = $_GET['company_slug'] ?? '';
$companySlug = trim(strtolower($companySlug));
if ($companySlug === '' && TenantHost::isTenantHost()) {
    $companySlug = (string) TenantHost::slug();
}
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

// Canonicalize to the subdomain: if we arrived via /{slug}/portal on
// the apex host, 301 to https://{slug}.cardify.om/portal[/{dept}].
$__h = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? ''));
if (in_array($__h, ['cardify.om', 'www.cardify.om'], true) && ($company['status'] ?? 'active') === 'active') {
    $path = '/portal' . ($departmentSlug ? '/' . $departmentSlug : '');
    header('Location: ' . getTenantUrl($companySlug, $path), true, 301);
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
        "SELECT id, name, slug, template_pair_id, portal_passcode, responsible_email, cc_emails, include_qr_default FROM departments WHERE company_id = :id AND portal_enabled = 1 ORDER BY name",
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

// Tenants whose divisions each carry their OWN card template (MHD) cannot be served
// by a generic form: the fields, the office numbers, the entity and the website all
// change with the division. For those, the division is step 1 and the form is built
// from it. Everyone else keeps the plain form with an optional department dropdown.
$divisionsWithTemplates = 0;
foreach ($departments as $__d) {
    if (!empty($__d['template_pair_id'])) { $divisionsWithTemplates++; }
}
$divisionRequired = $divisionsWithTemplates >= 2;
$divisionPickerRequired = $divisionRequired && !$selectedDepartment;

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
// Only DYNAMIC, per-employee fields become form inputs. Static decorations
// (is_static) and fields baked into the background PNG at import (render_in_bg)
// are not employee-editable; including them here rendered junk inputs like
// "Static 9" ... "Static 16" on the public portal (caught on falaj/hosn).
foreach ([$activeFrontTemplate, $activeBackTemplate] as $__tpl) {
    if (!$__tpl || empty($__tpl['fields'])) continue;
    foreach ($__tpl['fields'] as $key => $field) {
        if (empty($field['enabled'])) continue;
        if (!empty($field['is_static']) || !empty($field['render_in_bg'])) continue;
        $enabledFields[$key] = true;
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
        'position_en_2' => trim($_POST['position_en_2'] ?? ''),
        'position_ar_2' => trim($_POST['position_ar_2'] ?? ''),
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
    
    // Validate name only when the template exposes a name field. If the
    // imported card baked the name as a static decoration, no name_en input
    // is rendered, so requiring it here would reject every submission for a
    // field the visitor could never fill (matches the client-side guard).
    if (!$error && !empty($enabledFields['name_en']) && empty($formData['name_en'])) {
        $error = 'Name (English) is required.';
    }

    // When each division has its own card, a request with no division would be
    // rendered on whatever company-wide template happens to be newest, i.e. the
    // wrong card, and would route to no division mailbox. Refuse it.
    if (!$error && $divisionRequired && empty($formData['department_id'])) {
        $error = t('portal.division_required');
    }

    // Get request type and notes from form
    $requestType = $_POST['request_type'] ?? 'new';
    $requestNotes = trim($_POST['request_notes'] ?? '');
    $defaultQty = (int)($company['default_order_qty'] ?? 200);
    $quantityRequested = max(1, (int)($_POST['quantity_requested'] ?? $defaultQty));
    
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
        $maxSize = 5 * 1024 * 1024; // 5MB
        // SECURITY: this is a public, unauthenticated endpoint. Never trust
        // $_FILES['type'] (client-controlled) or the uploaded filename's
        // extension (lets a stranger drop request_xxx.php). Detect the real
        // MIME from file contents and derive a safe extension from it.
        // (BHD loop audit iter 4, 2 Jun 2026; matches project CLAUDE.md rule.)
        $mimeToExt = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/webp' => 'webp',
        ];
        $realMime = '';
        if (class_exists('finfo')) {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $realMime = (string) $finfo->file($_FILES['photo']['tmp_name']);
        }

        if (!isset($mimeToExt[$realMime])) {
            $error = 'Photo must be a JPEG, PNG, GIF, or WebP image.';
        } elseif ($_FILES['photo']['size'] > $maxSize) {
            $error = 'Photo must be less than 5MB.';
        } else {
            $uploadDir = getCompanyUploadsPath($companyId) . '/photos';
            if (!is_dir($uploadDir)) {
                @mkdir($uploadDir, 0755, true);
            }

            $ext = $mimeToExt[$realMime]; // extension from verified MIME, not filename
            $filename = 'request_' . uniqid() . '.' . $ext;
            $targetPath = $uploadDir . '/' . $filename;

            if (move_uploaded_file($_FILES['photo']['tmp_name'], $targetPath)) {
                @chmod($targetPath, 0644);
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

        // The client captures the rendered card as a data URL. Data-URI
        // images are stripped by many mail clients, so decode it, verify the
        // real MIME, and host it as a file; store the file path. A plain
        // URL/path is kept as-is. Returns '' on any problem (non-fatal).
        $resolvePreview = function (string $val, string $side) use ($companyId, $requestId) {
            if ($val === '') return '';
            if (strpos($val, 'data:image/') !== 0) return $val; // already a path/URL
            if (!preg_match('#^data:image/(png|jpeg);base64,#', $val)) return '';
            $bytes = base64_decode(substr($val, strpos($val, ',') + 1), true);
            if ($bytes === false || strlen($bytes) < 64 || strlen($bytes) > 3 * 1024 * 1024) return '';
            $realMime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
            $ext = ['image/png' => 'png', 'image/jpeg' => 'jpg'][$realMime] ?? '';
            if ($ext === '') return '';
            $dir = BASE_DIR . '/uploads/companies/' . $companyId . '/requests';
            if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) return '';
            $rel = 'uploads/companies/' . $companyId . '/requests/' . $requestId . '-' . $side . '.' . $ext;
            if (@file_put_contents(BASE_DIR . '/' . $rel, $bytes) === false) return '';
            return $rel;
        };
        $previewFront = $resolvePreview($previewFront, 'front');
        $previewBack = $resolvePreview($previewBack, 'back');

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
            
            // Add preview URLs if provided. Persist to both the display
            // columns (preview_front/back) and the path columns (used by
            // the admin approval flow + approval email) so the design
            // preview is never missing on one side.
            if (!empty($previewFront)) {
                $insertData['preview_front'] = $previewFront;
                $insertData['preview_front_path'] = $previewFront;
            }
            if (!empty($previewBack)) {
                $insertData['preview_back'] = $previewBack;
                $insertData['preview_back_path'] = $previewBack;
            }
            if (!empty($previewFront) || !empty($previewBack)) {
                $insertData['preview_generated_at'] = date('Y-m-d H:i:s');
            }
            
            $db->insert('card_requests', $insertData);

            $success = true;

            // MHD-style email-on-send: when the chosen department routes to a
            // responsible mailbox, generate the print-ready card and email it to
            // the employee + that mailbox + BHD sales. Notification-copy, no gate.
            $sendDept = null;
            foreach ($departments as $d) {
                if (($d['id'] ?? '') === ($formData['department_id'] ?? '')) { $sendDept = $d; break; }
            }
            if ($sendDept && !empty($sendDept['responsible_email'])) {
                try {
                    require_once INCLUDES_DIR . '/CardPDFRenderer.php';
                    require_once INCLUDES_DIR . '/MhdMailer.php';
                    // Upsert an employee from the request so it renders on the
                    // department's card template. id = email localpart.
                    $lp = strtolower(explode('@', $formData['email'])[0]);
                    $empId = preg_replace('/[^a-z0-9._-]/', '', $lp) ?: substr(md5($formData['email']), 0, 12);
                    // Mobile: the country prefix is baked on the card ("+968",
                    // or "+973" on the Bahrain Consumer card), so store digits only.
                    $mob = preg_replace('/^\+?9(68|73)[\s-]*/', '', trim($formData['mobile'] ?: $formData['phone']));
                    // Arabic-Indic mobile for the back (front stays Western digits).
                    // Array form, NOT strtr($s, '0123456789', '٠١٢٣٤٥٦٧٨٩'): the
                    // three-argument strtr maps BYTE for byte, and each Arabic-Indic
                    // digit is two bytes, so that form emits mojibake, not digits.
                    $mobAr = strtr($mob, ['0'=>'٠','1'=>'١','2'=>'٢','3'=>'٣','4'=>'٤',
                                          '5'=>'٥','6'=>'٦','7'=>'٧','8'=>'٨','9'=>'٩']);
                    $empData = [
                        'company_id'    => $companyId,
                        'name_en'       => $formData['name_en'],       'name_ar'       => $formData['name_ar'],
                        'position_en'   => $formData['position_en'],   'position_ar'   => $formData['position_ar'],
                        'position_en_2' => $formData['position_en_2'], 'position_ar_2' => $formData['position_ar_2'],
                        'mobile'        => $mob,                       'mobile_ar'     => $mobAr,
                        'email'         => $formData['email'],
                        'department_id' => $formData['department_id'] ?: null, 'status' => 'active',
                    ];
                    if ($db->fetchOne("SELECT id FROM employees WHERE id = :id", ['id' => $empId])) {
                        $db->update('employees', $empData, 'id = :id', ['id' => $empId]);
                    } else {
                        $db->insert('employees', ['id' => $empId] + $empData);
                    }
                    $includeQr = !empty($_POST['include_qr']);
                    $pdf = CardPDFRenderer::render($empId, 'print', ['include_qr' => $includeQr]);
                    if (!empty($pdf['success']) && is_file($pdf['path'])) {
                        $cc = array_values(array_filter(array_map('trim', explode(',', (string)($sendDept['cc_emails'] ?? '')))));
                        MhdMailer::sendCard([
                            'employee_email'    => $formData['email'],
                            'employee_name'     => $employeeName ?? ($formData['name_en'] ?: $formData['name_ar']),
                            'division_name'     => $sendDept['name'] ?? '',
                            'responsible_email' => $sendDept['responsible_email'],
                            'cc_emails'         => $cc,
                            'pdf_path'          => $pdf['path'],
                            'include_qr'        => $includeQr,
                        ]);
                    }
                } catch (Throwable $e) {
                    error_log('[portal mhd-send] ' . $e->getMessage());
                }
            }

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
                
                $adminUrl = getTenantUrl($companySlug, '/admin/requests');

                // Magic-link approval, the admin approves straight from the
                // email without logging in first. Token is scoped to this
                // company + request so a leaked link can't touch anything else.
                $approvalToken = AdminApprovalToken::mint($companyId, $requestId, $adminEmail);
                $approveUrl = getTenantUrl($companySlug, '/admin/one-tap-approve?t=' . urlencode($approvalToken));
                $reviewUrl = getTenantUrl($companySlug, '/admin/approve-request?t=' . urlencode($approvalToken));

                // Absolute design preview URL (APP_HOST, never HTTP_HOST) so
                // the image loads from a mail client with no session/cookies.
                $frontAbsUrl = '';
                $previewPath = $insertData['preview_front_path'] ?? $insertData['preview_front'] ?? '';
                if (!empty($previewPath)) {
                    $frontAbsUrl = 'https://' . (defined('APP_HOST') ? APP_HOST : 'cardify.om') . '/' . ltrim($previewPath, '/');
                }

                // Build the design block only when a preview exists, so the
                // email never shows a broken-image icon.
                $designPreviewHtml = $frontAbsUrl
                    ? '<p style="text-align:center;margin:16px 0;"><img src="' . htmlspecialchars($frontAbsUrl, ENT_QUOTES) . '" alt="Card design" style="max-width:320px;width:100%;border-radius:8px;border:1px solid #e5e7eb;"></p>'
                    : '';

                Mailer::sendTemplate($adminEmail, 'admin_new_request', [
                    'employee_name' => $employeeName,
                    'company_name' => $companyName,
                    'design_preview_html' => $designPreviewHtml,
                    'name_en' => $formData['name_en'],
                    'name_ar' => $formData['name_ar'],
                    'position_en' => $formData['position_en'],
                    'position_ar' => $formData['position_ar'],
                    'email' => $formData['email'],
                    'phone' => $formData['phone'],
                    'mobile' => $formData['mobile'],
                    'department' => $deptName,
                    'quantity' => $quantityRequested,
                    'design_front_url' => $frontAbsUrl,
                    'approve_url' => $approveUrl,
                    'review_url' => $reviewUrl,
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

require_once INCLUDES_DIR . '/OgImage.php';
$__ogLocale = function_exists('currentLocale') ? currentLocale() : 'en';
$__ogDept   = $selectedDepartment['slug'] ?? '';
$__ogImage  = OgImage::url($company, [
    'variant'    => 'portal',
    'locale'     => $__ogLocale,
    'department' => $__ogDept,
]);
$__ogDesc = ($__ogLocale === 'ar')
    ? ('اطلب بطاقة عملك من ' . $companyName . ' عبر Cardify.')
    : ('Request your business card from ' . $companyName . ' on Cardify.');
$__ogScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$__ogUrl = $__ogScheme . '://' . ($_SERVER['HTTP_HOST'] ?? (defined('APP_HOST') ? APP_HOST : 'cardify.om')) . ($_SERVER['REQUEST_URI'] ?? '/');
?>
<!DOCTYPE html>
<?php $__portalLocale = function_exists('currentLocale') ? currentLocale() : 'en'; $__portalDir = function_exists('currentDir') ? currentDir() : ($__portalLocale === 'ar' ? 'rtl' : 'ltr'); ?>
<html lang="<?= htmlspecialchars($__portalLocale) ?>" dir="<?= htmlspecialchars($__portalDir) ?>" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <?php
    // The portal is the tenant's own page, so it wears the tenant's icon when
    // one is set. Same resolver ui-header.php uses (TenantHost::theme() also
    // derives a favicon from the logo when favicon_path is empty), instead of
    // hardcoding the Cardify mark on every tenant.
    $__portalFav = null;
    if (class_exists('TenantHost')) {
        try { $__portalFav = TenantHost::theme()['favicon'] ?? null; } catch (Throwable $e) { $__portalFav = null; }
    }
    if (!empty($__portalFav)):
        $__favType = preg_match('/\.svg(\?|$)/i', $__portalFav) ? 'image/svg+xml'
                   : (preg_match('/\.ico(\?|$)/i', $__portalFav) ? 'image/x-icon' : 'image/png');
    ?>
    <link rel="icon" href="<?= htmlspecialchars($__portalFav, ENT_QUOTES) ?>" type="<?= $__favType ?>">
    <link rel="apple-touch-icon" href="<?= htmlspecialchars($__portalFav, ENT_QUOTES) ?>">
    <?php else: ?>
    <link rel="icon" href="<?php echo getBasePath(); ?>favicon.svg" type="image/svg+xml">
    <?php endif; ?>

    <meta name="description" content="<?= htmlspecialchars($__ogDesc) ?>">
    <meta property="og:type" content="website">
    <meta property="og:title" content="<?= htmlspecialchars($pageTitle) ?>">
    <meta property="og:description" content="<?= htmlspecialchars($__ogDesc) ?>">
    <meta property="og:url" content="<?= htmlspecialchars($__ogUrl) ?>">
    <meta property="og:site_name" content="<?= htmlspecialchars($companyName) ?> · Cardify">
    <meta property="og:image" content="<?= htmlspecialchars($__ogImage) ?>">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:alt" content="<?= htmlspecialchars($companyName . ' business card portal') ?>">
    <meta property="og:locale" content="<?= $__ogLocale === 'ar' ? 'ar_OM' : 'en_US' ?>">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= htmlspecialchars($pageTitle) ?>">
    <meta name="twitter:description" content="<?= htmlspecialchars($__ogDesc) ?>">
    <meta name="twitter:image" content="<?= htmlspecialchars($__ogImage) ?>">
    
    <!-- Webfonts (fonts.bhd.om, never Google) -->
    <link rel="preconnect" href="https://fonts.bhd.om">
    <link rel="preconnect" href="https://fonts.bhd.om" crossorigin>
    <link href="https://fonts.bhd.om/css2?family=Inter:wght@300;400;500;600;700;800&family=Cairo:wght@300;400;500;600;700;800;900&family=IBM+Plex+Sans+Arabic:wght@300;400;500;600;700&family=Tajawal:wght@300;400;500;700;800;900&display=swap" rel="stylesheet">
    <!-- Cardify portal redesign: Sora display face (Latin), Tajawal covers Arabic display, served from fonts.bhd.om -->
    <link href="https://fonts.bhd.om/css2?family=Sora:wght@500;600;700;800&display=swap" rel="stylesheet">

    <?php
    // Imported PDF templates can reference any number of font families
    // (Sora, Lato, Work Sans, etc). Without these loaded the Fabric preview
    // falls back to Arial and the design looks completely wrong.
    $importedFonts = [];
    $importTokens  = [];
    foreach ([$activeFrontTemplate, $activeBackTemplate] as $tpl) {
        if (!$tpl) continue;
        if (!empty($tpl['settings']['fonts_used'])) {
            foreach ($tpl['settings']['fonts_used'] as $fam) {
                $fam = trim((string)$fam);
                if ($fam !== '') $importedFonts[$fam] = true;
            }
        }
        // Also collect the EXACT fontFamily each enabled field asks for. A
        // field can reference a specific face (e.g. "FrutigerLTStd-Black" for
        // the name) that settings.fonts_used ("FrutigerLTStd") does not list,
        // so without this the dynamic text fell back to a system sans while the
        // baked static text was the real imported face.
        if (!empty($tpl['fields']) && is_array($tpl['fields'])) {
            foreach ($tpl['fields'] as $f) {
                if (!is_array($f) || empty($f['enabled'])) continue;
                $ff = trim((string)($f['fontFamily'] ?? ''));
                if ($ff !== '') $importedFonts[$ff] = true;
            }
        }
        // Derive the import-dir token(s) that hold the licensed font files from
        // the template's background + source paths (uploads/templates/imports/<token>/...),
        // so the font registry scans them (MHD's Frutiger lives there, not in the library).
        foreach ([$tpl['backgroundImage'] ?? '', $tpl['originalPdf'] ?? ''] as $p) {
            if (preg_match('#uploads/templates/imports/([^/]+)/#', (string)$p, $mm)) {
                $importTokens[$mm[1]] = true;
            }
        }
    }
    // Whitelist Google-Fonts-hosted families (anything else likely needs a
    // commercial license, so we only auto-load these).
    $googleFontWhitelist = [
        'Sora', 'Lato', 'Work Sans', 'Inter', 'Roboto', 'Open Sans', 'Montserrat',
        'Poppins', 'Raleway', 'Oswald', 'Merriweather', 'Playfair Display',
        'Noto Sans', 'Noto Serif', 'Noto Sans Arabic', 'Noto Kufi Arabic',
        'Cairo', 'Tajawal', 'Amiri', 'Reem Kufi', 'Changa', 'IBM Plex Sans',
    ];
    $loadFams = [];
    foreach ($importedFonts as $fam => $_) {
        foreach ($googleFontWhitelist as $g) {
            if (strcasecmp($fam, $g) === 0) { $loadFams[] = $g; break; }
        }
    }
    if (!empty($loadFams)) {
        $loadFams = array_values(array_unique($loadFams));
        $famParts = array_map(function($f){
            // Request the full static-weight ladder so any weight the
            // source PDF used (Light 300, Regular 400, Medium 500,
            // SemiBold 600, Bold 700, ExtraBold 800, Black 900) is
            // available. Browser falls back to nearest if a particular
            // weight isn't published for that family.
            return 'family=' . str_replace(' ', '+', $f) . ':ital,wght@0,100;0,200;0,300;0,400;0,500;0,600;0,700;0,800;0,900;1,400;1,500;1,700';
        }, $loadFams);
        echo '<link href="https://fonts.bhd.om/css2?' . implode('&', $famParts) . '&display=swap" rel="stylesheet">' . "\n";
    }

    // Cardify font registry: emit @font-face for any family the active
    // template references that we have on the server, either in the
    // global library or as a company upload. Company uploads win on
    // (family, weight, style) collisions so a tenant can drop in a
    // licensed Lato-Medium and override Google Fonts' nearest-weight
    // fallback.
    require_once INCLUDES_DIR . '/CompanyFonts.php';
    $registryCss = CompanyFonts::fontFaceCss(
        realpath(__DIR__),
        $companyId,
        array_keys($importedFonts),
        array_keys($importTokens)
    );
    if ($registryCss) {
        echo "<style id=\"cardify-font-registry\">\n" . $registryCss . "</style>\n";
    }
    ?>

    <!-- Myriad Pro (matches admin designer so Fabric preview renders the same face) -->
    <style>
        @font-face { font-family: 'Myriad Pro'; font-weight: 300; src: url('<?php echo getBasePath(); ?>assets/fonts/myriad-pro/MyriadPro-Light.otf') format('opentype'); font-display: swap; }
        @font-face { font-family: 'Myriad Pro'; font-weight: 400; src: url('<?php echo getBasePath(); ?>assets/fonts/myriad-pro/MyriadPro-Regular.otf') format('opentype'); font-display: swap; }
        @font-face { font-family: 'Myriad Pro'; font-weight: 600; src: url('<?php echo getBasePath(); ?>assets/fonts/myriad-pro/MyriadPro-SemiBold.otf') format('opentype'); font-display: swap; }
        @font-face { font-family: 'Myriad Pro'; font-weight: 700; src: url('<?php echo getBasePath(); ?>assets/fonts/myriad-pro/MyriadPro-Bold.otf') format('opentype'); font-display: swap; }
    </style>

    <!-- Font Awesome 7.2 Pro (design.bhd.om), ?v busts stale CF cache -->
    <link rel="stylesheet" href="https://design.bhd.om/fa/v7.2.0/css/fontawesome.min.css?v=7.2.0">
    <link rel="stylesheet" href="https://design.bhd.om/fa/v7.2.0/css/solid.min.css?v=7.2.0">
    <link rel="stylesheet" href="https://design.bhd.om/fa/v7.2.0/css/brands.min.css?v=7.2.0">
    
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
            border-color: #00718c;
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
        /* The AI Translate buttons sit in the field label, not on the input, so
           they need their own busy state rather than .translate-btn's. */
        .spinner { animation: spin 1s linear infinite; display: inline-block; }
        button.is-translating { opacity: 0.75; cursor: progress; }
        .is-translating-target {
            background-image: linear-gradient(90deg,
                rgba(59,130,246,0) 0%, rgba(59,130,246,0.14) 50%, rgba(59,130,246,0) 100%);
            background-size: 220% 100%;
            animation: translateSweep 1.1s linear infinite;
        }
        @keyframes translateSweep {
            from { background-position: 120% 0; }
            to   { background-position: -120% 0; }
        }
        @media (prefers-reduced-motion: reduce) {
            .spinner, .is-translating-target { animation: none; }
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

    <!-- Cardify portal redesign: clean cyan "watch your card fill" flow, mobile-first. Scoped under .issuance. -->
    <style>
    .issuance{
        --brand:#009bc1;--brand-600:#0086a6;--brand-700:#00708c;
        --brand-050:#e9f6fa;--brand-100:#cfeaf1;--brand-200:#a7d9e6;
        --ink:#0f172a;--ink-soft:#475569;--ink-faint:#8a97a8;
        --line:#e4e9ef;--line-soft:#eef2f6;--surface:#fff;--err:#dc2626;
        --r:14px;--r-lg:20px;--r-pill:999px;
        --shadow:0 1px 2px rgba(15,23,42,.04),0 18px 40px -22px rgba(2,60,80,.42);
        --ease:cubic-bezier(.2,.8,.3,1);
        color:var(--ink);
        font-family:'IBM Plex Sans Arabic','Inter',system-ui,-apple-system,sans-serif;
    }
    .issuance .display{font-family:'Sora','IBM Plex Sans Arabic',system-ui,sans-serif;letter-spacing:-.02em}
    [dir=rtl] .issuance .display{font-family:'Tajawal','IBM Plex Sans Arabic',sans-serif;letter-spacing:0}
    [lang="ar"] .issuance{letter-spacing:0}

    /* fixed reading-direction progress ribbon */
    .issue-ribbon{position:fixed;top:0;left:0;right:0;height:3px;background:var(--line-soft);z-index:60}
    .issue-ribbon i{position:absolute;top:0;bottom:0;inset-inline-start:0;width:0;
        background:linear-gradient(90deg,var(--brand),var(--brand-700));box-shadow:0 0 10px rgba(0,155,193,.5);
        transition:width .5s var(--ease)}
    [dir=rtl] .issue-ribbon i{background:linear-gradient(270deg,var(--brand),var(--brand-700))}

    .issue-grid{display:grid;grid-template-columns:1fr;gap:20px;align-items:start}
    @media(min-width:920px){.issue-grid{grid-template-columns:minmax(0,1fr) minmax(0,440px);gap:52px}}

    /* --- live card panel: stays visible so you watch the card fill --- */
    .issue-card-hold{order:-1;position:sticky;top:74px;z-index:30;padding:10px 0;margin:-6px 0 2px;
        background:linear-gradient(180deg,rgba(249,250,251,.96),rgba(249,250,251,.72));backdrop-filter:blur(6px)}
    @media(min-width:920px){.issue-card-hold{order:1;top:96px;padding:0;margin:0;background:none;backdrop-filter:none}}
    .issue-card-label{font-size:11px;font-weight:700;letter-spacing:.14em;text-transform:uppercase;
        color:var(--brand-700);margin-bottom:10px;display:flex;align-items:center;gap:8px}
    [dir=rtl] .issue-card-label{letter-spacing:0}
    .issue-card-label::before{content:"";width:22px;height:2px;background:var(--brand);border-radius:2px;flex:none}
    .issue-card-label .live-dot{width:7px;height:7px;border-radius:50%;background:var(--brand);
        margin-inline-start:auto;animation:livepulse 2.4s var(--ease) infinite}
    @keyframes livepulse{0%{box-shadow:0 0 0 0 rgba(0,155,193,.5)}70%{box-shadow:0 0 0 7px rgba(0,155,193,0)}100%{box-shadow:0 0 0 0 rgba(0,155,193,0)}}
    .issue-card-stack{display:flex;flex-direction:column;gap:12px}
    .issue-card-stack .canvas-preview-wrapper{border:1px solid var(--line);border-radius:12px;box-shadow:var(--shadow)}
    .issue-card-fallback{border:1px solid var(--line);border-radius:12px;overflow:hidden;background:#fff;box-shadow:var(--shadow)}
    .issue-card-fallback img{display:block;width:100%}
    .issue-card-note{margin-top:12px;font-size:12.5px;color:var(--ink-soft);display:flex;align-items:flex-start;gap:8px}
    .issue-card-note i{color:var(--brand-700);font-style:normal;margin-top:1px}
    @media(max-width:919px){
        .issue-card-note{display:none}
        .issue-card-stack{max-width:340px;margin-inline:auto}
        .issue-card-stack .canvas-preview-wrapper:nth-child(2){display:none} /* front only on mobile */
    }

    /* flow panel + question steps */
    .issue-flow{min-height:56vh}
    .issue-intro{margin-bottom:18px}
    .issue-intro h2{font-size:clamp(25px,5vw,36px);line-height:1.05;font-weight:800}
    .issue-intro p{margin-top:8px;color:var(--ink-soft);font-size:15px;max-width:46ch}
    .issue-domain{margin-top:12px;font-size:12.5px;color:var(--brand-700);display:inline-flex;align-items:center;gap:7px;
        background:var(--brand-050);border:1px solid var(--brand-100);padding:6px 12px;border-radius:999px;font-weight:600}

    /* segmented progress pips (clickable back to completed steps) */
    .issue-pips{display:flex;align-items:center;gap:7px;margin-bottom:22px;padding:0}
    .issue-pip{height:6px;flex:1;min-width:20px;border:none;padding:0;border-radius:999px;background:var(--line);
        transition:background .35s var(--ease),transform .12s var(--ease);cursor:default}
    .issue-pip.done{background:var(--brand)}
    .issue-pip.current{background:linear-gradient(90deg,var(--brand),var(--brand-200))}
    .issue-pip.clickable{cursor:pointer}
    .issue-pip.clickable:hover{transform:scaleY(1.5)}

    .issue-err-box{border:1px solid #f3c7c1;background:#fdecea;border-radius:12px;padding:12px 15px;
        margin-bottom:20px;color:var(--err);font-size:13.5px;display:flex;gap:10px;align-items:flex-start}

    .q{display:none}
    .q.active{display:block;animation:qin .4s var(--ease)}
    @keyframes qin{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:none}}
    .q-kicker{font-size:12px;font-weight:700;color:var(--brand-700);margin-bottom:8px;text-transform:uppercase;
        letter-spacing:.02em;font-variant-numeric:tabular-nums}
    [dir=rtl] .q-kicker{letter-spacing:0}
    .q-title{font-family:'Sora','IBM Plex Sans Arabic',sans-serif;font-weight:700;font-size:clamp(21px,4vw,28px);
        line-height:1.14;margin-bottom:6px;letter-spacing:-.01em}
    [dir=rtl] .q-title{font-family:'Tajawal','IBM Plex Sans Arabic',sans-serif;letter-spacing:0}
    .q-help{font-size:14px;color:var(--ink-soft);margin-bottom:18px;max-width:46ch;line-height:1.5}
    .q-fields{display:flex;flex-direction:column;gap:16px}
    .q-fields > div{margin:0}

    /* restyle the existing inputs to the Cardify system, without changing markup/ids/names */
    .issuance .form-input{background:var(--surface);border:1.5px solid var(--line);border-radius:11px;font-size:15px;
        color:var(--ink);padding:12px 14px;transition:border-color .15s,box-shadow .15s}
    .issuance .form-input:focus{border-color:var(--brand);box-shadow:0 0 0 4px rgba(0,155,193,.14)}
    .issuance textarea.form-input{min-height:78px}
    .issuance label{color:var(--ink)}
    .issuance .auto-filled{background:var(--brand-050)!important;border-color:var(--brand-200)!important}
    /* recolor legacy blue/green accents inside the flow to brand cyan */
    .issuance .text-blue-600{color:var(--brand-600)!important}
    .issuance .hover\:text-blue-700:hover{color:var(--brand-700)!important}
    .issuance #existingEmployeeNotice{background:var(--brand-050)!important;border-color:var(--brand-100)!important}
    .issuance #existingEmployeeNotice .text-blue-800{color:var(--brand-700)!important}
    .issuance input[type=checkbox],.issuance input[type=radio]{accent-color:var(--brand)}
    .issuance #requestTypeSection label{transition:border-color .15s,background .15s}
    .issuance #requestTypeSection label:hover{border-color:var(--brand-200);background:var(--brand-050)}

    /* review step */
    .issue-summary{border:1px solid var(--line);border-radius:var(--r);background:var(--surface);overflow:hidden;
        margin:6px 0 16px;box-shadow:var(--shadow)}
    .issue-summary .r{display:flex;justify-content:space-between;gap:14px;padding:12px 16px;
        border-bottom:1px solid var(--line-soft);font-size:13.5px}
    .issue-summary .r:last-child{border-bottom:none}
    .issue-summary .r span{color:var(--ink-soft)}
    .issue-summary .r b{font-weight:600;text-align:end;max-width:60%;overflow-wrap:anywhere}
    .issue-order{border:1.5px solid var(--brand-100);border-radius:var(--r);
        background:linear-gradient(180deg,#fff,var(--brand-050));padding:16px 18px;margin-bottom:16px;
        display:flex;align-items:center;justify-content:space-between;gap:14px}
    .issue-order b{font-family:'Sora',sans-serif;font-size:30px;font-weight:800;color:var(--brand-700);line-height:1}
    [dir=rtl] .issue-order b{font-family:'Tajawal',sans-serif}
    .issue-order .u{font-size:12.5px;color:var(--ink-soft)}
    .issue-order .lot{text-align:end;font-size:12.5px;color:var(--ink-soft);max-width:16ch}

    /* nav */
    .issue-nav{display:flex;align-items:center;gap:12px;margin-top:24px}
    @media(max-width:919px){
        .issue-nav{position:sticky;bottom:0;margin:20px -16px 0;padding:12px 16px calc(12px + env(safe-area-inset-bottom));
            background:linear-gradient(180deg,rgba(255,255,255,0),#fff 36%);border-top:1px solid var(--line-soft)}
    }
    .issue-next{border:none;border-radius:var(--r-pill);padding:14px 28px;font:inherit;font-weight:600;font-size:15px;
        color:#fff;cursor:pointer;background:linear-gradient(135deg,var(--brand),var(--brand-700));
        box-shadow:0 14px 26px -12px rgba(0,134,166,.9);display:inline-flex;align-items:center;gap:10px;
        transition:transform .12s var(--ease),box-shadow .2s var(--ease)}
    .issue-next:hover{transform:translateY(-1px);box-shadow:0 18px 30px -12px rgba(0,134,166,.9)}
    .issue-next:active{transform:translateY(0) scale(.985)}
    @media(max-width:919px){.issue-next{flex:1;justify-content:center}}
    .issue-back{border:none;background:none;color:var(--ink-soft);font:inherit;font-weight:600;font-size:14px;
        cursor:pointer;padding:11px 8px;border-radius:10px;transition:color .15s}
    .issue-back:hover{color:var(--ink)}
    .issue-back[hidden]{display:none}
    .issue-enter{margin-inline-start:auto;font-size:11.5px;color:var(--ink-faint)}
    .issue-enter b{background:#fff;border:1px solid var(--line);border-radius:5px;padding:1px 6px;font-weight:600}
    @media(max-width:919px){.issue-enter{display:none}}
    /* the old two-button preview/submit controls are driven by the step machine now */
    .issuance #generatePreviewSection{display:none !important}
    .issuance #submitSection{display:block !important}
    .issuance #submitSection > button[type="button"]{display:none}
    .issuance #submitRequestBtn{width:100%;border:none;border-radius:var(--r-pill);padding:15px 26px;font-weight:700;
        font-size:15.5px;color:#fff;cursor:pointer;background:linear-gradient(135deg,var(--brand),var(--brand-700));
        box-shadow:0 16px 30px -12px rgba(0,134,166,.9);transition:transform .12s var(--ease)}
    .issuance #submitRequestBtn:hover{transform:translateY(-1px)}
    .issuance #submitRequestBtn:active{transform:scale(.99)}
    .issuance #submitSection .bg-green-50{background:var(--brand-050)!important;border-color:var(--brand-100)!important}
    .issuance #submitSection .text-green-800,.issuance #submitSection .text-green-700{color:var(--brand-700)!important}

    /* the Cardify "issuing your card" seal on submit */
    .issue-seal{position:fixed;inset:0;z-index:80;background:rgba(2,32,44,.55);backdrop-filter:blur(5px);
        display:none;place-items:center;padding:22px}
    .issue-seal.on{display:grid;animation:sealbg .3s ease}
    @keyframes sealbg{from{opacity:0}to{opacity:1}}
    .issue-seal-card{background:#fff;border:1px solid var(--brand-100);border-radius:var(--r-lg);
        max-width:400px;width:100%;padding:36px 28px;text-align:center;box-shadow:0 40px 80px -30px rgba(2,60,80,.6)}
    .issue-stamp{width:96px;height:96px;margin:0 auto 16px;display:grid;place-items:center;border-radius:50%;
        background:linear-gradient(135deg,var(--brand),var(--brand-700));color:#fff;
        box-shadow:0 18px 34px -14px rgba(0,134,166,.9)}
    .issue-stamp svg{width:46px;height:46px}
    .issue-stamp .chk{stroke-dasharray:48;stroke-dashoffset:48}
    .issue-seal.on .issue-stamp{animation:pop .5s var(--ease) both}
    .issue-seal.on .issue-stamp .chk{animation:draw .5s var(--ease) .18s both}
    @keyframes pop{0%{transform:scale(.4);opacity:0}60%{transform:scale(1.06)}100%{transform:scale(1);opacity:1}}
    @keyframes draw{to{stroke-dashoffset:0}}
    .issue-seal-card h3{font-family:'Sora',sans-serif;font-size:22px;font-weight:800;margin-bottom:6px;color:var(--ink)}
    [dir=rtl] .issue-seal-card h3{font-family:'Tajawal',sans-serif}
    .issue-seal-card p{color:var(--ink-soft);font-size:14px}

    @media(prefers-reduced-motion:reduce){
        .issue-ribbon i{transition:none}
        .q.active{animation:none}
        .issue-seal.on .issue-stamp,.issue-seal.on .issue-stamp .chk{animation:none;stroke-dashoffset:0}
        .issue-next,.issue-back,.issue-card-label .live-dot,.issue-pip{transition:none;animation:none}
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
                <div class="flex items-center justify-between gap-3">
                    <div class="flex items-center gap-3 min-w-0 flex-1">
                        <?php
                        $logoPath = $companyTheme['logo_path'] ?? $company['logo_path'] ?? null;
                        if (!empty($logoPath)):
                        ?>
                        <img src="<?php echo imageUrl($logoPath); ?>" alt="<?php echo htmlspecialchars($companyName); ?>" class="h-10 w-auto rounded-xl flex-shrink-0">
                        <?php else: ?>
                        <div class="w-10 h-10 rounded-xl flex items-center justify-center text-white font-bold text-sm flex-shrink-0" style="background:linear-gradient(135deg,#009bc1,#00708c)">
                            <?php echo strtoupper(substr($companyName, 0, 2)); ?>
                        </div>
                        <?php endif; ?>
                        <div class="min-w-0">
                            <h1 class="text-lg font-bold text-gray-900 truncate"><?php echo htmlspecialchars($companyName); ?></h1>
                            <p class="text-xs text-gray-500 truncate">
                                <?php if ($selectedDepartment): ?>
                                    <?php echo htmlspecialchars($selectedDepartment['name']); ?> - <?= htmlspecialchars(t('portal.business_card_portal')) ?>
                                <?php else: ?>
                                    <?= htmlspecialchars(t('portal.business_card_portal')) ?>
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 flex-shrink-0">
                        <?php if (function_exists('currentLocale') && file_exists(INCLUDES_DIR . '/lang-switcher.php')): ?>
                            <?php $cardifyLangSwitchMode = 'query'; ?><?php require INCLUDES_DIR . '/lang-switcher.php'; ?>
                        <?php endif; ?>
                        <a href="<?= htmlspecialchars(getTenantUrl($companySlug, '/admin/login'), ENT_QUOTES, 'UTF-8') ?>" class="inline-flex items-center gap-2 px-3 sm:px-4 py-2 text-sm text-gray-600 hover:text-gray-900 font-medium" title="<?= htmlspecialchars(t('portal.admin_login')) ?>">
                            <i class="fa-solid fa-lock"></i><span class="hidden sm:inline"><?= htmlspecialchars(t('portal.admin_login')) ?></span>
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
                    <a href="<?= htmlspecialchars(getTenantUrl($companySlug, '/'), ENT_QUOTES, 'UTF-8') ?>" class="text-blue-600 hover:text-blue-700 font-medium">
                        <i class="fa-solid fa-arrow-left mr-1"></i> <?= htmlspecialchars(t('cardportal.back_to_company')) ?>
                    </a>
                </p>
            </div>
            
            <?php elseif ($passcodeRequired && !$passcodeVerified): ?>
            <!-- Passcode Entry Form -->
            <div class="max-w-sm mx-auto bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                <div class="px-6 py-6 text-white text-center" style="background:linear-gradient(90deg,#0086a6,#00708c)">
                    <i class="fa-solid fa-lock text-4xl mb-3"></i>
                    <h2 class="text-xl font-bold"><?= htmlspecialchars(t('cardportal.access_code_h2')) ?></h2>
                    <p class="text-sm mt-1" style="color:#cfeaf1">
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
                    <button type="submit" class="w-full px-6 py-3 text-white font-semibold rounded-lg transition-colors" style="background:#0086a6">
                        <i class="fa-solid fa-unlock mr-2"></i>
                        <?= htmlspecialchars(t('cardportal.access_portal_btn')) ?>
                    </button>
                </form>
                
                <div class="px-6 pb-6 text-center">
                    <a href="<?= htmlspecialchars(getTenantUrl($companySlug, '/'), ENT_QUOTES, 'UTF-8') ?>" class="text-sm text-gray-500 hover:text-gray-700">
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
                <div class="w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-4" style="background:#e9f6fa">
                    <i class="fa-solid fa-check text-2xl" style="color:#00718c"></i>
                </div>
                <h2 class="text-2xl font-bold text-gray-900 mb-2"><?= htmlspecialchars(t('cardportal.request_submitted_h2')) ?></h2>
                <p class="text-gray-600 mb-6">
                    <?= htmlspecialchars(t('cardportal.request_submitted_body')) ?>
                </p>
                <div class="rounded-lg p-4 text-left inline-block" style="background:#e9f6fa">
                    <h3 class="font-semibold mb-2" style="color:#00708c"><?= htmlspecialchars(t('cardportal.whats_next')) ?></h3>
                    <ul class="text-sm space-y-1" style="color:#00718c">
                        <li><i class="fa-solid fa-envelope mr-2"></i><?= htmlspecialchars(t('cardportal.next_email')) ?></li>
                        <li><i class="fa-solid fa-clock mr-2"></i><?= htmlspecialchars(t('cardportal.next_review')) ?></li>
                        <li><i class="fa-solid fa-id-card mr-2"></i><?= htmlspecialchars(t('cardportal.next_generate')) ?></li>
                        <li><i class="fa-solid fa-paper-plane mr-2"></i><?= htmlspecialchars(t('cardportal.next_deliver')) ?></li>
                    </ul>
                </div>
                <p class="mt-6">
                    <a href="<?php echo getTenantUrl($companySlug, '/portal'); ?>" class="font-medium" style="color:#00718c">
                        <i class="fa-solid fa-arrow-left mr-1"></i> <?= htmlspecialchars(t('cardportal.submit_another')) ?>
                    </a>
                </p>
            </div>
            
            <?php elseif ($divisionPickerRequired): ?>
            <!-- Step 1: division. Each division owns its card template, so the form
                 cannot be built until we know which one. Every tile routes to
                 /portal/{slug}, where the fields come from that division's template. -->
            <div class="max-w-3xl mx-auto">
                <?php if ($error): ?>
                <div class="mb-6 bg-red-50 border border-red-200 text-red-700 rounded-lg px-4 py-3 flex items-center gap-2">
                    <i class="fa-solid fa-circle-exclamation"></i>
                    <span class="text-sm"><?php echo htmlspecialchars($error); ?></span>
                </div>
                <?php endif; ?>
                <div class="text-center mb-8">
                    <h2 class="text-2xl font-bold text-gray-900"><?= htmlspecialchars(t('portal.division_pick_h2')) ?></h2>
                    <p class="text-gray-600 mt-2"><?= htmlspecialchars(t('portal.division_pick_sub')) ?></p>
                </div>
                <!-- inline grid: the compiled Tailwind build here has no sm:grid-cols-2 -->
                <div style="display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(260px,1fr))">
                    <?php foreach ($departments as $dept): ?>
                    <?php if (empty($dept['slug'])) continue; ?>
                    <a href="<?= htmlspecialchars(getTenantUrl($companySlug, '/portal/' . $dept['slug']), ENT_QUOTES, 'UTF-8') ?>"
                       class="group flex items-center justify-between gap-3 bg-white border border-gray-200 rounded-xl px-5 py-4 hover:border-blue-400 hover:shadow-sm transition-all">
                        <span class="font-semibold text-gray-900"><?= htmlspecialchars($dept['name']) ?></span>
                        <i class="fa-solid fa-arrow-right text-gray-300 group-hover:text-blue-500 transition-colors"></i>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php else: ?>
            <!-- Issuance flow (Concept B redesign). A single <form id="cardRequestForm">
                 still submits every field; the multi-step is client-side progressive
                 disclosure over the SAME fields, so the PHP handler is unchanged. -->
            <div class="issuance" id="issuance">
                <div class="issue-ribbon" aria-hidden="true"><i id="issueRibbon"></i></div>

                <?php if ($error): ?>
                <div class="issue-err-box">
                    <i class="fa-solid fa-circle-exclamation"></i>
                    <p><?php echo htmlspecialchars($error); ?></p>
                </div>
                <?php endif; ?>

                <div class="issue-grid">
                    <!-- Perpetual card: the existing Fabric live preview IS the card that fills -->
                    <aside class="issue-card-hold">
                        <div class="issue-card-label"><span id="previewTitle"><?= htmlspecialchars(t('portal.issue_card_label')) ?></span><span class="live-dot" aria-hidden="true"></span></div>
                        <?php if ($showPreview && ($activeFrontTemplate || $activeBackTemplate)): ?>
                        <div class="issue-card-stack" id="generatedPreview">
                            <div class="canvas-preview-wrapper rounded-lg border border-gray-200 relative">
                                <canvas id="previewFrontCanvas"></canvas>
                                <div id="frontLoading" class="absolute inset-0 bg-gray-100 flex items-center justify-center">
                                    <i class="fa-solid fa-spinner fa-spin text-gray-400 text-2xl"></i>
                                </div>
                            </div>
                            <div class="canvas-preview-wrapper rounded-lg border border-gray-200 relative">
                                <canvas id="previewBackCanvas"></canvas>
                                <div id="backLoading" class="absolute inset-0 bg-gray-100 flex items-center justify-center">
                                    <i class="fa-solid fa-spinner fa-spin text-gray-400 text-2xl"></i>
                                </div>
                            </div>
                        </div>
                        <?php elseif ($activeFrontTemplate && !empty($activeFrontTemplate['backgroundImage'])): ?>
                        <div class="issue-card-fallback">
                            <img src="<?php echo imageUrl($activeFrontTemplate['backgroundImage']); ?>" alt="<?= htmlspecialchars($companyName) ?>">
                        </div>
                        <?php endif; ?>
                        <p class="issue-card-note"><i class="fa-solid fa-lock"></i><span><?= htmlspecialchars(t('portal.issue_watermark')) ?></span></p>
                    </aside>

                    <!-- Flow: the question steps -->
                    <section class="issue-flow">
                        <div class="issue-intro">
                            <h2 class="display"><?= htmlspecialchars(t('cardportal.request_form_h2')) ?></h2>
                            <p><?= htmlspecialchars(t('cardportal.request_form_sub')) ?></p>
                            <?php if ($companyDomain): ?>
                            <p class="issue-domain"><i class="fa-solid fa-shield-halved"></i><?= htmlspecialchars(t('cardportal.domain_restricted', ['domain' => $companyDomain])) ?></p>
                            <?php endif; ?>
                        </div>

                <form method="POST" enctype="multipart/form-data" id="cardRequestForm">
                    <?php echo csrfField(); ?>
                    <!-- Hidden inputs for preview card URLs (captured client-side on submit) -->
                    <input type="hidden" name="preview_front" id="preview_front_input" value="">
                    <input type="hidden" name="preview_back" id="preview_back_input" value="">

                    <!-- Every field lives inside #issueFields; the step machine regroups
                         them into question panels at runtime without renaming anything. -->
                    <div id="issueFields">

                    <!-- Photo upload (Concept B step; handler already reads $_FILES['photo']) -->
                    <div id="photoBlock">
                        <label class="block text-sm font-semibold text-gray-700 mb-2"><?= htmlspecialchars(t('portal.issue_photo_cta')) ?></label>
                        <input type="file" name="photo" id="photo" accept="image/jpeg,image/png,image/gif,image/webp" class="form-input">
                        <p class="mt-1 text-xs text-gray-500"><?= htmlspecialchars(t('portal.issue_photo_hint')) ?></p>
                    </div>

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
                            <option value="100"><?= htmlspecialchars(str_replace(':n', '100', t('portal.quantity_n'))) ?></option>
                            <option value="200" selected><?= htmlspecialchars(t('portal.quantity_200')) ?></option>
                            <option value="500"><?= htmlspecialchars(str_replace(':n', '500', t('portal.quantity_n'))) ?></option>
                            <option value="1000"><?= htmlspecialchars(str_replace(':n', '1000', t('portal.quantity_n'))) ?></option>
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

                    <!-- Sub-title / second position line (English). Gated by the
                         template like every other field: only shown when the design
                         actually has a position_en_2 slot (OHB does not, so it hides). -->
                    <?php if (!empty($enabledFields['position_en_2'])): ?>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2"><?= htmlspecialchars(t('portal.position_en_2')) ?></label>
                        <input type="text" name="position_en_2" id="position_en_2"
                               value="<?php echo htmlspecialchars($formData['position_en_2'] ?? ''); ?>"
                               placeholder="Corporate Sales"
                               class="form-input">
                    </div>
                    <?php endif; ?>
                    <!-- Sub-title / second position line (Arabic) -->
                    <?php if (!empty($enabledFields['position_ar_2'])): ?>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">
                            <?= htmlspecialchars(t('portal.position_ar_2')) ?>
                            <button type="button" class="ml-2 text-xs text-blue-600 hover:text-blue-700" onclick="translateField('position_en_2', 'position_ar_2', 'position')">
                                <i class="fa-solid fa-wand-magic-sparkles"></i> <?= htmlspecialchars(t('portal.ai_translate')) ?>
                            </button>
                        </label>
                        <input type="text" name="position_ar_2" id="position_ar_2"
                               value="<?php echo htmlspecialchars($formData['position_ar_2'] ?? ''); ?>"
                               placeholder="مبيعات الشركات"
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
                               placeholder="<?= $divisionRequired ? '98765432' : '+968 9876 5432' ?>"
                               class="form-input">
                        <?php if ($divisionRequired): ?>
                        <p class="text-xs text-gray-500 mt-1"><?= htmlspecialchars(t('portal.mobile_cc_hint')) ?></p>
                        <?php endif; ?>
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

                    <!-- Mobile (Arabic): derived from the mobile above, never typed.
                         Kept as a rendered text input (display:none, NOT type=hidden)
                         because the issuance step machine drops hidden inputs from the
                         live-preview payload, so a type=hidden field would leave the
                         Arabic back of the card blank. -->
                    <?php if (!empty($enabledFields['mobile_ar'])): ?>
                    <div style="display:none" aria-hidden="true">
                        <input type="text" name="mobile_ar" id="mobile_ar" tabindex="-1"
                               value="<?php echo htmlspecialchars($formData['mobile_ar'] ?? ''); ?>"
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
                        'name_en','name_ar','position_en','position_ar','position_en_2','position_ar_2',
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

                    <!-- QR code toggle -->
                    <div class="pt-4 border-t border-gray-200" id="qrToggleBlock">
                        <label class="flex items-center gap-2 text-sm text-gray-700 cursor-pointer">
                            <input type="checkbox" name="include_qr" id="include_qr" value="1" checked
                                   onchange="if(typeof scheduleLivePreview==='function'){ scheduleLivePreview(); }"
                                   class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                            <span><?= htmlspecialchars(t('portal.include_qr')) ?></span>
                        </label>
                    </div>

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
                    </div><!-- /#issueFields -->

                        <div class="issue-nav">
                            <button type="button" class="issue-back" id="issueBack" hidden>&larr; <span><?= htmlspecialchars(t('portal.issue_back')) ?></span></button>
                            <button type="button" class="issue-next" id="issueNext"><span id="issueNextLabel"><?= htmlspecialchars(t('portal.issue_continue')) ?></span> <span aria-hidden="true">&rarr;</span></button>
                            <span class="issue-enter" id="issueEnter"><?= htmlspecialchars(t('portal.issue_enter_hint')) ?> <b>Enter</b></span>
                        </div>
                </form>
                    </section><!-- /.issue-flow -->
                </div><!-- /.issue-grid -->
            </div><!-- /.issuance -->

            <!-- Cardify "issuing your card" seal on submit -->
            <div class="issue-seal" id="issueSeal" aria-hidden="true">
                <div class="issue-seal-card">
                    <div class="issue-stamp">
                        <svg viewBox="0 0 52 52" fill="none" aria-hidden="true">
                            <path class="chk" d="M14 27 L23 36 L39 18" stroke="#fff" stroke-width="4.5" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </div>
                    <h3 class="display"><?= htmlspecialchars(t('portal.issue_sealing')) ?></h3>
                    <p><?= htmlspecialchars(t('portal.issue_sealing_sub')) ?></p>
                </div>
            </div>
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
                        <a href="<?= htmlspecialchars(getTenantUrl($companySlug, '/'), ENT_QUOTES, 'UTF-8') ?>" class="hover:text-gray-700">Home</a>
                        <a href="<?= htmlspecialchars(getTenantUrl($companySlug, '/admin/login'), ENT_QUOTES, 'UTF-8') ?>" class="hover:text-gray-700">Admin Login</a>
                        <span class="text-gray-300">|</span>
                        <span>Powered by <a href="<?php echo getBasePath(); ?>" class="hover:underline" style="color:#00718c">Cardify</a></span>
                    </div>
                </div>
            </div>
        </footer>
    </div>
    
    <!-- Alpine.js for interactivity (self-hosted, pinned) -->
    <script defer src="/assets/js/alpine-3.15.12.min.js"></script>
    
    <!-- Fabric.js 7.x for card preview generation -->
    <script src="https://cdn.jsdelivr.net/npm/fabric@7.1.0/dist/index.min.js"></script>
    <!-- QR Code Generator -->
    <script src="https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.min.js"></script>
    <!-- CardEditor (uses Fabric.js) -->
    <script src="<?php echo getBasePath(); ?>assets/js/card-editor.js?v=<?= @filemtime(__DIR__ . '/assets/js/card-editor.js') ?: '1' ?>"></script>
    
    <script>
    // Template data from PHP
    const basePath = '<?php echo getBasePath(); ?>';
    const companyName = '<?php echo addslashes($companyName); ?>';
    const companySlug = '<?php echo addslashes($companySlug); ?>';
    const apexHost = '<?php echo addslashes(cardifyApexHost()); ?>';
    const frontTemplate = <?php echo json_encode($activeFrontTemplate); ?>;
    const backTemplate = <?php echo json_encode($activeBackTemplate); ?>;
    
    // CardEditor instances
    let frontEditor = null;
    let backEditor = null;
    let previewGenerated = false;

    // Bridge the Fabric preview + shared state to the TypeScript step machine
    // (assets/js/portal-issuance.js). Getters read the live editor refs, which
    // are reassigned after async init, so the machine always sees the current
    // instances. previewGenerated stays in sync with the inline submit guard.
    window.__cardifyPortalBridge = {
        get frontEditor() { return frontEditor; },
        get backEditor() { return backEditor; },
        frontTemplate: frontTemplate,
        backTemplate: backTemplate,
        renderCardWithEditor: function (editor, template, data, side) { return renderCardWithEditor(editor, template, data, side); },
        scaleCanvasToFit: function (id, intrinsic) { return scaleCanvasToFit(id, intrinsic); },
        get previewGenerated() { return previewGenerated; },
        set previewGenerated(v) { previewGenerated = v; },
        companyName: companyName
    };
    
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
        
        // clientWidth, not offsetWidth: the wrapper carries a 1px border, so
        // offsetWidth is 2px wider than the box the canvas actually has to fit
        // inside, and the wrapper clips (overflow:hidden).
        const wrapperWidth = wrapper.clientWidth || wrapper.offsetWidth;

        // Skip if hidden
        if (wrapperWidth <= 0) {
            console.log('Wrapper width is 0, skipping scale for', canvasId);
            return;
        }

        // The Fabric canvas is the source of truth for the intrinsic width. The
        // caller passes getTemplateDimensions(), which falls back to 1050 for any
        // template without customWidth, while CardEditor sizes the canvas from the
        // template itself. On MHD Automotive that was 1063 against an assumed
        // 1050, so the card rendered 1.2% oversized and the wrapper sliced the
        // bottom line off every preview.
        //
        // style.width, NOT canvas.width: with retina scaling on, Fabric sets the
        // backing store to CSS width x devicePixelRatio, so canvas.width is double
        // on a 2x display and the card would render at half size there. style.width
        // stays in the CSS pixels the transform actually operates in.
        const cssWidth = parseFloat(canvas.style.width) || 0;
        const intrinsic = cssWidth || arguments[1] || canvas.width || 1050;
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
        // Only block on a missing name when the template actually exposes a
        // name field. When an imported card baked the name as a static/
        // decoration (no name_en input rendered), document.getElementById
        // returns null and requiring it created a hard dead-end: the portal
        // showed no name field yet refused to preview. Guard on existence.
        const nameInput = document.getElementById('name_en');
        if (nameInput && !nameEn) {
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

                // Decorations baked into the background PNG at import time
                // (render_in_bg) are already pixels in the bg. Re-drawing them
                // with Fabric double-strikes (offset overlap / wrong-font
                // overstrike). Skip them, exactly like the other 4 render paths
                // (rule 24). Non-baked statics still draw below.
                if (field.render_in_bg) continue;

                // Static / decorative spans imported from a PDF (e.g.,
                // "QR Code, to save the contact", "Follow us") are
                // rendered verbatim from detected_text, not looked up
                // against employee data. They keep their original
                // position, font, weight, size, and colour.
                if (field.is_static) {
                    const txt = (field.detected_text || '').trim();
                    if (!txt) continue;
                    // Same path as the dynamic fields below, not a bare
                    // fabric.Text. That anchored on field.x and then applied
                    // originX to it, so a right-aligned static hung its RIGHT
                    // edge on the box's LEFT edge, and Arabic missed the RTL
                    // shaping bitmap entirely. addTextField does the
                    // x + width anchoring and routes RTL correctly.
                    editor.addTextField(key, {
                        text: txt,
                        x: field.x,
                        y: field.y,
                        width: field.width,
                        fontSize: field.fontSize || 14,
                        fontFamily: field.fontFamily || 'Inter',
                        fontWeight: field.fontWeight || 'normal',
                        fontStyle: field.italic ? 'italic' : 'normal',
                        fill: field.fill || field.color || '#222',
                        textAlign: field.textAlign || 'left',
                        originX: field.originX || (field.textAlign === 'right' ? 'right'
                                 : (field.textAlign === 'center' ? 'center' : 'left')),
                        originY: field.originY || 'top',
                        selectable: false,
                        matchPrintBaseline: true,
                        // Static decorations sit next to baked labels (the tel/fax
                        // digits ride the baked "+968"). Fabric's box-top leading is
                        // a bit more than the generic 0.16, so a slightly stronger
                        // nudge lands the digit glyph-top on the baked prefix (source
                        // design: both are FrutigerLTStd-Roman 6.79pt, same line).
                        baselineFactor: 0.27,
                        // Static decorations (tel/fax digits, entity, division line)
                        // are fixed, design-fitting values; the print renderer draws
                        // them at their design size (no Latin shrink). Frutiger is a
                        // hair wider than the source font, so the Fabric auto-shrink
                        // was squeezing the tel/fax digits to ~92% and they read
                        // smaller than the baked "+968". Keep static text full-size to
                        // match the print (the width guard stops any clip).
                        autoShrink: false
                    });
                    if (editor.fields[key]) {
                        editor.fields[key].set({ selectable: false, evented: false,
                                                 hasControls: false, hasBorders: false });
                    }
                    continue;
                }

                // Handle QR code separately, use the employee's own vCard URL
                // so the QR is dynamic per person. Lock it against user movement
                // on the portal preview (designer is the only place to reposition).
                if (key === 'qr_code') {
                    // Honor the "Include QR code" tickbox: skip the QR entirely when off.
                    var __qrEl = document.getElementById('include_qr');
                    if (__qrEl && !__qrEl.checked) { continue; }
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
                    // Pass the bbox width so right/center-aligned fields anchor
                    // at x + width (x = bbox LEFT edge, rule 47). Without it,
                    // addTextField degrades to left-anchored at x and the value
                    // overflows the card's right edge.
                    width: field.width,
                    fontSize: field.fontSize || 14,
                    fontFamily: field.fontFamily || (isArabic ? 'Cairo' : 'Inter'),
                    fontWeight: field.fontWeight || 'normal',
                    fill: field.fill || field.color || '#333333',
                    textAlign: textAlign,
                    originX: originX,
                    originY: field.originY || 'top',
                    matchPrintBaseline: true
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
        return 'https://' + companySlug + '.' + apexHost + '/card/' + encodeURIComponent(email);
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
                // Capture the rendered card design so the admin sees it in
                // the notification email and on the approval page. Non-fatal:
                // a capture failure just submits without the preview image.
                try {
                    if (frontEditor && typeof frontEditor.exportPNG === 'function') {
                        document.getElementById('preview_front_input').value = frontEditor.exportPNG(1);
                    }
                    if (backEditor && typeof backEditor.exportPNG === 'function') {
                        document.getElementById('preview_back_input').value = backEditor.exportPNG(1);
                    }
                } catch (err) { /* submit without preview image */ }
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
    
    // Tenants whose artwork bakes the country code next to the "Mob:" label
    // (MHD: +968, and +973 on the Bahrain Consumer card) must not receive it
    // again from the form, or the card prints it twice. Same gate as the
    // division picker: divisions carrying their own template pair.
    const BAKED_CC = <?= $divisionRequired ? 'true' : 'false' ?>;

    // Normalise to bare digits. The artwork prints an 8-digit number with no
    // separator, and syncArabicPhone reorders space-delimited groups for RTL,
    // so a spaced entry would convert to a transposed Arabic number.
    function normaliseBakedMobile(el) {
        if (!el) return;
        const before = el.value;
        const after = before.replace(/^\s*(?:\+|00)?9(?:68|73)\s*/, '').replace(/\D/g, '');
        if (after !== before) {
            const atEnd = el.selectionStart === before.length;
            el.value = after;
            if (atEnd) { try { el.setSelectionRange(after.length, after.length); } catch (e) {} }
        }
    }

    function wireMobileAutoFormat() {
        const mob = document.getElementById('mobile');
        if (!mob) return;
        const apply = function () {
            if (BAKED_CC) normaliseBakedMobile(mob);
            syncArabicPhone('mobile', 'mobile_ar');
        };
        mob.addEventListener('input', apply);
        mob.addEventListener('blur', apply);
        apply();
    }

    // Initialize on page load (for pre-filled values)
    document.addEventListener('DOMContentLoaded', function() {
        syncArabicPhone('phone', 'phone_ar');
        wireMobileAutoFormat();
        
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
        
        // Find the translate button and show loading.
        // NOT parentElement.querySelector('.translate-btn'): these buttons live
        // in the field's LABEL and never carried that class, so btn was always
        // null and this whole loading block was dead code. Match on the onclick
        // instead; the quotes stop 'position_ar' matching 'position_ar_2'.
        const btn = document.querySelector('button[onclick*="\'' + targetId + '\'"]');
        if (btn) {
            if (!btn.dataset.idleLabel) btn.dataset.idleLabel = btn.innerHTML;
            btn.innerHTML = '<i class="fa-solid fa-spinner spinner"></i> '
                          + <?= json_encode(t('portal.translating')) ?>;
            btn.disabled = true;
            btn.classList.add('is-translating');
        }
        targetEl.classList.add('is-translating-target');
        targetEl.setAttribute('placeholder', <?= json_encode(t('portal.translating')) ?>);
        
        // The endpoint round-trips an LLM and measured 16.3s live, so the wait
        // is long enough that a hung request would otherwise leave the button
        // disabled for good. Abort at 45s and let finally restore it.
        const ctrl = (typeof AbortController !== 'undefined') ? new AbortController() : null;
        const killer = ctrl ? setTimeout(function () { ctrl.abort(); }, 45000) : null;
        try {
            const response = await fetch(translateApiUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                signal: ctrl ? ctrl.signal : undefined,
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
            showToast(error && error.name === 'AbortError'
                ? 'Translation timed out. Please try again.'
                : 'Translation failed. Please try again.', 'error');
        } finally {
            if (killer) clearTimeout(killer);
            isTranslating[targetId] = false;
            if (btn) {
                // Restore the label we captured, not a hardcoded icon: these
                // buttons read "AI Translate" with a wand, and the old code
                // would have silently swapped that for a bare globe.
                btn.innerHTML = btn.dataset.idleLabel;
                btn.disabled = false;
                btn.classList.remove('is-translating');
            }
            targetEl.classList.remove('is-translating-target');
            targetEl.removeAttribute('placeholder');
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

    <!-- Cardify portal step machine: server config + i18n injected here; the engine
         is authored in TypeScript (assets/ts/portal-issuance.ts) and compiled to
         assets/js/portal-issuance.js with esbuild, committed so the server needs no build. -->
    <script>
    window.CardifyPortal = {
        i18n: <?= json_encode([
            'step_of' => t('portal.issue_step_of'),
            'sec'     => [
                'identity' => t('portal.issue_sec_identity'),
                'name'     => t('portal.issue_sec_name'),
                'role'     => t('portal.issue_sec_role'),
                'contact'  => t('portal.issue_sec_contact'),
                'photo'    => t('portal.issue_sec_photo'),
                'confirm'  => t('portal.issue_sec_confirm'),
            ],
            'title'   => [
                'identity' => t('portal.issue_q_identity'),
                'name'     => t('portal.issue_q_name'),
                'role'     => t('portal.issue_q_role'),
                'contact'  => t('portal.issue_q_contact'),
                'photo'    => t('portal.issue_q_photo'),
                'confirm'  => t('portal.issue_q_confirm'),
            ],
            'help'    => [
                'identity' => t('portal.issue_h_identity'),
                'name'     => t('portal.issue_h_name'),
                'role'     => t('portal.issue_h_role'),
                'contact'  => t('portal.issue_h_contact'),
                'photo'    => t('portal.issue_h_photo'),
                'confirm'  => t('portal.issue_h_confirm'),
            ],
            'sum'     => [
                'email'      => t('portal.issue_sum_email'),
                'name'       => t('portal.issue_sum_name'),
                'title'      => t('portal.issue_sum_title'),
                'department' => t('portal.issue_sum_department'),
                'mobile'     => t('portal.issue_sum_mobile'),
                'photo'      => t('portal.issue_sum_photo'),
            ],
            'photo_added' => t('portal.issue_photo_added'),
            'lot_unit'    => t('portal.issue_lot_unit'),
            'lot_note'    => t('portal.issue_lot_note'),
            'err_email'   => t('portal.enter_email_first'),
            'err_name'    => t('portal.enter_name_first'),
            'err_domain'  => t('cardportal.email_domain_hint', ['domain' => ':domain']),
            'continue'    => t('portal.issue_continue'),
        ]) ?>,
        reqDomain: <?= json_encode($companyDomain ?: '') ?>,
        defaultQty: <?= (int)($company['default_order_qty'] ?? 200) ?>
    };
    </script>
    <!-- confetti for the cyan "issued" seal (self-hosted, gated on reduced-motion) -->
    <script src="<?php echo getBasePath(); ?>assets/js/canvas-confetti.min.js"></script>
    <script src="<?php echo getBasePath(); ?>assets/js/portal-issuance.js?v=<?= @filemtime(__DIR__ . '/assets/js/portal-issuance.js') ?: '1' ?>"></script>

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
