<?php
/**
 * wc.cardify.om/wc-leagues - the mini-leagues hub.
 * Lists the signed-in user's leagues (name, members, their rank) + a Create
 * form + a Join-by-code input. Requires a logged-in WC user; otherwise points
 * them at the sign-in flow.
 */
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/WcHub.php';

$user = WcHub::currentUser();
$lang = WcHub::lang($_GET['lang'] ?? ($user['language'] ?? 'en'));
$P    = WcHub::pstrings($lang);
$L    = WcHub::lstrings($lang);
$dir  = WcHub::isRtl($lang) ? 'rtl' : 'ltr';
function lgh($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$leagues = $user ? WcHub::userLeagues((int)$user['id']) : [];
?>
<!DOCTYPE html><html lang="<?= lgh($lang) ?>" dir="<?= lgh($dir) ?>"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= lgh($L['leagues']) ?> · Cardify</title>
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
  .card-tap{transition:transform .15s cubic-bezier(.16,1,.3,1),box-shadow .15s}
  .card-tap:active{transform:scale(.985)}
  .btn{transition:transform .12s cubic-bezier(.16,1,.3,1),background-color .15s,box-shadow .15s}
  .btn:active{transform:scale(.97)}
  @media (prefers-reduced-motion:reduce){.rise,.card-tap,.btn{animation:none;transition:none}}
</style>
</head>
<body class="min-h-[100dvh] bg-[#f7f8fa] text-slate-900 pb-16">
  <header class="sticky top-0 z-30 bg-[#f7f8fa]/85 backdrop-blur border-b border-slate-200/70">
    <div class="max-w-xl mx-auto px-5 h-16 flex items-center justify-between">
      <a href="<?= $user?'/predictions':'https://wc.cardify.om/' ?>"><img src="/assets/images/logo.svg" alt="Cardify" class="h-7 w-auto"></a>
      <div class="flex items-center gap-1 text-sm">
        <a href="/wc-leaderboard" class="text-slate-500 hover:text-slate-900 px-3 py-2 rounded-lg hover:bg-white"><?= lgh($P['leaderboard']) ?></a>
        <?php if($user): ?><a href="/predictions" class="font-semibold text-blue-700 px-3 py-2 rounded-lg hover:bg-white"><?= lgh($P['predict']) ?></a><?php endif; ?>
      </div>
    </div>
  </header>

  <main class="max-w-xl mx-auto px-5 py-6">
    <div class="flex items-center gap-2.5 mb-1">
      <span class="grid place-items-center w-10 h-10 rounded-xl bg-blue-100 text-blue-600"><i class="fa-solid fa-users-line"></i></span>
      <h1 class="text-2xl font-extrabold text-slate-900"><?= lgh($L['leagues']) ?></h1>
    </div>
    <p class="text-sm text-slate-500 mb-6"><?= lgh($L['leagues_sub']) ?></p>

    <?php if(!$user): ?>
      <div class="rise bg-white rounded-2xl border border-slate-100 p-7 text-center">
        <span class="grid place-items-center w-14 h-14 mx-auto rounded-2xl bg-blue-50 text-blue-500 mb-4"><i class="fa-solid fa-right-to-bracket text-xl"></i></span>
        <p class="text-slate-600 text-sm mb-5"><?= lgh($L['l_err_auth']) ?></p>
        <a href="/predictions" class="btn inline-flex items-center justify-center gap-2 rounded-xl px-5 py-3 font-semibold text-white bg-blue-600 hover:bg-blue-700"><?= lgh($P['signin']) ?> <i class="fa-solid fa-arrow-<?= $dir==='rtl'?'left':'right' ?> text-sm"></i></a>
      </div>
    <?php else: ?>

      <!-- Create + Join actions -->
      <div class="rise grid gap-3 mb-7">
        <!-- Create -->
        <div class="bg-white rounded-2xl border border-slate-100 p-4">
          <div class="flex items-center gap-2 mb-2.5">
            <span class="grid place-items-center w-8 h-8 rounded-lg bg-amber-50 text-amber-500"><i class="fa-solid fa-plus"></i></span>
            <div>
              <div class="font-bold text-slate-800 text-[15px] leading-tight"><?= lgh($L['create']) ?></div>
              <div class="text-[12px] text-slate-400"><?= lgh($L['create_hint']) ?></div>
            </div>
          </div>
          <form id="createForm" class="flex gap-2">
            <input id="leagueName" type="text" maxlength="60" autocomplete="off" placeholder="<?= lgh($L['l_name_ph']) ?>" class="flex-1 min-w-0 rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-2.5 text-[15px] focus:bg-white focus:border-blue-400 focus:ring-2 focus:ring-blue-100 outline-none">
            <button type="submit" id="createBtn" class="btn shrink-0 rounded-xl px-5 py-2.5 font-semibold text-white bg-blue-600 hover:bg-blue-700 disabled:opacity-60"><?= lgh($L['create_cta']) ?></button>
          </form>
          <p id="createErr" class="hidden text-[12px] text-rose-600 mt-2"></p>
        </div>
        <!-- Join by code -->
        <div class="bg-white rounded-2xl border border-slate-100 p-4">
          <div class="flex items-center gap-2 mb-2.5">
            <span class="grid place-items-center w-8 h-8 rounded-lg bg-blue-50 text-blue-500"><i class="fa-solid fa-hashtag"></i></span>
            <div class="font-bold text-slate-800 text-[15px]"><?= lgh($L['join_by_code']) ?></div>
          </div>
          <form id="joinForm" class="flex gap-2">
            <input id="joinCode" type="text" maxlength="12" autocomplete="off" placeholder="<?= lgh($L['join_ph']) ?>" class="flex-1 min-w-0 rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-2.5 text-[15px] tracking-[0.18em] font-semibold uppercase focus:bg-white focus:border-blue-400 focus:ring-2 focus:ring-blue-100 outline-none">
            <button type="submit" id="joinBtn" class="btn shrink-0 rounded-xl px-5 py-2.5 font-semibold text-slate-700 bg-slate-100 hover:bg-slate-200 disabled:opacity-60"><?= lgh($L['join_cta']) ?></button>
          </form>
          <p id="joinErr" class="hidden text-[12px] text-rose-600 mt-2"></p>
        </div>
      </div>

      <!-- Your leagues -->
      <h2 class="text-sm font-bold text-slate-500 uppercase tracking-wide mb-3"><?= lgh($L['your_leagues']) ?></h2>
      <?php if(!$leagues): ?>
        <div class="rise bg-white rounded-2xl border border-slate-100 p-7 text-center">
          <span class="grid place-items-center w-14 h-14 mx-auto rounded-2xl bg-slate-100 text-slate-300 mb-4"><i class="fa-solid fa-users text-xl"></i></span>
          <p class="text-slate-600 text-sm font-semibold"><?= lgh($L['no_leagues']) ?></p>
          <p class="text-slate-400 text-[13px] mt-1"><?= lgh($L['no_leagues_sub']) ?></p>
        </div>
      <?php else: ?>
        <div class="space-y-3">
          <?php foreach($leagues as $l):
            $mc = (int)$l['member_count']; $rank = $l['my_rank'];
            $url = '/wc-league?l=' . rawurlencode($l['code']); ?>
          <a href="<?= lgh($url) ?>" class="card-tap block bg-white rounded-2xl border border-slate-100 p-4 hover:border-blue-200 hover:shadow-sm">
            <div class="flex items-center gap-3">
              <span class="grid place-items-center w-11 h-11 rounded-xl bg-gradient-to-br from-blue-500 to-blue-700 text-white font-extrabold text-lg shrink-0"><?= lgh(mb_strtoupper(mb_substr(trim($l['name']),0,1))) ?></span>
              <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2">
                  <span class="font-bold text-slate-800 text-[15px] truncate"><?= lgh($l['name']) ?></span>
                  <?php if($l['is_owner']): ?><span class="shrink-0 text-[10px] font-bold uppercase tracking-wide text-amber-600 bg-amber-50 px-1.5 py-0.5 rounded"><?= lgh($L['owner']) ?></span><?php endif; ?>
                </div>
                <div class="text-[12px] text-slate-400 mt-0.5">
                  <i class="fa-solid fa-user-group text-[10px] me-1"></i><?= $mc ?> <?= lgh($mc===1?$L['member']:$L['members']) ?>
                  <span class="mx-1.5 text-slate-300">·</span>
                  <span class="font-mono tracking-wider text-slate-500"><?= lgh($l['code']) ?></span>
                </div>
              </div>
              <div class="text-end shrink-0">
                <?php if($rank): ?>
                  <div class="text-[10px] uppercase tracking-wide text-slate-400 leading-none mb-0.5"><?= lgh($L['rank_label']) ?></div>
                  <div class="font-extrabold text-blue-700 text-lg leading-none">#<?= (int)$rank ?></div>
                <?php else: ?>
                  <i class="fa-solid fa-chevron-<?= $dir==='rtl'?'left':'right' ?> text-slate-300"></i>
                <?php endif; ?>
              </div>
            </div>
          </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

    <?php endif; ?>

    <div class="text-center pt-7 pb-2">
      <span class="inline-flex items-center gap-2 text-xs text-slate-400">
        <img src="/assets/images/logo.svg" class="h-3.5 w-auto opacity-60"> powered by Cardify
      </span>
    </div>
  </main>

<?php if($user): ?>
<script>
const ERR = <?= json_encode([
  'l_err_name'=>$L['l_err_name'],'l_err_max'=>$L['l_err_max'],'l_err_full'=>$L['l_err_full'],
  'l_err_notfound'=>$L['l_err_notfound'],'l_err_generic'=>$L['l_err_generic'],'l_err_auth'=>$L['l_err_auth'],
  'already_member'=>$L['already_member'],
], JSON_UNESCAPED_UNICODE) ?>;
function errText(k){ return ERR[k] || ERR['l_err_generic']; }
function showErr(el, k){ el.textContent = errText(k); el.classList.remove('hidden'); }

// Create
const cf = document.getElementById('createForm');
cf.addEventListener('submit', async (e)=>{
  e.preventDefault();
  const name = document.getElementById('leagueName').value.trim();
  const errEl = document.getElementById('createErr'); errEl.classList.add('hidden');
  if(!name){ showErr(errEl,'l_err_name'); return; }
  const btn = document.getElementById('createBtn'); btn.disabled = true;
  try{
    const r = await fetch('/api/wc-league-create.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({name})});
    const j = await r.json();
    if(j.ok){ location.href = j.board; return; }
    showErr(errEl, j.error);
  }catch(_){ showErr(errEl,'l_err_generic'); }
  btn.disabled = false;
});

// Join by code
const jf = document.getElementById('joinForm');
jf.addEventListener('submit', async (e)=>{
  e.preventDefault();
  const code = document.getElementById('joinCode').value.trim().toUpperCase();
  const errEl = document.getElementById('joinErr'); errEl.classList.add('hidden');
  if(!code){ showErr(errEl,'l_err_notfound'); return; }
  const btn = document.getElementById('joinBtn'); btn.disabled = true;
  try{
    const r = await fetch('/api/wc-league-join.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({code})});
    const j = await r.json();
    if(j.ok){ location.href = j.board; return; }
    showErr(errEl, j.error);
  }catch(_){ showErr(errEl,'l_err_generic'); }
  btn.disabled = false;
});
</script>
<?php endif; ?>
</body></html>
