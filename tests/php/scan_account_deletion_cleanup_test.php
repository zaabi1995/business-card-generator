<?php
require_once dirname(__DIR__, 2) . '/includes/ScanAccountDeletionCleanup.php';

$failures = 0;

function cleanupCheck(string $label, bool $condition): void
{
    global $failures;
    echo ($condition ? 'PASS' : 'FAIL') . " $label\n";
    if (!$condition) {
        $failures++;
    }
}

$generatedOperationId =
    ScanAccountDeletionCleanup::generateOperationId();
cleanupCheck(
    'server operation identifier is canonical UUID v4',
    preg_match(
        '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-'
            . '[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',
        $generatedOperationId
    ) === 1
);

$bearerToken = str_repeat('A', 43);
$expectedBearerHash = hash('sha256', $bearerToken);
cleanupCheck(
    'authorization token is reduced to its one-way hash',
    ScanAccountDeletionCleanup::presentedBearerTokenHash(
        ['HTTP_AUTHORIZATION' => 'Bearer ' . $bearerToken],
        []
    ) === $expectedBearerHash
);
cleanupCheck(
    'redirected authorization header is supported',
    ScanAccountDeletionCleanup::presentedBearerTokenHash(
        ['REDIRECT_HTTP_AUTHORIZATION' => 'Bearer ' . $bearerToken],
        []
    ) === $expectedBearerHash
);
cleanupCheck(
    'short bearer input uses the same canonical parser as authentication',
    ScanAccountDeletionCleanup::presentedBearerTokenHash(
        ['HTTP_AUTHORIZATION' => 'Bearer short'],
        []
    ) === ScanAuth::hashToken('short')
);
cleanupCheck(
    'unrelated authorization scheme is not hashed',
    ScanAccountDeletionCleanup::presentedBearerTokenHash(
        ['HTTP_AUTHORIZATION' => 'Basic ' . $bearerToken],
        []
    ) === null
);

$employeeId = '11111111-1111-4111-8111-111111111111';
$safePath = 'uploads/scans/' . $employeeId
    . '/0123456789abcdef01234567.jpg';

cleanupCheck(
    'generated upload path is accepted for its owner',
    ScanAccountDeletionCleanup::normalizeOwnedPath($safePath, $employeeId)
        === $safePath
);
cleanupCheck(
    'another employee path is rejected',
    ScanAccountDeletionCleanup::normalizeOwnedPath(
        $safePath,
        '22222222-2222-4222-8222-222222222222'
    ) === null
);
cleanupCheck(
    'path traversal is rejected',
    ScanAccountDeletionCleanup::normalizeOwnedPath(
        'uploads/scans/' . $employeeId . '/../secret.jpg',
        $employeeId
    ) === null
);
cleanupCheck(
    'unexpected filename is rejected',
    ScanAccountDeletionCleanup::normalizeOwnedPath(
        'uploads/scans/' . $employeeId . '/avatar.jpg',
        $employeeId
    ) === null
);
cleanupCheck(
    'absolute path is rejected',
    ScanAccountDeletionCleanup::normalizeOwnedPath(
        '/uploads/scans/' . $employeeId . '/0123456789abcdef01234567.jpg',
        $employeeId
    ) === null
);

$tempRoot = sys_get_temp_dir()
    . '/cardify-account-cleanup-'
    . bin2hex(random_bytes(8));
$scanDirectory = $tempRoot . '/uploads/scans/' . $employeeId;
mkdir($scanDirectory, 0700, true);
$missingOwnerPath = 'uploads/scans/'
    . '44444444-4444-4444-8444-444444444444'
    . '/00112233445566778899aabb.png';
$missingOwnerResult =
    ScanAccountDeletionCleanup::deleteRelativePath(
        $missingOwnerPath,
        $tempRoot
    );
cleanupCheck(
    'missing owner directory confirms the file is absent',
    !empty($missingOwnerResult['success'])
);

$safeAbsolutePath = $scanDirectory . '/0123456789abcdef01234567.jpg';
file_put_contents($safeAbsolutePath, 'private scan');

$deleted = ScanAccountDeletionCleanup::deleteRelativePath(
    $safePath,
    $tempRoot
);
cleanupCheck(
    'safe scan file is deleted',
    !empty($deleted['success']) && !file_exists($safeAbsolutePath)
);

$outsidePath = $tempRoot . '/outside.jpg';
file_put_contents($outsidePath, 'must remain');
$linkPath = $scanDirectory . '/abcdef0123456789abcdef01.jpg';
$linkCreated = function_exists('symlink')
    && @symlink($outsidePath, $linkPath);
if ($linkCreated) {
    $linkRelativePath = 'uploads/scans/' . $employeeId
        . '/abcdef0123456789abcdef01.jpg';
    $linkResult = ScanAccountDeletionCleanup::deleteRelativePath(
        $linkRelativePath,
        $tempRoot
    );
    cleanupCheck(
        'scan symlink itself is removed without deleting its target',
        !empty($linkResult['success'])
            && !file_exists($linkPath)
            && file_exists($outsidePath)
    );
}

if (function_exists('symlink')) {
    $redirectEmployeeId = '33333333-3333-4333-8333-333333333333';
    $redirectTarget = $tempRoot . '/redirect-target';
    mkdir($redirectTarget, 0700, true);
    $redirectEmployeePath = $tempRoot
        . '/uploads/scans/'
        . $redirectEmployeeId;
    $redirectCreated = @symlink(
        $redirectTarget,
        $redirectEmployeePath
    );
    $outsideLinkTarget = $tempRoot . '/outside-link-target.jpg';
    file_put_contents($outsideLinkTarget, 'must remain');
    $redirectedLink = $redirectTarget
        . '/fedcba9876543210fedcba98.jpg';
    $redirectedLinkCreated = $redirectCreated
        && @symlink($outsideLinkTarget, $redirectedLink);
    if ($redirectedLinkCreated) {
        $redirectResult =
            ScanAccountDeletionCleanup::deleteRelativePath(
                'uploads/scans/'
                    . $redirectEmployeeId
                    . '/fedcba9876543210fedcba98.jpg',
                $tempRoot
            );
        cleanupCheck(
            'redirected employee directory is rejected before unlink',
            empty($redirectResult['success'])
                && is_link($redirectedLink)
                && file_exists($outsideLinkTarget)
        );
        @unlink($redirectedLink);
    }
    if ($redirectCreated) {
        @unlink($redirectEmployeePath);
    }
    @unlink($outsideLinkTarget);
    @rmdir($redirectTarget);
}

$missingScanRoot = sys_get_temp_dir()
    . '/cardify-account-cleanup-missing-'
    . bin2hex(random_bytes(8));
mkdir($missingScanRoot, 0700, true);
$missingScanRootResult =
    ScanAccountDeletionCleanup::deleteRelativePath(
        $safePath,
        $missingScanRoot
    );
cleanupCheck(
    'unavailable scan storage remains retryable',
    empty($missingScanRootResult['success'])
);
@rmdir($missingScanRoot);

@unlink($outsidePath);
@rmdir($scanDirectory);
@rmdir(dirname($scanDirectory));
@rmdir(dirname(dirname($scanDirectory)));
@rmdir($tempRoot);

echo $failures === 0 ? "ALL PASS\n" : "$failures FAILED\n";
exit($failures === 0 ? 0 : 1);
