
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

if (!function_exists('shouldFallbackForgotPasswordToLocalMailer')) {
    function shouldFallbackForgotPasswordToLocalMailer(string $message): bool
    {
        $message = strtolower(trim($message));
        if ($message === '') {
            return true;
        }

        $transportMarkers = [
            'temporarily unavailable',
            'email service is not configured',
            'unable to connect to the configured smtp server',
            'could not connect to smtp host',
            'network is unreachable',
            'mailer error:',
            'failed to send code',
        ];

        foreach ($transportMarkers as $marker) {
            if (strpos($message, $marker) !== false) {
                return true;
            }
        }

        return false;
    }
}

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

if ($useLaravelAuthBridge) {
    $bridgeData = postLaravelJsonBridge('/api/forgot-password', [
        'student_id' => $student_id,
    ], 12);

    if (is_array($bridgeData) && isset($bridgeData['success'])) {
        if (!empty($bridgeData['success'])) {
            closeDBConnection($conn);
            echo json_encode(['success' => true]);
            exit;
        }

        $bridgeMessage = (string) ($bridgeData['message'] ?? 'Failed to send code. Please try again.');
        if (!shouldFallbackForgotPasswordToLocalMailer($bridgeMessage)) {
            closeDBConnection($conn);
            echo json_encode([
                'success' => false,
                'message' => $bridgeMessage,
            ]);
            exit;
        }
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
$codeHash = password_hash($code, PASSWORD_DEFAULT);
$expiry = date('Y-m-d H:i:s', strtotime('+10 minutes'));

// Store a hashed code in password_resets so the raw verification code is not persisted.
ensurePasswordResetsTable($conn);

$stmt = $conn->prepare('REPLACE INTO password_resets (email, code, expires_at) VALUES (?, ?, ?)');
$stmt->bind_param('sss', $email, $codeHash, $expiry);
$stmt->execute();
$stmt->close();

try {
    $sendResult = sendConfiguredEmail(
        $email,
        'Your Password Reset Code',
        "Your password reset code is: $code\nThis code will expire in 10 minutes."
    );

    if (empty($sendResult['success'])) {
        throw new RuntimeException((string) ($sendResult['error'] ?? 'Unable to send the verification email right now.'));
    }

    closeDBConnection($conn);
    echo json_encode(['success' => true]);
    exit;
} catch (Throwable $e) {
    closeDBConnection($conn);
    echo json_encode(['success' => false, 'message' => formatForgotPasswordMailerError($e)]);
    exit;
}

