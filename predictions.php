<?php
/**
 * wc.cardify.om/predictions - the prediction game. Pick winners (1pt) and
 * optionally the exact score (+2). Locks at kickoff. Powered by Cardify.
 */
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/WcHub.php';
require_once INCLUDES_DIR . '/AppleWalletPass.php';
require_once INCLUDES_DIR . '/GoogleWalletPass.php';

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

// Gamification: streak state + earned badges (all derived from real data).
$checkedToday = WcHub::checkedInToday($user);
$streakCount  = (int)($user['streak_count'] ?? 0);
$streakBest   = (int)($user['streak_best'] ?? 0);
$badges       = WcHub::badges($user);
$badgesEarned = count(array_filter($badges, fn($b)=>$b['earned']));

// Player level + XP progress, derived from the lifetime points total.
$points = (int)$user['points_cache'];
$lvl    = WcHub::levelOf($points);
$flame  = min(1.0 + $streakCount * 0.07, 1.6); // flame scale grows with streak, capped
// 7-dot week tracker: how many of the last (up to 7) days are inside the current run.
$weekFilled = min($streakCount, 7);

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
    $won = $finished && $pred && (int)($pred['points']??0)>0;
    ob_start(); ?>
    <div class="bg-white rounded-2xl p-4 shadow-sm border <?= $won?'border-emerald-200 win-card':'border-slate-100' ?>" data-match="<?= fh($id) ?>">
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
            <button type="button" data-pick="<?= $k ?>" aria-pressed="<?= $sel===$k?'true':'false' ?>" class="pick-btn rounded-xl py-2 text-sm font-semibold border <?= $sel===$k?'bg-blue-600 text-white border-blue-600':'bg-slate-50 text-slate-700 border-slate-200' ?>"><?= fh($labelv) ?></button>
          <?php endforeach; ?>
        </div>
        <div class="flex items-center justify-center gap-2 mb-2">
          <input type="number" inputmode="numeric" min="0" max="50" value="<?= fh($ph) ?>" aria-label="<?= fh($m['home']).' '.fh($P['exact']) ?>" class="exact-h w-14 text-center rounded-lg border border-slate-200 py-1.5" placeholder="-">
          <span class="text-slate-300" aria-hidden="true">-</span>
          <input type="number" inputmode="numeric" min="0" max="50" value="<?= fh($pa) ?>" aria-label="<?= fh($m['away']).' '.fh($P['exact']) ?>" class="exact-a w-14 text-center rounded-lg border border-slate-200 py-1.5" placeholder="-">
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
<link rel="preconnect" href="https://fonts.bhd.om" crossorigin>
<link rel="preconnect" href="https://design.bhd.om" crossorigin>
<link rel="stylesheet" href="/assets/wc/wc.css?v=1">
<link rel="stylesheet" href="https://fonts.bhd.om/css2?family=Outfit:wght@400;500;600;700;800&family=Cairo:wght@400;600;700;800&family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="https://design.bhd.om/fa/v7.2.0/css/fontawesome.min.css">
<link rel="stylesheet" href="https://design.bhd.om/fa/v7.2.0/css/light.min.css">
<link rel="stylesheet" href="https://design.bhd.om/fa/v7.2.0/css/solid.min.css">
<link rel="stylesheet" href="https://design.bhd.om/fa/v7.2.0/css/brands.min.css">
<style>
  body{font-family:<?= WcHub::isRtl($lang)?"'Cairo','IBM Plex Sans Arabic',sans-serif":"'Outfit','IBM Plex Sans Arabic',sans-serif" ?>}
  /* ease-out-quart everywhere for a premium, weighty feel */
  .btn{transition:transform .16s cubic-bezier(.23,1,.32,1)}
  .btn:active{transform:scale(.96)}
  .pick-btn{transition:transform .14s cubic-bezier(.23,1,.32,1),background-color .14s,border-color .14s,color .14s}
  .pick-btn:active{transform:scale(.94)}
  /* HUD hero: deep brand gradient + soft inner sheen */
  .hud{background:linear-gradient(135deg,#1e3a8a 0%,#2563eb 52%,#1d4ed8 100%);box-shadow:0 18px 40px -18px rgba(37,99,235,.55)}
  .hud-sheen{background:radial-gradient(120% 80% at 85% -10%,rgba(255,255,255,.22),transparent 60%)}
  /* XP bar fill animates from 0 to its target width on load */
  .xp-track{background:rgba(255,255,255,.18)}
  .xp-fill{background:linear-gradient(90deg,#F2C14E,#fde68a);width:0;transition:width 1.1s cubic-bezier(.16,1,.3,1)}
  /* level ring conic-gradient progress */
  .lvl-ring{background:conic-gradient(#F2C14E calc(var(--p)*1%),rgba(255,255,255,.16) 0)}
  /* flame breathes; scale set inline per streak */
  @keyframes flameBreathe{0%,100%{transform:scale(var(--fs,1)) translateY(0)}50%{transform:scale(calc(var(--fs,1)*1.08)) translateY(-1px)}}
  .flame-live{animation:flameBreathe 1.5s ease-in-out infinite;transform-origin:bottom center}
  /* week dots fill in sequence */
  @keyframes dotPop{0%{transform:scale(.4);opacity:0}60%{transform:scale(1.18)}100%{transform:scale(1);opacity:1}}
  .wk-on{animation:dotPop .42s cubic-bezier(.16,1,.3,1) both}
  /* badge shine sweep on earned tiles */
  @keyframes badgeShine{0%{transform:translateX(-130%)}100%{transform:translateX(130%)}}
  .badge-shine{position:absolute;inset:0;border-radius:1rem;overflow:hidden;pointer-events:none}
  .badge-shine::after{content:"";position:absolute;top:0;left:0;width:55%;height:100%;background:linear-gradient(105deg,transparent,rgba(255,255,255,.7),transparent);transform:translateX(-130%);animation:badgeShine 3.4s ease-in-out infinite;animation-delay:var(--sd,0s)}
  .badge-ic{transition:transform .22s cubic-bezier(.16,1,.3,1)}
  .badge-cell:active .badge-ic{transform:scale(.92)}
  @keyframes badgePop{0%{transform:scale(.6)}55%{transform:scale(1.3)}100%{transform:scale(1)}}
  .badge-pop{animation:badgePop .5s cubic-bezier(.16,1,.3,1)}
  /* count-up + check feedback on save */
  @keyframes savePulse{0%{transform:scale(1)}45%{transform:scale(1.05)}100%{transform:scale(1)}}
  .save-ok{animation:savePulse .45s cubic-bezier(.16,1,.3,1)}
  /* correct-result celebration ring */
  @keyframes winGlow{0%{box-shadow:0 0 0 0 rgba(16,185,129,.45)}100%{box-shadow:0 0 0 14px rgba(16,185,129,0)}}
  .win-card{animation:winGlow 1.6s ease-out infinite}
  /* confetti pieces */
  .cf-pc{position:fixed;width:8px;height:13px;will-change:transform,opacity;pointer-events:none;z-index:60;border-radius:2px}
  @keyframes cfFall{to{transform:translate3d(var(--dx),var(--dy),0) rotate(var(--rot));opacity:0}}
  @media (prefers-reduced-motion: reduce){.flame-live,.badge-shine::after,.win-card{animation:none}.xp-fill{transition:none}}
  /* staggered card reveal */
  @keyframes riseIn{0%{opacity:0;transform:translateY(10px)}100%{opacity:1;transform:none}}
  .rise{animation:riseIn .5s cubic-bezier(.16,1,.3,1) both}
</style>
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

  <!-- Player HUD: the game profile card, hero of the page -->
  <section class="max-w-xl mx-auto px-5 pt-5">
    <div class="hud relative overflow-hidden rounded-3xl p-5 text-white">
      <div class="hud-sheen absolute inset-0 pointer-events-none"></div>
      <div class="relative flex items-center gap-4">
        <!-- Level ring -->
        <div class="lvl-ring grid place-items-center w-[68px] h-[68px] rounded-full shrink-0" style="--p:<?= (int)$lvl['pct'] ?>">
          <div class="grid place-items-center w-[56px] h-[56px] rounded-full bg-[#1d4ed8]">
            <div class="text-center leading-none">
              <div class="text-[9px] font-semibold uppercase tracking-wide text-white/60"><?= fh($P['level']) ?></div>
              <div class="text-xl font-extrabold"><?= (int)$lvl['level'] ?></div>
            </div>
          </div>
        </div>
        <div class="flex-1 min-w-0">
          <div class="text-lg font-extrabold leading-tight"><?= fh($lvl['title']) ?></div>
          <div class="flex items-center gap-3 text-white/85 text-[13px] mt-0.5">
            <span class="inline-flex items-center gap-1.5"><i class="fa-solid fa-star text-[#F2C14E]"></i><b id="hud-points"><?= $points ?></b> <?= fh($P['points']) ?></span>
            <span class="inline-flex items-center gap-1.5"><i class="fa-solid fa-ranking-star text-white/70"></i>#<?= $rank ?></span>
            <?php if($streakCount>0): ?>
            <span class="inline-flex items-center gap-1"><i class="fa-solid fa-fire text-[#F2C14E]"></i><b><?= $streakCount ?></b></span>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <!-- XP progress bar -->
      <div class="relative mt-4">
        <div class="xp-track h-2.5 rounded-full overflow-hidden">
          <div class="xp-fill h-full rounded-full" data-pct="<?= (int)$lvl['pct'] ?>"></div>
        </div>
        <div class="flex items-center justify-between text-[11px] text-white/75 mt-1.5">
          <span><?= (int)$lvl['into'] ?> / <?= (int)$lvl['span'] ?> <?= fh($P['xp']) ?></span>
          <span><?= max(0,(int)$lvl['span']-(int)$lvl['into']) ?> <?= fh($P['to_next']) ?></span>
        </div>
      </div>
    </div>
    <div class="flex items-center justify-center gap-1.5 text-[11px] text-amber-600 font-semibold mt-3">
      <i class="fa-solid fa-trophy"></i> <?= fh($P['prize_line']) ?>
    </div>
    <?php $appleOn = AppleWalletPass::isEnabled(); $googleOn = GoogleWalletPass::isEnabled(); if($appleOn || $googleOn): ?>
    <!-- Add the live pass straight from the page so it is one tap, updates daily -->
    <div class="mt-3 flex flex-col sm:flex-row items-stretch gap-2">
      <?php if($appleOn): ?>
        <a href="/wc-wallet-apple" class="btn flex-1 inline-flex items-center justify-center gap-2.5 rounded-2xl px-4 py-3 font-bold text-white bg-slate-900 hover:bg-black text-sm shadow-sm"><i class="fa-brands fa-apple text-lg" aria-hidden="true"></i> <?= fh($P['add_apple'] ?? 'Add to Apple Wallet') ?></a>
      <?php endif; ?>
      <?php if($googleOn): ?>
        <a href="/wc-wallet-google" class="btn flex-1 inline-flex items-center justify-center gap-2.5 rounded-2xl px-4 py-3 font-bold text-slate-700 bg-white border border-slate-200 hover:bg-slate-50 text-sm shadow-sm"><i class="fa-brands fa-google text-lg" aria-hidden="true"></i> <?= fh($P['add_google'] ?? 'Add to Google Wallet') ?></a>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </section>

  <main class="max-w-xl mx-auto px-5 py-5 space-y-5">
    <div class="rounded-2xl bg-white p-4 border border-slate-100">
      <div class="font-bold text-slate-800 mb-1"><?= fh($P['how_title']) ?></div>
      <p class="text-sm text-slate-500"><?= fh($P['how_body']) ?></p>
    </div>

    <!-- Daily streak widget -->
    <div id="streak-card" class="rise rounded-2xl bg-white p-4 border border-slate-100" style="animation-delay:.05s">
      <div class="flex items-center gap-3">
        <span class="grid place-items-center w-14 h-14 rounded-2xl bg-gradient-to-br from-amber-50 to-orange-50 text-amber-500 shrink-0">
          <i id="streak-flame" class="fa-solid fa-fire text-2xl flame-live" style="--fs:<?= number_format($flame,2) ?>"></i>
        </span>
        <div class="flex-1 min-w-0">
          <div class="flex items-baseline gap-2">
            <span id="streak-num" class="text-2xl font-extrabold text-slate-900"><?= $streakCount ?></span>
            <span class="text-sm font-semibold text-slate-500"><?= fh($P['streak']) ?></span>
          </div>
          <!-- 7-dot week tracker -->
          <div id="week-dots" class="flex items-center gap-1.5 mt-1.5">
            <?php for($d=0;$d<7;$d++): $on=$d<$weekFilled; ?>
            <span class="wk-dot grid place-items-center w-5 h-5 rounded-full text-[9px] font-bold <?= $on?'bg-amber-400 text-white wk-on':'bg-slate-100 text-slate-300' ?>" data-d="<?= $d ?>" style="animation-delay:<?= $d*60 ?>ms">
              <?php if($on): ?><i class="fa-solid fa-check"></i><?php else: ?><?= $d+1 ?><?php endif; ?>
            </span>
            <?php endfor; ?>
          </div>
        </div>
        <button id="checkin-btn" type="button"
          class="btn rounded-xl px-4 py-2.5 text-sm font-bold text-white shrink-0 <?= $checkedToday?'bg-emerald-600':'bg-blue-600' ?>"
          <?= $checkedToday?'disabled':'' ?>>
          <?php if($checkedToday): ?><i class="fa-solid fa-circle-check"></i> <?= fh($P['checked_in']) ?>
          <?php else: ?><i class="fa-solid fa-fire"></i> <?= fh($P['checkin_today']) ?><?php endif; ?>
        </button>
      </div>
      <p id="streak-hint" class="text-xs text-slate-400 mt-3"><?= $checkedToday?fh($P['come_back']):fh($P['streak_hint']) ?> · <?= fh($P['best_streak']) ?>: <span id="streak-best"><?= $streakBest ?></span></p>
    </div>

    <!-- Trophy case -->
    <div class="rise rounded-2xl bg-white p-4 border border-slate-100" style="animation-delay:.1s">
      <div class="flex items-center justify-between mb-3">
        <div class="font-bold text-slate-800"><i class="fa-solid fa-medal text-amber-500"></i> <?= fh($P['badges']) ?></div>
        <div class="text-xs text-slate-400"><span id="badges-earned"><?= $badgesEarned ?></span>/<?= count($badges) ?> <?= fh($P['badges_earned']) ?></div>
      </div>
      <div class="grid grid-cols-5 gap-2">
        <?php foreach($badges as $i=>$b):
          $title = $P['b_'.$b['key']] ?? $b['key']; $desc = $P['b_'.$b['key'].'_d'] ?? ''; ?>
        <button type="button" class="text-center badge-cell relative" data-badge="<?= fh($b['key']) ?>" data-earned="<?= $b['earned']?'1':'0' ?>"
          data-title="<?= fh($title) ?>" data-desc="<?= fh($desc) ?>" aria-label="<?= fh($title) ?>">
          <span class="badge-ic relative grid place-items-center w-12 h-12 mx-auto rounded-2xl <?= $b['earned']?'bg-gradient-to-br from-amber-100 to-amber-200 text-amber-500':'bg-slate-100 text-slate-300' ?>">
            <i class="fa-solid <?= fh($b['icon']) ?> text-lg"></i>
            <?php if($b['earned']): ?><span class="badge-shine" style="--sd:<?= $i*0.5 ?>s"></span><?php endif; ?>
            <?php if(!$b['earned']): ?><span class="absolute -bottom-1 -right-1 grid place-items-center w-4 h-4 rounded-full bg-slate-300 text-white text-[8px]"><i class="fa-solid fa-lock"></i></span><?php endif; ?>
          </span>
          <div class="badge-lb text-[10px] leading-tight mt-1 <?= $b['earned']?'text-slate-600 font-semibold':'text-slate-300' ?>"><?= fh($title) ?></div>
        </button>
        <?php endforeach; ?>
      </div>
      <p class="text-[11px] text-slate-300 text-center mt-2.5"><?= fh($P['tap_badge']) ?></p>
    </div>
    <!-- badge popover (origin-aware position set by JS) -->
    <div id="badge-pop" class="hidden fixed z-50 max-w-[220px] rounded-xl bg-slate-900 text-white px-3.5 py-2.5 shadow-xl text-left">
      <div id="bp-title" class="font-bold text-sm"></div>
      <div id="bp-desc" class="text-xs text-slate-300 mt-0.5"></div>
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
const T_CHECKED=<?= json_encode($P['checked_in']) ?>, T_COMEBACK=<?= json_encode($P['come_back']) ?>,
      T_BONUS=<?= json_encode($P['plus_five_bonus']) ?>, T_BEST=<?= json_encode($P['best_streak']) ?>;
const RM = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

// Lightweight deterministic confetti burst at (x,y). No libs.
function confetti(x,y,n){
  if(RM) return; n=n||18;
  const cols=['#2563eb','#F2C14E','#10b981','#fde68a','#1d4ed8'];
  for(let i=0;i<n;i++){
    const p=document.createElement('div'); p.className='cf-pc';
    const ang=(Math.PI* (i/n)) - Math.PI/2 + (Math.random()-.5)*0.6;
    const dist=70+Math.random()*120;
    p.style.left=x+'px'; p.style.top=y+'px';
    p.style.background=cols[i%cols.length];
    p.style.setProperty('--dx',(Math.cos(ang)*dist).toFixed(0)+'px');
    p.style.setProperty('--dy',(Math.sin(ang)*dist + 90).toFixed(0)+'px');
    p.style.setProperty('--rot',(Math.random()*720-360).toFixed(0)+'deg');
    p.style.animation='cfFall '+(0.9+Math.random()*0.5).toFixed(2)+'s cubic-bezier(.16,1,.3,1) forwards';
    document.body.appendChild(p);
    setTimeout(()=>p.remove(),1500);
  }
}
function burstFrom(el,n){ const r=el.getBoundingClientRect(); confetti(r.left+r.width/2, r.top+r.height/2, n); }
// Count up a number element from->to over ms.
function countUp(el,from,to,ms){
  if(RM||from===to){ el.textContent=to; return; }
  const t0=performance.now();
  (function step(t){ const k=Math.min(1,(t-t0)/ms); const e=1-Math.pow(1-k,3);
    el.textContent=Math.round(from+(to-from)*e); if(k<1) requestAnimationFrame(step); })(t0);
}

// Animate the XP bar to its target on load.
window.addEventListener('load',()=>{
  document.querySelectorAll('.xp-fill').forEach(f=>{ requestAnimationFrame(()=>{ f.style.width=(f.dataset.pct||0)+'%'; }); });
});

(function(){
  const btn=document.getElementById('checkin-btn'); if(!btn||btn.disabled) return;
  btn.addEventListener('click',async()=>{
    btn.disabled=true; const o=btn.innerHTML; btn.innerHTML='…';
    try{
      const r=await fetch('/api/wc-checkin.php',{method:'POST',headers:{'Content-Type':'application/json'}});
      const j=await r.json();
      if(j.ok){
        btn.classList.remove('bg-blue-600'); btn.classList.add('bg-emerald-600');
        btn.innerHTML='<i class="fa-solid fa-circle-check"></i> '+T_CHECKED;
        burstFrom(btn, j.milestone?34:20);
        const sn=document.getElementById('streak-num'); if(sn&&j.streak!=null) countUp(sn,+sn.textContent||0,j.streak,500);
        const sb=document.getElementById('streak-best'); if(sb&&j.best!=null) sb.textContent=j.best;
        // points HUD count-up reflects the award immediately
        const hp=document.getElementById('hud-points'); if(hp&&j.points!=null) countUp(hp,+hp.textContent||0,j.points,650);
        // fill the next week dot
        const fill=Math.min(j.streak||0,7);
        document.querySelectorAll('#week-dots .wk-dot').forEach((d,idx)=>{
          if(idx<fill && !d.classList.contains('bg-amber-400')){
            d.className='wk-dot grid place-items-center w-5 h-5 rounded-full text-[9px] font-bold bg-amber-400 text-white wk-on';
            d.innerHTML='<i class="fa-solid fa-check"></i>';
          }
        });
        // grow the flame with the new streak
        const fl=document.getElementById('streak-flame'); if(fl) fl.style.setProperty('--fs', Math.min(1+(j.streak||0)*0.07,1.6).toFixed(2));
        const hint=document.getElementById('streak-hint');
        if(hint){
          hint.innerHTML=(j.milestone?T_BONUS:T_COMEBACK)+' · '+T_BEST+': <span id="streak-best">'+(j.best||0)+'</span>';
          hint.className = j.milestone ? 'text-xs text-amber-600 font-semibold mt-3' : 'text-xs text-slate-400 mt-3';
        }
      } else { btn.innerHTML=o; btn.disabled=false; }
    }catch(_){ btn.innerHTML=o; btn.disabled=false; }
  });
})();

// Badge popover: tap a tile to see name + how to earn it. Origin-aware placement.
(function(){
  const pop=document.getElementById('badge-pop'); if(!pop) return;
  const ti=document.getElementById('bp-title'), de=document.getElementById('bp-desc');
  let open=null;
  function show(cell){
    const earned=cell.dataset.earned==='1';
    ti.textContent=cell.dataset.title||'';
    de.textContent=cell.dataset.desc||'';
    if(!earned){ ti.classList.add('text-slate-300'); } else { ti.classList.remove('text-slate-300'); }
    pop.classList.remove('hidden');
    const r=cell.getBoundingClientRect(), pw=pop.offsetWidth, ph=pop.offsetHeight;
    let x=r.left+r.width/2-pw/2; x=Math.max(8,Math.min(x,innerWidth-pw-8));
    let y=r.top-ph-8; if(y<8) y=r.bottom+8;
    pop.style.left=x+'px'; pop.style.top=y+'px'; open=cell;
  }
  document.querySelectorAll('.badge-cell').forEach(c=>{
    c.addEventListener('click',e=>{ e.stopPropagation(); if(open===c){ pop.classList.add('hidden'); open=null; } else show(c); });
  });
  document.addEventListener('click',()=>{ pop.classList.add('hidden'); open=null; });
})();

const T_SAVE=<?= json_encode($P['save']) ?>, T_SAVED=<?= json_encode($P['saved']) ?>;
document.querySelectorAll('[data-match]').forEach(card=>{
  const picks=card.querySelectorAll('.pick-btn');
  const save=card.querySelector('.save-btn');
  const badge=card.querySelector('.saved-badge');
  function dirty(){ // user changed something after a save -> show "save changes"
    if(save && save.dataset.saved==='1'){ save.dataset.saved=''; save.classList.remove('bg-emerald-600'); save.classList.add('bg-blue-600'); save.textContent=T_SAVE; }
  }
  picks.forEach(b=>b.addEventListener('click',()=>{
    picks.forEach(x=>{x.className=x.className.replace('bg-blue-600 text-white border-blue-600','bg-slate-50 text-slate-700 border-slate-200');x.setAttribute('aria-pressed','false');});
    b.className=b.className.replace('bg-slate-50 text-slate-700 border-slate-200','bg-blue-600 text-white border-blue-600');
    b.setAttribute('aria-pressed','true');
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
        save.dataset.saved='1'; save.classList.remove('bg-blue-600'); save.classList.add('bg-emerald-600','save-ok');
        save.innerHTML='<i class="fa-solid fa-circle-check"></i> '+T_SAVED;
        setTimeout(()=>save.classList.remove('save-ok'),460);
        burstFrom(save,14);
        if(badge) badge.classList.remove('hidden');
        if(j.badges) refreshBadges(j.badges, j.badges_earned);
      } else { save.textContent='!'; setTimeout(()=>save.textContent=o,1500); }
    }catch(_){ save.textContent='!'; setTimeout(()=>save.textContent=o,1500); }
    save.disabled=false;
  });
});

// Light up newly-earned badges live (no reload), with a little pop.
function refreshBadges(earnedKeys, count){
  const ec=document.getElementById('badges-earned'); if(ec) ec.textContent=count;
  earnedKeys.forEach(key=>{
    const cell=document.querySelector('.badge-cell[data-badge="'+key+'"]');
    if(!cell || cell.dataset.earned==='1') return;
    cell.dataset.earned='1';
    const ic=cell.querySelector('.badge-ic'), lb=cell.querySelector('.badge-lb');
    if(ic){
      ic.classList.remove('bg-slate-100','text-slate-300');
      ic.classList.add('bg-gradient-to-br','from-amber-100','to-amber-200','text-amber-500','badge-pop');
      const lock=ic.querySelector('.fa-lock'); if(lock&&lock.parentElement) lock.parentElement.remove();
      setTimeout(()=>ic.classList.remove('badge-pop'),520);
      burstFrom(ic,16);
    }
    if(lb){ lb.classList.remove('text-slate-300'); lb.classList.add('text-slate-600','font-semibold'); }
  });
}
</script>
</body></html>
