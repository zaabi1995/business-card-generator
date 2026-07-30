<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * Gate: every /ar/ URL in the ArTwins map must serve an ARABIC BODY.
 *
 * verify-ar-twins.php proves the map and the nginx rewrite table agree, which
 * is a statement about which URLs exist. It is silent on what they SAY. Two
 * URLs passed it while serving English prose under an Arabic header: /ar/blog
 * (Arabic chrome over 7,302 Latin characters of English post titles) and
 * /ar/get-started (an entirely English landing body). Both were a second
 * English URL wearing a translated navbar, which is a duplicate, not a
 * translation.
 *
 * The instrument that catches them is the Arabic SHARE of the page's letters,
 * never a raw Arabic count. Chrome alone contributes ~663 Arabic characters to
 * any Cardify page, so any count-based floor is satisfied by the header and
 * says nothing about the body. Measured live: real twins 0.73-0.96, the two
 * impostors 0.13 and 0.24. The threshold below sits in the empty band between
 * those two populations.
 *
 * Usage:
 *   php tools/verify-ar-body.php            # gate every mapped /ar/ URL
 *   php tools/verify-ar-body.php --selftest # prove the gate can fail
 *
 * Exit 0 = every Arabic twin is genuinely in Arabic. Exit 1 = at least one is
 * not, or the gate could not measure, which is also a failure.
 */

require_once __DIR__ . '/../includes/ArTwins.php';

const MIN_ARABIC_SHARE = 0.55;

/** Arabic share of the letters in a rendered HTML body, script/style stripped. */
function arabicShare(string $html): array
{
    $body = preg_replace('#<(script|style|noscript)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
    $body = preg_replace('#<[^>]+>#s', ' ', $body) ?? $body;
    $body = html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    // Arabic block + Arabic Supplement + Extended-A + presentation forms.
    $ar = preg_match_all('/[\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{08A0}-\x{08FF}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]/u', $body);
    $la = preg_match_all('/[A-Za-z]/u', $body);
    $tot = $ar + $la;
    return ['ar' => $ar, 'la' => $la, 'share' => $tot > 0 ? $ar / $tot : 0.0];
}

/**
 * r6-74: an Arabic BODY says nothing about the Arabic HEAD. cardify.om/ar/
 * passed this gate for weeks while serving an English <title>, an English
 * meta description and an English og:title, which are the three highest-weight
 * fields a search engine and a language model both read first. Measure each
 * head field on its own: a single field is far too short for a share floor to
 * be meaningful, so the test is "does this field contain any Arabic at all",
 * which is exactly the failure that shipped.
 *
 * Returns [fieldName => ['text' => ..., 'ar' => n, 'la' => n]] for the fields
 * that are present. A missing title is itself a failure and is reported as
 * such by the caller.
 */
function headShare(string $html): array
{
    $out = [];
    $grabs = [
        'title'          => '#<title[^>]*>(.*?)</title>#is',
        'description'    => '#<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']*)#is',
        'og:title'       => '#<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']*)#is',
        'og:description' => '#<meta[^>]+property=["\']og:description["\'][^>]+content=["\']([^"\']*)#is',
        'h1'             => '#<h1[^>]*>(.*?)</h1>#is',
    ];
    foreach ($grabs as $name => $re) {
        if (!preg_match($re, $html, $m)) continue;
        $txt = html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $txt = trim(preg_replace('/\s+/u', ' ', $txt) ?? $txt);
        if ($txt === '') continue;
        $out[$name] = [
            'text' => $txt,
            'ar'   => preg_match_all('/[\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{08A0}-\x{08FF}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]/u', $txt),
            'la'   => preg_match_all('/[A-Za-z]/u', $txt),
        ];
    }
    return $out;
}

/**
 * Head-field verdict for one /ar/ URL: the list of fields that carry Latin
 * letters and no Arabic at all, plus a flag for a missing title.
 */
function headFailures(string $html): array
{
    $f = headShare($html);
    $bad = [];
    if (!isset($f['title'])) $bad[] = 'title (absent)';
    foreach ($f as $name => $m) {
        if ($m['ar'] === 0 && $m['la'] > 0) {
            $bad[] = $name . ' ("' . mb_substr($m['text'], 0, 48) . '")';
        }
    }
    return $bad;
}

function fetch(string $url): ?string
{
    // Unique query string so Cloudflare cannot answer from cache: the gate
    // must measure what the origin renders today, not what it rendered when
    // the edge last filled. nginx matches on path, so the rewrite still fires.
    $bust = $url . (strpos($url, '?') === false ? '?' : '&') . '_gate=' . bin2hex(random_bytes(6));
    $ch = curl_init($bust);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_USERAGENT      => 'cardify-ar-body-gate/1.0',
        CURLOPT_HTTPHEADER     => ['Cache-Control: no-cache', 'Pragma: no-cache'],
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($code !== 200 || !is_string($body) || $body === '') {
        echo "  HTTP {$code} (expected 200)\n";
        return null;
    }
    return $body;
}

// --- Self-test: a gate that cannot fail is not a gate ---------------------
if (in_array('--selftest', $argv, true)) {
    $fixtures = [
        // [label, html, must the gate reject it?]
        ['English body under Arabic chrome (the /ar/get-started shape)',
         '<html><body><nav>الرئيسية التسعير تواصل معنا الشركات المدونة</nav>'
         . '<h1>Get started with Cardify</h1><p>' . str_repeat('Create your first digital business card in under two minutes. ', 40)
         . '</p></body></html>', true],
        ['English post titles under Arabic chrome (the /ar/blog shape)',
         '<html><body><nav>الرئيسية التسعير تواصل معنا الشركات المدونة</nav>'
         . str_repeat('<a>How NFC business cards work in Oman</a> ', 60) . '</body></html>', true],
        ['A genuinely translated page',
         '<html><body><nav>الرئيسية التسعير</nav><h1>عن كارديفاي</h1><p>'
         . str_repeat('كارديفاي منصّة بطاقات أعمال رقمية ومطبوعة مقرّها مسقط في سلطنة عُمان. ', 30)
         . '</p></body></html>', false],
        ['Arabic chrome alone with an empty body (must not pass on chrome)',
         '<html><body><nav>الرئيسية التسعير تواصل معنا الشركات المدونة</nav>'
         . str_repeat('<a>Pricing</a> <a>Companies</a> <a>Blog</a> <a>Contact</a> <a>About</a> ', 25)
         . '</body></html>', true],
    ];
    $bad = 0;
    foreach ($fixtures as [$label, $html, $mustReject]) {
        $m = arabicShare($html);
        $rejected = $m['share'] < MIN_ARABIC_SHARE;
        $ok = ($rejected === $mustReject);
        printf("  [%s] %-58s share=%.3f (ar=%d la=%d) rejected=%s expected=%s\n",
            $ok ? 'ok' : 'BAD', substr($label, 0, 58), $m['share'], $m['ar'], $m['la'],
            $rejected ? 'yes' : 'no', $mustReject ? 'yes' : 'no');
        if (!$ok) $bad++;
    }
    // --- head-field arms (r6-74) -------------------------------------------
    $headFixtures = [
        ['English title + English meta on an Arabic body (the shipped /ar/ shape)',
         '<html><head><title>Cardify, Digital &amp; Printed Business Cards for the GCC</title>'
         . '<meta name="description" content="Bilingual digital and printed business cards.">'
         . '</head><body><h1>عن كارديفاي</h1></body></html>',
         ['title', 'description']],
        ['English og:title only',
         '<html><head><title>كارديفاي، بطاقات أعمال</title>'
         . '<meta property="og:title" content="A powerful dashboard at your fingertips">'
         . '</head><body></body></html>',
         ['og:title']],
        ['Fully Arabic head (must pass)',
         '<html><head><title>كارديفاي، بطاقات أعمال رقمية</title>'
         . '<meta name="description" content="بطاقات أعمال رقمية ومطبوعة لفرق العمل.">'
         . '<meta property="og:title" content="كارديفاي">'
         . '</head><body><h1>لوحة تحكم متكاملة بين يديك</h1></body></html>',
         []],
        ['Bilingual title with an Arabic segment (must pass, this is the live shape)',
         '<html><head><title>كارديفاي، بطاقات أعمال رقمية ومطبوعة | Cardify</title>'
         . '</head><body></body></html>',
         []],
        ['No title at all (must fail: a missing field is not a passing field)',
         '<html><head><meta name="description" content="وصف عربي سليم"></head><body></body></html>',
         ['title (absent)']],
    ];
    foreach ($headFixtures as [$label, $html, $expect]) {
        $got = headFailures($html);
        $gotNames = array_map(function ($x) { return explode(' (', $x)[0]; }, $got);
        $expNames = array_map(function ($x) { return explode(' (', $x)[0]; }, $expect);
        sort($gotNames); sort($expNames);
        $ok = $gotNames === $expNames;
        printf("  [%s] %-58s head-failures=%s expected=%s\n",
            $ok ? 'ok' : 'BAD', substr($label, 0, 58),
            $gotNames ? implode(',', $gotNames) : 'none',
            $expNames ? implode(',', $expNames) : 'none');
        if (!$ok) $bad++;
    }

    echo $bad === 0
        ? "\nSELFTEST PASS: body arms and head arms all graded correctly.\n"
        : "\nSELFTEST FAILED: {$bad} fixture(s) graded wrong. Do not trust this gate.\n";
    exit($bad === 0 ? 0 : 1);
}

// --- Live gate -------------------------------------------------------------
$paths = ArTwins::paths();
if (!$paths) {
    echo "FAIL: ArTwins map is empty, this gate would pass on nothing.\n";
    exit(1);
}

$fail = [];
$headFail = [];
$rows = [];
foreach ($paths as $p) {
    $url = ArTwins::ar($p);
    echo "checking {$url}\n";
    $html = fetch($url);
    if ($html === null) { $fail[] = [$url, null]; continue; }
    $m = arabicShare($html);
    $hb = headFailures($html);
    $rows[] = [$url, $m, $hb];
    if ($m['share'] < MIN_ARABIC_SHARE) $fail[] = [$url, $m];
    if ($hb) { $headFail[] = [$url, $hb]; }
}

// --- Sampled population: the parameterised /ar/companies/{slug} route ------
// ArTwins::PATHS holds ~23 literal URLs. The company tree is 2,502 URLs that
// no literal map can enumerate, and it was exactly this population that served
// an English body under an Arabic shell (ledger bhd-r6-45). The gate now draws
// a random sample of it on every run, so the population it guards is the
// population at risk.
$sampleN = 12;
foreach ($argv as $a) {
    if (strpos($a, '--sample=') === 0) $sampleN = max(0, (int) substr($a, 9));
}
if ($sampleN > 0) {
    require_once __DIR__ . '/../config.php';
    $db = Database::getInstance();
    $slugs = $db->fetchAll(
        "SELECT slug FROM om_companies WHERE slug IS NOT NULL AND slug <> '' ORDER BY RAND() LIMIT ?",
        [$sampleN]
    );
    if (!$slugs) {
        echo "FAIL: could not sample om_companies, the company tree would go unchecked.\n";
        exit(1);
    }
    foreach ($slugs as $r) {
        $url = 'https://cardify.om/ar/companies/' . $r['slug'];
        echo "checking {$url}\n";
        $html = fetch($url);
        if ($html === null) { $fail[] = [$url, null]; continue; }
        $m = arabicShare($html);
        $hb = headFailures($html);
        $rows[] = [$url, $m, $hb];
        if ($m['share'] < MIN_ARABIC_SHARE) $fail[] = [$url, $m];
        if ($hb) { $headFail[] = [$url, $hb]; }
    }
}

echo "\n" . str_pad('URL', 46) . "  share    ar     latin\n";
foreach ($rows as [$url, $m, $hb]) {
    printf("%-46s  %.3f  %6d  %6d%s\n", $url, $m['share'], $m['ar'], $m['la'],
        $m['share'] < MIN_ARABIC_SHARE ? '   <-- ENGLISH BODY' : ($hb ? '   <-- ENGLISH HEAD' : ''));
}

if ($headFail) {
    echo "\nHEAD FIELDS IN ENGLISH on " . count($headFail) . " /ar/ URL(s):\n";
    foreach ($headFail as [$url, $bad]) {
        echo "  {$url}\n";
        foreach ($bad as $b) echo "      {$b}\n";
    }
}

if ($fail || $headFail) {
    echo "\nGATE FAILED: " . count($fail) . " /ar/ URL(s) below the "
       . MIN_ARABIC_SHARE . " Arabic-share floor, or unfetchable.\n";
    echo "An /ar/ URL that serves English body copy is a duplicate of its English\n"
       . "twin. Either translate the body, or 301 it to the canonical and drop it\n"
       . "from ArTwins::PATHS, the nginx rewrite table and the sitemaps.\n";
    exit(1);
}

echo "\nPASS: all " . count($rows) . " Arabic twins serve an Arabic body "
   . "(floor " . MIN_ARABIC_SHARE . ") AND an Arabic title, meta description, "
   . "og:title, og:description and h1 wherever those fields are present.\n";
exit(0);
