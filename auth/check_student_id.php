<?php
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/student_masterlist_service.php';
require_once __DIR__ . '/../includes/student_registration_service.php';

$studentId = isset($_POST['student_id']) ? trim((string) $_POST['student_id']) : trim((string) ($_GET['student_id'] ?? ''));

if ($studentId === '') {
    echo json_encode(['exists' => false, 'error' => 'No student_id provided']);
    exit;
}

$useLaravelBridge = getenv('USE_LARAVEL_BRIDGE') === '1';

if ($useLaravelBridge) {
    $bridgeUrl = laravelBridgeUrl('/api/check-student-id');
    $payload = http_build_query(['student_id' => $studentId]);
    $bridgeResponse = false;

    if (function_exists('curl_init')) {
        $ch = curl_init($bridgeUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $bridgeResponse = curl_exec($ch);
        curl_close($ch);
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => $payload,
                'timeout' => 5,
            ],
        ]);
        $bridgeResponse = @file_get_contents($bridgeUrl, false, $context);
    }

    if ($bridgeResponse !== false) {
        $decoded = json_decode($bridgeResponse, true);
        if (is_array($decoded) && array_key_exists('exists', $decoded)) {
            echo json_encode([
                'exists' => (bool) $decoded['exists'],
                'allowed' => (bool) ($decoded['allowed'] ?? true),
                'message' => (string) ($decoded['message'] ?? ''),
            ]);
            exit;
        }
    }
}

$conn = getDBConnection();
$availability = srsGetRegistrationAvailability($conn, $studentId);
if (!$availability['allowed']) {
    echo json_encode(['exists' => true, 'allowed' => false, 'message' => 'Student number already exists in the system.']);
    closeDBConnection($conn);
    exit;
}

$masterlistGate = smlStudentIdAllowedForRegistration($conn, $studentId);
echo json_encode([
    'exists' => false,
    'allowed' => (bool) $masterlistGate['allowed'],
    'message' => !$masterlistGate['allowed']
        ? (string) ($masterlistGate['message'] ?? '')
        : (string) ($availability['message'] ?? ''),
]);
closeDBConnection($conn);
