<?php
/**
 * Amwal Pay Payment Process Endpoint
 * This endpoint handles payment form submission to Amwal Pay
 * Based on Amwal Pay Laravel package structure
 */
session_start();
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Billing.php';
requireAdmin();

$companyId = getCurrentCompanyId();
$orderId = $_GET['order_id'] ?? $_POST['order_id'] ?? null;

if (empty($orderId)) {
    die('Order ID is required');
}

// Get payment data from session
$sessionKey = 'amwal_payment_' . $orderId;
if (!isset($_SESSION[$sessionKey])) {
    die('Payment session expired. Please try again.');
}

$paymentData = $_SESSION[$sessionKey]['payment_data'];
$merchantId = AMWAL_MERCHANT_ID ?? '';
$terminalId = AMWAL_TERMINAL_ID ?? '';
$apiUrl = AMWAL_API_URL ?? 'https://backend.sa.amwal.tech';

// Get the process URL from config
$processUrl = $apiUrl . '/payment/process';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Processing Payment | <?php echo SITE_NAME; ?></title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        .container {
            text-align: center;
            color: white;
        }
        .spinner {
            border: 4px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top: 4px solid white;
            width: 50px;
            height: 50px;
            animation: spin 1s linear infinite;
            margin: 0 auto 20px;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="spinner"></div>
        <h2>Redirecting to Amwal Pay...</h2>
        <p>Please wait while we redirect you to complete your payment.</p>
    </div>
    
    <form id="amwalForm" method="POST" action="<?php echo htmlspecialchars($processUrl); ?>">
        <?php foreach ($paymentData as $key => $value): ?>
            <input type="hidden" name="<?php echo htmlspecialchars($key); ?>" value="<?php echo htmlspecialchars($value); ?>">
        <?php endforeach; ?>
    </form>
    
    <script>
        // Auto-submit form
        document.getElementById('amwalForm').submit();
    </script>
</body>
</html>
