<?php
require_once __DIR__ . '/../config/config.php';

// Get database connection
$conn = getDBConnection();

// Check if student_id is set in the URL
if (isset($_GET['student_id'])) {
    $student_id = $conn->real_escape_string($_GET['student_id']); // Sanitize input

    // Update the student's status to 'approved'
    $query = "UPDATE student_info SET status = 'approved' WHERE student_number = '$student_id'";
    if ($conn->query($query) === TRUE) {
        // Redirect back to the pending accounts page with success message
        closeDBConnection($conn);
        header("Location: pending_accs_admin.php?message=Account approved successfully.");
        exit;
    } else {
        // Redirect back with an error message if the update fails
        closeDBConnection($conn);
        header("Location: pending_accs_admin.php?message=Error approving account.");
        exit;
    }
} else {
    // Redirect back if student_id is not set
    closeDBConnection($conn);
    header("Location: pending_accs_admin.php?message=No account selected.");
    exit;
}
?>
