<?php
header('Content-Type: application/json; charset=UTF-8');
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security_policy.php';

// Get database connection
$conn = getDBConnection();

$useLaravelAuthBridge = getenv('USE_LARAVEL_AUTH_BRIDGE') === '1';

// Get required fields
$student_id = isset($_POST['student_id']) ? trim($_POST['student_id']) : '';
$code = isset($_POST['code']) ? trim($_POST['code']) : '';
$password = isset($_POST['password']) ? $_POST['password'] : '';

if (!$student_id || !$code || !$password) {
    closeDBConnection($conn);
    echo json_encode(['success' => false, 'message' => 'All fields are required.']);
    exit;
}

if ($useLaravelAuthBridge) {
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
}

// Local MySQL fallback for resetting password
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

$minimumPasswordLength = 6;
if (function_exists('policySettingInt')) {
    $minimumPasswordLength = policySettingInt($conn, 'min_password_length', 8, 6, 64);
}
if (strlen($password) < $minimumPasswordLength) {
    closeDBConnection($conn);
    echo json_encode(['success' => false, 'message' => 'Password must be at least ' . $minimumPasswordLength . ' characters long.']);
    exit;
}

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
    echo json_encode(['success' => false, 'message' => 'No reset request found or session expired.']);
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

if ($storedCode !== $code && !password_verify($code, $storedCode)) {
    closeDBConnection($conn);
    echo json_encode(['success' => false, 'message' => 'Invalid verification code.']);
    exit;
}

// 3. Update password
$hashed = password_hash($password, PASSWORD_DEFAULT);
$stmt = $conn->prepare("UPDATE student_info SET password = ? WHERE student_number = ?");
$stmt->bind_param("ss", $hashed, $student_id);
if ($stmt->execute()) {
    $stmt->close();
    if (function_exists('recordPasswordHistory')) {
        @recordPasswordHistory($conn, $student_id, $hashed);
    }
    // Remove reset code
    $del = $conn->prepare("DELETE FROM password_resets WHERE email = ?");
    $del->bind_param("s", $email);
    $del->execute();
    $del->close();
    closeDBConnection($conn);
    echo json_encode(['success' => true]);
    exit;
} else {
    $stmt->close();
    closeDBConnection($conn);
    echo json_encode(['success' => false, 'message' => 'Failed to update password in database.']);
    exit;
}
?>
