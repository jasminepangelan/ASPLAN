<?php
/**
 * Shared service for study plan override storage.
 * Centralizes table bootstrap and CRUD helpers so entrypoints stay thin.
 */

if (!function_exists('spoValidOverrideYears')) {
    function spoValidOverrideYears(): array
    {
        return ['1st Yr', '2nd Yr', '3rd Yr', '4th Yr'];
    }
}

if (!function_exists('spoValidOverrideSemesters')) {
    function spoValidOverrideSemesters(): array
    {
        return ['1st Sem', '2nd Sem', 'Mid Year'];
    }
}

if (!function_exists('spoTableColumnExists')) {
    function spoTableColumnExists($conn, string $tableName, string $columnName): bool
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
            return (int) $result->num_rows > 0;
        }

        return false;
    }
}

if (!function_exists('spoEnsureStudyPlanOverrideTable')) {
    function spoEnsureStudyPlanOverrideTable($conn): void
    {
        $conn->query(
            "CREATE TABLE IF NOT EXISTS student_study_plan_overrides (
                id INT AUTO_INCREMENT PRIMARY KEY,
                student_id VARCHAR(32) NOT NULL,
                course_code VARCHAR(64) NOT NULL,
                target_year VARCHAR(20) NOT NULL,
                target_semester VARCHAR(20) NOT NULL,
                updated_by VARCHAR(120) DEFAULT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_student_course (student_id, course_code),
                KEY idx_student (student_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        if (!spoTableColumnExists($conn, 'student_study_plan_overrides', 'updated_by')) {
            $conn->query("ALTER TABLE student_study_plan_overrides ADD COLUMN updated_by VARCHAR(120) DEFAULT NULL");
        }

        if (!spoTableColumnExists($conn, 'student_study_plan_overrides', 'updated_at')) {
            $conn->query("ALTER TABLE student_study_plan_overrides ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
        }
    }
}

if (!function_exists('spoLoadStudyPlanOverrides')) {
    function spoLoadStudyPlanOverrides($conn, string $studentId): array
    {
        spoEnsureStudyPlanOverrideTable($conn);

        $validYears = spoValidOverrideYears();
        $validSemesters = spoValidOverrideSemesters();
        $overrideMap = [];

        $stmt = $conn->prepare(
            "SELECT course_code, target_year, target_semester
             FROM student_study_plan_overrides
             WHERE student_id = ?"
        );

        if (!$stmt) {
            return $overrideMap;
        }

        if ($stmt instanceof PDOStatement) {
            $stmt->execute([$studentId]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $courseCode = trim((string) ($row['course_code'] ?? ''));
                $targetYear = trim((string) ($row['target_year'] ?? ''));
                $targetSemester = trim((string) ($row['target_semester'] ?? ''));

                if (
                    $courseCode !== '' &&
                    in_array($targetYear, $validYears, true) &&
                    in_array($targetSemester, $validSemesters, true)
                ) {
                    $overrideMap[$courseCode] = [
                        'year' => $targetYear,
                        'semester' => $targetSemester,
                    ];
                }
            }

            return $overrideMap;
        }

        if (method_exists($stmt, 'bind_param')) {
            $stmt->bind_param('s', $studentId);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($result && $row = $result->fetch_assoc()) {
                $courseCode = trim((string) ($row['course_code'] ?? ''));
                $targetYear = trim((string) ($row['target_year'] ?? ''));
                $targetSemester = trim((string) ($row['target_semester'] ?? ''));

                if (
                    $courseCode !== '' &&
                    in_array($targetYear, $validYears, true) &&
                    in_array($targetSemester, $validSemesters, true)
                ) {
                    $overrideMap[$courseCode] = [
                        'year' => $targetYear,
                        'semester' => $targetSemester,
                    ];
                }
            }
            $stmt->close();
        }

        return $overrideMap;
    }
}

if (!function_exists('spoSaveStudyPlanOverride')) {
    function spoSaveStudyPlanOverride($conn, string $studentId, string $courseCode, string $targetYear, string $targetSemester, ?string $updatedBy = null): array
    {
        if (
            $studentId === '' ||
            $courseCode === '' ||
            !in_array($targetYear, spoValidOverrideYears(), true) ||
            !in_array($targetSemester, spoValidOverrideSemesters(), true)
        ) {
            return ['success' => false, 'message' => 'Invalid study plan override payload'];
        }

        spoEnsureStudyPlanOverrideTable($conn);

        $stmt = $conn->prepare(
            "INSERT INTO student_study_plan_overrides
                (student_id, course_code, target_year, target_semester, updated_by)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                target_year = VALUES(target_year),
                target_semester = VALUES(target_semester),
                updated_by = VALUES(updated_by),
                updated_at = CURRENT_TIMESTAMP"
        );

        if (!$stmt) {
            return ['success' => false, 'message' => 'Failed to prepare override save statement'];
        }

        if ($stmt instanceof PDOStatement) {
            $ok = $stmt->execute([$studentId, $courseCode, $targetYear, $targetSemester, $updatedBy]);
            return ['success' => $ok, 'message' => $ok ? null : 'Failed to save override'];
        }

        $updatedBy = (string) ($updatedBy ?? '');
        $stmt->bind_param('sssss', $studentId, $courseCode, $targetYear, $targetSemester, $updatedBy);
        $ok = $stmt->execute();
        $error = $stmt->error;
        $stmt->close();

        return ['success' => $ok, 'message' => $ok ? null : $error];
    }
}
