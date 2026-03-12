<?php
/**
 * Public Company View - Employee-focused landing page
 * Designed to help employees easily request and receive their business cards
 */
$companyName = sanitize($company['name_en'] ?? $company['name'] ?? $companySlug);
$companyNameAr = sanitize($company['name_ar'] ?? '');
$companyDomain = $company['email_domain'] ?? extractEmailDomain($company['admin_email'] ?? '');

// Get both front and back templates for card preview
$activeFrontTemplate = getActiveFrontTemplate($company['id']);
$activeBackTemplate = getActiveBackTemplate($company['id']);
$hasFrontTemplate = $activeFrontTemplate && !empty($activeFrontTemplate['backgroundImage']);
$hasBackTemplate = $activeBackTemplate && !empty($activeBackTemplate['backgroundImage']);

// Try to get designated sample cards first
$sampleFrontCard = null;
$sampleBackCard = null;

// Check for admin-designated sample cards in company settings
if (!empty($company['sample_card_front']) && !empty($company['sample_card_back'])) {
    $frontPath = UPLOADS_DIR . '/' . $company['sample_card_front'];
    $backPath = UPLOADS_DIR . '/' . $company['sample_card_back'];
    
    if (file_exists($frontPath) && file_exists($backPath)) {
        $sampleFrontCard = 'uploads/' . $company['sample_card_front'];
        $sampleBackCard = 'uploads/' . $company['sample_card_back'];
    }
}

// Fall back to most recent generated card if no designated sample
if (!$sampleFrontCard || !$sampleBackCard) {
    $generatedLog = loadGeneratedLog($company['id']);
    
    if (!empty($generatedLog)) {
        foreach ($generatedLog as $entry) {
            if (!empty($entry['front_file']) && !empty($entry['back_file'])) {
                $cardsDir = COMPANIES_UPLOADS_DIR . '/' . $company['id'] . '/cards/';
                $frontPath = $cardsDir . $entry['front_file'];
                $backPath = $cardsDir . $entry['back_file'];
                
                if (file_exists($frontPath) && file_exists($backPath)) {
                    $sampleFrontCard = 'uploads/companies/' . $company['id'] . '/cards/' . $entry['front_file'];
                    $sampleBackCard = 'uploads/companies/' . $company['id'] . '/cards/' . $entry['back_file'];
                    break;
                }
            }
        }
    }
}

// Final card images (sample or template fallback)
// Note: sample cards use getBasePath() directly since they're in uploads/, not assets/
$frontTemplateImage = $sampleFrontCard ? (getBasePath() . $sampleFrontCard) : ($hasFrontTemplate ? imageUrl($activeFrontTemplate['backgroundImage']) : null);
$backTemplateImage = $sampleBackCard ? (getBasePath() . $sampleBackCard) : ($hasBackTemplate ? imageUrl($activeBackTemplate['backgroundImage']) : null);
$hasSampleCards = $sampleFrontCard && $sampleBackCard;

// Check orientation from template settings (landscape = horizontal)
$isHorizontal = true; // default
if ($hasFrontTemplate && !empty($activeFrontTemplate['settings']['cardOrientation'])) {
    $isHorizontal = $activeFrontTemplate['settings']['cardOrientation'] === 'landscape';
}

$extraHead = '<style>
    :root {
        --primary-color: ' . $primaryColor . ';
        --secondary-color: ' . $secondaryColor . ';
    }
    body { font-family: "Plus Jakarta Sans", ui-sans-serif, system-ui, sans-serif; }
    .btn-primary { 
        background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
        color: white;
        transition: all 0.2s ease;
    }
    .btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 10px 25px -5px rgba(0,0,0,0.2);
    }
    .card-preview {
        background: linear-gradient(145deg, #f8fafc 0%, #e2e8f0 100%);
        border-radius: 12px;
        padding: 2rem;
        position: relative;
        overflow: hidden;
    }
    .card-preview::before {
        content: "";
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 4px;
        background: linear-gradient(90deg, var(--primary-color), var(--secondary-color));
    }
    .step-number {
        width: 40px;
        height: 40px;
        background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        color: white;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 1.1rem;
    }
    .feature-icon {
        width: 56px;
        height: 56px;
        border-radius: 16px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.5rem;
    }
    .text-shadow-lg {
        text-shadow: 0 2px 4px rgba(0,0,0,0.5), 0 1px 2px rgba(0,0,0,0.3);
    }
    ' . (!empty($companyTheme['custom_css']) ? preg_replace('/<\s*\/?\s*(style|script)[^>]*>/i', '', $companyTheme['custom_css']) : '') . '
</style>';
require_once INCLUDES_DIR . '/ui-header.php';
?>

<div class="min-h-screen bg-gradient-to-br from-slate-50 via-white to-blue-50">
    <!-- Header -->
    <header class="bg-white/80 backdrop-blur-md border-b border-gray-100 sticky top-0 z-50">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-4">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <?php if ($companyTheme && !empty($companyTheme['logo_path'])): ?>
                    <img src="<?php echo getBasePath() . 'uploads/' . ltrim($companyTheme['logo_path'], '/'); ?>" alt="<?php echo $companyName; ?>" class="h-10">
                    <?php else: ?>
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-blue-500 to-blue-600 flex items-center justify-center text-white font-bold">
                        <?php echo strtoupper(substr($company['name'], 0, 2)); ?>
                    </div>
                    <?php endif; ?>
                    <div>
                        <h1 class="text-lg font-bold text-gray-900"><?php echo $companyName; ?></h1>
                        <p class="text-xs text-gray-500">Business Card Portal</p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <a href="<?php echo getBasePath() . $companySlug; ?>/admin/login" 
                       class="px-4 py-2 text-sm text-gray-600 hover:text-gray-900 font-medium">
                        <i class="fa-solid fa-lock mr-1.5"></i>Admin
                    </a>
                </div>
            </div>
        </div>
    </header>

    <!-- Hero Section -->
    <main>
        <section class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-12 lg:py-20">
            <div class="grid lg:grid-cols-2 gap-12 items-center">
                <!-- Left: Content -->
                <div>
                    <div class="inline-flex items-center gap-2 px-3 py-1.5 bg-blue-50 text-blue-700 rounded-full text-sm font-medium mb-6">
                        <i class="fa-solid fa-id-card"></i>
                        <span>Employee Business Cards</span>
                    </div>
                    
                    <h2 class="text-4xl lg:text-5xl font-bold text-gray-900 mb-6 leading-tight">
                        Get Your Professional<br>
                        <span class="text-transparent bg-clip-text bg-gradient-to-r from-blue-600 to-indigo-600">Business Card</span>
                    </h2>
                    
                    <p class="text-lg text-gray-600 mb-8 leading-relaxed">
                        Request your <?php echo $companyName; ?> business card in just a few clicks. 
                        Fill in your details, and we'll generate your personalized card ready for digital sharing or printing.
                    </p>
                    
                    <div class="flex flex-col sm:flex-row gap-4 mb-8">
                        <a href="<?php echo getBasePath() . $companySlug; ?>/portal" 
                           class="px-8 py-4 btn-primary rounded-xl font-semibold text-lg text-center shadow-lg">
                            <i class="fa-solid fa-plus mr-2"></i>Request My Card
                        </a>
                        <?php if ($companyDomain): ?>
                        <div class="flex items-center text-sm text-gray-500">
                            <i class="fa-solid fa-envelope mr-2"></i>
                            For @<?php echo htmlspecialchars($companyDomain); ?> employees
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Trust indicators -->
                    <div class="flex items-center gap-6 text-sm text-gray-500">
                        <div class="flex items-center gap-2">
                            <i class="fa-solid fa-shield-check text-green-500"></i>
                            <span>Secure</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <i class="fa-solid fa-bolt text-yellow-500"></i>
                            <span>Fast Delivery</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <i class="fa-solid fa-check-circle text-blue-500"></i>
                            <span>Company Approved</span>
                        </div>
                    </div>
                </div>
                
                <!-- Right: Card Preview - Both Front & Back (Compact & Centered) -->
                <div class="relative flex justify-center">
                    <div class="card-preview shadow-xl p-4 sm:p-5 space-y-4">
                        <?php if ($hasSampleCards || $hasFrontTemplate): ?>
                        <!-- Front Card -->
                        <div class="flex flex-col items-center">
                            <div class="text-[10px] font-medium text-gray-400 mb-2 flex items-center justify-center gap-1 w-full">
                                <i class="fa-solid fa-image text-blue-400"></i> Front
                                <?php if ($hasSampleCards): ?>
                                <span class="bg-green-100 text-green-700 px-1.5 py-0.5 rounded text-[9px] ml-1">Sample</span>
                                <?php endif; ?>
                            </div>
                            <div class="rounded-lg overflow-hidden shadow-md w-full max-w-[320px]" style="aspect-ratio: 3.5/2;">
                                <img src="<?php echo $frontTemplateImage; ?>" alt="Front Card" class="w-full h-full object-cover">
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($hasSampleCards || $hasBackTemplate): ?>
                        <!-- Back Card -->
                        <div class="flex flex-col items-center">
                            <div class="text-[10px] font-medium text-gray-400 mb-2 flex items-center justify-center gap-1 w-full">
                                <i class="fa-solid fa-image text-green-400"></i> Back
                                <?php if ($hasSampleCards): ?>
                                <span class="bg-green-100 text-green-700 px-1.5 py-0.5 rounded text-[9px] ml-1">Sample</span>
                                <?php endif; ?>
                            </div>
                            <div class="rounded-lg overflow-hidden shadow-md w-full max-w-[320px]" style="aspect-ratio: 3.5/2;">
                                <img src="<?php echo $backTemplateImage; ?>" alt="Back Card" class="w-full h-full object-cover">
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!$hasSampleCards && !$hasFrontTemplate && !$hasBackTemplate): ?>
                        <!-- No Template Placeholder -->
                        <div class="text-center py-8 text-gray-400">
                            <i class="fa-solid fa-id-card text-3xl mb-2"></i>
                            <p class="text-sm">Card templates will appear here</p>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Decorative elements -->
                    <div class="absolute -top-4 -right-4 w-16 h-16 bg-blue-100 rounded-full opacity-40 blur-xl"></div>
                    <div class="absolute -bottom-4 -left-4 w-20 h-20 bg-indigo-100 rounded-full opacity-40 blur-xl"></div>
                </div>
            </div>
        </section>

        <!-- How it Works -->
        <section class="bg-white border-y border-gray-100 py-16">
            <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="text-center mb-12">
                    <h3 class="text-2xl font-bold text-gray-900 mb-3">How It Works</h3>
                    <p class="text-gray-600">Get your business card in 3 simple steps</p>
                </div>
                
                <div class="grid md:grid-cols-3 gap-8">
                    <!-- Step 1 -->
                    <div class="text-center">
                        <div class="step-number mx-auto mb-4">1</div>
                        <h4 class="font-semibold text-gray-900 mb-2">Fill Your Details</h4>
                        <p class="text-gray-600 text-sm">Enter your name, position, contact info, and upload a photo if needed.</p>
                    </div>
                    
                    <!-- Step 2 -->
                    <div class="text-center">
                        <div class="step-number mx-auto mb-4">2</div>
                        <h4 class="font-semibold text-gray-900 mb-2">Admin Review</h4>
                        <p class="text-gray-600 text-sm">Your request is reviewed and approved by the administrator.</p>
                    </div>
                    
                    <!-- Step 3 -->
                    <div class="text-center">
                        <div class="step-number mx-auto mb-4">3</div>
                        <h4 class="font-semibold text-gray-900 mb-2">Receive Your Card</h4>
                        <p class="text-gray-600 text-sm">Get your personalized business card delivered to your email.</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- Features -->
        <section class="py-16">
            <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="grid md:grid-cols-2 lg:grid-cols-4 gap-6">
                    <div class="bg-white rounded-2xl p-6 border border-gray-100 shadow-sm hover:shadow-md transition-shadow">
                        <div class="feature-icon bg-blue-50 text-blue-600 mb-4">
                            <i class="fa-solid fa-mobile-screen"></i>
                        </div>
                        <h4 class="font-semibold text-gray-900 mb-2">Digital Ready</h4>
                        <p class="text-gray-600 text-sm">Share your card digitally via link or QR code.</p>
                    </div>
                    
                    <div class="bg-white rounded-2xl p-6 border border-gray-100 shadow-sm hover:shadow-md transition-shadow">
                        <div class="feature-icon bg-green-50 text-green-600 mb-4">
                            <i class="fa-solid fa-print"></i>
                        </div>
                        <h4 class="font-semibold text-gray-900 mb-2">Print Quality</h4>
                        <p class="text-gray-600 text-sm">High-resolution PDF ready for professional printing.</p>
                    </div>
                    
                    <div class="bg-white rounded-2xl p-6 border border-gray-100 shadow-sm hover:shadow-md transition-shadow">
                        <div class="feature-icon bg-purple-50 text-purple-600 mb-4">
                            <i class="fa-solid fa-language"></i>
                        </div>
                        <h4 class="font-semibold text-gray-900 mb-2">Bilingual</h4>
                        <p class="text-gray-600 text-sm">Support for English and Arabic text on your card.</p>
                    </div>
                    
                    <div class="bg-white rounded-2xl p-6 border border-gray-100 shadow-sm hover:shadow-md transition-shadow">
                        <div class="feature-icon bg-orange-50 text-orange-600 mb-4">
                            <i class="fa-solid fa-qrcode"></i>
                        </div>
                        <h4 class="font-semibold text-gray-900 mb-2">Smart QR Code</h4>
                        <p class="text-gray-600 text-sm">Scan to instantly save contact to any phone.</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- CTA Section -->
        <section class="py-16">
            <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="bg-gradient-to-br from-blue-600 to-indigo-700 rounded-3xl p-8 md:p-12 text-center text-white relative overflow-hidden">
                    <!-- Background decoration -->
                    <div class="absolute inset-0 opacity-10">
                        <div class="absolute top-0 left-0 w-40 h-40 bg-white rounded-full -translate-x-1/2 -translate-y-1/2"></div>
                        <div class="absolute bottom-0 right-0 w-60 h-60 bg-white rounded-full translate-x-1/3 translate-y-1/3"></div>
                    </div>
                    
                    <div class="relative">
                        <h3 class="text-3xl font-bold mb-4">Ready to Get Your Card?</h3>
                        <p class="text-blue-100 mb-8 max-w-lg mx-auto">
                            Join your colleagues at <?php echo $companyName; ?> with a professional business card that represents your role.
                        </p>
                        <a href="<?php echo getBasePath() . $companySlug; ?>/portal" 
                           class="inline-flex items-center gap-2 px-8 py-4 bg-white text-blue-600 rounded-xl font-semibold text-lg hover:bg-blue-50 transition-colors shadow-lg">
                            <i class="fa-solid fa-arrow-right"></i>
                            Request My Business Card
                        </a>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <!-- Footer -->
    <footer class="border-t border-gray-200 bg-white">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
            <div class="flex flex-col md:flex-row items-center justify-between gap-4">
                <div class="flex items-center gap-3">
                    <?php if ($companyTheme && !empty($companyTheme['logo_path'])): ?>
                    <img src="<?php echo getBasePath() . 'uploads/' . ltrim($companyTheme['logo_path'], '/'); ?>" alt="<?php echo $companyName; ?>" class="h-8 opacity-60">
                    <?php endif; ?>
                    <p class="text-sm text-gray-500">
                        &copy; <?php echo date('Y'); ?> <?php echo $companyName; ?>
                    </p>
                </div>
                <div class="flex items-center gap-6 text-sm text-gray-500">
                    <a href="<?php echo getBasePath() . $companySlug; ?>/portal" class="hover:text-gray-700">Request Card</a>
                    <a href="<?php echo getBasePath() . $companySlug; ?>/admin/login" class="hover:text-gray-700">Admin Login</a>
                    <span class="text-gray-300">|</span>
                    <span>Powered by <a href="<?php echo getBasePath(); ?>" class="text-blue-600 hover:underline">Cardify</a></span>
                </div>
            </div>
        </div>
    </footer>
</div>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
