<?php
// Database import script for Railway

$host = 'thomas.proxy.rlwy.net';
$port = 45044;
$user = 'root';
$password = 'qMsZwbiIngMfINmKygVSbMIiqJfdoTst';
$database = 'railway';
$sqlFile = __DIR__ . '/backups/railway-production-2026-07-05.sql';

// Create connection
$mysqli = new mysqli();
$mysqli->options(MYSQLI_OPT_CONNECT_TIMEOUT, 10);
$mysqli->real_connect($host, $user, $password, $database, $port);

if ($mysqli->connect_error) {
    die("Connection failed: " . $mysqli->connect_error);
}

echo "Connected successfully to Railway MySQL\n";

// Read SQL file
if (!file_exists($sqlFile)) {
    die("SQL file not found: $sqlFile\n");
}

$sql = file_get_contents($sqlFile);

echo "SQL file loaded. Size: " . strlen($sql) . " bytes\n";
echo "Starting import...\n";

// Split by semicolon and execute each statement
$statements = array_filter(array_map('trim', explode(';', $sql)), function($s) {
    return !empty($s) && strpos(trim($s), '--') !== 0;
});

$count = 0;
foreach ($statements as $statement) {
    if (trim($statement) !== '') {
        if (!$mysqli->query($statement)) {
            echo "Error executing statement: " . $mysqli->error . "\n";
            echo "Statement: " . substr($statement, 0, 100) . "...\n";
        } else {
            $count++;
            if ($count % 10 == 0) {
                echo "Executed $count statements...\n";
            }
        }
    }
}

echo "Import completed! Total statements executed: $count\n";
$mysqli->close();
?>
