<?php
header('Content-Type: application/json; charset=UTF-8');
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security_policy.php';

// Get database connection
$conn = getDBConnection();

$useLaravelAuthBridge = getenv('USE_LARAVEL_AUTH_BRIDGE') === '1';

if (!$useLaravelAuthBridge) {
    closeDBConnection($conn);
    echo json_encode([
        'success' => false,
        'message' => 'Authentication bridge is disabled. Set USE_LARAVEL_AUTH_BRIDGE=1.',
    ]);
    exit;
}

// Get required fields
$student_id = isset($_POST['student_id']) ? trim($_POST['student_id']) : '';
$code = isset($_POST['code']) ? trim($_POST['code']) : '';
$password = isset($_POST['password']) ? $_POST['password'] : '';

if (!$student_id || !$code || !$password) {
    closeDBConnection($conn);
    echo json_encode(['success' => false, 'message' => 'All fields are required.']);
    exit;
}

$bridgeUrl = laravelBridgeUrl('/api/reset-password');
$payloadJson = json_encode([
    'student_id' => $student_id,
    'code' => $code,
    'password' => $password,
]);

$bridgeResponse = false;
if (function_exists('curl_init')) {
    $ch = curl_init($bridgeUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payloadJson,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    ]);
    $bridgeResponse = curl_exec($ch);
    curl_close($ch);
} else {
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => $payloadJson,
            'timeout' => 10,
        ],
    ]);
    $bridgeResponse = @file_get_contents($bridgeUrl, false, $context);
}

if ($bridgeResponse !== false) {
    $bridgeData = json_decode($bridgeResponse, true);
    if (is_array($bridgeData) && isset($bridgeData['success'])) {
        closeDBConnection($conn);
        if (!empty($bridgeData['success'])) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => $bridgeData['message'] ?? 'Failed to update password.',
            ]);
        }
        exit;
    }
}

closeDBConnection($conn);
echo json_encode([
    'success' => false,
    'message' => 'Authentication service is temporarily unavailable. Please try again shortly.',
]);
exit;
?>
