<?php

$reviewAccessPath = dirname(__DIR__, 2) . '/includes/ReviewAccess.php';
if (is_file($reviewAccessPath)) {
    require_once $reviewAccessPath;
}

function checkReviewAccess(string $label, $actual, $expected): void
{
    $ok = $actual === $expected;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $label . "\n";
    if (!$ok) {
        var_dump($actual, $expected);
        exit(1);
    }
}

if (!class_exists('ReviewAccess')) {
    echo "FAIL review access policy is available\n";
    exit(1);
}

$identifier = 'play-review@cardify.om';
$code = '482731';

putenv('CARDIFY_PLAY_REVIEW_IDENTIFIER');
putenv('CARDIFY_PLAY_REVIEW_CODE_HASH');
checkReviewAccess(
    'normal accounts remain on regular OTP verification',
    ReviewAccess::verificationDecision($identifier, $code),
    null
);

putenv('CARDIFY_PLAY_REVIEW_IDENTIFIER=' . $identifier);
putenv('CARDIFY_PLAY_REVIEW_CODE_HASH=' . hash('sha256', $code));

checkReviewAccess(
    'request delivery is bypassed only for the configured reviewer',
    ReviewAccess::usesStaticCode($identifier),
    true
);
checkReviewAccess(
    'other accounts still receive a normal OTP',
    ReviewAccess::usesStaticCode('someone@example.com'),
    false
);
checkReviewAccess(
    'the configured reviewer can use the reusable code',
    ReviewAccess::verificationDecision($identifier, $code),
    true
);
checkReviewAccess(
    'a wrong reviewer code is rejected without falling back to normal OTP',
    ReviewAccess::verificationDecision($identifier, '000000'),
    false
);
checkReviewAccess(
    'a malformed reviewer code is rejected',
    ReviewAccess::verificationDecision($identifier, '48273'),
    false
);
checkReviewAccess(
    'a different account still uses normal OTP verification',
    ReviewAccess::verificationDecision('someone@example.com', $code),
    null
);

putenv('CARDIFY_PLAY_REVIEW_CODE_HASH=invalid');
checkReviewAccess(
    'an invalid server-side hash disables the static reviewer credential',
    ReviewAccess::verificationDecision($identifier, $code),
    null
);

putenv('CARDIFY_PLAY_REVIEW_IDENTIFIER');
putenv('CARDIFY_PLAY_REVIEW_CODE_HASH');
echo "ALL PASS\n";
