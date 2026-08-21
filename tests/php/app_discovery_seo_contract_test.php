<?php
/**
 * Contract for the iOS discovery pages and their privacy statements.
 *
 * The public pages must describe the standard on-device scan and the optional
 * user-triggered Pro server reread as two different workflows. This keeps the
 * answer-first copy, FAQ schema, and AI-readable summary aligned with the app.
 */

$root = dirname(__DIR__, 2);
$app = file_get_contents($root . '/app.php');
$scanner = file_get_contents($root . '/business-card-scanner.php');
$llms = file_get_contents($root . '/llms.txt');

if ($app === false || $scanner === false || $llms === false) {
    fwrite(STDERR, "FAIL: unable to read app discovery surfaces\n");
    exit(1);
}

$assertContains = static function (string $haystack, string $needle, string $message): void {
    if (strpos($haystack, $needle) === false) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$assertMissing = static function (string $haystack, string $needle, string $message): void {
    if (strpos($haystack, $needle) !== false) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$assertContains($app, 'Business Card Scanner & Digital Card', 'app title must name both searchable product categories');
$assertContains($app, "'@type' => 'FAQPage'", 'app page must publish FAQPage schema');
$assertContains($app, 'Questions about the Cardify app', 'app FAQs must be visible to readers');
$assertContains($app, 'أسئلة عن تطبيق كارديفاي', 'app FAQs must be visible in Arabic');
$assertContains($app, 'currently available for iPhone and iPad', 'Android answer must scope the native scanner to iOS');
$assertContains($app, 'modern Android browser', 'Android answer must identify the web alternative');
$assertContains($app, 'optional Pro server-assisted reread', 'app page must disclose the optional server workflow');
$assertContains($scanner, 'optional Pro server-assisted reread', 'scanner page must disclose the optional server workflow');
$assertContains($llms, 'server-assisted reread', 'AI-readable summary must disclose the optional server workflow');

$combined = $app . "\n" . $scanner . "\n" . $llms;
foreach ([
    'no card image ever uploaded',
    'card image never leaves the phone',
    'A scanned card is never uploaded to a server',
    'لا تغادر صورة البطاقة الجهاز',
] as $obsoleteClaim) {
    $assertMissing($combined, $obsoleteClaim, 'obsolete absolute privacy claim remains: ' . $obsoleteClaim);
}

$assertContains($combined, 'https://apps.apple.com/om/app/cardify-business-card-scanner/id6790749589', 'canonical App Store URL must remain present');
$assertMissing($combined, 'play.google.com', 'pages must not invent a Google Play listing');
$assertMissing($combined, "\xE2\x80\x94", 'edited discovery surfaces must not contain an em dash');

fwrite(STDOUT, "PASS: app discovery SEO contract\n");
