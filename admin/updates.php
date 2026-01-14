<?php
/**
 * Database Updates & Migrations (Super Admin)
 * Run database migrations and updates
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/MigrationRunner.php';

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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Updates | <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="<?php echo assetUrl('css/tailwind.css'); ?>">
    <style>
        .glass-card { background: rgba(255, 255, 255, 0.03); backdrop-filter: blur(20px); border: 1px solid rgba(255, 255, 255, 0.1); }
    </style>
</head>
<body class="bg-alzayani-dark text-white font-sans min-h-screen">
    <div class="min-h-screen">
        <header class="glass-card border-b border-white/10">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-2xl font-bold text-white">Database Updates</h1>
                        <p class="text-gray-400 text-sm">Run database migrations and updates</p>
                    </div>
                    <a href="super/" class="px-4 py-2 rounded-lg bg-white/5 hover:bg-white/10 transition-colors">
                        ← Back
                    </a>
                </div>
            </div>
        </header>
        
        <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
            <?php if ($message): ?>
            <div class="mb-6 p-4 rounded-xl <?php echo $messageType === 'success' ? 'bg-green-500/10 border border-green-500/30 text-green-400' : 'bg-red-500/10 border border-red-500/30 text-red-400'; ?>">
                <?php echo sanitize($message); ?>
            </div>
            <?php endif; ?>
            
            <!-- Summary -->
            <div class="grid md:grid-cols-3 gap-6 mb-8">
                <div class="glass-card rounded-xl p-6">
                    <div class="text-3xl font-bold text-white mb-2"><?php echo count($availableMigrations); ?></div>
                    <div class="text-gray-400 text-sm">Total Migrations</div>
                </div>
                <div class="glass-card rounded-xl p-6">
                    <div class="text-3xl font-bold text-green-400 mb-2"><?php echo count($executedMigrations); ?></div>
                    <div class="text-gray-400 text-sm">Executed</div>
                </div>
                <div class="glass-card rounded-xl p-6">
                    <div class="text-3xl font-bold text-amber-400 mb-2"><?php echo count($pendingMigrations); ?></div>
                    <div class="text-gray-400 text-sm">Pending</div>
                </div>
            </div>
            
            <!-- Run All Pending -->
            <?php if (!empty($pendingMigrations)): ?>
            <div class="glass-card rounded-xl p-6 mb-8">
                <h2 class="text-xl font-bold mb-4">Run All Pending Migrations</h2>
                <p class="text-gray-400 text-sm mb-4">Execute all pending database updates in order.</p>
                <form method="post" onsubmit="return confirm('Run all pending migrations? This will update your database.');">
                    <input type="hidden" name="action" value="run_all">
                    <button type="submit" class="px-6 py-2 rounded-lg bg-gradient-to-r from-green-500 to-green-600 text-white font-bold hover:from-green-600 hover:to-green-700 transition-colors">
                        Run All Pending Migrations
                    </button>
                </form>
            </div>
            <?php endif; ?>
            
            <!-- Migrations List -->
            <div class="glass-card rounded-xl p-6">
                <h2 class="text-xl font-bold mb-4">Available Migrations</h2>
                <div class="space-y-4">
                    <?php foreach ($availableMigrations as $migration): ?>
                    <?php $isExecuted = in_array($migration['number'], $executedMigrations); ?>
                    <div class="p-4 rounded-lg bg-white/5 flex items-center justify-between">
                        <div class="flex-1">
                            <div class="flex items-center space-x-3">
                                <span class="text-2xl font-bold <?php echo $isExecuted ? 'text-green-400' : 'text-gray-500'; ?>">
                                    #<?php echo $migration['number']; ?>
                                </span>
                                <div>
                                    <div class="font-semibold"><?php echo ucwords($migration['name']); ?></div>
                                    <div class="text-sm text-gray-400"><?php echo $migration['file']; ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="flex items-center space-x-3">
                            <?php if ($isExecuted): ?>
                            <span class="px-3 py-1 rounded-full text-xs font-semibold bg-green-500/20 text-green-400">
                                ✓ Executed
                            </span>
                            <?php else: ?>
                            <form method="post" class="inline" onsubmit="return confirm('Run migration #<?php echo $migration['number']; ?>?');">
                                <input type="hidden" name="action" value="run_migration">
                                <input type="hidden" name="migration_number" value="<?php echo $migration['number']; ?>">
                                <button type="submit" class="px-4 py-2 rounded-lg bg-gradient-to-r from-amber-500 to-amber-600 text-white font-bold text-sm hover:from-amber-600 hover:to-amber-700 transition-colors">
                                    Run Migration
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    
                    <?php if (empty($availableMigrations)): ?>
                    <p class="text-gray-400 text-center py-8">No migrations available.</p>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>
</body>
</html>
