<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/laravel_bridge.php';

if (!isset($_SESSION['username']) || (isset($_SESSION['user_type']) && $_SESSION['user_type'] !== 'program_coordinator')) {
    header('Location: ../index.html');
    exit();
}

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

function normalizeProgramCode(string $program): string {
    $p = strtoupper(trim($program));
    if ($p === '') {
        return '';
    }

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

    if (preg_match('/^(\d{2})V\d+$/', $v, $m)) {
        return '20' . $m[1];
    }

    if (preg_match('/^(\d{4})$/', $v, $m)) {
        return $m[1];
    }

    return '';
}

$programNames = [
    'BSIndT' => 'BS Industrial Technology',
    'BSCpE' => 'BS Computer Engineering',
    'BSIT' => 'BS Information Technology',
    'BSCS' => 'BS Computer Science',
    'BSHM' => 'BS Hospitality Management',
    'BSBA-HRM' => 'BSBA - Human Resource Management',
    'BSBA-MM' => 'BSBA - Marketing Management',
    'BSEd-English' => 'BSEd Major in English',
    'BSEd-Science' => 'BSEd Major in Science',
    'BSEd-Math' => 'BSEd Major in Math',
];

$yearOrder = ['First Year' => 1, 'Second Year' => 2, 'Third Year' => 3, 'Fourth Year' => 4];
$semesterOrder = ['First Semester' => 1, 'Second Semester' => 2, 'Mid Year' => 3, 'Midyear' => 3];

$coordinatorName = isset($_SESSION['full_name']) ? htmlspecialchars($_SESSION['full_name']) : '';
$conn = getDBConnection();

$coordinatorProgramRaw = '';
$coordinatorProgramCode = '';
$errorMessage = '';

$pcTable = resolveProgramCoordinatorTable($conn);
if ($pcTable !== null) {
    $username = $_SESSION['username'];

    if (tableHasColumn($conn, $pcTable, 'program')) {
        $stmt = $conn->prepare("SELECT program FROM `$pcTable` WHERE username = ? LIMIT 1");
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
}

if ($coordinatorProgramCode === '') {
    $errorMessage = 'Program is not configured for this account.';
}

$availableYears = [];
$selectedYear = normalizeCurriculumYear((string)($_GET['year'] ?? ''));
$coursesByTerm = [];
$totalCourses = 0;

$bridgeLoaded = false;

if (getenv('USE_LARAVEL_BRIDGE') === '1') {
    $bridgeData = postLaravelJsonBridge(
        'http://localhost/ASPLAN_v10/laravel-app/public/api/program-coordinator/view-curriculum/overview',
        [
            'bridge_authorized' => true,
            'username' => $_SESSION['username'] ?? '',
            'year' => $selectedYear,
        ]
    );

    if (is_array($bridgeData) && !empty($bridgeData['success'])) {
        $coordinatorProgramRaw = (string) ($bridgeData['coordinator_program_raw'] ?? '');
        $coordinatorProgramCode = (string) ($bridgeData['coordinator_program_code'] ?? '');
        $errorMessage = (string) ($bridgeData['error_message'] ?? '');
        $availableYears = isset($bridgeData['available_years']) && is_array($bridgeData['available_years'])
            ? array_values($bridgeData['available_years'])
            : [];
        $selectedYear = (string) ($bridgeData['selected_year'] ?? $selectedYear);
        $coursesByTerm = isset($bridgeData['courses_by_term']) && is_array($bridgeData['courses_by_term'])
            ? $bridgeData['courses_by_term']
            : [];
        $totalCourses = (int) ($bridgeData['total_courses'] ?? 0);
        $bridgeLoaded = true;
    }
}

if (!$bridgeLoaded) {
if ($errorMessage === '') {
    $stmt = $conn->prepare(
        "SELECT curriculumyear_coursecode, programs, course_title, year_level, semester,
                credit_units_lec, credit_units_lab, lect_hrs_lec, lect_hrs_lab, pre_requisite
         FROM cvsucarmona_courses
         WHERE FIND_IN_SET(?, REPLACE(programs, ', ', ',')) > 0"
    );
    $stmt->bind_param('s', $coordinatorProgramCode);
    $stmt->execute();
    $rows = $stmt->get_result();

    $allProgramRows = [];
    while ($row = $rows->fetch_assoc()) {
        $allProgramRows[] = $row;

        $prefix = explode('_', (string)$row['curriculumyear_coursecode'], 2)[0] ?? '';
        $normalized = normalizeCurriculumYear($prefix);
        if ($normalized !== '') {
            $availableYears[$normalized] = true;
        }
    }
    $stmt->close();

    $availableYears = array_keys($availableYears);
    sort($availableYears, SORT_NUMERIC);

    if ($selectedYear === '' && !empty($availableYears)) {
        $selectedYear = end($availableYears);
    }

    foreach ($allProgramRows as $row) {
        $parts = explode('_', (string)$row['curriculumyear_coursecode'], 2);
        $prefix = $parts[0] ?? '';
        $courseCode = $parts[1] ?? (string)$row['curriculumyear_coursecode'];
        $normalized = normalizeCurriculumYear($prefix);

        if ($normalized === '' || $normalized !== $selectedYear) {
            continue;
        }

        $yearLevel = (string)($row['year_level'] ?? '');
        $semester = (string)($row['semester'] ?? '');

        if (!isset($coursesByTerm[$yearLevel])) {
            $coursesByTerm[$yearLevel] = [];
        }
        if (!isset($coursesByTerm[$yearLevel][$semester])) {
            $coursesByTerm[$yearLevel][$semester] = [];
        }

        $coursesByTerm[$yearLevel][$semester][] = [
            'course_code' => $courseCode,
            'course_title' => (string)($row['course_title'] ?? ''),
            'credit_units_lec' => (int)($row['credit_units_lec'] ?? 0),
            'credit_units_lab' => (int)($row['credit_units_lab'] ?? 0),
            'lect_hrs_lec' => (int)($row['lect_hrs_lec'] ?? 0),
            'lect_hrs_lab' => (int)($row['lect_hrs_lab'] ?? 0),
            'pre_requisite' => (string)($row['pre_requisite'] ?? 'NONE'),
        ];
        $totalCourses++;
    }

    uksort($coursesByTerm, function ($a, $b) use ($yearOrder) {
        return ($yearOrder[$a] ?? 99) <=> ($yearOrder[$b] ?? 99);
    });

    foreach ($coursesByTerm as $yearLevel => $semesters) {
        uksort($semesters, function ($a, $b) use ($semesterOrder) {
            return ($semesterOrder[$a] ?? 99) <=> ($semesterOrder[$b] ?? 99);
        });

        foreach ($semesters as $semester => $rows) {
            usort($rows, function ($x, $y) {
                return strcmp($x['course_code'], $y['course_code']);
            });
            $coursesByTerm[$yearLevel][$semester] = $rows;
        }
    }
}

}

closeDBConnection($conn);

$programDisplayName = $programNames[$coordinatorProgramCode] ?? $coordinatorProgramRaw;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>View Curriculum - Program Coordinator</title>
  <link rel="icon" type="image/png" href="../img/cav.png">
  <style>
    body { font-family: 'Segoe UI', Tahoma, Verdana, sans-serif; background: #f5f5f5; margin: 0; }
    .title-bar {
      background: linear-gradient(135deg, #206018 0%, #2d8f22 100%);
      color: #fff;
      padding: 3px 15px;
      font-size: 18px;
      font-weight: 800;
      display: flex;
      align-items: center;
      justify-content: space-between;
      position: sticky;
      top: 0;
      z-index: 1000;
    }
    .title-content { display: flex; align-items: center; }
    .title-bar img { height: 32px; margin-right: 12px; }
    .name-chip {
      font-size: 15px;
      font-weight: 600;
      color: #facc41;
      background: rgba(250, 204, 65, 0.15);
      padding: 8px 14px;
      border-radius: 8px;
      border: 1px solid rgba(250, 204, 65, 0.3);
    }
    .main { padding: 20px; max-width: 1200px; margin: 0 auto; }
    .panel {
      background: #fff;
      border-radius: 10px;
      box-shadow: 0 2px 10px rgba(0,0,0,0.1);
      padding: 18px;
      margin-bottom: 18px;
    }
    .panel h1 { margin: 0 0 10px; font-size: 24px; color: #333; }
    .meta-row { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; }
    .pill {
      display: inline-block;
      background: #eef7ee;
      color: #1f5e19;
      border: 1px solid #d6ecd5;
      border-radius: 999px;
      font-size: 13px;
      font-weight: 600;
      padding: 6px 12px;
    }
    .btn {
      border: none;
      border-radius: 6px;
      padding: 9px 14px;
      font-size: 13px;
      font-weight: 600;
      cursor: pointer;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      gap: 6px;
    }
    .btn-back { background: #455A64; color: #fff; }
    .btn-back:hover { background: #37474F; }
    .btn-print { background: #1976D2; color: #fff; }
    .btn-print:hover { background: #1565C0; }
    .btn-save { background: #2E7D32; color: #fff; }
    .btn-save:hover { background: #1B5E20; }
    .btn-add { background: #4CAF50; color: #fff; }
    .btn-add:hover { background: #43A047; }
    .btn-remove {
      background: transparent;
      color: #c62828;
      border: 1px solid #ef9a9a;
      padding: 5px 8px;
      border-radius: 4px;
      cursor: pointer;
      font-weight: 700;
    }
    .btn-remove:hover { background: #ffebee; }
    .error-box {
      background: #fff3f2;
      color: #a93226;
      border: 1px solid #f1c3be;
      border-radius: 8px;
      padding: 12px;
      font-size: 14px;
    }
    .term-card {
      background: #fff;
      border-radius: 10px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.08);
      margin-bottom: 14px;
      overflow: hidden;
    }
    .term-title {
      background: #e8f5e9;
      color: #1f5e19;
      padding: 10px 14px;
      font-weight: 700;
      font-size: 14px;
      border-bottom: 1px solid #dbead9;
    }
    table { width: 100%; border-collapse: collapse; }
    th, td { border: 1px solid #e6e6e6; padding: 8px; font-size: 12px; }
    th { background: #f7f9fb; color: #333; text-transform: uppercase; font-size: 11px; }
    td:nth-child(1) { white-space: nowrap; font-weight: 600; }
    .course-input {
      width: 100%;
      box-sizing: border-box;
      border: 1px solid #d5dbe0;
      border-radius: 5px;
      padding: 6px;
      font-size: 12px;
      background: #fff;
    }
    .course-input:focus {
      outline: none;
      border-color: #4CAF50;
      box-shadow: 0 0 0 2px rgba(76, 175, 80, 0.12);
    }
    .term-actions {
      display: flex;
      justify-content: flex-end;
      padding: 10px 12px;
      border-top: 1px solid #eef1f3;
      background: #fcfdfc;
    }
    .empty-box {
      background: #fff;
      border: 1px dashed #d6d6d6;
      border-radius: 10px;
      padding: 22px;
      text-align: center;
      color: #666;
      font-size: 14px;
    }
    .notice {
      margin-top: 10px;
      padding: 10px 12px;
      border-radius: 7px;
      font-size: 13px;
      display: none;
    }
    .notice.success {
      background: #e8f5e9;
      color: #1b5e20;
      border: 1px solid #c8e6c9;
      display: block;
    }
    .notice.error {
      background: #ffebee;
      color: #b71c1c;
      border: 1px solid #ffcdd2;
      display: block;
    }
    @media print {
      .title-bar, .actions { display: none; }
      body { background: #fff; }
      .main { max-width: 100%; padding: 0; }
      .panel, .term-card { box-shadow: none; border: 1px solid #ddd; }
    }
    @media (max-width: 768px) {
      .name-chip { display: none; }
      .main { padding: 12px; }
      .panel h1 { font-size: 20px; }
      th, td { font-size: 11px; padding: 6px; }
    }
  </style>
</head>
<body>
  <div class="title-bar">
    <div class="title-content">
      <img src="../img/cav.png" alt="CvSU Logo">
      <span style="color: #d9e441;">ASPLAN</span>
    </div>
    <div class="name-chip"><?= $coordinatorName; ?> | Program Coordinator</div>
  </div>

  <div class="main">
    <div class="panel">
      <h1>Curriculum Checklist View</h1>
      <div class="meta-row">
        <span class="pill">Program: <?= htmlspecialchars($programDisplayName ?: 'Not Set') ?></span>
        <span class="pill">Curriculum Year: <?= htmlspecialchars($selectedYear ?: 'N/A') ?></span>
        <span class="pill">Total Courses: <?= (int)$totalCourses ?></span>
      </div>
      <div class="meta-row actions" style="margin-top: 12px;">
        <a href="curriculum_management.php" class="btn btn-back">Back to Curriculum Management</a>
        <button type="button" class="btn btn-print" onclick="window.print()">Print</button>
        <?php if ($errorMessage === '' && $selectedYear !== ''): ?>
          <button type="button" class="btn btn-save" id="saveChangesBtn" onclick="saveChanges()">Save Changes</button>
        <?php endif; ?>
      </div>
      <div id="saveNotice" class="notice"></div>
    </div>

    <div class="menu-group">
        <div class="menu-group-title">Modules</div>
        <li><a href="curriculum_management.php" class="active"><img src="../pix/curr.png" alt="Curriculum"> Curriculum Management</a></li>
        <li><a href="adviser_management.php"><img src="../pix/account.png" alt="Advisers"> Adviser Management</a></li>
        <li><a href="list_of_students.php"><img src="../pix/checklist.png" alt="Students"> List of Students</a></li>
        <li><a href="profile.php"><img src="../pix/account.png" alt="Profile"> Update Profile</a></li>
    </div>

    <div class="menu-group">
        <div class="menu-group-title">Account</div>
        <li><a href="logout.php"><img src="../pix/singout.png" alt="Sign Out"> Sign Out</a></li>
    </div>

    <?php if ($errorMessage !== ''): ?>
      <div class="error-box"><?= htmlspecialchars($errorMessage) ?></div>
    <?php elseif ($selectedYear === ''): ?>
      <div class="empty-box">No curriculum year available for this program yet.</div>
    <?php elseif (empty($coursesByTerm)): ?>
      <div class="empty-box">No checklist data found for <?= htmlspecialchars($programDisplayName) ?> (<?= htmlspecialchars($selectedYear) ?>).</div>
    <?php else: ?>
      <?php foreach ($coursesByTerm as $yearLevel => $semesters): ?>
        <?php foreach ($semesters as $semester => $rows): ?>
          <div class="term-card">
            <div class="term-title"><?= htmlspecialchars($yearLevel) ?> - <?= htmlspecialchars($semester) ?></div>
            <table>
              <thead>
                <tr>
                  <th>Course Code</th>
                  <th>Course Title</th>
                  <th>Credit Lec</th>
                  <th>Credit Lab</th>
                  <th>Hrs Lec</th>
                  <th>Hrs Lab</th>
                  <th>Pre-requisite</th>
                  <th>Remove</th>
                </tr>
              </thead>
              <tbody data-year-level="<?= htmlspecialchars($yearLevel) ?>" data-semester="<?= htmlspecialchars($semester) ?>">
                <?php foreach ($rows as $c): ?>
                  <tr class="course-row"
                      data-original-code="<?= htmlspecialchars($c['course_code']) ?>"
                      data-original-title="<?= htmlspecialchars($c['course_title']) ?>"
                      data-original-credit-lec="<?= (int)$c['credit_units_lec'] ?>"
                      data-original-credit-lab="<?= (int)$c['credit_units_lab'] ?>"
                      data-original-hrs-lec="<?= (int)$c['lect_hrs_lec'] ?>"
                      data-original-hrs-lab="<?= (int)$c['lect_hrs_lab'] ?>"
                      data-original-prereq="<?= htmlspecialchars($c['pre_requisite']) ?>">
                    <td><input class="course-input code" type="text" value="<?= htmlspecialchars($c['course_code']) ?>"></td>
                    <td><input class="course-input title" type="text" value="<?= htmlspecialchars($c['course_title']) ?>"></td>
                    <td><input class="course-input credit-lec" type="number" min="0" max="20" value="<?= (int)$c['credit_units_lec'] ?>"></td>
                    <td><input class="course-input credit-lab" type="number" min="0" max="20" value="<?= (int)$c['credit_units_lab'] ?>"></td>
                    <td><input class="course-input hrs-lec" type="number" min="0" max="99" value="<?= (int)$c['lect_hrs_lec'] ?>"></td>
                    <td><input class="course-input hrs-lab" type="number" min="0" max="999" value="<?= (int)$c['lect_hrs_lab'] ?>"></td>
                    <td><input class="course-input prereq" type="text" value="<?= htmlspecialchars($c['pre_requisite']) ?>"></td>
                    <td style="text-align:center;"><button type="button" class="btn-remove" onclick="removeRow(this)">X</button></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
            <div class="term-actions">
              <button type="button" class="btn btn-add" onclick="addRow(this)">Add Course</button>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <?php if ($errorMessage === '' && $selectedYear !== ''): ?>
  <script>
    const selectedProgram = <?= json_encode($coordinatorProgramCode) ?>;
    const selectedYear = <?= json_encode($selectedYear) ?>;
    let deletedCourseCodes = [];

    function showSaveNotice(type, message) {
      const box = document.getElementById('saveNotice');
      if (!box) return;
      box.className = 'notice ' + type;
      box.textContent = message;
    }

    function addRow(btn) {
      const card = btn.closest('.term-card');
      const tbody = card ? card.querySelector('tbody') : null;
      if (!tbody) return;

      const row = document.createElement('tr');
      row.className = 'course-row';
      row.dataset.originalCode = '';
      row.dataset.originalTitle = '';
      row.dataset.originalCreditLec = '';
      row.dataset.originalCreditLab = '';
      row.dataset.originalHrsLec = '';
      row.dataset.originalHrsLab = '';
      row.dataset.originalPrereq = '';
      row.innerHTML = `
        <td><input class="course-input code" type="text" placeholder="e.g. COSC 50"></td>
        <td><input class="course-input title" type="text" placeholder="Course Title"></td>
        <td><input class="course-input credit-lec" type="number" min="0" max="20" value="0"></td>
        <td><input class="course-input credit-lab" type="number" min="0" max="20" value="0"></td>
        <td><input class="course-input hrs-lec" type="number" min="0" max="99" value="0"></td>
        <td><input class="course-input hrs-lab" type="number" min="0" max="999" value="0"></td>
        <td><input class="course-input prereq" type="text" value="NONE"></td>
        <td style="text-align:center;"><button type="button" class="btn-remove" onclick="removeRow(this)">X</button></td>
      `;
      tbody.appendChild(row);
      const firstInput = row.querySelector('.code');
      if (firstInput) firstInput.focus();
    }

    function removeRow(btn) {
      const row = btn.closest('tr');
      if (!row) return;

      const originalCode = (row.dataset.originalCode || '').trim();
      if (originalCode && !deletedCourseCodes.includes(originalCode)) {
        deletedCourseCodes.push(originalCode);
      }

      row.remove();
    }

    function collectCourses() {
      const courses = [];
      document.querySelectorAll('.term-card tbody').forEach((tbody) => {
        const yearLevel = (tbody.dataset.yearLevel || '').trim();
        const semester = (tbody.dataset.semester || '').trim();

        tbody.querySelectorAll('tr.course-row').forEach((row) => {
          const originalCode = (row.dataset.originalCode || '').trim();
          const originalTitle = (row.dataset.originalTitle || '').trim();
          const originalCreditLec = parseInt(row.dataset.originalCreditLec || '0', 10) || 0;
          const originalCreditLab = parseInt(row.dataset.originalCreditLab || '0', 10) || 0;
          const originalHrsLec = parseInt(row.dataset.originalHrsLec || '0', 10) || 0;
          const originalHrsLab = parseInt(row.dataset.originalHrsLab || '0', 10) || 0;
          const originalPrereq = (row.dataset.originalPrereq || 'NONE').trim() || 'NONE';

          const code = (row.querySelector('.code')?.value || '').trim();
          const title = (row.querySelector('.title')?.value || '').trim();
          if (!code || !title) {
            return;
          }

          const lec = parseInt(row.querySelector('.credit-lec')?.value || '0', 10) || 0;
          const lab = parseInt(row.querySelector('.credit-lab')?.value || '0', 10) || 0;
          const hrsLec = parseInt(row.querySelector('.hrs-lec')?.value || '0', 10) || 0;
          const hrsLab = parseInt(row.querySelector('.hrs-lab')?.value || '0', 10) || 0;
          const prereq = (row.querySelector('.prereq')?.value || '').trim() || 'NONE';

          const hasOriginal = originalCode !== '' || originalTitle !== '';
          const isChanged = !hasOriginal
            || code.toUpperCase() !== originalCode.toUpperCase()
            || title !== originalTitle
            || lec !== originalCreditLec
            || lab !== originalCreditLab
            || hrsLec !== originalHrsLec
            || hrsLab !== originalHrsLab
            || prereq !== originalPrereq;

          if (!isChanged) {
            return;
          }

          courses.push({
            course_code: code,
            course_title: title,
            year_level: yearLevel,
            semester: semester,
            credit_units_lec: lec,
            credit_units_lab: lab,
            lect_hrs_lec: hrsLec,
            lect_hrs_lab: hrsLab,
            pre_requisite: prereq,
            original_course_code: originalCode
          });
        });
      });
      return courses;
    }

    function saveChanges() {
      const btn = document.getElementById('saveChangesBtn');
      const totalRows = document.querySelectorAll('.term-card tbody tr.course-row').length;
      const courses = collectCourses();

      if (courses.length === 0 && deletedCourseCodes.length === 0) {
        showSaveNotice('info', totalRows > 0 ? 'No curriculum changes detected.' : 'No courses to save. Add at least one valid course row.');
        return;
      }

      const visibleCodeMap = new Map();
      document.querySelectorAll('.term-card tbody tr.course-row').forEach((row) => {
        const code = String(row.querySelector('.code')?.value || '').trim().toUpperCase();
        const title = String(row.querySelector('.title')?.value || '').trim();
        if (!code || !title) {
          return;
        }

        if (!visibleCodeMap.has(code)) {
          visibleCodeMap.set(code, []);
        }
        visibleCodeMap.get(code).push(title);
      });

      const codeMap = new Map();
      visibleCodeMap.forEach((titles, code) => codeMap.set(code, titles));

      const duplicates = [];
      codeMap.forEach((titles, code) => {
        if (titles.length > 1) {
          const uniqueTitles = [...new Set(titles.filter(Boolean))];
          duplicates.push(uniqueTitles.length > 1 ? `${code} (${uniqueTitles.join(' / ')})` : code);
        }
      });

      if (duplicates.length > 0) {
        showSaveNotice('error', 'Conflicting course codes found: ' + duplicates.join(', '));
        return;
      }

      if (btn) {
        btn.disabled = true;
        btn.textContent = 'Saving...';
      }

      fetch('save_curriculum.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          program: selectedProgram,
          curriculum_year: selectedYear,
          courses: courses,
          deleted_courses: deletedCourseCodes
        })
      })
      .then(res => res.json())
      .then(data => {
        if (data && data.success) {
          deletedCourseCodes = [];
          showSaveNotice('success', data.message || 'Curriculum changes saved successfully.');
        } else {
          showSaveNotice('error', (data && data.message) ? data.message : 'Failed to save curriculum changes.');
        }
      })
      .catch(err => {
        showSaveNotice('error', 'Network error: ' + err.message);
      })
      .finally(() => {
        if (btn) {
          btn.disabled = false;
          btn.textContent = 'Save Changes';
        }
      });
    }
  </script>
  <?php endif; ?>
</body>
</html>
