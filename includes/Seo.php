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
            'identifier' => [
                ['@type' => 'PropertyValue', 'name' => 'Commercial Registration', 'value' => '1334733'],
                ['@type' => 'PropertyValue', 'name' => 'VAT Identification Number', 'value' => 'OM1100019343'],
            ],
            'vatID' => 'OM1100019343',
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
