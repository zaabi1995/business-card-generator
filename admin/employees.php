<?php
/**
 * Employee Management - Cardify
 * Combined employee list with generated card previews
 */
require_once __DIR__ . '/../config.php';
requireAdmin();
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/admin-layout.php';
require_once INCLUDES_DIR . '/Billing.php';

$db = Database::getInstance();
$companyId = getCurrentCompanyId();

// HD card generation is free for every team since the Apr 2026 pricing reset.
$planInfo = Billing::getCompanyPlanInfo($companyId);
$isFreePlan = false; // hides the legacy upgrade-for-HD nag
$hasHighQuality = true;

// Get departments for dropdown
$departments = [];
if (DatabaseAdapter::useDatabase() && $companyId) {
    $departments = $db->fetchAll(
        "SELECT * FROM departments WHERE company_id = :id ORDER BY name",
        ['id' => $companyId]
    );
}

$employees = loadEmployees($companyId);
$message = null;
$messageType = 'success';

// Get current admin's info for "Add Myself" prefill
$currentAdminUser = Auth::getCurrentUser();
$adminName = $currentAdminUser['name'] ?? '';
$adminEmail = $currentAdminUser['email'] ?? '';

// Load generated cards and create lookup by employee ID
$generatedLog = loadGeneratedLog($companyId) ?: [];
$cardsByEmployee = [];
foreach ($generatedLog as $entry) {
    $empId = $entry['employeeId'] ?? null;
    if ($empId) {
        if (!isset($cardsByEmployee[$empId])) {
            $cardsByEmployee[$empId] = [];
        }
        $cardsByEmployee[$empId][] = $entry;
    }
}

// Load print orders by employee
$printOrdersByEmployee = [];
try {
    $printOrders = $db->fetchAll(
        "SELECT * FROM print_orders WHERE company_id = :id ORDER BY created_at DESC",
        ['id' => $companyId]
    );
    foreach ($printOrders as $order) {
        $empId = $order['employee_id'] ?? null;
        if ($empId) {
            if (!isset($printOrdersByEmployee[$empId])) {
                $printOrdersByEmployee[$empId] = [];
            }
            $printOrdersByEmployee[$empId][] = $order;
        }
    }
} catch (Exception $e) {
    // Table might not exist
}

// Load card requests by employee email
$cardRequestsByEmail = [];
try {
    $cardRequests = $db->fetchAll(
        "SELECT * FROM card_requests WHERE company_id = :id ORDER BY submitted_at DESC",
        ['id' => $companyId]
    );
    foreach ($cardRequests as $req) {
        $email = $req['email'] ?? null;
        if ($email) {
            if (!isset($cardRequestsByEmail[$email])) {
                $cardRequestsByEmail[$email] = [];
            }
            $cardRequestsByEmail[$email][] = $req;
        }
    }
} catch (Exception $e) {
    // Table might not exist
}

// Load templates for lookup
$templatesConfig = loadTemplates($companyId);
$templateLookup = [];
if (!empty($templatesConfig['templates'])) {
    foreach ($templatesConfig['templates'] as $tpl) {
        $templateLookup[$tpl['id']] = $tpl;
    }
}

// Get company for sample card info
$company = findCompanyById($companyId);
$currentSampleFront = $company['sample_card_front'] ?? null;
$currentSampleBack = $company['sample_card_back'] ?? null;

// Derive which dynamic fields the active card templates actually use.
// We hide every modal input that no template references so HR users
// don't have to scroll through 14 boxes for a name + position card.
$activeFieldKeys = [];
try {
    $activeRows = $db->fetchAll(
        "SELECT fields_json FROM templates WHERE company_id = :c AND is_active = 1",
        ['c' => $companyId]
    );
    foreach ($activeRows as $r) {
        $f = json_decode($r['fields_json'] ?? '{}', true);
        if (!is_array($f)) continue;
        foreach ($f as $k => $meta) {
            if (!is_array($meta)) continue;
            if (!empty($meta['is_static'])) continue;
            if ($k === 'qr_code') continue;
            $activeFieldKeys[$k] = true;
        }
    }
} catch (Exception $e) {}
// Sensible fallback: show name+position+mobile+email if no templates yet.
if (empty($activeFieldKeys)) {
    foreach (['name_en', 'name_ar', 'position_en', 'position_ar', 'mobile', 'email'] as $k) {
        $activeFieldKeys[$k] = true;
    }
}
// Helper closure used in the modal markup.
$needsField = function (string $key) use ($activeFieldKeys): bool {
    return isset($activeFieldKeys[$key]);
};

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { die('Invalid request'); }
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'add':
            $billing = new Billing();
            if (!$billing->checkLimit($companyId, 'employees')) {
                $limits = $billing->getPlanLimits($companyId);
                $message = 'Employee limit reached (' . ($limits['employees'] ?? 5) . ' max on your plan). Upgrade to add more employees.';
                $messageType = 'error';
                break;
            }
            $result = addEmployee($_POST);
            if ($result['success']) {
                // Auto-generate card for new employee
                header('Location: auto_generate.php?employee_id=' . urlencode($result['id']) . '&return=employees&new=1');
                exit;
            } else {
                $message = $result['error'];
                $messageType = 'error';
            }
            break;
            
        case 'update':
            $result = updateEmployee($_POST['id'], $_POST);
            if ($result['success']) {
                // Just save changes - keep existing card (user can regenerate manually if needed)
                $message = 'Employee updated successfully';
                $employees = loadEmployees($companyId);
                // Reload card data
                $generatedLog = loadGeneratedLog($companyId) ?: [];
                $cardsByEmployee = [];
                foreach ($generatedLog as $entry) {
                    $empId = $entry['employeeId'] ?? null;
                    if ($empId) {
                        if (!isset($cardsByEmployee[$empId])) {
                            $cardsByEmployee[$empId] = [];
                        }
                        $cardsByEmployee[$empId][] = $entry;
                    }
                }
            } else {
                $message = $result['error'];
                $messageType = 'error';
            }
            break;
            
        case 'resend_edit_invite':
            require_once INCLUDES_DIR . '/EmployeeEditToken.php';
            $rid = trim($_POST['id'] ?? '');
            $emp = $rid ? $db->fetchOne(
                "SELECT e.*, c.id AS _cid, c.name AS _cname, c.slug AS _cslug, c.brand_color, c.logo_path AS logo_url
                   FROM employees e
                   JOIN companies c ON c.id = e.company_id
                  WHERE e.id = :id AND e.company_id = :cid LIMIT 1",
                ['id' => $rid, 'cid' => $companyId]
            ) : null;
            if (!$emp) {
                $message = 'Employee not found';
                $messageType = 'error';
                break;
            }
            $company = ['id' => $companyId, 'name' => $emp['_cname'] ?? 'Cardify',
                        'slug' => $emp['_cslug'] ?? null,
                        'brand_color' => $emp['brand_color'] ?? null,
                        'logo_url' => $emp['logo_url'] ?? null];
            $channel = (!empty($emp['phone']) || !empty($emp['mobile'])) ? 'both' : 'email';
            $res = EmployeeEditToken::sendInvite($emp, $company, $channel);
            $okWa = !empty($res['wa']);
            $okEmail = !empty($res['email']);
            if ($okWa || $okEmail) {
                $parts = [];
                if ($okWa) $parts[] = 'WhatsApp';
                if ($okEmail) $parts[] = 'email';
                $message = t('employees.edit_invite_sent', ['channels' => implode(' + ', $parts)]);
            } else {
                $message = t('employees.edit_invite_failed');
                $messageType = 'error';
            }
            break;

        case 'delete':
            $result = deleteEmployee($_POST['id']);
            if ($result['success']) {
                $message = 'Employee deleted successfully';
                $employees = loadEmployees($companyId);
                $generatedLog = loadGeneratedLog($companyId) ?: [];
                // Rebuild card lookup
                $cardsByEmployee = [];
                foreach ($generatedLog as $entry) {
                    $empId = $entry['employeeId'] ?? null;
                    if ($empId) {
                        if (!isset($cardsByEmployee[$empId])) {
                            $cardsByEmployee[$empId] = [];
                        }
                        $cardsByEmployee[$empId][] = $entry;
                    }
                }
            } else {
                $message = $result['error'];
                $messageType = 'error';
            }
            break;
            
        case 'import':
            $skipDuplicates = isset($_POST['skip_duplicates']);
            $autoConvertArabic = isset($_POST['auto_convert_arabic']);
            $result = importFromExcel($_FILES['excel_file'] ?? null, $skipDuplicates, $autoConvertArabic);
            if ($result['success']) {
                // Send the user straight to the batch generator so the cards
                // for the freshly-imported rows actually get rendered. The
                // separate "Generate All" step was a footgun: HR imported
                // 50 employees, never noticed the cards page was empty.
                $msg = "Imported {$result['count']} employees";
                if (!empty($result['skipped'])) $msg .= " ({$result['skipped']} duplicates skipped)";
                if (($result['count'] ?? 0) > 0) {
                    header('Location: batch-auto-generate.php?from=import&imported=' . (int)$result['count']);
                    exit;
                }
                $message = $msg;
                $employees = loadEmployees($companyId);
            } else {
                $message = $result['error'];
                $messageType = 'error';
            }
            break;
            
        case 'set_sample':
            $frontPath = $_POST['sample_front'] ?? '';
            $backPath = $_POST['sample_back'] ?? '';
            if ($frontPath && $backPath) {
                try {
                    $db->query(
                        "UPDATE companies SET sample_card_front = :front, sample_card_back = :back WHERE id = :id",
                        ['front' => $frontPath, 'back' => $backPath, 'id' => $companyId]
                    );
                    $message = 'Card set as company sample';
                    $currentSampleFront = $frontPath;
                    $currentSampleBack = $backPath;
                } catch (Exception $e) {
                    $message = 'Failed to set sample';
                    $messageType = 'error';
                }
            }
            break;
            
        case 'delete_card':
            $entryId = $_POST['entry_id'] ?? '';
            $frontPath = $_POST['front_path'] ?? '';
            $backPath = $_POST['back_path'] ?? '';
            
            if ($entryId) {
                $cardsDir = getCompanyCardsDir($companyId);
                
                // Delete front files (PNG and PDF)
                if ($frontPath) {
                    $frontPng = $cardsDir . '/' . $frontPath;
                    $frontPdf = $cardsDir . '/' . str_replace('.png', '.pdf', $frontPath);
                    if (file_exists($frontPng)) @unlink($frontPng);
                    if (file_exists($frontPdf)) @unlink($frontPdf);
                }
                
                // Delete back files (PNG and PDF)
                if ($backPath) {
                    $backPng = $cardsDir . '/' . $backPath;
                    $backPdf = $cardsDir . '/' . str_replace('.png', '.pdf', $backPath);
                    if (file_exists($backPng)) @unlink($backPng);
                    if (file_exists($backPdf)) @unlink($backPdf);
                }
                
                // Delete from database
                deleteGeneratedCard($entryId);
                $message = 'Card deleted successfully';
                
                // Reload data
                $generatedLog = loadGeneratedLog($companyId) ?: [];
                $cardsByEmployee = [];
                foreach ($generatedLog as $entry) {
                    $empId = $entry['employeeId'] ?? null;
                    if ($empId) {
                        if (!isset($cardsByEmployee[$empId])) {
                            $cardsByEmployee[$empId] = [];
                        }
                        $cardsByEmployee[$empId][] = $entry;
                    }
                }
            }
            break;
    }
}

// Handle success messages from auto_generate redirect
if (isset($_GET['generated'])) {
    $message = 'Business card generated successfully!';
}
if (isset($_GET['regenerated'])) {
    $message = 'Business card regenerated successfully!';
}

// Handle export request
if (isset($_GET['action']) && $_GET['action'] === 'export') {
    exportEmployeesToCSV($employees);
    exit;
}

/**
 * Export employees to CSV file
 */
function exportEmployeesToCSV($employees) {
    $filename = 'employees_export_' . date('Y-m-d_His') . '.csv';
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    $output = fopen('php://output', 'w');
    
    // Add BOM for Excel UTF-8 compatibility
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Headers
    $headers = [
        'email', 'name_en', 'name_ar', 'position_en', 'position_ar',
        'phone', 'phone_ar', 'mobile', 'mobile_ar',
        'company_en', 'company_ar', 'website', 'website_ar',
        'address_en', 'address_ar', 'department'
    ];
    fputcsv($output, $headers);
    
    // Get departments for lookup
    $db = Database::getInstance();
    $companyId = getCurrentCompanyId();
    $deptLookup = [];
    if ($companyId) {
        $depts = $db->fetchAll("SELECT id, name FROM departments WHERE company_id = :id", ['id' => $companyId]);
        foreach ($depts as $d) {
            $deptLookup[$d['id']] = $d['name'];
        }
    }
    
    // Data rows
    foreach ($employees as $emp) {
        $deptName = '';
        if (!empty($emp['department_id']) && isset($deptLookup[$emp['department_id']])) {
            $deptName = $deptLookup[$emp['department_id']];
        }
        
        fputcsv($output, [
            $emp['email'] ?? '',
            $emp['name_en'] ?? '',
            $emp['name_ar'] ?? '',
            $emp['position_en'] ?? '',
            $emp['position_ar'] ?? '',
            $emp['phone'] ?? '',
            $emp['phone_ar'] ?? '',
            $emp['mobile'] ?? '',
            $emp['mobile_ar'] ?? '',
            $emp['company_en'] ?? '',
            $emp['company_ar'] ?? '',
            $emp['website'] ?? '',
            $emp['website_ar'] ?? '',
            $emp['address_en'] ?? $emp['address'] ?? '',
            $emp['address_ar'] ?? '',
            $deptName
        ]);
    }
    
    fclose($output);
}

/**
 * Import employees from Excel/CSV file
 */
function importFromExcel($file, $skipDuplicates = true, $autoConvertArabic = true) {
    if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'No file uploaded or upload error'];
    }
    
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    if ($ext === 'csv') {
        return importFromCSV($file['tmp_name'], $skipDuplicates, $autoConvertArabic);
    } elseif (in_array($ext, ['xlsx', 'xls'])) {
        $autoloadPath = BASE_DIR . '/vendor/autoload.php';
        if (file_exists($autoloadPath)) {
            require_once $autoloadPath;
            return importFromXLSX($file['tmp_name'], $skipDuplicates, $autoConvertArabic);
        } else {
            $savedPath = EXCEL_DIR . '/' . uniqid('import_') . '.' . $ext;
            move_uploaded_file($file['tmp_name'], $savedPath);
            return ['success' => false, 'error' => 'Excel support requires PhpSpreadsheet. Please use CSV format.'];
        }
    }
    
    return ['success' => false, 'error' => 'Unsupported file format. Use CSV or XLSX.'];
}

/**
 * Process imported employee data - auto-convert Arabic numerals, find/create department
 */
function processImportedEmployee($data, $autoConvertArabic = true) {
    // Auto-convert phone/mobile to Arabic numerals if _ar fields are empty
    if ($autoConvertArabic) {
        if (!empty($data['phone']) && empty($data['phone_ar'])) {
            $data['phone_ar'] = toArabicNumerals($data['phone']);
        }
        if (!empty($data['mobile']) && empty($data['mobile_ar'])) {
            $data['mobile_ar'] = toArabicNumerals($data['mobile']);
        }
    }
    
    // Handle department by name
    if (!empty($data['department']) && empty($data['department_id'])) {
        $deptName = trim($data['department']);
        $companyId = getCurrentCompanyId();
        if ($companyId && $deptName) {
            $db = Database::getInstance();
            // Try to find existing department
            $dept = $db->fetchOne(
                "SELECT id FROM departments WHERE company_id = :cid AND name = :name",
                ['cid' => $companyId, 'name' => $deptName]
            );
            if ($dept) {
                $data['department_id'] = $dept['id'];
            } else {
                // Create new department. Capture the UUID locally; Database::insert
                // returns lastInsertId() which is '0' for UUID-PK tables.
                $newDeptId = generateUUID();
                try {
                    $db->insert('departments', [
                        'id' => $newDeptId,
                        'company_id' => $companyId,
                        'name' => $deptName,
                        'created_at' => date('Y-m-d H:i:s')
                    ]);
                    $data['department_id'] = $newDeptId;
                } catch (Exception $e) {
                    error_log('[employees.import] department create failed for ' . $deptName . ': ' . $e->getMessage());
                }
            }
        }
        unset($data['department']); // Remove the name field, keep only ID
    }
    
    return $data;
}

function importFromCSV($filepath, $skipDuplicates = true, $autoConvertArabic = true) {
    $handle = fopen($filepath, 'r');
    if (!$handle) return ['success' => false, 'error' => 'Could not read file'];
    
    $header = fgetcsv($handle);
    if (!$header) { fclose($handle); return ['success' => false, 'error' => 'Empty file']; }
    
    // Remove BOM (Byte Order Mark) from first column if present
    if (!empty($header[0])) {
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]); // UTF-8 BOM
        $header[0] = preg_replace('/^\xFE\xFF/', '', $header[0]); // UTF-16 BE BOM
        $header[0] = preg_replace('/^\xFF\xFE/', '', $header[0]); // UTF-16 LE BOM
    }
    
    // Normalize headers: lowercase, trim, replace spaces/dashes with underscores
    $header = array_map(function($h) {
        $h = trim($h);
        $h = strtolower($h);
        $h = str_replace([' ', '-'], '_', $h);
        $h = preg_replace('/[^\w]/', '', $h); // Remove any non-word characters
        return $h;
    }, $header);
    
    $columnMap = [
        'email' => array_search('email', $header),
        'name_en' => findColumn($header, ['name_en', 'name_english', 'english_name', 'name']),
        'name_ar' => findColumn($header, ['name_ar', 'name_arabic', 'arabic_name']),
        'position_en' => findColumn($header, ['position_en', 'position_english', 'title_en', 'position', 'title']),
        'position_ar' => findColumn($header, ['position_ar', 'position_arabic', 'title_ar']),
        'phone' => findColumn($header, ['phone', 'telephone', 'tel']),
        'phone_ar' => findColumn($header, ['phone_ar', 'phone_arabic']),
        'mobile' => findColumn($header, ['mobile', 'cell', 'cellphone']),
        'mobile_ar' => findColumn($header, ['mobile_ar', 'mobile_arabic']),
        'company_en' => findColumn($header, ['company_en', 'company_english', 'company']),
        'company_ar' => findColumn($header, ['company_ar', 'company_arabic']),
        'website' => findColumn($header, ['website', 'web', 'url']),
        'website_ar' => findColumn($header, ['website_ar', 'website_arabic']),
        'address_en' => findColumn($header, ['address_en', 'address_english', 'address', 'location']),
        'address_ar' => findColumn($header, ['address_ar', 'address_arabic']),
        'department' => findColumn($header, ['department', 'dept', 'department_name'])
    ];
    
    if ($columnMap['email'] === false) { fclose($handle); return ['success' => false, 'error' => 'Email column not found']; }
    
    $imported = 0;
    $skipped = 0;
    
    $companyId = getCurrentCompanyId();
    
    while (($row = fgetcsv($handle)) !== false) {
        $data = [];
        foreach ($columnMap as $field => $index) {
            $data[$field] = ($index !== false && isset($row[$index])) ? trim($row[$index]) : '';
        }
        if (empty($data['email'])) continue;
        
        // Search only within current company
        $existing = findEmployeeByEmail($data['email'], $companyId);
        if ($existing) {
            if ($skipDuplicates) {
                $skipped++;
                continue;
            } else {
                $data = processImportedEmployee($data, $autoConvertArabic);
                updateEmployee($existing['id'], $data, $companyId);
            }
        } else {
            $data = processImportedEmployee($data, $autoConvertArabic);
            addEmployee($data, $companyId);
        }
        $imported++;
    }
    fclose($handle);
    return ['success' => true, 'count' => $imported, 'skipped' => $skipped];
}

function importFromXLSX($filepath, $skipDuplicates = true, $autoConvertArabic = true) {
    try {
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($filepath);
        $rows = $spreadsheet->getActiveSheet()->toArray();
        if (empty($rows)) return ['success' => false, 'error' => 'Empty file'];
        
        // Normalize headers: lowercase, trim, replace spaces/dashes with underscores
        $header = array_map(function($h) {
            $h = trim($h ?? '');
            $h = strtolower($h);
            $h = str_replace([' ', '-'], '_', $h);
            $h = preg_replace('/[^\w]/', '', $h); // Remove any non-word characters
            return $h;
        }, $rows[0]);
        $columnMap = [
            'email' => array_search('email', $header),
            'name_en' => findColumn($header, ['name_en', 'name_english', 'english_name', 'name']),
            'name_ar' => findColumn($header, ['name_ar', 'name_arabic', 'arabic_name']),
            'position_en' => findColumn($header, ['position_en', 'position_english', 'title_en', 'position', 'title']),
            'position_ar' => findColumn($header, ['position_ar', 'position_arabic', 'title_ar']),
            'phone' => findColumn($header, ['phone', 'telephone', 'tel']),
            'phone_ar' => findColumn($header, ['phone_ar', 'phone_arabic']),
            'mobile' => findColumn($header, ['mobile', 'cell', 'cellphone']),
            'mobile_ar' => findColumn($header, ['mobile_ar', 'mobile_arabic']),
            'company_en' => findColumn($header, ['company_en', 'company_english', 'company']),
            'company_ar' => findColumn($header, ['company_ar', 'company_arabic']),
            'website' => findColumn($header, ['website', 'web', 'url']),
            'website_ar' => findColumn($header, ['website_ar', 'website_arabic']),
            'address_en' => findColumn($header, ['address_en', 'address_english', 'address', 'location']),
            'address_ar' => findColumn($header, ['address_ar', 'address_arabic']),
            'department' => findColumn($header, ['department', 'dept', 'department_name'])
        ];
        
        if ($columnMap['email'] === false) return ['success' => false, 'error' => 'Email column not found'];
        
        $companyId = getCurrentCompanyId();
        $imported = 0;
        $skipped = 0;
        
        for ($i = 1; $i < count($rows); $i++) {
            $row = $rows[$i];
            $data = [];
            foreach ($columnMap as $field => $index) {
                $data[$field] = ($index !== false && isset($row[$index])) ? trim($row[$index] ?? '') : '';
            }
            if (empty($data['email'])) continue;
            
            // Search only within current company
            $existing = findEmployeeByEmail($data['email'], $companyId);
            if ($existing) {
                if ($skipDuplicates) {
                    $skipped++;
                    continue;
                } else {
                    $data = processImportedEmployee($data, $autoConvertArabic);
                    updateEmployee($existing['id'], $data, $companyId);
                }
            } else {
                $data = processImportedEmployee($data, $autoConvertArabic);
                addEmployee($data, $companyId);
            }
            $imported++;
        }
        return ['success' => true, 'count' => $imported, 'skipped' => $skipped];
    } catch (Exception $e) {
        return ['success' => false, 'error' => 'Error reading Excel file: ' . $e->getMessage()];
    }
}

function findColumn($header, $possibleNames) {
    foreach ($possibleNames as $name) {
        $index = array_search($name, $header);
        if ($index !== false) return $index;
    }
    return false;
}

// Start admin layout
adminHeader(t('employees.page_title'), 'employees');
?>

<?php if ($isFreePlan && !empty($employees)): ?>
<!-- Free Plan Quality Notice -->
<div class="mb-4 p-3 bg-amber-50 border border-amber-200 rounded-lg flex items-center justify-between">
    <div class="flex items-center gap-2 text-sm text-amber-700">
        <i class="fa-solid fa-info-circle"></i>
        <span><?= htmlspecialchars(t('employees.free_plan_notice')) ?></span>
    </div>
    <a href="billing.php" class="text-sm text-amber-700 hover:text-amber-800 font-medium flex items-center gap-1">
        <i class="fa-solid fa-crown"></i> <?= htmlspecialchars(t('employees.upgrade_for_hd')) ?>
    </a>
</div>
<?php endif; ?>

<div x-data="employeeManager()">
    <!-- Page Header Actions -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
        <div>
            <p class="text-gray-600"><?= htmlspecialchars(t('employees.team_members_count', ['n' => count($employees)])) ?></p>
        </div>
        <div class="flex items-center gap-3">
            <?php if (!empty($employees)): ?>
            <?php
            // Count employees without generated cards
            $employeesWithoutCards = 0;
            foreach ($employees as $emp) {
                if (empty($cardsByEmployee[$emp['id']])) {
                    $employeesWithoutCards++;
                }
            }
            ?>
            <?php if ($employeesWithoutCards > 0): ?>
            <a href="batch-auto-generate.php" class="px-4 py-2 bg-blue-50 text-blue-700 border border-blue-200 rounded-lg hover:bg-blue-100 transition-colors text-sm font-medium flex items-center gap-2" title="<?= htmlspecialchars(t('employees.generate_all_tooltip')) ?>">
                <i class="fa-solid fa-wand-magic-sparkles"></i>
                <span class="hidden sm:inline"><?= htmlspecialchars(t('employees.generate_all', ['n' => $employeesWithoutCards])) ?></span>
            </a>
            <?php endif; ?>
            <a href="batch_generate.php" class="px-4 py-2 bg-purple-50 text-purple-700 border border-purple-200 rounded-lg hover:bg-purple-100 transition-colors text-sm font-medium flex items-center gap-2" title="<?= htmlspecialchars(t('employees.bulk_regenerate_tooltip')) ?>">
                <i class="fa-solid fa-rotate"></i>
                <span class="hidden sm:inline"><?= htmlspecialchars(t('employees.bulk_regenerate')) ?></span>
            </a>
            <a href="?action=export" class="px-4 py-2 bg-gray-50 text-gray-700 border border-gray-200 rounded-lg hover:bg-gray-100 transition-colors text-sm font-medium flex items-center gap-2">
                <i class="fa-solid fa-file-export"></i>
                <span class="hidden sm:inline"><?= htmlspecialchars(t('employees.export_csv')) ?></span>
            </a>
            <?php endif; ?>
            <button @click="showImportModal = true" class="px-4 py-2 bg-green-50 text-green-700 border border-green-200 rounded-lg hover:bg-green-100 transition-colors text-sm font-medium flex items-center gap-2">
                <i class="fa-solid fa-file-import"></i>
                <span class="hidden sm:inline"><?= htmlspecialchars(t('employees.import_csv')) ?></span>
            </button>
            <button @click="openAddModal()" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors text-sm font-medium flex items-center gap-2">
                <i class="fa-solid fa-plus"></i>
                <span><?= htmlspecialchars(t('employees.add_employee')) ?></span>
            </button>
        </div>
    </div>

    <!-- Alert Message -->
    <?php if ($message): ?>
    <div class="mb-6 p-4 rounded-xl flex items-center gap-3 <?php echo $messageType === 'success' ? 'bg-green-50 border border-green-200 text-green-700' : 'bg-red-50 border border-red-200 text-red-700'; ?>">
        <i class="<?php echo $messageType === 'success' ? 'fa-solid fa-circle-check' : 'fa-solid fa-circle-exclamation'; ?>"></i>
        <?php echo sanitize($message); ?>
    </div>
    <?php endif; ?>

    <!-- Search & Filter Bar -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm mb-6">
        <div class="p-4 flex flex-col sm:flex-row gap-4">
            <div class="flex-1 relative">
                <i class="fa-solid fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
                <input 
                    type="text" 
                    x-model="searchQuery"
                    placeholder="<?= htmlspecialchars(t('employees.search_placeholder')) ?>"
                    class="w-full pl-10 pr-4 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 placeholder-gray-400 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 transition-all"
                >
            </div>
            <?php if (!empty($departments)): ?>
            <select x-model="filterDepartment" class="px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                <option value=""><?= htmlspecialchars(t('employees.all_departments')) ?></option>
                <?php foreach ($departments as $dept): ?>
                <option value="<?php echo sanitize($dept['id']); ?>"><?php echo sanitize($dept['name']); ?></option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>
        </div>
    </div>

    <!-- Employees Table -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th class="text-left px-6 py-4 text-gray-600 font-semibold text-sm"><?= htmlspecialchars(t('employees.col_employee')) ?></th>
                        <th class="text-left px-6 py-4 text-gray-600 font-semibold text-sm hidden lg:table-cell"><?= htmlspecialchars(t('employees.col_position')) ?></th>
                        <th class="text-left px-6 py-4 text-gray-600 font-semibold text-sm hidden md:table-cell"><?= htmlspecialchars(t('employees.col_department')) ?></th>
                        <th class="text-center px-6 py-4 text-gray-600 font-semibold text-sm"><?= htmlspecialchars(t('employees.col_card_preview')) ?></th>
                        <th class="text-center px-6 py-4 text-gray-600 font-semibold text-sm hidden sm:table-cell"><?= htmlspecialchars(t('employees.col_qr_scans')) ?></th>
                        <th class="text-right px-6 py-4 text-gray-600 font-semibold text-sm"><?= htmlspecialchars(t('employees.col_actions')) ?></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php foreach ($employees as $emp): ?>
                    <?php 
                    $deptName = '-';
                    if (!empty($emp['department_id']) && DatabaseAdapter::useDatabase()) {
                        $dept = $db->fetchOne("SELECT name FROM departments WHERE id = :id", ['id' => $emp['department_id']]);
                        $deptName = $dept ? sanitize($dept['name']) : '-';
                    }
                    
                    // Get employee's cards
                    $empCards = $cardsByEmployee[$emp['id']] ?? [];
                    $latestCard = !empty($empCards) ? $empCards[0] : null; // First is latest
                    $cardCount = count($empCards);
                    
                    // Card paths (from database: frontPath/backPath contain full relative path like "card_front_xxx.png")
                    $frontFile = $latestCard['frontPath'] ?? null;
                    $backFile = $latestCard['backPath'] ?? null;
                    
                    // Extract just filename from path if it contains directory
                    $frontFilename = $frontFile ? basename($frontFile) : null;
                    $backFilename = $backFile ? basename($backFile) : null;
                    
                    $cardBaseUrl = getBasePath() . 'uploads/companies/' . $companyId . '/cards/';
                    $frontUrl = $frontFilename ? $cardBaseUrl . $frontFilename : null;
                    $backUrl = $backFilename ? $cardBaseUrl . $backFilename : null;
                    
                    // Check for PDF
                    $cardsDir = getCompanyCardsDir($companyId);
                    $hasFrontPdf = $frontFilename && file_exists($cardsDir . '/' . str_replace('.png', '.pdf', $frontFilename));
                    $hasBackPdf = $backFilename && file_exists($cardsDir . '/' . str_replace('.png', '.pdf', $backFilename));
                    
                    // Check if this is the sample card
                    $isSample = $frontFilename && $backFilename && 
                                $currentSampleFront === 'companies/' . $companyId . '/cards/' . $frontFilename &&
                                $currentSampleBack === 'companies/' . $companyId . '/cards/' . $backFilename;
                    ?>
                    <tr 
                        class="hover:bg-gray-50 transition-colors"
                        x-show="matchesSearch('<?php echo addslashes($emp['email'] ?? ''); ?>', '<?php echo addslashes($emp['name_en'] ?? ''); ?>', '<?php echo addslashes($emp['name_ar'] ?? ''); ?>', '<?php echo addslashes($emp['department_id'] ?? ''); ?>')"
                    >
                        <td class="px-6 py-4">
                            <?php 
                            // Prepare employee detail data for modal
                            $empRequests = $cardRequestsByEmail[$emp['email']] ?? [];
                            $empPrintOrders = $printOrdersByEmployee[$emp['id']] ?? [];
                            $totalPrinted = array_sum(array_column($empPrintOrders, 'quantity'));
                            $lastPrintDate = !empty($empPrintOrders) ? $empPrintOrders[0]['created_at'] : null;
                            
                            $empDetailData = [
                                'employee' => $emp,
                                'cards' => $empCards,
                                'requests' => $empRequests,
                                'printOrders' => $empPrintOrders,
                                'totalPrinted' => $totalPrinted,
                                'lastPrintDate' => $lastPrintDate,
                                'department' => $deptName,
                                'latestCardUrl' => $frontUrl,
                                'latestCardBackUrl' => $backUrl
                            ];
                            ?>
                            <div class="flex items-center gap-3 cursor-pointer group" @click='openDetailModal(<?php echo json_encode($empDetailData, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>
                                <div class="w-10 h-10 rounded-full bg-gradient-to-br from-blue-500 to-blue-600 flex items-center justify-center text-white font-semibold text-sm group-hover:ring-2 group-hover:ring-blue-300 transition-all">
                                    <?php echo strtoupper(substr($emp['name_en'] ?? 'E', 0, 2)); ?>
                                </div>
                                <div>
                                    <p class="font-semibold text-gray-900 group-hover:text-blue-600 transition-colors"><?php echo sanitize($emp['name_en'] ?? ''); ?></p>
                                    <p class="text-gray-500 text-sm"><?php echo sanitize($emp['email'] ?? ''); ?></p>
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-4 hidden lg:table-cell">
                            <p class="text-gray-900"><?php echo sanitize($emp['position_en'] ?? '-'); ?></p>
                            <?php if (!empty($emp['position_ar'])): ?>
                            <p class="text-gray-500 text-sm" dir="rtl"><?php echo sanitize($emp['position_ar']); ?></p>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4 hidden md:table-cell">
                            <?php if ($deptName !== '-'): ?>
                            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-blue-50 text-blue-700">
                                <?php echo $deptName; ?>
                            </span>
                            <?php else: ?>
                            <span class="text-gray-400">-</span>
                            <?php endif; ?>
                        </td>
                        <!-- Card Preview Column -->
                        <td class="px-6 py-4">
                            <?php if ($latestCard && $frontUrl): ?>
                            <div class="flex items-center justify-center gap-2">
                                <!-- Front thumbnail -->
                                <a href="<?php echo $frontUrl; ?>" target="_blank" class="relative group">
                                    <img src="<?php echo $frontUrl; ?>" alt="Front" class="w-16 h-10 object-cover rounded border border-gray-200 shadow-sm">
                                    <span class="absolute -top-1 -left-1 w-4 h-4 bg-blue-500 text-white text-[8px] font-bold rounded flex items-center justify-center">F</span>
                                    <?php if ($isSample): ?>
                                    <span class="absolute -top-1 -right-1 text-amber-500"><i class="fa-solid fa-star text-xs"></i></span>
                                    <?php endif; ?>
                                </a>
                                <!-- Back thumbnail -->
                                <?php if ($backUrl): ?>
                                <a href="<?php echo $backUrl; ?>" target="_blank" class="relative group">
                                    <img src="<?php echo $backUrl; ?>" alt="Back" class="w-16 h-10 object-cover rounded border border-gray-200 shadow-sm">
                                    <span class="absolute -top-1 -left-1 w-4 h-4 bg-green-500 text-white text-[8px] font-bold rounded flex items-center justify-center">B</span>
                                </a>
                                <?php endif; ?>
                                <!-- Card info -->
                                <div class="text-xs text-gray-500 ml-1 hidden xl:block">
                                    <?php if ($cardCount > 1): ?>
                                    <span class="text-blue-600 font-medium">v<?php echo $cardCount; ?></span>
                                    <?php endif; ?>
                                    <div class="text-[10px]"><?php echo date('M j', strtotime($latestCard['generatedAt'] ?? 'now')); ?></div>
                                </div>
                            </div>
                            <?php else: ?>
                            <div class="flex items-center justify-center">
                                <a href="auto_generate.php?employee_id=<?php echo $emp['id']; ?>&return=employees" 
                                   class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-blue-50 hover:bg-blue-100 text-blue-700 rounded-lg text-xs font-medium transition-colors">
                                    <i class="fa-solid fa-wand-magic-sparkles"></i>
                                    Generate Card
                                </a>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4 text-center hidden sm:table-cell">
                            <?php 
                            $scanCount = (int)($emp['total_scans'] ?? 0);
                            if ($scanCount > 0): 
                            ?>
                            <a href="analytics.php?employee=<?php echo urlencode($emp['id']); ?>" 
                               class="inline-flex items-center gap-1.5 px-2.5 py-1 bg-blue-50 hover:bg-blue-100 text-blue-700 rounded-full text-sm font-medium transition-colors">
                                <i class="fa-solid fa-chart-line text-xs"></i>
                                <?php echo number_format($scanCount); ?>
                            </a>
                            <?php else: ?>
                            <span class="text-gray-400 text-sm">0</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4 text-right">
                            <div class="flex items-center justify-end gap-1" x-data="{ showMenu: false }">
                                <?php if ($latestCard && $frontUrl): ?>
                                <!-- Download dropdown -->
                                <div class="relative">
                                    <button @click="showMenu = !showMenu" @click.outside="showMenu = false"
                                            class="p-2 text-gray-500 hover:text-blue-600 hover:bg-blue-50 rounded-lg transition-colors"
                                            title="Download">
                                        <i class="fa-solid fa-download"></i>
                                    </button>
                                    <div x-show="showMenu" x-cloak
                                         class="absolute right-0 mt-1 w-48 bg-white rounded-lg shadow-lg border border-gray-200 py-1 z-20">
                                        <?php if ($hasFrontPdf && $hasBackPdf): ?>
                                        <a href="javascript:void(0)" onclick="downloadAsPDF('<?php echo $cardBaseUrl . str_replace('.png', '.pdf', $frontFilename); ?>', '<?php echo $cardBaseUrl . str_replace('.png', '.pdf', $backFilename); ?>', '<?php echo addslashes($emp['name_en'] ?? 'card'); ?>')"
                                           class="flex items-center gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                                            <i class="fa-solid fa-file-pdf text-red-500 w-4"></i> Download PDF
                                        </a>
                                        <?php endif; ?>
                                        <a href="javascript:void(0)" onclick="downloadAsPNGs('<?php echo $frontUrl; ?>', '<?php echo $backUrl; ?>', '<?php echo addslashes($emp['name_en'] ?? 'card'); ?>')"
                                           class="flex items-center gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                                            <i class="fa-solid fa-images text-blue-500 w-4"></i> Download PNGs
                                        </a>
                                        <hr class="my-1 border-gray-100">
                                        <a href="<?php echo $frontUrl; ?>" download class="flex items-center gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                                            <i class="fa-solid fa-image text-gray-400 w-4"></i> Front Only
                                        </a>
                                        <?php if ($backUrl): ?>
                                        <a href="<?php echo $backUrl; ?>" download class="flex items-center gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                                            <i class="fa-solid fa-image text-gray-400 w-4"></i> Back Only
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <!-- Set as sample -->
                                <?php if (!$isSample && $frontFilename && $backFilename): ?>
                                <form method="post" class="inline">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="action" value="set_sample">
                                    <input type="hidden" name="sample_front" value="companies/<?php echo $companyId; ?>/cards/<?php echo $frontFilename; ?>">
                                    <input type="hidden" name="sample_back" value="companies/<?php echo $companyId; ?>/cards/<?php echo $backFilename; ?>">
                                    <button type="submit" class="p-2 text-gray-500 hover:text-amber-600 hover:bg-amber-50 rounded-lg transition-colors" title="Set as Sample">
                                        <i class="fa-regular fa-star"></i>
                                    </button>
                                </form>
                                <?php endif; ?>
                                
                                <!-- Order Print -->
                                <a href="order_print.php?employee=<?php echo $emp['id']; ?>&card_front=<?php echo urlencode($frontUrl ?? ''); ?>&card_back=<?php echo urlencode($backUrl ?? ''); ?>" 
                                   class="p-2 text-gray-500 hover:text-green-600 hover:bg-green-50 rounded-lg transition-colors"
                                   title="Order Print">
                                    <i class="fa-solid fa-print"></i>
                                </a>
                                <?php else: ?>
                                <!-- Regenerate if no card -->
                                <a href="auto_generate.php?employee_id=<?php echo $emp['id']; ?>&return=employees" 
                                   class="p-2 text-gray-500 hover:text-purple-600 hover:bg-purple-50 rounded-lg transition-colors"
                                   title="Generate Card">
                                    <i class="fa-solid fa-wand-magic-sparkles"></i>
                                </a>
                                <?php endif; ?>
                                
                                <!-- Regenerate -->
                                <?php if ($latestCard): ?>
                                <a href="auto_generate.php?employee_id=<?php echo $emp['id']; ?>&return=employees&regenerate=1" 
                                   class="p-2 text-gray-500 hover:text-purple-600 hover:bg-purple-50 rounded-lg transition-colors"
                                   title="Regenerate Card">
                                    <i class="fa-solid fa-rotate"></i>
                                </a>
                                <?php endif; ?>
                                
                                <!-- Edit -->
                                <button
                                    @click='openEditModal(<?php echo json_encode($emp); ?>)'
                                    class="p-2 text-gray-500 hover:text-blue-600 hover:bg-blue-50 rounded-lg transition-colors"
                                    title="Edit"
                                >
                                    <i class="fa-solid fa-pen-to-square"></i>
                                </button>

                                <!-- Re-send edit-link invite -->
                                <form method="post" class="inline" onsubmit="return confirm(<?= htmlspecialchars(json_encode(t('employees.edit_invite_confirm')), ENT_QUOTES) ?>)">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="action" value="resend_edit_invite">
                                    <input type="hidden" name="id" value="<?php echo sanitize($emp['id']); ?>">
                                    <button type="submit" class="p-2 text-gray-500 hover:text-purple-600 hover:bg-purple-50 rounded-lg transition-colors" title="<?= htmlspecialchars(t('employees.edit_invite_title')) ?>">
                                        <i class="fa-solid fa-paper-plane"></i>
                                    </button>
                                </form>
                                
                                <!-- Delete -->
                                <form method="post" class="inline" onsubmit="return confirm('Are you sure you want to delete this employee and their cards?')">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo sanitize($emp['id']); ?>">
                                    <button type="submit" class="p-2 text-gray-500 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors" title="Delete">
                                        <i class="fa-solid fa-trash-can"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    
                    <?php if (empty($employees)): ?>
                    <tr>
                        <td colspan="5" class="px-6 py-12 text-center">
                            <div class="max-w-sm mx-auto">
                                <i class="fa-solid fa-id-card text-4xl text-gray-300 mb-4"></i>
                                <p class="text-gray-700 font-semibold mb-1"><?= htmlspecialchars(t('employees.no_employees')) ?></p>
                                <p class="text-sm text-gray-500 mb-6"><?= htmlspecialchars(t('employees.no_employees_body')) ?></p>
                                <div class="flex flex-col sm:flex-row gap-3 justify-center">
                                    <?php if (!empty($adminEmail)): ?>
                                    <button @click="openAddMyself(<?php echo htmlspecialchars(json_encode(['name_en' => $adminName, 'email' => $adminEmail]), ENT_QUOTES); ?>)"
                                            class="inline-flex items-center justify-center gap-2 px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-xl text-sm font-semibold transition-colors shadow-sm shadow-blue-200">
                                        <i class="fa-solid fa-user-plus"></i><?= htmlspecialchars(t('employees.add_myself_first')) ?>
                                    </button>
                                    <?php endif; ?>
                                    <button @click="openAddModal()"
                                            class="inline-flex items-center justify-center gap-2 px-5 py-2.5 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-xl text-sm font-medium transition-colors">
                                        <i class="fa-solid fa-plus"></i><?= htmlspecialchars(t('employees.add_an_employee')) ?>
                                    </button>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Add/Edit Modal -->
    <div x-show="showModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" @keydown.escape.window="showModal = false">
        <div class="absolute inset-0 bg-gray-900/50 backdrop-blur-sm" @click="showModal = false"></div>
        <div class="relative bg-white rounded-2xl shadow-xl w-full max-w-2xl max-h-[90vh] overflow-y-auto">
            <div class="p-6 border-b border-gray-100">
                <h3 class="text-xl font-bold text-gray-900" x-text="editingEmployee ? 'Edit Employee' : 'Add New Employee'"></h3>
            </div>
            
            <form method="post" class="p-6">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" :value="editingEmployee ? 'update' : 'add'">
                <input type="hidden" name="id" x-model="formData.id">
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Email <span class="text-red-500">*</span></label>
                        <input type="email" name="email" x-model="formData.email" required 
                               class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                    </div>
                    
                    <?php if (!empty($departments)): ?>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Department</label>
                        <select name="department_id" x-model="formData.department_id" 
                                class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                            <option value="">Select department</option>
                            <?php foreach ($departments as $dept): ?>
                            <option value="<?php echo sanitize($dept['id']); ?>"><?php echo sanitize($dept['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($needsField('name_en')): ?>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Name (English) <span class="text-red-500">*</span></label>
                        <input type="text" name="name_en" x-model="formData.name_en" required
                               class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                    </div>
                    <?php endif; ?>

                    <?php if ($needsField('name_ar')): ?>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Name (Arabic)</label>
                        <input type="text" name="name_ar" x-model="formData.name_ar" dir="rtl"
                               class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                    </div>
                    <?php endif; ?>

                    <?php if ($needsField('position_en')): ?>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Position (English)</label>
                        <input type="text" name="position_en" x-model="formData.position_en"
                               class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                    </div>
                    <?php endif; ?>

                    <?php if ($needsField('position_ar')): ?>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Position (Arabic)</label>
                        <input type="text" name="position_ar" x-model="formData.position_ar" dir="rtl"
                               class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                    </div>
                    <?php endif; ?>

                    <?php if ($needsField('mobile')): ?>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Mobile</label>
                        <input type="text" name="mobile" x-model="formData.mobile" placeholder="+968 9XXX XXXX"
                               @input="formData.mobile_ar = toArabicNumerals(formData.mobile || '')"
                               class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                        <input type="hidden" name="mobile_ar" :value="toArabicNumerals(formData.mobile || '')">
                    </div>
                    <?php endif; ?>

                    <?php if ($needsField('phone')): ?>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Phone</label>
                        <input type="text" name="phone" x-model="formData.phone" placeholder="+968 2XXX XXXX"
                               @input="formData.phone_ar = toArabicNumerals(formData.phone || '')"
                               class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                        <input type="hidden" name="phone_ar" :value="toArabicNumerals(formData.phone || '')">
                    </div>
                    <?php endif; ?>
                </div>

                <?php
                $hasOverridable = false;
                foreach (['company_en','company_ar','website','website_ar','address_en','address_ar'] as $__ovKey) {
                    if ($needsField($__ovKey)) { $hasOverridable = true; break; }
                }
                ?>
                <?php if ($hasOverridable): ?>
                <!-- Per-company fields. Defaulted to company-level values, only
                     unfold if HR needs to override for one employee. -->
                <details class="mt-4 rounded-xl border border-gray-200 bg-gray-50">
                    <summary class="cursor-pointer px-4 py-3 text-sm font-semibold text-gray-700 hover:bg-gray-100 select-none flex items-center gap-2">
                        <i class="fa-solid fa-chevron-down text-xs"></i>
                        Override company defaults
                        <span class="text-xs font-normal text-gray-500">(optional, leave blank to inherit)</span>
                    </summary>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 p-4 pt-2">
                        <?php if ($needsField('company_en')): ?>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Company (English)</label>
                            <input type="text" name="company_en" x-model="formData.company_en"
                                   class="w-full px-4 py-2.5 bg-white border border-gray-200 rounded-lg text-gray-900 focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                        </div>
                        <?php endif; ?>
                        <?php if ($needsField('company_ar')): ?>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Company (Arabic)</label>
                            <input type="text" name="company_ar" x-model="formData.company_ar" dir="rtl"
                                   class="w-full px-4 py-2.5 bg-white border border-gray-200 rounded-lg text-gray-900 focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                        </div>
                        <?php endif; ?>
                        <?php if ($needsField('website')): ?>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Website</label>
                            <input type="text" name="website" x-model="formData.website" placeholder="www.example.com"
                                   class="w-full px-4 py-2.5 bg-white border border-gray-200 rounded-lg text-gray-900 focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                        </div>
                        <?php endif; ?>
                        <?php if ($needsField('website_ar')): ?>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Website (Arabic)</label>
                            <input type="text" name="website_ar" x-model="formData.website_ar" dir="rtl" placeholder="www.example.com"
                                   class="w-full px-4 py-2.5 bg-white border border-gray-200 rounded-lg text-gray-900 focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                        </div>
                        <?php endif; ?>
                        <?php if ($needsField('address_en')): ?>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Address (English)</label>
                            <input type="text" name="address_en" x-model="formData.address_en"
                                   class="w-full px-4 py-2.5 bg-white border border-gray-200 rounded-lg text-gray-900 focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                        </div>
                        <?php endif; ?>
                        <?php if ($needsField('address_ar')): ?>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Address (Arabic)</label>
                            <input type="text" name="address_ar" x-model="formData.address_ar" dir="rtl"
                                   class="w-full px-4 py-2.5 bg-white border border-gray-200 rounded-lg text-gray-900 focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                        </div>
                        <?php endif; ?>
                    </div>
                </details>
                <?php endif; ?>

                <!-- Dynamic QR -->
                <div class="mt-6 p-4 rounded-xl bg-indigo-50 border border-indigo-100">
                    <div class="flex items-start justify-between gap-4 mb-3">
                        <div>
                            <h4 class="text-sm font-bold text-indigo-900 flex items-center gap-2">
                                <i class="fa-solid fa-qrcode"></i>
                                Dynamic QR
                            </h4>
                            <p class="text-xs text-indigo-700 mt-1">
                                Point this employee's printed QR to a custom URL (landing page, LinkedIn, etc.) without reprinting. Leave off to serve the VCF contact card.
                            </p>
                        </div>
                        <label class="inline-flex items-center gap-2 cursor-pointer select-none">
                            <input type="checkbox" x-model="qrRedirectEnabled"
                                   @change="if (!qrRedirectEnabled) formData.qr_redirect_url = ''"
                                   class="w-4 h-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                            <span class="text-xs font-semibold text-indigo-800">Enable</span>
                        </label>
                    </div>
                    <div x-show="qrRedirectEnabled" x-cloak>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">Redirect Destination URL</label>
                        <div class="flex gap-2">
                            <input type="url" name="qr_redirect_url" x-model="formData.qr_redirect_url"
                                   placeholder="https://example.com/landing"
                                   maxlength="1024"
                                   class="flex-1 px-4 py-2.5 bg-white border border-indigo-200 rounded-lg text-gray-900 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20">
                        </div>
                        <p class="text-[11px] text-indigo-700 mt-2" x-show="formData.id">
                            Printed QR tracker URL:
                            <button type="button"
                                    @click="navigator.clipboard.writeText(qrTrackerUrl()); $event.target.textContent='Copied!'; setTimeout(()=>{ $event.target.textContent=qrTrackerUrl() }, 1500);"
                                    class="font-mono underline text-indigo-800 hover:text-indigo-900"
                                    x-text="qrTrackerUrl()"></button>
                        </p>
                    </div>
                    <!-- Always POST a value so unchecking clears the DB field -->
                    <template x-if="!qrRedirectEnabled">
                        <input type="hidden" name="qr_redirect_url" value="">
                    </template>
                </div>

                <!-- Public Card Theme Toggle -->
                <div class="mt-4 p-4 rounded-xl bg-amber-50 border border-amber-100">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h4 class="text-sm font-bold text-amber-900 flex items-center gap-2">
                                <i class="fa-solid fa-circle-half-stroke"></i>
                                Public Card Theme Toggle
                            </h4>
                            <p class="text-xs text-amber-700 mt-1">
                                Lets visitors flip your public card between light and dark mode with a sun/moon button.
                                Uncheck to force your card's default theme for everyone.
                            </p>
                        </div>
                        <label class="inline-flex items-center gap-2 cursor-pointer select-none shrink-0">
                            <input type="checkbox"
                                   x-model="cardDarkModeToggle"
                                   class="w-4 h-4 rounded border-gray-300 text-amber-600 focus:ring-amber-500">
                            <span class="text-xs font-semibold text-amber-800"><?= htmlspecialchars(t('employees.dark_mode_hint')) ?></span>
                        </label>
                    </div>
                    <!-- Always POST a canonical 0/1 so unchecking persists -->
                    <input type="hidden" name="card_dark_mode_toggle" :value="cardDarkModeToggle ? '1' : '0'">
                </div>

                <!-- Viral "Made with Cardify" footer; Cardify branding always renders per company policy. -->
                <?php $__proUnlocked = true; ?>
                <div class="mt-4 p-4 rounded-xl bg-sky-50 border border-sky-100">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h4 class="text-sm font-bold text-sky-900 flex items-center gap-2">
                                <i class="fa-solid fa-eye-slash"></i>
                                Hide "Made with Cardify" footer
                                <?php if (!$__proUnlocked): ?>
                                    <span class="text-[10px] font-bold uppercase tracking-wider bg-amber-100 text-amber-800 px-2 py-0.5 rounded-full">Pro</span>
                                <?php endif; ?>
                            </h4>
                            <p class="text-xs text-sky-700 mt-1">
                                Removes the small "Made with Cardify · Create yours free" footer
                                from this employee's public digital card.
                                <?php if (!$__proUnlocked): ?>
                                    <span class="block mt-1 text-amber-700 font-medium">
                                        Upgrade to Pro to unlock white-label cards.
                                    </span>
                                <?php endif; ?>
                            </p>
                        </div>
                        <label class="inline-flex items-center gap-2 <?php echo $__proUnlocked ? 'cursor-pointer' : 'cursor-not-allowed opacity-50'; ?> select-none shrink-0"
                               <?php if (!$__proUnlocked): ?>title="Upgrade to Pro to unlock white-label cards"<?php endif; ?>>
                            <input type="checkbox"
                                   x-model="hideCardifyBranding"
                                   <?php if (!$__proUnlocked): ?>disabled<?php endif; ?>
                                   class="w-4 h-4 rounded border-gray-300 text-sky-600 focus:ring-sky-500">
                            <span class="text-xs font-semibold text-sky-800">Hide footer</span>
                        </label>
                    </div>
                    <input type="hidden" name="hide_cardify_branding" :value="hideCardifyBranding ? '1' : '0'">
                </div>

                <div class="flex items-center justify-end gap-3 mt-6 pt-6 border-t border-gray-100">
                    <button type="button" @click="showModal = false" class="px-4 py-2 text-gray-600 hover:text-gray-900 font-medium transition-colors">
                        Cancel
                    </button>
                    <button type="submit" class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition-colors">
                        <span x-text="editingEmployee ? 'Save Changes' : 'Add Employee'"></span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Import Modal -->
    <div x-show="showImportModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" @keydown.escape.window="showImportModal = false">
        <div class="absolute inset-0 bg-gray-900/50 backdrop-blur-sm" @click="showImportModal = false"></div>
        <div class="relative bg-white rounded-2xl shadow-xl w-full max-w-lg">
            <div class="p-6 border-b border-gray-100">
                <h3 class="text-xl font-bold text-gray-900"><?= htmlspecialchars(t('employees.import_title')) ?></h3>
            </div>
            
            <form method="post" enctype="multipart/form-data" class="p-6">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="import">
                
                <!-- Download Template Section -->
                <div class="mb-6 p-4 rounded-xl bg-blue-50 border border-blue-200">
                    <div class="flex items-center justify-between">
                        <div>
                            <h4 class="text-blue-800 font-semibold flex items-center gap-2">
                                <i class="fa-solid fa-file-csv"></i>
                                <?= htmlspecialchars(t('employees.import_need_template')) ?>
                            </h4>
                            <p class="text-blue-600 text-sm mt-1"><?= htmlspecialchars(t('employees.import_template_sub')) ?></p>
                        </div>
                        <button type="button" onclick="downloadEmployeeTemplate()" 
                                class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium transition-colors flex items-center gap-2">
                            <i class="fa-solid fa-download"></i>
                            <?= htmlspecialchars(t('employees.import_download')) ?>
                        </button>
                    </div>
                </div>
                
                <div class="mb-6">
                    <label class="block text-sm font-semibold text-gray-700 mb-2"><?= htmlspecialchars(t('employees.import_select_file')) ?></label>
                    <input type="file" name="excel_file" accept=".csv,.xlsx,.xls" required 
                           class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:bg-blue-50 file:text-blue-700 file:font-medium hover:file:bg-blue-100">
                </div>
                
                <!-- Import Options -->
                <div class="mb-6 space-y-3">
                    <label class="flex items-center gap-3 cursor-pointer">
                        <input type="checkbox" name="skip_duplicates" checked 
                               class="w-4 h-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                        <div>
                            <span class="text-sm font-medium text-gray-700"><?= htmlspecialchars(t('employees.import_skip_duplicates')) ?></span>
                            <p class="text-xs text-gray-500"><?= htmlspecialchars(t('employees.import_skip_duplicates_hint')) ?></p>
                        </div>
                    </label>
                    <label class="flex items-center gap-3 cursor-pointer">
                        <input type="checkbox" name="auto_convert_arabic" checked 
                               class="w-4 h-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                        <div>
                            <span class="text-sm font-medium text-gray-700"><?= htmlspecialchars(t('employees.import_auto_arabic')) ?></span>
                            <p class="text-xs text-gray-500"><?= htmlspecialchars(t('employees.import_auto_arabic_hint')) ?></p>
                        </div>
                    </label>
                </div>
                
                <div class="p-4 rounded-xl bg-amber-50 border border-amber-200 mb-6">
                    <h4 class="text-amber-800 font-semibold mb-2 flex items-center gap-2">
                        <i class="fa-solid fa-lightbulb"></i>
                        <?= htmlspecialchars(t('employees.import_expected_cols')) ?>
                    </h4>
                    <p class="text-amber-700 text-sm mb-2"><?= htmlspecialchars(t('employees.import_expected_cols_hint')) ?></p>
                    <p class="text-amber-600 text-xs font-mono bg-amber-100 p-2 rounded break-all">email, name_en, name_ar, position_en, position_ar, phone, mobile, company_en, company_ar, website, address_en, address_ar, department</p>
                    <p class="text-amber-600 text-xs mt-2"><?= t('employees.import_cols_note') ?></p>
                </div>
                
                <div class="flex items-center justify-end gap-3">
                    <button type="button" @click="showImportModal = false" class="px-4 py-2 text-gray-600 hover:text-gray-900 font-medium transition-colors">
                        <?= htmlspecialchars(t('employees.import_cancel')) ?>
                    </button>
                    <button type="submit" class="px-6 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg font-medium transition-colors">
                        <i class="fa-solid fa-file-import mr-2"></i><?= htmlspecialchars(t('employees.import_submit')) ?>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Employee Detail Modal -->
    <div x-show="showDetailModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" @keydown.escape.window="showDetailModal = false">
        <div class="absolute inset-0 bg-gray-900/50 backdrop-blur-sm" @click="showDetailModal = false"></div>
        <div class="relative bg-white rounded-2xl shadow-xl w-full max-w-4xl max-h-[90vh] overflow-y-auto">
            <!-- Header -->
            <div class="sticky top-0 bg-white border-b border-gray-100 p-6 flex items-center justify-between z-10">
                <div class="flex items-center gap-4">
                    <div class="w-14 h-14 rounded-full bg-gradient-to-br from-blue-500 to-blue-600 flex items-center justify-center text-white font-bold text-xl">
                        <span x-text="detailData.employee?.name_en?.substring(0,2).toUpperCase() || 'EM'"></span>
                    </div>
                    <div>
                        <h3 class="text-xl font-bold text-gray-900" x-text="detailData.employee?.name_en || 'Employee'"></h3>
                        <p class="text-gray-500" x-text="detailData.employee?.email"></p>
                    </div>
                </div>
                <button @click="showDetailModal = false" class="p-2 text-gray-400 hover:text-gray-600 rounded-lg hover:bg-gray-100">
                    <i class="fa-solid fa-times text-xl"></i>
                </button>
            </div>
            
            <div class="p-6">
                <!-- Stats Row -->
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                    <div class="bg-blue-50 rounded-xl p-4 text-center">
                        <div class="text-2xl font-bold text-blue-600" x-text="detailData.cards?.length || 0"></div>
                        <div class="text-xs text-blue-700 font-medium"><?= htmlspecialchars(t('employees.stat_card_versions')) ?></div>
                    </div>
                    <div class="bg-green-50 rounded-xl p-4 text-center">
                        <div class="text-2xl font-bold text-green-600" x-text="detailData.totalPrinted || 0"></div>
                        <div class="text-xs text-green-700 font-medium"><?= htmlspecialchars(t('employees.stat_cards_printed')) ?></div>
                    </div>
                    <div class="bg-purple-50 rounded-xl p-4 text-center">
                        <div class="text-2xl font-bold text-purple-600" x-text="detailData.employee?.total_scans || 0"></div>
                        <div class="text-xs text-purple-700 font-medium"><?= htmlspecialchars(t('employees.stat_qr_scans')) ?></div>
                    </div>
                    <div class="bg-amber-50 rounded-xl p-4 text-center">
                        <div class="text-2xl font-bold text-amber-600" x-text="detailData.printOrders?.length || 0"></div>
                        <div class="text-xs text-amber-700 font-medium"><?= htmlspecialchars(t('employees.stat_print_orders')) ?></div>
                    </div>
                </div>
                
                <div class="grid md:grid-cols-2 gap-6">
                    <!-- Left Column: Employee Info & Card Preview -->
                    <div class="space-y-6">
                        <!-- Current Card Preview -->
                        <div class="bg-gray-50 rounded-xl p-4">
                            <h4 class="font-semibold text-gray-900 mb-3 flex items-center gap-2">
                                <i class="fa-solid fa-id-card text-blue-500"></i>
                                <?= htmlspecialchars(t('employees.current_card')) ?>
                            </h4>
                            <template x-if="detailData.latestCardUrl">
                                <div class="flex gap-3">
                                    <a :href="detailData.latestCardUrl" target="_blank" class="flex-1">
                                        <img :src="detailData.latestCardUrl" alt="Front" class="w-full rounded-lg border border-gray-200 shadow-sm hover:shadow-md transition-shadow">
                                        <p class="text-xs text-center text-gray-500 mt-1">Front</p>
                                    </a>
                                    <a :href="detailData.latestCardBackUrl" target="_blank" class="flex-1" x-show="detailData.latestCardBackUrl">
                                        <img :src="detailData.latestCardBackUrl" alt="Back" class="w-full rounded-lg border border-gray-200 shadow-sm hover:shadow-md transition-shadow">
                                        <p class="text-xs text-center text-gray-500 mt-1">Back</p>
                                    </a>
                                </div>
                            </template>
                            <template x-if="!detailData.latestCardUrl">
                                <div class="text-center py-8 text-gray-400">
                                    <i class="fa-solid fa-image text-3xl mb-2"></i>
                                    <p class="text-sm"><?= htmlspecialchars(t('employees.no_card_generated')) ?></p>
                                </div>
                            </template>
                        </div>
                        
                        <!-- Employee Details -->
                        <div class="bg-gray-50 rounded-xl p-4">
                            <h4 class="font-semibold text-gray-900 mb-3 flex items-center gap-2">
                                <i class="fa-solid fa-user text-blue-500"></i>
                                Contact Information
                            </h4>
                            <div class="space-y-2 text-sm">
                                <div class="flex justify-between" x-show="detailData.employee?.position_en">
                                    <span class="text-gray-500">Position</span>
                                    <span class="text-gray-900 font-medium" x-text="detailData.employee?.position_en"></span>
                                </div>
                                <div class="flex justify-between" x-show="detailData.department && detailData.department !== '-'">
                                    <span class="text-gray-500">Department</span>
                                    <span class="px-2 py-0.5 bg-blue-100 text-blue-700 rounded-full text-xs font-medium" x-text="detailData.department"></span>
                                </div>
                                <div class="flex justify-between" x-show="detailData.employee?.phone">
                                    <span class="text-gray-500">Phone</span>
                                    <span class="text-gray-900" x-text="detailData.employee?.phone"></span>
                                </div>
                                <div class="flex justify-between" x-show="detailData.employee?.mobile">
                                    <span class="text-gray-500">Mobile</span>
                                    <span class="text-gray-900" x-text="detailData.employee?.mobile"></span>
                                </div>
                                <div class="flex justify-between" x-show="detailData.employee?.website">
                                    <span class="text-gray-500">Website</span>
                                    <span class="text-gray-900" x-text="detailData.employee?.website"></span>
                                </div>
                                <div class="flex justify-between" x-show="detailData.employee?.company_en">
                                    <span class="text-gray-500">Company</span>
                                    <span class="text-gray-900" x-text="detailData.employee?.company_en"></span>
                                </div>
                                <div class="flex justify-between" x-show="detailData.employee?.created_at">
                                    <span class="text-gray-500">Added</span>
                                    <span class="text-gray-900" x-text="formatDate(detailData.employee?.created_at)"></span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Right Column: History -->
                    <div class="space-y-6">
                        <!-- Card Generation History -->
                        <div class="bg-gray-50 rounded-xl p-4">
                            <h4 class="font-semibold text-gray-900 mb-3 flex items-center gap-2">
                                <i class="fa-solid fa-clock-rotate-left text-purple-500"></i>
                                Card Generation History
                            </h4>
                            <template x-if="detailData.cards?.length > 0">
                                <div class="space-y-2 max-h-48 overflow-y-auto">
                                    <template x-for="(card, idx) in detailData.cards" :key="card.id">
                                        <div class="flex items-center justify-between py-2 border-b border-gray-200 last:border-0">
                                            <div class="flex items-center gap-2">
                                                <span class="w-6 h-6 bg-purple-100 text-purple-600 rounded-full flex items-center justify-center text-xs font-bold" x-text="'v' + (detailData.cards.length - idx)"></span>
                                                <span class="text-sm text-gray-700" x-text="formatDate(card.generatedAt)"></span>
                                            </div>
                                            <span class="text-xs text-gray-400" x-text="idx === 0 ? 'Current' : ''"></span>
                                        </div>
                                    </template>
                                </div>
                            </template>
                            <template x-if="!detailData.cards?.length">
                                <p class="text-gray-400 text-sm text-center py-4"><?= htmlspecialchars(t('employees.no_cards_generated')) ?></p>
                            </template>
                        </div>
                        
                        <!-- Card Requests History -->
                        <div class="bg-gray-50 rounded-xl p-4">
                            <h4 class="font-semibold text-gray-900 mb-3 flex items-center gap-2">
                                <i class="fa-solid fa-inbox text-amber-500"></i>
                                Request History
                            </h4>
                            <template x-if="detailData.requests?.length > 0">
                                <div class="space-y-2 max-h-36 overflow-y-auto">
                                    <template x-for="req in detailData.requests" :key="req.id">
                                        <div class="flex items-center justify-between py-2 border-b border-gray-200 last:border-0">
                                            <span class="text-sm text-gray-700" x-text="formatDate(req.created_at)"></span>
                                            <span class="text-xs px-2 py-0.5 rounded-full font-medium"
                                                  :class="{
                                                      'bg-green-100 text-green-700': req.status === 'approved',
                                                      'bg-red-100 text-red-700': req.status === 'rejected',
                                                      'bg-amber-100 text-amber-700': req.status === 'pending'
                                                  }"
                                                  x-text="req.status"></span>
                                        </div>
                                    </template>
                                </div>
                            </template>
                            <template x-if="!detailData.requests?.length">
                                <p class="text-gray-400 text-sm text-center py-4">No requests</p>
                            </template>
                        </div>
                        
                        <!-- Print Orders History -->
                        <div class="bg-gray-50 rounded-xl p-4">
                            <h4 class="font-semibold text-gray-900 mb-3 flex items-center gap-2">
                                <i class="fa-solid fa-print text-green-500"></i>
                                Print Order History
                            </h4>
                            <template x-if="detailData.printOrders?.length > 0">
                                <div class="space-y-2 max-h-36 overflow-y-auto">
                                    <template x-for="order in detailData.printOrders" :key="order.id">
                                        <div class="flex items-center justify-between py-2 border-b border-gray-200 last:border-0">
                                            <div>
                                                <span class="text-sm text-gray-700" x-text="formatDate(order.created_at)"></span>
                                                <span class="text-xs text-gray-500 ml-2" x-text="order.quantity + ' cards'"></span>
                                            </div>
                                            <span class="text-xs px-2 py-0.5 rounded-full font-medium"
                                                  :class="{
                                                      'bg-green-100 text-green-700': order.status === 'completed' || order.status === 'delivered',
                                                      'bg-blue-100 text-blue-700': order.status === 'processing' || order.status === 'printing',
                                                      'bg-amber-100 text-amber-700': order.status === 'pending'
                                                  }"
                                                  x-text="order.status"></span>
                                        </div>
                                    </template>
                                </div>
                            </template>
                            <template x-if="!detailData.printOrders?.length">
                                <p class="text-gray-400 text-sm text-center py-4"><?= htmlspecialchars(t('employees.no_print_orders')) ?></p>
                            </template>
                            <template x-if="detailData.lastPrintDate">
                                <p class="text-xs text-gray-500 mt-2 pt-2 border-t border-gray-200">
                                    Last printed: <span x-text="formatDate(detailData.lastPrintDate)"></span>
                                </p>
                            </template>
                        </div>
                    </div>
                </div>
                
                <!-- Actions Footer -->
                <div class="flex items-center justify-between mt-6 pt-6 border-t border-gray-100">
                    <div class="flex items-center gap-2">
                        <a :href="'analytics.php?employee=' + detailData.employee?.id" 
                           class="px-4 py-2 bg-purple-50 text-purple-700 rounded-lg text-sm font-medium hover:bg-purple-100 transition-colors flex items-center gap-2"
                           x-show="detailData.employee?.total_scans > 0">
                            <i class="fa-solid fa-chart-line"></i> View Analytics
                        </a>
                    </div>
                    <div class="flex items-center gap-2">
                        <a :href="'auto_generate.php?employee_id=' + detailData.employee?.id + '&return=employees&regenerate=1'" 
                           class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg text-sm font-medium hover:bg-gray-200 transition-colors flex items-center gap-2"
                           x-show="detailData.latestCardUrl">
                            <i class="fa-solid fa-rotate"></i> Regenerate
                        </a>
                        <a :href="'order_print.php?employee=' + detailData.employee?.id" 
                           class="px-4 py-2 bg-green-600 text-white rounded-lg text-sm font-medium hover:bg-green-700 transition-colors flex items-center gap-2"
                           x-show="detailData.latestCardUrl">
                            <i class="fa-solid fa-print"></i> Order Print
                        </a>
                        <button @click="openEditModalFromDetail()" 
                                class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700 transition-colors flex items-center gap-2">
                            <i class="fa-solid fa-pen"></i> Edit
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function employeeManager() {
    return {
        searchQuery: '',
        filterDepartment: '',
        showModal: false,
        showImportModal: false,
        showDetailModal: false,
        editingEmployee: false,
        detailData: {},
        formData: {
            id: '', email: '', department_id: '', name_en: '', name_ar: '',
            position_en: '', position_ar: '', phone: '', phone_ar: '', mobile: '', mobile_ar: '',
            company_en: '', company_ar: '', website: '', website_ar: '', address_en: '', address_ar: '',
            qr_redirect_url: ''
        },
        qrRedirectEnabled: false,
        cardDarkModeToggle: true,
        hideCardifyBranding: false,

        // Tracker URL printed on the card, never changes post-print.
        qrTrackerUrl() {
            if (!this.formData.id) return '';
            return window.location.origin + '/qr.php?i=' + encodeURIComponent(this.formData.id);
        },

        // Arabic numeral conversion utility
        toArabicNumerals(str) {
            if (!str) return '';
            const western = '0123456789';
            const arabic = '٠١٢٣٤٥٦٧٨٩';
            return str.split('').map(c => {
                const i = western.indexOf(c);
                return i >= 0 ? arabic[i] : c;
            }).join('');
        },
        
        matchesSearch(email, nameEn, nameAr, deptId) {
            const searchMatch = !this.searchQuery || 
                email.toLowerCase().includes(this.searchQuery.toLowerCase()) || 
                nameEn.toLowerCase().includes(this.searchQuery.toLowerCase()) || 
                nameAr.toLowerCase().includes(this.searchQuery.toLowerCase());
            const deptMatch = !this.filterDepartment || deptId === this.filterDepartment;
            return searchMatch && deptMatch;
        },
        
        openAddModal() {
            this.editingEmployee = false;
            this.formData = {
                id: '', email: '', department_id: '', name_en: '', name_ar: '',
                position_en: '', position_ar: '', phone: '', phone_ar: '', mobile: '', mobile_ar: '',
                company_en: '', company_ar: '', website: '', website_ar: '', address_en: '', address_ar: '',
                qr_redirect_url: ''
            };
            this.qrRedirectEnabled = false;
            this.cardDarkModeToggle = true;
            this.hideCardifyBranding = false;
            this.showModal = true;
        },

        openAddMyself(prefill) {
            this.editingEmployee = false;
            this.formData = {
                id: '', email: prefill.email || '', department_id: '', name_en: prefill.name_en || '',
                name_ar: '', position_en: '', position_ar: '', phone: '', phone_ar: '', mobile: '', mobile_ar: '',
                company_en: '', company_ar: '', website: '', website_ar: '', address_en: '', address_ar: '',
                qr_redirect_url: ''
            };
            this.qrRedirectEnabled = false;
            this.cardDarkModeToggle = true;
            this.hideCardifyBranding = false;
            this.showModal = true;
        },

        openEditModal(employee) {
            this.editingEmployee = true;
            this.formData = { ...employee, qr_redirect_url: employee.qr_redirect_url || '' };
            this.qrRedirectEnabled = !!(employee.qr_redirect_url && employee.qr_redirect_url.trim());
            // DB stores 0/1; treat missing as 1 (default-on for existing rows)
            this.cardDarkModeToggle = employee.card_dark_mode_toggle === undefined || employee.card_dark_mode_toggle === null
                ? true
                : Number(employee.card_dark_mode_toggle) === 1;
            this.hideCardifyBranding = Number(employee.hide_cardify_branding || 0) === 1;
            this.showModal = true;
        },

        openDetailModal(data) {
            this.detailData = data;
            this.showDetailModal = true;
        },

        openEditModalFromDetail() {
            this.showDetailModal = false;
            this.editingEmployee = true;
            const emp = this.detailData.employee || {};
            this.formData = { ...emp, qr_redirect_url: emp.qr_redirect_url || '' };
            this.qrRedirectEnabled = !!(emp.qr_redirect_url && String(emp.qr_redirect_url).trim());
            this.cardDarkModeToggle = emp.card_dark_mode_toggle === undefined || emp.card_dark_mode_toggle === null
                ? true
                : Number(emp.card_dark_mode_toggle) === 1;
            this.hideCardifyBranding = Number(emp.hide_cardify_branding || 0) === 1;
            this.showModal = true;
        },
        
        formatDate(dateStr) {
            if (!dateStr) return '-';
            const date = new Date(dateStr);
            return date.toLocaleDateString('en-US', { 
                year: 'numeric', 
                month: 'short', 
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            });
        }
    };
}

// Download functions for cards
async function downloadAsPDF(frontPdfUrl, backPdfUrl, employeeName) {
    try {
        // Load PDF-lib
        if (typeof PDFLib === 'undefined') {
            await loadScript('https://unpkg.com/pdf-lib@1.17.1/dist/pdf-lib.min.js');
        }
        
        const frontBytes = await fetch(frontPdfUrl).then(res => res.arrayBuffer());
        const backBytes = await fetch(backPdfUrl).then(res => res.arrayBuffer());
        
        const mergedPdf = await PDFLib.PDFDocument.create();
        
        const frontPdf = await PDFLib.PDFDocument.load(frontBytes);
        const backPdf = await PDFLib.PDFDocument.load(backBytes);
        
        const [frontPage] = await mergedPdf.copyPages(frontPdf, [0]);
        const [backPage] = await mergedPdf.copyPages(backPdf, [0]);
        
        mergedPdf.addPage(frontPage);
        mergedPdf.addPage(backPage);
        
        const pdfBytes = await mergedPdf.save();
        const blob = new Blob([pdfBytes], { type: 'application/pdf' });
        const url = URL.createObjectURL(blob);
        
        const a = document.createElement('a');
        a.href = url;
        a.download = `${employeeName.replace(/[^a-z0-9]/gi, '_')}_card.pdf`;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    } catch (error) {
        console.error('PDF download error:', error);
        alert('Error downloading PDF. Please try downloading individual files.');
    }
}

async function downloadAsPNGs(frontUrl, backUrl, employeeName) {
    try {
        // Load JSZip
        if (typeof JSZip === 'undefined') {
            await loadScript('https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js');
        }
        
        const zip = new JSZip();
        
        const frontBlob = await fetch(frontUrl).then(res => res.blob());
        const backBlob = await fetch(backUrl).then(res => res.blob());
        
        const safeName = employeeName.replace(/[^a-z0-9]/gi, '_');
        zip.file(`${safeName}_front.png`, frontBlob);
        zip.file(`${safeName}_back.png`, backBlob);
        
        const content = await zip.generateAsync({ type: 'blob' });
        const url = URL.createObjectURL(content);
        
        const a = document.createElement('a');
        a.href = url;
        a.download = `${safeName}_cards.zip`;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    } catch (error) {
        console.error('PNG download error:', error);
        alert('Error creating ZIP. Please try downloading individual files.');
    }
}

function loadScript(src) {
    return new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = src;
        script.onload = resolve;
        script.onerror = reject;
        document.head.appendChild(script);
    });
}

// Download CSV template with sample data
function downloadEmployeeTemplate() {
    // CSV headers (simplified - Arabic numerals are auto-generated)
    const headers = [
        'email',
        'name_en',
        'name_ar',
        'position_en',
        'position_ar',
        'phone',
        'mobile',
        'company_en',
        'company_ar',
        'website',
        'address_en',
        'address_ar',
        'department'
    ];
    
    // Sample data rows - multiple employees from same company/department
    const sampleData = [
        [
            'john.doe@company.com',
            'John Doe',
            'جون دو',
            'Software Engineer',
            'مهندس برمجيات',
            '+968 1234 5678',
            '+968 9876 5432',
            'Tech Company',
            'شركة التقنية',
            'www.company.com',
            '123 Business Street, Muscat',
            'شارع الأعمال ١٢٣، مسقط',
            'Engineering'
        ],
        [
            'jane.smith@company.com',
            'Jane Smith',
            'جين سميث',
            'Senior Developer',
            'مطور أول',
            '+968 2345 6789',
            '+968 8765 4321',
            'Tech Company',
            'شركة التقنية',
            'www.company.com',
            '123 Business Street, Muscat',
            'شارع الأعمال ١٢٣، مسقط',
            'Engineering'
        ],
        [
            'ahmed.ali@company.com',
            'Ahmed Ali',
            'أحمد علي',
            'Marketing Manager',
            'مدير التسويق',
            '+968 3456 7890',
            '+968 7654 3210',
            'Tech Company',
            'شركة التقنية',
            'www.company.com',
            '123 Business Street, Muscat',
            'شارع الأعمال ١٢٣، مسقط',
            'Marketing'
        ]
    ];
    
    // Build CSV content
    let csvContent = headers.join(',') + '\n';
    
    sampleData.forEach(row => {
        // Escape fields that might contain commas or quotes
        const escapedRow = row.map(field => {
            if (field.includes(',') || field.includes('"') || field.includes('\n')) {
                return '"' + field.replace(/"/g, '""') + '"';
            }
            return field;
        });
        csvContent += escapedRow.join(',') + '\n';
    });
    
    // Create and download the file
    const blob = new Blob(['\ufeff' + csvContent], { type: 'text/csv;charset=utf-8;' }); // BOM for Excel UTF-8
    const url = window.URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = 'employees_template.csv';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    window.URL.revokeObjectURL(url);
}
</script>

<?php adminFooter(); ?>
