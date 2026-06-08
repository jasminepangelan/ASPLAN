<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/laravel_bridge.php';
require_once __DIR__ . '/../includes/list_of_students_service.php';

if (!isset($_SESSION['username']) || (isset($_SESSION['user_type']) && $_SESSION['user_type'] !== 'program_coordinator')) {
    header('Location: ../index.html');
    exit();
}

$search = trim((string)($_GET['search'] ?? ''));
$batch = trim((string)($_GET['batch'] ?? ''));
$export = trim((string)($_GET['export'] ?? ''));
$username = (string)$_SESSION['username'];

// enforce coordinator scope
// determine coordinator programs (try program_coordinator, program_coordinators, then adviser)
$coordinatorPrograms = [];
$db = getDBConnection();
$tables = ['program_coordinator', 'program_coordinators'];
foreach ($tables as $table) {
    $tableSafe = $db->real_escape_string($table);
    $tableResult = $db->query("SHOW TABLES LIKE '$tableSafe'");
    if (!$tableResult || $tableResult->num_rows === 0) {
        continue;
    }
    $colRes = $db->query("SHOW COLUMNS FROM `$tableSafe` LIKE 'program'");
    if (!$colRes || $colRes->num_rows === 0) {
        continue;
    }
    $stmt = $db->prepare("SELECT TRIM(program) AS program FROM `$tableSafe` WHERE username = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $res->num_rows > 0) {
            $row = $res->fetch_assoc();
            $stmt->close();
            $programRaw = trim((string)($row['program'] ?? ''));
            if ($programRaw !== '') {
                $parts = preg_split('/\s*,\s*/', $programRaw);
                foreach ($parts as $p) {
                    $pTrim = trim($p);
                    if ($pTrim !== '') $coordinatorPrograms[] = $pTrim;
                }
            }
            break;
        }
        $stmt->close();
    }
}

if (empty($coordinatorPrograms)) {
    $fallback = $db->prepare("SELECT TRIM(program) AS program FROM adviser WHERE username = ? LIMIT 1");
    if ($fallback) {
        $fallback->bind_param('s', $username);
        $fallback->execute();
        $fres = $fallback->get_result();
        if ($fres && $fres->num_rows > 0) {
            $row = $fres->fetch_assoc();
            $programRaw = trim((string)($row['program'] ?? ''));
            if ($programRaw !== '') {
                $parts = preg_split('/\s*,\s*/', $programRaw);
                foreach ($parts as $p) { $pTrim = trim($p); if ($pTrim !== '') $coordinatorPrograms[] = $pTrim; }
            }
        }
        $fallback->close();
    }
}

closeDBConnection($db);

if (empty($coordinatorPrograms)) {
    http_response_code(403);
    echo "Program is not configured for this coordinator account.";
    exit();
}

// Load all matching rows (not paginated) and then filter by coordinator programs
$allRows = losExportStudents($search, '', $batch);
$rows = [];
foreach ($allRows as $r) {
    $prog = trim((string)($r['program'] ?? ''));
    foreach ($coordinatorPrograms as $cp) {
        if ($prog === $cp) { $rows[] = $r; break; }
    }
}

if ($export === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="pc_student_report.csv"');
    // UTF-8 BOM
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Student ID','Last Name','First Name','Middle Name','Program','Batch']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['student_number'] ?? '',
            $r['last_name'] ?? '',
            $r['first_name'] ?? '',
            $r['middle_name'] ?? '',
            $r['program'] ?? '',
            isset($r['student_number']) ? substr($r['student_number'], 0, 4) : ''
        ]);
    }
    fclose($out);
    exit();
}

// Printable HTML
?><!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Student Report</title>
    <style>
        body { font-family: Arial, Helvetica, sans-serif; color:#1b3b1b; }
        .header { display:flex;justify-content:space-between;align-items:center;margin-bottom:18px; }
        h1 { margin:0; font-size:18px; }
        table { width:100%; border-collapse:collapse; border:1px solid #ddd; }
        th, td { padding:8px; border:1px solid #eee; font-size:12px; }
        th { background:#f1f9f1; text-align:left; }
        .controls { margin-bottom:12px; }
        @media print { .no-print { display:none; } }
    </style>
</head>
<body>
    <div class="header">
        <div>
            <h1>Student Report</h1>
            <div>Program scope: <?php echo htmlspecialchars(implode(', ', $coordinatorPrograms)); ?></div>
            <div>Filters: <?php echo ($search !== '' ? 'search=' . htmlspecialchars($search) : '') . ($batch !== '' ? ' batch=' . htmlspecialchars($batch) : ''); ?></div>
        </div>
        <div class="no-print">
            <button onclick="window.print();">Print / Save as PDF</button>
            <a href="?<?php echo http_build_query(array_merge($_GET, ['export'=>'csv'])); ?>"><button>Download CSV</button></a>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Student ID</th>
                <th>Last Name</th>
                <th>First Name</th>
                <th>Middle Name</th>
                <th>Program</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rows)): ?>
                <tr><td colspan="5">No students found for selected filters.</td></tr>
            <?php else: ?>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?php echo htmlspecialchars((string)($r['student_number'] ?? '')); ?></td>
                        <td><?php echo htmlspecialchars((string)($r['last_name'] ?? '')); ?></td>
                        <td><?php echo htmlspecialchars((string)($r['first_name'] ?? '')); ?></td>
                        <td><?php echo htmlspecialchars((string)($r['middle_name'] ?? '')); ?></td>
                        <td><?php echo htmlspecialchars((string)($r['program'] ?? '')); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</body>
</html>
