<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/laravel_bridge.php';
require_once __DIR__ . '/../includes/program_shift_service.php';

header('Content-Type: application/json');

if (!(isset($_SESSION['username']) && (!isset($_SESSION['user_type']) || $_SESSION['user_type'] === 'program_coordinator'))
    && !isset($_SESSION['admin_username'])
    && !isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || empty($input['program']) || empty($input['curriculum_year'])) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit();
}

$program = trim((string)$input['program']);
$curriculumYear = trim((string)$input['curriculum_year']);

if (!function_exists('pcDeleteNormalizeCurriculumYearToken')) {
    function pcDeleteNormalizeCurriculumYearToken(string $value): string {
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

$useLaravelBridge = getenv('USE_LARAVEL_BRIDGE') === '1';
if ($useLaravelBridge) {
    $bridgePayload = $input;
    $bridgePayload['bridge_authorized'] = true;

    $bridgeData = postLaravelJsonBridge(
        '/api/curriculum/delete-year',
        $bridgePayload
    );
    if (is_array($bridgeData)) {
        echo json_encode($bridgeData);
        exit();
    }
}

$normalizedProgramKey = strtoupper(trim((string)psNormalizeProgramKey($program)));
if ($normalizedProgramKey === '') {
    echo json_encode(['success' => false, 'message' => 'Invalid program']);
    exit();
}
$program = $normalizedProgramKey;

if (!preg_match('/^\d{4}$/', $curriculumYear) || (int)$curriculumYear < 2017 || (int)$curriculumYear > 2099) {
    echo json_encode(['success' => false, 'message' => 'Invalid curriculum year']);
    exit();
}

$conn = getDBConnection();
$conn->begin_transaction();

try {
    $canonicalProgramLabel = psCanonicalProgramLabel(psNormalizeProgramKey($program));
    if ($canonicalProgramLabel === '') {
        throw new RuntimeException('Unable to resolve program label for curriculum deletion.');
    }

    $existing = null;
    $existingStmt = $conn->prepare("SELECT curriculumyear_coursecode, programs FROM cvsucarmona_courses");
    if ($existingStmt) {
        $existingStmt->execute();
        $existing = $existingStmt->get_result();
    }

    if (!$existing) {
        throw new RuntimeException('Failed to load existing curriculum rows.');
    }

    while ($row = $existing->fetch_assoc()) {
        $rowKey = (string)($row['curriculumyear_coursecode'] ?? '');
        $rowPrefix = explode('_', $rowKey, 2)[0] ?? '';
        if (pcDeleteNormalizeCurriculumYearToken($rowPrefix) !== $curriculumYear) {
            continue;
        }

        $progs = array_map('trim', explode(',', (string)$row['programs']));
        $rowMatched = false;
        foreach ($progs as $programToken) {
            if (strtoupper(trim((string)psNormalizeProgramKey($programToken))) === $program) {
                $rowMatched = true;
                break;
            }
        }
        if (!$rowMatched) {
            continue;
        }

        if (count($progs) === 1) {
            $stmt = $conn->prepare('DELETE FROM cvsucarmona_courses WHERE curriculumyear_coursecode = ?');
            $stmt->bind_param('s', $rowKey);
            $stmt->execute();
            $stmt->close();
        } else {
            $progs = array_values(array_filter($progs, function ($p) use ($program) {
                return strtoupper(trim((string)psNormalizeProgramKey($p))) !== $program;
            }));
            $newPrograms = implode(', ', $progs);
            $stmt = $conn->prepare('UPDATE cvsucarmona_courses SET programs = ? WHERE curriculumyear_coursecode = ?');
            $stmt->bind_param('ss', $newPrograms, $rowKey);
            $stmt->execute();
            $stmt->close();
        }
    }
    if (isset($existingStmt) && $existingStmt instanceof mysqli_stmt) {
        $existingStmt->close();
    }

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

    $programCandidatesToDelete = psResolveChecklistProgramLabels($canonicalProgramLabel, $program);
    $programCandidatesToDelete[] = $program;
    $programCandidatesToDelete[] = strtoupper(trim($canonicalProgramLabel));
    $programCandidatesToDelete = array_values(array_unique(array_filter($programCandidatesToDelete, static fn($value) => $value !== '')));

    foreach ($programCandidatesToDelete as $deleteProgram) {
        $deleteYearStmt = $conn->prepare('DELETE FROM program_curriculum_years WHERE program = ? AND curriculum_year = ?');
        $deleteYearStmt->bind_param('ss', $deleteProgram, $curriculumYear);
        $deleteYearStmt->execute();
        $deleteYearStmt->close();
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

    $programCandidatesToDelete = psResolveChecklistProgramLabels($canonicalProgramLabel, $program);
    $programCandidatesToDelete[] = $program;
    $programCandidatesToDelete[] = strtoupper(trim($canonicalProgramLabel));
    $programCandidatesToDelete = array_values(array_unique(array_filter($programCandidatesToDelete, static fn($value) => $value !== '')));

    if (empty($programCandidatesToDelete)) {
        throw new RuntimeException('Failed to resolve program labels for curriculum deletion.');
    }

    $deleteConditions = array_fill(0, count($programCandidatesToDelete), 'UPPER(TRIM(program)) = ?');
    $deleteSql = 'DELETE FROM curriculum_courses WHERE curriculum_year = ? AND (' . implode(' OR ', $deleteConditions) . ')';
    $deleteCurriculumRows = $conn->prepare($deleteSql);
    if (!$deleteCurriculumRows) {
        throw new RuntimeException('Failed to prepare curriculum cleanup: ' . $conn->error);
    }

    $deleteTypes = 'i' . str_repeat('s', count($programCandidatesToDelete));
    $deleteParams = array_merge([(int)$curriculumYear], $programCandidatesToDelete);
    $deleteCurriculumRows->bind_param($deleteTypes, ...$deleteParams);
    $deleteCurriculumRows->execute();
    $deleteCurriculumRows->close();

    $conn->commit();
    echo json_encode(['success' => true, 'message' => 'Curriculum year deleted successfully.']);
} catch (Throwable $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => 'Failed to delete curriculum year: ' . $e->getMessage()]);
}

closeDBConnection($conn);
?>

