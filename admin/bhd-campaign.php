<?php
/**
 * BHD Campaign Manager
 * Send WhatsApp/email campaigns to BHD customers and track referral conversions
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/WhatsApp.php';
require_once INCLUDES_DIR . '/admin-layout.php';

requireAdmin();

// Super admin only
if (!Auth::hasRole('super_admin')) {
    header('Location: ' . getBasePath() . 'admin/');
    exit;
}

$db = Database::getInstance();
$message = null;
$messageType = 'success';

// ---- Referral Analytics ----
$referralStats = $db->fetchAll("
    SELECT
        COALESCE(referral_source, 'direct') AS source,
        COUNT(*) AS companies,
        SUM(CASE WHEN onboarding_completed = 1 THEN 1 ELSE 0 END) AS onboarded,
        MAX(created_at) AS last_signup
    FROM companies
    GROUP BY referral_source
    ORDER BY companies DESC
");

$bhdCompanies = $db->fetchAll("
    SELECT c.id, c.name, c.admin_email, c.created_at, c.onboarding_completed,
           COUNT(po.id) AS order_count,
           SUM(CASE WHEN po.status != 'cancelled' THEN po.total ELSE 0 END) AS total_revenue
    FROM companies c
    LEFT JOIN print_orders po ON po.company_id = c.id
    WHERE c.referral_source = 'bhd'
    GROUP BY c.id
    ORDER BY c.created_at DESC
");

$conversionRate = 0;
$totalBhd = count($bhdCompanies);
$totalWithOrders = count(array_filter($bhdCompanies, fn($c) => ($c['order_count'] ?? 0) > 0));
if ($totalBhd > 0) $conversionRate = round(($totalWithOrders / $totalBhd) * 100, 1);

$campaignHistory = $db->fetchAll("
    SELECT campaign_name, channel,
           COUNT(*) AS total_sent,
           SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) AS succeeded,
           SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed,
           MIN(sent_at) AS started_at
    FROM campaign_logs
    GROUP BY campaign_name, channel
    ORDER BY started_at DESC
    LIMIT 20
");

// ---- Handle campaign send ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !validateCSRFToken($_POST['csrf_token'] ?? '')) {
    die(htmlspecialchars(t('bhdcampaign.invalid_request')));
}

$sendResults = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_campaign') {
    $campaignName = trim($_POST['campaign_name'] ?? str_replace(':date', date('Y-m-d'), t('bhdcampaign.default_campaign')));
    $channel      = $_POST['channel'] ?? 'whatsapp';
    $msgTemplate  = trim($_POST['message_template'] ?? '');
    $recipients   = array_filter(array_map('trim', explode("\n", $_POST['recipients'] ?? '')));

    if (empty($msgTemplate) || empty($recipients)) {
        $message = t('bhdcampaign.need_msg_recipient');
        $messageType = 'error';
    } else {
        $sent = $failed = 0;
        foreach ($recipients as $recipient) {
            $recipient = trim($recipient);
            if (empty($recipient)) continue;

            $logId = generateUUID();
            $status = 'pending';
            $error = null;

            if ($channel === 'whatsapp') {
                $result = WhatsApp::sendMessage($recipient, $msgTemplate);
                $status = $result['success'] ? 'sent' : 'failed';
                $error  = $result['error'] ?? null;
            } elseif ($channel === 'email') {
                // Email not implemented here, log as pending for manual send
                $status = 'pending';
            }

            $db->exec(
                "INSERT INTO campaign_logs (id, campaign_name, channel, recipient, status, error_msg, referral_source) VALUES (:id,:cn,:ch,:rec,:st,:err,:ref)",
                ['id' => $logId, 'cn' => $campaignName, 'ch' => $channel, 'rec' => $recipient, 'st' => $status, 'err' => $error, 'ref' => 'bhd']
            );

            if ($status === 'sent') $sent++;
            else $failed++;

            $sendResults[] = ['recipient' => $recipient, 'status' => $status, 'error' => $error];

            // Small delay to avoid spam detection
            if ($channel === 'whatsapp') usleep(500000); // 0.5s
        }

        $message = strtr(t('bhdcampaign.sent_summary'), [':sent' => (string) $sent, ':failed' => (string) $failed]);
        $messageType = $failed > 0 && $sent === 0 ? 'error' : ($failed > 0 ? 'warning' : 'success');
    }
}

// Default WhatsApp template
$defaultWhatsapp = "Hello! BHD Printing now offers digital business cards through Cardify.\n\nDesign, share, and print professional cards for your team, in minutes.\n\nGet started FREE: https://cardify.om/bhd\n\nYour BHD Printing team";

adminHeader(t('adminchrome.bhd_campaign_manager'), 'reports');
?>

<div class="max-w-6xl mx-auto">

    <!-- Header -->
    <div class="mb-8">
        <div class="flex items-center gap-3 mb-2">
            <div class="w-10 h-10 bg-blue-100 rounded-xl flex items-center justify-center">
                <i class="fa-solid fa-bullhorn text-blue-600"></i>
            </div>
            <div>
                <h1 class="text-2xl font-bold text-gray-900"><?= htmlspecialchars(t('bhdcampaign.page_h1')) ?></h1>
                <p class="text-gray-500 text-sm"><?= htmlspecialchars(t('bhdcampaign.page_sub')) ?></p>
            </div>
        </div>
    </div>

    <?php if ($message): ?>
    <div class="mb-6 p-4 rounded-xl flex items-start gap-3
        <?= $messageType === 'success' ? 'bg-green-50 border border-green-200 text-green-700' : ($messageType === 'warning' ? 'bg-amber-50 border border-amber-200 text-amber-700' : 'bg-red-50 border border-red-200 text-red-700') ?>">
        <i class="fa-solid fa-<?= $messageType === 'success' ? 'circle-check' : ($messageType === 'warning' ? 'triangle-exclamation' : 'circle-exclamation') ?> mt-0.5"></i>
        <div><?= sanitize($message) ?></div>
    </div>
    <?php endif; ?>

    <!-- Send results -->
    <?php if (!empty($sendResults)): ?>
    <div class="mb-6 bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <div class="px-5 py-3 border-b border-gray-100 font-medium text-sm text-gray-700"><?= htmlspecialchars(t('bhdcampaign.send_results_h')) ?></div>
        <div class="max-h-48 overflow-y-auto divide-y divide-gray-50">
            <?php foreach ($sendResults as $r): ?>
            <div class="px-5 py-2 flex items-center justify-between text-sm">
                <span class="text-gray-700"><?= sanitize($r['recipient']) ?></span>
                <span class="<?= $r['status'] === 'sent' ? 'text-green-600' : 'text-red-600' ?> font-medium">
                    <?= $r['status'] === 'sent' ? '<i class="fa-solid fa-check mr-1"></i>' . htmlspecialchars(t('bhdcampaign.sent_label')) : '<i class="fa-solid fa-xmark mr-1"></i>' . sanitize($r['error'] ?? t('bhdcampaign.failed_label')) ?>
                </span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        <!-- Left: Campaign Composer -->
        <div class="lg:col-span-2 space-y-6">

            <!-- Send Campaign -->
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-100">
                    <h2 class="font-semibold text-gray-900"><i class="fa-brands fa-whatsapp mr-2 text-green-500"></i><?= htmlspecialchars(t('bhdcampaign.send_campaign_h')) ?></h2>
                </div>
                <form method="POST" class="p-6 space-y-4">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="send_campaign">

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1"><?= htmlspecialchars(t('bhdcampaign.field_campaign_name')) ?></label>
                            <input type="text" name="campaign_name" value="<?= htmlspecialchars(str_replace(':date', date('Y-m-d'), t('bhdcampaign.default_campaign'))) ?>"
                                   class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1"><?= htmlspecialchars(t('bhdcampaign.field_channel')) ?></label>
                            <select name="channel" class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:border-blue-500">
                                <option value="whatsapp"><?= htmlspecialchars(t('bhdcampaign.channel_whatsapp')) ?></option>
                                <option value="email"><?= htmlspecialchars(t('bhdcampaign.channel_email')) ?></option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1"><?= htmlspecialchars(t('bhdcampaign.field_msg_tpl')) ?></label>
                        <textarea name="message_template" rows="6"
                                  class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm font-mono focus:border-blue-500 focus:ring-1 focus:ring-blue-500"><?= htmlspecialchars($defaultWhatsapp) ?></textarea>
                        <p class="text-xs text-gray-400 mt-1"><?= htmlspecialchars(t('bhdcampaign.msg_tpl_hint')) ?></p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1"><?= htmlspecialchars(t('bhdcampaign.field_recipients')) ?> <span class="text-gray-400 font-normal"><?= htmlspecialchars(t('bhdcampaign.recipients_sub')) ?></span></label>
                        <textarea name="recipients" rows="8" placeholder="96812345678&#10;96887654321&#10;96899001234"
                                  class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm font-mono focus:border-blue-500 focus:ring-1 focus:ring-blue-500"></textarea>
                        <p class="text-xs text-gray-400 mt-1"><?= htmlspecialchars(t('bhdcampaign.recipients_hint')) ?></p>
                    </div>

                    <div class="flex items-center justify-between pt-2">
                        <p class="text-xs text-amber-600 flex items-center gap-1">
                            <i class="fa-solid fa-triangle-exclamation"></i>
                            <?= htmlspecialchars(t('bhdcampaign.send_warning')) ?>
                        </p>
                        <button type="submit" data-cardify-confirm="<?= htmlspecialchars(t('bhdcampaign.confirm_send'), ENT_QUOTES) ?>"
                                class="px-6 py-2.5 bg-green-600 hover:bg-green-700 text-white rounded-xl font-medium text-sm transition-colors flex items-center gap-2">
                            <i class="fa-brands fa-whatsapp"></i> <?= htmlspecialchars(t('bhdcampaign.btn_send')) ?>
                        </button>
                    </div>
                </form>
            </div>

            <!-- Email Template (display only) -->
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                    <h2 class="font-semibold text-gray-900"><i class="fa-solid fa-envelope mr-2 text-blue-500"></i><?= htmlspecialchars(t('bhdcampaign.email_tpl_h')) ?></h2>
                    <span class="text-xs text-gray-400"><?= htmlspecialchars(t('bhdcampaign.email_tpl_sub')) ?></span>
                </div>
                <div class="p-6">
                    <div class="bg-gray-50 border border-gray-200 rounded-xl p-5 font-sans text-sm text-gray-800 space-y-3">
                        <p><strong>Subject:</strong> Your business cards, upgraded, Free offer from BHD Printing</p>
                        <hr class="border-gray-200">
                        <p>Hi [Name],</p>
                        <p>We're excited to introduce <strong>Cardify</strong>, a digital business card platform exclusively available to BHD Printing customers.</p>
                        <p>With Cardify, you can:</p>
                        <ul class="list-disc pl-5 space-y-1">
                            <li>Design professional business cards in minutes</li>
                            <li>Share your card digitally via a QR code or link</li>
                            <li>Order high-quality printed cards delivered to your door</li>
                            <li>Manage cards for your entire team from one account</li>
                        </ul>
                        <p><strong>Get started free:</strong> <a href="https://cardify.om/bhd" class="text-blue-600">cardify.om/bhd</a></p>
                        <p>As a valued BHD customer, your first card design is on us.</p>
                        <p>Warm regards,<br>The BHD Printing Team</p>
                    </div>
                </div>
            </div>

        </div>

        <!-- Right: Analytics -->
        <div class="space-y-6">

            <!-- Conversion KPIs -->
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
                <h2 class="font-semibold text-gray-900 mb-4"><i class="fa-solid fa-chart-line mr-2 text-blue-500"></i><?= htmlspecialchars(t('bhdcampaign.stats_h')) ?></h2>
                <div class="space-y-3">
                    <div class="flex justify-between items-center">
                        <span class="text-sm text-gray-600"><?= htmlspecialchars(t('bhdcampaign.stat_signups')) ?></span>
                        <span class="font-bold text-2xl text-gray-900"><?= $totalBhd ?></span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-sm text-gray-600"><?= htmlspecialchars(t('bhdcampaign.stat_converted')) ?></span>
                        <span class="font-bold text-2xl text-green-600"><?= $totalWithOrders ?></span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-sm text-gray-600"><?= htmlspecialchars(t('bhdcampaign.stat_conversion')) ?></span>
                        <span class="font-bold text-2xl text-blue-600"><?= $conversionRate ?>%</span>
                    </div>
                    <?php if (!empty($bhdCompanies)):
                        $totalRev = array_sum(array_column($bhdCompanies, 'total_revenue'));
                    ?>
                    <div class="flex justify-between items-center border-t border-gray-100 pt-3">
                        <span class="text-sm text-gray-600"><?= htmlspecialchars(t('bhdcampaign.stat_revenue')) ?></span>
                        <span class="font-bold text-gray-900"><?= number_format($totalRev, 3) ?> <?= htmlspecialchars(t('bhdcampaign.omr')) ?></span>
                    </div>
                    <?php endif; ?>
                </div>
                <a href="<?= getBasePath() ?>bhd/" target="_blank"
                   class="mt-4 flex items-center justify-center gap-2 w-full py-2 bg-blue-50 hover:bg-blue-100 text-blue-600 rounded-lg text-sm font-medium transition-colors">
                    <i class="fa-solid fa-external-link"></i> <?= htmlspecialchars(t('bhdcampaign.view_landing')) ?>
                </a>
            </div>

            <!-- Traffic by source -->
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
                <div class="px-5 py-3 border-b border-gray-100 font-semibold text-sm text-gray-700"><?= htmlspecialchars(t('bhdcampaign.signups_by_source')) ?></div>
                <div class="divide-y divide-gray-50">
                    <?php foreach ($referralStats as $stat): ?>
                    <div class="px-5 py-3 flex items-center justify-between text-sm">
                        <div class="flex items-center gap-2">
                            <span class="w-2 h-2 rounded-full <?= $stat['source'] === 'bhd' ? 'bg-blue-500' : ($stat['source'] === 'direct' ? 'bg-gray-400' : 'bg-purple-500') ?>"></span>
                            <span class="text-gray-700 capitalize"><?= sanitize($stat['source']) ?></span>
                        </div>
                        <div class="text-right">
                            <span class="font-medium text-gray-900"><?= (int)$stat['companies'] ?></span>
                            <span class="text-gray-400 ml-1"><?= htmlspecialchars(t('bhdcampaign.companies_suffix')) ?></span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Campaign History -->
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
                <div class="px-5 py-3 border-b border-gray-100 font-semibold text-sm text-gray-700"><?= htmlspecialchars(t('bhdcampaign.history_h')) ?></div>
                <?php if (empty($campaignHistory)): ?>
                <div class="px-5 py-6 text-center text-gray-400 text-sm"><?= htmlspecialchars(t('bhdcampaign.history_empty')) ?></div>
                <?php else: ?>
                <div class="divide-y divide-gray-50 max-h-48 overflow-y-auto">
                    <?php foreach ($campaignHistory as $camp): ?>
                    <div class="px-5 py-3 text-sm">
                        <div class="font-medium text-gray-900 truncate"><?= sanitize($camp['campaign_name']) ?></div>
                        <div class="text-gray-500 text-xs mt-0.5">
                            <?= ucfirst($camp['channel']) ?> &bull; <?= date('M j Y', dbTs($camp['started_at'])) ?>
                            &bull; <span class="text-green-600"><?= htmlspecialchars(str_replace(':n', (string)(int)$camp['succeeded'], t('bhdcampaign.n_sent'))) ?></span>
                            <?php if ($camp['failed'] > 0): ?> &bull; <span class="text-red-500"><?= htmlspecialchars(str_replace(':n', (string)(int)$camp['failed'], t('bhdcampaign.n_failed'))) ?></span><?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

        </div>
    </div>

    <!-- BHD Signups Table -->
    <?php if (!empty($bhdCompanies)): ?>
    <div class="mt-6 bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
            <h2 class="font-semibold text-gray-900"><?= htmlspecialchars(str_replace(':n', (string)$totalBhd, t('bhdcampaign.signups_table_h'))) ?></h2>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-xs font-semibold text-gray-500 uppercase">
                    <tr>
                        <th class="px-6 py-3 text-left"><?= htmlspecialchars(t('bhdcampaign.col_company')) ?></th>
                        <th class="px-6 py-3 text-left"><?= htmlspecialchars(t('bhdcampaign.col_contact')) ?></th>
                        <th class="px-6 py-3 text-right"><?= htmlspecialchars(t('bhdcampaign.col_orders')) ?></th>
                        <th class="px-6 py-3 text-right"><?= htmlspecialchars(t('bhdcampaign.col_revenue')) ?></th>
                        <th class="px-6 py-3 text-left"><?= htmlspecialchars(t('bhdcampaign.col_joined')) ?></th>
                        <th class="px-6 py-3 text-left"><?= htmlspecialchars(t('bhdcampaign.col_status')) ?></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php foreach ($bhdCompanies as $comp): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-3 font-medium text-gray-900"><?= sanitize($comp['name']) ?></td>
                        <td class="px-6 py-3 text-gray-600">
                            <?php if ($comp['admin_email']): ?><div><?= sanitize($comp['admin_email']) ?></div><?php endif; ?>
                        </td>
                        <td class="px-6 py-3 text-right"><?= (int)$comp['order_count'] ?></td>
                        <td class="px-6 py-3 text-right font-medium"><?= number_format($comp['total_revenue'] ?? 0, 3) ?> <?= htmlspecialchars(t('bhdcampaign.omr')) ?></td>
                        <td class="px-6 py-3 text-gray-500"><?= date('M j, Y', dbTs($comp['created_at'])) ?></td>
                        <td class="px-6 py-3">
                            <?php if ((int)$comp['order_count'] > 0): ?>
                            <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-700"><?= htmlspecialchars(t('bhdcampaign.status_converted')) ?></span>
                            <?php elseif ($comp['onboarding_completed']): ?>
                            <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-700"><?= htmlspecialchars(t('bhdcampaign.status_onboarded')) ?></span>
                            <?php else: ?>
                            <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-600"><?= htmlspecialchars(t('bhdcampaign.status_signed_up')) ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

</div>

<?php adminFooter(); ?>
