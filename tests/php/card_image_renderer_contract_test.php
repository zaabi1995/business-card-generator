<?php

$rendererPath = __DIR__ . '/../../includes/CardImageRenderer.php';
$canonicalPath = __DIR__ . '/../../includes/CardRenderer.php';
$scriptPath = __DIR__ . '/../../scripts/render-card-images.py';

$renderer = is_file($rendererPath) ? file_get_contents($rendererPath) : '';
$canonical = is_file($canonicalPath) ? file_get_contents($canonicalPath) : '';
$script = is_file($scriptPath) ? file_get_contents($scriptPath) : '';
$failed = 0;

function rendererCheck(string $name, bool $condition): void
{
    global $failed;
    if (!$condition) {
        $failed++;
        echo "FAIL: {$name}\n";
        return;
    }
    echo "PASS: {$name}\n";
}

rendererCheck('renderer service exists', $renderer !== '');
rendererCheck('render exception exposes stable code', strpos($renderer, 'class CardRenderException') !== false
    && strpos($renderer, 'function errorCode()') !== false
    && strpos($renderer, 'function operationId()') !== false);
rendererCheck('staging is operation scoped', strpos($renderer, 'generated-staging') !== false
    && strpos($renderer, '$operationId') !== false);
rendererCheck('python invocation escapes paths', substr_count($renderer, 'escapeshellarg(') >= 3);
rendererCheck('both staged images are validated', strpos($renderer, "['front']") !== false
    && strpos($renderer, "['back']") !== false
    && strpos($renderer, 'getimagesize') !== false);
rendererCheck('promotion is transactional', strpos($renderer, 'beginTransaction()') !== false
    && strpos($renderer, 'commit()') !== false
    && strpos($renderer, 'rollback()') !== false);
rendererCheck('canonical renderer can regenerate', strpos($canonical, 'renderAndPromote') !== false);
rendererCheck('cli supports resolved input and output directory', strpos($script, '--input') !== false
    && strpos($script, '--out-dir') !== false);
rendererCheck('cli emits fixed dimensions and webp', strpos($script, '1050') !== false
    && strpos($script, '600') !== false
    && strpos($script, 'WEBP') !== false);

if ($failed > 0) {
    exit(1);
}

echo "ALL PASS\n";
