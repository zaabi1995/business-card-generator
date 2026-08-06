<?php
/**
 * The iOS app, as ONE entity, written down once.
 *
 * r81 / bhd-r6-99 + llm20-21. Before this file existed, the same App Store
 * record (id6790749589) was published under TWO @ids that nothing connected:
 *
 *   https://cardify.om/app#ios-app   defined in index.php  (the HOME page)
 *   https://cardify.om/#app          defined in business-card-scanner.php,
 *                                    and re-defined a third time, from a
 *                                    different repository, by bhd.om's
 *                                    _brandpages.py APP_ID
 *
 * Both were r6-99 fixes. Both carry a source comment asserting the app has
 * ONE @id. Each assertion is true only inside the file that makes it, and
 * _brandpages.py's comment even enumerates the surfaces it believes share its
 * @id, a list that omits the two surfaces publishing the other one. Two
 * rounds closed the same ledger item in two files and picked different
 * canonical identifiers; nothing compared the two answers, because the gate
 * that checks app identity (entity_gate.py APP-ID) hand-lists its surfaces
 * and the four it listed all emit the SAME @id. A gate can only confirm the
 * subset it was told about.
 *
 * It got worse than a split: cardify.om/#app also shipped two contradictory
 * payloads, `operatingSystem` = "iOS 16.4 or later" from cardify.om and
 * "iOS 16.4+" from bhd.om, and /app, the page that IS the app's page and
 * whose URL both @ids are a fragment of, published no app node at all while
 * naming a third App Store URL (apps.apple.com/app/id... with no country
 * segment and no slug).
 *
 * The rule this file exists to make structural:
 *
 *   The @id of an entity is a fragment of the document that DESCRIBES it.
 *   https://cardify.om/app#ios-app is canonical because /app is the app's
 *   page, so a consumer that dereferences the identifier lands on the thing.
 *   /#app put the app's fragment on the home document, which describes the
 *   WebSite and the Organization.
 *
 * Every surface takes its bytes from here. A surface that wants to say less
 * takes ref(); a surface that defines the entity takes node(). There is no
 * third option and no literal copy anywhere in the tree, which is what makes
 * a fourth divergent spelling impossible rather than merely unlikely.
 */
class AppEntity
{
    /**
     * Canonical identifier. A fragment of /app, the document about the app.
     */
    public const ID = 'https://cardify.om/app#ios-app';

    /** The app's own page. Also the node's `url`. */
    public const PAGE = 'https://cardify.om/app';

    /** Apple's numeric identifier, the one fact no rewrite can change. */
    public const APPSTORE_ID = '6790749589';

    /**
     * ONE App Store URL. app.php used to name a fourth spelling with no
     * country segment and no slug; a redirect chain is not an identifier.
     */
    public const APPSTORE_URL =
        'https://apps.apple.com/om/app/cardify-business-card-scanner/id6790749589';

    /**
     * Locale-invariant identity. These are the fields that MUST read the same
     * on every surface, in every locale, because they are claims about the
     * entity rather than about the page. Anything locale-specific (a
     * translated description, an Arabic feature list) belongs in the visible
     * body, never under a shared @id: a payload that varies by locale under
     * one identifier is what made one app read as three entities in r6.
     */
    public const NAME = 'Cardify: Business Card Scanner';

    /**
     * The app is reachable under three names: the App Store title, the short
     * name on the home screen, and the Arabic transliteration the AR pages
     * use. Asserting one left the other two unresolvable.
     */
    public const ALTERNATE_NAMES = [
        'Cardify Scan',
        'Cardify Business Card Scanner',
        'كارديفاي',
        'Cardify، ماسح بطاقات العمل',
    ];

    /**
     * One spelling. "iOS 16.4+" and "iOS 16.4 or later" are the same fact and
     * were the measured contradiction under the shared @id.
     */
    public const OS = 'iOS 16.4 or later, iPadOS 16.4 or later';

    /** The sibling web app, which carries the reciprocal edge back. */
    public const WEBAPP_ID = 'https://cardify.om/#webapp';

    /**
     * A reference. Everything a consumer needs to MERGE this mention into the
     * entity, and nothing it could contradict. Surfaces that merely mention
     * the app use this.
     */
    public static function ref(): array
    {
        return ['@id' => self::ID];
    }

    /**
     * The full definition. Identical bytes on every surface that emits it,
     * which is what lets entity_gate.py assert payload agreement per @id
     * rather than merely counting identifiers.
     *
     * @param string $publisherId the Organization @id this surface resolves
     *        the publisher to. cardify.om resolves it to its own
     *        #organization; bhd.om resolves it to the group node. Both are
     *        references, never a fresh anonymous copy of an entity the estate
     *        already defines once.
     */
    public static function node(string $publisherId = 'https://cardify.om/#organization'): array
    {
        return [
            '@type' => 'MobileApplication',
            '@id' => self::ID,
            'name' => self::NAME,
            'alternateName' => self::ALTERNATE_NAMES,
            'identifier' => self::APPSTORE_ID,
            'url' => self::PAGE,
            'downloadUrl' => self::APPSTORE_URL,
            'installUrl' => self::APPSTORE_URL,
            'softwareHelp' => self::PAGE,
            'sameAs' => [self::APPSTORE_URL],
            'applicationCategory' => 'BusinessApplication',
            'applicationSubCategory' => 'Business card scanner',
            'applicationSuite' => 'Cardify',
            'operatingSystem' => self::OS,
            'inLanguage' => ['en', 'ar'],
            'isAccessibleForFree' => true,
            'description' => 'Free Arabic and English business card scanner for '
                . 'iPhone and iPad with on-device OCR, contact cleanup, QR and '
                . 'NFC sharing, digital cards and Apple Wallet.',
            'featureList' => [
                'On-device optical recognition of Arabic and English cards',
                'Field review before saving to contacts',
                'NFC read and write',
                'Digital card and Apple Wallet pass',
                'Ordering the card in print',
            ],
            'isRelatedTo' => ['@id' => self::WEBAPP_ID],
            'offers' => [
                '@type' => 'Offer',
                'price' => '0',
                'priceCurrency' => 'OMR',
                'availability' => 'https://schema.org/InStock',
            ],
            'publisher' => ['@id' => $publisherId],
        ];
    }
}
