<?php
/**
 * purge-unverified-demos.php, daily cleanup for the instant-card funnel.
 *
 * Removes demo cards (under the `demo` tenant) that were never verified AND are
 * older than 14 days AND have no card_events (nobody shared/opened them). Keeps
 * verified cards and cards with real engagement. Also clears their stale pending
 * leads + edit tokens.
 *
 * Usage:
 *   php scripts/purge-unverified-demos.php           # live
 *   php scripts/purge-unverified-demos.php --dry-run # count only
 *
 * Cron (VPS): 0 3 * * *  /www/server/php/83/bin/php /www/wwwroot/cardify.om/scripts/purge-unverified-demos.php >> /var/log/cardify-demo-purge.log 2>&1
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Database.php';
require_once INCLUDES_DIR . '/InstantCard.php';

$dry = in_array('--dry-run', $argv ?? [], true);
$demo = InstantCard::DEMO_COMPANY_ID;
$pdo = Database::getInstance()->getConnection();
$stamp = date('Y-m-d H:i:s');

try {
    // Candidate demo cards: unverified, >14 days old, zero card_events.
    $sel = $pdo->prepare(
        "SELECT e.id, e.email FROM employees e
         LEFT JOIN card_events ev ON ev.employee_id = e.id
         WHERE e.company_id = :demo
           AND (e.demo_meta IS NULL OR e.demo_meta NOT LIKE '%\"verified\":true%')
           AND e.created_at < (NOW() - INTERVAL 14 DAY)
         GROUP BY e.id, e.email
         HAVING COUNT(ev.id) = 0"
    );
    $sel->execute([':demo' => $demo]);
    $rows = $sel->fetchAll(PDO::FETCH_ASSOC);
    $ids = array_column($rows, 'id');
    $emails = array_values(array_unique(array_column($rows, 'email')));

    echo "[$stamp] purge-unverified-demos: " . count($ids) . " candidate(s)" . ($dry ? " (DRY RUN)\n" : "\n");
    if ($dry) {
        foreach ($rows as $r) { echo "  would delete: {$r['id']} ({$r['email']})\n"; }
        exit(0);
    }
    if (!$ids) { echo "  nothing to purge.\n"; exit(0); }

    $in = implode(',', array_fill(0, count($ids), '?'));
    $pdo->prepare("DELETE FROM employee_edit_tokens WHERE employee_id IN ($in)")->execute($ids);
    if ($emails) {
        $ein = implode(',', array_fill(0, count($emails), '?'));
        $pdo->prepare("DELETE FROM cardify_signup_leads WHERE source='hero_instant' AND status='pending' AND email IN ($ein)")->execute($emails);
    }
    $pdo->prepare("DELETE FROM employees WHERE id IN ($in)")->execute($ids);
    echo "  deleted " . count($ids) . " demo card(s) + their tokens/leads.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "[$stamp] purge-unverified-demos FAILED: " . $e->getMessage() . "\n");
    exit(1);
}
