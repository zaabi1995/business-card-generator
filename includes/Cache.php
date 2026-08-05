<?php
/**
 * File-based Cache helper.
 *
 * Deliberately simple: no Redis dependency, no opcache coupling,
 * no PSR-16 conformance. Keys get SHA-256 hashed into directory
 * shards so a single `ls` never returns a million files.
 *
 * Storage: BASE_DIR/cache/{namespace}/{shard}/{hash}.json
 *
 * Semantics:
 *   Cache::get($key, $default)        returns value or $default
 *   Cache::put($key, $value, $ttlSec) writes, expires after $ttl
 *   Cache::remember($key, $ttl, $cb)  lazy compute-if-missing
 *   Cache::forget($key)               delete single
 *   Cache::flush($namespace='')       nuke a namespace
 *   Cache::gc()                       remove expired entries (cron)
 *
 * Safe against race conditions: writes go to .tmp + rename().
 * Safe across RTL-locale requests: keys are plain ASCII of what the
 * caller passes in; utf-8 content survives json_encode.
 *
 * Usage:
 *   $logos = Cache::remember('logos:hub:' . $page, 3600,
 *       fn() => LogoLibrary::fetchHub($page));
 */
class Cache
{
    public const DEFAULT_NAMESPACE = 'cardify';
    public const DEFAULT_TTL = 3600;

    public static function baseDir(): string
    {
        $root = defined('BASE_DIR') ? BASE_DIR : dirname(__DIR__);
        return $root . '/cache';
    }

    public static function get(string $key, $default = null, string $namespace = self::DEFAULT_NAMESPACE)
    {
        $path = self::pathFor($key, $namespace);
        if (!is_file($path)) return $default;
        $raw = @file_get_contents($path);
        if ($raw === false) return $default;
        $entry = @json_decode($raw, true);
        if (!is_array($entry) || !isset($entry['expires_at'], $entry['value'])) return $default;
        if ($entry['expires_at'] !== 0 && $entry['expires_at'] < time()) {
            @unlink($path);
            return $default;
        }
        return $entry['value'];
    }

    public static function put(string $key, $value, int $ttlSec = self::DEFAULT_TTL, string $namespace = self::DEFAULT_NAMESPACE): bool
    {
        $path = self::pathFor($key, $namespace);
        $dir  = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
            if (!is_dir($dir)) return false;
        }
        $entry = [
            'key'        => $key,
            'namespace'  => $namespace,
            'expires_at' => $ttlSec > 0 ? (time() + $ttlSec) : 0,
            'written_at' => time(),
            'value'      => $value,
        ];
        $tmp = $path . '.tmp';
        $encoded = json_encode($entry, JSON_UNESCAPED_UNICODE);
        if ($encoded === false) return false;
        if (@file_put_contents($tmp, $encoded, LOCK_EX) === false) return false;
        return @rename($tmp, $path);
    }

    /**
     * llm71-2: an entry is judged against the TTL IN FORCE NOW, not the one
     * that was in force when it was written. Without this, shortening a TTL
     * does nothing until the last long-lived entry expires on its own, so the
     * deploy that fixes a stale public number leaves it stale for the length
     * of the TTL being retired, and the only way to see the fix take effect is
     * to clear the key by hand. Clearing by hand is what r71 already did, and
     * why llm71-2 stayed open: a reset is not a fix.
     */
    public static function remember(string $key, int $ttlSec, callable $producer, string $namespace = self::DEFAULT_NAMESPACE)
    {
        $hit = self::get($key, $miss = "\0__miss__\0", $namespace);
        if ($hit !== $miss && $ttlSec > 0) {
            $entry = self::rawEntry($key, $namespace);
            if (is_array($entry) && isset($entry['written_at'])
                && $entry['written_at'] + $ttlSec < time()) {
                self::forget($key, $namespace);
                $hit = $miss;
            }
        }
        if ($hit !== $miss) return $hit;
        $value = $producer();
        self::put($key, $value, $ttlSec, $namespace);
        return $value;
    }

    /** The stored envelope (key, namespace, written_at, expires_at, value), or null. */
    private static function rawEntry(string $key, string $namespace): ?array
    {
        $raw = @file_get_contents(self::pathFor($key, $namespace));
        if ($raw === false) return null;
        $entry = @json_decode($raw, true);
        return is_array($entry) ? $entry : null;
    }

    public static function forget(string $key, string $namespace = self::DEFAULT_NAMESPACE): bool
    {
        $path = self::pathFor($key, $namespace);
        return @unlink($path);
    }

    public static function flush(string $namespace = ''): int
    {
        $root = self::baseDir();
        if ($namespace !== '') $root .= '/' . self::sanitizeNs($namespace);
        return self::rrmdir($root);
    }

    public static function gc(int $now = 0): int
    {
        $now = $now ?: time();
        $deleted = 0;
        $root = self::baseDir();
        if (!is_dir($root)) return 0;
        $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($iter as $f) {
            if (!$f->isFile() || substr($f->getFilename(), -5) !== '.json') continue;
            $raw = @file_get_contents($f->getPathname());
            if ($raw === false) continue;
            $entry = @json_decode($raw, true);
            if (!is_array($entry)) { @unlink($f->getPathname()); $deleted++; continue; }
            if (!empty($entry['expires_at']) && $entry['expires_at'] < $now) {
                @unlink($f->getPathname()); $deleted++;
            }
        }
        return $deleted;
    }

    private static function pathFor(string $key, string $namespace): string
    {
        $ns   = self::sanitizeNs($namespace);
        $hash = hash('sha256', $key);
        $shard = substr($hash, 0, 2);
        return self::baseDir() . "/$ns/$shard/$hash.json";
    }

    private static function sanitizeNs(string $ns): string
    {
        $clean = preg_replace('/[^a-z0-9_-]/i', '_', $ns);
        return $clean ?: self::DEFAULT_NAMESPACE;
    }

    private static function rrmdir(string $dir): int
    {
        if (!is_dir($dir)) return 0;
        $count = 0;
        $items = @scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            if (is_dir($path)) $count += self::rrmdir($path);
            else { @unlink($path); $count++; }
        }
        @rmdir($dir);
        return $count;
    }
}
