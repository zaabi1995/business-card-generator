<?php
/**
 * Website discovery for logo-less om_companies. For each company we
 * derive candidate domains from the name, fetch the homepage, and only
 * accept a domain when its <title>/og:site_name actually contains a
 * significant word from the company name (the name-match guard). This
 * avoids attributing a stranger's favicon to the wrong company.
 *
 * On accept we set website + website_domain_cache; the existing
 * crawl-logos.php then fetches the actual logo on its next run.
 *
 * DRY-RUN by default (reports what it WOULD set). Pass --apply to write.
 *
 *   php discover-websites.php --limit=40                 # dry run, 40 rows
 *   php discover-websites.php --limit=40 --apply         # write matches
 *   php discover-websites.php --limit=40 --curated       # curated rows only
 *   php discover-websites.php --id=467                   # one company
 */
require_once __DIR__ . '/../config.php';

const UA = 'CardifyLogoCrawler/1.0 (+https://cardify.om/logos/press)';
const TIMEOUT = 8;
const SLEEP_SEC = 1;

// Single generic words that are almost always a stranger's domain when
// guessed as <word>.com. Reject these bases outright.
const GENERIC_BASES = [
    'advanced','africa','modern','united','global','national','badi','adhi',
    'anwar','aqmar','athnain','ahlia','ahmadi','barakah','baraka','arkan',
    'first','best','prime','royal','star','sun','gulf','east','west','north',
    'south','new','top','smart','green','blue','gold','silver','power','energy',
    'trading','general','oman','muscat','middle','east','grand','city','metro',
];

$opts = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z\-]+)(?:=(.*))?$/i', $a, $m)) $opts[$m[1]] = $m[2] ?? true;
}
$limit   = max(1, min(500, (int) ($opts['limit'] ?? 40)));
$apply   = !empty($opts['apply']);
$curated = !empty($opts['curated']);
$onlyId  = isset($opts['id']) ? (int) $opts['id'] : null;

$db = Database::getInstance();

// Legal suffixes + filler words to strip when deriving name tokens.
$STOP = [
    'LLC','L.L.C','L.L.C.','SAOC','SAOG','S.A.O.C','S.A.O.G','S.A.O.C.','S.A.O.G.',
    'CO','CO.','COMPANY','COMP','EST','EST.','ESTABLISHMENT','TRADING','TRAD','TRAD.',
    'GROUP','HOLDING','INTERNATIONAL','INTL','BRANCH','OMAN','OMANI','THE','AND','FOR',
    'OF','LTD','LIMITED','ENTERPRISES','ENTERPRISE','SERVICES','SERVICE','GENERAL',
    'NATIONAL','MODERN','UNITED','GLOBAL','AL','BIN','&','WLL','W.L.L','PROJECTS',
    'CONTRACTING','INVESTMENT','INDUSTRIES','INDUSTRY','FACTORY',
];

if ($onlyId) {
    $rows = $db->fetchAll(
        "SELECT id, slug, name_en FROM om_companies WHERE id = :id", [':id' => $onlyId]
    );
} else {
    // Exclude already-probed rows so the cron marches forward instead of
    // re-probing the same non-matching head every night.
    $where = "logo_status = 'none' AND (website_domain_cache IS NULL OR website_domain_cache = '')"
           . " AND logo_discovery_attempted_at IS NULL";
    if ($curated) $where .= " AND curated = 1";
    $rows = $db->fetchAll(
        "SELECT id, slug, name_en FROM om_companies
         WHERE $where
         ORDER BY (size_bucket='large') DESC, curated DESC, name_en ASC
         LIMIT $limit"
    );
}

$considered = $matched = $skipped = 0;
$seenDomains = []; // within-run de-dupe: a domain matching 2+ companies is ambiguous
fwrite(STDOUT, "discover_start\tmode=" . ($apply ? 'APPLY' : 'DRY') . "\trows=" . count($rows) . "\n");

foreach ($rows as $r) {
    $considered++;
    // Stamp attempted-at up front (apply mode) so every probed row drops
    // out of future runs whether or not it matches.
    if ($apply) {
        $db->getConnection()
            ->prepare("UPDATE om_companies SET logo_discovery_attempted_at = NOW() WHERE id = :id")
            ->execute([':id' => $r['id']]);
    }
    $tokens = nameTokens($r['name_en'], $STOP);
    if (empty($tokens)) {
        $skipped++;
        fwrite(STDOUT, "skip\tid={$r['id']}\tname=" . trim($r['name_en']) . "\treason=no_tokens\n");
        continue;
    }

    $candidates = candidateDomains($tokens);
    $hit = null;
    foreach ($candidates as $domain) {
        // Reject generic single-word bases outright (advanced.com, africa.com,
        // badi.com) - these are almost always someone else's domain.
        $base = explode('.', $domain)[0];
        if (in_array($base, GENERIC_BASES, true)) continue;

        $page = httpGet("https://$domain/");
        if ($page === null) $page = httpGet("http://$domain/");
        if ($page === null) continue;

        $title = pageTitle($page) . ' ' . pageMeta($page, 'og:site_name');
        // Two-signal guard: (1) a >=5-char distinctive token in the TITLE,
        // AND (2) corroboration in the page body - either "OMAN" or a SECOND
        // company token. A generic domain that happens to contain one word
        // in its title won't also mention Oman or a second distinctive word.
        if (nameMatchesStrict($title, $page, $tokens)) {
            $hit = $domain;
            break;
        }
        usleep(200000);
    }

    if (!$hit) {
        $skipped++;
        fwrite(STDOUT, "nomatch\tid={$r['id']}\tname=" . trim($r['name_en'])
            . "\ttried=" . implode(',', array_slice($candidates, 0, 4)) . "\n");
        sleep(SLEEP_SEC);
        continue;
    }

    // Within-run de-dupe: if this domain already matched another company,
    // both are ambiguous - skip and flag.
    if (isset($seenDomains[$hit])) {
        $skipped++;
        fwrite(STDOUT, "dupe\tid={$r['id']}\tname=" . trim($r['name_en'])
            . "\tdomain=$hit\talready_matched_id={$seenDomains[$hit]}\n");
        sleep(SLEEP_SEC);
        continue;
    }
    $seenDomains[$hit] = $r['id'];

    $matched++;
    fwrite(STDOUT, "MATCH\tid={$r['id']}\tname=" . trim($r['name_en']) . "\tdomain=$hit\n");
    if ($apply) {
        $db->getConnection()->prepare(
            "UPDATE om_companies SET website = :w, website_domain_cache = :d WHERE id = :id"
        )->execute([':w' => "https://$hit", ':d' => $hit, ':id' => $r['id']]);
    }
    sleep(SLEEP_SEC);
}

fwrite(STDOUT, sprintf(
    "discover_done\tconsidered=%d\tmatched=%d\tskipped=%d\tmode=%s\n",
    $considered, $matched, $skipped, $apply ? 'APPLY' : 'DRY'
));

// === helpers ===

function nameTokens(string $name, array $stop): array {
    $name = strtoupper($name);
    $name = preg_replace('/[^A-Z0-9 ]+/', ' ', $name); // drop punctuation
    $parts = preg_split('/\s+/', trim($name));
    $stopSet = array_flip($stop);
    $tokens = [];
    foreach ($parts as $p) {
        if ($p === '' || isset($stopSet[$p])) continue;
        if (strlen($p) < 3) continue;
        $tokens[] = $p;
    }
    return $tokens;
}

function candidateDomains(array $tokens): array {
    $joined  = strtolower(implode('', $tokens));
    $first   = strtolower($tokens[0]);
    $first2  = strtolower(implode('', array_slice($tokens, 0, 2)));
    $hyphen  = strtolower(implode('-', array_slice($tokens, 0, 3)));
    $bases = array_values(array_unique(array_filter([
        $first2, $joined, $first, $hyphen,
    ], fn($b) => strlen($b) >= 3 && strlen($b) <= 30)));
    $out = [];
    foreach ($bases as $b) {
        $out[] = "$b.om";
        $out[] = "$b.com";
        $out[] = "$b.co.om";
    }
    return array_slice(array_unique($out), 0, 12);
}

/**
 * Two-signal acceptance:
 *   (1) a >=5-char company token appears in the page TITLE, AND
 *   (2) corroboration in the page BODY: the literal "OMAN", or a SECOND
 *       distinct >=4-char company token.
 * A generic domain whose title coincidentally contains one company word
 * won't also mention Oman or a second distinctive word, so this rejects
 * the advanced.com / africa.com / badi.com class of false positives.
 */
function nameMatchesStrict(string $title, string $page, array $tokens): bool {
    $t = strtoupper($title);
    $b = strtoupper($page);
    if (trim($t) === '') return false;

    $titleHit = null;
    foreach ($tokens as $tok) {
        if (strlen($tok) >= 5 && strpos($t, $tok) !== false) { $titleHit = $tok; break; }
    }
    if ($titleHit === null) return false;

    // Corroboration
    if (strpos($b, 'OMAN') !== false) return true;
    foreach ($tokens as $tok) {
        if ($tok === $titleHit) continue;
        if (strlen($tok) >= 4 && strpos($b, $tok) !== false) return true;
    }
    return false;
}

function httpGet(string $url): ?string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 4,
        CURLOPT_TIMEOUT        => TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_USERAGENT      => UA,
        CURLOPT_SSL_VERIFYPEER => false, // discovery only, we just read the title
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_NOBODY         => false,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code < 200 || $code >= 400 || !is_string($body) || $body === '') return null;
    return substr($body, 0, 60000); // head of the doc is enough for title/meta
}

function pageTitle(string $html): string {
    if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
        return html_entity_decode(trim($m[1]), ENT_QUOTES);
    }
    return '';
}

function pageMeta(string $html, string $property): string {
    if (preg_match('/<meta[^>]*property=["\']' . preg_quote($property, '/') . '["\'][^>]*content=["\']([^"\']+)["\']/i', $html, $m)) {
        return html_entity_decode($m[1], ENT_QUOTES);
    }
    return '';
}
