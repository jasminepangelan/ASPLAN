<?php
/**
 * Auto-Login Check Endpoint (AJAX)
 * Checks the remember-me cookie and returns a redirect URL if valid.
 * Called from index.html on page load.
 */

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../config/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$sessionExpiredNotice = !empty($_SESSION['session_expired']);
$sessionTimeoutSeconds = isset($_SESSION['session_timeout_seconds']) ? (int)$_SESSION['session_timeout_seconds'] : null;

if ($sessionExpiredNotice) {
    clearAppCookie('remember_me', '/');
    clearAppCookie('remember_me', '/PEAS/');
    unset($_SESSION['session_expired'], $_SESSION['session_timeout_seconds']);

    echo json_encode([
        'redirect' => null,
        'session_expired' => true,
        'session_timeout_seconds' => $sessionTimeoutSeconds,
    ]);
    exit();
}

// If already logged in, return the redirect
if (isset($_SESSION['student_id'])) {
    echo json_encode([
        'redirect' => 'student/home_page_student.php',
        'session_expired' => $sessionExpiredNotice,
        'session_timeout_seconds' => $sessionTimeoutSeconds,
    ]);
    exit();
}

// Check for remember me cookie
if (!isset($_COOKIE['remember_me'])) {
    echo json_encode([
        'redirect' => null,
        'session_expired' => $sessionExpiredNotice,
        'session_timeout_seconds' => $sessionTimeoutSeconds,
    ]);
    exit();
}

$cookie_data = $_COOKIE['remember_me'];

$useLaravelAuthBridge = getenv('USE_LARAVEL_AUTH_BRIDGE') === '1';

if (!$useLaravelAuthBridge) {
    echo json_encode([
        'redirect' => null,
        'session_expired' => $sessionExpiredNotice,
        'session_timeout_seconds' => $sessionTimeoutSeconds,
    ]);
    exit();
}

$bridgeUrl = laravelBridgeUrl('/api/check-auto-login');
$payloadJson = json_encode([
    'remember_me' => $cookie_data,
    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
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
    if (is_array($bridgeData)) {
        if (isset($bridgeData['session']) && is_array($bridgeData['session'])) {
            session_regenerate_id(true);
            foreach ($bridgeData['session'] as $key => $value) {
                $_SESSION[$key] = $value;
            }
        }

        if (!empty($bridgeData['clear_cookie'])) {
            clearAppCookie('remember_me', '/');
            clearAppCookie('remember_me', '/PEAS/');
        }

        echo json_encode([
            'redirect' => $bridgeData['redirect'] ?? null,
            'session_expired' => $sessionExpiredNotice,
            'session_timeout_seconds' => $sessionTimeoutSeconds,
        ]);
        exit();
    }
}

echo json_encode([
    'redirect' => null,
    'error' => 'Authentication service unavailable',
    'session_expired' => $sessionExpiredNotice,
    'session_timeout_seconds' => $sessionTimeoutSeconds,
]);
exit();

$parts = explode(':', $cookie_data);
$student_id = '';
$remember_token = '';

if (count($parts) === 3) {
    list($student_id, $remember_token, $account_type) = $parts;
    if ($account_type !== 'student') {
        echo json_encode(['redirect' => null]);
        exit();
    }
} elseif (count($parts) === 2) {
    // Backward compatibility for older remember-me cookie format.
    list($student_id, $remember_token) = $parts;
} else {
    echo json_encode(['redirect' => null]);
    exit();
}

// Validate student_id format
if (!preg_match('/^[0-9]{1,20}$/', $student_id)) {
    echo json_encode(['redirect' => null]);
    exit();
}

$conn = getDBConnection();

$query = $conn->prepare(
    "SELECT student_number AS student_id, last_name, first_name, middle_name, email,
            contact_number AS contact_no,
            CONCAT_WS(', ', house_number_street, brgy, town, province) AS address,
            date_of_admission AS admission_date, NULL AS picture, remember_token
     FROM student_info
     WHERE student_number = ? AND remember_token IS NOT NULL AND remember_token_expiry > NOW()"
);
$query->bind_param("s", $student_id);
$query->execute();
$result = $query->get_result();

if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();

    if (password_verify($remember_token, $row['remember_token'])) {
        // Token is valid — establish session
        session_regenerate_id(true);

        $_SESSION['student_id']    = $student_id;
        $_SESSION['last_name']     = $row['last_name'];
        $_SESSION['first_name']    = $row['first_name'];
        $_SESSION['middle_name']   = $row['middle_name'];
        $_SESSION['email']         = $row['email'] ?? '';
        $_SESSION['contact_no']    = $row['contact_no'];
        $_SESSION['address']       = $row['address'];
        $_SESSION['admission_date']= $row['admission_date'];
        $_SESSION['picture']       = $row['picture'];
        $_SESSION['login_time']    = time();
        $_SESSION['user_ip']       = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $_SESSION['auto_login']    = true;

        closeDBConnection($conn);
        echo json_encode(['redirect' => 'student/home_page_student.php']);
        exit();
    }
}

// Token invalid or expired — clear the cookie
clearAppCookie('remember_me', '/');
clearAppCookie('remember_me', '/PEAS/');

closeDBConnection($conn);
echo json_encode(['redirect' => null]);
