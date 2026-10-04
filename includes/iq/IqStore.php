<?php
declare(strict_types=1);

require_once __DIR__ . '/IqEngine.php';

/*
 * Storage for the Cardify IQ test: attempts, the server clock, answers, users, the leaderboard.
 *
 * Who owns an attempt: a guest holds a private token in an httpOnly cookie (only its sha256 is
 * stored); a signed-in person owns it by user_id. A guest attempt is adopted by the account when
 * that browser signs in.
 *
 * The clock: one deadline for the whole test (started_ts + IQE_TEST_SECONDS). When it passes,
 * every question not yet answered counts as wrong and the attempt is scored (Raven's rule).
 *
 * The leaderboard counts each account's FIRST finished attempt only, and only when the person
 * stayed on the page (focus_lost <= IQ_BOARD_MAX_FOCUS_LOST). Retakes never replace it, so a
 * leaderboard place cannot be bought with practice.
 */
final class IqStore
{
    public const GUEST_COOKIE = 'cardify_iq';
    public const PER_IP_PER_DAY = 12;
    public const PER_DAY_TOTAL = 3000;
    public const FREE_RETAKE_DAYS = 30;
    public const BOARD_MAX_FOCUS_LOST = 2;
    public const AGE_MIN = 14;
    public const AGE_MAX = 90;
    public const NORM_MIN = 200;
    /** Share to unlock: this many people starting the test from a shared result opens its full report. */
    public const SHARE_UNLOCK = 1;
    public const REF_COOKIE = 'cardify_iq_ref';

    private static ?array $settings = null;

    /* ---------- settings ---------- */

    public static function setting(string $key): ?string
    {
        if (self::$settings === null) {
            self::$settings = [];
            try {
                foreach (Database::getInstance()->fetchAll('SELECT `key`, `value` FROM iq_settings') as $r) {
                    self::$settings[$r['key']] = $r['value'];
                }
            } catch (Throwable $e) {
                error_log('[iq] settings: ' . $e->getMessage());
            }
        }
        $v = self::$settings[$key] ?? null;
        return ($v === null || $v === '') ? null : (string)$v;
    }

    public static function setSetting(string $key, ?string $value): void
    {
        Database::getInstance()->getConnection()->prepare(
            'INSERT INTO iq_settings (`key`, `value`) VALUES (:k, :v) ON DUPLICATE KEY UPDATE `value` = :v2'
        )->execute([':k' => $key, ':v' => $value, ':v2' => $value]);
        self::$settings = null;
    }

    /* ---------- people ---------- */

    public static function userId(): ?string
    {
        return isset($_SESSION['iq_user_id']) && is_string($_SESSION['iq_user_id']) ? $_SESSION['iq_user_id'] : null;
    }

    public static function user(): ?array
    {
        $id = self::userId();
        if ($id === null) return null;
        $u = Database::getInstance()->fetchOne('SELECT * FROM iq_users WHERE id = :id', ['id' => $id]);
        return $u ?: null;
    }

    public static function isPro(?array $u): bool
    {
        return $u !== null && !empty($u['pro_until']) && strtotime((string)$u['pro_until']) > time();
    }

    /** Signs in by verified email or phone, creating the account the first time. */
    public static function signIn(string $identifier, string $channel): array
    {
        $db = Database::getInstance();
        $col = $channel === 'email' ? 'email' : 'phone';
        $u = $db->fetchOne("SELECT * FROM iq_users WHERE $col = :v", ['v' => $identifier]);
        if (!$u) {
            $id = generateUUID();
            $data = ['id' => $id, $col => $identifier, 'created_at' => date('Y-m-d H:i:s')];
            // Someone who already has a Cardify card is linked to it, so their name comes along.
            $emp = $col === 'email'
                ? $db->fetchOne('SELECT id, name_en FROM employees WHERE LOWER(email) = :v AND deleted_at IS NULL LIMIT 1', ['v' => strtolower($identifier)])
                : null;
            if ($emp) {
                $data['employee_id'] = $emp['id'];
                $data['display_name'] = mb_substr((string)$emp['name_en'], 0, 60);
            }
            $db->insert('iq_users', $data);
            $u = $db->fetchOne('SELECT * FROM iq_users WHERE id = :id', ['id' => $id]);
        }
        $db->update('iq_users', ['last_login_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $u['id']]);
        if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) session_regenerate_id(true);
        $_SESSION['iq_user_id'] = $u['id'];
        // This browser's guest attempts become the account's.
        $token = self::guestTokenHash();
        if ($token !== null) {
            $db->getConnection()->prepare('UPDATE iq_attempts SET user_id = :u WHERE guest_token = :t AND user_id IS NULL')
                ->execute([':u' => $u['id'], ':t' => $token]);
        }
        if ($u['display_name'] === '') {
            $named = $db->fetchOne("SELECT name FROM iq_attempts WHERE user_id = :u AND name <> '' ORDER BY id DESC LIMIT 1", ['u' => $u['id']]);
            if ($named) $db->update('iq_users', ['display_name' => $named['name']], 'id = :id', ['id' => $u['id']]);
        }
        return $u;
    }

    public static function signOut(): void
    {
        unset($_SESSION['iq_user_id']);
    }

    /* ---------- guest token ---------- */

    public static function guestTokenHash(): ?string
    {
        $raw = (string)($_COOKIE[self::GUEST_COOKIE] ?? '');
        return preg_match('/^[a-f0-9]{48}$/', $raw) ? hash('sha256', $raw) : null;
    }

    private static function issueGuestToken(): string
    {
        $raw = bin2hex(random_bytes(24));
        if (!headers_sent()) setcookie(self::GUEST_COOKIE, $raw, [
            'expires' => time() + 400 * 86400, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax',
        ]);
        $_COOKIE[self::GUEST_COOKIE] = $raw;
        return hash('sha256', $raw);
    }

    /* ---------- share to unlock ---------- */

    private static function ip(): string
    {
        return function_exists('getClientIp') ? (string)getClientIp() : (string)($_SERVER['REMOTE_ADDR'] ?? '');
    }

    /** A visitor arrived through a shared result (?ref=<public id>): remember it for 30 days. */
    public static function captureRef(string $pid): void
    {
        $a = self::byPublicId($pid);
        if (!$a || $a['status'] !== 'done' || self::owns($a)) return;
        if (!headers_sent()) setcookie(self::REF_COOKIE, $pid, [
            'expires' => time() + 30 * 86400, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax',
        ]);
        $_COOKIE[self::REF_COOKIE] = $pid;
    }

    /**
     * The shared result that brought this visitor, if it may be credited: not the visitor's own,
     * and not from the sharer's own connection (opening your link in another browser is not a share).
     */
    private static function referrer(): ?array
    {
        $pid = (string)($_COOKIE[self::REF_COOKIE] ?? '');
        $a = $pid !== '' ? self::byPublicId($pid) : null;
        if (!$a || self::owns($a)) return null;
        $uid = self::userId();
        if ($uid !== null && $a['user_id'] === $uid) return null;
        if ($a['ip'] !== null && $a['ip'] === substr(self::ip(), 0, 64)) return null;
        return $a;
    }

    /** People who started the test from this result, each connection counted once. */
    public static function referrals(array $a): int
    {
        $r = Database::getInstance()->fetchOne('SELECT COUNT(DISTINCT ip) AS n FROM iq_attempts WHERE ref_id = :id', ['id' => $a['id']]);
        return (int)$r['n'];
    }

    private static function creditReferrer(array $ref): void
    {
        if (self::referrals($ref) < self::SHARE_UNLOCK) return;
        Database::getInstance()->getConnection()->prepare(
            "UPDATE iq_attempts SET report_paid = 1, unlocked_by = 'share' WHERE id = :id AND report_paid = 0"
        )->execute([':id' => $ref['id']]);
    }

    /* ---------- attempts ---------- */

    public static function byPublicId(string $pid): ?array
    {
        if (!preg_match('/^[A-Za-z0-9]{16}$/', $pid)) return null;
        $a = Database::getInstance()->fetchOne('SELECT * FROM iq_attempts WHERE public_id = :p', ['p' => $pid]);
        return $a ?: null;
    }

    /** The attempt this visitor is taking or last took: the account's, else this browser's. */
    public static function current(): ?array
    {
        $db = Database::getInstance();
        $uid = self::userId();
        if ($uid !== null) {
            $a = $db->fetchOne('SELECT * FROM iq_attempts WHERE user_id = :u ORDER BY id DESC LIMIT 1', ['u' => $uid]);
            if ($a) return $a;
        }
        $t = self::guestTokenHash();
        if ($t === null) return null;
        $a = $db->fetchOne('SELECT * FROM iq_attempts WHERE guest_token = :t', ['t' => $t]);
        return $a ?: null;
    }

    public static function owns(array $a): bool
    {
        $uid = self::userId();
        if ($uid !== null && $a['user_id'] === $uid) return true;
        $t = self::guestTokenHash();
        return $t !== null && hash_equals((string)$a['guest_token'], $t);
    }

    /** When this visitor may start again: null now, else a date. */
    public static function nextFreeStart(): ?string
    {
        $u = self::user();
        if (self::isPro($u)) return null;
        $db = Database::getInstance();
        $row = null;
        if ($u) {
            $row = $db->fetchOne("SELECT MAX(started_at) AS t FROM iq_attempts WHERE user_id = :u", ['u' => $u['id']]);
        } elseif (($t = self::guestTokenHash()) !== null) {
            $row = $db->fetchOne('SELECT started_at AS t FROM iq_attempts WHERE guest_token = :t', ['t' => $t]);
        }
        if (!$row || !$row['t']) return null;
        $next = strtotime((string)$row['t']) + self::FREE_RETAKE_DAYS * 86400;
        return $next > time() ? date('Y-m-d', $next) : null;
    }

    public static function start(string $name, int $age, ?string $country, string $lang): array
    {
        $db = Database::getInstance();
        $ip = self::ip();
        $since = date('Y-m-d H:i:s', time() - 86400);
        $n = $db->fetchOne('SELECT COUNT(*) AS n FROM iq_attempts WHERE ip = :ip AND started_at > :s', ['ip' => $ip, 's' => $since]);
        if ((int)$n['n'] >= self::PER_IP_PER_DAY) throw new IqError('rate_limited', 429);
        $n = $db->fetchOne('SELECT COUNT(*) AS n FROM iq_attempts WHERE started_at > :s', ['s' => $since]);
        if ((int)$n['n'] >= self::PER_DAY_TOTAL) throw new IqError('busy', 429);

        $uid = self::userId();
        $token = null;
        if ($uid === null) {
            // A guest's earlier attempt keeps its token row; a new attempt gets a fresh token.
            $token = self::issueGuestToken();
        }
        $ref = self::referrer();
        $now = microtime(true);
        $db->insert('iq_attempts', [
            'ref_id' => $ref['id'] ?? null,
            'public_id' => self::publicId(),
            'user_id' => $uid,
            'guest_token' => $token,
            'name' => $name,
            'age' => $age,
            'country' => $country,
            'lang' => $lang === 'ar' ? 'ar' : 'en',
            'ip' => substr($ip, 0, 64),
            'seed' => bin2hex(random_bytes(8)),
            'status' => 'in_progress',
            'started_ts' => $now,
            'started_at' => date('Y-m-d H:i:s', (int)$now),
        ]);
        $a = $db->fetchOne('SELECT * FROM iq_attempts WHERE id = :id', ['id' => $db->getConnection()->lastInsertId()]);
        if ($ref) self::creditReferrer($ref);
        return $a;
    }

    private static function publicId(): string
    {
        $abc = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $s = '';
        for ($i = 0; $i < 16; $i++) $s .= $abc[random_int(0, strlen($abc) - 1)];
        return $s;
    }

    public static function answers(int $attemptId): array
    {
        return Database::getInstance()->fetchAll('SELECT * FROM iq_answers WHERE attempt_id = :a ORDER BY idx', ['a' => $attemptId]);
    }

    /** Closed answers as [kind, level, right], for the estimate. */
    public static function items(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            if ($r['answered_ts'] !== null) $out[] = [$r['kind'], (int)$r['level'], (int)$r['correct'] === 1];
        }
        return $out;
    }

    private static function reload(array $a): array
    {
        return Database::getInstance()->fetchOne('SELECT * FROM iq_attempts WHERE id = :id', ['id' => $a['id']]);
    }

    public static function deadline(array $a): float
    {
        return (float)$a['started_ts'] + IQE_TEST_SECONDS;
    }

    /** Time is up: the question on screen and every unserved one count as wrong. */
    private static function expire(array $a): void
    {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        $now = microtime(true);
        $conn->prepare('UPDATE iq_answers SET answered_ts = :t, correct = 0, late = 1, ms = ROUND((:t2 - served_ts) * 1000)
                        WHERE attempt_id = :a AND answered_ts IS NULL')
            ->execute([':t' => $now, ':t2' => $now, ':a' => $a['id']]);
        $rows = self::answers((int)$a['id']);
        $items = self::items($rows);
        $ins = $conn->prepare('INSERT IGNORE INTO iq_answers (attempt_id, idx, kind, level, served_ts, answered_ts, correct, late, ms)
                               VALUES (:a, :i, :k, :l, :s, :t, 0, 1, 0)');
        for ($idx = count($rows); $idx < IQE_COUNT; $idx++) {
            $kind = IQE_SEQ[$idx];
            [$theta] = iqe_estimate($items);
            $level = iqe_next_level($kind, $theta);
            $ins->execute([':a' => $a['id'], ':i' => $idx, ':k' => $kind, ':l' => $level, ':s' => $now, ':t' => $now]);
            $items[] = [$kind, $level, false];
        }
    }

    /** Scores a finished attempt and stores it. */
    private static function score(array $a): array
    {
        $rows = self::answers((int)$a['id']);
        [$theta, $se] = iqe_estimate(self::items($rows));
        $domains = [];
        $correct = 0;
        foreach ($rows as $r) {
            $domains[$r['kind']] ??= [0, 0];
            $domains[$r['kind']][1]++;
            if ((int)$r['correct'] === 1) { $domains[$r['kind']][0]++; $correct++; }
        }
        Database::getInstance()->update('iq_attempts', [
            'theta' => round($theta, 4), 'theta_se' => round($se, 4), 'iq' => iqe_from_theta($theta),
            'correct' => $correct, 'domains' => json_encode($domains),
        ], 'id = :id', ['id' => $a['id']]);
        return self::reload($a);
    }

    /** Applies the clock, and finishes the attempt once every question is closed. */
    public static function settle(array $a): array
    {
        if ($a['status'] !== 'in_progress') return $a;
        if (microtime(true) >= self::deadline($a)) self::expire($a);
        $rows = self::answers((int)$a['id']);
        $closed = count(array_filter($rows, fn($r) => $r['answered_ts'] !== null));
        if ($closed >= IQE_COUNT) {
            Database::getInstance()->getConnection()->prepare(
                "UPDATE iq_attempts SET status = 'done', finished_at = :f WHERE id = :id AND status = 'in_progress'"
            )->execute([':f' => date('Y-m-d H:i:s'), ':id' => $a['id']]);
            $a = self::score(self::reload($a));
        }
        return self::reload($a);
    }

    /** The question in play, served now if new, with the test time left. Null when finished. */
    public static function serve(array $a): ?array
    {
        $rows = self::answers((int)$a['id']);
        $idx = count(array_filter($rows, fn($r) => $r['answered_ts'] !== null));
        if ($idx >= IQE_COUNT) return null;
        $open = null;
        foreach ($rows as $r) if ((int)$r['idx'] === $idx) $open = $r;
        if (!$open) {
            $kind = IQE_SEQ[$idx];
            [$theta] = iqe_estimate(self::items($rows));
            $level = iqe_next_level($kind, $theta);
            // INSERT IGNORE: two tabs asking at once must not serve the same question twice.
            Database::getInstance()->getConnection()->prepare(
                'INSERT IGNORE INTO iq_answers (attempt_id, idx, kind, level, served_ts) VALUES (:a, :i, :k, :l, :s)'
            )->execute([':a' => $a['id'], ':i' => $idx, ':k' => $kind, ':l' => $level, ':s' => microtime(true)]);
            $open = Database::getInstance()->fetchOne('SELECT * FROM iq_answers WHERE attempt_id = :a AND idx = :i', ['a' => $a['id'], 'i' => $idx]);
        }
        $q = iqe_question((string)$a['seed'], $idx, (string)$open['kind'], (int)$open['level']);
        unset($q['answer']);
        $q['index'] = $idx;
        $q['seconds'] = IQE_TEST_SECONDS;
        $q['remaining'] = max(0, round(self::deadline($a) - microtime(true), 1));
        return $q;
    }

    /** One answer. Late or invalid counts as wrong; a second answer to the same question is ignored. */
    public static function answer(array $a, $idx, $choice): void
    {
        if (!is_int($idx)) return;
        $db = Database::getInstance();
        $row = $db->fetchOne('SELECT * FROM iq_answers WHERE attempt_id = :a AND idx = :i', ['a' => $a['id'], 'i' => $idx]);
        if (!$row || $row['answered_ts'] !== null) return;
        $q = iqe_question((string)$a['seed'], $idx, (string)$row['kind'], (int)$row['level']);
        $now = microtime(true);
        $late = $now > self::deadline($a) + IQE_GRACE_SECONDS;
        $valid = is_int($choice) && $choice >= 0 && $choice < count($q['options']);
        $correct = !$late && $valid && $choice === $q['answer'];
        $db->getConnection()->prepare(
            'UPDATE iq_answers SET answered_ts = :t, choice = :c, correct = :ok, late = :l, ms = :ms
             WHERE attempt_id = :a AND idx = :i AND answered_ts IS NULL'
        )->execute([':t' => $now, ':c' => $valid ? $choice : null, ':ok' => $correct ? 1 : 0, ':l' => $late ? 1 : 0,
            ':ms' => (int)round(($now - (float)$row['served_ts']) * 1000), ':a' => $a['id'], ':i' => $idx]);
    }

    public static function focusLost(array $a): void
    {
        if ($a['status'] !== 'in_progress') return;
        Database::getInstance()->getConnection()->prepare('UPDATE iq_attempts SET focus_lost = focus_lost + 1 WHERE id = :id')
            ->execute([':id' => $a['id']]);
    }

    /** Practice questions: easy, fresh, never scored, answers included. */
    public static function practice(): array
    {
        $seed = 'practice:' . bin2hex(random_bytes(6));
        $out = [];
        foreach (['matrix', 'series', 'rotate'] as $i => $kind) {
            $q = iqe_question($seed, $i, $kind, 1);
            unset($q['seconds']);
            $out[] = $q;
        }
        return $out;
    }

    /* ---------- results ---------- */

    public static function result(array $a): array
    {
        $out = [
            'id' => $a['public_id'],
            'status' => $a['status'],
            'name' => $a['name'],
            'started_at' => $a['started_at'],
            'finished_at' => $a['finished_at'],
            'total' => IQE_COUNT,
            'correct' => $a['correct'] === null ? null : (int)$a['correct'],
            'focus_lost' => (int)$a['focus_lost'],
            'iq' => null,
        ];
        if ($a['status'] === 'done' && $a['iq'] !== null) {
            $theta = (float)$a['theta'];
            $se = (float)$a['theta_se'];
            $out['iq'] = (int)$a['iq'];
            $out['iq_low'] = iqe_from_theta($theta - 1.645 * $se);
            $out['iq_high'] = iqe_from_theta($theta + 1.645 * $se);
            $out['percentile'] = iqe_percentile($theta);
            $out['band'] = iqe_band((int)$a['iq']);
            $domains = [];
            foreach (json_decode((string)$a['domains'], true) ?: [] as $kind => [$right, $of]) {
                $domains[] = ['kind' => $kind, 'ar' => IQE_DOMAINS[$kind][0], 'en' => IQE_DOMAINS[$kind][1], 'correct' => $right, 'total' => $of];
            }
            $out['domains'] = $domains;
        }
        return $out;
    }

    public static function normed(): bool
    {
        return self::setting('calibration') !== null;
    }

    public static function finishedCount(): int
    {
        $r = Database::getInstance()->fetchOne("SELECT COUNT(*) AS n FROM iq_attempts WHERE status = 'done'");
        return (int)$r['n'];
    }

    /* ---------- leaderboard ---------- */

    /**
     * Each account's first finished attempt, if they stayed on the page and opted in.
     * $period: 'all' | 'month'. $country: ISO code or null.
     */
    public static function leaderboard(string $period = 'all', ?string $country = null, int $limit = 100): array
    {
        $where = ["u.leaderboard = 1", "a.focus_lost <= " . self::BOARD_MAX_FOCUS_LOST, "u.display_name <> ''"];
        $params = [];
        if ($period === 'month') { $where[] = 'a.finished_at >= :since'; $params['since'] = date('Y-m-01 00:00:00'); }
        if ($country !== null) { $where[] = 'u.country = :c'; $params['c'] = $country; }
        $sql = "SELECT u.display_name, u.country, a.iq, a.finished_at, a.public_id,
                       (u.pro_until IS NOT NULL AND u.pro_until > NOW()) AS pro
                FROM iq_attempts a
                JOIN iq_users u ON u.id = a.user_id
                JOIN (SELECT user_id, MIN(id) AS first_id FROM iq_attempts
                      WHERE status = 'done' AND user_id IS NOT NULL GROUP BY user_id) f ON f.first_id = a.id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY a.iq DESC, a.finished_at ASC
                LIMIT " . max(1, min(500, $limit));
        return Database::getInstance()->fetchAll($sql, $params);
    }

    /** The account's own place on the board, or null. */
    public static function boardPlace(string $userId): ?array
    {
        $db = Database::getInstance();
        $first = $db->fetchOne("SELECT * FROM iq_attempts WHERE user_id = :u AND status = 'done' ORDER BY id ASC LIMIT 1", ['u' => $userId]);
        if (!$first) return null;
        $eligible = (int)$first['focus_lost'] <= self::BOARD_MAX_FOCUS_LOST;
        $above = $db->fetchOne(
            "SELECT COUNT(*) AS n FROM iq_attempts a JOIN iq_users u ON u.id = a.user_id
             JOIN (SELECT user_id, MIN(id) AS first_id FROM iq_attempts WHERE status = 'done' AND user_id IS NOT NULL GROUP BY user_id) f ON f.first_id = a.id
             WHERE u.leaderboard = 1 AND u.display_name <> '' AND a.focus_lost <= " . self::BOARD_MAX_FOCUS_LOST . " AND a.iq > :iq",
            ['iq' => (int)$first['iq']]
        );
        return ['attempt' => $first, 'eligible' => $eligible, 'rank' => (int)$above['n'] + 1];
    }
}

final class IqError extends RuntimeException
{
    public function __construct(public readonly string $codeName, public readonly int $status = 400)
    {
        parent::__construct($codeName);
    }
}
