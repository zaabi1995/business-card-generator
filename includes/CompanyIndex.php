<?php
/**
 * ONE definition of "the companies in the Oman Business Index".
 *
 * r6-99: the Dataset node on /oman-business-index described "N enterprises"
 * from `SELECT COUNT(*) FROM om_companies`, while sitemap-companies.xml
 * listed a row per `SELECT slug, updated_at FROM om_companies`. Two queries,
 * two populations, no shared definition: the day a row lands with an empty
 * slug (or a row is soft-hidden), the sitemap and the schema disagree and the
 * Dataset publishes a count no crawlable page corroborates. They agree today
 * at 2,502 by coincidence of both being unfiltered, not by construction.
 *
 * Both callers now go through here. A row without a slug has no page, so it
 * is not in the index and is not in the count: that is the definition, stated
 * once.
 */

final class CompanyIndex
{
    /** WHERE clause shared by every query below. A row with no slug has no URL. */
    private const WHERE = "WHERE slug IS NOT NULL AND TRIM(slug) <> ''";

    /**
     * Every indexed company, in sitemap order (large first, then insertion).
     *
     * @return array<int,array{slug:string,updated_at:string}>
     */
    public static function rows($db): array
    {
        if (!$db) {
            return [];
        }
        try {
            return $db->fetchAll(
                'SELECT slug, updated_at FROM om_companies ' . self::WHERE
                . ' ORDER BY size_bucket ASC, id ASC'
            );
        } catch (Throwable $e) {
            error_log('CompanyIndex::rows failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * How many companies the index publishes: exactly one per sitemap URL.
     *
     * Returns null when the DB is unreachable, so a caller can omit the claim
     * rather than print a 0 that the sitemap contradicts.
     */
    public static function count($db): ?int
    {
        if (!$db) {
            return null;
        }
        try {
            $row = $db->fetchOne('SELECT COUNT(*) AS c FROM om_companies ' . self::WHERE);
            return $row ? (int) $row['c'] : null;
        } catch (Throwable $e) {
            error_log('CompanyIndex::count failed: ' . $e->getMessage());
            return null;
        }
    }
}
