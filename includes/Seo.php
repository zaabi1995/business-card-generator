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

    /** Emit hreflang + canonical tags for a path that exists in both /path (EN) and /ar/path (AR). */
    public static function hreflang(string $path): void
    {
        $path = '/' . ltrim($path, '/');
        $en = self::SITE . ($path === '/' ? '/' : $path);
        // /ar/foo for non-root; /ar/ for root.
        $ar = self::SITE . '/ar' . ($path === '/' ? '/' : $path);
        $current = (($_SERVER['REQUEST_SCHEME'] ?? 'https') . '://' . ($_SERVER['HTTP_HOST'] ?? 'cardify.om') . ($_SERVER['REQUEST_URI'] ?? $path));

        echo '<link rel="canonical" href="' . htmlspecialchars($current, ENT_QUOTES) . "\">\n";
        echo '<link rel="alternate" hreflang="en" href="' . htmlspecialchars($en, ENT_QUOTES) . "\">\n";
        echo '<link rel="alternate" hreflang="ar" href="' . htmlspecialchars($ar, ENT_QUOTES) . "\">\n";
        echo '<link rel="alternate" hreflang="x-default" href="' . htmlspecialchars($en, ENT_QUOTES) . "\">\n";
    }

    /** Publisher/brand once per page. */
    public static function organization(): void
    {
        self::emit([
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            'name' => self::BRAND,
            'url' => self::SITE,
            'logo' => self::PUBLISHER_LOGO,
            'sameAs' => [
                'https://www.instagram.com/cardify.om',
                'https://twitter.com/cardify_om',
                'https://www.linkedin.com/company/cardify-om',
            ],
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
