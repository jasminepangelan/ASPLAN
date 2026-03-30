<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/program_shift_service.php';
require_once __DIR__ . '/../includes/laravel_bridge.php';

header('Content-Type: application/json');

if (!(isset($_SESSION['username']) && (!isset($_SESSION['user_type']) || $_SESSION['user_type'] === 'program_coordinator'))
    && !isset($_SESSION['admin_username'])
    && !isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || empty($input['program']) || empty($input['curriculum_year']) || !array_key_exists('courses', $input)) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit();
}

$program = $input['program'];
$curriculum_year = $input['curriculum_year'];
$courses = is_array($input['courses']) ? $input['courses'] : [];
$deleted_courses = is_array($input['deleted_courses'] ?? null) ? $input['deleted_courses'] : [];

$useLaravelBridge = getenv('USE_LARAVEL_BRIDGE') === '1';
if ($useLaravelBridge) {
    $bridgePayload = $input;
    $bridgePayload['bridge_authorized'] = true;

    $bridgeData = postLaravelJsonBridge(
        'http://localhost/ASPLAN_v10/laravel-app/public/api/curriculum/save',
        $bridgePayload
    );
    if (is_array($bridgeData)) {
        echo json_encode($bridgeData);
        exit();
    }
}

$valid_programs = ['BSIndT','BSCpE','BSIT','BSCS','BSHM','BSBA-HRM','BSBA-MM','BSEd-English','BSEd-Science','BSEd-Math'];
if (!in_array($program, $valid_programs, true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid program']);
    exit();
}

if (!preg_match('/^\d{4}$/', (string)$curriculum_year) || $curriculum_year < 2017 || $curriculum_year > 2099) {
    echo json_encode(['success' => false, 'message' => 'Invalid curriculum year']);
    exit();
}

$prefix = $curriculum_year . '_';

$conn = getDBConnection();
$conn->begin_transaction();

try {
    $canonicalProgramLabel = psCanonicalProgramLabel(psNormalizeProgramKey($program));
    if ($canonicalProgramLabel === '') {
        throw new RuntimeException('Unable to resolve program label for curriculum sync.');
    }

    // Persist program + curriculum-year pair even when there are no courses yet.
    $conn->query(
        "CREATE TABLE IF NOT EXISTS program_curriculum_years (
            id INT AUTO_INCREMENT PRIMARY KEY,
            program VARCHAR(64) NOT NULL,
            curriculum_year CHAR(4) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_program_year (program, curriculum_year)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $yearStmt = $conn->prepare(
        "INSERT INTO program_curriculum_years (program, curriculum_year)
         VALUES (?, ?)
         ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP"
    );
    $yearStmt->bind_param('ss', $program, $curriculum_year);
    $yearStmt->execute();
    $yearStmt->close();

    $valid_years = ['First Year', 'Second Year', 'Third Year', 'Fourth Year'];
    $valid_semesters = ['First Semester', 'Second Semester', 'Mid Year'];

    $codeMap = [];
    foreach ($courses as $course) {
        $courseCode = strtoupper(psNormalizeCourseCode($course['course_code'] ?? ''));
        if ($courseCode === '') {
            continue;
        }
        if (!isset($codeMap[$courseCode])) {
            $codeMap[$courseCode] = [];
        }
        $codeMap[$courseCode][] = trim((string)($course['course_title'] ?? ''));
    }

    $conflicts = [];
    foreach ($codeMap as $code => $titles) {
        if (count($titles) > 1) {
            $uniqueTitles = array_values(array_unique(array_filter($titles, static fn ($value) => $value !== '')));
            $conflicts[] = count($uniqueTitles) > 1 ? ($code . ' (' . implode(' / ', $uniqueTitles) . ')') : $code;
        }
    }

    if (!empty($conflicts)) {
        echo json_encode(['success' => false, 'message' => 'Conflicting course codes found: ' . implode(', ', $conflicts)]);
        exit();
    }

    $changed = 0;
    $curriculumRowsToSync = [];

    foreach ($deleted_courses as $deletedCodeRaw) {
        $deletedToken = trim((string)$deletedCodeRaw);
        if ($deletedToken === '') {
            continue;
        }

        $lookupKey = preg_match('/^\d{4}_.+$/', $deletedToken) ? $deletedToken : ($prefix . $deletedToken);
        $find = $conn->prepare("SELECT programs FROM cvsucarmona_courses WHERE curriculumyear_coursecode = ? LIMIT 1");
        $find->bind_param('s', $lookupKey);
        $find->execute();
        $found = $find->get_result();

        if (!$found || $found->num_rows === 0) {
            $find->close();
            continue;
        }

        $row = $found->fetch_assoc();
        $find->close();

        $progList = array_values(array_filter(array_map('trim', explode(',', (string)($row['programs'] ?? '')))));
        if (!in_array($program, $progList, true)) {
            continue;
        }

        if (count($progList) === 1) {
            $deleteStmt = $conn->prepare("DELETE FROM cvsucarmona_courses WHERE curriculumyear_coursecode = ?");
            $deleteStmt->bind_param('s', $lookupKey);
            if (!$deleteStmt->execute()) {
                throw new RuntimeException('Failed to delete curriculum row: ' . $deleteStmt->error);
            }
            $deleteStmt->close();
            $changed++;
            continue;
        }

        $remainingPrograms = array_values(array_filter($progList, static fn ($value) => $value !== $program));
        $newPrograms = implode(', ', $remainingPrograms);
        $updPrograms = $conn->prepare("UPDATE cvsucarmona_courses SET programs = ? WHERE curriculumyear_coursecode = ?");
        $updPrograms->bind_param('ss', $newPrograms, $lookupKey);
        if (!$updPrograms->execute()) {
            throw new RuntimeException('Failed to update curriculum row programs: ' . $updPrograms->error);
        }
        $updPrograms->close();
        $changed++;
    }

    foreach ($courses as $course) {
        $course_code = psNormalizeCourseCode($course['course_code'] ?? '');
        $course_title = trim((string)($course['course_title'] ?? ''));
        $year_level = trim((string)($course['year_level'] ?? ''));
        $semester = trim((string)($course['semester'] ?? ''));
        $original_course_code = psNormalizeCourseCode($course['original_course_code'] ?? '');
        $original_curriculum_key = trim((string)($course['original_curriculum_key'] ?? ''));
        $curriculum_key_prefix = trim((string)($course['curriculum_key_prefix'] ?? ''));

        if ($course_code === '' || $course_title === '') {
            continue;
        }
        if (!in_array($year_level, $valid_years, true) || !in_array($semester, $valid_semesters, true)) {
            continue;
        }

        $keyPrefix = $prefix;
        if ($original_curriculum_key !== '' && preg_match('/^([^_]+)_.+$/', $original_curriculum_key, $matches)) {
            $keyPrefix = trim((string)$matches[1]);
        } elseif ($curriculum_key_prefix !== '') {
            $keyPrefix = $curriculum_key_prefix;
        }

        $key = $keyPrefix . '_' . $course_code;
        $lookupKey = $original_curriculum_key !== '' ? $original_curriculum_key : ($original_course_code !== '' ? $prefix . $original_course_code : $key);
        $hasOriginalIdentity = $original_curriculum_key !== '' || $original_course_code !== '';
        $credit_lec = (int)($course['credit_units_lec'] ?? 0);
        $credit_lab = (int)($course['credit_units_lab'] ?? 0);
        $hrs_lec = (int)($course['lect_hrs_lec'] ?? 0);
        $hrs_lab = (int)($course['lect_hrs_lab'] ?? 0);
        $prereq = trim((string)($course['pre_requisite'] ?? '')) ?: 'NONE';

        $curriculumRowsToSync[] = [
            'course_code' => $course_code,
            'course_title' => $course_title,
            'year_level' => $year_level,
            'semester' => $semester,
            'credit_units_lec' => $credit_lec,
            'credit_units_lab' => $credit_lab,
            'lect_hrs_lec' => $hrs_lec,
            'lect_hrs_lab' => $hrs_lab,
            'pre_requisite' => $prereq,
            'original_course_code' => $original_course_code,
            'original_curriculum_key' => $original_curriculum_key,
        ];

        if ($lookupKey !== $key) {
            $conflict = $conn->prepare("SELECT curriculumyear_coursecode FROM cvsucarmona_courses WHERE curriculumyear_coursecode = ? LIMIT 1");
            $conflict->bind_param('s', $key);
            $conflict->execute();
            $conflictRes = $conflict->get_result();
            if ($conflictRes && $conflictRes->num_rows > 0) {
                $conflict->close();
                throw new RuntimeException('Course code already exists for this curriculum year: ' . $course_code);
            }
            $conflict->close();
        }

        $check = $conn->prepare("SELECT curriculumyear_coursecode, programs, course_title, year_level, semester, credit_units_lec, credit_units_lab, lect_hrs_lec, lect_hrs_lab, pre_requisite FROM cvsucarmona_courses WHERE curriculumyear_coursecode = ?");
        $check->bind_param('s', $lookupKey);
        $check->execute();
        $result = $check->get_result();

        if ($result->num_rows > 0) {
            $existingRow = $result->fetch_assoc();
            $existing_progs = (string)($existingRow['programs'] ?? '');
            $prog_list = array_map('trim', explode(',', $existing_progs));
            if (!in_array($program, $prog_list, true)) {
                $prog_list[] = $program;
            }
            $new_progs = implode(', ', array_values(array_filter($prog_list)));

            $existingKey = (string)($existingRow['curriculumyear_coursecode'] ?? '');
            $needsUpdate = (
                $existingKey !== $key
                || (string)($existingRow['course_title'] ?? '') !== $course_title
                || (string)($existingRow['year_level'] ?? '') !== $year_level
                || (string)($existingRow['semester'] ?? '') !== $semester
                || (int)($existingRow['credit_units_lec'] ?? 0) !== $credit_lec
                || (int)($existingRow['credit_units_lab'] ?? 0) !== $credit_lab
                || (int)($existingRow['lect_hrs_lec'] ?? 0) !== $hrs_lec
                || (int)($existingRow['lect_hrs_lab'] ?? 0) !== $hrs_lab
                || (string)($existingRow['pre_requisite'] ?? 'NONE') !== $prereq
                || (string)($existingRow['programs'] ?? '') !== $new_progs
            );

            if ($needsUpdate) {
                $upd = $conn->prepare(
                    "UPDATE cvsucarmona_courses
                     SET curriculumyear_coursecode = ?, programs = ?, course_title = ?, year_level = ?, semester = ?, credit_units_lec = ?, credit_units_lab = ?, lect_hrs_lec = ?, lect_hrs_lab = ?, pre_requisite = ?
                     WHERE curriculumyear_coursecode = ?"
                );
                $upd->bind_param('sssssiiiiss', $key, $new_progs, $course_title, $year_level, $semester, $credit_lec, $credit_lab, $hrs_lec, $hrs_lab, $prereq, $lookupKey);
                if (!$upd->execute()) {
                    throw new RuntimeException('Failed to update curriculum row: ' . $upd->error);
                }
                $upd->close();

                if ($lookupKey !== $key && $original_course_code !== '') {
                    $studentChecklistUpd = $conn->prepare("UPDATE student_checklists SET course_code = ? WHERE course_code = ?");
                    $studentChecklistUpd->bind_param('ss', $course_code, $original_course_code);
                    if (!$studentChecklistUpd->execute()) {
                        throw new RuntimeException('Failed to update student checklist rows: ' . $studentChecklistUpd->error);
                    }
                    $studentChecklistUpd->close();

                    $studyPlanUpd = $conn->prepare("UPDATE student_study_plan_overrides SET course_code = ? WHERE course_code = ?");
                    $studyPlanUpd->bind_param('ss', $course_code, $original_course_code);
                    if (!$studyPlanUpd->execute()) {
                        throw new RuntimeException('Failed to update study plan override rows: ' . $studyPlanUpd->error);
                    }
                    $studyPlanUpd->close();
                }
                $changed++;
            }
        } else {
            if ($hasOriginalIdentity) {
                throw new RuntimeException('Unable to update course: original entry was not found. Please reload the curriculum and try again.');
            }

            $stmt = $conn->prepare(
                "INSERT INTO cvsucarmona_courses (curriculumyear_coursecode, programs, course_title, year_level, semester, credit_units_lec, credit_units_lab, lect_hrs_lec, lect_hrs_lab, pre_requisite)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param('sssssiiiis', $key, $program, $course_title, $year_level, $semester, $credit_lec, $credit_lab, $hrs_lec, $hrs_lab, $prereq);
            $stmt->execute();
            $stmt->close();
            $changed++;
        }

        $check->close();
    }

    $dupQuery = $conn->prepare(
        "SELECT UPPER(TRIM(SUBSTRING_INDEX(curriculumyear_coursecode, '_', -1))) AS normalized_code, COUNT(*) AS duplicate_count
         FROM cvsucarmona_courses
         WHERE curriculumyear_coursecode LIKE ?
           AND FIND_IN_SET(?, REPLACE(programs, ', ', ',')) > 0
         GROUP BY UPPER(TRIM(SUBSTRING_INDEX(curriculumyear_coursecode, '_', -1)))
         HAVING COUNT(*) > 1"
    );
    $likePrefix = $prefix . '%';
    $dupQuery->bind_param('ss', $likePrefix, $program);
    $dupQuery->execute();
    $dupRes = $dupQuery->get_result();
    $duplicateCodes = [];
    if ($dupRes) {
        while ($dupRow = $dupRes->fetch_assoc()) {
            $duplicateCodes[] = (string)($dupRow['normalized_code'] ?? '');
        }
    }
    $dupQuery->close();

    if (!empty($duplicateCodes)) {
        throw new RuntimeException('Duplicate course codes detected for this curriculum year: ' . implode(', ', array_values(array_unique($duplicateCodes))));
    }

    $conn->query(
        "CREATE TABLE IF NOT EXISTS curriculum_courses (
            id INT(11) NOT NULL AUTO_INCREMENT,
            curriculum_year INT(4) NOT NULL,
            program VARCHAR(255) NOT NULL,
            year_level VARCHAR(50) NOT NULL,
            semester VARCHAR(50) NOT NULL,
            course_code VARCHAR(20) NOT NULL,
            course_title VARCHAR(255) NOT NULL,
            credit_units_lec INT(2) DEFAULT 0,
            credit_units_lab INT(2) DEFAULT 0,
            lect_hrs_lec INT(2) DEFAULT 0,
            lect_hrs_lab INT(2) DEFAULT 0,
            pre_requisite VARCHAR(255) DEFAULT 'NONE',
            PRIMARY KEY (id),
            KEY curriculum_year (curriculum_year),
            KEY program (program),
            KEY course_code (course_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $canonicalProgramLabelUpper = strtoupper(trim($canonicalProgramLabel));
    $curriculumRowsByCode = [];
    $existingCurriculumRows = [];

    $existingCurriculumStmt = $conn->prepare("
        SELECT course_code, course_title, year_level, semester, credit_units_lec, credit_units_lab, lect_hrs_lec, lect_hrs_lab, pre_requisite
        FROM curriculum_courses
        WHERE curriculum_year = ? AND UPPER(TRIM(program)) = ?
        ORDER BY id
    ");
    if ($existingCurriculumStmt) {
        $existingCurriculumStmt->bind_param('is', $curriculum_year, $canonicalProgramLabelUpper);
        $existingCurriculumStmt->execute();
        $existingCurriculumRes = $existingCurriculumStmt->get_result();
        while ($existingCurriculumRes && ($existingRow = $existingCurriculumRes->fetch_assoc())) {
            $existingCurriculumRows[] = [
                'course_code' => psNormalizeCourseCode($existingRow['course_code'] ?? ''),
                'course_title' => trim((string)($existingRow['course_title'] ?? '')),
                'year_level' => trim((string)($existingRow['year_level'] ?? '')),
                'semester' => trim((string)($existingRow['semester'] ?? '')),
                'credit_units_lec' => (int)($existingRow['credit_units_lec'] ?? 0),
                'credit_units_lab' => (int)($existingRow['credit_units_lab'] ?? 0),
                'lect_hrs_lec' => (int)($existingRow['lect_hrs_lec'] ?? 0),
                'lect_hrs_lab' => (int)($existingRow['lect_hrs_lab'] ?? 0),
                'pre_requisite' => trim((string)($existingRow['pre_requisite'] ?? 'NONE')) ?: 'NONE',
            ];
        }
        $existingCurriculumStmt->close();
    }

    $legacyCurriculumRows = [];
    $legacyCurriculumStmt = $conn->prepare("
        SELECT
            TRIM(SUBSTRING_INDEX(curriculumyear_coursecode, '_', -1)) AS course_code,
            course_title,
            year_level,
            semester,
            credit_units_lec,
            credit_units_lab,
            lect_hrs_lec,
            lect_hrs_lab,
            pre_requisite
        FROM cvsucarmona_courses
        WHERE curriculumyear_coursecode LIKE ?
          AND FIND_IN_SET(?, REPLACE(programs, ', ', ',')) > 0
        ORDER BY curriculumyear_coursecode
    ");
    if ($legacyCurriculumStmt) {
        $legacyLikePrefix = $prefix . '%';
        $legacyCurriculumStmt->bind_param('ss', $legacyLikePrefix, $program);
        $legacyCurriculumStmt->execute();
        $legacyCurriculumRes = $legacyCurriculumStmt->get_result();
        while ($legacyCurriculumRes && ($legacyRow = $legacyCurriculumRes->fetch_assoc())) {
            $legacyCurriculumRows[] = [
                'course_code' => psNormalizeCourseCode($legacyRow['course_code'] ?? ''),
                'course_title' => trim((string)($legacyRow['course_title'] ?? '')),
                'year_level' => trim((string)($legacyRow['year_level'] ?? '')),
                'semester' => trim((string)($legacyRow['semester'] ?? '')),
                'credit_units_lec' => (int)($legacyRow['credit_units_lec'] ?? 0),
                'credit_units_lab' => (int)($legacyRow['credit_units_lab'] ?? 0),
                'lect_hrs_lec' => (int)($legacyRow['lect_hrs_lec'] ?? 0),
                'lect_hrs_lab' => (int)($legacyRow['lect_hrs_lab'] ?? 0),
                'pre_requisite' => trim((string)($legacyRow['pre_requisite'] ?? 'NONE')) ?: 'NONE',
            ];
        }
        $legacyCurriculumStmt->close();
    }

    $baselineCurriculumRows = count($existingCurriculumRows) >= count($legacyCurriculumRows)
        ? $existingCurriculumRows
        : $legacyCurriculumRows;

    foreach ($baselineCurriculumRows as $baselineRow) {
        $baselineCode = trim((string)($baselineRow['course_code'] ?? ''));
        if ($baselineCode === '') {
            continue;
        }
        $curriculumRowsByCode[strtoupper($baselineCode)] = $baselineRow;
    }

    foreach ($deleted_courses as $deletedCodeRaw) {
        $deletedToken = trim((string)$deletedCodeRaw);
        if ($deletedToken === '') {
            continue;
        }

        if (preg_match('/^\d{4}_(.+)$/', $deletedToken, $deletedMatches)) {
            $deletedToken = trim((string)($deletedMatches[1] ?? ''));
        }
        $deletedToken = psNormalizeCourseCode($deletedToken);

        if ($deletedToken === '') {
            continue;
        }

        unset($curriculumRowsByCode[strtoupper($deletedToken)]);
    }

    foreach ($curriculumRowsToSync as $syncRow) {
        $courseCode = psNormalizeCourseCode($syncRow['course_code'] ?? '');
        if ($courseCode === '') {
            continue;
        }

        $originalCourseCode = psNormalizeCourseCode($syncRow['original_course_code'] ?? '');
        $originalCurriculumKey = trim((string)($syncRow['original_curriculum_key'] ?? ''));
        if ($originalCourseCode === '' && preg_match('/^\d{4}_(.+)$/', $originalCurriculumKey, $originalMatches)) {
            $originalCourseCode = psNormalizeCourseCode($originalMatches[1] ?? '');
        }

        if ($originalCourseCode !== '' && strcasecmp($originalCourseCode, $courseCode) !== 0) {
            unset($curriculumRowsByCode[strtoupper($originalCourseCode)]);
        }

        $curriculumRowsByCode[strtoupper($courseCode)] = [
            'course_code' => $courseCode,
            'course_title' => trim((string)($syncRow['course_title'] ?? '')),
            'year_level' => trim((string)($syncRow['year_level'] ?? '')),
            'semester' => trim((string)($syncRow['semester'] ?? '')),
            'credit_units_lec' => (int)($syncRow['credit_units_lec'] ?? 0),
            'credit_units_lab' => (int)($syncRow['credit_units_lab'] ?? 0),
            'lect_hrs_lec' => (int)($syncRow['lect_hrs_lec'] ?? 0),
            'lect_hrs_lab' => (int)($syncRow['lect_hrs_lab'] ?? 0),
            'pre_requisite' => trim((string)($syncRow['pre_requisite'] ?? 'NONE')) ?: 'NONE',
        ];
    }

    $deleteCurriculumRows = $conn->prepare("
        DELETE FROM curriculum_courses
        WHERE curriculum_year = ? AND UPPER(TRIM(program)) = ?
    ");
    $deleteCurriculumRows->bind_param('is', $curriculum_year, $canonicalProgramLabelUpper);
    if (!$deleteCurriculumRows->execute()) {
        throw new RuntimeException('Failed to clear synced curriculum rows: ' . $deleteCurriculumRows->error);
    }
    $deleteCurriculumRows->close();

    if (!empty($curriculumRowsByCode)) {
        $syncStmt = $conn->prepare("
            INSERT INTO curriculum_courses (
                curriculum_year, program, year_level, semester, course_code, course_title,
                credit_units_lec, credit_units_lab, lect_hrs_lec, lect_hrs_lab, pre_requisite
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        if (!$syncStmt) {
            throw new RuntimeException('Failed to prepare curriculum sync: ' . $conn->error);
        }

        foreach (array_values($curriculumRowsByCode) as $syncRow) {
            $syncStmt->bind_param(
                'isssssiiiis',
                $curriculum_year,
                $canonicalProgramLabel,
                $syncRow['year_level'],
                $syncRow['semester'],
                $syncRow['course_code'],
                $syncRow['course_title'],
                $syncRow['credit_units_lec'],
                $syncRow['credit_units_lab'],
                $syncRow['lect_hrs_lec'],
                $syncRow['lect_hrs_lab'],
                $syncRow['pre_requisite']
            );
            if (!$syncStmt->execute()) {
                throw new RuntimeException('Failed to sync curriculum course: ' . $syncStmt->error);
            }
        }

        $syncStmt->close();
    }

    $conn->commit();
    $message = $changed > 0
        ? 'Curriculum changes saved successfully.'
        : 'Curriculum year saved successfully. You can add courses later.';
    echo json_encode(['success' => true, 'message' => $message, 'inserted' => $changed]);
} catch (Throwable $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => 'Failed to save curriculum: ' . $e->getMessage()]);
}

closeDBConnection($conn);
?>
