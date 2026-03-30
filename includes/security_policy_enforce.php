<?php
/**
 * SECURITY POLICY - ASPLAN_v9
 * 
 * This file enforces security checks on all requests and pages.
 * Include at the top of every sensitive page or handler.
 */

/**
 * Check if user is authenticated
 * Returns: ['authenticated' => bool, 'user_id' => string, 'role' => string]
 */
function checkAuthenticated() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $authenticated = false;
    $userId = null;
    $role = null;

    if (isset($_SESSION['admin_id']) || isset($_SESSION['admin_username'])) {
        $authenticated = true;
        $userId = $_SESSION['admin_id'] ?? $_SESSION['admin_username'];
        $role = 'admin';
    } elseif (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'program_coordinator' && isset($_SESSION['username'])) {
        $authenticated = true;
        $userId = $_SESSION['username'];
        $role = 'program_coordinator';
    } elseif (isset($_SESSION['id'])) {
        $authenticated = true;
        $userId = $_SESSION['id'];
        $role = $_SESSION['user_type'] ?? 'adviser';
    } elseif (isset($_SESSION['student_number'])) {
        $authenticated = true;
        $userId = $_SESSION['student_number'];
        $role = 'student';
    }

    return [
        'authenticated' => $authenticated,
        'user_id' => $userId,
        'role' => $role
    ];
}

/**
 * Require specific role to access page/handler
 * Usage: requireRole('admin'); or requireRole(['admin', 'adviser']);
 */
function requireRole($requiredRoles) {
    if (!is_array($requiredRoles)) {
        $requiredRoles = [$requiredRoles];
    }

    $auth = checkAuthenticated();

    if (!$auth['authenticated']) {
        http_response_code(401);
        die(json_encode(['error' => 'Authentication required']));
    }

    if (!in_array($auth['role'], $requiredRoles)) {
        http_response_code(403);
die(json_encode(['error' => 'Access denied. Required role: ' . implode(', ', $requiredRoles)]));
    }

    return $auth;
}

/**
 * Require admin role
 */
function requireAdmin() {
    return requireRole('admin');
}

/**
 * Require adviser role
 */
function requireAdviser() {
    return requireRole('adviser');
}

/**
 * Require student role
 */
function requireStudent() {
    return requireRole('student');
}

/**
 * Require any authenticated user
 */
function requireAuthenticated() {
    $auth = checkAuthenticated();

    if (!$auth['authenticated']) {
        http_response_code(401);
        die(json_encode(['error' => 'Authentication required']));
    }

    return $auth;
}

/**
 * Validate CSRF token from POST request
 */
function validateCSRFTokenRequired() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return true;
    }

    require_once __DIR__ . '/csrf.php';

    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;

    if (!$token || !validateCSRFToken($token)) {
        http_response_code(403);
        die(json_encode(['error' => 'CSRF validation failed. Invalid or missing security token.']));
    }

    return true;
}

/**
 * Log security event for audit trail
 */
function logSecurityEvent($eventType, $details = [], $severity = 'info') {
    if (function_exists('elsLog')) {
        $auth = checkAuthenticated();
        $context = array_merge([
            'event_type' => $eventType,
            'user_id' => $auth['user_id'],
            'role' => $auth['role'],
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'timestamp' => date('Y-m-d H:i:s')
        ], $details);

        elsLog($severity, "Security Event: $eventType", $context, 'security_audit');
    }
}

/**
 * Security: Block if not HTTPS in production
 */
function enforceHTTPS() {
    $env = getenv('APP_ENV') ?: 'development';
    
    if ($env === 'production' && empty($_SERVER['HTTPS'])) {
        header('HTTP/1.1 403 Forbidden');
        die('HTTPS required in production environment');
    }
}

?>
