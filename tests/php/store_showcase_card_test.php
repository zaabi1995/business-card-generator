<?php
declare(strict_types=1);

define('INCLUDES_DIR', dirname(__DIR__, 2) . '/includes');
$_SERVER['HTTPS'] = 'on';
$_SERVER['HTTP_HOST'] = 'demo.cardify.om';
$employeeId = 'maya';

ob_start();
require dirname(__DIR__, 2) . '/store-showcase-card.php';
$html = (string) ob_get_clean();

if (strpos($html, 'Maya Hassan') === false) {
    fwrite(STDERR, "Missing showcase profile name\n");
    exit(1);
}
if (strpos($html, 'Studio North') === false) {
    fwrite(STDERR, "Missing showcase company\n");
    exit(1);
}
if (strpos($html, 'telephone') !== false || strpos($html, 'mailto:') !== false) {
    fwrite(STDERR, "Showcase page exposed a contact channel\n");
    exit(1);
}

$employeeId = 'showcase-directory-1';
ob_start();
require dirname(__DIR__, 2) . '/store-showcase-card.php';
$directoryHtml = (string) ob_get_clean();
if (
    strpos($directoryHtml, 'Nora Ahmed') === false
    || strpos($directoryHtml, 'Studio North') === false
) {
    fwrite(STDERR, "Missing showcase directory profile\n");
    exit(1);
}

$digitalCard = file_get_contents(dirname(__DIR__, 2) . '/digital_card.php');
if (
    strpos((string) $digitalCard, "companySlug === 'demo'") === false
    || strpos((string) $digitalCard, "store-showcase-card.php") === false
) {
    fwrite(STDERR, "Digital card route does not dispatch the showcase profile\n");
    exit(1);
}

echo "Store showcase card test passed\n";
