<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/program_shift_service.php';

$conn = getDBConnection();
psEnsureProgramShiftTables($conn);

$summary = [
    'happy_path' => ['ok' => false, 'details' => []],
    'reject_adviser_path' => ['ok' => false, 'details' => []],
    'reject_coordinator_path' => ['ok' => false, 'details' => []],
    'cleanup' => ['ok' => false],
];

$requestIds = [];
$tempStudentNumber = null;
$programs = [];
$createdCurriculumFixture = false;

function pickDifferent(array $programs, string $not, array $exclude = []): ?string {
    foreach ($programs as $p) {
        if ($p === $not) {
            continue;
        }
        if (in_array($p, $exclude, true)) {
            continue;
        }
        return $p;
    }
    return null;
}

try {
    $tableCheck = $conn->query("SHOW TABLES LIKE 'curriculum_courses'");
    $hasCurriculum = $tableCheck && $tableCheck->num_rows > 0;

    if (!$hasCurriculum) {
        $createdCurriculumFixture = true;
        $conn->query("CREATE TABLE curriculum_courses (
            id INT AUTO_INCREMENT PRIMARY KEY,
            curriculum_year INT NOT NULL,
            program VARCHAR(255) NOT NULL,
            year_level VARCHAR(50) NOT NULL,
            semester VARCHAR(50) NOT NULL,
            course_code VARCHAR(50) NOT NULL,
            course_title VARCHAR(255) NOT NULL,
            credit_units_lec INT DEFAULT 0,
            credit_units_lab INT DEFAULT 0,
            lect_hrs_lec INT DEFAULT 0,
            lect_hrs_lab INT DEFAULT 0,
            pre_requisite VARCHAR(255) DEFAULT 'NONE'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Minimal curriculum data required for shift execution matching logic.
        $conn->query("INSERT INTO curriculum_courses
            (curriculum_year, program, year_level, semester, course_code, course_title, credit_units_lec, credit_units_lab, lect_hrs_lec, lect_hrs_lab, pre_requisite)
            VALUES
            (2026, 'Program A', 'First Year', 'First Semester', 'GEN101', 'General Course 101', 3, 0, 3, 0, 'NONE'),
            (2026, 'Program B', 'First Year', 'First Semester', 'GEN101', 'General Course 101', 3, 0, 3, 0, 'NONE'),
            (2026, 'Program C', 'First Year', 'First Semester', 'GEN101', 'General Course 101', 3, 0, 3, 0, 'NONE')");
    }

    $res = $conn->query("SELECT DISTINCT TRIM(program) AS program FROM curriculum_courses WHERE program IS NOT NULL AND TRIM(program) != '' ORDER BY program ASC");
    while ($res && ($row = $res->fetch_assoc())) {
        $programs[] = (string)$row['program'];
    }
    if (count($programs) < 3) {
        throw new RuntimeException('Need at least 3 curriculum programs for this test.');
    }

    $sourceProgram = $programs[0];
    $destProgram1 = $programs[1];
    $destProgram2 = $programs[2];

    // Create isolated temporary student for workflow tests.
    do {
        $tempStudentNumber = (string)random_int(980000000, 989999999);
        $chk = $conn->prepare('SELECT student_number FROM student_info WHERE student_number = ? LIMIT 1');
        $chk->bind_param('s', $tempStudentNumber);
        $chk->execute();
        $exists = $chk->get_result()->num_rows > 0;
        $chk->close();
    } while ($exists);

    $ins = $conn->prepare("INSERT INTO student_info (student_number, last_name, first_name, middle_name, password, email, program, status, created_at) VALUES (?, 'Phase2', 'Tester', '', NULL, NULL, ?, 'approved', NOW())");
    $ins->bind_param('ss', $tempStudentNumber, $sourceProgram);
    if (!$ins->execute()) {
        throw new RuntimeException('Failed to create temporary student: ' . $ins->error);
    }
    $ins->close();

    // 1) Happy path: submit -> adviser approve -> coordinator approve/execute.
    $create1 = psCreateStudentRequest($conn, $tempStudentNumber, $destProgram1, 'Phase 2 happy path validation');
    $summary['happy_path']['details']['create'] = $create1;
    if (!empty($create1['ok'])) {
        $req1 = (int)$create1['request_id'];
        $requestIds[] = $req1;

        $adv1 = psHandleAdviserDecision($conn, $req1, 'approve', 'phase2_adviser', 'Phase 2 Adviser', [], 'Approved for coordinator review');
        $summary['happy_path']['details']['adviser'] = $adv1;

        $coor1 = psHandleCoordinatorDecision($conn, $req1, 'approve', 'phase2_coordinator', 'Phase 2 Coordinator', [], 'Approved and execute');
        $summary['happy_path']['details']['coordinator'] = $coor1;

        $row1 = psGetRequestById($conn, $req1);
        $summary['happy_path']['details']['request_after'] = $row1;

        $summary['happy_path']['ok'] = !empty($adv1['ok']) && !empty($coor1['ok']) && isset($row1['status']) && $row1['status'] === 'approved' && !empty($row1['executed_at']);
    }

    // 2) Reject path at adviser stage.
    $studentAfterHappy = psGetCurrentStudentInfo($conn, $tempStudentNumber);
    $currentProgram = trim((string)($studentAfterHappy['program'] ?? ''));
    $rejectAdviserDest = pickDifferent($programs, $currentProgram, []);
    if ($rejectAdviserDest === null) {
        throw new RuntimeException('Could not find destination program for adviser reject path.');
    }

    $create2 = psCreateStudentRequest($conn, $tempStudentNumber, $rejectAdviserDest, 'Phase 2 adviser reject validation');
    $summary['reject_adviser_path']['details']['create'] = $create2;
    if (!empty($create2['ok'])) {
        $req2 = (int)$create2['request_id'];
        $requestIds[] = $req2;

        $adv2 = psHandleAdviserDecision($conn, $req2, 'reject', 'phase2_adviser', 'Phase 2 Adviser', [], 'Rejected by adviser test');
        $summary['reject_adviser_path']['details']['adviser'] = $adv2;
        $row2 = psGetRequestById($conn, $req2);
        $summary['reject_adviser_path']['details']['request_after'] = $row2;

        $summary['reject_adviser_path']['ok'] = !empty($adv2['ok']) && isset($row2['status']) && $row2['status'] === 'rejected';
    }

    // 3) Reject path at coordinator stage.
    $studentAfterAdviserReject = psGetCurrentStudentInfo($conn, $tempStudentNumber);
    $currentProgram2 = trim((string)($studentAfterAdviserReject['program'] ?? ''));
    $rejectCoordinatorDest = pickDifferent($programs, $currentProgram2, [$rejectAdviserDest]);
    if ($rejectCoordinatorDest === null) {
        $rejectCoordinatorDest = pickDifferent($programs, $currentProgram2, []);
    }
    if ($rejectCoordinatorDest === null) {
        throw new RuntimeException('Could not find destination program for coordinator reject path.');
    }

    $create3 = psCreateStudentRequest($conn, $tempStudentNumber, $rejectCoordinatorDest, 'Phase 2 coordinator reject validation');
    $summary['reject_coordinator_path']['details']['create'] = $create3;
    if (!empty($create3['ok'])) {
        $req3 = (int)$create3['request_id'];
        $requestIds[] = $req3;

        $adv3 = psHandleAdviserDecision($conn, $req3, 'approve', 'phase2_adviser', 'Phase 2 Adviser', [], 'Forwarded to coordinator');
        $summary['reject_coordinator_path']['details']['adviser'] = $adv3;

        $coor3 = psHandleCoordinatorDecision($conn, $req3, 'reject', 'phase2_coordinator', 'Phase 2 Coordinator', [], 'Rejected by coordinator test');
        $summary['reject_coordinator_path']['details']['coordinator'] = $coor3;

        $row3 = psGetRequestById($conn, $req3);
        $summary['reject_coordinator_path']['details']['request_after'] = $row3;

        $summary['reject_coordinator_path']['ok'] = !empty($adv3['ok']) && !empty($coor3['ok']) && isset($row3['status']) && $row3['status'] === 'rejected';
    }

} catch (Throwable $e) {
    $summary['error'] = $e->getMessage();
} finally {
    // Cleanup test artifacts.
    if (!empty($requestIds)) {
        $ids = implode(',', array_map('intval', $requestIds));
        $conn->query("DELETE FROM program_shift_credit_map WHERE request_id IN ($ids)");
        $conn->query("DELETE FROM program_shift_approvals WHERE request_id IN ($ids)");
        $conn->query("DELETE FROM program_shift_audit WHERE request_id IN ($ids)");
        $conn->query("DELETE FROM program_shift_requests WHERE id IN ($ids)");
    }

    if (!empty($tempStudentNumber)) {
        $delChecklist = $conn->prepare("DELETE FROM student_checklists WHERE student_id = ? AND submitted_by = 'shift_engine'");
        if ($delChecklist) {
            $delChecklist->bind_param('s', $tempStudentNumber);
            $delChecklist->execute();
            $delChecklist->close();
        }

        $delStudent = $conn->prepare('DELETE FROM student_info WHERE student_number = ?');
        if ($delStudent) {
            $delStudent->bind_param('s', $tempStudentNumber);
            $delStudent->execute();
            $delStudent->close();
        }
    }

    if ($createdCurriculumFixture) {
        $conn->query('DROP TABLE IF EXISTS curriculum_courses');
    }

    $summary['cleanup']['ok'] = true;
    $summary['cleanup']['request_ids'] = $requestIds;
    $summary['cleanup']['temp_student'] = $tempStudentNumber;

    closeDBConnection($conn);
}

echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
?>
