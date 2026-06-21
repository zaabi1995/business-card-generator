<?php
/**
 * wc.cardify.om - Cardify World Cup 2026 hub (signup + OTP).
 * Powered by Cardify. Captures every signup as a Cardify lead.
 */
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/WhatsApp.php';
require_once INCLUDES_DIR . '/WcHub.php';

$lang = WcHub::lang($_GET['lang'] ?? (WcHub::detectCountry() === 'OM' ? 'en' : 'en'));
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
<?php if ($turnstileSite): ?><script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script><?php endif; ?>
<style>
:root{
  --pitch1:#0a7d3c; --pitch2:#065a2b; --gold:#ffd34d; --gold2:#f5b301;
  --cyan:#009bc1; --ink:#0b1d14; --card:#ffffff; --muted:#5b6b62;
}
*{box-sizing:border-box} html,body{margin:0;padding:0}
body{
  font-family:'IBM Plex Sans Arabic',system-ui,-apple-system,sans-serif;
  color:var(--ink); background:#06351c;
  min-height:100dvh; -webkit-font-smoothing:antialiased;
}
.wrap{position:relative; min-height:100dvh; overflow:hidden;
  background:
    radial-gradient(1200px 600px at 80% -10%, rgba(255,211,77,.18), transparent 60%),
    radial-gradient(900px 500px at -10% 110%, rgba(0,155,193,.18), transparent 60%),
    linear-gradient(160deg,var(--pitch1),var(--pitch2) 55%,#04331b);
}
/* pitch stripes */
.wrap::before{content:"";position:absolute;inset:0;opacity:.06;
  background:repeating-linear-gradient(90deg,#fff 0 60px,transparent 60px 120px);pointer-events:none}
.confetti{position:absolute;inset:0;overflow:hidden;pointer-events:none}
.confetti i{position:absolute;top:-12px;width:9px;height:14px;border-radius:2px;opacity:.0;
  animation:fall linear infinite}
@keyframes fall{0%{transform:translateY(-10vh) rotate(0);opacity:0}
  10%{opacity:.9}100%{transform:translateY(110vh) rotate(540deg);opacity:.2}}
.top{display:flex;align-items:center;justify-content:space-between;gap:12px;
  padding:18px 20px;position:relative;z-index:5}
.brandlogo{display:flex;align-items:center;gap:10px;color:#fff;text-decoration:none;font-weight:700}
.brandlogo img{height:26px;width:auto;filter:brightness(0) invert(1)}
.langsel{display:flex;gap:6px;flex-wrap:wrap}
.langsel a{font-size:13px;color:#dff3e6;text-decoration:none;padding:6px 10px;border-radius:999px;
  border:1px solid rgba(255,255,255,.18)}
.langsel a.active{background:#fff;color:var(--pitch2);font-weight:700;border-color:#fff}
.main{position:relative;z-index:4;max-width:560px;margin:0 auto;padding:8px 20px 60px}
.kicker{display:inline-flex;align-items:center;gap:8px;background:rgba(255,211,77,.16);
  color:var(--gold);border:1px solid rgba(255,211,77,.35);padding:6px 14px;border-radius:999px;
  font-weight:700;font-size:13px;letter-spacing:.3px;margin:14px 0 14px}
h1{color:#fff;font-size:clamp(28px,7vw,40px);line-height:1.12;margin:0 0 12px;font-weight:700}
.sub{color:#d6efdd;font-size:16px;line-height:1.6;margin:0 0 22px}
.prize{display:flex;align-items:center;gap:10px;color:#04331b;background:linear-gradient(90deg,var(--gold),var(--gold2));
  border-radius:14px;padding:12px 16px;font-weight:700;margin:0 0 22px;box-shadow:0 10px 30px rgba(245,179,1,.25)}
.prize b{font-size:18px}
.card{background:var(--card);border-radius:22px;padding:22px;box-shadow:0 30px 70px rgba(0,0,0,.35)}
label{display:block;font-size:13px;font-weight:600;color:#33433b;margin:0 0 6px}
.field{margin-bottom:16px}
input[type=text],input[type=tel],select{width:100%;padding:14px 14px;border:1.5px solid #dde5e0;
  border-radius:12px;font-size:16px;font-family:inherit;background:#fff;color:var(--ink);outline:none}
input:focus,select:focus{border-color:var(--cyan);box-shadow:0 0 0 4px rgba(0,155,193,.14)}
.hint{font-size:12px;color:var(--muted);margin-top:6px}
.btn{width:100%;border:0;border-radius:14px;padding:16px;font-size:17px;font-weight:700;cursor:pointer;
  color:#04331b;background:linear-gradient(90deg,var(--gold),var(--gold2));
  box-shadow:0 12px 30px rgba(245,179,1,.35);transition:transform .08s}
.btn:active{transform:translateY(1px)} .btn[disabled]{opacity:.6;cursor:default}
.btn.alt{background:var(--cyan);color:#fff;box-shadow:0 12px 30px rgba(0,155,193,.3)}
.otpgrid{display:flex;gap:8px;justify-content:space-between;margin:6px 0 4px;direction:ltr}
.otpgrid input{width:100%;text-align:center;font-size:24px;font-weight:700;padding:14px 0;letter-spacing:0}
.muted-row{display:flex;justify-content:space-between;margin-top:14px;font-size:14px}
.muted-row a{color:var(--cyan);text-decoration:none;font-weight:600;cursor:pointer}
.err{background:#fff2f2;color:#b3261e;border:1px solid #f3c6c2;border-radius:10px;padding:10px 12px;
  font-size:14px;margin-bottom:12px;display:none}
.success{text-align:center}
.success .big{font-size:46px;margin:4px 0 8px}
.success h2{font-size:24px;margin:0 0 8px;color:var(--ink)}
.success p{color:var(--muted);margin:0 0 18px}
.succ-actions{display:flex;flex-direction:column;gap:10px}
.foot{position:relative;z-index:4;text-align:center;color:#bfe6cb;font-size:13px;padding:6px 20px 30px}
.foot a{color:#fff;text-decoration:none;font-weight:600}
.poweredpill{display:inline-flex;align-items:center;gap:6px;background:rgba(255,255,255,.12);
  border:1px solid rgba(255,255,255,.2);color:#fff;border-radius:999px;padding:5px 12px;font-size:12px;font-weight:600}
.poweredpill img{height:14px;filter:brightness(0) invert(1)}
.iti{width:100%}.iti__selected-dial-code{font-weight:700}
[hidden]{display:none!important}
</style>
</head>
<body>
<div class="wrap">
  <div class="confetti" id="confetti"></div>
  <div class="top">
    <a class="brandlogo" href="https://cardify.om"><img src="/assets/images/logo.svg" alt="Cardify"><span><?= h($S['brand']) ?></span></a>
    <nav class="langsel">
      <?php foreach (WcHub::LANGS as $code => $native): ?>
        <a href="?lang=<?= h($code) ?>" class="<?= $code===$lang?'active':'' ?>"><?= h($native) ?></a>
      <?php endforeach; ?>
    </nav>
  </div>

  <main class="main">
    <span class="kicker">⚽ <?= h($S['kicker']) ?></span>
    <h1><?= h($S['hero_title']) ?></h1>
    <p class="sub"><?= h($S['hero_sub']) ?></p>
    <div class="prize">🏆 <span><?= h($S['win_title']) ?>: <b><?= h($S['win_sub']) ?></b></span></div>

    <!-- STEP 1: details -->
    <section class="card" id="step-form">
      <div class="err" id="err1"></div>
      <form id="signupForm" autocomplete="on">
        <div class="field">
          <label for="name"><?= h($S['f_name']) ?></label>
          <input type="text" id="name" name="name" maxlength="120" required>
        </div>
        <div class="field">
          <label for="phone"><?= h($S['f_phone']) ?></label>
          <input type="tel" id="phone" name="phone" autocomplete="tel" required>
        </div>
        <div class="field">
          <label for="language"><?= h($S['f_language']) ?></label>
          <select id="language" name="language">
            <?php foreach (WcHub::LANGS as $code => $native): ?>
              <option value="<?= h($code) ?>" <?= $code===$lang?'selected':'' ?>><?= h($native) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="tz"><?= h($S['f_timezone']) ?></label>
          <select id="tz" name="tz">
            <?php foreach ($tzList as $z => $labelz): ?>
              <option value="<?= h($z) ?>" <?= $z===$tzGuess?'selected':'' ?>><?= h($labelz) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="hint">📍 <?= h($S['tz_detected']) ?></div>
        </div>
        <?php if ($turnstileSite): ?>
          <div class="cf-turnstile" data-sitekey="<?= h($turnstileSite) ?>" data-size="flexible" style="margin-bottom:14px"></div>
        <?php endif; ?>
        <button class="btn" id="btnGet" type="submit"><?= h($S['get_code']) ?> →</button>
      </form>
    </section>

    <!-- STEP 2: otp -->
    <section class="card" id="step-otp" hidden>
      <div class="err" id="err2"></div>
      <h2 style="margin:0 0 6px;font-size:20px"><?= h($S['otp_title']) ?></h2>
      <p style="color:var(--muted);margin:0 0 16px;font-size:14px"><?= h($S['otp_sub']) ?> <b id="maskTo"></b></p>
      <div class="otpgrid" id="otpGrid">
        <input inputmode="numeric" maxlength="1"><input inputmode="numeric" maxlength="1">
        <input inputmode="numeric" maxlength="1"><input inputmode="numeric" maxlength="1">
        <input inputmode="numeric" maxlength="1"><input inputmode="numeric" maxlength="1">
      </div>
      <button class="btn alt" id="btnVerify" type="button" style="margin-top:14px"><?= h($S['verify']) ?></button>
      <div class="muted-row">
        <a id="btnResend"><?= h($S['resend']) ?></a>
        <a id="btnChange"><?= h($S['change']) ?></a>
      </div>
    </section>

    <!-- STEP 3: success -->
    <section class="card success" id="step-done" hidden>
      <div class="big">🎉⚽</div>
      <h2><?= h($S['success_title']) ?></h2>
      <p><?= h($S['success_sub']) ?></p>
      <div class="succ-actions">
        <a class="btn" href="/predictions"><?= h($S['go_predict']) ?></a>
        <a class="btn alt" href="/wc-wallet"><?= h($S['add_wallet']) ?></a>
        <a class="btn" style="background:#25d366;color:#fff" id="btnInvite"><?= h($S['invite']) ?></a>
      </div>
    </section>
  </main>

  <div class="foot">
    <span class="poweredpill"><img src="/assets/images/logo.svg" alt=""> <?= h($S['brand']) ?></span>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/intl-tel-input@25.10.1/build/js/intlTelInputWithUtils.js"></script>
<script>
const STR = <?= json_encode($S, JSON_UNESCAPED_UNICODE) ?>;
const INIT_COUNTRY = <?= json_encode(strtolower($cc ?: 'om')) ?>;
// confetti
(function(){const c=document.getElementById('confetti');const cols=['#ffd34d','#009bc1','#ff5d5d','#2ecc71','#fff'];
for(let i=0;i<26;i++){const s=document.createElement('i');s.style.left=(Math.random()*100)+'%';
s.style.background=cols[i%cols.length];s.style.animationDuration=(6+Math.random()*6)+'s';
s.style.animationDelay=(-Math.random()*8)+'s';c.appendChild(s);}})();

const phoneEl=document.getElementById('phone');
const iti=window.intlTelInput(phoneEl,{initialCountry:INIT_COUNTRY,separateDialCode:true,
  countryOrder:['om','ae','sa','in','bd','pk','ph','eg','gb','us'],autoPlaceholder:'polite',
  nationalMode:true});

const $=id=>document.getElementById(id);
function show(step){['step-form','step-otp','step-done'].forEach(s=>$(s).hidden=(s!==step));}
function showErr(box,msg){box.textContent=msg;box.style.display='block';}
function clearErr(box){box.style.display='none';}

let CURRENT={phone:'',name:'',language:'',tz:''};

document.getElementById('signupForm').addEventListener('submit',async e=>{
  e.preventDefault(); clearErr($('err1'));
  const name=$('name').value.trim();
  if(!name){showErr($('err1'),STR.err_name);return;}
  if(!iti.isValidNumber()){showErr($('err1'),STR.err_phone);return;}
  const phone=iti.getNumber().replace('+','');
  const language=$('language').value, tz=$('tz').value;
  const ts=(document.querySelector('[name=cf-turnstile-response]')||{}).value||'';
  const btn=$('btnGet'); btn.disabled=true; const orig=btn.textContent; btn.textContent=STR.sending;
  try{
    const r=await fetch('/api/wc-otp-request.php',{method:'POST',headers:{'Content-Type':'application/json'},
      body:JSON.stringify({name,phone,language,tz,turnstile:ts})});
    const j=await r.json();
    if(!j.ok){showErr($('err1'),STR[j.error]||STR.err_generic);btn.disabled=false;btn.textContent=orig;return;}
    CURRENT={phone,name,language,tz};
    $('maskTo').textContent=j.masked||('****'+phone.slice(-4));
    show('step-otp'); $('otpGrid').children[0].focus();
  }catch(_){showErr($('err1'),STR.err_generic);}
  btn.disabled=false; btn.textContent=orig;
});

// otp boxes
const boxes=[...document.getElementById('otpGrid').children];
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
$('btnChange').addEventListener('click',()=>{show('step-form');});
$('btnResend').addEventListener('click',async()=>{
  clearErr($('err2'));
  await fetch('/api/wc-otp-request.php',{method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({...CURRENT,turnstile:''})});
});
$('btnInvite')&&$('btnInvite').addEventListener('click',()=>{
  const t=encodeURIComponent(STR.hero_title+' '+ 'https://wc.cardify.om');
  window.open('https://api.whatsapp.com/send?text='+t,'_blank');
});
</script>
</body>
</html>
