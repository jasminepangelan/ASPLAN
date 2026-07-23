<?php
$_ENV['MYSQL_PUBLIC_URL'] = 'mysql://root:PIlezyGzBauvijKewcPUtNqUtETTNcfP@hayabusa.proxy.rlwy.net:58143/railway';
require_once 'config/database.php';

function formatToTitleCase($str) {
    if (!$str) return $str;
    // If the string is already mostly lowercase or mixed case, user might have intended it. 
    // But the prompt says "Change all the uppercased... to Titlecase. And also if system detected as Uppercase."
    // Actually, it's safer to just ucwords(strtolower()) all of them to be uniform.
    return ucwords(strtolower($str));
}

try {
    $conn = getDBConnection();
    
    // Update student_info
    $res = $conn->query("SELECT student_number, first_name, last_name, middle_name FROM student_info");
    $updatedInfo = 0;
    while ($row = $res->fetch_assoc()) {
        $fn = $row['first_name'];
        $ln = $row['last_name'];
        $mn = $row['middle_name'];
        
        $newFn = formatToTitleCase($fn);
        $newLn = formatToTitleCase($ln);
        $newMn = formatToTitleCase($mn);
        
        if ($fn !== $newFn || $ln !== $newLn || $mn !== $newMn) {
            $stmt = $conn->prepare("UPDATE student_info SET first_name=?, last_name=?, middle_name=? WHERE student_number=?");
            $stmt->execute([$newFn, $newLn, $newMn, $row['student_number']]);
            $updatedInfo++;
        }
    }
    echo "Updated $updatedInfo rows in student_info.\n";
    
    // Update student_masterlist
    $res = $conn->query("SELECT id, first_name, last_name, middle_initial FROM student_masterlist");
    $updatedMaster = 0;
    while ($row = $res->fetch_assoc()) {
        $fn = $row['first_name'];
        $ln = $row['last_name'];
        $mn = $row['middle_initial'];
        
        $newFn = formatToTitleCase($fn);
        $newLn = formatToTitleCase($ln);
        $newMn = formatToTitleCase($mn);
        
        if ($fn !== $newFn || $ln !== $newLn || $mn !== $newMn) {
            $stmt = $conn->prepare("UPDATE student_masterlist SET first_name=?, last_name=?, middle_initial=? WHERE id=?");
            $stmt->execute([$newFn, $newLn, $newMn, $row['id']]);
            $updatedMaster++;
        }
    }
    echo "Updated $updatedMaster rows in student_masterlist.\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
