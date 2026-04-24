<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/program_catalog.php';

$isAdmin = isset($_SESSION['admin_username']) || isset($_SESSION['admin_id']);
$isProgramCoordinator = isset($_SESSION['username']) && (!isset($_SESSION['user_type']) || $_SESSION['user_type'] === 'program_coordinator');

if (!$isAdmin && !$isProgramCoordinator) {
  header("Location: ../index.html");
  exit();
}

$coordinator_name = '';
if ($isAdmin) {
  $coordinator_name = 'Admin Panel';
} else {
  $coordinator_name = isset($_SESSION['full_name']) ? htmlspecialchars((string)$_SESSION['full_name']) : 'Program Coordinator';
}

$conn = getDBConnection();

function resolveProgramCoordinatorTable($conn): ?string {
  $singular = $conn->query("SHOW TABLES LIKE 'program_coordinator'");
  if ($singular && $singular->num_rows > 0) {
    return 'program_coordinator';
  }

  $plural = $conn->query("SHOW TABLES LIKE 'program_coordinators'");
  if ($plural && $plural->num_rows > 0) {
    return 'program_coordinators';
  }

  return null;
}

function tableHasColumn($conn, string $table, string $column): bool {
  $tableSafe = $conn->real_escape_string($table);
  $columnSafe = $conn->real_escape_string($column);
  $result = $conn->query("SHOW COLUMNS FROM `$tableSafe` LIKE '$columnSafe'");
  return $result && $result->num_rows > 0;
}

function tableExists($conn, string $table): bool {
  $tableSafe = $conn->real_escape_string($table);
  $result = $conn->query("SHOW TABLES LIKE '$tableSafe'");
  return $result && $result->num_rows > 0;
}

function normalizeProgramCode(string $program): string {
  $catalogCode = pcNormalizeProgramCode($program);
  if ($catalogCode !== '') {
    return $catalogCode;
  }

  $p = strtoupper(trim($program));
  if ($p === '') {
    return '';
  }

  // Already in supported code format.
  $directMap = [
    'BSINDT' => 'BSIndT',
    'BSCPE' => 'BSCpE',
    'BSCPE ' => 'BSCpE',
    'BSIT' => 'BSIT',
    'BSCS' => 'BSCS',
    'BSHM' => 'BSHM',
    'BSBA-HRM' => 'BSBA-HRM',
    'BSBA-MM' => 'BSBA-MM',
    'BSED-ENGLISH' => 'BSEd-English',
    'BSED-SCIENCE' => 'BSEd-Science',
    'BSED-MATH' => 'BSEd-Math',
  ];

  if (isset($directMap[$p])) {
    return $directMap[$p];
  }

  if (strpos($p, 'COMPUTER SCIENCE') !== false) {
    return 'BSCS';
  }
  if (strpos($p, 'INFORMATION TECHNOLOGY') !== false) {
    return 'BSIT';
  }
  if (strpos($p, 'COMPUTER ENGINEERING') !== false) {
    return 'BSCpE';
  }
  if (strpos($p, 'INDUSTRIAL TECHNOLOGY') !== false) {
    return 'BSIndT';
  }
  if (strpos($p, 'HOSPITALITY MANAGEMENT') !== false) {
    return 'BSHM';
  }
  if (strpos($p, 'BUSINESS ADMINISTRATION') !== false && strpos($p, 'HUMAN RESOURCE') !== false) {
    return 'BSBA-HRM';
  }
  if (strpos($p, 'BUSINESS ADMINISTRATION') !== false && strpos($p, 'MARKETING') !== false) {
    return 'BSBA-MM';
  }
  if (strpos($p, 'SECONDARY EDUCATION') !== false && strpos($p, 'ENGLISH') !== false) {
    return 'BSEd-English';
  }
  if (strpos($p, 'SECONDARY EDUCATION') !== false && strpos($p, 'SCIENCE') !== false) {
    return 'BSEd-Science';
  }
  if (strpos($p, 'SECONDARY EDUCATION') !== false && strpos($p, 'MATH') !== false) {
    return 'BSEd-Math';
  }

  return '';
}

function normalizeCurriculumYear(string $value): string {
  $v = strtoupper(trim($value));
  if ($v === '') {
    return '';
  }

  // 18v1/18v2/18v3/18v4 style tokens map to 2018.
  if (preg_match('/^(\d{2})V\d+$/', $v, $m)) {
    return '20' . $m[1];
  }

  if (preg_match('/^(\d{4})$/', $v, $m)) {
    return $m[1];
  }

  return '';
}

function normalizeDisplayCourseCode(string $value): string {
  $code = trim($value);
  if ($code === '') {
    return '';
  }

  foreach ([' CS-IT', ' CpE', ' CPE', ' IndT', ' INDT', ' CS', ' IT'] as $suffix) {
    if (strlen($code) > strlen($suffix) && strcasecmp(substr($code, -strlen($suffix)), $suffix) === 0) {
      return trim(substr($code, 0, -strlen($suffix)));
    }
  }

  return $code;
}

function splitProgramTokens(string $value): array {
  if (trim($value) === '') {
    return [];
  }

  $tokens = array_map('trim', explode(',', $value));
  return array_values(array_filter($tokens, static fn($token) => $token !== ''));
}

function rowIncludesProgram(string $programsCsv, string $targetProgramCode): bool {
  $targetProgramCode = normalizeProgramCode($targetProgramCode);
  if ($targetProgramCode === '') {
    return false;
  }

  foreach (splitProgramTokens($programsCsv) as $token) {
    if (normalizeProgramCode($token) === $targetProgramCode) {
      return true;
    }
  }

  return false;
}

function appendCurriculumYear(array &$existing, string $program, string $year): void {
  $programCode = normalizeProgramCode($program);
  if ($programCode === '' || $year === '') {
    return;
  }

  if (!isset($existing[$programCode])) {
    $existing[$programCode] = [];
  }

  if (!in_array($year, $existing[$programCode], true)) {
    $existing[$programCode][] = $year;
  }
}

function appendCurriculumCatalogCourse(array &$catalog, string $curriculumYear, string $yearLevel, string $semester, array $course): void {
  if ($curriculumYear === '' || $yearLevel === '' || $semester === '') {
    return;
  }

  if (!isset($catalog[$curriculumYear])) {
    $catalog[$curriculumYear] = [];
  }
  if (!isset($catalog[$curriculumYear][$yearLevel])) {
    $catalog[$curriculumYear][$yearLevel] = [];
  }
  if (!isset($catalog[$curriculumYear][$yearLevel][$semester])) {
    $catalog[$curriculumYear][$yearLevel][$semester] = [];
  }

  foreach ($catalog[$curriculumYear][$yearLevel][$semester] as $existingCourse) {
    if (($existingCourse['course_code'] ?? '') === ($course['course_code'] ?? '')) {
      return;
    }
  }

  $catalog[$curriculumYear][$yearLevel][$semester][] = $course;
}

function countCurriculumCatalogCoursesForYear(array $catalog, string $curriculumYear): int {
  if (!isset($catalog[$curriculumYear]) || !is_array($catalog[$curriculumYear])) {
    return 0;
  }

  $count = 0;
  foreach ($catalog[$curriculumYear] as $yearBuckets) {
    if (!is_array($yearBuckets)) {
      continue;
    }

    foreach ($yearBuckets as $semesterBuckets) {
      if (is_array($semesterBuckets)) {
        $count += count($semesterBuckets);
      }
    }
  }

  return $count;
}

function loadCurriculumYearsByProgram(string $filePath): array {
  $result = [];
  if (!is_file($filePath)) {
    return $result;
  }

  $content = file_get_contents($filePath);
  if ($content === false) {
    return $result;
  }

  if (preg_match_all("/\(\s*(\d{4})\s*,\s*'([^']+)'\s*,/", $content, $matches, PREG_SET_ORDER)) {
    foreach ($matches as $m) {
      $year = $m[1];
      $programName = $m[2];
      $code = normalizeProgramCode($programName);
      if ($code === '') {
        continue;
      }
      if (!isset($result[$code])) {
        $result[$code] = [];
      }
      $result[$code][] = $year;
    }
  }

  foreach ($result as $code => $years) {
    $years = array_values(array_unique($years));
    sort($years, SORT_NUMERIC);
    $result[$code] = $years;
  }

  return $result;
}

$coordinatorProgramRaw = '';
$coordinatorProgramCode = '';
$programConfigNotice = '';
$pageLoadError = '';
$existing = [];
$curriculumCatalog = [];
$availableCurriculumYears = [];
$currentCurriculumYear = '';
$programs = pcLoadProgramCatalog($conn, true);
$bridgeLoaded = false;
require_once __DIR__ . '/../includes/laravel_bridge.php';

try {
  if (getenv('USE_LARAVEL_BRIDGE') === '1') {
    $bridgeData = postLaravelJsonBridge(
      'http://localhost/ASPLAN_v10/laravel-app/public/api/program-coordinator/curriculum-management/bootstrap',
      [
        'bridge_authorized' => true,
        'is_admin' => $isAdmin,
        'username' => $_SESSION['username'] ?? '',
        'program' => trim((string)($_GET['program'] ?? '')),
      ]
    );

    if (is_array($bridgeData) && !empty($bridgeData['success'])) {
      $coordinatorProgramRaw = (string)($bridgeData['coordinator_program_raw'] ?? '');
      $coordinatorProgramCode = (string)($bridgeData['coordinator_program_code'] ?? '');
      $programConfigNotice = (string)($bridgeData['program_config_notice'] ?? '');
      $existing = isset($bridgeData['existing']) && is_array($bridgeData['existing']) ? $bridgeData['existing'] : [];
      $curriculumCatalog = isset($bridgeData['curriculum_catalog']) && is_array($bridgeData['curriculum_catalog']) ? $bridgeData['curriculum_catalog'] : [];
      $bridgeLoaded = true;
    }
  }

  if (!$bridgeLoaded) {
    $pcTable = resolveProgramCoordinatorTable($conn);
    if (!$isAdmin && $pcTable !== null) {
      $username = $_SESSION['username'];

      if (tableHasColumn($conn, $pcTable, 'program')) {
        $stmt = $conn->prepare("SELECT program FROM `$pcTable` WHERE username = ? LIMIT 1");
        if ($stmt) {
          $stmt->bind_param('s', $username);
          $stmt->execute();
          $res = $stmt->get_result();
          if ($res && $res->num_rows > 0) {
            $row = $res->fetch_assoc();
            $coordinatorProgramRaw = trim((string)($row['program'] ?? ''));
            $coordinatorProgramCode = normalizeProgramCode($coordinatorProgramRaw);
          }
          $stmt->close();
        }
      } else {
        $fallback = $conn->prepare("SELECT program FROM adviser WHERE username = ? LIMIT 1");
        if ($fallback) {
          $fallback->bind_param('s', $username);
          $fallback->execute();
          $fallbackRes = $fallback->get_result();
          if ($fallbackRes && $fallbackRes->num_rows > 0) {
            $fallbackRow = $fallbackRes->fetch_assoc();
            $coordinatorProgramRaw = trim((string)($fallbackRow['program'] ?? ''));
            $coordinatorProgramCode = normalizeProgramCode($coordinatorProgramRaw);
            $programConfigNotice = 'Program source fallback is active (adviser table).';
          } else {
            $programConfigNotice = 'Program is not configured for this account.';
          }
          $fallback->close();
        } else {
          $programConfigNotice = 'Unable to load coordinator program configuration.';
        }
      }
    }

    if ($isAdmin) {
      $requestedProgram = trim((string)($_GET['program'] ?? ''));
      if ($requestedProgram !== '' && isset($programs[$requestedProgram])) {
        $coordinatorProgramCode = $requestedProgram;
      }
      if ($coordinatorProgramCode !== '') {
        $coordinatorProgramRaw = $programs[$coordinatorProgramCode] ?? $coordinatorProgramCode;
      } else {
        $coordinatorProgramRaw = '';
        $programConfigNotice = '';
      }
    }
  }

  if ($coordinatorProgramCode !== '') {
    $yearsStmt = $conn->prepare(
      "SELECT SUBSTRING_INDEX(curriculumyear_coursecode, '_', 1) AS cy, programs
       FROM cvsucarmona_courses
       ORDER BY cy DESC"
    );
    if ($yearsStmt) {
      $yearsStmt->execute();
      $yearsRes = $yearsStmt->get_result();
      if ($yearsRes) {
        while ($row = $yearsRes->fetch_assoc()) {
          if (!rowIncludesProgram((string)($row['programs'] ?? ''), $coordinatorProgramCode)) {
            continue;
          }

          $normalizedYear = normalizeCurriculumYear((string)($row['cy'] ?? ''));
          if ($normalizedYear === '') {
            continue;
          }
          appendCurriculumYear($existing, $coordinatorProgramCode, $normalizedYear);
        }
      }
      $yearsStmt->close();
    }
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

  $yearOnlyRes = $conn->query("SELECT program, curriculum_year FROM program_curriculum_years");
  if ($yearOnlyRes) {
    while ($row = $yearOnlyRes->fetch_assoc()) {
      $program = trim((string)($row['program'] ?? ''));
      $year = normalizeCurriculumYear((string)($row['curriculum_year'] ?? ''));
      if ($program === '' || $year === '') {
        continue;
      }
      if ($coordinatorProgramCode !== '' && normalizeProgramCode($program) !== $coordinatorProgramCode) {
        continue;
      }

      appendCurriculumYear($existing, $program, $year);
    }
  }

  if ($coordinatorProgramCode !== '') {
    $stmt = $conn->prepare(
      "SELECT curriculumyear_coursecode, programs, course_title, year_level, semester,
          credit_units_lec, credit_units_lab, lect_hrs_lec, lect_hrs_lab, pre_requisite
       FROM cvsucarmona_courses
       ORDER BY curriculumyear_coursecode"
    );
    if ($stmt) {
      $stmt->execute();
      $rows = $stmt->get_result();

      $legacyCurriculumCatalog = [];

      while ($rows && ($row = $rows->fetch_assoc())) {
        if (!rowIncludesProgram((string)($row['programs'] ?? ''), $coordinatorProgramCode)) {
          continue;
        }

        $key = (string)($row['curriculumyear_coursecode'] ?? '');
        $parts = explode('_', $key, 2);
        $yearToken = $parts[0] ?? '';
        $normalizedYear = normalizeCurriculumYear($yearToken);
        if ($normalizedYear === '') {
          continue;
        }

        $courseCode = normalizeDisplayCourseCode((string)($parts[1] ?? $key));
        $yearLevel = (string)($row['year_level'] ?? '');
        $semester = (string)($row['semester'] ?? '');

        appendCurriculumCatalogCourse($legacyCurriculumCatalog, $normalizedYear, $yearLevel, $semester, [
          'curriculum_key' => $key,
          'course_code' => $courseCode,
          'course_title' => (string)($row['course_title'] ?? ''),
          'credit_units_lec' => (int)($row['credit_units_lec'] ?? 0),
          'credit_units_lab' => (int)($row['credit_units_lab'] ?? 0),
          'lect_hrs_lec' => (int)($row['lect_hrs_lec'] ?? 0),
          'lect_hrs_lab' => (int)($row['lect_hrs_lab'] ?? 0),
          'pre_requisite' => (string)($row['pre_requisite'] ?? 'NONE'),
        ]);
      }

      $stmt->close();

      $curriculumCatalog = $legacyCurriculumCatalog;
    }

    if (tableExists($conn, 'curriculum_courses')) {
      $canonicalProgramLabel = trim((string)($programs[$coordinatorProgramCode] ?? $coordinatorProgramRaw));
      $canonicalProgramLabelUpper = strtoupper($canonicalProgramLabel);

      if ($canonicalProgramLabelUpper !== '') {
        $syncedCurriculumCatalog = [];
        $syncedStmt = $conn->prepare(
          "SELECT curriculum_year, year_level, semester, course_code, course_title,
                  credit_units_lec, credit_units_lab, lect_hrs_lec, lect_hrs_lab, pre_requisite
           FROM curriculum_courses
           WHERE UPPER(TRIM(program)) = ?
           ORDER BY curriculum_year, year_level, semester, course_code"
        );

        if ($syncedStmt) {
          $syncedStmt->bind_param('s', $canonicalProgramLabelUpper);
          $syncedStmt->execute();
          $syncedRows = $syncedStmt->get_result();

          while ($syncedRows && ($row = $syncedRows->fetch_assoc())) {
            $normalizedYear = normalizeCurriculumYear((string)($row['curriculum_year'] ?? ''));
            if ($normalizedYear === '') {
              continue;
            }

            appendCurriculumYear($existing, $coordinatorProgramCode, $normalizedYear);

            $yearLevel = trim((string)($row['year_level'] ?? ''));
            $semester = trim((string)($row['semester'] ?? ''));
            $courseCode = normalizeDisplayCourseCode((string)($row['course_code'] ?? ''));

            appendCurriculumCatalogCourse($syncedCurriculumCatalog, $normalizedYear, $yearLevel, $semester, [
              'curriculum_key' => $normalizedYear . '_' . $courseCode,
              'course_code' => $courseCode,
              'course_title' => (string)($row['course_title'] ?? ''),
              'credit_units_lec' => (int)($row['credit_units_lec'] ?? 0),
              'credit_units_lab' => (int)($row['credit_units_lab'] ?? 0),
              'lect_hrs_lec' => (int)($row['lect_hrs_lec'] ?? 0),
              'lect_hrs_lab' => (int)($row['lect_hrs_lab'] ?? 0),
              'pre_requisite' => (string)($row['pre_requisite'] ?? 'NONE'),
            ]);
          }

          $syncedStmt->close();
        }

        foreach (array_keys($syncedCurriculumCatalog) as $catalogYear) {
          if (countCurriculumCatalogCoursesForYear($syncedCurriculumCatalog, $catalogYear) > countCurriculumCatalogCoursesForYear($curriculumCatalog, $catalogYear)) {
            $curriculumCatalog[$catalogYear] = $syncedCurriculumCatalog[$catalogYear];
          }
        }
      }
    }
  }

  if ($coordinatorProgramCode !== '') {
    $fromExisting = $existing[$coordinatorProgramCode] ?? [];
    if (!empty($fromExisting)) {
      $availableCurriculumYears = array_values(array_unique($fromExisting));
      sort($availableCurriculumYears, SORT_NUMERIC);
    } else {
      $guideYearsByProgram = loadCurriculumYearsByProgram(__DIR__ . '/../dev/checklist_of_programs');
      $availableCurriculumYears = array_values(array_unique($guideYearsByProgram[$coordinatorProgramCode] ?? []));
      sort($availableCurriculumYears, SORT_NUMERIC);
    }

    if (!empty($fromExisting)) {
      $existingSorted = array_values(array_unique($fromExisting));
      sort($existingSorted, SORT_NUMERIC);
      $currentCurriculumYear = (string)end($existingSorted);
    }
  }
} catch (Throwable $e) {
  if (function_exists('elsError')) {
    elsError('Curriculum management bootstrap failed', [
      'message' => $e->getMessage(),
      'file' => $e->getFile(),
      'line' => $e->getLine(),
    ], 'program_coordinator');
  } else {
    error_log('Curriculum management bootstrap failed: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
  }
  $pageLoadError = 'Unable to load curriculum management data right now.';
  if ($programConfigNotice === '') {
    $programConfigNotice = $pageLoadError;
  }
}

$conn->close();

$roleLabel = $isAdmin ? 'Admin' : 'Program Coordinator';
$panelTitle = $isAdmin ? 'Admin Panel' : 'Program Coordinator Panel';
$dashboardHref = $isAdmin ? '../admin/index.php' : 'index.php';
$programOptions = is_array($programs) ? $programs : [];
if (!empty($programOptions)) {
  asort($programOptions, SORT_NATURAL | SORT_FLAG_CASE);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Curriculum Management - <?= $isAdmin ? 'Admin' : 'Program Coordinator' ?></title>
  <link rel="icon" type="image/png" href="../img/cav.png">
  <style>
    :root {
      --pc-green-900: #1a4f16;
      --pc-green-800: #206018;
      --pc-green-700: #2d8f22;
      --pc-green-600: #33a327;
      --pc-bg: #f2f6f1;
      --pc-surface: #ffffff;
      --pc-border: #dbe7d9;
      --pc-muted: #5f6f60;
      --pc-shadow: 0 12px 28px rgba(32, 96, 24, 0.08);
      --pc-shadow-soft: 0 4px 12px rgba(21, 43, 21, 0.08);
    }

    body {
      font-family: 'Segoe UI', Tahoma, Verdana, sans-serif;
      background: radial-gradient(circle at top left, #f8fbf8 0%, var(--pc-bg) 42%, #edf4ec 100%);
      margin: 0;
      padding: 0;
      overflow-x: hidden;
      color: #203022;
    }

    .title-bar {
      background: linear-gradient(135deg, var(--pc-green-800) 0%, var(--pc-green-700) 100%);
      color: #fff;
      padding: 5px 15px;
      font-size: 18px;
      font-weight: 800;
      display: flex;
      align-items: center;
      justify-content: space-between;
      position: fixed;
      top: 0;
      left: 0;
      right: 0;
      height: 45px;
      box-sizing: border-box;
      z-index: 1000;
      box-shadow: 0 2px 10px rgba(32, 96, 24, 0.2);
    }
    .title-content {
      display: flex;
      align-items: center;
    }
    .title-bar img {
      height: 32px;
      width: auto;
      margin-right: 12px;
      vertical-align: middle;
      filter: drop-shadow(0 2px 4px rgba(0,0,0,0.2));
    }
    .coordinator-name {
      font-size: 16px;
      font-weight: 600;
      color: white;
      font-family: 'Segoe UI', Arial, sans-serif;
      letter-spacing: 0.5px;
      background: rgba(255, 255, 255, 0.15);
      padding: 5px 15px;
      border-radius: 8px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.1);
      border: 1px solid rgba(255, 255, 255, 0.3);
    }
    .menu-toggle {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 30px;
        height: 30px;
        border: 1px solid rgba(255, 255, 255, 0.35);
        background: rgba(255, 255, 255, 0.12);
        color: #fff;
        border-radius: 6px;
        font-size: 18px;
        cursor: pointer;
        margin-right: 10px;
        transition: all 0.2s ease;
    }

    .menu-toggle:hover {
        background: rgba(255, 255, 255, 0.22);
    }

    @media (max-width: 768px) {
        .menu-toggle {
            display: inline-flex;
        }
    }

    .sidebar {
        width: 250px;
        height: calc(100vh - 45px);
        background: linear-gradient(135deg, #1a4f16 0%, #2d8f22 100%);
        color: white;
        position: fixed;
        left: 0;
        top: 45px;
        padding: 20px 0;
        box-shadow: 2px 0 5px rgba(0,0,0,0.1);
        overflow-y: auto;
        transition: transform 0.3s ease;
        z-index: 999;
    }

    .sidebar.collapsed {
        transform: translateX(-250px);
    }

    .sidebar-header {
        padding: 15px 20px;
        text-align: center;
        color: white;
        font-size: 20px;
        font-weight: 700;
        border-bottom: 2px solid rgba(255, 255, 255, 0.2);
        margin-bottom: 5px;
    }

    .sidebar-header h3 { margin: 0; }
    
    .sidebar-menu {
        list-style: none;
        padding: 6px 0;
        margin: 0;
    }

    .sidebar-menu li {
        margin: 0;
    }

    .sidebar-menu a {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 10px 20px;
        color: #ffffff;
        text-decoration: none;
        transition: all 0.3s ease;
        font-size: 15px;
        line-height: 1.2;
        border-left: 4px solid transparent;
    }

    .sidebar-menu a:hover {
        background: rgba(255, 255, 255, 0.1);
        padding-left: 25px;
        border-left-color: #4CAF50;
    }

    .sidebar-menu a.active {
        background: rgba(255, 255, 255, 0.15);
        border-left-color: #4CAF50;
    }

    .sidebar-menu img {
        width: 20px;
        height: 20px;
        margin-right: 0;
        filter: brightness(0) invert(1);
        flex: 0 0 20px;
    }

    .menu-group {
        margin: 8px 0;
    }

    .menu-group-title {
        padding: 6px 20px 2px 20px;
        color: rgba(255, 255, 255, 0.7);
        font-size: 15px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 1px;
        line-height: 1.2;
    }

    .main-content {
      margin: 24px 24px 24px 274px;
      padding-top: 28px;
        animation: slideInUp 0.6s ease-out;
        transition: margin-left 0.3s ease;
    }

    .main-content.expanded {
        margin-left: 24px;
    }

    @media (max-width: 768px) {
      .sidebar { transform: translateX(-250px); }
      .sidebar:not(.collapsed) { transform: translateX(0); }
      .main-content { margin: 70px 10px 10px; }
      .main-content.expanded { margin-left: 10px; }
      .header { padding: 5px 8px; font-size: 12px; }
      .header img { height: 22px; margin-right: 6px; }
    }

    /* Restored CSS for curriculum layout */
    .page-header {
      max-width: 1150px;
      margin: 0 auto 12px;
    }
    .page-header h1 {
        font-size: 24px;
      color: var(--pc-green-900);
      margin-bottom: 12px;
      letter-spacing: 0.2px;
    }
    
    .curriculum-setup {
      background: var(--pc-surface);
      padding: 22px;
      border-radius: 14px;
      border: 1px solid var(--pc-border);
      box-shadow: var(--pc-shadow);
      margin: 0 auto 18px;
      max-width: 1150px;
    }
    
    .curriculum-setup h2 {
      font-size: 20px;
      color: #223224;
        margin-top: 0;
        margin-bottom: 15px;
      border-bottom: 1px solid #e8efe7;
        padding-bottom: 10px;
    }

    .curriculum-toolbar {
      margin-bottom: 18px;
    }

    .curriculum-actions-grid {
      display: grid;
      grid-template-columns: 1fr;
      gap: 16px;
      max-width: 880px;
      margin: 0 auto;
    }

    .curriculum-card {
      background: linear-gradient(180deg, #ffffff 0%, #f8fbf7 100%);
      border: 1px solid #dde8db;
      border-radius: 14px;
      padding: 20px;
      box-shadow: var(--pc-shadow-soft);
      display: flex;
      flex-direction: column;
      gap: 16px;
    }

    .curriculum-card-existing {
      border-top: 4px solid rgba(32, 96, 24, 0.85);
    }

    .curriculum-card-generator {
      border-top: 4px solid rgba(45, 143, 34, 0.78);
    }

    .curriculum-card-head {
      display: flex;
      flex-direction: column;
      gap: 8px;
      padding-bottom: 14px;
      border-bottom: 1px solid #e8efe7;
    }

    .curriculum-card-label {
      display: inline-flex;
      align-self: flex-start;
      padding: 5px 10px;
      border-radius: 999px;
      background: rgba(45, 143, 34, 0.1);
      color: var(--pc-green-900);
      font-size: 12px;
      font-weight: 700;
      letter-spacing: 0.4px;
      text-transform: uppercase;
    }

    .curriculum-card-head h2 {
      margin: 0;
      padding: 0;
      border: none;
      font-size: 20px;
      color: #223224;
    }

    .curriculum-card-head p {
      margin: 0;
      color: var(--pc-muted);
      font-size: 13px;
      line-height: 1.5;
    }

    .curriculum-card-body {
      display: flex;
      flex-direction: column;
      gap: 14px;
      flex: 1;
    }

    .curriculum-card .form-group {
      min-width: 0;
    }

    .curriculum-action-note {
      margin: 0;
      color: var(--pc-muted);
      font-size: 13px;
      line-height: 1.5;
    }
    
    .form-row {
        display: flex;
        flex-wrap: wrap;
        gap: 20px;
        align-items: flex-end;
    }
    
    .form-group {
        display: flex;
        flex-direction: column;
        gap: 8px;
    }
    
    .form-group label {
        font-weight: 600;
      color: #2e4030;
      font-size: 13px;
      letter-spacing: 0.2px;
    }
    
    .year-select-row {
        display: flex;
        gap: 10px;
        align-items: center;
    }
    
    select#curriculumYearSelect, input#curriculumYearInput {
        padding: 10px 12px;
        border: 1px solid #c8d8c6;
        border-radius: 10px;
        font-size: 14px;
        min-width: 200px;
        background: #fbfffb;
        transition: border-color 0.2s ease, box-shadow 0.2s ease;
    }

    select#curriculumYearSelect:focus,
    input#curriculumYearInput:focus {
      outline: none;
      border-color: var(--pc-green-700);
      box-shadow: 0 0 0 4px rgba(45, 143, 34, 0.12);
    }

    #programSelect {
      padding: 10px 12px;
      border: 1px solid #c8d8c6;
      border-radius: 10px;
      font-size: 14px;
      min-width: 300px;
      background: #fbfffb;
      transition: border-color 0.2s ease, box-shadow 0.2s ease;
    }

    #programSelect:focus {
      outline: none;
      border-color: var(--pc-green-700);
      box-shadow: 0 0 0 4px rgba(45, 143, 34, 0.12);
    }

    .program-note {
      color: var(--pc-muted);
      font-size: 12px;
      margin-top: 6px;
      line-height: 1.4;
    }
    
    .btn {
      padding: 10px 16px;
        border: none;
      border-radius: 10px;
      font-size: 13px;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        color: #fff;
      transition: transform 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
        font-weight: 600;
      box-shadow: 0 4px 10px rgba(0, 0, 0, 0.12);
      letter-spacing: 0.2px;
    }

    .btn:hover { transform: translateY(-1px); }
    .btn:active { transform: translateY(0); }
    
    .btn-view { background: #5f6f66; }
    .btn-view:hover { background: #4e5d55; }
    
    .btn-add { background: linear-gradient(135deg, #39a63f 0%, #248129 100%); }
    .btn-add:hover { background: linear-gradient(135deg, #44b349 0%, #2b8f2f 100%); }
    
    .btn-save { background: linear-gradient(135deg, #1976d2 0%, #0d5eaf 100%); }
    .btn-save:hover { background: linear-gradient(135deg, #2583df 0%, #1568ba 100%); }
    
    .existing-info {
        font-size: 13px;
      color: var(--pc-muted);
        margin-top: 5px;
    }
    
    .year-generate-row {
        display: flex;
        gap: 10px;
        align-items: center;
    }
    
    .top-actions {
        display: flex;
        justify-content: space-between;
        align-items: center;
      background: var(--pc-surface);
        padding: 15px;
      border-radius: 14px;
      border: 1px solid var(--pc-border);
      box-shadow: var(--pc-shadow-soft);
      margin: 0 auto 16px;
      max-width: 1150px;
    }

    .checklist-container {
      background: var(--pc-surface);
        padding: 30px;
      border-radius: 14px;
      border: 1px solid var(--pc-border);
      box-shadow: var(--pc-shadow);
      max-width: 1150px;
      margin: 0 auto;
    }

    .checklist-header {
        text-align: center;
        margin-bottom: 30px;
    }
    .checklist-header h1 { font-size: 18px; margin: 0; font-weight: normal; }
    .checklist-header h2 { font-size: 18px; margin: 5px 0; }
    .checklist-header h3 { font-size: 20px; margin: 15px 0; color: #1a4f16; display: flex; align-items: center; justify-content: center; gap: 10px; }
    .logo-inline { height: 40px; }

    table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 20px;
        font-size: 13px;
      background: #fff;
      border-radius: 10px;
      overflow: hidden;
    }
    th, td {
      border: 1px solid #e5ece3;
        padding: 8px;
        text-align: center;
        vertical-align: middle;
    }
    th {
      background: #f4f8f3;
        font-weight: 600;
      color: #2a3d2b;
    }
    .semester-title-row td {
      background: #edf4ea;
        font-weight: 700;
        text-align: left;
      color: var(--pc-green-900);
    }
    .total-row td {
        font-weight: bold;
      background: #f4f8f3;
    }
    input[type="text"], input[type="number"] {
      padding: 7px 8px;
      border: 1px solid #c8d8c6;
      border-radius: 8px;
        width: 100%;
        box-sizing: border-box;
      background: #fbfffb;
    }

    input[type="text"]:focus,
    input[type="number"]:focus {
      outline: none;
      border-color: var(--pc-green-700);
      box-shadow: 0 0 0 3px rgba(45, 143, 34, 0.1);
    }
    
    .term-actions {
        display: flex;
        gap: 10px;
        margin-bottom: 30px;
    }
    .btn-remove { background: #dc3545; }
    .btn-remove:hover { background: #c82333; }

    .no-curriculum-msg {
        text-align: center;
        padding: 50px 20px;
      background: var(--pc-surface);
      border-radius: 14px;
      color: var(--pc-muted);
      border: 1px solid var(--pc-border);
      box-shadow: var(--pc-shadow-soft);
      max-width: 1150px;
      margin: 0 auto;
    }
    .no-curriculum-msg svg {
        margin-bottom: 15px;
        opacity: 0.5;
    }
    .no-curriculum-msg p {
        font-size: 16px;
        margin: 0;
    }

    /* View Mode Styles */
    .view-container {
      background: var(--pc-surface);
        padding: 20px;
      border-radius: 14px;
      border: 1px solid var(--pc-border);
      box-shadow: var(--pc-shadow-soft);
      max-width: 1150px;
      margin: 0 auto;
    }
    .view-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
        padding-bottom: 15px;
        border-bottom: 2px solid #f0f0f0;
    }
    .view-header h2 {
        margin: 0;
      color: var(--pc-green-900);
        font-size: 20px;
    }
    .btn-back { background: #6c757d; }
    .btn-back:hover { background: #5a6268; }
    .view-term {
        margin-bottom: 30px;
    }
    .view-term-title {
        background: #e9ecef;
        padding: 10px 15px;
        font-weight: bold;
        color: #1a4f16;
        border-radius: 4px 4px 0 0;
        border: 1px solid #ddd;
        border-bottom: none;
    }
    .view-empty {
        padding: 15px;
        border: 1px solid #ddd;
        color: #666;
        text-align: center;
        font-style: italic;
    }
    
    /* Notification */
    .notification {
        position: fixed;
        bottom: 20px;
        right: 20px;
      background: #fff;
        padding: 15px 20px;
      border-radius: 12px;
      box-shadow: 0 12px 24px rgba(0,0,0,0.14);
        display: flex;
        align-items: center;
        gap: 12px;
        z-index: 9999;
        animation: slideInRight 0.3s ease-out;
        border-left: 4px solid #fff;
    }
    .notification.success { border-left-color: #4CAF50; }
    .notification.error { border-left-color: #f44336; }
    
    @keyframes slideInUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
    @keyframes slideInRight { from { opacity: 0; transform: translateX(50px); } to { opacity: 1; transform: translateX(0); } }

    @media (max-width: 900px) {
      .curriculum-actions-grid {
        grid-template-columns: 1fr;
      }
      .form-row {
        flex-direction: column;
        align-items: stretch;
      }
      .year-select-row,
      .year-generate-row,
      .top-actions {
        flex-direction: column;
        align-items: stretch;
      }
      .btn {
        justify-content: center;
      }
      .checklist-container {
        padding: 16px;
      }
    }

  </style>
</head>
<body>
  <!-- Title Bar -->
  <div class="title-bar">
    <div class="title-content">
      <button class="menu-toggle" onclick="toggleSidebar()">&#9776;</button>
      <img src="../img/cav.png" alt="CvSU Logo" style="cursor:pointer;" onclick="toggleSidebar()">
      <span style="color: #d9e441;">ASPLAN</span>
    </div>
    <div class="coordinator-name"><?= $isAdmin ? 'Admin Panel' : ($coordinator_name . ' | ' . $roleLabel); ?></div>
  </div>

  <!-- Sidebar -->
  <?php if ($isAdmin): ?>
    <?php
      $activeAdminPage = 'curriculum_management';
      $adminSidebarCollapsed = true;
      require __DIR__ . '/../includes/admin_sidebar.php';
    ?>
  <?php else: ?>
    <div class="sidebar collapsed" id="sidebar">
      <div class="sidebar-header">
        <h3><?= htmlspecialchars($panelTitle) ?></h3>
      </div>
      <ul class="sidebar-menu">
            <div class="menu-group">
              <div class="menu-group-title">Dashboard</div>
              <li><a href="<?= htmlspecialchars($dashboardHref) ?>"><img src="../pix/home1.png" alt="Dashboard" style="filter: brightness(0) invert(1);"> Dashboard</a></li>
            </div>

            <div class="menu-group">
              <div class="menu-group-title">Modules</div>
              <li><a href="curriculum_management.php" class="active"><img src="../pix/curr.png" alt="Curriculum" style="filter: brightness(0) invert(1);"> Curriculum Management</a></li>
              <li><a href="adviser_management.php"><img src="../pix/account.png" alt="Advisers" style="filter: brightness(0) invert(1);"> Adviser Management</a></li>
              <li><a href="list_of_students.php"><img src="../pix/checklist.png" alt="Students" style="filter: brightness(0) invert(1);"> List of Students</a></li>
              <li><a href="program_shift_requests.php"><img src="../pix/update.png" alt="Program Shift" style="filter: brightness(0) invert(1);"> Program Shift Requests</a></li>
              <li><a href="profile.php"><img src="../pix/account.png" alt="Profile" style="filter: brightness(0) invert(1);"> Update Profile</a></li>
            </div>

            <div class="menu-group">
              <div class="menu-group-title">Account</div>
              <li><a href="logout.php"><img src="../pix/singout.png" alt="Sign Out" style="filter: brightness(0) invert(1);"> Sign Out</a></li>
            </div>
      </ul>
    </div>
  <?php endif; ?>

  <div class="main-content expanded" id="mainContent">
    <div class="page-header">
      <h1>Curriculum Management</h1>
    </div>

    <div class="curriculum-setup">
      <?php if ($isAdmin): ?>
      <div class="curriculum-toolbar">
        <div class="curriculum-card">
          <div class="curriculum-card-head">
            <span class="curriculum-card-label">Target Program</span>
            <h2>Select Program</h2>
            <p>Choose the program before viewing an existing curriculum or generating a new one.</p>
          </div>
          <div class="curriculum-card-body">
            <div class="form-group">
              <label for="programSelect">Program</label>
              <select id="programSelect">
                <?php if ($isAdmin): ?>
                  <option value="">-- Select Program --</option>
                <?php endif; ?>
                <?php foreach ($programOptions as $programCode => $programLabel): ?>
                  <option value="<?= htmlspecialchars($programCode) ?>" <?= $coordinatorProgramCode === $programCode ? 'selected' : '' ?>>
                    <?= htmlspecialchars($programLabel) ?> (<?= htmlspecialchars($programCode) ?>)
                  </option>
                <?php endforeach; ?>
              </select>
              <div class="program-note">Select the target program before generating or editing curriculum.</div>
            </div>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <div class="curriculum-actions-grid">
        <section class="curriculum-card curriculum-card-existing" aria-labelledby="existingCurriculumHeading">
          <div class="curriculum-card-head">
            <span class="curriculum-card-label">Existing Curriculum</span>
            <h2 id="existingCurriculumHeading">View/Edit Existing Curriculum</h2>
            <p>Open a saved curriculum year to review, update, or remove it for the selected program.</p>
          </div>
          <div class="curriculum-card-body">
            <div class="form-group">
              <label for="curriculumYearSelect">Curriculum Year</label>
              <div class="year-select-row">
                <select id="curriculumYearSelect">
                  <option value="">-- Select Curriculum Year --</option>
                  <?php foreach ($availableCurriculumYears as $year): ?>
                    <option value="<?= htmlspecialchars($year) ?>">
                      <?= htmlspecialchars($year) ?><?= ((string)$year === $currentCurriculumYear) ? ' (Currently Used)' : '' ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <button type="button" id="viewEditBtn" class="btn btn-view" onclick="viewChecklist()" disabled>View / Edit</button>
              </div>
              <div class="existing-info" id="existingInfo"></div>
              <?php if ($programConfigNotice !== ''): ?>
                <div class="existing-info" style="color:#c0392b;"><?= htmlspecialchars($programConfigNotice) ?></div>
              <?php endif; ?>
            </div>
          </div>
        </section>

        <section class="curriculum-card curriculum-card-generator" aria-labelledby="newCurriculumHeading">
          <div class="curriculum-card-head">
            <span class="curriculum-card-label">New Curriculum</span>
            <h2 id="newCurriculumHeading">Generate New Curriculum</h2>
            <p>Create a fresh checklist workspace, then set the curriculum year before saving it.</p>
          </div>
          <div class="curriculum-card-body">
            <p class="curriculum-action-note">Use this when you want to build a new curriculum instead of editing an existing saved year.</p>
            <div class="form-group">
              <button class="btn btn-add" type="button" onclick="startCreateChecklistFlow()" id="initBtn">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 1-2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><line x1="9" y1="15" x2="15" y2="15"/></svg>
                Generate Curriculum/Checklist
              </button>
            </div>
          </div>
        </section>
      </div>
    </div>

    <!-- Step 2: The checklist (hidden until generated) -->
    <div id="checklistArea" style="display:none;">
      <div class="top-actions">
        <div class="year-generate-row">
          <input type="text" id="curriculumYearInput" maxlength="4" inputmode="numeric" placeholder="Enter curriculum year (YYYY)">
        </div>
        <button class="btn btn-save" id="saveAllBtn" onclick="saveAllCourses()">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1-2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
          Save Curriculum
        </button>
      </div>

      <div class="checklist-container">
        <div class="checklist-header">
          <h1>Republic of the Philippines</h1>
          <h2>CAVITE STATE UNIVERSITY - CARMONA</h2>
          <h2>Carmona, Cavite</h2>
          <h3>
            <img src="../img/cav.png" alt="CvSU Logo" class="logo-inline">
            <span id="checklistProgramTitle"></span>
          </h3>
          <p style="font-size:12px; color:#555; margin:5px 0;" id="checklistYearLabel"></p>
        </div>
        <div id="checklistBody"></div>
      </div>

      <div class="top-actions" style="margin-top:20px;">
        <button class="btn btn-save" onclick="saveAllCourses()">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1-2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
          Save Curriculum
        </button>
      </div>
    </div>

    <!-- No curriculum placeholder -->
    <div id="noChecklistMsg" class="no-curriculum-msg">
      <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="#ccc" stroke-width="1.5">
        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 1-2 2z"/>
        <polyline points="14 2 14 8 20 8"/>
        <line x1="16" y1="13" x2="8" y2="13"/>
        <line x1="16" y1="17" x2="8" y2="17"/>
        <polyline points="10 9 9 9 8 9"/>
      </svg>
      <p>Click <strong>"Generate Checklist/Curriculum"</strong> to open the checklist, then enter curriculum year.</p>
    </div>

    <div id="curriculumViewArea" class="view-container" style="display:none;">
      <div class="view-header">
        <h2 id="curriculumViewTitle">Curriculum Checklist View</h2>
        <button type="button" class="btn btn-back" onclick="closeCurriculumView()">Close View</button>
      </div>
      <div id="curriculumViewBody"></div>
    </div>
  </div>

<script>
const existingCurriculums = <?= json_encode($existing) ?>;
const programNames = <?= json_encode($programs) ?>;
const availableCurriculumYears = <?= json_encode($availableCurriculumYears) ?>;
const curriculumCatalog = <?= json_encode($curriculumCatalog) ?>;
const coordinatorProgramCode = <?= json_encode($coordinatorProgramCode) ?>;
const coordinatorProgramName = <?= json_encode($programs[$coordinatorProgramCode] ?? $coordinatorProgramRaw) ?>;
const isAdminView = <?= json_encode($isAdmin) ?>;

let selectedProgram = coordinatorProgramCode || '';
let selectedYear = '';
let deletedCourseKeys = [];

function refreshExistingInfo() {
  const info = document.getElementById('existingInfo');
  const activeProgram = selectedProgram || coordinatorProgramCode;
  if (activeProgram && existingCurriculums[activeProgram]) {
    const years = [...existingCurriculums[activeProgram]]
      .map(y => String(y).trim())
      .filter(Boolean)
      .sort((a, b) => parseInt(a, 10) - parseInt(b, 10));

    const currentYear = years.length > 0 ? years[years.length - 1] : '';
    const programLabel = programNames[activeProgram] || coordinatorProgramName || activeProgram;

    if (currentYear) {
      info.textContent = 'Currently used curriculum year (' + programLabel + '): ' + currentYear;
    } else {
      info.textContent = 'No existing curriculum year found for ' + programLabel + '.';
    }
  } else {
    const programLabel = programNames[activeProgram] || coordinatorProgramName || activeProgram;
    if (programLabel) {
      info.textContent = 'No existing curriculum year found for ' + programLabel + '.';
    } else {
      info.textContent = '';
    }
  }
}

window.addEventListener('DOMContentLoaded', function() {
  refreshExistingInfo();

  const yearSelect = document.getElementById('curriculumYearSelect');
  const yearInput = document.getElementById('curriculumYearInput');
  const viewEditBtn = document.getElementById('viewEditBtn');
  const programSelect = document.getElementById('programSelect');

  if (programSelect) {
    programSelect.addEventListener('change', function() {
      const nextProgram = String(programSelect.value || '').trim();
      const url = new URL(window.location.href);
      if (nextProgram) {
        url.searchParams.set('program', nextProgram);
      } else {
        url.searchParams.delete('program');
      }
      window.location.href = url.toString();
    });
  }

  if (viewEditBtn && yearSelect) {
    viewEditBtn.disabled = String(yearSelect.value || '').trim() === '';
  }

  if (yearSelect) {
    yearSelect.addEventListener('change', function() {
      const chosenYear = String(yearSelect.value || '').trim();

      // Keep input aligned with dropdown selection.
      if (yearInput) {
        yearInput.value = chosenYear;
      }

      selectedYear = chosenYear || selectedYear;
      updateChecklistYearLabel(selectedYear);

      if (viewEditBtn) {
        viewEditBtn.disabled = chosenYear === '';
      }
    });

    // Right-click on the dropdown deletes the currently selected curriculum year.
    yearSelect.addEventListener('contextmenu', function(e) {
      e.preventDefault();

      const chosenYear = String(yearSelect.value || '').trim();
      if (!chosenYear) {
        showNotification('error', 'Select a curriculum year first, then right-click to delete.');
        return;
      }

      if (!isValidCurriculumYear(chosenYear)) {
        showNotification('error', 'Invalid curriculum year selected.');
        return;
      }

      const ok = confirm('Delete curriculum year ' + chosenYear + ' for this program? This will also remove its saved courses.');
      if (!ok) {
        return;
      }

      deleteCurriculumYear(chosenYear);
    });
  }
});

window.addEventListener('DOMContentLoaded', function() {
      const sidebar = document.getElementById('sidebar');
      const mainContent = document.querySelector('.main-content');
      if (window.innerWidth <= 768) {
        if(sidebar) sidebar.classList.add('collapsed');
        if(mainContent) mainContent.classList.add('expanded');
      } else {
        if(sidebar) sidebar.classList.remove('collapsed');
        if(mainContent) mainContent.classList.remove('expanded');
      }
    });

    // Handle responsive behavior
    window.addEventListener('resize', function() {
      const sidebar = document.getElementById('sidebar');
      const mainContent = document.querySelector('.main-content');
      if (window.innerWidth > 768) {
        // Reset to desktop view
        if(sidebar) sidebar.classList.remove('collapsed');
        if(mainContent) mainContent.classList.remove('expanded');
      } else {
        // On mobile, keep sidebar collapsed
        if(sidebar) sidebar.classList.add('collapsed');
        if(mainContent) mainContent.classList.add('expanded');
      }
    });

function updateChecklistYearLabel(yearValue) {
  const yearLabel = document.getElementById('checklistYearLabel');
  if (!yearLabel) {
    return;
  }

  const yearText = String(yearValue || '').trim();
  yearLabel.textContent = yearText
    ? ('Curriculum Year: ' + yearText)
    : 'Curriculum Year: (set this before saving)';
}

function isValidCurriculumYear(yearValue) {
  const y = String(yearValue || '').trim();
  if (!/^\d{4}$/.test(y)) {
    return false;
  }
  const yearNum = parseInt(y, 10);
  return yearNum >= 2017 && yearNum <= 2099;
}

function startCreateChecklistFlow() {
  const inputEl = document.getElementById('curriculumYearInput');

  // Generate flow is for creating a fresh curriculum checklist.
  if (inputEl) {
    inputEl.value = '';
  }

  selectedYear = '';
  deletedCourseKeys = [];
  initChecklist('', true);
}

function applyYearToControls(year, syncDropdown = true) {
  const inputEl = document.getElementById('curriculumYearInput');
  const selectEl = document.getElementById('curriculumYearSelect');

  if (inputEl) {
    inputEl.value = year;
  }

  if (!syncDropdown) {
    return;
  }

  if (selectEl && !Array.from(selectEl.options).some(opt => opt.value === year)) {
    const opt = document.createElement('option');
    opt.value = year;
    opt.textContent = year;
    selectEl.appendChild(opt);
  }
  if (selectEl) {
    selectEl.value = year;
  }
}

function generateFromInputOrPrompt() {
  const inputEl = document.getElementById('curriculumYearInput');
  const selectEl = document.getElementById('curriculumYearSelect');
  const typedYear = inputEl ? String(inputEl.value || '').trim() : '';
  const selectedYearFromDropdown = selectEl ? String(selectEl.value || '').trim() : '';
  const year = typedYear !== '' ? typedYear : selectedYearFromDropdown;

  if (year !== '' && !isValidCurriculumYear(year)) {
    showNotification('error', 'Invalid curriculum year. Please enter a year from 2017 to 2099.');
    return;
  }

  applyYearToControls(year, false);
  initChecklist(year);
}

function initChecklist(forcedYear, ignoreDropdownSelection = false) {
  const programSelect = document.getElementById('programSelect');
  selectedProgram = programSelect ? String(programSelect.value || '').trim() : coordinatorProgramCode;
  const inputEl = document.getElementById('curriculumYearInput');
  const selectEl = document.getElementById('curriculumYearSelect');
  const typedYear = inputEl ? String(inputEl.value || '').trim() : '';
  const selectedYearFromDropdown = selectEl ? String(selectEl.value || '').trim() : '';
  selectedYear = ignoreDropdownSelection
    ? (forcedYear || typedYear)
    : (forcedYear || typedYear || selectedYearFromDropdown);

  if (!selectedProgram) { showNotification('error', 'Program is not set for this account.'); return; }

  // Check for duplicate only if taking action specifically for a year
  if (selectedYear && forcedYear !== '' && existingCurriculums[selectedProgram] && existingCurriculums[selectedProgram].includes(selectedYear)) {
    // Remove the confirmation prompt so generating won't be blocked or prompt
  }

  // Generating checklist should switch focus back from view panel.
  const viewArea = document.getElementById('curriculumViewArea');
  if (viewArea) {
    viewArea.style.display = 'none';
  }

  document.getElementById('checklistArea').style.display = 'block';
  document.getElementById('noChecklistMsg').style.display = 'none';
  document.getElementById('checklistProgramTitle').textContent = (programNames[selectedProgram] || coordinatorProgramName || selectedProgram).toUpperCase();
  updateChecklistYearLabel(selectedYear);
  refreshNoChecklistPlaceholder();

  // Reset pending row deletions whenever checklist is (re)initialized from server data.
  deletedCourseKeys = [];
  buildChecklistTables(selectedYear);
}

function viewChecklist() {
  const selectEl = document.getElementById('curriculumYearSelect');
  const selected = selectEl ? String(selectEl.value || '').trim() : '';
  const programSelect = document.getElementById('programSelect');
  selectedProgram = programSelect ? String(programSelect.value || '').trim() : coordinatorProgramCode;

  if (!selectedProgram) {
    showNotification('error', 'Program is not set for this account.');
    return;
  }
  if (!selected) {
    showNotification('error', 'Please enter or select a curriculum year to view.');
    return;
  }
  if (!isValidCurriculumYear(selected)) {
    showNotification('error', 'Invalid curriculum year. Please select a year from 2017 to 2099.');
    return;
  }

  // Instead of view-only mode, initialize the editable checklist with existing data
  applyYearToControls(selected);
  initChecklist(selected);
}

function closeCurriculumView() {
  const area = document.getElementById('curriculumViewArea');
  if (area) {
    area.style.display = 'none';
  }
  refreshNoChecklistPlaceholder();
}

function refreshNoChecklistPlaceholder() {
  const placeholder = document.getElementById('noChecklistMsg');
  const checklistArea = document.getElementById('checklistArea');
  const viewArea = document.getElementById('curriculumViewArea');
  if (!placeholder) {
    return;
  }

  const checklistVisible = checklistArea && checklistArea.style.display !== 'none';
  const viewVisible = viewArea && viewArea.style.display !== 'none';
  placeholder.style.display = (checklistVisible || viewVisible) ? 'none' : 'block';
}

function escapeHtml(value) {
  return String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

const TERMS = [
  { year: 'First Year',  semester: 'First Semester' },
  { year: 'First Year',  semester: 'Second Semester' },
  { year: 'First Year',  semester: 'Mid Year' },
  { year: 'Second Year', semester: 'First Semester' },
  { year: 'Second Year', semester: 'Second Semester' },
  { year: 'Second Year', semester: 'Mid Year' },
  { year: 'Third Year',  semester: 'First Semester' },
  { year: 'Third Year',  semester: 'Second Semester' },
  { year: 'Third Year',  semester: 'Mid Year' },
  { year: 'Fourth Year', semester: 'First Semester' },
  { year: 'Fourth Year', semester: 'Second Semester' },
  { year: 'Fourth Year', semester: 'Mid Year' },
];

function buildCurriculumKey(yearValue, courseCode) {
  const yearText = String(yearValue || '').trim();
  const codeText = String(courseCode || '').trim();
  if (!yearText || !codeText) {
    return '';
  }

  return yearText + '_' + codeText;
}

function extractCurriculumKeyPrefix(curriculumKey, fallbackValue = '') {
  const keyText = String(curriculumKey || '').trim();
  if (keyText !== '') {
    const separatorIndex = keyText.indexOf('_');
    if (separatorIndex > 0) {
      return keyText.slice(0, separatorIndex);
    }
  }

  return String(fallbackValue || '').trim();
}

function resolveYearKeyPrefix(yearValue) {
  const yearData = (yearValue && curriculumCatalog && curriculumCatalog[yearValue]) ? curriculumCatalog[yearValue] : null;
  if (yearData) {
    for (const yearLevel of Object.keys(yearData)) {
      const semesterBuckets = yearData[yearLevel] || {};
      for (const semesterName of Object.keys(semesterBuckets)) {
        const courses = Array.isArray(semesterBuckets[semesterName]) ? semesterBuckets[semesterName] : [];
        const firstCourse = courses.find(course => course && String(course.curriculum_key || '').trim() !== '');
        if (firstCourse) {
          return extractCurriculumKeyPrefix(firstCourse.curriculum_key, yearValue);
        }
      }
    }
  }

  return String(yearValue || '').trim();
}

function getOriginalCurriculumKey(row) {
  if (!row) {
    return '';
  }

  const originalKeyInput = row.querySelector('[name="original_curriculum_key"]');
  const originalKey = originalKeyInput ? String(originalKeyInput.value || '').trim() : '';
  if (originalKey !== '') {
    return originalKey;
  }

  const originalCodeInput = row.querySelector('[name="original_course_code"]');
  const originalCode = originalCodeInput ? String(originalCodeInput.value || '').trim() : '';
  return buildCurriculumKey(selectedYear, originalCode);
}

function markRowForDeletion(row) {
  const originalKey = getOriginalCurriculumKey(row);
  if (originalKey && !deletedCourseKeys.includes(originalKey)) {
    deletedCourseKeys.push(originalKey);
  }
}

function getRowKeyPrefix(row, fallbackYear = selectedYear) {
  if (!row) {
    return resolveYearKeyPrefix(fallbackYear);
  }

  const prefixInput = row.querySelector('[name="curriculum_key_prefix"]');
  const explicitPrefix = prefixInput ? String(prefixInput.value || '').trim() : '';
  if (explicitPrefix !== '') {
    return explicitPrefix;
  }

  return extractCurriculumKeyPrefix(getOriginalCurriculumKey(row), resolveYearKeyPrefix(fallbackYear));
}

function syncRowOriginalState(row, yearValue) {
  if (!row) {
    return;
  }

  const courseCode = row.querySelector('[name="course_code"]')?.value.trim() || '';
  const courseTitle = row.querySelector('[name="course_title"]')?.value.trim() || '';
  const creditLec = row.querySelector('[name="credit_lec"]')?.value || '0';
  const creditLab = row.querySelector('[name="credit_lab"]')?.value || '0';
  const hrsLec = row.querySelector('[name="hrs_lec"]')?.value || '0';
  const hrsLab = row.querySelector('[name="hrs_lab"]')?.value || '0';
  const preReq = row.querySelector('[name="pre_requisite"]')?.value.trim() || 'NONE';
  const keyPrefix = getRowKeyPrefix(row, yearValue);

  const originalKeyInput = row.querySelector('[name="original_curriculum_key"]');
  const keyPrefixInput = row.querySelector('[name="curriculum_key_prefix"]');
  const originalCodeInput = row.querySelector('[name="original_course_code"]');
  const originalTitleInput = row.querySelector('[name="original_course_title"]');
  const originalCreditLecInput = row.querySelector('[name="original_credit_lec"]');
  const originalCreditLabInput = row.querySelector('[name="original_credit_lab"]');
  const originalHrsLecInput = row.querySelector('[name="original_hrs_lec"]');
  const originalHrsLabInput = row.querySelector('[name="original_hrs_lab"]');
  const originalPreReqInput = row.querySelector('[name="original_pre_requisite"]');

  if (originalKeyInput) {
    originalKeyInput.value = buildCurriculumKey(keyPrefix, courseCode);
  }
  if (keyPrefixInput) {
    keyPrefixInput.value = keyPrefix;
  }
  if (originalCodeInput) {
    originalCodeInput.value = courseCode;
  }
  if (originalTitleInput) {
    originalTitleInput.value = courseTitle;
  }
  if (originalCreditLecInput) {
    originalCreditLecInput.value = creditLec;
  }
  if (originalCreditLabInput) {
    originalCreditLabInput.value = creditLab;
  }
  if (originalHrsLecInput) {
    originalHrsLecInput.value = hrsLec;
  }
  if (originalHrsLabInput) {
    originalHrsLabInput.value = hrsLab;
  }
  if (originalPreReqInput) {
    originalPreReqInput.value = preReq;
  }
}

function syncChecklistOriginalState(yearValue) {
  TERMS.forEach((term, termIdx) => {
    const tbody = document.getElementById('tbody_' + termIdx);
    if (!tbody) {
      return;
    }

    for (const row of tbody.rows) {
      syncRowOriginalState(row, yearValue);
    }
  });
}

function captureChecklistSnapshot(yearValue) {
  const snapshot = {};

  TERMS.forEach((term, termIdx) => {
    const tbody = document.getElementById('tbody_' + termIdx);
    if (!tbody) {
      return;
    }

    for (const row of tbody.rows) {
      const courseCode = row.querySelector('[name="course_code"]')?.value.trim() || '';
      const courseTitle = row.querySelector('[name="course_title"]')?.value.trim() || '';
      if (!courseCode || !courseTitle) {
        continue;
      }

      if (!snapshot[term.year]) {
        snapshot[term.year] = {};
      }
      if (!snapshot[term.year][term.semester]) {
        snapshot[term.year][term.semester] = [];
      }

      snapshot[term.year][term.semester].push({
        curriculum_key: buildCurriculumKey(getRowKeyPrefix(row, yearValue), courseCode),
        course_code: courseCode,
        course_title: courseTitle,
        credit_units_lec: parseInt(row.querySelector('[name="credit_lec"]')?.value || '0', 10) || 0,
        credit_units_lab: parseInt(row.querySelector('[name="credit_lab"]')?.value || '0', 10) || 0,
        lect_hrs_lec: parseInt(row.querySelector('[name="hrs_lec"]')?.value || '0', 10) || 0,
        lect_hrs_lab: parseInt(row.querySelector('[name="hrs_lab"]')?.value || '0', 10) || 0,
        pre_requisite: row.querySelector('[name="pre_requisite"]')?.value.trim() || 'NONE'
      });
    }
  });

  return snapshot;
}

function buildChecklistTables(year) {
  const body = document.getElementById('checklistBody');
  body.innerHTML = '';

  const yearData = (year && curriculumCatalog && curriculumCatalog[year]) ? curriculumCatalog[year] : null;

  TERMS.forEach((term, termIdx) => {
    const section = document.createElement('div');
    section.id = 'term_' + termIdx;
    section.innerHTML = `
      <table>
        <thead>
          <tr class="semester-title-row"><td colspan="8">${term.year} - ${term.semester}</td></tr>
          <tr>
            <th rowspan="2" style="width:110px;">COURSE CODE</th>
            <th rowspan="2">COURSE TITLE</th>
            <th colspan="2">CREDIT UNIT</th>
            <th colspan="2">CONTACT HRS</th>
            <th rowspan="2">PRE-REQUISITE</th>
            <th rowspan="2" style="width:40px;">&#10006;</th>
          </tr>
          <tr>
            <th style="width:60px;">Lec</th>
            <th style="width:60px;">Lab</th>
            <th style="width:60px;">Lec</th>
            <th style="width:60px;">Lab</th>
          </tr>
        </thead>
        <tbody id="tbody_${termIdx}">
        </tbody>
        <tfoot>
          <tr class="total-row">
            <td colspan="2" style="text-align:right;">Total:</td>
            <td id="total_lec_${termIdx}">0</td>
            <td id="total_lab_${termIdx}">0</td>
            <td id="total_hrs_lec_${termIdx}">0</td>
            <td id="total_hrs_lab_${termIdx}">0</td>
            <td colspan="2"></td>
          </tr>
        </tfoot>
      </table>
      <div class="term-actions">
        <button class="btn btn-add" onclick="addRow(${termIdx})">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Add Course
        </button>
        <button class="btn btn-remove" onclick="removeTerm(${termIdx})">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
          Remove Term
        </button>
      </div>
    `;
    body.appendChild(section);

    const termCourses = (yearData && yearData[term.year] && yearData[term.year][term.semester]) ? yearData[term.year][term.semester] : [];
    if (termCourses.length > 0) {
      termCourses.forEach(course => addRow(termIdx, course));
    }
  });
}

function removeTerm(termIdx) {
  const section = document.getElementById('term_' + termIdx);
  if (!section) return;
  const tbody = document.getElementById('tbody_' + termIdx);
  const courseCount = tbody ? tbody.rows.length : 0;
  const termLabel = TERMS[termIdx].year + ' - ' + TERMS[termIdx].semester;
  if (courseCount > 0) {
    if (!confirm('Remove "' + termLabel + '" and its ' + courseCount + ' course(s)?')) return;
  }

  if (tbody) {
    for (const row of tbody.rows) {
      markRowForDeletion(row);
    }
  }

  section.remove();
}

function addRow(termIdx, courseData = null) {
  const tbody = document.getElementById('tbody_' + termIdx);
  const rowIdx = tbody.rows.length;
  const row = tbody.insertRow();
  
  const cCode = courseData ? escapeHtml(courseData.course_code || '') : '';
  const cTitle = courseData ? escapeHtml(courseData.course_title || '') : '';
  const cLec = courseData ? (courseData.credit_units_lec || 0) : 0;
  const cLab = courseData ? (courseData.credit_units_lab || 0) : 0;
  const cHLec = courseData ? (courseData.lect_hrs_lec || 0) : 0;
  const cHLab = courseData ? (courseData.lect_hrs_lab || 0) : 0;
  const cPre = courseData ? escapeHtml(courseData.pre_requisite || '') : '';
  const keyPrefix = courseData
    ? extractCurriculumKeyPrefix(courseData.curriculum_key || '', resolveYearKeyPrefix(selectedYear))
    : resolveYearKeyPrefix(selectedYear);
  const oKey = courseData ? escapeHtml(courseData.curriculum_key || buildCurriculumKey(keyPrefix, courseData.course_code || '')) : '';
  const oCode = courseData ? escapeHtml(courseData.course_code || '') : '';
  const oTitle = courseData ? escapeHtml(courseData.course_title || '') : '';
  const oLec = courseData ? (courseData.credit_units_lec || 0) : '';
  const oLab = courseData ? (courseData.credit_units_lab || 0) : '';
  const oHLec = courseData ? (courseData.lect_hrs_lec || 0) : '';
  const oHLab = courseData ? (courseData.lect_hrs_lab || 0) : '';
  const oPre = courseData ? escapeHtml(courseData.pre_requisite || '') : '';

  row.innerHTML = `
    <td>
      <input type="hidden" name="curriculum_key_prefix" value="${escapeHtml(keyPrefix)}">
      <input type="hidden" name="original_curriculum_key" value="${oKey}">
      <input type="hidden" name="original_course_code" value="${oCode}">
      <input type="hidden" name="original_course_title" value="${oTitle}">
      <input type="hidden" name="original_credit_lec" value="${oLec}">
      <input type="hidden" name="original_credit_lab" value="${oLab}">
      <input type="hidden" name="original_hrs_lec" value="${oHLec}">
      <input type="hidden" name="original_hrs_lab" value="${oHLab}">
      <input type="hidden" name="original_pre_requisite" value="${oPre}">
      <input type="text" name="course_code" placeholder="e.g. GNED 01" style="width:95px;" value="${cCode}">
    </td>
    <td><input type="text" name="course_title" placeholder="Course Title" style="text-align:left;" value="${cTitle}"></td>
    <td><input type="number" name="credit_lec" value="${cLec}" min="0" max="20" style="width:50px;" onchange="updateTotals(${termIdx})"></td>
    <td><input type="number" name="credit_lab" value="${cLab}" min="0" max="20" style="width:50px;" onchange="updateTotals(${termIdx})"></td>
    <td><input type="number" name="hrs_lec" value="${cHLec}" min="0" max="99" style="width:50px;" onchange="updateTotals(${termIdx})"></td>
    <td><input type="number" name="hrs_lab" value="${cHLab}" min="0" max="999" style="width:50px;" onchange="updateTotals(${termIdx})"></td>
    <td><input type="text" name="pre_requisite" placeholder="NONE" style="text-align:left;width:100%;" value="${cPre}"></td>
    <td>
      <button onclick="removeRow(this, ${termIdx})" style="background:none;border:none;color:#f44336;cursor:pointer;font-size:16px;padding:2px 6px;" title="Remove course">&#10006;</button>
    </td>
  `;
  updateTotals(termIdx);
  if (!courseData) {
    row.querySelector('input[name="course_code"]').focus();
  }
}

function removeRow(btn, termIdx) {
  const row = btn.closest('tr');
  if (!row) {
    return;
  }

  markRowForDeletion(row);

  row.remove();
  updateTotals(termIdx);
}

function deleteCurriculumYear(year) {
  const programSelect = document.getElementById('programSelect');
  selectedProgram = programSelect ? String(programSelect.value || '').trim() : coordinatorProgramCode;

  if (!selectedProgram) {
    showNotification('error', 'Program is not set for this account.');
    return;
  }

  fetch('delete_curriculum.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      program: selectedProgram,
      curriculum_year: year
    })
  })
  .then(res => res.json())
  .then(data => {
    if (!data.success) {
      showNotification('error', data.message || 'Failed to delete curriculum year.');
      return;
    }

    const yearSelect = document.getElementById('curriculumYearSelect');
    const yearInput = document.getElementById('curriculumYearInput');
    const viewEditBtn = document.getElementById('viewEditBtn');

    if (existingCurriculums[selectedProgram]) {
      existingCurriculums[selectedProgram] = existingCurriculums[selectedProgram]
        .map(v => String(v).trim())
        .filter(v => v !== String(year));
    }

    if (yearSelect) {
      const option = Array.from(yearSelect.options).find(opt => String(opt.value) === String(year));
      if (option) {
        option.remove();
      }
      yearSelect.value = '';
    }

    if (yearInput && String(yearInput.value || '').trim() === String(year)) {
      yearInput.value = '';
    }

    if (selectedYear === String(year)) {
      selectedYear = '';
      const checklistArea = document.getElementById('checklistArea');
      const checklistBody = document.getElementById('checklistBody');
      if (checklistBody) {
        checklistBody.innerHTML = '';
      }
      if (checklistArea) {
        checklistArea.style.display = 'none';
      }
      updateChecklistYearLabel('');
      refreshNoChecklistPlaceholder();
    }

    if (viewEditBtn) {
      viewEditBtn.disabled = true;
    }

    refreshExistingInfo();
    showNotification('success', data.message || 'Curriculum year deleted successfully.');
  })
  .catch(err => {
    showNotification('error', 'Network error: ' + err.message);
  });
}

function updateTotals(termIdx) {
  const tbody = document.getElementById('tbody_' + termIdx);
  let lec = 0, lab = 0, hrsLec = 0, hrsLab = 0;
  for (const row of tbody.rows) {
    lec += parseInt(row.querySelector('[name="credit_lec"]').value) || 0;
    lab += parseInt(row.querySelector('[name="credit_lab"]').value) || 0;
    hrsLec += parseInt(row.querySelector('[name="hrs_lec"]').value) || 0;
    hrsLab += parseInt(row.querySelector('[name="hrs_lab"]').value) || 0;
  }
  document.getElementById('total_lec_' + termIdx).textContent = lec;
  document.getElementById('total_lab_' + termIdx).textContent = lab;
  document.getElementById('total_hrs_lec_' + termIdx).textContent = hrsLec;
  document.getElementById('total_hrs_lab_' + termIdx).textContent = hrsLab;
}

function saveAllCourses() {
  const inputEl = document.getElementById('curriculumYearInput');
  const selectEl = document.getElementById('curriculumYearSelect');
  const typedYear = inputEl ? String(inputEl.value || '').trim() : '';
  const selectedYearFromDropdown = selectEl ? String(selectEl.value || '').trim() : '';
  selectedYear = typedYear || selectedYearFromDropdown || selectedYear;

  if (!selectedYear) {
    const promptedYear = String(prompt('Enter curriculum year (YYYY):') || '').trim();
    if (!promptedYear) {
      showNotification('error', 'Please enter or select a curriculum year before saving.');
      return;
    }
    selectedYear = promptedYear;
    applyYearToControls(selectedYear);
    updateChecklistYearLabel(selectedYear);
  }
  if (!isValidCurriculumYear(selectedYear)) {
    showNotification('error', 'Invalid curriculum year. Please enter a year from 2017 to 2099 before saving.');
    return;
  }

  const courses = [];
  const allVisibleCodes = new Map();

  TERMS.forEach((term, termIdx) => {
    const tbody = document.getElementById('tbody_' + termIdx);
    if (!tbody) return;

    for (const row of tbody.rows) {
      const curriculumKeyPrefix = row.querySelector('[name="curriculum_key_prefix"]')?.value.trim() || '';
      const originalCurriculumKey = row.querySelector('[name="original_curriculum_key"]')?.value.trim() || '';
      const originalCourseCode = row.querySelector('[name="original_course_code"]')?.value.trim() || '';
      const originalCourseTitle = row.querySelector('[name="original_course_title"]')?.value.trim() || '';
      const originalCreditLec = parseInt(row.querySelector('[name="original_credit_lec"]')?.value || '0', 10) || 0;
      const originalCreditLab = parseInt(row.querySelector('[name="original_credit_lab"]')?.value || '0', 10) || 0;
      const originalHrsLec = parseInt(row.querySelector('[name="original_hrs_lec"]')?.value || '0', 10) || 0;
      const originalHrsLab = parseInt(row.querySelector('[name="original_hrs_lab"]')?.value || '0', 10) || 0;
      const originalPreReq = row.querySelector('[name="original_pre_requisite"]')?.value.trim() || 'NONE';

      const code = row.querySelector('[name="course_code"]').value.trim();
      const title = row.querySelector('[name="course_title"]').value.trim();
      if (!code || !title) continue; // skip empty rows

      const normalizedCode = code.toUpperCase();
      if (!allVisibleCodes.has(normalizedCode)) {
        allVisibleCodes.set(normalizedCode, []);
      }
      allVisibleCodes.get(normalizedCode).push(title);

      const lec = parseInt(row.querySelector('[name="credit_lec"]').value) || 0;
      const lab = parseInt(row.querySelector('[name="credit_lab"]').value) || 0;
      const hrsLec = parseInt(row.querySelector('[name="hrs_lec"]').value) || 0;
      const hrsLab = parseInt(row.querySelector('[name="hrs_lab"]').value) || 0;
      const prereq = row.querySelector('[name="pre_requisite"]').value.trim() || 'NONE';

      const hasOriginal = originalCourseCode !== '' || originalCourseTitle !== '';
      const isChanged = !hasOriginal
        || code.toUpperCase() !== originalCourseCode.toUpperCase()
        || title !== originalCourseTitle
        || lec !== originalCreditLec
        || lab !== originalCreditLab
        || hrsLec !== originalHrsLec
        || hrsLab !== originalHrsLab
        || prereq !== originalPreReq;

      if (!isChanged) {
        continue;
      }

      courses.push({
        course_code: code,
        course_title: title,
        year_level: term.year,
        semester: term.semester,
        credit_units_lec: lec,
        credit_units_lab: lab,
        lect_hrs_lec: hrsLec,
        lect_hrs_lab: hrsLab,
        pre_requisite: prereq,
        original_course_code: originalCourseCode,
        original_curriculum_key: originalCurriculumKey,
        curriculum_key_prefix: curriculumKeyPrefix
      });
    }
  });

  // Validate the submitted curriculum snapshot for duplicate course codes.
  const duplicates = [];
  allVisibleCodes.forEach((titles, code) => {
    if (titles.length > 1) {
      const uniqueTitles = [...new Set(titles.filter(Boolean))];
      duplicates.push(uniqueTitles.length > 1
        ? `${code} (${uniqueTitles.join(' / ')})`
        : code);
    }
  });

  if (duplicates.length > 0) {
    showNotification('error', 'Conflicting course codes found: ' + duplicates.join(', '));
    return;
  }

  const hasExistingYear = !!(existingCurriculums[selectedProgram] && existingCurriculums[selectedProgram].includes(selectedYear));
  if (courses.length === 0 && deletedCourseKeys.length === 0 && hasExistingYear) {
    showNotification('info', 'No curriculum changes detected.');
    return;
  }

  const saveBtn = document.getElementById('saveAllBtn');
  saveBtn.disabled = true;
  saveBtn.textContent = 'Saving...';

  const payload = {
    program: selectedProgram,
    curriculum_year: selectedYear,
    courses: courses,
    deleted_courses: deletedCourseKeys
  };

  fetch('save_curriculum.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload)
  })
  .then(res => res.json())
  .then(data => {
    saveBtn.disabled = false;
    saveBtn.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1-2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg> Save Curriculum';

    if (data.success) {
      showNotification('success', data.message || 'Curriculum saved successfully! (' + courses.length + ' courses)');
      deletedCourseKeys = [];
      if (selectedYear) {
        syncChecklistOriginalState(selectedYear);
        curriculumCatalog[selectedYear] = captureChecklistSnapshot(selectedYear);
      }
      // Update existing info
      if (!existingCurriculums[selectedProgram]) existingCurriculums[selectedProgram] = [];
      if (!existingCurriculums[selectedProgram].includes(selectedYear)) existingCurriculums[selectedProgram].push(selectedYear);
      refreshExistingInfo();
    } else {
      showNotification('error', data.message || 'Failed to save curriculum');
    }
  })
  .catch(err => {
    saveBtn.disabled = false;
    saveBtn.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1-2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg> Save Curriculum';
    showNotification('error', 'Network error: ' + err.message);
  });
}

function showNotification(type, message) {
  const n = document.createElement('div');
  n.className = 'notification ' + type;
  const icon = type === 'success'
    ? '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#4CAF50" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>'
    : '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#f44336" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>';
  n.innerHTML = icon + '<span style="font-size:14px;color:#333;">' + message + '</span>';
  document.body.appendChild(n);
  setTimeout(() => n.remove(), 3000);
}

// Sidebar toggle
function isMobileDevice() {
  return window.innerWidth <= 1280 || ('ontouchstart' in window) || /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
}

(function() {
  if (isMobileDevice()) {
    const s = document.getElementById('sidebar');
    s.style.setProperty('display', 'none', 'important');
  }
})();

function toggleSidebar() {
  const sidebar = document.getElementById('sidebar');
  const overlay = document.getElementById('sidebarOverlay');
  if (isMobileDevice()) {
    const isActive = sidebar.classList.contains('active');
    if (isActive) {
      sidebar.classList.remove('active');
      overlay.classList.remove('active');
      sidebar.style.setProperty('display', 'none', 'important');
    } else {
      sidebar.classList.add('active');
      overlay.classList.add('active');
      sidebar.style.setProperty('display', 'block', 'important');
      sidebar.style.setProperty('visibility', 'visible', 'important');
      sidebar.style.setProperty('opacity', '1', 'important');
      sidebar.style.setProperty('transform', 'translateX(0)', 'important');
    }
  } else {
    sidebar.classList.toggle('collapsed');
    document.querySelector('.main-content').classList.toggle('expanded');
  }
}
</script>
</body>
</html>





