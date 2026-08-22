<?php
/**
 * Strict DateTime handling for structured data.
 *
 * MySQL timestamps in this application are stored in UTC. Bare database
 * values are therefore parsed as UTC, while ISO 8601 values must carry their
 * own Z or numeric offset. Relative phrases and date-only strings are refused.
 */
final class StructuredDataDate
{
    /**
     * Parse a factual database DateTime into a Unix timestamp.
     *
     * @param mixed $value
     */
    public static function databaseTimestamp($value): ?int
    {
        if ($value instanceof DateTimeInterface) {
            $timestamp = $value->getTimestamp();
            return $timestamp > 0 ? $timestamp : null;
        }
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '' || $value === '0000-00-00 00:00:00') {
            return null;
        }

        $utc = new DateTimeZone('UTC');
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value)) {
            return self::parseExact('!Y-m-d H:i:s', $value, $utc);
        }

        if (!preg_match(
            '/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.(\d{1,6}))?(Z|[+-]\d{2}:?\d{2})$/',
            $value,
            $match
        )) {
            return null;
        }

        $fraction = $match[2] ?? '';
        $offset = $match[3] === 'Z' ? '+00:00' : $match[3];
        if ($match[3] !== 'Z' && strlen($offset) === 5) {
            $offset = substr($offset, 0, 3) . ':' . substr($offset, 3, 2);
        }
        if ($match[3] !== 'Z') {
            $offsetHour = (int) substr($offset, 1, 2);
            $offsetMinute = (int) substr($offset, 4, 2);
            if ($offsetHour > 14 || $offsetMinute > 59
                || ($offsetHour === 14 && $offsetMinute !== 0)
            ) {
                return null;
            }
        }
        if ($fraction !== '') {
            $normalized = $match[1] . '.' . str_pad($fraction, 6, '0') . $offset;
            return self::parseExact('!Y-m-d\TH:i:s.uP', $normalized, $utc);
        }
        return self::parseExact('!Y-m-d\TH:i:sP', $match[1] . $offset, $utc);
    }

    /**
     * Format a Unix timestamp as an explicit UTC ISO 8601 DateTime.
     *
     * @param mixed $value
     */
    public static function fromUnixTimestamp($value): ?string
    {
        if (!is_int($value) || $value <= 0) {
            return null;
        }
        return gmdate('Y-m-d\TH:i:s\Z', $value);
    }

    /**
     * Normalize a database DateTime to explicit UTC ISO 8601.
     *
     * @param mixed $value
     */
    public static function fromDatabase($value): ?string
    {
        $timestamp = self::databaseTimestamp($value);
        return $timestamp === null ? null : self::fromUnixTimestamp($timestamp);
    }

    /**
     * Add dateModified only when the supplied value is a valid DateTime.
     *
     * @param mixed $value
     */
    public static function withDateModified(array $node, $value): array
    {
        unset($node['dateModified']);
        $normalized = self::fromDatabase($value);
        if ($normalized !== null) {
            $node['dateModified'] = $normalized;
        }
        return $node;
    }

    private static function parseExact(string $format, string $value, DateTimeZone $timezone): ?int
    {
        $date = DateTimeImmutable::createFromFormat($format, $value, $timezone);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false) {
            return null;
        }
        if (is_array($errors)
            && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0)
        ) {
            return null;
        }
        $timestamp = $date->getTimestamp();
        return $timestamp > 0 ? $timestamp : null;
    }
}
