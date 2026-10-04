<?php
/** Public-content monetisation, using the publisher and unit created in AdSense. */
final class CardifyAdSense
{
    public const CLIENT = 'ca-pub-4720055706897611';
    public const SLOT = '1547426434';

    /** Set by a page that must not carry ads (the IQ test while running, IQ Pro members). */
    public static bool $suppress = false;

    public static function apex(): bool
    {
        $host = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? ''));
        return in_array($host, ['cardify.om', 'www.cardify.om'], true);
    }

    public static function contentPage(): bool
    {
        if (self::$suppress || !self::apex() || http_response_code() >= 400) return false;
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        // The IQ landing page, a shared result and the leaderboard carry ads; the test itself,
        // the account, the paid report and practice never do.
        if (preg_match('~^/(?:ar/)?iq(?:/(?:result/[A-Za-z0-9]{16}|leaderboard))?/?$~D', $path) === 1) return true;
        return preg_match('~^/(?:ar/)?(?:companies|logos)(?:\.php)?(?:/[^/]+)?/?$~D', $path) === 1
            && !preg_match('~/(?:terms|privacy|press|download)/?$~D', $path);
    }

    public static function messagingPage(): bool
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        return self::apex() && (self::contentPage()
            || preg_match('~^/(?:ar/)?(?:privacy|cookies)(?:\.php)?/?$~D', $path) === 1);
    }

    public static function head(): void
    {
        if (!self::apex()) return;
        echo '<meta name="google-adsense-account" content="' . self::CLIENT . '">' . "\n";
        if (!self::messagingPage()) return;
        // Google Privacy & messaging is published for this site. Its consent
        // integration controls ad eligibility in the EEA, UK and Switzerland.
        echo '<script async' . cspNonceAttr() . ' src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client='
            . self::CLIENT . '" crossorigin="anonymous"></script>' . "\n";
    }

    public static function footer(): void
    {
        if (!self::messagingPage()) return;
        $isAr = function_exists('currentDir') && currentDir() === 'rtl';
        $labels = require __DIR__ . '/../lang/' . ($isAr ? 'ar' : 'en') . '/advertising.php';
        if (self::contentPage()): ?>
        <aside class="cardify-ad-placement" aria-label="<?= htmlspecialchars($labels['advertisement']) ?>">
            <p><?= htmlspecialchars($labels['advertisement']) ?></p>
            <ins class="adsbygoogle" style="display:block" data-ad-client="<?= self::CLIENT ?>" data-ad-slot="<?= self::SLOT ?>" data-ad-format="auto" data-full-width-responsive="true"></ins>
        </aside>
        <script<?= cspNonceAttr() ?>>
        (window.adsbygoogle = window.adsbygoogle || []).push({});
        </script>
        <?php endif; ?>
        <div class="cardify-ad-privacy">
            <button type="button" id="cardify-ad-privacy" hidden><?= htmlspecialchars($labels['privacy_choices']) ?></button>
        </div>
        <style>
        .cardify-ad-placement{max-width:1100px;margin:24px auto;padding:16px;overflow:hidden;text-align:center}
        .cardify-ad-placement p{font-size:12px;color:#64748b;margin:0 0 8px}
        .cardify-ad-placement:has(ins[data-ad-status="unfilled"]){display:none}
        .cardify-ad-privacy{text-align:center;background:#f8fafc}
        .cardify-ad-privacy button{min-height:44px;padding:8px 16px;color:#334155;text-decoration:underline}
        </style>
        <script<?= cspNonceAttr() ?>>
        window.googlefc = window.googlefc || {};
        window.googlefc.callbackQueue = window.googlefc.callbackQueue || [];
        window.googlefc.callbackQueue.push({CONSENT_API_READY: function () {
            var button = document.getElementById('cardify-ad-privacy');
            if (button && typeof window.googlefc.showRevocationMessage === 'function') {
                button.hidden = false;
                button.addEventListener('click', function () { window.googlefc.showRevocationMessage(); });
            }
        }});
        </script>
        <?php
    }
}
