<?php
/**
 * wc.cardify.om - Cardify World Cup 2026 hub (signup + OTP).
 * Dark premium "trophy" theme. Powered by Cardify. Every signup = a Cardify lead.
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
function fa($cls){ return '<i class="'.$cls.'" aria-hidden="true"></i>'; }
$T = [
  'signin' => $rtl ? 'تسجيل الدخول' : 'Sign in',
  'join'   => $rtl ? 'انضم مجانًا' : 'Join free',
  'trust1' => $rtl ? 'مجاني' : 'Free',
  'trust2' => $rtl ? 'بدون إزعاج' : 'No spam',
  'trust3' => $rtl ? 'إلغاء في أي وقت' : 'Unsubscribe anytime',
  'micro'  => $rtl ? 'نرسل رمز تأكيد على واتساب. نستخدم رقمك فقط لتحديثات كأس العالم.' : "We send a WhatsApp code to confirm. Your number is used only for World Cup updates.",
  'how'    => $rtl ? 'كيف يعمل' : 'How it works',
  'how1'   => $rtl ? 'سجّل برقم واتساب' : 'Sign up with WhatsApp',
  'how1d'  => $rtl ? 'تأكيد سريع برمز لمرة واحدة.' : 'A quick one-time code confirms it is you.',
  'how2'   => $rtl ? 'مباريات يوميًا الساعة 10' : 'Daily matches at 10am',
  'how2d'  => $rtl ? 'المواعيد والنتائج بلغتك وتوقيتك.' : 'Fixtures and results in your language and timezone.',
  'how3'   => $rtl ? 'توقّع وتصدّر' : 'Predict and climb',
  'how3d'  => $rtl ? 'النقاط حسب توقعاتك، تصدّر اللوحة.' : 'Earn points for correct calls, rise up the table.',
  'league' => $rtl ? 'دوري التوقعات' : 'The prediction league',
  'leagued'=> $rtl ? 'توقّع النتائج، اجمع النقاط (نقطة لكل نتيجة صحيحة، +2 للنتيجة الدقيقة)، وتصدّر. أصحاب أعلى النقاط في النهاية يفوزون.' : 'Call the results, earn points (1 per correct result, +2 for the exact score), and climb the table. The top of the final standings takes the prize pool.',
  'pool'   => $rtl ? 'مجموع الجوائز' : 'Prize pool',
  'first'  => $rtl ? 'الأول' : '1st', 'second' => $rtl ? 'الثاني' : '2nd', 'third'  => $rtl ? 'الثالث' : '3rd',
  'rules_link' => $rtl ? 'القواعد والأهلية' : 'Rules and eligibility',
  'disc'   => $rtl ? 'للمشاركين 18+. تُدفع الجوائز بتحويل بنكي بعد التحقق من الهوية. تطبق الشروط.' : 'Open to entrants 18 and over. Prizes paid by bank transfer after identity verification. Terms apply.',
  'rules_h'=> $rtl ? 'القواعد' : 'Contest rules',
  'fifa'   => $rtl ? 'هذا الموقع غير تابع لفيفا ولا معتمد منها. "كأس العالم" علامة تجارية لمالكها.' : 'Not affiliated with, or endorsed by, FIFA. "FIFA World Cup" is a trademark of its owner.',
  'about'  => $rtl ? 'Cardify منصّة بطاقات أعمال رقمية مقرّها مسقط، عُمان.' : 'Cardify is a digital business-card platform based in Muscat, Oman.',
  'privacy'=> $rtl ? 'نستخدم رقمك فقط لإرسال تحديثات كأس العالم التي طلبتها. لا نبيع بياناتك.' : 'Your number is used only for the World Cup updates you asked for. We never sell your data.',
];
$rules = $rtl ? [
  'الدخول مجاني تمامًا، بدون أي رسوم.','تُغلق التوقعات عند بداية كل مباراة.',
  'نقطة واحدة للنتيجة الصحيحة، و+2 للنتيجة الدقيقة.','الترتيب حسب مجموع النقاط في نهاية البطولة.',
  'أفضل 3 يفوزون بـ 10,000 / 5,000 / 1,000 دولار، تُدفع بتحويل بنكي بعد التحقق.','عند التعادل، الأفضلية بعدد النتائج الدقيقة ثم بأسبقية التسجيل.',
] : [
  'Free to enter. No purchase, no fees.','Predictions lock at each match kickoff.',
  '1 point for the correct result, +2 for the exact score.','Ranking is by total points at the end of the tournament.',
  'Top 3 receive $10,000 / $5,000 / $1,000, paid by bank transfer after identity verification.','Ties are broken by exact-score count, then earliest signup.',
];
$inputCls = 'w-full px-3.5 py-3 rounded-xl border border-white/12 bg-white/[0.04] text-white text-base outline-none placeholder:text-slate-500 focus:border-gold/60 focus:ring-4 focus:ring-gold/10 transition';
$famH = $rtl ? "'Cairo','IBM Plex Sans Arabic',sans-serif" : "'Outfit','IBM Plex Sans Arabic',sans-serif";
$famB = $rtl ? "'IBM Plex Sans Arabic','Cairo',sans-serif" : "'Outfit','IBM Plex Sans Arabic',sans-serif";
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
<meta property="og:url" content="https://wc.cardify.om/"><meta property="og:type" content="website">
<meta name="twitter:card" content="summary_large_image"><meta name="twitter:image" content="https://wc.cardify.om/assets/wc/og.jpg?v=2">
<meta name="theme-color" content="#0a0a0f">
<link rel="icon" href="/favicon.svg" type="image/svg+xml"><link rel="alternate icon" href="/favicon.ico">
<link rel="preconnect" href="https://fonts.bhd.om" crossorigin>
<link rel="stylesheet" href="https://fonts.bhd.om/css2?family=Outfit:wght@400;500;600;700;800&family=Cairo:wght@700;900&family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="https://design.bhd.om/fa/v7.2.0/css/fontawesome.min.css">
<link rel="stylesheet" href="https://design.bhd.om/fa/v7.2.0/css/light.min.css">
<link rel="stylesheet" href="https://design.bhd.om/fa/v7.2.0/css/solid.min.css">
<link rel="stylesheet" href="https://design.bhd.om/fa/v7.2.0/css/brands.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/intl-tel-input@25.10.1/build/css/intlTelInput.css">
<script src="https://cdn.tailwindcss.com"></script>
<script>tailwind.config={theme:{extend:{colors:{gold:{DEFAULT:'#F2C14E',deep:'#D4950D',soft:'#FFE066'},ink:'#0a0a0f'}}}}</script>
<?php if ($turnstileSite): ?><script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script><?php endif; ?>
<style>
  :root{ --ease:cubic-bezier(0.23,1,0.32,1); }
  body{ font-family:<?= $famB ?>; background:#0a0a0f; color:#e2e8f0; }
  .display{ font-family:<?= $famH ?>; }
  .orb{ position:fixed; border-radius:9999px; filter:blur(60px); opacity:.5; pointer-events:none; z-index:0; animation:drift 22s var(--ease) infinite alternate; }
  @keyframes drift{ to{ transform:translate3d(28px,-22px,0) scale(1.08); } }
  .gold-btn{ background:linear-gradient(135deg,#FFE066,#F2C14E 45%,#D4950D); color:#1a1205; transition:transform .16s var(--ease), filter .16s var(--ease); }
  .gold-btn:hover{ filter:brightness(1.05); } .gold-btn:active{ transform:scale(.97); }
  .btn{ transition:transform .16s var(--ease), background-color .16s var(--ease), border-color .16s; } .btn:active{ transform:scale(.97); }
  .glass{ background:rgba(255,255,255,.04); border:1px solid rgba(255,255,255,.10); box-shadow:inset 0 1px 0 rgba(255,255,255,.06), 0 30px 60px -30px rgba(0,0,0,.7); }
  .rise{ opacity:0; transform:translateY(12px); animation:rise .7s var(--ease) forwards; } @keyframes rise{ to{opacity:1;transform:none;} }
  .iti{width:100%}.iti__selected-dial-code{font-weight:600}
  .iti__selected-country{background:transparent}.iti__dropdown-content{background:#14141c;color:#e2e8f0;border:1px solid rgba(255,255,255,.1)}
  .iti__country:hover,.iti__country.iti__highlight{background:rgba(255,255,255,.06)} .iti--separate-dial-code .iti__selected-flag{background:transparent}
  input[type=tel]{color:#fff}
  [hidden]{display:none!important}
  @media (prefers-reduced-motion:reduce){ .rise{animation:none;opacity:1;transform:none} .orb{animation:none} .btn,.gold-btn{transition:none} }
</style>
</head>
<body class="relative min-h-[100dvh] overflow-x-hidden">
<!-- aurora orbs -->
<div class="orb" style="width:520px;height:520px;top:-160px;<?= $rtl?'left':'right' ?>:-120px;background:radial-gradient(circle,rgba(242,193,78,.30),transparent 70%);animation-delay:-3s"></div>
<div class="orb" style="width:460px;height:460px;bottom:-180px;<?= $rtl?'right':'left' ?>:-140px;background:radial-gradient(circle,rgba(37,99,235,.22),transparent 70%);animation-delay:-9s"></div>
<div class="orb" style="width:360px;height:360px;top:40%;<?= $rtl?'right':'left' ?>:45%;background:radial-gradient(circle,rgba(16,185,129,.14),transparent 70%);animation-delay:-14s"></div>

<header class="relative z-20 border-b border-white/8">
  <div class="max-w-6xl mx-auto px-5 h-16 flex items-center justify-between gap-3">
    <a href="https://cardify.om" class="shrink-0"><img src="/assets/images/logo-light.svg" alt="Cardify" class="h-7 w-auto"></a>
    <div class="flex items-center gap-1.5">
      <nav class="hidden sm:flex gap-0.5">
        <?php foreach (WcHub::LANGS as $code => $native): ?>
          <a href="?lang=<?= h($code) ?>" class="text-[13px] px-2.5 py-1.5 rounded-lg <?= $code===$lang?'bg-white/10 text-white font-semibold':'text-slate-400 hover:text-white' ?>"><?= h($native) ?></a>
        <?php endforeach; ?>
      </nav>
      <a href="/predictions" class="text-sm font-semibold text-gold px-3 py-2 rounded-lg hover:bg-white/5"><?= h($T['signin']) ?></a>
    </div>
  </div>
  <nav class="sm:hidden flex gap-1 px-4 pb-2 overflow-x-auto">
    <?php foreach (WcHub::LANGS as $code => $native): ?>
      <a href="?lang=<?= h($code) ?>" class="text-[13px] px-2.5 py-1 rounded-lg whitespace-nowrap <?= $code===$lang?'bg-white/10 font-semibold text-white':'text-slate-400' ?>"><?= h($native) ?></a>
    <?php endforeach; ?>
  </nav>
</header>

<main class="relative z-10">
  <section class="relative max-w-6xl mx-auto px-5 pt-14 pb-10 grid lg:grid-cols-[1.05fr_0.95fr] gap-12 items-center">
    <div aria-hidden="true" class="display pointer-events-none select-none absolute top-2 <?= $rtl?'left':'right' ?>-2 font-extrabold leading-none text-white/[0.035] text-[clamp(90px,16vw,200px)]">2026</div>
    <div class="relative">
      <span class="rise inline-flex items-center gap-2 rounded-full bg-gold/10 border border-gold/25 text-gold ps-2.5 pe-3.5 py-1.5 text-[13px] font-semibold" style="animation-delay:.02s">
        <?= fa('fa-solid fa-futbol text-[15px]') ?><?= h($S['kicker']) ?>
      </span>
      <div class="rise mt-3 text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-500" style="animation-delay:.04s"><?= $rtl?'أمريكا · كندا · المكسيك':'United States · Canada · Mexico' ?></div>
      <h1 class="rise display mt-4 font-extrabold tracking-tight leading-[1.02] text-white text-[clamp(36px,6.2vw,62px)]" style="animation-delay:.06s"><?= h($S['hero_title']) ?></h1>
      <p class="rise mt-5 text-slate-300 text-lg leading-relaxed max-w-[58ch]" style="animation-delay:.1s"><?= h($S['hero_sub']) ?></p>
      <div class="rise mt-6 flex flex-wrap gap-x-5 gap-y-2 text-sm text-slate-300" style="animation-delay:.14s">
        <?php foreach ([$T['trust1'],$T['trust2'],$T['trust3']] as $chip): ?>
          <span class="inline-flex items-center gap-1.5"><?= fa('fa-solid fa-circle-check text-gold text-[15px]') ?><?= h($chip) ?></span>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="rise w-full max-w-md lg:justify-self-end mx-auto" style="animation-delay:.1s">
      <section id="step-form" class="glass rounded-2xl p-6 sm:p-7">
        <h2 class="display font-extrabold text-xl text-white"><?= h($T['join']) ?></h2>
        <p class="text-sm text-slate-400 mt-1 mb-5"><?= h($T['micro']) ?></p>
        <div id="err1" class="hidden mb-3 rounded-xl bg-rose-500/15 text-rose-300 border border-rose-500/25 px-3.5 py-2.5 text-sm"></div>
        <form id="signupForm" autocomplete="on" class="space-y-3.5">
          <div class="flex flex-col gap-1.5"><label for="name" class="text-[13px] font-semibold text-slate-300"><?= h($S['f_name']) ?></label><input type="text" id="name" name="name" maxlength="120" required class="<?= $inputCls ?>"></div>
          <div class="flex flex-col gap-1.5"><label for="phone" class="text-[13px] font-semibold text-slate-300"><?= h($S['f_phone']) ?></label><input type="tel" id="phone" name="phone" autocomplete="tel" required class="<?= $inputCls ?>"></div>
          <div class="grid grid-cols-2 gap-3">
            <div class="flex flex-col gap-1.5"><label for="language" class="text-[13px] font-semibold text-slate-300"><?= h($S['f_language']) ?></label><select id="language" name="language" class="<?= $inputCls ?>"><?php foreach (WcHub::LANGS as $code => $native): ?><option class="bg-ink" value="<?= h($code) ?>" <?= $code===$lang?'selected':'' ?>><?= h($native) ?></option><?php endforeach; ?></select></div>
            <div class="flex flex-col gap-1.5"><label for="tz" class="text-[13px] font-semibold text-slate-300"><?= h($S['f_timezone']) ?></label><select id="tz" name="tz" class="<?= $inputCls ?>"><?php foreach ($tzList as $z => $labelz): ?><option class="bg-ink" value="<?= h($z) ?>" <?= $z===$tzGuess?'selected':'' ?>><?= h($labelz) ?></option><?php endforeach; ?></select></div>
          </div>
          <p class="text-xs text-slate-500"><?= h($S['tz_detected']) ?></p>
          <?php if ($turnstileSite): ?><div class="cf-turnstile" data-sitekey="<?= h($turnstileSite) ?>" data-size="flexible"></div><?php endif; ?>
          <button id="btnGet" type="submit" class="gold-btn w-full rounded-xl py-3.5 text-base font-bold"><?= h($T['join']) ?></button>
        </form>
      </section>

      <section id="step-otp" hidden class="glass rounded-2xl p-6 sm:p-7">
        <div id="err2" class="hidden mb-3 rounded-xl bg-rose-500/15 text-rose-300 border border-rose-500/25 px-3.5 py-2.5 text-sm"></div>
        <h2 class="display text-xl font-extrabold text-white"><?= h($S['otp_title']) ?></h2>
        <p class="text-slate-400 text-sm mt-1 mb-4"><?= h($S['otp_sub']) ?> <b id="maskTo" class="text-gold"></b></p>
        <div id="otpGrid" dir="ltr" class="flex gap-2 justify-between">
          <?php for($i=0;$i<6;$i++): ?><input inputmode="numeric" maxlength="1" class="w-full text-center rounded-xl border border-white/12 bg-white/[0.04] text-2xl font-bold text-white py-3 focus:border-gold/60 focus:ring-4 focus:ring-gold/10 outline-none"><?php endfor; ?>
        </div>
        <button id="btnVerify" type="button" class="gold-btn mt-4 w-full rounded-xl py-3.5 text-base font-bold"><?= h($S['verify']) ?></button>
        <div class="flex justify-between mt-3 text-sm"><a id="btnResend" class="text-gold font-semibold cursor-pointer"><?= h($S['resend']) ?></a><a id="btnChange" class="text-slate-400 font-semibold cursor-pointer"><?= h($S['change']) ?></a></div>
      </section>

      <section id="step-done" hidden class="glass rounded-2xl p-7 text-center">
        <span class="mx-auto w-12 h-12 grid place-items-center rounded-full bg-gold/15 text-gold mb-3"><?= fa('fa-solid fa-check text-2xl') ?></span>
        <h2 class="display text-xl font-extrabold text-white mb-1.5"><?= h($S['success_title']) ?></h2>
        <p class="text-slate-400 mb-5"><?= h($S['success_sub']) ?></p>
        <div class="space-y-2.5">
          <a href="/predictions" class="gold-btn block w-full rounded-xl py-3.5 font-bold"><?= h($S['go_predict']) ?></a>
          <a id="btnInvite" class="btn block w-full rounded-xl py-3.5 font-semibold text-white bg-white/8 hover:bg-white/12 border border-white/10 cursor-pointer"><?= h($S['invite']) ?></a>
        </div>
      </section>
    </div>
  </section>

  <!-- HOW IT WORKS -->
  <section class="max-w-5xl mx-auto px-5 py-8">
    <div class="glass rounded-2xl">
      <h2 class="px-6 sm:px-8 pt-6 text-xs font-bold uppercase tracking-[0.15em] text-slate-500"><?= h($T['how']) ?></h2>
      <div class="grid sm:grid-cols-3 divide-y sm:divide-y-0 sm:divide-x divide-white/8">
        <?php $steps=[['fa-brands fa-whatsapp',$T['how1'],$T['how1d']],['fa-light fa-clock',$T['how2'],$T['how2d']],['fa-light fa-chart-line',$T['how3'],$T['how3d']]];
        foreach ($steps as $i=>$st): ?>
          <div class="px-6 sm:px-8 py-6">
            <div class="flex items-center gap-2.5 text-gold"><?= fa($st[0].' text-[19px]') ?><span class="display text-sm font-bold text-slate-600"><?= str_pad((string)($i+1),2,'0',STR_PAD_LEFT) ?></span></div>
            <div class="mt-3 font-semibold text-white"><?= h($st[1]) ?></div>
            <p class="mt-1 text-sm text-slate-400 leading-relaxed"><?= h($st[2]) ?></p>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <!-- LEAGUE + PRIZE POOL -->
  <section class="max-w-5xl mx-auto px-5 py-8">
    <div class="glass rounded-2xl p-6 sm:p-8 grid lg:grid-cols-[1.2fr_1fr] gap-8 items-center">
      <div>
        <div class="flex items-center gap-2 text-white"><?= fa('fa-light fa-trophy text-gold text-[19px]') ?><h2 class="display text-xl font-extrabold tracking-tight"><?= h($T['league']) ?></h2></div>
        <p class="mt-3 text-slate-300 leading-relaxed max-w-[60ch]"><?= h($T['leagued']) ?></p>
        <p class="mt-4 text-xs text-slate-500 leading-relaxed"><?= h($T['disc']) ?> <a href="#rules" class="text-gold font-semibold"><?= h($T['rules_link']) ?></a></p>
      </div>
      <div class="rounded-xl border border-gold/20 bg-gold/[0.04]">
        <div class="px-5 pt-4 text-xs font-bold uppercase tracking-[0.15em] text-gold/80"><?= h($T['pool']) ?></div>
        <dl class="divide-y divide-white/8">
          <?php foreach ([[$T['first'],'$10,000'],[$T['second'],'$5,000'],[$T['third'],'$1,000']] as $row): ?>
            <div class="flex items-baseline justify-between px-5 py-3"><dt class="text-sm text-slate-400"><?= h($row[0]) ?></dt><dd class="display text-2xl font-extrabold tracking-tight text-gold-soft"><?= h($row[1]) ?></dd></div>
          <?php endforeach; ?>
        </dl>
      </div>
    </div>
  </section>

  <!-- RULES -->
  <section id="rules" class="max-w-3xl mx-auto px-5 py-10">
    <h2 class="display text-base font-extrabold tracking-tight text-white mb-4"><?= h($T['rules_h']) ?></h2>
    <ul class="space-y-2.5">
      <?php foreach ($rules as $r): ?><li class="flex gap-2.5 text-sm text-slate-400 leading-relaxed"><?= fa('fa-solid fa-check text-gold/60 text-[12px] mt-1 shrink-0') ?><?= h($r) ?></li><?php endforeach; ?>
    </ul>
    <p class="mt-5 text-xs text-slate-600 leading-relaxed"><?= h($T['fifa']) ?></p>
  </section>
</main>

<footer class="relative z-10 border-t border-white/8">
  <div class="max-w-6xl mx-auto px-5 py-9 text-sm text-slate-400">
    <img src="/assets/images/logo-light.svg" alt="Cardify" class="h-6 w-auto mb-3">
    <p class="max-w-[60ch]"><?= h($T['about']) ?></p>
    <p class="max-w-[60ch] mt-2"><?= h($T['privacy']) ?></p>
    <div class="mt-5 flex flex-wrap gap-x-5 gap-y-2"><a href="https://cardify.om" class="hover:text-white">cardify.om</a><a href="#rules" class="hover:text-white"><?= h($T['rules_h']) ?></a><a href="/wc-leaderboard" class="hover:text-white"><?= h($lang==='ar'?'المتصدرون':'Leaderboard') ?></a></div>
    <p class="mt-5 text-xs text-slate-600">© <?= date('Y') ?> Cardify · <?= h($S['brand']) ?></p>
  </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/intl-tel-input@25.10.1/build/js/intlTelInputWithUtils.js"></script>
<script>
const STR = <?= json_encode($S, JSON_UNESCAPED_UNICODE) ?>;
const INIT_COUNTRY = <?= json_encode(strtolower($cc ?: 'om')) ?>;
const phoneEl=document.getElementById('phone');
const iti=window.intlTelInput(phoneEl,{initialCountry:INIT_COUNTRY,separateDialCode:true,countryOrder:['om','ae','sa','in','bd','pk','ph','eg','gb','us'],autoPlaceholder:'polite',nationalMode:true});
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
