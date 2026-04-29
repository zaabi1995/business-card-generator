<?php
/**
 * Cardify language switcher, renders a single pill button that flips locales.
 * Include anywhere inside <body> where a top-nav item fits.
 *
 * Usage: <?php require INCLUDES_DIR . '/lang-switcher.php'; ?>
 */
if (!function_exists('currentLocale')) { return; }

$cardifyCurrentLocale = currentLocale();
$cardifyTargetLocale  = ($cardifyCurrentLocale === 'ar') ? 'en' : 'ar';
$cardifyTargetLabel   = ($cardifyTargetLocale === 'ar') ? 'العربية' : 'English';

$cardifyReqUri = $_SERVER['REQUEST_URI'] ?? '/';
$cardifyReqPath = parse_url($cardifyReqUri, PHP_URL_PATH) ?: '/';
$cardifyReqQs   = parse_url($cardifyReqUri, PHP_URL_QUERY) ?: '';
parse_str($cardifyReqQs, $cardifyQsArr);
$cardifyQsArr['lang'] = $cardifyTargetLocale;
$cardifySwitchUrl = $cardifyReqPath . '?' . http_build_query($cardifyQsArr);
?>
<a href="<?= htmlspecialchars($cardifySwitchUrl) ?>"
   class="cardify-lang-switch"
   rel="nofollow"
   aria-label="<?= $cardifyTargetLocale === 'ar' ? 'التحويل إلى العربية' : 'Switch to English' ?>"
   title="<?= $cardifyTargetLocale === 'ar' ? 'التحويل إلى العربية' : 'Switch to English' ?>">
    <i class="fa-solid fa-globe" aria-hidden="true"></i>
    <span><?= htmlspecialchars($cardifyTargetLabel) ?></span>
</a>
