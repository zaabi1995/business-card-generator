<?php

class ScanCardLookup
{
    private const ROLE_EMAILS = [
        'info', 'sales', 'contact', 'admin', 'office', 'hello', 'support',
        'enquiries', 'inquiries', 'marketing', 'hr', 'careers', 'help',
        'team', 'accounts', 'billing', 'orders', 'service', 'mail',
        'no-reply', 'noreply',
    ];

    public static function normalizeEmail($raw): ?string
    {
        $email = strtolower(trim((string) $raw));
        if ($email === '' || strlen($email) > 320 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        $local = explode('@', $email, 2)[0] ?? '';
        if ($local === '' || in_array($local, self::ROLE_EMAILS, true)) {
            return null;
        }
        return $email;
    }

    public static function normalizePhone($raw): ?string
    {
        $value = trim((string) $raw);
        if ($value === '' || strlen($value) > 64) {
            return null;
        }
        $compact = preg_replace('/[^0-9+]/', '', $value);
        $digits = preg_replace('/\D/', '', (string) $compact);
        if ($digits === '' || strlen($digits) < 8 || strlen($digits) > 15) {
            return null;
        }
        if (strpos((string) $compact, '00') === 0) {
            $compact = '+' . substr((string) $compact, 2);
        }
        if (strpos((string) $compact, '+') === 0) {
            return '+' . preg_replace('/\D/', '', substr((string) $compact, 1));
        }
        if (strpos($digits, '968') === 0) {
            return '+' . $digits;
        }
        return '+968' . ltrim($digits, '0');
    }

    public static function identifiers(array $body): array
    {
        $emails = [];
        foreach (array_slice(is_array($body['emails'] ?? null) ? $body['emails'] : [], 0, 5) as $raw) {
            $email = self::normalizeEmail($raw);
            if ($email !== null) {
                $emails[$email] = true;
            }
        }

        $phones = [];
        foreach (array_slice(is_array($body['phones'] ?? null) ? $body['phones'] : [], 0, 5) as $raw) {
            $number = is_array($raw) ? ($raw['number'] ?? '') : $raw;
            $phone = self::normalizePhone($number);
            if ($phone !== null) {
                $phones[$phone] = true;
            }
        }

        return [
            'emails' => array_keys($emails),
            'phones' => array_keys($phones),
        ];
    }

    public static function employeeMatches(array $employee, array $emails, array $phones): bool
    {
        $employeeEmail = self::normalizeEmail($employee['email'] ?? '');
        if ($employeeEmail !== null && in_array($employeeEmail, $emails, true)) {
            return true;
        }
        foreach (['mobile', 'phone'] as $field) {
            $employeePhone = self::normalizePhone($employee[$field] ?? '');
            if ($employeePhone !== null && in_array($employeePhone, $phones, true)) {
                return true;
            }
        }
        return false;
    }

    public static function uniqueMatchedEmployees(array $employees, array $emails, array $phones): array
    {
        $matched = [];
        foreach ($employees as $employee) {
            $id = trim((string) ($employee['id'] ?? ''));
            if ($id === '' || !self::employeeMatches($employee, $emails, $phones)) {
                continue;
            }
            $matched[$id] = $employee;
        }
        return array_values($matched);
    }
}
