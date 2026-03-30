<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/academic_hold_service.php';
require_once __DIR__ . '/../includes/laravel_bridge.php';

header('Content-Type: application/json');

// Log incoming request
error_log("Received request in save_pre_enrollment.php");

$useLaravelBridge = getenv('USE_LARAVEL_BRIDGE') === '1';

// Get student_id from URL parameter if it exists, otherwise use session
$student_id = isset($_GET['student_id']) ? $_GET['student_id'] : (isset($_SESSION['student_id']) ? $_SESSION['student_id'] : null);

if (!$student_id) {
    error_log("No student ID provided");
    echo json_encode(['success' => false, 'message' => 'No student ID provided']);
    exit();
}

// Get POST data
$raw_data = file_get_contents('php://input');
error_log("Received raw data: " . $raw_data);

$data = json_decode($raw_data, true);
error_log("Decoded data: " . print_r($data, true));

if (!$data) {
    error_log("JSON decode error: " . json_last_error_msg());
    echo json_encode(['success' => false, 'message' => 'Invalid JSON data received: ' . json_last_error_msg()]);
    exit();
}

// Check if courses data exists
if (!isset($data['courses']) || empty($data['courses'])) {
    error_log("No courses data found in submission");
    echo json_encode(['success' => false, 'message' => 'No courses selected']);
    exit();
}

if ($useLaravelBridge) {
    $bridgePayload = $data;
    $bridgePayload['student_id'] = $student_id;
    $bridgeData = postLaravelJsonBridge(
        'http://localhost/ASPLAN_v10/laravel-app/public/api/pre-enrollment/save',
        $bridgePayload
    );

    if (is_array($bridgeData)) {
        echo json_encode($bridgeData);
        exit();
    }
}

// Get database connection
$conn = getDBConnection();

$academicHold = ahsGetStudentAcademicHold($conn, (string)$student_id);
if (!empty($academicHold['active'])) {
    echo json_encode([
        'success' => false,
        'message' => (string)($academicHold['message'] ?? 'The student is currently under academic hold.')
    ]);
    closeDBConnection($conn);
    exit();
}

try 
{
    // Log the data we're about to process
    error_log("Processing data: " . print_r($data, true));

    // Generate IDs with date and time
    $timestamp = date('YmdHis'); // Format: YYYYMMDDHHMMSS
    $pre_enrollment_id = 'PE' . $timestamp; // PE for Pre-Enrollment
    $course_id_prefix = 'PC' . $timestamp; // PC for Pre-Enrollment Course

    // Start transaction
    $conn->begin_transaction();

    // Validate required fields
    $required_fields = ['student_id', 'name', 'year_level', 'course', 'section_major',
                       'classification', 'registration_status', 'scholarship_awarded',
                       'mode_of_payment'];
    
    foreach ($required_fields as $field) {
        if (!isset($data[$field])) {
            throw new Exception("Missing required field: " . $field);
        }
    }

    // Prepare values for duplicate check
    $section_major = empty($data['section_major']) ? 'N/A' : $data['section_major'];
    $course_codes = array();
    $course_titles = array();
    $course_units = array();
    foreach ($data['courses'] as $course) {
        $course_codes[] = $course['course_code'];
        $course_titles[] = $course['course_title'];
        $course_units[] = $course['units'];
    }
    $course_codes_str = implode(', ', $course_codes);
    $course_titles_str = implode(', ', $course_titles);
    $course_units_str = implode(', ', $course_units);

    // Check for duplicate pre-enrollment
    $dup_query = "SELECT pe.id FROM pre_enrollments pe
        JOIN pre_enrollment_courses pec ON pe.id = pec.pre_enrollment_id
        WHERE pe.student_id = ? AND pe.year_level = ? AND pe.course = ? AND pe.section_major = ?
        AND pe.classification = ? AND pe.registration_status = ? AND pe.scholarship_awarded = ?
        AND pe.mode_of_payment = ?
        AND pec.course_codes = ? AND pec.course_titles = ? AND pec.units = ?";
    $dup_stmt = $conn->prepare($dup_query);
    if (!$dup_stmt) {
        throw new Exception("Prepare failed for duplicate check: " . $conn->error);
    }
    $dup_stmt->bind_param("sssssssssss",
        $data['student_id'],
        $data['year_level'],
        $data['course'],
        $section_major,
        $data['classification'],
        $data['registration_status'],
        $data['scholarship_awarded'],
        $data['mode_of_payment'],
        $course_codes_str,
        $course_titles_str,
        $course_units_str
    );
    $dup_stmt->execute();
    $dup_stmt->store_result();
    if ($dup_stmt->num_rows > 0) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'Duplicate pre-enrollment found. Submission not saved.']);
        exit();
    }
    $dup_stmt->close();

    // Insert into pre_enrollments table
    $stmt = $conn->prepare("INSERT INTO pre_enrollments (
        id, student_id, name, year_level, course, section_major,
        classification, registration_status, scholarship_awarded,
        mode_of_payment
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }

    // Prepare values for binding
    $section_major = empty($data['section_major']) ? 'N/A' : $data['section_major'];
    
    $stmt->bind_param("ssssssssss",
        $pre_enrollment_id,
        $data['student_id'],
        $data['name'],
        $data['year_level'],
        $data['course'],
        $section_major,
        $data['classification'],
        $data['registration_status'],
        $data['scholarship_awarded'],
        $data['mode_of_payment']
    );

    if (!$stmt->execute()) {
        error_log("SQL Error: " . $stmt->error);
        error_log("SQL State: " . $stmt->sqlstate);
        throw new Exception("Execute failed for pre_enrollment: " . $stmt->error);
    }
    error_log("Inserted pre_enrollment with ID: " . $pre_enrollment_id);

    // Validate courses array
    if (!isset($data['courses']) || !is_array($data['courses'])) {
        throw new Exception("No courses data provided");
    }


    // Insert all courses as a single record with comma-separated values (legacy structure)
    $stmt = $conn->prepare("INSERT INTO pre_enrollment_courses (
        id, pre_enrollment_id, course_codes, course_titles, units
    ) VALUES (?, ?, ?, ?, ?)");
    if (!$stmt) {
        throw new Exception("Prepare failed for courses: " . $conn->error);
    }

    $course_codes = array();
    $course_titles = array();
    $course_units = array();
    foreach ($data['courses'] as $course) {
        $course_codes[] = $course['course_code'];
        $course_titles[] = $course['course_title'];
        $course_units[] = $course['units'];
    }
    $course_codes_str = implode(', ', $course_codes);
    $course_titles_str = implode(', ', $course_titles);
    $course_units_str = implode(', ', $course_units);

    $course_id = $course_id_prefix . '001';
    if (!$stmt->bind_param("sssss",
        $course_id,
        $pre_enrollment_id,
        $course_codes_str,
        $course_titles_str,
        $course_units_str
    )) {
        throw new Exception("Bind param failed for courses: " . $stmt->error . " (SQL State: " . $stmt->sqlstate . ")");
    }
    if (!$stmt->execute()) {
        throw new Exception("Execute failed for courses: " . $stmt->error);
    }
    error_log("Successfully inserted all courses as a single record (comma-separated)");

    // Commit transaction
    $conn->commit();
    echo json_encode(['success' => true, 'message' => 'Pre-enrollment saved successfully']);
} 
catch (Exception $e) 
{
    // Rollback transaction on error
    $conn->rollback();
    error_log("Error in save_pre_enrollment.php: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    echo json_encode([
        'success' => false, 
        'message' => 'Error saving pre-enrollment: ' . $e->getMessage(),
        'details' => $e->getTraceAsString()
    ]);
}

// Close database connection
closeDBConnection($conn);
?>
