<?php
/**
 * WcMatches - today's World Cup fixtures + yesterday's results for the
 * "Matches" wallet pass (and anywhere else that wants the day's schedule).
 *
 * Mirrors /opt/wc2026-daily/send_daily.py: the "matchday" is anchored to the
 * North-America day (America/New_York), the same anchor ESPN groups a matchday
 * under, so a user in Asia/Muscat sees the same set of fixtures the daily
 * WhatsApp digest lists. Kickoff times are then rendered in the USER's tz.
 *
 * Source of truth = wc_matches (espn_id, home, away, kickoff_utc, state, scores).
 */
class WcMatches
{
    public const ANCHOR_TZ = 'America/New_York';
    public const TOURNEY_END = '2026-07-19';

    /** Resolve a usable DateTimeZone from a possibly-bad tz name. */
    public static function tzOf(?string $name): DateTimeZone
    {
        try { return new DateTimeZone($name ?: 'Asia/Muscat'); }
        catch (Throwable $e) { return new DateTimeZone('Asia/Muscat'); }
    }

    /**
     * The current matchday as a Y-m-d string in the North-America anchor tz.
     * @param DateTime|null $now override "now" (UTC) for tests.
     */
    public static function currentMatchday(?DateTime $now = null): string
    {
        $now = $now ? (clone $now) : new DateTime('now', new DateTimeZone('UTC'));
        $now->setTimezone(new DateTimeZone(self::ANCHOR_TZ));
        return $now->format('Y-m-d');
    }

    /**
     * All fixtures whose kickoff falls on a given matchday (anchor-tz date).
     * Ordered by kickoff. Returns rows from wc_matches.
     */
    public static function fixturesOn(string $matchday): array
    {
        $tz = new DateTimeZone(self::ANCHOR_TZ);
        $start = new DateTime($matchday . ' 00:00:00', $tz);
        $end   = (clone $start)->modify('+1 day');
        // Compare in UTC against kickoff_utc.
        $startUtc = (clone $start)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $endUtc   = (clone $end)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        return Database::getInstance()->fetchAll(
            "SELECT espn_id, home, away, kickoff_utc, state, home_score, away_score
             FROM wc_matches
             WHERE kickoff_utc >= :a AND kickoff_utc < :b
             ORDER BY kickoff_utc ASC",
            ['a'=>$startUtc, 'b'=>$endUtc]
        );
    }

    /**
     * Find the next matchday (anchor-tz date string) on/after $fromDay that has
     * at least one fixture, scanning up to $maxAhead days. Null if none.
     */
    public static function nextMatchdayWithFixtures(string $fromDay, int $maxAhead = 8): ?array
    {
        $tz = new DateTimeZone(self::ANCHOR_TZ);
        $d  = new DateTime($fromDay . ' 00:00:00', $tz);
        for ($i = 0; $i <= $maxAhead; $i++) {
            $day = $d->format('Y-m-d');
            if ($day > self::TOURNEY_END) return null;
            $fx = self::fixturesOn($day);
            if ($fx) return ['day'=>$day, 'fixtures'=>$fx];
            $d->modify('+1 day');
        }
        return null;
    }

    /**
     * Full day view for a user: today's fixtures (or the next matchday's) +
     * yesterday's results, all kickoff times rendered in the user's tz.
     *
     * Returns:
     *   matchday   string Y-m-d (anchor day actually shown)
     *   is_today   bool   true if the shown matchday == current anchor day
     *   over       bool   tournament concluded, nothing ahead
     *   today      array  [ ['home','away','ko'(DateTime user-tz),'state','hs','as','label'] ]
     *   yesterday  array  same shape, post-state results only
     *   tz         string the user tz used
     */
    public static function dayView(array $user, ?DateTime $now = null): array
    {
        $tz      = self::tzOf($user['tz'] ?? null);
        $anchor  = self::currentMatchday($now);
        $todayFx = self::fixturesOn($anchor);
        $shownDay = $anchor;
        $isToday  = true;
        $over     = false;

        if (!$todayFx) {
            $nd = self::nextMatchdayWithFixtures($anchor);
            if ($nd) {
                $shownDay = $nd['day'];
                $todayFx  = $nd['fixtures'];
                $isToday  = false;
            } else {
                $over = true;
            }
        }

        // Yesterday = the calendar day before the SHOWN day (anchor-tz),
        // results only (state=post).
        $ydayDate = (new DateTime($shownDay . ' 00:00:00', new DateTimeZone(self::ANCHOR_TZ)))
            ->modify('-1 day')->format('Y-m-d');
        $ydayFx = array_values(array_filter(self::fixturesOn($ydayDate), function ($m) {
            return ($m['state'] ?? '') === 'post';
        }));

        $shape = function (array $m) use ($tz): array {
            $ko = new DateTime((string)$m['kickoff_utc'], new DateTimeZone('UTC'));
            $ko->setTimezone($tz);
            return [
                'home'  => (string)$m['home'],
                'away'  => (string)$m['away'],
                'ko'    => $ko,
                'state' => (string)($m['state'] ?? 'pre'),
                'hs'    => (int)($m['home_score'] ?? 0),
                'as'    => (int)($m['away_score'] ?? 0),
            ];
        };

        return [
            'matchday'  => $shownDay,
            'is_today'  => $isToday,
            'over'      => $over,
            'today'     => array_map($shape, $todayFx),
            'yesterday' => array_map($shape, $ydayFx),
            'tz'        => $tz->getName(),
        ];
    }

    /** One-line label for a fixture in the user's tz, e.g. "Mon 18:00 - Spain v Brazil" or "FT Spain 2-1 Brazil". */
    public static function fixtureLine(array $m): string
    {
        $state = $m['state'];
        if ($state === 'post') {
            return 'FT  ' . $m['home'] . ' ' . $m['hs'] . '-' . $m['as'] . ' ' . $m['away'];
        }
        if ($state === 'in') {
            return 'LIVE ' . $m['home'] . ' ' . $m['hs'] . '-' . $m['as'] . ' ' . $m['away'];
        }
        /** @var DateTime $ko */
        $ko = $m['ko'];
        return $ko->format('D H:i') . '  ' . $m['home'] . ' v ' . $m['away'];
    }

    /**
     * A short content tag that changes whenever the day's pushable content
     * changes (matchday, fixtures, live scores, states). Used by the cron's
     * change detection so a device only re-downloads a pass that moved.
     */
    public static function updateTag(array $view): string
    {
        $sig = [$view['matchday'], $view['is_today'], $view['over']];
        foreach (['today','yesterday'] as $bucket) {
            foreach ($view[$bucket] as $m) {
                /** @var DateTime $ko */
                $ko = $m['ko'];
                $sig[] = $m['home'] . '|' . $m['away'] . '|' . $ko->format('Y-m-d H:i')
                    . '|' . $m['state'] . '|' . $m['hs'] . '-' . $m['as'];
            }
        }
        return substr(sha1(json_encode($sig)), 0, 16);
    }
}
