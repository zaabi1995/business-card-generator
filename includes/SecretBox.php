<?php
/**
 * SecretBox: authenticated encryption at rest for values we MUST be able to read
 * back (unlike a password, which would be hashed).
 *
 * Why encryption and not a hash: Apple's PassKit protocol embeds the pass
 * authenticationToken in pass.json on EVERY regeneration, so the server has to
 * reproduce the clear value. A one-way hash cannot. We therefore encrypt at rest
 * and additionally store a keyed HMAC so request verification is a constant-time
 * compare that never decrypts anything.
 *
 * Format:  v<keyVersion>:<base64url(nonce || ciphertext || tag)>
 *   - AES-256-GCM (authenticated: tampering fails to decrypt, it cannot be
 *     silently altered).
 *   - 12-byte random nonce PER value (never reused).
 *   - 16-byte GCM tag.
 *
 * Key management:
 *   - The key lives OUTSIDE the database and OUTSIDE git, in a file under
 *     data/wallet/ (nginx 404s that directory; php-fpm open_basedir confines it).
 *   - APPLE_WALLET_TOKEN_KEY_PATH points at it; chmod 600, owned by www.
 *   - Key VERSIONS are supported so rotation is possible without invalidating
 *     already-issued passes: old values keep decrypting with their own version
 *     while new writes use the current key.
 *   - If the key is missing/unreadable we FAIL CLOSED (throw) rather than fall
 *     back to plaintext.
 *
 * Never log plaintext, ciphertext, or the key.
 */
class SecretBox
{
    private const CIPHER = 'aes-256-gcm';
    private const NONCE_LEN = 12;
    private const TAG_LEN = 16;
    private const CURRENT_VERSION = 1;

    /** @var array<int,string> cached raw keys by version */
    private static array $keys = [];

    private static function keyPath(int $version): string
    {
        $base = defined('APPLE_WALLET_TOKEN_KEY_PATH')
            ? APPLE_WALLET_TOKEN_KEY_PATH
            : '/www/wwwroot/cardify.om/data/wallet/token_key.bin';
        // v1 uses the base path; later versions get a .v<N> suffix so both can
        // coexist during a rotation window.
        return $version === 1 ? $base : $base . '.v' . $version;
    }

    /** Raw 32-byte key for a version. Throws (fails closed) when unavailable. */
    private static function key(int $version): string
    {
        if (isset(self::$keys[$version])) {
            return self::$keys[$version];
        }
        $path = self::keyPath($version);
        if (!is_readable($path)) {
            throw new RuntimeException('wallet token key unavailable (v' . $version . ')');
        }
        $raw = trim((string) file_get_contents($path));
        // Accept raw 32 bytes or base64.
        $key = strlen($raw) === 32 ? $raw : base64_decode($raw, true);
        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('wallet token key malformed (v' . $version . ')');
        }
        self::$keys[$version] = $key;
        return $key;
    }

    public static function available(): bool
    {
        try { self::key(self::CURRENT_VERSION); return true; } catch (Throwable $e) { return false; }
    }

    /** Generate a new key file (used by setup + rotation). Never overwrites. */
    public static function generateKey(?int $version = null): string
    {
        $version = $version ?? self::CURRENT_VERSION;
        $path = self::keyPath($version);
        if (file_exists($path)) {
            throw new RuntimeException('refusing to overwrite an existing key: ' . basename($path));
        }
        $dir = dirname($path);
        if (!is_dir($dir)) { mkdir($dir, 0700, true); }
        $key = random_bytes(32);
        file_put_contents($path, base64_encode($key));
        chmod($path, 0600);
        return $path;
    }

    public static function isEncrypted(string $value): bool
    {
        return (bool) preg_match('/^v\d+:/', $value);
    }

    public static function encrypt(string $plaintext): string
    {
        $v = self::CURRENT_VERSION;
        $nonce = random_bytes(self::NONCE_LEN);
        $tag = '';
        $ct = openssl_encrypt($plaintext, self::CIPHER, self::key($v), OPENSSL_RAW_DATA, $nonce, $tag, '', self::TAG_LEN);
        if ($ct === false) {
            throw new RuntimeException('wallet token encryption failed');
        }
        return 'v' . $v . ':' . rtrim(strtr(base64_encode($nonce . $ct . $tag), '+/', '-_'), '=');
    }

    /** Decrypt a stored value. A legacy PLAINTEXT value is returned as-is so a
     *  migration window keeps working; callers migrate it forward. */
    public static function decrypt(string $stored): string
    {
        if (!self::isEncrypted($stored)) {
            return $stored; // legacy plaintext (pre-migration)
        }
        [$vPart, $b64] = explode(':', $stored, 2);
        $v = (int) substr($vPart, 1);
        $raw = base64_decode(strtr($b64, '-_', '+/'), true);
        if ($raw === false || strlen($raw) < self::NONCE_LEN + self::TAG_LEN + 1) {
            throw new RuntimeException('wallet token ciphertext malformed');
        }
        $nonce = substr($raw, 0, self::NONCE_LEN);
        $tag = substr($raw, -self::TAG_LEN);
        $ct = substr($raw, self::NONCE_LEN, -self::TAG_LEN);
        $pt = openssl_decrypt($ct, self::CIPHER, self::key($v), OPENSSL_RAW_DATA, $nonce, $tag);
        if ($pt === false) {
            // Wrong key or tampered ciphertext. Fail closed; never guess.
            throw new RuntimeException('wallet token decryption failed (v' . $v . ')');
        }
        return $pt;
    }

    /** Keyed verification hash: lets authorize() compare in constant time
     *  without decrypting, and is useless to an attacker without the key. */
    public static function hmac(string $plaintext): string
    {
        return hash_hmac('sha256', $plaintext, self::key(self::CURRENT_VERSION));
    }

    public static function currentVersion(): int
    {
        return self::CURRENT_VERSION;
    }
}
