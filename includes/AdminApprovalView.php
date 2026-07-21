<?php
/**
 * Shared branded shell for the scoped magic-link approval pages
 * (admin/approve-request.php + admin/one-tap-approve.php).
 *
 * Self-contained (no Tailwind/Alpine dependency) so these pages render
 * cleanly even when hit cold from an email client preview or a phone.
 * Bilingual: English primary, short Arabic line. No em dashes.
 */

if (!function_exists('aat_asset_url')) {
    /**
     * Resolve a stored preview path to a browser URL. Absolute URLs pass
     * through; relative paths are prefixed with the app base path.
     */
    function aat_asset_url(?string $path): string
    {
        $path = trim((string) $path);
        if ($path === '') return '';
        if (preg_match('#^https?://#i', $path)) return $path;
        $base = function_exists('getBasePath') ? getBasePath() : '/';
        return $base . ltrim($path, '/');
    }
}

if (!function_exists('aat_page_open')) {
    function aat_page_open(string $title, bool $isRtl = false): void
    {
        $lang = $isRtl ? 'ar' : 'en';
        $dir  = $isRtl ? ' dir="rtl"' : '';
        ?><!DOCTYPE html>
<html lang="<?php echo $lang; ?>"<?php echo $dir; ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo htmlspecialchars($title); ?></title>
    <link rel="icon" href="/favicon.ico">
    <link href="https://fonts.bhd.om/css2?family=Noto+Sans+Arabic:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --brand-blue:   #009bc1;
            --brand-yellow: #fb0;
            --brand-purple: #824598;
            --brand-teal:   #45c0ba;
            --brand-green:  #1f9d55;
            --brand-red:    #cc2b2b;
            --ink:          #141421;
            --muted:        #5a5a6e;
            --line:         rgba(20,20,33,0.08);
            --bg-soft:      #f6f8fb;
        }
        html, body { min-height: 100%; }
        body {
            font-family: 'Noto Sans Arabic', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
            color: var(--ink);
            background:
                radial-gradient(1200px 600px at 50% -10%, rgba(0,155,193,0.08), transparent 60%),
                linear-gradient(180deg, #fff 0%, var(--bg-soft) 100%);
            min-height: 100vh;
            display: flex;
            align-items: flex-start;
            justify-content: center;
            padding: 24px 16px 48px;
            -webkit-font-smoothing: antialiased;
        }
        .wrap { width: 100%; max-width: 620px; }
        .card {
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 20px;
            box-shadow: 0 30px 60px -20px rgba(10,20,40,0.12), 0 2px 6px rgba(10,20,40,0.04);
            padding: 28px 24px;
        }
        .brandbar { height: 5px; border-radius: 999px; margin-bottom: 20px;
            background: linear-gradient(90deg, var(--brand-blue), var(--brand-teal), var(--brand-yellow), var(--brand-purple)); }
        h1 { font-size: 20px; line-height: 1.3; margin-bottom: 4px; }
        .sub { color: var(--muted); font-size: 14px; margin-bottom: 4px; }
        .sub-ar { color: var(--muted); font-size: 13px; direction: rtl; }
        .company { font-size: 13px; color: var(--brand-blue); font-weight: 600; margin-bottom: 18px; }
        .section-title { font-size: 12px; text-transform: uppercase; letter-spacing: .04em;
            color: var(--muted); font-weight: 600; margin: 22px 0 10px; }
        .previews { display: grid; grid-template-columns: 1fr; gap: 14px; }
        @media (min-width: 520px) { .previews.two { grid-template-columns: 1fr 1fr; } }
        .preview { border: 1px solid var(--line); border-radius: 14px; overflow: hidden; background: var(--bg-soft); }
        .preview img { display: block; width: 100%; height: auto; }
        .preview .tag { font-size: 11px; color: var(--muted); text-align: center; padding: 6px; background: #fff; border-top: 1px solid var(--line); }
        .summary { border: 1px solid var(--line); border-radius: 14px; overflow: hidden; }
        .row { display: flex; gap: 12px; padding: 10px 14px; border-top: 1px solid var(--line); font-size: 14px; }
        .row:first-child { border-top: 0; }
        .row .k { color: var(--muted); flex: 0 0 40%; }
        .row .v { color: var(--ink); font-weight: 500; word-break: break-word; }
        .qty { display: inline-block; margin-top: 14px; padding: 6px 14px; border-radius: 999px;
            background: rgba(0,155,193,0.10); color: var(--brand-blue); font-weight: 600; font-size: 14px; }
        .actions { display: flex; flex-direction: column; gap: 10px; margin-top: 24px; }
        .btn { display: block; width: 100%; text-align: center; border: 0; border-radius: 12px;
            padding: 14px 18px; font-size: 15px; font-weight: 600; cursor: pointer; text-decoration: none; }
        .btn-primary { background: var(--brand-green); color: #fff; }
        .btn-secondary { background: #fff; color: var(--ink); border: 1px solid var(--line); }
        .btn-danger { background: #fff; color: var(--brand-red); border: 1px solid rgba(204,43,43,0.3); }
        .btn-link { display: inline-block; color: var(--brand-blue); text-decoration: none; font-weight: 600; font-size: 14px; }
        .reject-reason { width: 100%; border: 1px solid var(--line); border-radius: 12px; padding: 10px 12px;
            font: inherit; font-size: 14px; resize: vertical; min-height: 70px; margin-bottom: 10px; }
        details { margin-top: 6px; }
        details summary { cursor: pointer; color: var(--brand-red); font-size: 14px; font-weight: 600; list-style: none; padding: 6px 0; }
        details summary::-webkit-details-marker { display: none; }
        .note { color: var(--muted); font-size: 13px; margin-top: 18px; text-align: center; }
        .big-confirm { padding: 18px; font-size: 17px; }
        .center { text-align: center; }
        .icon { font-size: 34px; margin-bottom: 10px; }
    </style>
</head>
<body>
<div class="wrap">
<?php
    }
}

if (!function_exists('aat_page_close')) {
    function aat_page_close(): void
    {
        ?></div>
</body>
</html><?php
    }
}

if (!function_exists('aat_message_page')) {
    /**
     * Render a standalone branded message page (expired, already actioned,
     * rejected, error confirmations). $emoji is a plain unicode glyph.
     */
    function aat_message_page(
        string $pageTitle,
        string $emoji,
        string $headingEn,
        string $bodyEn,
        string $headingAr = '',
        string $bodyAr = '',
        ?string $linkUrl = null,
        string $linkLabel = ''
    ): void {
        if (class_exists('SecurityHeaders') && !headers_sent()) {
            // no-op, headers already sent by caller; guard only
        }
        aat_page_open($pageTitle);
        ?>
        <div class="card center">
            <div class="brandbar"></div>
            <div class="icon"><?php echo $emoji; ?></div>
            <h1><?php echo htmlspecialchars($headingEn); ?></h1>
            <p class="sub"><?php echo htmlspecialchars($bodyEn); ?></p>
            <?php if ($headingAr !== '' || $bodyAr !== ''): ?>
            <p class="sub-ar" style="margin-top:10px;">
                <?php echo htmlspecialchars(trim($headingAr . ($bodyAr !== '' ? '. ' . $bodyAr : ''))); ?>
            </p>
            <?php endif; ?>
            <?php if ($linkUrl): ?>
            <p style="margin-top:20px;">
                <a class="btn-link" href="<?php echo htmlspecialchars($linkUrl); ?>"><?php echo htmlspecialchars($linkLabel); ?></a>
            </p>
            <?php endif; ?>
        </div>
        <?php
        aat_page_close();
    }
}

if (!function_exists('aat_expired_page')) {
    /**
     * The canonical expired/invalid-token page. Never reveals whether the
     * underlying request exists; always the same neutral copy.
     */
    function aat_expired_page(): void
    {
        $login = (function_exists('getBasePath') ? getBasePath() : '/') . 'login';
        aat_message_page(
            'Approval link expired - Cardify',
            "\xE2\x8F\xB1", // stopwatch
            'This approval link has expired or is invalid',
            'Please log in to review this request.',
            'انتهت صلاحية رابط الموافقة أو أنه غير صالح',
            'يرجى تسجيل الدخول لمراجعة الطلب',
            $login,
            'Go to login'
        );
    }
}
