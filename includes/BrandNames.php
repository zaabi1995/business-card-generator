<?php
/**
 * BrandNames
 *
 * Single source of truth for how this brand spells its own name, in every
 * script it is written in.
 *
 * WHY THIS EXISTS
 * The Arabic name was typed by hand wherever a string needed it, and one of
 * those hands dropped a ya: "كاردفاي" instead of "كارديفاي". That misspelling
 * reached 58% of the Arabic tree, including the answer text inside FAQPage
 * JSON-LD, where it is not prose a reader skims past but a machine-readable
 * assertion about the entity's name. Every existing check passed it, because
 * every existing check asked whether the brand was NAMED, never whether it
 * was named CORRECTLY.
 *
 * RULE
 * Anything that stores, seeds or renders the brand name programmatically
 * (DB seeds, wallet themes, card renderers, tests) must read it from here.
 * Running prose in lang/ar/*.php keeps its literal text -- a constant spliced
 * into a sentence is unreadable to a translator -- so prose is held to the
 * same spelling by a gate instead: MISSPELLED below is the registered wrong
 * list, and both tools/verify-brand-name.php (source tree) and the estate's
 * seo_gate.py arm ar-brand-misspelled (live HTML, body and JSON-LD) FAIL on
 * any of them.
 *
 * Adding a newly-observed wrong spelling is one entry in MISSPELLED. It is
 * never a new checker.
 */

class BrandNames
{
    /** The brand, in Latin script. */
    public const EN = 'Cardify';

    /** The brand, in Arabic script. The ONLY correct spelling. */
    public const AR = 'كارديفاي';

    /**
     * Spellings that have been observed live and are wrong.
     *
     *  - كاردفاي  missing the ya between dal and fa (r27-19, 20 source hits)
     */
    public const MISSPELLED_AR = [
        'كاردفاي',
    ];

    /**
     * Every wrong spelling found in a blob, with its count.
     *
     * Counts, not booleans, because "is it present" is the question that let
     * this defect live: a page can name the brand correctly nine times and
     * still misspell it seven.
     */
    public static function misspellings(string $text): array
    {
        $hits = [];
        foreach (self::MISSPELLED_AR as $bad) {
            $n = substr_count($text, $bad);
            if ($n > 0) {
                $hits[$bad] = $n;
            }
        }
        return $hits;
    }
}
