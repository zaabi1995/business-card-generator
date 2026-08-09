<?php
/**
 * Cardify's free tools, as ONE family, joined once.
 *
 * r150 / bhd-r6-99 clause 2, change of approach #18. AppEntity.php is the same
 * idea for the iOS app: one entity, written down once. This file is its sibling
 * for the tools, and it exists because of what the previous seventeen
 * approaches kept producing -- a correct fix on the ONE surface a sampled gate
 * happened to name, while identically shaped siblings stayed live and the gate
 * went green.
 *
 * MEASURED 10 Aug 2026, on the served bytes, not on the repo:
 *
 *   https://cardify.om/tools/email-signature-generator#tool   no link predicate
 *   https://cardify.om/tools/vcard-qr-generator#tool          no link predicate
 *   https://cardify.om/tools/whatsapp-qr-generator#tool       no link predicate
 *
 * entity_graph_gate named ONE of the three. Not because the other two are
 * joined -- they are not -- but because its derived population takes
 * DERIVED_REPS_PER_FAMILY = 2 URLs per family, and the cardify.om/tools family
 * has four members. Alphabetically the two sampled are
 * email-signature-generator (a tool) and nfc-business-card-guide (an Article
 * that publishes no app node at all). So one of three orphans was visible and
 * a per-page fix on that one would have printed GREEN with two live.
 *
 * THE MECHANIC THIS REPLACES: every tool page hand-authored its own
 * SoftwareApplication body, so the join was a thing each page could forget
 * independently, and forgetting it was invisible unless that page was the one
 * sampled. Here the join is not a field a page supplies. A page supplies only
 * what is ITS OWN -- slug, name, description, featureList -- and the family
 * facts come from this table by construction. A new tool that forgets to link
 * is not possible, because there is nothing to forget.
 *
 * THE PREDICATE, and why this one: index.php already joins with
 * "isRelatedTo": {"@id": "https://cardify.om/#webapp"}. isRelatedTo is the
 * estate's existing choice for this edge and is an honest claim -- a free
 * standalone tool is related to the Cardify web app, it is not a PART of it,
 * and hasPart in the other direction would make the web app assert ownership
 * of pages it does not render. The reference is a stub {@id} and never a body:
 * a body under an @id this document does not own is the r6-99 defect itself
 * (r149 deleted two of those from bhd.om for exactly this reason).
 */
class ToolEntity
{
    /** The app this family is related to. A stub target, never re-described. */
    public const SUITE_ID = 'https://cardify.om/#webapp';

    /** The organization that makes them. Resolved by reference, never copied. */
    public const CREATOR_ID = 'https://cardify.om/#organization';

    public const BASE = 'https://cardify.om/tools/';

    /**
     * Facts that are true of every tool in the family. A page that wanted to
     * disagree with one of these would be describing a different kind of
     * thing, which is a decision, not an override.
     */
    public const FAMILY = [
        'applicationCategory' => 'BusinessApplication',
        'operatingSystem'     => 'Web',
        'browserRequirements' => 'Requires JavaScript. Works in Chrome, Safari, Firefox, Edge.',
    ];

    /** Canonical URL of a tool, from its slug. One spelling, derived. */
    public static function url(string $slug): string
    {
        return self::BASE . $slug;
    }

    /** @id of a tool: a fragment of the document that describes it. */
    public static function id(string $slug): string
    {
        return self::url($slug) . '#tool';
    }

    /**
     * The full SoftwareApplication node for one tool.
     *
     * $own carries only what belongs to this tool: name, description,
     * featureList. Everything else -- identity, family facts, the free Offer,
     * the creator reference and the join to the suite -- is written here once.
     */
    public static function node(string $slug, array $own): array
    {
        $node = [
            '@context' => 'https://schema.org',
            '@type'    => 'SoftwareApplication',
            '@id'      => self::id($slug),
            'name'     => $own['name'],
            'description' => $own['description'],
            'url'      => self::url($slug),
        ] + self::FAMILY;

        $node['offers'] = [
            '@type' => 'Offer',
            'price' => '0',
            'priceCurrency' => 'OMR',
        ];
        $node['creator'] = ['@id' => self::CREATOR_ID];
        // The join. Not a field a page may supply, omit or contradict.
        $node['isRelatedTo'] = ['@id' => self::SUITE_ID];
        $node['featureList'] = $own['featureList'];

        return $node;
    }
}
