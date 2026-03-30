<?php
/**
 * Database Configuration
 * Central database connection file - include this instead of duplicating connection code
 * 
 * NOTE: Database migrated from e_checklist to osas_db on March 4, 2026
 * See dev/migrations/migrate_e_checklist_to_osas_db.sql for migration script
 */

// Load environment variables from .env file
require_once __DIR__ . '/../includes/env_loader.php';

// Database credentials - loaded from environment variables
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_NAME', getenv('DB_NAME') ?: 'osas_db');

/**
 * Get database connection
 * @return mysqli Database connection object
 */
function getDBConnection() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    
    // Check connection
    if ($conn->connect_error) {
        // Log error to file instead of exposing to user
        error_log("Database connection failed: " . $conn->connect_error);
        die(json_encode([
            'status' => 'error', 
            'message' => 'Database connection failed. Please try again later.'
        ]));
    }
    
    // Set charset to prevent encoding issues
    $conn->set_charset("utf8mb4");
    
    return $conn;
}

/**
 * Close database connection
 * @param mysqli $conn Database connection object
 */
function closeDBConnection($conn) {
    if ($conn && $conn instanceof mysqli) {
        $conn->close();
    }
}
?>
