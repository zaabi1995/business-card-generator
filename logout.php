<?php
/**
 * Unified Logout
 */
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/Auth.php';

Auth::logout();

header('Location: ' . getBasePath() . 'login.php');
exit;
