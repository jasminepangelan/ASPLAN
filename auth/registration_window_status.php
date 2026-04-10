<?php
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security_policy.php';

$conn = getDBConnection();
$timezone = (string) (defined('APP_TIMEZONE') ? APP_TIMEZONE : date_default_timezone_get());
$serverNow = date('Y-m-d H:i:s');

$open = true;
$message = '';

if (isRegistrationDisabled($conn)) {
    $open = false;
    $message = 'Registration is temporarily disabled by the administrator. Please try again later.';
} else {
    $windowStatus = isRegistrationWindowOpen($conn);
    $open = !empty($windowStatus['open']);
    $message = (string) ($windowStatus['message'] ?? '');
}

closeDBConnection($conn);

echo json_encode([
    'open' => $open,
    'message' => $message,
    'timezone' => $timezone,
    'server_time' => $serverNow,
]);
