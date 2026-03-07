<?php
/**
 * Print Shop Login - Redirect to unified login
 */
require_once __DIR__ . '/../config.php';

// Redirect to main login with print shop context
header('Location: ' . getBasePath() . 'login.php');
exit;
