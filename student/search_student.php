<?php
require __DIR__ . '/../config/config.php';
$conn = getDBConnection();

$search = $argv[1] ?? 'Bermundo';

echo "Searching for: $search\n\n";

$query = $conn->query("
    SELECT student_number AS student_id, last_name, first_name, middle_name 
    FROM student_info 
    WHERE last_name LIKE '%$search%' 
    OR first_name LIKE '%$search%' 
    OR middle_name LIKE '%$search%'
    OR student_number LIKE '%$search%'
");

echo "Found students:\n";
while ($row = $query->fetch_assoc()) {
    echo "{$row['student_id']} - {$row['last_name']}, {$row['first_name']} {$row['middle_name']}\n";
}

closeDBConnection($conn);
?>
