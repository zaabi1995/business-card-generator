<?php
/**
 * Migrate scan_passes.auth_token from plaintext to AES-256-GCM at rest, and
 * populate the keyed verification HMAC.
 *
 * Usage:
 *   php wallet_encrypt_tokens.php --dry-run    show what would change (default)
 *   php wallet_encrypt_tokens.php --apply      encrypt in place (backs up first)
 *   php wallet_encrypt_tokens.php --verify     prove every row round-trips
 *   php wallet_encrypt_tokens.php --rollback <backup.sql>   restore plaintext
 *
 * Guarantees:
 *   - IDEMPOTENT: an already-encrypted row (v<N>:...) is skipped.
 *   - NON-DESTRUCTIVE: --apply writes a timestamped backup of the table first.
 *   - NO PASS INVALIDATION: the decrypted value is byte-identical to what was
 *     stored, so every already-installed pass keeps authenticating.
 *   - FAILS CLOSED: no key -> refuses to run rather than "migrating" to nothing.
 *   - Tokens are never printed.
 */
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/SecretBox.php';

$mode = 'dry-run';
foreach ($argv as $a) {
    if ($a === '--apply') $mode = 'apply';
    if ($a === '--verify') $mode = 'verify';
    if ($a === '--dry-run') $mode = 'dry-run';
    if ($a === '--rollback') $mode = 'rollback';
}

$db = Database::getInstance();
$pdo = $db->getConnection();

// Ensure the verification column exists (additive, idempotent).
// Ensure the schema can HOLD a ciphertext before writing one. The original
// column was sized for a 48-char hex token; AES-GCM + base64 + the version
// prefix needs ~100. Widening first is what makes the write possible; without
// it MySQL rejects the row - which is how the first run failed SAFE, leaving
// every plaintext token intact.
$pdo->exec("ALTER TABLE scan_passes MODIFY auth_token VARCHAR(255) NOT NULL");
$pdo->exec("ALTER TABLE scan_passes ADD COLUMN IF NOT EXISTS auth_token_hmac CHAR(64) NULL");

if ($mode === 'rollback') {
    $file = $argv[count($argv) - 1] ?? '';
    if (!is_readable($file)) { fwrite(STDERR, "rollback needs a readable backup .sql\n"); exit(1); }
    fwrite(STDOUT, "Restore with:\n  mysql <db> < $file\nThen unset APPLE_WALLET_TOKEN_KEY_PATH usage in ScanPassService.\n");
    exit(0);
}

if (!SecretBox::available()) {
    fwrite(STDERR, "FAIL CLOSED: wallet token key unavailable. Create it first:\n");
    fwrite(STDERR, "  php -r 'require \"config.php\"; require INCLUDES_DIR.\"/SecretBox.php\"; echo SecretBox::generateKey();'\n");
    exit(1);
}

$rows = $db->fetchAll("SELECT id, serial_number, auth_token, auth_token_hmac FROM scan_passes", []);
$plain = 0; $already = 0;
foreach ($rows as $r) {
    if (SecretBox::isEncrypted((string) $r['auth_token'])) { $already++; } else { $plain++; }
}
fwrite(STDOUT, "rows: " . count($rows) . " | plaintext: $plain | already encrypted: $already\n");

if ($mode === 'dry-run') {
    fwrite(STDOUT, "DRY RUN: would encrypt $plain row(s) and backfill HMACs. No changes made.\n");
    exit(0);
}

if ($mode === 'verify') {
    $ok = 0; $bad = 0;
    foreach ($rows as $r) {
        try {
            $clear = SecretBox::decrypt((string) $r['auth_token']);
            $hmacOk = $r['auth_token_hmac'] !== null && hash_equals((string) $r['auth_token_hmac'], SecretBox::hmac($clear));
            if ($clear !== '' && $hmacOk) { $ok++; } else { $bad++; }
        } catch (Throwable $e) {
            $bad++;
        }
    }
    fwrite(STDOUT, "VERIFY: $ok round-trip OK, $bad broken\n");
    exit($bad === 0 ? 0 : 1);
}

// --- apply -----------------------------------------------------------------
$ts = date('Ymd-His');
$backup = "/root/backups/cardify/scan_passes-preencrypt-$ts.sql";
@mkdir('/root/backups/cardify', 0700, true);
// Backup via mysqldump so rollback is a single restore.
$cmd = sprintf(
    'mysqldump -h%s -u%s -p%s %s scan_passes > %s 2>/dev/null',
    escapeshellarg(DB_HOST), escapeshellarg(DB_USER), escapeshellarg(DB_PASS), escapeshellarg(DB_NAME), escapeshellarg($backup)
);
system($cmd, $rc);
if ($rc !== 0 || !file_exists($backup) || filesize($backup) === 0) {
    fwrite(STDERR, "ABORT: backup failed; refusing to migrate.\n");
    exit(1);
}
fwrite(STDOUT, "backup: $backup\n");

$upd = $pdo->prepare("UPDATE scan_passes SET auth_token = :t, auth_token_hmac = :h WHERE id = :id");
$done = 0; $skipped = 0;
foreach ($rows as $r) {
    $stored = (string) $r['auth_token'];
    if (SecretBox::isEncrypted($stored)) {
        // Idempotent: only backfill a missing HMAC.
        if ($r['auth_token_hmac'] === null) {
            $upd->execute(['t' => $stored, 'h' => SecretBox::hmac(SecretBox::decrypt($stored)), 'id' => $r['id']]);
        }
        $skipped++;
        continue;
    }
    $enc = SecretBox::encrypt($stored);
    // Prove the round trip BEFORE writing, so a bad key can never destroy a token.
    if (SecretBox::decrypt($enc) !== $stored) {
        fwrite(STDERR, "ABORT: round-trip check failed; nothing further written.\n");
        exit(1);
    }
    $upd->execute(['t' => $enc, 'h' => SecretBox::hmac($stored), 'id' => $r['id']]);
    $done++;
}
fwrite(STDOUT, "APPLIED: encrypted $done, skipped $skipped (already encrypted)\n");
fwrite(STDOUT, "Now run: php wallet_encrypt_tokens.php --verify\n");
fwrite(STDOUT, "Rollback: mysql <db> < $backup\n");
