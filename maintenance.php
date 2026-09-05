<?php
/**
 * Cardify - Maintenance Page
 * Design: Flowbite Pro maintenance template
 */
require_once __DIR__ . '/config.php';

$brandName = defined('SITE_NAME') ? SITE_NAME : 'Cardify';
$pageTitle = 'Under Maintenance';
$htmlClass = 'h-full bg-white';
$bodyClass = 'h-full';

http_response_code(503);
header('Retry-After: 3600'); // Suggest retry after 1 hour
?>
<?php require_once INCLUDES_DIR . '/ui-header.php'; ?>
    <div class="flex flex-col justify-center items-center px-6 mx-auto min-h-screen xl:px-0">
        <div class="block md:max-w-lg mb-8">
            <img src="<?php echo assetUrl('images/illustrations/maintenance.svg'); ?>" alt="Maintenance illustration" class="w-full h-auto">
        </div>
        <div class="text-center xl:max-w-4xl">
            <h1 class="mb-4 text-2xl font-bold leading-tight text-gray-900 sm:text-4xl lg:text-5xl">
                We're under maintenance
            </h1>
            <p class="mb-6 text-base font-normal text-gray-500 md:text-lg">
                We're working hard to improve <?php echo $brandName; ?> and will be back shortly. Thank you for your patience!
            </p>
            
            <!-- Status Updates -->
            <div class="inline-flex items-center gap-2 px-4 py-2 bg-amber-50 text-amber-700 rounded-full text-sm font-medium mb-8">
                <span class="flex h-2 w-2">
                    <span class="animate-ping absolute inline-flex h-2 w-2 rounded-full bg-amber-400 opacity-75"></span>
                    <span class="relative inline-flex rounded-full h-2 w-2 bg-amber-500"></span>
                </span>
                Scheduled maintenance in progress
            </div>
            
            <div class="flex flex-col sm:flex-row items-center justify-center gap-4">
                <button data-cardify-action="reload" class="inline-flex items-center justify-center px-5 py-3 text-base font-medium text-white bg-blue-600 rounded-lg hover:bg-blue-700 focus:ring-4 focus:ring-blue-300 transition-colors">
                    <i class="fa-solid fa-rotate-right mr-2"></i>
                    Refresh page
                </button>
                <a href="mailto:support@cardify.om" class="inline-flex items-center justify-center px-5 py-3 text-base font-medium text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200 focus:ring-4 focus:ring-gray-300 transition-colors">
                    <i class="fa-solid fa-envelope mr-2"></i>
                    Contact support
                </a>
            </div>
        </div>
    </div>
<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
