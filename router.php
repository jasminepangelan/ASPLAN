<?php

require_once __DIR__ . '/config/app.php';
emitAppSecurityHeaders();

$requestUri = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$requestPath = rawurldecode($requestUri);

if ($requestPath === '') {
    $requestPath = '/';
}

$normalizedPath = '/' . ltrim(str_replace('\\', '/', $requestPath), '/');

$routeAliases = [
    '/api/check_student_id.php' => __DIR__ . '/auth/check_student_id.php',
];

if (isset($routeAliases[$normalizedPath]) && is_file($routeAliases[$normalizedPath])) {
    $previousCwd = getcwd();
    $aliasTarget = $routeAliases[$normalizedPath];
    $_SERVER['SCRIPT_FILENAME'] = $aliasTarget;
    $_SERVER['SCRIPT_NAME'] = $normalizedPath;
    $_SERVER['PHP_SELF'] = $normalizedPath;
    chdir(dirname($aliasTarget));
    require $aliasTarget;
    if ($previousCwd !== false) {
        chdir($previousCwd);
    }
    return;
}

// Block direct access to sensitive directories/files that Apache previously handled in .htaccess.
$denyPatterns = [
    '#^/(dev|config|includes|var)(/|$)#i',
    '#^/\.env.*$#i',
    '#\.(sql|bak|backup|old)$#i',
    '#\.(git|svn)$#i',
    '#^/uploads/.*\.(php|phtml|php3|php4|php5|phar|phps|pht|phtm|asp|aspx|jsp|sh|bash|exe|bat|cmd|com)$#i',
    '#/(test_|debug_|raw_|check_db|check_schema|check_case|check_duplicates)[^/]*\.php$#i',
];

foreach ($denyPatterns as $pattern) {
    if (preg_match($pattern, $normalizedPath)) {
        http_response_code(403);
        echo '403 Forbidden';
        return;
    }
}

$documentRoot = __DIR__;
$targetPath = $documentRoot . $normalizedPath;
$resolvedViaAlternateStorage = false;

if (!is_file($targetPath) && str_starts_with($normalizedPath, '/uploads/')) {
    $relativeUploadPath = ltrim(substr($normalizedPath, strlen('/uploads')), '/\\');
    if ($relativeUploadPath !== '') {
        $candidateDirs = function_exists('resolveUploadStorageCandidates')
            ? resolveUploadStorageCandidates()
            : [defined('UPLOAD_DIR') ? (string) UPLOAD_DIR : (__DIR__ . '/uploads/')];

        foreach ($candidateDirs as $uploadStorageDir) {
            $alternateUploadPath = rtrim((string) $uploadStorageDir, "/\\") . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativeUploadPath);
            if (is_file($alternateUploadPath)) {
                $targetPath = $alternateUploadPath;
                $resolvedViaAlternateStorage = true;
                break;
            }
        }
    }
}

if (is_dir($targetPath)) {
    foreach (['index.php', 'index.html'] as $indexFile) {
        $indexPath = rtrim($targetPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $indexFile;
        if (is_file($indexPath)) {
            if (str_ends_with($indexFile, '.php')) {
                require $indexPath;
                return;
            }

            header('Content-Type: text/html; charset=UTF-8');
            readfile($indexPath);
            return;
        }
    }
}

if (is_file($targetPath)) {
    if (strtolower((string) pathinfo($targetPath, PATHINFO_EXTENSION)) === 'html') {
        header('Content-Type: text/html; charset=UTF-8');
        readfile($targetPath);
        return;
    }

    if (strtolower((string) pathinfo($targetPath, PATHINFO_EXTENSION)) === 'php') {
        $previousCwd = getcwd();
        $_SERVER['SCRIPT_FILENAME'] = $targetPath;
        $_SERVER['SCRIPT_NAME'] = $normalizedPath;
        $_SERVER['PHP_SELF'] = $normalizedPath;
        chdir(dirname($targetPath));
        require $targetPath;
        if ($previousCwd !== false) {
            chdir($previousCwd);
        }
        return;
    }

    if ($resolvedViaAlternateStorage) {
        $mimeType = function_exists('mime_content_type') ? @mime_content_type($targetPath) : false;
        if (!$mimeType) {
            $mimeType = 'application/octet-stream';
        }

        header('Content-Type: ' . $mimeType);
        header('Content-Length: ' . (string) filesize($targetPath));
        header('Cache-Control: public, max-age=86400');
        readfile($targetPath);
        return;
    }

    return false;
}

http_response_code(404);
echo '404 Not Found';
