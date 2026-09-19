<?php
/**
 * ScanTextJev: structure the OCR text of one business card with TypeSafe Jev.
 *
 * The model fallback in api/scan/parse-text.php WRITES the fields, so it can
 * invent a value (the app then drops it by grounding). Jev never writes: code
 * splits the OCR into lines, takes phones, emails and the website with
 * patterns, and Jev only PICKS which line is the name, title, company and
 * address. Every value returned is a line of the card, verbatim.
 *
 * Arabic questions only see lines with Arabic letters and English questions
 * only lines with Latin letters: without that, Jev put "Section Manager" in
 * title_ar. A line holding an email, a website or a phone number is never an
 * option. A pick is kept at confidence 0.6 or more.
 *
 * Tested 19 Sep 2026 on 60 real scanned cards: Arabic fields 58-59/60 right;
 * most English disagreements were errors in the old model parse (a slogan as
 * the person's name, the company and the name swapped) that Jev did not copy.
 *
 * parse() returns null when Jev is off or down, or when it finds no name in
 * either language: the caller then uses the model fallback, as before.
 */

require_once __DIR__ . '/JevClient.php';

class ScanTextJev
{
    public const MIN = 0.6;
    private const MAX_LINES = 40;

    private const QUESTIONS = [
        'name_en' => 'Which line of `card_lines` is the name of the person on the card, in English letters? A job title, a department, a slogan or a company is not a name.',
        'name_ar' => 'Which line of `card_lines` is the name of the person on the card, in Arabic letters? A job title, a department, a slogan or a company is not a name.',
        'title_en' => "Which line of `card_lines` is the person's job title or position, in English letters?",
        'title_ar' => "Which line of `card_lines` is the person's job title or position, in Arabic letters?",
        'company_en' => 'Which line of `card_lines` is the company or organisation name, in English letters?',
        'company_ar' => 'Which line of `card_lines` is the company or organisation name, in Arabic letters?',
        'address_en' => 'Which line of `card_lines` is the street address or location (building, street, area, city, P.O. Box), in English letters?',
        'address_ar' => 'Which line of `card_lines` is the street address or location (building, street, area, city, P.O. Box), in Arabic letters?',
    ];

    /** @return string[] trimmed, de-duplicated card lines */
    public static function lines(string $text): array
    {
        $out = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = trim(preg_replace('/\s+/u', ' ', $line) ?? '');
            if ($line === '' || isset($out[$line])) continue;
            $out[$line] = mb_substr($line, 0, 120);
            if (count($out) >= self::MAX_LINES) break;
        }
        return array_values($out);
    }

    public static function hasArabic(string $s): bool
    {
        return (bool) preg_match('/\p{Arabic}/u', $s);
    }

    public static function hasLatin(string $s): bool
    {
        return (bool) preg_match('/[A-Za-z]/', $s);
    }

    private static function asciiDigits(string $s): string
    {
        return strtr($s, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);
    }

    /** @return string[] */
    public static function emails(string $text): array
    {
        preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $text, $m);
        return array_values(array_unique($m[0]));
    }

    public static function website(string $text): string
    {
        $noEmail = preg_replace('/\S+@\S+/', ' ', $text) ?? $text;
        if (preg_match('/(?:https?:\/\/)?(?:www\.)?[a-z0-9\-]+(?:\.[a-z0-9\-]+)*\.(?:com|om|net|org|ae|sa|qa|kw|bh|co|io|jp|uz|ru|info|biz|gov|edu|uk|in|cn)(?:\.[a-z]{2})?(?:\/[^\s]*)?/i', $noEmail, $m)) {
            return rtrim($m[0], '.,;');
        }
        return '';
    }

    /** @return array<int, array{number: string, type: string}> */
    public static function phones(array $lines): array
    {
        $out = [];
        foreach ($lines as $line) {
            $ascii = self::asciiDigits($line);
            if (!preg_match_all('/\+?\d[\d\s\-().\/]{6,}\d/', $ascii, $m)) continue;
            $type = 'mobile';
            if (preg_match('/fax|فاكس|\bF\s*[:.]/i', $ascii)) $type = 'fax';
            elseif (preg_match('/\btel\b|phone|office|هاتف|\bT\s*[:.]|\bP\s*[:.]/i', $ascii)) $type = 'work';
            foreach ($m[0] as $number) {
                $digits = preg_replace('/\D/', '', $number);
                if (strlen($digits) < 7 || strlen($digits) > 15) continue;
                $out[] = ['number' => trim($number), 'type' => $type];
                if (count($out) >= 10) return $out;
            }
        }
        return $out;
    }

    /** A line that carries an email, a website or a phone number is never a name, title or company. */
    public static function isContactLine(string $line): bool
    {
        if (strpos($line, '@') !== false) return true;
        if (self::website($line) !== '') return true;
        return strlen(preg_replace('/\D/', '', self::asciiDigits($line))) >= 7;
    }

    /** The Jev questions, each with the lines of the right script as options. */
    public static function questions(array $lines): array
    {
        $questions = [];
        foreach (self::QUESTIONS as $key => $instructions) {
            $arabic = substr($key, -3) === '_ar';
            $criteria = ['none' => 'No line of the card is this'];
            foreach ($lines as $i => $line) {
                if (self::isContactLine($line)) continue;
                $ok = $arabic ? self::hasArabic($line) : (self::hasLatin($line) && !self::hasArabic($line));
                if ($ok) $criteria['l' . $i] = $line;
            }
            $questions[$key] = ['type' => 'choice', 'instructions' => $instructions, 'criteria' => $criteria];
        }
        return $questions;
    }

    /** @return array|null the parsed shape of api/scan/parse-text.php, or null */
    public static function parse(string $text, ?callable $ask = null): ?array
    {
        $lines = self::lines($text);
        if (!$lines) return null;
        $ask = $ask ?? [JevClient::class, 'ask'];
        $answers = $ask(['card_lines' => $lines], self::questions($lines), 'jev:scan-text');
        if (!is_array($answers)) return null;

        $parsed = [];
        foreach (array_keys(self::QUESTIONS) as $key) {
            $a = $answers[$key] ?? null;
            $choice = is_array($a) ? (string) ($a['choice'] ?? '') : '';
            $conf = is_array($a) ? (float) ($a['confidence'] ?? 0) : 0.0;
            $value = '';
            if ($conf >= self::MIN && preg_match('/^l(\d+)$/', $choice, $m) && isset($lines[(int) $m[1]])) {
                $value = $lines[(int) $m[1]];
            }
            $parsed[$key] = $value;
        }
        if ($parsed['name_en'] === '' && $parsed['name_ar'] === '') return null;

        return [
            'name_en' => $parsed['name_en'],
            'name_ar' => $parsed['name_ar'],
            'title_en' => $parsed['title_en'],
            'title_ar' => $parsed['title_ar'],
            'company_en' => $parsed['company_en'],
            'company_ar' => $parsed['company_ar'],
            'phones' => self::phones($lines),
            'emails' => self::emails($text),
            'website' => self::website(implode("\n", $lines)),
            'address_en' => $parsed['address_en'],
            'address_ar' => $parsed['address_ar'],
            'card_type' => 'business',
        ];
    }
}
