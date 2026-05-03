<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/laravel_bridge.php';

header('Content-Type: application/json');

// Get student_id from URL parameter if it exists, otherwise use session
$useLaravelBridge = getenv('USE_LARAVEL_BRIDGE') === '1';
$student_id = isset($_GET['student_id']) ? $_GET['student_id'] : (isset($_SESSION['student_id']) ? $_SESSION['student_id'] : null);

if (!$student_id) {
    echo json_encode(['success' => false, 'message' => 'No student ID provided']);
    exit();
}

if ($useLaravelBridge) {
    $bridgeData = postLaravelJsonBridge(
        '/api/pre-enrollment/load',
        ['student_id' => $student_id]
    );

    if (is_array($bridgeData)) {
        echo json_encode($bridgeData);
        exit();
    }
}

// Get database connection
$conn = getDBConnection();

// Get the latest pre-enrollment record with courses
$stmt = $conn->prepare("
    SELECT pe.*, pec.course_codes, pec.course_titles, pec.units,
           DATE_FORMAT(pe.created_at, '%Y-%m-%d %H:%i:%s') as formatted_date,
           pe.year_level
    FROM pre_enrollments pe
    LEFT JOIN pre_enrollment_courses pec ON pe.id = pec.pre_enrollment_id
    WHERE pe.student_id = ? 
    ORDER BY pe.created_at DESC 
    LIMIT 1 
");

$stmt->bind_param("s", $student_id);
$stmt->execute();
$result = $stmt->get_result();
$enrollment = $result->fetch_assoc();

if ($enrollment) {
    // Process course codes, titles, and units from comma-separated strings
    $course_codes = explode(', ', $enrollment['course_codes']);
    $course_titles = explode(', ', $enrollment['course_titles']);
    $course_units = explode(', ', $enrollment['units']);
    
    // Combine them into courses array
    $courses = array();
    for ($i = 0; $i < count($course_codes); $i++) {
        if (isset($course_codes[$i]) && isset($course_titles[$i])) {
            $courses[] = array(
                'course_code' => $course_codes[$i],
                'course_title' => $course_titles[$i],
                'units' => isset($course_units[$i]) ? $course_units[$i] : '',
                'day' => ''
            );
        }
    }
    
    $enrollment['courses'] = $courses;
    echo json_encode(['success' => true, 'data' => $enrollment]);
} else {
    echo json_encode(['success' => false, 'message' => 'No pre-enrollment found']);
}

$conn->close();
?>

