<?php
/**
 * Get CSRF Token Endpoint
 * Returns a JSON response with a CSRF token
 */

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/env_loader.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

echo json_encode([
    'success' => true,
    'token' => getCSRFToken(),
]);
