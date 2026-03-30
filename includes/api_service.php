<?php
/**
 * API Standardization Service
 * Provides consistent request parsing, validation, and response formatting
 */

require_once __DIR__ . '/../includes/error_logging_service.php';

// HTTP Status Codes
define('API_HTTP_OK', 200);
define('API_HTTP_CREATED', 201);
define('API_HTTP_BAD_REQUEST', 400);
define('API_HTTP_UNAUTHORIZED', 401);
define('API_HTTP_FORBIDDEN', 403);
define('API_HTTP_NOT_FOUND', 404);
define('API_HTTP_CONFLICT', 409);
define('API_HTTP_SERVER_ERROR', 500);

// Response Status Constants
define('API_STATUS_SUCCESS', 'success');
define('API_STATUS_ERROR', 'error');
define('API_STATUS_VALIDATION_ERROR', 'validation_error');

/**
 * Parse API request from POST/JSON
 */
function apiParseRequest(): array
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    $isJSON = (stripos($contentType, 'application/json') !== false);
    $rawBody = file_get_contents('php://input');

    if ($isJSON) {
        $data = json_decode($rawBody, true);
        if ($data === null && $rawBody !== '') {
            return [
                'method' => $method,
                'content_type' => 'json',
                'data' => [],
                'raw' => $rawBody,
                'error' => 'Invalid JSON: ' . json_last_error_msg()
            ];
        }
        return [
            'method' => $method,
            'content_type' => 'json',
            'data' => is_array($data) ? $data : [],
            'raw' => $rawBody,
            'error' => null
        ];
    }
    
    return [
        'method' => $method,
        'content_type' => 'form',
        'data' => array_merge($_POST ?? [], $_GET ?? []),
        'raw' => $rawBody,
        'error' => null
    ];
}

/**
 * Validate request method
 */
function apiValidateMethod(string $allowedMethods): array
{
    $allowed = array_map('trim', explode(',', strtoupper($allowedMethods)));
    $current = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

    if (!in_array($current, $allowed)) {
        return [
            'valid' => false,
            'error' => "Method $current not allowed. Expected: $allowedMethods"
        ];
    }

    return ['valid' => true, 'error' => null];
}

/**
 * Send successful API response
 */
function apiResponseSuccess(
    $data = null,
    string $message = 'Success',
    int $statusCode = API_HTTP_OK,
    array $metadata = []
): void {
    http_response_code($statusCode);
    
    $response = [
        'status' => API_STATUS_SUCCESS,
        'message' => $message,
        'data' => $data
    ];

    if (!empty($metadata)) {
        $response['metadata'] = $metadata;
    }

    $response['request_id'] = apiGetRequestId();

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Send error API response
 */
function apiResponseError(
    string $message,
    int $statusCode = API_HTTP_BAD_REQUEST,
    array $details = [],
    ?string $errorCode = null
): void {
    http_response_code($statusCode);

    $response = [
        'status' => API_STATUS_ERROR,
        'message' => elsSanitizeForUser($message),
        'error_code' => $errorCode
    ];

    if (!empty($details)) {
        $sanitized = [];
        foreach ($details as $key => $value) {
            $sanitized[$key] = is_string($value) ? elsSanitizeForUser($value) : $value;
        }
        $response['details'] = $sanitized;
    }

    $response['request_id'] = apiGetRequestId();

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Send validation error response
 */
function apiResponseValidationError(
    array $errors,
    string $message = 'Validation failed',
    int $statusCode = API_HTTP_BAD_REQUEST
): void {
    http_response_code($statusCode);

    $sanitized_errors = [];
    foreach ($errors as $field => $error) {
        $sanitized_errors[$field] = is_string($error) ? elsSanitizeForUser($error) : $error;
    }

    $response = [
        'status' => API_STATUS_VALIDATION_ERROR,
        'message' => $message,
        'errors' => $sanitized_errors
    ];

    $response['request_id'] = apiGetRequestId();

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Require authentication and return user info
 */
function apiRequireAuth(): array
{
    $userId = null;
    $role = null;

    if (isset($_SESSION['admin_id']) || isset($_SESSION['admin_username'])) {
        $userId = $_SESSION['admin_id'] ?? $_SESSION['admin_username'];
        $role = 'admin';
    } elseif (isset($_SESSION['id'])) {
        $userId = $_SESSION['id'];
        $role = $_SESSION['user_type'] ?? 'adviser';
    } elseif (isset($_SESSION['student_number'])) {
        $userId = $_SESSION['student_number'];
        $role = 'student';
    } elseif (isset($_SESSION['username'])) {
        $userId = $_SESSION['username'];
        $role = $_SESSION['user_type'] ?? 'user';
    }

    if (!$userId) {
        return [
            'authenticated' => false,
            'user_id' => null,
            'role' => null,
            'error' => 'Authentication required'
        ];
    }

    return [
        'authenticated' => true,
        'user_id' => $userId,
        'role' => $role,
        'error' => null
    ];
}

/**
 * Require specific role(s)
 */
function apiRequireRole(string $requiredRoles): array
{
    $authResult = apiRequireAuth();
    
    if (!$authResult['authenticated']) {
        return array_merge($authResult, ['authorized' => false]);
    }

    $allowed = array_map('trim', explode(',', strtolower($requiredRoles)));
    $userRole = strtolower($authResult['role']);

    if (!in_array($userRole, $allowed)) {
        return [
            'authenticated' => true,
            'authorized' => false,
            'user_id' => $authResult['user_id'],
            'role' => $authResult['role'],
            'error' => "Role '$userRole' not authorized. Required: $requiredRoles"
        ];
    }

    return array_merge($authResult, ['authorized' => true]);
}

/**
 * Set standard API CORS headers
 */
function apiSetCORSHeaders(
    string $allowOrigin = '*',
    array $allowMethods = ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
    array $allowHeaders = ['Content-Type', 'X-CSRF-Token', 'X-Request-ID']
): void {
    header('Access-Control-Allow-Origin: ' . $allowOrigin);
    header('Access-Control-Allow-Methods: ' . implode(', ', $allowMethods));
    header('Access-Control-Allow-Headers: ' . implode(', ', $allowHeaders));
    header('Access-Control-Max-Age: 3600');
    header('Access-Control-Allow-Credentials: true');
}

/**
 * Handle preflight OPTIONS request
 */
function apiHandlePreflight(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        apiSetCORSHeaders();
        http_response_code(200);
        exit;
    }
}

/**
 * Get request ID for logging
 */
function apiGetRequestId(): string
{
    $requestId = $_SERVER['HTTP_X_REQUEST_ID'] ?? null;
    
    if ($requestId) {
        return $requestId;
    }
    
    // Try to get from error logging service if available
    if (function_exists('elsGetRequestId')) {
        return call_user_func('elsGetRequestId');
    }
    
    return uniqid('req_', true);
}

/**
 * Log API call with standardized format
 */
function apiLog(string $endpoint, string $level, string $message, array $context = []): void
{
    if (!function_exists('elsLog')) {
        error_log("[$endpoint] [$level] $message " . json_encode($context));
        return;
    }

    $logContext = array_merge([
        'endpoint' => $endpoint,
        'request_id' => apiGetRequestId(),
        'method' => $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN',
        'remote_ip' => $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN'
    ], $context);

    elsLog($level, $message, $logContext, "api_$endpoint");
}
?>
