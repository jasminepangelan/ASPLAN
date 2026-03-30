<?php
/**
 * CSRF Protection Utility
 * Generates and validates CSRF tokens for form security
 */

/**
 * Generate a CSRF token and store it in session
 * @return string The generated CSRF token
 */
function generateCSRFToken() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Generate a random token
    $token = bin2hex(random_bytes(32));
    
    // Store in session
    $_SESSION['csrf_token'] = $token;
    $_SESSION['csrf_token_time'] = time();
    
    return $token;
}

/**
 * Validate a CSRF token
 * @param string $token The token to validate
 * @param int $maxAge Maximum age of token in seconds (default: 3600)
 * @return bool True if valid, false otherwise
 */
function validateCSRFToken($token, $maxAge = 3600) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Check if token exists in session
    if (!isset($_SESSION['csrf_token']) || !isset($_SESSION['csrf_token_time'])) {
        return false;
    }
    
    // Check if token has expired
    if (time() - $_SESSION['csrf_token_time'] > $maxAge) {
        unset($_SESSION['csrf_token']);
        unset($_SESSION['csrf_token_time']);
        return false;
    }
    
    // Validate token
    $valid = hash_equals($_SESSION['csrf_token'], $token);
    
    return $valid;
}

/**
 * Get the current CSRF token (generates one if it doesn't exist)
 * @return string The CSRF token
 */
function getCSRFToken() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    if (!isset($_SESSION['csrf_token'])) {
        return generateCSRFToken();
    }
    
    return $_SESSION['csrf_token'];
}

/**
 * Output a hidden CSRF token input field
 * @return void
 */
function csrfTokenField() {
    $token = getCSRFToken();
    echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '" aria-hidden="true">';
}
