<?php
/**
 * Cardify SEO helpers (Category R actions 423-429).
 *
 * Thin wrappers that emit JSON-LD blocks + hreflang link tags. Pages
 * drop a single call into <head> (hreflang) or <body> (JSON-LD) instead
 * of copy-pasting 40 lines of script plumbing on every page.
 *
 *   Seo::hreflang('/companies/xyz');           // bilingual page
 *   Seo::organization();                        // once per page
 *   Seo::breadcrumbs([['Home','/'], ['Tools','/tools']]);
 *   Seo::faqPage([['Question?', 'Answer.']]);
 *   Seo::article($title, $desc, $url, $image, $published, $modified, $author);
 *
 * All helpers echo a full <script type="application/ld+json"> block and
 * are safe to call multiple times on the same page (Google accepts a
 * stack of JSON-LD scripts; each describes one thing).
 */
class Seo
{
    public const SITE = 'https://cardify.om';
    public const BRAND = 'Cardify';
    public const PUBLISHER_LOGO = 'https://cardify.om/assets/images/logo.svg';

    /**
     * r6-50: the group's disambiguation, word for word as bhd.om publishes it.
     *
     * "BHD" resolves to the Bahraini dinar before it resolves to a company, and
     * "Bin Haider Darwish" is one letter of context from Mohsin Haider Darwish
     * LLC, an unrelated Omani group that a model returned when asked the Arabic
     * entity question. bhd.om/_nap.py is the source of these three constants;
     * they are copied rather than fetched because a build-time HTTP call to a
     * sibling property is a worse dependency than a divergence a gate catches.
     * seo_gate.py asserts the strings match across properties.
     */
    public const GROUP_ALTERNATE_NAMES = [
        'BHD Group',
        'BHD',
        'Bin Haider Darwish',
        'Bin Haider Darwish LLC',
        'بن حيدر درويش',
        'مجموعة BHD',
        'شركة بن حيدر درويش ش.م.م',
    ];

    public const GROUP_DISAMBIGUATION =
        'BHD Group is Bin Haider Darwish L.L.C., Commercial Registration 1334733, '
        . 'a family-owned printing and technology group founded in Muscat in 2018. '
        . 'It is not Mohsin Haider Darwish LLC (a separate and unrelated Omani '
        . 'company), and the initials BHD here stand for Bin Haider Darwish, not the '
        . 'Bahraini dinar currency code.';

    public const GROUP_DISAMBIGUATION_AR =
        'مجموعة BHD هي شركة بن حيدر درويش ش.م.م، السجل التجاري 1334733، مجموعة '
        . 'عمانية عائلية للطباعة والتقنية تأسست في مسقط عام 2018. وهي ليست شركة محسن '
        . 'حيدر درويش ش.م.م (شركة عمانية منفصلة لا علاقة لها بنا)، والحروف BHD هنا '
        . 'اختصار لـ Bin Haider Darwish وليست رمز الدينار البحريني.';

    /** The disambiguation in the language the page is actually rendered in. */
    public static function groupDisambiguation(): string
    {
        return (function_exists('currentLocale') && currentLocale() === 'ar')
            ? self::GROUP_DISAMBIGUATION_AR
            : self::GROUP_DISAMBIGUATION;
    }

    /**
     * Emit canonical + hreflang for a path. Alternates come from the ArTwins
     * map rather than from concatenating '/ar' onto the path: the old version
     * asserted an Arabic twin for EVERY caller, including paths whose /ar/ URL
     * 404s, which is an hreflang aimed at nothing.
     */
    public static function hreflang(string $path): void
    {
        require_once __DIR__ . '/ArTwins.php';
        $current = (($_SERVER['REQUEST_SCHEME'] ?? 'https') . '://' . ($_SERVER['HTTP_HOST'] ?? 'cardify.om') . ($_SERVER['REQUEST_URI'] ?? $path));

        echo '<link rel="canonical" href="' . htmlspecialchars($current, ENT_QUOTES) . "\">\n";
        foreach (ArTwins::tags($path) as [$hrefLang, $hrefUrl]) {
            echo '<link rel="alternate" hreflang="' . $hrefLang . '" href="' . htmlspecialchars($hrefUrl, ENT_QUOTES) . "\">\n";
        }
    }

    /** Publisher/brand once per page. */
    public static function organization(): void
    {
        self::emit([
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            '@id' => self::SITE . '/#organization',
            'name' => self::BRAND,
            'url' => self::SITE,
            // Ownership was one-way: bhd.om named Cardify a subOrganization,
            // Cardify named no parent, so the edge did not resolve from here.
            'parentOrganization' => ['@id' => 'https://bhd.om/#organization'],
            // r6-50: the group-level collision (Bahraini dinar, Mohsin Haider
            // Darwish LLC) reaches every property that names BHD, and until now
            // only bhd.om answered it. Stated on Cardify's own node rather than
            // by inlining a second BHD Group node, which r6-59 already ruled out.
            // Same two alternates index.php's live node publishes. Seo::organization()
            // currently has no caller (index.php hand-writes the node); keeping the
            // two in step means activating this one cannot silently change the entity.
            'alternateName' => ['Cardify Oman', 'Cardify GCC'],
            'disambiguatingDescription' =>
                'Cardify is the digital business card platform published by BHD Group '
                . '(Bin Haider Darwish L.L.C.) in Muscat, Oman. ' . self::GROUP_DISAMBIGUATION,
            // r20-17: NO identifier / vatID here, deliberately. CR 1334733 and
            // VATIN OM1100019343 register the LEGAL ENTITY Bin Haider Darwish
            // L.L.C., which the estate defines once at
            // https://bhd.om/#organization. Asserting them again on this @id
            // (and on bhdoman.com/#business) made one registration number the
            // key of three different nodes, which a resolver must read either as
            // three companies sharing a registration or as an instruction to
            // merge three entities. Cardify is a brand of that entity, so it
            // carries parentOrganization and nothing else. index.php's live
            // node already publishes this shape; this dormant helper is kept in
            // step so activating it can never reintroduce the collision.
            'logo' => self::PUBLISHER_LOGO,
            // Live-probed 29 Jul 2026 (redirects followed, desktop UA):
            //   https://instagram.com/cardifyom              -> 200
            //   https://twitter.com/cardify_om               -> 404
            //   https://www.linkedin.com/company/cardify-om  -> 404
            // sameAs is a checkable identity assertion, so a 404 in it is
            // worse than an omission. Only the surviving profile ships.
            'sameAs' => ['https://instagram.com/cardifyom'],
            'contactPoint' => [
                '@type' => 'ContactPoint',
                'contactType' => 'customer support',
                'email' => 'hello@cardify.om',
                'areaServed' => ['OM', 'GCC'],
                'availableLanguage' => ['English', 'Arabic'],
            ],
        ]);
    }

    /**
     * @param array $crumbs List of [label, url] pairs, root-first. URLs can be absolute or relative.
     */
    public static function breadcrumbs(array $crumbs): void
    {
        $items = [];
        $i = 1;
        foreach ($crumbs as $c) {
            [$label, $url] = $c;
            if (strpos($url, 'http') !== 0) $url = self::SITE . '/' . ltrim($url, '/');
            $items[] = [
                '@type' => 'ListItem',
                'position' => $i++,
                'name' => $label,
                'item' => $url,
            ];
        }
        self::emit([
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => $items,
        ]);
    }

    /**
     * @param array $qa List of [question, answer] pairs.
     */
    public static function faqPage(array $qa): void
    {
        $entries = [];
        foreach ($qa as $row) {
            [$q, $a] = $row;
            $entries[] = [
                '@type' => 'Question',
                'name' => $q,
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $a],
            ];
        }
        self::emit([
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => $entries,
        ]);
    }

    public static function article(
        string $title,
        string $description,
        string $url,
        ?string $imageUrl,
        ?string $datePublished,
        ?string $dateModified = null,
        ?string $authorName = null
    ): void {
        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'Article',
            'headline' => $title,
            'description' => $description,
            'url' => $url,
            'publisher' => [
                '@type' => 'Organization',
                'name' => self::BRAND,
                'logo' => [
                    '@type' => 'ImageObject',
                    'url' => self::PUBLISHER_LOGO,
                    'creditText' => self::BRAND,
                    'copyrightNotice' => '© ' . date('Y') . ' ' . self::BRAND,
                    'license' => self::SITE . '/terms',
                ],
            ],
        ];
        if ($imageUrl) {
            $data['image'] = [
                '@type' => 'ImageObject',
                'url' => $imageUrl,
                'creditText' => self::BRAND,
                'copyrightNotice' => '© ' . date('Y') . ' ' . self::BRAND,
                'license' => self::SITE . '/terms',
            ];
        }
        if ($datePublished) $data['datePublished'] = $datePublished;
        if ($dateModified)  $data['dateModified']  = $dateModified;
        if ($authorName) {
            $data['author'] = ['@type' => 'Person', 'name' => $authorName];
        }
        self::emit($data);
    }

    /**
     * The canonical publisher node. Every Article, NewsArticle and BlogPosting
     * on the estate must reference this shape.
     *
     * r6-88: 57 publisher nodes carried a name and a logo but no url, so none of
     * them resolved to the entity they were publishing on behalf of. url and @id
     * are not optional here.
     */
    public static function publisherNode(): array
    {
        return [
            '@type' => 'Organization',
            '@id' => self::SITE . '/#organization',
            'name' => self::BRAND,
            'url' => self::SITE,
            'logo' => [
                '@type' => 'ImageObject',
                'url' => self::PUBLISHER_LOGO,
                'creditText' => self::BRAND,
                'copyrightNotice' => '© ' . date('Y') . ' ' . self::BRAND,
                'license' => self::SITE . '/terms',
            ],
        ];
    }

    /**
     * Build the Article node for a standalone content page (/solutions/*).
     *
     * r6-88: these 20 pages each hand-rolled an Article with no datePublished,
     * no image and a publisher with no url. This is the one place that shape is
     * defined. $sourceFile is the page's own __FILE__: datePublished comes from
     * the recorded git-add date for it, dateModified from its mtime, so a page
     * cannot be edited into staleness (r6-95).
     *
     * Returns the node rather than echoing it, because these pages assemble
     * $extraHead as a string before the header is included.
     */
    public static function articleNode(
        string $sourceFile,
        string $title,
        string $description,
        string $url,
        ?string $imageUrl = null,
        string $inLanguage = 'en-OM'
    ): array {
        $node = [
            '@context' => 'https://schema.org',
            '@type' => 'Article',
            'headline' => $title,
            'description' => $description,
            'url' => $url,
            'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $url],
            // A REFERENCE, not a second definition. Author and publisher are the
            // same entity here, and spelling the payload out twice would define
            // #organization twice on one page with two different bodies, which
            // is the r6-56 defect wearing a different hat.
            'author' => ['@id' => self::SITE . '/#organization'],
            'publisher' => self::publisherNode(),
            'image' => [
                '@type' => 'ImageObject',
                'url' => $imageUrl ?: self::SITE . '/assets/images/cardify-og.png',
                'creditText' => self::BRAND,
                'copyrightNotice' => '© ' . date('Y') . ' ' . self::BRAND,
                'license' => self::SITE . '/terms',
            ],
            'inLanguage' => $inLanguage,
        ];
        $published = self::publishedDate($sourceFile);
        if ($published) $node['datePublished'] = $published;
        $mtime = @filemtime($sourceFile);
        if ($mtime) $node['dateModified'] = date('Y-m-d', $mtime);
        return $node;
    }

    /** Recorded git-add date for a page, or null when the page is not listed. */
    public static function publishedDate(string $sourceFile): ?string
    {
        static $map = null;
        if ($map === null) {
            $file = __DIR__ . '/seo-published-dates.php';
            $map = is_file($file) ? (array)require $file : [];
        }
        $root = realpath(__DIR__ . '/..');
        $real = realpath($sourceFile) ?: $sourceFile;
        $key = ($root && strpos($real, $root . '/') === 0) ? substr($real, strlen($root) + 1) : basename($real);
        return $map[$key] ?? null;
    }

    /** Render one or more JSON-LD nodes as script tags, for $extraHead assembly. */
    public static function ldScript(array ...$nodes): string
    {
        $out = '';
        foreach ($nodes as $node) {
            $out .= '<script type="application/ld+json">'
                . json_encode($node, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                . '</script>';
        }
        return $out;
    }

    /** Product schema for a pricing plan / tier. */
    public static function product(string $name, string $description, string $priceOMR, ?string $url = null): void
    {
        self::emit([
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => $name,
            'description' => $description,
            'brand' => ['@type' => 'Brand', 'name' => self::BRAND],
            'offers' => [
                '@type' => 'Offer',
                'priceCurrency' => 'OMR',
                'price' => number_format((float)$priceOMR, 3, '.', ''),
                'availability' => 'https://schema.org/InStock',
                'url' => $url ?: self::SITE . '/pricing',
            ],
        ]);
    }

    private static function emit(array $data): void
    {
        echo '<script type="application/ld+json">'
            . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            . "</script>\n";
    }
}
