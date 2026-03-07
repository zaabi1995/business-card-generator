<?php
/**
 * Generated Cards - Cardify
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/admin-layout.php';

// Check authentication
if (!Auth::isLoggedIn()) {
    header('Location: ' . getBasePath() . 'login.php');
    exit;
}

$companyId = getCurrentCompanyId();

try {
    $generatedLog = loadGeneratedLog($companyId) ?: [];
    $employees = loadEmployees($companyId) ?: [];
    
    $employeeLookup = [];
    foreach ($employees as $emp) {
        $employeeLookup[$emp['id']] = $emp;
    }
    
    $templatesConfig = loadTemplates($companyId);
    $templateLookup = [];
    if (!empty($templatesConfig['templates'])) {
        foreach ($templatesConfig['templates'] as $tpl) {
            $templateLookup[$tpl['id']] = $tpl;
        }
    }
    
    // Group cards by employee for history view
    $cardsByEmployee = [];
    foreach ($generatedLog as $entry) {
        $empId = $entry['employeeId'] ?? 'unknown';
        if (!isset($cardsByEmployee[$empId])) {
            $cardsByEmployee[$empId] = [];
        }
        $cardsByEmployee[$empId][] = $entry;
    }
    
    // Count cards per employee
    $cardCountByEmployee = [];
    foreach ($cardsByEmployee as $empId => $cards) {
        $cardCountByEmployee[$empId] = count($cards);
    }
    
} catch (Exception $e) {
    $generatedLog = [];
    $employees = [];
    $employeeLookup = [];
    $templateLookup = [];
    $cardsByEmployee = [];
    $cardCountByEmployee = [];
    error_log("Generated page error: " . $e->getMessage());
}

// Get current company to check for sample card
$company = findCompanyById($companyId);
$currentSampleFront = $company['sample_card_front'] ?? null;
$currentSampleBack = $company['sample_card_back'] ?? null;

// Handle set as sample
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'set_sample') {
    $frontPath = $_POST['sample_front'] ?? '';
    $backPath = $_POST['sample_back'] ?? '';
    
    if ($frontPath && $backPath) {
        try {
            $db = Database::getInstance();
            $db->query(
                "UPDATE companies SET sample_card_front = :front, sample_card_back = :back WHERE id = :id",
                ['front' => $frontPath, 'back' => $backPath, 'id' => $companyId]
            );
            header('Location: generated.php?sample_set=1');
            exit;
        } catch (Exception $e) {
            error_log("Error setting sample card: " . $e->getMessage());
        }
    }
}

// Handle clear sample
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'clear_sample') {
    try {
        $db = Database::getInstance();
        $db->query(
            "UPDATE companies SET sample_card_front = NULL, sample_card_back = NULL WHERE id = :id",
            ['id' => $companyId]
        );
        header('Location: generated.php?sample_cleared=1');
        exit;
    } catch (Exception $e) {
        error_log("Error clearing sample card: " . $e->getMessage());
    }
}

// Handle deletion - also delete files from disk
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    $entryId = $_POST['entry_id'] ?? '';
    $frontPath = $_POST['front_path'] ?? '';
    $backPath = $_POST['back_path'] ?? '';
    
    if ($entryId) {
        // Delete files from disk
        $cardsDir = getCompanyCardsDir($companyId);
        
        // Delete front files (PNG and PDF)
        if ($frontPath) {
            $frontPng = $cardsDir . '/' . $frontPath;
            $frontPdf = $cardsDir . '/' . str_replace('.png', '.pdf', $frontPath);
            if (file_exists($frontPng)) @unlink($frontPng);
            if (file_exists($frontPdf)) @unlink($frontPdf);
        }
        
        // Delete back files (PNG and PDF)
        if ($backPath) {
            $backPng = $cardsDir . '/' . $backPath;
            $backPdf = $cardsDir . '/' . str_replace('.png', '.pdf', $backPath);
            if (file_exists($backPng)) @unlink($backPng);
            if (file_exists($backPdf)) @unlink($backPdf);
        }
        
        // Delete database entry
        if (deleteGeneratedCard($entryId, $companyId)) {
            header('Location: generated.php?deleted=1');
            exit;
        }
    }
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

    <!-- Success Messages -->
    <?php if (isset($_GET['deleted'])): ?>
    <div class="mb-6 p-4 rounded-xl flex items-center gap-3 bg-green-50 border border-green-200 text-green-700">
        <i class="fa-solid fa-circle-check"></i>
        Card entry deleted successfully.
    </div>
    <?php endif; ?>
    <?php if (isset($_GET['sample_set'])): ?>
    <div class="mb-6 p-4 rounded-xl flex items-center gap-3 bg-green-50 border border-green-200 text-green-700">
        <i class="fa-solid fa-circle-check"></i>
        Sample card set successfully! This card will be shown on the company landing page.
    </div>
    <?php endif; ?>
    <?php if (isset($_GET['sample_cleared'])): ?>
    <div class="mb-6 p-4 rounded-xl flex items-center gap-3 bg-blue-50 border border-blue-200 text-blue-700">
        <i class="fa-solid fa-info-circle"></i>
        Sample card cleared. The most recent card will be shown instead.
    </div>
    <?php endif; ?>
    
    <!-- Current Sample Card Banner -->
    <?php if ($currentSampleFront && $currentSampleBack): ?>
    <div class="mb-6 p-4 rounded-xl bg-purple-50 border border-purple-200 flex items-center justify-between">
        <div class="flex items-center gap-3 text-purple-700">
            <i class="fa-solid fa-star"></i>
            <span class="font-medium">A sample card is set for the landing page</span>
        </div>
        <form method="post" class="inline">
            <input type="hidden" name="action" value="clear_sample">
            <button type="submit" class="text-sm text-purple-600 hover:text-purple-800 font-medium">
                <i class="fa-solid fa-times mr-1"></i>Clear
            </button>
        </form>
    </div>
    <?php endif; ?>

    <!-- Search & Filters -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-4 mb-6">
        <div class="flex flex-col sm:flex-row gap-4">
            <div class="relative flex-1 max-w-md">
                <i class="fa-solid fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
                <input 
                    type="text" 
                    x-model="searchQuery"
                    placeholder="Search by employee name..."
                    class="w-full pl-10 pr-4 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 placeholder-gray-400 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 transition-all"
                >
            </div>
            <div class="flex items-center gap-4 text-sm text-gray-500">
                <span><strong><?php echo count($generatedLog); ?></strong> cards total</span>
                <span class="text-gray-300">|</span>
                <span><strong><?php echo count($cardsByEmployee); ?></strong> employees</span>
            </div>
        </div>
    </div>

    <!-- Cards Table -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Card Preview</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Employee</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Generated</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Version</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Format</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">QR Analytics</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php 
                    // Track version numbers per employee (newest first, so count down)
                    $employeeVersionTracker = [];
                    foreach ($cardsByEmployee as $empId => $cards) {
                        $employeeVersionTracker[$empId] = count($cards);
                    }
                    ?>
                    <?php foreach (array_reverse($generatedLog) as $index => $entry): ?>
                    <?php 
                    $employee = $employeeLookup[$entry['employeeId']] ?? null;
                    $template = $templateLookup[$entry['templateId']] ?? null;
                    $employeeId = $entry['employeeId'] ?? 'unknown';
                    
                    // Get version number for this card (total cards - remaining = version)
                    $totalCardsForEmployee = $cardCountByEmployee[$employeeId] ?? 1;
                    $currentVersion = $employeeVersionTracker[$employeeId] ?? 1;
                    $employeeVersionTracker[$employeeId]--; // Decrement for next card
                    $isLatestVersion = ($currentVersion === $totalCardsForEmployee);
                    
                    // Build correct URLs for card images
                    $cardsBasePath = 'uploads/companies/' . $companyId . '/cards/';
                    $frontPath = $entry['frontPath'] ?? '';
                    $backPath = $entry['backPath'] ?? '';
                    
                    // PNG URLs (for display and PNG download)
                    $frontPngUrl = !empty($frontPath) ? getBasePath() . $cardsBasePath . $frontPath : '';
                    $backPngUrl = !empty($backPath) ? getBasePath() . $cardsBasePath . $backPath : '';
                    
                    // PDF URLs (same base name, .pdf extension)
                    $frontPdfPath = str_replace('.png', '.pdf', $frontPath);
                    $backPdfPath = str_replace('.png', '.pdf', $backPath);
                    $frontPdfUrl = !empty($frontPath) ? getBasePath() . $cardsBasePath . $frontPdfPath : '';
                    $backPdfUrl = !empty($backPath) ? getBasePath() . $cardsBasePath . $backPdfPath : '';
                    
                    // Check if PDF files exist
                    $cardsDir = getCompanyCardsDir($companyId);
                    $frontHasPdf = !empty($frontPath) && file_exists($cardsDir . '/' . $frontPdfPath);
                    $backHasPdf = !empty($backPath) && file_exists($cardsDir . '/' . $backPdfPath);
                    
                    // Safely get employee info
                    $employeeName = $employee ? sanitize($employee['name_en'] ?? $employee['name_ar'] ?? 'Unknown') : 'Deleted Employee';
                    $employeeNameLower = $employee ? strtolower($employee['name_en'] ?? '') : '';
                    $employeeNameSlug = $employee ? preg_replace('/[^a-z0-9]+/', '-', strtolower($employee['name_en'] ?? 'card')) : 'card';
                    $employeePosition = $employee ? sanitize($employee['position_en'] ?? $employee['position_ar'] ?? '') : '';
                    $employeeEmail = $employee ? ($employee['email'] ?? '') : '';
                    
                    // Check if sample
                    $isSample = false;
                    if ($currentSampleFront && $currentSampleBack && $frontPath && $backPath) {
                        $isSample = (strpos($currentSampleFront, $frontPath) !== false && strpos($currentSampleBack, $backPath) !== false);
                    }
                    
                    // Build URLs
                    $ext = (defined('COMPANY_ADMIN_BASE') || !empty($_SESSION['company_slug'])) ? '' : '.php';
                    $analyticsUrl = getAdminBasePath() . 'analytics' . $ext . '?employee=' . urlencode($employeeId);
                    $orderPrintUrl = getAdminBasePath() . 'order_print' . $ext . '?employee=' . urlencode($employeeId);
                    if ($frontPngUrl) $orderPrintUrl .= '&card_front=' . urlencode($frontPngUrl);
                    if ($backPngUrl) $orderPrintUrl .= '&card_back=' . urlencode($backPngUrl);
                    ?>
                    <tr class="hover:bg-gray-50 transition-colors" 
                        x-show="!searchQuery || '<?php echo addslashes($employeeNameLower); ?>'.includes(searchQuery.toLowerCase())"
                        x-data="{ showMenu: false }">
                        <!-- Card Preview -->
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2">
                                <?php if ($frontPngUrl): ?>
                                <a href="<?php echo $frontPngUrl; ?>" target="_blank" class="block group relative">
                                    <img src="<?php echo $frontPngUrl; ?>" alt="Front" loading="lazy" 
                                         class="w-20 h-12 object-cover rounded border border-gray-200 group-hover:border-blue-400 transition-colors"
                                         onerror="this.src='data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 60%22><rect fill=%22%23f3f4f6%22 width=%22100%22 height=%2260%22/><text x=%2250%22 y=%2235%22 text-anchor=%22middle%22 fill=%22%239ca3af%22 font-size=%2210%22>No image</text></svg>'">
                                    <span class="absolute -bottom-1 -right-1 bg-blue-500 text-white text-[8px] px-1 rounded">F</span>
                                </a>
                                <?php endif; ?>
                                <?php if ($backPngUrl): ?>
                                <a href="<?php echo $backPngUrl; ?>" target="_blank" class="block group relative">
                                    <img src="<?php echo $backPngUrl; ?>" alt="Back" loading="lazy" 
                                         class="w-20 h-12 object-cover rounded border border-gray-200 group-hover:border-green-400 transition-colors"
                                         onerror="this.src='data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 60%22><rect fill=%22%23f3f4f6%22 width=%22100%22 height=%2260%22/><text x=%2250%22 y=%2235%22 text-anchor=%22middle%22 fill=%22%239ca3af%22 font-size=%2210%22>No image</text></svg>'">
                                    <span class="absolute -bottom-1 -right-1 bg-green-500 text-white text-[8px] px-1 rounded">B</span>
                                </a>
                                <?php endif; ?>
                                <?php if ($isSample): ?>
                                <span class="text-yellow-500" title="Sample card for landing page">
                                    <i class="fa-solid fa-star"></i>
                                </span>
                                <?php endif; ?>
                            </div>
                        </td>
                        
                        <!-- Employee Info -->
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded-full bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center text-white font-semibold text-sm">
                                    <?php echo strtoupper(substr($employeeName, 0, 2)); ?>
                                </div>
                                <div>
                                    <div class="flex items-center gap-2">
                                        <span class="font-medium text-gray-900"><?php echo $employeeName; ?></span>
                                        <?php if ($totalCardsForEmployee > 1): ?>
                                        <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium bg-purple-100 text-purple-700" title="<?php echo $totalCardsForEmployee; ?> cards generated for this employee">
                                            <i class="fa-solid fa-layer-group mr-0.5"></i><?php echo $totalCardsForEmployee; ?>
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($employeePosition): ?>
                                    <div class="text-sm text-gray-500"><?php echo $employeePosition; ?></div>
                                    <?php endif; ?>
                                    <?php if ($employeeEmail): ?>
                                    <div class="text-xs text-gray-400"><?php echo htmlspecialchars($employeeEmail); ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        
                        <!-- Generated Date -->
                        <td class="px-4 py-3">
                            <div class="text-sm text-gray-900"><?php echo date('M j, Y', strtotime($entry['generatedAt'])); ?></div>
                            <div class="text-xs text-gray-500"><?php echo date('g:i A', strtotime($entry['generatedAt'])); ?></div>
                        </td>
                        
                        <!-- Version -->
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-1">
                                <?php if ($isLatestVersion): ?>
                                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-semibold bg-green-100 text-green-700">
                                    <i class="fa-solid fa-check mr-1"></i>Latest
                                </span>
                                <?php else: ?>
                                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-600">
                                    v<?php echo $currentVersion; ?>
                                </span>
                                <?php endif; ?>
                                <?php if ($totalCardsForEmployee > 1): ?>
                                <span class="text-xs text-gray-400">of <?php echo $totalCardsForEmployee; ?></span>
                                <?php endif; ?>
                            </div>
                        </td>
                        
                        <!-- Format -->
                        <td class="px-4 py-3">
                            <div class="flex flex-wrap gap-1">
                                <?php if ($frontPngUrl): ?>
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-700">PNG</span>
                                <?php endif; ?>
                                <?php if ($frontHasPdf || $backHasPdf): ?>
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-700">PDF</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        
                        <!-- QR Analytics -->
                        <td class="px-4 py-3">
                            <?php if ($employee): ?>
                            <a href="<?php echo $analyticsUrl; ?>" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-medium text-indigo-600 hover:text-indigo-700 hover:bg-indigo-50 rounded-lg transition-colors">
                                <i class="fa-solid fa-chart-line"></i>
                                <span>View Scans</span>
                            </a>
                            <?php else: ?>
                            <span class="text-gray-400 text-sm">N/A</span>
                            <?php endif; ?>
                        </td>
                        
                        <!-- Actions -->
                        <td class="px-4 py-3">
                            <div class="flex items-center justify-end gap-1">
                                <!-- Download Menu -->
                                <div class="relative">
                                    <button @click="showMenu = !showMenu" @click.away="showMenu = false"
                                        class="p-2 text-blue-600 hover:bg-blue-50 rounded-lg transition-colors" title="Download">
                                        <i class="fa-solid fa-download"></i>
                                    </button>
                                    <div x-show="showMenu" x-transition x-cloak
                                        class="absolute right-0 bottom-full mb-2 w-48 bg-white rounded-lg shadow-xl border border-gray-200 py-1 z-50">
                                        <?php if ($frontHasPdf && $backHasPdf): ?>
                                        <button onclick="downloadAsPDF('<?php echo $frontPdfUrl; ?>', '<?php echo $backPdfUrl; ?>', '<?php echo $employeeNameSlug; ?>')"
                                            class="w-full px-3 py-2 text-left text-sm text-gray-700 hover:bg-gray-50 flex items-center gap-2">
                                            <i class="fa-solid fa-file-pdf text-red-500 w-4"></i>
                                            PDF (High Quality)
                                        </button>
                                        <?php endif; ?>
                                        <?php if ($frontPngUrl && $backPngUrl): ?>
                                        <button onclick="downloadAsPNGs('<?php echo $frontPngUrl; ?>', '<?php echo $backPngUrl; ?>', '<?php echo $employeeNameSlug; ?>')"
                                            class="w-full px-3 py-2 text-left text-sm text-gray-700 hover:bg-gray-50 flex items-center gap-2">
                                            <i class="fa-solid fa-file-zipper text-yellow-500 w-4"></i>
                                            PNGs (ZIP)
                                        </button>
                                        <?php endif; ?>
                                        <hr class="my-1">
                                        <?php if ($frontPngUrl): ?>
                                        <a href="<?php echo $frontPngUrl; ?>" download class="block px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 flex items-center gap-2">
                                            <i class="fa-solid fa-image text-blue-500 w-4"></i>
                                            Front Only
                                        </a>
                                        <?php endif; ?>
                                        <?php if ($backPngUrl): ?>
                                        <a href="<?php echo $backPngUrl; ?>" download class="block px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 flex items-center gap-2">
                                            <i class="fa-solid fa-image text-green-500 w-4"></i>
                                            Back Only
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <!-- Set as Sample -->
                                <?php if ($frontPath && $backPath && !$isSample): ?>
                                <form method="post" class="inline">
                                    <input type="hidden" name="action" value="set_sample">
                                    <input type="hidden" name="sample_front" value="companies/<?php echo $companyId; ?>/cards/<?php echo htmlspecialchars($frontPath); ?>">
                                    <input type="hidden" name="sample_back" value="companies/<?php echo $companyId; ?>/cards/<?php echo htmlspecialchars($backPath); ?>">
                                    <button type="submit" class="p-2 text-yellow-500 hover:bg-yellow-50 rounded-lg transition-colors" title="Set as sample">
                                        <i class="fa-regular fa-star"></i>
                                    </button>
                                </form>
                                <?php endif; ?>
                                
                                <!-- Order Print -->
                                <a href="<?php echo $orderPrintUrl; ?>" 
                                   class="p-2 text-green-600 hover:bg-green-50 rounded-lg transition-colors" title="Order Print">
                                    <i class="fa-solid fa-print"></i>
                                </a>
                                
                                <!-- Delete -->
                                <form method="post" class="inline" onsubmit="return confirm('Delete this card? Files will be removed from server.')">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="entry_id" value="<?php echo $entry['id']; ?>">
                                    <input type="hidden" name="front_path" value="<?php echo htmlspecialchars($frontPath); ?>">
                                    <input type="hidden" name="back_path" value="<?php echo htmlspecialchars($backPath); ?>">
                                    <button type="submit" class="p-2 text-gray-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors" title="Delete">
                                        <i class="fa-solid fa-trash-can"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <?php if (empty($generatedLog)): ?>
        <div class="p-12 text-center">
            <div class="text-gray-400">
                <i class="fa-solid fa-id-card text-4xl mb-4 opacity-50"></i>
                <p class="text-gray-600 font-medium">No cards generated yet</p>
                <p class="text-sm mt-1 text-gray-500">Generate business cards for your employees</p>
                <a href="batch_generate.php" class="mt-4 inline-flex px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium">
                    <i class="fa-solid fa-wand-magic-sparkles mr-2"></i>Generate Cards
                </a>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- jsPDF, JSZip, PDF-lib for downloads -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://unpkg.com/pdf-lib@1.17.1/dist/pdf-lib.min.js"></script>
<script>
function isPDF(url) {
    return url.toLowerCase().endsWith('.pdf');
}

async function loadImageAsDataURL(url) {
    const response = await fetch(url);
    const blob = await response.blob();
    return new Promise((resolve) => {
        const reader = new FileReader();
        reader.onloadend = () => resolve(reader.result);
        reader.readAsDataURL(blob);
    });
}

async function downloadAsPDF(frontUrl, backUrl, filename) {
    try {
        const frontIsPDF = isPDF(frontUrl);
        const backIsPDF = isPDF(backUrl);
        
        // If both are PDFs, merge them using PDF-lib
        if (frontIsPDF && backIsPDF) {
            const { PDFDocument } = PDFLib;
            
            // Load both PDFs
            const [frontBytes, backBytes] = await Promise.all([
                fetch(frontUrl).then(r => r.arrayBuffer()),
                fetch(backUrl).then(r => r.arrayBuffer())
            ]);
            
            const frontPdf = await PDFDocument.load(frontBytes);
            const backPdf = await PDFDocument.load(backBytes);
            
            // Create merged PDF
            const mergedPdf = await PDFDocument.create();
            
            // Copy pages from front
            const [frontPage] = await mergedPdf.copyPages(frontPdf, [0]);
            mergedPdf.addPage(frontPage);
            
            // Copy pages from back
            const [backPage] = await mergedPdf.copyPages(backPdf, [0]);
            mergedPdf.addPage(backPage);
            
            // Download
            const pdfBytes = await mergedPdf.save();
            const blob = new Blob([pdfBytes], { type: 'application/pdf' });
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = filename + '-card.pdf';
            link.click();
            URL.revokeObjectURL(link.href);
            return;
        }
        
        // If PNGs, use jsPDF to create PDF with images
        const { jsPDF } = window.jspdf;
        
        // Load images
        const frontImg = await loadImageAsDataURL(frontUrl);
        const backImg = await loadImageAsDataURL(backUrl);
        
        // Create PDF - business card size (3.5" x 2" = 88.9mm x 50.8mm)
        const cardWidth = 88.9;
        const cardHeight = 50.8;
        
        const pdf = new jsPDF({
            orientation: 'landscape',
            unit: 'mm',
            format: [cardWidth, cardHeight]
        });
        
        // Front page
        pdf.addImage(frontImg, 'PNG', 0, 0, cardWidth, cardHeight);
        
        // Back page
        pdf.addPage([cardWidth, cardHeight], 'landscape');
        pdf.addImage(backImg, 'PNG', 0, 0, cardWidth, cardHeight);
        
        pdf.save(filename + '-card.pdf');
    } catch (error) {
        console.error('PDF generation error:', error);
        alert('Failed to generate PDF. Please try again.');
    }
}

async function downloadAsPNGs(frontUrl, backUrl, filename) {
    try {
        const zip = new JSZip();
        
        // Fetch files
        const [frontResponse, backResponse] = await Promise.all([
            fetch(frontUrl),
            fetch(backUrl)
        ]);
        
        const [frontBlob, backBlob] = await Promise.all([
            frontResponse.blob(),
            backResponse.blob()
        ]);
        
        // Determine extensions
        const frontExt = isPDF(frontUrl) ? '.pdf' : '.png';
        const backExt = isPDF(backUrl) ? '.pdf' : '.png';
        
        // Add to zip
        zip.file(filename + '-front' + frontExt, frontBlob);
        zip.file(filename + '-back' + backExt, backBlob);
        
        // Generate and download
        const content = await zip.generateAsync({ type: 'blob' });
        const link = document.createElement('a');
        link.href = URL.createObjectURL(content);
        link.download = filename + '-cards.zip';
        link.click();
        URL.revokeObjectURL(link.href);
    } catch (error) {
        console.error('ZIP generation error:', error);
        alert('Failed to generate ZIP. Please try again.');
    }
}
</script>

<?php adminFooter(); ?>
