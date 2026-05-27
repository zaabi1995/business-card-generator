<?php
/**
 * Cardify - Installer Layout Helpers
 * Lightweight header/footer for pre-config setup.
 */

function installerHeader($title, $basePath, $extraHead = '', $bodyClass = '') {
?>
<!DOCTYPE html>
<html lang="en" data-product="cardify" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($title); ?></title>
    <link rel="icon" href="<?php echo $basePath; ?>/favicon.svg" type="image/svg+xml">
    <link rel="alternate icon" href="<?php echo $basePath; ?>/favicon.ico">

    <link rel="preconnect" href="https://fonts.bhd.om">
    <link rel="preconnect" href="https://fonts.bhd.om" crossorigin>
    <link href="https://fonts.bhd.om/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- Font Awesome (CDN) -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <!-- Tailwind CSS (Local) -->
    <?php $tailwindVersion = @filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/techwind/css/tailwind.min.css') ?: time(); ?>
    <link rel="stylesheet" href="/assets/techwind/css/tailwind.min.css?v=<?php echo $tailwindVersion; ?>">
    
    <!-- Flowbite CSS (Local) -->
    <?php $flowbiteCssVersion = @filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/flowbite/app.css') ?: time(); ?>
    <link rel="stylesheet" href="/assets/flowbite/app.css?v=<?php echo $flowbiteCssVersion; ?>">
    
    <link rel="stylesheet" href="<?php echo $basePath; ?>/assets/css/cardify-overrides.css">

    <style>
        body { font-family: 'Inter', ui-sans-serif, system-ui, sans-serif; }
        [x-cloak] { display: none !important; }
    </style>
    <?php if (!empty($extraHead)) echo $extraHead; ?>
</head>
<body class="bg-gray-50 text-gray-900 antialiased <?php echo $bodyClass; ?>">
<?php
}

function installerFooter($extraScripts = '') {
?>
    <!-- Flowbite JS (Local) -->
    <?php $flowbiteJsVersion = @filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/flowbite/app.bundle.js') ?: time(); ?>
    <script src="/assets/flowbite/app.bundle.js?v=<?php echo $flowbiteJsVersion; ?>"></script>
    <?php if (!empty($extraScripts)) echo $extraScripts; ?>
</body>
</html>
<?php
}
