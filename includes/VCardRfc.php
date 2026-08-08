<?php
/**
 * Shared RFC 2426 mechanics for BOTH vCard generators (includes/VCF.php, the
 * public/web path, and includes/ScanVcf.php, the scan/rolodex path).
 *
 * These two used to hand-roll the same rules independently and drifted apart.
 * Anything that has to be identical in the bytes we emit lives here, once.
 */
class VCardRfc {

    /**
     * Maximum octets on the wire per content line, EXCLUDING the line break.
     * RFC 2426 section 2.6.
     */
    const MAX_OCTETS = 75;

    /**
     * Fold one content line per RFC 2426 section 2.6.
     *
     * The rule is an OCTET rule, not a character rule: a content line SHOULD NOT
     * be longer than 75 octets excluding the line break. A longer line is folded
     * by inserting CRLF followed by a single white-space character. Unfolding
     * removes the CRLF and that one character, so the original octets come back
     * byte for byte.
     *
     * The trap this function exists for: Arabic is 2 octets per letter in UTF-8,
     * so an Omani street address blows past 75 octets in about 37 characters. A
     * naive substr() at octet 75 lands in the MIDDLE of a multi-byte sequence and
     * corrupts the text into replacement characters. RFC 6350 section 3.2 calls
     * this out by name. So we always back the fold point up to a UTF-8 character
     * boundary: a continuation byte is 10xxxxxx, i.e. (byte & 0xC0) === 0x80.
     *
     * Budgeting: the first segment gets the full 75 octets. Every continuation
     * line is emitted as " " + payload, and that leading space is part of the
     * line on the wire, so continuations only get 74 octets of payload.
     *
     * @param string $line Already-escaped content line ("PROP;PARAMS:value")
     * @param string $eol  Line break to insert at each fold point
     * @return string The line, folded, with no trailing line break
     */
    public static function fold($line, $eol = "\r\n") {
        $line = (string) $line;
        $len  = strlen($line); // strlen() counts octets, which is what the RFC counts

        if ($len <= self::MAX_OCTETS) {
            return $line;
        }

        $out   = '';
        $pos   = 0;
        $limit = self::MAX_OCTETS;

        while ($len - $pos > $limit) {
            $cut = $pos + $limit;

            // Walk back off any UTF-8 continuation byte so the cut lands between
            // characters. The $cut > $pos + 1 guard keeps at least one octet of
            // progress per iteration, so malformed input cannot spin forever.
            while ($cut > $pos + 1 && (ord($line[$cut]) & 0xC0) === 0x80) {
                $cut--;
            }

            $out .= substr($line, $pos, $cut - $pos) . $eol . ' ';
            $pos  = $cut;

            // Continuation lines pay one octet for their leading space.
            $limit = self::MAX_OCTETS - 1;
        }

        return $out . substr($line, $pos);
    }

    /**
     * Fold every line in a vCard.
     *
     * MUST run after escaping, never before: escaping adds octets (a comma
     * becomes "\,"), so folding first would produce over-length lines.
     *
     * @param array  $lines
     * @param string $eol
     * @return array
     */
    public static function foldAll(array $lines, $eol = "\r\n") {
        $folded = [];
        foreach ($lines as $line) {
            $folded[] = self::fold((string) $line, $eol);
        }
        return $folded;
    }

    /**
     * Tokens that mark a name as a PATRONYMIC CHAIN rather than a
     * given/middle/family name. Matched as whole tokens, never as substrings:
     * "Robin" contains "bin". "ben" is deliberately absent, it is a common given
     * name in its own right.
     */
    private static $patronymicParticles = [
        'bin', 'ibn', 'bint',
        'بن', 'ابن', 'بنت',
    ];

    /**
     * Split a printed full name into vCard N components.
     *
     * POLICY, and the measurements behind it:
     *
     * N is the property that matters. iOS does NOT read FN. Verified on a booted
     * iPhone 17 through CNContactVCardSerialization, the parser iOS Contacts
     * itself uses: a card carrying N:Watson;Mary;Jane plus
     * FN:ZZZ TOTALLY DIFFERENT parses to givenName "Mary", middleName "Jane",
     * familyName "Watson", and CNContactFormatter renders "Mary Jane Watson".
     * The FN string is discarded outright. There is no CNContact property for it.
     * We still emit FN because it costs nothing and other platforms do read it,
     * but it cannot be relied on to carry the display name on Apple devices.
     *
     * So the split has to be right, and "refuse to guess" is not a safe default:
     *
     *   1 token   -> given only, nothing to find.
     *
     *   2 tokens  -> given = first, family = second.
     *
     *   3+ tokens -> given = first, middle = the interior tokens, family = LAST.
     *               This is what Apple itself does. Fed a card with FN only and
     *               no N line at all, iOS's own fallback splitter produced
     *               given=Mary middle=Jane family=Watson. It is also right for
     *               the population this product serves: Omani names run 3-4
     *               tokens ENDING in the family name. Ali Adnan Haider Darwish
     *               is a Darwish, which is what the company name Bin Haider
     *               Darwish encodes. Abdul Rahman al-Balushi is an al-Balushi.
     *               Dropping that last token throws away the real family name
     *               and files the contact under the given name instead.
     *
     *   patronymic chain -> given = the WHOLE name, family = "".
     *               Detected by an explicit particle token (bin, ibn, bint, بن,
     *               ابن, بنت), NOT by counting tokens. In "علي بن عدنان بن حيدر
     *               درويش" the interior tokens are ancestors, not middle names,
     *               and the chain form means the final token is not reliably a
     *               family name. This is the narrow case where declining to
     *               guess is genuinely better than guessing.
     *
     * Callers holding EXPLICIT first_name/last_name columns must use those and
     * never call this.
     *
     * @param string $fullName
     * @return array ['given' => string, 'middle' => string, 'family' => string]
     */
    public static function splitName($fullName) {
        $fullName = (string) $fullName;

        // Collapse runs of whitespace. The /u pass handles Arabic and NBSP;
        // it returns null on invalid UTF-8, so fall back to the byte version.
        $name = preg_replace('/\s+/u', ' ', $fullName);
        if ($name === null) {
            $name = preg_replace('/\s+/', ' ', $fullName);
        }
        $name = trim((string) $name);

        if ($name === '') {
            return ['given' => '', 'middle' => '', 'family' => ''];
        }

        $parts = explode(' ', $name);

        // A patronymic chain is a chain, not a given/middle/family name.
        foreach ($parts as $token) {
            if (in_array(strtolower($token), self::$patronymicParticles, true)) {
                return ['given' => $name, 'middle' => '', 'family' => ''];
            }
        }

        if (count($parts) === 1) {
            return ['given' => $parts[0], 'middle' => '', 'family' => ''];
        }

        // Last token is the family name; anything between first and last is the
        // additional/middle name. Matches Apple's own fallback splitter.
        $family = array_pop($parts);
        $given  = array_shift($parts);

        return ['given' => $given, 'middle' => implode(' ', $parts), 'family' => $family];
    }
}
