<?php

$myCard = file_get_contents(__DIR__ . '/../../api/scan/my-card.php');
$designs = file_get_contents(__DIR__ . '/../../api/scan/designs.php');
$failed = 0;

function syncCheck(string $name, bool $condition): void
{
    global $failed;
    if (!$condition) {
        $failed++;
        echo "FAIL: {$name}\n";
        return;
    }
    echo "PASS: {$name}\n";
}

foreach (['my-card' => $myCard, 'designs' => $designs] as $name => $source) {
    syncCheck($name . ' uses canonical image renderer',
        strpos($source, 'CardImageRenderer::renderAndPromote') !== false);
    syncCheck($name . ' updates registered wallet passes',
        strpos($source, 'ScanPassService::onCardChanged') !== false
        && strpos($source, 'pushPassUpdates') !== false);
    syncCheck($name . ' maps render failure to retryable response',
        strpos($source, 'CardRenderException') !== false
        && strpos($source, 'render_failed') !== false
        && strpos($source, 'operation_id') !== false
        && strpos($source, 'http_response_code(503)') !== false);
}

syncCheck('my-card no longer uses invalidation-only edit flow',
    strpos($myCard, 'invalidateForEmployee') === false
    && strpos($myCard, 'invalidateForCompany') === false);
syncCheck('designs no longer uses invalidation-only edit flow',
    strpos($designs, 'invalidateForEmployee') === false);

if ($failed > 0) {
    exit(1);
}

echo "ALL PASS\n";
