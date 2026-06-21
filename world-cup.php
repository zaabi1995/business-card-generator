<?php
/**
 * wc.cardify.om - Cardify World Cup 2026 hub (signup + OTP).
 * Powered by Cardify. Captures every signup as a Cardify lead.
 */
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/WhatsApp.php';
require_once INCLUDES_DIR . '/WcHub.php';

$lang = WcHub::lang($_GET['lang'] ?? 'en');
$S    = WcHub::strings($lang);
$dir  = $S['dir'];
$cc   = WcHub::detectCountry();
$tzGuess = WcHub::countryTz($cc);
$turnstileSite = defined('TURNSTILE_SITE_KEY') ? TURNSTILE_SITE_KEY : '';

$tzList = [
    'Asia/Muscat'=>'Muscat (GMT+4)','Asia/Dubai'=>'Dubai (GMT+4)','Asia/Riyadh'=>'Riyadh (GMT+3)',
    'Asia/Qatar'=>'Doha (GMT+3)','Asia/Bahrain'=>'Manama (GMT+3)','Asia/Kuwait'=>'Kuwait (GMT+3)',
    'Asia/Kolkata'=>'India (GMT+5:30)','Asia/Dhaka'=>'Dhaka (GMT+6)','Asia/Karachi'=>'Pakistan (GMT+5)',
    'Asia/Manila'=>'Manila (GMT+8)','Asia/Colombo'=>'Colombo (GMT+5:30)','Asia/Kathmandu'=>'Nepal (GMT+5:45)',
    'Africa/Cairo'=>'Cairo (GMT+2)','Asia/Amman'=>'Amman (GMT+3)','Asia/Baghdad'=>'Baghdad (GMT+3)',
    'Europe/London'=>'London (GMT+0)','America/New_York'=>'New York (GMT-5)','Europe/Paris'=>'Paris (GMT+1)',
    'Europe/Madrid'=>'Madrid (GMT+1)','Africa/Casablanca'=>'Casablanca (GMT+1)','Asia/Jakarta'=>'Jakarta (GMT+7)',
];
if (!isset($tzList[$tzGuess])) $tzGuess = 'Asia/Muscat';
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$inputCls = 'w-full px-4 py-3.5 rounded-xl border-[1.5px] border-slate-200 bg-white text-slate-900 text-base outline-none focus:border-cyan focus:ring-4 focus:ring-cyan/15 transition';
?>
<!DOCTYPE html>
<html lang="<?= h($lang) ?>" dir="<?= h($dir) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= h($S['kicker']) ?> · Cardify</title>
<meta name="description" content="<?= h($S['hero_sub']) ?>">
<meta property="og:title" content="<?= h($S['hero_title']) ?>">
<meta property="og:description" content="<?= h($S['hero_sub']) ?>">
<meta property="og:image" content="https://wc.cardify.om/assets/wc/og.jpg?v=1">
<meta property="og:url" content="https://wc.cardify.om/">
<meta property="og:type" content="website">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:image" content="https://wc.cardify.om/assets/wc/og.jpg?v=1">
<link rel="icon" href="/assets/images/cardify-icon-192.png">
<link rel="preconnect" href="https://fonts.bhd.om" crossorigin>
<link rel="stylesheet" href="https://fonts.bhd.om/css2?family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/intl-tel-input@25.10.1/build/css/intlTelInput.css">
<script src="https://cdn.tailwindcss.com"></script>
<script>
tailwind.config = { theme: { extend: {
  fontFamily: { sans: ['IBM Plex Sans Arabic','system-ui','-apple-system','sans-serif'] },
  colors: { pitch:{DEFAULT:'#0a7d3c',deep:'#065a2b',dark:'#04331b'}, gold:'#ffd34d', gold2:'#f5b301', cyan:'#009bc1' },
  keyframes: { fall:{ '0%':{transform:'translateY(-10vh) rotate(0)',opacity:'0'}, '12%':{opacity:'.9'}, '100%':{transform:'translateY(110vh) rotate(540deg)',opacity:'.15'} },
    pop:{ '0%':{transform:'scale(.96)',opacity:'0'}, '100%':{transform:'scale(1)',opacity:'1'} } },
  animation: { fall:'fall linear infinite', pop:'pop .35s ease both' },
}}}
</script>
<?php if ($turnstileSite): ?><script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script><?php endif; ?>
<style>
  body{font-family:'IBM Plex Sans Arabic',system-ui,sans-serif}
  .iti{width:100%} .iti__selected-dial-code{font-weight:700}
  .otp-in{width:100%;text-align:center}
  [hidden]{display:none!important}
</style>
</head>
<body class="min-h-[100dvh] text-slate-900 bg-pitch-dark">
<div class="relative min-h-[100dvh] overflow-hidden
     bg-[radial-gradient(1200px_600px_at_80%_-10%,rgba(255,211,77,.18),transparent_60%),radial-gradient(900px_500px_at_-10%_110%,rgba(0,155,193,.18),transparent_60%),linear-gradient(160deg,#0a7d3c,#065a2b_55%,#04331b)]">
  <!-- pitch stripes -->
  <div class="pointer-events-none absolute inset-0 opacity-[.06] bg-[repeating-linear-gradient(90deg,#fff_0_60px,transparent_60px_120px)]"></div>
  <!-- confetti -->
  <div id="confetti" class="pointer-events-none absolute inset-0 overflow-hidden"></div>

  <!-- top bar -->
  <header class="relative z-10 flex items-center justify-between gap-3 px-5 py-4">
    <a href="https://cardify.om" class="shrink-0"><img src="/assets/images/logo-light.svg" alt="Cardify" class="h-7 w-auto"></a>
    <nav class="flex flex-wrap gap-1.5 justify-end">
      <?php foreach (WcHub::LANGS as $code => $native): ?>
        <a href="?lang=<?= h($code) ?>" class="text-[13px] px-3 py-1.5 rounded-full border <?= $code===$lang
          ? 'bg-white text-pitch-deep font-bold border-white'
          : 'text-emerald-50/90 border-white/20 hover:border-white/40' ?>"><?= h($native) ?></a>
      <?php endforeach; ?>
    </nav>
  </header>

  <main class="relative z-10 mx-auto w-full max-w-xl px-5 pb-16">
    <span class="inline-flex items-center gap-2 rounded-full bg-gold/15 text-gold border border-gold/35 px-3.5 py-1.5 text-[13px] font-bold tracking-wide mt-3">⚽ <?= h($S['kicker']) ?></span>
    <h1 class="text-white font-bold leading-[1.12] mt-3 text-[clamp(28px,7vw,42px)]"><?= h($S['hero_title']) ?></h1>
    <p class="text-emerald-50/90 text-base leading-relaxed mt-3"><?= h($S['hero_sub']) ?></p>

    <!-- prize banner -->
    <div class="mt-5 rounded-2xl p-4 bg-[linear-gradient(90deg,#ffd34d,#f5b301)] text-pitch-dark shadow-[0_14px_40px_rgba(245,179,1,.3)]">
      <div class="text-[13px] font-bold uppercase tracking-wide opacity-80 mb-2">🏆 <?= h($S['win_title']) ?></div>
      <div class="grid grid-cols-3 gap-2 text-center">
        <div class="rounded-xl bg-white/35 py-2.5"><div class="text-lg">🥇</div><div class="font-extrabold text-[clamp(15px,4.4vw,20px)]">$10,000</div></div>
        <div class="rounded-xl bg-white/25 py-2.5"><div class="text-lg">🥈</div><div class="font-extrabold text-[clamp(15px,4.4vw,20px)]">$5,000</div></div>
        <div class="rounded-xl bg-white/20 py-2.5"><div class="text-lg">🥉</div><div class="font-extrabold text-[clamp(15px,4.4vw,20px)]">$1,000</div></div>
      </div>
    </div>

    <!-- STEP 1 -->
    <section id="step-form" class="mt-5 bg-white rounded-3xl p-6 shadow-[0_30px_70px_rgba(0,0,0,.35)] animate-pop">
      <div id="err1" class="hidden mb-3 rounded-xl bg-red-50 text-red-700 border border-red-200 px-3.5 py-2.5 text-sm"></div>
      <form id="signupForm" autocomplete="on" class="space-y-4">
        <div>
          <label for="name" class="block text-[13px] font-semibold text-slate-700 mb-1.5"><?= h($S['f_name']) ?></label>
          <input type="text" id="name" name="name" maxlength="120" required class="<?= $inputCls ?>">
        </div>
        <div>
          <label for="phone" class="block text-[13px] font-semibold text-slate-700 mb-1.5"><?= h($S['f_phone']) ?></label>
          <input type="tel" id="phone" name="phone" autocomplete="tel" required class="<?= $inputCls ?>">
        </div>
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label for="language" class="block text-[13px] font-semibold text-slate-700 mb-1.5"><?= h($S['f_language']) ?></label>
            <select id="language" name="language" class="<?= $inputCls ?>">
              <?php foreach (WcHub::LANGS as $code => $native): ?><option value="<?= h($code) ?>" <?= $code===$lang?'selected':'' ?>><?= h($native) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div>
            <label for="tz" class="block text-[13px] font-semibold text-slate-700 mb-1.5"><?= h($S['f_timezone']) ?></label>
            <select id="tz" name="tz" class="<?= $inputCls ?>">
              <?php foreach ($tzList as $z => $labelz): ?><option value="<?= h($z) ?>" <?= $z===$tzGuess?'selected':'' ?>><?= h($labelz) ?></option><?php endforeach; ?>
            </select>
          </div>
        </div>
        <p class="text-xs text-slate-500 -mt-1">📍 <?= h($S['tz_detected']) ?></p>
        <?php if ($turnstileSite): ?><div class="cf-turnstile" data-sitekey="<?= h($turnstileSite) ?>" data-size="flexible"></div><?php endif; ?>
        <button id="btnGet" type="submit" class="w-full rounded-2xl py-4 text-[17px] font-bold text-pitch-dark bg-[linear-gradient(90deg,#ffd34d,#f5b301)] shadow-[0_12px_30px_rgba(245,179,1,.35)] active:translate-y-px transition"><?= h($S['get_code']) ?> →</button>
      </form>
    </section>

    <!-- STEP 2 -->
    <section id="step-otp" hidden class="mt-5 bg-white rounded-3xl p-6 shadow-[0_30px_70px_rgba(0,0,0,.35)] animate-pop">
      <div id="err2" class="hidden mb-3 rounded-xl bg-red-50 text-red-700 border border-red-200 px-3.5 py-2.5 text-sm"></div>
      <h2 class="text-xl font-bold mb-1"><?= h($S['otp_title']) ?></h2>
      <p class="text-slate-500 text-sm mb-4"><?= h($S['otp_sub']) ?> <b id="maskTo"></b></p>
      <div id="otpGrid" dir="ltr" class="flex gap-2 justify-between">
        <?php for($i=0;$i<6;$i++): ?><input inputmode="numeric" maxlength="1" class="otp-in rounded-xl border-[1.5px] border-slate-200 text-2xl font-bold py-3.5 focus:border-cyan focus:ring-4 focus:ring-cyan/15 outline-none"><?php endfor; ?>
      </div>
      <button id="btnVerify" type="button" class="mt-4 w-full rounded-2xl py-4 text-[17px] font-bold text-white bg-cyan shadow-[0_12px_30px_rgba(0,155,193,.3)] active:translate-y-px transition"><?= h($S['verify']) ?></button>
      <div class="flex justify-between mt-3.5 text-sm">
        <a id="btnResend" class="text-cyan font-semibold cursor-pointer"><?= h($S['resend']) ?></a>
        <a id="btnChange" class="text-cyan font-semibold cursor-pointer"><?= h($S['change']) ?></a>
      </div>
    </section>

    <!-- STEP 3 -->
    <section id="step-done" hidden class="mt-5 bg-white rounded-3xl p-7 text-center shadow-[0_30px_70px_rgba(0,0,0,.35)] animate-pop">
      <div class="text-5xl mb-2">🎉⚽</div>
      <h2 class="text-2xl font-bold mb-1.5"><?= h($S['success_title']) ?></h2>
      <p class="text-slate-500 mb-5"><?= h($S['success_sub']) ?></p>
      <div class="space-y-2.5">
        <a href="/predictions" class="block w-full rounded-2xl py-4 text-[17px] font-bold text-pitch-dark bg-[linear-gradient(90deg,#ffd34d,#f5b301)]"><?= h($S['go_predict']) ?></a>
        <a href="/wc-wallet" class="block w-full rounded-2xl py-4 text-[17px] font-bold text-white bg-cyan"><?= h($S['add_wallet']) ?></a>
        <a id="btnInvite" class="block w-full rounded-2xl py-4 text-[17px] font-bold text-white bg-[#25d366] cursor-pointer"><?= h($S['invite']) ?></a>
      </div>
    </section>
  </main>

  <footer class="relative z-10 text-center pb-8 pt-1">
    <span class="inline-flex items-center gap-2 rounded-full bg-white/12 border border-white/20 text-white px-3.5 py-1.5 text-xs font-semibold">
      <img src="/assets/images/logo-light.svg" alt="" class="h-3.5 w-auto opacity-90"> <?= h($S['brand']) ?>
    </span>
  </footer>
</div>

<script src="https://cdn.jsdelivr.net/npm/intl-tel-input@25.10.1/build/js/intlTelInputWithUtils.js"></script>
<script>
const STR = <?= json_encode($S, JSON_UNESCAPED_UNICODE) ?>;
const INIT_COUNTRY = <?= json_encode(strtolower($cc ?: 'om')) ?>;
(function(){const c=document.getElementById('confetti');const cols=['#ffd34d','#009bc1','#ff5d5d','#2ecc71','#fff'];
for(let i=0;i<26;i++){const s=document.createElement('i');
s.className='absolute -top-3 w-[9px] h-[14px] rounded-[2px] animate-fall';
s.style.left=(Math.random()*100)+'%';s.style.background=cols[i%cols.length];
s.style.animationDuration=(6+Math.random()*6)+'s';s.style.animationDelay=(-Math.random()*8)+'s';c.appendChild(s);}})();

const phoneEl=document.getElementById('phone');
const iti=window.intlTelInput(phoneEl,{initialCountry:INIT_COUNTRY,separateDialCode:true,
  countryOrder:['om','ae','sa','in','bd','pk','ph','eg','gb','us'],autoPlaceholder:'polite',nationalMode:true});
const $=id=>document.getElementById(id);
function show(step){['step-form','step-otp','step-done'].forEach(s=>$(s).hidden=(s!==step));}
function showErr(b,m){b.textContent=m;b.classList.remove('hidden');}
function clearErr(b){b.classList.add('hidden');}
let CURRENT={phone:'',name:'',language:'',tz:''};

$('signupForm').addEventListener('submit',async e=>{
  e.preventDefault(); clearErr($('err1'));
  const name=$('name').value.trim();
  if(!name){showErr($('err1'),STR.err_name);return;}
  if(!iti.isValidNumber()){showErr($('err1'),STR.err_phone);return;}
  const phone=iti.getNumber().replace('+','');
  const language=$('language').value, tz=$('tz').value;
  const ts=(document.querySelector('[name=cf-turnstile-response]')||{}).value||'';
  const btn=$('btnGet'); btn.disabled=true; const orig=btn.innerHTML; btn.textContent=STR.sending;
  try{
    const r=await fetch('/api/wc-otp-request.php',{method:'POST',headers:{'Content-Type':'application/json'},
      body:JSON.stringify({name,phone,language,tz,turnstile:ts})});
    const j=await r.json();
    if(!j.ok){showErr($('err1'),STR[j.error]||STR.err_generic);btn.disabled=false;btn.innerHTML=orig;return;}
    CURRENT={phone,name,language,tz};
    $('maskTo').textContent=j.masked||('****'+phone.slice(-4));
    show('step-otp'); $('otpGrid').children[0].focus();
  }catch(_){showErr($('err1'),STR.err_generic);}
  btn.disabled=false; btn.innerHTML=orig;
});
const boxes=[...$('otpGrid').children];
boxes.forEach((b,i)=>{
  b.addEventListener('input',()=>{b.value=b.value.replace(/\D/g,'');if(b.value&&i<5)boxes[i+1].focus();});
  b.addEventListener('keydown',e=>{if(e.key==='Backspace'&&!b.value&&i>0)boxes[i-1].focus();});
  b.addEventListener('paste',e=>{const d=(e.clipboardData.getData('text')||'').replace(/\D/g,'').slice(0,6);
    if(d){d.split('').forEach((c,k)=>{if(boxes[k])boxes[k].value=c;});boxes[Math.min(d.length,5)].focus();e.preventDefault();}});
});
async function verify(){
  clearErr($('err2'));
  const code=boxes.map(b=>b.value).join('');
  if(code.length!==6){showErr($('err2'),STR.err_otp);return;}
  const btn=$('btnVerify'); btn.disabled=true;
  try{
    const r=await fetch('/api/wc-otp-verify.php',{method:'POST',headers:{'Content-Type':'application/json'},
      body:JSON.stringify({...CURRENT,code})});
    const j=await r.json();
    if(!j.ok){showErr($('err2'),STR[j.error]||STR.err_otp);btn.disabled=false;return;}
    show('step-done');
  }catch(_){showErr($('err2'),STR.err_generic);btn.disabled=false;}
}
$('btnVerify').addEventListener('click',verify);
$('btnChange').addEventListener('click',()=>show('step-form'));
$('btnResend').addEventListener('click',()=>fetch('/api/wc-otp-request.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({...CURRENT,turnstile:''})}));
$('btnInvite').addEventListener('click',()=>{const t=encodeURIComponent(STR.hero_title+' https://wc.cardify.om');window.open('https://api.whatsapp.com/send?text='+t,'_blank');});
</script>
</body>
</html>
