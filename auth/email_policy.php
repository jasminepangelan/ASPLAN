<?php
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security_policy.php';

$email = isset($_POST['email']) ? trim((string) $_POST['email']) : trim((string) ($_GET['email'] ?? ''));

if ($email === '') {
    echo json_encode([
        'allowed' => false,
        'message' => 'Email address is required.',
    ]);
    exit;
}

$conn = getDBConnection();
$emailPolicy = isAllowedEmailDomain($conn, $email);
closeDBConnection($conn);

echo json_encode([
    'allowed' => (bool) ($emailPolicy['allowed'] ?? false),
    'message' => (string) ($emailPolicy['message'] ?? ''),
]);
