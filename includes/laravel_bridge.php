<?php

if (!function_exists('laravelBridgeBaseUrl')) {
    /**
     * Resolve the Laravel sidecar base URL from environment or the current app URL.
     */
    function laravelBridgeBaseUrl(): string
    {
        static $baseUrl = null;

        if ($baseUrl !== null) {
            return $baseUrl;
        }

        if (!function_exists('loadEnvFile')) {
            $envLoader = __DIR__ . '/env_loader.php';
            if (is_file($envLoader)) {
                require_once $envLoader;
            }
        }

        $configured = trim((string) (getenv('LARAVEL_BRIDGE_BASE_URL') ?: ''));

        if ($configured === '') {
            $appUrl = trim((string) (getenv('APP_URL') ?: ''));
            if ($appUrl === '' && defined('APP_URL')) {
                $appUrl = (string) APP_URL;
            }

            if ($appUrl === '') {
                $projectFolder = basename(dirname(__DIR__));
                $appUrl = 'http://localhost/' . $projectFolder;
            }

            $configured = rtrim($appUrl, '/') . '/laravel-app/public';
        }

        $baseUrl = rtrim($configured, '/');
        return $baseUrl;
    }
}

if (!function_exists('laravelBridgeUrl')) {
    /**
     * Build a Laravel bridge URL from either a relative API path or a legacy absolute URL.
     */
    function laravelBridgeUrl(string $path = ''): string
    {
        $baseUrl = laravelBridgeBaseUrl();
        $path = trim($path);

        if ($path === '') {
            return $baseUrl;
        }

        if (preg_match('#^https?://#i', $path)) {
            $parsedPath = (string) (parse_url($path, PHP_URL_PATH) ?: '');
            $parsedQuery = (string) (parse_url($path, PHP_URL_QUERY) ?: '');
            $path = $parsedPath;
            if ($parsedQuery !== '') {
                $path .= '?' . $parsedQuery;
            }
        }

        $path = str_replace('\\', '/', $path);

        $publicPos = stripos($path, '/public/');
        if ($publicPos !== false) {
            $path = substr($path, $publicPos + strlen('/public'));
        } else {
            $apiPos = stripos($path, '/api/');
            if ($apiPos !== false) {
                $path = substr($path, $apiPos);
            }
        }

        if ($path === '') {
            return $baseUrl;
        }

        return $baseUrl . '/' . ltrim($path, '/');
    }
}

if (!function_exists('postLaravelJsonBridge')) {
    /**
     * Send a JSON POST request to the Laravel sidecar and decode the JSON response.
     *
     * Returns null when the sidecar is unavailable or the response is not valid JSON.
     */
    function postLaravelJsonBridge(string $url, array $payload, int $timeoutSeconds = 10): ?array
    {
        $url = laravelBridgeUrl($url);
        $payloadJson = json_encode($payload);
        if ($payloadJson === false) {
            return null;
        }

        $response = false;

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payloadJson,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeoutSeconds,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            ]);
            $response = curl_exec($ch);
            curl_close($ch);
        } else {
            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-Type: application/json\r\n",
                    'content' => $payloadJson,
                    'timeout' => $timeoutSeconds,
                ],
            ]);
            $response = @file_get_contents($url, false, $context);
        }

        if (!is_string($response) || $response === '') {
            return null;
        }

        $decoded = json_decode($response, true);
        return is_array($decoded) ? $decoded : null;
    }
}

if (!function_exists('postLaravelMultipartBridge')) {
    /**
     * Send a multipart/form-data POST request to the Laravel sidecar.
     *
     * $fields contains scalar form values.
     * $fileFields contains entries in the form:
     *   [
     *     'picture' => ['path' => '/full/path/file.jpg', 'name' => 'file.jpg', 'mime' => 'image/jpeg']
     *   ]
     */
    function postLaravelMultipartBridge(string $url, array $fields, array $fileFields = [], int $timeoutSeconds = 20): ?array
    {
        if (!function_exists('curl_init')) {
            return null;
        }

        $url = laravelBridgeUrl($url);

        $payload = $fields;

        foreach ($fileFields as $fieldName => $fileInfo) {
            $path = (string) ($fileInfo['path'] ?? '');
            if ($path === '' || !is_file($path)) {
                return null;
            }

            $mime = (string) ($fileInfo['mime'] ?? '');
            if ($mime === '' && function_exists('mime_content_type')) {
                $mime = (string) mime_content_type($path);
            }
            if ($mime === '') {
                $mime = 'application/octet-stream';
            }

            $payload[$fieldName] = new CURLFile(
                $path,
                $mime,
                (string) ($fileInfo['name'] ?? basename($path))
            );
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeoutSeconds,
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        if (!is_string($response) || $response === '') {
            return null;
        }

        $decoded = json_decode($response, true);
        return is_array($decoded) ? $decoded : null;
    }
}

if (!function_exists('postLaravelTextBridge')) {
    /**
     * Send a JSON POST request to the Laravel sidecar and return the raw text response.
     */
    function postLaravelTextBridge(string $url, array $payload, int $timeoutSeconds = 10): ?string
    {
        $url = laravelBridgeUrl($url);
        $payloadJson = json_encode($payload);
        if ($payloadJson === false) {
            return null;
        }

        $response = false;

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payloadJson,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeoutSeconds,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            ]);
            $response = curl_exec($ch);
            curl_close($ch);
        } else {
            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-Type: application/json\r\n",
                    'content' => $payloadJson,
                    'timeout' => $timeoutSeconds,
                ],
            ]);
            $response = @file_get_contents($url, false, $context);
        }

        return is_string($response) ? $response : null;
    }
}
