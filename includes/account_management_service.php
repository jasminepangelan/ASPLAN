<?php
// account_management_service.php
// Contains business logic for loading and displaying student account details for admin management.

require_once __DIR__ . '/../config/database.php';

/**
 * Loads complete student information by student number.
 * Returns student data array or null if not found.
 */
function amLoadStudentInfo($student_number)
{
    $conn = getDBConnection();
    $stmt = $conn->prepare("SELECT * FROM student_info WHERE student_number = ?");
    $stmt->bind_param("s", $student_number);
    $stmt->execute();
    $result = $stmt->get_result();
    $student = null;
    if ($row = $result->fetch_assoc()) {
        $student = $row;
    }
    $stmt->close();
    closeDBConnection($conn);
    return $student;
}

/**
 * Formats student picture path for display.
 * Handles default picture if not found.
 */
function amFormatPicturePath($picture_raw)
{
    if (!empty($picture_raw) && strpos($picture_raw, '../') !== 0) {
        return '../' . htmlspecialchars($picture_raw);
    }
    return !empty($picture_raw) ? htmlspecialchars($picture_raw) : '../img/default-profile.png';
}

/**
 * Builds complete address string from address parts.
 */
function amBuildAddressString($house_number, $brgy, $town, $province)
{
    $parts = array_filter([
        trim($house_number ?? ''),
        trim($brgy ?? ''),
        trim($town ?? ''),
        trim($province ?? '')
    ]);
    return htmlspecialchars(implode(', ', $parts));
}
