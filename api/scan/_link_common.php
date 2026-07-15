<?php
// Shared helpers for the account-linking endpoints.

// Parse a raw identifier into [normalized, isEmail, channel, masked] or
// [null, ...] when invalid.
function linkParseIdentifier(string $raw): array {
    $isEmail = strpos($raw, '@') !== false;
    if ($isEmail) {
        $id = strtolower($raw);
        if (!filter_var($id, FILTER_VALIDATE_EMAIL)) return [null, true, 'email', ''];
        return [$id, true, 'email', function_exists('maskEmailIdentifier') ? maskEmailIdentifier($id) : $id];
    }
    $id = Phone::normalize($raw);
    if ($id === null) return [null, false, 'whatsapp', ''];
    return [$id, false, 'whatsapp', Phone::mask($id)];
}

// Owner (employee id) of an identifier, using the SAME fuzzy last-8 phone match
// as login so mixed stored formats ('71616161' vs '+968 7161 6161') can't slip
// past the guard. Null if unowned.
function linkFindOwner($db, string $identifier, bool $isEmail): ?string {
    if ($isEmail) {
        $row = $db->fetchOne(
            "SELECT id FROM employees WHERE email = :v AND status = 'active' ORDER BY created_at ASC LIMIT 1",
            ['v' => $identifier]
        );
        return $row ? (string)$row['id'] : null;
    }
    $digits = preg_replace('/\D/', '', $identifier);
    $tail = strlen($digits) >= 8 ? substr($digits, -8) : $digits;
    $rows = $db->fetchAll(
        "SELECT id, mobile, phone FROM employees
         WHERE status = 'active'
           AND ( (mobile <> '' AND mobile LIKE :m) OR (phone <> '' AND phone LIKE :p) )
         ORDER BY created_at ASC",
        ['m' => '%' . $tail, 'p' => '%' . $tail]
    );
    foreach ($rows as $r) {
        foreach (['mobile', 'phone'] as $col) {
            if (!empty($r[$col]) && Phone::normalize($r[$col]) === $identifier) return (string)$r['id'];
        }
    }
    return null;
}

// Does the account already have a DIFFERENT identifier of this type linked
// (so linking would overwrite it)? Same normalized value returns false (a
// harmless re-confirm).
function linkAccountHasType($db, string $employeeId, bool $isEmail, string $identifier): bool {
    $row = $db->fetchOne("SELECT email, mobile FROM employees WHERE id = :id", ['id' => $employeeId]) ?: [];
    if ($isEmail) {
        $cur = strtolower(trim((string)($row['email'] ?? '')));
        return $cur !== '' && $cur !== $identifier;
    }
    $cur = (string)($row['mobile'] ?? '');
    if ($cur === '') return false;
    return Phone::normalize($cur) !== $identifier;
}
