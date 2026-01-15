<?php
/**
 * Admin Login - Redirects to unified login page
 */
require_once __DIR__ . '/../config.php';

// Redirect to unified login page
header('Location: ' . getBasePath() . 'login.php');
exit;
