<?php
$_ENV['MYSQL_PUBLIC_URL'] = 'mysql://root:PIlezyGzBauvijKewcPUtNqUtETTNcfP@hayabusa.proxy.rlwy.net:58143/railway';
require_once 'config/database.php';
$conn = getDBConnection();
$res = $conn->query("SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='railway' AND COLUMN_NAME IN ('student_id', 'student_number')");
while ($row = $res->fetch_assoc()) {
    echo $row['TABLE_NAME'] . '.' . $row['COLUMN_NAME'] . "\n";
}
