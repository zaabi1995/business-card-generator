<?php
// ScanVcf: builds a vCard 3.0 from a parsed scan. CRLF line endings per RFC 2426.
require_once __DIR__ . '/VCardRfc.php';

class ScanVcf {

    /**
     * Map the parser's free-text phone type to an RFC 2426 TEL type.
     *
     * The parser prompt asks the model for mobile|work|fax, but a model reading a
     * business card returns whatever the CARD says: "Office", "Direct Line",
     * "Mobile/WhatsApp", "جوال", "هاتف", "Tel/Fax", "Ext". So matching is
     * SUBSTRING and case-insensitive, not exact-key: an exact-match table over
     * English keys almost never fires on real scanner output.
     *
     * Ordered, first match wins. CELL needles come first so a combined label like
     * "Mobile/WhatsApp" resolves to CELL. FAX comes before the generic voice
     * words so "Tel/Fax" is not read as a plain landline.
     *
     * The unclassified fallback is OTHER, not VOICE. Measured on a booted
     * iPhone 17 via CNContactVCardSerialization: TYPE=VOICE is not a label iOS
     * knows, so it is stored as a CUSTOM label and round-trips as
     * "item1.X-ABLabel:VOICE", i.e. the user sees a phone labelled "VOICE" in
     * raw caps that no locale ever translates. TYPE=OTHER maps to the standard
     * label _$!<Other>!$_, which iOS localises.
     *
     * strtolower() only touches ASCII A-Z, so the Arabic needles pass through
     * byte-intact and no mbstring dependency is introduced here.
     */
    private static $typeNeedles = [
        ['cell', 'CELL'], ['mobile', 'CELL'], ['mob', 'CELL'], ['gsm', 'CELL'],
        ['whatsapp', 'CELL'], ['handy', 'CELL'],
        ['جوال', 'CELL'], ['محمول', 'CELL'], ['نقال', 'CELL'],

        ['fax', 'FAX'], ['فاكس', 'FAX'], ['ناسوخ', 'FAX'],

        ['home', 'HOME'], ['منزل', 'HOME'],

        ['work', 'WORK'], ['office', 'WORK'], ['business', 'WORK'],
        ['direct', 'WORK'], ['landline', 'WORK'], ['land line', 'WORK'],
        ['hotline', 'WORK'], ['toll', 'WORK'], ['switchboard', 'WORK'],
        ['reception', 'WORK'], ['main', 'WORK'], ['ext', 'WORK'],
        ['telephone', 'WORK'], ['tel', 'WORK'], ['phone', 'WORK'], ['ph', 'WORK'],
        ['مباشر', 'WORK'], ['هاتف', 'WORK'], ['مكتب', 'WORK'],
        ['مجاني', 'WORK'], ['تحويلة', 'WORK'], ['عمل', 'WORK'],
    ];

    /** Resolve one free-text phone type label to an RFC 2426 TEL type. */
    private static function telType($raw) {
        $label = strtolower(trim((string) $raw));
        if ($label === '') {
            return 'OTHER';
        }
        foreach (self::$typeNeedles as $pair) {
            if (strpos($label, $pair[0]) !== false) {
                return $pair[1];
            }
        }
        return 'OTHER';
    }

    public static function build(array $parsed, ?string $note = null): string {
        $eol = "\r\n";
        $esc = function ($v) {
            // Backslash first: it is the escape character per RFC 2426, so
            // escaping it later would double-escape the slashes added below.
            //
            // A stray CR is dropped outright, matching includes/VCF.php. Left in
            // place it terminates the content line early inside the value, so one
            // property silently becomes two garbage ones on the parsing end.
            // trim() only reaches a leading/trailing CR, never an interior one.
            return str_replace(
                ['\\', ';', ',', "\n", "\r"],
                ['\\\\', '\\;', '\\,', '\\n', ''],
                trim((string)$v)
            );
        };
        $name = ($parsed['name_en'] ?? '') ?: ($parsed['name_ar'] ?? '');

        // N is the property Apple actually reads; iOS discards FN entirely (see
        // VCardRfc::splitName()). FN is still emitted verbatim for the platforms
        // that do read it.
        $n = VCardRfc::splitName((string)$name);

        $lines = ['BEGIN:VCARD', 'VERSION:3.0'];
        $lines[] = 'N;CHARSET=UTF-8:' . $esc($n['family']) . ';' . $esc($n['given']) . ';'
                 . $esc($n['middle']) . ';;';
        $lines[] = 'FN;CHARSET=UTF-8:' . $esc($name);
        // ?? on every read: an Arabic-only scan carries no *_en keys at all and
        // the bare reads emitted "Undefined array key" warnings into the response.
        $company = ($parsed['company_en'] ?? '') ?: ($parsed['company_ar'] ?? '');
        if ($company !== '') {
            $lines[] = 'ORG;CHARSET=UTF-8:' . $esc($company);
        }
        $title = ($parsed['title_en'] ?? '') ?: ($parsed['title_ar'] ?? '');
        if ($title !== '') {
            $lines[] = 'TITLE;CHARSET=UTF-8:' . $esc($title);
        }
        foreach (($parsed['phones'] ?? []) as $p) {
            if (empty($p['number'])) continue;
            $lines[] = 'TEL;TYPE=' . self::telType($p['type'] ?? '') . ':' . $esc($p['number']);
        }
        foreach (($parsed['emails'] ?? []) as $e) {
            if ($e) $lines[] = 'EMAIL;TYPE=INTERNET:' . $esc($e);
        }
        if (!empty($parsed['website'])) $lines[] = 'URL:' . $esc($parsed['website']);
        // Same _en ?: _ar rule the name/org/title fields already use: an
        // Arabic-only card otherwise emitted no ADR at all.
        $address = ($parsed['address_en'] ?? '') ?: ($parsed['address_ar'] ?? '');
        if (!empty($address)) $lines[] = 'ADR;CHARSET=UTF-8;TYPE=WORK:;;' . $esc($address) . ';;;;';

        // Arabic goes to NOTE only when English holds the primary field;
        // on an Arabic-only card the *_ar value IS the FN/TITLE/ORG already.
        $noteParts = [];
        if (!empty($parsed['name_ar']) && !empty($parsed['name_en'])) $noteParts[] = $parsed['name_ar'];
        if (!empty($parsed['title_ar']) && !empty($parsed['title_en'])) $noteParts[] = $parsed['title_ar'];
        if (!empty($parsed['company_ar']) && !empty($parsed['company_en'])) $noteParts[] = $parsed['company_ar'];
        if ($note) $noteParts[] = $note;
        if ($noteParts) $lines[] = 'NOTE;CHARSET=UTF-8:' . $esc(implode(' | ', $noteParts));
        $lines[] = 'END:VCARD';

        // Fold AFTER escaping: escaping adds octets, so folding first would
        // leave over-length lines behind. RFC 2426 section 2.6.
        return implode($eol, VCardRfc::foldAll($lines, $eol)) . $eol;
    }
}
