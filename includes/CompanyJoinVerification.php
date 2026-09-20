<?php
/** Mailbox proof for a pending join request, never a grant of membership. */
class CompanyJoinVerification
{
    public static function check(string $email, string $companyId, string $code): array
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $companyId === '') {
            return ['ok' => false, 'error' => 'invalid_request'];
        }
        // A code issued for one company must not authorize a request to another.
        // Fits the existing otp_codes.purpose VARCHAR(40), no migration needed.
        $purpose = 'join:' . substr(hash('sha256', $companyId), 0, 32);
        if ($code === '') {
            $sent = OtpService::send($email, 'email', $purpose);
            return ['ok' => false, 'error' => !empty($sent['ok']) ? 'code_sent' : 'send_failed'];
        }
        $proof = OtpService::verify($email, trim($code), $purpose);
        return !empty($proof['ok'])
            ? ['ok' => true]
            : ['ok' => false, 'error' => 'invalid_code'];
    }
}
