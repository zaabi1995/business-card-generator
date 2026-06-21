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
$rtl = WcHub::isRtl($lang);
$inputCls = 'w-full px-4 py-3 rounded-xl border border-slate-300 bg-white text-slate-900 text-base outline-none focus:border-blue-600 focus:ring-4 focus:ring-blue-600/10 transition';
// honest copy (language-aware where it matters; English fallback otherwise)
$T = [
  'signin' => $rtl ? 'تسجيل الدخول' : 'Sign in',
  'join'   => $rtl ? 'انضم مجانًا' : 'Join free',
  'trust1' => $rtl ? 'مجاني' : 'Free',
  'trust2' => $rtl ? 'بدون إزعاج' : 'No spam',
  'trust3' => $rtl ? 'إلغاء الاشتراك في أي وقت' : 'Unsubscribe anytime',
  'micro'  => $rtl ? 'سنرسل رمز تأكيد على واتساب. نستخدم رقمك فقط لتحديثات كأس العالم.' : "We'll send a WhatsApp code to confirm. We use your number only for World Cup updates.",
  'how'    => $rtl ? 'كيف يعمل' : 'How it works',
  'how1'   => $rtl ? 'سجّل برقم واتساب' : 'Sign up with WhatsApp',
  'how1d'  => $rtl ? 'تأكيد سريع برمز لمرة واحدة.' : 'Quick confirm with a one-time code.',
  'how2'   => $rtl ? 'استلم المباريات يوميًا' : 'Get matches daily',
  'how2d'  => $rtl ? 'المواعيد والنتائج بلغتك وتوقيتك، الساعة 10 صباحًا.' : 'Fixtures and results in your language and timezone, at 10am.',
  'how3'   => $rtl ? 'توقّع وتصدّر' : 'Predict & climb',
  'how3d'  => $rtl ? 'العب لعبة التوقعات المجانية واجمع النقاط.' : 'Play the free prediction game and earn points.',
  'league' => $rtl ? 'دوري التوقعات المجاني' : 'Free prediction league',
  'leagued'=> $rtl ? 'توقّع النتائج، اجمع النقاط (نقطة لكل نتيجة صحيحة، +2 للنتيجة الدقيقة)، وتصدّر الترتيب. أصحاب أعلى النقاط في الترتيب النهائي يفوزون بجائزة.' : 'Predict results, earn points (1 per correct result, +2 for the exact score), and climb the table. The top of the final leaderboard wins a prize.',
  'rules_link' => $rtl ? 'القواعد والأهلية' : 'Rules & eligibility',
  'disc'   => $rtl ? 'للمشاركين 18+. تُدفع الجوائز بعد التحقق من الهوية. تطبق الشروط.' : 'Open to entrants 18+. Prizes paid after identity verification. Terms apply.',
  'rules_h'=> $rtl ? 'القواعد' : 'Contest rules',
  'fifa'   => $rtl ? 'هذا الموقع غير تابع لفيفا ولا معتمد منها. "كأس العالم" علامة تجارية لمالكها.' : 'This site is not affiliated with, or endorsed by, FIFA. "FIFA World Cup" is a trademark of its owner.',
  'about'  => $rtl ? 'Cardify منصّة بطاقات أعمال رقمية مقرّها مسقط، عُمان.' : 'Cardify is a digital business-card platform based in Muscat, Oman.',
  'privacy'=> $rtl ? 'نستخدم رقمك فقط لإرسال تحديثات كأس العالم التي طلبتها. لا نبيع بياناتك.' : 'We use your number only to send the World Cup updates you asked for. We never sell your data.',
];
$rules = $rtl ? [
  'الدخول مجاني تمامًا، بدون أي رسوم.',
  'تُغلق التوقعات عند بداية كل مباراة.',
  'نقطة واحدة للنتيجة الصحيحة، و+2 للنتيجة الدقيقة.',
  'الترتيب حسب مجموع النقاط في نهاية البطولة.',
  'أفضل 3 متصدرين يفوزون بـ 10,000 / 5,000 / 1,000 دولار، تُدفع بتحويل بنكي بعد التحقق.',
  'في حال التعادل في النقاط، تُحسم الأفضلية بعدد النتائج الدقيقة ثم بأسبقية التسجيل.',
] : [
  'Free to enter. No purchase, no fees.',
  'Predictions lock at each match kickoff.',
  '1 point for the correct result, +2 for the exact score.',
  'Ranking is by total points at the end of the tournament.',
  'Top 3 win $10,000 / $5,000 / $1,000, paid by bank transfer after identity verification.',
  'Ties are broken by exact-score count, then earliest signup.',
];
?>
<!DOCTYPE html>
<html lang="<?= h($lang) ?>" dir="<?= h($dir) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= h($S['kicker']) ?> on WhatsApp · Cardify</title>
<meta name="description" content="<?= h($S['hero_sub']) ?>">
<meta property="og:title" content="<?= h($S['hero_title']) ?>">
<meta property="og:description" content="<?= h($S['hero_sub']) ?>">
<meta property="og:image" content="https://wc.cardify.om/assets/wc/og.jpg?v=2">
<meta property="og:url" content="https://wc.cardify.om/">
<meta property="og:type" content="website">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:image" content="https://wc.cardify.om/assets/wc/og.jpg?v=2">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="alternate icon" href="/favicon.ico">
<link rel="preconnect" href="https://fonts.bhd.om" crossorigin>
<link rel="stylesheet" href="https://fonts.bhd.om/css2?family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/intl-tel-input@25.10.1/build/css/intlTelInput.css">
<script src="https://cdn.tailwindcss.com"></script>
<script>tailwind.config={theme:{extend:{fontFamily:{sans:['IBM Plex Sans Arabic','system-ui','sans-serif']}}}}</script>
<?php if ($turnstileSite): ?><script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script><?php endif; ?>
<style>
  body{font-family:'IBM Plex Sans Arabic',system-ui,sans-serif}
  .iti{width:100%} .iti__selected-dial-code{font-weight:700}
  .otp-in{width:100%;text-align:center}
  [hidden]{display:none!important}
</style>
</head>
<body class="bg-slate-50 text-slate-900">

<header class="sticky top-0 z-30 bg-white/90 backdrop-blur border-b border-slate-200">
  <div class="max-w-6xl mx-auto px-5 h-16 flex items-center justify-between gap-3">
    <a href="https://cardify.om" class="shrink-0"><img src="/assets/images/logo.svg" alt="Cardify" class="h-7 w-auto"></a>
    <div class="flex items-center gap-2">
      <nav class="hidden sm:flex gap-1">
        <?php foreach (WcHub::LANGS as $code => $native): ?>
          <a href="?lang=<?= h($code) ?>" class="text-[13px] px-2.5 py-1.5 rounded-lg <?= $code===$lang?'bg-slate-100 text-slate-900 font-semibold':'text-slate-500 hover:text-slate-900' ?>"><?= h($native) ?></a>
        <?php endforeach; ?>
      </nav>
      <a href="/predictions" class="text-sm font-semibold text-blue-700 px-3 py-2 rounded-lg hover:bg-blue-50"><?= h($T['signin']) ?></a>
    </div>
  </div>
  <nav class="sm:hidden flex gap-1 px-4 pb-2 overflow-x-auto">
    <?php foreach (WcHub::LANGS as $code => $native): ?>
      <a href="?lang=<?= h($code) ?>" class="text-[13px] px-2.5 py-1 rounded-lg whitespace-nowrap <?= $code===$lang?'bg-slate-100 font-semibold':'text-slate-500' ?>"><?= h($native) ?></a>
    <?php endforeach; ?>
  </nav>
</header>

<main>
  <!-- HERO -->
  <section class="max-w-6xl mx-auto px-5 pt-10 pb-6 grid lg:grid-cols-2 gap-10 items-center">
    <div>
      <span class="inline-flex items-center gap-2 rounded-full bg-blue-50 text-blue-700 px-3 py-1.5 text-[13px] font-semibold">⚽ <?= h($S['kicker']) ?></span>
      <h1 class="mt-4 text-slate-900 font-bold leading-[1.1] text-[clamp(30px,6vw,46px)]"><?= h($S['hero_title']) ?></h1>
      <p class="mt-4 text-slate-600 text-lg leading-relaxed max-w-xl"><?= h($S['hero_sub']) ?></p>
      <div class="mt-5 flex flex-wrap gap-2 text-sm">
        <?php foreach ([$T['trust1'],$T['trust2'],$T['trust3']] as $chip): ?>
          <span class="inline-flex items-center gap-1.5 text-slate-600"><svg class="w-4 h-4 text-emerald-500" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 010 1.4l-7.5 7.5a1 1 0 01-1.4 0L3.3 9.7a1 1 0 011.4-1.4l3.1 3.1 6.8-6.8a1 1 0 011.4 0z" clip-rule="evenodd"/></svg><?= h($chip) ?></span>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- signup card -->
    <div class="lg:justify-self-end w-full max-w-md mx-auto">
      <section id="step-form" class="bg-white rounded-2xl p-6 shadow-xl shadow-slate-200/70 border border-slate-100">
        <h2 class="font-bold text-lg mb-1"><?= h($T['join']) ?></h2>
        <p class="text-sm text-slate-500 mb-4"><?= h($T['micro']) ?></p>
        <div id="err1" class="hidden mb-3 rounded-xl bg-red-50 text-red-700 border border-red-200 px-3.5 py-2.5 text-sm"></div>
        <form id="signupForm" autocomplete="on" class="space-y-3.5">
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
              <select id="language" name="language" class="<?= $inputCls ?>"><?php foreach (WcHub::LANGS as $code => $native): ?><option value="<?= h($code) ?>" <?= $code===$lang?'selected':'' ?>><?= h($native) ?></option><?php endforeach; ?></select>
            </div>
            <div>
              <label for="tz" class="block text-[13px] font-semibold text-slate-700 mb-1.5"><?= h($S['f_timezone']) ?></label>
              <select id="tz" name="tz" class="<?= $inputCls ?>"><?php foreach ($tzList as $z => $labelz): ?><option value="<?= h($z) ?>" <?= $z===$tzGuess?'selected':'' ?>><?= h($labelz) ?></option><?php endforeach; ?></select>
            </div>
          </div>
          <p class="text-xs text-slate-400">📍 <?= h($S['tz_detected']) ?></p>
          <?php if ($turnstileSite): ?><div class="cf-turnstile" data-sitekey="<?= h($turnstileSite) ?>" data-size="flexible"></div><?php endif; ?>
          <button id="btnGet" type="submit" class="w-full rounded-xl py-3.5 text-base font-semibold text-white bg-blue-600 hover:bg-blue-700 active:translate-y-px transition"><?= h($T['join']) ?></button>
        </form>
      </section>

      <section id="step-otp" hidden class="bg-white rounded-2xl p-6 shadow-xl shadow-slate-200/70 border border-slate-100">
        <div id="err2" class="hidden mb-3 rounded-xl bg-red-50 text-red-700 border border-red-200 px-3.5 py-2.5 text-sm"></div>
        <h2 class="text-lg font-bold mb-1"><?= h($S['otp_title']) ?></h2>
        <p class="text-slate-500 text-sm mb-4"><?= h($S['otp_sub']) ?> <b id="maskTo"></b></p>
        <div id="otpGrid" dir="ltr" class="flex gap-2 justify-between">
          <?php for($i=0;$i<6;$i++): ?><input inputmode="numeric" maxlength="1" class="otp-in rounded-xl border border-slate-300 text-2xl font-bold py-3 focus:border-blue-600 focus:ring-4 focus:ring-blue-600/10 outline-none"><?php endfor; ?>
        </div>
        <button id="btnVerify" type="button" class="mt-4 w-full rounded-xl py-3.5 text-base font-semibold text-white bg-blue-600 hover:bg-blue-700"><?= h($S['verify']) ?></button>
        <div class="flex justify-between mt-3 text-sm">
          <a id="btnResend" class="text-blue-700 font-semibold cursor-pointer"><?= h($S['resend']) ?></a>
          <a id="btnChange" class="text-slate-500 font-semibold cursor-pointer"><?= h($S['change']) ?></a>
        </div>
      </section>

      <section id="step-done" hidden class="bg-white rounded-2xl p-7 text-center shadow-xl shadow-slate-200/70 border border-slate-100">
        <div class="text-4xl mb-2">⚽</div>
        <h2 class="text-xl font-bold mb-1.5"><?= h($S['success_title']) ?></h2>
        <p class="text-slate-500 mb-5"><?= h($S['success_sub']) ?></p>
        <div class="space-y-2.5">
          <a href="/predictions" class="block w-full rounded-xl py-3.5 font-semibold text-white bg-blue-600"><?= h($S['go_predict']) ?></a>
          <a id="btnInvite" class="block w-full rounded-xl py-3.5 font-semibold text-slate-700 bg-slate-100 cursor-pointer"><?= h($S['invite']) ?></a>
        </div>
      </section>
    </div>
  </section>

  <!-- HOW IT WORKS -->
  <section class="max-w-6xl mx-auto px-5 py-10">
    <h2 class="text-center text-xl font-bold text-slate-900 mb-6"><?= h($T['how']) ?></h2>
    <div class="grid sm:grid-cols-3 gap-4">
      <?php foreach ([['1️⃣',$T['how1'],$T['how1d']],['2️⃣',$T['how2'],$T['how2d']],['3️⃣',$T['how3'],$T['how3d']]] as $st): ?>
        <div class="bg-white rounded-2xl p-5 border border-slate-100">
          <div class="text-2xl mb-2"><?= $st[0] ?></div>
          <div class="font-semibold text-slate-900"><?= h($st[1]) ?></div>
          <p class="text-sm text-slate-500 mt-1"><?= h($st[2]) ?></p>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <!-- PREDICTION LEAGUE -->
  <section class="max-w-6xl mx-auto px-5 py-6">
    <div class="bg-white rounded-2xl border border-slate-100 p-6 sm:p-8">
      <div class="flex flex-col sm:flex-row sm:items-center gap-5 justify-between">
        <div class="max-w-xl">
          <h2 class="text-xl font-bold text-slate-900">🏆 <?= h($T['league']) ?></h2>
          <p class="mt-2 text-slate-600"><?= h($T['leagued']) ?></p>
          <p class="mt-3 text-xs text-slate-400"><?= h($T['disc']) ?> <a href="#rules" class="text-blue-700 font-semibold underline"><?= h($T['rules_link']) ?></a></p>
        </div>
        <div class="grid grid-cols-3 gap-2 shrink-0">
          <?php foreach ([['🥇','$10,000'],['🥈','$5,000'],['🥉','$1,000']] as $pz): ?>
            <div class="text-center rounded-xl border border-slate-200 px-3 py-3 min-w-[84px]">
              <div class="text-lg"><?= $pz[0] ?></div><div class="font-bold text-slate-900"><?= $pz[1] ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </section>

  <!-- RULES -->
  <section id="rules" class="max-w-3xl mx-auto px-5 py-10">
    <h2 class="text-lg font-bold text-slate-900 mb-3"><?= h($T['rules_h']) ?></h2>
    <ul class="space-y-2 text-sm text-slate-600 list-disc ps-5">
      <?php foreach ($rules as $r): ?><li><?= h($r) ?></li><?php endforeach; ?>
    </ul>
    <p class="mt-4 text-xs text-slate-400"><?= h($T['fifa']) ?></p>
  </section>
</main>

<footer class="border-t border-slate-200 bg-white">
  <div class="max-w-6xl mx-auto px-5 py-8 text-sm text-slate-500">
    <div class="flex items-center gap-2 mb-3"><img src="/assets/images/logo.svg" alt="Cardify" class="h-6 w-auto"></div>
    <p class="max-w-xl"><?= h($T['about']) ?></p>
    <p class="max-w-xl mt-2"><?= h($T['privacy']) ?></p>
    <div class="mt-4 flex flex-wrap gap-x-5 gap-y-2">
      <a href="https://cardify.om" class="hover:text-slate-900">cardify.om</a>
      <a href="#rules" class="hover:text-slate-900"><?= h($T['rules_h']) ?></a>
      <a href="/wc-leaderboard" class="hover:text-slate-900"><?= h($lang==='ar'?'المتصدرون':'Leaderboard') ?></a>
    </div>
    <p class="mt-4 text-xs text-slate-400">© <?= date('Y') ?> Cardify · <?= h($S['brand']) ?></p>
  </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/intl-tel-input@25.10.1/build/js/intlTelInputWithUtils.js"></script>
<script>
const STR = <?= json_encode($S, JSON_UNESCAPED_UNICODE) ?>;
const INIT_COUNTRY = <?= json_encode(strtolower($cc ?: 'om')) ?>;
const phoneEl=document.getElementById('phone');
const iti=window.intlTelInput(phoneEl,{initialCountry:INIT_COUNTRY,separateDialCode:true,
  countryOrder:['om','ae','sa','in','bd','pk','ph','eg','gb','us'],autoPlaceholder:'polite',nationalMode:true});
const $=id=>document.getElementById(id);
function show(step){['step-form','step-otp','step-done'].forEach(s=>$(s).hidden=(s!==step));window.scrollTo({top:0,behavior:'smooth'});}
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
  const btn=$('btnGet'); btn.disabled=true; const orig=btn.textContent; btn.textContent=STR.sending;
  try{
    const r=await fetch('/api/wc-otp-request.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({name,phone,language,tz,turnstile:ts})});
    const j=await r.json();
    if(!j.ok){showErr($('err1'),STR[j.error]||STR.err_generic);btn.disabled=false;btn.textContent=orig;return;}
    CURRENT={phone,name,language,tz}; $('maskTo').textContent=j.masked||('****'+phone.slice(-4));
    show('step-otp'); $('otpGrid').children[0].focus();
  }catch(_){showErr($('err1'),STR.err_generic);}
  btn.disabled=false; btn.textContent=orig;
});
const boxes=[...$('otpGrid').children];
boxes.forEach((b,i)=>{
  b.addEventListener('input',()=>{b.value=b.value.replace(/\D/g,'');if(b.value&&i<5)boxes[i+1].focus();});
  b.addEventListener('keydown',e=>{if(e.key==='Backspace'&&!b.value&&i>0)boxes[i-1].focus();});
  b.addEventListener('paste',e=>{const d=(e.clipboardData.getData('text')||'').replace(/\D/g,'').slice(0,6);if(d){d.split('').forEach((c,k)=>{if(boxes[k])boxes[k].value=c;});boxes[Math.min(d.length,5)].focus();e.preventDefault();}});
});
async function verify(){
  clearErr($('err2'));
  const code=boxes.map(b=>b.value).join('');
  if(code.length!==6){showErr($('err2'),STR.err_otp);return;}
  const btn=$('btnVerify'); btn.disabled=true;
  try{
    const r=await fetch('/api/wc-otp-verify.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({...CURRENT,code})});
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
