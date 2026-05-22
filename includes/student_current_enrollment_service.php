<?php

if (!function_exists('sceEnsureCurrentEnrollmentTables')) {
    function sceEnsureCurrentEnrollmentTables(mysqli $conn): void
    {
        $conn->query(
            "CREATE TABLE IF NOT EXISTS student_current_enrollments (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                student_id VARCHAR(30) NOT NULL,
                year_level VARCHAR(20) NOT NULL,
                semester VARCHAR(20) NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_student_current_enrollment (student_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $conn->query(
            "CREATE TABLE IF NOT EXISTS student_current_enrollment_courses (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                enrollment_id BIGINT UNSIGNED NOT NULL,
                course_code VARCHAR(50) NOT NULL,
                course_title VARCHAR(255) NOT NULL,
                units DECIMAL(6,2) NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_current_enrollment_course (enrollment_id, course_code),
                KEY idx_current_enrollment_id (enrollment_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }
}

if (!function_exists('sceValidYears')) {
    function sceValidYears(): array
    {
        return ['1st Yr', '2nd Yr', '3rd Yr', '4th Yr'];
    }
}

if (!function_exists('sceValidSemesters')) {
    function sceValidSemesters(): array
    {
        return ['1st Sem', '2nd Sem', 'Mid Year'];
    }
}

if (!function_exists('sceNormalizeCourseCode')) {
    function sceNormalizeCourseCode(string $value): string
    {
        $value = strtoupper(trim($value));
        $value = preg_replace('/\s+/', ' ', $value);
        return trim((string) $value);
    }
}

if (!function_exists('sceBuildSelectableTermMap')) {
    function sceBuildSelectableTermMap(array $ayCoursesByTerm): array
    {
        $terms = [];

        foreach ($ayCoursesByTerm as $termData) {
            $year = trim((string) ($termData['year'] ?? ''));
            $semester = trim((string) ($termData['semester'] ?? ''));
            if ($year === '' || $semester === '') {
                continue;
            }

            $termKey = $year . '|' . $semester;
            $courses = [];

            foreach ((array) ($termData['uncomplete'] ?? []) as $course) {
                $code = sceNormalizeCourseCode((string) ($course['code'] ?? ''));
                if ($code === '') {
                    continue;
                }

                $prerequisite = trim((string) ($course['prerequisite'] ?? ''));
                if ($prerequisite === '' || strtoupper($prerequisite) === 'NONE') {
                    $prerequisite = 'None';
                }

                $courses[$code] = [
                    'course_code' => $code,
                    'course_title' => trim((string) ($course['title'] ?? '')),
                    'units' => (float) ($course['units'] ?? 0),
                    'prerequisite' => $prerequisite,
                    'reason' => trim((string) ($course['reason'] ?? '')),
                ];
            }

            $terms[$termKey] = [
                'year_level' => $year,
                'semester' => $semester,
                'courses' => $courses,
            ];
        }

        return $terms;
    }
}

if (!function_exists('sceLoadStudentCurrentEnrollment')) {
    function sceLoadStudentCurrentEnrollment(mysqli $conn, string $studentId): ?array
    {
        sceEnsureCurrentEnrollmentTables($conn);

        $stmt = $conn->prepare(
            "SELECT id, student_id, year_level, semester, created_at, updated_at
             FROM student_current_enrollments
             WHERE student_id = ?
             LIMIT 1"
        );
        if (!$stmt) {
            return null;
        }

        $stmt->bind_param('s', $studentId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        if (!$row) {
            return null;
        }

        $enrollmentId = (int) ($row['id'] ?? 0);
        $courses = [];
        if ($enrollmentId > 0) {
            $courseStmt = $conn->prepare(
                "SELECT course_code, course_title, units
                 FROM student_current_enrollment_courses
                 WHERE enrollment_id = ?
                 ORDER BY course_code ASC"
            );
            if ($courseStmt) {
                $courseStmt->bind_param('i', $enrollmentId);
                $courseStmt->execute();
                $courseResult = $courseStmt->get_result();
                while ($courseResult && ($courseRow = $courseResult->fetch_assoc())) {
                    $courses[] = [
                        'course_code' => (string) ($courseRow['course_code'] ?? ''),
                        'course_title' => (string) ($courseRow['course_title'] ?? ''),
                        'units' => (float) ($courseRow['units'] ?? 0),
                    ];
                }
                $courseStmt->close();
            }
        }

        return [
            'id' => $enrollmentId,
            'student_id' => (string) ($row['student_id'] ?? ''),
            'year_level' => (string) ($row['year_level'] ?? ''),
            'semester' => (string) ($row['semester'] ?? ''),
            'courses' => $courses,
            'course_codes' => array_values(array_map(
                static fn(array $course): string => (string) ($course['course_code'] ?? ''),
                $courses
            )),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }
}

if (!function_exists('sceBuildSelectableCourseMap')) {
    function sceBuildSelectableCourseMap(array $termMap): array
    {
        $courseMap = [];

        foreach ($termMap as $term) {
            $sourceYear = trim((string) ($term['year_level'] ?? ''));
            $sourceSemester = trim((string) ($term['semester'] ?? ''));

            foreach ((array) ($term['courses'] ?? []) as $courseCode => $course) {
                $normalizedCode = sceNormalizeCourseCode((string) $courseCode);
                if ($normalizedCode === '') {
                    continue;
                }

                $courseMap[$normalizedCode] = [
                    'course_code' => $normalizedCode,
                    'course_title' => trim((string) ($course['course_title'] ?? '')),
                    'units' => (float) ($course['units'] ?? 0),
                    'prerequisite' => trim((string) ($course['prerequisite'] ?? 'None')),
                    'reason' => trim((string) ($course['reason'] ?? '')),
                    'source_year_level' => $sourceYear,
                    'source_semester' => $sourceSemester,
                ];
            }
        }

        return $courseMap;
    }
}

if (!function_exists('sceSaveStudentCurrentEnrollment')) {
    function sceSaveStudentCurrentEnrollment(
        mysqli $conn,
        string $studentId,
        string $yearLevel,
        string $semester,
        array $courses
    ): array {
        sceEnsureCurrentEnrollmentTables($conn);

        $conn->begin_transaction();

        try {
            $existing = sceLoadStudentCurrentEnrollment($conn, $studentId);
            $enrollmentId = (int) ($existing['id'] ?? 0);

            if ($enrollmentId > 0) {
                $updateStmt = $conn->prepare(
                    "UPDATE student_current_enrollments
                     SET year_level = ?, semester = ?
                     WHERE id = ? AND student_id = ?"
                );
                if (!$updateStmt) {
                    throw new RuntimeException('Unable to prepare current enrollment update.');
                }
                $updateStmt->bind_param('ssis', $yearLevel, $semester, $enrollmentId, $studentId);
                if (!$updateStmt->execute()) {
                    throw new RuntimeException('Unable to update current enrollment.');
                }
                $updateStmt->close();

                $deleteStmt = $conn->prepare(
                    "DELETE FROM student_current_enrollment_courses WHERE enrollment_id = ?"
                );
                if (!$deleteStmt) {
                    throw new RuntimeException('Unable to prepare current enrollment reset.');
                }
                $deleteStmt->bind_param('i', $enrollmentId);
                if (!$deleteStmt->execute()) {
                    throw new RuntimeException('Unable to reset current enrollment courses.');
                }
                $deleteStmt->close();
            } else {
                $insertStmt = $conn->prepare(
                    "INSERT INTO student_current_enrollments (student_id, year_level, semester)
                     VALUES (?, ?, ?)"
                );
                if (!$insertStmt) {
                    throw new RuntimeException('Unable to prepare current enrollment save.');
                }
                $insertStmt->bind_param('sss', $studentId, $yearLevel, $semester);
                if (!$insertStmt->execute()) {
                    throw new RuntimeException('Unable to save current enrollment.');
                }
                $enrollmentId = (int) $conn->insert_id;
                $insertStmt->close();
            }

            $courseInsert = $conn->prepare(
                "INSERT INTO student_current_enrollment_courses (enrollment_id, course_code, course_title, units)
                 VALUES (?, ?, ?, ?)"
            );
            if (!$courseInsert) {
                throw new RuntimeException('Unable to prepare current enrollment course save.');
            }

            foreach ($courses as $course) {
                $courseCode = sceNormalizeCourseCode((string) ($course['course_code'] ?? ''));
                $courseTitle = trim((string) ($course['course_title'] ?? ''));
                $units = (float) ($course['units'] ?? 0);
                $courseInsert->bind_param('issd', $enrollmentId, $courseCode, $courseTitle, $units);
                if (!$courseInsert->execute()) {
                    throw new RuntimeException('Unable to save current enrollment course.');
                }
            }
            $courseInsert->close();

            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }

        return sceLoadStudentCurrentEnrollment($conn, $studentId) ?? [];
    }
}
