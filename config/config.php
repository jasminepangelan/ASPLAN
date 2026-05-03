<?php
/**
 * Master Configuration File
 * Include this file in all PHP scripts to load all configurations
 * 
 * Usage: require_once __DIR__ . '/config/config.php';
 */

if (!defined('APP_BOOTSTRAP_BUFFERING')) {
    define('APP_BOOTSTRAP_BUFFERING', true);
    if (!headers_sent() && !ob_get_level()) {
        ob_start();
    }
}

if (!function_exists('isSecureRequest')) {
    function isSecureRequest() {
        if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
            return true;
        }

        if (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) {
            return true;
        }

        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
            return true;
        }

        return false;
    }
}

if (!function_exists('setAppCookie')) {
    function setAppCookie($name, $value, $expires, $path = '/') {
        return setcookie($name, $value, [
            'expires' => (int)$expires,
            'path' => (string)$path,
            'secure' => isSecureRequest(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}

if (!function_exists('clearAppCookie')) {
    function clearAppCookie($name, $path = '/') {
        return setAppCookie($name, '', time() - 3600, $path);
    }
}

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    if (!headers_sent()) {
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_secure', isSecureRequest() ? '1' : '0');
        ini_set('session.cookie_samesite', 'Lax');

        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => isSecureRequest(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_start();
    } else {
        @session_start();
    }
}

// Load all configuration files
require_once __DIR__ . '/app.php';
emitAppSecurityHeaders();
require_once __DIR__ . '/../includes/laravel_bridge.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/email.php';
require_once __DIR__ . '/../includes/student_email_verification_service.php';
require_once __DIR__ . '/../includes/coordinator_email_verification_service.php';
require_once __DIR__ . '/../includes/admin_session_service.php';
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/error_logging_service.php';

// Initialize error logging and request tracking
elsInitialize();
elsGenerateRequestId();

if (!function_exists('appBasePath')) {
    function appBasePath() {
        $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        $appFolder = basename(dirname(__DIR__));
        $marker = '/' . $appFolder . '/';
        $markerPos = strpos($scriptName, $marker);
        return $markerPos !== false ? substr($scriptName, 0, $markerPos + strlen('/' . $appFolder)) : '';
    }
}

if (!function_exists('buildAppRelativeUrl')) {
    function buildAppRelativeUrl($path) {
        $normalizedPath = '/' . ltrim((string)$path, '/');
        return appBasePath() . $normalizedPath;
    }
}

if (!function_exists('buildLoginUrlWithTimeoutNotice')) {
    function buildLoginUrlWithTimeoutNotice($sessionTimeout) {
        $loginPath = buildAppRelativeUrl('/index.html');

        return $loginPath . '?session_expired=1&limit=' . urlencode((string)$sessionTimeout);
    }
}

if (!function_exists('buildLoginUrlWithAdminSessionNotice')) {
    function buildLoginUrlWithAdminSessionNotice() {
        $loginPath = buildAppRelativeUrl('/index.html');

        return $loginPath . '?admin_session_replaced=1';
    }
}

if (!function_exists('shouldInjectSessionTimeoutClientScript')) {
    function shouldInjectSessionTimeoutClientScript() {
        if (PHP_SAPI === 'cli') {
            return false;
        }

        $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        $excludedPrefixes = ['/auth/', '/api/', '/handlers/'];
        foreach ($excludedPrefixes as $prefix) {
            if (stripos($scriptName, $prefix) !== false) {
                return false;
            }
        }

        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            return false;
        }

        if (isset($_SERVER['HTTP_ACCEPT'])) {
            $accept = strtolower((string)$_SERVER['HTTP_ACCEPT']);
            if (strpos($accept, 'application/json') !== false && strpos($accept, 'text/html') === false) {
                return false;
            }
        }

        return true;
    }
}

if (!function_exists('shouldBypassStudentVerificationGate')) {
    function shouldBypassStudentVerificationGate(): bool
    {
        if (PHP_SAPI === 'cli') {
            return true;
        }

        $scriptName = str_replace('\\', '/', strtolower((string)($_SERVER['SCRIPT_NAME'] ?? '')));
        $allowed = [
            '/student/verify_cvsu_email.php',
            '/student/send_cvsu_email_verification.php',
            '/student/verify_cvsu_email_otp.php',
            '/auth/signout.php',
            '/auth/unified_login_process.php',
            '/auth/check_auto_login.php',
            '/auth/check_remember_me.php',
            '/auth/get_csrf_token.php',
            '/handlers/student_input_process.php',
        ];

        foreach ($allowed as $path) {
            if (str_ends_with($scriptName, $path)) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('shouldBypassCoordinatorVerificationGate')) {
    function shouldBypassCoordinatorVerificationGate(): bool
    {
        if (PHP_SAPI === 'cli') {
            return true;
        }

        $scriptName = str_replace('\\', '/', strtolower((string)($_SERVER['SCRIPT_NAME'] ?? '')));
        $allowed = [
            '/program_coordinator/profile.php',
            '/program_coordinator/verify_cvsu_email.php',
            '/program_coordinator/send_cvsu_email_verification.php',
            '/program_coordinator/verify_cvsu_email_otp.php',
            '/auth/signout.php',
            '/auth/unified_login_process.php',
            '/auth/get_csrf_token.php',
        ];

        foreach ($allowed as $path) {
            if (str_ends_with($scriptName, $path)) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('shouldBypassAdminSessionGuard')) {
    function shouldBypassAdminSessionGuard(): bool
    {
        if (PHP_SAPI === 'cli') {
            return true;
        }

        $scriptName = str_replace('\\', '/', strtolower((string)($_SERVER['SCRIPT_NAME'] ?? '')));
        $allowed = [
            '/admin/logout.php',
            '/auth/signout.php',
            '/auth/unified_login_process.php',
            '/auth/get_csrf_token.php',
            '/admin/setup_2fa.php',
            '/admin/verify_2fa.php',
        ];

        foreach ($allowed as $path) {
            if (str_ends_with($scriptName, $path)) {
                return true;
            }
        }

        return false;
    }
}

if (
    !empty($_SESSION['student_id']) &&
    !empty($_SESSION['student_email_verification_required']) &&
    !shouldBypassStudentVerificationGate()
) {
    $verificationRedirect = sevVerificationRedirectUrl();
    $expectsJson =
        (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
        (isset($_SERVER['HTTP_ACCEPT']) && stripos((string)$_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

    if ($expectsJson) {
        header('Content-Type: application/json; charset=UTF-8');
        http_response_code(403);
        echo json_encode([
            'status' => 'email_verification_required',
            'message' => 'Please verify your CvSU email address before continuing.',
            'redirect' => $verificationRedirect,
        ]);
        exit();
    }

    header('Location: ' . $verificationRedirect);
    exit();
}

if (
    (string) ($_SESSION['user_type'] ?? '') === 'program_coordinator' &&
    !empty($_SESSION['username']) &&
    !empty($_SESSION['program_coordinator_email_verification_required']) &&
    !shouldBypassCoordinatorVerificationGate()
) {
    $verificationRedirect = cevVerificationRedirectUrl();
    $expectsJson =
        (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
        (isset($_SERVER['HTTP_ACCEPT']) && stripos((string)$_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

    if ($expectsJson) {
        header('Content-Type: application/json; charset=UTF-8');
        http_response_code(403);
        echo json_encode([
            'status' => 'email_verification_required',
            'message' => 'Please verify your CvSU email address before continuing.',
            'redirect' => $verificationRedirect,
        ]);
        exit();
    }

    header('Location: ' . $verificationRedirect);
    exit();
}

if (
    !shouldBypassAdminSessionGuard() &&
    ((string) ($_SESSION['user_type'] ?? '') === 'admin' || !empty($_SESSION['admin_id']) || !empty($_SESSION['admin_username']))
) {
    $adminUsername = trim((string) ($_SESSION['admin_username'] ?? $_SESSION['admin_id'] ?? ''));

    if ($adminUsername !== '') {
        $adminSessionConn = getDBConnection();
        $sessionStillCurrent = assIsCurrentAdminSession($adminSessionConn, $adminUsername);

        if (!$sessionStillCurrent) {
            session_unset();
            session_destroy();
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            $_SESSION['admin_session_replaced'] = true;

            $expectsJson =
                (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
                (isset($_SERVER['HTTP_ACCEPT']) && stripos((string)$_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

            if ($expectsJson) {
                header('Content-Type: application/json; charset=UTF-8');
                http_response_code(401);
                echo json_encode([
                    'status' => 'session_expired',
                    'message' => 'Your admin account was signed in on another device. Please log in again.',
                    'redirect' => buildLoginUrlWithAdminSessionNotice(),
                ]);
                exit();
            }

            header('Location: ' . buildLoginUrlWithAdminSessionNotice());
            exit();
        }

        assTouchActiveSession($adminSessionConn, $adminUsername);
    }
}

// Enforce session timeout for authenticated sessions.
if (isset($_SESSION['login_time'])) {
    $defaultTimeout = defined('SESSION_TIMEOUT') ? (int)SESSION_TIMEOUT : 3600;
    $sessionTimeout = function_exists('getSystemSettingInt')
        ? getSystemSettingInt('session_timeout_seconds', $defaultTimeout, 60, 86400)
        : $defaultTimeout;
    $loginUrl = buildLoginUrlWithTimeoutNotice($sessionTimeout);

    $elapsed = time() - (int)$_SESSION['login_time'];

    if ($elapsed <= $sessionTimeout && shouldInjectSessionTimeoutClientScript()) {
        $remainingSeconds = max(1, $sessionTimeout - $elapsed);
        $clientTimeoutScript = '<script>(function(){'
            . 'const remainingSeconds=' . json_encode($remainingSeconds) . ';'
            . 'const timeoutSeconds=' . json_encode($sessionTimeout) . ';'
            . 'const redirectUrl=' . json_encode($loginUrl) . ';'
            . 'function showNoticeThenRedirect(){'
                . 'if(document.getElementById("global-session-timeout-overlay")){return;}'
                . 'const overlay=document.createElement("div");'
                . 'overlay.id="global-session-timeout-overlay";'
                . 'overlay.style.cssText="position:fixed;inset:0;background:rgba(0,0,0,.55);display:flex;align-items:center;justify-content:center;z-index:99999;padding:16px;";'
                . 'const box=document.createElement("div");'
                . 'box.style.cssText="width:100%;max-width:460px;background:#fff;border-radius:12px;padding:22px 20px;text-align:center;box-shadow:0 20px 45px rgba(0,0,0,.28);font-family:Poppins,Arial,sans-serif;";'
                . 'box.innerHTML="<div style=\"font-size:18px;font-weight:700;color:#c62828;margin-bottom:8px;\">Session Limit Reached</div>"+'
                    . '"<div style=\"font-size:14px;color:#333;line-height:1.45;\">Your session expired after "+timeoutSeconds+" seconds of inactivity.</div>"+'
                    . '"<div style=\"font-size:13px;color:#666;margin-top:8px;\">Redirecting to login page...</div>";'
                . 'overlay.appendChild(box);document.body.appendChild(overlay);'
                . 'try{sessionStorage.setItem("session_expired_notice","1");sessionStorage.setItem("session_expired_limit",String(timeoutSeconds));sessionStorage.setItem("session_timeout_notice_shown","1");}catch(e){}'
                . 'window.setTimeout(function(){window.location.href=redirectUrl;},1800);'
            . '}'
            . 'window.setTimeout(showNoticeThenRedirect, Math.max(1, remainingSeconds)*1000);'
        . '})();</script>';

        if (!defined('SESSION_TIMEOUT_CLIENT_SCRIPT_INJECTED')) {
            define('SESSION_TIMEOUT_CLIENT_SCRIPT_INJECTED', true);
            ob_start(function ($buffer) use ($clientTimeoutScript) {
                $trimmed = ltrim((string)$buffer);
                if ($trimmed === '') {
                    return $buffer;
                }

                $isHtml = (stripos($trimmed, '<!doctype html') === 0) || (stripos($trimmed, '<html') === 0);
                if (!$isHtml) {
                    return $buffer;
                }

                if (stripos($buffer, '</body>') !== false) {
                    return preg_replace('/<\/body>/i', $clientTimeoutScript . '</body>', $buffer, 1);
                }

                return $buffer . $clientTimeoutScript;
            });
        }
    }

    if ($elapsed > $sessionTimeout) {
        // Prevent immediate silent re-authentication via remember-me after timeout.
        clearAppCookie('remember_me', '/');
        clearAppCookie('remember_me', '/PEAS/');

        session_unset();
        session_destroy();
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['session_expired'] = true;
        $_SESSION['session_timeout_seconds'] = $sessionTimeout;

        $expectsJson =
            (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
            (isset($_SERVER['HTTP_ACCEPT']) && stripos((string)$_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

        if ($expectsJson) {
            header('Content-Type: application/json; charset=UTF-8');
            http_response_code(401);
            echo json_encode([
                'status' => 'session_expired',
                'message' => 'Session limit reached. Please login again.',
                'redirect' => $loginUrl,
                'timeout_seconds' => $sessionTimeout,
            ]);
            exit();
        }

        header('Content-Type: text/html; charset=UTF-8');
        $noticeMessage = 'Session limit reached. You were logged out after ' . $sessionTimeout . ' seconds of inactivity.';
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Session Expired</title>
</head>
<body style="margin:0;font-family:Poppins,Arial,sans-serif;background:#f3f5f7;display:flex;min-height:100vh;align-items:center;justify-content:center;">
    <div style="width:100%;max-width:480px;background:#ffffff;border-radius:12px;padding:22px 20px;box-shadow:0 14px 38px rgba(0,0,0,.18);text-align:center;">
        <div style="font-size:18px;font-weight:700;color:#c62828;margin-bottom:8px;">Session Limit Reached</div>
        <div style="font-size:14px;color:#333;line-height:1.45;"><?php echo htmlspecialchars($noticeMessage, ENT_QUOTES, 'UTF-8'); ?></div>
        <div style="font-size:13px;color:#666;margin-top:8px;">Redirecting to login page...</div>
    </div>
    <script>
        sessionStorage.setItem('session_expired_notice', '1');
        sessionStorage.setItem('session_expired_limit', <?php echo json_encode((string)$sessionTimeout); ?>);
        sessionStorage.setItem('session_timeout_notice_shown', '1');
        window.setTimeout(function () {
            window.location.href = <?php echo json_encode($loginUrl); ?>;
        }, 1800);
    </script>
</body>
</html>
<?php
        exit();
    }
}

// You can add more config files here as needed
// require_once __DIR__ . '/payment.php';
// require_once __DIR__ . '/api.php';
