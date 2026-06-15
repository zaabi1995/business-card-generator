<?php
/**
 * wallet_demo.php, dynamic Apple/Google Wallet pass for the homepage hero demo.
 *
 * GET /wallet_demo.php?platform=apple|google&name=..&title=..&company=..&color=%23009bc1&lang=en
 *
 * Builds a signed pass on the fly from the values the visitor chose in the
 * interactive hero (their typed name/title/company + the brand colour they
 * picked), with NO employee lookup. The QR always points at cardify.om so the
 * demo pass can't be turned into a phishing redirect.
 */

$configFile = __DIR__ . '/config.php';
if (!file_exists($configFile)) { http_response_code(500); exit('Not configured'); }
require_once $configFile;
require_once INCLUDES_DIR . '/SecurityHeaders.php';
SecurityHeaders::send();
require_once INCLUDES_DIR . '/AppleWalletPass.php';
require_once INCLUDES_DIR . '/GoogleWalletPass.php';
require_once INCLUDES_DIR . '/WalletImage.php';

// ---- Light rate limit (signing is not free; deter abuse) ----
if (class_exists('RateLimiter')) {
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        if (!RateLimiter::check('wallet_demo', $ip, 30, 60)) { // 30 / minute / IP
            http_response_code(429);
            header('Content-Type: text/plain; charset=utf-8');
            exit("Too many requests. Try again in a moment.\n");
        }
    } catch (Throwable $e) { /* fail open */ }
}

// ---- Sanitize inputs ----
$clean = function (string $s, int $max = 48): string {
    $s = preg_replace('/[\x00-\x1F\x7F]/u', '', $s); // strip control chars / newlines
    $s = trim((string)$s);
    return mb_substr($s, 0, $max);
};
$platform = strtolower(trim((string)($_GET['platform'] ?? 'apple')));
$name     = $clean((string)($_GET['name'] ?? ''));
$title    = $clean((string)($_GET['title'] ?? ''));
$company  = $clean((string)($_GET['company'] ?? ''), 40);
$lang     = (strtolower(trim((string)($_GET['lang'] ?? 'en'))) === 'ar') ? 'ar' : 'en';

if ($name === '')    { $name = ($lang === 'ar') ? 'علي عدنان حيدر درويش' : 'Ali Adnan Haider Darwish'; }
if ($title === '')   { $title = ($lang === 'ar') ? 'الرئيس التنفيذي' : 'Chief Executive Officer'; }
if ($company === '') { $company = ($lang === 'ar') ? 'مجموعة BHD' : 'BHD GROUP'; }

$color = (string)($_GET['color'] ?? '#009bc1');
if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) { $color = '#009bc1'; }

// Where the QR points. Defaults to cardify.om; if a &card= URL is supplied it
// must be a strict https://<sub>.cardify.om/... URL (host-validated to block
// open-redirect / phishing in the signed pass), otherwise it is ignored.
$qrTarget = 'https://cardify.om';
$cardParam = (string)($_GET['card'] ?? '');
if ($cardParam !== '') {
    $p = parse_url($cardParam);
    $okHost = !empty($p['host']) && preg_match('/^[a-z0-9-]+\.cardify\.om$/i', $p['host']);
    if (($p['scheme'] ?? '') === 'https' && $okHost) { $qrTarget = $cardParam; }
}

// hex -> rgb + contrast foreground
$hex = ltrim($color, '#');
$r = hexdec(substr($hex, 0, 2)); $g = hexdec(substr($hex, 2, 2)); $b = hexdec(substr($hex, 4, 2));
$bgRgb = "rgb($r, $g, $b)";
$lum   = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;
$fg    = ($lum < 0.62) ? 'rgb(255, 255, 255)' : 'rgb(17, 24, 39)';

// ================= GOOGLE =================
if ($platform === 'google') {
    if (!GoogleWalletPass::isEnabled()) {
        http_response_code(503); header('Content-Type: text/plain; charset=utf-8');
        exit("Google Wallet is not configured.\n");
    }
    // Stable-ish per (name,color) so re-taps update the same object instead of erroring.
    $oid = 'demo_' . substr(sha1($name . '|' . $title . '|' . $company . '|' . $color . '|' . $lang), 0, 24);
    $object = [
        'id'                => GoogleWalletPass::objectResourceId($oid),
        'classId'           => GoogleWalletPass::classResourceId(),
        'state'             => 'ACTIVE',
        'cardTitle'         => ['defaultValue' => ['language' => $lang, 'value' => $company]],
        'subheader'         => ['defaultValue' => ['language' => $lang, 'value' => $title]],
        'header'            => ['defaultValue' => ['language' => $lang, 'value' => $name]],
        'hexBackgroundColor'=> $color,
        'barcode'           => ['type' => 'QR_CODE', 'value' => $qrTarget, 'alternateText' => 'cardify.om'],
    ];
    $saveUrl = GoogleWalletPass::buildSaveUrl($object);
    header('Location: ' . $saveUrl, true, 302);
    exit;
}

// ================= APPLE =================
if (!AppleWalletPass::isEnabled()) {
    http_response_code(503); header('Content-Type: text/plain; charset=utf-8');
    exit("Apple Wallet is not configured.\n");
}

$isAr = ($lang === 'ar');
// Arabic: bake name+title into an RTL strip image, because PassKit renders
// Arabic text fields LTR on non-Arabic devices (mirrors wallet_apple.php).
// Fall back to Unicode RLE-wrapping if the baker is unavailable (GD missing).
$bakedStrip = $isAr ? WalletImage::brandStripWithText($color, $name, $title) : [];
$arBaked    = !empty($bakedStrip);
$styleKey   = $arBaked ? 'storeCard' : 'eventTicket';
$rle        = function (string $s) use ($isAr, $arBaked): string {
    return ($isAr && !$arBaked && $s !== '') ? "\u{202B}" . $s . "\u{202C}" : $s;
};

$qr = ['format' => 'PKBarcodeFormatQR', 'message' => $qrTarget, 'messageEncoding' => 'iso-8859-1'];
$L  = $isAr
    ? ['card' => 'البطاقة الرقمية', 'demo' => 'بطاقة تجريبية', 'disc' => 'بطاقة تجريبية غير مُفعّلة، أُنشئت على cardify.om']
    : ['card' => 'Digital Card', 'demo' => 'Demo card', 'disc' => 'Demo card, unverified. Made on cardify.om'];

$pass = [
    'formatVersion'      => 1,
    'passTypeIdentifier' => APPLE_WALLET_PASS_TYPE_ID,
    'serialNumber'       => 'demo-' . substr(sha1($name . $title . $company . $color), 0, 16),
    'teamIdentifier'     => APPLE_WALLET_TEAM_ID,
    // Issuer is ALWAYS Cardify (never the user-typed company) so a demo card can
    // never look like it was issued/authorised by a real organisation.
    'organizationName'   => defined('APPLE_WALLET_ORG_NAME') ? APPLE_WALLET_ORG_NAME : 'Cardify',
    // The typed company shows on the card FACE (next to the logo), as content, not issuer.
    'logoText'           => $rle($company),
    'description'        => trim($name . ($company !== '' ? ', ' . $company : '')),
    'foregroundColor'    => $fg,
    'backgroundColor'    => $bgRgb,
    'labelColor'         => $fg,
    'barcodes'           => [$qr],
    'barcode'            => $qr,
    $styleKey            => [
        'headerFields'    => [],
        'primaryFields'   => $arBaked ? [] : [['key' => 'name', 'label' => '', 'value' => $rle($name), 'textAlignment' => 'PKTextAlignmentCenter']],
        'secondaryFields' => ($arBaked || $title === '') ? [] : [['key' => 'title', 'label' => '', 'value' => $rle($title), 'textAlignment' => 'PKTextAlignmentNatural']],
        'auxiliaryFields' => [],
        'backFields'      => [
            ['key' => 'demo', 'label' => $L['demo'], 'value' => $L['disc']],
            ['key' => 'card', 'label' => $L['card'], 'value' => $qrTarget,
             'attributedValue' => '<a href="' . htmlspecialchars($qrTarget, ENT_QUOTES) . '">cardify.om</a>'],
        ],
    ],
];

$apple = new AppleWalletPass($pass);

// Icon + logo = the Cardify mark (re-encoded to clean PNGs Apple accepts). The
// brand colour the visitor picked is the pass BACKGROUND, so it still reflects
// their choice. Fall back to a brand-colour square, then a 1x1 transparent PNG,
// so the manifest always carries a valid icon.
$markPath = __DIR__ . '/assets/images/cardify-icon-512.png';
$transparentPng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
foreach (['icon.png' => 29, 'icon@2x.png' => 58, 'icon@3x.png' => 87] as $fname => $px) {
    $png = (is_readable($markPath) ? WalletImage::fitPng($markPath, $px, $px) : null)
        ?: WalletImage::brandBackground($color, $px, $px)
        ?: $transparentPng;
    $apple->addAsset($fname, $png);
}
// logo.png (top-left of the pass) = the Cardify mark.
foreach (['logo.png' => 50, 'logo@2x.png' => 100, 'logo@3x.png' => 150] as $fname => $px) {
    $png = (is_readable($markPath) ? WalletImage::fitPng($markPath, $px, $px) : null);
    if ($png) { $apple->addAsset($fname, $png); }
}
// Baked Arabic strip (RTL name+title rendered as an image) when present.
if ($arBaked) {
    foreach ($bakedStrip as $stripName => $stripBytes) { $apple->addAsset($stripName, $stripBytes); }
}

try {
    $bytes = $apple->build();
} catch (Throwable $e) {
    error_log('wallet_demo apple build failed: ' . $e->getMessage());
    http_response_code(500); header('Content-Type: text/plain; charset=utf-8');
    exit("Could not build the pass.\n");
}

$fileName = preg_replace('/[^A-Za-z0-9_-]+/', '_', $name ?: 'Cardify') . '.pkpass';
header('Content-Type: application/vnd.apple.pkpass');
header('Content-Disposition: attachment; filename="' . $fileName . '"');
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . strlen($bytes));
echo $bytes;
