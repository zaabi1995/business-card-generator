<?php
/**
 * wc.cardify.om/wc-settings - edit language, timezone, name, or unsubscribe.
 * Identity proven by the session set after OTP verification.
 */
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/WcHub.php';

$user = WcHub::currentUser();
if (!$user) { header('Location: https://wc.cardify.om/'); exit; }
$lang = WcHub::lang($user['language'] ?? 'en');
$S = WcHub::strings($lang); $P = WcHub::pstrings($lang);
$dir = WcHub::isRtl($lang) ? 'rtl' : 'ltr';
$tzList = ['Asia/Muscat'=>'Muscat (GMT+4)','Asia/Dubai'=>'Dubai (GMT+4)','Asia/Riyadh'=>'Riyadh (GMT+3)',
    'Asia/Kolkata'=>'India (GMT+5:30)','Asia/Dhaka'=>'Dhaka (GMT+6)','Asia/Karachi'=>'Pakistan (GMT+5)',
    'Asia/Manila'=>'Manila (GMT+8)','Africa/Cairo'=>'Cairo (GMT+2)','Europe/London'=>'London (GMT+0)',
    'America/New_York'=>'New York (GMT-5)','Europe/Madrid'=>'Madrid (GMT+1)','Asia/Jakarta'=>'Jakarta (GMT+7)'];
if (!isset($tzList[$user['tz']])) $tzList[$user['tz']] = $user['tz'];
function sh($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$inputCls='w-full px-4 py-3 rounded-xl border-[1.5px] border-slate-200 bg-white text-base outline-none focus:border-blue-600';
?>
<!DOCTYPE html><html lang="<?= sh($lang) ?>" dir="<?= sh($dir) ?>"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= sh($P['settings']) ?> · Cardify</title>
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
    <div class="max-w-md mx-auto px-5 h-16 flex items-center justify-between">
      <a href="/predictions"><img src="/assets/images/logo.svg" alt="Cardify" class="h-7 w-auto"></a>
      <a href="/predictions" class="text-sm font-semibold text-blue-700 px-3 py-2 rounded-lg hover:bg-white"><?= sh($P['predict']) ?></a>
    </div>
  </header>
  <main class="max-w-md mx-auto px-5 py-6">
    <h1 class="text-xl font-bold text-slate-800 mb-4"><?= sh($P['settings']) ?></h1>
    <div id="msg" class="hidden mb-3 rounded-xl px-3.5 py-2.5 text-sm"></div>
    <div class="bg-white rounded-3xl p-6 space-y-4 border border-slate-100">
      <div>
        <label class="block text-[13px] font-semibold text-slate-700 mb-1.5"><?= sh($S['f_name']) ?></label>
        <input id="name" class="<?= $inputCls ?>" maxlength="120" value="<?= sh($user['name']) ?>">
      </div>
      <div>
        <label class="block text-[13px] font-semibold text-slate-700 mb-1.5"><?= sh($S['f_language']) ?></label>
        <select id="language" class="<?= $inputCls ?>">
          <?php foreach (WcHub::LANGS as $code=>$native): ?><option value="<?= sh($code) ?>" <?= $code===$lang?'selected':'' ?>><?= sh($native) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="block text-[13px] font-semibold text-slate-700 mb-1.5"><?= sh($S['f_timezone']) ?></label>
        <select id="tz" class="<?= $inputCls ?>">
          <?php foreach ($tzList as $z=>$lbl): ?><option value="<?= sh($z) ?>" <?= $z===$user['tz']?'selected':'' ?>><?= sh($lbl) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="block text-[13px] font-semibold text-slate-700 mb-1.5"><?= sh($lang==='ar'?'وقت التذكير اليومي':'Daily reminder time') ?></label>
        <select id="notify_hour" class="<?= $inputCls ?>">
          <?php $uh=(int)($user['notify_hour'] ?? 10); for($hh=0;$hh<24;$hh++): ?><option value="<?= $hh ?>" <?= $hh===$uh?'selected':'' ?>><?= date('g:i A', mktime($hh,0,0,1,1,2026)) ?></option><?php endfor; ?>
        </select>
      </div>
      <button id="save" class="btn w-full rounded-2xl py-3.5 font-bold text-white bg-blue-600"><?= sh($P['save_settings']) ?></button>
    </div>
    <div class="flex items-center justify-between mt-5 text-sm">
      <a href="/u?t=<?= sh($user['unsub_token']) ?>" class="text-red-500 font-semibold">Unsubscribe</a>
      <a href="/wc-logout" class="text-slate-500 font-semibold"><?= sh($P['logout']) ?></a>
    </div>
    <p class="text-xs text-slate-400 mt-4">Phone: ****<?= sh(substr(preg_replace('/\D/','',$user['phone']),-4)) ?>. To use a different number, log out and sign in again with OTP.</p>
  </main>
<script>
const $=id=>document.getElementById(id);
$('save').addEventListener('click',async()=>{
  const b=$('save'); b.disabled=true; const o=b.textContent; b.textContent='…';
  const m=$('msg');
  try{
    const r=await fetch('/api/wc-settings-save.php',{method:'POST',headers:{'Content-Type':'application/json'},
      body:JSON.stringify({name:$('name').value,language:$('language').value,tz:$('tz').value,notify_hour:parseInt($('notify_hour').value,10)})});
    const j=await r.json();
    m.className=(j.ok?'bg-emerald-50 text-emerald-700':'bg-red-50 text-red-600')+' mb-3 rounded-xl px-3.5 py-2.5 text-sm';
    m.textContent=j.ok?'Saved':'Could not save'; m.classList.remove('hidden');
    if(j.ok) setTimeout(()=>location.reload(),700);
  }catch(_){m.className='bg-red-50 text-red-600 mb-3 rounded-xl px-3.5 py-2.5 text-sm';m.textContent='Error';m.classList.remove('hidden');}
  b.disabled=false; b.textContent=o;
});
</script>
</body></html>
