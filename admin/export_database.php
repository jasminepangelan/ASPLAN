<?php
session_start();

// Check if admin is logged in
if (!isset($_SESSION['admin_id']) || empty($_SESSION['admin_id'])) {
    header('HTTP/1.1 403 Forbidden');
    echo '<div style="color:red; text-align:center; font-size:1.2em; margin-top:40px;">Access denied. Please log in as admin.</div>';
    exit();
}

require_once __DIR__ . '/../config/database.php';
$conn = getDBConnection();

$tables = [];
$result = $conn->query("SHOW TABLES");

if ($result) {
    // Both mysqli_result and LegacyDbResult implement fetch_assoc
    while ($row = $result->fetch_assoc()) {
        $tables[] = array_values($row)[0];
    }
}

$sql = "-- ASPLAN Database Export\n";
$sql .= "-- Generated: " . date('Y-m-d H:i:s') . "\n\n";
$sql .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

foreach ($tables as $table) {
    $sql .= "-- Table structure for `$table`\n";
    $sql .= "DROP TABLE IF EXISTS `$table`;\n";
    
    $createResult = $conn->query("SHOW CREATE TABLE `$table`");
    if ($createResult) {
        $createRow = $createResult->fetch_assoc();
        $sql .= $createRow['Create Table'] . ";\n\n";
    }

    $sql .= "-- Dumping data for table `$table`\n";
    $rowsResult = $conn->query("SELECT * FROM `$table`");
    
    if ($rowsResult && $rowsResult->num_rows > 0) {
        while ($row = $rowsResult->fetch_assoc()) {
            $fields = [];
            foreach ($row as $val) {
                if ($val === null) {
                    $fields[] = 'NULL';
                } else {
                    if (method_exists($conn, 'real_escape_string')) {
                        $fields[] = "'" . $conn->real_escape_string($val) . "'";
                    } else {
                        // Fallback escape
                        $search = array("\\",  "\x00", "\n",  "\r",  "'",  '"', "\x1a");
                        $replace = array("\\\\","\\0","\\n", "\\r", "\'", '\"', "\\Z");
                        $fields[] = "'" . str_replace($search, $replace, $val) . "'";
                    }
                }
            }
            $sql .= "INSERT INTO `$table` VALUES (" . implode(", ", $fields) . ");\n";
        }
    }
    $sql .= "\n\n";
}

$sql .= "SET FOREIGN_KEY_CHECKS=1;\n";

// Close connection if applicable
if (function_exists('closeDBConnection')) {
    closeDBConnection($conn);
}

// Output headers to trigger download
header('Content-Type: application/sql');
header('Content-Disposition: attachment; filename="asplan_backup_' . date('Y-m-d_H-i-s') . '.sql"');
header('Content-Length: ' . strlen($sql));

echo $sql;
exit();
