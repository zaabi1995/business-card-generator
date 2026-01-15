<?php
/**
 * Company Login - Redirects to unified login page
 */
require_once __DIR__ . '/../config.php';

// Redirect to unified login page with company parameter if provided
$companyParam = isset($_GET['company']) ? '?company=' . urlencode($_GET['company']) : '';
header('Location: ' . getBasePath() . 'login.php' . $companyParam);
exit;
