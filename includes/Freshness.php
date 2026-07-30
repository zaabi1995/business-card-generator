<?php
/**
 * r6-95: cardify.om published no freshness signal at all: no dateModified in
 * schema and no visible "last updated" line on any page. A model asked how
 * current the page is has nothing to read, and Google has nothing to prefer.
 *
 * The date is taken from the mtime of the file that actually produced the
 * page, never from date('Y-m-d'). A today-stamp on every URL is a false
 * freshness signal: it tells a crawler the whole site changed every night,
 * which is both untrue and the exact pattern that gets freshness discounted.
 */
class Freshness
{
    /** Absolute path of the file whose mtime dates this page. */
    public static function sourceFile(): string
    {
        // A page rendered through router.php can name its own source with
        // $GLOBALS['pageSourceFile'] so the date follows the content, not the
        // front controller.
        $named = $GLOBALS['pageSourceFile'] ?? null;
        if (is_string($named) && $named !== '' && is_file($named)) {
            return $named;
        }
        $script = $_SERVER['SCRIPT_FILENAME'] ?? '';
        if (is_string($script) && $script !== '' && is_file($script)) {
            return $script;
        }
        return __FILE__;
    }

    /** ISO-8601 date (YYYY-MM-DD) of the page source, or null if unreadable. */
    public static function isoDate(): ?string
    {
        $mtime = @filemtime(self::sourceFile());
        if (!$mtime) {
            return null;
        }
        return gmdate('Y-m-d', $mtime);
    }

    /** Human date for the visible line, in the current locale's numerals. */
    public static function displayDate(): ?string
    {
        $mtime = @filemtime(self::sourceFile());
        if (!$mtime) {
            return null;
        }
        return gmdate('j F Y', $mtime);
    }
}
