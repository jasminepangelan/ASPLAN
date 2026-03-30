<?php
/**
 * Application Configuration
 * General application settings and constants
 */

require_once __DIR__ . '/../includes/env_loader.php';

if (!function_exists('resolveLegacyAppUrl')) {
    function resolveLegacyAppUrl() {
        $configuredUrl = trim((string) (getenv('APP_URL') ?: ''));
        if ($configuredUrl !== '') {
            return rtrim($configuredUrl, '/');
        }

        $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
        if ($host !== '') {
            $scheme = 'http';
            if (
                (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') ||
                (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') ||
                ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443)
            ) {
                $scheme = 'https';
            }

            $projectFolder = basename(dirname(__DIR__));
            $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
            $basePath = '';
            $marker = '/' . $projectFolder;
            $markerPos = strpos($scriptName, $marker);
            if ($markerPos !== false) {
                $basePath = substr($scriptName, 0, $markerPos + strlen($marker));
            }

            return $scheme . '://' . $host . rtrim($basePath, '/');
        }

        return 'http://localhost/' . basename(dirname(__DIR__));
    }
}

// Application Settings
define('APP_NAME', 'PEAS - Pre-Enrollment Assessment System');
define('APP_VERSION', '1.0.0');
define('APP_URL', resolveLegacyAppUrl());

// Session Configuration
define('SESSION_TIMEOUT', 3600); // 1 hour in seconds

// File Upload Settings
define('UPLOAD_DIR', __DIR__ . '/../uploads/');
define('MAX_FILE_SIZE', 5242880); // 5MB in bytes
define('ALLOWED_IMAGE_TYPES', ['jpg', 'jpeg', 'png', 'gif']);

// Password Settings
define('MIN_PASSWORD_LENGTH', 8);
define('PASSWORD_RESET_EXPIRY', 600); // 10 minutes in seconds

// Pagination
define('RECORDS_PER_PAGE', 20);

// Error reporting defaults to off; set APP_DEBUG=true to enable locally.
define('DEBUG_MODE', filter_var(getenv('APP_DEBUG') ?: '0', FILTER_VALIDATE_BOOLEAN));

if (DEBUG_MODE) {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    error_reporting(0);
}

// Timezone
date_default_timezone_set('Asia/Manila');
?>
