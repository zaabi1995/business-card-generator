<?php
/**
 * Bulk employee import: which employee field does each spreadsheet column hold?
 *
 * The importer matches headers against exact synonym lists after stripping
 * every non-ASCII character, so an Arabic header ("الاسم") becomes empty and
 * "E-mail" becomes "e_mail"; the column is dropped, and a missing email column
 * stops the whole import. After the exact matches, Jev reads each column that
 * is still unmatched (its raw header plus three sample values) and picks one
 * field or none. It never overrides an exact match, needs confidence 0.7, and
 * one field goes to one column only. Jev down = the exact matches alone, as
 * before.
 */
require_once __DIR__ . '/JevClient.php';

class JevColumnMapper
{
    public const MIN_CONFIDENCE = 0.7;

    private const FIELDS = [
        'email'       => 'Work email address',
        'name_en'     => "Person's full name in English or Latin letters",
        'name_ar'     => "Person's full name in Arabic",
        'position_en' => 'Job title in English',
        'position_ar' => 'Job title in Arabic',
        'phone'       => 'Office or landline phone number',
        'mobile'      => 'Mobile phone number',
        'company_en'  => 'Company name in English',
        'company_ar'  => 'Company name in Arabic',
        'website'     => 'Website address',
        'address_en'  => 'Office address in English',
        'address_ar'  => 'Office address in Arabic',
        'department'  => 'Department, division or section name',
        'none'        => 'Not a business card field: an ID, a date, a number of years, notes or anything else',
    ];

    /**
     * @param array $rawHeaders  headers as they appear in the file
     * @param array $columnMap   field => column index|false, from the exact matches
     * @param array $sampleRows  up to 3 data rows (numerically indexed)
     * @return array             the column map with unmatched fields filled where Jev is sure
     */
    public static function fill(array $rawHeaders, array $columnMap, array $sampleRows, ?callable $ask = null): array
    {
        $ask = $ask ?? [JevClient::class, 'ask'];
        $used = [];
        foreach ($columnMap as $idx) {
            if ($idx !== false && $idx !== null) $used[(int)$idx] = true;
        }
        $missing = array_filter(array_keys($columnMap), fn($f) => $columnMap[$f] === false && isset(self::FIELDS[$f]));
        if (!$missing) return $columnMap;

        $columns = [];
        $questions = [];
        foreach ($rawHeaders as $i => $h) {
            $h = trim(preg_replace('/^\xEF\xBB\xBF/', '', (string)$h));
            if ($h === '' || isset($used[$i])) continue;
            $samples = [];
            foreach (array_slice($sampleRows, 0, 3) as $row) {
                $v = trim((string)($row[$i] ?? ''));
                if ($v !== '') $samples[] = mb_substr($v, 0, 80);
            }
            $columns[] = ['index' => $i, 'header' => mb_substr($h, 0, 80), 'samples' => $samples];
            $questions['c' . $i] = [
                'type' => 'choice',
                'instructions' => "Which employee field does the column in `columns` with index $i hold, judged from its header and samples?",
                'criteria' => self::FIELDS,
            ];
            if (count($questions) >= 30) break;
        }
        if (!$questions) return $columnMap;

        $answers = $ask(['columns' => $columns], $questions, 'jev:import-columns');
        if (!is_array($answers)) return $columnMap;

        // Best column per missing field, highest confidence wins.
        $best = [];
        foreach ($columns as $col) {
            $a = $answers['c' . $col['index']] ?? null;
            if (!is_array($a)) continue;
            $field = (string)($a['choice'] ?? '');
            $conf = (float)($a['confidence'] ?? 0);
            if ($field === 'none' || !in_array($field, $missing, true) || $conf < self::MIN_CONFIDENCE) continue;
            if (!isset($best[$field]) || $conf > $best[$field][1]) $best[$field] = [$col['index'], $conf];
        }
        foreach ($best as $field => [$idx, $conf]) {
            $columnMap[$field] = $idx;
            error_log(sprintf('[import] Jev mapped column %d "%s" to %s (%.2f)', $idx, $rawHeaders[$idx] ?? '', $field, $conf));
        }
        return $columnMap;
    }
}
