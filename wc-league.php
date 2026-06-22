<?php
/**
 * wc.cardify.om/wc-league?l=CODE - a single mini-league's private board.
 * Ranks ONLY that league's members on the same prize-race points as the global
 * leaderboard (knockout-stage points + bonus). Masked names, current user
 * highlighted + sticky YOU row, podium for the top 3, invite link + WhatsApp
 * share so members can grow the league.
 */
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/WcHub.php';

$user = WcHub::currentUser();
$lang = WcHub::lang($_GET['lang'] ?? ($user['language'] ?? 'en'));
$P    = WcHub::pstrings($lang);
$L    = WcHub::lstrings($lang);
$dir  = WcHub::isRtl($lang) ? 'rtl' : 'ltr';
function lbh($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$code   = WcHub::normLeagueCode((string)($_GET['l'] ?? ''));
$league = $code !== '' ? WcHub::leagueByCode($code) : null;

// Unknown code: bounce to the hub (or the join confirm if logged out elsewhere).
if (!$league) { header('Location: /wc-leagues'); exit; }

$lid     = (int)$league['id'];
$isMember = $user ? WcHub::isLeagueMember($lid, (int)$user['id']) : false;

// A logged-in non-member who lands here gets the join confirm flow.
if ($user && !$isMember) { header('Location: /join?l=' . rawurlencode($code)); exit; }

$board = WcHub::leagueBoard($lid);
$shareUrl = WcHub::leagueShareUrl($code);

// Resolve the current user's rank inside this league.
$myRank = null; $myPts = 0;
if ($user) {
    foreach ($board as $idx=>$r) { if ((int)$r['id'] === (int)$user['id']) { $myRank = $idx+1; $myPts = (int)$r['pts']; break; } }
}

function lbMask($name,$phone){
    $first = trim(explode(' ', trim((string)$name))[0] ?? '');
    $first = $first !== '' ? mb_substr($first, 0, 12) : 'Player';
    $d = preg_replace('/\D/', '', (string)$phone);
    return lbh($first) . ' <span class="text-slate-400">****' . lbh(substr($d, -4)) . '</span>';
}
$waText = $L['share_text'] . ': ' . $shareUrl;
?>
<!DOCTYPE html><html lang="<?= lbh($lang) ?>" dir="<?= lbh($dir) ?>"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= lbh($league['name']) ?> · <?= lbh($L['league_board']) ?></title>
<meta name="robots" content="noindex">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="alternate icon" href="/favicon.ico">
<link rel="preconnect" href="https://fonts.bhd.om" crossorigin>
<link rel="preconnect" href="https://design.bhd.om" crossorigin>
<link rel="stylesheet" href="/assets/wc/wc.css?v=2">
<link rel="stylesheet" href="https://fonts.bhd.om/css2?family=Outfit:wght@400;500;600;700;800&family=Cairo:wght@400;600;700;800&family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="https://design.bhd.om/fa/v7.2.0/css/fontawesome.min.css">
<link rel="stylesheet" href="https://design.bhd.om/fa/v7.2.0/css/light.min.css">
<link rel="stylesheet" href="https://design.bhd.om/fa/v7.2.0/css/solid.min.css">
<link rel="stylesheet" href="https://design.bhd.om/fa/v7.2.0/css/brands.min.css">
<style>
  body{font-family:<?= WcHub::isRtl($lang)?"'Cairo','IBM Plex Sans Arabic',sans-serif":"'Outfit','IBM Plex Sans Arabic',sans-serif" ?>}
  @keyframes riseIn{0%{opacity:0;transform:translateY(12px)}100%{opacity:1;transform:none}}
  .rise{animation:riseIn .5s cubic-bezier(.16,1,.3,1) both}
  .plinth-1{background:linear-gradient(180deg,#fef3c7,#fcd34d)}
  .plinth-2{background:linear-gradient(180deg,#f1f5f9,#cbd5e1)}
  .plinth-3{background:linear-gradient(180deg,#ffedd5,#fdba74)}
  .crown{filter:drop-shadow(0 2px 4px rgba(245,158,11,.4));animation:crownBob 2.4s ease-in-out infinite}
  @keyframes crownBob{0%,100%{transform:translateY(0)}50%{transform:translateY(-3px)}}
  .ldr-row{transition:background-color .15s}
  .btn{transition:transform .12s cubic-bezier(.16,1,.3,1),background-color .15s}
  .btn:active{transform:scale(.97)}
  @media (prefers-reduced-motion:reduce){.rise,.crown,.btn{animation:none;transition:none}}
</style>
</head>
<body class="min-h-[100dvh] bg-[#f7f8fa] text-slate-900 pb-24">
  <header class="sticky top-0 z-30 bg-[#f7f8fa]/85 backdrop-blur border-b border-slate-200/70">
    <div class="max-w-xl mx-auto px-5 h-16 flex items-center justify-between">
      <a href="/wc-leagues" class="inline-flex items-center gap-2 text-sm font-semibold text-slate-600 hover:text-slate-900">
        <i class="fa-solid fa-chevron-<?= $dir==='rtl'?'right':'left' ?> text-xs"></i> <?= lbh($L['back_leagues']) ?>
      </a>
      <?php if($user): ?><a href="/predictions" class="text-sm font-semibold text-blue-700 px-3 py-2 rounded-lg hover:bg-white"><?= lbh($P['predict']) ?></a><?php endif; ?>
    </div>
  </header>

  <main class="max-w-xl mx-auto px-5 py-6">
    <!-- League header -->
    <div class="flex items-center gap-3 mb-1">
      <span class="grid place-items-center w-11 h-11 rounded-xl bg-gradient-to-br from-blue-500 to-blue-700 text-white font-extrabold text-lg shrink-0"><?= lbh(mb_strtoupper(mb_substr(trim($league['name']),0,1))) ?></span>
      <div class="min-w-0">
        <h1 class="text-xl font-extrabold text-slate-900 truncate"><?= lbh($league['name']) ?></h1>
        <div class="text-[12px] text-slate-400"><i class="fa-solid fa-user-group text-[10px] me-1"></i><?= count($board) ?> <?= lbh(count($board)===1?$L['member']:$L['members']) ?> <span class="mx-1 text-slate-300">·</span> <span class="font-mono tracking-wider text-slate-500"><?= lbh($league['code']) ?></span></div>
      </div>
    </div>

    <!-- Invite link + share, the growth hook -->
    <div class="rise bg-white rounded-2xl border border-slate-100 p-4 mt-4 mb-6">
      <div class="text-[11px] font-bold text-slate-500 uppercase tracking-wide mb-2"><?= lbh($L['invite_link']) ?></div>
      <div class="flex items-center gap-2">
        <div class="flex-1 min-w-0 rounded-xl bg-slate-50 border border-slate-200 px-3 py-2.5 text-[13px] text-slate-600 truncate font-mono" id="shareUrl"><?= lbh($shareUrl) ?></div>
        <button type="button" id="copyBtn" class="btn shrink-0 grid place-items-center w-11 h-11 rounded-xl bg-slate-100 text-slate-600 hover:bg-slate-200" aria-label="<?= lbh($L['copy']) ?>"><i class="fa-solid fa-copy"></i></button>
      </div>
      <a href="https://api.whatsapp.com/send?text=<?= rawurlencode($waText) ?>" target="_blank" rel="noopener" class="btn mt-3 flex items-center justify-center gap-2.5 w-full rounded-xl py-3 font-semibold text-white bg-[#25D366] hover:brightness-105">
        <i class="fa-brands fa-whatsapp text-lg"></i> <?= lbh($L['share_wa']) ?>
      </a>
    </div>

    <?php
      $podOrder = [1,0,2];
      $medalBg=[1=>'plinth-1',2=>'plinth-2',3=>'plinth-3'];
      $medalIc=[1=>'text-amber-500',2=>'text-slate-400',3=>'text-orange-400'];
    ?>
    <?php if(count($board) >= 2): ?>
    <!-- Podium: 2-1-3 layout for the league's top 3 -->
    <div class="rise grid grid-cols-3 gap-2 items-end mb-6">
      <?php foreach($podOrder as $slot): $row=$board[$slot]??null; $r=$slot+1;
        if(!$row){ echo '<div></div>'; continue; }
        $me=$user && (int)$row['id']===(int)$user['id'];
        $plinthH = $r===1?'h-20':($r===2?'h-16':'h-12'); ?>
      <div class="text-center">
        <?php if($r===1): ?><div class="crown text-amber-400 text-lg mb-0.5"><i class="fa-solid fa-crown"></i></div><?php endif; ?>
        <div class="grid place-items-center w-12 h-12 mx-auto rounded-full bg-white border-2 <?= $r===1?'border-amber-300':($r===2?'border-slate-300':'border-orange-200') ?> shadow-sm mb-1.5">
          <span class="font-extrabold <?= $medalIc[$r] ?> text-lg"><?= $r ?></span>
        </div>
        <div class="text-[13px] font-bold text-slate-800 leading-tight truncate px-0.5"><?= lbMask($row['name'],$row['phone']) ?></div>
        <div class="text-[11px] text-slate-400 mb-1.5"><?= (int)$row['pts'] ?> <?= lbh($P['points']) ?></div>
        <div class="<?= $medalBg[$r] ?> <?= $plinthH ?> rounded-t-xl flex items-center justify-center <?= $me?'ring-2 ring-blue-500':'' ?>">
          <span class="font-extrabold text-slate-900/70 text-xl"><i class="fa-solid fa-trophy"></i></span>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Ladder -->
    <div class="bg-white rounded-2xl overflow-hidden border border-slate-100">
      <?php if(!$board): ?>
        <div class="p-8 text-center">
          <span class="grid place-items-center w-12 h-12 mx-auto rounded-full bg-slate-100 text-slate-300 mb-3"><i class="fa-solid fa-ranking-star text-xl" aria-hidden="true"></i></span>
          <p class="text-slate-400 text-sm"><?= lbh($L['board_empty']) ?></p>
        </div>
      <?php elseif(count($board)===1): ?>
        <div class="p-8 text-center">
          <span class="grid place-items-center w-12 h-12 mx-auto rounded-full bg-blue-50 text-blue-500 mb-3"><i class="fa-solid fa-user-plus text-xl" aria-hidden="true"></i></span>
          <p class="text-slate-500 text-sm font-semibold"><?= lbh($L['board_solo']) ?></p>
        </div>
      <?php else: foreach($board as $i=>$row): $r=$i+1; if($r<=3) continue;
        $me = $user && (int)$row['id']===(int)$user['id']; ?>
        <div class="ldr-row flex items-center gap-3 px-4 py-3 border-b border-slate-50 last:border-0 <?= $me?'bg-blue-50':'' ?>">
          <div class="w-7 text-center font-bold text-slate-400 text-sm"><?= $r ?></div>
          <div class="flex-1 min-w-0 text-slate-800 text-[15px] truncate"><?= lbMask($row['name'],$row['phone']) ?><?= $me?' · '.lbh($P['you']):'' ?></div>
          <div class="font-extrabold text-slate-900 shrink-0"><?= (int)$row['pts'] ?> <span class="text-[11px] font-normal text-slate-400"><?= lbh($P['points']) ?></span></div>
        </div>
      <?php endforeach; endif; ?>
    </div>

    <div class="text-center pt-5 pb-2">
      <span class="inline-flex items-center gap-2 text-xs text-slate-400">
        <img src="/assets/images/logo.svg" class="h-3.5 w-auto opacity-60"> powered by Cardify
      </span>
    </div>
  </main>

  <?php if($user && $myRank): ?>
  <!-- Sticky YOU bar -->
  <div class="fixed bottom-0 inset-x-0 z-30">
    <div class="max-w-xl mx-auto px-4 pb-4">
      <div class="rounded-2xl bg-blue-600 text-white px-4 py-3 flex items-center justify-between shadow-xl shadow-blue-600/30">
        <span class="font-semibold inline-flex items-center gap-2">
          <span class="grid place-items-center w-7 h-7 rounded-full bg-white/20 text-xs font-bold">#<?= (int)$myRank ?></span>
          <?= lbh($P['you']) ?>
        </span>
        <span class="font-extrabold text-lg"><?= (int)$myPts ?> <span class="text-xs font-normal text-white/80"><?= lbh($P['points']) ?></span></span>
      </div>
    </div>
  </div>
  <?php endif; ?>

<script>
const copyBtn = document.getElementById('copyBtn');
const SHARE = <?= json_encode($shareUrl) ?>;
const COPIED = <?= json_encode($L['copied']) ?>;
copyBtn.addEventListener('click', async ()=>{
  try{ await navigator.clipboard.writeText(SHARE); }
  catch(_){ const t=document.createElement('textarea'); t.value=SHARE; document.body.appendChild(t); t.select(); try{document.execCommand('copy');}catch(e){} t.remove(); }
  const html = copyBtn.innerHTML;
  copyBtn.innerHTML = '<i class="fa-solid fa-check"></i>';
  copyBtn.classList.add('!bg-emerald-100','!text-emerald-600');
  setTimeout(()=>{ copyBtn.innerHTML = html; copyBtn.classList.remove('!bg-emerald-100','!text-emerald-600'); }, 1600);
});
</script>
</body></html>
