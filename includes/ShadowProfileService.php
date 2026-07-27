<?php
// ShadowProfileService: one row per real-world person seen on scanned cards,
// deduped by phone/email. Holds the claim token for the growth loop.
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/ScanAuth.php';

class ShadowProfileService {

    public static function normalizePhone(?string $raw): ?string {
        if ($raw === null) return null;
        $digits = preg_replace('/[^0-9+]/', '', $raw);
        if ($digits === '' || strlen(preg_replace('/[^0-9]/', '', $digits)) < 7) return null;
        if (strpos($digits, '00') === 0) $digits = '+' . substr($digits, 2);
        if ($digits[0] !== '+') {
            // Bare local number: assume Oman.
            $digits = '+968' . ltrim($digits, '0');
        }
        return $digits;
    }

    // Returns shadow_profiles.id, creating or updating by phone (preferred) or email.
    public static function upsertFromParsed(array $parsed): ?int {
        $phone = null;
        foreach (($parsed['phones'] ?? []) as $p) {
            if (($p['type'] ?? '') === 'mobile') { $phone = self::normalizePhone($p['number'] ?? null); break; }
        }
        if (!$phone && !empty($parsed['phones'][0]['number'])) {
            $phone = self::normalizePhone($parsed['phones'][0]['number']);
        }
        $email = strtolower(trim($parsed['emails'][0] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $email = null;
        if (!$phone && !$email) return null;

        $db = Database::getInstance();
        $json = json_encode($parsed, JSON_UNESCAPED_UNICODE);

        $row = self::findExisting($db, $phone, $email);
        if ($row) {
            return self::applyUpdate($db, (int)$row['id'], $json, $phone, $email);
        }
        try {
            return (int)$db->insert('shadow_profiles', [
                'phone_primary' => $phone,
                'email_primary' => $email,
                'best_parsed' => $json,
                'claim_token' => ScanAuth::generateToken(),
            ]);
        } catch (\PDOException $e) {
            // Duplicate-key race: a concurrent scan of the same new person
            // inserted between our SELECT and INSERT. One retry of the
            // lookup is enough; if it still finds nothing the 23000 came
            // from something else, so rethrow.
            if ((string)$e->getCode() !== '23000') throw $e;
            $row = self::findExisting($db, $phone, $email);
            if (!$row) throw $e;
            return self::applyUpdate($db, (int)$row['id'], $json, $phone, $email);
        }
    }

    private static function findExisting(Database $db, ?string $phone, ?string $email): ?array {
        $row = null;
        if ($phone) $row = $db->fetchOne("SELECT id FROM shadow_profiles WHERE phone_primary = :p", ['p' => $phone]);
        if (!$row && $email) $row = $db->fetchOne("SELECT id FROM shadow_profiles WHERE email_primary = :e", ['e' => $email]);
        return $row ?: null;
    }

    private static function applyUpdate(Database $db, int $id, string $json, ?string $phone, ?string $email): int {
        try {
            // OVERWRITE, not COALESCE. COALESCE only ever FILLED a null, so once
            // a mis-OCR'd number was stored it was permanent: a later, better
            // scan could never correct it, and the claim OTP kept going to the
            // stranger whose number was first recorded. IF(? IS NULL, keep, new)
            // preserves the "don't clobber with nothing" intent while allowing a
            // real correction. Each identifier is bound TWICE: this connection
            // runs with emulated prepares off, where reusing one placeholder
            // throws HY093.
            $db->getConnection()->prepare(
                "UPDATE shadow_profiles SET best_parsed = ?, "
                . "phone_primary = IF(? IS NULL, phone_primary, ?), "
                . "email_primary = IF(? IS NULL, email_primary, ?) WHERE id = ?"
            )->execute([$json, $phone, $phone, $email, $email, $id]);
        } catch (\PDOException $e) {
            // The corrected identifier already belongs to a DIFFERENT profile.
            // Keeping the stale row here reproduced the exact wrong-recipient
            // outcome, so hand the parse to the profile that genuinely owns the
            // identifier and return THAT id.
            if ((string)$e->getCode() !== '23000') throw $e;
            $owner = self::findExisting($db, $phone, $email);
            $targetId = ($owner && (int)$owner['id'] !== $id) ? (int)$owner['id'] : $id;
            $db->getConnection()->prepare(
                "UPDATE shadow_profiles SET best_parsed = ? WHERE id = ?"
            )->execute([$json, $targetId]);
            return $targetId;
        }
        return $id;
    }

    public static function findByToken(string $token): ?array {
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $token)) return null;
        $row = Database::getInstance()->fetchOne(
            "SELECT * FROM shadow_profiles WHERE claim_token = :t", ['t' => $token]
        );
        return $row ?: null;
    }
}
