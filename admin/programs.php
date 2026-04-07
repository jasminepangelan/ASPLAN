<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/program_catalog.php';

if (!isset($_SESSION['admin_username']) && !isset($_SESSION['admin_id'])) {
    header('Location: ../index.html');
    exit();
}

$adminName = isset($_SESSION['admin_full_name'])
    ? htmlspecialchars((string) $_SESSION['admin_full_name'], ENT_QUOTES, 'UTF-8')
    : 'Admin';
$csrfToken = getCSRFToken();

$conn = getDBConnection();
$flash = $_SESSION['admin_programs_flash'] ?? null;
unset($_SESSION['admin_programs_flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['admin_programs_flash'] = ['type' => 'error', 'message' => 'Your session token expired. Please try again.'];
        header('Location: programs.php');
        exit();
    }

    $result = pcSaveProgramCatalogEntry(
        $conn,
        (string) ($_POST['program_code'] ?? ''),
        (string) ($_POST['program_name'] ?? '')
    );

    $_SESSION['admin_programs_flash'] = [
        'type' => !empty($result['success']) ? 'success' : 'error',
        'message' => (string) ($result['message'] ?? 'Unable to save the program.'),
    ];

    if (!empty($result['success'])) {
        $redirect = '../program_coordinator/curriculum_management.php?program=' . urlencode((string) ($result['code'] ?? ''));
        header('Location: ' . $redirect);
        exit();
    }

    header('Location: programs.php');
    exit();
}

$programCatalog = pcLoadProgramCatalog($conn, true);
$defaultProgramCode = '';
if (!empty($programCatalog)) {
    $programKeys = array_keys($programCatalog);
    $defaultProgramCode = (string) ($programKeys[0] ?? '');
}
$curriculumYearsByProgram = [];
$yearsResult = $conn->query("SELECT program, curriculum_year FROM program_curriculum_years ORDER BY curriculum_year DESC");
if ($yearsResult) {
    while ($row = $yearsResult->fetch_assoc()) {
        $code = pcNormalizeProgramCode((string) ($row['program'] ?? ''));
        $year = trim((string) ($row['curriculum_year'] ?? ''));
        if ($code === '' || $year === '') {
            continue;
        }
        if (!isset($curriculumYearsByProgram[$code])) {
            $curriculumYearsByProgram[$code] = [];
        }
        if (!in_array($year, $curriculumYearsByProgram[$code], true)) {
            $curriculumYearsByProgram[$code][] = $year;
        }
    }
}

foreach ($curriculumYearsByProgram as &$years) {
    rsort($years, SORT_STRING);
}
unset($years);

$activeAdminPage = 'programs';
$adminSidebarCollapsed = false;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Programs - Admin</title>
    <link rel="icon" type="image/png" href="../img/cav.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background:
                radial-gradient(circle at top left, rgba(111, 195, 73, 0.18), rgba(111, 195, 73, 0) 28%),
                linear-gradient(135deg, #f4f7f2 0%, #edf3eb 100%);
            color: #203022;
            overflow-x: hidden;
            padding-top: 45px;
        }
        .main-header {
            width: 100%;
            background: linear-gradient(135deg, #206018 0%, #2d8f22 100%);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 6px 15px;
            height: 45px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
        }
        .main-header > div:first-child {
            display: flex;
            align-items: center;
        }
        .main-header img {
            height: 32px;
            margin-right: 10px;
            cursor: pointer;
        }
        .main-header span {
            font-size: 1.2rem;
            font-weight: 800;
            letter-spacing: 0.6px;
        }
        .admin-info {
            font-size: 15px;
            font-weight: 600;
            color: white;
            background: rgba(255, 255, 255, 0.15);
            padding: 4px 12px;
            border-radius: 8px;
            border: 1px solid rgba(255, 255, 255, 0.3);
            white-space: nowrap;
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
        .menu-toggle:hover { background: rgba(255, 255, 255, 0.22); }
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
        .sidebar.collapsed { transform: translateX(-250px); }
        .sidebar-header {
            padding: 15px 20px;
            text-align: center;
            color: white;
            font-size: 20px;
            font-weight: 700;
            border-bottom: 2px solid rgba(255, 255, 255, 0.2);
            margin-bottom: 5px;
        }
        .sidebar-header h3 {
            margin: 0;
            font-size: 20px;
            font-weight: 700;
        }
        .sidebar-menu {
            list-style: none;
            padding: 6px 0;
            margin: 0;
        }
        .sidebar-menu li { margin: 0; }
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
        }
        .sidebar-menu a:hover {
            background-color: rgba(255,255,255,0.1);
            border-left-color: #4CAF50;
        }
        .sidebar-menu a.active {
            background-color: rgba(255,255,255,0.15);
            border-left-color: #4CAF50;
        }
        .sidebar-menu img {
            width: 20px;
            height: 20px;
            margin-right: 0;
            filter: brightness(0) invert(1);
        }
        .menu-group { margin: 8px 0; }
        .menu-group-title {
            padding: 6px 20px 2px 20px;
            color: rgba(255, 255, 255, 0.7);
            font-size: 15px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .main-content {
            margin-left: 250px;
            min-height: calc(100vh - 45px);
            width: calc(100vw - 250px);
            transition: margin-left 0.3s ease, width 0.3s ease;
            padding: 30px;
        }
        .main-content.expanded {
            margin-left: 0;
            width: 100vw;
        }
        .shell {
            max-width: 1240px;
            margin: 0 auto;
            display: grid;
            gap: 24px;
        }
        .hero {
            position: relative;
            overflow: hidden;
            background:
                radial-gradient(circle at top right, rgba(111, 195, 73, 0.18), rgba(111, 195, 73, 0) 35%),
                linear-gradient(140deg, rgba(255,255,255,0.98) 0%, rgba(246,251,245,0.98) 100%);
            border: 1px solid rgba(32, 96, 24, 0.08);
            border-radius: 28px;
            box-shadow: 0 24px 45px rgba(32, 96, 24, 0.1);
            padding: 28px 32px;
        }
        .hero::before {
            content: '';
            position: absolute;
            inset: 0 auto 0 0;
            width: 8px;
            background: linear-gradient(180deg, #206018 0%, #51a747 100%);
        }
        .hero-kicker {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 7px 12px;
            border-radius: 999px;
            background: rgba(32, 96, 24, 0.08);
            color: #2a6623;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.14em;
            text-transform: uppercase;
        }
        .hero h1 {
            margin: 14px 0 10px;
            font-size: clamp(32px, 3vw, 42px);
            line-height: 1.03;
            letter-spacing: -0.04em;
            color: #174a12;
        }
        .hero p {
            margin: 0;
            max-width: 760px;
            color: #5c6f5f;
            font-size: 15px;
            line-height: 1.75;
        }
        .grid {
            display: grid;
            gap: 24px;
            align-items: stretch;
        }
        .panel {
            background: rgba(255,255,255,0.97);
            border: 1px solid rgba(32, 96, 24, 0.08);
            border-radius: 24px;
            box-shadow: 0 18px 34px rgba(32, 96, 24, 0.08);
            padding: 24px;
        }
        .panel.programs-list-panel,
        .panel.compact-form-panel {
            width: min(100%, 980px);
            margin: 0 auto;
        }
        .panel h2 {
            margin: 0 0 8px;
            font-size: 24px;
            color: #174a12;
            letter-spacing: -0.03em;
        }
        .panel p.lead {
            margin: 0 0 20px;
            color: #617564;
            font-size: 14px;
            line-height: 1.7;
        }
        .flash {
            margin-bottom: 16px;
            padding: 14px 16px;
            border-radius: 14px;
            font-size: 14px;
            font-weight: 600;
        }
        .flash.success {
            background: rgba(76, 175, 80, 0.12);
            color: #22641b;
            border: 1px solid rgba(76, 175, 80, 0.18);
        }
        .flash.error {
            background: rgba(220, 53, 69, 0.1);
            color: #8b1f2a;
            border: 1px solid rgba(220, 53, 69, 0.16);
        }
        .form-grid {
            display: grid;
            gap: 16px;
        }
        .field label {
            display: block;
            margin-bottom: 8px;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: #2c6326;
        }
        .field input {
            width: 100%;
            padding: 14px 15px;
            border-radius: 14px;
            border: 1px solid #d7e6d2;
            background: #fbfdf9;
            font-size: 15px;
            color: #203022;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }
        .field input:focus {
            outline: none;
            border-color: #4caf50;
            box-shadow: 0 0 0 4px rgba(76, 175, 80, 0.12);
        }
        .field small {
            display: block;
            margin-top: 8px;
            font-size: 12px;
            color: #6a7d6c;
            line-height: 1.5;
        }
        .primary-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 14px 18px;
            border: none;
            border-radius: 14px;
            background: linear-gradient(135deg, #206018 0%, #3fa43b 100%);
            color: #fff;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            box-shadow: 0 16px 28px rgba(32, 96, 24, 0.18);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .primary-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 20px 34px rgba(32, 96, 24, 0.22);
        }
        .catalog-meta {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 18px;
            padding-bottom: 14px;
            border-bottom: 1px solid rgba(32, 96, 24, 0.08);
        }
        .catalog-count {
            color: #5f745f;
            font-size: 13px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.12em;
        }
        .program-selector {
            display: grid;
            gap: 16px;
            padding: 18px;
            border-radius: 20px;
            background: linear-gradient(135deg, rgba(247, 251, 245, 0.98) 0%, rgba(240, 247, 238, 0.98) 100%);
            border: 1px solid rgba(32, 96, 24, 0.08);
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.7);
        }
        .program-selector-form {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 14px;
            align-items: end;
        }
        .program-selector-meta {
            display: grid;
            gap: 10px;
        }
        .program-selector-title {
            display: flex;
            align-items: center;
            gap: 10px;
            color: #174a12;
            font-size: 14px;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }
        .program-selector-summary {
            display: grid;
            gap: 10px;
            padding: 16px 18px;
            border-radius: 18px;
            background: rgba(255,255,255,0.88);
            border: 1px solid rgba(32, 96, 24, 0.08);
        }
        .program-selector-code {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: fit-content;
            padding: 5px 10px;
            border-radius: 999px;
            background: rgba(32, 96, 24, 0.08);
            color: #22631c;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.12em;
            text-transform: uppercase;
        }
        .program-selector-name {
            margin: 0;
            font-size: 22px;
            line-height: 1.15;
            color: #173f12;
            letter-spacing: -0.03em;
        }
        .program-years {
            display: flex;
            flex-wrap: wrap;
            gap: 7px;
        }
        .year-pill {
            display: inline-flex;
            align-items: center;
            padding: 6px 9px;
            border-radius: 999px;
            background: rgba(32, 96, 24, 0.06);
            color: #2d6128;
            font-size: 11px;
            font-weight: 700;
        }
        .year-pill.empty {
            background: rgba(109, 125, 112, 0.08);
            color: #617564;
        }
        .open-builder-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-width: 220px;
            min-height: 50px;
            padding: 13px 18px;
            border-radius: 14px;
            text-decoration: none;
            border: none;
            cursor: pointer;
            font-size: 14px;
            font-weight: 700;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            background: linear-gradient(135deg, #206018 0%, #3ea63f 100%);
            color: #fff;
            box-shadow: 0 14px 24px rgba(32, 96, 24, 0.16);
        }
        .open-builder-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 18px 28px rgba(32, 96, 24, 0.2);
        }
        .program-selector-note {
            color: #617564;
            font-size: 13px;
            line-height: 1.6;
        }
        .empty-programs {
            padding: 28px;
            border-radius: 18px;
            background: linear-gradient(145deg, #fbfefb 0%, #f4f9f2 100%);
            border: 1px dashed rgba(32, 96, 24, 0.18);
            text-align: center;
            color: #617564;
        }
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-250px); }
            .main-content {
                margin-left: 0;
                width: 100vw;
                padding: 16px;
            }
            .hero, .panel { padding: 20px; border-radius: 20px; }
            .content-shell { width: 100%; }
            .program-selector-form {
                grid-template-columns: 1fr;
            }
            .open-builder-btn {
                width: 100%;
                min-width: 0;
            }
        }
    </style>
</head>
<body>
    <div class="main-header">
        <div>
            <button type="button" class="menu-toggle" onclick="toggleSidebar()" aria-label="Toggle sidebar">&#9776;</button>
            <img src="../img/cav.png" alt="CvSU Logo" onclick="toggleSidebar()">
            <span>ASPLAN</span>
        </div>
        <div class="admin-info"><?= $adminName ?> | Admin</div>
    </div>

    <?php require __DIR__ . '/../includes/admin_sidebar.php'; ?>

    <div class="main-content" id="mainContent">
        <div class="shell">
            <section class="hero">
                <span class="hero-kicker"><i class="fas fa-sitemap"></i> Program Catalog</span>
                <h1>Programs</h1>
                <p>Review the existing list of programs, then add a new one only when you are ready to generate its blank checklist and start encoding the courses that define it.</p>
            </section>

            <section class="grid">
                <div class="panel programs-list-panel">
                    <div class="catalog-meta">
                        <div>
                            <h2>List of Programs</h2>
                            <p class="lead">Current program entries available for checklist and curriculum creation.</p>
                        </div>
                        <span class="catalog-count"><?= count($programCatalog) ?> program<?= count($programCatalog) === 1 ? '' : 's' ?></span>
                    </div>

                    <?php if (!empty($programCatalog)): ?>
                        <div class="program-selector">
                            <form method="get" action="../program_coordinator/curriculum_management.php" class="program-selector-form">
                                <div class="field">
                                    <label for="program_picker">Programs</label>
                                    <select id="program_picker" name="program" style="width:100%; padding:14px 15px; border-radius:14px; border:1px solid #d7e6d2; background:#fbfdf9; font-size:15px; color:#203022;">
                                        <?php foreach ($programCatalog as $code => $name): ?>
                                            <?php $years = $curriculumYearsByProgram[$code] ?? []; ?>
                                            <option
                                                value="<?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?>"
                                                data-name="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>"
                                                data-years="<?= htmlspecialchars(implode('|', $years), ENT_QUOTES, 'UTF-8') ?>"
                                                <?= $code === $defaultProgramCode ? 'selected' : '' ?>
                                            >
                                                <?= htmlspecialchars($name . ' - ' . $code, ENT_QUOTES, 'UTF-8') ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small>Choose an existing program, then continue directly to the checklist builder.</small>
                                </div>
                                <button type="submit" class="open-builder-btn">
                                    <i class="fas fa-layer-group"></i>
                                    Open Checklist Builder
                                </button>
                            </form>

                            <div class="program-selector-summary">
                                <div class="program-selector-title">
                                    <i class="fas fa-list-check"></i>
                                    Selected Program
                                </div>
                                <span class="program-selector-code" id="programSummaryCode"><?= htmlspecialchars($defaultProgramCode, ENT_QUOTES, 'UTF-8') ?></span>
                                <h3 class="program-selector-name" id="programSummaryName">
                                    <?= htmlspecialchars($defaultProgramCode !== '' ? ($programCatalog[$defaultProgramCode] ?? '') : '', ENT_QUOTES, 'UTF-8') ?>
                                </h3>
                                <div class="program-years" id="programSummaryYears">
                                    <?php if ($defaultProgramCode !== '' && !empty($curriculumYearsByProgram[$defaultProgramCode] ?? [])): ?>
                                        <?php foreach ($curriculumYearsByProgram[$defaultProgramCode] as $year): ?>
                                            <span class="year-pill">Curriculum <?= htmlspecialchars($year, ENT_QUOTES, 'UTF-8') ?></span>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <span class="year-pill empty">No checklist/curriculum year yet</span>
                                    <?php endif; ?>
                                </div>
                                <div class="program-selector-note">
                                    All existing programs stay available here without stretching the page vertically.
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="empty-programs">
                            No programs are available yet. Add the first one to start building its checklist-based curriculum.
                        </div>
                    <?php endif; ?>
                </div>

                <div class="panel compact-form-panel">
                    <h2>Add Program</h2>
                    <p class="lead">Create the program entry, then continue straight to the blank checklist builder where you will input the courses just like the Generate New Curriculum workflow.</p>
                    <?php if (is_array($flash)): ?>
                        <div class="flash <?= $flash['type'] === 'success' ? 'success' : 'error' ?>">
                            <?= htmlspecialchars((string) ($flash['message'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                        </div>
                    <?php endif; ?>
                    <form method="post" class="form-grid">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <div class="field">
                            <label for="program_code">Program Code</label>
                            <input id="program_code" name="program_code" type="text" maxlength="64" placeholder="e.g. BSArch or BSN" required>
                            <small>Use a short stable code. Letters, numbers, and hyphens are safest.</small>
                        </div>
                        <div class="field">
                            <label for="program_name">Program Name</label>
                            <input id="program_name" name="program_name" type="text" maxlength="255" placeholder="e.g. Bachelor of Science in Architecture" required>
                            <small>The display name is what the admin module and curriculum workflow will show.</small>
                        </div>
                        <button type="submit" class="primary-btn">
                            <i class="fas fa-plus-circle"></i>
                            Add Program and Generate Checklist
                        </button>
                    </form>
                </div>
            </section>
        </div>
    </div>

    <script>
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');
            sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('expanded');
        }

        (function bindProgramPicker() {
            const picker = document.getElementById('program_picker');
            if (!picker) {
                return;
            }

            const codeEl = document.getElementById('programSummaryCode');
            const nameEl = document.getElementById('programSummaryName');
            const yearsEl = document.getElementById('programSummaryYears');

            function renderSelectedProgram() {
                const option = picker.options[picker.selectedIndex];
                if (!option) {
                    return;
                }

                if (codeEl) {
                    codeEl.textContent = option.value || '';
                }
                if (nameEl) {
                    nameEl.textContent = option.dataset.name || '';
                }
                if (yearsEl) {
                    const rawYears = (option.dataset.years || '').trim();
                    yearsEl.innerHTML = '';

                    if (rawYears !== '') {
                        rawYears.split('|').forEach((year) => {
                            const pill = document.createElement('span');
                            pill.className = 'year-pill';
                            pill.textContent = 'Curriculum ' + year;
                            yearsEl.appendChild(pill);
                        });
                    } else {
                        const pill = document.createElement('span');
                        pill.className = 'year-pill empty';
                        pill.textContent = 'No checklist/curriculum year yet';
                        yearsEl.appendChild(pill);
                    }
                }
            }

            picker.addEventListener('change', renderSelectedProgram);
            renderSelectedProgram();
        })();
    </script>
</body>
</html>
