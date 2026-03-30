<?php
/**
 * Rate Limiting Utility
 * Prevents brute force attacks by limiting request frequency
 */

/**
 * Check if an IP address has exceeded rate limits
 * @param string $action The action being rate limited (e.g., 'login', 'forgot_password')
 * @param int $maxAttempts Maximum attempts allowed
 * @param int $windowSeconds Time window in seconds
 * @return array ['allowed' => bool, 'remaining' => int, 'retry_after' => int]
 */
function getRateLimitDefaults($action) {
    $defaults = [
        'login' => ['attempts' => 5, 'window' => 300],
        'forgot_password' => ['attempts' => 3, 'window' => 600],
    ];

    return $defaults[$action] ?? ['attempts' => 5, 'window' => 300];
}

function getRateLimitConfig($action, $maxAttempts = null, $windowSeconds = null) {
    $defaults = getRateLimitDefaults($action);
    $baseAttempts = $maxAttempts !== null ? (int)$maxAttempts : (int)$defaults['attempts'];
    $baseWindow = $windowSeconds !== null ? (int)$windowSeconds : (int)$defaults['window'];

    if (function_exists('getSystemSettingInt')) {
        $attempts = getSystemSettingInt("rate_limit_{$action}_max_attempts", $baseAttempts, 1, 100);
        $window = getSystemSettingInt("rate_limit_{$action}_window_seconds", $baseWindow, 30, 86400);
    } else {
        $attempts = $baseAttempts;
        $window = $baseWindow;
    }

    return ['attempts' => $attempts, 'window' => $window];
}

function checkRateLimit($action, $maxAttempts = null, $windowSeconds = null) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $config = getRateLimitConfig($action, $maxAttempts, $windowSeconds);
    $maxAttempts = $config['attempts'];
    $windowSeconds = $config['window'];
    
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $key = "rate_limit_{$action}_{$ip}";
    
    // Initialize rate limit data
    if (!isset($_SESSION[$key])) {
        $_SESSION[$key] = [
            'attempts' => 0,
            'first_attempt' => time(),
            'last_attempt' => time()
        ];
    }
    
    $data = $_SESSION[$key];
    $now = time();
    
    // Reset if window has expired
    if ($now - $data['first_attempt'] > $windowSeconds) {
        $_SESSION[$key] = [
            'attempts' => 0,
            'first_attempt' => $now,
            'last_attempt' => $now
        ];
        $data = $_SESSION[$key];
    }
    
    // Check if rate limit exceeded
    if ($data['attempts'] >= $maxAttempts) {
        $retryAfter = $windowSeconds - ($now - $data['first_attempt']);
        return [
            'allowed' => false,
            'remaining' => 0,
            'retry_after' => max(0, $retryAfter),
            'message' => "Too many attempts. Please try again in " . ceil($retryAfter / 60) . " minutes."
        ];
    }
    
    return [
        'allowed' => true,
        'remaining' => $maxAttempts - $data['attempts'],
        'retry_after' => 0,
        'message' => ''
    ];
}

/**
 * Record an attempt for rate limiting
 * @param string $action The action being rate limited
 * @return void
 */
function recordAttempt($action) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $key = "rate_limit_{$action}_{$ip}";
    
    if (!isset($_SESSION[$key])) {
        $_SESSION[$key] = [
            'attempts' => 0,
            'first_attempt' => time(),
            'last_attempt' => time()
        ];
    }
    
    $_SESSION[$key]['attempts']++;
    $_SESSION[$key]['last_attempt'] = time();
}

/**
 * Reset rate limit for an action
 * @param string $action The action to reset
 * @return void
 */
function resetRateLimit($action) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $key = "rate_limit_{$action}_{$ip}";
    
    unset($_SESSION[$key]);
}

/**
 * Database-based rate limiting (for production use)
 * This version stores rate limit data in the database instead of sessions
 */
function checkRateLimitDB($conn, $action, $maxAttempts = 5, $windowSeconds = 300) {
    $config = getRateLimitConfig($action, $maxAttempts, $windowSeconds);
    $maxAttempts = $config['attempts'];
    $windowSeconds = $config['window'];

    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    
    // Create table if it doesn't exist
    $createTableQuery = "CREATE TABLE IF NOT EXISTS rate_limits (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ip_address VARCHAR(45) NOT NULL,
        action VARCHAR(50) NOT NULL,
        attempts INT DEFAULT 0,
        first_attempt DATETIME NOT NULL,
        last_attempt DATETIME NOT NULL,
        INDEX idx_ip_action (ip_address, action),
        INDEX idx_last_attempt (last_attempt)
    )";
    $conn->query($createTableQuery);
    
    $now = date('Y-m-d H:i:s');
    $windowStart = date('Y-m-d H:i:s', time() - $windowSeconds);
    
    // Get current attempts
    $stmt = $conn->prepare("SELECT attempts, first_attempt FROM rate_limits WHERE ip_address = ? AND action = ? AND first_attempt > ?");
    $stmt->bind_param("sss", $ip, $action, $windowStart);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $attempts = $row['attempts'];
        $firstAttempt = strtotime($row['first_attempt']);
        
        if ($attempts >= $maxAttempts) {
            $retryAfter = $windowSeconds - (time() - $firstAttempt);
            return [
                'allowed' => false,
                'remaining' => 0,
                'retry_after' => max(0, $retryAfter),
                'message' => "Too many attempts. Please try again in " . ceil($retryAfter / 60) . " minutes."
            ];
        }
    }
    
    return [
        'allowed' => true,
        'remaining' => $maxAttempts - ($result->num_rows > 0 ? $attempts : 0),
        'retry_after' => 0,
        'message' => ''
    ];
}

/**
 * Record attempt in database
 */
function recordAttemptDB($conn, $action) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $now = date('Y-m-d H:i:s');
    $config = getRateLimitConfig($action);
    $windowStart = date('Y-m-d H:i:s', time() - (int)$config['window']);
    
    // Check if record exists
    $stmt = $conn->prepare("SELECT id, attempts FROM rate_limits WHERE ip_address = ? AND action = ? AND first_attempt > ?");
    $stmt->bind_param("sss", $ip, $action, $windowStart);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        // Update existing record
        $row = $result->fetch_assoc();
        $updateStmt = $conn->prepare("UPDATE rate_limits SET attempts = attempts + 1, last_attempt = ? WHERE id = ?");
        $updateStmt->bind_param("si", $now, $row['id']);
        $updateStmt->execute();
    } else {
        // Insert new record
        $insertStmt = $conn->prepare("INSERT INTO rate_limits (ip_address, action, attempts, first_attempt, last_attempt) VALUES (?, ?, 1, ?, ?)");
        $insertStmt->bind_param("ssss", $ip, $action, $now, $now);
        $insertStmt->execute();
    }
    
    // Clean up old records (older than 1 hour)
    $cleanupTime = date('Y-m-d H:i:s', time() - 3600);
    $conn->query("DELETE FROM rate_limits WHERE last_attempt < '$cleanupTime'");
}
