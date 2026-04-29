<?php
/**
 * Cardify Terms of Service (Cat R action 439).
 * Bilingual via lang/{en,ar}/legal.php, section renderer shared with
 * privacy.php through /includes/legal-render.php.
 */
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/Seo.php';

$legalKey = 'terms';
require __DIR__ . '/includes/legal-render.php';
