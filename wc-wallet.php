<?php
/** wc.cardify.om/wc-wallet - Apple/Google Wallet pass (daily-updating). Placeholder until Phase 5. */
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/WcHub.php';
require_once INCLUDES_DIR . '/GoogleWalletPass.php';
require_once INCLUDES_DIR . '/AppleWalletPass.php';
$user = WcHub::currentUser();
$lang = WcHub::lang($user['language'] ?? 'en');
$dir = WcHub::isRtl($lang) ? 'rtl' : 'ltr';
$googleWallet = $user && GoogleWalletPass::isEnabled();
$appleWallet  = $user && AppleWalletPass::isEnabled();
function wh($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html><html lang="<?= wh($lang) ?>" dir="<?= wh($dir) ?>"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Wallet · Cardify</title>
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="alternate icon" href="/favicon.ico">
<link rel="stylesheet" href="https://fonts.bhd.om/css2?family=IBM+Plex+Sans+Arabic:wght@400;600;700&display=swap">
<link rel="stylesheet" href="https://design.bhd.om/fa/v7.2.0/css/fontawesome.min.css">
<link rel="stylesheet" href="https://design.bhd.om/fa/v7.2.0/css/light.min.css">
<link rel="stylesheet" href="https://design.bhd.om/fa/v7.2.0/css/solid.min.css">
<link rel="stylesheet" href="https://design.bhd.om/fa/v7.2.0/css/brands.min.css">
<script src="https://cdn.tailwindcss.com"></script>
<style>body{font-family:'IBM Plex Sans Arabic',system-ui,sans-serif}</style></head>
<body class="min-h-[100dvh] grid place-items-center bg-slate-900 p-6">
  <div class="max-w-sm w-full bg-white rounded-3xl p-7 text-center shadow-2xl">
    <img src="/assets/images/logo.svg" alt="Cardify" class="h-7 w-auto mx-auto mb-4">
    <div class="mb-3"><i class="fa-light fa-ticket text-blue-600 text-4xl"></i></div>
    <h1 class="text-xl font-bold mb-2 text-slate-900">Your World Cup pass</h1>
    <?php if($appleWallet || $googleWallet): ?>
      <p class="text-slate-500 text-sm mb-5">Save your points, rank, level and streak to your phone. The pass updates daily with your next match.</p>
      <?php if($appleWallet): ?>
        <a href="/wc-wallet-apple" class="inline-flex items-center justify-center gap-2 w-full rounded-2xl px-5 py-3 font-bold text-white bg-black mb-2.5"><i class="fa-brands fa-apple"></i> Add to Apple Wallet</a>
      <?php endif; ?>
      <?php if($googleWallet): ?>
        <a href="/wc-wallet-google" class="inline-flex items-center justify-center gap-2 w-full rounded-2xl px-5 py-3 font-bold text-white bg-slate-900 mb-2.5"><i class="fa-brands fa-google"></i> Add to Google Wallet</a>
      <?php endif; ?>
      <a href="<?= $user?'/predictions':'https://wc.cardify.om/' ?>" class="inline-block text-sm font-semibold text-blue-700 px-5 py-2.5">Back</a>
    <?php else: ?>
      <p class="text-slate-500 text-sm mb-5">A live pass with today's matches and your points, updated daily, is on the way.</p>
      <a href="<?= $user?'/predictions':'https://wc.cardify.om/' ?>" class="inline-block rounded-2xl px-5 py-3 font-bold text-white bg-blue-600">Back</a>
    <?php endif; ?>
  </div>
</body></html>
