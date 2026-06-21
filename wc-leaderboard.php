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
<link rel="stylesheet" href="https://fonts.bhd.om/css2?family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="https://design.bhd.om/fa/v7.2.0/css/fontawesome.min.css">
<link rel="stylesheet" href="https://design.bhd.om/fa/v7.2.0/css/light.min.css">
<link rel="stylesheet" href="https://design.bhd.om/fa/v7.2.0/css/solid.min.css">
<link rel="stylesheet" href="https://design.bhd.om/fa/v7.2.0/css/brands.min.css">
<script src="https://cdn.tailwindcss.com"></script>
<style>body{font-family:'IBM Plex Sans Arabic',system-ui,sans-serif}</style>
</head>
<body class="min-h-[100dvh] bg-slate-100">
  <header class="bg-slate-900 text-white">
    <div class="max-w-xl mx-auto px-5 pt-4 pb-6">
      <div class="flex items-center justify-between">
        <a href="<?= $user?'/predictions':'https://wc.cardify.om/' ?>"><img src="/assets/images/logo-light.svg" alt="Cardify" class="h-6 w-auto"></a>
        <?php if($user): ?><a href="/predictions" class="text-sm text-emerald-50/90"><?= lh($P['predict']) ?></a><?php endif; ?>
      </div>
      <h1 class="text-2xl font-extrabold mt-4"><i class="fa-light fa-trophy"></i> <?= lh($P['leaderboard']) ?></h1>
      <div class="grid grid-cols-3 gap-2 mt-3">
        <?php for($i=0;$i<3;$i++): ?>
          <div class="rounded-xl bg-white/10 border border-white/15 text-center py-2.5">
            <div class="text-[11px] uppercase tracking-wide text-white/60"><?= $ranks[$i] ?></div><div class="font-extrabold"><?= $prizes[$i] ?></div>
          </div>
        <?php endfor; ?>
      </div>
    </div>
  </header>

  <main class="max-w-xl mx-auto px-5 py-5">
    <?php if($user && $myRank): ?>
      <div class="rounded-2xl bg-blue-600 text-white px-4 py-3 mb-4 flex items-center justify-between">
        <span class="font-semibold"><?= lh($P['you']) ?> · #<?= $myRank ?></span>
        <span class="font-extrabold text-lg"><?= $myPts ?> <span class="text-xs font-normal"><?= lh($P['points']) ?></span></span>
      </div>
    <?php endif; ?>

    <div class="bg-white rounded-2xl overflow-hidden border border-slate-100">
      <?php if(!$top): ?>
        <div class="p-6 text-center text-slate-400 text-sm"><?= lh($P['empty']) ?></div>
      <?php else: foreach($top as $i=>$row): $r=$i+1;
        $me = $user && (int)$row['id']===(int)$user['id']; ?>
        <div class="flex items-center gap-3 px-4 py-3 border-b border-slate-50 <?= $me?'bg-blue-50':'' ?>">
          <div class="w-8 text-center font-bold <?= $r<=3?'text-amber-500 text-lg':'text-slate-400' ?>"><?= $r ?></div>
          <div class="flex-1 text-slate-800 text-[15px]"><?= maskName($row['name'],$row['phone']) ?><?= $me?' · '.lh($P['you']):'' ?></div>
          <div class="font-extrabold text-slate-900"><?= (int)$row['pts'] ?> <span class="text-[11px] font-normal text-slate-400"><?= lh($P['points']) ?></span></div>
        </div>
      <?php endforeach; endif; ?>
    </div>

    <div class="text-center pt-5 pb-6">
      <span class="inline-flex items-center gap-2 text-xs text-slate-400">
        <img src="/assets/images/logo.svg" class="h-3.5 w-auto opacity-60"> powered by Cardify
      </span>
    </div>
  </main>
</body></html>
