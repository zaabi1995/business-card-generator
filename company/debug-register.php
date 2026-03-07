<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Try to include the actual register.php and catch errors
try {
    ob_start();
    include __DIR__ . '/register.php';
    $output = ob_get_clean();
    echo $output;
} catch (Throwable $e) {
    ob_end_clean();
    echo "<pre>";
    echo "ERROR CAUGHT!\n\n";
    echo "Message: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . "\n";
    echo "Line: " . $e->getLine() . "\n\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    echo "</pre>";
}
