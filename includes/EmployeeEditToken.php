<?php
/**
 * EmployeeEditToken, magic-link token issuer + verifier for
 * employee self-service edits (Cardify v2.0 Category E).
 *
 * Token is 40 hex chars (20 random bytes). Hashed SHA-256 at rest;
 * plain token only appears in the WhatsApp/email invite.
 *
 * TTL: 30 days from mint. Each successful verify extends last_used_at;
 * if last_used_at falls more than 30 days behind now the token is
 * considered expired regardless of the original expires_at.
 *
 * Minting a new token for a given employee revokes any prior active
 * tokens, so only one magic link per employee is ever valid at once.
 */
class EmployeeEditToken
{
    // Magic links are essentially permanent: an employee may receive the
    // invite once and keep updating their card for years (job title change,
    // new phone, photo update). TTL stays as a safety net (10 years), but
    // the idle-timeout check below is intentionally disabled because the
    // whole point is "link always works, click whenever". Revocation is
    // explicit: admin clicks Copy link (mints fresh, revokes prior) or
    // leave-company flow calls revokeAllForEmployee().
    public const TTL_DAYS = 3650;
    public const BYTES = 20; // 40 hex chars

    /**
     * Revoke every active token for an employee. Called from the
     * leave-company approval flow so the departing employee can no
     * longer edit their card after being deactivated.
     */
    public static function revokeAllForEmployee(string $employeeId): int
    {
        $db = Database::getInstance();
        $db->update(
            'employee_edit_tokens',
            ['revoked_at' => date('Y-m-d H:i:s')],
            'employee_id = :eid AND revoked_at IS NULL',
            ['eid' => $employeeId]
        );
        return 1;
    }

    public static function mint(string $employeeId, ?string $createdBy = null, ?string $ip = null): string
    {
        $db = Database::getInstance();
        // Revoke previous
        $db->update(
            'employee_edit_tokens',
            ['revoked_at' => date('Y-m-d H:i:s')],
            'employee_id = :eid AND revoked_at IS NULL',
            ['eid' => $employeeId]
        );

        $plain = bin2hex(random_bytes(self::BYTES));
        $hash = hash('sha256', $plain);

        $db->insert('employee_edit_tokens', [
            'employee_id' => $employeeId,
            'token_hash'  => $hash,
            'expires_at'  => date('Y-m-d H:i:s', time() + (self::TTL_DAYS * 86400)),
            'created_by'  => $createdBy,
            'ip'          => $ip ? substr($ip, 0, 45) : null,
        ]);

        return $plain;
    }

    /**
     * Look up a plain token. Returns the employee row on success, null
     * on expired/revoked/unknown. Updates last_used_at on hit.
     */
    public static function verify(string $plain): ?array
    {
        if (!preg_match('/^[a-f0-9]{40}$/i', $plain)) return null;
        $hash = hash('sha256', $plain);

        $db = Database::getInstance();
        // Alias the token's columns to avoid clashing with e.* (employees has
        // both 'id' and 'employee_id'; without aliases, e.employee_id silently
        // wins and the returned row['id'] / row['employee_id'] both end up wrong).
        $row = $db->fetchOne(
            "SELECT t.id AS _t_id, t.employee_id AS _t_emp_id,
                    t.expires_at AS _t_expires_at,
                    t.last_used_at AS _t_last_used_at,
                    t.revoked_at AS _t_revoked_at,
                    e.*
             FROM employee_edit_tokens t
             JOIN employees e ON e.id = t.employee_id
             WHERE t.token_hash = :h LIMIT 1",
            ['h' => $hash]
        );
        if (!$row) return null;
        if (!empty($row['_t_revoked_at'])) return null;
        if (strtotime($row['_t_expires_at']) < time()) return null;
        // Idle-timeout removed: links are designed to live for the full
        // tenure of the employment. Revocation is explicit via admin
        // mint-replaces-prior or leave-company flow, not implicit drift.

        $db->update(
            'employee_edit_tokens',
            ['last_used_at' => date('Y-m-d H:i:s')],
            'id = :id',
            ['id' => $row['_t_id']]
        );

        // Return the employees row in the shape callers expect: $row['id']
        // is the employee row id (which e.id confirmed equals the token's
        // employee_id via the JOIN). Strip the token-only internals.
        $boundEmpId = $row['_t_emp_id'];
        unset(
            $row['_t_id'], $row['_t_emp_id'], $row['_t_expires_at'],
            $row['_t_last_used_at'], $row['_t_revoked_at']
        );
        $row['id'] = $boundEmpId;
        return $row;
    }

    /**
     * Build the portal edit URL for a token. When a tenant slug is
     * provided (or can be inferred from the current employee/company),
     * the URL is rooted on the tenant subdomain so staff land on their
     * branded host. Without a slug, falls back to the bare apex host.
     *
     * When $employeeSlug is provided (email-localpart used in the
     * digital-card URL), emits the recognisable shape
     * `https://{tenant}.cardify.om/{employeeSlug}/edit?t={plain}`
     * which routes to portal.php in edit-mode (prefilled form, real
     * Fabric.js card preview, "Save changes" submit). Without a slug,
     * falls back to the legacy `/portal/employee-edit?token=...` URL
     * so existing share-links keep working.
     */
    public static function buildUrl(string $plain, ?string $tenantSlug = null, ?string $employeeSlug = null): string
    {
        $apex = defined('APP_HOST') ? APP_HOST : ($_SERVER['HTTP_HOST'] ?? 'cardify.om');
        // Always emit https for cardify.om (live in production behind CF SSL).
        // $_SERVER['HTTPS'] is unset in PHP CLI contexts like cron + admin
        // bulk-send loops, which previously emitted http:// links that
        // recipients flagged as insecure. Hardcode scheme for cardify.om.
        $scheme = 'https';
        $host = $tenantSlug ? $tenantSlug . '.' . $apex : $apex;
        $slug = $employeeSlug !== null ? trim((string) $employeeSlug) : '';
        if ($slug !== '' && preg_match('/^[a-z0-9._-]+$/i', $slug)) {
            return $scheme . '://' . $host . '/' . $slug . '/edit?t=' . $plain;
        }
        return $scheme . '://' . $host . '/portal/employee-edit?token=' . $plain;
    }

    /**
     * Mint a token for an employee and dispatch the invite via WhatsApp
     * and/or email. Returns ['token' => string, 'wa' => bool, 'email' => bool].
     *
     * Template context: $employeeName, $companyName, $editUrl,
     * $expiresInDays, $brandColor, $logoUrl (last two nullable).
     */
    public static function sendInvite(array $employee, array $company, string $channel = 'both'): array
    {
        $plain = self::mint($employee['id'] ?? '', $employee['created_by'] ?? null, $_SERVER['REMOTE_ADDR'] ?? null);
        // Derive the same slug used by index.php's tenant bare-URL router:
        // email local-part with dots/underscores normalised to dashes. Keeps
        // the share link visually recognisable to the recipient (their own
        // name in the URL) instead of an opaque 40-char token.
        $email = (string) ($employee['email'] ?? '');
        $localPart = $email !== '' ? strtolower((string) strstr($email, '@', true)) : '';
        if ($localPart === '') {
            $localPart = strtolower((string) substr((string) strstr($email . '@', '@', true), 0));
        }
        $employeeSlug = $localPart !== '' ? strtr($localPart, ['.' => '-', '_' => '-']) : null;
        $editUrl = self::buildUrl($plain, $company['slug'] ?? null, $employeeSlug);

        // Normalise logo to an absolute URL: company_themes.logo_path stores
        // a relative path like /uploads/companies/otech-logo.png that won't
        // render in an email client without the host prepended.
        $logoUrl = $company['logo_url'] ?? null;
        if ($logoUrl && strpos($logoUrl, 'http') !== 0) {
            $apex = defined('APP_HOST') ? APP_HOST : 'cardify.om';
            $logoUrl = 'https://' . $apex . '/' . ltrim($logoUrl, '/');
        }
        // Support email shown in the email footer. Defaults to a real
        // provisioned inbox so recipients can actually get a human reply.
        // tenant admin_email gets used only if Ali has confirmed the
        // mailbox exists and accepts mail (skipped by default to avoid
        // dead-letter replies).
        $supportEmail = 'info@cardify.om';

        $ctx = [
            'employeeName'     => $employee['name_en'] ?? $employee['name_ar'] ?? $employee['email'] ?? '',
            'employeePosition' => $employee['position_en'] ?? $employee['position_ar'] ?? '',
            'employeeNameAr'   => $employee['name_ar'] ?? '',
            'employeeEmail'    => $employee['email'] ?? '',
            'employeeMobile'   => $employee['mobile'] ?? $employee['phone'] ?? '',
            'companyName'      => $company['name'] ?? 'Cardify',
            'companyDomain'    => $company['email_domain'] ?? '',
            'editUrl'          => $editUrl,
            'expiresInDays'    => self::TTL_DAYS,
            'brandColor'       => $company['brand_color'] ?? $company['primary_color'] ?? null,
            'secondaryColor'   => $company['secondary_color'] ?? null,
            'logoUrl'          => $logoUrl,
            'supportEmail'     => $supportEmail,
            'printDeadline'    => $company['print_deadline'] ?? null,
        ];
        $locale = function_exists('currentLocale') ? currentLocale() : 'en';

        $waOk = false;
        if (($channel === 'both' || $channel === 'whatsapp') && class_exists('WhatsApp') && WhatsApp::isEnabled()) {
            $phone = $employee['mobile'] ?? $employee['phone'] ?? '';
            if ($phone !== '') {
                $body = self::renderTemplate('employee_invite.whatsapp', $locale, $ctx);
                $waOk = (bool) WhatsApp::sendMessage($phone, $body);
            }
        }

        $emailOk = false;
        if (($channel === 'both' || $channel === 'email') && class_exists('Mailer')) {
            $email = $employee['email'] ?? '';
            if ($email !== '') {
                [$subject, $body] = self::renderEmailTemplate('employee_invite.email', $locale, $ctx);
                // Override From-name to "<Company> via Cardify" so the
                // recipient's inbox lists their employer (the actual sender
                // of the link), not just the platform. The From-address
                // stays on cardify.om so DKIM/SPF still align.
                $tenantName = (string) ($company['name'] ?? 'Cardify');
                $tenantName = preg_replace('/[\r\n,"<>@]/', '', $tenantName); // RFC 5322 sanitise
                $fromName = $tenantName === '' || $tenantName === 'Cardify'
                    ? 'Cardify'
                    : ($tenantName . ' via Cardify');
                // Reply-To: must point to a REAL provisioned mailbox or
                // Gmail downgrades the message. We don't auto-trust
                // companies.admin_email because tenants seed it with
                // aspirational addresses that don't accept mail.
                // Fall back to info@cardify.om (provisioned + monitored).
                $apex = defined('APP_HOST') ? APP_HOST : 'cardify.om';
                $replyTo = 'info@' . $apex;
                $emailOk = (bool) Mailer::send(
                    $email, $subject, $body,
                    [], // no attachments
                    [
                        'from_name_override' => $fromName,
                        'reply_to'           => $replyTo,
                        'company_id'         => $company['id'] ?? null,
                        'employee_id'        => $employee['id'] ?? null,
                    ]
                );
            }
        }

        return ['token' => $plain, 'wa' => $waOk, 'email' => $emailOk];
    }

    private static function renderTemplate(string $name, string $locale, array $ctx): string
    {
        $path = __DIR__ . "/notifications/templates/{$name}.{$locale}.php";
        if (!is_file($path)) $path = __DIR__ . "/notifications/templates/{$name}.en.php";
        extract($ctx, EXTR_SKIP);
        $body = '';
        require $path;
        return $body;
    }

    private static function renderEmailTemplate(string $name, string $locale, array $ctx): array
    {
        $path = __DIR__ . "/notifications/templates/{$name}.{$locale}.php";
        if (!is_file($path)) $path = __DIR__ . "/notifications/templates/{$name}.en.php";
        extract($ctx, EXTR_SKIP);
        $subject = '';
        $body = '';
        require $path;
        return [$subject, $body];
    }
}
