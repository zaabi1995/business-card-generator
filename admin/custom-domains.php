<?php
/**
 * Custom Domains - Cardify (Pro feature)
 *
 * Manage employee_custom_domains: add, verify (DNS check), mark SSL
 * status, delete. Free plan sees an upgrade gate.
 */
require_once __DIR__ . '/../config.php';
requireAdmin();
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/admin-layout.php';
require_once INCLUDES_DIR . '/Billing.php';
require_once INCLUDES_DIR . '/CustomDomain.php';

$db = Database::getInstance();
$companyId = getCurrentCompanyId();
if (!$companyId) {
    header('Location: ' . getBasePath() . 'login.php');
    exit;
}

$company = findCompanyById($companyId);
$planInfo = Billing::getCompanyPlanInfo($companyId);
$plan = $planInfo['plan'] ?? 'free';
$isPro = ($plan !== 'free');

$message = null;
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isPro) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = 'Invalid CSRF token';
        $messageType = 'error';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'add') {
            $employeeId = $_POST['employee_id'] ?? '';
            $domain = $_POST['domain'] ?? '';
            $res = CustomDomain::add($employeeId, $companyId, $domain);
            if ($res['success']) {
                $message = 'Domain added. Configure DNS, then click Verify.';
            } else {
                $message = $res['error'] ?? 'Failed to add domain';
                $messageType = 'error';
            }
        } elseif ($action === 'verify') {
            $id = $_POST['id'] ?? '';
            $res = CustomDomain::verify($id, $companyId);
            $message = $res['verified']
                ? 'Verified: ' . $res['message']
                : 'Not yet verified: ' . $res['message'];
            $messageType = $res['verified'] ? 'success' : 'error';
        } elseif ($action === 'delete') {
            $id = $_POST['id'] ?? '';
            CustomDomain::delete($id, $companyId);
            $message = 'Domain removed';
        } elseif ($action === 'set_ssl') {
            $id = $_POST['id'] ?? '';
            $status = $_POST['ssl_status'] ?? 'pending';
            CustomDomain::setSslStatus($id, $companyId, $status);
            $message = 'SSL status updated';
        }
    }
}

$domains = $isPro ? CustomDomain::listForCompany($companyId) : [];
$employees = loadEmployees($companyId);

adminHeader('Custom Domains', 'custom-domains');
?>

<div class="p-6 max-w-6xl mx-auto">
    <div class="mb-6 flex items-center justify-between flex-wrap gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Custom Domains</h1>
            <p class="text-gray-600 mt-1">Point your own domain at any employee's digital card.</p>
        </div>
        <span class="px-3 py-1 text-xs font-semibold rounded-full <?php echo $isPro ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'; ?>">
            <?php echo $isPro ? 'Pro feature enabled' : 'Pro feature - upgrade required'; ?>
        </span>
    </div>

    <?php if ($message): ?>
        <div class="mb-4 px-4 py-3 rounded-lg <?php echo $messageType === 'success' ? 'bg-emerald-50 text-emerald-800 border border-emerald-200' : 'bg-rose-50 text-rose-800 border border-rose-200'; ?>">
            <?php echo sanitize($message); ?>
        </div>
    <?php endif; ?>

    <?php if (!$isPro): ?>
        <div class="bg-white border border-amber-200 rounded-2xl p-8 text-center">
            <i class="fa-solid fa-globe text-4xl text-amber-500 mb-4"></i>
            <h2 class="text-xl font-bold text-gray-900 mb-2">Custom Domains is a Pro feature</h2>
            <p class="text-gray-600 mb-6 max-w-md mx-auto">
                Upgrade to Pro to let your team serve their digital cards from
                their own domain (e.g. <code>ceo.acme.com</code>).
            </p>
            <a href="<?php echo getBasePath(); ?>admin/billing.php"
               class="inline-flex items-center gap-2 px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-xl font-semibold">
                <i class="fa-solid fa-arrow-up-right-from-square"></i>Upgrade plan
            </a>
        </div>
    <?php else: ?>

    <!-- Add form -->
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 mb-6">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Add a custom domain</h2>
        <form method="post" class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="add">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Employee</label>
                <select name="employee_id" required class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                    <option value="">Select employee...</option>
                    <?php foreach ($employees as $emp): ?>
                        <option value="<?php echo sanitize($emp['id']); ?>">
                            <?php echo sanitize(($emp['name_en'] ?? '') . ' (' . ($emp['email'] ?? '') . ')'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Domain</label>
                <input type="text" name="domain" placeholder="ceo.acme.com" required
                       pattern="[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}"
                       class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
            </div>
            <div class="flex items-end">
                <button type="submit" class="w-full px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-semibold">
                    Add domain
                </button>
            </div>
        </form>
        <p class="text-xs text-gray-500 mt-3">
            After adding, set up DNS as shown below, then click <strong>Verify</strong>.
            SSL certificate provisioning is currently manual - contact support after verification.
        </p>
    </div>

    <!-- Domains list -->
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100">
            <h2 class="text-lg font-semibold text-gray-900">Your domains</h2>
        </div>
        <?php if (empty($domains)): ?>
            <div class="p-12 text-center text-gray-500">
                <i class="fa-solid fa-globe text-4xl text-gray-300 mb-3"></i>
                <p>No custom domains yet. Add one above.</p>
            </div>
        <?php else: ?>
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-left">
                    <tr>
                        <th class="px-6 py-3 font-semibold text-gray-600">Domain</th>
                        <th class="px-6 py-3 font-semibold text-gray-600">Employee</th>
                        <th class="px-6 py-3 font-semibold text-gray-600">DNS</th>
                        <th class="px-6 py-3 font-semibold text-gray-600">SSL</th>
                        <th class="px-6 py-3 font-semibold text-gray-600 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                <?php foreach ($domains as $d): ?>
                    <tr>
                        <td class="px-6 py-4 font-mono text-gray-900"><?php echo sanitize($d['domain']); ?></td>
                        <td class="px-6 py-4 text-gray-700">
                            <?php echo sanitize($d['employee_name'] ?? ''); ?>
                            <div class="text-xs text-gray-500"><?php echo sanitize($d['employee_email'] ?? ''); ?></div>
                        </td>
                        <td class="px-6 py-4">
                            <?php if ((int)$d['verified'] === 1): ?>
                                <span class="px-2 py-1 text-xs rounded-full bg-emerald-100 text-emerald-700">Verified</span>
                            <?php else: ?>
                                <span class="px-2 py-1 text-xs rounded-full bg-amber-100 text-amber-700">Pending</span>
                            <?php endif; ?>
                            <?php if (!empty($d['last_check_message'])): ?>
                                <div class="text-xs text-gray-500 mt-1"><?php echo sanitize($d['last_check_message']); ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4">
                            <?php
                                $sslColor = ['active' => 'emerald', 'pending' => 'amber', 'failed' => 'rose'][$d['ssl_status']] ?? 'gray';
                            ?>
                            <span class="px-2 py-1 text-xs rounded-full bg-<?php echo $sslColor; ?>-100 text-<?php echo $sslColor; ?>-700">
                                <?php echo sanitize(ucfirst($d['ssl_status'])); ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 text-right whitespace-nowrap">
                            <form method="post" class="inline">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="verify">
                                <input type="hidden" name="id" value="<?php echo sanitize($d['id']); ?>">
                                <button class="px-3 py-1.5 text-xs bg-blue-50 hover:bg-blue-100 text-blue-700 rounded-md font-medium">
                                    <i class="fa-solid fa-rotate"></i> Verify
                                </button>
                            </form>
                            <form method="post" class="inline" onsubmit="return confirm('Remove this custom domain?');">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo sanitize($d['id']); ?>">
                                <button class="px-3 py-1.5 text-xs bg-rose-50 hover:bg-rose-100 text-rose-700 rounded-md font-medium">
                                    <i class="fa-solid fa-trash"></i> Delete
                                </button>
                            </form>
                        </td>
                    </tr>
                    <tr class="bg-gray-50/50">
                        <td colspan="5" class="px-6 py-3 text-xs text-gray-600">
                            <strong>DNS setup:</strong>
                            create a <code>CNAME</code> record on
                            <code><?php echo sanitize($d['domain']); ?></code>
                            pointing to <code class="px-1.5 py-0.5 bg-white border border-gray-200 rounded">cardify.om</code>
                            <button type="button" class="ml-2 text-blue-600 hover:underline"
                                    onclick="navigator.clipboard.writeText('cardify.om')">copy</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <div class="mt-6 bg-blue-50 border border-blue-200 rounded-2xl p-5 text-sm text-blue-900">
        <div class="font-semibold mb-1"><i class="fa-solid fa-circle-info"></i> SSL is provisioned manually for v1</div>
        After your DNS is verified, contact Cardify support to issue an SSL
        certificate for your domain. We'll mark SSL status as <strong>Active</strong>
        once the certificate is live. Auto-SSL is coming in v2.
    </div>

    <?php endif; ?>
</div>

<?php adminFooter(); ?>
