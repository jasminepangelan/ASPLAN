<?php
/**
 * SQL File Importer
 * Imports railway-production-2026-07-05.sql to MySQL
 */

// Load configuration
require_once __DIR__ . '/config/database.php';

$sqlFile = __DIR__ . '/backups/railway-production-2026-07-05.sql';

if (!file_exists($sqlFile)) {
    die("Error: SQL file not found at {$sqlFile}\n");
}

echo "Starting SQL import...\n";
echo "Database: " . DB_NAME . "\n";
echo "Host: " . DB_HOST . "\n";
echo "Port: " . DB_PORT . "\n";

try {
    // Connect to MySQL
    $connection = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
    
    if ($connection->connect_error) {
        die("Connection failed: " . $connection->connect_error . "\n");
    }
    
    echo "Connected successfully!\n";
    
    // Read SQL file
    $sql = file_get_contents($sqlFile);
    
    if ($sql === false) {
        die("Error: Could not read SQL file\n");
    }
    
    // Split by multiple statement delimiters and execute
    $statements = preg_split('/;[\r\n]+/', $sql);
    $importedStatements = 0;
    $errors = [];
    
    foreach ($statements as $statement) {
        $statement = trim($statement);
        
        // Skip empty statements and comments
        if (empty($statement) || substr($statement, 0, 2) === '--') {
            continue;
        }
        
        if ($connection->query($statement) === true) {
            $importedStatements++;
            echo ".";
        } else {
            $errors[] = "Error: " . $connection->error . " | Statement: " . substr($statement, 0, 100) . "...\n";
            echo "E";
        }
    }
    
    echo "\n\nImport completed!\n";
    echo "Statements executed: $importedStatements\n";
    
    if (!empty($errors)) {
        echo "\nErrors encountered:\n";
        foreach ($errors as $error) {
            echo $error;
        }
    }
    
    $connection->close();
    
} catch (Exception $e) {
    die("Exception: " . $e->getMessage() . "\n");
}
?>
