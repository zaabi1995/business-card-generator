<?php
/**
 * RateLimiter, atomic per-action / per-IP / per-time-window counters.
 *
 * Uses a single `rate_limits` table with UNIQUE(action, ip, bucket) so
 * `INSERT ... ON DUPLICATE KEY UPDATE` gives us check+increment in one atomic
 * statement, no TOCTOU race between SELECT and INSERT.
 *
 * Usage:
 *   if (!RateLimiter::check('offer_redeem:' . $offerId, $ip, 10, 3600)) {
 *       http_response_code(429); exit;
 *   }
 *
 * Design notes:
 *  - Bucket granularity = the provided window, expressed in seconds-since-epoch
 *    integer-divided by window. That means a single counter per bucket, rolling
 *    at the boundary. "5 per hour" = at most 5 increments per HH:00–HH:59 block
 *    (so worst case a burst of 10 straddling the boundary is possible, that's
 *    acceptable for abuse throttling vs. a sliding-window cost).
 *  - Garbage collection: a lightweight DELETE happens on ~1% of writes.
 *
 * Created April 2026 for Codex round-2 findings.
 */

class RateLimiter
{
    /** How often (1 in N calls) to opportunistically prune old rows. */
    const GC_PROBABILITY = 100;

    /**
     * Atomically increment the counter for ($action, $ip, current_bucket).
     * Returns true if incremented counter is ≤ $limit, false if limit exceeded.
     *
     * @param string $action   logical action key, e.g. "offer_redeem:123"
     * @param string $ip       client IP (use getClientIp())
     * @param int    $limit    max count allowed in the window
     * @param int    $windowSec window size in seconds
     * @return bool true = allowed, false = blocked (429-worthy)
     */
    public static function check(string $action, string $ip, int $limit, int $windowSec): bool
    {
        if ($limit <= 0 || $windowSec <= 0) {
            return true;
        }
        try {
            $db = Database::getInstance();
            if (!$db->isConnected()) {
                return true; // fail-open, don't block legit users on DB hiccup
            }
            $pdo = $db->getConnection();
            $bucket = (int) floor(time() / $windowSec);
            $action = substr($action, 0, 64);
            $ip     = substr($ip, 0, 64);

            // Atomic increment: new row starts at 1, existing row bumps count.
            $stmt = $pdo->prepare(
                "INSERT INTO rate_limits (action, ip, bucket, count, window_sec, updated_at)
                 VALUES (:a, :ip, :b, 1, :w, NOW())
                 ON DUPLICATE KEY UPDATE
                   count = count + 1,
                   updated_at = NOW()"
            );
            $stmt->execute([
                ':a'  => $action,
                ':ip' => $ip,
                ':b'  => $bucket,
                ':w'  => $windowSec,
            ]);

            // Read the value we just incremented to.
            $sel = $pdo->prepare(
                "SELECT count FROM rate_limits
                  WHERE action = :a AND ip = :ip AND bucket = :b"
            );
            $sel->execute([':a' => $action, ':ip' => $ip, ':b' => $bucket]);
            $count = (int) $sel->fetchColumn();

            // Opportunistic GC of rows whose bucket is > 2 windows old.
            if (random_int(1, self::GC_PROBABILITY) === 1) {
                self::gc($pdo);
            }

            return $count <= $limit;
        } catch (Throwable $e) {
            error_log('RateLimiter::check failed: ' . $e->getMessage());
            return true; // fail-open
        }
    }

    /**
     * Decrement the most-recent-bucket counter for ($action, $ip) by 1.
     * Used to "refund" a rate-limit slot when a subsequent validation (not the
     * rate limit itself) fails, so repeated legit retries don't lock the user
     * out on misspelled input.
     *
     * Safe no-op if no row exists.
     */
    public static function refund(string $action, string $ip, int $windowSec): void
    {
        if ($windowSec <= 0) {
            return;
        }
        try {
            $db = Database::getInstance();
            if (!$db->isConnected()) {
                return;
            }
            $bucket = (int) floor(time() / $windowSec);
            $stmt = $db->getConnection()->prepare(
                "UPDATE rate_limits
                    SET count = GREATEST(count - 1, 0)
                  WHERE action = :a AND ip = :ip AND bucket = :b"
            );
            $stmt->execute([
                ':a'  => substr($action, 0, 64),
                ':ip' => substr($ip, 0, 64),
                ':b'  => $bucket,
            ]);
        } catch (Throwable $e) {
            error_log('RateLimiter::refund failed: ' . $e->getMessage());
        }
    }

    /**
     * Prune rows older than 2 * window_sec (worst case ~2 hours for a 1hr
     * bucket). Best-effort, fires on ~1% of writes.
     */
    private static function gc(PDO $pdo): void
    {
        try {
            // Rows are considered prune-worthy when their updated_at is older
            // than twice their window_sec. Cheap on a small rolling table.
            $pdo->exec(
                "DELETE FROM rate_limits
                  WHERE updated_at < (NOW() - INTERVAL 6 HOUR)
                  LIMIT 500"
            );
        } catch (Throwable $e) {
            // ignore
        }
    }
}
