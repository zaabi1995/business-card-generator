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
$rtl  = WcHub::isRtl($lang);

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

// FontAwesome 7 from design.bhd.om (no emoji, consistent across OS/browsers).
function fa($cls){ return '<i class="'.$cls.'" aria-hidden="true"></i>'; }
$T = [
  'signin' => $rtl ? 'تسجيل الدخول' : 'Sign in',
  'join'   => $rtl ? 'انضم مجانًا' : 'Join free',
  'trust1' => $rtl ? 'مجاني' : 'Free',
  'trust2' => $rtl ? 'بدون إزعاج' : 'No spam',
  'trust3' => $rtl ? 'إلغاء الاشتراك في أي وقت' : 'Unsubscribe anytime',
  'micro'  => $rtl ? 'سنرسل رمز تأكيد على واتساب. نستخدم رقمك فقط لتحديثات كأس العالم.' : "We send a WhatsApp code to confirm. Your number is used only for World Cup updates.",
  'how'    => $rtl ? 'كيف يعمل' : 'How it works',
  'how1'   => $rtl ? 'سجّل برقم واتساب' : 'Sign up with WhatsApp',
  'how1d'  => $rtl ? 'تأكيد سريع برمز لمرة واحدة.' : 'A quick one-time code confirms it is you.',
  'how2'   => $rtl ? 'استلم المباريات يوميًا' : 'Daily matches at 10am',
  'how2d'  => $rtl ? 'المواعيد والنتائج بلغتك وتوقيتك.' : 'Fixtures and results in your language and timezone.',
  'how3'   => $rtl ? 'توقّع وتصدّر الترتيب' : 'Predict and climb',
  'how3d'  => $rtl ? 'النقاط حسب توقعاتك، تصدّر لوحة المتصدرين.' : 'Earn points for correct calls, rise up the table.',
  'league' => $rtl ? 'دوري التوقعات المجاني' : 'The prediction league',
  'leagued'=> $rtl ? 'توقّع النتائج، اجمع النقاط (نقطة لكل نتيجة صحيحة، +2 للنتيجة الدقيقة)، وتصدّر الترتيب. أصحاب أعلى النقاط في الترتيب النهائي يفوزون.' : 'Call the results, earn points (1 per correct result, +2 for the exact score), and climb the table. The top of the final standings takes the prize pool.',
  'pool'   => $rtl ? 'مجموع الجوائز' : 'Prize pool',
  'first'  => $rtl ? 'الأول' : '1st',
  'second' => $rtl ? 'الثاني' : '2nd',
  'third'  => $rtl ? 'الثالث' : '3rd',
  'rules_link' => $rtl ? 'القواعد والأهلية' : 'Rules and eligibility',
  'disc'   => $rtl ? 'للمشاركين 18+. تُدفع الجوائز بتحويل بنكي بعد التحقق من الهوية. تطبق الشروط.' : 'Open to entrants 18 and over. Prizes paid by bank transfer after identity verification. Terms apply.',
  'rules_h'=> $rtl ? 'القواعد' : 'Contest rules',
  'fifa'   => $rtl ? 'هذا الموقع غير تابع لفيفا ولا معتمد منها. "كأس العالم" علامة تجارية لمالكها.' : 'Not affiliated with, or endorsed by, FIFA. "FIFA World Cup" is a trademark of its owner.',
  'about'  => $rtl ? 'Cardify منصّة بطاقات أعمال رقمية مقرّها مسقط، عُمان.' : 'Cardify is a digital business-card platform based in Muscat, Oman.',
  'privacy'=> $rtl ? 'نستخدم رقمك فقط لإرسال تحديثات كأس العالم التي طلبتها. لا نبيع بياناتك.' : 'Your number is used only for the World Cup updates you asked for. We never sell your data.',
];
$rules = $rtl ? [
  'الدخول مجاني تمامًا، بدون أي رسوم.',
  'تُغلق التوقعات عند بداية كل مباراة.',
  'نقطة واحدة للنتيجة الصحيحة، و+2 للنتيجة الدقيقة.',
  'الترتيب حسب مجموع النقاط في نهاية البطولة.',
  'أفضل 3 متصدرين يفوزون بـ 10,000 / 5,000 / 1,000 دولار، تُدفع بتحويل بنكي بعد التحقق.',
  'في حال التعادل، تُحسم الأفضلية بعدد النتائج الدقيقة ثم بأسبقية التسجيل.',
] : [
  'Free to enter. No purchase, no fees.',
  'Predictions lock at each match kickoff.',
  '1 point for the correct result, +2 for the exact score.',
  'Ranking is by total points at the end of the tournament.',
  'Top 3 receive $10,000 / $5,000 / $1,000, paid by bank transfer after identity verification.',
  'Ties are broken by exact-score count, then earliest signup.',
];
$inputCls = 'w-full px-3.5 py-3 rounded-xl border border-slate-200 bg-white text-slate-900 text-base outline-none focus:border-blue-600 focus:ring-4 focus:ring-blue-600/10 transition';
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
<link rel="stylesheet" href="https://design.bhd.om/fa/v7.2.0/css/fontawesome.min.css">
<link rel="stylesheet" href="https://design.bhd.om/fa/v7.2.0/css/light.min.css">
<link rel="stylesheet" href="https://design.bhd.om/fa/v7.2.0/css/solid.min.css">
<link rel="stylesheet" href="https://design.bhd.om/fa/v7.2.0/css/brands.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/intl-tel-input@25.10.1/build/css/intlTelInput.css">
<script src="https://cdn.tailwindcss.com"></script>
<script>tailwind.config={theme:{extend:{fontFamily:{sans:['IBM Plex Sans Arabic','system-ui','sans-serif']}}}}</script>
<?php if ($turnstileSite): ?><script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script><?php endif; ?>
<style>
  :root{ --ease-out:cubic-bezier(0.23,1,0.32,1); }
  body{ font-family:'IBM Plex Sans Arabic',system-ui,sans-serif; background:#f7f8fa; color:#0f172a; }
  .ico{ display:inline-block; width:1em; height:1em; vertical-align:-0.125em; }
  .btn{ transition:transform .16s var(--ease-out), background-color .16s var(--ease-out); }
  .btn:active{ transform:scale(.97); }
  .diffuse{ box-shadow:0 24px 48px -24px rgba(15,23,42,.18); }
  .rise{ opacity:0; transform:translateY(10px); animation:rise .6s var(--ease-out) forwards; }
  @keyframes rise{ to{ opacity:1; transform:none; } }
  .iti{width:100%} .iti__selected-dial-code{font-weight:600}
  .otp-in{width:100%;text-align:center}
  [hidden]{display:none!important}
  @media (prefers-reduced-motion:reduce){ .rise{animation:none;opacity:1;transform:none} .btn{transition:none} }
</style>
</head>
<body>

<header class="sticky top-0 z-30 bg-[#f7f8fa]/85 backdrop-blur border-b border-slate-200/70">
  <div class="max-w-6xl mx-auto px-5 h-16 flex items-center justify-between gap-3">
    <a href="https://cardify.om" class="shrink-0"><img src="/assets/images/logo.svg" alt="Cardify" class="h-7 w-auto"></a>
    <div class="flex items-center gap-1.5">
      <nav class="hidden sm:flex gap-0.5">
        <?php foreach (WcHub::LANGS as $code => $native): ?>
          <a href="?lang=<?= h($code) ?>" class="text-[13px] px-2.5 py-1.5 rounded-lg <?= $code===$lang?'bg-white text-slate-900 font-semibold shadow-sm':'text-slate-500 hover:text-slate-900' ?>"><?= h($native) ?></a>
        <?php endforeach; ?>
      </nav>
      <a href="/predictions" class="text-sm font-semibold text-blue-700 px-3 py-2 rounded-lg hover:bg-white"><?= h($T['signin']) ?></a>
    </div>
  </div>
  <nav class="sm:hidden flex gap-1 px-4 pb-2 overflow-x-auto">
    <?php foreach (WcHub::LANGS as $code => $native): ?>
      <a href="?lang=<?= h($code) ?>" class="text-[13px] px-2.5 py-1 rounded-lg whitespace-nowrap <?= $code===$lang?'bg-white font-semibold shadow-sm':'text-slate-500' ?>"><?= h($native) ?></a>
    <?php endforeach; ?>
  </nav>
</header>

<main>
  <!-- HERO -->
  <section class="max-w-6xl mx-auto px-5 pt-12 pb-10 grid lg:grid-cols-[1.05fr_0.95fr] gap-12 items-center">
    <div>
      <span class="rise inline-flex items-center gap-2 rounded-full bg-white border border-slate-200/80 text-slate-700 ps-2.5 pe-3 py-1.5 text-[13px] font-semibold shadow-sm" style="animation-delay:.02s">
        <?= fa('fa-solid fa-futbol text-blue-600 text-[16px]') ?><?= h($S['kicker']) ?>
      </span>
      <h1 class="rise mt-5 font-bold tracking-tight leading-[1.05] text-[clamp(32px,5.4vw,52px)]" style="animation-delay:.06s"><?= h($S['hero_title']) ?></h1>
      <p class="rise mt-4 text-slate-600 text-lg leading-relaxed max-w-[60ch]" style="animation-delay:.1s"><?= h($S['hero_sub']) ?></p>
      <div class="rise mt-6 flex flex-wrap gap-x-5 gap-y-2 text-sm text-slate-600" style="animation-delay:.14s">
        <?php foreach ([$T['trust1'],$T['trust2'],$T['trust3']] as $chip): ?>
          <span class="inline-flex items-center gap-1.5"><?= fa('fa-solid fa-circle-check text-emerald-600 text-[15px]') ?><?= h($chip) ?></span>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- signup card -->
    <div class="rise w-full max-w-md lg:justify-self-end mx-auto" style="animation-delay:.12s">
      <section id="step-form" class="bg-white rounded-2xl p-6 sm:p-7 border border-slate-200/70 diffuse">
        <h2 class="font-bold text-lg tracking-tight"><?= h($T['join']) ?></h2>
        <p class="text-sm text-slate-500 mt-1 mb-5"><?= h($T['micro']) ?></p>
        <div id="err1" class="hidden mb-3 rounded-xl bg-rose-50 text-rose-700 border border-rose-200 px-3.5 py-2.5 text-sm"></div>
        <form id="signupForm" autocomplete="on" class="space-y-3.5">
          <div class="flex flex-col gap-1.5">
            <label for="name" class="text-[13px] font-semibold text-slate-700"><?= h($S['f_name']) ?></label>
            <input type="text" id="name" name="name" maxlength="120" required class="<?= $inputCls ?>">
          </div>
          <div class="flex flex-col gap-1.5">
            <label for="phone" class="text-[13px] font-semibold text-slate-700"><?= h($S['f_phone']) ?></label>
            <input type="tel" id="phone" name="phone" autocomplete="tel" required class="<?= $inputCls ?>">
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div class="flex flex-col gap-1.5">
              <label for="language" class="text-[13px] font-semibold text-slate-700"><?= h($S['f_language']) ?></label>
              <select id="language" name="language" class="<?= $inputCls ?>"><?php foreach (WcHub::LANGS as $code => $native): ?><option value="<?= h($code) ?>" <?= $code===$lang?'selected':'' ?>><?= h($native) ?></option><?php endforeach; ?></select>
            </div>
            <div class="flex flex-col gap-1.5">
              <label for="tz" class="text-[13px] font-semibold text-slate-700"><?= h($S['f_timezone']) ?></label>
              <select id="tz" name="tz" class="<?= $inputCls ?>"><?php foreach ($tzList as $z => $labelz): ?><option value="<?= h($z) ?>" <?= $z===$tzGuess?'selected':'' ?>><?= h($labelz) ?></option><?php endforeach; ?></select>
            </div>
          </div>
          <p class="text-xs text-slate-400"><?= h($S['tz_detected']) ?></p>
          <?php if ($turnstileSite): ?><div class="cf-turnstile" data-sitekey="<?= h($turnstileSite) ?>" data-size="flexible"></div><?php endif; ?>
          <button id="btnGet" type="submit" class="btn w-full rounded-xl py-3.5 text-base font-semibold text-white bg-blue-600 hover:bg-blue-700"><?= h($T['join']) ?></button>
        </form>
      </section>

      <section id="step-otp" hidden class="bg-white rounded-2xl p-6 sm:p-7 border border-slate-200/70 diffuse">
        <div id="err2" class="hidden mb-3 rounded-xl bg-rose-50 text-rose-700 border border-rose-200 px-3.5 py-2.5 text-sm"></div>
        <h2 class="text-lg font-bold tracking-tight"><?= h($S['otp_title']) ?></h2>
        <p class="text-slate-500 text-sm mt-1 mb-4"><?= h($S['otp_sub']) ?> <b id="maskTo" class="text-slate-700"></b></p>
        <div id="otpGrid" dir="ltr" class="flex gap-2 justify-between">
          <?php for($i=0;$i<6;$i++): ?><input inputmode="numeric" maxlength="1" class="otp-in rounded-xl border border-slate-200 text-2xl font-bold py-3 focus:border-blue-600 focus:ring-4 focus:ring-blue-600/10 outline-none"><?php endfor; ?>
        </div>
        <button id="btnVerify" type="button" class="btn mt-4 w-full rounded-xl py-3.5 text-base font-semibold text-white bg-blue-600 hover:bg-blue-700"><?= h($S['verify']) ?></button>
        <div class="flex justify-between mt-3 text-sm">
          <a id="btnResend" class="text-blue-700 font-semibold cursor-pointer"><?= h($S['resend']) ?></a>
          <a id="btnChange" class="text-slate-500 font-semibold cursor-pointer"><?= h($S['change']) ?></a>
        </div>
      </section>

      <section id="step-done" hidden class="bg-white rounded-2xl p-7 text-center border border-slate-200/70 diffuse">
        <span class="mx-auto w-12 h-12 grid place-items-center rounded-full bg-emerald-50 text-emerald-600 mb-3"><?= fa('fa-solid fa-check text-2xl') ?></span>
        <h2 class="text-xl font-bold tracking-tight mb-1.5"><?= h($S['success_title']) ?></h2>
        <p class="text-slate-500 mb-5"><?= h($S['success_sub']) ?></p>
        <div class="space-y-2.5">
          <a href="/predictions" class="btn block w-full rounded-xl py-3.5 font-semibold text-white bg-blue-600 hover:bg-blue-700"><?= h($S['go_predict']) ?></a>
          <a id="btnInvite" class="btn block w-full rounded-xl py-3.5 font-semibold text-slate-700 bg-slate-100 hover:bg-slate-200 cursor-pointer"><?= h($S['invite']) ?></a>
        </div>
      </section>
    </div>
  </section>

  <!-- HOW IT WORKS: numbered, divided, no card grid -->
  <section class="max-w-5xl mx-auto px-5 py-8">
    <div class="rounded-2xl bg-white border border-slate-200/70">
      <h2 class="px-6 sm:px-8 pt-6 text-sm font-semibold uppercase tracking-wide text-slate-400"><?= h($T['how']) ?></h2>
      <div class="grid sm:grid-cols-3 divide-y sm:divide-y-0 sm:divide-x divide-slate-100">
        <?php $steps=[['fa-brands fa-whatsapp',$T['how1'],$T['how1d']],['fa-light fa-clock',$T['how2'],$T['how2d']],['fa-light fa-chart-line',$T['how3'],$T['how3d']]];
        foreach ($steps as $i=>$st): ?>
          <div class="px-6 sm:px-8 py-6">
            <div class="flex items-center gap-2.5 text-blue-600">
              <?= fa($st[0].' text-[19px]') ?>
              <span class="text-xs font-bold text-slate-300"><?= str_pad((string)($i+1),2,'0',STR_PAD_LEFT) ?></span>
            </div>
            <div class="mt-3 font-semibold text-slate-900"><?= h($st[1]) ?></div>
            <p class="mt-1 text-sm text-slate-500 leading-relaxed"><?= h($st[2]) ?></p>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <!-- PREDICTION LEAGUE + prize pool (restrained, typographic, no medals) -->
  <section class="max-w-5xl mx-auto px-5 py-8">
    <div class="rounded-2xl bg-white border border-slate-200/70 p-6 sm:p-8 grid lg:grid-cols-[1.2fr_1fr] gap-8 items-center">
      <div>
        <div class="flex items-center gap-2 text-slate-900">
          <?= fa('fa-light fa-trophy text-blue-600 text-[19px]') ?>
          <h2 class="text-xl font-bold tracking-tight"><?= h($T['league']) ?></h2>
        </div>
        <p class="mt-3 text-slate-600 leading-relaxed max-w-[60ch]"><?= h($T['leagued']) ?></p>
        <p class="mt-4 text-xs text-slate-400 leading-relaxed"><?= h($T['disc']) ?> <a href="#rules" class="text-blue-700 font-semibold"><?= h($T['rules_link']) ?></a></p>
      </div>
      <div class="rounded-xl border border-slate-200/70 bg-slate-50/60">
        <div class="px-5 pt-4 text-xs font-semibold uppercase tracking-wide text-slate-400"><?= h($T['pool']) ?></div>
        <dl class="divide-y divide-slate-100">
          <?php foreach ([[$T['first'],'$10,000'],[$T['second'],'$5,000'],[$T['third'],'$1,000']] as $row): ?>
            <div class="flex items-baseline justify-between px-5 py-3">
              <dt class="text-sm text-slate-500"><?= h($row[0]) ?></dt>
              <dd class="text-xl font-bold tracking-tight text-slate-900"><?= h($row[1]) ?></dd>
            </div>
          <?php endforeach; ?>
        </dl>
      </div>
    </div>
  </section>

  <!-- RULES -->
  <section id="rules" class="max-w-3xl mx-auto px-5 py-10">
    <h2 class="text-base font-bold tracking-tight text-slate-900 mb-4"><?= h($T['rules_h']) ?></h2>
    <ul class="space-y-2.5">
      <?php foreach ($rules as $r): ?>
        <li class="flex gap-2.5 text-sm text-slate-600 leading-relaxed"><?= fa('fa-solid fa-check text-slate-300 text-[12px] mt-1 shrink-0') ?><?= h($r) ?></li>
      <?php endforeach; ?>
    </ul>
    <p class="mt-5 text-xs text-slate-400 leading-relaxed"><?= h($T['fifa']) ?></p>
  </section>
</main>

<footer class="border-t border-slate-200/70 bg-white">
  <div class="max-w-6xl mx-auto px-5 py-9 text-sm text-slate-500">
    <img src="/assets/images/logo.svg" alt="Cardify" class="h-6 w-auto mb-3">
    <p class="max-w-[60ch]"><?= h($T['about']) ?></p>
    <p class="max-w-[60ch] mt-2"><?= h($T['privacy']) ?></p>
    <div class="mt-5 flex flex-wrap gap-x-5 gap-y-2">
      <a href="https://cardify.om" class="hover:text-slate-900">cardify.om</a>
      <a href="#rules" class="hover:text-slate-900"><?= h($T['rules_h']) ?></a>
      <a href="/wc-leaderboard" class="hover:text-slate-900"><?= h($lang==='ar'?'المتصدرون':'Leaderboard') ?></a>
    </div>
    <p class="mt-5 text-xs text-slate-400">© <?= date('Y') ?> Cardify · <?= h($S['brand']) ?></p>
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
