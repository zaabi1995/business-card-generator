<?php
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/SecurityHeaders.php';
SecurityHeaders::send();
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once INCLUDES_DIR . '/LogoAccess.php';
header('Cache-Control: private, no-store');
$pageTitle = t('logoaccess.title');
$pageDescription = t('logoaccess.description');
$metaRobots = 'noindex,follow';
$showNavigation = true;
$minimalFooter = true;
$extraHead = '<link rel="stylesheet" href="/assets/css/logo-access.css?v=' . filemtime(__DIR__ . '/assets/css/logo-access.css') . '">';
$logoAccessCompany = (int)($_GET['company'] ?? 0);
$logoAccessFormat = in_array($_GET['format'] ?? '', LogoAccess::FORMATS, true) ? $_GET['format'] : '';
$logoAccessPage = true;
$logoAccessReturned = ($_GET['payment'] ?? '') === 'returned';
$member = LogoAccess::member();
if (!empty($_GET['order']) && $member) {
    $order = Database::getInstance()->fetchOne('SELECT return_company_id, return_format FROM logo_pass_orders WHERE id = :id AND member_id = :member', ['id' => $_GET['order'], 'member' => $member['id']]);
    if ($order) { $logoAccessCompany = (int)$order['return_company_id']; $logoAccessFormat = $order['return_format'] ?? ''; }
}
require INCLUDES_DIR . '/ui-header.php';
?>
<main class="logo-access-page">
    <?php require __DIR__ . '/views/partials/logo_access_panel.php'; ?>
    <a class="logo-access-link" href="<?= currentLocale() === 'ar' ? '/ar' : '' ?>/logos"><?= t('logoaccess.browse') ?></a>
</main>
<?php require INCLUDES_DIR . '/ui-footer.php'; ?>
