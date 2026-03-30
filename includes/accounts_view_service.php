<?php
// accounts_view_service.php
// Contains business logic for loading and filtering different account types for admin viewing.

require_once __DIR__ . '/../config/database.php';

/**
 * Resolves which admin table exists in the database.
 * Returns 'admins', 'admin', or null.
 */
function avsResolveAdminTable()
{
    $conn = getDBConnection();
    $checkAdmins = $conn->query("SHOW TABLES LIKE 'admins'");
    if ($checkAdmins && $checkAdmins->num_rows > 0) {
        closeDBConnection($conn);
        return 'admins';
    }
    $checkAdmin = $conn->query("SHOW TABLES LIKE 'admin'");
    if ($checkAdmin && $checkAdmin->num_rows > 0) {
        closeDBConnection($conn);
        return 'admin';
    }
    closeDBConnection($conn);
    return null;
}

/**
 * Resolves which program coordinator table exists in the database.
 * Returns 'program_coordinator', 'program_coordinators', or null.
 */
function avsResolveProgramCoordinatorTable()
{
    $conn = getDBConnection();
    $checkSingular = $conn->query("SHOW TABLES LIKE 'program_coordinator'");
    if ($checkSingular && $checkSingular->num_rows > 0) {
        closeDBConnection($conn);
        return 'program_coordinator';
    }
    $checkPlural = $conn->query("SHOW TABLES LIKE 'program_coordinators'");
    if ($checkPlural && $checkPlural->num_rows > 0) {
        closeDBConnection($conn);
        return 'program_coordinators';
    }
    closeDBConnection($conn);
    return null;
}

/**
 * Checks if a table has a specific column.
 */
function avsTableHasColumn($table, $column)
{
    $conn = getDBConnection();
    $tableSafe = $conn->real_escape_string($table);
    $columnSafe = $conn->real_escape_string($column);
    $result = $conn->query("SHOW COLUMNS FROM `$tableSafe` LIKE '$columnSafe'");
    $has = $result && $result->num_rows > 0;
    closeDBConnection($conn);
    return $has;
}

/**
 * Loads student accounts with filtering and pagination.
 * Returns [columns, rows, total_records, total_pages].
 * SECURITY: Uses prepared statements to prevent SQL injection
 */
function avsLoadStudentAccounts($search, $offset, $records_per_page)
{
    $conn = getDBConnection();
    $search = trim($search);
    
    // Build WHERE clause dynamically with prepared statement
    $whereClause = '';
    $searchParam = $search !== '' ? '%' . $search . '%' : '';
    
    if ($search !== '') {
        $whereClause = " WHERE (student_number LIKE ? OR last_name LIKE ? OR first_name LIKE ? OR middle_name LIKE ? OR email LIKE ?)";
    }
    
    // Get total count
    $countSql = "SELECT COUNT(*) AS total FROM student_info" . $whereClause;
    $countStmt = $conn->prepare($countSql);
    if ($search !== '') {
        $countStmt->bind_param("sssss", $searchParam, $searchParam, $searchParam, $searchParam, $searchParam);
    }
    $countStmt->execute();
    $countResult = $countStmt->get_result();
    $total_records = $countResult ? (int)$countResult->fetch_assoc()['total'] : 0;
    $total_pages = $total_records > 0 ? (int)ceil($total_records / $records_per_page) : 1;
    $countStmt->close();

    // Get paginated results
    $sql = "SELECT student_number, last_name, first_name, middle_name, email, program, status
            FROM student_info" . $whereClause . " ORDER BY last_name ASC, first_name ASC LIMIT ? OFFSET ?";
    $stmt = $conn->prepare($sql);
    if ($search !== '') {
        $stmt->bind_param("sssssii", $searchParam, $searchParam, $searchParam, $searchParam, $searchParam, $records_per_page, $offset);
    } else {
        $stmt->bind_param("ii", $records_per_page, $offset);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    if ($result) {
        while ($r = $result->fetch_assoc()) {
            $rows[] = [
                $r['student_number'] ?? '',
                $r['last_name'] ?? '',
                $r['first_name'] ?? '',
                $r['middle_name'] ?? '',
                $r['email'] ?? '',
                $r['program'] ?? '',
                $r['status'] ?? ''
            ];
        }
    }
    $stmt->close();
    closeDBConnection($conn);
    $columns = ['Student Number', 'Last Name', 'First Name', 'Middle Name', 'Email', 'Program', 'Status'];
    return [$columns, $rows, $total_records, $total_pages];
}

/**
 * Loads adviser accounts with filtering and pagination.
 * Returns [columns, rows, total_records, total_pages].
 * SECURITY: Uses prepared statements to prevent SQL injection
 */
function avsLoadAdviserAccounts($search, $offset, $records_per_page)
{
    $conn = getDBConnection();
    $search = trim($search);
    
    // Build WHERE clause dynamically with prepared statement
    $whereClause = '';
    $searchParam = $search !== '' ? '%' . $search . '%' : '';
    
    if ($search !== '') {
        $whereClause = " WHERE (id LIKE ? OR last_name LIKE ? OR first_name LIKE ? OR middle_name LIKE ? OR username LIKE ? OR program LIKE ?)";
    }
    
    // Get total count
    $countSql = "SELECT COUNT(*) AS total FROM adviser" . $whereClause;
    $countStmt = $conn->prepare($countSql);
    if ($search !== '') {
        $countStmt->bind_param("ssssss", $searchParam, $searchParam, $searchParam, $searchParam, $searchParam, $searchParam);
    }
    $countStmt->execute();
    $countResult = $countStmt->get_result();
    $total_records = $countResult ? (int)$countResult->fetch_assoc()['total'] : 0;
    $total_pages = $total_records > 0 ? (int)ceil($total_records / $records_per_page) : 1;
    $countStmt->close();

    // Get paginated results
    $sql = "SELECT id, last_name, first_name, middle_name, username, program, sex
            FROM adviser" . $whereClause . " ORDER BY last_name ASC, first_name ASC LIMIT ? OFFSET ?";
    $stmt = $conn->prepare($sql);
    if ($search !== '') {
        $stmt->bind_param("ssssssii", $searchParam, $searchParam, $searchParam, $searchParam, $searchParam, $searchParam, $records_per_page, $offset);
    } else {
        $stmt->bind_param("ii", $records_per_page, $offset);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    if ($result) {
        while ($r = $result->fetch_assoc()) {
            $rows[] = [
                $r['id'] ?? '',
                $r['last_name'] ?? '',
                $r['first_name'] ?? '',
                $r['middle_name'] ?? '',
                $r['username'] ?? '',
                $r['program'] ?? '',
                $r['sex'] ?? ''
            ];
        }
    }
    $stmt->close();
    closeDBConnection($conn);
    $columns = ['Adviser ID', 'Last Name', 'First Name', 'Middle Name', 'Username', 'Program', 'Sex'];
    return [$columns, $rows, $total_records, $total_pages];
}

/**
 * Loads program coordinator accounts with filtering and pagination.
 * Returns [columns, rows, total_records, total_pages].
 */
function avsLoadProgramCoordinatorAccounts($search, $offset, $records_per_page)
{
    $conn = getDBConnection();
    $searchSafe = $conn->real_escape_string($search);
    $programCoordinatorTable = avsResolveProgramCoordinatorTable();

    if ($programCoordinatorTable === null) {
        $columns = ['Message'];
        $rows = [['No program coordinator table found (expected program_coordinator or program_coordinators).']];
        closeDBConnection($conn);
        return [$columns, $rows, 1, 1];
    }

    $hasProgramColumn = avsTableHasColumn($programCoordinatorTable, 'program');
    $whereClause = '';
    if ($hasProgramColumn) {
        if ($search !== '') {
            $whereClause = " WHERE (id LIKE '%$searchSafe%' OR last_name LIKE '%$searchSafe%' OR first_name LIKE '%$searchSafe%' OR middle_name LIKE '%$searchSafe%' OR username LIKE '%$searchSafe%' OR program LIKE '%$searchSafe%')";
        }
        $countSql = "SELECT COUNT(*) AS total FROM $programCoordinatorTable" . $whereClause;
        $countResult = $conn->query($countSql);
        $total_records = $countResult ? (int)$countResult->fetch_assoc()['total'] : 0;
        $total_pages = $total_records > 0 ? (int)ceil($total_records / $records_per_page) : 1;

        $sql = "SELECT id, last_name, first_name, middle_name, username, program, sex
                FROM $programCoordinatorTable" . $whereClause . " ORDER BY last_name ASC, first_name ASC LIMIT $records_per_page OFFSET $offset";
        $result = $conn->query($sql);
        $rows = [];
        if ($result) {
            while ($r = $result->fetch_assoc()) {
                $rows[] = [
                    $r['id'] ?? '',
                    $r['last_name'] ?? '',
                    $r['first_name'] ?? '',
                    $r['middle_name'] ?? '',
                    $r['username'] ?? '',
                    $r['program'] ?? '',
                    $r['sex'] ?? ''
                ];
            }
        }
        $columns = ['Coordinator ID', 'Last Name', 'First Name', 'Middle Name', 'Username', 'Program', 'Sex'];
    } else {
        if ($search !== '') {
            $whereClause = " WHERE (id LIKE '%$searchSafe%' OR last_name LIKE '%$searchSafe%' OR first_name LIKE '%$searchSafe%' OR middle_name LIKE '%$searchSafe%' OR username LIKE '%$searchSafe%')";
        }
        $countSql = "SELECT COUNT(*) AS total FROM $programCoordinatorTable" . $whereClause;
        $countResult = $conn->query($countSql);
        $total_records = $countResult ? (int)$countResult->fetch_assoc()['total'] : 0;
        $total_pages = $total_records > 0 ? (int)ceil($total_records / $records_per_page) : 1;

        $sql = "SELECT id, last_name, first_name, middle_name, username, sex
                FROM $programCoordinatorTable" . $whereClause . " ORDER BY last_name ASC, first_name ASC LIMIT $records_per_page OFFSET $offset";
        $result = $conn->query($sql);
        $rows = [];
        if ($result) {
            while ($r = $result->fetch_assoc()) {
                $rows[] = [
                    $r['id'] ?? '',
                    $r['last_name'] ?? '',
                    $r['first_name'] ?? '',
                    $r['middle_name'] ?? '',
                    $r['username'] ?? '',
                    $r['sex'] ?? ''
                ];
            }
        }
        $columns = ['Coordinator ID', 'Last Name', 'First Name', 'Middle Name', 'Username', 'Sex'];
    }
    closeDBConnection($conn);
    return [$columns, $rows, $total_records, $total_pages];
}

/**
 * Loads admin accounts with filtering and pagination.
 * Returns [columns, rows, total_records, total_pages].
 */
function avsLoadAdminAccounts($search, $offset, $records_per_page)
{
    $conn = getDBConnection();
    $searchSafe = $conn->real_escape_string($search);
    $adminTable = avsResolveAdminTable();

    if ($adminTable === 'admins') {
        $whereClause = '';
        if ($search !== '') {
            $whereClause = " WHERE (username LIKE '%$searchSafe%' OR full_name LIKE '%$searchSafe%')";
        }
        $countSql = "SELECT COUNT(*) AS total FROM admins" . $whereClause;
        $countResult = $conn->query($countSql);
        $total_records = $countResult ? (int)$countResult->fetch_assoc()['total'] : 0;
        $total_pages = $total_records > 0 ? (int)ceil($total_records / $records_per_page) : 1;

        $sql = "SELECT username, full_name FROM admins" . $whereClause . " ORDER BY username ASC LIMIT $records_per_page OFFSET $offset";
        $result = $conn->query($sql);
        $rows = [];
        if ($result) {
            while ($r = $result->fetch_assoc()) {
                $rows[] = [
                    $r['username'] ?? '',
                    $r['full_name'] ?? ''
                ];
            }
        }
        $columns = ['Username', 'Full Name'];
    } elseif ($adminTable === 'admin') {
        $whereClause = '';
        if ($search !== '') {
            $whereClause = " WHERE (admin_id LIKE '%$searchSafe%' OR last_name LIKE '%$searchSafe%' OR first_name LIKE '%$searchSafe%' OR middle_name LIKE '%$searchSafe%' OR username LIKE '%$searchSafe%')";
        }
        $countSql = "SELECT COUNT(*) AS total FROM admin" . $whereClause;
        $countResult = $conn->query($countSql);
        $total_records = $countResult ? (int)$countResult->fetch_assoc()['total'] : 0;
        $total_pages = $total_records > 0 ? (int)ceil($total_records / $records_per_page) : 1;

        $sql = "SELECT admin_id, last_name, first_name, middle_name, username FROM admin" . $whereClause . " ORDER BY last_name ASC, first_name ASC LIMIT $records_per_page OFFSET $offset";
        $result = $conn->query($sql);
        $rows = [];
        if ($result) {
            while ($r = $result->fetch_assoc()) {
                $rows[] = [
                    $r['admin_id'] ?? '',
                    $r['last_name'] ?? '',
                    $r['first_name'] ?? '',
                    $r['middle_name'] ?? '',
                    $r['username'] ?? ''
                ];
            }
        }
        $columns = ['Admin ID', 'Last Name', 'First Name', 'Middle Name', 'Username'];
    } else {
        $columns = ['Message'];
        $rows = [['No admin table found.']];
        $total_records = 1;
        $total_pages = 1;
    }
    closeDBConnection($conn);
    return [$columns, $rows, $total_records, $total_pages];
}
