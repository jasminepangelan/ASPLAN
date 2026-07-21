<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config/database.php';

$output = "Web Context DB Check\n";
$output .= "DB_HOST: " . DB_HOST . "\n";
$output .= "DB_USER: " . DB_USER . "\n";
$output .= "DB_PASS: " . DB_PASS . "\n";
$output .= "DB_NAME: " . DB_NAME . "\n";
$output .= "DB_PORT: " . DB_PORT . "\n";

$output .= "getenv(DB_HOST): " . getenv('DB_HOST') . "\n";
$output .= "\$_SERVER(DB_HOST): " . ($_SERVER['DB_HOST'] ?? 'NOT SET') . "\n";

try {
    $conn = getDBConnection();
    $output .= "Connection successful!\n";
} catch (Exception $e) {
    $output .= "Exception: " . $e->getMessage() . "\n";
}

file_put_contents('db_check_out.txt', $output);
echo "Done";
