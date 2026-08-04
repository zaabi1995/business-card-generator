<?php
/**
 * Arabic text hygiene for anything written into an Arabic column.
 *
 * WHY THIS EXISTS
 * r62 repaired 10 om_companies.name_ar rows written in Arabic PRESENTATION
 * FORMS (U+FB50-FDFF, U+FE70-FEFF): the positional and ligature variants a PDF
 * or registry extraction emits instead of the normal Arabic block. They RENDER
 * correctly, which is why the defect survived rounds of review, and they match
 * nothing: directory search, card pre-fill and any crawler query written in
 * normal Arabic all miss the row. Those 10 rows had reached 21+ of 220 scanned
 * Arabic pages before an instrument ever measured them.
 *
 * That repair had a timer on it. Nothing in the write path refused the shape,
 * so the next registry import would put it back and every gate would stay green
 * while it did. This class is the code half of the guard; migration 150 is the
 * database half, and the two are deliberately different in kind:
 *
 *   - here, NORMALISE: an importer that calls normalize() cannot store the
 *     shape even if its source file is full of it.
 *   - in the trigger, REFUSE: an importer written next month that forgets to
 *     call this class fails loudly instead of poisoning pages silently.
 *
 * A code-only guard would be bypassed by the first script that does not know
 * about it, which is exactly the failure this finding describes.
 *
 * SCOPE, stated so the hole is visible rather than assumed away:
 * normalize() fixes codepoint choice. It does NOT fix the OTHER extraction
 * defect in the same table (llm62-2: parentheses stored mirrored in logical
 * order, e.g. ')شركة منطقة حره(' ). NFKC leaves those byte-identical, measured.
 * parenFault() reports that shape so a caller can see it; nothing refuses it,
 * because an unbalanced parenthesis in a company name is ugly but can be real,
 * and refusing an import row over punctuation loses the company.
 */

class ArabicText
{
    /**
     * Arabic Presentation Forms-A (U+FB50-FDFF) and -B (U+FE70-FEFF).
     * Verified on both arms against MariaDB's PCRE before the trigger was
     * built on it: fires on the r62 shape, silent on all 2,502 live rows.
     */
    public const PRESENTATION_FORMS = '/[\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]/u';

    /**
     * NFKC, which is what folds a presentation form back to the normal block.
     *
     * Returns null for null so a caller can pass an optional column straight
     * through. Throws when intl is missing rather than returning the input
     * unchanged: a normaliser that silently does nothing is the same silence
     * this class exists to end.
     */
    public static function normalize(?string $s): ?string
    {
        if ($s === null) {
            return null;
        }
        if (!class_exists('Normalizer')) {
            throw new RuntimeException(
                'ArabicText::normalize needs ext-intl; refusing to pass Arabic '
                . 'through unnormalised.'
            );
        }
        $out = Normalizer::normalize($s, Normalizer::FORM_KC);
        if ($out === false) {
            throw new RuntimeException('ArabicText::normalize: NFKC failed on input.');
        }
        // U+FEFF (BOM) sits inside Presentation Forms-B and is not a letter;
        // NFKC keeps it, so it would survive as an invisible character that
        // still trips the database guard. Strip it here rather than at every
        // call site.
        $out = str_replace("\u{FEFF}", '', $out);
        return trim($out);
    }

    /** True when the string still carries a presentation-form codepoint. */
    public static function hasPresentationForms(?string $s): bool
    {
        return $s !== null && $s !== '' && (bool) preg_match(self::PRESENTATION_FORMS, $s);
    }

    /**
     * llm62-2: classify the parenthesis run.
     *
     * A regex for ')...(' was the first attempt and is WRONG: any string with
     * two ordinary pairs contains that sequence, and it scored three
     * legitimate Arabic summaries as mangled. Balance is the test.
     *
     * 'unopened' = a ')' arrives at depth 0 (the row-1218 shape, mirrored
     * glyphs stored in logical order). 'unclosed' = the string ends open (the
     * row-216 shape, where the trailing ')' was carried to the front as '(').
     * Population on 2,502 live rows: 3 pairs, 0 false positives.
     */
    public static function parenFault(?string $s): ?string
    {
        if ($s === null || $s === '') {
            return null;
        }
        $depth = 0;
        foreach (preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
            if ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                if (--$depth < 0) {
                    return 'unopened';
                }
            }
        }
        return $depth > 0 ? 'unclosed' : null;
    }
}
