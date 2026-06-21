<?php
/**
 * wc.cardify.om/wc-leaderboard - masked leaderboard + prize banner.
 */
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/WcHub.php';

$user = WcHub::currentUser();
$lang = WcHub::lang($user['language'] ?? ($_GET['lang'] ?? 'en'));
$P    = WcHub::pstrings($lang);
$dir  = WcHub::isRtl($lang) ? 'rtl' : 'ltr';
$db   = Database::getInstance();

// Prize race = knockout-stage points (Round of 32 onward), so late joiners start level.
$KO = '2026-06-28 00:00:00';
$top = $db->fetchAll(
  "SELECT u.id, u.name, u.phone,
          COALESCE(SUM(CASE WHEN m.kickoff_utc >= :ko THEN p.points ELSE 0 END),0) + MAX(u.bonus_points) AS pts
   FROM wc_users u
   LEFT JOIN wc_predictions p ON p.user_id = u.id
   LEFT JOIN wc_matches m ON m.espn_id = p.match_id
   WHERE u.status='active'
   GROUP BY u.id, u.name, u.phone
   ORDER BY pts DESC, u.points_cache DESC, u.verified_at ASC
   LIMIT 50", ['ko'=>$KO]);
$myRank = null; $myPts = 0;
if ($user) {
    foreach ($top as $idx=>$r) { if ((int)$r['id']===(int)$user['id']) { $myRank=$idx+1; $myPts=(int)$r['pts']; break; } }
    if ($myRank === null) {
        $mp = $db->fetchOne(
          "SELECT COALESCE(SUM(CASE WHEN m.kickoff_utc >= :ko THEN p.points ELSE 0 END),0) AS pts
           FROM wc_predictions p JOIN wc_matches m ON m.espn_id=p.match_id WHERE p.user_id=:u",
          ['ko'=>$KO,'u'=>$user['id']]);
        $myPts = (int)($mp['pts'] ?? 0) + (int)($user['bonus_points'] ?? 0);
        $myRank = (int)($db->fetchOne(
          "SELECT COUNT(*)+1 AS r FROM (
             SELECT u.id, COALESCE(SUM(CASE WHEN m.kickoff_utc >= :ko THEN p.points ELSE 0 END),0) + MAX(u.bonus_points) AS pts
             FROM wc_users u LEFT JOIN wc_predictions p ON p.user_id=u.id
             LEFT JOIN wc_matches m ON m.espn_id=p.match_id
             WHERE u.status='active' GROUP BY u.id HAVING pts > :mp
          ) t", ['ko'=>$KO,'mp'=>$myPts])['r'] ?? 1);
    }
}
function lh($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function maskName($name,$phone){
    $first = trim(explode(' ', trim((string)$name))[0] ?? '');
    $first = $first !== '' ? mb_substr($first, 0, 12) : 'Player';
    $d = preg_replace('/\D/', '', (string)$phone);
    return lh($first) . ' <span class="text-slate-400">****' . lh(substr($d, -4)) . '</span>';
}
$prizes = ['$10,000','$5,000','$1,000']; $ranks=['1st','2nd','3rd'];
?>
<!DOCTYPE html><html lang="<?= lh($lang) ?>" dir="<?= lh($dir) ?>"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= lh($P['leaderboard']) ?> · Cardify</title>
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
  @keyframes riseIn{0%{opacity:0;transform:translateY(12px)}100%{opacity:1;transform:none}}
  .rise{animation:riseIn .5s cubic-bezier(.16,1,.3,1) both}
  /* podium plinth gradients */
  .plinth-1{background:linear-gradient(180deg,#fef3c7,#fcd34d)}
  .plinth-2{background:linear-gradient(180deg,#f1f5f9,#cbd5e1)}
  .plinth-3{background:linear-gradient(180deg,#ffedd5,#fdba74)}
  .crown{filter:drop-shadow(0 2px 4px rgba(245,158,11,.4))}
  @keyframes crownBob{0%,100%{transform:translateY(0)}50%{transform:translateY(-3px)}}
  .crown{animation:crownBob 2.4s ease-in-out infinite}
  .ldr-row{transition:background-color .15s}
  @media (prefers-reduced-motion: reduce){.rise,.crown{animation:none}}
</style>
</head>
<body class="min-h-[100dvh] bg-[#f7f8fa] text-slate-900 pb-24">
  <header class="sticky top-0 z-30 bg-[#f7f8fa]/85 backdrop-blur border-b border-slate-200/70">
    <div class="max-w-xl mx-auto px-5 h-16 flex items-center justify-between">
      <a href="<?= $user?'/predictions':'https://wc.cardify.om/' ?>"><img src="/assets/images/logo.svg" alt="Cardify" class="h-7 w-auto"></a>
      <?php if($user): ?><a href="/predictions" class="text-sm font-semibold text-blue-700 px-3 py-2 rounded-lg hover:bg-white"><?= lh($P['predict']) ?></a><?php endif; ?>
    </div>
  </header>

  <main class="max-w-xl mx-auto px-5 py-6">
    <div class="flex items-center gap-2.5 mb-1">
      <span class="grid place-items-center w-10 h-10 rounded-xl bg-amber-100 text-amber-500"><i class="fa-solid fa-trophy"></i></span>
      <h1 class="text-2xl font-extrabold text-slate-900"><?= lh($P['leaderboard']) ?></h1>
    </div>
    <p class="text-xs text-slate-400 mb-5"><?= lh($P['prize_line']) ?></p>

    <?php
      // Podium order on the plinth: 2nd | 1st | 3rd. Pull the top 3 rows (may be fewer).
      $podOrder = [1,0,2]; $heights=[null,'h-24','h-16','h-12']; // by display rank
      $medalBg=[1=>'plinth-1',2=>'plinth-2',3=>'plinth-3'];
      $medalIc=[1=>'text-amber-500',2=>'text-slate-400',3=>'text-orange-400'];
    ?>
    <?php if($top): ?>
    <!-- Podium: real 3-stage stand, 2-1-3 layout, gold/silver/bronze + prize -->
    <div class="rise grid grid-cols-3 gap-2 items-end mb-6">
      <?php foreach($podOrder as $slot): $row=$top[$slot]??null; $r=$slot+1;
        if(!$row){ echo '<div></div>'; continue; }
        $me=$user && (int)$row['id']===(int)$user['id'];
        $plinthH = $r===1?'h-24':($r===2?'h-20':'h-14'); ?>
      <div class="text-center">
        <?php if($r===1): ?><div class="crown text-amber-400 text-lg mb-0.5"><i class="fa-solid fa-crown"></i></div><?php endif; ?>
        <div class="grid place-items-center w-12 h-12 mx-auto rounded-full bg-white border-2 <?= $r===1?'border-amber-300':($r===2?'border-slate-300':'border-orange-200') ?> shadow-sm mb-1.5">
          <span class="font-extrabold <?= $medalIc[$r] ?> text-lg"><?= $r ?></span>
        </div>
        <div class="text-[13px] font-bold text-slate-800 leading-tight truncate px-0.5"><?= maskName($row['name'],$row['phone']) ?></div>
        <div class="text-[11px] text-slate-400 mb-1.5"><?= (int)$row['pts'] ?> <?= lh($P['points']) ?></div>
        <div class="<?= $medalBg[$r] ?> <?= $plinthH ?> rounded-t-xl flex flex-col items-center justify-start pt-2 <?= $me?'ring-2 ring-blue-500':'' ?>">
          <div class="font-extrabold text-slate-900 text-sm"><?= $prizes[$r-1] ?></div>
          <div class="text-[10px] uppercase tracking-wide text-slate-600/70"><?= $ranks[$r-1] ?></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Full ladder from rank 4 down (top 3 already on the podium) -->
    <?php $ladder = array_slice($top, 3); ?>
    <div class="bg-white rounded-2xl overflow-hidden border border-slate-100">
      <?php if(!$top): ?>
        <div class="p-8 text-center">
          <span class="grid place-items-center w-12 h-12 mx-auto rounded-full bg-slate-100 text-slate-300 mb-3"><i class="fa-solid fa-ranking-star text-xl" aria-hidden="true"></i></span>
          <p class="text-slate-400 text-sm"><?= lh($P['empty']) ?></p>
        </div>
      <?php elseif(!$ladder): ?>
        <div class="p-8 text-center">
          <span class="grid place-items-center w-12 h-12 mx-auto rounded-full bg-blue-50 text-blue-500 mb-3"><i class="fa-solid fa-bolt text-xl" aria-hidden="true"></i></span>
          <p class="text-slate-500 text-sm font-semibold"><?= lh($lang==='ar'?'الترتيب يتشكّل الآن':'The table is just getting started') ?></p>
          <p class="text-slate-400 text-xs mt-1"><?= lh($lang==='ar'?'توقّع لتصعد وتظهر هنا':'Make a prediction to climb and appear here.') ?></p>
        </div>
      <?php else: foreach($top as $i=>$row): $r=$i+1; if($r<=3) continue;
        $me = $user && (int)$row['id']===(int)$user['id']; ?>
        <div class="ldr-row flex items-center gap-3 px-4 py-3 border-b border-slate-50 last:border-0 <?= $me?'bg-blue-50':'' ?>">
          <div class="w-7 text-center font-bold text-slate-400 text-sm"><?= $r ?></div>
          <div class="flex-1 min-w-0 text-slate-800 text-[15px] truncate"><?= maskName($row['name'],$row['phone']) ?><?= $me?' · '.lh($P['you']):'' ?></div>
          <div class="font-extrabold text-slate-900 shrink-0"><?= (int)$row['pts'] ?> <span class="text-[11px] font-normal text-slate-400"><?= lh($P['points']) ?></span></div>
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
  <!-- Sticky YOU bar pinned to the bottom -->
  <div class="fixed bottom-0 inset-x-0 z-30">
    <div class="max-w-xl mx-auto px-4 pb-4">
      <div class="rounded-2xl bg-blue-600 text-white px-4 py-3 flex items-center justify-between shadow-xl shadow-blue-600/30">
        <span class="font-semibold inline-flex items-center gap-2">
          <span class="grid place-items-center w-7 h-7 rounded-full bg-white/20 text-xs font-bold">#<?= $myRank ?></span>
          <?= lh($P['you']) ?>
        </span>
        <span class="font-extrabold text-lg"><?= $myPts ?> <span class="text-xs font-normal text-white/80"><?= lh($P['points']) ?></span></span>
      </div>
    </div>
  </div>
  <?php endif; ?>
</body></html>
