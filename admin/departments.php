<?php
/**
 * Department Management - Cardify
 * Manage departments and assign template pairs
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

$company = findCompanyById($companyId);
$companySlug = $company['slug'] ?? '';

$message = null;
$messageType = 'success';

// Routing-email helpers for the portal Send flow (single responsible + CC list).
function dept_clean_email($e) {
    $e = trim((string)$e);
    return ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) ? $e : null;
}
function dept_clean_cc($s) {
    $out = array_filter(array_map('trim', explode(',', (string)$s)), function ($e) {
        return filter_var($e, FILTER_VALIDATE_EMAIL);
    });
    return $out ? implode(',', array_values(array_unique($out))) : null;
}

// Get departments with template pair info
$departments = $db->fetchAll(
    "SELECT d.*, 
            t.name as template_name,
            tp.name as pair_name
     FROM departments d 
     LEFT JOIN templates t ON d.template_id = t.id 
     LEFT JOIN templates tp ON d.template_pair_id = tp.pair_id AND tp.side = 'front'
     WHERE d.company_id = :id 
     ORDER BY d.name",
    ['id' => $companyId]
);

// Get available template pairs (grouped by pair_id)
$templatePairs = $db->fetchAll(
    "SELECT DISTINCT t.pair_id, t.name, 
            (SELECT background_image_path FROM templates WHERE pair_id = t.pair_id AND side = 'front' LIMIT 1) as front_image
     FROM templates t 
     WHERE t.company_id = :id AND t.pair_id IS NOT NULL
     GROUP BY t.pair_id, t.name
     ORDER BY t.name",
    ['id' => $companyId]
);

// Get legacy templates (no pair_id) for backward compatibility
$legacyTemplates = $db->fetchAll(
    "SELECT * FROM templates WHERE company_id = :id AND pair_id IS NULL AND is_active = 1 ORDER BY name",
    ['id' => $companyId]
);

// Per-side templates list for the per-side overrides (action 586).
// `side` may be 'front' or 'back' on the legacy templates table.
$frontTemplates = $db->fetchAll(
    "SELECT id, name FROM templates WHERE company_id = :id AND side = 'front' ORDER BY name",
    ['id' => $companyId]
);
$backTemplates = $db->fetchAll(
    "SELECT id, name FROM templates WHERE company_id = :id AND side = 'back' ORDER BY name",
    ['id' => $companyId]
);

// Handle create/update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { die('Invalid request'); }
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create') {
        $name = $_POST['name'] ?? '';
        $description = $_POST['description'] ?? '';
        $templatePairId = $_POST['template_pair_id'] ?? null;
        $slug = trim($_POST['slug'] ?? '');
        $portalPasscode = trim($_POST['portal_passcode'] ?? '');
        
        // Validate passcode (only digits, max 4)
        if (!empty($portalPasscode) && !preg_match('/^\d{1,4}$/', $portalPasscode)) {
            $portalPasscode = '';
        }
        
        // Auto-generate slug if not provided
        if (empty($slug)) {
            $slug = generateSlug($name);
        }
        
        // Ensure slug is unique for this company
        $baseSlug = $slug;
        $counter = 1;
        while ($db->fetchOne("SELECT id FROM departments WHERE company_id = :cid AND portal_slug = :slug", ['cid' => $companyId, 'slug' => $slug])) {
            $slug = $baseSlug . '-' . $counter++;
        }
        
        if (!empty($name)) {
            $deptId = generateUUID();
            $insertData = [
                'id' => $deptId,
                'company_id' => $companyId,
                'name' => $name,
                'description' => $description,
                'portal_slug' => $slug,
                'access_code' => $portalPasscode ?: null,
                'responsible_email' => dept_clean_email($_POST['responsible_email'] ?? ''),
                'cc_emails' => dept_clean_cc($_POST['cc_emails'] ?? ''),
                'include_qr_default' => !empty($_POST['include_qr_default']) ? 1 : 0,
                'created_at' => date('Y-m-d H:i:s')
            ];
            
            // Handle template pair assignment
            if (!empty($templatePairId)) {
                $insertData['template_pair_id'] = $templatePairId;
            }

            // Per-side overrides (action 586), scope ids to this company
            $frontId = trim($_POST['front_template_id'] ?? '');
            $backId  = trim($_POST['back_template_id']  ?? '');
            if ($frontId !== '') {
                $ok = $db->fetchOne("SELECT id FROM templates WHERE id = :id AND company_id = :cid", ['id' => $frontId, 'cid' => $companyId]);
                if ($ok) $insertData['front_template_id'] = $frontId;
            }
            if ($backId !== '') {
                $ok = $db->fetchOne("SELECT id FROM templates WHERE id = :id AND company_id = :cid", ['id' => $backId, 'cid' => $companyId]);
                if ($ok) $insertData['back_template_id'] = $backId;
            }

            $db->insert('departments', $insertData);
            $message = 'Department created successfully!';
        }
    } elseif ($action === 'update') {
        $deptId = $_POST['department_id'] ?? '';
        $name = $_POST['name'] ?? '';
        $description = $_POST['description'] ?? '';
        $templatePairId = $_POST['template_pair_id'] ?? null;
        $slug = trim($_POST['slug'] ?? '');
        $portalPasscode = trim($_POST['portal_passcode'] ?? '');
        
        // Validate passcode (only digits, max 4)
        if (!empty($portalPasscode) && !preg_match('/^\d{1,4}$/', $portalPasscode)) {
            $portalPasscode = '';
        }
        
        // Auto-generate slug if not provided
        if (empty($slug)) {
            $slug = generateSlug($name);
        }
        
        // Ensure slug is unique for this company (excluding current department)
        $baseSlug = $slug;
        $counter = 1;
        while ($db->fetchOne("SELECT id FROM departments WHERE company_id = :cid AND portal_slug = :slug AND id != :id", ['cid' => $companyId, 'slug' => $slug, 'id' => $deptId])) {
            $slug = $baseSlug . '-' . $counter++;
        }
        
        $frontId = trim($_POST['front_template_id'] ?? '');
        $backId  = trim($_POST['back_template_id']  ?? '');
        $frontSafe = null; $backSafe = null;
        if ($frontId !== '') {
            $ok = $db->fetchOne("SELECT id FROM templates WHERE id = :id AND company_id = :cid", ['id' => $frontId, 'cid' => $companyId]);
            if ($ok) $frontSafe = $frontId;
        }
        if ($backId !== '') {
            $ok = $db->fetchOne("SELECT id FROM templates WHERE id = :id AND company_id = :cid", ['id' => $backId, 'cid' => $companyId]);
            if ($ok) $backSafe = $backId;
        }

        if (!empty($deptId) && !empty($name)) {
            $updateData = [
                'name' => $name,
                'description' => $description,
                'portal_slug' => $slug,
                'template_pair_id' => $templatePairId ?: null,
                'front_template_id' => $frontSafe,
                'back_template_id'  => $backSafe,
                'access_code' => $portalPasscode ?: null,
                'responsible_email' => dept_clean_email($_POST['responsible_email'] ?? ''),
                'cc_emails' => dept_clean_cc($_POST['cc_emails'] ?? ''),
                'include_qr_default' => !empty($_POST['include_qr_default']) ? 1 : 0
            ];

            $db->update('departments', $updateData, 'id = :id AND company_id = :company_id', [
                'id' => $deptId,
                'company_id' => $companyId
            ]);
            $message = 'Department updated successfully!';
        }
    } elseif ($action === 'delete') {
        $deptId = $_POST['department_id'] ?? '';
        if (!empty($deptId)) {
            // Unlink employees from this department first
            $db->update('employees', ['department_id' => null], 'department_id = :id', ['id' => $deptId]);
            
            $db->delete('departments', 'id = :id AND company_id = :company_id', [
                'id' => $deptId,
                'company_id' => $companyId
            ]);
            $message = 'Department deleted successfully!';
        }
    }
    
    // Reload departments
    $departments = $db->fetchAll(
        "SELECT d.*, 
                t.name as template_name,
                tp.name as pair_name
         FROM departments d 
         LEFT JOIN templates t ON d.template_id = t.id 
         LEFT JOIN templates tp ON d.template_pair_id = tp.pair_id AND tp.side = 'front'
         WHERE d.company_id = :id 
         ORDER BY d.name",
        ['id' => $companyId]
    );
}

// Get employee counts per department
$deptCounts = [];
foreach ($departments as $dept) {
    $count = $db->fetchOne(
        "SELECT COUNT(*) as count FROM employees WHERE department_id = :id",
        ['id' => $dept['id']]
    );
    $deptCounts[$dept['id']] = $count['count'] ?? 0;
}

adminHeader(t('departments.page_title'), 'departments');
?>

<div x-data="{ showModal: false, editMode: false, formData: { id: '', name: '', description: '', portal_slug: '', template_pair_id: '', front_template_id: '', back_template_id: '', access_code: '', responsible_email: '', cc_emails: '', include_qr_default: true } }">
    <!-- Page Header Actions -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
        <div>
            <p class="text-gray-600"><?= htmlspecialchars(t('departments.count', ['n' => count($departments)])) ?></p>
        </div>
        <button @click="showModal = true; editMode = false; formData = { id: '', name: '', description: '', portal_slug: '', template_pair_id: '', front_template_id: '', back_template_id: '', access_code: '', responsible_email: '', cc_emails: '', include_qr_default: true }"
                class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors text-sm font-medium flex items-center gap-2">
            <i class="fa-solid fa-plus"></i>
            <span><?= htmlspecialchars(t('departments.add_department')) ?></span>
        </button>
    </div>

    <!-- Alert Message -->
    <?php if ($message): ?>
    <div class="mb-6 p-4 rounded-xl flex items-center gap-3 bg-green-50 border border-green-200 text-green-700">
        <i class="fa-solid fa-circle-check"></i>
        <?php echo sanitize($message); ?>
    </div>
    <?php endif; ?>

    <!-- Departments Grid -->
    <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-6">
        <?php foreach ($departments as $dept): ?>
        <div class="bg-white rounded-xl border border-gray-200 shadow-sm hover:shadow-md transition-shadow overflow-hidden">
            <div class="p-6">
                <div class="flex items-start justify-between mb-4">
                    <div class="w-12 h-12 rounded-xl bg-blue-100 flex items-center justify-center">
                        <i class="fa-solid fa-sitemap text-blue-600 text-xl"></i>
                    </div>
                    <div class="flex items-center gap-1">
                        <button 
                            @click="showModal = true; editMode = true; formData = { id: '<?php echo $dept['id']; ?>', name: '<?php echo addslashes($dept['name']); ?>', description: '<?php echo addslashes($dept['description'] ?? ''); ?>', portal_slug: '<?php echo addslashes($dept['portal_slug'] ?? ''); ?>', template_pair_id: '<?php echo $dept['template_pair_id'] ?? ''; ?>', front_template_id: '<?php echo $dept['front_template_id'] ?? ''; ?>', back_template_id: '<?php echo $dept['back_template_id'] ?? ''; ?>', access_code: '<?php echo addslashes($dept['access_code'] ?? ''); ?>', responsible_email: '<?php echo addslashes($dept['responsible_email'] ?? ''); ?>', cc_emails: '<?php echo addslashes($dept['cc_emails'] ?? ''); ?>', include_qr_default: <?php echo !empty($dept['include_qr_default']) ? 'true' : 'false'; ?> }"
                            class="p-2 text-gray-400 hover:text-blue-600 hover:bg-blue-50 rounded-lg transition-colors"
                        >
                            <i class="fa-solid fa-pen-to-square"></i>
                        </button>
                        <form method="post" class="inline" onsubmit="return confirm(<?= json_encode(t('departments.delete_confirm')) ?>)">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="department_id" value="<?php echo $dept['id']; ?>">
                            <button type="submit" class="p-2 text-gray-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors">
                                <i class="fa-solid fa-trash-can"></i>
                            </button>
                        </form>
                    </div>
                </div>
                
                <h3 class="text-lg font-bold text-gray-900 mb-1"><?php echo sanitize($dept['name']); ?></h3>
                
                <?php if (!empty($dept['portal_slug']) && !empty($companySlug)): ?>
                <div class="flex items-center gap-1.5 text-xs text-blue-600 mb-2">
                    <i class="fa-solid fa-link"></i>
                    <code class="bg-blue-50 px-1.5 py-0.5 rounded"><?php echo htmlspecialchars(getTenantUrl($companySlug, '/portal/' . $dept['portal_slug'])); ?></code>
                    <button type="button" onclick="copyToClipboard('<?php echo getTenantUrl($companySlug, '/portal/' . $dept['portal_slug']); ?>')"
                            class="text-gray-400 hover:text-blue-600 transition-colors" title="<?= htmlspecialchars(t('departments.copy_link')) ?>">
                        <i class="fa-solid fa-copy"></i>
                    </button>
                    <?php if (!empty($dept['access_code'])): ?>
                    <span class="inline-flex items-center px-1.5 py-0.5 rounded bg-amber-100 text-amber-700 text-[10px] font-medium ml-1" title="<?= htmlspecialchars(t('departments.access_code_title', ['code' => $dept['access_code']])) ?>">
                        <i class="fa-solid fa-lock mr-0.5"></i><?= htmlspecialchars(t('departments.protected')) ?>
                    </span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($dept['description'])): ?>
                <p class="text-gray-600 text-sm mb-4"><?php echo sanitize($dept['description']); ?></p>
                <?php endif; ?>
                
                <div class="flex items-center justify-between pt-4 border-t border-gray-100">
                    <div class="flex items-center gap-2 text-sm text-gray-500">
                        <i class="fa-solid fa-users"></i>
                        <span><?= htmlspecialchars(t('departments.employees_count', ['n' => $deptCounts[$dept['id']] ?? 0])) ?></span>
                    </div>
                    
                    <?php 
                    $templateName = $dept['pair_name'] ?? $dept['template_name'] ?? null;
                    if (!empty($templateName)): 
                    ?>
                    <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-purple-50 text-purple-700">
                        <i class="fa-solid fa-palette mr-1"></i>
                        <?php echo sanitize($templateName); ?>
                    </span>
                    <?php else: ?>
                    <span class="text-xs text-gray-400"><?= htmlspecialchars(t('departments.company_default')) ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        
        <?php if (empty($departments)): ?>
        <div class="md:col-span-2 lg:col-span-3 bg-white rounded-xl border border-gray-200 p-12 text-center">
            <div class="text-gray-400">
                <i class="fa-solid fa-sitemap text-4xl mb-4 opacity-50"></i>
                <p class="text-gray-600 font-medium"><?= htmlspecialchars(t('departments.no_departments')) ?></p>
                <p class="text-sm mt-1"><?= htmlspecialchars(t('departments.no_departments_body')) ?></p>
                <button @click="showModal = true; editMode = false" class="mt-4 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium">
                    <i class="fa-solid fa-plus mr-2"></i><?= htmlspecialchars(t('departments.create_first')) ?>
                </button>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Add/Edit Modal -->
    <div x-show="showModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" @keydown.escape.window="showModal = false">
        <div class="absolute inset-0 bg-gray-900/50 backdrop-blur-sm" @click="showModal = false"></div>
        <div class="relative bg-white rounded-2xl shadow-xl w-full max-w-md">
            <div class="p-6 border-b border-gray-100">
                <h3 class="text-xl font-bold text-gray-900" x-text="editMode ? <?= htmlspecialchars(json_encode(t('departments.modal_edit_title'), JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?> : <?= htmlspecialchars(json_encode(t('departments.modal_create_title'), JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>"></h3>
            </div>
            
            <form method="post" class="p-6">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" :value="editMode ? 'update' : 'create'">
                <input type="hidden" name="department_id" x-model="formData.id">
                
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2"><?= htmlspecialchars(t('departments.field_name')) ?> <span class="text-red-500" aria-label="<?= htmlspecialchars(t('departments.required')) ?>">*</span></label>
                        <input type="text" name="name" x-model="formData.name" required
                               @input="if(!editMode) formData.portal_slug = $event.target.value.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '')"
                               class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20"
                               placeholder="<?= htmlspecialchars(t('departments.name_placeholder')) ?>">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">
                            <?= htmlspecialchars(t('departments.field_slug')) ?>
                            <span class="text-xs font-normal text-gray-500"><?= htmlspecialchars(t('departments.slug_note')) ?></span>
                        </label>
                        <div class="flex items-center gap-2">
                            <span class="text-gray-500 text-sm whitespace-nowrap" dir="ltr">/<?php echo $companySlug; ?>/portal/</span>
                            <input type="text" name="slug" x-model="formData.portal_slug"
                                   class="flex-1 px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 font-mono text-sm"
                                   placeholder="<?= htmlspecialchars(t('departments.slug_placeholder')) ?>" pattern="[a-z0-9-]+">
                        </div>
                        <p class="text-xs text-gray-500 mt-1"><?= htmlspecialchars(t('departments.slug_hint')) ?></p>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2"><?= htmlspecialchars(t('departments.field_template')) ?></label>
                        <select name="template_pair_id" x-model="formData.template_pair_id"
                                class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                            <option value=""><?= htmlspecialchars(t('departments.use_company_default')) ?></option>
                            <?php foreach ($templatePairs as $pair): ?>
                            <option value="<?php echo sanitize($pair['pair_id']); ?>"><?php echo sanitize($pair['name']); ?></option>
                            <?php endforeach; ?>
                            <?php if (!empty($legacyTemplates)): ?>
                            <optgroup label="<?= htmlspecialchars(t('departments.legacy_templates')) ?>">
                            <?php foreach ($legacyTemplates as $template): ?>
                            <option value="legacy_<?php echo sanitize($template['id']); ?>"><?php echo sanitize($template['name']); ?> (<?php echo $template['side']; ?>)</option>
                            <?php endforeach; ?>
                            </optgroup>
                            <?php endif; ?>
                        </select>
                        <p class="text-xs text-gray-500 mt-1"><?= htmlspecialchars(t('departments.template_hint')) ?></p>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-semibold text-gray-700 mb-1"><?= htmlspecialchars(t('departments.field_front_template')) ?></label>
                            <select name="front_template_id" x-model="formData.front_template_id"
                                    class="w-full px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 text-sm focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                                <option value=""><?= htmlspecialchars(t('departments.use_company_default')) ?></option>
                                <?php foreach ($frontTemplates as $tpl): ?>
                                    <option value="<?= sanitize($tpl['id']) ?>"><?= sanitize($tpl['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-700 mb-1"><?= htmlspecialchars(t('departments.field_back_template')) ?></label>
                            <select name="back_template_id" x-model="formData.back_template_id"
                                    class="w-full px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 text-sm focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                                <option value=""><?= htmlspecialchars(t('departments.use_company_default')) ?></option>
                                <?php foreach ($backTemplates as $tpl): ?>
                                    <option value="<?= sanitize($tpl['id']) ?>"><?= sanitize($tpl['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <p class="text-xs text-gray-500 -mt-2"><?= htmlspecialchars(t('departments.side_override_hint')) ?></p>

                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2"><?= htmlspecialchars(t('departments.field_description')) ?></label>
                        <textarea name="description" x-model="formData.description" rows="3"
                                  class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20"
                                  placeholder="<?= htmlspecialchars(t('departments.description_placeholder')) ?>"></textarea>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">
                            <?= htmlspecialchars(t('departments.field_access_code')) ?>
                            <span class="text-xs font-normal text-gray-500"><?= htmlspecialchars(t('departments.optional')) ?></span>
                        </label>
                        <input type="text" name="portal_passcode" x-model="formData.access_code"
                               maxlength="4" pattern="[0-9]*" inputmode="numeric"
                               class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 font-mono text-lg tracking-widest"
                               placeholder="<?= htmlspecialchars(t('departments.access_code_placeholder')) ?>">
                        <p class="text-xs text-gray-500 mt-1"><?= htmlspecialchars(t('departments.access_code_hint')) ?></p>
                    </div>
                </div>
                
                <div class="mt-4 pt-4 border-t border-gray-100">
                    <p class="text-sm font-semibold text-gray-700 mb-3"><?= htmlspecialchars(t('departments.routing_heading')) ?></p>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2"><?= htmlspecialchars(t('departments.field_responsible_email')) ?></label>
                            <input type="email" name="responsible_email" x-model="formData.responsible_email"
                                   class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20"
                                   placeholder="dept@company.com">
                            <p class="text-xs text-gray-500 mt-1"><?= htmlspecialchars(t('departments.responsible_email_hint')) ?></p>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2"><?= htmlspecialchars(t('departments.field_cc_emails')) ?></label>
                            <input type="text" name="cc_emails" x-model="formData.cc_emails"
                                   class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20"
                                   placeholder="a@company.com, b@company.com">
                            <p class="text-xs text-gray-500 mt-1"><?= htmlspecialchars(t('departments.cc_emails_hint')) ?></p>
                        </div>
                    </div>
                    <label class="flex items-center gap-2 mt-4 text-sm text-gray-700">
                        <input type="checkbox" name="include_qr_default" value="1" x-model="formData.include_qr_default"
                               class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                        <span><?= htmlspecialchars(t('departments.include_qr_default_label')) ?></span>
                    </label>
                </div>

                <div class="flex items-center justify-end gap-3 mt-6 pt-6 border-t border-gray-100">
                    <button type="button" @click="showModal = false" class="px-4 py-2 text-gray-600 hover:text-gray-900 font-medium transition-colors">
                        <?= htmlspecialchars(t('departments.cancel')) ?>
                    </button>
                    <button type="submit" class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition-colors">
                        <span x-text="editMode ? <?= htmlspecialchars(json_encode(t('departments.save_changes'), JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?> : <?= htmlspecialchars(json_encode(t('departments.create_department'), JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>"></span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function copyToClipboard(text) {
    navigator.clipboard.writeText(text).then(() => {
        // Show brief feedback
        const btn = event.target.closest('button');
        if (btn) {
            const icon = btn.querySelector('i');
            if (icon) {
                icon.className = 'fa-solid fa-check';
                setTimeout(() => {
                    icon.className = 'fa-solid fa-copy';
                }, 2000);
            }
        }
    });
}
</script>

<?php adminFooter(); ?>
