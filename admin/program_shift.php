<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/program_shift_service.php';

if (!isset($_SESSION['admin_id']) && !isset($_SESSION['admin_username'])) {
    header('Location: login.php');
    exit();
}

$conn = getDBConnection();
$message = '';
$error = '';
$students = [];
$programOptions = [];
$selectedStudent = '';
$selectedProgram = '';
$reason = '';
$schemaReady = true;

try {
    psAssertProgramShiftSchemaReady($conn);
} catch (RuntimeException $e) {
    $schemaReady = false;
    $error = 'Program shift feature is temporarily unavailable. Run the program shift migration first.';
    error_log('Admin direct program shift blocked: ' . $e->getMessage());
}

if ($schemaReady && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    $selectedStudent = trim((string)($_POST['student_number'] ?? ''));
    $selectedProgram = trim((string)($_POST['destination_program'] ?? ''));
    $reason = trim((string)($_POST['reason'] ?? ''));

    if (!validateCSRFToken($token)) {
        $error = 'Invalid security token. Please refresh the page and try again.';
    } else {
        $actorUsername = trim((string)($_SESSION['admin_username'] ?? $_SESSION['admin_id'] ?? 'admin'));
        $result = psAdminShiftStudent($conn, $selectedStudent, $selectedProgram, $actorUsername, $reason);
        if (!empty($result['ok'])) {
            $message = 'Student shifted successfully. Auto-credited courses: ' . (int)($result['credited_courses'] ?? 0) . '.';
            $selectedStudent = '';
            $selectedProgram = '';
            $reason = '';
        } else {
            $error = (string)($result['message'] ?? 'Unable to shift student.');
        }
    }
}

if ($schemaReady) {
    $studentResult = $conn->query(
        "SELECT student_number, last_name, first_name, middle_name, program, curriculum_year
         FROM student_info
         WHERE student_number IS NOT NULL AND student_number <> ''
         ORDER BY last_name, first_name, student_number"
    );
    if ($studentResult) {
        while ($row = $studentResult->fetch_assoc()) {
            $students[] = $row;
        }
    }

    $programOptions = psGetProgramOptions($conn);
}

$csrfToken = getCSRFToken();
$adminName = htmlspecialchars((string)($_SESSION['admin_full_name'] ?? $_SESSION['admin_username'] ?? 'Admin'), ENT_QUOTES, 'UTF-8');
closeDBConnection($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Program Shift</title>
    <link rel="icon" type="image/png" href="../img/cav.png">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            padding-top: 45px;
            font-family: 'Segoe UI', Tahoma, Arial, sans-serif;
            color: #172033;
            background: linear-gradient(135deg, #eef6ef 0%, #f5f8fb 100%);
            overflow-x: hidden;
        }
        .main-header {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            height: 45px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 6px 15px;
            color: #fff;
            background: linear-gradient(135deg, #206018 0%, #2d8f22 100%);
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
        }
        .main-header > div:first-child { display: flex; align-items: center; }
        .main-header img { height: 32px; margin-right: 10px; cursor: pointer; }
        .brand { color: #d9e441; font-weight: 800; letter-spacing: 0.6px; }
        .admin-info {
            font-size: 15px;
            font-weight: 700;
            background: rgba(255,255,255,0.15);
            border: 1px solid rgba(255,255,255,0.3);
            border-radius: 8px;
            padding: 5px 14px;
        }
        .menu-toggle {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 30px;
            height: 30px;
            margin-right: 10px;
            border: 1px solid rgba(255,255,255,0.35);
            border-radius: 6px;
            background: rgba(255,255,255,0.12);
            color: #fff;
            font-size: 18px;
            cursor: pointer;
        }
        .sidebar {
            width: 250px;
            height: calc(100vh - 45px);
            position: fixed;
            top: 45px;
            left: 0;
            z-index: 999;
            padding: 20px 0;
            overflow-y: auto;
            color: #fff;
            background: linear-gradient(135deg, #1a4f16 0%, #2d8f22 100%);
            box-shadow: 2px 0 5px rgba(0,0,0,0.1);
            transition: transform 0.3s ease;
        }
        .sidebar.collapsed { transform: translateX(-250px); }
        .sidebar-header {
            padding: 15px 20px;
            text-align: center;
            border-bottom: 2px solid rgba(255,255,255,0.2);
            margin-bottom: 5px;
        }
        .sidebar-header h3 { margin: 0; font-size: 20px; }
        .sidebar-menu { list-style: none; padding: 6px 0; margin: 0; }
        .sidebar-menu li { margin: 0; }
        .sidebar-menu a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 20px;
            color: #fff;
            text-decoration: none;
            border-left: 4px solid transparent;
            transition: all 0.25s ease;
        }
        .sidebar-menu a:hover, .sidebar-menu a.active {
            background: rgba(255,255,255,0.14);
            border-left-color: #4CAF50;
        }
        .sidebar-menu img {
            width: 20px;
            height: 20px;
            filter: brightness(0) invert(1);
        }
        .menu-group { margin: 8px 0; }
        .menu-group-title {
            padding: 6px 20px 2px;
            color: rgba(255,255,255,0.72);
            font-size: 13px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .main-content {
            margin-left: 250px;
            min-height: calc(100vh - 45px);
            transition: margin-left 0.3s ease;
        }
        .main-content.expanded { margin-left: 0; }
        .content {
            max-width: 1120px;
            margin: 0 auto;
            padding: 28px 24px 34px;
        }
        .page-header {
            margin-bottom: 18px;
        }
        .page-header h1 {
            margin: 0 0 6px;
            font-size: 32px;
            color: #163417;
        }
        .page-header p {
            margin: 0;
            color: #53636f;
            line-height: 1.55;
        }
        .panel {
            background: #fff;
            border: 1px solid #dbe7df;
            border-radius: 16px;
            box-shadow: 0 18px 40px rgba(15,23,42,0.08);
            padding: 22px;
        }
        .alert {
            margin: 0 0 14px;
            border-radius: 10px;
            padding: 12px 14px;
            font-weight: 700;
        }
        .ok { background: #ecfdf3; color: #166534; border: 1px solid #86efac; }
        .err { background: #fef2f2; color: #b91c1c; border: 1px solid #fca5a5; }
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }
        .field { display: grid; gap: 7px; }
        label { font-size: 13px; font-weight: 800; color: #162033; }
        select, textarea, button {
            width: 100%;
            font: inherit;
            border-radius: 10px;
        }
        select, textarea {
            border: 1px solid #cdd8e3;
            background: #fff;
            padding: 11px 12px;
        }
        textarea {
            min-height: 112px;
            resize: vertical;
        }
        .span-2 { grid-column: 1 / -1; }
        .hint { color: #64748b; font-size: 13px; line-height: 1.5; }
        .actions {
            display: flex;
            gap: 10px;
            align-items: center;
            margin-top: 16px;
            flex-wrap: wrap;
        }
        .submit-btn {
            width: auto;
            min-width: 230px;
            border: 0;
            background: linear-gradient(135deg, #1f7a2f 0%, #35a44a 100%);
            color: #fff;
            font-weight: 800;
            padding: 12px 16px;
            cursor: pointer;
            box-shadow: 0 10px 22px rgba(31,122,47,0.22);
        }
        .form-panel .field select, .form-panel .field textarea {
            width: 100%;
            padding: 12px 14px;
            font-size: 15px;
            border: 1px solid #c8d2cc;
            border-radius: 8px;
            background: #fff;
            color: #172033;
            transition: all 0.2s ease;
        }

        /* Select2 specific overrides to match your theme */
        .select2-container .select2-selection--single {
            height: 44px;
            border: 1px solid #c8d2cc;
            border-radius: 8px;
            padding: 5px 8px;
        }
        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 32px;
            color: #172033;
            padding-right: 30px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 42px;
            right: 8px;
        }
        .select2-container--default .select2-selection--single .select2-selection__clear {
            height: 42px;
            line-height: 32px;
            margin-right: 15px;
        }
        .select2-container--default .select2-selection--single:focus,
        .select2-container--default.select2-container--focus .select2-selection--single {
            border-color: #3b8e40;
            box-shadow: 0 0 0 3px rgba(59, 142, 64, 0.12);
        }
        .student-meta {
            margin-top: 12px;
            padding: 12px;
            border: 1px dashed #cbd5e1;
            border-radius: 10px;
            background: #f8fafc;
            color: #475569;
            font-size: 13px;
        }
        @media (max-width: 820px) {
            .sidebar { transform: translateX(-250px); }
            .sidebar:not(.collapsed) { transform: translateX(0); }
            .main-content { margin-left: 0; }
            .form-grid { grid-template-columns: 1fr; }
            .span-2 { grid-column: auto; }
            .content { padding: 18px 12px; }
            .admin-info { font-size: 11px; padding: 4px 8px; }
        }
    </style>
</head>
<body>
    <div class="main-header">
        <div>
            <button type="button" class="menu-toggle" onclick="toggleSidebar()" aria-label="Toggle sidebar">&#9776;</button>
            <img src="../img/cav.png" alt="CvSU Logo" onclick="toggleSidebar()">
            <span class="brand">ASPLAN</span>
        </div>
        <div class="admin-info"><?= $adminName ?> | Admin</div>
    </div>

    <?php
    $activeAdminPage = 'program_shift';
    $adminSidebarCollapsed = false;
    require __DIR__ . '/../includes/admin_sidebar.php';
    ?>

    <main class="main-content" id="mainContent">
        <div class="content">
            <div class="page-header">
                <h1>Program Shift</h1>
                <p>Shift a student directly to another program. After a successful shift, the student profile uses the destination curriculum and eligible completed courses are auto-credited into the destination checklist.</p>
            </div>

            <section class="panel">
                <?php if ($message !== ''): ?><div class="alert ok"><?= htmlspecialchars($message) ?></div><?php endif; ?>
                <?php if ($error !== ''): ?><div class="alert err"><?= htmlspecialchars($error) ?></div><?php endif; ?>

                <form method="post" id="adminProgramShiftForm">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <div class="form-grid">
                        <div class="field">
                            <label for="student_number">Student</label>
                            <select id="student_number" name="student_number" required>
                                <option value="">Select student...</option>
                                <?php foreach ($students as $student): ?>
                                    <?php
                                        $studentNumber = (string)($student['student_number'] ?? '');
                                        $studentName = trim((string)($student['last_name'] ?? '') . ', ' . (string)($student['first_name'] ?? '') . ' ' . (string)($student['middle_name'] ?? ''));
                                        $studentProgram = trim((string)($student['program'] ?? ''));
                                    ?>
                                    <option
                                        value="<?= htmlspecialchars($studentNumber) ?>"
                                        data-program="<?= htmlspecialchars($studentProgram) ?>"
                                        data-curriculum="<?= htmlspecialchars((string)($student['curriculum_year'] ?? '')) ?>"
                                        <?= $selectedStudent === $studentNumber ? 'selected' : '' ?>
                                    >
                                        <?= htmlspecialchars($studentNumber . ' - ' . $studentName . ' (' . ($studentProgram !== '' ? $studentProgram : 'No program') . ')') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="student-meta" id="studentMeta">Choose a student to review the current program before shifting.</div>
                        </div>

                        <div class="field">
                            <label for="destination_program">Destination Program</label>
                            <select id="destination_program" name="destination_program" required>
                                <option value="">Select destination program...</option>
                                <?php foreach ($programOptions as $program): ?>
                                    <option value="<?= htmlspecialchars((string)$program) ?>" <?= $selectedProgram === (string)$program ? 'selected' : '' ?>>
                                        <?= htmlspecialchars((string)$program) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="hint">Destination must have curriculum courses so the checklist can change correctly.</div>
                        </div>

                        <div class="field span-2">
                            <label for="reason">Admin Note</label>
                            <textarea id="reason" name="reason" placeholder="Optional reason or reference for this shift"><?= htmlspecialchars($reason) ?></textarea>
                        </div>
                    </div>

                    <div class="actions">
                        <button type="submit" class="submit-btn">Shift Student</button>
                        <span class="hint">This also clears current enrollment selections and study plan overrides so the new program can generate cleanly.</span>
                    </div>
                </form>
            </section>
        </div>
    </main>

    <script>
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');
            if (!sidebar || !mainContent) {
                return;
            }
            sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('expanded');
        }

        function updateStudentMeta() {
            const field = document.getElementById('student_number');
            const meta = document.getElementById('studentMeta');
            if (!field || !meta) {
                return;
            }
            const selected = field.options[field.selectedIndex];
            if (!selected || selected.value === '') {
                meta.textContent = 'Choose a student to review the current program before shifting.';
                return;
            }
            const program = selected.getAttribute('data-program') || 'No program';
            const curriculum = selected.getAttribute('data-curriculum') || 'No curriculum year';
            meta.textContent = 'Current program: ' + program + ' | Curriculum year: ' + curriculum;
        }

        document.addEventListener('DOMContentLoaded', function () {
            updateStudentMeta();
            const field = document.getElementById('student_number');
            if (field) {
                field.addEventListener('change', updateStudentMeta);
            }
        });
    </script>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
        $(document).ready(function() {
            $('#student_number').select2({
                placeholder: "Select student...",
                allowClear: true,
                width: '100%'
            });
            $('#destination_program').select2({
                placeholder: "Select destination program...",
                allowClear: true,
                width: '100%'
            });
            $('#student_number').on('change', function() {
                updateStudentMeta();
            });
        });
    </script>
</body>
</html>
