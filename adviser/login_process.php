<?php
require_once __DIR__ . '/../config/config.php';

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$loginUrl = buildAppRelativeUrl('/index.html');
$message = 'Adviser and program coordinator sign-in now uses the unified ASPLAN login page.';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    header('Content-Type: application/json; charset=UTF-8');
    http_response_code(410);
    echo json_encode([
        'status' => 'deprecated_endpoint',
        'message' => $message,
        'redirect' => $loginUrl,
    ]);
    exit();
}

header('Location: ' . $loginUrl);
exit();
