<?php
declare(strict_types=1);
/**
 * Mehdi Store link page: https://mehdistore.cardify.om/main
 *
 * The QR printed on Mehdi Store's 2020s paper bag encodes that exact URL.
 * NEVER rename this file or the "mehdistore" folder: the printed bags would 404.
 *
 * Served by includes/TenantPages.php (hooked at the top of index.php).
 *   - Data (phone numbers, links, branches, brands): content.json in this folder.
 *   - Look and copy (this file): the <style> block and the $t strings below.
 *   - Public assets (logo, skyline, icons, favicon, OG image):
 *     assets/tenant-pages/mehdistore/, rebuilt by
 *     scripts/tenant-pages/mehdistore/build_assets.py.
 * Arabic by default, English at /main?lang=en.
 * Change -> commit to main -> /usr/local/bin/deploy-cardify.sh on the VPS.
 */

if (!isset($tenantPage) || !is_array($tenantPage)) {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__, 2) . '/SecurityHeaders.php';

$data = json_decode((string) file_get_contents(__DIR__ . '/content.json'), true, 32, JSON_THROW_ON_ERROR);

$lang   = (($_GET['lang'] ?? '') === 'en') ? 'en' : 'ar';
$other  = $lang === 'ar' ? 'en' : 'ar';
$isAr   = $lang === 'ar';
$origin = $tenantPage['origin'];
$page   = $tenantPage['page'];
$base   = $tenantPage['asset_base'];
$adir   = $tenantPage['asset_dir'];

$e   = static fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$url = static fn($u): string => (is_string($u) && preg_match('~^https://[^\s"<>]+$~', $u)) ? $u : '#';
$f   = static fn(array $row, string $key): string => (string) ($row[$key . '_' . $lang] ?? ($row[$key . '_en'] ?? ''));
$alt = static fn(array $row, string $key): string => (string) ($row[$key . '_' . $other] ?? '');
$ver = static function (string $file) use ($adir): string {
    $m = @filemtime($adir . '/' . $file);
    return $m ? (string) $m : '1';
};
$asset = static fn(string $file): string => $base . $file . '?v=' . $ver($file);
$phone = static function (string $digits): string {
    $d = preg_replace('/\D/', '', $digits);
    if (strlen($d) === 11 && strpos($d, '968') === 0) {
        return '+968 ' . substr($d, 3, 4) . ' ' . substr($d, 7);
    }
    return '+' . $d;
};

$t = [
    'ar' => [
        'title'        => 'مخزن مهدي | Mehdi Store',
        'description'  => 'مخزن مهدي منذ 1948. راسلونا على واتساب، وتابعونا على إنستغرام وإكس، واعثروا على أقرب فرع من فروعنا الـ12 في عُمان.',
        'og_title'     => 'مخزن مهدي | Mehdi Store',
        'og_alt'       => 'شعار مخزن مهدي على الأزرق الملكي مع رسم لمعالم عُمان',
        'switch'       => 'English',
        'switch_label' => 'View this page in English',
        'welcome'      => 'أهلاً بكم في مخزن مهدي',
        'lead'         => 'راسلونا، تابعونا، واعثروا على أقرب فرع.',
        'wa'           => 'راسلنا على واتساب',
        'follow'       => 'تابعونا',
        'jump'         => 'مواقع الفروع',
        'branches_eyebrow' => '12 فرعاً في عُمان',
        'branches'     => 'فروعنا',
        'branches_sub' => 'اضغطوا على الفرع لفتح الاتجاهات في خرائط Google.',
        'directions'   => 'الاتجاهات في خرائط Google',
        'brands_eyebrow' => 'من عائلة مخزن مهدي',
        'brands'       => 'علاماتنا التجارية',
        'brand_branch' => 'الفرع',
        'brand_branches' => 'الفروع',
        'whatsapp'     => 'واتساب',
        'instagram'    => 'إنستغرام',
        'new_tab'      => 'يفتح في نافذة جديدة',
    ],
    'en' => [
        'title'        => 'Mehdi Store | مخزن مهدي',
        'description'  => 'Mehdi Store, Oman, since 1948. Message us on WhatsApp, follow us on Instagram and X, and get directions to our 12 branches.',
        'og_title'     => 'Mehdi Store | مخزن مهدي',
        'og_alt'       => 'Mehdi Store logo on royal blue with an Oman skyline drawing',
        'switch'       => 'عربي',
        'switch_label' => 'عرض الصفحة بالعربية',
        'welcome'      => 'Welcome to Mehdi Store',
        'lead'         => 'Message us, follow us, and find your nearest branch.',
        'wa'           => 'Message us on WhatsApp',
        'follow'       => 'Follow us',
        'jump'         => 'Branch locations',
        'branches_eyebrow' => '12 branches across Oman',
        'branches'     => 'Our branches',
        'branches_sub' => 'Tap a branch for directions in Google Maps.',
        'directions'   => 'Directions in Google Maps',
        'brands_eyebrow' => 'From the Mehdi Store family',
        'brands'       => 'Our brands',
        'brand_branch' => 'Branch',
        'brand_branches' => 'Branches',
        'whatsapp'     => 'WhatsApp',
        'instagram'    => 'Instagram',
        'new_tab'      => 'opens in a new tab',
    ],
][$lang];

$canonical = $origin . '/' . $page . ($isAr ? '' : '?lang=en');
$altHref   = $isAr ? '?lang=en' : '/' . $page;
$ogImage   = $origin . $asset('og.jpg');
$year      = (int) date('Y');
$brand     = $data['brand'];
$wa        = $data['whatsapp'];
$groups    = $data['branch_groups'];
$total     = 0;
foreach ($groups as $g) {
    $total += count($g['branches']);
}

$logoSvg = (string) @file_get_contents($adir . '/logo.svg');
$logoSvg = (string) preg_replace('~<title>.*?</title>~s', '', $logoSvg);
$logoSvg = (string) preg_replace('~\srole="img"\saria-label="[^"]*"~', ' aria-hidden="true" focusable="false"', $logoSvg);

$socialIcon = ['instagram' => 'fa-brands fa-instagram', 'x' => 'fa-brands fa-x-twitter', 'whatsapp' => 'fa-brands fa-whatsapp'];
$arrow = $isAr ? 'fa-arrow-up-left' : 'fa-arrow-up-right';

$jsonLd = [
    '@context'      => 'https://schema.org',
    '@type'         => 'Organization',
    'name'          => $brand['name_en'],
    'alternateName' => $brand['name_ar'],
    'legalName'     => $brand['legal_en'],
    'foundingDate'  => (string) $brand['founded'],
    'url'           => $origin . '/' . $page,
    'logo'          => $origin . $base . 'apple-touch-icon.png',
    'sameAs'        => array_values(array_map(static fn($s) => $s['url'], $data['socials'])),
    'contactPoint'  => [
        '@type'             => 'ContactPoint',
        'telephone'         => '+' . preg_replace('/\D/', '', $wa['number']),
        'contactType'       => 'customer service',
        'availableLanguage' => ['ar', 'en'],
    ],
];

SecurityHeaders::send();
header('Content-Type: text/html; charset=utf-8');
header('Content-Language: ' . $lang);
header('Vary: Accept-Encoding');
$nonce = SecurityHeaders::nonce();
?>
<!doctype html>
<html lang="<?= $lang ?>" dir="<?= $isAr ? 'rtl' : 'ltr' ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= $e($t['title']) ?></title>
<meta name="description" content="<?= $e($t['description']) ?>">
<meta name="robots" content="index, follow">
<meta name="theme-color" content="#293688">
<meta name="color-scheme" content="light">
<meta name="format-detection" content="telephone=no">
<link rel="canonical" href="<?= $e($canonical) ?>">
<link rel="alternate" hreflang="ar" href="<?= $e($origin . '/' . $page) ?>">
<link rel="alternate" hreflang="en" href="<?= $e($origin . '/' . $page . '?lang=en') ?>">
<link rel="alternate" hreflang="x-default" href="<?= $e($origin . '/' . $page) ?>">
<link rel="icon" type="image/svg+xml" href="<?= $e($asset('favicon.svg')) ?>">
<link rel="icon" type="image/png" sizes="32x32" href="<?= $e($asset('favicon-32.png')) ?>">
<link rel="apple-touch-icon" sizes="180x180" href="<?= $e($asset('apple-touch-icon.png')) ?>">
<meta property="og:type" content="website">
<meta property="og:site_name" content="<?= $e($isAr ? $brand['name_ar'] : $brand['name_en']) ?>">
<meta property="og:locale" content="<?= $isAr ? 'ar_OM' : 'en_US' ?>">
<meta property="og:title" content="<?= $e($t['og_title']) ?>">
<meta property="og:description" content="<?= $e($t['description']) ?>">
<meta property="og:url" content="<?= $e($canonical) ?>">
<meta property="og:image" content="<?= $e($ogImage) ?>">
<meta property="og:image:secure_url" content="<?= $e($ogImage) ?>">
<meta property="og:image:type" content="image/jpeg">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:alt" content="<?= $e($t['og_alt']) ?>">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= $e($t['og_title']) ?>">
<meta name="twitter:description" content="<?= $e($t['description']) ?>">
<meta name="twitter:image" content="<?= $e($ogImage) ?>">
<link rel="preconnect" href="https://fonts.bhd.om" crossorigin>
<link rel="stylesheet" href="https://fonts.bhd.om/css2?family=Tajawal:wght@400;500;700;800&amp;display=swap">
<link rel="preload" href="<?= $e($asset('icons-brands.woff2')) ?>" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="<?= $e($asset('icons-light.woff2')) ?>" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="<?= $e($asset('icons-solid.woff2')) ?>" as="font" type="font/woff2" crossorigin>
<script type="application/ld+json" nonce="<?= $e($nonce) ?>"><?= json_encode($jsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>
<style>
/* Icons: FontAwesome 7.2 Pro (design.bhd.om/fa), subset to the glyphs this page uses. */
@font-face{font-family:"MS Icons Brands";font-style:normal;font-weight:400;font-display:block;src:url("<?= $e($asset('icons-brands.woff2')) ?>") format("woff2")}
@font-face{font-family:"MS Icons Solid";font-style:normal;font-weight:900;font-display:block;src:url("<?= $e($asset('icons-solid.woff2')) ?>") format("woff2")}
@font-face{font-family:"MS Icons Light";font-style:normal;font-weight:300;font-display:block;src:url("<?= $e($asset('icons-light.woff2')) ?>") format("woff2")}
.fa-brands,.fa-solid,.fa-light{display:inline-block;font-style:normal;font-variant:normal;line-height:1;text-rendering:auto;-webkit-font-smoothing:antialiased;width:1.25em;text-align:center}
.fa-brands{font-family:"MS Icons Brands";font-weight:400}
.fa-solid{font-family:"MS Icons Solid";font-weight:900}
.fa-light{font-family:"MS Icons Light";font-weight:300}
.fa-whatsapp::before{content:"\f232"}.fa-instagram::before{content:"\f16d"}.fa-x-twitter::before{content:"\e61b"}
.fa-location-dot::before{content:"\f3c5"}.fa-arrow-up-left::before{content:"\e09d"}.fa-arrow-up-right::before{content:"\e09f"}
.fa-chevron-down::before{content:"\f078"}.fa-globe::before{content:"\f0ac"}.fa-shirt::before{content:"\f553"}.fa-scissors::before{content:"\f0c4"}

:root{
  --blue:#293688;--blue-700:#222d77;--blue-900:#181f58;
  --gold:#A37A37;--gold-ink:#83602A;--gold-tint:#F5EEE2;
  --paper:#F7F5F0;--card:#FFFFFF;--ink:#1B2150;--muted:#5B5F7C;--line:#E6E0D4;
  --wa:#1FA855;--radius:18px;
  --ease-out:cubic-bezier(.23,1,.32,1);
  --font:"Tajawal",system-ui,-apple-system,"Segoe UI",Roboto,"Noto Sans Arabic",Arial,sans-serif;
}
*,*::before,*::after{box-sizing:border-box}
html{background:var(--blue);-webkit-text-size-adjust:100%;text-size-adjust:100%}
body{margin:0;background:var(--paper);color:var(--ink);font-family:var(--font);font-size:16px;line-height:1.5;-webkit-font-smoothing:antialiased;overflow-x:hidden}
a{color:inherit;text-decoration:none;-webkit-tap-highlight-color:transparent}
img,svg{display:block;max-width:100%}
h1,h2,h3,p{margin:0}
ul{margin:0;padding:0;list-style:none}
bdi{unicode-bidi:isolate}
.sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
:focus-visible{outline:3px solid #E7C68A;outline-offset:3px;border-radius:12px}
.wrap{width:100%;max-width:980px;margin:0 auto;padding:0 16px}
.skip{position:absolute;inset-inline-start:12px;top:-60px;z-index:10;background:#fff;color:var(--blue);padding:10px 14px;border-radius:10px;font-weight:700}
.skip:focus{top:12px}

/* ---------- Hero ---------- */
.hero{position:relative;color:#fff;background:
  radial-gradient(120% 70% at 50% 0%,#3a4aa6 0%,rgba(58,74,166,0) 60%),
  var(--blue);
  border-radius:0 0 32px 32px;overflow:hidden;padding:max(14px,env(safe-area-inset-top)) 0 34px}
.hero::before{content:"";position:absolute;inset:0;opacity:.07;pointer-events:none;
  background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='44' height='44' viewBox='0 0 44 44'%3E%3Cpath d='M22 2 42 22 22 42 2 22Z' fill='none' stroke='%23fff' stroke-width='1'/%3E%3Cpath d='M22 14 30 22 22 30 14 22Z' fill='none' stroke='%23fff' stroke-width='1'/%3E%3C/svg%3E");
  background-size:44px 44px;-webkit-mask-image:linear-gradient(#000,transparent 85%);mask-image:linear-gradient(#000,transparent 85%)}
.hero-bar{position:relative;display:flex;justify-content:flex-end;max-width:520px;margin:0 auto;padding:0 16px}
.lang{display:inline-flex;align-items:center;gap:6px;min-height:40px;padding:0 14px;border-radius:999px;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.24);font-size:14px;font-weight:700;transition:background .2s var(--ease-out),transform .15s var(--ease-out)}
.lang:active{transform:scale(.96)}
.lang .fa-light{font-size:15px;width:auto}
.hero-inner{position:relative;max-width:520px;margin:0 auto;padding:10px 16px 0;text-align:center}
.logo{display:flex;justify-content:center;margin:14px auto 22px}
.logo svg{width:min(250px,68vw);height:auto;filter:drop-shadow(0 2px 10px rgba(0,0,0,.18))}
.welcome{font-size:clamp(21px,5.6vw,26px);font-weight:800;line-height:1.3}
.lead{margin-top:6px;font-size:16px;color:rgba(255,255,255,.82)}
.actions{margin-top:22px;display:grid;gap:12px}
.btn-wa{display:flex;align-items:center;gap:14px;min-height:66px;padding:10px 12px;border-radius:var(--radius);background:#fff;color:var(--blue);box-shadow:0 10px 26px rgba(10,14,50,.28);text-align:start;transition:transform .15s var(--ease-out),box-shadow .2s var(--ease-out)}
.btn-wa:active{transform:scale(.97)}
.wa-ico{flex:none;display:grid;place-items:center;width:46px;height:46px;border-radius:14px;background:var(--wa);color:#fff;font-size:26px}
.wa-ico .fa-brands{width:auto}
.btn-text{flex:1;min-width:0;display:flex;flex-direction:column;line-height:1.25}
.btn-label{font-size:17px;font-weight:800}
.btn-sub{font-size:14px;font-weight:500;color:var(--muted);margin-top:2px}
.btn-go{flex:none;font-size:18px;color:var(--gold)}
.follow-title{margin-top:6px;font-size:13px;font-weight:700;color:rgba(255,255,255,.72)}
.social-row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.btn-soc{display:flex;align-items:center;gap:10px;min-height:60px;padding:10px 12px;border-radius:var(--radius);background:rgba(255,255,255,.09);border:1px solid rgba(255,255,255,.26);color:#fff;text-align:start;transition:background .2s var(--ease-out),transform .15s var(--ease-out)}
.btn-soc:active{transform:scale(.97)}
.btn-soc .fa-brands{flex:none;font-size:24px}
.btn-soc .btn-label{font-size:15px}
.btn-soc .btn-sub{font-size:12.5px;color:rgba(255,255,255,.78);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.jump{justify-self:center;display:inline-flex;align-items:center;gap:8px;min-height:44px;margin-top:4px;padding:0 16px;font-size:15px;font-weight:700;color:#fff;border-bottom:2px solid rgba(214,178,112,.75)}
.jump .fa-light{color:#E2C386;font-size:14px;width:auto}
@media (hover:hover) and (pointer:fine){
  .lang:hover,.btn-soc:hover{background:rgba(255,255,255,.16)}
  .btn-wa:hover{box-shadow:0 14px 32px rgba(10,14,50,.34)}
  .branch:hover,.brand-row:hover{border-color:#D5C39E;box-shadow:0 8px 22px rgba(27,33,80,.09)}
  .chip:hover{background:var(--gold-tint)}
}

/* ---------- Sections ---------- */
.section{padding:38px 0 8px}
.sec-head{text-align:center;margin-bottom:22px}
.eyebrow{display:inline-flex;align-items:center;gap:10px;font-size:13.5px;font-weight:700;color:var(--gold-ink)}
.eyebrow::before,.eyebrow::after{content:"";width:22px;height:2px;border-radius:2px;background:var(--gold);opacity:.6}
.sec-head h2{margin-top:6px;font-size:clamp(25px,6.4vw,32px);font-weight:800;color:var(--blue);line-height:1.2}
.sec-sub{margin-top:6px;font-size:15px;color:var(--muted)}
.group+.group{margin-top:26px}
.group-title{display:flex;align-items:center;gap:10px;margin:0 2px 12px;font-size:17px;font-weight:800;color:var(--ink)}
.group-title::after{content:"";flex:1;height:1px;background:var(--line)}
.count{display:inline-grid;place-items:center;min-width:26px;height:24px;padding:0 7px;border-radius:999px;background:var(--gold-tint);color:var(--gold-ink);font-size:13px;font-weight:800}
.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
.grid>li:last-child:nth-child(odd){grid-column:1/-1}
.branch{position:relative;display:flex;flex-direction:column;height:100%;min-height:112px;padding:14px 14px 13px;border-radius:16px;background:var(--card);border:1px solid var(--line);box-shadow:0 1px 2px rgba(27,33,80,.04);transition:transform .15s var(--ease-out),border-color .2s,box-shadow .2s var(--ease-out)}
.branch:active{transform:scale(.97)}
.pin{display:grid;place-items:center;width:34px;height:34px;border-radius:11px;background:var(--gold-tint);color:var(--gold);font-size:15px}
.pin .fa-solid{width:auto}
.branch .go{position:absolute;top:14px;inset-inline-end:12px;color:#9a9db3;font-size:15px}
.b-name{margin-top:12px;font-size:17.5px;font-weight:800;color:var(--ink);line-height:1.25}
.b-alt{font-size:13px;font-weight:500;color:var(--muted);line-height:1.3}
.b-venue{margin-top:auto;padding-top:8px;font-size:12.5px;font-weight:700;color:var(--gold-ink);line-height:1.3}

.section-brands{background:#fff;margin-top:34px;padding-bottom:40px;border-top:1px solid var(--line)}
.brands{display:grid;gap:14px}
.brand{border:1px solid var(--line);border-radius:22px;background:var(--card);padding:18px;box-shadow:0 1px 2px rgba(27,33,80,.04)}
.brand-head{display:flex;align-items:center;gap:14px}
.brand-mark{flex:none;display:grid;place-items:center;width:52px;height:52px;border-radius:16px;background:var(--blue);color:#fff;font-size:22px;box-shadow:inset 0 0 0 2px rgba(163,122,55,.55)}
.brand-mark .fa-light{width:auto}
.brand-names{flex:1;min-width:0}
.brand-names h3{font-size:20px;font-weight:800;color:var(--ink);line-height:1.25}
.brand-alt{font-size:13.5px;color:var(--muted)}
.brand-kind{flex:none;align-self:flex-start;padding:4px 10px;border-radius:999px;background:var(--gold-tint);color:var(--gold-ink);font-size:12.5px;font-weight:700}
.chips{display:flex;flex-wrap:wrap;gap:8px;margin-top:14px}
.chip{display:inline-flex;align-items:center;gap:6px;min-height:44px;padding:0 14px;border-radius:999px;border:1px solid var(--line);background:#fff;color:var(--ink);font-size:14px;font-weight:700;transition:background .2s,transform .15s var(--ease-out)}
.chip:active{transform:scale(.97)}
.chip .fa-brands{font-size:18px;width:auto}
.chip .fa-whatsapp{color:#15803d}
.chip .fa-instagram{color:#C13584}
.chip bdi{font-weight:500;color:var(--muted)}
.brand-sub{margin:16px 2px 8px;font-size:13px;font-weight:700;color:var(--muted)}
.brand-rows{display:grid;gap:8px}
.brand-row{display:flex;align-items:center;gap:12px;min-height:58px;padding:9px 12px;border-radius:14px;border:1px solid var(--line);background:var(--paper);transition:transform .15s var(--ease-out),border-color .2s,box-shadow .2s}
.brand-row:active{transform:scale(.98)}
.brand-row .pin{width:32px;height:32px;border-radius:10px;background:#fff}
.row-text{flex:1;min-width:0;display:flex;flex-direction:column;line-height:1.3}
.row-text strong{font-size:15px;font-weight:800;color:var(--ink)}
.row-text small{font-size:13px;color:var(--muted)}
.brand-row .go{color:#9a9db3;font-size:15px}

/* ---------- Footer ---------- */
.foot{position:relative;background:var(--blue);color:#fff;text-align:center;padding-top:30px;overflow:hidden}
.foot-logo{width:132px;height:auto;margin:0 auto;opacity:.95}
.foot p{margin-top:12px;font-size:13.5px;color:rgba(255,255,255,.8)}
.made{display:inline-flex;align-items:center;min-height:44px;padding:0 10px;font-size:12.5px;color:rgba(255,255,255,.66)}
.made span{font-weight:700;color:rgba(255,255,255,.86)}
.skyline{margin-top:8px;display:flex;justify-content:center;padding-bottom:env(safe-area-inset-bottom)}
.skyline img{width:max(100%,760px);max-width:none;height:auto;flex:none}

@media (max-width:339px){.grid{grid-template-columns:1fr}.social-row{grid-template-columns:1fr}}
@media (min-width:640px){
  .grid{grid-template-columns:repeat(3,minmax(0,1fr))}
  .grid>li:last-child:nth-child(odd){grid-column:auto}
  .brands{grid-template-columns:1fr 1fr;align-items:start}
  .section{padding-top:48px}
}
@media (min-width:900px){
  .hero{border-radius:0 0 44px 44px}
  .b-name{font-size:18.5px}
}
@media (prefers-reduced-motion:reduce){*{transition:none!important}.btn-wa:active,.btn-soc:active,.branch:active,.chip:active,.brand-row:active,.lang:active{transform:none}}
</style>
</head>
<body>
<a class="skip" href="#branches"><?= $e($t['jump']) ?></a>

<header class="hero">
  <div class="hero-bar">
    <a class="lang" href="<?= $e($altHref) ?>" hreflang="<?= $other ?>" lang="<?= $other ?>" aria-label="<?= $e($t['switch_label']) ?>">
      <i class="fa-light fa-globe" aria-hidden="true"></i><span><?= $e($t['switch']) ?></span>
    </a>
  </div>
  <div class="hero-inner">
    <h1 class="logo"><span class="sr-only"><?= $e($brand['name_ar'] . ' | ' . $brand['name_en']) ?></span><?= $logoSvg ?></h1>
    <p class="welcome"><?= $e($t['welcome']) ?></p>
    <p class="lead"><?= $e($t['lead']) ?></p>

    <nav class="actions" aria-label="<?= $e($t['follow']) ?>">
      <a class="btn-wa" href="<?= $e($url($wa['url'])) ?>" target="_blank" rel="noopener">
        <span class="wa-ico" aria-hidden="true"><i class="fa-brands fa-whatsapp"></i></span>
        <span class="btn-text">
          <span class="btn-label"><?= $e($t['wa']) ?></span>
          <span class="btn-sub"><bdi dir="ltr"><?= $e($phone($wa['number'])) ?></bdi></span>
        </span>
        <i class="fa-light <?= $arrow ?> btn-go" aria-hidden="true"></i>
      </a>

      <p class="follow-title"><?= $e($t['follow']) ?></p>
      <div class="social-row">
        <?php foreach ($data['socials'] as $s): ?>
        <a class="btn-soc" href="<?= $e($url($s['url'])) ?>" target="_blank" rel="noopener">
          <i class="<?= $e($socialIcon[$s['type']] ?? 'fa-light fa-globe') ?>" aria-hidden="true"></i>
          <span class="btn-text">
            <span class="btn-label"><?= $e($s['label_' . $lang] ?? $s['label_en']) ?></span>
            <span class="btn-sub"><bdi dir="ltr"><?= $e($s['handle']) ?></bdi></span>
          </span>
        </a>
        <?php endforeach; ?>
      </div>

      <a class="jump" href="#branches"><?= $e($t['jump']) ?><i class="fa-light fa-chevron-down" aria-hidden="true"></i></a>
    </nav>
  </div>
</header>

<main>
  <section id="branches" class="section" aria-labelledby="branches-title">
    <div class="wrap">
      <div class="sec-head">
        <p class="eyebrow"><?= $e($t['branches_eyebrow']) ?></p>
        <h2 id="branches-title"><?= $e($t['branches']) ?></h2>
        <p class="sec-sub"><?= $e($t['branches_sub']) ?></p>
      </div>

      <?php foreach ($groups as $gi => $g): ?>
      <div class="group">
        <h3 class="group-title" id="group-<?= (int) $gi ?>"><?= $e($f($g, 'title')) ?> <span class="count"><?= count($g['branches']) ?></span></h3>
        <ul class="grid" aria-labelledby="group-<?= (int) $gi ?>">
          <?php foreach ($g['branches'] as $b):
              $venue = $f($b, 'venue');
              $label = $f($b, 'name') . ($venue !== '' ? ', ' . $venue : '') . '. ' . $t['directions'] . ' (' . $t['new_tab'] . ')';
          ?>
          <li>
            <a class="branch" href="<?= $e($url($b['map'])) ?>" target="_blank" rel="noopener" aria-label="<?= $e($label) ?>">
              <span class="pin" aria-hidden="true"><i class="fa-solid fa-location-dot"></i></span>
              <i class="fa-light <?= $arrow ?> go" aria-hidden="true"></i>
              <span class="b-name"><?= $e($f($b, 'name')) ?></span>
              <span class="b-alt" lang="<?= $other ?>"><?= $e($alt($b, 'name')) ?></span>
              <?php if ($venue !== ''): ?><span class="b-venue"><?= $e($venue) ?></span><?php endif; ?>
            </a>
          </li>
          <?php endforeach; ?>
        </ul>
      </div>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="section section-brands" aria-labelledby="brands-title">
    <div class="wrap">
      <div class="sec-head">
        <p class="eyebrow"><?= $e($t['brands_eyebrow']) ?></p>
        <h2 id="brands-title"><?= $e($t['brands']) ?></h2>
      </div>
      <div class="brands">
        <?php foreach ($data['brands'] as $br): ?>
        <article class="brand">
          <div class="brand-head">
            <span class="brand-mark" aria-hidden="true"><i class="fa-light fa-<?= $e($br['icon']) ?>"></i></span>
            <div class="brand-names">
              <h3><?= $e($f($br, 'name')) ?></h3>
              <p class="brand-alt" lang="<?= $other ?>"><?= $e($alt($br, 'name')) ?></p>
            </div>
            <span class="brand-kind"><?= $e($f($br, 'kind')) ?></span>
          </div>
          <div class="chips">
            <?php foreach ($br['links'] as $l):
                $isWa = $l['type'] === 'whatsapp';
                $shown = $isWa ? $phone($l['number']) : $l['handle'];
            ?>
            <a class="chip" href="<?= $e($url($l['url'])) ?>" target="_blank" rel="noopener">
              <i class="<?= $e($socialIcon[$l['type']] ?? 'fa-light fa-globe') ?>" aria-hidden="true"></i>
              <span><?= $e($isWa ? $t['whatsapp'] : $t['instagram']) ?></span>
              <bdi dir="ltr"><?= $e($shown) ?></bdi>
            </a>
            <?php endforeach; ?>
          </div>
          <p class="brand-sub"><?= $e(count($br['branches']) > 1 ? $t['brand_branches'] : $t['brand_branch']) ?></p>
          <ul class="brand-rows">
            <?php foreach ($br['branches'] as $b):
                $label = $f($b, 'name') . ', ' . $f($b, 'area') . '. ' . $t['directions'] . ' (' . $t['new_tab'] . ')';
            ?>
            <li>
              <a class="brand-row" href="<?= $e($url($b['map'])) ?>" target="_blank" rel="noopener" aria-label="<?= $e($label) ?>">
                <span class="pin" aria-hidden="true"><i class="fa-solid fa-location-dot"></i></span>
                <span class="row-text"><strong><?= $e($f($b, 'name')) ?></strong><small><?= $e($f($b, 'area')) ?></small></span>
                <i class="fa-light <?= $arrow ?> go" aria-hidden="true"></i>
              </a>
            </li>
            <?php endforeach; ?>
          </ul>
        </article>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
</main>

<footer class="foot">
  <img class="foot-logo" src="<?= $e($asset('logo.svg')) ?>" width="225" height="82" alt="<?= $e($brand['name_ar'] . ' | ' . $brand['name_en']) ?>" loading="lazy" decoding="async">
  <p dir="ltr" lang="en">&copy; <?= $year ?> <?= $e($brand['legal_en']) ?></p>
  <a class="made" href="https://cardify.om/" rel="noopener"><?php if ($isAr): ?>صُنعت بواسطة&nbsp;<span>Cardify</span><?php else: ?>Made with&nbsp;<span>Cardify</span><?php endif; ?></a>
  <div class="skyline"><img src="<?= $e($asset('skyline.svg')) ?>" width="842" height="139" alt="" loading="lazy" decoding="async"></div>
</footer>
</body>
</html>
