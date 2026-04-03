
<?php
// forgot_password.php: Step 1 - Accept student ID, verify, and send code to associated email
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/rate_limit.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Get database connection
$conn = getDBConnection();

$useLaravelAuthBridge = getenv('USE_LARAVEL_AUTH_BRIDGE') === '1';

// Check rate limit (settings-backed defaults if configured)
$rateLimit = checkRateLimit('forgot_password');
if (!$rateLimit['allowed']) {
    echo json_encode([
        'success' => false, 
        'message' => $rateLimit['message']
    ]);
    exit;
}

$student_id = isset($_POST['student_id']) ? trim($_POST['student_id']) : '';
if (!$student_id) {
    recordAttempt('forgot_password');
    echo json_encode(['success' => false, 'message' => 'Student ID is required.']);
    exit;
}

// Validate student ID format
if (!preg_match('/^[0-9]{1,20}$/', $student_id)) {
    recordAttempt('forgot_password');
    echo json_encode(['success' => false, 'message' => 'Invalid Student ID format.']);
    exit;
}

if (!function_exists('formatForgotPasswordMailerError')) {
    function formatForgotPasswordMailerError(Throwable $e, $mail = null): string
    {
        $raw = trim((string) $e->getMessage());
        if ($raw === '' && is_object($mail) && isset($mail->ErrorInfo)) {
            $raw = trim((string) $mail->ErrorInfo);
        }

        if ($raw === '') {
            return 'Unable to send the verification email right now. Please try again later.';
        }

        if (stripos($raw, 'Could not connect to SMTP host') !== false || stripos($raw, 'Network is unreachable') !== false) {
            return 'Unable to connect to the configured SMTP server. Please check the mail host, port, and outbound network access.';
        }

        return preg_replace('/^(Mailer Error:\s*)+/i', 'Mailer Error: ', $raw) ?: 'Unable to send the verification email right now.';
    }
}

if ($useLaravelAuthBridge) {
    $bridgeData = postLaravelJsonBridge('/api/forgot-password', [
        'student_id' => $student_id,
    ], 12);

    if (is_array($bridgeData) && isset($bridgeData['success'])) {
        closeDBConnection($conn);
        if (!empty($bridgeData['success'])) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => $bridgeData['message'] ?? 'Failed to send code. Please try again.',
            ]);
        }
        exit;
    }
}

// Check if student exists and get their email
$stmt = $conn->prepare("SELECT email FROM student_info WHERE student_number = ?");
$stmt->bind_param("s", $student_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Student ID not found.']);
    exit;
}

$row = $result->fetch_assoc();
$email = $row['email'];
// Only allow CvSU email addresses for security
if (!preg_match('/^([a-zA-Z0-9_.+-]+)@cvsu.edu\.ph$/', $email)) {
    echo json_encode(['success' => false, 'message' => 'Only CvSU accounts are allowed.']);
    exit;
}

// Generate 4-digit code
$code = str_pad(random_int(0, 9999), 4, '0', STR_PAD_LEFT);
$expiry = date('Y-m-d H:i:s', strtotime('+10 minutes'));

// Store code in password_resets table (create if not exists)
$conn->query('CREATE TABLE IF NOT EXISTS password_resets (
    email VARCHAR(255) PRIMARY KEY,
    code VARCHAR(10),
    expires_at DATETIME
)');

$stmt = $conn->prepare('REPLACE INTO password_resets (email, code, expires_at) VALUES (?, ?, ?)');
$stmt->bind_param('sss', $email, $code, $expiry);
$stmt->execute();
$stmt->close();

// Send code to email using PHPMailer and Gmail SMTP
$mail = getMailer();

try {
    // Recipients
    $mail->addAddress($email);

    // Content
    $mail->isHTML(false);
    $mail->Subject = 'Your Password Reset Code';
    $mail->Body    = "Your password reset code is: $code\nThis code will expire in 10 minutes.";

    $mail->send();
    closeDBConnection($conn);
    echo json_encode(['success' => true]);
    exit;
} catch (Exception $e) {
    closeDBConnection($conn);
    echo json_encode(['success' => false, 'message' => formatForgotPasswordMailerError($e, $mail)]);
    exit;
}

