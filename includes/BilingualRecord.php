<?php
/**
 * Cardify bilingual DB-record gate (award ledger llm75-1).
 *
 * THE DEFECT THIS EXISTS TO MAKE IMPOSSIBLE
 * -----------------------------------------
 * A row in `blog_posts` or `career_listings` carries its English text in
 * `title` / `excerpt` / `description` and its Arabic text, when it exists at
 * all, in `title_ar` / `excerpt_ar` / `description_ar`. Every render path that
 * selected the base columns and printed them shipped English prose inside a
 * document that declares <html lang="ar">. Measured live 5 Aug 2026:
 * cardify.om/ar/ carried 7 such blocks (three blog cards, title + blurb each,
 * plus the footer brand line) and cardify.om/ar/careers carried 1 (a whole job
 * listing, 141 Latin prose letters, zero Arabic).
 *
 * The page MEAN cannot see this: those two pages measure 0.856 and 0.841
 * Arabic share, comfortably above any floor, because the chrome is translated
 * and the untranslated records are a small fraction of the letters. Only a
 * per-BLOCK census sees it. So the mean is not the instrument, and the fix
 * cannot be "raise the floor".
 *
 * WHY A HELPER AND NOT AN `if` AT EACH CALL SITE
 * ----------------------------------------------
 * llm54-1 recorded the general rule the hard way: "a defect fixed by adding a
 * key to N call sites is not fixed, it is fixed at N call sites, and the N+1th
 * is written by someone reading the comment that says it was fixed." Any query
 * against a table with `_ar` columns goes through rows()/row() here, and the
 * refusal happens once, in one place, for every present and future caller.
 *
 * THE RULE
 * --------
 * On the default locale (en) a row passes through untouched.
 * On any other locale every requested field must have a non-blank
 * `<field>_<locale>` value. If ANY required twin is missing the WHOLE row is
 * refused, never partially translated: half an Arabic card is the same defect
 * in a smaller box, and it is harder to see.
 *
 * Refusal, not a fatal, on the web path: a missing translation must not 500 a
 * page for a human reader. Under CLI (gates, tools/verify-bilingual-records.php,
 * migrations) it THROWS instead, so a missing twin is loud exactly where
 * loudness is free. Every refusal is recorded in refusals() and error_log'd, so
 * "the section vanished" is never a silent event.
 *
 * WHAT A CALLER MUST NOT DO
 * -------------------------
 * Do not translate strings in place at the template. The r76 fencing.om round
 * proved the sibling trap: a block census renders two OPPOSITE defects
 * identically, (1) no Arabic exists (wants a translator) and (2) Arabic exists
 * and the English ships beside it (wants a deletion). Cardify is shape (1),
 * verified: zero en-only/ar-only markers in its markup. Shape (2) properties
 * need a response transform, not this class.
 */

final class BilingualRecord
{
    /** Locale whose values live in the BASE columns (no suffix). */
    public const DEFAULT_LOCALE = 'en';

    /** @var array<int,array{table:string,key:string,locale:string,missing:string}> */
    private static array $refusals = [];

    /**
     * Resolve a list of DB rows for the active locale, dropping any row whose
     * translation is incomplete.
     *
     * @param array<int,array<string,mixed>> $rows   rows as fetched
     * @param array<int,string>              $fields base column names that carry prose
     * @param string                         $table  named only so a refusal is traceable
     * @param string|null                    $locale defaults to the request locale
     * @param array<int,string>              $whenFilled columns required only when the
     *                                       BASE column carries text (see row())
     * @return array<int,array<string,mixed>> rows with $fields resolved to $locale
     */
    public static function rows(array $rows, array $fields, string $table = '?', ?string $locale = null, array $whenFilled = []): array
    {
        $out = [];
        foreach ($rows as $row) {
            $resolved = self::row($row, $fields, $table, $locale, $whenFilled);
            if ($resolved !== null) $out[] = $resolved;
        }
        return $out;
    }

    /**
     * Resolve one row, or return null when its twin is incomplete.
     *
     * $whenFilled names columns that are OPTIONAL in the record but must not be
     * half-translated: `requirements` on a job listing is allowed to be empty,
     * and then it renders nothing in either language, but a row that HAS English
     * requirements and no Arabic ones would print an English paragraph under an
     * Arabic heading. So the twin is demanded exactly when the base column
     * carries text, and the row is refused whole if it is missing.
     *
     * @param array<int,string> $whenFilled base columns required only when non-blank
     * @return array<string,mixed>|null
     */
    public static function row(array $row, array $fields, string $table = '?', ?string $locale = null, array $whenFilled = []): ?array
    {
        $locale = $locale ?? self::currentLocale();
        if ($locale === self::DEFAULT_LOCALE) return $row;

        $key = (string)($row['slug'] ?? $row['id'] ?? '(no key)');

        foreach ($whenFilled as $field) {
            if (array_key_exists($field, $row) && trim((string)$row[$field]) !== '') {
                $fields[] = $field;
            }
        }

        foreach ($fields as $field) {
            $col = $field . '_' . $locale;
            // array_key_exists, not isset: a column absent from the SELECT is a
            // caller bug (ask for it), a column present but NULL is missing data.
            if (!array_key_exists($col, $row)) {
                self::refuse($table, $key, $locale, $col . ' (not selected)');
                return null;
            }
            if (trim((string)$row[$col]) === '') {
                self::refuse($table, $key, $locale, $col);
                return null;
            }
        }

        foreach ($fields as $field) {
            $row[$field] = $row[$field . '_' . $locale];
        }
        $row['_locale'] = $locale;
        return $row;
    }

    /**
     * Every row refused during this request. A gate or a test asserts on this;
     * a template may use it to explain an empty section to an editor.
     *
     * @return array<int,array{table:string,key:string,locale:string,missing:string}>
     */
    public static function refusals(): array
    {
        return self::$refusals;
    }

    public static function resetRefusals(): void
    {
        self::$refusals = [];
    }

    private static function refuse(string $table, string $key, string $locale, string $missing): void
    {
        self::$refusals[] = [
            'table'   => $table,
            'key'     => $key,
            'locale'  => $locale,
            'missing' => $missing,
        ];
        $msg = sprintf(
            'BilingualRecord: refused %s[%s] on locale %s, missing %s',
            $table, $key, $locale, $missing
        );
        if (PHP_SAPI === 'cli') {
            // Gates, migrations and tools/. A throw here costs nothing and
            // turns a silent content hole into a failing exit code.
            throw new RuntimeException($msg);
        }
        error_log($msg);
    }

    private static function currentLocale(): string
    {
        if (function_exists('currentLocale')) return currentLocale();
        if (class_exists('I18n'))             return I18n::getLocale();
        return self::DEFAULT_LOCALE;
    }
}
