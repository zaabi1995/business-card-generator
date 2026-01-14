<?php
/**
 * API Endpoint: Check Company Slug Availability
 */
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

$slug = $_GET['slug'] ?? '';

if (empty($slug)) {
    echo json_encode(['available' => false, 'error' => 'Slug required']);
    exit;
}

// Validate format
if (!preg_match('/^[a-z0-9-]+$/', $slug)) {
    echo json_encode(['available' => false, 'error' => 'Invalid format']);
    exit;
}

// Check if slug exists
$company = findCompanyBySlug($slug);

echo json_encode([
    'available' => $company === null,
    'slug' => $slug
]);
