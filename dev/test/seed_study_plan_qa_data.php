<?php
require_once __DIR__ . '/../../config/config.php';

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

function normalizeTermYear($value)
{
    $value = trim((string)$value);
    switch ($value) {
        case 'First Year':
            return '1st Yr';
        case 'Second Year':
            return '2nd Yr';
        case 'Third Year':
            return '3rd Yr';
        case 'Fourth Year':
            return '4th Yr';
        default:
            return $value;
    }
}

function normalizeTermSem($value)
{
    $value = trim((string)$value);
    switch ($value) {
        case 'First Semester':
            return '1st Sem';
        case 'Second Semester':
            return '2nd Sem';
        case 'Midyear':
        case 'Summer':
        case 'Mid Year':
            return 'Mid Year';
        default:
            return $value;
    }
}

function parseArgs(array $argv)
{
    $mode = 'plan';
    foreach ($argv as $arg) {
        if ($arg === '--apply') {
            $mode = 'apply';
        }
        if ($arg === '--cleanup') {
            $mode = 'cleanup';
        }
    }

    return $mode;
}

function curriculumData(mysqli $conn, $programLabel)
{
    $rows = [];

    $curriculumExists = $conn->query("SHOW TABLES LIKE 'curriculum_courses'");
    if ($curriculumExists && $curriculumExists->num_rows > 0) {
        $sql = "
            SELECT
                TRIM(course_code) AS course_code,
                year_level,
                semester
            FROM curriculum_courses
            WHERE UPPER(TRIM(program)) = UPPER(?)
              AND course_title IS NOT NULL
              AND course_title != ''
              AND (credit_units_lec > 0 OR credit_units_lab > 0)
            ORDER BY
              FIELD(year_level, 'First Year', 'Second Year', 'Third Year', 'Fourth Year'),
              FIELD(semester, 'First Semester', 'Second Semester', 'Mid Year', 'Midyear', 'Summer'),
              id
        ";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('s', $programLabel);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $rows[] = [
                    'course_code' => trim((string)$row['course_code']),
                    'year' => normalizeTermYear($row['year_level'] ?? ''),
                    'semester' => normalizeTermSem($row['semester'] ?? ''),
                ];
            }
            $stmt->close();
        }
    }

    if (!empty($rows)) {
        return $rows;
    }

    $sql = "
        SELECT
            TRIM(SUBSTRING_INDEX(curriculumyear_coursecode, '_', -1)) AS course_code,
            year_level,
            semester
        FROM cvsucarmona_courses
        WHERE FIND_IN_SET('BSCS', REPLACE(UPPER(programs), ' ', '')) > 0
          AND course_title IS NOT NULL
          AND course_title != ''
          AND (credit_units_lec > 0 OR credit_units_lab > 0)
        ORDER BY
          FIELD(year_level, 'First Year', 'Second Year', 'Third Year', 'Fourth Year'),
          FIELD(semester, 'First Semester', 'Second Semester', 'Mid Year', 'Midyear', 'Summer'),
          curriculumyear_coursecode
    ";

    $result = $conn->query($sql);
    while ($result && $row = $result->fetch_assoc()) {
        $rows[] = [
            'course_code' => trim((string)$row['course_code']),
            'year' => normalizeTermYear($row['year_level'] ?? ''),
            'semester' => normalizeTermSem($row['semester'] ?? ''),
        ];
    }

    return $rows;
}

function ensureStudent(mysqli $conn, array $student, $apply)
{
    if (!$apply) {
        return;
    }

    $sql = "
        INSERT INTO student_info (
            student_number,
            last_name,
            first_name,
            middle_name,
            program,
            curriculum_year,
            stud_classification,
            general_weighted_average,
            date_of_admission,
            status,
            created_at,
            email,
            cvsu_email
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?)
        ON DUPLICATE KEY UPDATE
            last_name = VALUES(last_name),
            first_name = VALUES(first_name),
            middle_name = VALUES(middle_name),
            program = VALUES(program),
            curriculum_year = VALUES(curriculum_year),
            stud_classification = VALUES(stud_classification),
            general_weighted_average = VALUES(general_weighted_average),
            date_of_admission = VALUES(date_of_admission),
            status = VALUES(status),
            email = VALUES(email),
            cvsu_email = VALUES(cvsu_email)
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param(
        'issssssdssss',
        $student['id'],
        $student['last_name'],
        $student['first_name'],
        $student['middle_name'],
        $student['program'],
        $student['curriculum_year'],
        $student['classification'],
        $student['gwa'],
        $student['admission_date'],
        $student['status'],
        $student['email'],
        $student['cvsu_email']
    );
    $stmt->execute();
    $stmt->close();
}

function clearChecklistForStudent(mysqli $conn, $studentId, $apply)
{
    if (!$apply) {
        return;
    }

    $stmt = $conn->prepare("DELETE FROM student_checklists WHERE student_id = ?");
    $stmt->bind_param('i', $studentId);
    $stmt->execute();
    $stmt->close();
}

function insertChecklistRow(mysqli $conn, array $row, $apply)
{
    if (!$apply) {
        return;
    }

    $sql = "
        INSERT INTO student_checklists (
            student_id,
            course_code,
            semester,
            school_year,
            final_grade,
            evaluator_remarks,
            grade_submitted_at,
            updated_at,
            submitted_by,
            grade_approved,
            created_at,
            final_grade_2,
            evaluator_remarks_2,
            final_grade_3,
            evaluator_remarks_3
        ) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW(), 'qa_seed', 1, NOW(), ?, ?, ?, ?)
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param(
        'isssssssss',
        $row['student_id'],
        $row['course_code'],
        $row['semester'],
        $row['school_year'],
        $row['final_grade'],
        $row['evaluator_remarks'],
        $row['final_grade_2'],
        $row['evaluator_remarks_2'],
        $row['final_grade_3'],
        $row['evaluator_remarks_3']
    );
    $stmt->execute();
    $stmt->close();
}

function makeChecklistRow($studentId, $courseCode, $grade, $remark = 'Approved', $semester = '1st Sem', $schoolYear = '2025-2026', $g2 = null, $r2 = null, $g3 = null, $r3 = null)
{
    return [
        'student_id' => $studentId,
        'course_code' => $courseCode,
        'semester' => $semester,
        'school_year' => $schoolYear,
        'final_grade' => $grade,
        'evaluator_remarks' => $remark,
        'final_grade_2' => $g2,
        'evaluator_remarks_2' => $r2,
        'final_grade_3' => $g3,
        'evaluator_remarks_3' => $r3,
    ];
}

function removeQaData(mysqli $conn, array $seedIds, $apply)
{
    if (!$apply) {
        return;
    }

    $idList = implode(',', array_map('intval', $seedIds));
    $conn->query("DELETE FROM student_checklists WHERE student_id IN ($idList)");
    $conn->query("DELETE FROM student_info WHERE student_number IN ($idList)");
    $conn->query("DELETE FROM program_shift_requests WHERE student_number IN ('" . implode("','", array_map('strval', $seedIds)) . "')");
}

$mode = parseArgs(array_slice($argv, 1));
$apply = $mode !== 'plan';

$conn = getDBConnection();
$program = 'Bachelor of Science in Computer Science';
$curriculumRows = curriculumData($conn, $program);

if (empty($curriculumRows)) {
    echo "No curriculum rows found.\n";
    closeDBConnection($conn);
    exit(1);
}

$allCodes = [];
$termBuckets = [];
foreach ($curriculumRows as $row) {
    $code = trim((string)$row['course_code']);
    if ($code === '' || isset($allCodes[$code])) {
        continue;
    }
    $allCodes[$code] = true;
    $termKey = ($row['year'] ?? '') . '|' . ($row['semester'] ?? '');
    if (!isset($termBuckets[$termKey])) {
        $termBuckets[$termKey] = [];
    }
    $termBuckets[$termKey][] = $code;
}
$codes = array_keys($allCodes);

$firstTerm = $termBuckets['1st Yr|1st Sem'] ?? array_slice($codes, 0, 8);
$secondTerm = $termBuckets['1st Yr|2nd Sem'] ?? array_slice($codes, 8, 8);

$seedStudents = [
    91010001 => ['name' => 'Regular New Student', 'type' => 'exact_new', 'classification' => '', 'gwa' => 1.75],
    91010002 => ['name' => 'Fully Completed', 'type' => 'fully_completed', 'classification' => '', 'gwa' => 1.50],
    91010003 => ['name' => 'Current Probation', 'type' => 'probation', 'classification' => '', 'gwa' => 2.50],
    91010004 => ['name' => 'Current Disqualification', 'type' => 'disqualification', 'classification' => '', 'gwa' => 3.50],
    91010005 => ['name' => 'Policy Gate Paused', 'type' => 'policy_paused', 'classification' => 'Transferee', 'gwa' => 2.75],
    91010006 => ['name' => 'Academic Hold Active', 'type' => 'academic_hold', 'classification' => '', 'gwa' => 3.00],
    91010007 => ['name' => 'Remaining Two Courses', 'type' => 'remaining_two', 'classification' => '', 'gwa' => 1.80],
];

if ($mode === 'cleanup') {
    removeQaData($conn, array_keys($seedStudents), true);
    closeDBConnection($conn);
    echo "Cleanup completed for QA seed IDs.\n";
    exit(0);
}

echo "Mode: " . $mode . "\n";
echo "Target program: " . $program . "\n";
echo "Distinct curriculum courses: " . count($codes) . "\n\n";

$totalRowsPlanned = 0;

foreach ($seedStudents as $id => $meta) {
    $student = [
        'id' => $id,
        'last_name' => 'QA_SEED',
        'first_name' => 'SP_' . $id,
        'middle_name' => 'T',
        'program' => $program,
        'curriculum_year' => '2023',
        'classification' => $meta['classification'],
        'gwa' => (float)$meta['gwa'],
        'admission_date' => '2023-08-15',
        'status' => 'approved',
        'email' => 'qa_seed_' . $id . '@example.com',
        'cvsu_email' => 'qa_seed_' . $id . '@cvsu.edu.ph',
    ];

    echo '[' . $id . '] ' . $meta['name'] . ' (' . $meta['type'] . ")\n";
    ensureStudent($conn, $student, $apply);
    clearChecklistForStudent($conn, $id, $apply);

    $rows = [];

    switch ($meta['type']) {
        case 'exact_new':
            $rows = [];
            break;

        case 'fully_completed':
            foreach ($codes as $code) {
                $grade = (strtoupper($code) === 'CVSU 101') ? 'S' : '2.00';
                $rows[] = makeChecklistRow($id, $code, $grade, 'Approved');
            }
            break;

        case 'probation':
            $failCount = max(1, (int)ceil(count($firstTerm) * 0.6));
            foreach ($firstTerm as $i => $code) {
                $grade = ($i < $failCount) ? '5.00' : '2.00';
                $rows[] = makeChecklistRow($id, $code, $grade, 'Approved', '1st Sem');
            }
            break;

        case 'disqualification':
            $failCount = max(1, (int)ceil(count($firstTerm) * 0.8));
            foreach ($firstTerm as $i => $code) {
                $grade = ($i < $failCount) ? '5.00' : '2.00';
                $rows[] = makeChecklistRow($id, $code, $grade, 'Approved', '1st Sem');
            }
            break;

        case 'policy_paused':
            foreach ($firstTerm as $code) {
                $rows[] = makeChecklistRow($id, $code, '2.50', 'Approved', '1st Sem');
            }
            if (!empty($secondTerm)) {
                $rows[] = makeChecklistRow($id, $secondTerm[0], '5.00', 'Approved', '2nd Sem');
            }
            break;

        case 'academic_hold':
            $holdCode = $firstTerm[0] ?? $codes[0];
            $rows[] = makeChecklistRow(
                $id,
                $holdCode,
                '5.00',
                'Approved',
                '1st Sem',
                '2025-2026',
                '5.00',
                'Approved',
                '5.00',
                'Approved'
            );
            break;

        case 'remaining_two':
            $forcedBucket = $termBuckets['4th Yr|1st Sem'] ?? [];
            $remaining = array_slice($forcedBucket, 0, 2);
            if (count($remaining) < 2) {
                $remaining = array_slice($codes, -2);
            }

            foreach ($codes as $code) {
                if (in_array($code, $remaining, true)) {
                    continue;
                }
                $rows[] = makeChecklistRow($id, $code, '2.00', 'Approved');
            }

            foreach ($remaining as $remainingCode) {
                $rows[] = makeChecklistRow($id, $remainingCode, '5.00', 'Approved');
            }
            break;
    }

    foreach ($rows as $row) {
        insertChecklistRow($conn, $row, $apply);
    }

    $totalRowsPlanned += count($rows);
    echo '  checklist rows: ' . count($rows) . "\n\n";
}

closeDBConnection($conn);

echo 'Total checklist rows prepared: ' . $totalRowsPlanned . "\n";
if ($mode === 'plan') {
    echo "No data written. Re-run with --apply to insert QA seed data.\n";
} else {
    echo "QA seed data applied.\n";
}
