<?php
/**
 * Environment Variable Loader
 * Loads .env file from project root and sets environment variables
 * 
 * Usage: require_once __DIR__ . '/../includes/env_loader.php';
 */

function loadEnvFile() {
    $env_file = __DIR__ . '/../.env';
    
    // Only load if file exists
    if (!file_exists($env_file)) {
        return false;
    }
    
    // Read the file line by line
    $lines = file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    
    foreach ($lines as $line) {
        // Skip comments
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        
        // Parse KEY=VALUE pairs
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            
            // Remove quotes if present
            if ((strpos($value, '"') === 0 && strrpos($value, '"') === strlen($value) - 1) ||
                (strpos($value, "'") === 0 && strrpos($value, "'") === strlen($value) - 1)) {
                $value = substr($value, 1, -1);
            }
            
            // Set environment variable
            putenv("$key=$value");
            $_ENV[$key] = $value;
        }
    }
    
    return true;
}

// Auto-load .env on include
loadEnvFile();
?>
