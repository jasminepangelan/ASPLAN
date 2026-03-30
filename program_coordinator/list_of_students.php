<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/laravel_bridge.php';

if (!isset($_SESSION['username']) || (isset($_SESSION['user_type']) && $_SESSION['user_type'] !== 'program_coordinator')) {
    header('Location: ../index.html');
    exit();
}

$coordinatorName = isset($_SESSION['full_name']) ? htmlspecialchars((string)$_SESSION['full_name']) : 'Program Coordinator';
function acronymFromPhrase($text) {
    $cleaned = strtoupper(trim((string)$text));
    if ($cleaned === '') {
        return '';
    }

    $cleaned = preg_replace('/[^A-Z0-9\s]/', ' ', $cleaned);
    $cleaned = preg_replace('/\s+/', ' ', $cleaned);
    $tokens = explode(' ', $cleaned);
    $skip = ['OF', 'IN', 'AND', 'THE', 'A', 'AN', 'MAJOR', 'PROGRAM'];
    $result = '';

    foreach ($tokens as $token) {
        if ($token === '' || in_array($token, $skip, true)) {
            continue;
        }
        $result .= substr($token, 0, 1);
    }

    return $result;
}

function normalizeProgramKey($programName) {
    $programName = trim((string)$programName);
    if ($programName === '') {
        return '';
    }

    $normalized = strtoupper(preg_replace('/\s+/', ' ', $programName));

    // Prevent collisions where different programs share similar acronyms.
    if (strpos($normalized, 'INFORMATION TECHNOLOGY') !== false) {
        return 'BSIT';
    }
    if (strpos($normalized, 'INDUSTRIAL TECHNOLOGY') !== false) {
        return 'BSINDTECH';
    }

    if (preg_match('/\b(BSCS|BSIT|BSIS|BSBA|BSA|BSED|BEED|BSCPE|BSCP[E]?|BSCE|BSEE|BSME|BSTM|BSHM|BSN|ABENG|ABPSYCH|ABCOMM)\b/', $normalized, $codeMatch)) {
        $baseCode = strtoupper($codeMatch[1]);
    } elseif (strpos($normalized, 'BACHELOR OF SCIENCE IN') !== false) {
        $subject = trim(str_replace('BACHELOR OF SCIENCE IN', '', $normalized));
        $baseCode = 'BS' . acronymFromPhrase($subject);
    } elseif (strpos($normalized, 'BACHELOR OF SECONDARY EDUCATION') !== false) {
        $baseCode = 'BSED';
    } elseif (strpos($normalized, 'BACHELOR OF ELEMENTARY EDUCATION') !== false) {
        $baseCode = 'BEED';
    } elseif (strpos($normalized, 'BACHELOR OF SCIENCE') !== false) {
        $subject = trim(str_replace('BACHELOR OF SCIENCE', '', $normalized));
        $baseCode = 'BS' . acronymFromPhrase($subject);
    } elseif (strpos($normalized, 'BACHELOR OF ARTS') !== false) {
        $subject = trim(str_replace('BACHELOR OF ARTS', '', $normalized));
        $baseCode = 'AB' . acronymFromPhrase($subject);
    } else {
        $baseCode = strtoupper($programName);
    }

    $majorKey = '';
    if (preg_match('/MAJOR\s+IN\s+(.+)$/', $normalized, $majorMatch)) {
        $majorKey = acronymFromPhrase($majorMatch[1]);
    }

    if ($majorKey !== '') {
        return $baseCode . '-' . $majorKey;
    }

    return $baseCode;
}

function parseProgramList($programRaw) {
    $programRaw = trim((string)$programRaw);
    if ($programRaw === '') {
        return [];
    }

    $parts = preg_split('/\s*,\s*/', $programRaw);
    $normalized = [];
    foreach ($parts as $part) {
        $key = normalizeProgramKey($part);
        if ($key !== '') {
            $normalized[] = $key;
        }
    }

    return array_values(array_unique($normalized, SORT_STRING));
}

function resolveCoordinatorProgramKeys(mysqli $conn, $username) {
    $username = trim((string)$username);
    if ($username === '') {
        return [];
    }

    $tables = ['program_coordinator', 'program_coordinators'];
    foreach ($tables as $table) {
        $tableSafe = $conn->real_escape_string($table);
        $tableResult = $conn->query("SHOW TABLES LIKE '$tableSafe'");
        if (!$tableResult || $tableResult->num_rows === 0) {
            continue;
        }

        $columnResult = $conn->query("SHOW COLUMNS FROM `$tableSafe` LIKE 'program'");
        if (!$columnResult || $columnResult->num_rows === 0) {
            continue;
        }

        $stmt = $conn->prepare("SELECT TRIM(program) AS program FROM `$tableSafe` WHERE username = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('s', $username);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result && $result->num_rows > 0) {
                $row = $result->fetch_assoc();
                $stmt->close();
                $keys = parseProgramList((string)($row['program'] ?? ''));
                if (!empty($keys)) {
                    return $keys;
                }
            } else {
                $stmt->close();
            }
        }
    }

    $fallback = $conn->prepare("SELECT TRIM(program) AS program FROM adviser WHERE username = ? LIMIT 1");
    if ($fallback) {
        $fallback->bind_param('s', $username);
        $fallback->execute();
        $fallbackResult = $fallback->get_result();
        if ($fallbackResult && $fallbackResult->num_rows > 0) {
            $row = $fallbackResult->fetch_assoc();
            $fallback->close();
            return parseProgramList((string)($row['program'] ?? ''));
        }
        $fallback->close();
    }

    return [];
}

$selectedBatch = isset($_GET['batch']) ? trim((string)$_GET['batch']) : '';
$search = isset($_GET['search']) ? trim((string)$_GET['search']) : '';
$recordsPerPage = 10;
$currentPage = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$currentPage = max(1, $currentPage);

$coordinatorPrograms = [];
$availableBatches = [];
$displayRows = [];
$totalRecords = 0;
$totalPages = 1;
$bridgeLoaded = false;

if (getenv('USE_LARAVEL_BRIDGE') === '1') {
    $bridgeData = postLaravelJsonBridge(
        'http://localhost/ASPLAN_v10/laravel-app/public/api/program-coordinator/list-of-students/overview',
        [
            'bridge_authorized' => true,
            'username' => $_SESSION['username'] ?? '',
            'search' => $search,
            'batch' => $selectedBatch,
            'page' => $currentPage,
            'records_per_page' => $recordsPerPage,
        ]
    );

    if (is_array($bridgeData) && !empty($bridgeData['success'])) {
        $coordinatorPrograms = isset($bridgeData['coordinator_programs']) && is_array($bridgeData['coordinator_programs'])
            ? $bridgeData['coordinator_programs']
            : [];
        $availableBatches = isset($bridgeData['available_batches']) && is_array($bridgeData['available_batches'])
            ? $bridgeData['available_batches']
            : [];
        $displayRows = isset($bridgeData['students']) && is_array($bridgeData['students'])
            ? $bridgeData['students']
            : [];
        $totalRecords = (int) ($bridgeData['total_records'] ?? 0);
        $totalPages = max(1, (int) ($bridgeData['total_pages'] ?? 1));
        $currentPage = max(1, (int) ($bridgeData['current_page'] ?? $currentPage));
        $search = (string) ($bridgeData['search'] ?? $search);
        $selectedBatch = (string) ($bridgeData['batch'] ?? $selectedBatch);
        $bridgeLoaded = true;
    }
}

if (!$bridgeLoaded) {
    $conn = getDBConnection();
    $conn->set_charset('utf8mb4');
    $coordinatorPrograms = resolveCoordinatorProgramKeys($conn, $_SESSION['username'] ?? '');
    if (!empty($coordinatorPrograms)) {
        $stmtBatches = $conn->prepare("SELECT DISTINCT LEFT(student_number, 4) AS batch, TRIM(program) AS program
                                       FROM student_info
                                       WHERE student_number IS NOT NULL AND student_number != ''
                                       ORDER BY batch DESC");
        if ($stmtBatches) {
            $stmtBatches->execute();
            $resBatches = $stmtBatches->get_result();
            while ($row = $resBatches->fetch_assoc()) {
                if (in_array(normalizeProgramKey((string)($row['program'] ?? '')), $coordinatorPrograms, true)) {
                    $availableBatches[] = (string)$row['batch'];
                }
            }
            $stmtBatches->close();
        }
        $availableBatches = array_values(array_unique($availableBatches, SORT_STRING));
        rsort($availableBatches, SORT_STRING);
    }

    if ($selectedBatch !== '' && !in_array($selectedBatch, $availableBatches, true)) {
        $selectedBatch = '';
    }

    $whereParts = [];
    if (!empty($coordinatorPrograms)) {
        $whereParts[] = "TRIM(program) IS NOT NULL";
    } else {
        $whereParts[] = "1 = 0";
    }

    if ($search !== '') {
        $searchEscaped = $conn->real_escape_string($search);
        $whereParts[] = "(student_number LIKE '%$searchEscaped%'
                        OR last_name LIKE '%$searchEscaped%'
                        OR first_name LIKE '%$searchEscaped%'
                        OR middle_name LIKE '%$searchEscaped%')";
    }

    if ($selectedBatch !== '') {
        $batchEscaped = $conn->real_escape_string($selectedBatch);
        $whereParts[] = "LEFT(student_number, 4) = '$batchEscaped'";
    }

    $whereClause = ' WHERE ' . implode(' AND ', $whereParts);

    $queryParams = [];
    if ($search !== '') {
        $queryParams['search'] = $search;
    }
    if ($selectedBatch !== '') {
        $queryParams['batch'] = $selectedBatch;
    }
    $paginationSuffix = empty($queryParams) ? '' : '&' . http_build_query($queryParams);

    $countSql = "SELECT student_number, program FROM student_info $whereClause";
    $countResult = $conn->query($countSql);
    if ($countResult) {
        while ($row = $countResult->fetch_assoc()) {
            if (in_array(normalizeProgramKey((string)($row['program'] ?? '')), $coordinatorPrograms, true)) {
                $totalRecords++;
            }
        }
    }
    $totalPages = max(1, (int)ceil($totalRecords / $recordsPerPage));
    if ($currentPage > $totalPages) {
        $currentPage = $totalPages;
    }

    $sql = "SELECT student_number, last_name, first_name, middle_name, program
            FROM student_info
            $whereClause
            ORDER BY last_name, first_name";
    $result = $conn->query($sql);
    $filteredRows = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            if (in_array(normalizeProgramKey((string)($row['program'] ?? '')), $coordinatorPrograms, true)) {
                $filteredRows[] = $row;
            }
        }
    }
    $offset = ($currentPage - 1) * $recordsPerPage;
    $displayRows = array_slice($filteredRows, $offset, $recordsPerPage);
    closeDBConnection($conn);
} else {
    $queryParams = [];
    if ($search !== '') {
        $queryParams['search'] = $search;
    }
    if ($selectedBatch !== '') {
        $queryParams['batch'] = $selectedBatch;
    }
    $paginationSuffix = empty($queryParams) ? '' : '&' . http_build_query($queryParams);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student List - Program Coordinator</title>
    <link rel="icon" type="image/png" href="../img/cav.png">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            background: #f2f5f1;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
            overflow-x: hidden;
            padding-top: 45px;
        }
        .header {
            background: linear-gradient(135deg, #206018 0%, #2e7d32 100%);
            color: #fff;
            padding: 5px 15px;
            text-align: left;
            font-size: 18px;
            font-weight: 800;
            width: 100%;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            box-shadow: 0 2px 10px rgba(32, 96, 24, 0.2);
            display: flex;
            align-items: center;
            justify-content: space-between;
            height: 45px;
        }
        
        .header img {
            height: 32px;
            width: auto;
            margin-right: 12px;
            vertical-align: middle;
            filter: drop-shadow(0 2px 4px rgba(0,0,0,0.2));
            cursor: pointer;
        }

        .admin-info {
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
        .sidebar.collapsed { transform: translateX(-250px); }
        .sidebar-header {
            padding: 15px 20px;
            text-align: center;
            font-size: 20px;
            font-weight: 700;
            border-bottom: 2px solid rgba(255, 255, 255, 0.2);
            margin-bottom: 5px;
        }
        .sidebar-menu { list-style: none; padding: 6px 0; margin: 0; }
        .sidebar-menu li { margin: 0; }
        .sidebar-menu a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 20px;
            color: #ffffff;
            text-decoration: none;
            line-height: 1.2;
            font-size: 15px;
            border-left: 4px solid transparent;
            transition: all 0.25s ease;
        }
        .sidebar-menu a:hover {
            background: rgba(255, 255, 255, 0.10);
            padding-left: 25px;
            border-left-color: #4CAF50;
        }
        .sidebar-menu a.active {
            background: rgba(255, 255, 255, 0.15);
            border-left-color: #4CAF50;
        }
        .sidebar-menu img { width: 20px; height: 20px; filter: brightness(0) invert(1); }
        .menu-group { margin: 8px 0; }
        .menu-group-title {
            padding: 6px 20px 2px 20px;
            color: rgba(255, 255, 255, 0.7);
            font-size: 15px;
            font-weight: 600;
            text-transform: uppercase;
            line-height: 1.2;
            letter-spacing: 1px;
        }
        .content {
            margin: 24px 24px 24px 274px;
            transition: margin-left 0.3s ease;
        }
        .content.expanded { margin-left: 24px; }
        .page-card {
            background: #fff;
            border: 1px solid #dbe5d9;
            border-radius: 10px;
            box-shadow: 0 3px 10px rgba(32, 96, 24, 0.08);
            padding: 16px;
            margin-bottom: 14px;
        }
        .page-card h2 {
            color: #206018;
            font-size: 22px;
            margin-bottom: 4px;
        }
        .subtitle { color: #647067; font-size: 13px; }
        .filter-row {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }
        .filter-row input,
        .filter-row select {
            padding: 8px 10px;
            border: 1px solid #cddccc;
            border-radius: 8px;
            font-size: 13px;
            min-width: 220px;
        }
        .filter-note { color: #627364; font-size: 12px; margin-top: 8px; }
        .stats-card {
            background: #f8fbf7;
            border: 1px solid #dce8d9;
            border-radius: 10px;
            padding: 12px;
            margin-bottom: 14px;
            color: #206018;
            font-weight: 700;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            background: #fff;
            border: 1px solid #dbe5db;
            border-radius: 10px;
            overflow: hidden;
        }
        th {
            background: #206018;
            color: #fff;
            text-align: left;
            padding: 10px;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }
        td {
            padding: 10px;
            font-size: 13px;
            border-bottom: 1px solid #edf3ed;
        }
        tr:nth-child(even) td { background: #f7faf7; }
        .program-badge {
            display: inline-block;
            padding: 4px 10px;
            background: #2e7d32;
            color: #fff;
            border-radius: 14px;
            font-size: 11px;
            font-weight: 600;
        }
        .no-data {
            padding: 20px;
            text-align: center;
            color: #647067;
        }
        .pagination {
            margin-top: 14px;
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            align-items: center;
        }
        .pagination a,
        .pagination span {
            padding: 6px 10px;
            border: 1px solid #d0ddd0;
            border-radius: 8px;
            text-decoration: none;
            font-size: 12px;
            color: #206018;
            background: #fff;
        }
        .pagination .active {
            background: #206018;
            color: #fff;
            border-color: #206018;
        }
        .btn-check {
            display: inline-block;
            padding: 6px 12px;
            background: #4CAF50;
            color: #fff;
            text-decoration: none;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            transition: background 0.2s ease;
        }
        .btn-check:hover {
            background: #45a049;
        }
        .action-links {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            flex-wrap: nowrap;
            width: 100%;
        }
        .btn-check,
        .btn-study,
        .btn-profile {
            display: inline-block;
            min-width: 88px;
            padding: 6px 0;
            color: #fff;
            text-decoration: none;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            text-align: center;
            transition: background 0.2s ease;
            white-space: nowrap;
            line-height: 1.2;
        }
        .btn-study {
            background: #206018;
        }
        .btn-study:hover {
            background: #1a4f16;
        }
        .btn-profile {
            background: #d97706;
        }
        .btn-profile:hover {
            background: #b45309;
        }
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-250px); }
            .sidebar:not(.collapsed) { transform: translateX(0); }
            .content { margin: 70px 10px 10px; }
            .content.expanded { margin-left: 10px; }
            .filter-row input,
            .filter-row select { min-width: 0; width: 100%; }
        }
    </style>
</head>
<body>
    <div class="header">
        <div>
            <button class="menu-toggle" onclick="toggleSidebar()" aria-label="Toggle sidebar">&#9776;</button>
            <img src="../img/cav.png" alt="CvSU Logo" onclick="toggleSidebar()">
            <span style="color: #d9e441;">ASPLAN</span>
        </div>
        <div class="admin-info"><?php echo $coordinatorName; ?> | Program Coordinator</div>
    </div>

    <div class="sidebar collapsed" id="sidebar">
        <div class="sidebar-header"><h3>Program Coordinator Panel</h3></div>
        <ul class="sidebar-menu">
            <div class="menu-group">
                <div class="menu-group-title">Dashboard</div>
                <li><a href="index.php"><img src="../pix/home1.png" alt="Dashboard" style="filter: brightness(0) invert(1);"> Dashboard</a></li>
            </div>

            <div class="menu-group">
                <div class="menu-group-title">Modules</div>
                <li><a href="curriculum_management.php"><img src="../pix/curr.png" alt="Curriculum" style="filter: brightness(0) invert(1);"> Curriculum Management</a></li>
                <li><a href="adviser_management.php"><img src="../pix/account.png" alt="Advisers" style="filter: brightness(0) invert(1);"> Adviser Management</a></li>
                <li><a href="list_of_students.php" class="active"><img src="../pix/checklist.png" alt="Students" style="filter: brightness(0) invert(1);"> List of Students</a></li>
                <li><a href="program_shift_requests.php"><img src="../pix/update.png" alt="Program Shift" style="filter: brightness(0) invert(1);"> Program Shift Requests</a></li>
                <li><a href="profile.php"><img src="../pix/account.png" alt="Profile" style="filter: brightness(0) invert(1);"> Update Profile</a></li>
            </div>

            <div class="menu-group">
                <div class="menu-group-title">Account</div>
                <li><a href="logout.php"><img src="../pix/singout.png" alt="Sign Out" style="filter: brightness(0) invert(1);"> Sign Out</a></li>
            </div>
        </ul>
    </div>

    <div class="content" id="mainContent">
        <div class="page-card">
            <h2>Student Directory</h2>
            <p class="subtitle">Program scope: <?php echo !empty($coordinatorPrograms) ? htmlspecialchars(implode(', ', $coordinatorPrograms)) : 'Not configured'; ?></p>
        </div>

        <div class="page-card">
            <form method="GET" action="" class="filter-row" id="filterForm">
                <input type="text" name="search" id="searchInput" placeholder="Search by student id or name" value="<?php echo htmlspecialchars($search); ?>">

                <select id="batchFilter" name="batch" <?php echo empty($coordinatorPrograms) ? 'disabled' : ''; ?>>
                    <option value="">-- Select Batch --</option>
                    <?php foreach ($availableBatches as $batch): ?>
                        <option value="<?php echo htmlspecialchars($batch); ?>" <?php echo $selectedBatch === $batch ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($batch); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
            <div class="filter-note">Use the Batch dropdown to filter students in your assigned program.</div>
        </div>

        <div class="stats-card">Total students found: <?php echo (int)$totalRecords; ?></div>

        <table>
            <thead>
                <tr>
                    <th>Student ID</th>
                    <th>Last Name</th>
                    <th>First Name</th>
                    <th>Middle Name</th>
                    <th>Program</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($coordinatorPrograms)): ?>
                    <tr><td colspan="6" class="no-data">Program is not configured for this coordinator account.</td></tr>
                <?php elseif (empty($displayRows)): ?>
                    <tr><td colspan="6" class="no-data">No students found for selected filters.</td></tr>
                <?php else: ?>
                    <?php foreach ($displayRows as $row): ?>
                        <tr>
                            <td><?php echo htmlspecialchars((string)$row['student_number']); ?></td>
                            <td><?php echo htmlspecialchars((string)$row['last_name']); ?></td>
                            <td><?php echo htmlspecialchars((string)$row['first_name']); ?></td>
                            <td><?php echo htmlspecialchars((string)($row['middle_name'] ?? '')); ?></td>
                            <td><span class="program-badge"><?php echo htmlspecialchars((string)($row['program'] ?? '')); ?></span></td>
                            <td style="text-align:center;">
                                <div class="action-links">
                                    <a href="account_management.php?student_id=<?php echo urlencode((string)$row['student_number']); ?>" class="btn-profile">Profile</a>
                                    <a href="checklist.php?student_id=<?php echo urlencode((string)$row['student_number']); ?>" class="btn-check">Checklist</a>
                                    <a href="study_plan_view.php?student_id=<?php echo urlencode((string)$row['student_number']); ?>" class="btn-study">Study Plan</a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <?php if ($i == $currentPage): ?>
                        <span class="active"><?php echo $i; ?></span>
                    <?php else: ?>
                        <a href="?page=<?php echo $i; ?><?php echo htmlspecialchars($paginationSuffix); ?>"><?php echo $i; ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    </div>

    <script>
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');
            sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('expanded');
        }

        document.addEventListener('click', function(event) {
            const sidebar = document.getElementById('sidebar');
            const logo = document.querySelector('.header img');

            if (window.innerWidth <= 768 && sidebar && !sidebar.contains(event.target) && (!logo || !logo.contains(event.target))) {
                sidebar.classList.add('collapsed');
                const mainContent = document.getElementById('mainContent');
                if (mainContent) {
                    mainContent.classList.add('expanded');
                }
            }
        });

        window.addEventListener('DOMContentLoaded', function() {
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');

            if (window.innerWidth <= 768) {
                sidebar.classList.add('collapsed');
                mainContent.classList.add('expanded');
            } else {
                sidebar.classList.remove('collapsed');
                mainContent.classList.remove('expanded');
            }

            const batchFilter = document.getElementById('batchFilter');
            if (batchFilter) {
                batchFilter.addEventListener('change', function() {
                    document.getElementById('filterForm').submit();
                });
            }

            const searchInput = document.getElementById('searchInput');
            if (searchInput) {
                let searchTimeout;
                searchInput.addEventListener('input', function() {
                    clearTimeout(searchTimeout);
                    searchTimeout = setTimeout(function() {
                        document.getElementById('filterForm').submit();
                    }, 500);
                });
            }
        });

        window.addEventListener('resize', function() {
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');

            if (window.innerWidth > 768) {
                sidebar.classList.remove('collapsed');
                mainContent.classList.remove('expanded');
            } else {
                sidebar.classList.add('collapsed');
                mainContent.classList.add('expanded');
            }
        });
    </script>
</body>
</html>
<?php
if (isset($conn) && $conn instanceof mysqli) {
    $conn->close();
}
?>
