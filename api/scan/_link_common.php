<?php

function linkParseIdentifier(string $raw): array
{
    $isEmail = strpos($raw, '@') !== false;
    if ($isEmail) {
        $identifier = strtolower(trim($raw));
        if (!filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            return [null, true, 'email', ''];
        }
        $masked = function_exists('maskEmailIdentifier')
            ? maskEmailIdentifier($identifier)
            : $identifier;
        return [$identifier, true, 'email', $masked];
    }

    $identifier = Phone::normalize($raw);
    if ($identifier === null) {
        return [null, false, 'whatsapp', ''];
    }
    return [$identifier, false, 'whatsapp', Phone::mask($identifier)];
}

function linkFindOwner($db, string $identifier, bool $isEmail): ?string
{
    $account = ScanIdentity::findAccountByIdentifier(
        $db,
        $identifier,
        $isEmail ? 'email' : 'phone'
    );
    return $account !== null ? (string) $account['account_id'] : null;
}

function linkAccountHasType(
    $db,
    string $accountId,
    bool $isEmail,
    string $identifier
): bool {
    $owner = linkFindOwner($db, $identifier, $isEmail);
    if ($owner !== null && hash_equals($accountId, $owner)) {
        return false;
    }
    return ScanIdentity::hasIdentifierType(
        $db,
        $accountId,
        $isEmail ? 'email' : 'phone'
    );
}
