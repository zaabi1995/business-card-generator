<?php
/**
 * wc.cardify.om/predictions - the prediction game. Pick winners (1pt) and
 * optionally the exact score (+2). Locks at kickoff. Powered by Cardify.
 */
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/WcHub.php';

$user = WcHub::currentUser();
if (!$user) { header('Location: https://wc.cardify.om/'); exit; }
$lang = WcHub::lang($user['language'] ?? 'en');
$P    = WcHub::pstrings($lang);
$dir  = WcHub::isRtl($lang) ? 'rtl' : 'ltr';
$tz   = $user['tz'] ?: 'Asia/Muscat';
try { $tzObj = new DateTimeZone($tz); } catch (Throwable $e) { $tzObj = new DateTimeZone('Asia/Muscat'); }
$nowUtc = new DateTime('now', new DateTimeZone('UTC'));

$db = Database::getInstance();
$all = $db->fetchAll("SELECT * FROM wc_matches ORDER BY kickoff_utc ASC");
$myPredRows = $db->fetchAll("SELECT * FROM wc_predictions WHERE user_id=:u", ['u'=>$user['id']]);
$myPred = [];
foreach ($myPredRows as $r) $myPred[$r['match_id']] = $r;

$rank = (int)($db->fetchOne("SELECT COUNT(*)+1 AS r FROM wc_users WHERE status='active' AND points_cache > :p",
    ['p'=>(int)$user['points_cache']])['r'] ?? 1);

// split
$live=[]; $upcoming=[]; $results=[];
foreach ($all as $m) {
    $ko = new DateTime($m['kickoff_utc'], new DateTimeZone('UTC'));
    if (($m['state'] ?? '')==='in') $live[]=$m;
    elseif (($m['state'] ?? '')==='post') $results[]=$m;
    elseif ($ko > $nowUtc) $upcoming[]=$m;
}
$results = array_slice(array_reverse($results), 0, 14);
function fh($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function kostr($utc,$tzObj){ $d=new DateTime($utc,new DateTimeZone('UTC')); $d->setTimezone($tzObj); return $d->format('D j M, H:i'); }

function matchCard($m,$myPred,$tzObj,$nowUtc,$P,$locked=null){
    $id=$m['espn_id']; $pred=$myPred[$id]??null;
    $ko=new DateTime($m['kickoff_utc'],new DateTimeZone('UTC'));
    $isLocked = $locked!==null ? $locked : ($nowUtc>=$ko);
    $finished = ($m['state']??'')==='post';
    $sel=$pred['pick']??''; $ph=$pred['pred_home']??''; $pa=$pred['pred_away']??'';
    ob_start(); ?>
    <div class="bg-white rounded-2xl p-4 shadow-sm border border-slate-100" data-match="<?= fh($id) ?>">
      <div class="flex items-center justify-between text-xs text-slate-400 mb-2">
        <span><?= fh(kostr($m['kickoff_utc'],$tzObj)) ?></span>
        <?php if($finished): ?><span class="font-bold text-slate-500"><?= fh($P['results']) ?></span>
        <?php elseif($isLocked): ?><span class="font-bold text-amber-500"><i class="fa-solid fa-lock"></i> <?= fh($P['locked']) ?></span>
        <?php else: ?><span class="saved-badge <?= $pred?'':'hidden' ?> text-emerald-600 font-semibold"><i class="fa-solid fa-circle-check"></i> <?= fh($P['saved']) ?></span><?php endif; ?>
      </div>
      <div class="flex items-center justify-center gap-3 text-center mb-3">
        <div class="flex-1 font-semibold text-slate-800 text-[15px]"><?= fh($m['home']) ?></div>
        <?php if($finished): ?>
          <div class="px-3 py-1 rounded-lg bg-slate-900 text-white font-extrabold text-lg"><?= (int)$m['home_score'] ?> - <?= (int)$m['away_score'] ?></div>
        <?php else: ?>
          <div class="text-slate-300 font-bold">vs</div>
        <?php endif; ?>
        <div class="flex-1 font-semibold text-slate-800 text-[15px]"><?= fh($m['away']) ?></div>
      </div>
      <?php if($finished): ?>
        <?php if($pred): $pts=(int)$pred['points']; ?>
          <div class="text-center text-sm <?= $pts>0?'text-emerald-600 font-bold':'text-slate-400' ?>">
            <?= fh($P['you']) ?>: <?= $sel==='home'?fh($m['home']):($sel==='away'?fh($m['away']):fh($P['draw'])) ?>
            <?= ($ph!==''&&$pa!=='')?" ($ph-$pa)":"" ?> · +<?= $pts ?> <?= fh($P['points']) ?>
          </div>
        <?php else: ?><div class="text-center text-xs text-slate-300">-</div><?php endif; ?>
      <?php elseif($isLocked): ?>
        <div class="text-center text-sm text-slate-500"><?= $pred ? fh($P['you']).': '.($sel==='home'?fh($m['home']):($sel==='away'?fh($m['away']):fh($P['draw']))) : fh($P['locked']) ?></div>
      <?php else: ?>
        <div class="grid grid-cols-3 gap-2 mb-2">
          <?php foreach(['home'=>$m['home'],'draw'=>$P['draw'],'away'=>$m['away']] as $k=>$labelv): ?>
            <button type="button" data-pick="<?= $k ?>" class="pick-btn rounded-xl py-2 text-sm font-semibold border <?= $sel===$k?'bg-blue-600 text-white border-blue-600':'bg-slate-50 text-slate-700 border-slate-200' ?>"><?= fh($labelv) ?></button>
          <?php endforeach; ?>
        </div>
        <div class="flex items-center justify-center gap-2 mb-2">
          <input type="number" min="0" max="50" value="<?= fh($ph) ?>" class="exact-h w-14 text-center rounded-lg border border-slate-200 py-1.5" placeholder="-">
          <span class="text-slate-300">-</span>
          <input type="number" min="0" max="50" value="<?= fh($pa) ?>" class="exact-a w-14 text-center rounded-lg border border-slate-200 py-1.5" placeholder="-">
          <span class="text-xs text-slate-400 ms-1"><?= fh($P['exact']) ?></span>
        </div>
        <button type="button" class="save-btn btn w-full rounded-xl py-2.5 text-sm font-bold text-white bg-blue-600"><?= fh($P['save']) ?></button>
      <?php endif; ?>
    </div>
    <?php return ob_get_clean();
}
?>
<!DOCTYPE html><html lang="<?= fh($lang) ?>" dir="<?= fh($dir) ?>"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= fh($P['predict']) ?> · Cardify</title>
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="alternate icon" href="/favicon.ico">
<link rel="stylesheet" href="https://fonts.bhd.om/css2?family=Outfit:wght@400;500;600;700;800&family=Cairo:wght@400;600;700;800&family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="https://design.bhd.om/fa/v7.2.0/css/fontawesome.min.css">
<link rel="stylesheet" href="https://design.bhd.om/fa/v7.2.0/css/light.min.css">
<link rel="stylesheet" href="https://design.bhd.om/fa/v7.2.0/css/solid.min.css">
<link rel="stylesheet" href="https://design.bhd.om/fa/v7.2.0/css/brands.min.css">
<script src="https://cdn.tailwindcss.com"></script>
<script>tailwind.config={theme:{extend:{fontFamily:{sans:['Outfit','IBM Plex Sans Arabic','sans-serif']}}}}</script>
<style>body{font-family:<?= WcHub::isRtl($lang)?"'Cairo','IBM Plex Sans Arabic',sans-serif":"'Outfit','IBM Plex Sans Arabic',sans-serif" ?>}.btn{transition:transform .16s cubic-bezier(.23,1,.32,1)}.btn:active{transform:scale(.97)}</style>
</head>
<body class="min-h-[100dvh] bg-[#f7f8fa] text-slate-900">
  <header class="sticky top-0 z-30 bg-[#f7f8fa]/85 backdrop-blur border-b border-slate-200/70">
    <div class="max-w-xl mx-auto px-5 h-16 flex items-center justify-between">
      <a href="https://wc.cardify.om/"><img src="/assets/images/logo.svg" alt="Cardify" class="h-7 w-auto"></a>
      <div class="flex items-center gap-1 text-sm">
        <a href="/wc-leaderboard" class="font-semibold text-blue-700 px-3 py-2 rounded-lg hover:bg-white"><?= fh($P['leaderboard']) ?></a>
        <a href="/wc-settings" class="text-slate-500 hover:text-slate-900 px-2.5 py-2 rounded-lg hover:bg-white" aria-label="<?= fh($P['settings']) ?>"><i class="fa-light fa-gear text-base"></i></a>
      </div>
    </div>
  </header>

  <header class="max-w-xl mx-auto px-5 pt-5">
    <div class="flex items-center gap-3">
      <div class="rounded-2xl bg-white border border-slate-200/70 shadow-sm px-4 py-3">
        <div class="text-2xl font-extrabold text-slate-900"><?= (int)$user['points_cache'] ?></div>
        <div class="text-[11px] uppercase tracking-wide text-slate-400"><?= fh($P['your_points']) ?></div>
      </div>
      <div class="rounded-2xl bg-white border border-slate-200/70 shadow-sm px-4 py-3">
        <div class="text-2xl font-extrabold text-slate-900">#<?= $rank ?></div>
        <div class="text-[11px] uppercase tracking-wide text-slate-400"><?= fh($P['rank']) ?></div>
      </div>
      <div class="flex-1 text-right text-[11px] text-amber-600 font-medium leading-tight"><i class="fa-solid fa-trophy"></i> <?= fh($P['prize_line']) ?></div>
    </div>
  </header>

  <main class="max-w-xl mx-auto px-5 py-5 space-y-5">
    <div class="rounded-2xl bg-white p-4 border border-slate-100">
      <div class="font-bold text-slate-800 mb-1"><?= fh($P['how_title']) ?></div>
      <p class="text-sm text-slate-500"><?= fh($P['how_body']) ?></p>
    </div>

    <?php if($live): ?><h2 class="font-bold text-slate-700"><i class="fa-solid fa-circle text-rose-500 text-[10px] align-middle"></i> <?= fh($P['live']) ?></h2>
      <div class="space-y-3"><?php foreach($live as $m) echo matchCard($m,$myPred,$tzObj,$nowUtc,$P,true); ?></div><?php endif; ?>

    <h2 class="font-bold text-slate-700"><?= fh($P['upcoming']) ?></h2>
    <?php if($upcoming): ?>
      <div class="space-y-3"><?php foreach(array_slice($upcoming,0,30) as $m) echo matchCard($m,$myPred,$tzObj,$nowUtc,$P); ?></div>
    <?php else: ?><p class="text-slate-400 text-sm"><?= fh($P['empty']) ?></p><?php endif; ?>

    <?php if($results): ?><h2 class="font-bold text-slate-700"><?= fh($P['results']) ?></h2>
      <div class="space-y-3"><?php foreach($results as $m) echo matchCard($m,$myPred,$tzObj,$nowUtc,$P,true); ?></div><?php endif; ?>

    <div class="text-center pt-2 pb-6">
      <span class="inline-flex items-center gap-2 text-xs text-slate-400">
        <img src="/assets/images/logo.svg" class="h-3.5 w-auto opacity-60"> powered by Cardify
      </span>
    </div>
  </main>

<script>
const T_SAVE=<?= json_encode($P['save']) ?>, T_SAVED=<?= json_encode($P['saved']) ?>;
document.querySelectorAll('[data-match]').forEach(card=>{
  const picks=card.querySelectorAll('.pick-btn');
  const save=card.querySelector('.save-btn');
  const badge=card.querySelector('.saved-badge');
  function dirty(){ // user changed something after a save -> show "save changes"
    if(save && save.dataset.saved==='1'){ save.dataset.saved=''; save.classList.remove('bg-emerald-600'); save.classList.add('bg-blue-600'); save.textContent=T_SAVE; }
  }
  picks.forEach(b=>b.addEventListener('click',()=>{
    picks.forEach(x=>x.className=x.className.replace('bg-blue-600 text-white border-blue-600','bg-slate-50 text-slate-700 border-slate-200'));
    b.className=b.className.replace('bg-slate-50 text-slate-700 border-slate-200','bg-blue-600 text-white border-blue-600');
    card.dataset.pick=b.dataset.pick; dirty();
  }));
  card.querySelectorAll('.exact-h,.exact-a').forEach(i=>i.addEventListener('input',dirty));
  if(save) save.addEventListener('click',async()=>{
    const h=card.querySelector('.exact-h'), a=card.querySelector('.exact-a');
    let pick=card.dataset.pick||'';
    const ph=h&&h.value!==''?+h.value:null, pa=a&&a.value!==''?+a.value:null;
    if(ph!==null&&pa!==null) pick=ph===pa?'draw':(ph>pa?'home':'away');
    if(!pick){const o=save.textContent;save.textContent='!';setTimeout(()=>save.textContent=o,1200);return;}
    save.disabled=true; const o=save.textContent; save.textContent='…';
    try{
      const r=await fetch('/api/wc-predict.php',{method:'POST',headers:{'Content-Type':'application/json'},
        body:JSON.stringify({match_id:card.dataset.match,pick,pred_home:ph,pred_away:pa})});
      const j=await r.json();
      if(j.ok){
        save.dataset.saved='1'; save.classList.remove('bg-blue-600'); save.classList.add('bg-emerald-600');
        save.innerHTML='<i class="fa-solid fa-circle-check"></i> '+T_SAVED;
        if(badge) badge.classList.remove('hidden');
      } else { save.textContent='!'; setTimeout(()=>save.textContent=o,1500); }
    }catch(_){ save.textContent='!'; setTimeout(()=>save.textContent=o,1500); }
    save.disabled=false;
  });
});
</script>
</body></html>
