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

function apNormalizeCurriculumYear(string $value): string
{
    $value = strtoupper(trim($value));
    if ($value === '') {
        return '';
    }

    if (preg_match('/^(\d{2})V\d+$/', $value, $matches)) {
        return '20' . $matches[1];
    }

    if (preg_match('/^(\d{4})$/', $value, $matches)) {
        return $matches[1];
    }

    return '';
}

function apAppendCurriculumYear(array &$bucket, string $program, string $year): void
{
    $code = pcNormalizeProgramCode($program);
    $year = apNormalizeCurriculumYear($year);

    if ($code === '' || $year === '') {
        return;
    }

    if (!isset($bucket[$code])) {
        $bucket[$code] = [];
    }

    if (!in_array($year, $bucket[$code], true)) {
        $bucket[$code][] = $year;
    }
}

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
        $redirect = 'curriculum_management.php?program=' . urlencode((string) ($result['code'] ?? ''));
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
$courseYearResult = $conn->query("SELECT DISTINCT SUBSTRING_INDEX(curriculumyear_coursecode, '_', 1) AS curriculum_year, programs FROM cvsucarmona_courses ORDER BY curriculum_year DESC");
if ($courseYearResult) {
    while ($row = $courseYearResult->fetch_assoc()) {
        $year = (string) ($row['curriculum_year'] ?? '');
        $programTokens = array_map('trim', explode(',', (string) ($row['programs'] ?? '')));
        foreach ($programTokens as $programToken) {
            apAppendCurriculumYear($curriculumYearsByProgram, $programToken, $year);
        }
    }
}

$yearsResult = $conn->query("SELECT program, curriculum_year FROM program_curriculum_years ORDER BY curriculum_year DESC");
if ($yearsResult) {
    while ($row = $yearsResult->fetch_assoc()) {
        apAppendCurriculumYear(
            $curriculumYearsByProgram,
            (string) ($row['program'] ?? ''),
            (string) ($row['curriculum_year'] ?? '')
        );
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
            max-width: 1288px;
            margin: 0 auto;
            display: grid;
            gap: 20px;
        }
        .hero {
            position: relative;
            overflow: hidden;
            background:
                radial-gradient(circle at top right, rgba(111, 195, 73, 0.16), rgba(111, 195, 73, 0) 36%),
                linear-gradient(135deg, rgba(255,255,255,0.99) 0%, rgba(247,251,245,0.98) 56%, rgba(239,247,236,0.96) 100%);
            border: 1px solid rgba(32, 96, 24, 0.08);
            border-radius: 26px;
            box-shadow: 0 20px 40px rgba(32, 96, 24, 0.09);
            padding: 22px 28px;
        }
        .hero::before {
            content: '';
            position: absolute;
            inset: 0 auto 0 0;
            width: 6px;
            background: linear-gradient(180deg, #206018 0%, #51a747 100%);
        }
        .hero::after {
            content: '';
            position: absolute;
            right: -70px;
            top: -70px;
            width: 220px;
            height: 220px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(111, 195, 73, 0.18) 0%, rgba(111, 195, 73, 0) 72%);
            pointer-events: none;
        }
        .hero-inner {
            position: relative;
            z-index: 1;
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 18px;
            align-items: center;
        }
        .hero-kicker {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 11px;
            border-radius: 999px;
            background: rgba(32, 96, 24, 0.08);
            color: #2a6623;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.16em;
            text-transform: uppercase;
        }
        .hero h1 {
            margin: 12px 0 8px;
            font-size: clamp(28px, 2.6vw, 38px);
            line-height: 1.02;
            letter-spacing: -0.04em;
            color: #174a12;
        }
        .hero p {
            margin: 0;
            max-width: 700px;
            color: #5c6f5f;
            font-size: 14px;
            line-height: 1.72;
        }
        .hero-stat {
            min-width: 180px;
            padding: 16px 18px;
            border-radius: 20px;
            background: linear-gradient(145deg, rgba(28, 89, 22, 0.96) 0%, rgba(47, 138, 39, 0.92) 100%);
            color: #fff;
            box-shadow: 0 18px 32px rgba(32, 96, 24, 0.18);
        }
        .hero-stat-label {
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.16em;
            text-transform: uppercase;
            opacity: 0.8;
        }
        .hero-stat-value {
            margin-top: 8px;
            font-size: 30px;
            line-height: 1;
            font-weight: 800;
            letter-spacing: -0.05em;
        }
        .hero-stat-note {
            margin-top: 8px;
            font-size: 12px;
            line-height: 1.5;
            color: rgba(255,255,255,0.82);
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
            box-shadow: 0 16px 30px rgba(32, 96, 24, 0.08);
            padding: 20px 22px;
        }
        .panel.programs-list-panel,
        .panel.compact-form-panel {
            width: min(100%, 1020px);
            margin: 0 auto;
        }
        .panel h2 {
            margin: 0 0 8px;
            font-size: 22px;
            color: #174a12;
            letter-spacing: -0.03em;
        }
        .panel p.lead {
            margin: 0 0 18px;
            color: #617564;
            font-size: 13px;
            line-height: 1.72;
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
        .field input,
        .field select {
            width: 100%;
            padding: 14px 15px;
            border-radius: 14px;
            border: 1px solid #d7e6d2;
            background: #fbfdf9;
            font-size: 15px;
            color: #203022;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }
        .field input:focus,
        .field select:focus {
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
            margin-bottom: 16px;
            padding-bottom: 12px;
            border-bottom: 1px solid rgba(32, 96, 24, 0.08);
        }
        .catalog-count {
            color: #5f745f;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.16em;
        }
        .program-selector {
            display: grid;
            gap: 14px;
            padding: 16px;
            border-radius: 22px;
            background: linear-gradient(135deg, rgba(247, 251, 245, 0.98) 0%, rgba(239, 247, 236, 0.98) 100%);
            border: 1px solid rgba(32, 96, 24, 0.08);
            box-shadow:
                inset 0 1px 0 rgba(255,255,255,0.75),
                0 10px 22px rgba(32, 96, 24, 0.05);
        }
        .program-selector-form {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 14px;
            align-items: end;
        }
        .program-selector-title {
            display: flex;
            align-items: center;
            gap: 10px;
            color: #174a12;
            font-size: 13px;
            font-weight: 800;
            letter-spacing: 0.13em;
            text-transform: uppercase;
        }
        .program-selector-summary {
            display: grid;
            gap: 12px;
            padding: 18px 18px 16px;
            border-radius: 20px;
            background:
                linear-gradient(145deg, rgba(255,255,255,0.98) 0%, rgba(250,253,249,0.92) 100%);
            border: 1px solid rgba(32, 96, 24, 0.08);
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.75);
        }
        .program-summary-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            flex-wrap: wrap;
        }
        .program-summary-chip {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 7px 11px;
            border-radius: 999px;
            background: rgba(32, 96, 24, 0.06);
            color: #2e6227;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.12em;
            text-transform: uppercase;
        }
        .program-selector-code {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: fit-content;
            padding: 6px 11px;
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
            font-size: 18px;
            line-height: 1.24;
            color: #173f12;
            letter-spacing: -0.03em;
            max-width: 640px;
        }
        .program-years {
            display: flex;
            flex-wrap: wrap;
            gap: 7px;
        }
        .year-pill {
            display: inline-flex;
            align-items: center;
            padding: 7px 10px;
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
            font-size: 12px;
            line-height: 1.6;
        }
        .compact-form-panel .form-grid {
            gap: 14px;
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
            .hero-inner {
                grid-template-columns: 1fr;
            }
            .hero-stat {
                min-width: 0;
            }
            .program-selector-form {
                grid-template-columns: 1fr;
            }
            .open-builder-btn {
                width: 100%;
                min-width: 0;
            }
            .program-summary-top {
                align-items: flex-start;
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
                <div class="hero-inner">
                    <div class="hero-copy">
                        <span class="hero-kicker"><i class="fas fa-sitemap"></i> Program Catalog</span>
                        <h1>Programs</h1>
                        <p>Review the existing list of programs, then add a new one only when you are ready to generate its blank checklist and start encoding the courses that define it.</p>
                    </div>
                    <div class="hero-stat">
                        <div class="hero-stat-label">Current Catalog</div>
                        <div class="hero-stat-value"><?= count($programCatalog) ?></div>
                        <div class="hero-stat-note">Programs ready for checklist building in the admin workspace.</div>
                    </div>
                </div>
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
                            <form method="get" action="curriculum_management.php" class="program-selector-form">
                                <div class="field">
                                    <label for="program_picker">Programs</label>
                                    <select id="program_picker" name="program">
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
                                <div class="program-summary-top">
                                    <div class="program-selector-title">
                                        <i class="fas fa-list-check"></i>
                                        Selected Program
                                    </div>
                                    <div class="program-summary-chip">
                                        <i class="fas fa-sparkles"></i>
                                        Builder Ready
                                    </div>
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
