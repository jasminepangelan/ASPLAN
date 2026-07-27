<?php
/**
 * Lightweight auto-login probe for the public login page.
 *
 * This endpoint intentionally avoids loading the full legacy bootstrap so
 * the login screen can still render even if deeper app services are unhealthy.
 */

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

if (!function_exists('autoLoginIsSecureRequest')) {
    function autoLoginIsSecureRequest(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }

        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
            return true;
        }

        return isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443;
    }
}

if (!function_exists('autoLoginSetCookie')) {
    function autoLoginSetCookie(string $name, string $value, int $expires, string $path = '/'): bool
    {
        return setcookie($name, $value, [
            'expires' => $expires,
            'path' => $path,
            'secure' => autoLoginIsSecureRequest(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}

if (!function_exists('autoLoginClearCookie')) {
    function autoLoginClearCookie(string $name, string $path = '/'): bool
    {
        return autoLoginSetCookie($name, '', time() - 3600, $path);
    }
}

require_once __DIR__ . '/../includes/env_loader.php';
require_once __DIR__ . '/../includes/laravel_bridge.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/student_email_verification_service.php';
require_once __DIR__ . '/../includes/student_masterlist_service.php';

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', autoLoginIsSecureRequest() ? '1' : '0');
    ini_set('session.cookie_samesite', 'Lax');
    session_start();
}

function autoLoginJson(array $payload): void
{
    echo json_encode($payload);
    exit();
}

$sessionExpiredNotice = !empty($_SESSION['session_expired']);
$sessionTimeoutSeconds = isset($_SESSION['session_timeout_seconds']) ? (int) $_SESSION['session_timeout_seconds'] : null;

if ($sessionExpiredNotice) {
    autoLoginClearCookie('remember_me', '/');
    autoLoginClearCookie('remember_me', '/PEAS/');
    unset($_SESSION['session_expired'], $_SESSION['session_timeout_seconds']);

    autoLoginJson([
        'redirect' => null,
        'session_expired' => true,
        'session_timeout_seconds' => $sessionTimeoutSeconds,
    ]);
}

if (isset($_SESSION['student_id'])) {
    $sessionConn = getDBConnection();
    if (!smlStudentHasSystemAccess($sessionConn, (string) ($_SESSION['student_id'] ?? ''))) {
        $_SESSION = [];
        if (session_id() !== '') {
            session_destroy();
        }
        closeDBConnection($sessionConn);
        autoLoginClearCookie('remember_me', '/');
        autoLoginClearCookie('remember_me', '/PEAS/');
        autoLoginJson([
            'redirect' => null,
            'session_expired' => false,
            'session_timeout_seconds' => $sessionTimeoutSeconds,
        ]);
    }

    $requiresVerification = sevApplySessionRequirement(
        $sessionConn,
        (string) ($_SESSION['student_id'] ?? ''),
        (string) ($_SESSION['email'] ?? '')
    );
    closeDBConnection($sessionConn);

    autoLoginJson([
        'redirect' => $requiresVerification ? sevVerificationRedirectUrl() : 'student/home_page_student.php',
        'session_expired' => false,
        'session_timeout_seconds' => $sessionTimeoutSeconds,
    ]);
}

if (empty($_COOKIE['remember_me'])) {
    autoLoginJson([
        'redirect' => null,
        'session_expired' => false,
        'session_timeout_seconds' => $sessionTimeoutSeconds,
    ]);
}

$bridgeData = null;
if ((getenv('USE_LARAVEL_AUTH_BRIDGE') ?: '') === '1') {
    try {
        $bridgeData = postLaravelJsonBridge('/api/check-auto-login', [
            'remember_me' => (string) $_COOKIE['remember_me'],
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        ], 8);
    } catch (Throwable $e) {
        $bridgeData = null;
    }
}

if (is_array($bridgeData) && (isset($bridgeData['session']) || !empty($bridgeData['redirect']) || !empty($bridgeData['clear_cookie']))) {
    if (isset($bridgeData['session']) && is_array($bridgeData['session'])) {
        session_regenerate_id(true);
        foreach ($bridgeData['session'] as $key => $value) {
            $_SESSION[$key] = $value;
        }

        $verificationConn = getDBConnection();
        $requiresVerification = sevApplySessionRequirement(
            $verificationConn,
            (string) ($_SESSION['student_id'] ?? ''),
            (string) ($_SESSION['email'] ?? '')
        );
        closeDBConnection($verificationConn);

        if ($requiresVerification) {
            $_SESSION['student_email_verification_notice'] = 'Please verify your CvSU email address before accessing your student workspace.';
        }
    }

    if (!empty($bridgeData['clear_cookie'])) {
        autoLoginClearCookie('remember_me', '/');
        autoLoginClearCookie('remember_me', '/PEAS/');
    }

    autoLoginJson([
        'redirect' => !empty($_SESSION['student_email_verification_required'])
            ? sevVerificationRedirectUrl()
            : ($bridgeData['redirect'] ?? null),
        'session_expired' => false,
        'session_timeout_seconds' => $sessionTimeoutSeconds,
    ]);
}

// Local MySQL fallback verification when bridge is disabled or unavailable
$cookieData = (string) $_COOKIE['remember_me'];
$parts = explode(':', $cookieData);
$studentId = '';
$rememberToken = '';
$accountType = 'student';

if (count($parts) === 3) {
    list($studentId, $rememberToken, $accountType) = $parts;
} elseif (count($parts) === 2) {
    list($studentId, $rememberToken) = $parts;
}

if ($accountType === 'student' && preg_match('/^[0-9]{1,20}$/', $studentId) && $rememberToken !== '') {
    $conn = getDBConnection();
    if (smlStudentHasSystemAccess($conn, $studentId)) {
        $query = $conn->prepare("SELECT student_number AS student_id, last_name, first_name, middle_name, email, contact_number AS contact_no, CONCAT_WS(', ', house_number_street, brgy, town, province) AS address, date_of_admission AS admission_date, picture, status, program, remember_token FROM student_info WHERE student_number = ? AND remember_token IS NOT NULL AND remember_token_expiry > NOW()");
        if ($query) {
            $query->bind_param("s", $studentId);
            $query->execute();
            $result = $query->get_result();

            if ($result && $result->num_rows > 0) {
                $row = $result->fetch_assoc();
                if (($row['status'] ?? '') !== 'archived' && password_verify($rememberToken, (string) $row['remember_token'])) {
                    session_regenerate_id(true);
                    $_SESSION['student_id'] = $studentId;
                    $_SESSION['last_name'] = $row['last_name'];
                    $_SESSION['first_name'] = $row['first_name'];
                    $_SESSION['middle_name'] = $row['middle_name'];
                    $_SESSION['email'] = $row['email'] ?? '';
                    $_SESSION['contact_no'] = $row['contact_no'];
                    $_SESSION['address'] = $row['address'];
                    $_SESSION['admission_date'] = $row['admission_date'];
                    $_SESSION['picture'] = $row['picture'];
                    $_SESSION['program'] = $row['program'] ?? '';
                    $_SESSION['login_time'] = time();
                    $_SESSION['user_ip'] = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                    $_SESSION['user_type'] = 'student';
                    $_SESSION['auto_login'] = true;

                    $requiresVerification = sevApplySessionRequirement(
                        $conn,
                        (string) $studentId,
                        (string) ($row['email'] ?? '')
                    );
                    if ($requiresVerification) {
                        $_SESSION['student_email_verification_notice'] = 'Please verify your CvSU email address before accessing your student workspace.';
                    }
                    closeDBConnection($conn);

                    autoLoginJson([
                        'redirect' => $requiresVerification ? sevVerificationRedirectUrl() : 'student/home_page_student.php',
                        'session_expired' => false,
                        'session_timeout_seconds' => $sessionTimeoutSeconds,
                    ]);
                }
            }
        }
    }
    closeDBConnection($conn);
}

autoLoginClearCookie('remember_me', '/');
autoLoginClearCookie('remember_me', '/PEAS/');

autoLoginJson([
    'redirect' => null,
    'session_expired' => false,
    'session_timeout_seconds' => $sessionTimeoutSeconds,
]);
