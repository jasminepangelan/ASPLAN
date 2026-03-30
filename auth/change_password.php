<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security_policy.php';

header('Content-Type: application/json');

$useLaravelAuthBridge = getenv('USE_LARAVEL_AUTH_BRIDGE') === '1';

if (!$useLaravelAuthBridge) {
    echo json_encode([
        'success' => false,
        'message' => 'Authentication bridge is disabled. Set USE_LARAVEL_AUTH_BRIDGE=1.',
    ]);
    exit;
}

// Check if the user is logged in
if (!isset($_SESSION['student_id'])) {
    echo json_encode(['success' => false, 'message' => 'User not logged in']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $student_id = $_SESSION['student_id'];  // Get the student ID from the session
    $current_password = isset($_POST['current_password']) ? (string) $_POST['current_password'] : '';
    $new_password = isset($_POST['new_password']) ? (string) $_POST['new_password'] : '';

    $bridgeUrl = laravelBridgeUrl('/api/change-password');
    $payloadJson = json_encode([
        'student_id' => $student_id,
        'current_password' => $current_password,
        'new_password' => $new_password,
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
            if (!empty($bridgeData['success'])) {
                echo json_encode(['success' => true]);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => $bridgeData['message'] ?? 'Failed to update password',
                ]);
            }
            exit;
        }
    }

    echo json_encode([
        'success' => false,
        'message' => 'Authentication service is temporarily unavailable. Please try again shortly.',
    ]);
    exit;

    // Get database connection
    $conn = getDBConnection();

    $minimumPasswordLength = policySettingInt($conn, 'min_password_length', 8, 6, 64);
    if (strlen($new_password) < $minimumPasswordLength) {
        echo json_encode(['success' => false, 'message' => 'Password must be at least ' . $minimumPasswordLength . ' characters long']);
        exit;
    }

    // Fetch the current password from the database
    $sql = "SELECT password FROM student_info WHERE student_number = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $student_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $stored_password = $result->fetch_assoc()['password'];

    // Verify the current password
    if (!password_verify($current_password, $stored_password)) {
        echo json_encode(['success' => false, 'message' => 'Current password is incorrect']);
        exit;
    }

    $passwordHistoryCount = policySettingInt($conn, 'password_history_count', 5, 0, 24);
    if (isPasswordReuseDetected($conn, $student_id, $new_password, $passwordHistoryCount, $stored_password)) {
        echo json_encode(['success' => false, 'message' => 'New password was recently used. Please choose a different password.']);
        exit;
    }

    // Hash the new password
    $hashed_password = password_hash($new_password, PASSWORD_BCRYPT);

    // Update the password in the database
    $sql = "UPDATE student_info SET password = ? WHERE student_number = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ss', $hashed_password, $student_id);

    if (!$stmt->execute()) {
        echo json_encode(['success' => false, 'message' => 'Failed to update password']);
        exit;
    }

    recordPasswordHistory($conn, $student_id, $hashed_password);

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
        error_log('change_password notification error: ' . $e->getMessage());
    }

    echo json_encode(['success' => true]);
    closeDBConnection($conn);
}
?>
