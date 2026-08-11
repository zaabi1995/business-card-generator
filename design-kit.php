<?php
/**
 * Cardify, Beautiful UI kit
 *
 * All 19 Beautiful UI primitives, verbatim, rendered by the React island
 * (web-react/). The live reference for the AI-native surfaces Cardify is
 * moving to: scan review, card generation, bulk employee import, print-order
 * status, admin grids.
 *
 * Companion to design-showcase.php, which documents the CURRENT Tailwind v3 /
 * Flowbite language. Keep both: one is where Cardify is, one is where it is
 * going.
 *
 * Route: cardify.om/design-kit.php
 * Internal reference page, noindex.
 */
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/react_island.php';

$pageTitle = 'Beautiful UI kit, Cardify';
$pageDescription = 'The 19 Beautiful UI primitives running inside Cardify.';
$canonicalUrl = 'https://cardify.om/design-kit';
$showNavigation = true;
$metaRobots = 'noindex, nofollow';
$bodyClass = 'bg-white';

require_once INCLUDES_DIR . '/ui-header.php';

// Above the fold on this page, so the preload hint earns its place.
cardify_react_island(true);
?>

<main class="mx-auto max-w-[1000px] px-4 py-10">
  <?php cardify_react_mount('kit'); ?>

  <noscript>
    <p class="text-sm text-gray-600">
      This page renders with JavaScript. The kit source lives in
      <code class="font-mono text-xs">web-react/src/beautiful-ui/</code>.
    </p>
  </noscript>
</main>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
