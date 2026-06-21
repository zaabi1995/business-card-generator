<?php
/**
 * WcHub - helpers for the Cardify World Cup hub (wc.cardify.om).
 * 5-language UI strings (en/ar/hi/bn/ur), country->timezone suggestion,
 * phone masking, wc_users upsert, and lead mirroring into the Cardify
 * backend (cardify_signup_leads, source='world-cup').
 */
class WcHub
{
    public const LANGS = [
        'en' => 'English',
        'ar' => 'العربية',
        'hi' => 'हिन्दी',
        'bn' => 'বাংলা',
        'ur' => 'اردو',
    ];
    public const RTL = ['ar', 'ur'];

    public static function isRtl(string $lang): bool
    {
        return in_array($lang, self::RTL, true);
    }

    public static function lang(string $l): string
    {
        return isset(self::LANGS[$l]) ? $l : 'en';
    }

    /** Country (ISO-2) -> representative IANA timezone for the suggestion. */
    public static function countryTz(?string $cc): string
    {
        $cc = strtoupper((string)$cc);
        $map = [
            'OM' => 'Asia/Muscat',    'AE' => 'Asia/Dubai',     'SA' => 'Asia/Riyadh',
            'QA' => 'Asia/Qatar',     'BH' => 'Asia/Bahrain',   'KW' => 'Asia/Kuwait',
            'IN' => 'Asia/Kolkata',   'BD' => 'Asia/Dhaka',     'PK' => 'Asia/Karachi',
            'PH' => 'Asia/Manila',    'LK' => 'Asia/Colombo',   'NP' => 'Asia/Kathmandu',
            'EG' => 'Africa/Cairo',   'JO' => 'Asia/Amman',     'IQ' => 'Asia/Baghdad',
            'GB' => 'Europe/London',  'US' => 'America/New_York','CA' => 'America/Toronto',
            'FR' => 'Europe/Paris',   'DE' => 'Europe/Berlin',  'ES' => 'Europe/Madrid',
            'MA' => 'Africa/Casablanca','NG' => 'Africa/Lagos',  'ID' => 'Asia/Jakarta',
            'TR' => 'Europe/Istanbul','BR' => 'America/Sao_Paulo','AU' => 'Australia/Sydney',
        ];
        return $map[$cc] ?? 'Asia/Muscat';
    }

    /** Detect the visitor country from Cloudflare's edge header. */
    public static function detectCountry(): ?string
    {
        $cc = $_SERVER['HTTP_CF_IPCOUNTRY'] ?? '';
        $cc = strtoupper(trim($cc));
        return preg_match('/^[A-Z]{2}$/', $cc) && $cc !== 'XX' ? $cc : null;
    }

    public static function maskPhone(string $phone): string
    {
        $d = preg_replace('/\D/', '', $phone);
        if (strlen($d) <= 4) return $d;
        return '****' . substr($d, -4);
    }

    /** UI strings per language. */
    public static function strings(string $lang): array
    {
        $lang = self::lang($lang);
        $S = [
            'en' => [
                'dir' => 'ltr',
                'brand' => 'powered by Cardify',
                'kicker' => 'FIFA World Cup 2026',
                'hero_title' => 'Your daily World Cup, on WhatsApp.',
                'hero_sub' => 'Daily fixtures and results in your language and timezone, plus a free prediction game. Powered by Cardify.',
                'f_name' => 'Your name',
                'f_phone' => 'WhatsApp number',
                'f_language' => 'Language',
                'f_timezone' => 'Timezone',
                'tz_detected' => 'Detected from your location',
                'get_code' => 'Get my code',
                'sending' => 'Sending…',
                'otp_title' => 'Enter your code',
                'otp_sub' => 'We sent a 6-digit code on WhatsApp to',
                'verify' => 'Verify & join',
                'resend' => 'Resend code',
                'change' => 'Change number',
                'win_title' => 'Predict & win',
                'win_sub' => 'Top 3 predictors win $10,000, $5,000 and $1,000. Free to enter.',
                'success_title' => "You're in!",
                'success_sub' => 'Your first match digest arrives tomorrow at 10am. Start predicting now.',
                'go_predict' => 'Make predictions',
                'add_wallet' => 'Add to Wallet',
                'invite' => 'Invite friends (+3 points)',
                'err_name' => 'Please enter your name.',
                'err_phone' => 'Please enter a valid WhatsApp number.',
                'err_otp' => 'That code is incorrect or expired.',
                'err_rate' => 'Too many attempts. Please wait a few minutes.',
                'err_generic' => 'Something went wrong. Please try again.',
            ],
            'ar' => [
                'dir' => 'rtl',
                'brand' => 'مدعوم من Cardify',
                'kicker' => 'كأس العالم 2026',
                'hero_title' => 'كأس العالم يوميًا، على واتساب.',
                'hero_sub' => 'مباريات ونتائج يومية بلغتك وتوقيتك، مع لعبة توقعات مجانية. مدعوم من Cardify.',
                'f_name' => 'اسمك',
                'f_phone' => 'رقم واتساب',
                'f_language' => 'اللغة',
                'f_timezone' => 'المنطقة الزمنية',
                'tz_detected' => 'تم تحديدها من موقعك',
                'get_code' => 'أرسل لي الرمز',
                'sending' => 'جارٍ الإرسال…',
                'otp_title' => 'أدخل الرمز',
                'otp_sub' => 'أرسلنا رمزًا من 6 أرقام على واتساب إلى',
                'verify' => 'تأكيد والانضمام',
                'resend' => 'إعادة إرسال الرمز',
                'change' => 'تغيير الرقم',
                'win_title' => 'توقّع واربح',
                'win_sub' => 'أفضل 3 متوقعين يربحون 10,000 و5,000 و1,000 دولار. الدخول مجاني.',
                'success_title' => 'تم تسجيلك!',
                'success_sub' => 'تصلك أول نشرة مباريات غدًا الساعة 10 صباحًا. ابدأ التوقّع الآن.',
                'go_predict' => 'ابدأ التوقّع',
                'add_wallet' => 'أضف إلى المحفظة',
                'invite' => 'ادعُ أصدقاءك (+3 نقاط)',
                'err_name' => 'الرجاء إدخال اسمك.',
                'err_phone' => 'الرجاء إدخال رقم واتساب صحيح.',
                'err_otp' => 'الرمز غير صحيح أو منتهي.',
                'err_rate' => 'محاولات كثيرة. انتظر بضع دقائق.',
                'err_generic' => 'حدث خطأ. حاول مرة أخرى.',
            ],
            'hi' => [
                'dir' => 'ltr',
                'brand' => 'Cardify द्वारा संचालित',
                'kicker' => 'फीफा विश्व कप 2026',
                'hero_title' => 'आपका रोज़ का वर्ल्ड कप, WhatsApp पर।',
                'hero_sub' => 'आपकी भाषा और समयक्षेत्र में रोज़ के मैच और नतीजे, साथ में मुफ़्त भविष्यवाणी गेम। Cardify द्वारा संचालित।',
                'f_name' => 'आपका नाम',
                'f_phone' => 'WhatsApp नंबर',
                'f_language' => 'भाषा',
                'f_timezone' => 'समयक्षेत्र',
                'tz_detected' => 'आपके स्थान से पहचाना गया',
                'get_code' => 'मेरा कोड भेजें',
                'sending' => 'भेजा जा रहा है…',
                'otp_title' => 'अपना कोड डालें',
                'otp_sub' => 'हमने WhatsApp पर 6 अंकों का कोड भेजा है',
                'verify' => 'सत्यापित करें और जुड़ें',
                'resend' => 'कोड फिर भेजें',
                'change' => 'नंबर बदलें',
                'win_title' => 'अनुमान लगाएं और जीतें',
                'win_sub' => 'शीर्ष 3 विजेता $10,000, $5,000, $1,000 जीतते हैं। प्रवेश मुफ़्त।',
                'success_title' => 'आप शामिल हो गए!',
                'success_sub' => 'आपका पहला मैच डाइजेस्ट कल सुबह 10 बजे आएगा। अभी अनुमान लगाना शुरू करें।',
                'go_predict' => 'अनुमान लगाएं',
                'add_wallet' => 'Wallet में जोड़ें',
                'invite' => 'दोस्तों को बुलाएं (+3 अंक)',
                'err_name' => 'कृपया अपना नाम डालें।',
                'err_phone' => 'कृपया मान्य WhatsApp नंबर डालें।',
                'err_otp' => 'कोड ग़लत या समाप्त है।',
                'err_rate' => 'बहुत प्रयास। कुछ मिनट रुकें।',
                'err_generic' => 'कुछ ग़लत हुआ। फिर कोशिश करें।',
            ],
            'bn' => [
                'dir' => 'ltr',
                'brand' => 'Cardify দ্বারা চালিত',
                'kicker' => 'ফিফা বিশ্বকাপ ২০২৬',
                'hero_title' => 'আপনার প্রতিদিনের বিশ্বকাপ, WhatsApp-এ।',
                'hero_sub' => 'আপনার ভাষা ও সময় অঞ্চলে প্রতিদিনের ম্যাচ ও ফলাফল, সাথে ফ্রি প্রেডিকশন গেম। Cardify দ্বারা চালিত।',
                'f_name' => 'আপনার নাম',
                'f_phone' => 'WhatsApp নম্বর',
                'f_language' => 'ভাষা',
                'f_timezone' => 'সময় অঞ্চল',
                'tz_detected' => 'আপনার অবস্থান থেকে শনাক্ত',
                'get_code' => 'আমার কোড পাঠান',
                'sending' => 'পাঠানো হচ্ছে…',
                'otp_title' => 'আপনার কোড দিন',
                'otp_sub' => 'আমরা WhatsApp-এ ৬ সংখ্যার কোড পাঠিয়েছি',
                'verify' => 'যাচাই করে যোগ দিন',
                'resend' => 'কোড আবার পাঠান',
                'change' => 'নম্বর পরিবর্তন',
                'win_title' => 'অনুমান করুন ও জিতুন',
                'win_sub' => 'সেরা ৩ জন জিতবেন $10,000, $5,000, $1,000। প্রবেশ ফ্রি।',
                'success_title' => 'আপনি যুক্ত হয়েছেন!',
                'success_sub' => 'আপনার প্রথম ম্যাচ ডাইজেস্ট আগামীকাল সকাল ১০টায় আসবে। এখনই অনুমান শুরু করুন।',
                'go_predict' => 'অনুমান করুন',
                'add_wallet' => 'Wallet-এ যোগ করুন',
                'invite' => 'বন্ধুদের আমন্ত্রণ (+৩ পয়েন্ট)',
                'err_name' => 'অনুগ্রহ করে আপনার নাম দিন।',
                'err_phone' => 'সঠিক WhatsApp নম্বর দিন।',
                'err_otp' => 'কোড ভুল বা মেয়াদোত্তীর্ণ।',
                'err_rate' => 'অনেক চেষ্টা। কিছুক্ষণ অপেক্ষা করুন।',
                'err_generic' => 'কিছু ভুল হয়েছে। আবার চেষ্টা করুন।',
            ],
            'ur' => [
                'dir' => 'rtl',
                'brand' => 'Cardify کے زیرِ اہتمام',
                'kicker' => 'فیفا ورلڈ کپ 2026',
                'hero_title' => 'آپ کا روزانہ ورلڈ کپ، واٹس ایپ پر۔',
                'hero_sub' => 'آپ کی زبان اور ٹائم زون میں روزانہ میچز اور نتائج، مفت پیشگوئی گیم کے ساتھ۔ Cardify کے زیرِ اہتمام۔',
                'f_name' => 'آپ کا نام',
                'f_phone' => 'واٹس ایپ نمبر',
                'f_language' => 'زبان',
                'f_timezone' => 'ٹائم زون',
                'tz_detected' => 'آپ کے مقام سے شناخت',
                'get_code' => 'میرا کوڈ بھیجیں',
                'sending' => 'بھیجا جا رہا ہے…',
                'otp_title' => 'اپنا کوڈ درج کریں',
                'otp_sub' => 'ہم نے واٹس ایپ پر 6 ہندسوں کا کوڈ بھیجا ہے',
                'verify' => 'تصدیق کریں اور شامل ہوں',
                'resend' => 'کوڈ دوبارہ بھیجیں',
                'change' => 'نمبر تبدیل کریں',
                'win_title' => 'اندازہ لگائیں اور جیتیں',
                'win_sub' => 'سرفہرست 3 فاتحین $10,000، $5,000، $1,000 جیتتے ہیں۔ داخلہ مفت۔',
                'success_title' => 'آپ شامل ہو گئے!',
                'success_sub' => 'آپ کا پہلا میچ ڈائجسٹ کل صبح 10 بجے آئے گا۔ ابھی اندازہ لگانا شروع کریں۔',
                'go_predict' => 'اندازہ لگائیں',
                'add_wallet' => 'Wallet میں شامل کریں',
                'invite' => 'دوستوں کو مدعو کریں (+3 پوائنٹس)',
                'err_name' => 'براہ کرم اپنا نام درج کریں۔',
                'err_phone' => 'درست واٹس ایپ نمبر درج کریں۔',
                'err_otp' => 'کوڈ غلط یا ختم شدہ ہے۔',
                'err_rate' => 'بہت زیادہ کوششیں۔ چند منٹ انتظار کریں۔',
                'err_generic' => 'کچھ غلط ہوا۔ دوبارہ کوشش کریں۔',
            ],
        ];
        return $S[$lang];
    }

    /** Mirror a signup into the Cardify backend leads table. Returns lead id or null. */
    public static function mirrorLead(string $phone, string $name, string $lang, ?string $cc): ?string
    {
        try {
            $db = Database::getInstance();
            $id = self::uuid();
            $db->insert('cardify_signup_leads', [
                'id'         => $id,
                'phone'      => $phone,
                'source'     => 'world-cup',
                'utm_source' => 'wc.cardify.om',
                'utm_medium' => 'world-cup-2026',
                'utm_campaign' => 'wc2026',
                'locale'     => $lang,
                'ip_address' => substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 64),
                'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 512),
                'status'     => 'new',
            ]);
            return $id;
        } catch (Throwable $e) {
            error_log('WcHub::mirrorLead failed: ' . $e->getMessage());
            return null; // never block signup on the lead mirror
        }
    }

    /**
     * Kabir's (96891117795) OWN Dardasha token. Token owns the line, so this is
     * what makes WC messages send FROM Kabir (not Anna/Cupsbyaa). Cached.
     */
    public static function kabirToken(): ?string
    {
        static $tok = null;
        if ($tok !== null) return $tok ?: null;
        $tok = '';
        // PDO (shell_exec is disabled under PHP-FPM); Dardasha's token lives in wacrm.
        foreach (['mysql:host=127.0.0.1;dbname=wacrm;charset=utf8mb4',
                  'mysql:unix_socket=/tmp/mysql.sock;dbname=wacrm;charset=utf8mb4',
                  'mysql:unix_socket=/var/lib/mysql/mysql.sock;dbname=wacrm;charset=utf8mb4'] as $dsn) {
            try {
                $pdo = new PDO($dsn, 'wacrm', 'XdbDAdX3crnDyF6m', [PDO::ATTR_TIMEOUT=>5, PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
                $v = $pdo->query("SELECT api_key FROM user WHERE email='kabir@bhd.om' LIMIT 1")->fetchColumn();
                if ($v) { $tok = (string)$v; break; }
            } catch (Throwable $e) { /* try next dsn */ }
        }
        if ($tok === '') error_log('WcHub::kabirToken: could not resolve token');
        return $tok ?: null;
    }

    /** Send a WhatsApp text FROM Kabir (96891117795) via the local Dardasha API. */
    public static function waSend(string $to, string $text): bool
    {
        $tok = self::kabirToken();
        if (!$tok) { error_log('WcHub::waSend: no Kabir token'); return false; }
        $to = preg_replace('/\D/', '', $to);
        $payload = json_encode([
            'messageType'=>'text', 'requestType'=>'POST', 'token'=>$tok,
            'from'=>'96891117795', 'to'=>$to, 'text'=>$text,
        ]);
        $ch = curl_init('http://127.0.0.1:3000/api/qr/rest/send_message');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>$payload,
            CURLOPT_HTTPHEADER=>['Content-Type: application/json'], CURLOPT_TIMEOUT=>20,
        ]);
        $resp = (string)curl_exec($ch); curl_close($ch);
        return strpos($resp, '"success":true') !== false;
    }

    /** Generate a unique 8-char invite code. */
    public static function genRefCode(): string
    {
        $db = Database::getInstance();
        for ($i = 0; $i < 6; $i++) {
            $code = substr(strtoupper(bin2hex(random_bytes(6))), 0, 8);
            if (!$db->fetchOne("SELECT id FROM wc_users WHERE ref_code = :c", ['c'=>$code])) return $code;
        }
        return substr(strtoupper(bin2hex(random_bytes(8))), 0, 10);
    }

    /**
     * Create or update a verified WC user. Returns the user row.
     * On a NEW signup with a valid referrer code, link the referrer and
     * award them +3 referral points (once per new referee).
     */
    public static function upsertUser(string $phone, string $name, string $lang, string $tz, ?string $cc, ?string $leadId, ?string $referrerCode = null, int $notifyHour = 10): array
    {
        $notifyHour = max(0, min(23, $notifyHour));
        $db = Database::getInstance();
        $existing = $db->fetchOne("SELECT * FROM wc_users WHERE phone = :p LIMIT 1", ['p' => $phone]);
        if ($existing) {
            $set = ['name'=>$name, 'language'=>$lang, 'tz'=>$tz, 'country'=>$cc, 'status'=>'active', 'notify_hour'=>$notifyHour, 'verified_at'=>date('Y-m-d H:i:s')];
            if (empty($existing['ref_code'])) $set['ref_code'] = self::genRefCode();
            $db->update('wc_users', $set, 'id = :id', ['id' => $existing['id']]);
            return $db->fetchOne("SELECT * FROM wc_users WHERE id = :id", ['id' => $existing['id']]);
        }

        // Resolve referrer (must exist, active, and not the same phone).
        $referrer = null;
        $rc = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$referrerCode));
        if ($rc !== '') {
            $referrer = $db->fetchOne("SELECT id, phone FROM wc_users WHERE ref_code = :c AND status='active' LIMIT 1", ['c'=>$rc]);
            if ($referrer && $referrer['phone'] === $phone) $referrer = null; // no self-referral
        }

        $db->insert('wc_users', [
            'phone' => $phone, 'name' => $name, 'language' => $lang, 'tz' => $tz,
            'country' => $cc, 'status' => 'active', 'unsub_token' => bin2hex(random_bytes(16)),
            'ref_code' => self::genRefCode(), 'referred_by' => $referrer['id'] ?? null,
            'lead_id' => $leadId, 'ip_address' => substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 64),
            'verified_at' => date('Y-m-d H:i:s'),
        ]);
        $user = $db->fetchOne("SELECT * FROM wc_users WHERE phone = :p LIMIT 1", ['p' => $phone]);

        // Award the referrer +3 (bonus_points feeds points_cache + the prize race).
        if ($referrer) {
            $db->query("UPDATE wc_users SET bonus_points = bonus_points + 3,
                        points_cache = points_cache + 3 WHERE id = :id", ['id'=>$referrer['id']]);
        }
        return $user;
    }

    private static function uuid(): string
    {
        $d = random_bytes(16);
        $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
        $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }

    // Signed-cookie auth (robust across the front-controller and direct /api
    // files; avoids PHP-session name/start-order issues with config.php).
    private static function authSecret(): string
    {
        return hash('sha256', 'wc-auth-v1|' . (defined('DB_PASS') ? DB_PASS : 'x') . '|' . (defined('DB_NAME') ? DB_NAME : ''));
    }

    /** Issue the auth cookie after a verified OTP. Must run before output. */
    public static function login(array $user): void
    {
        $uid = (int)$user['id'];
        $exp = time() + 60 * 60 * 24 * 30; // 30 days
        $sig = hash_hmac('sha256', "$uid.$exp", self::authSecret());
        $val = "$uid.$exp.$sig";
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        setcookie('wc_auth', $val, ['expires'=>$exp,'path'=>'/','secure'=>$secure,'httponly'=>true,'samesite'=>'Lax']);
        $_COOKIE['wc_auth'] = $val; // usable within the same request
    }

    /** The currently logged-in WC user row, or null. */
    public static function currentUser(): ?array
    {
        $val = (string)($_COOKIE['wc_auth'] ?? '');
        $p = explode('.', $val);
        if (count($p) !== 3) return null;
        [$uid, $exp, $sig] = $p;
        if (!ctype_digit($uid) || !ctype_digit($exp) || (int)$exp < time()) return null;
        $expected = hash_hmac('sha256', "$uid.$exp", self::authSecret());
        if (!hash_equals($expected, $sig)) return null;
        $u = Database::getInstance()->fetchOne("SELECT * FROM wc_users WHERE id = :id AND status != 'unsubscribed' LIMIT 1", ['id' => (int)$uid]);
        return $u ?: null;
    }

    public static function logout(): void
    {
        setcookie('wc_auth', '', ['expires'=>time()-3600,'path'=>'/']);
        unset($_COOKIE['wc_auth']);
    }

    /** Predictions UI strings (en + ar full; hi/bn/ur fall back to en for now). */
    public static function pstrings(string $lang): array
    {
        $en = [
            'predict'=>'Predictions','matches'=>'Matches','leaderboard'=>'Leaderboard',
            'your_points'=>'Your points','rank'=>'Rank','upcoming'=>'Upcoming','live'=>'Live','results'=>'Results',
            'pick_winner'=>'Who wins?','draw'=>'Draw','exact'=>'Exact score (optional, +2)','save'=>'Save pick',
            'saved'=>'Saved','locked'=>'Locked','kickoff'=>'Kickoff','points'=>'pts','you'=>'You',
            'prize_line'=>'Top 3 win $10,000 / $5,000 / $1,000','signin'=>'Sign in to predict',
            'how_title'=>'How it works','how_body'=>'1 point for the right result, +2 for the exact score. Predict before kickoff. Top 3 on the final leaderboard win the cash prizes.',
            'empty'=>'Fixtures are loading. Check back shortly.','settings'=>'Settings','logout'=>'Log out','win'=>'win',
            'save_settings'=>'Save changes','unsubscribe'=>'Unsubscribe',
            'phone_note'=>'To use a different number, log out and sign in again with a one-time code.',
            'wallet_title'=>'Your World Cup pass','back'=>'Back',
            'wallet_have'=>'Save your points, rank, level and streak to your phone. The pass updates daily with your next match.',
            'wallet_soon'=>'A live pass with today\'s matches and your points, updated daily, is on the way.',
            'add_apple'=>'Add to Apple Wallet','add_google'=>'Add to Google Wallet',
            'wallet_player_label'=>'Player pass · points, rank, streak',
            'wallet_matches_label'=>'Matches pass · today\'s fixtures',
            'wallet_matches_have'=>'Add the daily fixtures pass: today\'s World Cup matches in your timezone, plus yesterday\'s results. Updates every day.',
            'add_apple_matches'=>'Add the Matches pass to Apple Wallet',
            'add_google_matches'=>'Add the Matches pass to Google Wallet',
            'saved_ok'=>'Saved','save_fail'=>'Could not save','err'=>'Error',
            // streak + gamification
            'streak'=>'Day streak','best_streak'=>'Best','checkin_today'=>'Check in today',
            'checked_in'=>'Checked in','come_back'=>'Come back tomorrow to keep your streak',
            'streak_hint'=>'Check in daily for +1 point. Reach a 7-day streak for a +5 bonus.',
            'plus_one'=>'+1 point','plus_five_bonus'=>'7-day streak! +5 bonus',
            'badges'=>'Badges','badges_earned'=>'earned',
            'b_first_pred'=>'First prediction','b_first_pred_d'=>'Made your first pick',
            'b_ten_preds'=>'Strategist','b_ten_preds_d'=>'Made 10 predictions',
            'b_five_correct'=>'Sharpshooter','b_five_correct_d'=>'5 correct results',
            'b_streak7'=>'On fire','b_streak7_d'=>'7-day check-in streak',
            'b_referrer'=>'Recruiter','b_referrer_d'=>'Referred a friend',
            'locked_badge'=>'Locked',
            // XP / level HUD
            'level'=>'Level','xp'=>'XP','to_next'=>'to next level','tap_badge'=>'Tap a badge to see how to earn it',
            'newly_earned'=>'Badge unlocked','prize_pool'=>'Prize pool','your_run'=>'Your run','climbing'=>'Keep climbing',
        ];
        $ar = [
            'predict'=>'التوقعات','matches'=>'المباريات','leaderboard'=>'المتصدرون',
            'your_points'=>'نقاطك','rank'=>'الترتيب','upcoming'=>'القادمة','live'=>'مباشر','results'=>'النتائج',
            'pick_winner'=>'من يفوز؟','draw'=>'تعادل','exact'=>'النتيجة الدقيقة (اختياري، +2)','save'=>'حفظ التوقع',
            'saved'=>'تم الحفظ','locked'=>'مغلق','kickoff'=>'البداية','points'=>'نقطة','you'=>'أنت',
            'prize_line'=>'أفضل 3 يربحون 10,000 / 5,000 / 1,000 دولار','signin'=>'سجّل للتوقع',
            'how_title'=>'كيف تلعب','how_body'=>'نقطة واحدة للنتيجة الصحيحة، و+2 للنتيجة الدقيقة. توقّع قبل بداية المباراة. أفضل 3 في الترتيب النهائي يربحون الجوائز النقدية.',
            'empty'=>'يتم تحميل المباريات. عُد بعد قليل.','settings'=>'الإعدادات','logout'=>'خروج','win'=>'فوز',
            'save_settings'=>'حفظ التغييرات','unsubscribe'=>'إلغاء الاشتراك',
            'phone_note'=>'لاستخدام رقم مختلف، سجّل الخروج ثم ادخل من جديد برمز لمرة واحدة.',
            'wallet_title'=>'بطاقة كأس العالم','back'=>'رجوع',
            'wallet_have'=>'احفظ نقاطك وترتيبك ومستواك وسلسلتك على هاتفك. تُحدّث البطاقة يوميًا بمبارتك القادمة.',
            'wallet_soon'=>'بطاقة حية بمباريات اليوم ونقاطك، تُحدّث يوميًا، في الطريق إليك.',
            'add_apple'=>'أضف إلى Apple Wallet','add_google'=>'أضف إلى Google Wallet',
            'wallet_player_label'=>'بطاقة اللاعب · النقاط والترتيب والسلسلة',
            'wallet_matches_label'=>'بطاقة المباريات · مباريات اليوم',
            'wallet_matches_have'=>'أضف بطاقة المباريات اليومية: مباريات كأس العالم اليوم بتوقيتك، مع نتائج الأمس. تُحدّث كل يوم.',
            'add_apple_matches'=>'أضف بطاقة المباريات إلى Apple Wallet',
            'add_google_matches'=>'أضف بطاقة المباريات إلى Google Wallet',
            'saved_ok'=>'تم الحفظ','save_fail'=>'تعذّر الحفظ','err'=>'خطأ',
            // streak + gamification
            'streak'=>'سلسلة الأيام','best_streak'=>'الأفضل','checkin_today'=>'سجّل حضورك اليوم',
            'checked_in'=>'تم تسجيل الحضور','come_back'=>'عُد غدًا للحفاظ على سلسلتك',
            'streak_hint'=>'سجّل حضورك يوميًا لتربح نقطة. اصل إلى سلسلة 7 أيام لتربح 5 نقاط إضافية.',
            'plus_one'=>'+1 نقطة','plus_five_bonus'=>'سلسلة 7 أيام! +5 نقاط',
            'badges'=>'الأوسمة','badges_earned'=>'محققة',
            'b_first_pred'=>'أول توقع','b_first_pred_d'=>'سجّلت أول توقع لك',
            'b_ten_preds'=>'استراتيجي','b_ten_preds_d'=>'سجّلت 10 توقعات',
            'b_five_correct'=>'قنّاص','b_five_correct_d'=>'5 نتائج صحيحة',
            'b_streak7'=>'متّقد','b_streak7_d'=>'سلسلة حضور 7 أيام',
            'b_referrer'=>'مُحفّز','b_referrer_d'=>'دعوت صديقًا',
            'locked_badge'=>'مقفل',
            // XP / level HUD
            'level'=>'المستوى','xp'=>'نقاط الخبرة','to_next'=>'للمستوى التالي','tap_badge'=>'اضغط على وسام لمعرفة كيفية كسبه',
            'newly_earned'=>'تم فتح وسام','prize_pool'=>'مجموع الجوائز','your_run'=>'مسيرتك','climbing'=>'واصل التقدّم',
        ];
        $lang = self::lang($lang);
        return $lang === 'ar' ? $ar : $en;
    }

    /**
     * Derive earned/locked badges for a user from existing data only
     * (wc_predictions + wc_users). No new heavy infra. Returns an ordered
     * list of [key, icon, earned] for the strip; titles/desc come from
     * pstrings (b_*). Honest: every threshold is computed live.
     */
    public static function badges(array $user): array
    {
        $db  = Database::getInstance();
        $uid = (int)$user['id'];

        $total   = (int)($db->fetchOne("SELECT COUNT(*) AS c FROM wc_predictions WHERE user_id=:u", ['u'=>$uid])['c'] ?? 0);
        $correct = (int)($db->fetchOne("SELECT COUNT(*) AS c FROM wc_predictions WHERE user_id=:u AND scored=1 AND points>0", ['u'=>$uid])['c'] ?? 0);
        $refs    = (int)($db->fetchOne("SELECT COUNT(*) AS c FROM wc_users WHERE referred_by=:u", ['u'=>$uid])['c'] ?? 0);
        $streakBest = (int)($user['streak_best'] ?? 0);

        return [
            ['key'=>'first_pred',   'icon'=>'fa-flag-checkered', 'earned'=>$total >= 1],
            ['key'=>'ten_preds',    'icon'=>'fa-chess-knight',   'earned'=>$total >= 10],
            ['key'=>'five_correct', 'icon'=>'fa-bullseye',       'earned'=>$correct >= 5],
            ['key'=>'streak7',      'icon'=>'fa-fire',           'earned'=>$streakBest >= 7],
            ['key'=>'referrer',     'icon'=>'fa-user-plus',      'earned'=>$refs >= 1],
        ];
    }

    /**
     * Derive a game level + XP progress from a points total. Tiered thresholds
     * (each tier costs more), so a level feels earned. Returns:
     *   level    int   1-based level number
     *   into     int   XP earned inside the current level
     *   span     int   XP needed to clear the current level
     *   pct      int   0-100 progress through the current level
     *   floor    int   cumulative XP at the start of the current level
     *   next     int   cumulative XP at the start of the next level
     *   title    string a short rank name for the level band
     */
    public static function levelOf(int $points): array
    {
        $points = max(0, $points);
        // Cost to clear level L (1-based): 10, 16, 22, 28 ... grows by 6 each level.
        $cost = fn(int $l) => 10 + ($l - 1) * 6;
        $level = 1; $floor = 0;
        while ($points >= $floor + $cost($level)) {
            $floor += $cost($level);
            $level++;
            if ($level > 999) break;
        }
        $span = $cost($level);
        $into = $points - $floor;
        $pct  = $span > 0 ? (int)round($into / $span * 100) : 0;
        $titles = ['Rookie','Contender','Sharp','Pro','Veteran','Ace','Maestro','Legend'];
        $title  = $titles[min($level - 1, count($titles) - 1)];
        return [
            'level' => $level, 'into' => $into, 'span' => $span, 'pct' => max(0, min(100, $pct)),
            'floor' => $floor, 'next' => $floor + $span, 'title' => $title,
        ];
    }

    /** Has this user already checked in on their local "today"? */
    public static function checkedInToday(array $user): bool
    {
        $tzName = $user['tz'] ?: 'Asia/Muscat';
        try { $tz = new DateTimeZone($tzName); } catch (Throwable $e) { $tz = new DateTimeZone('Asia/Muscat'); }
        $today = (new DateTime('now', $tz))->format('Y-m-d');
        return ($user['last_checkin'] ?? null) === $today;
    }

    /**
     * Live snapshot of a user's game state for a wallet pass: points, rank,
     * level, streak and the next upcoming match. One source of truth for both
     * the Apple-pass issuer and the daily cron, so the pushed pass always
     * matches the website.
     */
    public static function walletState(array $user): array
    {
        $db   = Database::getInstance();
        $pts  = (int)($user['points_cache'] ?? 0);
        $rank = (int)($db->fetchOne(
            "SELECT COUNT(*)+1 AS r FROM wc_users WHERE status='active' AND points_cache > :p",
            ['p'=>$pts]
        )['r'] ?? 1);
        $lvl = self::levelOf($pts);

        $next = $db->fetchOne(
            "SELECT home, away, kickoff_utc FROM wc_matches
             WHERE kickoff_utc > UTC_TIMESTAMP() AND (state IS NULL OR state <> 'post')
             ORDER BY kickoff_utc ASC LIMIT 1"
        );
        $nextLabel = '-';
        if ($next) {
            $tzName = $user['tz'] ?: 'Asia/Muscat';
            try { $tz = new DateTimeZone($tzName); } catch (Throwable $e) { $tz = new DateTimeZone('Asia/Muscat'); }
            $ko = new DateTime($next['kickoff_utc'], new DateTimeZone('UTC'));
            $ko->setTimezone($tz);
            $nextLabel = $next['home'] . ' v ' . $next['away'] . ' · ' . $ko->format('D H:i');
        }

        return [
            'points'     => $pts,
            'rank'       => $rank,
            'level'      => (int)$lvl['level'],
            'level_title'=> (string)$lvl['title'],
            'xp_into'    => (int)$lvl['into'],
            'xp_span'    => (int)$lvl['span'],
            'streak'     => (int)($user['streak_count'] ?? 0),
            'next_match' => $nextLabel,
        ];
    }

    /**
     * A short content tag that changes whenever the pushable pass content
     * changes. Apple's lastUpdated / serial-list flow compares this so a
     * device only re-downloads a pass that actually moved.
     */
    public static function walletUpdateTag(array $state): string
    {
        return substr(sha1(json_encode([
            $state['points'], $state['rank'], $state['level'],
            $state['streak'], $state['next_match'],
        ])), 0, 16);
    }

    /**
     * Get (or lazily create) the wc_wallet_passes row for a user + pass type.
     * Returns ['serial'=>..., 'auth_token'=>...]. One pass per (user, type):
     *   'player'  - points/rank/level/streak/next match
     *   'matches' - today's fixtures + yesterday's results
     */
    public static function walletPassFor(int $uid, string $type = 'player'): array
    {
        $type = $type === 'matches' ? 'matches' : 'player';
        $db  = Database::getInstance();
        $row = $db->fetchOne(
            "SELECT serial, auth_token FROM wc_wallet_passes WHERE user_id=:u AND pass_type=:t LIMIT 1",
            ['u'=>$uid, 't'=>$type]
        );
        if ($row) return $row;
        // Serial namespace encodes the type so the web service can route by serial.
        $prefix = $type === 'matches' ? 'wcm' : 'wc';
        $serial = $prefix . $uid . '-' . bin2hex(random_bytes(8));
        $token  = bin2hex(random_bytes(20));
        try {
            $db->insert('wc_wallet_passes', ['user_id'=>$uid, 'pass_type'=>$type, 'serial'=>$serial, 'auth_token'=>$token, 'updated_tag'=>'0', 'platform'=>'apple']);
        } catch (Throwable $e) {
            // race: another request created it first
            $row = $db->fetchOne(
                "SELECT serial, auth_token FROM wc_wallet_passes WHERE user_id=:u AND pass_type=:t LIMIT 1",
                ['u'=>$uid, 't'=>$type]
            );
            if ($row) return $row;
            throw $e;
        }
        return ['serial'=>$serial, 'auth_token'=>$token];
    }

    /** Resolve a wc_wallet_passes serial's pass type ('player' default). */
    public static function walletPassType(string $serial): string
    {
        if (strpos($serial, 'wcm') === 0) return 'matches';
        $row = Database::getInstance()->fetchOne(
            "SELECT pass_type FROM wc_wallet_passes WHERE serial=:s LIMIT 1", ['s'=>$serial]
        );
        return ($row && ($row['pass_type'] ?? '') === 'matches') ? 'matches' : 'player';
    }
}
