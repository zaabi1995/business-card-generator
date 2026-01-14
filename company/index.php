<?php
/**
 * Company-Specific Page
 * Accessible via: bc.bhd.om/{company_slug}/
 * Example: bc.bhd.om/mhd/
 */
require_once __DIR__ . '/../config.php';

// Get company slug from query string or URI
$companySlug = $_GET['company_slug'] ?? null;

if (!$companySlug) {
    // Try to extract from URI
    $requestUri = $_SERVER['REQUEST_URI'] ?? '';
    $basePath = getBasePath();
    $pathAfterBase = str_replace($basePath, '', $requestUri);
    $pathParts = explode('/', trim($pathAfterBase, '/'));
    $companySlug = $pathParts[0] ?? null;
}

// If no slug, redirect
if (empty($companySlug)) {
    header('Location: ' . getBasePath());
    exit;
}

// Find company
$company = findCompanyBySlug($companySlug);
if (!$company) {
    http_response_code(404);
    die('Company not found');
}

// Get company theme
$companyTheme = null;
$db = Database::getInstance();
if (DatabaseAdapter::useDatabase()) {
    $companyTheme = $db->fetchOne(
        "SELECT * FROM company_themes WHERE company_id = :id",
        ['id' => $company['id']]
    );
    
    // Get company hierarchy
    $companyHierarchy = getCompanyHierarchy($company['id']);
}

// Set company context
setCompanyContext($company);

// Get departments
$departments = [];
if (DatabaseAdapter::useDatabase()) {
    $departments = $db->fetchAll(
        "SELECT * FROM departments WHERE company_id = :id ORDER BY name",
        ['id' => $company['id']]
    );
}

$message = null;
$messageType = 'success';

// Handle employee form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_employee') {
    $employeeData = [
        'email' => sanitizeEmail($_POST['email'] ?? ''),
        'name_en' => $_POST['name_en'] ?? '',
        'name_ar' => $_POST['name_ar'] ?? '',
        'position_en' => $_POST['position_en'] ?? '',
        'position_ar' => $_POST['position_ar'] ?? '',
        'phone' => $_POST['phone'] ?? '',
        'mobile' => $_POST['mobile'] ?? '',
        'company_en' => $_POST['company_en'] ?? $company['name'],
        'company_ar' => $_POST['company_ar'] ?? '',
        'website' => $_POST['website'] ?? '',
        'address' => $_POST['address'] ?? '',
        'department_id' => $_POST['department_id'] ?? null
    ];
    
    if (!empty($employeeData['email'])) {
        $result = addEmployee($employeeData);
        if ($result['success']) {
            $message = 'Your details have been submitted successfully!';
        } else {
            $message = $result['error'] ?? 'Failed to submit details';
            $messageType = 'error';
        }
    }
}

// Apply theme colors
$primaryColor = $companyTheme['primary_color'] ?? '#d4af37';
$secondaryColor = $companyTheme['secondary_color'] ?? '#0f3460';
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo sanitize($company['name']); ?> | <?php echo SITE_NAME; ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Cairo:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo assetUrl('css/tailwind.css'); ?>">
    <style>
        :root {
            --primary-color: <?php echo $primaryColor; ?>;
            --secondary-color: <?php echo $secondaryColor; ?>;
        }
        .glass-card { background: rgba(255, 255, 255, 0.03); backdrop-filter: blur(20px); border: 1px solid rgba(255, 255, 255, 0.1); }
        .btn-primary { background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%); }
        <?php if ($companyTheme && $companyTheme['custom_css']): ?>
        <?php echo $companyTheme['custom_css']; ?>
        <?php endif; ?>
    </style>
</head>
<body class="bg-alzayani-dark text-white font-sans min-h-screen">
    <!-- Header -->
    <header class="glass-card border-b border-white/10">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-4">
                    <?php if ($companyTheme && $companyTheme['logo_path']): ?>
                    <img src="<?php echo imageUrl($companyTheme['logo_path']); ?>" alt="<?php echo sanitize($company['name']); ?>" class="h-12">
                    <?php endif; ?>
                    <div>
                        <h1 class="text-2xl font-bold text-white"><?php echo sanitize($company['name']); ?></h1>
                        <?php if (!empty($companyHierarchy)): ?>
                        <p class="text-sm text-gray-400"><?php echo sanitize($companyHierarchy); ?></p>
                        <?php endif; ?>
                    </div>
                </div>
                <a href="<?php echo getBasePath(); ?>" class="px-4 py-2 rounded-lg bg-white/5 hover:bg-white/10 transition-colors text-sm">
                    ← Back to Home
                </a>
            </div>
        </div>
    </header>
    
    <!-- Main Content -->
    <main class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <?php if ($message): ?>
        <div class="mb-6 p-4 rounded-xl <?php echo $messageType === 'success' ? 'bg-green-500/10 border border-green-500/30 text-green-400' : 'bg-red-500/10 border border-red-500/30 text-red-400'; ?>">
            <?php echo sanitize($message); ?>
        </div>
        <?php endif; ?>
        
        <!-- Employee Form -->
        <div class="glass-card rounded-xl p-8 mb-8">
            <h2 class="text-2xl font-bold mb-6">Employee Information Form</h2>
            <p class="text-gray-400 mb-6">Please fill in your details to generate your business card.</p>
            
            <form method="post" class="space-y-6">
                <input type="hidden" name="action" value="add_employee">
                
                <div class="grid md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-2">Email *</label>
                        <input type="email" name="email" required class="w-full px-4 py-3 rounded-lg bg-white/5 border border-white/10 text-white" placeholder="your@email.com">
                    </div>
                    
                    <?php if (!empty($departments)): ?>
                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-2">Department</label>
                        <select name="department_id" class="w-full px-4 py-3 rounded-lg bg-white/5 border border-white/10 text-white">
                            <option value="">Select Department</option>
                            <?php foreach ($departments as $dept): ?>
                            <option value="<?php echo sanitize($dept['id']); ?>"><?php echo sanitize($dept['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-2">Name (English) *</label>
                        <input type="text" name="name_en" required class="w-full px-4 py-3 rounded-lg bg-white/5 border border-white/10 text-white" placeholder="John Doe">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-2">Name (Arabic)</label>
                        <input type="text" name="name_ar" dir="rtl" class="w-full px-4 py-3 rounded-lg bg-white/5 border border-white/10 text-white" placeholder="جون دو">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-2">Position (English)</label>
                        <input type="text" name="position_en" class="w-full px-4 py-3 rounded-lg bg-white/5 border border-white/10 text-white" placeholder="Manager">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-2">Position (Arabic)</label>
                        <input type="text" name="position_ar" dir="rtl" class="w-full px-4 py-3 rounded-lg bg-white/5 border border-white/10 text-white" placeholder="مدير">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-2">Phone</label>
                        <input type="text" name="phone" class="w-full px-4 py-3 rounded-lg bg-white/5 border border-white/10 text-white" placeholder="+968 XXXX XXXX">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-2">Mobile</label>
                        <input type="text" name="mobile" class="w-full px-4 py-3 rounded-lg bg-white/5 border border-white/10 text-white" placeholder="+968 XXXX XXXX">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-2">Company (English)</label>
                        <input type="text" name="company_en" value="<?php echo sanitize($company['name']); ?>" class="w-full px-4 py-3 rounded-lg bg-white/5 border border-white/10 text-white">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-2">Company (Arabic)</label>
                        <input type="text" name="company_ar" dir="rtl" class="w-full px-4 py-3 rounded-lg bg-white/5 border border-white/10 text-white">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-2">Website</label>
                        <input type="text" name="website" class="w-full px-4 py-3 rounded-lg bg-white/5 border border-white/10 text-white" placeholder="www.example.com">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-2">Address</label>
                        <input type="text" name="address" class="w-full px-4 py-3 rounded-lg bg-white/5 border border-white/10 text-white" placeholder="Your address">
                    </div>
                </div>
                
                <button type="submit" class="w-full py-4 rounded-xl btn-primary text-white font-bold text-lg hover:opacity-90 transition-opacity">
                    Submit Information
                </button>
            </form>
        </div>
        
        <!-- Company Info -->
        <div class="glass-card rounded-xl p-6">
            <h3 class="text-lg font-semibold mb-4">About <?php echo sanitize($company['name']); ?></h3>
            <p class="text-gray-400">
                <?php echo $companyTheme['footer_text'] ?? 'Welcome to ' . sanitize($company['name']); ?>
            </p>
        </div>
    </main>
</body>
</html>

<?php
/**
 * Get company hierarchy path
 * Returns: "Healthcare > MHD ITICS > MHD"
 */
function getCompanyHierarchy($companyId) {
    if (!DatabaseAdapter::useDatabase()) {
        return '';
    }
    
    $db = Database::getInstance();
    $company = $db->fetchOne("SELECT * FROM companies WHERE id = :id", ['id' => $companyId]);
    
    if (!$company) {
        return '';
    }
    
    // If company_path is set, use it
    if (!empty($company['company_path'])) {
        return $company['company_path'];
    }
    
    // Otherwise, build hierarchy
    $path = [];
    $currentId = $companyId;
    $visited = [];
    
    while ($currentId && !isset($visited[$currentId])) {
        $visited[$currentId] = true;
        $current = $db->fetchOne("SELECT * FROM companies WHERE id = :id", ['id' => $currentId]);
        
        if (!$current) break;
        
        array_unshift($path, $current['name']);
        $currentId = $current['parent_company_id'] ?? null;
    }
    
    return implode(' > ', $path);
}
?>
