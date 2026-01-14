<?php
/**
 * Super Admin Panel
 * Full platform administration
 */
require_once __DIR__ . '/../../config.php';
require_once INCLUDES_DIR . '/Auth.php';

Auth::requireRole('super_admin');

$db = Database::getInstance();
$currentUser = Auth::getCurrentUser();

// Get statistics
$totalCompanies = $db->fetchOne("SELECT COUNT(*) as count FROM companies")['count'] ?? 0;
$totalEmployees = $db->fetchOne("SELECT COUNT(*) as count FROM employees")['count'] ?? 0;
$totalTemplates = $db->fetchOne("SELECT COUNT(*) as count FROM templates")['count'] ?? 0;
$totalGenerated = $db->fetchOne("SELECT COUNT(*) as count FROM generated_cards")['count'] ?? 0;
$activeSubscriptions = $db->fetchOne("SELECT COUNT(*) as count FROM companies WHERE subscription_status = 'active'")['count'] ?? 0;

// Get recent companies
$recentCompanies = $db->fetchAll("SELECT * FROM companies ORDER BY created_at DESC LIMIT 10");

// Get recent transactions
$recentTransactions = $db->fetchAll("SELECT pt.*, c.name as company_name FROM payment_transactions pt LEFT JOIN companies c ON pt.company_id = c.id ORDER BY pt.created_at DESC LIMIT 10");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Admin | <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="<?php echo assetUrl('css/tailwind.css'); ?>">
    <style>
        .glass-card { background: rgba(255, 255, 255, 0.03); backdrop-filter: blur(20px); border: 1px solid rgba(255, 255, 255, 0.1); }
        .stat-card { transition: all 0.3s ease; }
        .stat-card:hover { transform: translateY(-4px); }
    </style>
</head>
<body class="bg-alzayani-dark text-white font-sans min-h-screen">
    <div class="min-h-screen">
        <!-- Header -->
        <header class="glass-card border-b border-white/10">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-2xl font-bold text-white">Super Admin Panel</h1>
                        <p class="text-gray-400 text-sm">Platform Administration</p>
                    </div>
                    <div class="flex items-center space-x-4">
                        <span class="text-gray-400 text-sm"><?php echo sanitize($currentUser['name']); ?></span>
                        <a href="<?php echo getBasePath(); ?>logout.php" class="px-4 py-2 rounded-lg bg-red-500/20 text-red-400 hover:bg-red-500/30 transition-colors">
                            Logout
                        </a>
                    </div>
                </div>
            </div>
        </header>
        
        <!-- Main Content -->
        <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
            <!-- Statistics -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-6 mb-8">
                <div class="glass-card rounded-xl p-6 stat-card">
                    <div class="text-3xl font-bold text-white mb-2"><?php echo $totalCompanies; ?></div>
                    <div class="text-gray-400 text-sm">Total Companies</div>
                </div>
                <div class="glass-card rounded-xl p-6 stat-card">
                    <div class="text-3xl font-bold text-white mb-2"><?php echo $totalEmployees; ?></div>
                    <div class="text-gray-400 text-sm">Total Employees</div>
                </div>
                <div class="glass-card rounded-xl p-6 stat-card">
                    <div class="text-3xl font-bold text-white mb-2"><?php echo $totalTemplates; ?></div>
                    <div class="text-gray-400 text-sm">Templates</div>
                </div>
                <div class="glass-card rounded-xl p-6 stat-card">
                    <div class="text-3xl font-bold text-white mb-2"><?php echo $totalGenerated; ?></div>
                    <div class="text-gray-400 text-sm">Cards Generated</div>
                </div>
                <div class="glass-card rounded-xl p-6 stat-card">
                    <div class="text-3xl font-bold text-green-400 mb-2"><?php echo $activeSubscriptions; ?></div>
                    <div class="text-gray-400 text-sm">Active Subscriptions</div>
                </div>
            </div>
            
            <!-- Quick Actions -->
            <div class="grid md:grid-cols-3 gap-6 mb-8">
                <a href="../companies.php" class="glass-card rounded-xl p-6 hover:bg-white/5 transition-colors block">
                    <h3 class="text-lg font-semibold mb-2">Manage Companies</h3>
                    <p class="text-gray-400 text-sm">View and manage all companies and hierarchy</p>
                </a>
                <a href="users.php" class="glass-card rounded-xl p-6 hover:bg-white/5 transition-colors block">
                    <h3 class="text-lg font-semibold mb-2">Manage Users</h3>
                    <p class="text-gray-400 text-sm">Manage user accounts and roles</p>
                </a>
                <a href="settings.php" class="glass-card rounded-xl p-6 hover:bg-white/5 transition-colors block">
                    <h3 class="text-lg font-semibold mb-2">Platform Settings</h3>
                    <p class="text-gray-400 text-sm">Configure platform settings</p>
                </a>
            </div>
            
            <!-- Recent Activity -->
            <div class="grid md:grid-cols-2 gap-6">
                <div class="glass-card rounded-xl p-6">
                    <h2 class="text-xl font-bold mb-4">Recent Companies</h2>
                    <div class="space-y-3">
                        <?php foreach ($recentCompanies as $company): ?>
                        <div class="flex items-center justify-between p-3 rounded-lg bg-white/5">
                            <div>
                                <div class="font-semibold"><?php echo sanitize($company['name']); ?></div>
                                <div class="text-sm text-gray-400"><?php echo sanitize($company['slug']); ?></div>
                            </div>
                            <div class="text-sm text-gray-400">
                                <?php echo date('M d, Y', strtotime($company['created_at'])); ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <div class="glass-card rounded-xl p-6">
                    <h2 class="text-xl font-bold mb-4">Recent Transactions</h2>
                    <div class="space-y-3">
                        <?php foreach ($recentTransactions as $tx): ?>
                        <div class="flex items-center justify-between p-3 rounded-lg bg-white/5">
                            <div>
                                <div class="font-semibold"><?php echo sanitize($tx['company_name'] ?? 'N/A'); ?></div>
                                <div class="text-sm text-gray-400">$<?php echo number_format($tx['amount'], 2); ?> - <?php echo sanitize($tx['status']); ?></div>
                            </div>
                            <div class="text-sm text-gray-400">
                                <?php echo date('M d', strtotime($tx['created_at'])); ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>
</body>
</html>
