<?php
/**
 * ONE definition of every public dataset Cardify publishes.
 *
 * r59 / llm27-37 (the Dataset is anonymous and duplicated). Measured 4 Aug
 * before this file existed: five Dataset nodes across three documents, ZERO
 * carrying an @id. /oman-business-index published "Oman Business Index 2026"
 * and /press published "Oman Business Index" for the same dataset, so the two
 * descriptions could not even be joined by name; /gcc-business-index and
 * /press did the same with the GCC index; and the logo library was a third
 * split, typed DataCatalog and named "Omani Logo Library, Public API" on
 * /logos while /press typed it Dataset and named it "Omani Logo Library".
 * Identity is the @id, so an entity without one is a new entity per document.
 *
 * r59 / llm27-36 (a FAQ answer promises a distribution the Dataset does not
 * declare). /oman-business-index FAQ answer 9 sends the reader to the logo
 * library for "downloadable brand marks (SVG + PNG)", and the logo-library
 * node on /press declared no distribution at all. The downloads are real:
 * measured 4 Aug, /api/logos/list 200 application/json, /api/logos/sectors
 * 200, /api/logos/stats 200, and a per-entry sample served .svg 200
 * image/svg+xml (728B), .png 200 image/png (12,507B), .webp 200 image/webp
 * (6,794B). They were never declared, not absent. EVERY contentUrl below was
 * fetched and returned 200 with the media type claimed for it before it was
 * written here. Nothing is declared that was not measured.
 *
 * One owner per key (the rule r58 earned): @id, name, url, license, creator,
 * publisher and distribution live here and nowhere else. A page adds only what
 * is genuinely its own, a description carrying a live count, dateModified,
 * keywords, coverage. A page that re-states a key this file owns is the defect
 * this file exists to prevent.
 */

final class Datasets
{
    /**
     * The identity of each dataset: a fragment on the page that is its home.
     * Referenced everywhere, redefined nowhere.
     */
    public const IDS = [
        'obi'   => 'https://cardify.om/oman-business-index#dataset',
        'logos' => 'https://cardify.om/logos#dataset',
        'gcc'   => 'https://cardify.om/gcc-business-index#dataset',
    ];

    private const CREATOR = [
        '@type' => 'Organization',
        'name'  => 'Cardify',
        'url'   => 'https://cardify.om',
    ];

    /**
     * The keys one owner holds. Read by node() and by brief(); a caller never
     * writes any of them.
     */
    private const CORE = [
        'obi' => [
            '@type'         => 'Dataset',
            'name'          => 'Oman Business Index 2026',
            'alternateName' => 'Cardify Oman Business Index',
            'url'           => 'https://cardify.om/oman-business-index',
            'license'       => 'https://creativecommons.org/licenses/by/4.0/',
            'isAccessibleForFree' => true,
            'distribution'  => [
                [
                    '@type'          => 'DataDownload',
                    'name'           => 'Browsable company index',
                    'encodingFormat' => 'text/html',
                    'contentUrl'     => 'https://cardify.om/companies',
                ],
                [
                    '@type'          => 'DataDownload',
                    'name'           => 'Company URL enumeration (sitemap)',
                    'encodingFormat' => 'application/xml',
                    'contentUrl'     => 'https://cardify.om/sitemap-companies.xml',
                ],
            ],
        ],
        // Typed both ways on purpose. /logos called it a DataCatalog and /press
        // called it a Dataset; it is one thing that is both, the same
        // multi-type shape cardify.om/#organization already uses for
        // Organization + LocalBusiness. One @id, two types, no third entity.
        'logos' => [
            '@type'         => ['Dataset', 'DataCatalog'],
            'name'          => 'Omani Logo Library',
            'alternateName' => 'Omani Logo Library, Public API',
            'url'           => 'https://cardify.om/logos',
            'license'       => 'https://cardify.om/logos/terms',
            'isAccessibleForFree' => true,
            // The formats a consumer can actually obtain. Each was fetched.
            'encodingFormat' => [
                'application/json', 'image/svg+xml', 'image/png', 'image/webp',
            ],
            'distribution'  => [
                [
                    '@type'          => 'DataDownload',
                    'name'           => 'Logo index (JSON). Every record carries the SVG, PNG and WebP URLs for that brand.',
                    'encodingFormat' => 'application/json',
                    'contentUrl'     => 'https://cardify.om/api/logos/list',
                ],
                [
                    '@type'          => 'DataDownload',
                    'name'           => 'Sector facets (JSON)',
                    'encodingFormat' => 'application/json',
                    'contentUrl'     => 'https://cardify.om/api/logos/sectors',
                ],
                [
                    '@type'          => 'DataDownload',
                    'name'           => 'Index statistics (JSON)',
                    'encodingFormat' => 'application/json',
                    'contentUrl'     => 'https://cardify.om/api/logos/stats',
                ],
                [
                    '@type'          => 'DataDownload',
                    'name'           => 'Browsable logo library',
                    'encodingFormat' => 'text/html',
                    'contentUrl'     => 'https://cardify.om/logos',
                ],
            ],
        ],
        'gcc' => [
            '@type'         => 'Dataset',
            'name'          => 'GCC Business Index 2026',
            'alternateName' => 'Cardify GCC Business Index',
            'url'           => 'https://cardify.om/gcc-business-index',
            'license'       => 'https://creativecommons.org/licenses/by/4.0/',
            'isAccessibleForFree' => true,
            'distribution'  => [
                [
                    '@type'          => 'DataDownload',
                    'name'           => 'Logo index (JSON)',
                    'encodingFormat' => 'application/json',
                    'contentUrl'     => 'https://cardify.om/api/logos/list',
                ],
                [
                    '@type'          => 'DataDownload',
                    'name'           => 'Sector facets (JSON)',
                    'encodingFormat' => 'application/json',
                    'contentUrl'     => 'https://cardify.om/api/logos/sectors',
                ],
                [
                    '@type'          => 'DataDownload',
                    'name'           => 'Index statistics (JSON)',
                    'encodingFormat' => 'application/json',
                    'contentUrl'     => 'https://cardify.om/api/logos/stats',
                ],
                [
                    '@type'          => 'DataDownload',
                    'name'           => 'Oman national index',
                    'encodingFormat' => 'text/html',
                    'contentUrl'     => 'https://cardify.om/oman-business-index',
                ],
            ],
        ],
    ];

    /** The @id of a dataset. Fails loudly rather than inventing an identity. */
    public static function id(string $key): string
    {
        if (!isset(self::IDS[$key])) {
            throw new InvalidArgumentException("Datasets: unknown dataset '$key'");
        }
        return self::IDS[$key];
    }

    /**
     * The full node for the page that is this dataset's home.
     *
     * @param array $own Keys the PAGE legitimately owns (description with a
     *                   live count, dateModified, datePublished, keywords,
     *                   variableMeasured, spatialCoverage, temporalCoverage,
     *                   inLanguage). Any key this class owns is ignored, so a
     *                   page cannot silently re-mint a second name.
     */
    public static function node(string $key, array $own = []): array
    {
        $core = self::CORE[$key] ?? throw new InvalidArgumentException("Datasets: unknown dataset '$key'");
        $own  = array_diff_key($own, $core, ['@id' => 1, 'creator' => 1, 'publisher' => 1]);

        return ['@context' => 'https://schema.org', '@id' => self::id($key)]
            + $core
            + [
                'creator'   => self::CREATOR,
                'publisher' => self::CREATOR + [
                    'logo' => [
                        '@type'           => 'ImageObject',
                        'url'             => 'https://cardify.om/assets/images/cardify-logo.png',
                        'creditText'      => 'Cardify',
                        'copyrightNotice' => '© Cardify',
                        'license'         => 'https://cardify.om/terms',
                    ],
                ],
            ]
            + $own;
    }

    /**
     * The same entity, described compactly, for a document that references the
     * dataset without being its home (/press, and the OBI page pointing at the
     * logo library its own FAQ answer promises).
     *
     * It carries the SAME @id, so this is a second description of one entity,
     * not a second entity, and it carries the distribution so the promise
     * resolves inside the document that makes it. It is a full node rather than
     * a bare {"@id": ...} ref on purpose: the r37 rule is that an edge must
     * resolve in the document it appears in.
     */
    public static function brief(string $key, array $own = []): array
    {
        $core = self::CORE[$key] ?? throw new InvalidArgumentException("Datasets: unknown dataset '$key'");
        $own  = array_diff_key($own, $core, ['@id' => 1, 'creator' => 1]);

        return ['@id' => self::id($key)]
            + array_intersect_key($core, array_flip([
                '@type', 'name', 'alternateName', 'url', 'license',
                'isAccessibleForFree', 'encodingFormat', 'distribution',
            ]))
            + ['creator' => self::CREATOR]
            + $own;
    }
}
