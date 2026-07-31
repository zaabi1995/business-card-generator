<?php
declare(strict_types=1);

require_once INCLUDES_DIR . '/SecurityHeaders.php';
SecurityHeaders::send();

header('Cache-Control: public, max-age=300');
header('X-Robots-Tag: noindex, nofollow');

$profiles = [
    'maya' => [
        'name_en' => 'Maya Hassan',
        'name_ar' => 'مايا حسن',
        'title_en' => 'Product Designer',
        'title_ar' => 'مصممة منتجات',
        'company_en' => 'Studio North',
        'company_ar' => 'استوديو نورث',
        'accent' => '#0789a3',
    ],
    'maya-personal' => [
        'name_en' => 'Maya Hassan',
        'name_ar' => 'مايا حسن',
        'title_en' => 'Independent Designer',
        'title_ar' => 'مصممة مستقلة',
        'company_en' => 'Personal',
        'company_ar' => 'شخصي',
        'accent' => '#6d4aff',
    ],
    'maya-collective' => [
        'name_en' => 'Maya Hassan',
        'name_ar' => 'مايا حسن',
        'title_en' => 'Design Partner',
        'title_ar' => 'شريكة تصميم',
        'company_en' => 'Design Collective',
        'company_ar' => 'مجموعة التصميم',
        'accent' => '#6d4aff',
    ],
];

$profile = $profiles[$employeeId ?? ''] ?? null;
if (!$profile) {
    http_response_code(404);
    return;
}

$arabic = ($_GET['lang'] ?? '') === 'ar';
$dir = $arabic ? 'rtl' : 'ltr';
$name = $arabic ? $profile['name_ar'] : $profile['name_en'];
$title = $arabic ? $profile['title_ar'] : $profile['title_en'];
$company = $arabic ? $profile['company_ar'] : $profile['company_en'];
$accent = $profile['accent'];
?>
<!doctype html>
<html lang="<?= $arabic ? 'ar' : 'en' ?>" dir="<?= $dir ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="<?= htmlspecialchars($accent, ENT_QUOTES, 'UTF-8') ?>">
  <title><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?> | Cardify</title>
  <style>
    @font-face{font-family:Tajawal;src:url("https://fonts.bhd.om/Tajawal/Tajawal-Regular.ttf") format("truetype");font-weight:400}
    @font-face{font-family:Tajawal;src:url("https://fonts.bhd.om/Tajawal/Tajawal-Bold.ttf") format("truetype");font-weight:700}
    *{box-sizing:border-box}
    body{margin:0;min-height:100dvh;display:grid;place-items:center;padding:24px;background:linear-gradient(145deg,#f8fbfc,#eaf4f6);color:#101828;font-family:-apple-system,BlinkMacSystemFont,"SF Pro Display",Arial,sans-serif}
    [dir=rtl] body{font-family:Tajawal,-apple-system,BlinkMacSystemFont,Arial,sans-serif}
    main{width:min(100%,520px)}
    .brand{display:flex;align-items:center;gap:10px;margin:0 0 20px;font-size:20px;font-weight:700}
    .mark{display:grid;place-items:center;width:38px;height:30px;border-radius:8px;background:<?= htmlspecialchars($accent, ENT_QUOTES, 'UTF-8') ?>;color:#fff;font-size:18px}
    .card{position:relative;overflow:hidden;aspect-ratio:1.75;border:1px solid rgba(16,24,40,.08);border-radius:24px;background:#fff;box-shadow:0 24px 70px rgba(16,24,40,.16);padding:36px}
    .card:before{content:"";position:absolute;inset:0 auto 0 0;width:10px;background:<?= htmlspecialchars($accent, ENT_QUOTES, 'UTF-8') ?>}
    [dir=rtl] .card:before{inset:0 0 0 auto}
    .company{margin:0;color:#667085;font-size:13px;font-weight:700;letter-spacing:.08em;text-transform:uppercase}
    [dir=rtl] .company{letter-spacing:0;text-transform:none}
    .rule{width:100%;height:3px;margin:24px 0;background:<?= htmlspecialchars($accent, ENT_QUOTES, 'UTF-8') ?>;opacity:.78}
    h1{margin:0;color:<?= htmlspecialchars($accent, ENT_QUOTES, 'UTF-8') ?>;font-size:clamp(26px,7vw,38px);line-height:1.15}
    .title{margin:9px 0 0;color:#667085;font-size:17px}
    .footer{position:absolute;inset:auto 0 0;height:12px;background:<?= htmlspecialchars($accent, ENT_QUOTES, 'UTF-8') ?>}
    @media(max-width:420px){.card{padding:26px;border-radius:20px}.rule{margin:18px 0}}
  </style>
</head>
<body>
  <main>
    <p class="brand"><span class="mark">C</span><span>Cardify</span></p>
    <section class="card">
      <p class="company"><?= htmlspecialchars($company, ENT_QUOTES, 'UTF-8') ?></p>
      <div class="rule"></div>
      <h1><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?></h1>
      <p class="title"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></p>
      <div class="footer"></div>
    </section>
  </main>
</body>
</html>
