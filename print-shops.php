<?php
/**
 * Browse Print Shops - Public Page
 * Companies can browse available print shops
 */
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/PrintShop.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/Currency.php';

// Get search/filter parameters
$search = trim($_GET['q'] ?? '');
$country = trim($_GET['country'] ?? '');
$expressOnly = isset($_GET['express']);
$verifiedOnly = isset($_GET['verified']);

// Search or get all
$filters = [];
if ($expressOnly) $filters['express'] = true;
if ($verifiedOnly) $filters['verified'] = true;
if ($country) $filters['country'] = $country;

if ($search || !empty($filters)) {
    $shops = PrintShop::search($search, $filters);
} else {
    $shops = PrintShop::getAll('active', 50);
}

// Get featured shops
$featuredShops = PrintShop::getFeatured(3);

// Get unique countries for filter
$countries = [];
try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();
    $stmt = $pdo->query("SELECT DISTINCT country FROM print_shops WHERE status = 'active' AND country IS NOT NULL ORDER BY country");
    $countries = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {}

$pageTitle = 'Print Shops — Order Business Cards from Local Omani Print Shops';
$pageDescription = 'Browse verified print shops across Oman to order professional business cards. Compare prices, delivery times, and paper options. Order directly from Cardify.';
$canonicalUrl = 'https://cardify.om/print-shops';
$bodyClass = 'bg-gray-50';
$showNavigation = true;
require_once INCLUDES_DIR . '/ui-header.php';
?>

<div class="pt-24 pb-16">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        
        <!-- Header -->
        <div class="text-center mb-12">
            <h1 class="text-4xl font-bold text-gray-900 mb-4">Print Shop Marketplace</h1>
            <p class="text-xl text-gray-600 max-w-2xl mx-auto">
                Find trusted print shops for your business cards. Compare pricing, turnaround times, and services.
            </p>
        </div>
        
        <!-- Search & Filters -->
        <div class="bg-white rounded-2xl shadow-lg p-6 mb-8">
            <form method="get" class="flex flex-wrap items-center gap-4">
                <div class="flex-1 min-w-[200px]">
                    <div class="relative">
                        <i class="fa-solid fa-search absolute left-4 top-1/2 -translate-y-1/2 text-gray-400"></i>
                        <input type="text" name="q" value="<?php echo sanitize($search); ?>"
                               class="w-full pl-12 pr-4 py-3 border border-gray-200 rounded-xl focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20"
                               placeholder="Search print shops...">
                    </div>
                </div>
                
                <select name="country" class="px-4 py-3 border border-gray-200 rounded-xl focus:border-blue-500 bg-white">
                    <option value="">All Countries</option>
                    <?php foreach ($countries as $c): ?>
                    <option value="<?php echo sanitize($c); ?>" <?php echo $country === $c ? 'selected' : ''; ?>>
                        <?php echo sanitize($c); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                
                <label class="flex items-center gap-2 px-4 py-3 border border-gray-200 rounded-xl cursor-pointer hover:bg-gray-50">
                    <input type="checkbox" name="express" <?php echo $expressOnly ? 'checked' : ''; ?> class="rounded text-blue-600">
                    <span class="text-sm text-gray-700">Express Available</span>
                </label>
                
                <label class="flex items-center gap-2 px-4 py-3 border border-gray-200 rounded-xl cursor-pointer hover:bg-gray-50">
                    <input type="checkbox" name="verified" <?php echo $verifiedOnly ? 'checked' : ''; ?> class="rounded text-blue-600">
                    <span class="text-sm text-gray-700">Verified Only</span>
                </label>
                
                <button type="submit" class="px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white rounded-xl font-medium transition-colors">
                    Search
                </button>
            </form>
        </div>
        
        <!-- Featured Shops -->
        <?php if (!empty($featuredShops) && empty($search)): ?>
        <div class="mb-12">
            <h2 class="text-2xl font-bold text-gray-900 mb-6 flex items-center gap-2">
                <i class="fa-solid fa-star text-amber-500"></i>
                Featured Print Shops
            </h2>
            <div class="grid md:grid-cols-3 gap-6">
                <?php foreach ($featuredShops as $shop): ?>
                <?php 
                $pricing = json_decode($shop['pricing'], true); 
                $shopCurrency = $shop['currency'] ?? 'USD';
                ?>
                <div class="bg-white rounded-2xl border-2 border-amber-200 shadow-lg overflow-hidden hover:shadow-xl transition-all">
                    <div class="p-6">
                        <div class="flex items-start gap-4 mb-4">
                            <?php if (!empty($shop['logo_url'])): ?>
                            <img src="<?php echo getBasePath() . ltrim(sanitize($shop['logo_url']), '/'); ?>" alt="<?php echo sanitize($shop['name']); ?>" class="w-14 h-14 rounded-xl object-cover">
                            <?php else: ?>
                            <div class="w-14 h-14 bg-amber-100 rounded-xl flex items-center justify-center text-amber-600 font-bold text-xl">
                                <?php echo strtoupper(substr($shop['name'], 0, 2)); ?>
                            </div>
                            <?php endif; ?>
                            <div class="flex-1">
                                <h3 class="text-lg font-bold text-gray-900">
                                    <?php echo sanitize($shop['name']); ?>
                                    <?php if ($shop['verified']): ?>
                                    <i class="fa-solid fa-circle-check text-blue-500 text-sm ml-1"></i>
                                    <?php endif; ?>
                                </h3>
                                <p class="text-gray-500"><?php echo sanitize($shop['city']); ?>, <?php echo sanitize($shop['country']); ?></p>
                            </div>
                        </div>
                        
                        <?php if ($shop['description']): ?>
                        <p class="text-gray-600 text-sm mb-4 line-clamp-2"><?php echo sanitize($shop['description']); ?></p>
                        <?php endif; ?>
                        
                        <div class="flex items-center justify-between text-sm">
                            <span class="text-gray-500">
                                <i class="fa-solid fa-clock mr-1"></i>
                                <?php echo $shop['turnaround_days']; ?> days
                            </span>
                            <span class="font-bold text-amber-600">
                                From <?php echo Currency::formatHtml($pricing['per_card'] ?? 0.10, $shopCurrency, 'sm'); ?>/card
                            </span>
                        </div>
                    </div>
                    <div class="px-6 py-4 bg-amber-50 border-t border-amber-100">
                        <a href="<?php echo getBasePath(); ?>admin/order_print.php?shop=<?php echo $shop['id']; ?>" 
                           class="block w-full py-2.5 bg-amber-500 hover:bg-amber-600 text-white text-center rounded-lg font-medium transition-colors">
                            Order Now
                        </a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- All Shops -->
        <div>
            <h2 class="text-2xl font-bold text-gray-900 mb-6">
                <?php echo $search ? 'Search Results' : 'All Print Shops'; ?>
                <span class="text-lg font-normal text-gray-500">(<?php echo count($shops); ?>)</span>
            </h2>
            
            <?php if (empty($shops)): ?>
            <div class="bg-white rounded-2xl p-12 text-center">
                <div class="w-20 h-20 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
                    <i class="fa-solid fa-store text-gray-400 text-3xl"></i>
                </div>
                <h3 class="text-xl font-medium text-gray-900 mb-2">No Print Shops Found</h3>
                <p class="text-gray-500 mb-6">Try adjusting your search filters</p>
                <a href="print-shops.php" class="px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition-colors">
                    Clear Filters
                </a>
            </div>
            <?php else: ?>
            <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-6">
                <?php foreach ($shops as $shop): ?>
                <?php 
                $pricing = json_decode($shop['pricing'], true); 
                $shopCurrency = $shop['currency'] ?? 'USD';
                ?>
                <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden hover:shadow-lg hover:border-gray-300 transition-all">
                    <div class="p-6">
                        <div class="flex items-start gap-4 mb-4">
                            <?php if (!empty($shop['logo_url'])): ?>
                            <img src="<?php echo getBasePath() . ltrim(sanitize($shop['logo_url']), '/'); ?>" alt="<?php echo sanitize($shop['name']); ?>" class="w-12 h-12 rounded-lg object-cover">
                            <?php else: ?>
                            <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center text-blue-600 font-bold">
                                <?php echo strtoupper(substr($shop['name'], 0, 2)); ?>
                            </div>
                            <?php endif; ?>
                            <div class="flex-1 min-w-0">
                                <h3 class="font-bold text-gray-900 truncate">
                                    <?php echo sanitize($shop['name']); ?>
                                    <?php if ($shop['verified']): ?>
                                    <i class="fa-solid fa-circle-check text-blue-500 text-xs ml-1"></i>
                                    <?php endif; ?>
                                </h3>
                                <p class="text-sm text-gray-500"><?php echo sanitize($shop['city']); ?>, <?php echo sanitize($shop['country']); ?></p>
                            </div>
                        </div>
                        
                        <div class="space-y-2 text-sm mb-4">
                            <div class="flex items-center justify-between">
                                <span class="text-gray-500">Base Price</span>
                                <span class="font-semibold text-gray-900"><?php echo Currency::formatHtml($pricing['per_card'] ?? 0.10, $shopCurrency, 'sm'); ?>/card</span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="text-gray-500">Turnaround</span>
                                <span class="font-semibold text-gray-900"><?php echo $shop['turnaround_days']; ?> days</span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="text-gray-500">Min Order</span>
                                <span class="font-semibold text-gray-900"><?php echo $shop['min_order_quantity']; ?> cards</span>
                            </div>
                        </div>
                        
                        <div class="flex flex-wrap gap-2 mb-4">
                            <?php if ($shop['express_available']): ?>
                            <span class="inline-flex items-center gap-1 px-2 py-1 bg-green-100 text-green-700 rounded-full text-xs font-medium">
                                <i class="fa-solid fa-bolt"></i> Express
                            </span>
                            <?php endif; ?>
                            <?php 
                            $paperTypes = json_decode($shop['paper_types'], true) ?? [];
                            foreach (array_slice($paperTypes, 0, 3) as $paper): 
                            ?>
                            <span class="px-2 py-1 bg-gray-100 text-gray-600 rounded-full text-xs"><?php echo ucfirst($paper); ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <div class="px-6 py-4 bg-gray-50 border-t border-gray-100 flex items-center gap-3">
                        <a href="<?php echo getBasePath(); ?>admin/order_print.php?shop=<?php echo $shop['id']; ?>" 
                           class="flex-1 py-2.5 bg-blue-600 hover:bg-blue-700 text-white text-center rounded-lg font-medium transition-colors">
                            Select Shop
                        </a>
                        <?php if ($shop['website']): ?>
                        <a href="<?php echo sanitize($shop['website']); ?>" target="_blank" 
                           class="p-2.5 bg-gray-100 hover:bg-gray-200 text-gray-600 rounded-lg transition-colors" title="Visit website">
                            <i class="fa-solid fa-external-link"></i>
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- CTA -->
        <div class="mt-16 bg-gradient-to-r from-blue-600 to-purple-600 rounded-2xl p-8 md:p-12 text-center text-white">
            <h2 class="text-3xl font-bold mb-4">Are You a Print Shop?</h2>
            <p class="text-xl text-blue-100 mb-8 max-w-2xl mx-auto">
                Join our marketplace and receive business card orders from companies using Cardify.
            </p>
            <a href="<?php echo getBasePath(); ?>printshop/register.php" 
               class="inline-flex items-center gap-2 px-8 py-4 bg-white text-blue-600 rounded-xl font-semibold hover:bg-blue-50 transition-colors">
                <i class="fa-solid fa-store"></i>
                Register Your Print Shop
            </a>
        </div>
    </div>
</div>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
