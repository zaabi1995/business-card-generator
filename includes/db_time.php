<?php
/**
 * Database time helpers.
 *
 * Split out of functions.php because DatabaseAdapter and ScanIdentity write
 * timestamps with dbNow() without requiring anything that defines it. In
 * production functions.php always happens to be loaded first, so it worked; in
 * the unit tests it was not, and three suites died with "Call to undefined
 * function dbNow()" instead of testing anything. Any file that writes a
 * timestamp requires this directly now.
 */
if (!function_exists('dbNow')) {
/**
 * UTC timestamp string for writing into a DATETIME/TIMESTAMP column.
 */
function dbNow(?int $ts = null): string
{
    return gmdate('Y-m-d H:i:s', $ts ?? time());
}

/**
 * Parse a stored timestamp into a Unix epoch.
 *
 * A bare "Y-m-d H:i:s" out of MySQL carries no zone, and strtotime() would
 * read it as Asia/Muscat and land four hours early. Values that already carry
 * a Z or a +04:00 style offset are self-describing and pass through untouched,
 * which keeps this safe to apply to API and ISO 8601 strings too.
 *
 * Feed the result to date() to render it in the viewer's local zone.
 */
function dbTs($value): int
{
    if ($value === null || $value === '' || $value === '0000-00-00 00:00:00') {
        return 0;
    }
    if (is_int($value)) {
        return $value;
    }
    $s = trim((string) $value);
    if ($s === '') {
        return 0;
    }
    // Already zone-qualified (…Z, …+04:00, …+0400): trust what it says.
    if (preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/', $s)) {
        return (int) strtotime($s);
    }
    // Only a bare absolute datetime gets the UTC assumption. Relative phrases
    // ("now", "today", "+1 hour") are evaluated against the local clock on
    // purpose, and appending " UTC" to one shifts it by the offset instead of
    // labelling it: strtotime('now UTC') lands four hours out.
    if (!preg_match('/^\d{4}-\d{2}-\d{2}/', $s)) {
        return (int) strtotime($s);
    }
    return (int) strtotime($s . ' UTC');
}
}
