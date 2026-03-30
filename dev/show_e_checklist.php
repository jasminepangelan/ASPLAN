<?php
header('Content-Type: text/plain');
$conn = new mysqli('localhost', 'root', '', 'e_checklist');
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

echo "=== e_checklist.students structure ===\n";
$result = $conn->query("DESCRIBE students");
while ($row = $result->fetch_assoc()) {
    echo "{$row['Field']} - {$row['Type']}\n";
}

echo "\n=== Sample data ===\n";
$result = $conn->query("SELECT * FROM students LIMIT 2");
while ($row = $result->fetch_assoc()) {
    print_r($row);
}

echo "\n=== e_checklist.admins structure ===\n";
$result = $conn->query("DESCRIBE admins");
while ($row = $result->fetch_assoc()) {
    echo "{$row['Field']} - {$row['Type']}\n";
}

echo "\n=== Sample admin data ===\n";
$result = $conn->query("SELECT * FROM admins LIMIT 2");
while ($row = $result->fetch_assoc()) {
    print_r($row);
}

echo "\n=== e_checklist.adviser structure ===\n";
$result = $conn->query("DESCRIBE adviser");
while ($row = $result->fetch_assoc()) {
    echo "{$row['Field']} - {$row['Type']}\n";
}

echo "\n=== Sample adviser data ===\n";
$result = $conn->query("SELECT * FROM adviser LIMIT 2");
while ($row = $result->fetch_assoc()) {
    print_r($row);
}

$conn->close();
?>
