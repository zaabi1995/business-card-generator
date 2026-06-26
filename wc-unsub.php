<?php
/**
 * wc.cardify.om/u?t=<token> - unsubscribe from the World Cup digest.
 *
 * IMPORTANT: unsubscribe is a STATE CHANGE, so it only happens on POST. A GET
 * shows a one-tap confirmation page. The unsub link travels inside WhatsApp
 * messages, and link-preview crawlers / prefetchers / scanners issue GETs; if
 * GET unsubscribed, they would silently drop real people from the list. The
 * unguessable unsub_token is the capability that authorises the POST, so no
 * separate CSRF token is needed. (Cardify rule: state-changing endpoints are
 * POST-only; GET gets prefetched.)
 */
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/WcHub.php';

$token  = preg_replace('/[^a-f0-9]/', '', (string)($_GET['t'] ?? $_POST['t'] ?? ''));
$lang   = WcHub::lang($_GET['lang'] ?? 'en');
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$state  = 'invalid';          // invalid | confirm | done

if ($token !== '') {
    try {
        $db = Database::getInstance();
        $u = $db->fetchOne("SELECT id, language, status FROM wc_users WHERE unsub_token = :t LIMIT 1", ['t' => $token]);
        if ($u) {
            $lang = WcHub::lang($u['language'] ?? $lang);
            if ($isPost) {
                if (($u['status'] ?? '') !== 'unsubscribed') {
                    $db->update('wc_users', ['status' => 'unsubscribed'], 'id = :id', ['id' => $u['id']]);
                }
                $state = 'done';
            } else {
                // GET: never mutate. Already-unsubscribed reads as done; else confirm.
                $state = (($u['status'] ?? '') === 'unsubscribed') ? 'done' : 'confirm';
            }
        }
    } catch (Throwable $e) { error_log('wc-unsub: ' . $e->getMessage()); }
}

$dir = WcHub::isRtl($lang) ? 'rtl' : 'ltr';
// [title, body, primary-label] per state, per language.
$S = [
    'en' => [
        'confirm' => ['Unsubscribe?', 'Stop receiving World Cup match reminders and results on WhatsApp?', 'Unsubscribe', 'Keep my reminders'],
        'done'    => ['You are unsubscribed', 'You will no longer receive World Cup messages. Changed your mind? Sign up again anytime.', 'Back to wc.cardify.om'],
    ],
    'ar' => [
        'confirm' => ['إلغاء الاشتراك؟', 'هل تريد إيقاف تذكيرات ونتائج مباريات كأس العالم على واتساب؟', 'إلغاء الاشتراك', 'الاحتفاظ بالتذكيرات'],
        'done'    => ['تم إلغاء الاشتراك', 'لن تتلقى رسائل كأس العالم بعد الآن. غيّرت رأيك؟ يمكنك التسجيل مجددًا في أي وقت.', 'العودة إلى wc.cardify.om'],
    ],
    'hi' => [
        'confirm' => ['सदस्यता रद्द करें?', 'WhatsApp पर वर्ल्ड कप मैच रिमाइंडर और नतीजे आना बंद करें?', 'सदस्यता रद्द करें', 'रिमाइंडर रखें'],
        'done'    => ['सदस्यता रद्द हो गई', 'अब आपको वर्ल्ड कप संदेश नहीं मिलेंगे। दोबारा जुड़ना चाहें तो कभी भी साइन अप करें।', 'wc.cardify.om पर लौटें'],
    ],
    'bn' => [
        'confirm' => ['আনসাবস্ক্রাইব করবেন?', 'WhatsApp-এ বিশ্বকাপ ম্যাচ রিমাইন্ডার ও ফলাফল পাওয়া বন্ধ করবেন?', 'আনসাবস্ক্রাইব', 'রিমাইন্ডার রাখুন'],
        'done'    => ['আনসাবস্ক্রাইব হয়েছে', 'আপনি আর বিশ্বকাপ বার্তা পাবেন না। আবার যোগ দিতে চাইলে যেকোনো সময় সাইন আপ করুন।', 'wc.cardify.om এ ফিরুন'],
    ],
    'ur' => [
        'confirm' => ['سبسکرپشن منسوخ کریں؟', 'واٹس ایپ پر ورلڈ کپ میچ کی یاد دہانی اور نتائج آنا بند کریں؟', 'سبسکرپشن منسوخ کریں', 'یاد دہانیاں رکھیں'],
        'done'    => ['سبسکرپشن منسوخ ہو گئی', 'اب آپ کو ورلڈ کپ پیغامات نہیں ملیں گے۔ دوبارہ شامل ہونا چاہیں تو کسی بھی وقت سائن اپ کریں۔', 'wc.cardify.om پر واپس جائیں'],
    ],
];
$L = $S[$lang] ?? $S['en'];
function uh($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
if ($state === 'done')        { [$title, $body, $cta] = $L['done']; $back = $L['done'][2]; }
elseif ($state === 'confirm') { [$title, $body, $cta, $keep] = $L['confirm']; }
else                          { $title = 'Link not found'; $body = 'This unsubscribe link is invalid.'; }
?>
<!DOCTYPE html><html lang="<?= uh($lang) ?>" dir="<?= uh($dir) ?>"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= uh($title) ?> · Cardify</title>
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="alternate icon" href="/favicon.ico">
<link rel="stylesheet" href="https://fonts.bhd.om/css2?family=IBM+Plex+Sans+Arabic:wght@400;600;700&display=swap">
<link rel="stylesheet" href="https://design.bhd.om/fa/v7.2.0/css/fontawesome.min.css">
<link rel="stylesheet" href="https://design.bhd.om/fa/v7.2.0/css/light.min.css">
<link rel="stylesheet" href="https://design.bhd.om/fa/v7.2.0/css/solid.min.css">
<link rel="stylesheet" href="https://design.bhd.om/fa/v7.2.0/css/brands.min.css">
<script src="https://cdn.tailwindcss.com"></script>
<style>body{font-family:'IBM Plex Sans Arabic',system-ui,sans-serif}.btn{transition:transform .16s cubic-bezier(.23,1,.32,1)}.btn:active{transform:scale(.97)}</style>
</head>
<body class="min-h-[100dvh] grid place-items-center bg-slate-900 p-6">
  <div class="max-w-sm w-full bg-white rounded-3xl p-7 text-center shadow-2xl">
    <img src="/assets/images/logo.svg" alt="Cardify" class="h-7 w-auto mx-auto mb-4">
    <div class="text-4xl mb-2">
      <?php if ($state === 'done'): ?><i class="fa-light fa-circle-check text-emerald-500"></i>
      <?php elseif ($state === 'confirm'): ?><i class="fa-light fa-bell-slash text-blue-600"></i>
      <?php else: ?><i class="fa-light fa-triangle-exclamation text-amber-500"></i><?php endif; ?>
    </div>
    <h1 class="text-xl font-bold mb-2 text-slate-900"><?= uh($title) ?></h1>
    <p class="text-slate-500 text-sm mb-5"><?= uh($body) ?></p>
    <?php if ($state === 'confirm'): ?>
      <form method="post" action="/u">
        <input type="hidden" name="t" value="<?= uh($token) ?>">
        <input type="hidden" name="lang" value="<?= uh($lang) ?>">
        <button type="submit" class="btn w-full rounded-2xl px-5 py-3 font-bold text-white bg-red-600 mb-3"><?= uh($cta) ?></button>
      </form>
      <a href="https://wc.cardify.om/" class="text-sm font-semibold text-slate-500"><?= uh($keep) ?></a>
    <?php else: ?>
      <a href="https://wc.cardify.om/" class="btn inline-block rounded-2xl px-5 py-3 font-bold text-white bg-blue-600"><?= uh($state === 'done' ? $back : 'Go to wc.cardify.om') ?></a>
    <?php endif; ?>
  </div>
</body></html>
