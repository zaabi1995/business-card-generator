<?php
/**
 * InstantCard, the homepage "type your details -> instant demo card -> verify email" funnel.
 *
 * All instant cards live under the `demo` sandbox tenant (demo.cardify.om/<slug>),
 * slug = email with @ -> . (ali@bhd.om -> ali.bhd.om). Nothing touches a real
 * company subdomain. The card is created UNVERIFIED; a magic-link email confirms
 * inbox ownership and unlocks editing/upgrade.
 *
 * Security (from adversarial review): atomic no-overwrite upsert, per-email +
 * per-IP + global rate limits, header-injection guard, MX + disposable checks,
 * idempotent email. The wallet pass issuer is forced to "Cardify" in wallet_demo.php.
 */
class InstantCard
{
    const DEMO_COMPANY_ID = 'd4eceb4c-da60-49d0-8253-1d60cd539b09';
    const DEMO_SLUG       = 'demo';

    /** ali@bhd.om -> ali.bhd.om (strips +tag, collapses dots, safe chars, cap 80). */
    public static function emailToSlug(string $email): string
    {
        $email = strtolower(trim($email));
        $email = preg_replace('/\+[^@]*@/', '@', $email);   // drop +tag so ali+x@ == ali@
        $slug  = str_replace('@', '.', $email);
        $slug  = preg_replace('/[^a-z0-9._-]/', '', $slug);
        $slug  = preg_replace('/\.{2,}/', '.', trim($slug, '.'));
        return substr($slug, 0, 80);
    }

    private static function clean(string $s, int $max): string
    {
        $s = preg_replace('/[\x00-\x1F\x7F]/u', '', (string)$s); // strip control chars + newlines
        return mb_substr(trim($s), 0, $max);
    }

    private static function isDisposable(string $domain): bool
    {
        static $bl = ['mailinator.com','10minutemail.com','tempmail.com','guerrillamail.com',
            'yopmail.com','trashmail.com','getnada.com','sharklasers.com','throwawaymail.com','maildrop.cc'];
        return in_array($domain, $bl, true);
    }

    /**
     * @return array{ok:bool,slug?:string,cardUrl?:string,pending?:bool,error?:string}
     */
    public static function capture(array $in): array
    {
        $email = strtolower(trim((string)($in['email'] ?? '')));
        if (!function_exists('isValidEmail') || !isValidEmail($email)) {
            return ['ok' => false, 'error' => 'invalid_email'];
        }
        $ip = (string)($in['ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? ''));

        if (class_exists('RateLimiter')) {
            if (!RateLimiter::check('instant_card', $ip, 5, 600))            return ['ok' => false, 'error' => 'rate_ip'];
            if (!RateLimiter::check('instant_card_email', $email, 3, 3600))  return ['ok' => false, 'error' => 'rate_email'];
            if (!RateLimiter::check('instant_card_global', 'global', 120, 60)) return ['ok' => false, 'error' => 'busy'];
        }

        $domain = substr(strrchr($email, '@') ?: '', 1);
        if ($domain === '' || self::isDisposable($domain) || !@checkdnsrr($domain, 'MX')) {
            return ['ok' => false, 'error' => 'bad_domain'];
        }

        $name    = self::clean((string)($in['name'] ?? ''), 48);
        $title   = self::clean((string)($in['title'] ?? ''), 48);
        $company = self::clean((string)($in['company'] ?? ''), 40);
        $color   = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($in['color'] ?? '')) ? $in['color'] : '#009bc1';
        $locale  = (($in['lang'] ?? 'en') === 'ar') ? 'ar' : 'en';
        if ($name === '')    { $name = $locale === 'ar' ? 'بطاقتي' : 'Your Name'; }
        if ($title === '')   { $title = $locale === 'ar' ? 'المسمى الوظيفي' : 'Your Title'; }
        if ($company === '') { $company = $locale === 'ar' ? 'شركتك' : 'Your Company'; }

        $slug = self::emailToSlug($email);
        if ($slug === '') { return ['ok' => false, 'error' => 'invalid_email']; }

        $pdo  = Database::getInstance()->getConnection();
        $meta = json_encode(['brand_color' => $color, 'verified' => false, 'source' => 'hero_instant'], JSON_UNESCAPED_UNICODE);

        // Atomic, race-safe upsert that REFUSES to overwrite a row owned by a
        // different email/company (PK is employees.id, global). Re-check after.
        $sql = "INSERT INTO employees (id,company_id,email,name_en,position_en,company_en,demo_meta,status,created_at)
                VALUES (:id,:cid,:email,:name,:pos,:comp,:meta,'active',NOW())
                ON DUPLICATE KEY UPDATE
                  name_en     = IF(email=VALUES(email) AND company_id=VALUES(company_id), VALUES(name_en), name_en),
                  position_en = IF(email=VALUES(email) AND company_id=VALUES(company_id), VALUES(position_en), position_en),
                  company_en  = IF(email=VALUES(email) AND company_id=VALUES(company_id), VALUES(company_en), company_en),
                  demo_meta   = IF(email=VALUES(email) AND company_id=VALUES(company_id), VALUES(demo_meta), demo_meta)";
        $pdo->prepare($sql)->execute([
            ':id' => $slug, ':cid' => self::DEMO_COMPANY_ID, ':email' => $email,
            ':name' => $name, ':pos' => $title, ':comp' => $company, ':meta' => $meta,
        ]);

        $check = $pdo->prepare("SELECT company_id, email FROM employees WHERE id = :id");
        $check->execute([':id' => $slug]);
        $row = $check->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['company_id'] !== self::DEMO_COMPANY_ID || strtolower((string)$row['email']) !== $email) {
            return ['ok' => false, 'error' => 'slug_taken'];
        }

        $cardUrl = function_exists('getTenantUrl')
            ? getTenantUrl(self::DEMO_SLUG, '/' . $slug)
            : 'https://demo.cardify.om/' . $slug;

        // Idempotent: don't re-email if a pending lead for this email exists in the last hour.
        $recent = $pdo->prepare("SELECT id FROM cardify_signup_leads
            WHERE email = :e AND source = 'hero_instant' AND status = 'pending'
              AND created_at > (NOW() - INTERVAL 60 MINUTE) LIMIT 1");
        $recent->execute([':e' => $email]);
        $alreadyPending = (bool)$recent->fetchColumn();

        if (!$alreadyPending) {
            $leadId = function_exists('generateUUID') ? generateUUID() : bin2hex(random_bytes(16));
            $pdo->prepare("INSERT INTO cardify_signup_leads (id,email,source,locale,ip_address,user_agent,status,created_at)
                           VALUES (:id,:e,'hero_instant',:l,:ip,:ua,'pending',NOW())")
                ->execute([':id' => $leadId, ':e' => $email, ':l' => $locale, ':ip' => $ip, ':ua' => substr((string)($in['ua'] ?? ''), 0, 512)]);

            if (class_exists('EmployeeEditToken') && class_exists('Mailer')) {
                try {
                    $token     = EmployeeEditToken::mint($slug, 'hero_instant', $ip);
                    $verifyUrl = 'https://cardify.om/verify_card.php?t=' . urlencode($token);
                    Mailer::sendTemplated($email, 'instant_card_welcome', $locale,
                        ['name' => $name, 'cardUrl' => $cardUrl],
                        [], ['cta_url' => $verifyUrl]);
                } catch (Throwable $e) {
                    error_log('InstantCard email failed: ' . $e->getMessage());
                }
            }
        }

        return ['ok' => true, 'slug' => $slug, 'cardUrl' => $cardUrl, 'pending' => $alreadyPending];
    }

    /** Flip the demo card to verified (clears the banner, exempts from purge). */
    public static function markVerified(string $slug): bool
    {
        try {
            $pdo = Database::getInstance()->getConnection();
            $st  = $pdo->prepare("SELECT demo_meta, company_id FROM employees WHERE id = :id");
            $st->execute([':id' => $slug]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row || $row['company_id'] !== self::DEMO_COMPANY_ID) { return false; }
            $meta = json_decode((string)($row['demo_meta'] ?? '{}'), true) ?: [];
            $meta['verified'] = true;
            $pdo->prepare("UPDATE employees SET demo_meta = :m WHERE id = :id")
                ->execute([':m' => json_encode($meta, JSON_UNESCAPED_UNICODE), ':id' => $slug]);
            $pdo->prepare("UPDATE cardify_signup_leads SET status='verified', claimed_at=NOW()
                           WHERE email = (SELECT email FROM employees WHERE id = :id) AND status='pending'")
                ->execute([':id' => $slug]);
            return true;
        } catch (Throwable $e) {
            error_log('InstantCard markVerified failed: ' . $e->getMessage());
            return false;
        }
    }
}
