<?php
$_ENV['MYSQL_PUBLIC_URL'] = 'mysql://root:PIlezyGzBauvijKewcPUtNqUtETTNcfP@hayabusa.proxy.rlwy.net:58143/railway';
require_once 'config/database.php';

try {
    echo "Connecting to DB...\n";
    $conn = getDBConnection();
    echo "Connected.\n";
    
    $file = "osas_db_07_28_26_part2_ignore.sql";
    echo "Reading $file...\n";
    
    $content = file_get_contents($file);
    echo "Read " . strlen($content) . " bytes.\n";
    
    // The mysqli multi_query can execute the whole file!
    // But since we are using PDO fallback sometimes, it's better to split by statement
    // Or just use the underlying PDO directly
    if ($conn instanceof mysqli) {
        echo "Using MySQLi multi_query...\n";
        if ($conn->multi_query($content)) {
            do {
                if ($res = $conn->store_result()) {
                    $res->free();
                }
            } while ($conn->more_results() && $conn->next_result());
            echo "Successfully executed multi_query.\n";
        } else {
            echo "Error executing multi_query: " . $conn->error . "\n";
        }
    } else {
        echo "Using PDO...\n";
        $pdo = $conn->getPdo();
        $pdo->exec($content);
        echo "Successfully executed PDO exec.\n";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
