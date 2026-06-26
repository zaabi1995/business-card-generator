<?php
/**
 * cron/wc_results.php - INSTANT full-time result notifications.
 *
 * For each match that ESPN marked finished (state='post') and has not yet been
 * announced (result_notified=0), build the full-time result and WhatsApp it to
 * every active subscriber who has instant results ON (notify_results=1), in the
 * user's language, PERSONALIZED with how their prediction did:
 *   - predicted + correct winner  -> "You called it! +N points" (exact-score noted)
 *   - predicted + wrong           -> "Not this time, you picked {X}"
 *   - did not predict             -> soft nudge to predict the next match
 *
 * After ATTEMPTING the sends for a match, result_notified is set to 1, so a
 * match's result is announced exactly ONCE (idempotent). A re-run sends nothing.
 *
 * Sends go FROM Kabir (96891117795) via the localhost Dardasha API, spaced
 * ~4s apart (anti-ban). One bad send never blocks the rest or the cron.
 *
 * Run on the VPS with the aaPanel PHP 8.3 binary (cron):
 *   php /www/wwwroot/cardify.om/cron/wc_results.php
 * Crontab (every 5 min, flock so two ticks never overlap):
 *   [slash]5 [star] [star] [star] [star] flock -n /tmp/wc_results.lock php /www/wwwroot/cardify.om/cron/wc_results.php >> /var/log/wc_results.log 2>&1
 *
 * Runs independently of wc_score.php (which awards the points). Order it after
 * wc_score in the cron so prediction.points are already set when we read them,
 * but it is safe either way: an unscored prediction reads points=0 and we fall
 * back to the result/exact comparison to phrase the outcome.
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/WcHub.php';

$db = Database::getInstance();

// Bidi controls so digits/scores stay intact inside RTL Arabic lines.
$RLM = "\u{200F}";                 // right-to-left mark (line prefix)
$iso = function ($s) { return "\u{2068}" . $s . "\u{2069}"; }; // first-strong isolate

// Compact flag + Arabic-name map for the 48 World Cup nations. en uses ESPN's
// names as given; ar uses these translations (falls back to the en name).
$FLAGS = [
    'Mexico'=>'🇲🇽','South Africa'=>'🇿🇦','United States'=>'🇺🇸','USA'=>'🇺🇸','Canada'=>'🇨🇦',
    'Argentina'=>'🇦🇷','Brazil'=>'🇧🇷','France'=>'🇫🇷','England'=>'🏴󠁧󠁢󠁥󠁮󠁧󠁿','Spain'=>'🇪🇸',
    'Germany'=>'🇩🇪','Portugal'=>'🇵🇹','Netherlands'=>'🇳🇱','Belgium'=>'🇧🇪','Italy'=>'🇮🇹',
    'Croatia'=>'🇭🇷','Uruguay'=>'🇺🇾','Colombia'=>'🇨🇴','Morocco'=>'🇲🇦','Japan'=>'🇯🇵',
    'South Korea'=>'🇰🇷','Korea Republic'=>'🇰🇷','Australia'=>'🇦🇺','Senegal'=>'🇸🇳','Switzerland'=>'🇨🇭',
    'Denmark'=>'🇩🇰','Poland'=>'🇵🇱','Serbia'=>'🇷🇸','Wales'=>'🏴󠁧󠁢󠁷󠁬󠁳󠁿','Ecuador'=>'🇪🇨',
    'Qatar'=>'🇶🇦','Saudi Arabia'=>'🇸🇦','Iran'=>'🇮🇷','IR Iran'=>'🇮🇷','Tunisia'=>'🇹🇳',
    'Ghana'=>'🇬🇭','Cameroon'=>'🇨🇲','Nigeria'=>'🇳🇬','Egypt'=>'🇪🇬','Algeria'=>'🇩🇿',
    'Ivory Coast'=>'🇨🇮','Costa Rica'=>'🇨🇷','Jamaica'=>'🇯🇲','Panama'=>'🇵🇦','Paraguay'=>'🇵🇾',
    'Peru'=>'🇵🇪','Chile'=>'🇨🇱','Norway'=>'🇳🇴','Sweden'=>'🇸🇪','Austria'=>'🇦🇹',
    'Turkey'=>'🇹🇷','Türkiye'=>'🇹🇷','Ukraine'=>'🇺🇦','Scotland'=>'🏴󠁧󠁢󠁳󠁣󠁴󠁿','New Zealand'=>'🇳🇿',
    'Uzbekistan'=>'🇺🇿','Jordan'=>'🇯🇴','Cape Verde'=>'🇨🇻','Curacao'=>'🇨🇼','Haiti'=>'🇭🇹',
    'Honduras'=>'🇭🇳','Bolivia'=>'🇧🇴','Venezuela'=>'🇻🇪','Greece'=>'🇬🇷','Czechia'=>'🇨🇿',
];
$NAMES_AR = [
    'Mexico'=>'المكسيك','South Africa'=>'جنوب أفريقيا','United States'=>'الولايات المتحدة','USA'=>'الولايات المتحدة','Canada'=>'كندا',
    'Argentina'=>'الأرجنتين','Brazil'=>'البرازيل','France'=>'فرنسا','England'=>'إنجلترا','Spain'=>'إسبانيا',
    'Germany'=>'ألمانيا','Portugal'=>'البرتغال','Netherlands'=>'هولندا','Belgium'=>'بلجيكا','Italy'=>'إيطاليا',
    'Croatia'=>'كرواتيا','Uruguay'=>'الأوروغواي','Colombia'=>'كولومبيا','Morocco'=>'المغرب','Japan'=>'اليابان',
    'South Korea'=>'كوريا الجنوبية','Korea Republic'=>'كوريا الجنوبية','Australia'=>'أستراليا','Senegal'=>'السنغال','Switzerland'=>'سويسرا',
    'Denmark'=>'الدنمارك','Poland'=>'بولندا','Serbia'=>'صربيا','Wales'=>'ويلز','Ecuador'=>'الإكوادور',
    'Qatar'=>'قطر','Saudi Arabia'=>'السعودية','Iran'=>'إيران','IR Iran'=>'إيران','Tunisia'=>'تونس',
    'Ghana'=>'غانا','Cameroon'=>'الكاميرون','Nigeria'=>'نيجيريا','Egypt'=>'مصر','Algeria'=>'الجزائر',
    'Ivory Coast'=>'ساحل العاج','Costa Rica'=>'كوستاريكا','Jamaica'=>'جامايكا','Panama'=>'بنما','Paraguay'=>'باراغواي',
    'Peru'=>'بيرو','Chile'=>'تشيلي','Norway'=>'النرويج','Sweden'=>'السويد','Austria'=>'النمسا',
    'Turkey'=>'تركيا','Türkiye'=>'تركيا','Ukraine'=>'أوكرانيا','Scotland'=>'اسكتلندا','New Zealand'=>'نيوزيلندا',
    'Uzbekistan'=>'أوزبكستان','Jordan'=>'الأردن','Cape Verde'=>'الرأس الأخضر','Curacao'=>'كوراساو','Haiti'=>'هايتي',
    'Honduras'=>'هندوراس','Bolivia'=>'بوليفيا','Venezuela'=>'فنزويلا','Greece'=>'اليونان','Czechia'=>'تشيكيا',
];
$flag = function (string $name) use ($FLAGS): string {
    $f = $FLAGS[$name] ?? '';
    return $f !== '' ? $f . ' ' : '';
};
$nameFor = function (string $name, string $lang) use ($NAMES_AR): string {
    return ($lang === 'ar') ? ($NAMES_AR[$name] ?? $name) : $name;
};

/**
 * Build the personalized result message for one user + one finished match.
 * $pred is the user's prediction row for this match, or null if they did not
 * predict it.
 */
$buildMessage = function (array $m, string $lang, ?array $pred) use ($flag, $nameFor, $RLM, $iso): string {
    $hs = (int)$m['home_score'];
    $as = (int)$m['away_score'];
    $home = (string)$m['home'];
    $away = (string)$m['away'];
    $result = $hs === $as ? 'draw' : ($hs > $as ? 'home' : 'away');

    if ($lang === 'ar') {
        $score = $iso("{$hs} - {$as}");
        $scoreLine = $flag($home) . $nameFor($home, 'ar') . "  *{$score}*  " . $flag($away) . $nameFor($away, 'ar');
        $lines = ['⚽ انتهت المباراة', '', $scoreLine, ''];
        if ($pred) {
            $pts = (int)($pred['points'] ?? 0);
            $hitWinner = ($pred['pick'] === $result);
            $hitExact  = ($pred['pred_home'] !== null && $pred['pred_away'] !== null
                && (int)$pred['pred_home'] === $hs && (int)$pred['pred_away'] === $as);
            if ($hitWinner || $pts > 0) {
                $ptsTxt = $pts > 0 ? $iso("+{$pts}") . ' نقطة' : '';
                $lines[] = '✅ توقّعك صحيح! ' . $ptsTxt;
                if ($hitExact) $lines[] = '🎯 نتيجة دقيقة، أحسنت!';
            } else {
                $pickLabel = $pred['pick'] === 'draw' ? 'التعادل'
                    : ($pred['pick'] === 'home' ? $nameFor($home, 'ar') : $nameFor($away, 'ar'));
                $lines[] = 'لم يحالفك الحظ، اخترت ' . $pickLabel . '.';
            }
        } else {
            $lines[] = 'توقّع المباراة القادمة: wc.cardify.om/predictions';
        }
        $lines[] = '';
        $lines[] = 'بدعم من Cardify';
        return implode("\n", array_map(function ($l) use ($RLM) {
            return trim($l) !== '' ? $RLM . $l : $l;
        }, $lines));
    }

    // English (default for en/hi/bn/ur).
    $scoreLine = $flag($home) . $home . "  *{$hs} - {$as}*  " . $flag($away) . $away;
    $lines = ['⚽ Full time', '', $scoreLine, ''];
    if ($pred) {
        $pts = (int)($pred['points'] ?? 0);
        $hitWinner = ($pred['pick'] === $result);
        $hitExact  = ($pred['pred_home'] !== null && $pred['pred_away'] !== null
            && (int)$pred['pred_home'] === $hs && (int)$pred['pred_away'] === $as);
        if ($hitWinner || $pts > 0) {
            $ptsTxt = $pts > 0 ? "+{$pts} " . ($pts === 1 ? 'point' : 'points') : '';
            $lines[] = '✅ You called it! ' . $ptsTxt;
            if ($hitExact) $lines[] = '🎯 Exact score, brilliant!';
        } else {
            $pickLabel = $pred['pick'] === 'draw' ? 'a draw'
                : ($pred['pick'] === 'home' ? $home : $away);
            $lines[] = 'Not this time, you picked ' . $pickLabel . '.';
        }
    } else {
        $lines[] = 'Predict the next one: wc.cardify.om/predictions';
    }
    $lines[] = '';
    $lines[] = 'Powered by Cardify';
    return implode("\n", $lines);
};

// --- main loop ----------------------------------------------------------------

$pending = $db->fetchAll(
    "SELECT espn_id, home, away, home_score, away_score
     FROM wc_matches
     WHERE state='post' AND result_notified=0
       AND home_score IS NOT NULL AND away_score IS NOT NULL
     ORDER BY kickoff_utc ASC"
);

if (!$pending) {
    echo "[wc_results] nothing to announce\n";
    exit(0);
}

// Recipients: active subscribers who want instant results. Read once.
$recipients = $db->fetchAll(
    "SELECT id, phone, language FROM wc_users
     WHERE status='active' AND notify_results=1"
);

$token = WcHub::kabirToken();
if (!$token) {
    // No token = cannot send. Do NOT flip result_notified, so the next run retries.
    fwrite(STDERR, "[wc_results] no Kabir token, aborting without marking matches\n");
    exit(1);
}

$totalSent = 0; $totalFail = 0; $matchesDone = 0;

foreach ($pending as $m) {
    $espn = (string)$m['espn_id'];

    // Predictions for this match, indexed by user_id (one read per match).
    $predRows = $db->fetchAll(
        "SELECT user_id, pick, pred_home, pred_away, points
         FROM wc_predictions WHERE match_id=:m", ['m'=>$espn]
    );
    $predByUser = [];
    foreach ($predRows as $pr) { $predByUser[(int)$pr['user_id']] = $pr; }

    // Mark the match announced BEFORE sending: a mid-run kill, flock overlap, or
    // a 503-that-still-delivered must never re-announce it to everyone (at-most-once).
    $db->update('wc_matches', ['result_notified'=>1], 'espn_id=:e', ['e'=>$espn]);

    $sent = 0; $fail = 0;
    foreach ($recipients as $u) {
        $uid  = (int)$u['id'];
        $lang = WcHub::lang($u['language'] ?? 'en');
        $pred = $predByUser[$uid] ?? null;
        $text = $buildMessage($m, $lang, $pred);
        try {
            $ok = WcHub::waSend((string)$u['phone'], $text);
            if ($ok) { $sent++; } else { $fail++; }
        } catch (Throwable $e) {
            $fail++;
            error_log("[wc_results] send failed to user {$uid}: " . $e->getMessage());
        }
        // Space sends ~4s (anti-ban). One bad send never blocks the rest.
        usleep(4000000);
    }

    $matchesDone++;
    $totalSent += $sent; $totalFail += $fail;
    echo "[wc_results] {$m['home']} {$m['home_score']}-{$m['away_score']} {$m['away']}: sent {$sent}, failed {$fail}\n";
}

echo "[wc_results] announced {$matchesDone} match(es) to ".count($recipients)." recipients (sent {$totalSent}, failed {$totalFail})\n";
