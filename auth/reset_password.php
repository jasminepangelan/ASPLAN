<?php
header('Content-Type: application/json');
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

$minimumPasswordLength = policySettingInt($conn, 'min_password_length', 8, 6, 64);
if (strlen($password) < $minimumPasswordLength) {
    echo json_encode(['success' => false, 'message' => 'Password must be at least ' . $minimumPasswordLength . ' characters long.']);
    exit;
}

// First get the student's email
$stmt = $conn->prepare('SELECT email FROM student_info WHERE student_number = ? LIMIT 1');
$stmt->bind_param('s', $student_id);
$stmt->execute();
$stmt->store_result();
if ($stmt->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Student not found.']);
    exit;
}
$stmt->bind_result($email);
$stmt->fetch();
$stmt->close();

// Now check code validity
$stmt = $conn->prepare('SELECT code, expires_at FROM password_resets WHERE email = ? LIMIT 1');
$stmt->bind_param('s', $email);
$stmt->execute();
$stmt->store_result();
if ($stmt->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'No reset request found.']);
    exit;
}
$stmt->bind_result($db_code, $expires_at);
$stmt->fetch();
$stmt->close();

if ($db_code !== $code) {
    echo json_encode(['success' => false, 'message' => 'Invalid code.']);
    exit;
}
$expiresAtValue = (string)($expires_at ?? '');
if ($expiresAtValue === '' || strtotime($expiresAtValue) < time()) {
    echo json_encode(['success' => false, 'message' => 'Code expired.']);
    exit;
}

// Update password (student_info table) by student_number
$currentHashStmt = $conn->prepare('SELECT password FROM student_info WHERE student_number = ? LIMIT 1');
$currentHashStmt->bind_param('s', $student_id);
$currentHashStmt->execute();
$currentHashResult = $currentHashStmt->get_result();
$currentHashRow = $currentHashResult ? $currentHashResult->fetch_assoc() : null;
$currentHashStmt->close();
$currentHash = $currentHashRow['password'] ?? '';

$passwordHistoryCount = policySettingInt($conn, 'password_history_count', 5, 0, 24);
if (isPasswordReuseDetected($conn, $student_id, $password, $passwordHistoryCount, $currentHash)) {
    echo json_encode(['success' => false, 'message' => 'New password was recently used. Please choose a different password.']);
    exit;
}

$hashed = password_hash($password, PASSWORD_DEFAULT);
$stmt = $conn->prepare('UPDATE student_info SET password = ? WHERE student_number = ?');
$stmt->bind_param('ss', $hashed, $student_id);
if ($stmt->execute()) {
    recordPasswordHistory($conn, $student_id, $hashed);
    // Remove reset code
    $del = $conn->prepare('DELETE FROM password_resets WHERE email = ?');
    $del->bind_param('s', $email);
    $del->execute();
    $del->close();

    try {
        $profileStmt = $conn->prepare('SELECT email, last_name, first_name, middle_name FROM student_info WHERE student_number = ? LIMIT 1');
        $profileStmt->bind_param('s', $student_id);
        $profileStmt->execute();
        $profileResult = $profileStmt->get_result();
        $profileRow = $profileResult ? $profileResult->fetch_assoc() : null;
        $profileStmt->close();

        $notifyEmail = trim((string) ($profileRow['email'] ?? ''));
        if ($notifyEmail !== '' && filter_var($notifyEmail, FILTER_VALIDATE_EMAIL)) {
            $nameParts = array_filter([
                trim((string) ($profileRow['first_name'] ?? '')),
                trim((string) ($profileRow['middle_name'] ?? '')),
                trim((string) ($profileRow['last_name'] ?? '')),
            ], static function ($value) {
                return $value !== '';
            });

            $fullName = trim(implode(' ', $nameParts));
            if ($fullName === '') {
                $fullName = 'Student';
            }

            require_once __DIR__ . '/../includes/EmailNotification.php';
            $notifier = new EmailNotification();
            $notifier->sendPasswordChange($notifyEmail, $fullName);
        }
    } catch (Throwable $e) {
        error_log('reset_password notification error: ' . $e->getMessage());
    }

    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to update password.']);
}
$stmt->close();

// Close database connection
closeDBConnection($conn);
?>
