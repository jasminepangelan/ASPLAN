<?php
/**
 * Security Hardening Service
 * Rate limiting, CSRF protection, session security, throttling
 */

require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/rate_limit.php';
require_once __DIR__ . '/error_logging_service.php';

define('SSEC_SESSION_MAX_AGE', 1800);
define('SSEC_CSRF_ROTATION_INTERVAL', 900);
define('SSEC_SUSPICIOUS_THRESHOLD', 10);
define('SSEC_THROTTLE_BURST', 3);

/**
 * Initialize security for this request
 */
function ssecInitialize(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!isset($_SESSION['_ssec_initialized'])) {
        $_SESSION['_ssec_initialized'] = true;
        $_SESSION['_ssec_activity_log'] = [];
        $_SESSION['_ssec_csrf_tokens'] = [];
        $_SESSION['_ssec_created'] = time();
        $_SESSION['_ssec_last_activity'] = time();
    }

    ssecValidateSessionAge();
    ssecRotateCSRFTokenIfNeeded();
}

/**
 * Validate session hasn't exceeded idle timeout
 */
function ssecValidateSessionAge(): bool
{
    if (!isset($_SESSION['_ssec_created'])) {
        $_SESSION['_ssec_created'] = time();
        $_SESSION['_ssec_last_activity'] = time();
        return true;
    }

    $created = (int)$_SESSION['_ssec_created'];
    $lastActivity = (int)$_SESSION['_ssec_last_activity'];
    $now = time();

    // Absolute timeout: 1 hour
    if ($now - $created > 3600) {
        elsWarning('Session expired (absolute timeout)', [
            'user_id' => $_SESSION['student_number'] ?? $_SESSION['id'] ?? 'unknown'
        ]);
        session_destroy();
        return false;
    }

    // Idle timeout
    if ($now - $lastActivity > SSEC_SESSION_MAX_AGE) {
        elsWarning('Session expired (idle timeout)', [
            'user_id' => $_SESSION['student_number'] ?? $_SESSION['id'] ?? 'unknown'
        ]);
        session_destroy();
        return false;
    }

    $_SESSION['_ssec_last_activity'] = $now;
    return true;
}

/**
 * Rotate CSRF token periodically
 */
function ssecRotateCSRFTokenIfNeeded(): void
{
    if (!isset($_SESSION['_ssec_csrf_rotation_time'])) {
        $_SESSION['_ssec_csrf_rotation_time'] = time();
        if (!isset($_SESSION['csrf_token'])) {
            generateCSRFToken();
        }
        return;
    }

    $lastRotation = (int)$_SESSION['_ssec_csrf_rotation_time'];
    $now = time();

    if ($now - $lastRotation > SSEC_CSRF_ROTATION_INTERVAL) {
        if (isset($_SESSION['csrf_token'])) {
            $_SESSION['_ssec_csrf_tokens'][] = $_SESSION['csrf_token'];
        }

        generateCSRFToken();
        $_SESSION['_ssec_csrf_rotation_time'] = $now;

        if (count($_SESSION['_ssec_csrf_tokens']) > 2) {
            array_shift($_SESSION['_ssec_csrf_tokens']);
        }

        elsInfo('CSRF token rotated');
    }
}

/**
 * Validate CSRF token with rotation support
 */
function ssecValidateCSRFToken(string $token, int $maxAge = 3600): bool
{
    if (empty($token)) {
        return false;
    }

    // Check current token
    if (isset($_SESSION['csrf_token'])) {
        if (hash_equals($_SESSION['csrf_token'], $token)) {
            if (!isset($_SESSION['csrf_token_time']) || time() - $_SESSION['csrf_token_time'] <= $maxAge) {
                return true;
            }
        }
    }

    // Check rotated tokens
    if (isset($_SESSION['_ssec_csrf_tokens'])) {
        foreach ($_SESSION['_ssec_csrf_tokens'] as $oldToken) {
            if ($oldToken && hash_equals($oldToken, $token)) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Check endpoint throttling
 */
function ssecCheckEndpointThrottle(string $endpoint, int $maxRequests = 100, int $windowSeconds = 60): array
{
    if (!isset($_SESSION['_ssec_throttle_endpoints'])) {
        $_SESSION['_ssec_throttle_endpoints'] = [];
    }

    $key = "ep_$endpoint";
    $now = time();

    if (!isset($_SESSION['_ssec_throttle_endpoints'][$key])) {
        $_SESSION['_ssec_throttle_endpoints'][$key] = ['requests' => [], 'reset' => $now];
    }

    $data = &$_SESSION['_ssec_throttle_endpoints'][$key];

    if ($now - $data['reset'] > $windowSeconds) {
        $data['requests'] = [];
        $data['reset'] = $now;
    }

    $count = count($data['requests']);
    $canBurst = $count <= $maxRequests + SSEC_THROTTLE_BURST;
    
    $data['requests'][] = $now;

    return [
        'throttled' => !$canBurst,
        'requests' => $count,
        'limit' => $maxRequests,
        'retry_after' => !$canBurst ? $windowSeconds - ($now - $data['reset']) : 0
    ];
}

/**
 * Check user throttling
 */
function ssecCheckUserThrottle(string $userId, int $maxRequests = 500, int $windowSeconds = 300): array
{
    if (empty($userId)) {
        return ['throttled' => false, 'requests' => 0, 'limit' => $maxRequests, 'retry_after' => 0];
    }

    if (!isset($_SESSION['_ssec_throttle_users'])) {
        $_SESSION['_ssec_throttle_users'] = [];
    }

    $key = "usr_$userId";
    $now = time();

    if (!isset($_SESSION['_ssec_throttle_users'][$key])) {
        $_SESSION['_ssec_throttle_users'][$key] = ['requests' => [], 'reset' => $now];
    }

    $data = &$_SESSION['_ssec_throttle_users'][$key];

    if ($now - $data['reset'] > $windowSeconds) {
        $data['requests'] = [];
        $data['reset'] = $now;
    }

    $count = count($data['requests']);
    $canBurst = $count <= $maxRequests + SSEC_THROTTLE_BURST;
    
    $data['requests'][] = $now;

    return [
        'throttled' => !$canBurst,
        'requests' => $count,
        'limit' => $maxRequests,
        'retry_after' => !$canBurst ? $windowSeconds - ($now - $data['reset']) : 0
    ];
}

/**
 * Log suspicious activity
 */
function ssecLogActivity(string $type, array $context = []): void
{
    if (!isset($_SESSION['_ssec_activity_log'])) {
        $_SESSION['_ssec_activity_log'] = [];
    }

    $_SESSION['_ssec_activity_log'][] = ['type' => $type, 'time' => time(), 'ctx' => $context];

    if (count($_SESSION['_ssec_activity_log']) > 100) {
        array_shift($_SESSION['_ssec_activity_log']);
    }
}

/**
 * Get activity report
 */
function ssecGetActivityReport(int $windowSeconds = 600): array
{
    if (!isset($_SESSION['_ssec_activity_log'])) {
        return [];
    }

    $now = time();
    $report = [];

    foreach ($_SESSION['_ssec_activity_log'] as $activity) {
        if ($now - $activity['time'] < $windowSeconds) {
            $type = $activity['type'];
            $report[$type] = ($report[$type] ?? 0) + 1;
        }
    }

    return $report;
}

/**
 * Verify request origin
 */
function ssecVerifyRequestOrigin(string $allowedDomain = null): bool
{
    if (!$allowedDomain) {
        $allowedDomain = $_SERVER['HTTP_HOST'] ?? 'localhost';
    }

    $origin = $_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '';

    if (empty($origin)) {
        return false;
    }

    $originHost = parse_url($origin, PHP_URL_HOST) ?: '';
    return $originHost === $allowedDomain;
}

/**
 * Comprehensive security check
 */
function ssecPerformSecurityChecks(array $options = []): array
{
    $opts = array_merge([
        'check_session' => true,
        'check_csrf' => null,
        'check_endpoint_throttle' => null,
        'check_user_throttle' => null,
        'check_origin' => false,
        'log_activity' => true
    ], $options);

    $result = ['passed' => true, 'errors' => []];

    // Session check
    if ($opts['check_session'] && !ssecValidateSessionAge()) {
        $result['passed'] = false;
        $result['errors'][] = 'Session validation failed';
    }

    // CSRF check
    if ($opts['check_csrf'] && !ssecValidateCSRFToken($opts['check_csrf'])) {
        $result['passed'] = false;
        $result['errors'][] = 'CSRF validation failed';
        if ($opts['log_activity']) ssecLogActivity('csrf_fail', []);
    }

    // Endpoint throttle check
    if ($opts['check_endpoint_throttle']) {
        $throttle = ssecCheckEndpointThrottle($opts['check_endpoint_throttle']);
        if ($throttle['throttled']) {
            $result['passed'] = false;
            $result['errors'][] = 'Endpoint throttled';
            if ($opts['log_activity']) ssecLogActivity('throttle_endpoint', []);
        }
    }

    // User throttle check
    if ($opts['check_user_throttle']) {
        $throttle = ssecCheckUserThrottle($opts['check_user_throttle']);
        if ($throttle['throttled']) {
            $result['passed'] = false;
            $result['errors'][] = 'User throttled';
            if ($opts['log_activity']) ssecLogActivity('throttle_user', []);
        }
    }

    // Origin check
    if ($opts['check_origin'] && !ssecVerifyRequestOrigin()) {
        $result['passed'] = false;
        $result['errors'][] = 'Origin verification failed';
    }

    return $result;
}

/**
 * Get current security status
 */
function ssecGetStatus(): array
{
    return [
        'session_age' => $_SESSION['_ssec_created'] ? time() - $_SESSION['_ssec_created'] : 0,
        'csrf_present' => isset($_SESSION['csrf_token']),
        'activities_suspicious' => count(array_filter(ssecGetActivityReport(), function($v) { return $v > 5; })) > 0
    ];
}
?>
