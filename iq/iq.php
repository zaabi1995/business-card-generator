<?php
declare(strict_types=1);

/*
 * cardify.om/iq: the Cardify IQ test. One front controller for every page and the JSON API.
 *
 * nginx (aaPanel rewrite file, not in the repo) sends /iq, /iq/* and /ar/iq, /ar/iq/* here:
 *   rewrite ^/ar/iq(/.*)?$ /iq/iq.php?lang=ar last;
 *   rewrite ^/iq(/.*)?$    /iq/iq.php last;
 * This file is deliberately NOT named index.php: config.php gives every index.php public HTTP
 * caching, and these pages are per-person.
 *
 *   /iq                  landing page (SEO), start
 *   /iq/test             the test: intro, practice, 30 questions
 *   /iq/result/{id}      public result, shareable
 *   /iq/report/{id}      full report (paid or IQ Pro)
 *   /iq/certificate/{id} certificate PDF (paid or IQ Pro)
 *   /iq/og/{id}          share image for a result
 *   /iq/leaderboard      public leaderboard
 *   /iq/account          sign in (code by email or WhatsApp), profile, history, purchases
 *   /iq/practice         practice mode (IQ Pro)
 *   /iq/api/{action}     JSON
 */

require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/UrlSafety.php';
require_once INCLUDES_DIR . '/SecurityHeaders.php';
SecurityHeaders::send();
require_once INCLUDES_DIR . '/iq/IqStore.php';
require_once INCLUDES_DIR . '/iq/IqPay.php';

// The URL decides the language: /ar/iq... is Arabic, /iq... is English. The JSON API carries the
// page's language in ?ui=. A language cookie from elsewhere on the site never overrides the URL.
$__rawPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$LANG = preg_match('~^/ar/iq(/|$)~', $__rawPath) ? 'ar' : 'en';
if (preg_match('~^/iq/api/~', $__rawPath)) $LANG = ($_GET['ui'] ?? '') === 'ar' ? 'ar' : 'en';
if (class_exists('I18n')) I18n::setLocale($LANG);
$S = require dirname(__DIR__) . '/lang/' . $LANG . '/iq.php';

function iq_s(string $key, array $vars = []): string
{
    global $S;
    $v = $S[$key] ?? $key;
    if (!is_string($v)) return $key;
    foreach ($vars as $k => $x) $v = str_replace(':' . $k, (string)$x, $v);
    return $v;
}
function iq_e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function iq_url(string $path = ''): string { global $LANG; return ($LANG === 'ar' ? '/ar' : '') . '/iq' . $path; }
function iq_abs(string $path = ''): string { return 'https://cardify.om' . iq_url($path); }
function iq_num($n): string
{
    global $LANG;
    $s = (string)$n;
    return $LANG === 'ar' ? strtr($s, ['0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤', '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩']) : $s;
}

const IQ_COUNTRIES = ['OM', 'AE', 'SA', 'QA', 'KW', 'BH', 'EG', 'JO', 'LB', 'IQ', 'SY', 'PS', 'YE', 'MA', 'DZ', 'TN', 'LY', 'SD',
    'TR', 'IR', 'PK', 'IN', 'BD', 'LK', 'MY', 'ID', 'PH', 'CN', 'JP', 'KR', 'GB', 'IE', 'FR', 'DE', 'NL', 'ES', 'IT', 'SE', 'NO', 'CH',
    'RU', 'UA', 'PL', 'US', 'CA', 'MX', 'BR', 'AR', 'AU', 'NZ', 'ZA', 'NG', 'KE'];

function iq_country_name(string $cc): string
{
    global $LANG;
    if (class_exists('Locale')) {
        $n = Locale::getDisplayRegion('-' . $cc, $LANG);
        if ($n && $n !== $cc) return $n;
    }
    return $cc;
}

/* ---------- routing ---------- */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = preg_replace('~^/ar(?=/)~', '', $path);
$path = preg_replace('~^/iq~', '', $path);
$path = rtrim((string)$path, '/');
$seg = $path === '' ? [] : explode('/', ltrim($path, '/'));
$page = $seg[0] ?? '';

if (isset($_GET['ref']) && is_string($_GET['ref']) && $page !== 'api') IqStore::captureRef($_GET['ref']);

try {
    switch ($page) {
        case '': page_home(); break;
        case 'test': page_test(); break;
        case 'result': page_result($seg[1] ?? ''); break;
        case 'report': page_report($seg[1] ?? ''); break;
        case 'certificate': page_certificate(preg_replace('/\.pdf$/', '', $seg[1] ?? '')); break;
        case 'verify': page_verify($seg[1] ?? ''); break;
        case 'og': page_og(preg_replace('/\.png$/', '', $seg[1] ?? '')); break;
        case 'leaderboard': page_leaderboard(); break;
        case 'account': page_account(); break;
        case 'practice': page_practice(); break;
        case 'api': api($seg[1] ?? ''); break;
        default: not_found();
    }
} catch (IqError $e) {
    if ($page === 'api') api_out(['error' => $e->codeName], $e->status);
    not_found();
} catch (Throwable $e) {
    error_log('[iq] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if ($page === 'api') api_out(['error' => 'server'], 500);
    http_response_code(500);
    echo 'Server error';
}
exit;

/* ---------- shared layout ---------- */

function no_store(): void
{
    header('Cache-Control: private, no-store, max-age=0');
}

function layout_open(string $title, string $desc, array $opt = []): void
{
    global $LANG, $pageTitle, $pageDescription, $canonicalUrl, $extraHead, $showNavigation, $metaRobots, $ogImage, $bodyClass;
    $pageTitle = $title;
    $pageDescription = $desc;
    $canonicalUrl = $opt['canonical'] ?? null;
    $metaRobots = $opt['robots'] ?? 'index,follow';
    $ogImage = $opt['og'] ?? 'https://cardify.om/assets/iq/og-iq.png';
    $showNavigation = true;
    $bodyClass = 'iqx-body';
    require_once INCLUDES_DIR . '/AdSense.php';
    if (IqStore::isPro(IqStore::user())) CardifyAdSense::$suppress = true;
    $v = @filemtime(dirname(__DIR__) . '/assets/iq/iq.css') ?: 1;
    $extraHead = '<link rel="stylesheet" href="/assets/iq/iq.css?v=' . $v . '">' . "\n" . ($opt['head'] ?? '');
    // hreflang for /iq and /iq/leaderboard comes from includes/ArTwins.php through the shared header.
    require INCLUDES_DIR . '/ui-header.php';
    echo '<main id="main-content" class="iqx" dir="' . ($LANG === 'ar' ? 'rtl' : 'ltr') . '">';
}

function layout_close(?array $jsData = null): void
{
    global $LANG, $S;
    echo '</main>';
    if ($jsData !== null) {
        $jsData['lang'] = $LANG;
        $jsData['s'] = [
            'level' => $S['level'], 'kinds' => $S['kinds'], 'errors' => $S['errors'], 'rules' => $S['rules'],
            'copied' => $S['copied'], 'saved' => $S['saved'], 'code_sent' => $S['code_sent'], 'not_now' => $S['not_now'],
        ];
        $jsData['csrf'] = generateCSRFToken();
        $jsData['base'] = iq_url();
        echo '<script type="application/json" id="iq-data">' . json_encode($jsData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . '</script>';
        $v = @filemtime(dirname(__DIR__) . '/assets/iq/iq.js') ?: 1;
        echo '<script src="/assets/iq/iq.js?v=' . $v . '" defer></script>';
    }
    require INCLUDES_DIR . '/ui-footer.php';
}

function not_found(): void
{
    http_response_code(404);
    layout_open(iq_s('not_found'), '', ['robots' => 'noindex']);
    echo '<section class="iqx-wrap iqx-center"><h1 class="iqx-h2">' . iq_e(iq_s('not_found')) . '</h1>'
        . '<p><a class="iqx-btn" href="' . iq_url() . '">' . iq_e(iq_s('take_test')) . '</a></p></section>';
    layout_close();
    exit;
}

function lang_switch(string $pathTail): string
{
    global $LANG;
    $href = ($LANG === 'ar' ? '' : '/ar') . '/iq' . $pathTail;
    return '<a class="iqx-lang" href="' . iq_e($href) . '" hreflang="' . ($LANG === 'ar' ? 'en' : 'ar') . '">' . ($LANG === 'ar' ? 'English' : 'العربية') . '</a>';
}

function subnav(string $active, string $tail): string
{
    $u = IqStore::user();
    $items = [['', iq_s('home')], ['/leaderboard', iq_s('board_title')], ['/account', $u ? iq_s('account') : iq_s('sign_in')]];
    if (IqStore::isPro($u)) array_splice($items, 2, 0, [['/practice', iq_s('practice')]]);
    $h = '<nav class="iqx-subnav" aria-label="IQ"><div class="iqx-wrap iqx-subnav-in">';
    foreach ($items as [$p, $l]) {
        $h .= '<a href="' . iq_url($p) . '"' . ($p === $active ? ' aria-current="page"' : '') . '>' . iq_e($l) . '</a>';
    }
    // The site header already carries the language switch; no second copy here.
    return $h . '</div></nav>';
}

function price_label(?float $p): string
{
    return $p === null ? iq_s('not_on_sale') : 'OMR ' . number_format($p, 3);
}

function board_table(array $rows, bool $compact = false): string
{
    if (!$rows) return '<p class="iqx-muted">' . iq_e(iq_s('board_empty')) . '</p>';
    $h = '<table class="iqx-board"><thead><tr><th>' . iq_e(iq_s('board_rank')) . '</th><th>' . iq_e(iq_s('board_name')) . '</th>'
        . ($compact ? '' : '<th>' . iq_e(iq_s('board_country')) . '</th>') . '<th>' . iq_e(iq_s('board_iq')) . '</th></tr></thead><tbody>';
    foreach ($rows as $i => $r) {
        $cc = strtolower((string)$r['country']);
        $flag = $cc !== '' ? '<span class="fi fi-' . iq_e($cc) . '" aria-hidden="true"></span> ' : '';
        $h .= '<tr><td>' . iq_num($i + 1) . '</td><td><a href="' . iq_url('/result/' . $r['public_id']) . '">' . $flag . '<bdi>' . iq_e($r['display_name']) . '</bdi></a>'
            . ($r['pro'] ? ' <span class="iqx-pro">PRO</span>' : '') . '</td>'
            . ($compact ? '' : '<td>' . ($r['country'] ? iq_e(iq_country_name((string)$r['country'])) : '') . '</td>')
            . '<td class="iqx-num">' . iq_num((int)$r['iq']) . '</td></tr>';
    }
    return $h . '</tbody></table>';
}

/* ---------- pages ---------- */

function page_home(): void
{
    global $LANG, $S;
    $current = IqStore::current();
    $inProgress = $current && $current['status'] === 'in_progress' && IqStore::owns($current);
    $prices = IqPay::prices();
    $faqLd = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn($q) => [
        '@type' => 'Question', 'name' => $q[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $q[1]],
    ], $S['faq'])];
    $appLd = ['@context' => 'https://schema.org', '@type' => 'WebApplication', 'name' => iq_s('brand'),
        'url' => iq_abs(), 'applicationCategory' => 'EducationalApplication', 'operatingSystem' => 'Any',
        'inLanguage' => ['en', 'ar'], 'description' => iq_s('desc_home'),
        'offers' => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'OMR'],
        'publisher' => ['@type' => 'Organization', 'name' => 'Cardify', 'url' => 'https://cardify.om/']];
    $crumbLd = ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Cardify', 'item' => 'https://cardify.om/'],
        ['@type' => 'ListItem', 'position' => 2, 'name' => iq_s('home'), 'item' => iq_abs()]]];
    $ld = '';
    foreach ([$appLd, $faqLd, $crumbLd] as $node) {
        $ld .= '<script type="application/ld+json">' . json_encode($node, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) . "</script>\n";
    }
    if (!IqStore::userId() && !$inProgress) {
        header('Cache-Control: public, max-age=300');
    } else {
        no_store();
    }
    layout_open(iq_s('title_home'), iq_s('desc_home'), ['canonical' => iq_abs(), 'alt' => '', 'head' => $ld]);
    echo subnav('', '');
    ?>
    <section class="iqx-hero">
        <div class="iqx-wrap iqx-hero-in">
            <div>
                <p class="iqx-eyebrow"><?= iq_e(iq_s('brand')) ?></p>
                <h1 class="iqx-h1"><?= iq_e(iq_s('h1')) ?></h1>
                <p class="iqx-lead"><?= iq_e(iq_s('lead')) ?></p>
                <a class="iqx-btn iqx-btn-lg" href="<?= iq_url('/test') ?>"><?= iq_e($inProgress ? iq_s('continue') : iq_s('start')) ?></a>
            </div>
            <div class="iqx-hero-art" aria-hidden="true">
                <div class="iqx-ring"><span>IQ</span><small dir="ltr">100 ± 15</small></div>
            </div>
        </div>
    </section>
    <section class="iqx-wrap iqx-grid4">
        <?php foreach ($S['how'] as [$h, $p]): ?>
            <article class="iqx-card"><h2 class="iqx-h3"><?= iq_e($h) ?></h2><p><?= iq_e($p) ?></p></article>
        <?php endforeach; ?>
    </section>
    <section class="iqx-wrap iqx-split">
        <article class="iqx-card">
            <h2 class="iqx-h2"><?= iq_e(iq_s('board_title')) ?></h2>
            <p class="iqx-muted"><?= iq_e(iq_s('board_lead')) ?></p>
            <?= board_table(IqStore::leaderboard('all', null, 10), true) ?>
            <p><a href="<?= iq_url('/leaderboard') ?>"><?= iq_e(iq_s('board_see')) ?></a></p>
        </article>
        <article class="iqx-card">
            <h2 class="iqx-h2"><?= iq_e(iq_s('measures_title')) ?></h2>
            <p><?= iq_e(iq_s('measures')) ?></p>
        </article>
    </section>
    <section class="iqx-wrap">
        <h2 class="iqx-h2"><?= iq_e(iq_s('pro_title')) ?></h2>
        <div class="iqx-split">
            <article class="iqx-card iqx-offer">
                <h3 class="iqx-h3"><?= iq_e(iq_s('report_title')) ?></h3>
                <p class="iqx-price"><?= iq_e(price_label($prices['report'])) ?> <small><?= $prices['report'] !== null ? iq_e(iq_s('one_off')) : '' ?></small></p>
                <ul><?php foreach ($S['report_points'] as $p): ?><li><?= iq_e($p) ?></li><?php endforeach; ?></ul>
                <p class="iqx-muted iqx-small"><?= iq_e(iq_s('report_free_share')) ?></p>
            </article>
            <article class="iqx-card iqx-offer iqx-offer-pro">
                <h3 class="iqx-h3"><?= iq_e(iq_s('pro_name')) ?></h3>
                <p class="iqx-price"><?= iq_e(price_label($prices['pro_month'])) ?> <small><?= $prices['pro_month'] !== null ? iq_e(iq_s('per_month')) : '' ?></small></p>
                <ul><?php foreach ($S['pro_points'] as $p): ?><li><?= iq_e($p) ?></li><?php endforeach; ?></ul>
                <?php if ($prices['pro_month'] !== null): ?><a class="iqx-btn" href="<?= iq_url('/account') ?>"><?= iq_e(iq_s('buy_pro')) ?></a><?php endif; ?>
            </article>
        </div>
    </section>
    <section class="iqx-wrap">
        <h2 class="iqx-h2"><?= iq_e(iq_s('faq_title')) ?></h2>
        <?php foreach ($S['faq'] as [$q, $a]): ?>
            <details class="iqx-faq"><summary><?= iq_e($q) ?></summary><p><?= iq_e($a) ?></p></details>
        <?php endforeach; ?>
        <p class="iqx-center"><a class="iqx-btn iqx-btn-lg" href="<?= iq_url('/test') ?>"><?= iq_e(iq_s('start')) ?></a></p>
    </section>
    <?php
    layout_close();
}

function page_test(): void
{
    no_store();
    $a = IqStore::current();
    $state = ['view' => 'intro', 'next_free' => IqStore::nextFreeStart()];
    if ($a && IqStore::owns($a)) {
        $a = IqStore::settle($a);
        if ($a['status'] === 'in_progress') $state['view'] = 'question';
        elseif ($state['next_free'] !== null) { header('Location: ' . iq_url('/result/' . $a['public_id'])); exit; }
    }
    $u = IqStore::user();
    $state['name'] = $u['display_name'] ?? '';
    $state['countries'] = array_map(fn($c) => [$c, iq_country_name($c)], IQ_COUNTRIES);
    $state['country'] = $u['country'] ?? '';
    layout_open(iq_s('h1'), iq_s('desc_home'), ['robots' => 'noindex,follow']);
    echo subnav('/test', '/test');
    echo '<section class="iqx-wrap iqx-test" id="iq-app" aria-live="polite"><div class="iqx-skeleton"></div></section>';
    layout_close($state + ['page' => 'test']);
}

function result_block(array $a, bool $owner): string
{
    $r = IqStore::result($a);
    $h = '<div class="iqx-result">';
    $h .= '<div class="iqx-ring iqx-ring-score" style="--s:' . max(0, min(1, ($r['iq'] - 55) / 90)) . '"><span>' . iq_num($r['iq']) . '</span><small>IQ</small></div>';
    $h .= '<h2 class="iqx-h2">' . iq_e($GLOBALS['LANG'] === 'ar' ? $r['band']['ar'] : $r['band']['en']) . '</h2>';
    $h .= '<p>' . iq_e(iq_s('range', ['low' => iq_num($r['iq_low']), 'high' => iq_num($r['iq_high'])])) . ' '
        . iq_e(iq_s('percentile', ['p' => iq_num($r['percentile'])])) . '</p>';
    if ($owner) $h .= '<p class="iqx-muted">' . iq_e(iq_s('right_answers', ['n' => iq_num($r['correct']), 't' => iq_num($r['total'])])) . '</p>';
    return $h . '</div>';
}

/**
 * Share buttons for a result. The link carries ?ref=<id>, so whoever starts the test from it
 * counts towards this result's free report. The text names Cardify and the score.
 */
function share_buttons(array $a, bool $owner): string
{
    $url = iq_abs('/result/' . $a['public_id']) . ($owner ? '?ref=' . $a['public_id'] : '');
    $text = $owner ? iq_s('share_text', ['iq' => (int)$a['iq']]) : iq_s('share_text_other', ['name' => $a['name'], 'iq' => (int)$a['iq']]);
    $t = rawurlencode($text . ' ' . $url);
    $u = rawurlencode($url);
    $links = [
        ['WhatsApp', 'https://api.whatsapp.com/send?text=' . $t],
        ['X', 'https://twitter.com/intent/tweet?text=' . $t],
        ['LinkedIn', 'https://www.linkedin.com/sharing/share-offsite/?url=' . $u],
        ['Facebook', 'https://www.facebook.com/sharer/sharer.php?u=' . $u],
        ['Telegram', 'https://t.me/share/url?url=' . $u . '&text=' . rawurlencode($text)],
    ];
    $h = '<div class="iqx-actions iqx-share">';
    foreach ($links as [$label, $href]) {
        $h .= '<a class="iqx-btn iqx-btn-ghost" href="' . iq_e($href) . '" target="_blank" rel="noopener">' . $label . '</a>';
    }
    return $h . '<button class="iqx-btn iqx-btn-ghost" type="button" data-iq-copy="' . iq_e($url) . '" data-iq-text="' . iq_e($text) . '">'
        . iq_e(iq_s('copy')) . '</button></div>';
}

function domain_bars(array $a): string
{
    global $LANG;
    $r = IqStore::result($a);
    $h = '<ul class="iqx-domains">';
    foreach ($r['domains'] ?? [] as $d) {
        $pct = $d['total'] ? round($d['correct'] / $d['total'] * 100) : 0;
        $h .= '<li><span>' . iq_e($LANG === 'ar' ? $d['ar'] : $d['en']) . '</span><span class="iqx-bar"><i style="inline-size:' . $pct . '%"></i></span><span class="iqx-num">'
            . iq_num($d['correct']) . '/' . iq_num($d['total']) . '</span></li>';
    }
    return $h . '</ul>';
}

function page_result(string $pid): void
{
    $a = IqStore::byPublicId($pid);
    if (!$a) not_found();
    $a = IqStore::settle($a);
    $owner = IqStore::owns($a);
    $u = IqStore::user();
    no_store();
    if ($a['status'] !== 'done') {
        if ($owner) { header('Location: ' . iq_url('/test')); exit; }
        layout_open(iq_s('not_finished'), '', ['robots' => 'noindex']);
        echo '<section class="iqx-wrap iqx-center"><p>' . iq_e(iq_s('not_finished')) . '</p><a class="iqx-btn" href="' . iq_url('/test') . '">' . iq_e(iq_s('take_test')) . '</a></section>';
        layout_close();
        return;
    }
    $name = $a['name'] !== '' ? $a['name'] : 'Someone';
    $title = iq_s('result_title', ['name' => $name]) . ': IQ ' . $a['iq'];
    $prices = IqPay::prices();
    layout_open($title, iq_s('desc_home'), [
        'robots' => 'noindex,follow', 'canonical' => iq_abs('/result/' . $pid), 'og' => 'https://cardify.om/iq/og/' . $pid . '.png',
    ]);
    echo subnav('/result', '/result/' . $pid);
    ?>
    <section class="iqx-wrap iqx-narrow">
        <p class="iqx-eyebrow"><?= iq_e($owner ? iq_s('your_result') : iq_s('result_title', ['name' => $name])) ?></p>
        <article class="iqx-card iqx-hero-card"><?= result_block($a, $owner) ?></article>
        <?php if ($owner): ?>
            <?php if (!empty($_GET['payment'])): ?>
                <p class="iqx-note <?= $_GET['payment'] === 'success' ? 'is-ok' : 'is-bad' ?>"><?= iq_e($_GET['payment'] === 'success' ? iq_s('payment_success') : iq_s('payment_failed')) ?></p>
            <?php endif; ?>
            <article class="iqx-card">
                <h2 class="iqx-h3"><?= iq_e(iq_s('by_kind')) ?></h2>
                <?= domain_bars($a) ?>
                <p class="iqx-muted iqx-small"><?= iq_e(iq_s('by_kind_note')) ?></p>
            </article>
            <?php $canSee = IqPay::canSeeReport($u, $a); $refs = IqStore::referrals($a); ?>
            <?php if ($canSee): ?>
                <?php if ($a['unlocked_by'] === 'share'): ?><p class="iqx-note is-ok"><?= iq_e(iq_s('unlocked_share')) ?></p><?php endif; ?>
                <div class="iqx-actions"><a class="iqx-btn" href="<?= iq_url('/report/' . $pid) ?>"><?= iq_e(iq_s('open_report')) ?></a></div>
            <?php else: ?>
                <article class="iqx-card iqx-unlock">
                    <h2 class="iqx-h3"><?= iq_e(iq_s('share_unlock_title')) ?></h2>
                    <p><?= iq_e(iq_s('share_unlock_body', ['n' => iq_num(IqStore::SHARE_UNLOCK)])) ?></p>
                    <p class="iqx-muted iqx-small"><?= iq_e(iq_s('share_unlock_progress', ['n' => iq_num($refs), 't' => iq_num(IqStore::SHARE_UNLOCK)])) ?></p>
                    <?= share_buttons($a, true) ?>
                    <?php if ($u && $prices['report'] !== null): ?>
                        <p class="iqx-muted iqx-small"><?= iq_e(iq_s('or_pay')) ?></p>
                        <button class="iqx-btn iqx-btn-ghost" type="button" data-iq-buy="report" data-attempt="<?= iq_e($pid) ?>"><?= iq_e(iq_s('buy_report')) ?> · <?= iq_e(price_label($prices['report'])) ?></button>
                    <?php endif; ?>
                </article>
            <?php endif; ?>
            <?= cert_card($a, $u, $prices['certificate']) ?>
            <?php if (!$u): ?>
                <div class="iqx-actions"><a class="iqx-btn iqx-btn-ghost" href="<?= iq_url('/account?next=' . rawurlencode('/result/' . $pid)) ?>"><?= iq_e(iq_s('claim')) ?></a></div>
            <?php endif; ?>
        <?php endif; ?>
        <?php if (!$owner || IqPay::canSeeReport($u, $a)): ?>
        <article class="iqx-card">
            <h2 class="iqx-h3"><?= iq_e(iq_s('share')) ?></h2>
            <?= share_buttons($a, $owner) ?>
        </article>
        <?php endif; ?>
        <?php if (!$owner): ?><p class="iqx-center"><a class="iqx-btn iqx-btn-lg" href="<?= iq_url('/test') ?>"><?= iq_e(iq_s('take_test')) ?></a></p><?php endif; ?>
        <p class="iqx-muted iqx-small"><?= iq_e(iq_s('disclaimer')) ?><?= IqStore::normed() ? '' : ' ' . iq_e(iq_s('provisional')) ?></p>
    </section>
    <?php
    layout_close(['page' => 'result']);
}

function page_report(string $pid): void
{
    global $LANG;
    $a = IqStore::byPublicId($pid);
    if (!$a || $a['status'] !== 'done') not_found();
    no_store();
    $u = IqStore::user();
    layout_open(iq_s('report'), '', ['robots' => 'noindex,nofollow']);
    echo subnav('/report', '/report/' . $pid);
    echo '<section class="iqx-wrap iqx-narrow">';
    if (!IqPay::canSeeReport($u, $a)) {
        echo '<article class="iqx-card iqx-center"><p>' . iq_e(iq_s('locked')) . '</p><a class="iqx-btn" href="' . iq_url('/result/' . $pid) . '">' . iq_e(iq_s('your_result')) . '</a></article></section>';
        layout_close();
        return;
    }
    if (!empty($_GET['payment'])) {
        echo '<p class="iqx-note ' . ($_GET['payment'] === 'success' ? 'is-ok' : 'is-bad') . '">' . iq_e($_GET['payment'] === 'success' ? iq_s('payment_success') : iq_s('payment_failed')) . '</p>';
    }
    echo '<h1 class="iqx-h1">' . iq_e(iq_s('report')) . '</h1>';
    echo '<article class="iqx-card iqx-hero-card">' . result_block($a, true) . '</article>';
    echo '<article class="iqx-card"><h2 class="iqx-h3">' . iq_e(iq_s('by_kind')) . '</h2>' . domain_bars($a) . '</article>';
    echo cert_card($a, $u, IqPay::price('certificate'));
    echo '<h2 class="iqx-h2">' . iq_e(iq_s('review')) . '</h2>';
    $kinds = $GLOBALS['S']['kinds'];
    $levels = $GLOBALS['S']['level'];
    foreach (IqStore::answers((int)$a['id']) as $row) {
        $q = iqe_question((string)$a['seed'], (int)$row['idx'], (string)$row['kind'], (int)$row['level']);
        $mine = $row['choice'] === null ? null : (int)$row['choice'];
        $ok = (int)$row['correct'] === 1;
        echo '<article class="iqx-card iqx-review ' . ($ok ? 'is-ok' : 'is-bad') . '">';
        echo '<p class="iqx-muted iqx-small">' . iq_num((int)$row['idx'] + 1) . ' · ' . iq_e($kinds[$row['kind']] ?? $row['kind']) . ' · ' . iq_e($levels[(int)$row['level']] ?? '')
            . ' · ' . iq_e(iq_s('seconds', ['s' => iq_num(round((int)$row['ms'] / 1000))])) . '</p>';
        echo '<h3 class="iqx-h3">' . iq_e($LANG === 'ar' ? $q['prompt_ar'] : $q['prompt_en']) . '</h3>';
        echo review_body($q);
        echo '<div class="iqx-opts ' . (isset($q['options'][0]['svg']) ? 'is-fig' : '') . '">';
        foreach ($q['options'] as $i => $o) {
            $cls = $i === $q['answer'] ? 'is-right' : ($i === $mine ? 'is-wrong' : '');
            echo '<div class="iqx-opt ' . $cls . '">' . (isset($o['svg']) ? $o['svg'] : iq_e($o['text'] ?? ($LANG === 'ar' ? $o['text_ar'] : $o['text_en']))) . '</div>';
        }
        echo '</div>';
        if ($mine === null) echo '<p class="iqx-muted iqx-small">' . iq_e(iq_s('no_answer')) . '</p>';
        echo '</article>';
    }
    echo '</section>';
    layout_close();
}

function review_body(array $q): string
{
    if (!empty($q['series'])) {
        $h = '<div class="iqx-series" dir="ltr">';
        foreach ($q['series'] as $n) $h .= '<span>' . (int)$n . '</span>';
        return $h . '<span class="is-blank">?</span></div>';
    }
    if (!empty($q['grid'])) {
        $h = '<div class="iqx-grid">';
        foreach ($q['grid'] as $svg) $h .= '<span>' . $svg . '</span>';
        return $h . '<span class="is-blank">?</span></div>';
    }
    if (!empty($q['figure'])) return '<div class="iqx-figure">' . $q['figure'] . '</div>';
    return '';
}

function cert_verify_url(array $a): string
{
    return 'https://cardify.om/iq/verify/' . $a['cert_no'];
}

/** "Add to profile" on LinkedIn, pre-filled as a licence or certification. */
function cert_linkedin_url(array $a): string
{
    $t = strtotime((string)$a['cert_issued_at']);
    return 'https://www.linkedin.com/profile/add?' . http_build_query([
        'startTask' => 'CERTIFICATION_NAME', 'name' => 'Verified IQ Certificate (IQ ' . (int)$a['iq'] . ')',
        'organizationName' => 'Cardify', 'issueYear' => date('Y', $t), 'issueMonth' => date('n', $t),
        'certUrl' => cert_verify_url($a), 'certId' => $a['cert_no'],
    ]);
}

function cert_qr_data_uri(string $url): string
{
    $lib = dirname(__DIR__) . '/vendor/tecnickcom/tcpdf/tcpdf_barcodes_2d.php';
    if (!is_file($lib)) return '';
    require_once $lib;
    $svg = (new TCPDF2DBarcode($url, 'QRCODE,M'))->getBarcodeSVGcode(4, 4, '#0b2433');
    return 'data:image/svg+xml;base64,' . base64_encode($svg);
}

/** The verified certificate: A4 landscape, bilingual, with the confirmed name, a number and a QR code. */
function page_certificate(string $pid): void
{
    $a = IqStore::byPublicId($pid);
    if (!$a || $a['status'] !== 'done' || empty($a['cert_no']) || !IqStore::owns($a)) not_found();
    $r = IqStore::result($a);
    $verify = cert_verify_url($a);
    $qr = cert_qr_data_uri($verify);
    $logo = dirname(__DIR__) . '/assets/images/logo.svg';
    $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $issued = strtotime((string)$a['cert_issued_at']);
    $taken = strtotime((string)$a['finished_at']);
    $arDigits = fn($s) => strtr((string)$s, ['0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤', '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩']);
    $html = '<!doctype html><html><head><meta charset="utf-8"><style>
@import url("https://fonts.bhd.om/css2?family=Sora:wght@400;600;700;800&family=Noto+Sans+Arabic:wght@400;600;700&display=swap");
@page{size:A4 landscape;margin:0}
*{box-sizing:border-box}
body{margin:0;font-family:Sora,"Noto Sans Arabic",sans-serif;color:#0b2433}
.page{position:relative;width:297mm;height:210mm;padding:10mm}
.frame{position:relative;width:100%;height:100%;border:1.6mm solid #009bc1;padding:2.2mm}
.inner{position:relative;width:100%;height:100%;border:.35mm solid #9fd8e8;padding:12mm 18mm 10mm}
.top{display:flex;justify-content:space-between;align-items:center}
.logo{height:11mm}
.no{font-size:8.5pt;color:#456;text-align:right;line-height:1.5}
.no b{color:#0b2433;letter-spacing:.3pt}
h1{margin:8mm 0 0;text-align:center;font-size:27pt;font-weight:800;letter-spacing:.4pt}
.ar{font-family:"Noto Sans Arabic",sans-serif;direction:rtl}
h2.ar{margin:1mm 0 0;text-align:center;font-size:15pt;font-weight:700;color:#007a9c}
.cert{margin:6mm 0 0;text-align:center;font-size:10.5pt;color:#456}
.name{margin:2.5mm 0 0;text-align:center;font-size:28pt;font-weight:700}
.rule{width:120mm;margin:2mm auto 0;border-top:.4mm solid #e2b34a}
.line{margin:3mm 0 0;text-align:center;font-size:10.5pt;color:#456}
.score{display:flex;justify-content:center;align-items:center;gap:14mm;margin:5mm 0 0}
.iq{text-align:center}
.iq b{display:block;font-size:54pt;line-height:1;font-weight:800;color:#009bc1}
.iq span{font-size:9pt;letter-spacing:2pt;color:#456}
.facts{font-size:10.5pt;line-height:1.75}
.facts b{color:#0b2433}
.foot{position:absolute;left:18mm;right:18mm;bottom:9mm;display:flex;justify-content:space-between;align-items:flex-end}
.small{font-size:7.6pt;color:#567;line-height:1.5;max-width:150mm}
.qr{text-align:center;font-size:7.4pt;color:#456}
.qr img{width:25mm;height:25mm;display:block;margin:0 auto 1mm}
.seal{position:absolute;right:52mm;bottom:12mm;width:26mm;height:26mm;border-radius:50%;border:.7mm solid #e2b34a;color:#b8892c;
 display:flex;flex-direction:column;align-items:center;justify-content:center;font-size:6.6pt;font-weight:700;letter-spacing:.8pt;text-align:center;line-height:1.3}
</style></head><body><div class="page"><div class="frame"><div class="inner">
<div class="top"><img class="logo" src="file://' . $e($logo) . '"><div class="no">Certificate No. <b>' . $e($a['cert_no']) . '</b><br>Issued ' . date('j F Y', $issued) . '</div></div>
<h1>Verified IQ Certificate</h1>
<h2 class="ar">شهادة ذكاء موثّقة</h2>
<p class="cert">This certifies that &nbsp;·&nbsp; <span class="ar">نشهد بأن</span></p>
<p class="name" dir="auto">' . $e($a['cert_name']) . '</p>
<div class="rule"></div>
<p class="line">completed the Cardify adaptive IQ assessment on ' . date('j F Y', $taken) . ' and achieved</p>
<div class="score">
 <div class="iq"><b>' . (int)$r['iq'] . '</b><span>IQ SCORE</span></div>
 <div class="facts">Classification: <b>' . $e($r['band']['en']) . '</b> <span class="ar">(' . $e($r['band']['ar']) . ')</span><br>
 Higher than <b>' . (int)$r['percentile'] . '%</b> of people<br>
 90% range: <b>' . (int)$r['iq_low'] . ' to ' . (int)$r['iq_high'] . '</b><br>
 <span class="ar">درجة الذكاء ' . $arDigits((int)$r['iq']) . '، أعلى من ' . $arDigits((int)$r['percentile']) . '٪ من الناس</span></div>
</div>
<div class="seal">VERIFIED<br>CARDIFY<br>IQ</div>
<div class="foot">
 <div class="small">30 adaptive questions of fluid reasoning (patterns, numbers, spatial, observation, logic), one 33-minute clock, scored with item response theory on the standard scale (average 100, standard deviation 15). Server-timed, questions generated for each test. Not a clinical diagnosis.<br>Verify this certificate: <b>' . $e($verify) . '</b></div>
 <div class="qr">' . ($qr !== '' ? '<img src="' . $qr . '">' : '') . 'Scan to verify</div>
</div>
</div></div></div></body></html>';
    $dir = dirname(__DIR__) . '/tmp/iq-cert';
    @mkdir($dir, 0750, true);
    $in = $dir . '/' . $pid . '.html';
    $out = $dir . '/' . $pid . '.pdf';
    file_put_contents($in, $html);
    // open_basedir hides /usr/local/bin from is_file(), but exec() is not limited by it.
    $bin = PHP_OS_FAMILY === 'Darwin' ? '/opt/homebrew/bin/weasyprint' : '/usr/local/bin/weasyprint'; // local Mac vs the VPS
    exec('timeout 40 ' . $bin . ' ' . escapeshellarg($in) . ' ' . escapeshellarg($out) . ' 2>&1', $log, $rc);
    @unlink($in);
    if ($rc !== 0 || !is_file($out)) {
        error_log('[iq] certificate failed: ' . implode(' ', array_slice($log, -3)));
        http_response_code(500);
        echo 'Certificate could not be made. Try again.';
        return;
    }
    no_store();
    $file = 'Cardify-IQ-Certificate-' . preg_replace('/[^A-Za-z0-9]+/', '-', (string)$a['cert_no']) . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $file . '"');
    readfile($out);
    @unlink($out);
}

/** Public check an employer can open from the QR code or the number printed on the certificate. */
function page_verify(string $no): void
{
    no_store();
    $a = IqPay::byCertNo(strtoupper($no));
    layout_open(iq_s('verify_title'), '', ['robots' => 'noindex,nofollow']);
    echo '<section class="iqx-wrap iqx-narrow">';
    if (!$a) {
        echo '<article class="iqx-card iqx-center"><h1 class="iqx-h2">' . iq_e(iq_s('verify_title')) . '</h1><p class="iqx-note is-bad">'
            . iq_e(iq_s('verify_invalid')) . '</p></article></section>';
        layout_close();
        return;
    }
    $r = IqStore::result($a);
    $lang = $GLOBALS['LANG'];
    echo '<article class="iqx-card"><p class="iqx-note is-ok">' . iq_e(iq_s('verify_valid')) . '</p>'
        . '<h1 class="iqx-h2"><bdi>' . iq_e((string)$a['cert_name']) . '</bdi></h1>'
        . '<dl class="iqx-dl">'
        . '<dt>' . iq_e(iq_s('estimated')) . '</dt><dd class="iqx-num">' . iq_num((int)$a['iq']) . '</dd>'
        . '<dt>' . iq_e(iq_s('cert_class')) . '</dt><dd>' . iq_e($lang === 'ar' ? $r['band']['ar'] : $r['band']['en']) . '</dd>'
        . '<dt>' . iq_e(iq_s('cert_percentile')) . '</dt><dd>' . iq_e(iq_s('percentile', ['p' => iq_num($r['percentile'])])) . '</dd>'
        . '<dt>' . iq_e(iq_s('cert_taken')) . '</dt><dd>' . iq_e(date('j M Y', strtotime((string)$a['finished_at']))) . '</dd>'
        . '<dt>' . iq_e(iq_s('cert_number')) . '</dt><dd dir="ltr">' . iq_e((string)$a['cert_no']) . '</dd>'
        . '</dl><p class="iqx-muted iqx-small">' . iq_e(iq_s('disclaimer')) . '</p></article>'
        . '<p class="iqx-center"><a class="iqx-btn" href="' . iq_url('/test') . '">' . iq_e(iq_s('take_test')) . '</a></p></section>';
    layout_close();
}

/** The certificate offer (or the issued certificate) on the owner's result page. */
function cert_card(array $a, ?array $u, ?float $price): string
{
    $pid = $a['public_id'];
    if (!empty($a['cert_no'])) {
        return '<article class="iqx-card iqx-cert"><h2 class="iqx-h3">' . iq_e(iq_s('cert_issued')) . '</h2>'
            . '<p class="iqx-muted iqx-small" dir="ltr">' . iq_e((string)$a['cert_no']) . '</p><div class="iqx-actions">'
            . '<a class="iqx-btn" href="' . iq_url('/certificate/' . $pid . '.pdf') . '">' . iq_e(iq_s('download_cert')) . '</a>'
            . '<a class="iqx-btn iqx-btn-ghost" href="' . iq_e(cert_linkedin_url($a)) . '" target="_blank" rel="noopener">' . iq_e(iq_s('add_linkedin')) . '</a>'
            . '<a class="iqx-btn iqx-btn-ghost" href="' . iq_e(cert_verify_url($a)) . '">' . iq_e(iq_s('verify_cert')) . '</a></div></article>';
    }
    if ($price === null) return '';
    $h = '<article class="iqx-card iqx-cert" id="certificate"><h2 class="iqx-h3">' . iq_e(iq_s('cert_title')) . '</h2>'
        . '<p class="iqx-price">' . iq_e(price_label($price)) . ' <small>' . iq_e(iq_s('one_off')) . '</small></p><ul>';
    foreach ($GLOBALS['S']['cert_points'] as $p) $h .= '<li>' . iq_e($p) . '</li>';
    $h .= '</ul>';
    if (!$u) {
        return $h . '<a class="iqx-btn" href="' . iq_url('/account?next=' . rawurlencode('/result/' . $pid)) . '">' . iq_e(iq_s('cert_sign_in')) . '</a></article>';
    }
    return $h . '<form class="iqx-form" data-iq-cert="' . iq_e($pid) . '"><label>' . iq_e(iq_s('cert_name_label'))
        . '<input class="iqx-input" name="cert_name" maxlength="80" required value="' . iq_e($u['display_name'] ?: $a['name']) . '"></label>'
        . '<p class="iqx-muted iqx-small">' . iq_e(iq_s('cert_name_hint')) . '</p>'
        . '<button class="iqx-btn" type="submit">' . iq_e(iq_s('buy_cert')) . ' · ' . iq_e(price_label($price)) . '</button></form></article>';
}

function page_og(string $pid): void
{
    $a = IqStore::byPublicId($pid);
    $png = dirname(__DIR__) . '/assets/iq/og-iq.png';
    if (!$a || $a['status'] !== 'done' || !function_exists('imagecreatetruecolor')) {
        header('Content-Type: image/png');
        header('Cache-Control: public, max-age=3600');
        readfile($png);
        return;
    }
    $font = dirname(__DIR__) . '/assets/iq/Sora-Bold.ttf';
    $im = @imagecreatefrompng($png) ?: imagecreatetruecolor(1200, 630);
    $white = imagecolorallocate($im, 255, 255, 255);
    $gold = imagecolorallocate($im, 226, 179, 74);
    $soft = imagecolorallocate($im, 207, 227, 238);
    // Cover the generic ring and print the person's number on it.
    $bg = imagecolorallocate($im, 7, 31, 49);
    imagefilledellipse($im, 240, 310, 250, 250, $bg);
    if (is_file($font)) {
        $iq = (string)(int)$a['iq'];
        $box = imagettfbbox(96, 0, $font, $iq);
        $w = $box[2] - $box[0];
        imagettftext($im, 96, 0, (int)(240 - $w / 2), 345, $white, $font, $iq);
        $box = imagettfbbox(26, 0, $font, 'IQ');
        imagettftext($im, 26, 0, (int)(240 - ($box[2] - $box[0]) / 2), 395, $gold, $font, 'IQ');
        $name = mb_substr(preg_replace('/[^\p{L}\p{N} .\'-]/u', '', (string)$a['name']), 0, 28);
        if ($name !== '' && !preg_match('/\p{Arabic}/u', $name)) imagettftext($im, 34, 0, 470, 460, $soft, $font, $name);
    }
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=86400');
    imagepng($im);
    imagedestroy($im);
}

function page_leaderboard(): void
{
    global $S;
    $period = ($_GET['period'] ?? '') === 'month' ? 'month' : 'all';
    $cc = strtoupper((string)($_GET['country'] ?? ''));
    $cc = in_array($cc, IQ_COUNTRIES, true) ? $cc : null;
    header('Cache-Control: public, max-age=120');
    layout_open(iq_s('board_title') . ' · ' . iq_s('brand'), iq_s('board_lead'), ['canonical' => iq_abs('/leaderboard'), 'alt' => '/leaderboard']);
    echo subnav('/leaderboard', '/leaderboard');
    ?>
    <section class="iqx-wrap iqx-narrow">
        <h1 class="iqx-h1"><?= iq_e(iq_s('board_title')) ?></h1>
        <p class="iqx-muted"><?= iq_e(iq_s('board_lead')) ?></p>
        <form class="iqx-filters" method="get">
            <select name="period" aria-label="<?= iq_e(iq_s('board_title')) ?>">
                <option value="all"><?= iq_e(iq_s('board_all')) ?></option>
                <option value="month"<?= $period === 'month' ? ' selected' : '' ?>><?= iq_e(iq_s('board_month')) ?></option>
            </select>
            <select name="country" aria-label="<?= iq_e(iq_s('board_country')) ?>">
                <option value=""><?= iq_e(iq_s('board_all_countries')) ?></option>
                <?php foreach (IQ_COUNTRIES as $c): ?><option value="<?= $c ?>"<?= $cc === $c ? ' selected' : '' ?>><?= iq_e(iq_country_name($c)) ?></option><?php endforeach; ?>
            </select>
            <button class="iqx-btn iqx-btn-ghost" type="submit">OK</button>
        </form>
        <article class="iqx-card"><?= board_table(IqStore::leaderboard($period, $cc, 100)) ?></article>
        <p class="iqx-center"><a class="iqx-btn iqx-btn-lg" href="<?= iq_url('/test') ?>"><?= iq_e(iq_s('take_test')) ?></a></p>
    </section>
    <?php
    layout_close();
}

function page_account(): void
{
    no_store();
    $u = IqStore::user();
    $next = (string)($_GET['next'] ?? '');
    $next = preg_match('~^/[A-Za-z0-9/_-]*$~', $next) ? $next : '';
    layout_open(iq_s('account') . ' · ' . iq_s('brand'), '', ['robots' => 'noindex']);
    echo subnav('/account', '/account');
    echo '<section class="iqx-wrap iqx-narrow" id="iq-app">';
    if (!empty($_GET['payment'])) {
        echo '<p class="iqx-note ' . ($_GET['payment'] === 'success' ? 'is-ok' : 'is-bad') . '">' . iq_e($_GET['payment'] === 'success' ? iq_s('payment_success') : iq_s('payment_failed')) . '</p>';
    }
    if (!$u) {
        ?>
        <article class="iqx-card">
            <h1 class="iqx-h2"><?= iq_e(iq_s('sign_in')) ?></h1>
            <p class="iqx-muted"><?= iq_e(iq_s('sign_in_lead')) ?></p>
            <form id="iq-otp" class="iqx-form" data-next="<?= iq_e($next) ?>">
                <label><?= iq_e(iq_s('email_or_phone')) ?><input class="iqx-input" name="identifier" autocomplete="email" required dir="ltr"></label>
                <button class="iqx-btn" type="submit"><?= iq_e(iq_s('send_code')) ?></button>
                <div class="iqx-code" hidden>
                    <label><?= iq_e(iq_s('code')) ?><input class="iqx-input" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" dir="ltr"></label>
                    <button class="iqx-btn" type="button" data-iq-verify><?= iq_e(iq_s('verify')) ?></button>
                </div>
                <p class="iqx-msg" role="status"></p>
            </form>
        </article>
        <?php
        echo '</section>';
        layout_close(['page' => 'account']);
        return;
    }
    $db = Database::getInstance();
    $hist = $db->fetchAll("SELECT public_id, status, iq, started_at, report_paid FROM iq_attempts WHERE user_id = :u ORDER BY id DESC LIMIT 50", ['u' => $u['id']]);
    $place = IqStore::boardPlace($u['id']);
    $prices = IqPay::prices();
    $pro = IqStore::isPro($u);
    ?>
    <article class="iqx-card">
        <h1 class="iqx-h2"><?= iq_e(iq_s('profile')) ?></h1>
        <p class="iqx-muted" dir="ltr"><?= iq_e((string)($u['email'] ?: $u['phone'])) ?></p>
        <?php if ($place): ?>
            <p><?= $place['eligible'] ? iq_e(iq_s('board_place', ['rank' => iq_num($place['rank'])])) : iq_e(iq_s('board_not_eligible')) ?></p>
        <?php endif; ?>
        <form id="iq-profile" class="iqx-form">
            <label><?= iq_e(iq_s('display_name')) ?><input class="iqx-input" name="display_name" maxlength="60" value="<?= iq_e($u['display_name']) ?>"></label>
            <label><?= iq_e(iq_s('country')) ?>
                <select class="iqx-input" name="country"><option value=""></option>
                    <?php foreach (IQ_COUNTRIES as $c): ?><option value="<?= $c ?>"<?= $u['country'] === $c ? ' selected' : '' ?>><?= iq_e(iq_country_name($c)) ?></option><?php endforeach; ?>
                </select></label>
            <label><?= iq_e(iq_s('birth_year')) ?><input class="iqx-input" name="birth_year" inputmode="numeric" maxlength="4" dir="ltr" value="<?= iq_e((string)($u['birth_year'] ?? '')) ?>"></label>
            <label class="iqx-check"><input type="checkbox" name="leaderboard" <?= (int)$u['leaderboard'] === 1 ? 'checked' : '' ?>> <?= iq_e(iq_s('show_on_board')) ?></label>
            <button class="iqx-btn" type="submit"><?= iq_e(iq_s('save')) ?></button>
            <p class="iqx-msg" role="status"></p>
        </form>
    </article>
    <article class="iqx-card">
        <h2 class="iqx-h3"><?= iq_e(iq_s('pro_name')) ?></h2>
        <?php if ($pro): ?><p><?= iq_e(iq_s('pro_until', ['date' => date('j M Y', strtotime((string)$u['pro_until']))])) ?></p><?php endif; ?>
        <ul><?php foreach ($GLOBALS['S']['pro_points'] as $p): ?><li><?= iq_e($p) ?></li><?php endforeach; ?></ul>
        <?php if ($prices['pro_month'] !== null): ?>
            <button class="iqx-btn" type="button" data-iq-buy="pro_month"><?= iq_e($pro ? iq_s('renew_pro') : iq_s('buy_pro')) ?> · <?= iq_e(price_label($prices['pro_month'])) ?></button>
        <?php else: ?><p class="iqx-muted"><?= iq_e(iq_s('not_on_sale')) ?></p><?php endif; ?>
    </article>
    <article class="iqx-card">
        <h2 class="iqx-h3"><?= iq_e($pro ? iq_s('progress') : iq_s('history')) ?></h2>
        <?php if (!$hist): ?><p class="iqx-muted"><?= iq_e(iq_s('no_history')) ?></p><?php else: ?>
            <ul class="iqx-history">
                <?php foreach ($hist as $h): ?>
                    <li><a href="<?= iq_url('/result/' . $h['public_id']) ?>"><span><?= iq_e(date('j M Y', strtotime((string)$h['started_at']))) ?></span>
                        <span class="iqx-num"><?= $h['iq'] !== null ? 'IQ ' . iq_num((int)$h['iq']) : '…' ?></span>
                        <?php if ($pro && $h['iq'] !== null): ?><span class="iqx-bar"><i style="inline-size:<?= max(0, min(100, ((int)$h['iq'] - 55) / 0.9)) ?>%"></i></span><?php endif; ?></a></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </article>
    <p><button class="iqx-btn iqx-btn-ghost" type="button" data-iq-signout><?= iq_e(iq_s('sign_out')) ?></button></p>
    <?php
    echo '</section>';
    layout_close(['page' => 'account']);
}

function page_practice(): void
{
    no_store();
    $u = IqStore::user();
    layout_open(iq_s('practice') . ' · ' . iq_s('brand'), '', ['robots' => 'noindex']);
    echo subnav('/practice', '/practice');
    if (!IqStore::isPro($u)) {
        echo '<section class="iqx-wrap iqx-narrow"><article class="iqx-card iqx-center"><p>' . iq_e(iq_s('pro_only')) . '</p><a class="iqx-btn" href="' . iq_url('/account') . '">' . iq_e(iq_s('buy_pro')) . '</a></article></section>';
        layout_close();
        return;
    }
    echo '<section class="iqx-wrap iqx-test"><h1 class="iqx-h2">' . iq_e(iq_s('practice')) . '</h1><p class="iqx-muted">' . iq_e(iq_s('practice_lead')) . '</p><div id="iq-app"></div></section>';
    layout_close(['page' => 'practice']);
}

/* ---------- JSON API ---------- */

function api_out(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    no_store();
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function api_body(): array
{
    static $b = null;
    if ($b === null) $b = json_decode((string)file_get_contents('php://input'), true) ?: [];
    return $b;
}

function api_view(?array $a): array
{
    $out = ['attempt' => null, 'next_free' => IqStore::nextFreeStart()];
    if ($a) {
        $a = IqStore::settle($a);
        $out['attempt'] = ['id' => $a['public_id'], 'status' => $a['status'], 'count' => IQE_COUNT];
        if ($a['status'] === 'in_progress') $out['question'] = IqStore::serve($a);
        else $out['result_url'] = iq_url('/result/' . $a['public_id']);
    }
    return $out;
}

function api(string $action): void
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'POST' && !validateCSRFToken($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) api_out(['error' => 'csrf'], 403);
    $b = api_body();
    switch ($action) {
        case 'state':
            $a = IqStore::current();
            api_out(api_view($a && IqStore::owns($a) ? $a : null));
        case 'practice':
            $level = (int)($_GET['level'] ?? 1);
            if ($level > 1 && !IqStore::isPro(IqStore::user())) $level = 1;
            $seed = 'practice:' . bin2hex(random_bytes(6));
            $kinds = ['matrix', 'series', 'rotate', 'odd', 'logic'];
            $n = isset($_GET['n']) ? max(1, min(10, (int)$_GET['n'])) : 3;
            $qs = [];
            for ($i = 0; $i < $n; $i++) {
                $kind = $n === 3 && $level === 1 ? ['matrix', 'series', 'rotate'][$i] : $kinds[random_int(0, 4)];
                $lv = in_array($level, IQE_LEVELS[$kind], true) ? $level : max(IQE_LEVELS[$kind]);
                $q = iqe_question($seed, $i, $kind, $lv);
                unset($q['seconds']);
                $qs[] = $q;
            }
            api_out(['questions' => $qs]);
        case 'start':
            if ($method !== 'POST') api_out(['error' => 'method'], 405);
            $a = IqStore::current();
            if ($a && IqStore::owns($a) && $a['status'] === 'in_progress') api_out(api_view($a));
            if (IqStore::nextFreeStart() !== null) api_out(['error' => 'wait'], 409);
            $name = trim(preg_replace('/\s+/u', ' ', preg_replace('/[\p{C}<>]+/u', ' ', (string)($b['name'] ?? '')) ?? '') ?? '');
            if (mb_strlen($name) < 2 || mb_strlen($name) > 60) api_out(['error' => 'name'], 422);
            $age = $b['age'] ?? null;
            if (!is_int($age) || $age < IqStore::AGE_MIN || $age > IqStore::AGE_MAX) api_out(['error' => 'age'], 422);
            $cc = strtoupper((string)($b['country'] ?? ''));
            $cc = in_array($cc, IQ_COUNTRIES, true) ? $cc : null;
            $a = IqStore::start($name, $age, $cc, $GLOBALS['LANG']);
            api_out(api_view($a));
        case 'answer':
            if ($method !== 'POST') api_out(['error' => 'method'], 405);
            $a = IqStore::current();
            if (!$a || !IqStore::owns($a) || $a['status'] !== 'in_progress') api_out(['error' => 'not_running'], 409);
            $a = IqStore::settle($a);
            if ($a['status'] === 'in_progress') IqStore::answer($a, $b['index'] ?? null, $b['choice'] ?? null);
            api_out(api_view($a));
        case 'focus':
            $a = IqStore::current();
            if ($a && IqStore::owns($a)) IqStore::focusLost($a);
            api_out(['ok' => true]);
        case 'otp_send':
            $id = trim((string)($b['identifier'] ?? ''));
            [$ident, $channel] = iq_identifier($id);
            if ($ident === null) api_out(['error' => 'bad_identifier'], 422);
            require_once INCLUDES_DIR . '/WhatsApp.php';
            require_once INCLUDES_DIR . '/Mailer.php';
            require_once INCLUDES_DIR . '/RateLimiter.php';
            require_once INCLUDES_DIR . '/OtpService.php';
            $r = OtpService::send($ident, $channel, 'iq_login');
            if (!$r['ok']) api_out(['error' => $r['error']], 429);
            $_SESSION['iq_otp'] = ['identifier' => $ident, 'channel' => $channel];
            api_out(['ok' => true, 'channel' => $channel]);
        case 'otp_verify':
            $pending = $_SESSION['iq_otp'] ?? null;
            if (!$pending) api_out(['error' => 'expired_or_missing'], 409);
            require_once INCLUDES_DIR . '/OtpService.php';
            $r = OtpService::verify($pending['identifier'], (string)($b['code'] ?? ''), 'iq_login');
            if (!$r['ok']) api_out(['error' => $r['error']], 422);
            unset($_SESSION['iq_otp']);
            IqStore::signIn($pending['identifier'], $pending['channel']);
            api_out(['ok' => true]);
        case 'profile':
            $u = IqStore::user();
            if (!$u) api_out(['error' => 'sign_in'], 401);
            $name = trim(preg_replace('/[\p{C}<>]+/u', ' ', (string)($b['display_name'] ?? '')) ?? '');
            $cc = strtoupper((string)($b['country'] ?? ''));
            $by = (int)($b['birth_year'] ?? 0);
            Database::getInstance()->update('iq_users', [
                'display_name' => mb_substr($name, 0, 60),
                'country' => in_array($cc, IQ_COUNTRIES, true) ? $cc : null,
                'birth_year' => ($by >= 1920 && $by <= (int)date('Y') - 10) ? $by : null,
                'leaderboard' => !empty($b['leaderboard']) ? 1 : 0,
            ], 'id = :id', ['id' => $u['id']]);
            api_out(['ok' => true]);
        case 'logout':
            IqStore::signOut();
            api_out(['ok' => true]);
        case 'checkout':
            $u = IqStore::user();
            if (!$u) api_out(['error' => 'sign_in'], 401);
            $product = (string)($b['product'] ?? '');
            $att = null;
            if ($product === 'report' || $product === 'certificate') {
                $att = IqStore::byPublicId((string)($b['attempt'] ?? ''));
                if (!$att || $att['user_id'] !== $u['id']) api_out(['error' => 'no_result'], 409);
            }
            api_out(['url' => IqPay::checkout($u, $product, $att, ['name' => (string)($b['name'] ?? '')])]);
        default:
            api_out(['error' => 'unknown'], 404);
    }
}

/** An email, or a phone in international form. Returns [identifier, channel] or [null, null]. */
function iq_identifier(string $s): array
{
    if (filter_var($s, FILTER_VALIDATE_EMAIL)) return [strtolower($s), 'email'];
    $digits = preg_replace('/\D+/', '', $s);
    if (strlen($digits) === 8 && preg_match('/^[2789]/', $digits)) $digits = '968' . $digits; // Omani local number
    if (strlen($digits) >= 10 && strlen($digits) <= 15) return [$digits, 'whatsapp'];
    return [null, null];
}
