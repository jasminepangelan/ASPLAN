<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/laravel_bridge.php';

header('Content-Type: application/json');

if (!isset($_SESSION['admin_username'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || empty($input['program']) || empty($input['curriculum_year']) || empty($input['courses'])) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit();
}

$program = $input['program'];
$curriculum_year = $input['curriculum_year'];
$courses = $input['courses'];

$useLaravelBridge = getenv('USE_LARAVEL_BRIDGE') === '1';
if ($useLaravelBridge) {
    $bridgePayload = $input;
    $bridgePayload['bridge_authorized'] = true;
    $bridgeData = postLaravelJsonBridge(
        '/api/curriculum/save',
        $bridgePayload
    );
    if (is_array($bridgeData)) {
        echo json_encode($bridgeData);
        exit();
    }
}

// Validate program
$valid_programs = ['BSIndT','BSCpE','BSIT','BSCS','BSHM','BSBA-HRM','BSBA-MM','BSEd-English','BSEd-Science','BSEd-Math'];
if (!in_array($program, $valid_programs)) {
    echo json_encode(['success' => false, 'message' => 'Invalid program']);
    exit();
}

// Validate curriculum year
if (!preg_match('/^\d{4}$/', $curriculum_year) || $curriculum_year < 2017 || $curriculum_year > 2099) {
    echo json_encode(['success' => false, 'message' => 'Invalid curriculum year']);
    exit();
}

// Build prefix (e.g., 2025_)
$prefix = $curriculum_year . '_';

$conn = getDBConnection();
$conn->begin_transaction();

try {
    // Delete existing courses for this program + curriculum year prefix
    // We need to find rows where the prefix matches AND the program is in the programs list
    $existing = null;
    $existingStmt = $conn->prepare("SELECT curriculumyear_coursecode, programs FROM cvsucarmona_courses WHERE curriculumyear_coursecode LIKE ?");
    if ($existingStmt) {
        $prefixLike = $prefix . '%';
        $existingStmt->bind_param('s', $prefixLike);
        $existingStmt->execute();
        $existing = $existingStmt->get_result();
    }

    if (!$existing) {
        throw new Exception('Failed to load existing curriculum rows.');
    }

    while ($row = $existing->fetch_assoc()) {
        $progs = array_map('trim', explode(',', $row['programs']));
        if (in_array($program, $progs)) {
            if (count($progs) === 1) {
                // Only this program uses this course - delete it
                $stmt = $conn->prepare("DELETE FROM cvsucarmona_courses WHERE curriculumyear_coursecode = ?");
                $stmt->bind_param('s', $row['curriculumyear_coursecode']);
                $stmt->execute();
                $stmt->close();
            } else {
                // Other programs also use this course - just remove this program from the list
                $progs = array_filter($progs, function($p) use ($program) { return $p !== $program; });
                $new_progs = implode(', ', $progs);
                $stmt = $conn->prepare("UPDATE cvsucarmona_courses SET programs = ? WHERE curriculumyear_coursecode = ?");
                $stmt->bind_param('ss', $new_progs, $row['curriculumyear_coursecode']);
                $stmt->execute();
                $stmt->close();
            }
        }
    }
    if (isset($existingStmt) && $existingStmt instanceof mysqli_stmt) {
        $existingStmt->close();
    }

    // Insert new courses
    $valid_years = ['First Year', 'Second Year', 'Third Year', 'Fourth Year'];
    $valid_semesters = ['First Semester', 'Second Semester', 'Mid Year'];

    $inserted = 0;
    foreach ($courses as $course) {
        $course_code = trim($course['course_code']);
        $course_title = trim($course['course_title']);
        $year_level = trim($course['year_level']);
        $semester = trim($course['semester']);

        if (empty($course_code) || empty($course_title)) continue;
        if (!in_array($year_level, $valid_years)) continue;
        if (!in_array($semester, $valid_semesters)) continue;

        $key = $prefix . $course_code;
        $credit_lec = intval($course['credit_units_lec']);
        $credit_lab = intval($course['credit_units_lab']);
        $hrs_lec = intval($course['lect_hrs_lec']);
        $hrs_lab = intval($course['lect_hrs_lab']);
        $prereq = trim($course['pre_requisite']) ?: 'NONE';

        // Check if a row with this key already exists (from another program's courses or a duplicate within this batch)
        $check = $conn->prepare("SELECT programs FROM cvsucarmona_courses WHERE curriculumyear_coursecode = ?");
        $check->bind_param('s', $key);
        $check->execute();
        $result = $check->get_result();

        if ($result->num_rows > 0) {
            // Row exists — add this program to the programs list if not already there
            $existing_progs = $result->fetch_assoc()['programs'];
            $prog_list = array_map('trim', explode(',', $existing_progs));
            if (!in_array($program, $prog_list)) {
                $prog_list[] = $program;
                $new_progs = implode(', ', $prog_list);
                $upd = $conn->prepare("UPDATE cvsucarmona_courses SET programs = ? WHERE curriculumyear_coursecode = ?");
                $upd->bind_param('ss', $new_progs, $key);
                $upd->execute();
                $upd->close();
            }
        } else {
            // New row
            $stmt = $conn->prepare(
                "INSERT INTO cvsucarmona_courses (curriculumyear_coursecode, programs, course_title, year_level, semester, credit_units_lec, credit_units_lab, lect_hrs_lec, lect_hrs_lab, pre_requisite)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param('sssssiiiis', $key, $program, $course_title, $year_level, $semester, $credit_lec, $credit_lab, $hrs_lec, $hrs_lab, $prereq);
            $stmt->execute();
            $stmt->close();
        }
        $check->close();
        $inserted++;
    }

    $conn->commit();
    echo json_encode(['success' => true, 'message' => "Curriculum saved! $inserted courses for $program (Year $curriculum_year)."]);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}

$conn->close();
