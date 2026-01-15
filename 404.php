<?php
/**
 * Cardify - 404 Page Not Found
 * Design: Flowbite Pro 404 template
 */
require_once __DIR__ . '/config.php';

$brandName = defined('SITE_NAME') ? SITE_NAME : 'Cardify';
$pageTitle = 'Page Not Found';
$htmlClass = 'h-full bg-white';
$bodyClass = 'h-full';

http_response_code(404);
?>
<?php require_once INCLUDES_DIR . '/ui-header.php'; ?>
    <div class="flex flex-col justify-center items-center px-6 mx-auto min-h-screen xl:px-0">
        <div class="block md:max-w-lg mb-8">
            <img src="<?php echo assetUrl('images/illustrations/404.svg'); ?>" alt="Page not found illustration" class="w-full h-auto">
        </div>
        <div class="text-center xl:max-w-4xl">
            <h1 class="mb-4 text-2xl font-bold leading-tight text-gray-900 sm:text-4xl lg:text-5xl">
                Page not found
            </h1>
            <p class="mb-6 text-base font-normal text-gray-500 md:text-lg">
                Oops! Looks like you followed a bad link. If you think this is a problem with us, please let us know.
            </p>
            <div class="flex flex-col sm:flex-row items-center justify-center gap-4">
                <a href="<?php echo getBasePath(); ?>" class="inline-flex items-center justify-center px-5 py-3 text-base font-medium text-white bg-blue-600 rounded-lg hover:bg-blue-700 focus:ring-4 focus:ring-blue-300 transition-colors">
                    <svg class="w-5 h-5 mr-2 -ml-1" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                        <path fill-rule="evenodd" d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                    </svg>
                    Go back home
                </a>
                <a href="<?php echo getBasePath(); ?>login.php" class="inline-flex items-center justify-center px-5 py-3 text-base font-medium text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200 focus:ring-4 focus:ring-gray-300 transition-colors">
                    <i class="fa-solid fa-right-to-bracket mr-2"></i>
                    Sign in
                </a>
            </div>
        </div>
    </div>
<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
