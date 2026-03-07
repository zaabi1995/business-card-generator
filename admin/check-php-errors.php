<?php
/**
 * Check for PHP errors that might break JavaScript
 */
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display in HTML
ini_set('log_errors', 1);

ob_start();

try {
    require_once __DIR__ . '/../config.php';
    require_once INCLUDES_DIR . '/Auth.php';
    
    Auth::init();
    
    $companyId = getCurrentCompanyId();
    
    // Load templates
    $templatesConfig = loadTemplates();
    $templates = $templatesConfig['templates'] ?? [];
    
    $activeFrontId = $templatesConfig['active_front'] ?? null;
    $activeBackId = $templatesConfig['active_back'] ?? null;
    
    $sampleEmployee = [
        'name_en' => 'John Doe',
        'name_ar' => 'جون دو',
        'email' => 'john@example.com'
    ];
    
    // Get company slug
    $companySlug = '';
    $baseUrl = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'bc.bhd.om');
    if ($companyId && DatabaseAdapter::useDatabase()) {
        $db = Database::getInstance();
        $company = $db->fetchOne("SELECT slug FROM companies WHERE id = :id", ['id' => $companyId]);
        $companySlug = $company['slug'] ?? '';
    }
    
    // Test all the JSON outputs
    $tests = [
        'templates' => json_encode(array_values($templates), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP),
        'activeFrontId' => json_encode($activeFrontId, JSON_HEX_TAG),
        'activeBackId' => json_encode($activeBackId, JSON_HEX_TAG),
        'sampleEmployee' => json_encode($sampleEmployee, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP),
        'defaultFields' => json_encode(getDefaultFieldSettings(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP),
        'basePath' => getBasePath(),
        'companySlug' => $companySlug,
        'baseUrl' => $baseUrl
    ];
    
    $output = ob_get_clean();
    
    echo "<h1>PHP Error Check</h1>\n";
    
    if (!empty($output)) {
        echo "<h2 style='color:red'>WARNING: Unexpected output detected!</h2>\n";
        echo "<pre style='background:#fee;padding:10px'>" . htmlspecialchars($output) . "</pre>\n";
    } else {
        echo "<p style='color:green'>No unexpected output.</p>\n";
    }
    
    echo "<h2>JSON Outputs</h2>\n";
    foreach ($tests as $name => $value) {
        $jsonError = json_last_error();
        echo "<h3>$name</h3>\n";
        if ($jsonError !== JSON_ERROR_NONE) {
            echo "<p style='color:red'>JSON Error: " . json_last_error_msg() . "</p>\n";
        }
        echo "<pre style='background:#f0f0f0;padding:5px;max-height:100px;overflow:auto'>" . htmlspecialchars(substr($value, 0, 500)) . (strlen($value) > 500 ? '...' : '') . "</pre>\n";
        
        // Check for problematic characters
        if (preg_match('/[^\x20-\x7E\s]/', $value, $matches)) {
            $pos = strpos($value, $matches[0]);
            echo "<p style='color:orange'>Contains non-ASCII at position $pos</p>\n";
        }
    }
    
    echo "<h2>Test JavaScript Generation</h2>\n";
    ?>
    <script>
    try {
        var test = {
            templates: <?php echo $tests['templates']; ?>,
            activeFrontId: <?php echo $tests['activeFrontId']; ?>,
            activeBackId: <?php echo $tests['activeBackId']; ?>,
            sampleEmployee: <?php echo $tests['sampleEmployee']; ?>,
            defaultFields: <?php echo $tests['defaultFields']; ?>,
            basePath: '<?php echo $tests['basePath']; ?>',
            companySlug: '<?php echo $tests['companySlug']; ?>',
            baseUrl: '<?php echo $tests['baseUrl']; ?>'
        };
        document.write('<p style="color:green">JavaScript object created successfully!</p>');
        console.log('Test object:', test);
    } catch(e) {
        document.write('<p style="color:red">JavaScript Error: ' + e.message + '</p>');
        console.error(e);
    }
    </script>
    <?php
    
} catch (Throwable $e) {
    ob_end_clean();
    echo "<h1 style='color:red'>PHP Exception!</h1>\n";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>\n";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>\n";
}
