<?php
/**
 * Cardify Privacy Policy (Cat R action 439).
 * Bilingual via lang/{en,ar}/legal.php, section renderer shared with
 * terms.php through /includes/legal-render.php.
 */
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/Seo.php';

$legalKey = 'privacy';
require __DIR__ . '/includes/legal-render.php';
