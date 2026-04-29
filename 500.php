<?php
/**
 * Cardify - 500 Server Error
 * Design: Flowbite Pro 500 template
 */
require_once __DIR__ . '/config.php';

$brandName = defined('SITE_NAME') ? SITE_NAME : 'Cardify';
$pageTitle = t('errors.page_500_title');
$htmlClass = 'h-full bg-white';
$bodyClass = 'h-full';

http_response_code(500);
?>
<?php require_once INCLUDES_DIR . '/ui-header.php'; ?>
    <div class="flex flex-col justify-center items-center px-6 mx-auto min-h-screen xl:px-0">
        <div class="block md:max-w-lg mb-8">
            <img src="<?php echo assetUrl('images/illustrations/500.svg'); ?>" alt="<?= htmlspecialchars(t('errors.page_500_title')) ?>" class="w-full h-auto">
        </div>
        <div class="text-center xl:max-w-4xl">
            <h1 class="mb-4 text-2xl font-bold leading-tight text-gray-900 sm:text-4xl lg:text-5xl">
                <?= htmlspecialchars(t('errors.page_500_title')) ?>
            </h1>
            <p class="mb-6 text-base font-normal text-gray-500 md:text-lg">
                <?= htmlspecialchars(t('errors.page_500_body')) ?>
            </p>
            <div class="flex flex-col sm:flex-row items-center justify-center gap-4">
                <a href="<?php echo getBasePath(); ?>" class="inline-flex items-center justify-center px-5 py-3 text-base font-medium text-white bg-blue-600 rounded-lg hover:bg-blue-700 focus:ring-4 focus:ring-blue-300 transition-colors">
                    <i class="fa-solid fa-arrow-left mr-2"></i>
                    <?= htmlspecialchars(t('errors.go_home')) ?>
                </a>
                <button onclick="location.reload()" class="inline-flex items-center justify-center px-5 py-3 text-base font-medium text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200 focus:ring-4 focus:ring-gray-300 transition-colors">
                    <i class="fa-solid fa-rotate-right mr-2"></i>
                    <?= htmlspecialchars(t('common.try_again')) ?>
                </button>
            </div>
        </div>
    </div>
<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
