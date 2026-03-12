<?php
/**
 * Employee Profile Page
 * Shows employee their business card and allows them to share it
 */
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/VCF.php';

// Require login
if (!Auth::isLoggedIn()) {
    header('Location: ' . getBasePath() . 'login.php');
    exit;
}

$currentUser = Auth::getCurrentUser();
$userRole = Auth::getCurrentRole();

// If not an employee, redirect to admin
if ($userRole !== 'employee') {
    $redirect = $userRole === 'super_admin' ? getBasePath() . 'admin/super/' : getBasePath() . 'admin/';
    header('Location: ' . $redirect);
    exit;
}

// Get employee data
$employee = null;
$company = null;
$vcfUrl = '';

if (isset($_SESSION['employee_id'])) {
    $employee = findEmployeeById($_SESSION['employee_id'], $_SESSION['company_id'] ?? null);
}

if (!$employee && isset($_SESSION['user_email'])) {
    $employee = findEmployeeByEmail($_SESSION['user_email'], $_SESSION['company_id'] ?? null);
}

if ($_SESSION['company_id'] ?? null) {
    $company = findCompanyById($_SESSION['company_id']);
}

if ($employee && $company) {
    $vcfUrl = VCF::getUrl($employee, $company);
}

$brandName = defined('SITE_NAME') ? SITE_NAME : 'Cardify';
$pageTitle = 'My Profile';
$bodyClass = 'bg-gray-50';

require_once INCLUDES_DIR . '/ui-header.php';
?>

<!-- Simple Navigation -->
<nav class="bg-white border-b border-gray-200">
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between items-center h-16">
            <a href="<?php echo getBasePath(); ?>" class="flex items-center gap-3">
                <img src="<?php echo assetUrl('images/logo.svg'); ?>" alt="<?php echo $brandName; ?>" class="h-8 w-8">
                <span class="text-xl font-bold text-gray-900"><?php echo $brandName; ?></span>
            </a>
            <div class="flex items-center gap-4">
                <span class="text-gray-600">
                    Hello, <?php echo htmlspecialchars($employee['name_en'] ?? $employee['name'] ?? 'User'); ?>
                </span>
                <a href="<?php echo getBasePath(); ?>logout.php" class="text-gray-500 hover:text-red-600 transition-colors">
                    <i class="fa-solid fa-right-from-bracket"></i>
                    Sign Out
                </a>
            </div>
        </div>
    </div>
</nav>

<main class="max-w-4xl mx-auto px-4 py-12">
    <div class="text-center mb-10">
        <h1 class="text-3xl font-bold text-gray-900 mb-2">Your Digital Business Card</h1>
        <p class="text-gray-600">Share your contact information easily</p>
    </div>

    <?php if ($employee): ?>
    <div class="grid md:grid-cols-2 gap-8">
        <!-- Profile Card -->
        <div class="bg-white rounded-2xl shadow-lg p-8">
            <div class="text-center">
                <!-- Avatar -->
                <div class="w-24 h-24 rounded-full bg-gradient-to-br from-blue-500 to-blue-600 flex items-center justify-center text-white text-3xl font-bold mx-auto mb-4">
                    <?php 
                    $name = $employee['name_en'] ?? $employee['name'] ?? 'U';
                    $initials = '';
                    $parts = explode(' ', $name);
                    foreach ($parts as $part) {
                        $initials .= strtoupper(substr($part, 0, 1));
                    }
                    echo substr($initials, 0, 2);
                    ?>
                </div>
                
                <h2 class="text-2xl font-bold text-gray-900">
                    <?php echo htmlspecialchars($employee['name_en'] ?? $employee['name'] ?? ''); ?>
                </h2>
                
                <?php if (!empty($employee['position_en'])): ?>
                <p class="text-blue-600 font-medium mt-1">
                    <?php echo htmlspecialchars($employee['position_en']); ?>
                </p>
                <?php endif; ?>
                
                <?php if ($company): ?>
                <p class="text-gray-500 mt-1">
                    <?php echo htmlspecialchars($company['name'] ?? ''); ?>
                </p>
                <?php endif; ?>
            </div>
            
            <div class="mt-8 space-y-4">
                <?php if (!empty($employee['email'])): ?>
                <div class="flex items-center gap-4 text-gray-600">
                    <i class="fa-solid fa-envelope w-5 text-blue-500"></i>
                    <span><?php echo htmlspecialchars($employee['email']); ?></span>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($employee['phone'])): ?>
                <div class="flex items-center gap-4 text-gray-600">
                    <i class="fa-solid fa-phone w-5 text-blue-500"></i>
                    <span><?php echo htmlspecialchars($employee['phone']); ?></span>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($employee['mobile'])): ?>
                <div class="flex items-center gap-4 text-gray-600">
                    <i class="fa-solid fa-mobile w-5 text-blue-500"></i>
                    <span><?php echo htmlspecialchars($employee['mobile']); ?></span>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($employee['website'])): ?>
                <div class="flex items-center gap-4 text-gray-600">
                    <i class="fa-solid fa-globe w-5 text-blue-500"></i>
                    <a href="<?php echo htmlspecialchars($employee['website']); ?>" target="_blank" class="hover:text-blue-600">
                        <?php echo htmlspecialchars($employee['website']); ?>
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Share Options -->
        <div class="space-y-6">
            <!-- QR Code -->
            <?php if ($vcfUrl): ?>
            <div class="bg-white rounded-2xl shadow-lg p-8 text-center">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Scan to Save Contact</h3>
                <div id="qrcode" class="inline-block bg-white p-4 rounded-xl"></div>
                <p class="text-sm text-gray-500 mt-4">
                    Scan this QR code to download the contact card
                </p>
            </div>
            <?php endif; ?>
            
            <!-- Share Buttons -->
            <div class="bg-white rounded-2xl shadow-lg p-8">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Share Your Card</h3>
                <div class="space-y-3">
                    <?php if ($vcfUrl): ?>
                    <a href="<?php echo htmlspecialchars($vcfUrl); ?>" 
                       class="flex items-center justify-center gap-3 w-full py-3 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-xl transition-colors">
                        <i class="fa-solid fa-download"></i>
                        Download vCard
                    </a>
                    
                    <button onclick="copyLink()" 
                            class="flex items-center justify-center gap-3 w-full py-3 bg-gray-100 hover:bg-gray-200 text-gray-700 font-semibold rounded-xl transition-colors">
                        <i class="fa-solid fa-link"></i>
                        Copy Link
                    </button>
                    
                    <a href="https://wa.me/?text=<?php echo urlencode('Download my contact: ' . $vcfUrl); ?>" 
                       target="_blank"
                       class="flex items-center justify-center gap-3 w-full py-3 bg-green-500 hover:bg-green-600 text-white font-semibold rounded-xl transition-colors">
                        <i class="fa-brands fa-whatsapp"></i>
                        Share via WhatsApp
                    </a>
                    
                    <a href="mailto:?subject=My Contact Card&body=<?php echo urlencode('Download my contact card: ' . $vcfUrl); ?>"
                       class="flex items-center justify-center gap-3 w-full py-3 bg-gray-700 hover:bg-gray-800 text-white font-semibold rounded-xl transition-colors">
                        <i class="fa-solid fa-envelope"></i>
                        Share via Email
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php else: ?>
    <div class="bg-yellow-50 border border-yellow-200 rounded-xl p-8 text-center">
        <i class="fa-solid fa-triangle-exclamation text-yellow-500 text-4xl mb-4"></i>
        <h2 class="text-xl font-bold text-gray-900 mb-2">Profile Not Found</h2>
        <p class="text-gray-600">Your employee profile could not be loaded. Please contact your administrator.</p>
    </div>
    <?php endif; ?>
</main>

<!-- QR Code Script -->
<script src="https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.min.js"></script>
<script>
    // Generate QR Code
    const vcfUrl = <?php echo json_encode($vcfUrl); ?>;
    if (vcfUrl) {
        const qr = qrcode(0, 'M');
        qr.addData(vcfUrl);
        qr.make();
        document.getElementById('qrcode').innerHTML = qr.createImgTag(5, 8);
    }
    
    // Copy link function
    function copyLink() {
        navigator.clipboard.writeText(vcfUrl).then(() => {
            alert('Link copied to clipboard!');
        }).catch(() => {
            // Fallback
            const input = document.createElement('input');
            input.value = vcfUrl;
            document.body.appendChild(input);
            input.select();
            document.execCommand('copy');
            document.body.removeChild(input);
            alert('Link copied to clipboard!');
        });
    }
</script>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
