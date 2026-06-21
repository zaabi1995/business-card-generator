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
            'save_settings'=>'Save changes',
        ];
        $ar = [
            'predict'=>'التوقعات','matches'=>'المباريات','leaderboard'=>'المتصدرون',
            'your_points'=>'نقاطك','rank'=>'الترتيب','upcoming'=>'القادمة','live'=>'مباشر','results'=>'النتائج',
            'pick_winner'=>'من يفوز؟','draw'=>'تعادل','exact'=>'النتيجة الدقيقة (اختياري، +2)','save'=>'حفظ التوقع',
            'saved'=>'تم الحفظ','locked'=>'مغلق','kickoff'=>'البداية','points'=>'نقطة','you'=>'أنت',
            'prize_line'=>'أفضل 3 يربحون 10,000 / 5,000 / 1,000 دولار','signin'=>'سجّل للتوقع',
            'how_title'=>'كيف تلعب','how_body'=>'نقطة واحدة للنتيجة الصحيحة، و+2 للنتيجة الدقيقة. توقّع قبل بداية المباراة. أفضل 3 في الترتيب النهائي يربحون الجوائز النقدية.',
            'empty'=>'يتم تحميل المباريات. عُد بعد قليل.','settings'=>'الإعدادات','logout'=>'خروج','win'=>'فوز',
            'save_settings'=>'حفظ التغييرات',
        ];
        $lang = self::lang($lang);
        return $lang === 'ar' ? $ar : $en;
    }
}
