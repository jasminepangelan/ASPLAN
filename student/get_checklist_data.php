<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/program_shift_service.php';
require_once __DIR__ . '/../includes/laravel_bridge.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: 0');

$useLaravelBridge = getenv('USE_LARAVEL_BRIDGE') === '1';
$studentId = trim((string)($_GET['student_id'] ?? ''));
$programView = trim((string)($_GET['program_view'] ?? ''));

if ($studentId === '') {
    echo json_encode([
        'status' => 'error',
        'message' => 'Student ID is required',
    ]);
    exit;
}

if ($useLaravelBridge) {
    $bridgeData = postLaravelJsonBridge(
        '/api/checklist/view',
        [
            'student_id' => $studentId,
            'program_view' => $programView,
        ]
    );

    if (is_array($bridgeData) && isset($bridgeData['status'])) {
        echo json_encode($bridgeData);
        exit;
    }
}

try {
    $conn = getDBConnection();

    $progStmt = $conn->prepare("SELECT program, curriculum_year FROM student_info WHERE student_number = ?");
    $progStmt->bind_param("s", $studentId);
    $progStmt->execute();
    $progResult = $progStmt->get_result();
    $studentProgram = '';
    if ($progRow = $progResult->fetch_assoc()) {
        $studentProgram = $progRow['program'] ?? '';
    }
    $progStmt->close();

    $normalizeProgramLabel = static function ($value) {
        $value = strtoupper(trim((string) $value));
        $value = preg_replace('/[^A-Z0-9]+/', ' ', $value);
        return trim(preg_replace('/\s+/', ' ', (string) $value));
    };

    $resolveProgramAbbreviation = static function ($programName) use ($normalizeProgramLabel) {
        $normalized = $normalizeProgramLabel($programName);
        if ($normalized === '') {
            return '';
        }

        $abbrAliases = [
            'BSBA MM' => 'BSBA-MM',
            'BSBA HRM' => 'BSBA-HRM',
            'BSCPE' => 'BSCpE',
            'BSCS' => 'BSCS',
            'BSHM' => 'BSHM',
            'BSINDT' => 'BSIndT',
            'BSIT' => 'BSIT',
            'BSED ENGLISH' => 'BSEd-English',
            'BSED MATH' => 'BSEd-Math',
            'BSED SCIENCE' => 'BSEd-Science',
        ];
        if (isset($abbrAliases[$normalized])) {
            return $abbrAliases[$normalized];
        }

        if (strpos($normalized, 'BUSINESS ADMINISTRATION') !== false && strpos($normalized, 'MARKETING') !== false) {
            return 'BSBA-MM';
        }
        if (strpos($normalized, 'BUSINESS ADMINISTRATION') !== false &&
            (strpos($normalized, 'HUMAN RESOURCE') !== false || strpos($normalized, 'HRM') !== false)) {
            return 'BSBA-HRM';
        }

        $programMap = [
            'Bachelor of Science in Business Administration - Major in Marketing Management' => 'BSBA-MM',
            'Bachelor of Science in Business Administration - Major in Human Resource Management' => 'BSBA-HRM',
            'Bachelor of Science in Computer Engineering' => 'BSCpE',
            'Bachelor of Science in Computer Science' => 'BSCS',
            'Bachelor of Science in Hospitality Management' => 'BSHM',
            'Bachelor of Science in Industrial Technology' => 'BSIndT',
            'Bachelor of Science in Information Technology' => 'BSIT',
            'Bachelor of Secondary Education major in English' => 'BSEd-English',
            'Bachelor of Secondary Education major Math' => 'BSEd-Math',
            'Bachelor of Secondary Education major in Science' => 'BSEd-Science',
        ];

        foreach ($programMap as $label => $abbr) {
            if ($normalizeProgramLabel($label) === $normalized) {
                return $abbr;
            }
        }

        return '';
    };

    $programAbbr = $resolveProgramAbbreviation($studentProgram);
    if ($programView !== '') {
        $programAbbr = $programView;
    }

    $selectedProgramLabel = $studentProgram;
    $canonicalProgramLabel = psCanonicalProgramLabel(psNormalizeProgramKey((string) $programAbbr));
    if ($canonicalProgramLabel !== '') {
        $selectedProgramLabel = $canonicalProgramLabel;
    }

    if ($programAbbr === '') {
        echo json_encode([
            'status' => 'success',
            'courses' => [],
        ]);
        exit;
    }

    $courses = psFetchChecklistCourses($conn, $studentId, $selectedProgramLabel, $programAbbr);

    echo json_encode([
        'status' => 'success',
        'courses' => $courses,
    ]);
} catch (Throwable $e) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Failed to load checklist data',
    ]);
} finally {
    if (isset($conn) && $conn) {
        closeDBConnection($conn);
    }
}

