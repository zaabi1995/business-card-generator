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

$top = $db->fetchAll("SELECT id, name, phone, points_cache FROM wc_users
    WHERE status='active' ORDER BY points_cache DESC, verified_at ASC LIMIT 50");
$myRank = null;
if ($user) {
    $myRank = (int)($db->fetchOne("SELECT COUNT(*)+1 AS r FROM wc_users WHERE status='active' AND points_cache > :p",
        ['p'=>(int)$user['points_cache']])['r'] ?? 1);
}
function lh($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function maskName($name,$phone){
    $first = trim(explode(' ', trim((string)$name))[0] ?? '');
    $first = $first !== '' ? mb_substr($first, 0, 12) : 'Player';
    $d = preg_replace('/\D/', '', (string)$phone);
    return lh($first) . ' <span class="text-slate-400">****' . lh(substr($d, -4)) . '</span>';
}
$prizes = ['$10,000','$5,000','$1,000']; $medals=['🥇','🥈','🥉'];
?>
<!DOCTYPE html><html lang="<?= lh($lang) ?>" dir="<?= lh($dir) ?>"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= lh($P['leaderboard']) ?> · Cardify</title>
<link rel="icon" href="/assets/images/cardify-icon-192.png">
<link rel="stylesheet" href="https://fonts.bhd.om/css2?family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&display=swap">
<script src="https://cdn.tailwindcss.com"></script>
<style>body{font-family:'IBM Plex Sans Arabic',system-ui,sans-serif}</style>
</head>
<body class="min-h-[100dvh] bg-slate-100">
  <header class="bg-[linear-gradient(160deg,#0a7d3c,#04331b)] text-white">
    <div class="max-w-xl mx-auto px-5 pt-4 pb-6">
      <div class="flex items-center justify-between">
        <a href="<?= $user?'/predictions':'https://wc.cardify.om/' ?>"><img src="/assets/images/logo-light.svg" alt="Cardify" class="h-6 w-auto"></a>
        <?php if($user): ?><a href="/predictions" class="text-sm text-emerald-50/90"><?= lh($P['predict']) ?></a><?php endif; ?>
      </div>
      <h1 class="text-2xl font-extrabold mt-4">🏆 <?= lh($P['leaderboard']) ?></h1>
      <div class="grid grid-cols-3 gap-2 mt-3">
        <?php for($i=0;$i<3;$i++): ?>
          <div class="rounded-xl bg-[linear-gradient(90deg,#ffd34d,#f5b301)] text-emerald-950 text-center py-2.5">
            <div class="text-lg"><?= $medals[$i] ?></div><div class="font-extrabold"><?= $prizes[$i] ?></div>
          </div>
        <?php endfor; ?>
      </div>
    </div>
  </header>

  <main class="max-w-xl mx-auto px-5 py-5">
    <?php if($user && $myRank): ?>
      <div class="rounded-2xl bg-cyan-600 text-white px-4 py-3 mb-4 flex items-center justify-between">
        <span class="font-semibold"><?= lh($P['you']) ?> · #<?= $myRank ?></span>
        <span class="font-extrabold text-lg"><?= (int)$user['points_cache'] ?> <span class="text-xs font-normal"><?= lh($P['points']) ?></span></span>
      </div>
    <?php endif; ?>

    <div class="bg-white rounded-2xl overflow-hidden border border-slate-100">
      <?php if(!$top): ?>
        <div class="p-6 text-center text-slate-400 text-sm"><?= lh($P['empty']) ?></div>
      <?php else: foreach($top as $i=>$row): $r=$i+1;
        $me = $user && (int)$row['id']===(int)$user['id']; ?>
        <div class="flex items-center gap-3 px-4 py-3 border-b border-slate-50 <?= $me?'bg-cyan-50':'' ?>">
          <div class="w-8 text-center font-bold <?= $r<=3?'text-amber-500 text-lg':'text-slate-400' ?>"><?= $r<=3?$medals[$r-1]:$r ?></div>
          <div class="flex-1 text-slate-800 text-[15px]"><?= maskName($row['name'],$row['phone']) ?><?= $me?' · '.lh($P['you']):'' ?></div>
          <div class="font-extrabold text-slate-900"><?= (int)$row['points_cache'] ?> <span class="text-[11px] font-normal text-slate-400"><?= lh($P['points']) ?></span></div>
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
