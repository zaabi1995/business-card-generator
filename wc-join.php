<?php
/**
 * wc.cardify.om/join?l=CODE - the viral join entry point.
 *
 * Logged in  -> show a "Join <league name>?" confirm + Join button (POSTs to
 *               api/wc-league-join.php, idempotent). Already a member -> straight
 *               to the board.
 * Logged out -> send to the landing with ?l=CODE preserved. The landing stashes
 *               it (mirror of the ?ref= pattern); after OTP verify the user is
 *               auto-joined to the league, so: friend shares -> new person signs
 *               up -> auto-joins the friend's board.
 */
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/WcHub.php';

$user = WcHub::currentUser();
$lang = WcHub::lang($_GET['lang'] ?? ($user['language'] ?? 'en'));
$P    = WcHub::pstrings($lang);
$L    = WcHub::lstrings($lang);
$dir  = WcHub::isRtl($lang) ? 'rtl' : 'ltr';
function jh($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$code   = WcHub::normLeagueCode((string)($_GET['l'] ?? ''));
$league = $code !== '' ? WcHub::leagueByCode($code) : null;

// Logged out: bounce to the landing, preserving the league code so signup can
// auto-join. The landing JS reads ?l= and threads it through OTP verify.
if (!$user) {
    $q = 'l=' . rawurlencode($code);
    if ($lang !== 'en') $q .= '&lang=' . rawurlencode($lang);
    header('Location: https://wc.cardify.om/?' . $q);
    exit;
}

// Logged in but the code is bad: send to the hub.
if (!$league) { header('Location: /wc-leagues'); exit; }

// Already a member: straight to the board.
if (WcHub::isLeagueMember((int)$league['id'], (int)$user['id'])) {
    header('Location: /wc-league?l=' . rawurlencode($code));
    exit;
}

$boardUrl = '/wc-league?l=' . rawurlencode($code);
$memberCount = WcHub::leagueMemberCount((int)$league['id']);
?>
<!DOCTYPE html><html lang="<?= jh($lang) ?>" dir="<?= jh($dir) ?>"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= jh($L['confirm_join']) ?> · Cardify</title>
<meta name="robots" content="noindex">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="alternate icon" href="/favicon.ico">
<link rel="preconnect" href="https://fonts.bhd.om" crossorigin>
<link rel="preconnect" href="https://design.bhd.om" crossorigin>
<link rel="stylesheet" href="/assets/wc/wc.css?v=1">
<link rel="stylesheet" href="https://fonts.bhd.om/css2?family=Outfit:wght@400;500;600;700;800&family=Cairo:wght@400;600;700;800&family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="https://design.bhd.om/fa/v7.2.0/css/fontawesome.min.css">
<link rel="stylesheet" href="https://design.bhd.om/fa/v7.2.0/css/light.min.css">
<link rel="stylesheet" href="https://design.bhd.om/fa/v7.2.0/css/solid.min.css">
<style>
  body{font-family:<?= WcHub::isRtl($lang)?"'Cairo','IBM Plex Sans Arabic',sans-serif":"'Outfit','IBM Plex Sans Arabic',sans-serif" ?>}
  @keyframes riseIn{0%{opacity:0;transform:translateY(12px)}100%{opacity:1;transform:none}}
  .rise{animation:riseIn .5s cubic-bezier(.16,1,.3,1) both}
  .btn{transition:transform .12s cubic-bezier(.16,1,.3,1),background-color .15s}
  .btn:active{transform:scale(.97)}
  @media (prefers-reduced-motion:reduce){.rise,.btn{animation:none;transition:none}}
</style>
</head>
<body class="min-h-[100dvh] bg-[#f7f8fa] text-slate-900 grid place-items-center px-5">
  <main class="rise w-full max-w-sm">
    <div class="bg-white rounded-3xl border border-slate-100 shadow-sm p-7 text-center">
      <span class="grid place-items-center w-16 h-16 mx-auto rounded-2xl bg-gradient-to-br from-blue-500 to-blue-700 text-white font-extrabold text-2xl mb-4"><?= jh(mb_strtoupper(mb_substr(trim($league['name']),0,1))) ?></span>
      <p class="text-sm text-slate-400 mb-1"><?= jh($L['confirm_join_in']) ?></p>
      <h1 class="text-2xl font-extrabold text-slate-900 leading-tight mb-1"><?= jh($league['name']) ?></h1>
      <div class="text-[12px] text-slate-400 mb-6"><i class="fa-solid fa-user-group text-[10px] me-1"></i><?= $memberCount ?> <?= jh($memberCount===1?$L['member']:$L['members']) ?></div>
      <button type="button" id="joinBtn" class="btn w-full rounded-xl py-3.5 font-semibold text-white bg-blue-600 hover:bg-blue-700 disabled:opacity-60"><?= jh($L['join_cta']) ?></button>
      <a href="/wc-leagues" class="block mt-3 text-[13px] font-semibold text-slate-500 hover:text-slate-800"><?= jh($L['back_leagues']) ?></a>
      <p id="joinErr" class="hidden text-[12px] text-rose-600 mt-3"></p>
    </div>
    <div class="text-center pt-5">
      <span class="inline-flex items-center gap-2 text-xs text-slate-400"><img src="/assets/images/logo.svg" class="h-3.5 w-auto opacity-60"> powered by Cardify</span>
    </div>
  </main>
<script>
const CODE = <?= json_encode($code) ?>;
const BOARD = <?= json_encode($boardUrl) ?>;
const ERR = <?= json_encode([
  'l_err_full'=>$L['l_err_full'],'l_err_notfound'=>$L['l_err_notfound'],
  'l_err_generic'=>$L['l_err_generic'],'l_err_auth'=>$L['l_err_auth'],
], JSON_UNESCAPED_UNICODE) ?>;
const btn = document.getElementById('joinBtn');
const errEl = document.getElementById('joinErr');
btn.addEventListener('click', async ()=>{
  btn.disabled = true; errEl.classList.add('hidden');
  try{
    const r = await fetch('/api/wc-league-join.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({code:CODE})});
    const j = await r.json();
    if(j.ok){ location.href = j.board || BOARD; return; }
    errEl.textContent = ERR[j.error] || ERR['l_err_generic']; errEl.classList.remove('hidden');
  }catch(_){ errEl.textContent = ERR['l_err_generic']; errEl.classList.remove('hidden'); }
  btn.disabled = false;
});
</script>
</body></html>
