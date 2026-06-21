<?php
/** wc.cardify.om/wc-wallet - Apple/Google Wallet pass (daily-updating). Placeholder until Phase 5. */
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/WcHub.php';
require_once INCLUDES_DIR . '/GoogleWalletPass.php';
require_once INCLUDES_DIR . '/AppleWalletPass.php';
$user = WcHub::currentUser();
$lang = WcHub::lang($_GET['lang'] ?? ($user['language'] ?? 'en'));
$dir = WcHub::isRtl($lang) ? 'rtl' : 'ltr';
$P    = WcHub::pstrings($lang);
$rtl  = WcHub::isRtl($lang);
$googleWallet = $user && GoogleWalletPass::isEnabled();
$appleWallet  = $user && AppleWalletPass::isEnabled();
function wh($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html><html lang="<?= wh($lang) ?>" dir="<?= wh($dir) ?>"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#f7f8fa">
<title><?= wh($P['wallet_title']) ?> · Cardify</title>
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="alternate icon" href="/favicon.ico">
<link rel="preconnect" href="https://fonts.bhd.om" crossorigin>
<link rel="preconnect" href="https://design.bhd.om" crossorigin>
<link rel="stylesheet" href="/assets/wc/wc.css?v=1">
<link rel="stylesheet" href="https://fonts.bhd.om/css2?family=Outfit:wght@400;500;600;700;800&family=Cairo:wght@400;600;700;800&family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="https://design.bhd.om/fa/v7.2.0/css/fontawesome.min.css">
<link rel="stylesheet" href="https://design.bhd.om/fa/v7.2.0/css/light.min.css">
<link rel="stylesheet" href="https://design.bhd.om/fa/v7.2.0/css/solid.min.css">
<link rel="stylesheet" href="https://design.bhd.om/fa/v7.2.0/css/brands.min.css">
<style>body{font-family:<?= WcHub::isRtl($lang)?"'Cairo','IBM Plex Sans Arabic',sans-serif":"'Outfit','IBM Plex Sans Arabic',sans-serif" ?>}.btn{transition:transform .16s cubic-bezier(.23,1,.32,1)}.btn:active{transform:scale(.97)}</style></head>
<body class="min-h-[100dvh] grid place-items-center bg-[#f7f8fa] text-slate-900 p-6">
  <main class="max-w-sm w-full bg-white rounded-3xl p-7 text-center border border-slate-200/70 shadow-[0_24px_48px_-24px_rgba(15,23,42,0.18)]">
    <img src="/assets/images/logo.svg" alt="Cardify" class="h-7 w-auto mx-auto mb-4">
    <div class="mb-3"><span class="grid place-items-center w-16 h-16 mx-auto rounded-2xl bg-blue-50 text-blue-600"><i class="fa-light fa-ticket text-3xl" aria-hidden="true"></i></span></div>
    <h1 class="text-xl font-bold mb-2 text-slate-900"><?= wh($P['wallet_title']) ?></h1>
    <?php if($appleWallet || $googleWallet): ?>
      <!-- Player pass: points / rank / streak -->
      <p class="text-slate-500 text-sm mb-2 leading-relaxed"><?= wh($P['wallet_have']) ?></p>
      <p class="text-[11px] font-semibold uppercase tracking-wide text-blue-700/80 mb-2.5 flex items-center justify-center gap-1.5"><i class="fa-light fa-star text-amber-500" aria-hidden="true"></i> <?= wh($P['wallet_player_label']) ?></p>
      <div class="space-y-2.5">
      <?php if($appleWallet): ?>
        <a href="/wc-wallet-apple" class="btn inline-flex items-center justify-center gap-2 w-full rounded-2xl px-5 py-3.5 font-bold text-white bg-slate-900 hover:bg-black"><i class="fa-brands fa-apple" aria-hidden="true"></i> <?= wh($P['add_apple']) ?></a>
      <?php endif; ?>
      <?php if($googleWallet): ?>
        <a href="/wc-wallet-google" class="btn inline-flex items-center justify-center gap-2 w-full rounded-2xl px-5 py-3.5 font-bold text-slate-700 bg-slate-100 hover:bg-slate-200"><i class="fa-brands fa-google" aria-hidden="true"></i> <?= wh($P['add_google']) ?></a>
      <?php endif; ?>
      </div>

      <!-- Matches pass: today's fixtures -->
      <div class="my-5 border-t border-dashed border-slate-200"></div>
      <p class="text-slate-500 text-sm mb-2 leading-relaxed"><?= wh($P['wallet_matches_have']) ?></p>
      <p class="text-[11px] font-semibold uppercase tracking-wide text-blue-700/80 mb-2.5 flex items-center justify-center gap-1.5"><i class="fa-light fa-calendar-days text-blue-600" aria-hidden="true"></i> <?= wh($P['wallet_matches_label']) ?></p>
      <div class="space-y-2.5">
      <?php if($appleWallet): ?>
        <a href="/wc-wallet-apple-matches" class="btn inline-flex items-center justify-center gap-2 w-full rounded-2xl px-5 py-3.5 font-bold text-white bg-blue-600 hover:bg-blue-700"><i class="fa-brands fa-apple" aria-hidden="true"></i> <?= wh($P['add_apple_matches']) ?></a>
      <?php endif; ?>
      <?php if($googleWallet): ?>
        <a href="/wc-wallet-google-matches" class="btn inline-flex items-center justify-center gap-2 w-full rounded-2xl px-5 py-3.5 font-bold text-blue-700 bg-blue-50 hover:bg-blue-100"><i class="fa-brands fa-google" aria-hidden="true"></i> <?= wh($P['add_google_matches']) ?></a>
      <?php endif; ?>
      </div>
      <a href="<?= $user?'/predictions':'https://wc.cardify.om/' ?>" class="inline-block text-sm font-semibold text-blue-700 px-5 py-2.5 mt-3"><?= wh($P['back']) ?></a>
    <?php else: ?>
      <p class="text-slate-500 text-sm mb-5 leading-relaxed"><?= wh($P['wallet_soon']) ?></p>
      <a href="<?= $user?'/predictions':'https://wc.cardify.om/' ?>" class="btn inline-block w-full rounded-2xl px-5 py-3.5 font-bold text-white bg-blue-600 hover:bg-blue-700"><?= wh($P['back']) ?></a>
    <?php endif; ?>
  </main>
</body></html>
