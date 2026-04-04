<?php
require_once __DIR__ . '/../config/config.php';

// Get database connection
$conn = getDBConnection();

// Check if student_id is set in the URL
if (isset($_GET['student_id'])) {
    $student_id = trim((string) $_GET['student_id']);

    $stmt = $conn->prepare("UPDATE student_info SET status = ? WHERE student_number = ?");
    if ($stmt) {
        $status = 'approved';
        $stmt->bind_param('ss', $status, $student_id);
        $success = $stmt->execute();
        $stmt->close();
    } else {
        $success = false;
    }

    // Update the student's status to 'approved'
    if ($success === true) {
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
