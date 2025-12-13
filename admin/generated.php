<?php
/**
 * Generated Cards Dashboard - BHD Business Cards
 */
require_once __DIR__ . '/../config.php';
requireAdmin();

$generatedLog = loadGeneratedLog();
$employees = loadEmployees();

// Create employee lookup
$employeeLookup = [];
foreach ($employees as $emp) {
    $employeeLookup[$emp['id']] = $emp;
}

// Get template lookup
$templatesConfig = loadTemplates();
$templateLookup = [];
foreach ($templatesConfig['templates'] as $tpl) {
    $templateLookup[$tpl['id']] = $tpl;
}

// Handle deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    requireCsrf();
    $entryId = $_POST['entry_id'] ?? '';
    
    $newLog = array_filter($generatedLog, function($entry) use ($entryId) {
        return $entry['id'] !== $entryId;
    });
    
    saveGeneratedLog(array_values($newLog));
    header('Location: generated.php?deleted=1');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Generated Cards | <?php echo SITE_NAME; ?></title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <link rel="stylesheet" href="<?php echo assetUrl('css/tailwind.css'); ?>?v=<?php echo filemtime(ASSETS_DIR . '/css/tailwind.css'); ?>">
    
    <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
    
    <style>
        [x-cloak] { display: none !important; }
        .glass-card { background: rgba(255, 255, 255, 0.03); backdrop-filter: blur(20px); border: 1px solid rgba(255, 255, 255, 0.1); }
        .input-bhd { background: rgba(255, 255, 255, 0.05); border: 1px solid rgba(255, 255, 255, 0.15); transition: all 0.3s ease; }
        .input-bhd:focus { background: rgba(255, 255, 255, 0.08); border-color: rgba(212, 175, 55, 0.6); }
    </style>
</head>
<body class="bg-alzayani-dark text-white font-sans min-h-screen antialiased">
    <div class="min-h-screen" x-data="{ searchQuery: '', showPreview: false, previewEntry: null }">
        <!-- Header -->
        <header class="glass-card border-b border-white/10">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4">
                <div class="flex items-center justify-between">
                    <div class="flex items-center space-x-4">
                        <a href="index.php" class="text-gray-400 hover:text-white transition-colors">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                            </svg>
                        </a>
                        <div>
                            <h1 class="text-xl font-bold text-white">Generated Cards</h1>
                            <p class="text-gray-500 text-xs"><?php echo count($generatedLog); ?> cards generated</p>
                        </div>
                    </div>
                    
                    <a href="batch_generate.php" class="px-4 py-2 bg-amber-500/20 text-amber-400 rounded-lg hover:bg-amber-500/30 transition-colors text-sm flex items-center space-x-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
                        </svg>
                        <span>Batch Generate</span>
                    </a>
                </div>
            </div>
        </header>
        
        <!-- Main Content -->
        <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
            <?php if (isset($_GET['deleted'])): ?>
            <div class="mb-6 p-4 rounded-xl bg-green-500/10 border border-green-500/30 text-green-400">
                Entry deleted successfully.
            </div>
            <?php endif; ?>
            
            <!-- Search -->
            <div class="mb-6">
                <input 
                    type="text" 
                    x-model="searchQuery"
                    placeholder="Search by employee name or email..."
                    class="input-bhd w-full max-w-md px-4 py-3 rounded-xl text-white"
                >
            </div>
            
            <!-- Generated Cards Grid -->
            <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-6">
                <?php foreach ($generatedLog as $entry): 
                    $employee = $employeeLookup[$entry['employee_id']] ?? null;
                    $frontTemplate = $templateLookup[$entry['front_template_id']] ?? null;
                    $backTemplate = $templateLookup[$entry['back_template_id']] ?? null;
                    
                    if (!$employee) continue;
                ?>
                <div 
                    class="glass-card rounded-xl overflow-hidden"
                    x-show="!searchQuery || 
                        '<?php echo addslashes(strtolower($employee['name_en'] ?? '')); ?>'.includes(searchQuery.toLowerCase()) ||
                        '<?php echo addslashes(strtolower($employee['email'] ?? '')); ?>'.includes(searchQuery.toLowerCase())"
                >
                    <!-- Card Preview -->
                    <div class="aspect-video bg-gray-900 relative">
                        <?php if ($entry['front_file']): ?>
                        <img 
                            src="<?php echo getBasePath(); ?>download_card.php?side=front&front=<?php echo urlencode($entry['front_file']); ?>&disposition=inline" 
                            alt="Front" 
                            class="w-full h-full object-contain"
                        >
                        <?php else: ?>
                        <div class="w-full h-full flex items-center justify-center text-gray-600">
                            <svg class="w-12 h-12" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                            </svg>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Card Info -->
                    <div class="p-4">
                        <div class="flex items-start justify-between mb-2">
                            <div>
                                <p class="font-medium text-white"><?php echo sanitize($employee['name_en'] ?? ''); ?></p>
                                <p class="text-gray-500 text-sm"><?php echo sanitize($employee['email'] ?? ''); ?></p>
                            </div>
                            <div class="flex items-center space-x-1">
                                <?php if ($entry['front_file']): ?>
                                <span class="px-2 py-0.5 text-xs bg-blue-500/20 text-blue-400 rounded">Front</span>
                                <?php endif; ?>
                                <?php if ($entry['back_file']): ?>
                                <span class="px-2 py-0.5 text-xs bg-purple-500/20 text-purple-400 rounded">Back</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <p class="text-gray-600 text-xs mb-3">
                            <?php echo date('M j, Y g:i A', strtotime($entry['generated_at'])); ?>
                        </p>
                        
                        <div class="flex items-center space-x-2">
                            <?php if ($entry['front_file']): ?>
                            <a 
                                href="<?php echo getBasePath(); ?>download_card.php?side=front&front=<?php echo urlencode($entry['front_file']); ?>&disposition=attachment" 
                                class="flex-1 py-2 text-center text-sm bg-blue-500/20 text-blue-400 rounded-lg hover:bg-blue-500/30 transition-colors"
                            >
                                Front
                            </a>
                            <?php endif; ?>
                            
                            <?php if ($entry['back_file']): ?>
                            <a 
                                href="<?php echo getBasePath(); ?>download_card.php?side=back&back=<?php echo urlencode($entry['back_file']); ?>&disposition=attachment" 
                                class="flex-1 py-2 text-center text-sm bg-purple-500/20 text-purple-400 rounded-lg hover:bg-purple-500/30 transition-colors"
                            >
                                Back
                            </a>
                            <?php endif; ?>

                            <?php if ($entry['front_file'] || $entry['back_file']): ?>
                            <a
                                href="<?php echo getBasePath(); ?>download_card.php?format=pdf&front=<?php echo urlencode($entry['front_file'] ?? ''); ?>&back=<?php echo urlencode($entry['back_file'] ?? ''); ?>"
                                class="px-3 py-2 text-center text-sm bg-green-500/20 text-green-400 rounded-lg hover:bg-green-500/30 transition-colors"
                            >
                                PDF
                            </a>
                            <?php endif; ?>
                            
                            <form method="post" class="inline" onsubmit="return confirm('Delete this entry?')">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="entry_id" value="<?php echo sanitize($entry['id']); ?>">
                                <button type="submit" class="p-2 text-gray-500 hover:text-red-400 transition-colors">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                    </svg>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                
                <?php if (empty($generatedLog)): ?>
                <div class="col-span-full text-center py-16 text-gray-500">
                    <svg class="w-16 h-16 mx-auto mb-4 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                    </svg>
                    <p>No cards generated yet</p>
                    <p class="text-sm mt-1">Cards will appear here when employees generate them</p>
                </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
</body>
</html>

