<?php
/**
 * Cardify language switcher, renders a single pill button that flips locales.
 * Include anywhere inside <body> where a top-nav item fits.
 *
 * Usage: <?php require INCLUDES_DIR . '/lang-switcher.php'; ?>
 *
 * It used to append ?lang=xx to the CURRENT path, so the switch never left the
 * page it was on: /pricing offered /pricing?lang=ar (an English URL wearing an
 * Arabic body) while the real Arabic twin /ar/pricing was reachable only from
 * a sitemap, and /ar/logos/press offered /ar/logos/press?lang=en, an Arabic URL
 * serving an English body. Every hop minted a duplicate of a URL that already
 * existed in the other language, which is the opposite of what a hreflang pair
 * is for.
 *
 * It now resolves the TWIN PATH from ArTwins, the same map that feeds hreflang
 * and the sitemap, and renders NOTHING when the target locale has no twin. A
 * switch that lands on an untranslated page is worse than no switch: it tells
 * the reader Arabic exists here when it does not, and it hands a crawler a
 * second URL for the same English text.
 *
 * App surfaces (admin, portal) are not part of the public /ar/ tree and have no
 * twin paths at all, so they opt into the old query behaviour explicitly:
 *
 *   $cardifyLangSwitchMode = 'query';
 *   require INCLUDES_DIR . '/lang-switcher.php';
 */
if (!function_exists('currentLocale')) { return; }
require_once __DIR__ . '/ArTwins.php';

$cardifyCurrentLocale = currentLocale();
$cardifyTargetLocale  = ($cardifyCurrentLocale === 'ar') ? 'en' : 'ar';
$cardifyTargetLabel   = ($cardifyTargetLocale === 'ar') ? 'العربية' : 'English';

$cardifyReqUri  = $_SERVER['REQUEST_URI'] ?? '/';
$cardifyReqPath = parse_url($cardifyReqUri, PHP_URL_PATH) ?: '/';
$cardifyReqQs   = parse_url($cardifyReqUri, PHP_URL_QUERY) ?: '';

$cardifyMode = isset($cardifyLangSwitchMode) ? $cardifyLangSwitchMode : 'path';
$cardifySwitchUrl = null;

if ($cardifyMode === 'query') {
    // App surface: no public twin tree, keep the locale flag on the same URL.
    parse_str($cardifyReqQs, $cardifyQsArr);
    $cardifyQsArr['lang'] = $cardifyTargetLocale;
    $cardifySwitchUrl = $cardifyReqPath . '?' . http_build_query($cardifyQsArr);
} elseif ($cardifyTargetLocale === 'en') {
    // Leaving Arabic always has a destination: every /ar/ route mirrors an EN
    // route. When the path is already English the page is Arabic only because
    // of the sticky locale cookie, so ?lang=en on the same URL is the honest
    // target: it clears the cookie without minting a new path.
    $cardifySwitchUrl = ArTwins::isArabic($cardifyReqPath)
        ? ArTwins::normalise($cardifyReqPath)
        : $cardifyReqPath . '?lang=en';
} else {
    // Entering Arabic only where an Arabic URL actually exists.
    $cardifyArUrl = ArTwins::arPath($cardifyReqPath);
    if ($cardifyArUrl === null) { return; }   // no twin: render nothing
    $cardifySwitchUrl = $cardifyArUrl;
}

if ($cardifySwitchUrl === null) { return; }
?>
<a href="<?= htmlspecialchars($cardifySwitchUrl) ?>"
   class="cardify-lang-switch"
   rel="nofollow"
   aria-label="<?= $cardifyTargetLocale === 'ar' ? 'التحويل إلى العربية' : 'Switch to English' ?>"
   title="<?= $cardifyTargetLocale === 'ar' ? 'التحويل إلى العربية' : 'Switch to English' ?>">
    <i class="fa-solid fa-globe" aria-hidden="true"></i>
    <span><?= htmlspecialchars($cardifyTargetLabel) ?></span>
</a>
