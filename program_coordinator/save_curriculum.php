<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/program_shift_service.php';
require_once __DIR__ . '/../includes/laravel_bridge.php';
require_once __DIR__ . '/../includes/program_catalog.php';

header('Content-Type: application/json');

if (!function_exists('pcSaveNormalizeCurriculumYearToken')) {
    function pcSaveNormalizeCurriculumYearToken(string $value): string {
        $token = strtoupper(trim($value));
        if ($token === '') {
            return '';
        }

        if (preg_match('/^(\d{2})V\d+$/', $token, $matches)) {
            return '20' . $matches[1];
        }

        if (preg_match('/^\d{4}$/', $token)) {
            return $token;
        }

        return '';
    }
}

if (!function_exists('pcSaveNormalizeEditableCourseCode')) {
    function pcSaveNormalizeEditableCourseCode(string $value): string {
        return preg_replace('/\s+/', ' ', trim($value)) ?: '';
    }
}

if (!function_exists('pcSaveResolveCurriculumLookupKey')) {
    function pcSaveResolveCurriculumLookupKey(string $token, string $selectedYear, string $fallbackPrefix): string {
        $token = trim($token);
        if ($token === '') {
            return '';
        }

        $separator = strpos($token, '_');
        if ($separator !== false && $separator > 0) {
            $tokenPrefix = substr($token, 0, $separator);
            if (pcSaveNormalizeCurriculumYearToken($tokenPrefix) === (string)$selectedYear) {
                return $token;
            }
        }

        return $fallbackPrefix . $token;
    }
}

if (!function_exists('pcSaveExtractCourseCodeFromCurriculumKey')) {
    function pcSaveExtractCourseCodeFromCurriculumKey(string $value, string $selectedYear = ''): string {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $separator = strpos($value, '_');
        if ($separator !== false && $separator > 0) {
            $tokenPrefix = substr($value, 0, $separator);
            if ($selectedYear === '' || pcSaveNormalizeCurriculumYearToken($tokenPrefix) === (string)$selectedYear) {
                return pcSaveNormalizeEditableCourseCode(substr($value, $separator + 1));
            }
        }

        return pcSaveNormalizeEditableCourseCode($value);
    }
}

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
        '/api/curriculum/save',
        $bridgePayload
    );
    if (is_array($bridgeData)) {
        echo json_encode($bridgeData);
        exit();
    }
}

$conn = getDBConnection();
$valid_programs = array_keys(pcLoadProgramCatalog($conn, true));
if (!in_array($program, $valid_programs, true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid program']);
    closeDBConnection($conn);
    exit();
}

if (!preg_match('/^\d{4}$/', (string)$curriculum_year) || $curriculum_year < 2017 || $curriculum_year > 2099) {
    echo json_encode(['success' => false, 'message' => 'Invalid curriculum year']);
    exit();
}

$prefix = $curriculum_year . '_';

$conn->begin_transaction();

try {
    $programCatalog = pcLoadProgramCatalog($conn, true);
    $canonicalProgramLabel = (string) ($programCatalog[$program] ?? psCanonicalProgramLabel(psNormalizeProgramKey($program)));
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
        $courseCode = strtoupper(pcSaveNormalizeEditableCourseCode((string)($course['course_code'] ?? '')));
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
    if (!function_exists('pcSaveFindLegacyCurriculumKey')) {
        function pcSaveFindLegacyCurriculumKey(mysqli $conn, string $program, string $curriculumYear, string $courseCode): string {
            $courseCode = pcSaveNormalizeEditableCourseCode($courseCode);
            $curriculumYear = pcSaveNormalizeCurriculumYearToken($curriculumYear);
            if ($courseCode === '' || $curriculumYear === '') {
                return '';
            }

            $sql = "
                SELECT curriculumyear_coursecode
                FROM cvsucarmona_courses
                WHERE UPPER(TRIM(SUBSTRING(curriculumyear_coursecode, LOCATE('_', curriculumyear_coursecode) + 1))) = UPPER(?)
                  AND FIND_IN_SET(?, REPLACE(programs, ', ', ',')) > 0
                  AND (
                    CASE
                      WHEN SUBSTRING_INDEX(curriculumyear_coursecode, '_', 1) REGEXP '^[0-9]{2}V[0-9]+$'
                        THEN CONCAT('20', LEFT(SUBSTRING_INDEX(curriculumyear_coursecode, '_', 1), 2))
                      ELSE SUBSTRING_INDEX(curriculumyear_coursecode, '_', 1)
                    END
                  ) = ?
                ORDER BY curriculumyear_coursecode
                LIMIT 1
            ";

            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                return '';
            }
            $stmt->bind_param('sss', $courseCode, $program, $curriculumYear);
            $stmt->execute();
            $res = $stmt->get_result();
            $key = '';
            if ($res && $res->num_rows > 0) {
                $row = $res->fetch_assoc();
                $key = trim((string)($row['curriculumyear_coursecode'] ?? ''));
            }
            $stmt->close();
            return $key;
        }
    }

    if (!empty($conflicts)) {
        echo json_encode(['success' => false, 'message' => 'Conflicting course codes found: ' . implode(', ', $conflicts)]);
        $conn->rollback();
        closeDBConnection($conn);
        exit();
    }

    // Pre-check: detect duplicates that already exist in the database for this program + year
    $preCheckDupQuery = $conn->prepare(
        "SELECT UPPER(TRIM(SUBSTRING(curriculumyear_coursecode, LOCATE('_', curriculumyear_coursecode) + 1))) AS normalized_code, COUNT(*) AS dup_count
         FROM cvsucarmona_courses
         WHERE curriculumyear_coursecode LIKE ?
           AND FIND_IN_SET(?, REPLACE(programs, ', ', ',')) > 0
         GROUP BY UPPER(TRIM(SUBSTRING(curriculumyear_coursecode, LOCATE('_', curriculumyear_coursecode) + 1)))
         HAVING dup_count > 1"
    );
    if (!$preCheckDupQuery) {
        throw new RuntimeException('Failed to prepare pre-check duplicate query: ' . $conn->error);
    }
    $likePrefix = $prefix . '%';
    $preCheckDupQuery->bind_param('ss', $likePrefix, $program);
    $preCheckDupQuery->execute();
    $preCheckDupRes = $preCheckDupQuery->get_result();
    $existingDuplicates = [];
    if ($preCheckDupRes) {
        while ($dupRow = $preCheckDupRes->fetch_assoc()) {
            $existingDuplicates[] = (string)($dupRow['normalized_code'] ?? '');
        }
    }
    $preCheckDupQuery->close();

    if (!empty($existingDuplicates)) {
        echo json_encode(['success' => false, 'message' => 'Database integrity error: Duplicate course codes already exist: ' . implode(', ', array_values(array_unique($existingDuplicates))) . '. Please contact support to fix these duplicates before proceeding.']);
        $conn->rollback();
        closeDBConnection($conn);
        exit();
    }

    $changed = 0;
    $curriculumRowsToSync = [];
    $processedNormalizedCodes = [];

    foreach ($deleted_courses as $deletedCodeRaw) {
        $deletedToken = trim((string)$deletedCodeRaw);
        if ($deletedToken === '') {
            continue;
        }

        $lookupKey = pcSaveResolveCurriculumLookupKey($deletedToken, (string)$curriculum_year, $prefix);
        if ($lookupKey === '') {
            continue;
        }

        $find = $conn->prepare("SELECT programs FROM cvsucarmona_courses WHERE curriculumyear_coursecode = ? LIMIT 1");
        if (!$find) {
            throw new RuntimeException('Failed to prepare delete lookup: ' . $conn->error);
        }
        $find->bind_param('s', $lookupKey);
        $find->execute();
        $found = $find->get_result();

        if (!$found || $found->num_rows === 0) {
            $find->close();

            $deletedCourseCode = pcSaveExtractCourseCodeFromCurriculumKey($deletedToken, (string)$curriculum_year);
            $fallbackKey = pcSaveFindLegacyCurriculumKey($conn, $program, (string)$curriculum_year, $deletedCourseCode);
            if ($fallbackKey === '') {
                continue;
            }

            $lookupKey = $fallbackKey;
            $find = $conn->prepare("SELECT programs FROM cvsucarmona_courses WHERE curriculumyear_coursecode = ? LIMIT 1");
            if (!$find) {
                throw new RuntimeException('Failed to prepare delete fallback lookup: ' . $conn->error);
            }
            $find->bind_param('s', $lookupKey);
            $find->execute();
            $found = $find->get_result();
            if (!$found || $found->num_rows === 0) {
                $find->close();
                continue;
            }
        }

        $row = $found->fetch_assoc();
        $find->close();

        $progList = array_values(array_filter(array_map('trim', explode(',', (string)($row['programs'] ?? '')))));
        if (!in_array($program, $progList, true)) {
            continue;
        }

        if (count($progList) === 1) {
            $deleteStmt = $conn->prepare("DELETE FROM cvsucarmona_courses WHERE curriculumyear_coursecode = ?");
            if (!$deleteStmt) {
                throw new RuntimeException('Failed to prepare delete statement: ' . $conn->error);
            }
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
        if (!$updPrograms) {
            throw new RuntimeException('Failed to prepare program update: ' . $conn->error);
        }
        $updPrograms->bind_param('ss', $newPrograms, $lookupKey);
        if (!$updPrograms->execute()) {
            throw new RuntimeException('Failed to update curriculum row programs: ' . $updPrograms->error);
        }
        $updPrograms->close();
        $changed++;
    }

    foreach ($courses as $course) {
        $course_code = pcSaveNormalizeEditableCourseCode((string)($course['course_code'] ?? ''));
        $course_title = trim((string)($course['course_title'] ?? ''));
        $year_level = trim((string)($course['year_level'] ?? ''));
        $semester = trim((string)($course['semester'] ?? ''));
        $original_course_code = pcSaveNormalizeEditableCourseCode((string)($course['original_course_code'] ?? ''));
        $original_curriculum_key = trim((string)($course['original_curriculum_key'] ?? ''));
        $curriculum_key_prefix = trim((string)($course['curriculum_key_prefix'] ?? ''));

        if ($course_code === '' || $course_title === '') {
            continue;
        }
        if (!in_array($year_level, $valid_years, true) || !in_array($semester, $valid_semesters, true)) {
            continue;
        }

        $keyPrefix = rtrim($prefix, '_');
        $originalKeyBelongsToSelectedYear = false;
        if ($original_curriculum_key !== '' && preg_match('/^([^_]+)_.+$/', $original_curriculum_key, $matches)) {
            $originalKeyPrefix = trim((string)$matches[1]);
            if (pcSaveNormalizeCurriculumYearToken($originalKeyPrefix) === (string)$curriculum_year) {
                $keyPrefix = $originalKeyPrefix;
                $originalKeyBelongsToSelectedYear = true;
            }
        }
        if (!$originalKeyBelongsToSelectedYear && $curriculum_key_prefix !== ''
            && pcSaveNormalizeCurriculumYearToken($curriculum_key_prefix) === (string)$curriculum_year) {
            $keyPrefix = $curriculum_key_prefix;
        }

        $key = $keyPrefix . '_' . $course_code;
        $lookupKey = $originalKeyBelongsToSelectedYear
            ? $original_curriculum_key
            : ($original_course_code !== '' ? ($keyPrefix . '_' . $original_course_code) : $key);
        $hasOriginalIdentity = $originalKeyBelongsToSelectedYear
            || ($original_curriculum_key === '' && $original_course_code !== '');
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

        // Track normalized course codes processed in this save operation
        $normCode = strtoupper(trim((string)$course_code));
        if ($normCode !== '') {
            $processedNormalizedCodes[$normCode] = $key; // store preferred key for this code
        }

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

        if (($result === false || $result->num_rows === 0) && $hasOriginalIdentity) {
            $fallbackOriginalCode = $original_course_code !== ''
                ? $original_course_code
                : pcSaveExtractCourseCodeFromCurriculumKey($original_curriculum_key, (string)$curriculum_year);
            $fallbackKey = pcSaveFindLegacyCurriculumKey($conn, $program, (string)$curriculum_year, $fallbackOriginalCode);
            if ($fallbackKey !== '' && $fallbackKey !== $lookupKey) {
                $check->close();
                $lookupKey = $fallbackKey;
                $check = $conn->prepare("SELECT curriculumyear_coursecode, programs, course_title, year_level, semester, credit_units_lec, credit_units_lab, lect_hrs_lec, lect_hrs_lab, pre_requisite FROM cvsucarmona_courses WHERE curriculumyear_coursecode = ?");
                $check->bind_param('s', $lookupKey);
                $check->execute();
                $result = $check->get_result();
            }
        }

        // If we still don't have a match, try a more forgiving legacy lookup by course code alone.
        // This helps catch cases where the stored key used a different prefix/token but the
        // course code is the same — avoid inserting a duplicate when the intent was an edit.
        if (($result === false || $result->num_rows === 0)) {
            $fallbackByCode = pcSaveFindLegacyCurriculumKey($conn, $program, (string)$curriculum_year, $course_code);
            if ($fallbackByCode !== '' && $fallbackByCode !== $lookupKey) {
                $check->close();
                $lookupKey = $fallbackByCode;
                $check = $conn->prepare("SELECT curriculumyear_coursecode, programs, course_title, year_level, semester, credit_units_lec, credit_units_lab, lect_hrs_lec, lect_hrs_lab, pre_requisite FROM cvsucarmona_courses WHERE curriculumyear_coursecode = ?");
                $check->bind_param('s', $lookupKey);
                $check->execute();
                $result = $check->get_result();
            }
        }

        if ($result && $result->num_rows > 0) {
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
            // Before inserting, do one more forgiving lookup by course code to avoid creating
            // a duplicate legacy row when an existing row uses a different prefix token.
            $existingFallbackKey = pcSaveFindLegacyCurriculumKey($conn, $program, (string)$curriculum_year, $course_code);
            if ($existingFallbackKey !== '') {
                $progFind = $conn->prepare("SELECT programs FROM cvsucarmona_courses WHERE curriculumyear_coursecode = ? LIMIT 1");
                if ($progFind) {
                    $progFind->bind_param('s', $existingFallbackKey);
                    $progFind->execute();
                    $progRes = $progFind->get_result();
                    $existingProgramsCsv = '';
                    if ($progRes && ($prow = $progRes->fetch_assoc())) {
                        $existingProgramsCsv = (string)($prow['programs'] ?? '');
                    }
                    $progFind->close();

                    $progList = array_values(array_filter(array_map('trim', explode(',', $existingProgramsCsv))));
                    if (!in_array($program, $progList, true)) {
                        $progList[] = $program;
                    }
                    $newProgramsCsv = implode(', ', $progList);

                    $upd = $conn->prepare(
                        "UPDATE cvsucarmona_courses
                         SET curriculumyear_coursecode = ?, programs = ?, course_title = ?, year_level = ?, semester = ?, credit_units_lec = ?, credit_units_lab = ?, lect_hrs_lec = ?, lect_hrs_lab = ?, pre_requisite = ?
                         WHERE curriculumyear_coursecode = ?"
                    );
                    if ($upd) {
                        $upd->bind_param('sssssiiiiss', $key, $newProgramsCsv, $course_title, $year_level, $semester, $credit_lec, $credit_lab, $hrs_lec, $hrs_lab, $prereq, $existingFallbackKey);
                        if (!$upd->execute()) {
                            throw new RuntimeException('Failed to update legacy curriculum row (fallback): ' . $upd->error);
                        }
                        $upd->close();

                        // If the legacy key changed, update student references
                        if ($existingFallbackKey !== $key && $original_course_code !== '') {
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
                }
            } else {
                // Final check: ensure no duplicate exists before inserting
                $finalCheck = $conn->prepare(
                    "SELECT curriculumyear_coursecode FROM cvsucarmona_courses 
                     WHERE UPPER(TRIM(SUBSTRING(curriculumyear_coursecode, LOCATE('_', curriculumyear_coursecode) + 1))) = ?
                       AND FIND_IN_SET(?, REPLACE(programs, ', ', ',')) > 0
                     LIMIT 1"
                );
                if (!$finalCheck) {
                    throw new RuntimeException('Failed to prepare final duplicate check: ' . $conn->error);
                }
                $normCourseCode = strtoupper(trim($course_code));
                $finalCheck->bind_param('ss', $normCourseCode, $program);
                $finalCheck->execute();
                $finalCheckRes = $finalCheck->get_result();
                
                if ($finalCheckRes && $finalCheckRes->num_rows === 0) {
                    $stmt = $conn->prepare(
                        "INSERT INTO cvsucarmona_courses (curriculumyear_coursecode, programs, course_title, year_level, semester, credit_units_lec, credit_units_lab, lect_hrs_lec, lect_hrs_lab, pre_requisite)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                    );
                    if (!$stmt) {
                        throw new RuntimeException('Failed to prepare insert statement: ' . $conn->error);
                    }
                    $stmt->bind_param('sssssiiiis', $key, $program, $course_title, $year_level, $semester, $credit_lec, $credit_lab, $hrs_lec, $hrs_lab, $prereq);
                    if (!$stmt->execute()) {
                        throw new RuntimeException('Failed to insert curriculum row: ' . $stmt->error);
                    }
                    $stmt->close();
                    $changed++;
                }
                $finalCheck->close();
            }
        }

        $check->close();
    }

            // Cleanup: for any normalized course codes processed, ensure there are no duplicate rows
            // visible for this program in the legacy cvsucarmona_courses table. If duplicates exist,
            // remove this program token from the non-preferred rows (or delete empty rows).
            foreach ($processedNormalizedCodes as $normCode => $preferredKey) {
                $findDupStmt = $conn->prepare(
                    "SELECT curriculumyear_coursecode, programs
                     FROM cvsucarmona_courses
                     WHERE UPPER(TRIM(SUBSTRING(curriculumyear_coursecode, LOCATE('_', curriculumyear_coursecode) + 1))) = ?
                       AND FIND_IN_SET(?, REPLACE(programs, ', ', ',')) > 0"
                );
                if (!$findDupStmt) continue;
                $findDupStmt->bind_param('ss', $normCode, $program);
                $findDupStmt->execute();
                $dupRes = $findDupStmt->get_result();
                $rowsToAdjust = [];
                if ($dupRes) {
                    while ($r = $dupRes->fetch_assoc()) {
                        $rowsToAdjust[] = $r;
                    }
                }
                $findDupStmt->close();

                if (count($rowsToAdjust) <= 1) {
                    continue;
                }

                // Prefer the row matching the preferred key; if not present, keep the first and adjust others.
                $keepKey = $preferredKey;
                $foundKeep = false;
                foreach ($rowsToAdjust as $r) {
                    if (trim((string)$r['curriculumyear_coursecode']) === $keepKey) {
                        $foundKeep = true;
                        break;
                    }
                }
                if (!$foundKeep) {
                    $keepKey = (string)($rowsToAdjust[0]['curriculumyear_coursecode'] ?? '');
                }

                foreach ($rowsToAdjust as $r) {
                    $rowKey = (string)$r['curriculumyear_coursecode'];
                    $rowPrograms = (string)$r['programs'];
                    if ($rowKey === $keepKey) {
                        continue;
                    }

                    // Remove the program token from this row's programs list.
                    $progList = array_values(array_filter(array_map('trim', explode(',', $rowPrograms))));
                    $newProgList = array_values(array_filter($progList, static fn($v) => strtoupper(trim($v)) !== strtoupper(trim($program))));
                    $newPrograms = implode(', ', $newProgList);

                    if ($newPrograms === '') {
                        $del = $conn->prepare("DELETE FROM cvsucarmona_courses WHERE curriculumyear_coursecode = ?");
                        if ($del) {
                            $del->bind_param('s', $rowKey);
                            $del->execute();
                            $del->close();
                        }
                    } else {
                        $upd = $conn->prepare("UPDATE cvsucarmona_courses SET programs = ? WHERE curriculumyear_coursecode = ?");
                        if ($upd) {
                            $upd->bind_param('ss', $newPrograms, $rowKey);
                            $upd->execute();
                            $upd->close();
                        }
                    }
                }
            }

                $dupQuery = $conn->prepare(
                "SELECT UPPER(TRIM(SUBSTRING(curriculumyear_coursecode, LOCATE('_', curriculumyear_coursecode) + 1))) AS normalized_code, COUNT(*) AS duplicate_count
                 FROM cvsucarmona_courses
                 WHERE curriculumyear_coursecode LIKE ?
                     AND FIND_IN_SET(?, REPLACE(programs, ', ', ',')) > 0
                 GROUP BY UPPER(TRIM(SUBSTRING(curriculumyear_coursecode, LOCATE('_', curriculumyear_coursecode) + 1)))
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
                'course_code' => pcSaveNormalizeEditableCourseCode((string)($existingRow['course_code'] ?? '')),
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
            TRIM(SUBSTRING(curriculumyear_coursecode, LOCATE('_', curriculumyear_coursecode) + 1)) AS course_code,
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
                'course_code' => pcSaveNormalizeEditableCourseCode((string)($legacyRow['course_code'] ?? '')),
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

        $deletedToken = pcSaveExtractCourseCodeFromCurriculumKey($deletedToken, (string)$curriculum_year);

        if ($deletedToken === '') {
            continue;
        }

        unset($curriculumRowsByCode[strtoupper($deletedToken)]);
    }

    foreach ($curriculumRowsToSync as $syncRow) {
        $courseCode = pcSaveNormalizeEditableCourseCode((string)($syncRow['course_code'] ?? ''));
        if ($courseCode === '') {
            continue;
        }

        $originalCourseCode = pcSaveNormalizeEditableCourseCode((string)($syncRow['original_course_code'] ?? ''));
        $originalCurriculumKey = trim((string)($syncRow['original_curriculum_key'] ?? ''));
        if ($originalCourseCode === '') {
            $originalCourseCode = pcSaveExtractCourseCodeFromCurriculumKey($originalCurriculumKey, (string)$curriculum_year);
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

    $programCandidatesToDelete = [];
    $programCandidatesToDelete[] = strtoupper(trim((string)$program));
    $programCandidatesToDelete[] = $canonicalProgramLabelUpper;
    $legacyCanonicalProgram = strtoupper(trim((string)psCanonicalProgramLabel(psNormalizeProgramKey($program))));
    if ($legacyCanonicalProgram !== '' && $legacyCanonicalProgram !== $canonicalProgramLabelUpper) {
        $programCandidatesToDelete[] = $legacyCanonicalProgram;
    }
    $programCandidatesToDelete = array_values(array_unique(array_filter($programCandidatesToDelete, static fn($value) => $value !== '')));

    if (empty($programCandidatesToDelete)) {
        throw new RuntimeException('Failed to resolve program label patterns for curriculum sync.');
    }

    $deleteConditions = array_fill(0, count($programCandidatesToDelete), 'UPPER(TRIM(program)) = ?');
    $deleteSql = "DELETE FROM curriculum_courses WHERE curriculum_year = ? AND (" . implode(' OR ', $deleteConditions) . ")";
    $deleteCurriculumRows = $conn->prepare($deleteSql);
    if (!$deleteCurriculumRows) {
        throw new RuntimeException('Failed to prepare curriculum cleanup: ' . $conn->error);
    }

    $deleteTypes = 'i' . str_repeat('s', count($programCandidatesToDelete));
    $deleteParams = array_merge([$curriculum_year], $programCandidatesToDelete);
    $deleteCurriculumRows->bind_param($deleteTypes, ...$deleteParams);
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

