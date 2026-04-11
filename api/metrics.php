<?php
/**
 * Cardify Metrics API
 * Returns aggregate platform stats for strategic loop monitoring.
 * Read-only, no PII exposed.
 */
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    $db = Database::getInstance();

    // Total companies
    $r = $db->fetchOne("SELECT COUNT(*) as cnt FROM companies");
    $totalCompanies = (int)($r['cnt'] ?? 0);

    // Total employees
    $r = $db->fetchOne("SELECT COUNT(*) as cnt FROM employees");
    $totalEmployees = (int)($r['cnt'] ?? 0);

    // Total cards generated
    $r = $db->fetchOne("SELECT COUNT(*) as cnt FROM generated_cards");
    $totalCards = (int)($r['cnt'] ?? 0);

    // Signups last 7 days
    $r = $db->fetchOne("SELECT COUNT(*) as cnt FROM companies WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
    $signupsLast7d = (int)($r['cnt'] ?? 0);

    // Cards created last 7 days
    $r = $db->fetchOne("SELECT COUNT(*) as cnt FROM generated_cards WHERE generated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
    $cardsLast7d = (int)($r['cnt'] ?? 0);

    // Paying customers (active subscription, not free)
    $r = $db->fetchOne("SELECT COUNT(*) as cnt FROM companies WHERE subscription_status = 'active' AND plan != 'free'");
    $payingCustomers = (int)($r['cnt'] ?? 0);

    // Monthly revenue (OMR) - sum of paid payments this month
    $r = $db->fetchOne(
        "SELECT COALESCE(SUM(amount), 0) as total FROM payments
         WHERE status = 'paid' AND currency = 'OMR'
         AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')"
    );
    $monthlyRevenueOmr = round((float)($r['total'] ?? 0), 3);

    // Total revenue all-time (OMR)
    $r = $db->fetchOne(
        "SELECT COALESCE(SUM(amount), 0) as total FROM payments
         WHERE status = 'paid' AND currency = 'OMR'"
    );
    $totalRevenueOmr = round((float)($r['total'] ?? 0), 3);

    // Print orders (total + last 7 days)
    $r = $db->fetchOne("SELECT COUNT(*) as cnt FROM print_orders");
    $totalPrintOrders = (int)($r['cnt'] ?? 0);

    $r = $db->fetchOne("SELECT COUNT(*) as cnt FROM print_orders WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
    $printOrdersLast7d = (int)($r['cnt'] ?? 0);

    echo json_encode([
        'ok' => true,
        'generated_at' => gmdate('c'),
        'metrics' => [
            'total_companies' => $totalCompanies,
            'total_employees' => $totalEmployees,
            'total_cards' => $totalCards,
            'signups_last_7d' => $signupsLast7d,
            'cards_created_last_7d' => $cardsLast7d,
            'paying_customers' => $payingCustomers,
            'monthly_revenue_omr' => $monthlyRevenueOmr,
            'total_revenue_omr' => $totalRevenueOmr,
            'total_print_orders' => $totalPrintOrders,
            'print_orders_last_7d' => $printOrdersLast7d,
        ],
    ], JSON_PRETTY_PRINT);

} catch (Exception $e) {
    error_log('metrics API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Internal server error']);
}
