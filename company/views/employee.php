<?php
/**
 * Employee View - Profile editor, card preview, order history
 *
 * UI: Tabbed layout (Basic / Card / Booking / Moderation)
 * Deep-link: ?tab=card pre-selects the tab via Alpine x-init.
 * All forms, field names, and submit URLs are unchanged from the pre-tab version.
 */
if (!defined('INCLUDES_DIR')) { http_response_code(404); exit; }
require_once INCLUDES_DIR . '/Currency.php';
require_once INCLUDES_DIR . '/CardSections.php';
require_once INCLUDES_DIR . '/Appointments.php';
require_once INCLUDES_DIR . '/EmployeeSocials.php';
require_once INCLUDES_DIR . '/CardifyConvention.php';

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
    header('Location: ' . getTenantUrl($companySlug));
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
    'add_offer', 'delete_offer',
    'add_product', 'delete_product', 'reorder_products',
    'approve_testimonial', 'reject_testimonial',
    'update_appointments',
    'update_socials',
    'update_hours',
    'add_faq', 'delete_faq', 'reorder_faqs',
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
                    'bio_text_ar' => $_POST['bio_text_ar'] ?? '',
                    'services_enabled' => !empty($_POST['services_enabled']),
                    'gallery_enabled' => !empty($_POST['gallery_enabled']),
                    'testimonials_enabled' => !empty($_POST['testimonials_enabled']),
                    'lead_form_enabled' => !empty($_POST['lead_form_enabled']),
                    'lead_form_email' => $_POST['lead_form_email'] ?? '',
                    'offers_enabled' => !empty($_POST['offers_enabled']),
                    'video_enabled' => !empty($_POST['video_enabled']),
                    'video_url' => $_POST['video_url'] ?? '',
                    'video_title' => $_POST['video_title'] ?? '',
                    'location_enabled' => !empty($_POST['location_enabled']),
                    'location_address' => $_POST['location_address'] ?? '',
                    'location_label' => $_POST['location_label'] ?? '',
                    'products_enabled' => !empty($_POST['products_enabled']),
                    'hours_enabled' => !empty($_POST['hours_enabled']),
                    'hours_timezone' => $_POST['hours_timezone'] ?? 'Asia/Muscat',
                    'faq_enabled' => !empty($_POST['faq_enabled']),
                    'section_order' => $_POST['section_order'] ?? '',
                ]);
                $message = 'Public card sections saved.';
            } elseif ($action === 'add_service') {
                $newServiceId = CardSections::addService(
                    $employeeId,
                    $_POST['service_icon'] ?? 'fa-solid fa-star',
                    $_POST['service_title'] ?? '',
                    $_POST['service_description'] ?? ''
                );
                if ($newServiceId) {
                    $arTitle = trim((string)($_POST['service_title_ar'] ?? ''));
                    $arDesc  = trim((string)($_POST['service_description_ar'] ?? ''));
                    if ($arTitle !== '' || $arDesc !== '') {
                        CardSections::upsertServiceTranslation($newServiceId, 'ar', $arTitle, $arDesc);
                    }
                }
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
                if ($tid) {
                    $arName  = trim((string)($_POST['testimonial_name_ar'] ?? ''));
                    $arQuote = trim((string)($_POST['testimonial_quote_ar'] ?? ''));
                    if ($arName !== '' || $arQuote !== '') {
                        CardSections::upsertTestimonialTranslation($tid, 'ar', $arName, $arQuote);
                    }
                }
                if ($tid && empty($message)) $message = 'Testimonial added.';
                if (!$tid) {
                    $message = 'Name and quote are required.';
                    $messageType = 'error';
                }
            } elseif ($action === 'delete_testimonial') {
                CardSections::deleteTestimonial($employeeId, $_POST['testimonial_id'] ?? '');
                $message = 'Testimonial removed.';
            } elseif ($action === 'add_offer') {
                $oid = CardSections::addOffer(
                    $employeeId,
                    $company['id'],
                    $_POST['offer_title'] ?? '',
                    $_POST['offer_description'] ?? '',
                    $_POST['offer_discount_label'] ?? '',
                    $_POST['offer_valid_until'] ?? '',
                    $_POST['offer_badge_color'] ?? ''
                );
                if ($oid) {
                    $message = 'Offer added.';
                } else {
                    $message = 'Offer title is required.';
                    $messageType = 'error';
                }
            } elseif ($action === 'delete_offer') {
                CardSections::deleteOffer($employeeId, $_POST['offer_id'] ?? '');
                $message = 'Offer removed.';
            } elseif ($action === 'add_product') {
                $imagePath = null;
                if (!empty($_FILES['product_image']) && !empty($_FILES['product_image']['name'])) {
                    $err = null;
                    $imagePath = CardSections::handleImageUpload($_FILES['product_image'], $employeeId, 'products', $err);
                    if ($err) {
                        $message = 'Product image upload: ' . $err;
                        $messageType = 'error';
                    }
                }
                $pid = CardSections::addProduct(
                    $employeeId,
                    $company['id'],
                    $_POST['product_title'] ?? '',
                    $_POST['product_description'] ?? '',
                    $_POST['product_price'] ?? 0,
                    $imagePath,
                    $_POST['product_whatsapp_message'] ?? ''
                );
                if ($pid && empty($message)) {
                    $message = 'Product added.';
                } elseif (!$pid) {
                    $message = 'Product title is required.';
                    $messageType = 'error';
                }
            } elseif ($action === 'delete_product') {
                CardSections::deleteProduct($employeeId, $_POST['product_id'] ?? '');
                $message = 'Product removed.';
            } elseif ($action === 'reorder_products') {
                $order = $_POST['product_order'] ?? [];
                if (is_string($order)) {
                    $order = array_filter(array_map('trim', explode(',', $order)));
                }
                CardSections::reorderProducts($employeeId, is_array($order) ? $order : []);
                $message = 'Product order saved.';
            } elseif ($action === 'approve_testimonial') {
                $ok = CardSections::setTestimonialStatus($employeeId, $_POST['testimonial_id'] ?? '', 'approved');
                $message = $ok ? 'Testimonial approved.' : 'Could not approve.';
                $messageType = $ok ? 'success' : 'error';
            } elseif ($action === 'reject_testimonial') {
                $ok = CardSections::setTestimonialStatus($employeeId, $_POST['testimonial_id'] ?? '', 'rejected');
                $message = $ok ? 'Testimonial rejected.' : 'Could not reject.';
                $messageType = $ok ? 'success' : 'error';
            } elseif ($action === 'update_appointments') {
                Appointments::saveSettings($employeeId, $company['id'], [
                    'enabled' => !empty($_POST['appt_enabled']),
                    'duration_minutes' => $_POST['appt_duration'] ?? 30,
                    'buffer_minutes' => $_POST['appt_buffer'] ?? 0,
                    'timezone' => $_POST['appt_timezone'] ?? 'Asia/Muscat',
                    'available_days' => $_POST['appt_days'] ?? [],
                    'available_start' => $_POST['appt_start'] ?? '09:00',
                    'available_end' => $_POST['appt_end'] ?? '17:00',
                    'max_advance_days' => $_POST['appt_max_advance'] ?? 30,
                    'notification_email' => $_POST['appt_email'] ?? '',
                ]);
                $message = 'Appointment settings saved.';
            } elseif ($action === 'update_socials') {
                $platforms = $_POST['social_platform'] ?? [];
                $urls      = $_POST['social_url'] ?? [];
                $items = [];
                $count = is_array($platforms) ? count($platforms) : 0;
                for ($i = 0; $i < $count; $i++) {
                    $items[] = [
                        'platform' => $platforms[$i] ?? '',
                        'url'      => $urls[$i] ?? '',
                    ];
                }
                $saved = EmployeeSocials::replaceAll($employeeId, $company['id'], $items);
                $message = 'Social links saved (' . (int)$saved . ').';
            } elseif ($action === 'update_hours') {
                $days   = $_POST['hours_day']   ?? [];
                $closed = $_POST['hours_closed']?? [];
                $open   = $_POST['hours_open']  ?? [];
                $close  = $_POST['hours_close'] ?? [];
                $bs     = $_POST['hours_break_start'] ?? [];
                $be     = $_POST['hours_break_end']   ?? [];
                $schedule = [];
                foreach (CardSections::DAY_KEYS as $d) {
                    $schedule[$d] = [
                        'is_closed'   => !empty($closed[$d]),
                        'open_time'   => $open[$d]  ?? null,
                        'close_time'  => $close[$d] ?? null,
                        'break_start' => $bs[$d]    ?? null,
                        'break_end'   => $be[$d]    ?? null,
                    ];
                }
                CardSections::saveBusinessHours($employeeId, $schedule);
                // Also persist the timezone + toggle with the master row.
                $existing = CardSections::loadMaster($employeeId, $company['id']);
                CardSections::saveMaster($employeeId, $company['id'], array_merge($existing, [
                    'hours_enabled'  => !empty($_POST['hours_enabled']),
                    'hours_timezone' => $_POST['hours_timezone'] ?? 'Asia/Muscat',
                ]));
                $message = 'Business hours saved.';
            } elseif ($action === 'add_faq') {
                $fid = CardSections::addFaq(
                    $employeeId,
                    $company['id'],
                    $_POST['faq_question'] ?? '',
                    $_POST['faq_answer'] ?? '',
                    $_POST['faq_question_ar'] ?? '',
                    $_POST['faq_answer_ar'] ?? ''
                );
                if ($fid) {
                    $message = 'FAQ added.';
                } else {
                    $message = 'Question and answer are required.';
                    $messageType = 'error';
                }
            } elseif ($action === 'delete_faq') {
                CardSections::deleteFaq($employeeId, $_POST['faq_id'] ?? '');
                $message = 'FAQ removed.';
            } elseif ($action === 'reorder_faqs') {
                $orderRaw = (string)($_POST['faq_order'] ?? '');
                $ids = array_values(array_filter(array_map('trim', explode(',', $orderRaw))));
                CardSections::reorderFaqs($employeeId, $ids);
                $message = 'FAQ order updated.';
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
$sectionTestimonials = CardSections::loadTestimonials($employeeId);
$sectionOffers = CardSections::loadOffers($employeeId, false);
$sectionProducts = CardSections::loadProducts($employeeId, false);
$pendingTestimonials = CardSections::loadPendingTestimonials($employeeId);
$socialLinks = EmployeeSocials::loadForEmployee($employeeId);
$sectionFaqs = CardSections::loadFaqs($employeeId);
$rejectedTestimonials = CardSections::loadRejectedTestimonials($employeeId);
$apptSettings = Appointments::loadSettings($employeeId, $company['id']);
$apptDays = explode(',', $apptSettings['available_days'] ?? '');
$businessHours = CardSections::loadBusinessHours($employeeId);
$timezoneList = timezone_identifiers_list();

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
$pendingCount = is_array($pendingTestimonials) ? count($pendingTestimonials) : 0;

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
    [x-cloak] { display: none !important; }
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

    <main class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-8"
          x-data="{
              tab: 'basic',
              tabs: ['basic','card','booking','moderation'],
              setTab(t) {
                  if (!this.tabs.includes(t)) return;
                  this.tab = t;
                  try {
                      const u = new URL(window.location);
                      u.searchParams.set('tab', t);
                      window.history.replaceState({}, '', u);
                  } catch(e) {}
              }
          }"
          x-init="
              try {
                  const p = new URLSearchParams(window.location.search).get('tab');
                  if (p && tabs.includes(p)) tab = p;
              } catch(e) {}
          ">
        <?php if ($message): ?>
        <div class="mb-6 p-4 rounded-xl flex items-center gap-3 <?php echo $messageType === 'success' ? 'bg-green-50 border border-green-200 text-green-700' : 'bg-red-50 border border-red-200 text-red-700'; ?>">
            <i class="<?php echo $messageType === 'success' ? 'fa-solid fa-circle-check' : 'fa-solid fa-circle-exclamation'; ?>"></i>
            <?php echo sanitize($message); ?>
        </div>
        <?php endif; ?>

        <!-- Tab bar -->
        <nav class="mb-6 border-b border-gray-200" role="tablist" aria-label="Employee editor sections">
            <div class="flex flex-wrap gap-1 -mb-px">
                <button type="button"
                        role="tab"
                        :aria-selected="tab==='basic'"
                        :tabindex="tab==='basic' ? 0 : -1"
                        @click="setTab('basic')"
                        :class="tab==='basic'
                            ? 'border-blue-600 text-blue-700 bg-white'
                            : 'border-transparent text-gray-600 hover:text-gray-900 hover:bg-gray-100'"
                        class="px-4 py-2.5 text-sm font-medium border-b-2 rounded-t-lg transition focus:outline-none focus:ring-2 focus:ring-blue-500/30">
                    <i class="fa-solid fa-user mr-1.5"></i>Basic
                </button>
                <button type="button"
                        role="tab"
                        :aria-selected="tab==='card'"
                        :tabindex="tab==='card' ? 0 : -1"
                        @click="setTab('card')"
                        :class="tab==='card'
                            ? 'border-blue-600 text-blue-700 bg-white'
                            : 'border-transparent text-gray-600 hover:text-gray-900 hover:bg-gray-100'"
                        class="px-4 py-2.5 text-sm font-medium border-b-2 rounded-t-lg transition focus:outline-none focus:ring-2 focus:ring-blue-500/30">
                    <i class="fa-solid fa-id-card mr-1.5"></i>Card
                </button>
                <button type="button"
                        role="tab"
                        :aria-selected="tab==='booking'"
                        :tabindex="tab==='booking' ? 0 : -1"
                        @click="setTab('booking')"
                        :class="tab==='booking'
                            ? 'border-blue-600 text-blue-700 bg-white'
                            : 'border-transparent text-gray-600 hover:text-gray-900 hover:bg-gray-100'"
                        class="px-4 py-2.5 text-sm font-medium border-b-2 rounded-t-lg transition focus:outline-none focus:ring-2 focus:ring-blue-500/30">
                    <i class="fa-solid fa-calendar-check mr-1.5"></i>Booking
                </button>
                <button type="button"
                        role="tab"
                        :aria-selected="tab==='moderation'"
                        :tabindex="tab==='moderation' ? 0 : -1"
                        @click="setTab('moderation')"
                        :class="tab==='moderation'
                            ? 'border-blue-600 text-blue-700 bg-white'
                            : 'border-transparent text-gray-600 hover:text-gray-900 hover:bg-gray-100'"
                        class="px-4 py-2.5 text-sm font-medium border-b-2 rounded-t-lg transition focus:outline-none focus:ring-2 focus:ring-blue-500/30 relative">
                    <i class="fa-solid fa-gavel mr-1.5"></i>Moderation
                    <?php if ($pendingCount > 0): ?>
                    <span class="ml-1 inline-flex items-center px-1.5 py-0.5 rounded-full text-[10px] font-semibold bg-yellow-100 text-yellow-800 border border-yellow-200"><?php echo $pendingCount; ?></span>
                    <?php endif; ?>
                </button>
            </div>
        </nav>

        <!-- BASIC TAB -->
        <section role="tabpanel" aria-labelledby="tab-basic" x-show="tab==='basic'" x-cloak>
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
        </section>
        <!-- /BASIC TAB -->

        <!-- CARD TAB -->
        <section role="tabpanel" aria-labelledby="tab-card" x-show="tab==='card'" x-cloak>
        <!-- Public Card Sections -->
        <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
            <div class="p-4 border-b border-gray-100 flex items-center justify-between">
                <div>
                    <h2 class="font-semibold text-gray-900">Public Card Sections</h2>
                    <p class="text-sm text-gray-500">Appears below your contact buttons when someone scans your QR.</p>
                </div>
                <?php if (!empty($company['slug']) && !empty($employee['id'])): ?>
                <a href="<?php echo htmlspecialchars(CardifyConvention::employeeShareUrl($company['slug'], $employee)); ?>" target="_blank" class="text-sm text-blue-600 hover:underline">Preview &rarr;</a>
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
                    <label class="flex items-center gap-3 p-3 rounded-lg bg-gray-50 border border-gray-200">
                        <input type="checkbox" name="lead_form_enabled" <?php echo !empty($sectionMaster['lead_form_enabled']) ? 'checked' : ''; ?> class="h-4 w-4">
                        <span class="text-sm font-medium text-gray-800"><i class="fa-solid fa-envelope-open-text mr-1"></i> Lead Form</span>
                    </label>
                    <label class="flex items-center gap-3 p-3 rounded-lg bg-gray-50 border border-gray-200">
                        <input type="checkbox" name="offers_enabled" <?php echo !empty($sectionMaster['offers_enabled']) ? 'checked' : ''; ?> class="h-4 w-4">
                        <span class="text-sm font-medium text-gray-800"><i class="fa-solid fa-tags mr-1"></i> Offers</span>
                    </label>
                    <label class="flex items-center gap-3 p-3 rounded-lg bg-gray-50 border border-gray-200">
                        <input type="checkbox" name="video_enabled" <?php echo !empty($sectionMaster['video_enabled']) ? 'checked' : ''; ?> class="h-4 w-4">
                        <span class="text-sm font-medium text-gray-800"><i class="fa-solid fa-video mr-1"></i> Video</span>
                    </label>
                    <label class="flex items-center gap-3 p-3 rounded-lg bg-gray-50 border border-gray-200">
                        <input type="checkbox" name="location_enabled" <?php echo !empty($sectionMaster['location_enabled']) ? 'checked' : ''; ?> class="h-4 w-4">
                        <span class="text-sm font-medium text-gray-800"><i class="fa-solid fa-location-dot mr-1"></i> Location</span>
                    </label>
                    <label class="flex items-center gap-3 p-3 rounded-lg bg-gray-50 border border-gray-200">
                        <input type="checkbox" name="products_enabled" <?php echo !empty($sectionMaster['products_enabled']) ? 'checked' : ''; ?> class="h-4 w-4">
                        <span class="text-sm font-medium text-gray-800"><i class="fa-solid fa-bag-shopping mr-1"></i> Products</span>
                    </label>
                    <label class="flex items-center gap-3 p-3 rounded-lg bg-gray-50 border border-gray-200">
                        <input type="checkbox" name="faq_enabled" <?php echo !empty($sectionMaster['faq_enabled']) ? 'checked' : ''; ?> class="h-4 w-4">
                        <span class="text-sm font-medium text-gray-800"><i class="fa-solid fa-circle-question mr-1"></i> FAQ</span>
                    </label>
                    <label class="flex items-center gap-3 p-3 rounded-lg bg-gray-50 border border-gray-200">
                        <input type="checkbox" name="hours_enabled" <?php echo !empty($sectionMaster['hours_enabled']) ? 'checked' : ''; ?> class="h-4 w-4">
                        <span class="text-sm font-medium text-gray-800"><i class="fa-solid fa-clock mr-1"></i> Business Hours</span>
                    </label>
                </div>

                <div class="grid md:grid-cols-3 gap-3">
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Video URL</label>
                        <input type="url" name="video_url"
                               value="<?php echo sanitize($sectionMaster['video_url'] ?? ''); ?>"
                               placeholder="https://youtube.com/watch?v=... or vimeo.com/... or .mp4 link"
                               class="w-full px-4 py-2 bg-gray-50 border border-gray-200 rounded-lg focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                        <p class="text-xs text-gray-500 mt-1">Supports YouTube, Vimeo, or direct mp4/webm/mov.</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Video title <span class="text-xs text-gray-400">, optional</span></label>
                        <input type="text" name="video_title" maxlength="200"
                               value="<?php echo sanitize($sectionMaster['video_title'] ?? ''); ?>"
                               placeholder="Watch our intro"
                               class="w-full px-4 py-2 bg-gray-50 border border-gray-200 rounded-lg focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                    </div>
                </div>

                <div class="grid md:grid-cols-3 gap-3">
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-1"><i class="fa-solid fa-location-dot mr-1 text-gray-500"></i> Business address</label>
                        <input type="text" name="location_address" maxlength="512"
                               placeholder="e.g. Way 4509, Al Khuwair, Muscat, or paste a Google Maps link"
                               value="<?php echo sanitize($sectionMaster['location_address'] ?? ''); ?>"
                               class="w-full px-4 py-2 bg-gray-50 border border-gray-200 rounded-lg focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                        <p class="text-xs text-gray-500 mt-1">Shown as an embedded map with a "Get Directions" button. No API key required.</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Location label <span class="text-xs text-gray-400">, optional</span></label>
                        <input type="text" name="location_label" maxlength="120"
                               placeholder="e.g. Head Office"
                               value="<?php echo sanitize($sectionMaster['location_label'] ?? ''); ?>"
                               class="w-full px-4 py-2 bg-gray-50 border border-gray-200 rounded-lg focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                    </div>
                </div>

                <div class="grid md:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Bio (English) <span class="text-xs text-gray-400">, primary</span></label>
                        <textarea name="bio_text" rows="4" placeholder="A short paragraph about you. Use **text** for bold."
                                  class="w-full px-4 py-2 bg-gray-50 border border-gray-200 rounded-lg focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20"><?php echo sanitize($sectionMaster['bio_text'] ?? ''); ?></textarea>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">السيرة (العربية) <span class="text-xs text-gray-400">, optional</span></label>
                        <textarea name="bio_text_ar" rows="4" dir="rtl" placeholder="نبذة قصيرة عنك."
                                  class="w-full px-4 py-2 bg-gray-50 border border-gray-200 rounded-lg focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20"><?php echo sanitize($sectionMaster['bio_text_ar'] ?? ''); ?></textarea>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Lead form &mdash; send to email</label>
                    <input type="email" name="lead_form_email" placeholder="<?php echo sanitize($employee['email'] ?? ''); ?>"
                           value="<?php echo sanitize($sectionMaster['lead_form_email'] ?? ''); ?>"
                           class="w-full px-4 py-2 bg-gray-50 border border-gray-200 rounded-lg focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                    <p class="text-xs text-gray-500 mt-1">Defaults to your account email if blank.</p>
                </div>

                <input type="hidden" name="section_order" value="<?php echo sanitize($sectionMaster['section_order'] ?? 'bio,offers,services,products,gallery,video,testimonials,faq,lead_form,location,hours'); ?>">
                <input type="hidden" name="hours_timezone_shadow" value="<?php echo sanitize($sectionMaster['hours_timezone'] ?? 'Asia/Muscat'); ?>">

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
                <form method="post" class="space-y-2">
                    <input type="hidden" name="action" value="add_service">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
                    <div class="grid md:grid-cols-4 gap-2">
                        <input type="text" name="service_icon" placeholder="fa-solid fa-star" class="px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg text-sm">
                        <input type="text" name="service_title" placeholder="Title (EN)" required class="px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg text-sm">
                        <input type="text" name="service_description" placeholder="Description (EN)" class="px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg text-sm md:col-span-2">
                    </div>
                    <div class="grid md:grid-cols-4 gap-2">
                        <div class="md:col-span-1 text-xs text-gray-500 self-center">Arabic (optional)</div>
                        <input type="text" name="service_title_ar" dir="rtl" placeholder="العنوان" class="px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg text-sm">
                        <input type="text" name="service_description_ar" dir="rtl" placeholder="الوصف" class="px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg text-sm md:col-span-2">
                    </div>
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
                    <?php if ($pendingCount > 0): ?>
                    <a href="?tab=moderation" @click.prevent="setTab('moderation')" class="inline-flex items-center px-2 py-0.5 rounded-full text-xs bg-yellow-100 text-yellow-800 border border-yellow-200 hover:bg-yellow-200"><?php echo $pendingCount; ?> pending review &rarr;</a>
                    <?php endif; ?>
                </h3>

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
                <form method="post" enctype="multipart/form-data" class="space-y-2">
                    <input type="hidden" name="action" value="add_testimonial">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
                    <div class="grid md:grid-cols-4 gap-2">
                        <input type="text" name="testimonial_name" placeholder="Client name (EN)" required class="px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg text-sm">
                        <input type="text" name="testimonial_quote" placeholder="What they said (EN)" required class="px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg text-sm md:col-span-2">
                        <input type="file" name="testimonial_photo" accept="image/jpeg,image/png,image/webp" class="text-sm">
                    </div>
                    <div class="grid md:grid-cols-4 gap-2">
                        <div class="md:col-span-1 text-xs text-gray-500 self-center">Arabic (optional)</div>
                        <input type="text" name="testimonial_name_ar" dir="rtl" placeholder="اسم العميل" class="px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg text-sm">
                        <input type="text" name="testimonial_quote_ar" dir="rtl" placeholder="الاقتباس" class="px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg text-sm md:col-span-2">
                    </div>
                    <button class="px-3 py-2 bg-gray-900 text-white rounded-lg text-sm">Add Testimonial</button>
                </form>
            </div>

            <div class="px-6 pb-6 border-t border-gray-100 pt-6">
                <h3 class="font-semibold text-gray-900 mb-3"><i class="fa-solid fa-tags mr-1 text-gray-500"></i> Offers</h3>
                <p class="text-xs text-gray-500 mb-3">Time-limited offers for cardholders to claim. Each Redeem click increments the counter and is logged in analytics.</p>
                <?php if (!empty($sectionOffers)): ?>
                <div class="space-y-2 mb-4">
                    <?php foreach ($sectionOffers as $offer): ?>
                    <?php
                        $isExpired = !empty($offer['valid_until']) && strtotime($offer['valid_until']) < strtotime(date('Y-m-d'));
                    ?>
                    <div class="flex items-start gap-3 p-3 border border-gray-200 rounded-lg <?php echo $isExpired ? 'bg-gray-50' : ''; ?>">
                        <span class="inline-flex items-center px-2 py-1 rounded text-xs font-bold text-white whitespace-nowrap"
                              style="background: <?php echo sanitize($offer['badge_color'] ?: '#009bc1'); ?>;">
                            <?php echo sanitize($offer['discount_label'] ?: 'OFFER'); ?>
                        </span>
                        <div class="flex-1 min-w-0">
                            <div class="text-sm font-medium text-gray-800">
                                <?php echo sanitize($offer['title']); ?>
                                <?php if ($isExpired): ?>
                                    <span class="ml-1 text-xs text-red-600 font-normal">(expired)</span>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($offer['description'])): ?>
                            <div class="text-xs text-gray-500"><?php echo sanitize($offer['description']); ?></div>
                            <?php endif; ?>
                            <div class="text-xs text-gray-400 mt-1">
                                <?php if (!empty($offer['valid_until'])): ?>
                                    Valid until <?php echo sanitize($offer['valid_until']); ?> ·
                                <?php endif; ?>
                                Redeemed <?php echo (int)$offer['redemption_count']; ?>×
                            </div>
                        </div>
                        <form method="post" onsubmit="return confirm('Remove this offer?');">
                            <input type="hidden" name="action" value="delete_offer">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
                            <input type="hidden" name="offer_id" value="<?php echo sanitize($offer['id']); ?>">
                            <button class="text-red-500 text-sm"><i class="fa-solid fa-trash"></i></button>
                        </form>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <form method="post" class="space-y-2">
                    <input type="hidden" name="action" value="add_offer">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
                    <div class="grid md:grid-cols-2 gap-2">
                        <input type="text" name="offer_title" placeholder="Offer title (e.g. Welcome discount)" required maxlength="255"
                               class="px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg text-sm">
                        <input type="text" name="offer_discount_label" placeholder="Discount (e.g. 10% off, BOGO, Free delivery)" maxlength="64"
                               class="px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg text-sm">
                    </div>
                    <input type="text" name="offer_description" placeholder="Short description shown on card" maxlength="2000"
                           class="w-full px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg text-sm">
                    <div class="grid md:grid-cols-3 gap-2 items-center">
                        <label class="text-xs text-gray-600 flex flex-col gap-1">
                            Valid until (optional)
                            <input type="date" name="offer_valid_until" class="px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg text-sm">
                        </label>
                        <div class="md:col-span-2">
                            <div class="text-xs text-gray-600 mb-1">Badge color</div>
                            <div class="flex flex-wrap gap-2">
                                <?php foreach (CardSections::OFFER_PALETTE as $idx => $color): ?>
                                <label class="cursor-pointer">
                                    <input type="radio" name="offer_badge_color" value="<?php echo sanitize($color); ?>" <?php echo $idx === 0 ? 'checked' : ''; ?> class="sr-only peer">
                                    <span class="block w-7 h-7 rounded-full border-2 border-white ring-2 ring-transparent peer-checked:ring-gray-900"
                                          style="background: <?php echo sanitize($color); ?>;"></span>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <button class="px-3 py-2 bg-gray-900 text-white rounded-lg text-sm">Add Offer</button>
                </form>
            </div>

            <div class="px-6 pb-6 border-t border-gray-100 pt-6">
                <h3 class="font-semibold text-gray-900 mb-3"><i class="fa-solid fa-bag-shopping mr-1 text-gray-500"></i> Products</h3>
                <p class="text-xs text-gray-500 mb-3">Showcase a small catalog on your card. Each row becomes a tile with image, title, price, and an "Order via WhatsApp" button. Drag to reorder.</p>

                <?php if (!empty($sectionProducts)): ?>
                <form method="post" id="productReorderForm"
                      x-data="productReorder(<?php echo htmlspecialchars(json_encode(array_map(function($p){ return ['id'=>$p['id'],'title'=>$p['title'],'price'=>$p['price'],'image'=>$p['image_path'] ?? '']; }, $sectionProducts)), ENT_QUOTES, 'UTF-8'); ?>)"
                      @submit="syncOrder($event)">
                    <input type="hidden" name="action" value="reorder_products">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
                    <input type="hidden" name="product_order" :value="rows.map(r=>r.id).join(',')">

                    <div class="space-y-2 mb-3">
                        <template x-for="(row, idx) in rows" :key="row.id">
                            <div class="flex items-center gap-3 p-3 bg-gray-50 border border-gray-200 rounded-lg cursor-move"
                                 draggable="true"
                                 @dragstart="dragStart($event, idx)"
                                 @dragover.prevent
                                 @drop="drop($event, idx)">
                                <i class="fa-solid fa-grip-vertical text-gray-400"></i>
                                <template x-if="row.image">
                                    <img :src="row.image" alt="" class="w-12 h-12 rounded-lg object-cover border border-gray-200">
                                </template>
                                <template x-if="!row.image">
                                    <div class="w-12 h-12 rounded-lg bg-gray-200 flex items-center justify-center text-gray-500"><i class="fa-solid fa-image"></i></div>
                                </template>
                                <div class="flex-1 min-w-0">
                                    <div class="text-sm font-medium text-gray-800 truncate" x-text="row.title"></div>
                                    <div class="text-xs text-gray-500" x-text="'OMR ' + Number(row.price).toFixed(3)"></div>
                                </div>
                                <form method="post" onsubmit="return confirm('Remove this product?');">
                                    <input type="hidden" name="action" value="delete_product">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
                                    <input type="hidden" name="product_id" :value="row.id">
                                    <button type="submit" class="text-red-500 text-sm" title="Delete"><i class="fa-solid fa-trash"></i></button>
                                </form>
                            </div>
                        </template>
                    </div>
                    <button type="submit" class="px-3 py-2 bg-white border border-gray-300 text-gray-700 rounded-lg text-sm hover:bg-gray-50">Save order</button>
                </form>
                <?php endif; ?>

                <form method="post" enctype="multipart/form-data" class="mt-4 space-y-2">
                    <input type="hidden" name="action" value="add_product">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
                    <div class="grid md:grid-cols-2 gap-2">
                        <input type="text" name="product_title" placeholder="Product title (e.g. Custom Ceramic Mug)" required maxlength="255"
                               class="px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg text-sm">
                        <input type="number" name="product_price" step="0.001" min="0" placeholder="Price (OMR, 3 decimals)" required
                               class="px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg text-sm">
                    </div>
                    <textarea name="product_description" rows="2" placeholder="Short description (optional)" maxlength="4000"
                              class="w-full px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg text-sm"></textarea>
                    <div class="grid md:grid-cols-2 gap-2">
                        <label class="flex flex-col gap-1 text-xs text-gray-600">
                            Product image
                            <input type="file" name="product_image" accept="image/jpeg,image/png,image/webp" class="text-sm">
                        </label>
                        <input type="text" name="product_whatsapp_message" maxlength="500"
                               placeholder="WA message template (use {title}, {price})"
                               class="px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg text-sm">
                    </div>
                    <p class="text-[11px] text-gray-500">Defaults to "I'd like to order {title} for {price}" when the template is blank.</p>
                    <button class="px-3 py-2 bg-gray-900 text-white rounded-lg text-sm">Add Product</button>
                </form>
            </div>

            <div class="px-6 pb-6 border-t border-gray-100 pt-6">
                <h3 class="font-semibold text-gray-900 mb-3"><i class="fa-solid fa-circle-question mr-1 text-gray-500"></i> FAQ</h3>
                <p class="text-xs text-gray-500 mb-3">Expandable Q&amp;A list shown on your public card. Visitors tap a question to reveal the answer.</p>

                <?php if (!empty($sectionFaqs)): ?>
                <form method="post" class="mb-4"
                      x-data="{
                        ids: <?php echo htmlspecialchars(json_encode(array_map(function($f){ return $f['id']; }, $sectionFaqs)), ENT_QUOTES, 'UTF-8'); ?>,
                        dragFrom: null,
                        dragStart(i){ this.dragFrom = i; },
                        dropOn(i){
                            if (this.dragFrom === null || this.dragFrom === i) return;
                            const moved = this.ids.splice(this.dragFrom,1)[0];
                            this.ids.splice(i,0,moved);
                            this.dragFrom = null;
                            document.getElementById('faqOrderHidden').value = this.ids.join(',');
                            this.$root.querySelector('button[type=submit]').disabled = false;
                            // Visually reorder the DOM rows
                            const list = this.$refs.list;
                            this.ids.forEach(id => {
                                const el = list.querySelector('[data-faq-id=\"'+id+'\"]');
                                if (el) list.appendChild(el);
                            });
                        }
                      }">
                    <input type="hidden" name="action" value="reorder_faqs">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
                    <input type="hidden" id="faqOrderHidden" name="faq_order" value="<?php echo sanitize(implode(',', array_map(function($f){ return $f['id']; }, $sectionFaqs))); ?>">

                    <div class="space-y-2" x-ref="list">
                        <?php foreach ($sectionFaqs as $i => $faq): ?>
                        <div class="flex items-start gap-3 p-3 border border-gray-200 rounded-lg bg-white"
                             data-faq-id="<?php echo sanitize($faq['id']); ?>"
                             draggable="true"
                             @dragstart="dragStart(<?php echo $i; ?>)"
                             @dragover.prevent
                             @drop.prevent="dropOn(<?php echo $i; ?>)">
                            <span class="cursor-grab text-gray-400 select-none pt-1" title="Drag to reorder">
                                <i class="fa-solid fa-grip-vertical"></i>
                            </span>
                            <div class="flex-1 min-w-0">
                                <div class="text-sm font-medium text-gray-800"><?php echo sanitize($faq['question']); ?></div>
                                <div class="text-xs text-gray-500 whitespace-pre-line mt-1"><?php echo sanitize($faq['answer']); ?></div>
                                <?php if (!empty($faq['question_ar']) || !empty($faq['answer_ar'])): ?>
                                <div class="mt-2 pt-2 border-t border-dashed border-gray-200" dir="rtl">
                                    <?php if (!empty($faq['question_ar'])): ?>
                                    <div class="text-sm font-medium text-gray-800"><?php echo sanitize($faq['question_ar']); ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($faq['answer_ar'])): ?>
                                    <div class="text-xs text-gray-500 whitespace-pre-line mt-1"><?php echo sanitize($faq['answer_ar']); ?></div>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            <form method="post" onsubmit="return confirm('Remove this FAQ?');">
                                <input type="hidden" name="action" value="delete_faq">
                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
                                <input type="hidden" name="faq_id" value="<?php echo sanitize($faq['id']); ?>">
                                <button class="text-red-500 text-sm"><i class="fa-solid fa-trash"></i></button>
                            </form>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <button type="submit" class="mt-3 px-3 py-2 bg-gray-900 text-white rounded-lg text-sm" disabled>Save order</button>
                </form>
                <?php endif; ?>

                <form method="post" class="space-y-2">
                    <input type="hidden" name="action" value="add_faq">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
                    <div class="grid gap-2">
                        <input type="text" name="faq_question" placeholder="Question (EN)" required maxlength="500"
                               class="w-full px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg text-sm">
                        <textarea name="faq_answer" placeholder="Answer (EN)" required rows="3"
                                  class="w-full px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg text-sm"></textarea>
                    </div>
                    <div class="grid gap-2 pt-2 border-t border-dashed border-gray-200" dir="rtl">
                        <div class="text-xs text-gray-500 self-center" dir="ltr">Arabic (optional, falls back to English)</div>
                        <input type="text" name="faq_question_ar" dir="rtl" placeholder="السؤال" maxlength="500"
                               class="w-full px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg text-sm">
                        <textarea name="faq_answer_ar" dir="rtl" placeholder="الإجابة" rows="3"
                                  class="w-full px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg text-sm"></textarea>
                    </div>
                    <button class="px-3 py-2 bg-gray-900 text-white rounded-lg text-sm">Add FAQ</button>
                </form>
            </div>
        </div>

        <!-- Enhanced Social Links -->
        <div class="mt-8 bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
            <div class="p-4 border-b border-gray-100">
                <h2 class="font-semibold text-gray-900"><i class="fa-solid fa-share-nodes mr-1 text-gray-500"></i> Social Links</h2>
                <p class="text-sm text-gray-500">Add as many social profiles as you want, drag to reorder. Each one renders as a circular icon on your public card.</p>
            </div>
            <form method="post" class="p-6 space-y-4"
                  x-data="socialLinksEditor(<?php echo htmlspecialchars(json_encode(array_map(function($s){ return ['platform'=>$s['platform'],'url'=>$s['url']]; }, $socialLinks)), ENT_QUOTES, 'UTF-8'); ?>)"
                  @submit="syncHiddenInputs($event)">
                <input type="hidden" name="action" value="update_socials">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">

                <div class="space-y-2">
                    <template x-for="(row, idx) in rows" :key="idx">
                        <div class="flex items-center gap-2 p-2 bg-gray-50 border border-gray-200 rounded-lg"
                             draggable="true"
                             @dragstart="dragStart($event, idx)"
                             @dragover.prevent
                             @drop.prevent="dropOn($event, idx)">
                            <span class="cursor-grab text-gray-400 select-none px-1" title="Drag to reorder">
                                <i class="fa-solid fa-grip-vertical"></i>
                            </span>
                            <select x-model="row.platform"
                                    class="px-3 py-2 bg-white border border-gray-200 rounded-lg text-sm w-44">
                                <option value="">Select platform…</option>
                                <?php foreach (EmployeeSocials::PLATFORMS as $key => $p): ?>
                                <option value="<?php echo htmlspecialchars($key); ?>"><?php echo htmlspecialchars($p['label']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="url" x-model="row.url"
                                   :placeholder="platformHint(row.platform)"
                                   class="flex-1 px-3 py-2 bg-white border border-gray-200 rounded-lg text-sm">
                            <button type="button" @click="rows.splice(idx,1)" class="text-red-500 p-2 hover:bg-red-50 rounded-lg" title="Remove">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                        </div>
                    </template>
                </div>

                <!-- Hidden submission inputs rendered at submit time -->
                <div class="hidden">
                    <template x-for="(row, idx) in rows" :key="'h'+idx">
                        <div>
                            <input type="hidden" name="social_platform[]" :value="row.platform">
                            <input type="hidden" name="social_url[]" :value="row.url">
                        </div>
                    </template>
                </div>

                <div class="flex items-center justify-between pt-2">
                    <button type="button" @click="rows.push({platform:'', url:''})" class="px-3 py-2 border border-dashed border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50">
                        <i class="fa-solid fa-plus mr-1"></i> Add social link
                    </button>
                    <button type="submit" class="px-6 py-2 btn-primary rounded-lg font-medium">Save Links</button>
                </div>
            </form>
            <script>
                function socialLinksEditor(initial) {
                    const hints = <?php echo json_encode(array_map(function($p){ return $p['hint']; }, EmployeeSocials::PLATFORMS)); ?>;
                    return {
                        rows: Array.isArray(initial) && initial.length ? initial.map(r => ({platform: r.platform || '', url: r.url || ''})) : [{platform:'', url:''}],
                        dragIndex: null,
                        platformHint(p) { return hints[p] || 'https://…'; },
                        dragStart(e, idx) { this.dragIndex = idx; e.dataTransfer.effectAllowed = 'move'; },
                        dropOn(e, idx) {
                            if (this.dragIndex === null || this.dragIndex === idx) return;
                            const [moved] = this.rows.splice(this.dragIndex, 1);
                            this.rows.splice(idx, 0, moved);
                            this.dragIndex = null;
                        },
                        syncHiddenInputs() { /* Alpine re-renders hidden inputs from rows on submit */ }
                    };
                }
                function productReorder(initial) {
                    return {
                        rows: Array.isArray(initial) ? initial.slice() : [],
                        dragIndex: null,
                        dragStart(e, idx) { this.dragIndex = idx; e.dataTransfer.effectAllowed = 'move'; },
                        drop(e, idx) {
                            if (this.dragIndex === null || this.dragIndex === idx) return;
                            const [moved] = this.rows.splice(this.dragIndex, 1);
                            this.rows.splice(idx, 0, moved);
                            this.dragIndex = null;
                        },
                        syncOrder() { /* hidden input already bound via :value */ }
                    };
                }
            </script>
        </div>

        <!-- Business Hours editor -->
        <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden mt-6" x-data="businessHoursEditor(<?php echo htmlspecialchars(json_encode($businessHours), ENT_QUOTES); ?>)">
            <div class="p-4 border-b border-gray-100 flex items-center justify-between">
                <div>
                    <h2 class="font-semibold text-gray-900"><i class="fa-solid fa-clock text-blue-600 mr-1"></i> Business Hours</h2>
                    <p class="text-sm text-gray-500">Publish when you're open. Enable "Business Hours" above to show this on your card.</p>
                </div>
            </div>
            <form method="post" class="p-6 space-y-4">
                <input type="hidden" name="action" value="update_hours">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
                <input type="hidden" name="hours_enabled" value="1">

                <!-- Presets -->
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-xs text-gray-500">Quick presets:</span>
                    <button type="button" @click="presetWeekdays9to5()" class="px-3 py-1.5 text-xs bg-gray-100 hover:bg-gray-200 text-gray-800 rounded-md">Weekdays 9-5</button>
                    <button type="button" @click="presetWeekendsClosed()" class="px-3 py-1.5 text-xs bg-gray-100 hover:bg-gray-200 text-gray-800 rounded-md">Weekends closed</button>
                    <button type="button" @click="preset247()" class="px-3 py-1.5 text-xs bg-gray-100 hover:bg-gray-200 text-gray-800 rounded-md">24/7</button>
                    <button type="button" @click="copyFromMonday()" class="px-3 py-1.5 text-xs bg-gray-100 hover:bg-gray-200 text-gray-800 rounded-md">Copy from Monday</button>
                </div>

                <!-- Timezone -->
                <div class="grid md:grid-cols-3 gap-3">
                    <div class="md:col-span-1">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Timezone</label>
                        <select name="hours_timezone" class="w-full px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg text-sm">
                            <?php
                            $currentTz = $sectionMaster['hours_timezone'] ?? 'Asia/Muscat';
                            foreach ($timezoneList as $tz):
                            ?>
                            <option value="<?php echo sanitize($tz); ?>" <?php echo $tz === $currentTz ? 'selected' : ''; ?>><?php echo sanitize($tz); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- 7-day grid -->
                <div class="space-y-2">
                    <?php
                    $dayLabelsEn = CardSections::DAY_NAMES_EN;
                    foreach (CardSections::DAY_KEYS as $d):
                        $row = $businessHours[$d];
                    ?>
                    <div class="grid grid-cols-1 md:grid-cols-12 gap-2 items-center p-3 rounded-lg border border-gray-200 bg-gray-50">
                        <div class="md:col-span-2 font-medium text-sm text-gray-800"><?php echo $dayLabelsEn[$d]; ?></div>
                        <label class="md:col-span-2 inline-flex items-center gap-2 text-sm text-gray-700">
                            <input type="checkbox" name="hours_closed[<?php echo $d; ?>]"
                                   class="h-4 w-4"
                                   x-model="days['<?php echo $d; ?>'].is_closed">
                            <span>Closed</span>
                        </label>
                        <div class="md:col-span-2">
                            <label class="block text-xs text-gray-500">Open</label>
                            <input type="time" name="hours_open[<?php echo $d; ?>]"
                                   :disabled="days['<?php echo $d; ?>'].is_closed"
                                   x-model="days['<?php echo $d; ?>'].open_time"
                                   class="w-full px-2 py-1.5 bg-white border border-gray-200 rounded text-sm disabled:bg-gray-100 disabled:text-gray-400">
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-xs text-gray-500">Close</label>
                            <input type="time" name="hours_close[<?php echo $d; ?>]"
                                   :disabled="days['<?php echo $d; ?>'].is_closed"
                                   x-model="days['<?php echo $d; ?>'].close_time"
                                   class="w-full px-2 py-1.5 bg-white border border-gray-200 rounded text-sm disabled:bg-gray-100 disabled:text-gray-400">
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-xs text-gray-500">Break start <span class="text-gray-400">(opt.)</span></label>
                            <input type="time" name="hours_break_start[<?php echo $d; ?>]"
                                   :disabled="days['<?php echo $d; ?>'].is_closed"
                                   x-model="days['<?php echo $d; ?>'].break_start"
                                   class="w-full px-2 py-1.5 bg-white border border-gray-200 rounded text-sm disabled:bg-gray-100 disabled:text-gray-400">
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-xs text-gray-500">Break end</label>
                            <input type="time" name="hours_break_end[<?php echo $d; ?>]"
                                   :disabled="days['<?php echo $d; ?>'].is_closed"
                                   x-model="days['<?php echo $d; ?>'].break_end"
                                   class="w-full px-2 py-1.5 bg-white border border-gray-200 rounded text-sm disabled:bg-gray-100 disabled:text-gray-400">
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <button type="submit" class="px-6 py-2 btn-primary rounded-lg font-medium">Save Hours</button>
            </form>
            <script>
                function businessHoursEditor(initial) {
                    const KEYS = ['mon','tue','wed','thu','fri','sat','sun'];
                    const days = {};
                    KEYS.forEach(k => {
                        const r = initial[k] || {};
                        days[k] = {
                            is_closed: r.is_closed ? true : false,
                            open_time:  (r.open_time  || '').slice(0,5),
                            close_time: (r.close_time || '').slice(0,5),
                            break_start:(r.break_start|| '').slice(0,5),
                            break_end:  (r.break_end  || '').slice(0,5),
                        };
                    });
                    return {
                        days,
                        presetWeekdays9to5() {
                            ['mon','tue','wed','thu','fri'].forEach(k => {
                                this.days[k] = { is_closed:false, open_time:'09:00', close_time:'17:00', break_start:'', break_end:'' };
                            });
                            ['sat','sun'].forEach(k => { this.days[k].is_closed = true; });
                        },
                        presetWeekendsClosed() {
                            ['sat','sun'].forEach(k => { this.days[k].is_closed = true; });
                        },
                        preset247() {
                            KEYS.forEach(k => {
                                this.days[k] = { is_closed:false, open_time:'00:00', close_time:'23:59', break_start:'', break_end:'' };
                            });
                        },
                        copyFromMonday() {
                            const src = this.days.mon;
                            ['tue','wed','thu','fri','sat','sun'].forEach(k => {
                                this.days[k] = { ...src };
                            });
                        }
                    };
                }
            </script>
        </div>
        </section>
        <!-- /CARD TAB -->

        <!-- BOOKING TAB -->
        <section role="tabpanel" aria-labelledby="tab-booking" x-show="tab==='booking'" x-cloak>
        <!-- Appointment Booking -->
        <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
            <div class="p-4 border-b border-gray-100 flex items-center justify-between">
                <div>
                    <h2 class="font-semibold text-gray-900"><i class="fa-solid fa-calendar-check text-blue-600 mr-1"></i> Appointment Booking</h2>
                    <p class="text-sm text-gray-500">Let visitors of your card book a meeting with you.</p>
                </div>
                <a href="<?php echo getBasePath(); ?>admin/appointments.php" class="text-sm text-blue-600 hover:underline">View bookings &rarr;</a>
            </div>
            <form method="post" class="p-6 space-y-5">
                <input type="hidden" name="action" value="update_appointments">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">

                <label class="flex items-center gap-3 p-3 rounded-lg bg-blue-50 border border-blue-200">
                    <input type="checkbox" name="appt_enabled" <?php echo !empty($apptSettings['enabled']) ? 'checked' : ''; ?> class="h-4 w-4">
                    <span class="text-sm font-medium text-gray-800">Enable appointment booking on my public card</span>
                </label>

                <div class="grid md:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Meeting duration (min)</label>
                        <input type="number" name="appt_duration" min="5" max="480" value="<?php echo (int)($apptSettings['duration_minutes'] ?? 30); ?>"
                               class="w-full px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Buffer between meetings (min)</label>
                        <input type="number" name="appt_buffer" min="0" max="240" value="<?php echo (int)($apptSettings['buffer_minutes'] ?? 0); ?>"
                               class="w-full px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Max advance booking (days)</label>
                        <input type="number" name="appt_max_advance" min="1" max="365" value="<?php echo (int)($apptSettings['max_advance_days'] ?? 30); ?>"
                               class="w-full px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg">
                    </div>
                </div>

                <div class="grid md:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Day starts</label>
                        <input type="time" name="appt_start" value="<?php echo sanitize(substr($apptSettings['available_start'] ?? '09:00:00', 0, 5)); ?>"
                               class="w-full px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Day ends</label>
                        <input type="time" name="appt_end" value="<?php echo sanitize(substr($apptSettings['available_end'] ?? '17:00:00', 0, 5)); ?>"
                               class="w-full px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Timezone</label>
                        <input type="text" name="appt_timezone" value="<?php echo sanitize($apptSettings['timezone'] ?? 'Asia/Muscat'); ?>"
                               class="w-full px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg">
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Available days</label>
                    <div class="flex flex-wrap gap-2">
                        <?php foreach (Appointments::DAY_KEYS as $d):
                            $checked = in_array($d, $apptDays, true);
                        ?>
                        <label class="flex items-center gap-2 px-3 py-2 rounded-lg border <?php echo $checked ? 'bg-blue-50 border-blue-300 text-blue-700' : 'bg-gray-50 border-gray-200 text-gray-700'; ?> text-sm cursor-pointer">
                            <input type="checkbox" name="appt_days[]" value="<?php echo $d; ?>" <?php echo $checked ? 'checked' : ''; ?>>
                            <?php echo strtoupper($d); ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Notify email when booked</label>
                    <input type="email" name="appt_email" placeholder="<?php echo sanitize($employee['email'] ?? ''); ?>"
                           value="<?php echo sanitize($apptSettings['notification_email'] ?? ''); ?>"
                           class="w-full px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg">
                    <p class="text-xs text-gray-500 mt-1">Defaults to your account email if blank.</p>
                </div>

                <button type="submit" class="px-6 py-2 btn-primary rounded-lg font-medium">Save Appointment Settings</button>
            </form>
        </div>
        </section>
        <!-- /BOOKING TAB -->

        <!-- MODERATION TAB -->
        <section role="tabpanel" aria-labelledby="tab-moderation" x-show="tab==='moderation'" x-cloak>
        <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
            <div class="p-4 border-b border-gray-100">
                <h2 class="font-semibold text-gray-900"><i class="fa-solid fa-gavel text-gray-500 mr-1"></i> Testimonial Moderation</h2>
                <p class="text-sm text-gray-500">Approve or reject testimonials submitted by visitors.</p>
            </div>

            <div class="p-6">
                <?php if (!empty($pendingTestimonials)): ?>
                <div class="mb-5 p-3 rounded-lg bg-yellow-50 border border-yellow-200">
                    <div class="text-xs uppercase tracking-wide text-yellow-800 font-semibold mb-2"><i class="fa-solid fa-clock mr-1"></i> Pending Review (<?php echo $pendingCount; ?>)</div>
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
                <?php else: ?>
                <div class="text-center py-8 text-gray-500">
                    <i class="fa-solid fa-circle-check text-3xl opacity-30 mb-2"></i>
                    <p class="text-sm">No pending testimonials. You're all caught up.</p>
                </div>
                <?php endif; ?>

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
        </section>
        <!-- /MODERATION TAB -->

    </main>
</div>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
