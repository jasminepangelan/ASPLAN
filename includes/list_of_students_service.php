<?php
// list_of_students_service.php
// Contains business logic for loading, filtering, and exporting student directory data for admin view.

require_once __DIR__ . '/../config/database.php';

function losPrepareFilters(mysqli $conn, string $search, string $selectedProgram, string $selectedBatch): array
{
    $whereParts = [];
    $params = [];
    $types = '';

    if ($search !== '') {
        $whereParts[] = "(student_number LIKE ? OR last_name LIKE ? OR first_name LIKE ? OR middle_name LIKE ?)";
        $searchParam = '%' . $search . '%';
        array_push($params, $searchParam, $searchParam, $searchParam, $searchParam);
        $types .= 'ssss';
    }

    if ($selectedProgram !== '') {
        $whereParts[] = "TRIM(program) = ?";
        $params[] = $selectedProgram;
        $types .= 's';
    }

    if ($selectedBatch !== '') {
        $whereParts[] = "LEFT(student_number, 4) = ?";
        $params[] = $selectedBatch;
        $types .= 's';
    }

    return [
        'where_clause' => !empty($whereParts) ? (' WHERE ' . implode(' AND ', $whereParts)) : '',
        'params' => $params,
        'types' => $types,
    ];
}

/**
 * Loads available programs from student_info table.
 * Returns an array of program names.
 */
function losGetAvailablePrograms()
{
    $conn = getDBConnection();
    $programs = [];
    $programResult = $conn->query("SELECT DISTINCT TRIM(program) AS program FROM student_info WHERE program IS NOT NULL AND TRIM(program) != '' ORDER BY program ASC");
    if ($programResult && method_exists($programResult, 'fetch_assoc')) {
        while ($programRow = $programResult->fetch_assoc()) {
            $programs[] = $programRow['program'];
        }
    }
    closeDBConnection($conn);
    return $programs;
}

/**
 * Loads available batches for a given program.
 * Returns an array of batch years (strings).
 */
function losGetAvailableBatches($selectedProgram)
{
    $conn = getDBConnection();
    $batches = [];
    $batchStmt = $conn->prepare("SELECT DISTINCT LEFT(student_number, 4) AS batch FROM student_info WHERE TRIM(program) = ? AND student_number IS NOT NULL AND student_number != '' ORDER BY batch DESC");
    if (!$batchStmt) {
        closeDBConnection($conn);
        return $batches;
    }
    $batchStmt->bind_param('s', $selectedProgram);
    if ($batchStmt->execute()) {
        $batchResult = $batchStmt->get_result();
        if ($batchResult && method_exists($batchResult, 'fetch_assoc')) {
            while ($batchRow = $batchResult->fetch_assoc()) {
                $batches[] = $batchRow['batch'];
            }
        }
    }
    $batchStmt->close();
    closeDBConnection($conn);
    return $batches;
}

/**
 * Loads filtered students for the directory, paginated.
 * Returns [students, total_records].
 */
function losLoadStudents($search, $selectedProgram, $selectedBatch, $records_per_page, $offset)
{
    $conn = getDBConnection();
    $records_per_page = max(1, (int) $records_per_page);
    $offset = max(0, (int) $offset);
    $filters = losPrepareFilters($conn, trim($search), trim($selectedProgram), trim($selectedBatch));
    $where_clause = $filters['where_clause'];
    $params = $filters['params'];
    $types = $filters['types'];

    $count_sql = "SELECT COUNT(*) as total FROM student_info" . $where_clause;
    $total_records = 0;
    $count_stmt = $conn->prepare($count_sql);
    if ($count_stmt) {
        if (!empty($params)) {
            $count_stmt->bind_param($types, ...$params);
        }
        $count_stmt->execute();
        $count_result = $count_stmt->get_result();
        if ($count_result && method_exists($count_result, 'fetch_assoc')) {
            $countRow = $count_result->fetch_assoc();
            $total_records = (int)($countRow['total'] ?? 0);
        }
        $count_stmt->close();
    }

    $sql = "SELECT student_number, last_name, first_name, middle_name, program FROM student_info $where_clause ORDER BY last_name, first_name LIMIT ? OFFSET ?";
    $students = [];
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $pageParams = $params;
        $pageParams[] = $records_per_page;
        $pageParams[] = $offset;
        $pageTypes = $types . 'ii';
        $stmt->bind_param($pageTypes, ...$pageParams);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result && method_exists($result, 'fetch_assoc') && (int)($result->num_rows ?? 0) > 0) {
            while ($row = $result->fetch_assoc()) {
                $students[] = $row;
            }
        }
        $stmt->close();
    }
    closeDBConnection($conn);
    return [$students, $total_records];
}

/**
 * Loads all filtered students for CSV export (no pagination).
 * Returns an array of students.
 */
function losExportStudents($search, $selectedProgram, $selectedBatch)
{
    $conn = getDBConnection();
    $filters = losPrepareFilters($conn, trim($search), trim($selectedProgram), trim($selectedBatch));
    $where_clause = $filters['where_clause'];
    $params = $filters['params'];
    $types = $filters['types'];
    $export_sql = "SELECT student_number, last_name, first_name, middle_name, program FROM student_info $where_clause ORDER BY last_name, first_name";
    $students = [];
    $stmt = $conn->prepare($export_sql);
    if ($stmt) {
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $export_result = $stmt->get_result();
        if ($export_result && method_exists($export_result, 'fetch_assoc') && (int)($export_result->num_rows ?? 0) > 0) {
            while ($row = $export_result->fetch_assoc()) {
                $students[] = $row;
            }
        }
        $stmt->close();
    }
    closeDBConnection($conn);
    return $students;
}
