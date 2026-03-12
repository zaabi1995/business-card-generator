<?php
/**
 * Order Print - Simplified 3-step print order flow
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/PrintShopIntegration.php';
require_once INCLUDES_DIR . '/PrintShop.php';
require_once INCLUDES_DIR . '/Currency.php';
require_once INCLUDES_DIR . '/admin-layout.php';

requireAdmin();

$db = Database::getInstance();
$message = null;
$messageType = 'success';
$orderSuccess = false;

// Get current company
$companyId = getCurrentCompanyId();
$currentCompany = $companyId ? findCompanyById($companyId) : null;

// Get available print shops
try {
    $printShops = PrintShop::getAll('active', 50);
} catch (Exception $e) {
    $printShops = [];
}

// Get selected print shop (default to first if only one)
$selectedShopId = (int)($_GET['shop'] ?? $_POST['print_shop_id'] ?? 0);
if (!$selectedShopId && count($printShops) === 1) {
    $selectedShopId = $printShops[0]['id'];
}
$selectedShop = $selectedShopId ? PrintShop::getById($selectedShopId) : null;

// Get employee if specified
$employeeId = $_GET['employee'] ?? $_POST['employee_id'] ?? '';
$employee = null;
$cardFrontUrl = urldecode($_GET['card_front'] ?? '');
$cardBackUrl = urldecode($_GET['card_back'] ?? '');

// Fetch employee data
if (!empty($employeeId)) {
    try {
        $pdo = $db->getConnection();
        $stmt = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
        $stmt->execute([$employeeId]);
        $employee = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Ignore
    }
}

// If employee selected but no card URLs, fetch from generated_cards
if (!empty($employeeId) && (empty($cardFrontUrl) || empty($cardBackUrl))) {
    try {
        $pdo = $db->getConnection();
        $stmt = $pdo->prepare("
            SELECT front_file_path, back_file_path 
            FROM generated_cards 
            WHERE employee_id = ? AND company_id = ?
            ORDER BY generated_at DESC 
            LIMIT 1
        ");
        $stmt->execute([$employeeId, $companyId]);
        $generatedCard = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($generatedCard) {
            $cardsBasePath = getBasePath() . 'uploads/companies/' . $companyId . '/cards/';
            if (!empty($generatedCard['front_file_path']) && empty($cardFrontUrl)) {
                $cardFrontUrl = $cardsBasePath . $generatedCard['front_file_path'];
            }
            if (!empty($generatedCard['back_file_path']) && empty($cardBackUrl)) {
                $cardBackUrl = $cardsBasePath . $generatedCard['back_file_path'];
            }
        }
    } catch (PDOException $e) {
        // Ignore
    }
}

// Get all employees with generated cards for dropdown
$employees = [];
try {
    $pdo = $db->getConnection();
    if ($companyId) {
        $stmt = $pdo->prepare("
            SELECT DISTINCT e.id, e.name_en, e.name_ar, e.email, e.position_en, e.position_ar,
                   gc.front_file_path, gc.back_file_path
            FROM employees e
            LEFT JOIN (
                SELECT employee_id, front_file_path, back_file_path,
                       ROW_NUMBER() OVER (PARTITION BY employee_id ORDER BY generated_at DESC) as rn
                FROM generated_cards WHERE company_id = ?
            ) gc ON e.id = gc.employee_id AND gc.rn = 1
            WHERE e.company_id = ?
            ORDER BY e.name_en
        ");
        $stmt->execute([$companyId, $companyId]);
    } else {
        $stmt = $pdo->query("SELECT id, name_en, name_ar, email, position_en, position_ar FROM employees ORDER BY name_en LIMIT 500");
    }
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Fallback to simple query
    try {
        $stmt = $pdo->prepare("SELECT id, name_en, name_ar, email, position_en FROM employees WHERE company_id = ? ORDER BY name_en");
        $stmt->execute([$companyId]);
        $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Ignore
    }
}

// Check if print shop is enabled
$printEnabled = PrintShopIntegration::isEnabled();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($printShops)) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = 'Invalid request. Please try again.';
        $messageType = 'error';
    }
    if ($messageType !== 'error') {
        $printShopId = (int)($_POST['print_shop_id'] ?? 0);

        $orderData = [
            'company_id' => $companyId,
            'employee_id' => $_POST['employee_id'] ?: null,
            'user_id' => Auth::getCurrentUser()['id'] ?? null,
            'print_shop_id' => $printShopId ?: null,
            'quantity' => (int)($_POST['quantity'] ?? 100),
            'paper_type' => $_POST['paper_type'] ?? 'matte',
            'finish' => $_POST['finish'] ?? 'standard',
            'card_front_url' => $_POST['card_front_url'] ?? null,
            'card_back_url' => $_POST['card_back_url'] ?? null,
            'shipping_name' => trim($_POST['shipping_name'] ?? ''),
            'shipping_address' => trim($_POST['shipping_address'] ?? ''),
            'shipping_city' => trim($_POST['shipping_city'] ?? ''),
            'shipping_country' => trim($_POST['shipping_country'] ?? ''),
            'shipping_phone' => trim($_POST['shipping_phone'] ?? ''),
            'notes' => trim($_POST['notes'] ?? ''),
            'payment_status' => 'pending' // Pay later
        ];

        $result = PrintShopIntegration::createOrder($orderData);

        if ($result['success']) {
            $shop = PrintShop::getById($printShopId);
            $currency = $shop['currency'] ?? 'OMR';
            $message = "Order #{$result['order_id']} placed successfully! Total: " . Currency::format($result['total'], $currency);
            $messageType = 'success';
            $orderSuccess = true;
        } else {
            $message = "Error: " . ($result['error'] ?? 'Unknown error');
            $messageType = 'error';
        }
    }
}

// Get company's preferred currency (or default to OMR for Oman)
$companyCurrency = $currentCompany['currency'] ?? 'OMR';
$companyCountry = $currentCompany['country'] ?? 'OM';

// Get shop currency and pricing - use shop's currency (which should match region)
$shopCurrency = $selectedShop['currency'] ?? $companyCurrency;
$shopPricing = $selectedShop ? json_decode($selectedShop['pricing'] ?? '{}', true) : [];
$basePrice = $shopPricing['per_card'] ?? 0.10;
$setupFee = $shopPricing['setup_fee'] ?? 0;
$shippingFee = $shopPricing['shipping_base'] ?? 2;
$quantityTiers = $shopPricing['quantity_tiers'] ?? [];

// Default tiers if none set
if (empty($quantityTiers)) {
    $quantityTiers = [
        '50' => $basePrice,
        '100' => round($basePrice * 0.95, 3),
        '200' => round($basePrice * 0.90, 3),
        '250' => round($basePrice * 0.88, 3),
        '500' => round($basePrice * 0.85, 3),
        '1000' => round($basePrice * 0.80, 3),
        '2000' => round($basePrice * 0.75, 3)
    ];
}

// Build employees JSON for Alpine.js
$employeesJson = [];
foreach ($employees as $emp) {
    $empCardFront = '';
    $empCardBack = '';
    if (!empty($emp['front_file_path'])) {
        $empCardFront = getBasePath() . 'uploads/companies/' . $companyId . '/cards/' . $emp['front_file_path'];
    }
    if (!empty($emp['back_file_path'])) {
        $empCardBack = getBasePath() . 'uploads/companies/' . $companyId . '/cards/' . $emp['back_file_path'];
    }
    $employeesJson[] = [
        'id' => $emp['id'],
        'name' => $emp['name_en'] ?? $emp['name_ar'] ?? 'Unknown',
        'position' => $emp['position_en'] ?? '',
        'email' => $emp['email'] ?? '',
        'cardFront' => $empCardFront,
        'cardBack' => $empCardBack
    ];
}

adminHeader('Order Print', 'order_print');
$ext = (defined('COMPANY_ADMIN_BASE') || !empty($_SESSION['company_slug'])) ? '' : '.php';
$basePath = getAdminBasePath();
?>

<?php if ($orderSuccess): ?>
<!-- Success State -->
<div class="max-w-2xl mx-auto">
    <div class="bg-white rounded-2xl border border-gray-200 p-8 text-center shadow-sm">
        <div class="w-20 h-20 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
            <i class="fa-solid fa-check text-green-600 text-3xl"></i>
        </div>
        <h3 class="text-xl font-semibold text-gray-900 mb-2">Order Placed!</h3>
        <p class="text-gray-600 mb-6"><?php echo sanitize($message); ?></p>
        <p class="text-sm text-gray-500 mb-6">Payment can be completed when you pick up or receive your order.</p>
        
        <div class="flex items-center justify-center gap-4">
            <a href="<?php echo $basePath; ?>print<?php echo $ext; ?>" class="px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition-colors">
                <i class="fa-solid fa-box mr-2"></i>View Orders
            </a>
            <a href="<?php echo $basePath; ?>employees<?php echo $ext; ?>" class="px-5 py-2.5 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg font-medium transition-colors border border-gray-300">
                <i class="fa-solid fa-arrow-left mr-2"></i>Back to Employees
            </a>
        </div>
    </div>
</div>
<?php elseif (empty($printShops)): ?>
<!-- No Print Shops -->
<div class="max-w-2xl mx-auto">
    <div class="bg-amber-50 border border-amber-200 rounded-2xl p-8 text-center">
        <div class="w-16 h-16 bg-amber-100 rounded-full flex items-center justify-center mx-auto mb-4">
            <i class="fa-solid fa-store text-amber-600 text-2xl"></i>
        </div>
        <h3 class="text-lg font-semibold text-amber-900 mb-2">No Print Shops Available</h3>
        <p class="text-amber-700 mb-4">There are no print shops available at the moment.</p>
        <a href="<?php echo $basePath; ?>employees<?php echo $ext; ?>" class="inline-flex items-center px-4 py-2 bg-amber-600 hover:bg-amber-700 text-white rounded-lg font-medium transition-colors">
            <i class="fa-solid fa-arrow-left mr-2"></i>Back to Employees
        </a>
    </div>
</div>
<?php else: ?>
<!-- Order Form -->
<div class="max-w-4xl mx-auto" x-data="orderForm()">
    
    <!-- Header with back button -->
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-4">
            <a href="<?php echo $basePath; ?>employees<?php echo $ext; ?>" class="p-2 text-gray-500 hover:text-gray-700 hover:bg-gray-100 rounded-lg transition-colors" title="Back to Employees">
                <i class="fa-solid fa-arrow-left text-lg"></i>
            </a>
            <div>
                <h2 class="text-xl font-bold text-gray-900">Order Business Cards</h2>
                <?php if ($employee): ?>
                <p class="text-sm text-gray-500">For <?php echo sanitize($employee['name_en'] ?? $employee['email']); ?></p>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($employee): ?>
        <a href="<?php echo $basePath; ?>employees<?php echo $ext; ?>" class="text-sm text-blue-600 hover:text-blue-700 font-medium flex items-center gap-1">
            <i class="fa-solid fa-users"></i>
            View All Employees
        </a>
        <?php endif; ?>
    </div>
    
    <?php if ($message && !$orderSuccess): ?>
    <div class="mb-6 p-4 rounded-xl flex items-center gap-3 bg-red-50 border border-red-200 text-red-700">
        <i class="fa-solid fa-circle-exclamation"></i>
        <?php echo sanitize($message); ?>
    </div>
    <?php endif; ?>
    
    <form method="post" class="space-y-6">
        <?php echo csrfField(); ?>

        <!-- Step 1: Select Print Shop -->
        <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-100 bg-gradient-to-r from-purple-50 to-white">
                <div class="flex items-center gap-3">
                    <div class="w-8 h-8 bg-purple-600 text-white rounded-full flex items-center justify-center text-sm font-bold">1</div>
                    <h3 class="font-semibold text-gray-900">Choose Print Shop</h3>
                </div>
            </div>
            <div class="p-6">
                <div class="grid sm:grid-cols-2 lg:grid-cols-<?php echo count($printShops) > 2 ? '3' : '2'; ?> gap-4">
                    <?php foreach ($printShops as $idx => $shop): 
                        $pricing = json_decode($shop['pricing'] ?? '{}', true);
                        $perCard = $pricing['per_card'] ?? 0.10;
                        $currency = $shop['currency'] ?? 'OMR';
                        $logoUrl = $shop['logo_url'] ?? '';
                    ?>
                    <div class="relative cursor-pointer" @click="selectedShopId = <?php echo $shop['id']; ?>; selectShop(<?php echo htmlspecialchars(json_encode($shop)); ?>)">
                        <input type="radio" name="print_shop_id" value="<?php echo $shop['id']; ?>" 
                               x-model.number="selectedShopId"
                               class="sr-only">
                        <div class="p-4 border-2 rounded-xl transition-all"
                             :class="selectedShopId == <?php echo $shop['id']; ?> ? 'border-purple-500 bg-purple-50 shadow-md' : 'border-gray-200 bg-white hover:border-purple-300'">
                            <div class="flex items-start gap-3">
                                <?php if ($logoUrl): ?>
                                <img src="<?php echo getBasePath() . ltrim(sanitize($logoUrl), '/'); ?>" alt="<?php echo sanitize($shop['name']); ?>" class="w-12 h-12 rounded-lg object-cover">
                                <?php else: ?>
                                <div class="w-12 h-12 bg-gradient-to-br from-purple-500 to-indigo-600 rounded-lg flex items-center justify-center text-white font-bold text-lg flex-shrink-0">
                                    <?php echo strtoupper(substr($shop['name'], 0, 2)); ?>
                                </div>
                                <?php endif; ?>
                                <div class="flex-1 min-w-0">
                                    <p class="font-semibold text-gray-900 truncate flex items-center gap-1">
                                        <?php echo sanitize($shop['name']); ?>
                                        <?php if ($shop['verified']): ?>
                                        <i class="fa-solid fa-badge-check text-blue-500 text-sm"></i>
                                        <?php endif; ?>
                                    </p>
                                    <p class="text-sm text-gray-500"><?php echo sanitize($shop['city']); ?>, <?php echo sanitize($shop['country']); ?></p>
                                </div>
                            </div>
                            <div class="mt-3 pt-3 border-t border-gray-100">
                                <p class="text-lg font-bold text-purple-600"><?php echo Currency::formatHtml($perCard, $currency, 'sm'); ?><span class="text-sm font-normal text-gray-500">/card</span></p>
                                <?php if ($shop['turnaround_days']): ?>
                                <p class="text-xs text-gray-500 mt-1">
                                    <i class="fa-solid fa-clock mr-1"></i><?php echo $shop['turnaround_days']; ?> days
                                    <?php if ($shop['express_available']): ?><span class="text-green-600 ml-1">• Express</span><?php endif; ?>
                                </p>
                                <?php endif; ?>
                            </div>
                            <!-- Selected indicator -->
                            <div class="absolute top-3 right-3 w-6 h-6 rounded-full flex items-center justify-center transition-all"
                                 :class="selectedShopId == <?php echo $shop['id']; ?> ? 'bg-purple-500' : 'border-2 border-gray-300 bg-white'">
                                <i class="fa-solid fa-check text-white text-xs" x-show="selectedShopId == <?php echo $shop['id']; ?>"></i>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        
        <!-- Step 2: Order Details -->
        <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-100 bg-gradient-to-r from-blue-50 to-white">
                <div class="flex items-center gap-3">
                    <div class="w-8 h-8 bg-blue-600 text-white rounded-full flex items-center justify-center text-sm font-bold">2</div>
                    <h3 class="font-semibold text-gray-900">Order Details</h3>
                </div>
            </div>
            <div class="p-6">
                <div class="grid lg:grid-cols-2 gap-6">
                    <!-- Left: Form -->
                    <div class="space-y-4">
                        <!-- Employee Selection -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Employee</label>
                            <select name="employee_id" x-model="selectedEmployeeId" @change="onEmployeeChange()"
                                    class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl text-gray-900 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 transition-all">
                                <option value="">Generic Company Cards</option>
                                <?php foreach ($employees as $emp): ?>
                                <option value="<?php echo $emp['id']; ?>" <?php echo ($employeeId == $emp['id']) ? 'selected' : ''; ?>>
                                    <?php echo sanitize($emp['name_en'] ?? $emp['name_ar'] ?? 'Unknown'); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <!-- Quantity -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Quantity</label>
                            <div class="grid grid-cols-3 sm:grid-cols-4 gap-2">
                                <?php foreach ([50, 100, 200, 250, 500, 1000, 2000] as $qty): ?>
                                <button type="button" 
                                        @click="quantity = <?php echo $qty; ?>; updatePrice()"
                                        :class="quantity == <?php echo $qty; ?> ? 'border-blue-500 bg-blue-100 text-blue-700 ring-2 ring-blue-500/30 font-bold' : 'border-gray-300 bg-white hover:border-blue-300 hover:bg-blue-50 text-gray-700'"
                                        class="px-3 py-2.5 text-center border-2 rounded-lg transition-all text-sm font-medium shadow-sm">
                                    <?php echo number_format($qty); ?>
                                </button>
                                <?php endforeach; ?>
                            </div>
                            <input type="hidden" name="quantity" :value="quantity">
                        </div>
                        
                        <!-- Paper & Finish (simplified) -->
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Paper</label>
                                <select name="paper_type" x-model="paperType" @change="updatePrice()"
                                        class="w-full px-3 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-sm focus:bg-white focus:border-blue-500 transition-all">
                                    <option value="matte">Matte</option>
                                    <option value="glossy">Glossy</option>
                                    <option value="silk">Silk</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Finish</label>
                                <select name="finish" x-model="finish" @change="updatePrice()"
                                        class="w-full px-3 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-sm focus:bg-white focus:border-blue-500 transition-all">
                                    <option value="standard">Standard</option>
                                    <option value="rounded_corners">Rounded Corners</option>
                                </select>
                            </div>
                        </div>
                        
                        <!-- Notes -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Notes <span class="text-gray-400">(optional)</span></label>
                            <textarea name="notes" rows="2" placeholder="Any special requests..."
                                      class="w-full px-3 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-sm focus:bg-white focus:border-blue-500 transition-all resize-none"></textarea>
                        </div>
                    </div>
                    
                    <!-- Right: Card Preview -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Card Preview</label>
                        <div class="bg-gray-100 rounded-xl p-4 space-y-3">
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <p class="text-xs text-gray-500 mb-1 text-center">Front</p>
                                    <template x-if="cardFrontUrl">
                                        <img :src="cardFrontUrl" alt="Front" class="w-full rounded-lg shadow-sm border border-gray-200">
                                    </template>
                                    <template x-if="!cardFrontUrl">
                                        <div class="aspect-[1.75] bg-gray-200 rounded-lg flex items-center justify-center text-gray-400">
                                            <i class="fa-solid fa-image"></i>
                                        </div>
                                    </template>
                                </div>
                                <div>
                                    <p class="text-xs text-gray-500 mb-1 text-center">Back</p>
                                    <template x-if="cardBackUrl">
                                        <img :src="cardBackUrl" alt="Back" class="w-full rounded-lg shadow-sm border border-gray-200">
                                    </template>
                                    <template x-if="!cardBackUrl">
                                        <div class="aspect-[1.75] bg-gray-200 rounded-lg flex items-center justify-center text-gray-400">
                                            <i class="fa-solid fa-image"></i>
                                        </div>
                                    </template>
                                </div>
                            </div>
                            <p x-show="selectedEmployeeName" class="text-sm text-gray-600 text-center">
                                <span x-text="selectedEmployeeName"></span>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Hidden fields for card URLs -->
        <input type="hidden" name="card_front_url" :value="cardFrontUrl">
        <input type="hidden" name="card_back_url" :value="cardBackUrl">
        
        <!-- Step 3: Review & Order -->
        <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-100 bg-gradient-to-r from-green-50 to-white">
                <div class="flex items-center gap-3">
                    <div class="w-8 h-8 bg-green-600 text-white rounded-full flex items-center justify-center text-sm font-bold">3</div>
                    <h3 class="font-semibold text-gray-900">Review & Order</h3>
                </div>
            </div>
            <div class="p-6">
                <!-- Price Summary -->
                <div class="bg-gradient-to-br from-gray-50 to-gray-100 rounded-xl p-5 mb-6">
                    <div class="space-y-2 text-sm">
                        <div class="flex justify-between">
                            <span class="text-gray-600"><span x-text="quantity"></span> cards × <span x-html="formatCurrency(perCardPrice, true)"></span></span>
                            <span class="font-medium" x-html="formatCurrency(subtotal, true)"></span>
                        </div>
                        <template x-if="setupFee > 0">
                            <div class="flex justify-between">
                                <span class="text-gray-600">Setup fee</span>
                                <span class="font-medium" x-html="formatCurrency(setupFee, true)"></span>
                            </div>
                        </template>
                        <div class="flex justify-between">
                            <span class="text-gray-600">Shipping</span>
                            <span class="font-medium" x-html="formatCurrency(shippingFee, true)"></span>
                        </div>
                        <div class="pt-2 mt-2 border-t border-gray-200 flex justify-between items-center">
                            <span class="font-semibold text-gray-900">Total</span>
                            <span class="text-2xl font-bold text-green-600" x-html="formatCurrency(total, true)"></span>
                        </div>
                    </div>
                    <p class="text-xs text-gray-500 mt-3 flex items-center gap-1">
                        <i class="fa-solid fa-info-circle"></i>
                        Payment on delivery/pickup
                    </p>
                </div>
                
                <!-- Delivery Address (Collapsible) -->
                <div x-data="{ showAddress: false }" class="mb-6">
                    <button type="button" @click="showAddress = !showAddress" 
                            class="flex items-center gap-2 text-sm text-blue-600 hover:text-blue-700 font-medium">
                        <i class="fa-solid" :class="showAddress ? 'fa-minus' : 'fa-plus'"></i>
                        <span x-text="showAddress ? 'Hide delivery address' : 'Add delivery address (optional)'"></span>
                    </button>
                    
                    <div x-show="showAddress" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 translate-y-0" x-transition:leave-end="opacity-0 -translate-y-2" class="mt-4 space-y-4 p-4 bg-gray-50 rounded-xl">
                        <div class="grid sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Recipient Name</label>
                                <input type="text" name="shipping_name" placeholder="John Doe"
                                       class="w-full px-3 py-2 bg-white border border-gray-200 rounded-lg text-sm focus:border-blue-500 transition-all">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Phone</label>
                                <input type="tel" name="shipping_phone" placeholder="+968 9XXX XXXX"
                                       class="w-full px-3 py-2 bg-white border border-gray-200 rounded-lg text-sm focus:border-blue-500 transition-all">
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Address</label>
                            <textarea name="shipping_address" rows="2" placeholder="Street address, building..."
                                      class="w-full px-3 py-2 bg-white border border-gray-200 rounded-lg text-sm focus:border-blue-500 transition-all resize-none"></textarea>
                        </div>
                        <div class="grid sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">City</label>
                                <input type="text" name="shipping_city" placeholder="Muscat"
                                       class="w-full px-3 py-2 bg-white border border-gray-200 rounded-lg text-sm focus:border-blue-500 transition-all">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Country</label>
                                <input type="text" name="shipping_country" placeholder="Oman" value="Oman"
                                       class="w-full px-3 py-2 bg-white border border-gray-200 rounded-lg text-sm focus:border-blue-500 transition-all">
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Submit -->
                <div class="flex items-center justify-between">
                    <a href="<?php echo $basePath; ?>employees<?php echo $ext; ?>" class="px-4 py-2.5 bg-gray-100 hover:bg-gray-200 text-gray-700 border border-gray-300 rounded-lg font-medium transition-colors flex items-center gap-2">
                        <i class="fa-solid fa-arrow-left"></i>
                        <span>Back to Employees</span>
                    </a>
                    <button type="submit" class="px-8 py-3 bg-green-600 hover:bg-green-700 text-white rounded-xl font-semibold shadow-md transition-all hover:shadow-lg flex items-center gap-2">
                        <i class="fa-solid fa-paper-plane"></i>
                        Place Order
                    </button>
                </div>
            </div>
        </div>
        
    </form>
</div>

<script>
function orderForm() {
    const employees = <?php echo json_encode($employeesJson); ?>;
    const defaultShop = <?php echo $selectedShop ? json_encode($selectedShop) : 'null'; ?>;
    const defaultEmployeeId = '<?php echo $employeeId; ?>';
    const defaultCardFront = '<?php echo addslashes($cardFrontUrl); ?>';
    const defaultCardBack = '<?php echo addslashes($cardBackUrl); ?>';
    const defaultTiers = <?php echo json_encode($quantityTiers); ?>;
    
    return {
        // Shop
        selectedShopId: defaultShop?.id || <?php echo $selectedShopId ?: 0; ?>,
        selectedShop: defaultShop,
        currency: defaultShop?.currency || 'OMR',
        basePrice: <?php echo $basePrice; ?>,
        setupFee: <?php echo $setupFee; ?>,
        shippingFee: <?php echo $shippingFee; ?>,
        quantityTiers: defaultTiers,
        
        // Employee
        selectedEmployeeId: defaultEmployeeId,
        selectedEmployeeName: '',
        cardFrontUrl: defaultCardFront,
        cardBackUrl: defaultCardBack,
        
        // Order
        quantity: 100,
        paperType: 'matte',
        finish: 'standard',
        
        // Calculated
        perCardPrice: 0,
        subtotal: 0,
        total: 0,
        
        init() {
            this.onEmployeeChange();
            this.updatePrice();
        },
        
        selectShop(shop) {
            this.selectedShop = shop;
            this.selectedShopId = shop?.id || 0;
            if (shop && shop.pricing) {
                const pricing = typeof shop.pricing === 'string' ? JSON.parse(shop.pricing) : shop.pricing;
                this.basePrice = parseFloat(pricing.per_card) || 0.10;
                this.setupFee = parseFloat(pricing.setup_fee) || 0;
                this.shippingFee = parseFloat(pricing.shipping_base) || 2;
                this.currency = shop.currency || 'OMR';
                this.quantityTiers = pricing.quantity_tiers || {};
            }
            this.updatePrice();
        },
        
        onEmployeeChange() {
            const emp = employees.find(e => e.id === this.selectedEmployeeId);
            if (emp) {
                this.selectedEmployeeName = emp.name;
                this.cardFrontUrl = emp.cardFront || '';
                this.cardBackUrl = emp.cardBack || '';
            } else {
                this.selectedEmployeeName = '';
                // Keep current card URLs for generic cards
            }
        },
        
        getTierPrice(quantity) {
            // Find the best applicable tier for the given quantity
            let price = this.basePrice;
            let bestTierQty = 0;
            
            for (const [tierQty, tierPrice] of Object.entries(this.quantityTiers)) {
                const qty = parseInt(tierQty);
                if (quantity >= qty && qty > bestTierQty) {
                    bestTierQty = qty;
                    price = parseFloat(tierPrice);
                }
            }
            
            return price;
        },
        
        updatePrice() {
            // Get tier-based price
            let price = this.getTierPrice(this.quantity);
            
            // Paper multiplier
            if (this.paperType === 'silk') price *= 1.15;
            
            // Finish multiplier
            if (this.finish === 'rounded_corners') price *= 1.10;
            
            this.perCardPrice = price;
            this.subtotal = this.quantity * price;
            this.total = this.subtotal + this.setupFee + this.shippingFee;
        },
        
        getOmrSymbol() {
            return '<img src="<?php echo getBasePath(); ?>assets/omr-symbol/Medium.svg" alt="OMR" class="inline-block align-middle h-4 w-4">';
        },
        
        formatCurrency(amount, asHtml = false) {
            // Handle decimals based on currency
            const decimals = ['OMR', 'BHD', 'KWD'].includes(this.currency) ? 3 : 2;
            const formatted = amount.toFixed(decimals);
            
            // Use OMR symbol (text or HTML)
            if (this.currency === 'OMR') {
                return asHtml ? formatted + ' ' + this.getOmrSymbol() : formatted + ' ر.ع.';
            }
            
            // Position symbol after for GCC currencies
            if (['AED', 'SAR', 'QAR', 'BHD', 'KWD'].includes(this.currency)) {
                return formatted + ' ' + this.currency;
            }
            return this.currency + ' ' + formatted;
        }
    }
}
</script>
<?php endif; ?>

<?php adminFooter(); ?>
