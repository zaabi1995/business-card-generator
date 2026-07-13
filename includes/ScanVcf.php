<?php
// ScanVcf: builds a vCard 3.0 from a parsed scan. CRLF line endings per RFC 2426.
class ScanVcf {

    public static function build(array $parsed, ?string $note = null): string {
        $eol = "\r\n";
        $esc = function ($v) {
            // Backslash first: it is the escape character per RFC 2426, so
            // escaping it later would double-escape the slashes added below.
            return str_replace(['\\', ';', ',', "\n"], ['\\\\', '\\;', '\\,', '\\n'], trim((string)$v));
        };
        $name = $parsed['name_en'] ?: ($parsed['name_ar'] ?? '');
        $parts = preg_split('/\s+/', trim($name));
        $family = count($parts) > 1 ? array_pop($parts) : '';
        $given = implode(' ', $parts);

        $lines = ['BEGIN:VCARD', 'VERSION:3.0'];
        $lines[] = 'N;CHARSET=UTF-8:' . $esc($family) . ';' . $esc($given) . ';;;';
        $lines[] = 'FN;CHARSET=UTF-8:' . $esc($name);
        if (!empty($parsed['company_en']) || !empty($parsed['company_ar'])) {
            $lines[] = 'ORG;CHARSET=UTF-8:' . $esc($parsed['company_en'] ?: $parsed['company_ar']);
        }
        if (!empty($parsed['title_en']) || !empty($parsed['title_ar'])) {
            $lines[] = 'TITLE;CHARSET=UTF-8:' . $esc($parsed['title_en'] ?: $parsed['title_ar']);
        }
        $typeMap = ['mobile' => 'CELL', 'work' => 'WORK', 'fax' => 'FAX'];
        foreach (($parsed['phones'] ?? []) as $p) {
            if (empty($p['number'])) continue;
            $t = $typeMap[$p['type'] ?? 'mobile'] ?? 'CELL';
            $lines[] = 'TEL;TYPE=' . $t . ':' . $esc($p['number']);
        }
        foreach (($parsed['emails'] ?? []) as $e) {
            if ($e) $lines[] = 'EMAIL;TYPE=INTERNET:' . $esc($e);
        }
        if (!empty($parsed['website'])) $lines[] = 'URL:' . $esc($parsed['website']);
        if (!empty($parsed['address_en'])) $lines[] = 'ADR;CHARSET=UTF-8;TYPE=WORK:;;' . $esc($parsed['address_en']) . ';;;;';

        // Arabic goes to NOTE only when English holds the primary field;
        // on an Arabic-only card the *_ar value IS the FN/TITLE/ORG already.
        $noteParts = [];
        if (!empty($parsed['name_ar']) && !empty($parsed['name_en'])) $noteParts[] = $parsed['name_ar'];
        if (!empty($parsed['title_ar']) && !empty($parsed['title_en'])) $noteParts[] = $parsed['title_ar'];
        if (!empty($parsed['company_ar']) && !empty($parsed['company_en'])) $noteParts[] = $parsed['company_ar'];
        if ($note) $noteParts[] = $note;
        if ($noteParts) $lines[] = 'NOTE;CHARSET=UTF-8:' . $esc(implode(' | ', $noteParts));
        $lines[] = 'END:VCARD';
        return implode($eol, $lines) . $eol;
    }
}
