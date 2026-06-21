<?php
/**
 * wc.cardify.om/u?t=<token> - one-click unsubscribe from the World Cup digest.
 */
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/WcHub.php';

$token = preg_replace('/[^a-f0-9]/', '', (string)($_GET['t'] ?? ''));
$lang  = WcHub::lang($_GET['lang'] ?? 'en');
$done  = false;
if ($token !== '') {
    try {
        $db = Database::getInstance();
        $u = $db->fetchOne("SELECT id, language FROM wc_users WHERE unsub_token = :t LIMIT 1", ['t' => $token]);
        if ($u) {
            $db->update('wc_users', ['status' => 'unsubscribed'], 'id = :id', ['id' => $u['id']]);
            $lang = WcHub::lang($u['language'] ?? $lang);
            $done = true;
        }
    } catch (Throwable $e) { error_log('wc-unsub: ' . $e->getMessage()); }
}
$dir = WcHub::isRtl($lang) ? 'rtl' : 'ltr';
$msg = [
    'en' => ['You are unsubscribed', "You will no longer receive World Cup messages. Changed your mind? Sign up again anytime.", 'Back to wc.cardify.om'],
    'ar' => ['تم إلغاء الاشتراك', 'لن تتلقى رسائل كأس العالم بعد الآن. غيّرت رأيك؟ يمكنك التسجيل مجددًا في أي وقت.', 'العودة إلى wc.cardify.om'],
    'hi' => ['सदस्यता रद्द हो गई', 'अब आपको वर्ल्ड कप संदेश नहीं मिलेंगे। दोबारा जुड़ना चाहें तो कभी भी साइन अप करें।', 'wc.cardify.om पर लौटें'],
    'bn' => ['আনসাবস্ক্রাইব হয়েছে', 'আপনি আর বিশ্বকাপ বার্তা পাবেন না। আবার যোগ দিতে চাইলে যেকোনো সময় সাইন আপ করুন।', 'wc.cardify.om এ ফিরুন'],
    'ur' => ['سبسکرپشن منسوخ ہو گئی', 'اب آپ کو ورلڈ کپ پیغامات نہیں ملیں گے۔ دوبارہ شامل ہونا چاہیں تو کسی بھی وقت سائن اپ کریں۔', 'wc.cardify.om پر واپس جائیں'],
][$lang] ?? null;
$t = $done ? $msg[0] : 'Link not found';
$b = $done ? $msg[1] : 'This unsubscribe link is invalid or already used.';
function uh($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html><html lang="<?= uh($lang) ?>" dir="<?= uh($dir) ?>"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= uh($t) ?> · Cardify</title>
<link rel="icon" href="/assets/images/cardify-icon-192.png">
<link rel="stylesheet" href="https://fonts.bhd.om/css2?family=IBM+Plex+Sans+Arabic:wght@400;600;700&display=swap">
<script src="https://cdn.tailwindcss.com"></script>
<style>body{font-family:'IBM Plex Sans Arabic',system-ui,sans-serif}</style>
</head>
<body class="min-h-[100dvh] grid place-items-center bg-[linear-gradient(160deg,#0a7d3c,#04331b)] p-6">
  <div class="max-w-sm w-full bg-white rounded-3xl p-7 text-center shadow-2xl">
    <img src="/assets/images/logo.svg" alt="Cardify" class="h-7 w-auto mx-auto mb-4">
    <div class="text-4xl mb-2"><?= $done ? '👋' : '⚠️' ?></div>
    <h1 class="text-xl font-bold mb-2 text-slate-900"><?= uh($t) ?></h1>
    <p class="text-slate-500 text-sm mb-5"><?= uh($b) ?></p>
    <a href="https://wc.cardify.om/" class="inline-block rounded-2xl px-5 py-3 font-bold text-white bg-cyan-600"><?= uh($done ? $msg[2] : 'Go to wc.cardify.om') ?></a>
  </div>
</body></html>
