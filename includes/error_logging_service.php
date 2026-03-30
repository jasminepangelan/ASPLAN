<?php
/**
 * Centralized Error Logging and Handling Service
 * Provides structured logging, error categorization, and recovery suggestions
 */

// Log levels
const ELS_DEBUG = 'DEBUG';
const ELS_INFO = 'INFO';
const ELS_WARNING = 'WARNING';
const ELS_ERROR = 'ERROR';
const ELS_CRITICAL = 'CRITICAL';

/**
 * Initialize error logging for the application
 * Call once at application bootstrap
 */
function elsInitialize(): void {
    $logDir = __DIR__ . '/../var/logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }

    // Set custom error handler
    set_error_handler('elsPhpErrorHandler');
    
    // Set custom exception handler
    set_exception_handler('elsExceptionHandler');
    
    // Register shutdown handler for fatal errors
    register_shutdown_function('elsFatalErrorHandler');
}

/**
 * Log a message with structured context
 */
function elsLog(string $level, string $message, array $context = [], string $component = 'app'): void {
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = [
        'timestamp' => $timestamp,
        'level' => $level,
        'component' => $component,
        'message' => $message,
        'context' => $context,
        'request_id' => elsPeekRequestId(),
        'user_id' => elsGetCurrentUserId(),
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
        'request_path' => $_SERVER['REQUEST_URI'] ?? 'unknown',
    ];

    // Write to file
    elsWriteLogFile($level, $logEntry);

    // Also log to PHP error log for critical issues
    if ($level === ELS_CRITICAL || $level === ELS_ERROR) {
        error_log($message . ' | Context: ' . json_encode($context));
    }
}

/**
 * Log debug message (development only)
 */
function elsDebug(string $message, array $context = [], string $component = 'app'): void {
    elsLog(ELS_DEBUG, $message, $context, $component);
}

/**
 * Log info message
 */
function elsInfo(string $message, array $context = [], string $component = 'app'): void {
    elsLog(ELS_INFO, $message, $context, $component);
}

/**
 * Log warning message
 */
function elsWarning(string $message, array $context = [], string $component = 'app'): void {
    elsLog(ELS_WARNING, $message, $context, $component);
}

/**
 * Log error message with optional exception
 */
function elsError(string $message, array $context = [], string $component = 'app', ?Exception $exception = null): void {
    if ($exception) {
        $context['exception'] = [
            'class' => get_class($exception),
            'code' => $exception->getCode(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString()
        ];
    }
    elsLog(ELS_ERROR, $message, $context, $component);
}

/**
 * Log critical error (application may not recover)
 */
function elsCritical(string $message, array $context = [], string $component = 'app', ?Exception $exception = null): void {
    if ($exception) {
        $context['exception'] = [
            'class' => get_class($exception),
            'code' => $exception->getCode(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString()
        ];
    }
    elsLog(ELS_CRITICAL, $message, $context, $component);
}

/**
 * Categorize error and suggest recovery action
 */
function elsCategorizeDatabaseError(string $errorMessage): array {
    $lower = strtolower($errorMessage);

    // Connection errors
    if (strpos($lower, 'connection') !== false || strpos($lower, 'access denied') !== false) {
        return [
            'category' => 'CONNECTION',
            'severity' => ELS_CRITICAL,
            'suggestion' => 'Check database credentials and server availability'
        ];
    }

    // Query syntax errors
    if (strpos($lower, 'syntax error') !== false || strpos($lower, 'sql') !== false) {
        return [
            'category' => 'QUERY_SYNTAX',
            'severity' => ELS_ERROR,
            'suggestion' => 'Review SQL query syntax'
        ];
    }

    // Duplicate key errors
    if (strpos($lower, 'duplicate') !== false || strpos($lower, 'unique') !== false) {
        return [
            'category' => 'CONSTRAINT_VIOLATION',
            'severity' => ELS_WARNING,
            'suggestion' => 'Value already exists in database'
        ];
    }

    // Table/column not found
    if (strpos($lower, 'table') !== false || strpos($lower, 'column') !== false || strpos($lower, 'unknown') !== false) {
        return [
            'category' => 'SCHEMA_MISMATCH',
            'severity' => ELS_ERROR,
            'suggestion' => 'Verify database schema matches application expectations'
        ];
    }

    // Generic database error
    return [
        'category' => 'UNKNOWN',
        'severity' => ELS_ERROR,
        'suggestion' => 'Check error logs for details'
    ];
}

/**
 * Sanitize error message for display to user (remove internal details)
 */
function elsSanitizeForUser(string $message): string {
    // Remove file paths and line numbers that expose internal structure
    $sanitized = preg_replace('#/[a-z0-9./_-]+\.php#i', '[internal]', $message);
    $sanitized = preg_replace('#line [0-9]+#i', '[internal]', $sanitized);
    $sanitized = preg_replace('#stack trace#i', '[internal]', $sanitized);
    
    return $sanitized ?: 'An error occurred. Please contact support.';
}

/**
 * Write log entry to file
 */
function elsWriteLogFile(string $level, array $logEntry): void {
    $logDir = __DIR__ . '/../var/logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }

    $filename = $logDir . '/' . date('Y-m-d') . '.log';
    $logLine = json_encode($logEntry) . "\n";
    
    file_put_contents($filename, $logLine, FILE_APPEND | LOCK_EX);
}

/**
 * PHP error handler callback
 */
function elsPhpErrorHandler($errno, $errstr, $errfile, $errline): bool {
    $level = ELS_WARNING;
    
    if ($errno === E_ERROR || $errno === E_PARSE) {
        $level = ELS_CRITICAL;
    } elseif ($errno === E_WARNING || $errno === E_CORE_WARNING) {
        $level = ELS_WARNING;
    } elseif ($errno === E_NOTICE || $errno === E_DEPRECATED) {
        $level = ELS_INFO;
    }

    elsLog($level, "PHP Error: $errstr", [
        'errno' => $errno,
        'file' => $errfile,
        'line' => $errline
    ], 'php');

    return false;
}

/**
 * Exception handler callback
 */
function elsExceptionHandler(Throwable $exception): void {
    $categorization = elsCategorizeDatabaseError($exception->getMessage());
    
    elsLog($categorization['severity'], $exception->getMessage(), [
        'exception_class' => get_class($exception),
        'exception_code' => $exception->getCode(),
        'category' => $categorization['category'],
        'suggestion' => $categorization['suggestion']
    ], 'exception');

    // Return JSON error to client if appropriate
    if (stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false) {
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => elsSanitizeForUser($exception->getMessage()),
            'error_id' => elsPeekRequestId()
        ]);
    } else {
        http_response_code(500);
        echo '<h1>Application Error</h1>';
        echo '<p>' . elsSanitizeForUser($exception->getMessage()) . '</p>';
        if (php_sapi_name() !== 'cli') {
            echo '<p><small>Error ID: ' . elsPeekRequestId() . '</small></p>';
        }
    }
}

/**
 * Fatal error handler callback
 */
function elsFatalErrorHandler(): void {
    $error = error_get_last();
    if ($error && ($error['type'] === E_ERROR || $error['type'] === E_PARSE)) {
        elsLog(ELS_CRITICAL, 'Fatal PHP Error: ' . $error['message'], [
            'error_type' => $error['type'],
            'file' => $error['file'],
            'line' => $error['line']
        ], 'fatal');
    }
}

/**
 * Generate or retrieve request ID for tracing
 */
function elsGenerateRequestId(): string {
    if (!isset($_SERVER['HTTP_X_REQUEST_ID'])) {
        $_SERVER['HTTP_X_REQUEST_ID'] = bin2hex(random_bytes(16));
    }
    return $_SERVER['HTTP_X_REQUEST_ID'];
}

/**
 * Get current request ID without generating new one
 */
function elsPeekRequestId(): string {
    return $_SERVER['HTTP_X_REQUEST_ID'] ?? 'no-id';
}

/**
 * Get current logged-in user ID
 */
function elsGetCurrentUserId(): ?string {
    if (isset($_SESSION['admin_id'])) {
        return 'admin:' . $_SESSION['admin_id'];
    }
    if (isset($_SESSION['adviser_id'])) {
        return 'adviser:' . $_SESSION['adviser_id'];
    }
    if (isset($_SESSION['student_number'])) {
        return 'student:' . $_SESSION['student_number'];
    }
    return null;
}

/**
 * Log database query for debugging
 */
function elsLogQuery(string $query, array $params = [], float $executionTime = 0.0): void {
    if (getenv('APP_DEBUG') === '1') {
        elsDebug('Database Query', [
            'query' => $query,
            'params' => $params,
            'execution_time_ms' => round($executionTime * 1000, 2)
        ], 'database');
    }
}

/**
 * Get log entries for a specific date range
 */
function elsGetLogs(string $startDate = '', string $endDate = '', string $level = '', int $limit = 100): array {
    $logDir = __DIR__ . '/../var/logs';
    if (!is_dir($logDir)) {
        return [];
    }

    $logs = [];
    $startDate = $startDate ?: date('Y-m-d', strtotime('-7 days'));
    $endDate = $endDate ?: date('Y-m-d');

    // Parse date range and read matching log files
    $currentDate = strtotime($startDate);
    $endTimestamp = strtotime($endDate);

    while ($currentDate <= $endTimestamp) {
        $filename = $logDir . '/' . date('Y-m-d', $currentDate) . '.log';
        
        if (file_exists($filename)) {
            $lines = file($filename, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            
            foreach ($lines as $line) {
                $entry = json_decode($line, true);
                
                if ($entry && (empty($level) || $entry['level'] === $level)) {
                    $logs[] = $entry;
                }
            }
        }

        $currentDate = strtotime('+1 day', $currentDate);
    }

    // Sort by timestamp descending and limit
    usort($logs, function($a, $b) {
        return strtotime($b['timestamp']) - strtotime($a['timestamp']);
    });

    return array_slice($logs, 0, $limit);
}

/**
 * Clear old log files (older than specified days)
 */
function elsPruneOldLogs(int $daysToKeep = 30): int {
    $logDir = __DIR__ . '/../var/logs';
    if (!is_dir($logDir)) {
        return 0;
    }

    $cutoffTime = time() - ($daysToKeep * 86400);
    $deleted = 0;

    foreach (glob($logDir . '/*.log') as $file) {
        if (filemtime($file) < $cutoffTime) {
            unlink($file);
            $deleted++;
        }
    }

    return $deleted;
}
?>
