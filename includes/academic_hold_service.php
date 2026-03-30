<?php
/**
 * Academic hold helpers for read-only student access.
 *
 * Current policy implemented:
 * - If a student has three or more approved failed attempts for the same course,
 *   the account stays accessible but academic write actions become read-only.
 */

if (!function_exists('ahsGetStudentChecklistColumns')) {
    function ahsGetStudentChecklistColumns($conn): array
    {
        static $cache = null;

        if ($cache !== null) {
            return $cache;
        }

        $cache = [];
        $result = $conn->query("SHOW COLUMNS FROM student_checklists");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $field = (string)($row['Field'] ?? '');
                if ($field !== '') {
                    $cache[$field] = true;
                }
            }
            $result->close();
        }

        return $cache;
    }
}

if (!function_exists('ahsIsApprovedRemark')) {
    function ahsIsApprovedRemark($remark): bool
    {
        return strtolower(trim((string)$remark)) === 'approved';
    }
}

if (!function_exists('ahsIsFailingGradeValue')) {
    function ahsIsFailingGradeValue($grade): bool
    {
        $normalized = strtoupper(trim((string)$grade));

        if ($normalized === '' || $normalized === 'NO GRADE' || $normalized === 'PASSED' || $normalized === 'S') {
            return false;
        }

        if (in_array($normalized, ['FAILED', 'INC', 'DRP', 'W', 'US'], true)) {
            return true;
        }

        if (is_numeric($normalized)) {
            $numeric = (float)$normalized;
            return $numeric === 0.0 || $numeric > 3.0;
        }

        return false;
    }
}

if (!function_exists('ahsAttemptCountsAsFailure')) {
    function ahsAttemptCountsAsFailure($grade, $remark): bool
    {
        return ahsIsApprovedRemark($remark) && ahsIsFailingGradeValue($grade);
    }
}

if (!function_exists('ahsFormatHoldCourseList')) {
    function ahsFormatHoldCourseList(array $courses): string
    {
        if (empty($courses)) {
            return '';
        }

        $parts = [];
        foreach ($courses as $course) {
            $code = trim((string)($course['course_code'] ?? ''));
            $count = (int)($course['failure_count'] ?? 0);
            if ($code === '' || $count <= 0) {
                continue;
            }
            $parts[] = $code . ' (' . $count . ' failed attempts)';
        }

        return implode(', ', $parts);
    }
}

if (!function_exists('ahsGetStudentAcademicHold')) {
    function ahsGetStudentAcademicHold($conn, string $studentId): array
    {
        $default = [
            'active' => false,
            'policy' => 'triple_failed_course',
            'title' => 'Academic Enrollment Hold',
            'short_message' => '',
            'message' => '',
            'courses' => [],
        ];

        $studentId = trim($studentId);
        if ($studentId === '') {
            return $default;
        }

        $columns = ahsGetStudentChecklistColumns($conn);
        if (!isset($columns['course_code']) || !isset($columns['final_grade']) || !isset($columns['evaluator_remarks'])) {
            return $default;
        }

        $selectColumns = ['course_code', 'final_grade', 'evaluator_remarks'];
        if (isset($columns['final_grade_2']) && isset($columns['evaluator_remarks_2'])) {
            $selectColumns[] = 'final_grade_2';
            $selectColumns[] = 'evaluator_remarks_2';
        }
        if (isset($columns['final_grade_3']) && isset($columns['evaluator_remarks_3'])) {
            $selectColumns[] = 'final_grade_3';
            $selectColumns[] = 'evaluator_remarks_3';
        }

        $sql = "SELECT " . implode(', ', $selectColumns) . " FROM student_checklists WHERE student_id = ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return $default;
        }

        $stmt->bind_param('s', $studentId);
        $stmt->execute();
        $result = $stmt->get_result();

        $failureCounts = [];
        while ($row = $result->fetch_assoc()) {
            $courseCode = trim((string)($row['course_code'] ?? ''));
            if ($courseCode === '') {
                continue;
            }

            $attempts = [
                [
                    'grade' => $row['final_grade'] ?? '',
                    'remark' => $row['evaluator_remarks'] ?? '',
                ],
            ];

            if (array_key_exists('final_grade_2', $row) && array_key_exists('evaluator_remarks_2', $row)) {
                $attempts[] = [
                    'grade' => $row['final_grade_2'] ?? '',
                    'remark' => $row['evaluator_remarks_2'] ?? '',
                ];
            }

            if (array_key_exists('final_grade_3', $row) && array_key_exists('evaluator_remarks_3', $row)) {
                $attempts[] = [
                    'grade' => $row['final_grade_3'] ?? '',
                    'remark' => $row['evaluator_remarks_3'] ?? '',
                ];
            }

            foreach ($attempts as $attempt) {
                if (ahsAttemptCountsAsFailure($attempt['grade'] ?? '', $attempt['remark'] ?? '')) {
                    $failureCounts[$courseCode] = ($failureCounts[$courseCode] ?? 0) + 1;
                }
            }
        }
        $stmt->close();

        $heldCourses = [];
        foreach ($failureCounts as $courseCode => $count) {
            if ($count >= 3) {
                $heldCourses[] = [
                    'course_code' => $courseCode,
                    'failure_count' => $count,
                ];
            }
        }

        if (empty($heldCourses)) {
            return $default;
        }

        usort($heldCourses, static function ($a, $b) {
            return strcmp((string)$a['course_code'], (string)$b['course_code']);
        });

        $courseList = ahsFormatHoldCourseList($heldCourses);

        return [
            'active' => true,
            'policy' => 'triple_failed_course',
            'title' => 'Academic Enrollment Hold',
            'short_message' => 'Your account is now in read-only mode because one or more courses have already been failed three times.',
            'message' => 'You can still view your checklist and study plan, but grade submission and enrollment-related updates are locked. Courses at the limit: ' . $courseList . '. Please contact your adviser or the registrar for guidance.',
            'courses' => $heldCourses,
        ];
    }
}
