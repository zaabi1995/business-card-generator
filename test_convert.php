<?php
/**
 * Test PDF to PNG conversion
 * Run this to diagnose ImageMagick issues
 * DELETE THIS FILE AFTER TESTING
 */

echo "<pre>";
echo "=== PDF to PNG Conversion Test ===\n\n";

// Check PHP Imagick
echo "1. PHP Imagick extension: ";
if (extension_loaded('imagick')) {
    echo "LOADED ✓\n";
} else {
    echo "NOT LOADED ✗\n";
}

// Check command-line convert
echo "\n2. ImageMagick CLI (convert):\n";
$convertPaths = ['/usr/bin/convert', '/usr/local/bin/convert'];
$convertCmd = null;

foreach ($convertPaths as $path) {
    echo "   Checking $path: ";
    if (file_exists($path)) {
        echo "EXISTS";
        if (is_executable($path)) {
            echo " & EXECUTABLE ✓\n";
            $convertCmd = $path;
            break;
        } else {
            echo " but NOT EXECUTABLE ✗\n";
        }
    } else {
        echo "NOT FOUND\n";
    }
}

// Try which command
echo "   Checking via 'which convert': ";
exec("which convert 2>&1", $whichOutput, $whichCode);
if ($whichCode === 0 && !empty($whichOutput[0])) {
    echo trim($whichOutput[0]) . " ✓\n";
    if (!$convertCmd) $convertCmd = trim($whichOutput[0]);
} else {
    echo "NOT FOUND ✗\n";
}

// Check Ghostscript
echo "\n3. Ghostscript (gs):\n";
exec("which gs 2>&1", $gsOutput, $gsCode);
if ($gsCode === 0 && !empty($gsOutput[0])) {
    echo "   Found: " . trim($gsOutput[0]) . " ✓\n";
} else {
    echo "   NOT FOUND ✗\n";
}

// Test Ghostscript conversion (preferred method)
echo "\n4. Test Ghostscript PDF Conversion:\n";
$gsCmd = null;
exec("which gs 2>&1", $gsWhich, $gsWhichCode);
if ($gsWhichCode === 0 && !empty($gsWhich[0])) {
    $gsCmd = trim($gsWhich[0]);
}

if ($gsCmd) {
    $testPdf = sys_get_temp_dir() . '/test_' . time() . '.pdf';
    $testPng = sys_get_temp_dir() . '/test_' . time() . '.png';
    
    // Create minimal PDF
    $pdfContent = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/MediaBox[0 0 612 792]/Parent 2 0 R>>endobj\nxref\n0 4\n0000000000 65535 f \n0000000009 00000 n \n0000000052 00000 n \n0000000101 00000 n \ntrailer<</Size 4/Root 1 0 R>>\nstartxref\n178\n%%EOF";
    file_put_contents($testPdf, $pdfContent);
    
    echo "   Test PDF created: $testPdf\n";
    
    $cmd = sprintf('%s -dSAFER -dBATCH -dNOPAUSE -sDEVICE=png16m -r150 -dFirstPage=1 -dLastPage=1 -sOutputFile="%s" "%s" 2>&1', $gsCmd, $testPng, $testPdf);
    echo "   Command: $cmd\n";
    
    $output = [];
    exec($cmd, $output, $returnCode);
    echo "   Return code: $returnCode\n";
    if (!empty($output)) {
        echo "   Output: " . implode("\n", $output) . "\n";
    }
    
    if (file_exists($testPng)) {
        echo "   PNG created: YES ✓ (" . filesize($testPng) . " bytes)\n";
        @unlink($testPng);
    } else {
        echo "   PNG created: NO ✗\n";
    }
    
    @unlink($testPdf);
} else {
    echo "   Cannot test - Ghostscript not found\n";
}

// Check www-data permissions
echo "\n5. Current User: " . exec('whoami') . "\n";
echo "   PHP User: " . (function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid())['name'] : 'unknown') . "\n";

echo "\n=== End Test ===\n";
echo "</pre>";
?>
