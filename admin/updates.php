<?php
/**
 * Database Updates & Migrations (Super Admin)
 * Run database migrations and updates
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/MigrationRunner.php';
require_once INCLUDES_DIR . '/admin-layout.php';

Auth::requireRole('super_admin');

$message = null;
$messageType = 'success';

// Handle migration execution
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'run_migration') {
        $migrationNumber = (int)($_POST['migration_number'] ?? 0);
        
        if ($migrationNumber > 0) {
            $result = MigrationRunner::runMigration($migrationNumber);
            
            if ($result['success']) {
                $message = 'Migration ' . $migrationNumber . ' executed successfully!';
                $messageType = 'success';
            } else {
                $message = $result['error'] ?? 'Migration failed';
                $messageType = 'error';
            }
        }
    } elseif ($action === 'run_all') {
        $results = MigrationRunner::runAllPending();
        $successCount = 0;
        $failCount = 0;
        
        foreach ($results as $item) {
            if ($item['result']['success']) {
                $successCount++;
            } else {
                $failCount++;
            }
        }
        
        if ($failCount === 0) {
            $message = "All pending migrations executed successfully! ({$successCount} migrations)";
            $messageType = 'success';
        } else {
            $message = "Migration completed with errors. Success: {$successCount}, Failed: {$failCount}";
            $messageType = 'error';
        }
    }
}

// Get migration status
$availableMigrations = MigrationRunner::getAvailableMigrations();
$executedMigrations = MigrationRunner::getExecutedMigrations();
$pendingMigrations = MigrationRunner::getPendingMigrations();

adminHeader('Database Updates', 'updates');
?>

<?php if ($message): ?>
<div class="mb-6 p-4 rounded-lg flex items-center <?php echo $messageType === 'success' ? 'bg-green-50 text-green-800' : 'bg-red-50 text-red-800'; ?>">
    <i class="fa-solid <?php echo $messageType === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?> mr-3"></i>
    <?php echo sanitize($message); ?>
</div>
<?php endif; ?>

<!-- Summary Stats -->
<div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
    <div class="bg-white rounded-lg shadow p-6">
        <div class="flex items-center">
            <div class="flex-shrink-0 bg-blue-100 rounded-lg p-3">
                <i class="fa-solid fa-database text-blue-600 text-xl"></i>
            </div>
            <div class="ml-4">
                <p class="text-sm font-medium text-gray-500">Total Migrations</p>
                <p class="text-2xl font-bold text-gray-900"><?php echo count($availableMigrations); ?></p>
            </div>
        </div>
    </div>
    
    <div class="bg-white rounded-lg shadow p-6">
        <div class="flex items-center">
            <div class="flex-shrink-0 bg-green-100 rounded-lg p-3">
                <i class="fa-solid fa-circle-check text-green-600 text-xl"></i>
            </div>
            <div class="ml-4">
                <p class="text-sm font-medium text-gray-500">Executed</p>
                <p class="text-2xl font-bold text-green-600"><?php echo count($executedMigrations); ?></p>
            </div>
        </div>
    </div>
    
    <div class="bg-white rounded-lg shadow p-6">
        <div class="flex items-center">
            <div class="flex-shrink-0 bg-amber-100 rounded-lg p-3">
                <i class="fa-solid fa-clock text-amber-600 text-xl"></i>
            </div>
            <div class="ml-4">
                <p class="text-sm font-medium text-gray-500">Pending</p>
                <p class="text-2xl font-bold text-amber-600"><?php echo count($pendingMigrations); ?></p>
            </div>
        </div>
    </div>
</div>

<!-- Run All Pending -->
<?php if (!empty($pendingMigrations)): ?>
<div class="bg-white rounded-lg shadow p-6 mb-8">
    <div class="flex items-center justify-between">
        <div>
            <h3 class="text-lg font-semibold text-gray-900">Run All Pending Migrations</h3>
            <p class="text-sm text-gray-500 mt-1">Execute all <?php echo count($pendingMigrations); ?> pending database updates in order</p>
        </div>
        <form method="post" onsubmit="return confirm('Run all pending migrations? This will update your database.');">
            <input type="hidden" name="action" value="run_all">
            <button type="submit" class="px-6 py-2.5 bg-green-600 hover:bg-green-700 text-white font-medium rounded-lg transition-colors flex items-center gap-2">
                <i class="fa-solid fa-play"></i>
                Run All Pending
            </button>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Migrations List -->
<div class="bg-white rounded-lg shadow overflow-hidden">
    <div class="p-6 border-b border-gray-200">
        <h3 class="text-lg font-semibold text-gray-900">Available Migrations</h3>
        <p class="text-sm text-gray-500 mt-1">View and run individual database migrations</p>
    </div>
    
    <div class="divide-y divide-gray-200">
        <?php foreach ($availableMigrations as $migration): ?>
        <?php $isExecuted = in_array($migration['number'], $executedMigrations); ?>
        <div class="p-6 flex items-center justify-between hover:bg-gray-50 transition-colors">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 rounded-lg flex items-center justify-center text-lg font-bold <?php echo $isExecuted ? 'bg-green-100 text-green-600' : 'bg-gray-100 text-gray-500'; ?>">
                    #<?php echo $migration['number']; ?>
                </div>
                <div>
                    <h4 class="font-semibold text-gray-900"><?php echo ucwords(str_replace('_', ' ', $migration['name'])); ?></h4>
                    <p class="text-sm text-gray-500"><?php echo $migration['file']; ?></p>
                </div>
            </div>
            
            <div>
                <?php if ($isExecuted): ?>
                <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-sm font-medium bg-green-100 text-green-800">
                    <i class="fa-solid fa-check"></i>
                    Executed
                </span>
                <?php else: ?>
                <form method="post" class="inline" onsubmit="return confirm('Run migration #<?php echo $migration['number']; ?>?');">
                    <input type="hidden" name="action" value="run_migration">
                    <input type="hidden" name="migration_number" value="<?php echo $migration['number']; ?>">
                    <button type="submit" class="px-4 py-2 bg-amber-500 hover:bg-amber-600 text-white font-medium rounded-lg transition-colors text-sm flex items-center gap-2">
                        <i class="fa-solid fa-play"></i>
                        Run Migration
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        
        <?php if (empty($availableMigrations)): ?>
        <div class="p-12 text-center">
            <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
                <i class="fa-solid fa-database text-gray-400 text-2xl"></i>
            </div>
            <h3 class="text-lg font-medium text-gray-900 mb-2">No Migrations Found</h3>
            <p class="text-gray-500">There are no database migrations available.</p>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php adminFooter(); ?>
