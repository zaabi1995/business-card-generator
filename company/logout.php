<?php
require_once __DIR__ . '/../config.php';
logoutCompanyAdmin();
header('Location: ' . getBasePath() . 'company/login.php');
exit;


