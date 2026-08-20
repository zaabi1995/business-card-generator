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
 * time behind a cache, so the marketing copy cannot drift from reality.
 * A stat with no honest source does not get a tile.
 *
 * llm71-2, 5 Aug 2026: the cache used to hold for 6 hours, and `issuing` is
 * published as an EXACT, UNDATED number on six public surfaces (index.php,
 * intro.php, blog.php, about.php and the trust strip, in EN and AR). So every
 * time a tenant issued its first card the estate published the previous count
 * for up to six hours and numeric_gate went red on all six at once. r71 purged
 * the key by hand and everything went green in a minute, which resets the
 * window rather than closing it.
 *
 * Two changes close it, and the first is the one that matters:
 *
 *   1. WRITE-THROUGH INVALIDATION. Database::insert()/delete() drop this
 *      snapshot whenever a row lands in or leaves a table whose COUNT(*) is
 *      published (SOURCE_TABLES below). The hook lives in Database, not at the
 *      four separate `$db->insert('generated_cards', ...)` call sites, because
 *      a policy attached to one call site and not its twins is how these
 *      regress: the fifth insert site added next month would silently reopen
 *      the window.
 *   2. A SHORT TTL as the backstop for writes this process never sees, a
 *      direct SQL import or an edit made in another service. Six COUNT(*)
 *      queries every five minutes is not a cost worth a stale public number.
 *
 * Never widen numeric_gate's tolerance instead. That is how "500+" survived a
 * year.
 */
require_once __DIR__ . '/Cache.php';

class PlatformStats
{
    /**
     * Backstop only; the real freshness comes from write-through invalidation.
     * Was 21600 (6h) until llm71-2.
     */
    private const TTL = 300; // 5 min

    public const CACHE_KEY = 'platform:stats:v3'; // v3: adds issued_cards / issued_people

    /**
     * Tables whose row count this class publishes on a public page. A write to
     * any of them moves a live number, so the snapshot is dropped at the write.
     * Keep this list in step with all() below: a count published from a table
     * that is not named here goes stale for a full TTL.
     */
    public const SOURCE_TABLES = [
        'companies', 'employees', 'generated_cards', 'card_events', 'om_companies',
    ];

    /**
     * Tenants that are actually using the product, as opposed to tenants that
     * merely exist. r20-28: "Join the 64 Omani companies using Cardify" was a
     * live COUNT(*) on `companies`, and therefore honest to its query and wrong
     * about its meaning: of those rows, 12 had never created a single employee
     * card, three were demo fixtures, six were BHD's own group entities, and
     * several were throwaway signups. A live query pointed at the wrong table
     * is the same defect class as a hardcoded number.
     *
     * The population this claims: has issued at least one card, is not a demo
     * or showcase fixture, and is not us.
     */
    private const NOT_A_CUSTOMER = [
        'BHD Group', 'BHD Oman', 'CupsByAA', 'Paper and Pen Company',
        'Cardify', 'Adnan Haider Darwish', 'Bin Haider Darwish LLC',
    ];

    /** @return array{companies:int,issuing:int,employees:int,cards:int,events:int,directory:int,issued_cards:int,issued_people:int} */
    public static function all(): array
    {
        return Cache::remember(self::CACHE_KEY, self::TTL, function () {
            return [
                'companies' => self::count('companies'),
                'issuing'   => self::issuingCompanies(),
                // Same population as `issuing`, so the three numbers can sit in
                // one sentence without contradicting each other. `cards` and
                // `employees` below are raw table counts and are deliberately
                // NOT published anywhere; they include demo fixtures and BHD's
                // own entities, and would inflate the claim by ~200 cards.
                'issued_cards'  => self::issuedScalar('COUNT(g.id)'),
                'issued_people' => self::issuedScalar('COUNT(DISTINCT e.id)'),
                'employees' => self::count('employees'),
                'cards'     => self::count('generated_cards'),
                'events'    => self::count('card_events'),
                'directory' => self::count('om_companies'),
            ];
        });
    }

    /**
     * Drop the snapshot. Called from Database on every write to a SOURCE_TABLE;
     * safe to call from anywhere else that moves one of these populations.
     */
    public static function invalidate(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /** True when a write to $table moves a number this class publishes. */
    public static function publishes(string $table): bool
    {
        return in_array(strtolower(trim($table, " \t\n\r`\"'")), self::SOURCE_TABLES, true);
    }

    /**
     * One scalar over the same population issuingCompanies() counts: has issued
     * at least one card, is not a demo or showcase fixture, and is not us.
     * Kept as one query shape so "N cards for M people across K companies"
     * cannot drift into three different definitions of who counts.
     */
    private static function issuedScalar(string $selectExpr): int
    {
        try {
            $db = Database::getInstance()->getConnection();
            $ph = implode(',', array_fill(0, count(self::NOT_A_CUSTOMER), '?'));
            $st = $db->prepare(
                "SELECT {$selectExpr} FROM companies c
                   JOIN employees e ON e.company_id = c.id
                   JOIN generated_cards g ON g.employee_id = e.id
                  WHERE c.id NOT LIKE '%demo%'
                    AND c.id NOT LIKE '%showcase%'
                    AND c.name NOT IN ({$ph})"
            );
            $st->execute(self::NOT_A_CUSTOMER);
            return (int) $st->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }

    private static function issuingCompanies(): int
    {
        try {
            $db = Database::getInstance()->getConnection();
            $ph = implode(',', array_fill(0, count(self::NOT_A_CUSTOMER), '?'));
            $st = $db->prepare(
                "SELECT COUNT(DISTINCT c.id) FROM companies c
                   JOIN employees e ON e.company_id = c.id
                   JOIN generated_cards g ON g.employee_id = e.id
                  WHERE c.id NOT LIKE '%demo%'
                    AND c.id NOT LIKE '%showcase%'
                    AND c.name NOT IN ({$ph})"
            );
            $st->execute(self::NOT_A_CUSTOMER);
            return (int) $st->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
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
