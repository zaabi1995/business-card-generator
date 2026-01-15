<?php
/**
 * Generated Cards - Cardify
 */
require_once __DIR__ . '/../config.php';
requireAdmin();
require_once INCLUDES_DIR . '/admin-layout.php';

$generatedLog = loadGeneratedLog();
$employees = loadEmployees();

$employeeLookup = [];
foreach ($employees as $emp) {
    $employeeLookup[$emp['id']] = $emp;
}

$templatesConfig = loadTemplates();
$templateLookup = [];
foreach ($templatesConfig['templates'] as $tpl) {
    $templateLookup[$tpl['id']] = $tpl;
}

// Handle deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    $entryId = $_POST['entry_id'] ?? '';
    $newLog = array_filter($generatedLog, fn($entry) => $entry['id'] !== $entryId);
    saveGeneratedLog(array_values($newLog));
    header('Location: generated.php?deleted=1');
    exit;
}

adminHeader('Generated Cards', 'generated');
?>

<div x-data="{ searchQuery: '', showPreview: false, previewEntry: null }">
    <!-- Page Header Actions -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
        <div>
            <p class="text-gray-600"><?php echo count($generatedLog); ?> cards generated</p>
        </div>
        <a href="batch_generate.php" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors text-sm font-medium flex items-center gap-2">
            <i class="fa-solid fa-wand-magic-sparkles"></i>
            <span>Generate New Cards</span>
        </a>
    </div>

    <!-- Success Message -->
    <?php if (isset($_GET['deleted'])): ?>
    <div class="mb-6 p-4 rounded-xl flex items-center gap-3 bg-green-50 border border-green-200 text-green-700">
        <i class="fa-solid fa-circle-check"></i>
        Card entry deleted successfully.
    </div>
    <?php endif; ?>

    <!-- Search -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-4 mb-6">
        <div class="relative max-w-md">
            <i class="fa-solid fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
            <input 
                type="text" 
                x-model="searchQuery"
                placeholder="Search by employee name..."
                class="w-full pl-10 pr-4 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 placeholder-gray-400 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 transition-all"
            >
        </div>
    </div>

    <!-- Cards Grid -->
    <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-6">
        <?php foreach (array_reverse($generatedLog) as $entry): ?>
        <?php 
        $employee = $employeeLookup[$entry['employeeId']] ?? null;
        $template = $templateLookup[$entry['templateId']] ?? null;
        ?>
        <div 
            class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden hover:shadow-md transition-shadow"
            x-show="!searchQuery || '<?php echo addslashes(strtolower($employee['name_en'] ?? '')); ?>'.includes(searchQuery.toLowerCase())"
        >
            <!-- Card Preview -->
            <div class="aspect-video bg-gray-100 relative group">
                <?php if (!empty($entry['frontPath'])): ?>
                <img src="<?php echo getBasePath() . ltrim($entry['frontPath'], '/'); ?>" alt="Card Front" class="w-full h-full object-contain">
                <?php else: ?>
                <div class="w-full h-full flex items-center justify-center text-gray-400">
                    <i class="fa-solid fa-image text-3xl"></i>
                </div>
                <?php endif; ?>
                
                <!-- Overlay Actions -->
                <div class="absolute inset-0 bg-gray-900/60 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center gap-3">
                    <a href="<?php echo getBasePath() . ltrim($entry['frontPath'] ?? '', '/'); ?>" target="_blank" 
                       class="px-3 py-2 bg-white text-gray-900 rounded-lg text-sm font-medium hover:bg-gray-100 transition-colors">
                        <i class="fa-solid fa-eye mr-1"></i> View
                    </a>
                    <a href="<?php echo getBasePath() . ltrim($entry['frontPath'] ?? '', '/'); ?>" download 
                       class="px-3 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700 transition-colors">
                        <i class="fa-solid fa-download mr-1"></i> Download
                    </a>
                </div>
            </div>
            
            <!-- Card Info -->
            <div class="p-4">
                <div class="flex items-start justify-between mb-3">
                    <div>
                        <h3 class="font-semibold text-gray-900"><?php echo sanitize($employee['name_en'] ?? 'Unknown'); ?></h3>
                        <p class="text-sm text-gray-500"><?php echo sanitize($employee['position_en'] ?? ''); ?></p>
                    </div>
                    <form method="post" class="inline" onsubmit="return confirm('Delete this entry?')">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="entry_id" value="<?php echo $entry['id']; ?>">
                        <button type="submit" class="p-2 text-gray-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors">
                            <i class="fa-solid fa-trash-can"></i>
                        </button>
                    </form>
                </div>
                
                <div class="flex items-center justify-between text-sm">
                    <span class="text-gray-500">
                        <i class="fa-solid fa-calendar mr-1"></i>
                        <?php echo date('M j, Y', strtotime($entry['generatedAt'])); ?>
                    </span>
                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-blue-50 text-blue-700">
                        <?php echo sanitize($template['name'] ?? 'Template'); ?>
                    </span>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        
        <?php if (empty($generatedLog)): ?>
        <div class="md:col-span-2 lg:col-span-3 bg-white rounded-xl border border-gray-200 p-12 text-center">
            <div class="text-gray-400">
                <i class="fa-solid fa-id-card text-4xl mb-4 opacity-50"></i>
                <p class="text-gray-600 font-medium">No cards generated yet</p>
                <p class="text-sm mt-1">Generate business cards for your employees</p>
                <a href="batch_generate.php" class="mt-4 inline-flex px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium">
                    <i class="fa-solid fa-wand-magic-sparkles mr-2"></i>Generate Cards
                </a>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php adminFooter(); ?>
