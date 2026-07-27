<?php
// verify_code.php: Step 2 - Accept student ID and code, check validity
header('Content-Type: application/json; charset=UTF-8');
require_once __DIR__ . '/../config/config.php';

// Get database connection
$conn = getDBConnection();

$useLaravelAuthBridge = getenv('USE_LARAVEL_AUTH_BRIDGE') === '1';

$student_id = isset($_POST['student_id']) ? trim($_POST['student_id']) : '';
$code = isset($_POST['code']) ? trim($_POST['code']) : '';

if (!$student_id || !$code) {
    closeDBConnection($conn);
    echo json_encode(['success' => false, 'message' => 'Student ID and code are required.']);
    exit;
}

if ($useLaravelAuthBridge) {
    $bridgeUrl = laravelBridgeUrl('/api/verify-code');
    $payloadJson = json_encode([
        'student_id' => $student_id,
        'code' => $code,
    ]);

    $bridgeResponse = false;
    if (function_exists('curl_init')) {
        $ch = curl_init($bridgeUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payloadJson,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
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
                'timeout' => 8,
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
                    'message' => $bridgeData['message'] ?? 'Invalid code.',
                ]);
            }
            exit;
        }
    }
}

// Local MySQL fallback verification
if (!function_exists('ensurePasswordResetsTable')) {
    function ensurePasswordResetsTable($conn): void
    {
        $conn->query('CREATE TABLE IF NOT EXISTS password_resets (
            email VARCHAR(255) PRIMARY KEY,
            code VARCHAR(255),
            expires_at DATETIME
        )');
        $conn->query('ALTER TABLE password_resets MODIFY COLUMN code VARCHAR(255) NULL');
    }
}
ensurePasswordResetsTable($conn);

// 1. Get student email
$stmt = $conn->prepare("SELECT email FROM student_info WHERE student_number = ? LIMIT 1");
$stmt->bind_param("s", $student_id);
$stmt->execute();
$result = $stmt->get_result();
if (!$result || $result->num_rows === 0) {
    closeDBConnection($conn);
    echo json_encode(['success' => false, 'message' => 'Student ID not found.']);
    exit;
}
$row = $result->fetch_assoc();
$email = $row['email'];
$stmt->close();

// 2. Lookup reset code
$stmt = $conn->prepare("SELECT code, expires_at FROM password_resets WHERE email = ? LIMIT 1");
$stmt->bind_param("s", $email);
$stmt->execute();
$res = $stmt->get_result();
if (!$res || $res->num_rows === 0) {
    closeDBConnection($conn);
    echo json_encode(['success' => false, 'message' => 'No verification code found or it has already been used. Please request a new one.']);
    exit;
}
$resetRow = $res->fetch_assoc();
$storedCode = (string)$resetRow['code'];
$expiresAtValue = (string)$resetRow['expires_at'];
$stmt->close();

if ($expiresAtValue === '' || strtotime($expiresAtValue) < time()) {
    $del = $conn->prepare("DELETE FROM password_resets WHERE email = ?");
    $del->bind_param("s", $email);
    $del->execute();
    $del->close();
    closeDBConnection($conn);
    echo json_encode(['success' => false, 'message' => 'Verification code has expired. Please request a new one.']);
    exit;
}

// 3. Verify code (handle plain string or password_hash)
if ($storedCode !== $code && !password_verify($code, $storedCode)) {
    closeDBConnection($conn);
    echo json_encode(['success' => false, 'message' => 'Invalid verification code. Please try again.']);
    exit;
}

closeDBConnection($conn);
echo json_encode(['success' => true]);
exit;
?>
