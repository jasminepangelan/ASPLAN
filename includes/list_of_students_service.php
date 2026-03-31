<?php
// list_of_students_service.php
// Contains business logic for loading, filtering, and exporting student directory data for admin view.

require_once __DIR__ . '/../config/database.php';

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
    $whereParts = [];
    if ($search !== '') {
        $search_term = $conn->real_escape_string($search);
        $whereParts[] = "(student_number LIKE '%$search_term%' OR last_name LIKE '%$search_term%' OR first_name LIKE '%$search_term%' OR middle_name LIKE '%$search_term%')";
    }
    if ($selectedProgram !== '') {
        $programEscaped = $conn->real_escape_string($selectedProgram);
        $whereParts[] = "TRIM(program) = '$programEscaped'";
    }
    if ($selectedBatch !== '') {
        $batchEscaped = $conn->real_escape_string($selectedBatch);
        $whereParts[] = "LEFT(student_number, 4) = '$batchEscaped'";
    }
    $where_clause = '';
    if (!empty($whereParts)) {
        $where_clause = ' WHERE ' . implode(' AND ', $whereParts);
    }
    $count_sql = "SELECT COUNT(*) as total FROM student_info" . $where_clause;
    $count_result = $conn->query($count_sql);
    $total_records = 0;
    if ($count_result && method_exists($count_result, 'fetch_assoc')) {
        $countRow = $count_result->fetch_assoc();
        $total_records = (int)($countRow['total'] ?? 0);
    }
    $sql = "SELECT student_number, last_name, first_name, middle_name, program FROM student_info $where_clause ORDER BY last_name, first_name LIMIT $records_per_page OFFSET $offset";
    $result = $conn->query($sql);
    $students = [];
    if ($result && method_exists($result, 'fetch_assoc') && (int)($result->num_rows ?? 0) > 0) {
        while ($row = $result->fetch_assoc()) {
            $students[] = $row;
        }
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
    $whereParts = [];
    if ($search !== '') {
        $search_term = $conn->real_escape_string($search);
        $whereParts[] = "(student_number LIKE '%$search_term%' OR last_name LIKE '%$search_term%' OR first_name LIKE '%$search_term%' OR middle_name LIKE '%$search_term%')";
    }
    if ($selectedProgram !== '') {
        $programEscaped = $conn->real_escape_string($selectedProgram);
        $whereParts[] = "TRIM(program) = '$programEscaped'";
    }
    if ($selectedBatch !== '') {
        $batchEscaped = $conn->real_escape_string($selectedBatch);
        $whereParts[] = "LEFT(student_number, 4) = '$batchEscaped'";
    }
    $where_clause = '';
    if (!empty($whereParts)) {
        $where_clause = ' WHERE ' . implode(' AND ', $whereParts);
    }
    $export_sql = "SELECT student_number, last_name, first_name, middle_name, program FROM student_info $where_clause ORDER BY last_name, first_name";
    $export_result = $conn->query($export_sql);
    $students = [];
    if ($export_result && method_exists($export_result, 'fetch_assoc') && (int)($export_result->num_rows ?? 0) > 0) {
        while ($row = $export_result->fetch_assoc()) {
            $students[] = $row;
        }
    }
    closeDBConnection($conn);
    return $students;
}
