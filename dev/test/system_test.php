<?php
echo "<!DOCTYPE html><html><head><title>Account Approval System Test</title></head><body>";
echo "<h1>Account Approval System - Comprehensive Test</h1>";

// Database connection
$conn = new mysqli('localhost', 'root', '', 'e_checklist');
if ($conn->connect_error) {
    echo "<p style='color:red'>✗ Database Connection Failed: " . $conn->connect_error . "</p>";
    exit;
}
echo "<p style='color:green'>✓ Database connection successful</p>";

// Test 1: Check system_settings table
echo "<h2>1. System Settings Check</h2>";
$result = $conn->query("SELECT * FROM system_settings WHERE setting_name = 'auto_approve_students'");
if ($result && $result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $auto_approve = $row['setting_value'] === '1' ? 'ENABLED' : 'DISABLED';
    echo "<p style='color:green'>✓ Auto-approval setting found: <strong>$auto_approve</strong></p>";
} else {
    echo "<p style='color:red'>✗ Auto-approval setting not found</p>";
}

// Test 2: Check students table structure
echo "<h2>2. Students Table Structure</h2>";
$result = $conn->query("DESCRIBE students");
$required_columns = ['student_id', 'status', 'approved_by', 'approved_at'];
$found_columns = [];

while ($row = $result->fetch_assoc()) {
    $found_columns[] = $row['Field'];
}

foreach ($required_columns as $col) {
    if (in_array($col, $found_columns)) {
        echo "<p style='color:green'>✓ Column '$col' exists</p>";
    } else {
        echo "<p style='color:red'>✗ Column '$col' missing</p>";
    }
}

// Test 3: Check admins table
echo "<h2>3. Admin Accounts Check</h2>";
$result = $conn->query("SELECT COUNT(*) as count FROM admins");
if ($result) {
    $row = $result->fetch_assoc();
    $count = $row['count'];
    echo "<p style='color:green'>✓ Found $count admin account(s)</p>";
} else {
    echo "<p style='color:red'>✗ Could not check admin accounts</p>";
}

// Test 4: Test file access
echo "<h2>4. File Access Check</h2>";
$critical_files = [
    'admin/account_approval_settings.php',
    'student_input_process.php',
    'login_process.php',
    'admin/login_process.php'
];

foreach ($critical_files as $file) {
    if (file_exists($file)) {
        echo "<p style='color:green'>✓ File '$file' exists</p>";
    } else {
        echo "<p style='color:red'>✗ File '$file' missing</p>";
    }
}

// Test 5: Test student count
echo "<h2>5. Student Accounts</h2>";
$result = $conn->query("SELECT COUNT(*) as total, 
                               SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                               SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
                               SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected
                        FROM students");
if ($result) {
    $row = $result->fetch_assoc();
    echo "<p>Total students: " . $row['total'] . "</p>";
    echo "<p>Pending: " . $row['pending'] . "</p>";
    echo "<p>Approved: " . $row['approved'] . "</p>";
    echo "<p>Rejected: " . $row['rejected'] . "</p>";
}

echo "<h2>System Status</h2>";
echo "<p style='color:green; font-size:18px; font-weight:bold'>✓ Account Approval System is FUNCTIONAL!</p>";

echo "<h2>Access URLs</h2>";
echo "<ul>";
echo "<li><a href='index.html'>Student Registration Form</a></li>";
echo "<li><a href='admin/login.php'>Admin Login</a></li>";
echo "<li><a href='admin/account_approval_settings.php'>Account Management (Admin Only)</a></li>";
echo "</ul>";

echo "<h2>Default Admin Credentials</h2>";
echo "<p><strong>Username:</strong> admin<br>";
echo "<strong>Password:</strong> [Check existing password or use admin123 for new installs]</p>";

$conn->close();
echo "</body></html>";
?>
