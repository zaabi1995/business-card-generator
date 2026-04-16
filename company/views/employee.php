<?php
/**
 * Employee View - Profile editor, card preview, order history
 */
require_once INCLUDES_DIR . '/Currency.php';
require_once INCLUDES_DIR . '/CardSections.php';

$employeeId = $_SESSION['employee_id'] ?? $_SESSION['user_id'] ?? null;
$message = null;
$messageType = 'success';

// Get employee data
$employee = null;
if ($employeeId) {
    $employee = $db->fetchOne("SELECT * FROM employees WHERE id = :id AND company_id = :cid", [
        'id' => $employeeId,
        'cid' => $company['id']
    ]);
}

if (!$employee) {
    // User is logged in but not an employee of this company
    header('Location: ' . getBasePath() . $companySlug . '/');
    exit;
}

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = 'Invalid request token. Please refresh and try again.';
        $messageType = 'error';
    } else {
    $updateData = [
        'name_en' => trim($_POST['name_en'] ?? ''),
        'name_ar' => trim($_POST['name_ar'] ?? ''),
        'position_en' => trim($_POST['position_en'] ?? ''),
        'position_ar' => trim($_POST['position_ar'] ?? ''),
        'phone' => trim($_POST['phone'] ?? ''),
        'mobile' => trim($_POST['mobile'] ?? ''),
        'website' => trim($_POST['website'] ?? ''),
        'address' => trim($_POST['address'] ?? ''),
        'updated_at' => date('Y-m-d H:i:s')
    ];
    
    try {
        $db->update('employees', $updateData, 'id = :id', ['id' => $employeeId]);
        $message = 'Profile updated successfully!';
        
        // Refresh employee data
        $employee = $db->fetchOne("SELECT * FROM employees WHERE id = :id", ['id' => $employeeId]);
        
        // Audit log
        if (class_exists('AuditLog')) {
            AuditLog::log('update', 'employee', $employeeId, null, $updateData, $company['id']);
        }
    } catch (Exception $e) {
        $message = 'Failed to update profile: ' . $e->getMessage();
        $messageType = 'error';
    }
    } // end CSRF else
}

// --- Public Card Sections handlers ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], [
    'update_sections', 'add_service', 'delete_service',
    'upload_gallery', 'delete_gallery',
    'add_testimonial', 'delete_testimonial',
    'approve_testimonial', 'reject_testimonial',
], true)) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = 'Invalid request token. Please refresh and try again.';
        $messageType = 'error';
    } else {
        try {
            $action = $_POST['action'];
            if ($action === 'update_sections') {
                CardSections::saveMaster($employeeId, $company['id'], [
                    'bio_enabled' => !empty($_POST['bio_enabled']),
                    'bio_text' => $_POST['bio_text'] ?? '',
                    'services_enabled' => !empty($_POST['services_enabled']),
                    'gallery_enabled' => !empty($_POST['gallery_enabled']),
                    'testimonials_enabled' => !empty($_POST['testimonials_enabled']),
                    'lead_form_enabled' => !empty($_POST['lead_form_enabled']),
                    'lead_form_email' => $_POST['lead_form_email'] ?? '',
                    'section_order' => $_POST['section_order'] ?? '',
                ]);
                $message = 'Public card sections saved.';
            } elseif ($action === 'add_service') {
                CardSections::addService(
                    $employeeId,
                    $_POST['service_icon'] ?? 'fa-solid fa-star',
                    $_POST['service_title'] ?? '',
                    $_POST['service_description'] ?? ''
                );
                $message = 'Service added.';
            } elseif ($action === 'delete_service') {
                CardSections::deleteService($employeeId, $_POST['service_id'] ?? '');
                $message = 'Service removed.';
            } elseif ($action === 'upload_gallery') {
                if (!empty($_FILES['gallery_images'])) {
                    $errs = [];
                    $stored = CardSections::handleGalleryUpload($_FILES['gallery_images'], $employeeId, $errs);
                    if ($errs) {
                        $message = 'Uploaded ' . count($stored) . ' image(s). Problems: ' . implode('; ', $errs);
                        $messageType = $stored ? 'success' : 'error';
                    } else {
                        $message = 'Uploaded ' . count($stored) . ' image(s).';
                    }
                }
            } elseif ($action === 'delete_gallery') {
                CardSections::deleteGalleryImage($employeeId, $_POST['gallery_id'] ?? '');
                $message = 'Photo removed.';
            } elseif ($action === 'add_testimonial') {
                $photoPath = null;
                if (!empty($_FILES['testimonial_photo']) && !empty($_FILES['testimonial_photo']['name'])) {
                    $err = null;
                    $photoPath = CardSections::handleImageUpload($_FILES['testimonial_photo'], $employeeId, 'testimonials', $err);
                    if ($err) {
                        $message = 'Photo upload: ' . $err;
                        $messageType = 'error';
                    }
                }
                $tid = CardSections::addTestimonial(
                    $employeeId,
                    $_POST['testimonial_name'] ?? '',
                    $_POST['testimonial_quote'] ?? '',
                    $photoPath,
                    ['status' => 'approved'] // owner-added → auto-approved
                );
                if ($tid && empty($message)) $message = 'Testimonial added.';
                if (!$tid) {
                    $message = 'Name and quote are required.';
                    $messageType = 'error';
                }
            } elseif ($action === 'delete_testimonial') {
                CardSections::deleteTestimonial($employeeId, $_POST['testimonial_id'] ?? '');
                $message = 'Testimonial removed.';
            } elseif ($action === 'approve_testimonial') {
                $ok = CardSections::setTestimonialStatus($employeeId, $_POST['testimonial_id'] ?? '', 'approved');
                $message = $ok ? 'Testimonial approved.' : 'Could not approve.';
                $messageType = $ok ? 'success' : 'error';
            } elseif ($action === 'reject_testimonial') {
                $ok = CardSections::setTestimonialStatus($employeeId, $_POST['testimonial_id'] ?? '', 'rejected');
                $message = $ok ? 'Testimonial rejected.' : 'Could not reject.';
                $messageType = $ok ? 'success' : 'error';
            }
        } catch (Throwable $e) {
            $message = 'Action failed: ' . $e->getMessage();
            $messageType = 'error';
            error_log('employee sections: ' . $e->getMessage());
        }
    }
}

// Load sections data for rendering
$sectionMaster = CardSections::loadMaster($employeeId, $company['id']);
$sectionServices = CardSections::loadServices($employeeId);
$sectionGallery = CardSections::loadGallery($employeeId);
$sectionTestimonials = CardSections::loadApprovedTestimonials($employeeId);
$pendingTestimonials = CardSections::loadPendingTestimonials($employeeId);
$rejectedTestimonials = CardSections::loadRejectedTestimonials($employeeId);

// Get employee's generated cards
$generatedCards = [];
try {
    $generatedCards = $db->fetchAll(
        "SELECT * FROM generated_cards WHERE employee_id = :id ORDER BY generated_at DESC LIMIT 10",
        ['id' => $employeeId]
    );
} catch (Exception $e) {}

// Get employee's orders
$employeeOrders = [];
try {
    $employeeOrders = $db->fetchAll(
        "SELECT o.*, oi.quantity 
         FROM orders o 
         JOIN order_items oi ON o.id = oi.order_id 
         WHERE oi.employee_id = :id 
         ORDER BY o.created_at DESC LIMIT 10",
        ['id' => $employeeId]
    );
} catch (Exception $e) {}

$currency = $company['currency'] ?? 'OMR';

$extraHead = '<style>
    :root {
        --primary-color: ' . $primaryColor . ';
        --secondary-color: ' . $secondaryColor . ';
    }
    body { font-family: "Plus Jakarta Sans", ui-sans-serif, system-ui, sans-serif; }
    .btn-primary { 
        background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
        color: white;
    }
    ' . (!empty($companyTheme['custom_css']) ? $companyTheme['custom_css'] : '') . '
</style>';
require_once INCLUDES_DIR . '/ui-header.php';
?>

<div class="min-h-screen bg-gray-50">
    <!-- Header -->
    <header class="bg-white border-b border-gray-200">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-4">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-4">
                    <?php if ($companyTheme && !empty($companyTheme['logo_path'])): ?>
                    <img src="<?php echo imageUrl($companyTheme['logo_path']); ?>" alt="<?php echo sanitize($company['name']); ?>" class="h-10">
                    <?php else: ?>
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-blue-500 to-blue-600 flex items-center justify-center text-white font-bold">
                        <?php echo strtoupper(substr($company['name'], 0, 2)); ?>
                    </div>
                    <?php endif; ?>
                    <div>
                        <h1 class="text-lg font-bold text-gray-900"><?php echo sanitize($company['name']); ?></h1>
                        <p class="text-sm text-gray-500">Employee Portal</p>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <span class="text-sm text-gray-600"><?php echo sanitize($employee['email']); ?></span>
                    <a href="<?php echo getBasePath(); ?>logout.php" class="text-sm text-red-600 hover:text-red-800">
                        Sign Out
                    </a>
                </div>
            </div>
        </div>
    </header>

    <main class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <?php if ($message): ?>
        <div class="mb-6 p-4 rounded-xl flex items-center gap-3 <?php echo $messageType === 'success' ? 'bg-green-50 border border-green-200 text-green-700' : 'bg-red-50 border border-red-200 text-red-700'; ?>">
            <i class="<?php echo $messageType === 'success' ? 'fa-solid fa-circle-check' : 'fa-solid fa-circle-exclamation'; ?>"></i>
            <?php echo sanitize($message); ?>
        </div>
        <?php endif; ?>

        <div class="grid lg:grid-cols-3 gap-6">
            <!-- Profile Form -->
            <div class="lg:col-span-2">
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
                    <div class="p-4 border-b border-gray-100">
                        <h2 class="font-semibold text-gray-900">Your Profile</h2>
                        <p class="text-sm text-gray-500">Update your information for your business card</p>
                    </div>
                    
                    <form method="post" class="p-6">
                        <input type="hidden" name="action" value="update_profile">
                        
                        <div class="grid md:grid-cols-2 gap-4 mb-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Name (English)</label>
                                <input type="text" name="name_en" value="<?php echo sanitize($employee['name_en'] ?? ''); ?>"
                                       class="w-full px-4 py-2 bg-gray-50 border border-gray-200 rounded-lg focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Name (Arabic)</label>
                                <input type="text" name="name_ar" value="<?php echo sanitize($employee['name_ar'] ?? ''); ?>" dir="rtl"
                                       class="w-full px-4 py-2 bg-gray-50 border border-gray-200 rounded-lg focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                            </div>
                        </div>
                        
                        <div class="grid md:grid-cols-2 gap-4 mb-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Position (English)</label>
                                <input type="text" name="position_en" value="<?php echo sanitize($employee['position_en'] ?? ''); ?>"
                                       class="w-full px-4 py-2 bg-gray-50 border border-gray-200 rounded-lg focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Position (Arabic)</label>
                                <input type="text" name="position_ar" value="<?php echo sanitize($employee['position_ar'] ?? ''); ?>" dir="rtl"
                                       class="w-full px-4 py-2 bg-gray-50 border border-gray-200 rounded-lg focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                            </div>
                        </div>
                        
                        <div class="grid md:grid-cols-2 gap-4 mb-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Phone</label>
                                <input type="tel" name="phone" value="<?php echo sanitize($employee['phone'] ?? ''); ?>"
                                       class="w-full px-4 py-2 bg-gray-50 border border-gray-200 rounded-lg focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Mobile</label>
                                <input type="tel" name="mobile" value="<?php echo sanitize($employee['mobile'] ?? ''); ?>"
                                       class="w-full px-4 py-2 bg-gray-50 border border-gray-200 rounded-lg focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                            </div>
                        </div>
                        
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Website</label>
                            <input type="url" name="website" value="<?php echo sanitize($employee['website'] ?? ''); ?>"
                                   class="w-full px-4 py-2 bg-gray-50 border border-gray-200 rounded-lg focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                        </div>
                        
                        <div class="mb-6">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Address</label>
                            <textarea name="address" rows="2"
                                      class="w-full px-4 py-2 bg-gray-50 border border-gray-200 rounded-lg focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20"><?php echo sanitize($employee['address'] ?? ''); ?></textarea>
                        </div>
                        
                        <button type="submit" class="px-6 py-2 btn-primary rounded-lg font-medium">
                            Save Changes
                        </button>
                    </form>
                </div>
            </div>

            <!-- Sidebar -->
            <div class="space-y-6">
                <!-- Card Preview -->
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
                    <div class="p-4 border-b border-gray-100">
                        <h3 class="font-semibold text-gray-900">Your Card</h3>
                    </div>
                    <div class="p-4">
                        <?php if (!empty($generatedCards)): ?>
                        <?php $latestCard = $generatedCards[0]; ?>
                        <?php if (!empty($latestCard['front_file_path'])): ?>
                        <img src="<?php echo imageUrl($latestCard['front_file_path']); ?>" alt="Your Card" class="w-full rounded-lg shadow-sm mb-3">
                        <?php endif; ?>
                        <p class="text-sm text-gray-500">Generated <?php echo date('M j, Y', strtotime($latestCard['generated_at'])); ?></p>
                        <?php else: ?>
                        <div class="text-center py-6 text-gray-500">
                            <i class="fa-solid fa-id-card text-3xl opacity-30 mb-2"></i>
                            <p class="text-sm">Your card hasn't been generated yet.</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Order History -->
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
                    <div class="p-4 border-b border-gray-100">
                        <h3 class="font-semibold text-gray-900">Your Orders</h3>
                    </div>
                    <div class="divide-y divide-gray-100">
                        <?php if (empty($employeeOrders)): ?>
                        <div class="p-4 text-center text-gray-500 text-sm">
                            No orders yet.
                        </div>
                        <?php else: ?>
                        <?php foreach ($employeeOrders as $order): ?>
                        <div class="p-4">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="font-medium text-gray-900 text-sm">#<?php echo sanitize($order['order_number']); ?></p>
                                    <p class="text-xs text-gray-500"><?php echo $order['quantity']; ?> cards</p>
                                </div>
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium
                                    <?php echo match($order['status']) {
                                        'completed' => 'bg-green-100 text-green-700',
                                        'pending' => 'bg-amber-100 text-amber-700',
                                        'cancelled' => 'bg-red-100 text-red-700',
                                        default => 'bg-gray-100 text-gray-700'
                                    }; ?>">
                                    <?php echo ucfirst($order['status']); ?>
                                </span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Public Card Sections -->
        <div class="mt-8 bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
            <div class="p-4 border-b border-gray-100 flex items-center justify-between">
                <div>
                    <h2 class="font-semibold text-gray-900">Public Card Sections</h2>
                    <p class="text-sm text-gray-500">Appears below your contact buttons when someone scans your QR.</p>
                </div>
                <?php if (!empty($company['slug']) && !empty($employee['id'])): ?>
                <a href="<?php echo getBasePath() . sanitize($company['slug']) . '/card/' . sanitize($employee['id']); ?>" target="_blank" class="text-sm text-blue-600 hover:underline">Preview &rarr;</a>
                <?php endif; ?>
            </div>

            <form method="post" class="p-6 space-y-6">
                <input type="hidden" name="action" value="update_sections">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">

                <div class="grid md:grid-cols-2 gap-3">
                    <label class="flex items-center gap-3 p-3 rounded-lg bg-gray-50 border border-gray-200">
                        <input type="checkbox" name="bio_enabled" <?php echo !empty($sectionMaster['bio_enabled']) ? 'checked' : ''; ?> class="h-4 w-4">
                        <span class="text-sm font-medium text-gray-800"><i class="fa-solid fa-user mr-1"></i> Bio</span>
                    </label>
                    <label class="flex items-center gap-3 p-3 rounded-lg bg-gray-50 border border-gray-200">
                        <input type="checkbox" name="services_enabled" <?php echo !empty($sectionMaster['services_enabled']) ? 'checked' : ''; ?> class="h-4 w-4">
                        <span class="text-sm font-medium text-gray-800"><i class="fa-solid fa-list-check mr-1"></i> Services</span>
                    </label>
                    <label class="flex items-center gap-3 p-3 rounded-lg bg-gray-50 border border-gray-200">
                        <input type="checkbox" name="gallery_enabled" <?php echo !empty($sectionMaster['gallery_enabled']) ? 'checked' : ''; ?> class="h-4 w-4">
                        <span class="text-sm font-medium text-gray-800"><i class="fa-solid fa-images mr-1"></i> Gallery</span>
                    </label>
                    <label class="flex items-center gap-3 p-3 rounded-lg bg-gray-50 border border-gray-200">
                        <input type="checkbox" name="testimonials_enabled" <?php echo !empty($sectionMaster['testimonials_enabled']) ? 'checked' : ''; ?> class="h-4 w-4">
                        <span class="text-sm font-medium text-gray-800"><i class="fa-solid fa-quote-right mr-1"></i> Testimonials</span>
                    </label>
                    <label class="flex items-center gap-3 p-3 rounded-lg bg-gray-50 border border-gray-200 md:col-span-2">
                        <input type="checkbox" name="lead_form_enabled" <?php echo !empty($sectionMaster['lead_form_enabled']) ? 'checked' : ''; ?> class="h-4 w-4">
                        <span class="text-sm font-medium text-gray-800"><i class="fa-solid fa-envelope-open-text mr-1"></i> Lead Form</span>
                    </label>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Bio</label>
                    <textarea name="bio_text" rows="4" placeholder="A short paragraph about you. Use **text** for bold."
                              class="w-full px-4 py-2 bg-gray-50 border border-gray-200 rounded-lg focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20"><?php echo sanitize($sectionMaster['bio_text'] ?? ''); ?></textarea>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Lead form &mdash; send to email</label>
                    <input type="email" name="lead_form_email" placeholder="<?php echo sanitize($employee['email'] ?? ''); ?>"
                           value="<?php echo sanitize($sectionMaster['lead_form_email'] ?? ''); ?>"
                           class="w-full px-4 py-2 bg-gray-50 border border-gray-200 rounded-lg focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                    <p class="text-xs text-gray-500 mt-1">Defaults to your account email if blank.</p>
                </div>

                <input type="hidden" name="section_order" value="<?php echo sanitize($sectionMaster['section_order'] ?? 'bio,services,gallery,testimonials,lead_form'); ?>">

                <button type="submit" class="px-6 py-2 btn-primary rounded-lg font-medium">Save Sections</button>
            </form>

            <div class="px-6 pb-6 border-t border-gray-100 pt-6">
                <h3 class="font-semibold text-gray-900 mb-3"><i class="fa-solid fa-list-check mr-1 text-gray-500"></i> Services</h3>
                <?php if (!empty($sectionServices)): ?>
                <div class="space-y-2 mb-4">
                    <?php foreach ($sectionServices as $svc): ?>
                    <div class="flex items-center gap-3 p-3 border border-gray-200 rounded-lg">
                        <i class="<?php echo sanitize($svc['icon']); ?> text-lg text-gray-500 w-6 text-center"></i>
                        <div class="flex-1 min-w-0">
                            <div class="text-sm font-medium text-gray-800"><?php echo sanitize($svc['title']); ?></div>
                            <?php if (!empty($svc['description'])): ?>
                            <div class="text-xs text-gray-500 truncate"><?php echo sanitize($svc['description']); ?></div>
                            <?php endif; ?>
                        </div>
                        <form method="post" onsubmit="return confirm('Remove this service?');">
                            <input type="hidden" name="action" value="delete_service">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
                            <input type="hidden" name="service_id" value="<?php echo sanitize($svc['id']); ?>">
                            <button class="text-red-500 text-sm"><i class="fa-solid fa-trash"></i></button>
                        </form>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <form method="post" class="grid md:grid-cols-4 gap-2">
                    <input type="hidden" name="action" value="add_service">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
                    <input type="text" name="service_icon" placeholder="fa-solid fa-star" class="px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg text-sm">
                    <input type="text" name="service_title" placeholder="Title" required class="px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg text-sm">
                    <input type="text" name="service_description" placeholder="Short description" class="px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg text-sm">
                    <button class="px-3 py-2 bg-gray-900 text-white rounded-lg text-sm">Add Service</button>
                </form>
                <p class="text-xs text-gray-500 mt-2">Icon uses <a href="https://fontawesome.com/icons" target="_blank" class="underline">Font Awesome</a> class names.</p>
            </div>

            <div class="px-6 pb-6 border-t border-gray-100 pt-6">
                <h3 class="font-semibold text-gray-900 mb-3"><i class="fa-solid fa-images mr-1 text-gray-500"></i> Gallery <span class="text-xs text-gray-500">(<?php echo count($sectionGallery); ?>/<?php echo CardSections::MAX_GALLERY_IMAGES; ?>)</span></h3>
                <?php if (!empty($sectionGallery)): ?>
                <div class="grid grid-cols-3 sm:grid-cols-4 md:grid-cols-6 gap-2 mb-4">
                    <?php foreach ($sectionGallery as $img): ?>
                    <div class="relative group">
                        <img src="<?php echo sanitize($img['file_path']); ?>" alt="" class="w-full aspect-square object-cover rounded-lg border border-gray-200">
                        <form method="post" class="absolute top-1 right-1 opacity-0 group-hover:opacity-100 transition" onsubmit="return confirm('Delete this photo?');">
                            <input type="hidden" name="action" value="delete_gallery">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
                            <input type="hidden" name="gallery_id" value="<?php echo sanitize($img['id']); ?>">
                            <button class="bg-red-500 text-white text-xs w-6 h-6 rounded-full"><i class="fa-solid fa-xmark"></i></button>
                        </form>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <form method="post" enctype="multipart/form-data" class="flex flex-wrap items-center gap-2">
                    <input type="hidden" name="action" value="upload_gallery">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
                    <input type="file" name="gallery_images[]" accept="image/jpeg,image/png,image/webp" multiple class="text-sm">
                    <button class="px-3 py-2 bg-gray-900 text-white rounded-lg text-sm">Upload</button>
                    <span class="text-xs text-gray-500">JPG / PNG / WebP, max 5 MB each.</span>
                </form>
            </div>

            <div class="px-6 pb-6 border-t border-gray-100 pt-6">
                <h3 class="font-semibold text-gray-900 mb-3 flex items-center gap-2"><i class="fa-solid fa-quote-right mr-1 text-gray-500"></i> Testimonials
                    <?php if (!empty($pendingTestimonials)): ?>
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs bg-yellow-100 text-yellow-800 border border-yellow-200"><?php echo count($pendingTestimonials); ?> pending review</span>
                    <?php endif; ?>
                </h3>

                <?php if (!empty($pendingTestimonials)): ?>
                <div class="mb-5 p-3 rounded-lg bg-yellow-50 border border-yellow-200">
                    <div class="text-xs uppercase tracking-wide text-yellow-800 font-semibold mb-2"><i class="fa-solid fa-clock mr-1"></i> Pending Review</div>
                    <div class="space-y-2">
                        <?php foreach ($pendingTestimonials as $t): ?>
                        <div class="flex items-start gap-3 p-3 bg-white border border-yellow-200 rounded-lg">
                            <?php if (!empty($t['photo_path'])): ?>
                            <img src="<?php echo sanitize($t['photo_path']); ?>" alt="" class="w-10 h-10 rounded-full object-cover">
                            <?php else: ?>
                            <div class="w-10 h-10 rounded-full bg-gray-200 flex items-center justify-center text-gray-500"><i class="fa-solid fa-user"></i></div>
                            <?php endif; ?>
                            <div class="flex-1 min-w-0">
                                <div class="text-sm font-medium text-gray-800 flex items-center gap-2">
                                    <?php echo sanitize($t['name']); ?>
                                    <?php if (!empty($t['submitted_by_visitor'])): ?>
                                    <span class="text-[10px] uppercase px-1.5 py-0.5 rounded bg-blue-100 text-blue-700">Visitor</span>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($t['visitor_email'])): ?>
                                <div class="text-xs text-gray-500"><?php echo sanitize($t['visitor_email']); ?></div>
                                <?php endif; ?>
                                <?php if (!empty($t['rating']) && (int)$t['rating'] > 0): ?>
                                <div class="text-xs text-yellow-600">
                                    <?php $r=(int)$t['rating']; for($i=1;$i<=5;$i++) echo $i<=$r?'&#9733;':'&#9734;'; ?>
                                </div>
                                <?php endif; ?>
                                <div class="text-xs text-gray-700 italic mt-1">&ldquo;<?php echo sanitize($t['quote']); ?>&rdquo;</div>
                                <div class="text-[11px] text-gray-400 mt-1">Submitted <?php echo sanitize($t['created_at']); ?></div>
                            </div>
                            <div class="flex flex-col gap-1">
                                <form method="post">
                                    <input type="hidden" name="action" value="approve_testimonial">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
                                    <input type="hidden" name="testimonial_id" value="<?php echo sanitize($t['id']); ?>">
                                    <button class="px-3 py-1 bg-green-600 hover:bg-green-700 text-white rounded text-xs"><i class="fa-solid fa-check mr-1"></i>Approve</button>
                                </form>
                                <form method="post">
                                    <input type="hidden" name="action" value="reject_testimonial">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
                                    <input type="hidden" name="testimonial_id" value="<?php echo sanitize($t['id']); ?>">
                                    <button class="px-3 py-1 bg-gray-200 hover:bg-gray-300 text-gray-800 rounded text-xs"><i class="fa-solid fa-xmark mr-1"></i>Reject</button>
                                </form>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!empty($sectionTestimonials)): ?>
                <div class="space-y-2 mb-4">
                    <?php foreach ($sectionTestimonials as $t): ?>
                    <div class="flex items-start gap-3 p-3 border border-gray-200 rounded-lg">
                        <?php if (!empty($t['photo_path'])): ?>
                        <img src="<?php echo sanitize($t['photo_path']); ?>" alt="" class="w-10 h-10 rounded-full object-cover">
                        <?php else: ?>
                        <div class="w-10 h-10 rounded-full bg-gray-200 flex items-center justify-center text-gray-500"><i class="fa-solid fa-user"></i></div>
                        <?php endif; ?>
                        <div class="flex-1 min-w-0">
                            <div class="text-sm font-medium text-gray-800 flex items-center gap-2">
                                <?php echo sanitize($t['name']); ?>
                                <span class="text-[10px] uppercase px-1.5 py-0.5 rounded bg-green-100 text-green-700">Approved</span>
                                <?php if (!empty($t['submitted_by_visitor'])): ?>
                                <span class="text-[10px] uppercase px-1.5 py-0.5 rounded bg-blue-100 text-blue-700">Visitor</span>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($t['rating']) && (int)$t['rating'] > 0): ?>
                            <div class="text-xs text-yellow-600">
                                <?php $r=(int)$t['rating']; for($i=1;$i<=5;$i++) echo $i<=$r?'&#9733;':'&#9734;'; ?>
                            </div>
                            <?php endif; ?>
                            <div class="text-xs text-gray-600 italic">&ldquo;<?php echo sanitize($t['quote']); ?>&rdquo;</div>
                        </div>
                        <form method="post" onsubmit="return confirm('Remove this testimonial?');">
                            <input type="hidden" name="action" value="delete_testimonial">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
                            <input type="hidden" name="testimonial_id" value="<?php echo sanitize($t['id']); ?>">
                            <button class="text-red-500 text-sm"><i class="fa-solid fa-trash"></i></button>
                        </form>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <form method="post" enctype="multipart/form-data" class="grid md:grid-cols-4 gap-2">
                    <input type="hidden" name="action" value="add_testimonial">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
                    <input type="text" name="testimonial_name" placeholder="Client name" required class="px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg text-sm">
                    <input type="text" name="testimonial_quote" placeholder="What they said" required class="px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg text-sm md:col-span-2">
                    <input type="file" name="testimonial_photo" accept="image/jpeg,image/png,image/webp" class="text-sm">
                    <button class="px-3 py-2 bg-gray-900 text-white rounded-lg text-sm md:col-span-4 md:justify-self-start">Add Testimonial</button>
                </form>

                <?php if (!empty($rejectedTestimonials)): ?>
                <details class="mt-5">
                    <summary class="cursor-pointer text-xs text-gray-500 hover:text-gray-800"><i class="fa-solid fa-eye-slash mr-1"></i>Show <?php echo count($rejectedTestimonials); ?> rejected</summary>
                    <div class="space-y-2 mt-2">
                        <?php foreach ($rejectedTestimonials as $t): ?>
                        <div class="flex items-start gap-3 p-3 border border-gray-200 rounded-lg bg-gray-50 opacity-75">
                            <?php if (!empty($t['photo_path'])): ?>
                            <img src="<?php echo sanitize($t['photo_path']); ?>" alt="" class="w-10 h-10 rounded-full object-cover grayscale">
                            <?php else: ?>
                            <div class="w-10 h-10 rounded-full bg-gray-200 flex items-center justify-center text-gray-400"><i class="fa-solid fa-user"></i></div>
                            <?php endif; ?>
                            <div class="flex-1 min-w-0">
                                <div class="text-sm font-medium text-gray-700 flex items-center gap-2">
                                    <?php echo sanitize($t['name']); ?>
                                    <span class="text-[10px] uppercase px-1.5 py-0.5 rounded bg-red-100 text-red-700">Rejected</span>
                                </div>
                                <div class="text-xs text-gray-500 italic">&ldquo;<?php echo sanitize($t['quote']); ?>&rdquo;</div>
                            </div>
                            <div class="flex flex-col gap-1">
                                <form method="post">
                                    <input type="hidden" name="action" value="approve_testimonial">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
                                    <input type="hidden" name="testimonial_id" value="<?php echo sanitize($t['id']); ?>">
                                    <button class="px-2 py-1 text-xs text-green-700 hover:underline">Restore</button>
                                </form>
                                <form method="post" onsubmit="return confirm('Remove permanently?');">
                                    <input type="hidden" name="action" value="delete_testimonial">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
                                    <input type="hidden" name="testimonial_id" value="<?php echo sanitize($t['id']); ?>">
                                    <button class="text-red-400 text-xs"><i class="fa-solid fa-trash"></i></button>
                                </form>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </details>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
