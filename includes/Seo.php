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
        self::emit(self::organizationNode());
    }

    /**
     * The ONE body behind https://cardify.om/#organization.
     *
     * llm20-11: publisherNode() used to return a second, 5-key body under this
     * same @id, so 20 solutions pages published one identifier with two
     * contradicting definitions. Splitting the payload out of organization()
     * means the reference target is a single literal that both the emitter and
     * the article graph read, and a future edit cannot move one without the
     * other.
     */
    public static function organizationNode(): array
    {
        // llm20-11 (r48): this payload is the LITERAL that used to be typed
        // into index.php's <script> block, moved here verbatim. It was
        // previously a second, shorter hand-written node kept "in step" by a
        // comment, and it had already drifted on two keys: it dropped the
        // LocalBusiness @type and published a different contactPoint (customer
        // support / email vs customer service / url). A comment is not a
        // constraint. index.php now RENDERS this, so there is one body for
        // https://cardify.om/#organization on every page that carries it.
        //
        // r154: a caller taking this payload IS the page's owner body, so the
        // shared header must not add a second one. organizationScriptOnce()
        // reads this flag.
        self::$ownerEmitted = true;
        return [
            "@context" => "https://schema.org",
            "@type" => ["Organization", "LocalBusiness"],
            "@id" => "https://cardify.om/#organization",
            "name" => "Cardify",
            "alternateName" => ["Cardify Oman", "Cardify GCC"],
            "url" => "https://cardify.om",
            "parentOrganization" => [
                "@id" => "https://bhd.om/#organization",
            ],
            "disambiguatingDescription" => "Cardify is the digital business card platform published by BHD Group (Bin Haider Darwish L.L.C.) in Muscat, Oman. BHD Group is Bin Haider Darwish L.L.C., Commercial Registration 1334733, a family-owned printing and technology group founded in Muscat in 2018. It is not Mohsin Haider Darwish LLC (a separate and unrelated Omani company), and the initials BHD here stand for Bin Haider Darwish, not the Bahraini dinar currency code.",
            "logo" => "https://cardify.om/assets/images/logo.svg",
            "description" => "Business-identity platform for the Gulf: digital and printed business cards, public logo libraries, and the GCC Business Index. Built in Oman, expanding across Saudi Arabia, UAE, Qatar, Bahrain, and Kuwait through 2026.",
            "address" => [
                "@type" => "PostalAddress",
                "streetAddress" => "HM Tower, Ground Floor, Bousher",
                "postOfficeBoxNumber" => "2237",
                "addressLocality" => "Muscat",
                "addressRegion" => "Muscat Governorate",
                "postalCode" => "133",
                "addressCountry" => "OM",
            ],
            "geo" => [
                "@type" => "GeoCoordinates",
                "latitude" => 23.57176,
                "longitude" => 58.4094427,
            ],
            "hasMap" => "https://maps.app.goo.gl/nR785v4vyTB8edNq9",
            "telephone" => "+96898899100",
            "priceRange" => "OMR",
            "openingHoursSpecification" => [
                [
                    "@type" => "OpeningHoursSpecification",
                    "dayOfWeek" => ["Saturday", "Sunday", "Monday", "Tuesday", "Wednesday", "Thursday"],
                    "opens" => "09:00",
                    "closes" => "19:00",
                ],
            ],
            "founder" => [
                "@id" => "https://bhd.om/#founder",
            ],
            "foundingDate" => "2024",
            "foundingLocation" => [
                "@type" => "Place",
                "address" => [
                    "@type" => "PostalAddress",
                    "addressCountry" => "OM",
                    "addressLocality" => "Muscat",
                ],
            ],
            "areaServed" => [
                [
                    "@type" => "Country",
                    "name" => "Oman",
                    "alternateName" => "عُمان",
                    "identifier" => "OMN",
                ],
                [
                    "@type" => "Country",
                    "name" => "Saudi Arabia",
                    "alternateName" => "المملكة العربية السعودية",
                    "identifier" => "SAU",
                ],
                [
                    "@type" => "Country",
                    "name" => "United Arab Emirates",
                    "alternateName" => "الإمارات العربية المتحدة",
                    "identifier" => "ARE",
                ],
                [
                    "@type" => "Country",
                    "name" => "Qatar",
                    "alternateName" => "قطر",
                    "identifier" => "QAT",
                ],
                [
                    "@type" => "Country",
                    "name" => "Bahrain",
                    "alternateName" => "البحرين",
                    "identifier" => "BHR",
                ],
                [
                    "@type" => "Country",
                    "name" => "Kuwait",
                    "alternateName" => "الكويت",
                    "identifier" => "KWT",
                ],
            ],
            "knowsLanguage" => ["en", "ar"],
            "sameAs" => ["https://instagram.com/cardifyom"],
            // r153 / llm148-1: a LIST, because press.php used to publish the
            // public-relations point inside a rival 12-key body under this same
            // @id. One contact route was the only thing that page said which
            // this node did not, so it moves here and the page keeps nothing.
            // A key that exists to be reachable is worse than useless when two
            // bodies disagree about which route is the real one.
            "contactPoint" => [
                [
                    "@type" => "ContactPoint",
                    "contactType" => "customer service",
                    "url" => "https://cardify.om/contact",
                    "availableLanguage" => ["en", "ar"],
                ],
                [
                    "@type" => "ContactPoint",
                    "contactType" => "public relations",
                    "email" => "press@cardify.om",
                    "url" => "https://cardify.om/contact",
                    "availableLanguage" => ["en", "ar"],
                ],
            ],
            "hasOfferCatalog" => [
                "@type" => "OfferCatalog",
                "@id" => "https://cardify.om/#catalog-cardify-product-catalog",
                "name" => "Cardify Product Catalog",
                "itemListElement" => [
                    [
                        "@type" => "OfferCatalog",
                        "@id" => "https://cardify.om/#catalog-digital-business-cards",
                        "name" => "Digital Business Cards",
                        "url" => "https://cardify.om/",
                    ],
                    [
                        "@type" => "OfferCatalog",
                        "@id" => "https://cardify.om/#catalog-omani-logo-library",
                        "name" => "Omani Logo Library",
                        "url" => "https://cardify.om/logos",
                    ],
                    [
                        "@type" => "OfferCatalog",
                        "@id" => "https://cardify.om/#catalog-oman-business-index",
                        "name" => "Oman Business Index",
                        "url" => "https://cardify.om/oman-business-index",
                    ],
                    [
                        "@type" => "OfferCatalog",
                        "@id" => "https://cardify.om/#catalog-gcc-business-index",
                        "name" => "GCC Business Index",
                        "url" => "https://cardify.om/gcc-business-index",
                    ],
                ],
            ],
        ];
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
     * The canonical publisher REFERENCE. Every Article, NewsArticle and
     * BlogPosting on the estate must point at the organization by @id.
     *
     * r6-88: 57 publisher nodes carried a name and a logo but no url, so none of
     * them resolved to the entity they were publishing on behalf of. That round
     * fixed it by spelling a name/url/logo body out here.
     *
     * llm20-11: spelling it out was the next defect. The body this returned was
     * a 5-key redefinition of https://cardify.om/#organization, published on the
     * same page that already referenced that @id bare for `author`, and
     * contradicting the 24-key node the homepage defines for it. A resolver
     * reading /solutions/* saw one identifier with two bodies and neither of
     * them the real one. The slot only ever needed a reference; articleNode()
     * now carries the real node in the same graph, so the reference resolves
     * in-document instead of hoping a crawler merges across pages.
     */
    public static function publisherNode(): array
    {
        return ['@id' => self::SITE . '/#organization'];
    }

    /**
     * r154 / llm153-2: the owner body, emitted at most once per request.
     *
     * publisherNode() returns a bare @id, and the r37 rule is that an edge
     * must resolve inside the document that makes it. Before this, only four
     * pages emitted the owner, so eleven creator/publisher/provider/
     * hiringOrganization slots on the other pages could not be reduced to a
     * reference without stranding it: each one therefore kept spelling out a
     * SECOND anonymous Organization naming Cardify, with no @id to join it to
     * anything. That is llm153-2, and it is one missing emitter, not eleven
     * page defects.
     *
     * ui-header.php calls this after $extraHead, so every page that renders
     * the shared header carries exactly one https://cardify.om/#organization
     * body. The guard is set by organizationNode() itself, so a page that has
     * already built the owner into its own @graph (index.php, press.php,
     * blog.php, gcc-business-index.php) does NOT get a second copy: those
     * pages assemble $extraHead before requiring the header, so the flag is
     * already true by the time this runs.
     */
    private static bool $ownerEmitted = false;

    public static function organizationScriptOnce(): string
    {
        if (self::$ownerEmitted) {
            return '';
        }
        self::$ownerEmitted = true;
        return '<script type="application/ld+json">'
            . json_encode(self::organizationNode(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            . '</script>' . "\n";
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

        // llm20-11: the Article's author and publisher are both bare @id refs
        // now, so the page must carry the node they refer to. Returned as a
        // @graph rather than a bare Article: every caller json_encodes this
        // value straight into one <script>, so the wrapper reaches all 20
        // solutions pages without touching any of them.
        $org = self::organizationNode();
        unset($node['@context'], $org['@context']);
        return [
            '@context' => 'https://schema.org',
            '@graph' => [$node, $org],
        ];
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

    /**
     * Product schema for a pricing plan / tier.
     *
     * r81 / llm20-21. These four nodes were anonymous AND carried no url and
     * no sku, so nothing on the estate could address them and, worse, the
     * Arabic page published four MORE of them under translated names. Eight
     * unaddressable Products describing four products, with no property a
     * consumer could use to merge "Standard Cards" with "بطاقات قياسية".
     *
     * $key is the locale-invariant handle. Everything under the resulting @id
     * is locale-invariant on purpose, the same rule AppEntity enforces for the
     * app: a shared identifier whose payload changes with the request locale
     * asserts one entity and then contradicts itself about it. The Arabic name
     * is alternateName, never a replacement for name, and the offer URL is the
     * English canonical because the offer is the same offer on both pages.
     */
    /**
     * Merchant listing policy, published on every print Offer.
     *
     * GSC WNC-10030322, 16 Aug 2026, reported three Merchant listings issues on
     * cardify.om: a CRITICAL missing 'image' plus a missing
     * 'hasMerchantReturnPolicy' and 'shippingDetails' inside 'offers'. All four
     * pricing Products were affected, on /pricing and on /ar/pricing.
     *
     * Both facts below are already published on the site in visible copy or in
     * the Terms. Nothing here is inferred:
     *
     *   - No returns: the Terms say "Print fees are non-refundable once the
     *     order has been sent to production" (lang/en/legal.php and its Arabic
     *     twin, under Payment terms).
     *     Cards carry the customer's own branding, so MerchantReturnNotPermitted
     *     is the accurate category and Google needs no window or fee with it.
     *     Confirmed by Ali, 16 Aug 2026.
     *   - Delivery time: "Delivery across Oman in 2 to 4 working days. Pickup
     *     from Muscat is same-day" (pricing.products_note).
     *
     * shippingRate is deliberately ABSENT. The delivery fee is set per order by
     * the print shop the customer picks (PrintShop::getEffectivePricing,
     * shipping_base, which is 2.000 on the default and 10.000 on BHD) and is
     * shown before confirming. There is no one rate to publish, and inventing
     * one would misprice the offer for every shop that charges something else.
     */
    private static function offerPolicy(): array
    {
        return [
            'hasMerchantReturnPolicy' => [
                '@type' => 'MerchantReturnPolicy',
                '@id' => self::SITE . '/#returnpolicy',
                'applicableCountry' => 'OM',
                'returnPolicyCategory' => 'https://schema.org/MerchantReturnNotPermitted',
            ],
            'shippingDetails' => [
                '@type' => 'OfferShippingDetails',
                '@id' => self::SITE . '/#shipping-oman',
                'shippingDestination' => [
                    '@type' => 'DefinedRegion',
                    'addressCountry' => 'OM',
                ],
                'deliveryTime' => [
                    '@type' => 'ShippingDeliveryTime',
                    'handlingTime' => [
                        '@type' => 'QuantitativeValue',
                        'minValue' => 0, 'maxValue' => 1, 'unitCode' => 'DAY',
                    ],
                    'transitTime' => [
                        '@type' => 'QuantitativeValue',
                        'minValue' => 2, 'maxValue' => 4, 'unitCode' => 'DAY',
                    ],
                ],
            ],
        ];
    }

    /**
     * @param string $imageFile Basename under /assets/images/products. Required,
     *   not optional: a missing image is the CRITICAL half of WNC-10030322, and
     *   an optional parameter is how the next tier gets added without one.
     */
    public static function product(string $key, string $nameKey, string $descKey, string $priceOMR, string $imageFile): void
    {
        $en = static fn(string $k): string => class_exists('I18n')
            ? I18n::t($k, [], 'en') : $k;
        $ar = static fn(string $k): string => class_exists('I18n')
            ? I18n::t($k, [], 'ar') : $k;
        $id = self::SITE . '/pricing#product-' . $key;
        $names = [$en($nameKey), $ar($nameKey)];
        // A null alternateName has to be REMOVED, not emitted: `"x": null` is
        // a published claim that the property is empty, and emit() does not
        // strip it.
        self::emit(array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            '@id' => $id,
            'sku' => 'CARDIFY-' . strtoupper($key),
            'name' => $names[0],
            // 1200x1200 photograph of the actual stock this tier names. Locale
            // invariant like everything else under this @id: the paper does not
            // change between /pricing and /ar/pricing.
            'image' => self::SITE . '/assets/images/products/' . $imageFile,
            // Only when the dictionary really has a distinct Arabic string.
            // Echoing the English back as an alternateName would assert a
            // translation that does not exist.
            'alternateName' => $names[1] !== $names[0] ? $names[1] : null,
            'description' => $en($descKey),
            'url' => self::SITE . '/pricing',
            'brand' => ['@type' => 'Brand', 'name' => self::BRAND],
            'offers' => array_merge([
                '@type' => 'Offer',
                'priceCurrency' => 'OMR',
                'price' => number_format((float)$priceOMR, 3, '.', ''),
                'availability' => 'https://schema.org/InStock',
                'url' => self::SITE . '/pricing',
            ], self::offerPolicy()),
        ], static fn($v) => $v !== null));
    }

    private static function emit(array $data): void
    {
        echo '<script type="application/ld+json">'
            . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            . "</script>\n";
    }
}
