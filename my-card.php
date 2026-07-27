<?php
/**
 * /my-card  -  "Get my digital card"
 *
 * Enter your number, prove it with a WhatsApp code, then see every Cardify card
 * on that number and add any of them to Apple or Google Wallet.
 *
 * The OTP is the whole point. Cards are already public at their own URLs, but
 * looking one up BY PHONE NUMBER is a different capability: without the code
 * this page would turn any leaked phone list into names and employers, and let
 * anyone test whether a number belongs to a Cardify user. So the existence of a
 * card is never revealed before the code is verified, and api/my-card-request
 * answers identically either way.
 */
require_once __DIR__ . '/config.php';

$lang = (isset($_GET['lang']) && $_GET['lang'] === 'ar') ? 'ar' : 'en';
$rtl  = $lang === 'ar';
$T = $rtl ? [
  'title' => 'احصل على بطاقتك الرقمية',
  'sub' => 'أدخل رقم هاتفك وسنرسل لك رمزًا عبر واتساب.',
  'phone' => 'رقم الهاتف',
  'send' => 'إرسال الرمز',
  'code' => 'الرمز المكوّن من 6 أرقام',
  'verify' => 'تأكيد',
  'sent' => 'إذا كان لهذا الرقم بطاقة، فسيصلك رمز عبر واتساب الآن.',
  'none' => 'لا توجد بطاقة على هذا الرقم.',
  'bad' => 'الرمز غير صحيح أو منتهي الصلاحية.',
  'rate' => 'محاولات كثيرة. حاول لاحقًا.',
  'badphone' => 'رقم غير صالح.',
  'yours' => 'بطاقاتك',
  'view' => 'عرض البطاقة',
  'apple' => 'أضف إلى Apple Wallet',
  'google' => 'أضف إلى Google Wallet',
  'back' => 'رقم آخر',
] : [
  'title' => 'Get my digital card',
  'sub' => 'Enter your phone number and we will send you a code on WhatsApp.',
  'phone' => 'Phone number',
  'send' => 'Send code',
  'code' => '6-digit code',
  'verify' => 'Verify',
  'sent' => 'If this number has a card, a WhatsApp code is on its way.',
  'none' => 'No card is registered to that number.',
  'bad' => 'That code is wrong or has expired.',
  'rate' => 'Too many attempts. Please try again later.',
  'badphone' => 'That does not look like a valid number.',
  'yours' => 'Your cards',
  'view' => 'View card',
  'apple' => 'Add to Apple Wallet',
  'google' => 'Add to Google Wallet',
  'back' => 'Use another number',
];
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?><!doctype html>
<html lang="<?= e($lang) ?>" dir="<?= $rtl ? 'rtl' : 'ltr' ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex,follow">
<title><?= e($T['title']) ?> · Cardify</title>
<link rel="stylesheet" href="https://fonts.bhd.om/css2?family=Inter:wght@400;500;600;700&display=swap">
<style>
  :root{--ink:#0f172a;--muted:#64748b;--line:#e2e8f0;--accent:#4f46e5;--bg:#f8fafc}
  *{box-sizing:border-box}
  body{margin:0;background:var(--bg);color:var(--ink);
       font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
       display:flex;align-items:center;justify-content:center;min-height:100dvh;padding:24px}
  .card{background:#fff;border:1px solid var(--line);border-radius:20px;padding:28px;
        width:100%;max-width:420px;box-shadow:0 10px 30px rgba(15,23,42,.06)}
  h1{font-size:22px;margin:0 0 6px;font-weight:700;letter-spacing:-.02em}
  p.sub{color:var(--muted);font-size:14px;margin:0 0 20px;line-height:1.5}
  label{display:block;font-size:13px;font-weight:600;margin:0 0 6px}
  input{width:100%;padding:13px 14px;font-size:16px;border:1px solid var(--line);
        border-radius:12px;font-family:inherit;background:#fff;color:var(--ink)}
  input:focus{outline:2px solid var(--accent);outline-offset:1px;border-color:transparent}
  button{width:100%;margin-top:14px;padding:14px;font-size:15px;font-weight:600;
         border:0;border-radius:12px;background:var(--accent);color:#fff;cursor:pointer;font-family:inherit}
  button:disabled{opacity:.55;cursor:default}
  .msg{margin-top:14px;font-size:13.5px;line-height:1.5}
  .msg.err{color:#b91c1c}.msg.ok{color:#047857}
  .hidden{display:none}
  .entry{border:1px solid var(--line);border-radius:14px;padding:16px;margin-top:12px}
  .entry .nm{font-weight:600;font-size:15px}
  .entry .mt{color:var(--muted);font-size:13px;margin-top:2px}
  .entry a{display:block;text-align:center;text-decoration:none;margin-top:10px;
           padding:11px;border-radius:10px;font-size:14px;font-weight:600}
  .a-view{background:#f1f5f9;color:var(--ink)}
  .a-apple{background:#000;color:#fff}
  .a-google{background:#fff;color:var(--ink);border:1px solid var(--line)}
  .link{display:block;text-align:center;margin-top:16px;color:var(--muted);font-size:13px;
        background:none;border:0;cursor:pointer;font-family:inherit;width:100%}
</style>
</head>
<body>
<main class="card">
  <h1><?= e($T['title']) ?></h1>
  <p class="sub" id="sub"><?= e($T['sub']) ?></p>

  <form id="step1">
    <label for="phone"><?= e($T['phone']) ?></label>
    <input id="phone" type="tel" inputmode="tel" autocomplete="tel" placeholder="+968 …" required>
    <button type="submit" id="b1"><?= e($T['send']) ?></button>
  </form>

  <form id="step2" class="hidden">
    <label for="code"><?= e($T['code']) ?></label>
    <input id="code" type="text" inputmode="numeric" autocomplete="one-time-code"
           pattern="[0-9]*" maxlength="6" placeholder="000000" required>
    <button type="submit" id="b2"><?= e($T['verify']) ?></button>
    <button type="button" class="link" id="again"><?= e($T['back']) ?></button>
  </form>

  <div id="msg" class="msg"></div>
  <div id="cards"></div>
</main>
<script>
(function(){
  var T = <?= json_encode($T, JSON_UNESCAPED_UNICODE) ?>;
  var s1=document.getElementById('step1'), s2=document.getElementById('step2');
  var phone=document.getElementById('phone'), code=document.getElementById('code');
  var msg=document.getElementById('msg'), out=document.getElementById('cards');
  var b1=document.getElementById('b1'), b2=document.getElementById('b2');

  function say(text, kind){ msg.textContent=text||''; msg.className='msg'+(kind?' '+kind:''); }
  function post(url, body){
    return fetch(url,{method:'POST',headers:{'Content-Type':'application/json'},
      body:JSON.stringify(body)}).then(function(r){ return r.json(); });
  }

  s1.addEventListener('submit', function(ev){
    ev.preventDefault();
    b1.disabled=true; say('');
    post('/api/my-card-request.php',{phone:phone.value})
      .then(function(j){
        if(!j.ok){ say(j.error==='err_rate'?T.rate:T.badphone,'err'); return; }
        // Deliberately the same message whether or not a card exists.
        say(T.sent,'ok');
        s1.classList.add('hidden'); s2.classList.remove('hidden'); code.focus();
      })
      .catch(function(){ say(T.badphone,'err'); })
      .then(function(){ b1.disabled=false; });
  });

  s2.addEventListener('submit', function(ev){
    ev.preventDefault();
    b2.disabled=true; say('');
    post('/api/my-card-verify.php',{phone:phone.value, code:code.value})
      .then(function(j){
        if(!j.ok){ say(T.bad,'err'); return; }
        if(!j.found){ say(T.none,'err'); return; }
        s2.classList.add('hidden');
        document.getElementById('sub').textContent='';
        say('');
        var h='<h1 style="font-size:17px;margin:4px 0 2px">'+T.yours+'</h1>';
        (j.cards||[j.card]).forEach(function(c){
          h+='<div class="entry">'
           +   '<div class="nm"></div><div class="mt"></div>'
           +   '<a class="a-view" href="'+c.url+'">'+T.view+'</a>'
           +   '<a class="a-apple" href="'+c.wallet_apple+'">'+T.apple+'</a>'
           +   '<a class="a-google" href="'+c.wallet_google+'">'+T.google+'</a>'
           + '</div>';
        });
        out.innerHTML=h;
        // Names are written as TEXT, never interpolated into the HTML above, so
        // a card whose name contains markup cannot inject anything.
        var entries=out.querySelectorAll('.entry'), list=(j.cards||[j.card]);
        for(var i=0;i<entries.length;i++){
          entries[i].querySelector('.nm').textContent=list[i].name||'';
          entries[i].querySelector('.mt').textContent=
            [list[i].title,list[i].company].filter(Boolean).join(' · ');
        }
      })
      .catch(function(){ say(T.bad,'err'); })
      .then(function(){ b2.disabled=false; });
  });

  document.getElementById('again').addEventListener('click', function(){
    s2.classList.add('hidden'); s1.classList.remove('hidden');
    code.value=''; say(''); out.innerHTML=''; phone.focus();
  });
})();
</script>
</body>
</html>
