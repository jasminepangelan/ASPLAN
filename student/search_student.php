<?php
require __DIR__ . '/../config/config.php';
$conn = getDBConnection();

$search = $argv[1] ?? 'Bermundo';

echo "Searching for: $search\n\n";

$stmt = $conn->prepare("
    SELECT student_number AS student_id, last_name, first_name, middle_name 
    FROM student_info 
    WHERE last_name LIKE ? 
    OR first_name LIKE ? 
    OR middle_name LIKE ?
    OR student_number LIKE ?
");

if (!$stmt) {
    fwrite(STDERR, "Failed to prepare search query.\n");
    closeDBConnection($conn);
    exit(1);
}

$searchParam = '%' . $search . '%';
$stmt->bind_param('ssss', $searchParam, $searchParam, $searchParam, $searchParam);
$stmt->execute();
$query = $stmt->get_result();

echo "Found students:\n";
while ($row = $query->fetch_assoc()) {
    echo "{$row['student_id']} - {$row['last_name']}, {$row['first_name']} {$row['middle_name']}\n";
}

$stmt->close();
closeDBConnection($conn);
?>
