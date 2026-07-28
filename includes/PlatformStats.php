<?php
/**
 * Real, database-derived platform statistics for public marketing surfaces.
 *
 * Why this exists: until 28 Jul 2026 /about rendered four hardcoded figures
 * ("10K+ users", "500+ companies", "50K+ cards", "99.9% uptime"). Against the
 * database those were 100, 69, 1,637 and unsourced. They had been indexed, and
 * the meta description repeated "Serving 500+ companies."
 *
 * Never hardcode a public number again. Everything here is COUNT(*) at read
 * time behind a 6-hour cache, so the marketing copy cannot drift from reality.
 * A stat with no honest source does not get a tile.
 */
require_once __DIR__ . '/Cache.php';

class PlatformStats
{
    private const TTL = 21600; // 6h

    /** @return array{companies:int,employees:int,cards:int,events:int,directory:int} */
    public static function all(): array
    {
        return Cache::remember('platform:stats:v1', self::TTL, function () {
            return [
                'companies' => self::count('companies'),
                'employees' => self::count('employees'),
                'cards'     => self::count('generated_cards'),
                'events'    => self::count('card_events'),
                'directory' => self::count('om_companies'),
            ];
        });
    }

    private static function count(string $table): int
    {
        // Table name is a compile-time literal from the array above, never user input.
        try {
            $db = Database::getInstance()->getConnection();
            $st = $db->query("SELECT COUNT(*) FROM `{$table}`");
            return (int) $st->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * Render a count honestly. We deliberately do NOT round up to a
     * flattering "500+" style bucket: that is how the old numbers became
     * false. 1637 -> "1,637". Zero returns null so the caller can omit the
     * tile entirely rather than publish a proud "0".
     */
    public static function fmt(int $n): ?string
    {
        return $n > 0 ? number_format($n) : null;
    }
}
