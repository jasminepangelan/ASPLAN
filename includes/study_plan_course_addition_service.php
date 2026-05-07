<?php
/**
 * Shared service for persisting "Added" confirmations on study-plan courses.
 * Keeps the optimizer separate from enrollment confirmation state.
 */

if (!function_exists('spcaValidYears')) {
    function spcaValidYears(): array
    {
        return ['1st Yr', '2nd Yr', '3rd Yr', '4th Yr'];
    }
}

if (!function_exists('spcaValidSemesters')) {
    function spcaValidSemesters(): array
    {
        return ['1st Sem', '2nd Sem', 'Mid Year'];
    }
}

if (!function_exists('spcaBuildCourseAdditionKey')) {
    function spcaBuildCourseAdditionKey(string $courseCode, string $targetYear, string $targetSemester): string
    {
        return strtoupper(trim($courseCode)) . '|' . trim($targetYear) . '|' . trim($targetSemester);
    }
}

if (!function_exists('spcaTableColumnExists')) {
    function spcaTableColumnExists($conn, string $tableName, string $columnName): bool
    {
        $tableName = trim($tableName);
        $columnName = trim($columnName);
        if ($tableName === '' || $columnName === '') {
            return false;
        }

        $tableSafe = str_replace('`', '``', $tableName);
        $columnSafe = str_replace(['`', "'"], ['', "''"], $columnName);
        $result = $conn->query("SHOW COLUMNS FROM `{$tableSafe}` LIKE '{$columnSafe}'");

        if ($result instanceof PDOStatement) {
            $row = $result->fetch(PDO::FETCH_ASSOC);
            return is_array($row);
        }

        if ($result instanceof mysqli_result) {
            $exists = $result->num_rows > 0;
            $result->close();
            return $exists;
        }

        if (is_object($result) && property_exists($result, 'num_rows')) {
            return (int)$result->num_rows > 0;
        }

        return false;
    }
}

if (!function_exists('spcaEnsureCourseAdditionTable')) {
    function spcaEnsureCourseAdditionTable($conn): void
    {
        $conn->query(
            "CREATE TABLE IF NOT EXISTS student_study_plan_course_additions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                student_id VARCHAR(32) NOT NULL,
                course_code VARCHAR(64) NOT NULL,
                target_year VARCHAR(20) NOT NULL,
                target_semester VARCHAR(20) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'added',
                updated_by VARCHAR(120) DEFAULT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_student_course_term (student_id, course_code, target_year, target_semester),
                KEY idx_student (student_id),
                KEY idx_student_course (student_id, course_code)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        if (!spcaTableColumnExists($conn, 'student_study_plan_course_additions', 'status')) {
            $conn->query("ALTER TABLE student_study_plan_course_additions ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'added'");
        }

        if (!spcaTableColumnExists($conn, 'student_study_plan_course_additions', 'updated_by')) {
            $conn->query("ALTER TABLE student_study_plan_course_additions ADD COLUMN updated_by VARCHAR(120) DEFAULT NULL");
        }

        if (!spcaTableColumnExists($conn, 'student_study_plan_course_additions', 'updated_at')) {
            $conn->query("ALTER TABLE student_study_plan_course_additions ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
        }
    }
}

if (!function_exists('spcaLoadCourseAdditionMap')) {
    function spcaLoadCourseAdditionMap($conn, string $studentId): array
    {
        spcaEnsureCourseAdditionTable($conn);

        $map = [];
        $stmt = $conn->prepare(
            "SELECT course_code, target_year, target_semester, status
             FROM student_study_plan_course_additions
             WHERE student_id = ?
               AND status = 'added'"
        );

        if (!$stmt) {
            return $map;
        }

        if ($stmt instanceof PDOStatement) {
            $stmt->execute([$studentId]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $courseCode = trim((string)($row['course_code'] ?? ''));
                $targetYear = trim((string)($row['target_year'] ?? ''));
                $targetSemester = trim((string)($row['target_semester'] ?? ''));
                if ($courseCode === '' || $targetYear === '' || $targetSemester === '') {
                    continue;
                }
                $map[spcaBuildCourseAdditionKey($courseCode, $targetYear, $targetSemester)] = true;
            }

            return $map;
        }

        if (method_exists($stmt, 'bind_param')) {
            $stmt->bind_param('s', $studentId);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($result && ($row = $result->fetch_assoc())) {
                $courseCode = trim((string)($row['course_code'] ?? ''));
                $targetYear = trim((string)($row['target_year'] ?? ''));
                $targetSemester = trim((string)($row['target_semester'] ?? ''));
                if ($courseCode === '' || $targetYear === '' || $targetSemester === '') {
                    continue;
                }
                $map[spcaBuildCourseAdditionKey($courseCode, $targetYear, $targetSemester)] = true;
            }
            $stmt->close();
        }

        return $map;
    }
}

if (!function_exists('spcaSaveCourseAdditionState')) {
    function spcaSaveCourseAdditionState($conn, string $studentId, string $courseCode, string $targetYear, string $targetSemester, bool $isAdded, ?string $updatedBy = null): array
    {
        $studentId = trim($studentId);
        $courseCode = strtoupper(trim($courseCode));
        $targetYear = trim($targetYear);
        $targetSemester = trim($targetSemester);

        if (
            $studentId === '' ||
            $courseCode === '' ||
            !in_array($targetYear, spcaValidYears(), true) ||
            !in_array($targetSemester, spcaValidSemesters(), true)
        ) {
            return ['success' => false, 'message' => 'Invalid study plan addition payload'];
        }

        spcaEnsureCourseAdditionTable($conn);

        if (!$isAdded) {
            $stmt = $conn->prepare(
                "DELETE FROM student_study_plan_course_additions
                 WHERE student_id = ?
                   AND course_code = ?
                   AND target_year = ?
                   AND target_semester = ?"
            );

            if (!$stmt) {
                return ['success' => false, 'message' => 'Failed to prepare removal statement'];
            }

            if ($stmt instanceof PDOStatement) {
                $ok = $stmt->execute([$studentId, $courseCode, $targetYear, $targetSemester]);
                return ['success' => $ok, 'message' => $ok ? null : 'Failed to remove added state'];
            }

            $stmt->bind_param('ssss', $studentId, $courseCode, $targetYear, $targetSemester);
            $ok = $stmt->execute();
            $error = $stmt->error;
            $stmt->close();

            return ['success' => $ok, 'message' => $ok ? null : $error];
        }

        // Keep only one "added" confirmation per course for the student.
        $cleanupStmt = $conn->prepare(
            "DELETE FROM student_study_plan_course_additions
             WHERE student_id = ?
               AND course_code = ?
               AND NOT (target_year = ? AND target_semester = ?)"
        );
        if ($cleanupStmt instanceof PDOStatement) {
            $cleanupStmt->execute([$studentId, $courseCode, $targetYear, $targetSemester]);
        } elseif ($cleanupStmt && method_exists($cleanupStmt, 'bind_param')) {
            $cleanupStmt->bind_param('ssss', $studentId, $courseCode, $targetYear, $targetSemester);
            $cleanupStmt->execute();
            $cleanupStmt->close();
        }

        $stmt = $conn->prepare(
            "INSERT INTO student_study_plan_course_additions
                (student_id, course_code, target_year, target_semester, status, updated_by)
             VALUES (?, ?, ?, ?, 'added', ?)
             ON DUPLICATE KEY UPDATE
                status = 'added',
                updated_by = VALUES(updated_by),
                updated_at = CURRENT_TIMESTAMP"
        );

        if (!$stmt) {
            return ['success' => false, 'message' => 'Failed to prepare added save statement'];
        }

        $updatedBy = (string)($updatedBy ?? '');

        if ($stmt instanceof PDOStatement) {
            $ok = $stmt->execute([$studentId, $courseCode, $targetYear, $targetSemester, $updatedBy]);
            return ['success' => $ok, 'message' => $ok ? null : 'Failed to save added state'];
        }

        $stmt->bind_param('sssss', $studentId, $courseCode, $targetYear, $targetSemester, $updatedBy);
        $ok = $stmt->execute();
        $error = $stmt->error;
        $stmt->close();

        return ['success' => $ok, 'message' => $ok ? null : $error];
    }
}
