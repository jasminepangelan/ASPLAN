<?php
/**
 * Study Plan Generator using CSP (Constraint Satisfaction Problem) and Greedy Algorithm
 * 
 * This algorithm generates an optimized study plan for students based on:
 * 1. CSP Phase: Handles hard constraints (prerequisites, semester offerings, year standing)
 * 2. Greedy Phase: Optimizes course selection (prioritizes by units, critical path, graduation speed)
 * 
 * Constraint Rules Implemented:
 * - Back and failed Courses: Prioritize lower-year failed courses, enforce prerequisites, no overloading
 * - Cross-Registration: Allow courses available in other programs for the same semester
 * - Retention Policy: Warning (30-50%), Probation (51%+), Disqualification (75%+)
 *   with unit limits, semester skips, and consecutive status escalation
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/program_shift_service.php';

class StudyPlanGenerator {
    private $conn;
    private $student_id;
    private $program_label;
    private $program_code;
    private $curriculum_year = '';
    private $curriculum_program_labels = [];
    private $completed_courses = [];
    private $failed_courses = [];        // Courses with failing grades (need retake)
    private $inc_courses = [];           // Courses with INC status
    private $dropped_courses = [];       // Courses that were dropped
    private $all_courses = [];
    private $prerequisite_map = [];
    private $standing_constraint_map = []; // Year-standing constraints parsed from raw prerequisite text
    private $semester_grade_history = []; // Grade history per semester for retention
    private $retention_status = 'None';  // Current retention status
    private $retention_history = [];     // Retention status per semester
    private $cross_reg_courses = [];     // Courses available via cross-registration
    private $cross_reg_equivalent_courses = []; // Equivalent offerings grouped by structure
    private $disqualification_count = 0; // Number of disqualification statuses received
    private $term_max_units = [];        // Max units per year|semester from curriculum
    private $course_failure_counts = []; // Number of failures per course code
    private $thrice_failed_courses = []; // Courses failed 3+ times (triggers plan stop)
    private $student_classification = '';
    private $student_gwa = null;
    private $has_active_shift_request = false;
    private $legacy_transferee_inferred = false;
    private $table_column_cache = [];
    private $policy_gate_status = [
        'applies' => false,
        'eligible' => true,
        'reasons' => [],
        'average_grade' => null,
        'failed_course_count' => 0,
        'classification' => '',
        'has_active_shift_request' => false,
    ];
    
    // Map full program names to curriculum program codes
    private static $programCodeMap = [
        'Bachelor of Science in Computer Science' => 'BSCS',
        'Bachelor of Science in Information Technology' => 'BSIT',
        'Bachelor of Science in Computer Engineering' => 'BSCpE',
        'Bachelor of Science in Industrial Technology' => 'BSIndT',
        'Bachelor of Science in Hospitality Management' => 'BSHM',
        'Bachelor of Science in Business Administration - Major in Marketing Management' => 'BSBA-MM',
        'Bachelor of Science in Business Administration Major in Marketing Management' => 'BSBA-MM',
        'Bachelor of Science in Business Administration - Major in Human Resource Management' => 'BSBA-HRM',
        'Bachelor of Science in Business Administration Major in Human Resource Management' => 'BSBA-HRM',
        'Bachelor of Secondary Education major in English' => 'BSEd-English',
        'Bachelor of Secondary Education Major in English' => 'BSEd-English',
        'Bachelor of Secondary Education major Math' => 'BSEd-Math',
        'Bachelor of Secondary Education Major in Mathematics' => 'BSEd-Math',
        'Bachelor of Secondary Education major in Science' => 'BSEd-Science',
        'Bachelor of Secondary Education Major in Science' => 'BSEd-Science',
    ];
    
    public function __construct($student_id, $program = '') {
        $this->conn = getDBConnection();
        $this->student_id = $student_id;
        $this->program_label = trim((string)$program);
        $this->program_code = self::resolveProgramCode($program);
        $this->curriculum_program_labels = self::resolveCurriculumProgramLabels($this->program_label, $this->program_code);
        $this->curriculum_year = function_exists('psResolveStudentCurriculumYear')
            ? psResolveStudentCurriculumYear($this->conn, $this->student_id, $this->program_label, $this->program_code)
            : '';
        $this->loadStudentPolicyContext();
        $this->loadStudentData();
        $this->loadCurriculumData();
        $this->loadCrossRegistrationCourses();
        $this->calculateRetentionHistory();
        $this->evaluateShiftTransfereePolicyGate();
    }

    /**
     * Build a generator using the student's saved program.
     * Keeps diagnostics aligned with the main study plan page.
     */
    public static function createForStudent($student_id) {
        return new self($student_id, self::lookupStudentProgram($student_id));
    }

    /**
     * Look up the student's stored program label.
     */
    public static function lookupStudentProgram($student_id) {
        $conn = getDBConnection();
        $program = '';

        $stmt = $conn->prepare("
            SELECT program
            FROM student_info
            WHERE student_number = ?
            LIMIT 1
        ");

        if ($stmt) {
            $stmt->bind_param("s", $student_id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $program = trim((string)($row['program'] ?? ''));
            }
            $stmt->close();
        }

        closeDBConnection($conn);
        return $program;
    }

    private function normalizeCourseCode($value) {
        if (function_exists('psNormalizeCourseCode')) {
            return psNormalizeCourseCode($value);
        }

        $code = trim((string)$value);
        if ($code === '') {
            return '';
        }

        foreach ([' CS-IT', ' CpE', ' CPE', ' IndT', ' INDT', ' CS', ' IT'] as $suffix) {
            if (strlen($code) > strlen($suffix) && strcasecmp(substr($code, -strlen($suffix)), $suffix) === 0) {
                return trim(substr($code, 0, -strlen($suffix)));
            }
        }

        return $code;
    }
    
    /**
     * Resolve a program name or code to the short code used in curriculum lookup
     */
    private static function normalizeProgramLabel($value) {
        $value = strtoupper(trim((string)$value));
        $value = preg_replace('/[^A-Z0-9]+/', ' ', $value);
        return trim(preg_replace('/\s+/', ' ', $value));
    }

    private static function resolveProgramCode($program) {
        $program = trim((string)$program);
        if ($program === '') {
            return ''; // Do not default to an unrelated curriculum
        }

        // If it's already a short code (exists as a value in the map), use it directly
        if (in_array($program, self::$programCodeMap, true)) {
            return $program;
        }

        $normalized = self::normalizeProgramLabel($program);

        // Handle common short-code aliases and variants stored in DB.
        $aliasMap = [
            'BSBA MM' => 'BSBA-MM',
            'BSBA HRM' => 'BSBA-HRM',
            'BSCPE' => 'BSCpE',
            'BSINDT' => 'BSIndT',
            'BSED ENGLISH' => 'BSEd-English',
            'BSED MATH' => 'BSEd-Math',
            'BSED SCIENCE' => 'BSEd-Science',
        ];
        if (isset($aliasMap[$normalized])) {
            return $aliasMap[$normalized];
        }

        // Handle full-name variations (e.g. missing hyphen before MAJOR).
        if (strpos($normalized, 'BUSINESS ADMINISTRATION') !== false && strpos($normalized, 'MARKETING') !== false) {
            return 'BSBA-MM';
        }
        if (strpos($normalized, 'BUSINESS ADMINISTRATION') !== false &&
            (strpos($normalized, 'HUMAN RESOURCE') !== false || strpos($normalized, 'HRM') !== false)) {
            return 'BSBA-HRM';
        }

        // Look up normalized full-name labels.
        foreach (self::$programCodeMap as $fullName => $code) {
            if (self::normalizeProgramLabel($fullName) === $normalized) {
                return $code;
            }
        }

        return '';
    }

    private static function resolveCurriculumProgramLabels($program, $programCode = '') {
        if (function_exists('psResolveChecklistProgramLabels')) {
            return psResolveChecklistProgramLabels($program, $programCode);
        }

        $labels = [];
        $program = trim((string)$program);
        if ($program !== '') {
            $labels[$program] = true;
        }

        return array_keys($labels);
    }

    private function curriculumCoursesTableExists() {
        return function_exists('psTableExists')
            ? psTableExists($this->conn, 'curriculum_courses')
            : false;
    }

    private function tableHasColumn($table, $column) {
        $table = trim((string)$table);
        $column = trim((string)$column);
        if ($table === '' || $column === '') {
            return false;
        }

        $cacheKey = $table . '|' . $column;
        if (array_key_exists($cacheKey, $this->table_column_cache)) {
            return $this->table_column_cache[$cacheKey];
        }

        $tableSafe = str_replace('`', '``', $table);
        $columnSafe = str_replace(['`', "'"], ['', "''"], $column);
        $result = $this->conn->query("SHOW COLUMNS FROM `{$tableSafe}` LIKE '{$columnSafe}'");
        $exists = ($result instanceof mysqli_result) && $result->num_rows > 0;
        if ($result instanceof mysqli_result) {
            $result->close();
        }

        $this->table_column_cache[$cacheKey] = $exists;
        return $exists;
    }

    private function legacyProgramTokens() {
        if (function_exists('psResolveProgramTokens')) {
            return psResolveProgramTokens($this->program_code !== '' ? $this->program_code : $this->program_label);
        }

        $token = strtoupper(trim((string)$this->program_code));
        return $token !== '' ? [$token] : [];
    }

    private function buildCurriculumProgramCondition($columnExpression = 'UPPER(TRIM(program))') {
        $conditions = [];
        $params = [];
        $types = '';

        foreach ($this->curriculum_program_labels as $label) {
            $conditions[] = $columnExpression . ' = ?';
            $params[] = strtoupper(trim((string)$label));
            $types .= 's';
        }

        return [
            'sql' => !empty($conditions) ? '(' . implode(' OR ', $conditions) . ')' : '1 = 0',
            'params' => $params,
            'types' => $types,
        ];
    }

    private function buildLegacyProgramCondition($columnExpression = 'programs') {
        $conditions = [];
        $params = [];
        $types = '';

        foreach ($this->legacyProgramTokens() as $token) {
            $conditions[] = 'FIND_IN_SET(?, REPLACE(UPPER(' . $columnExpression . '), " ", "")) > 0';
            $params[] = strtoupper(trim((string)$token));
            $types .= 's';
        }

        return [
            'sql' => !empty($conditions) ? '(' . implode(' OR ', $conditions) . ')' : '1 = 0',
            'params' => $params,
            'types' => $types,
        ];
    }

    private function buildCurriculumYearCondition($columnExpression, $legacyPrefixExpression = '') {
        if ($this->curriculum_year === '') {
            return [
                'sql' => '',
                'params' => [],
                'types' => '',
            ];
        }

        $expression = $legacyPrefixExpression !== '' ? $legacyPrefixExpression : $columnExpression;
        return [
            'sql' => ' AND ' . $expression . ' = ?',
            'params' => [$this->curriculum_year],
            'types' => 's',
        ];
    }

    private function formatCrossRegistrationSourceProgram($programRaw) {
        $programRaw = trim((string)$programRaw);
        if ($programRaw === '') {
            return '';
        }

        if (function_exists('psParseProgramList') && function_exists('psCanonicalProgramLabel')) {
            $tokens = psParseProgramList($programRaw);
            $labels = [];

            foreach ($tokens as $token) {
                $label = trim((string) psCanonicalProgramLabel($token));
                if ($label === '') {
                    $label = trim((string) $token);
                }
                if ($label !== '') {
                    $labels[$label] = true;
                }
            }

            if (!empty($labels)) {
                return implode(', ', array_keys($labels));
            }
        }

        return $programRaw;
    }

    private function normalizeCourseTitleForComparison($value) {
        $value = strtoupper(trim((string)$value));
        if ($value === '') {
            return '';
        }

        $value = str_replace('&', ' AND ', $value);
        $value = preg_replace('/[^A-Z0-9]+/', ' ', $value);
        return trim((string) preg_replace('/\s+/', ' ', $value));
    }

    private function buildCourseEquivalencySignature($title, $creditUnitLec, $creditUnitLab, $lectHrsLec = 0, $lectHrsLab = 0) {
        return implode('|', [
            $this->normalizeCourseTitleForComparison($title),
            (int) $creditUnitLec,
            (int) $creditUnitLab,
            (int) $lectHrsLec,
            (int) $lectHrsLab,
        ]);
    }

    private function buildCourseEquivalencySignatureFromCourse(array $course) {
        return $this->buildCourseEquivalencySignature(
            $course['title'] ?? '',
            $course['credit_unit_lec'] ?? 0,
            $course['credit_unit_lab'] ?? 0,
            $course['lect_hrs_lec'] ?? 0,
            $course['lect_hrs_lab'] ?? 0
        );
    }

    /**
     * Determine whether a stored final grade should count as passed for
     * completion/progression logic.
     */
    private function isPassingFinalGrade($grade) {
        $normalized = strtoupper(trim((string)$grade));
        if ($normalized === 'S' || $normalized === 'PASSED') {
            return true;
        }

        if (is_numeric($grade)) {
            $numeric_grade = (float)$grade;
            return $numeric_grade >= 1.0 && $numeric_grade <= 3.0;
        }

        return false;
    }

    private function getStudentChecklistColumns() {
        static $columns = null;

        if ($columns !== null) {
            return $columns;
        }

        $columns = [];
        $result = $this->conn->query("SHOW COLUMNS FROM student_checklists");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $field = trim((string)($row['Field'] ?? ''));
                if ($field !== '') {
                    $columns[$field] = true;
                }
            }
        }

        return $columns;
    }

    private function buildChecklistAttemptSelectColumns($prefix = '') {
        $prefix = $prefix !== '' ? rtrim($prefix, '.') . '.' : '';
        $columns = $this->getStudentChecklistColumns();
        $selectColumns = [
            $prefix . 'final_grade',
            $prefix . 'evaluator_remarks',
        ];

        if (isset($columns['final_grade_2']) && isset($columns['evaluator_remarks_2'])) {
            $selectColumns[] = $prefix . 'final_grade_2';
            $selectColumns[] = $prefix . 'evaluator_remarks_2';
        }

        if (isset($columns['final_grade_3']) && isset($columns['evaluator_remarks_3'])) {
            $selectColumns[] = $prefix . 'final_grade_3';
            $selectColumns[] = $prefix . 'evaluator_remarks_3';
        }

        return $selectColumns;
    }

    private function buildChecklistAnyGradeCondition($prefix = '') {
        $prefix = $prefix !== '' ? rtrim($prefix, '.') . '.' : '';
        $columns = $this->getStudentChecklistColumns();
        $gradeColumns = [$prefix . 'final_grade'];

        if (isset($columns['final_grade_2'])) {
            $gradeColumns[] = $prefix . 'final_grade_2';
        }

        if (isset($columns['final_grade_3'])) {
            $gradeColumns[] = $prefix . 'final_grade_3';
        }

        $parts = [];
        foreach ($gradeColumns as $column) {
            $parts[] = "({$column} IS NOT NULL AND TRIM({$column}) != '' AND {$column} != 'No Grade')";
        }

        return '(' . implode(' OR ', $parts) . ')';
    }

    private function isApprovedChecklistRemark($remark) {
        $normalized = strtoupper(trim((string)$remark));
        if ($normalized === 'APPROVED') {
            return true;
        }

        return $normalized !== '' && strpos($normalized, 'CREDITED') !== false;
    }

    private function resolveEffectiveChecklistAttempt(array $row, $requiresApproval = true) {
        $columns = $this->getStudentChecklistColumns();
        $attempts = [
            1 => [
                'grade' => trim((string)($row['final_grade'] ?? '')),
                'remark' => trim((string)($row['evaluator_remarks'] ?? '')),
            ],
        ];

        if (isset($columns['final_grade_2']) && isset($columns['evaluator_remarks_2'])) {
            $attempts[2] = [
                'grade' => trim((string)($row['final_grade_2'] ?? '')),
                'remark' => trim((string)($row['evaluator_remarks_2'] ?? '')),
            ];
        }

        if (isset($columns['final_grade_3']) && isset($columns['evaluator_remarks_3'])) {
            $attempts[3] = [
                'grade' => trim((string)($row['final_grade_3'] ?? '')),
                'remark' => trim((string)($row['evaluator_remarks_3'] ?? '')),
            ];
        }

        for ($slot = 3; $slot >= 1; $slot--) {
            if (!isset($attempts[$slot])) {
                continue;
            }

            $grade = $attempts[$slot]['grade'];
            if ($grade === '' || strtoupper($grade) === 'NO GRADE') {
                continue;
            }

            if (!$requiresApproval || $this->isApprovedChecklistRemark($attempts[$slot]['remark'])) {
                return [
                    'slot' => $slot,
                    'grade' => $grade,
                    'remark' => $attempts[$slot]['remark'],
                ];
            }
        }

        if ($requiresApproval && (int)($row['grade_approved'] ?? 0) === 1) {
            $fallbackGrade = trim((string)($row['final_grade'] ?? ''));
            if ($fallbackGrade !== '' && strtoupper($fallbackGrade) !== 'NO GRADE') {
                return [
                    'slot' => 1,
                    'grade' => $fallbackGrade,
                    'remark' => trim((string)($row['evaluator_remarks'] ?? '')),
                ];
            }
        }

        return null;
    }

    private function extractChecklistAttempts(array $row) {
        $columns = $this->getStudentChecklistColumns();
        $attempts = [
            1 => [
                'slot' => 1,
                'grade' => trim((string)($row['final_grade'] ?? '')),
                'remark' => trim((string)($row['evaluator_remarks'] ?? '')),
            ],
        ];

        if (isset($columns['final_grade_2']) && isset($columns['evaluator_remarks_2'])) {
            $attempts[2] = [
                'slot' => 2,
                'grade' => trim((string)($row['final_grade_2'] ?? '')),
                'remark' => trim((string)($row['evaluator_remarks_2'] ?? '')),
            ];
        }

        if (isset($columns['final_grade_3']) && isset($columns['evaluator_remarks_3'])) {
            $attempts[3] = [
                'slot' => 3,
                'grade' => trim((string)($row['final_grade_3'] ?? '')),
                'remark' => trim((string)($row['evaluator_remarks_3'] ?? '')),
            ];
        }

        return $attempts;
    }

    private function checklistAttemptIsApproved(array $attempt, $requiresApproval, array $row) {
        if (!$requiresApproval) {
            return true;
        }

        if ($this->isApprovedChecklistRemark($attempt['remark'] ?? '')) {
            return true;
        }

        if ((int)($attempt['slot'] ?? 0) === 1 && (int)($row['grade_approved'] ?? 0) === 1) {
            return true;
        }

        return false;
    }

    private function checklistAttemptCountsAsFailure($grade) {
        $normalized = strtoupper(trim((string)$grade));
        if ($normalized === '' || $normalized === 'NO GRADE' || $normalized === 'S' || $normalized === 'PASSED') {
            return false;
        }

        if (in_array($normalized, ['FAILED', 'INC', 'DRP', 'W', 'US'], true)) {
            return true;
        }

        if (is_numeric($grade)) {
            $numeric = (float)$grade;
            return $numeric === 0.0 || $numeric > 3.0;
        }

        return false;
    }
    
    /**
     * Load student's completed AND failed courses from database
     * Tracks:
     * - Completed courses (passing grades 1.0-3.0)
     * - Failed courses (grades > 3.0 or 5.0)
     * - INC courses (Incomplete)
     * - Dropped courses (DRP/W)
     * Also builds semester-by-semester grade history for retention policy
     */
    private function loadStudentData() {
        $columns = $this->getStudentChecklistColumns();
        $has_approval_column = isset($columns['grade_approved']);
        
        // Load ALL grades (not just passing) for retention policy calculation
        $this->loadAllGrades($has_approval_column);

        $selectColumns = array_merge(['course_code'], $this->buildChecklistAttemptSelectColumns());
        if ($has_approval_column) {
            $selectColumns[] = 'grade_approved';
        }

        $sql = "
            SELECT " . implode(', ', array_unique($selectColumns)) . "
            FROM student_checklists
            WHERE student_id = ?
            AND " . $this->buildChecklistAnyGradeCondition() . "
        ";

        $query = $this->conn->prepare($sql);
        if (!$query) {
            return;
        }

        $query->bind_param("s", $this->student_id);
        $query->execute();
        $result = $query->get_result();

        while ($row = $result->fetch_assoc()) {
            $effectiveAttempt = $this->resolveEffectiveChecklistAttempt($row, $has_approval_column);
            if ($effectiveAttempt === null) {
                continue;
            }

            if ($this->isPassingFinalGrade($effectiveAttempt['grade'] ?? null)) {
                $this->completed_courses[] = $this->normalizeCourseCode($row['course_code'] ?? '');
            }
        }
        $query->close();
    }

    private function loadStudentPolicyContext() {
        $stmt = $this->conn->prepare("
            SELECT stud_classification, general_weighted_average
            FROM student_info
            WHERE student_number = ?
            LIMIT 1
        ");
        if ($stmt) {
            $stmt->bind_param("s", $this->student_id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $this->student_classification = trim((string)($row['stud_classification'] ?? ''));
                $gwa = $row['general_weighted_average'] ?? null;
                if ($gwa !== null && $gwa !== '' && is_numeric($gwa)) {
                    $this->student_gwa = (float)$gwa;
                }
            }
            $stmt->close();
        }

        $tableExists = $this->conn->query("SHOW TABLES LIKE 'program_shift_requests'");
        if ($tableExists && $tableExists->num_rows > 0) {
            $shiftStmt = $this->conn->prepare("
                SELECT id
                FROM program_shift_requests
                WHERE student_number = ?
                AND status IN ('pending_adviser', 'pending_current_coordinator', 'pending_destination_coordinator', 'pending_coordinator')
                LIMIT 1
            ");
            if ($shiftStmt) {
                $shiftStmt->bind_param("s", $this->student_id);
                $shiftStmt->execute();
                $shiftResult = $shiftStmt->get_result();
                $this->has_active_shift_request = $shiftResult && $shiftResult->num_rows > 0;
                $shiftStmt->close();
            }
        }
    }
    
    /**
     * Load ALL grades including failed/INC/dropped for retention policy and back-subject tracking
     */
    private function loadAllGrades($has_approval_column) {
        $attemptColumns = $this->buildChecklistAttemptSelectColumns('sc');
        if ($has_approval_column) {
            $attemptColumns[] = 'sc.grade_approved';
        }

        if ($this->curriculumCoursesTableExists()) {
            $condition = $this->buildCurriculumProgramCondition('UPPER(TRIM(cb.program))');
            $yearCondition = $this->buildCurriculumYearCondition('cb.curriculum_year');
            $sql = "
                SELECT
                    sc.course_code,
                    " . implode(",\n                    ", $attemptColumns) . ",
                    CASE cb.year_level
                        WHEN 'First Year' THEN '1st Yr'
                        WHEN 'Second Year' THEN '2nd Yr'
                        WHEN 'Third Year' THEN '3rd Yr'
                        WHEN 'Fourth Year' THEN '4th Yr'
                        ELSE cb.year_level
                    END AS year,
                    CASE cb.semester
                        WHEN 'First Semester' THEN '1st Sem'
                        WHEN 'Second Semester' THEN '2nd Sem'
                        WHEN 'Mid Year' THEN 'Mid Year'
                        WHEN 'Midyear' THEN 'Mid Year'
                        WHEN 'Summer' THEN 'Mid Year'
                        ELSE cb.semester
                    END AS semester,
                    (cb.credit_units_lec + cb.credit_units_lab) AS total_units,
                    sc.evaluator_remarks
                FROM student_checklists sc
                JOIN curriculum_courses cb
                    ON sc.course_code = TRIM(cb.course_code)
                WHERE sc.student_id = ?
                AND " . $this->buildChecklistAnyGradeCondition('sc') . "
                AND {$condition['sql']}" . $yearCondition['sql'] . "
                ORDER BY
                    FIELD(cb.year_level, 'First Year', 'Second Year', 'Third Year', 'Fourth Year'),
                    FIELD(cb.semester, 'First Semester', 'Second Semester', 'Mid Year', 'Midyear', 'Summer')
            ";

            $stmt = $this->conn->prepare($sql);
            $params = array_merge([$this->student_id], $condition['params'], $yearCondition['params']);
            $types = 's' . $condition['types'] . $yearCondition['types'];
            $stmt->bind_param($types, ...$params);
        } else {
            $condition = $this->buildLegacyProgramCondition('cb.programs');
            $yearCondition = $this->buildCurriculumYearCondition('', "TRIM(SUBSTRING_INDEX(cb.curriculumyear_coursecode, '_', 1))");
            $sql = "
                SELECT 
                    sc.course_code,
                    " . implode(",\n                    ", $attemptColumns) . ",
                    CASE cb.year_level
                        WHEN 'First Year' THEN '1st Yr'
                        WHEN 'Second Year' THEN '2nd Yr'
                        WHEN 'Third Year' THEN '3rd Yr'
                        WHEN 'Fourth Year' THEN '4th Yr'
                        ELSE cb.year_level
                    END AS year,
                    CASE cb.semester
                        WHEN 'First Semester' THEN '1st Sem'
                        WHEN 'Second Semester' THEN '2nd Sem'
                        WHEN 'Mid Year' THEN 'Mid Year'
                        WHEN 'Midyear' THEN 'Mid Year'
                        WHEN 'Summer' THEN 'Mid Year'
                        ELSE cb.semester
                    END AS semester,
                    (cb.credit_units_lec + cb.credit_units_lab) AS total_units,
                    sc.evaluator_remarks
                FROM student_checklists sc
                JOIN cvsucarmona_courses cb 
                    ON sc.course_code = TRIM(SUBSTRING_INDEX(cb.curriculumyear_coursecode, '_', -1))
                WHERE sc.student_id = ?
                AND " . $this->buildChecklistAnyGradeCondition('sc') . "
                AND {$condition['sql']}" . $yearCondition['sql'] . "
                ORDER BY
                    FIELD(cb.year_level, 'First Year', 'Second Year', 'Third Year', 'Fourth Year'),
                    FIELD(cb.semester, 'First Semester', 'Second Semester', 'Mid Year', 'Midyear', 'Summer')
            ";

            $stmt = $this->conn->prepare($sql);
            $params = array_merge([$this->student_id], $condition['params'], $yearCondition['params']);
            $types = 's' . $condition['types'] . $yearCondition['types'];
            $stmt->bind_param($types, ...$params);
        }

        $stmt->execute();
        $result = $stmt->get_result();
        
        // Track processed course codes to prevent double-counting from duplicate DB rows
        $processed_grade_codes = [];
        
        while ($row = $result->fetch_assoc()) {
            $course_code = $this->normalizeCourseCode($row['course_code'] ?? '');
            
            // Skip duplicate rows caused by same course_code in multiple curriculum entries
            if (isset($processed_grade_codes[$course_code])) continue;
            $processed_grade_codes[$course_code] = true;

            // Count every approved failed attempt slot (final_grade, final_grade_2, final_grade_3)
            // so courses failed multiple times can trigger policy stop conditions.
            foreach ($this->extractChecklistAttempts($row) as $attempt) {
                $grade = $attempt['grade'] ?? '';
                if ($grade === '' || strtoupper((string)$grade) === 'NO GRADE') {
                    continue;
                }

                if (!$this->checklistAttemptIsApproved($attempt, $has_approval_column, $row)) {
                    continue;
                }

                if ($this->checklistAttemptCountsAsFailure($grade)) {
                    $this->course_failure_counts[$course_code] = ($this->course_failure_counts[$course_code] ?? 0) + 1;
                }
            }

            $effectiveAttempt = $this->resolveEffectiveChecklistAttempt($row, $has_approval_column);
            if ($effectiveAttempt === null) {
                continue;
            }

            $grade = $effectiveAttempt['grade'];
            $year = $row['year'];
            $semester = $row['semester'];
            
            // Build semester-by-semester grade history for retention calculation
            $term_key = $year . '|' . $semester;
            if (!isset($this->semester_grade_history[$term_key])) {
                $this->semester_grade_history[$term_key] = [
                    'year' => $year,
                    'semester' => $semester,
                    'total_subjects' => 0,
                    'failed_subjects' => 0,
                    'courses' => []
                ];
            }
            
            $is_failed = false;
            
            if ($grade === 'INC') {
                $this->inc_courses[] = $course_code;
                $is_failed = true;
            } elseif ($grade === 'DRP' || $grade === 'W') {
                $this->dropped_courses[] = $course_code;
                $is_failed = true;
            } elseif (is_numeric($grade)) {
                $numeric_grade = floatval($grade);
                if ($numeric_grade > 3.0 || $numeric_grade === 0.0) {
                    $this->failed_courses[] = $course_code;
                    $is_failed = true;
                }
            }
            
            $this->semester_grade_history[$term_key]['total_subjects']++;
            if ($is_failed) {
                $this->semester_grade_history[$term_key]['failed_subjects']++;
            }
            $this->semester_grade_history[$term_key]['courses'][] = [
                'code' => $course_code,
                'grade' => $grade,
                'failed' => $is_failed
            ];
        }
        // Identify courses that have been failed 3 or more times
        foreach ($this->course_failure_counts as $code => $count) {
            if ($count >= 3) {
                $this->thrice_failed_courses[$code] = $count;
            }
        }

        $stmt->close();
    }
    
    /**
     * Calculate retention status history per semester
     * Implements:
     * - Warning: 30-50% failure rate
     * - Probation: 51%+ failure rate OR two consecutive warnings
     * - Disqualification: 75%+ failure rate OR two consecutive probations
     */
    private function calculateRetentionHistory() {
        $year_order = ['1st Yr' => 1, '2nd Yr' => 2, '3rd Yr' => 3, '4th Yr' => 4];
        $sem_order = ['1st Sem' => 1, '2nd Sem' => 2, 'Mid Year' => 3];
        
        // Sort semesters chronologically
        $sorted_terms = $this->semester_grade_history;
        uksort($sorted_terms, function($a, $b) use ($year_order, $sem_order) {
            list($ya, $sa) = explode('|', $a);
            list($yb, $sb) = explode('|', $b);
            $ya_ord = $year_order[$ya] ?? 0;
            $yb_ord = $year_order[$yb] ?? 0;
            if ($ya_ord !== $yb_ord) return $ya_ord - $yb_ord;
            return ($sem_order[$sa] ?? 0) - ($sem_order[$sb] ?? 0);
        });
        
        $prev_status = 'None';
        $consecutive_warning = 0;
        $consecutive_probation = 0;
        $disqualification_count = 0;
        
        foreach ($sorted_terms as $term_key => $term_data) {
            $total = $term_data['total_subjects'];
            $failed = $term_data['failed_subjects'];
            
            if ($total === 0) {
                $this->retention_history[$term_key] = 'None';
                continue;
            }
            
            $fail_percentage = ($failed / $total) * 100;
            $status = 'None';
            
            // Determine base status from failure percentage
            if ($fail_percentage >= 75) {
                $status = 'Disqualification';
            } elseif ($fail_percentage >= 51) {
                $status = 'Probation';
            } elseif ($fail_percentage >= 30) {
                $status = 'Warning';
            }
            
            // Escalation: Two consecutive warnings → Probation
            if ($status === 'Warning') {
                $consecutive_warning++;
                $consecutive_probation = 0;
                if ($consecutive_warning >= 2) {
                    $status = 'Probation';
                    $consecutive_warning = 0;
                }
            } elseif ($status === 'Probation') {
                $consecutive_probation++;
                $consecutive_warning = 0;
                // Two consecutive probations → Disqualification
                if ($consecutive_probation >= 2) {
                    $status = 'Disqualification';
                    $consecutive_probation = 0;
                }
            } elseif ($status === 'Disqualification') {
                $disqualification_count++;
                $consecutive_warning = 0;
                $consecutive_probation = 0;
            } else {
                // Reset consecutive counters on clean semester
                $consecutive_warning = 0;
                $consecutive_probation = 0;
            }
            
            $this->retention_history[$term_key] = $status;
            $prev_status = $status;
        }
        
        // Determine current retention status (from latest semester)
        if (!empty($this->retention_history)) {
            $this->retention_status = end($this->retention_history);
        }
        
        // Store disqualification count for study plan generation
        $this->disqualification_count = $disqualification_count;
    }
    
    /**
     * Load courses available via cross-registration from other programs
     * If a course is not offered in the student's program for a given semester
     * but is offered in another program's same semester, it can be cross-registered
     */
    private function loadCrossRegistrationCourses() {
        $curriculumHasLecHours = $this->tableHasColumn('curriculum_courses', 'lect_hrs_lec');
        $curriculumHasLabHours = $this->tableHasColumn('curriculum_courses', 'lect_hrs_lab');
        $legacyHasLecHours = $this->tableHasColumn('cvsucarmona_courses', 'lect_hrs_lec');
        $legacyHasLabHours = $this->tableHasColumn('cvsucarmona_courses', 'lect_hrs_lab');

        if ($this->curriculumCoursesTableExists()) {
            $condition = $this->buildCurriculumProgramCondition('UPPER(TRIM(program))');
            $yearCondition = $this->buildCurriculumYearCondition('curriculum_year');
            $sql = "
                SELECT
                    TRIM(course_code) AS course_code,
                    course_title,
                    credit_units_lec AS credit_unit_lec,
                    credit_units_lab AS credit_unit_lab,
                    " . ($curriculumHasLecHours ? 'lect_hrs_lec' : '0') . " AS lect_hrs_lec,
                    " . ($curriculumHasLabHours ? 'lect_hrs_lab' : '0') . " AS lect_hrs_lab,
                    pre_requisite,
                    program AS programs,
                    CASE year_level
                        WHEN 'First Year' THEN '1st Yr'
                        WHEN 'Second Year' THEN '2nd Yr'
                        WHEN 'Third Year' THEN '3rd Yr'
                        WHEN 'Fourth Year' THEN '4th Yr'
                        ELSE year_level
                    END AS year,
                    CASE semester
                        WHEN 'First Semester' THEN '1st Sem'
                        WHEN 'Second Semester' THEN '2nd Sem'
                        WHEN 'Mid Year' THEN 'Mid Year'
                        WHEN 'Midyear' THEN 'Mid Year'
                        WHEN 'Summer' THEN 'Mid Year'
                        ELSE semester
                    END AS semester
                FROM curriculum_courses
                WHERE NOT {$condition['sql']}
                " . $yearCondition['sql'] . "
                AND course_title IS NOT NULL
                AND course_title != ''
                AND (credit_units_lec > 0 OR credit_units_lab > 0)
                ORDER BY
                    FIELD(year_level, 'First Year', 'Second Year', 'Third Year', 'Fourth Year'),
                    FIELD(semester, 'First Semester', 'Second Semester', 'Mid Year', 'Midyear', 'Summer'),
                    id
            ";
            $stmt = $this->conn->prepare($sql);
            $params = array_merge($condition['params'], $yearCondition['params']);
            $types = $condition['types'] . $yearCondition['types'];
            $stmt->bind_param($types, ...$params);
        } else {
            $condition = $this->buildLegacyProgramCondition('programs');
            $yearCondition = $this->buildCurriculumYearCondition('', "TRIM(SUBSTRING_INDEX(curriculumyear_coursecode, '_', 1))");
            $sql = "
                SELECT 
                    TRIM(SUBSTRING_INDEX(curriculumyear_coursecode, '_', -1)) AS course_code,
                    course_title,
                    credit_units_lec AS credit_unit_lec,
                    credit_units_lab AS credit_unit_lab,
                    " . ($legacyHasLecHours ? 'lect_hrs_lec' : '0') . " AS lect_hrs_lec,
                    " . ($legacyHasLabHours ? 'lect_hrs_lab' : '0') . " AS lect_hrs_lab,
                    pre_requisite,
                    programs,
                    CASE year_level
                        WHEN 'First Year' THEN '1st Yr'
                        WHEN 'Second Year' THEN '2nd Yr'
                        WHEN 'Third Year' THEN '3rd Yr'
                        WHEN 'Fourth Year' THEN '4th Yr'
                        ELSE year_level
                    END AS year,
                    CASE semester
                        WHEN 'First Semester' THEN '1st Sem'
                        WHEN 'Second Semester' THEN '2nd Sem'
                        WHEN 'Mid Year' THEN 'Mid Year'
                        WHEN 'Midyear' THEN 'Mid Year'
                        WHEN 'Summer' THEN 'Mid Year'
                        ELSE semester
                    END AS semester
                FROM cvsucarmona_courses
                WHERE NOT {$condition['sql']}
                " . $yearCondition['sql'] . "
                AND course_title IS NOT NULL
                AND course_title != ''
                AND (credit_units_lec > 0 OR credit_units_lab > 0)
                ORDER BY
                    FIELD(year_level, 'First Year', 'Second Year', 'Third Year', 'Fourth Year'),
                    FIELD(semester, 'First Semester', 'Second Semester', 'Mid Year', 'Midyear', 'Summer'),
                    curriculumyear_coursecode
            ";
            $stmt = $this->conn->prepare($sql);
            $params = array_merge($condition['params'], $yearCondition['params']);
            $types = $condition['types'] . $yearCondition['types'];
            $stmt->bind_param($types, ...$params);
        }

        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $course_code = $this->normalizeCourseCode($row['course_code'] ?? '');
            if ($course_code === '') {
                continue;
            }

            $offering = [
                'code' => $course_code,
                'title' => $row['course_title'],
                'units' => ($row['credit_unit_lec'] ?? 0) + ($row['credit_unit_lab'] ?? 0),
                'credit_unit_lec' => (int)($row['credit_unit_lec'] ?? 0),
                'credit_unit_lab' => (int)($row['credit_unit_lab'] ?? 0),
                'lect_hrs_lec' => (int)($row['lect_hrs_lec'] ?? 0),
                'lect_hrs_lab' => (int)($row['lect_hrs_lab'] ?? 0),
                'prerequisite' => $row['pre_requisite'],
                'year' => $row['year'],
                'semester' => $row['semester'],
                'programs' => $row['programs'],
                'cross_reg_source_program' => $this->formatCrossRegistrationSourceProgram($row['programs'] ?? ''),
                'cross_registered' => true
            ];

            if (!isset($this->cross_reg_courses[$course_code])) {
                $this->cross_reg_courses[$course_code] = [];
            }

            $signature = implode('|', [
                $offering['year'],
                $offering['semester'],
                strtoupper(trim((string)$offering['programs'])),
            ]);

            $this->cross_reg_courses[$course_code][$signature] = $offering;

            $equivalencySignature = $this->buildCourseEquivalencySignature(
                $offering['title'],
                $offering['credit_unit_lec'],
                $offering['credit_unit_lab'],
                $offering['lect_hrs_lec'],
                $offering['lect_hrs_lab']
            );
            if (!isset($this->cross_reg_equivalent_courses[$equivalencySignature])) {
                $this->cross_reg_equivalent_courses[$equivalencySignature] = [];
            }
            $this->cross_reg_equivalent_courses[$equivalencySignature][$signature] = $offering;
        }
        $stmt->close();
    }

    private function findCrossRegistrationOffering($course_code, $target_semester, array $courseContext = []) {
        $offerings = $this->cross_reg_courses[$course_code] ?? [];
        foreach ($offerings as $offering) {
            if (($offering['semester'] ?? null) === $target_semester) {
                return $offering;
            }
        }

        if (!empty($courseContext)) {
            $equivalencySignature = $this->buildCourseEquivalencySignatureFromCourse($courseContext);
            $equivalentOfferings = $this->cross_reg_equivalent_courses[$equivalencySignature] ?? [];
            foreach ($equivalentOfferings as $offering) {
                if (($offering['semester'] ?? null) === $target_semester) {
                    return $offering;
                }
            }
        }

        return null;
    }
    
    /**
     * Load complete curriculum with prerequisites
     * Only loads actual courses (excludes empty/placeholder records)
     * Also marks failed/INC/dropped courses for retake scheduling
     */
    private function loadCurriculumData() {
        $curriculumHasLecHours = $this->tableHasColumn('curriculum_courses', 'lect_hrs_lec');
        $curriculumHasLabHours = $this->tableHasColumn('curriculum_courses', 'lect_hrs_lab');
        $legacyHasLecHours = $this->tableHasColumn('cvsucarmona_courses', 'lect_hrs_lec');
        $legacyHasLabHours = $this->tableHasColumn('cvsucarmona_courses', 'lect_hrs_lab');

        if ($this->curriculumCoursesTableExists()) {
            $condition = $this->buildCurriculumProgramCondition('UPPER(TRIM(program))');
            $yearCondition = $this->buildCurriculumYearCondition('curriculum_year');
            $sql = "
                SELECT 
                    TRIM(course_code) AS course_code,
                    course_title,
                    credit_units_lec AS credit_unit_lec,
                    credit_units_lab AS credit_unit_lab,
                    " . ($curriculumHasLecHours ? 'lect_hrs_lec' : '0') . " AS lect_hrs_lec,
                    " . ($curriculumHasLabHours ? 'lect_hrs_lab' : '0') . " AS lect_hrs_lab,
                    pre_requisite,
                    CASE year_level
                        WHEN 'First Year' THEN '1st Yr'
                        WHEN 'Second Year' THEN '2nd Yr'
                        WHEN 'Third Year' THEN '3rd Yr'
                        WHEN 'Fourth Year' THEN '4th Yr'
                        ELSE year_level
                    END AS year,
                    CASE semester
                        WHEN 'First Semester' THEN '1st Sem'
                        WHEN 'Second Semester' THEN '2nd Sem'
                        WHEN 'Mid Year' THEN 'Mid Year'
                        WHEN 'Midyear' THEN 'Mid Year'
                        WHEN 'Summer' THEN 'Mid Year'
                        ELSE semester
                    END AS semester
                FROM curriculum_courses
                WHERE {$condition['sql']}
                " . $yearCondition['sql'] . "
                AND course_title IS NOT NULL
                AND course_title != ''
                AND (credit_units_lec > 0 OR credit_units_lab > 0)
                ORDER BY 
                    FIELD(year_level, 'First Year', 'Second Year', 'Third Year', 'Fourth Year'),
                    FIELD(semester, 'First Semester', 'Second Semester', 'Mid Year', 'Midyear', 'Summer'),
                    IFNULL(curriculum_year, 0),
                    id
            ";
            $stmt = $this->conn->prepare($sql);
            $params = array_merge($condition['params'], $yearCondition['params']);
            $types = $condition['types'] . $yearCondition['types'];
            $stmt->bind_param($types, ...$params);
        } else {
            $condition = $this->buildLegacyProgramCondition('programs');
            $yearCondition = $this->buildCurriculumYearCondition('', "TRIM(SUBSTRING_INDEX(curriculumyear_coursecode, '_', 1))");
            $sql = "
                SELECT 
                    TRIM(SUBSTRING_INDEX(curriculumyear_coursecode, '_', -1)) AS course_code,
                    course_title,
                    credit_units_lec AS credit_unit_lec,
                    credit_units_lab AS credit_unit_lab,
                    " . ($legacyHasLecHours ? 'lect_hrs_lec' : '0') . " AS lect_hrs_lec,
                    " . ($legacyHasLabHours ? 'lect_hrs_lab' : '0') . " AS lect_hrs_lab,
                    pre_requisite,
                    CASE year_level
                        WHEN 'First Year' THEN '1st Yr'
                        WHEN 'Second Year' THEN '2nd Yr'
                        WHEN 'Third Year' THEN '3rd Yr'
                        WHEN 'Fourth Year' THEN '4th Yr'
                        ELSE year_level
                    END AS year,
                    CASE semester
                        WHEN 'First Semester' THEN '1st Sem'
                        WHEN 'Second Semester' THEN '2nd Sem'
                        WHEN 'Mid Year' THEN 'Mid Year'
                        WHEN 'Midyear' THEN 'Mid Year'
                        WHEN 'Summer' THEN 'Mid Year'
                        ELSE semester
                    END AS semester
                FROM cvsucarmona_courses
                WHERE {$condition['sql']}
                " . $yearCondition['sql'] . "
                AND course_title IS NOT NULL
                AND course_title != ''
                AND (credit_units_lec > 0 OR credit_units_lab > 0)
                ORDER BY 
                    FIELD(year_level, 'First Year', 'Second Year', 'Third Year', 'Fourth Year'),
                    FIELD(semester, 'First Semester', 'Second Semester', 'Mid Year', 'Midyear', 'Summer'),
                    curriculumyear_coursecode
            ";
            $stmt = $this->conn->prepare($sql);
            $params = array_merge($condition['params'], $yearCondition['params']);
            $types = $condition['types'] . $yearCondition['types'];
            $stmt->bind_param($types, ...$params);
        }

        $stmt->execute();
        $query = $stmt->get_result();
        
        // Track term max units separately (includes all rows, even duplicates)
        $this->term_max_units = [];
        
        while ($row = $query->fetch_assoc()) {
            $course_code = $this->normalizeCourseCode($row['course_code'] ?? '');
            $units = ($row['credit_unit_lec'] ?? 0) + ($row['credit_unit_lab'] ?? 0);
            $year = $row['year'];
            $semester = $row['semester'];
            
            // Always count units toward term max (even for duplicate course codes)
            $term_key = $year . '|' . $semester;
            if (!isset($this->term_max_units[$term_key])) {
                $this->term_max_units[$term_key] = 0;
            }
            $this->term_max_units[$term_key] += $units;
            
            // For duplicate course codes within a program, keep the first (lowest year/sem) entry
            // since ORDER BY ensures chronological order. The student takes it at the earliest offering.
            if (isset($this->all_courses[$course_code])) {
                continue;
            }
            
            $is_failed = in_array($course_code, $this->failed_courses);
            $is_inc = in_array($course_code, $this->inc_courses);
            $is_dropped = in_array($course_code, $this->dropped_courses);
            $needs_retake = $is_failed || $is_inc || $is_dropped;
            
            $this->all_courses[$course_code] = [
                'code' => $course_code,
                'title' => $row['course_title'],
                'units' => $units,
                'credit_unit_lec' => (int)($row['credit_unit_lec'] ?? 0),
                'credit_unit_lab' => (int)($row['credit_unit_lab'] ?? 0),
                'lect_hrs_lec' => (int)($row['lect_hrs_lec'] ?? 0),
                'lect_hrs_lab' => (int)($row['lect_hrs_lab'] ?? 0),
                'prerequisite' => $row['pre_requisite'],
                'year' => $year,
                'semester' => $semester,
                'completed' => in_array($course_code, $this->completed_courses),
                'is_failed' => $is_failed,
                'is_inc' => $is_inc,
                'is_dropped' => $is_dropped,
                'needs_retake' => $needs_retake,
                'cross_registered' => false
            ];
            
            // Build prerequisite map
            $this->prerequisite_map[$course_code] = $this->parsePrerequisites($row['pre_requisite']);
            $this->standing_constraint_map[$course_code] = $this->extractStandingConstraint($row['pre_requisite']);
        }
        $stmt->close();
    }

    private function inferLegacyTransfereeStatus() {
        $classification = strtolower(trim($this->student_classification));
        if ($classification !== '') {
            return false;
        }

        if ($this->has_active_shift_request) {
            return false;
        }

        $active_failed = array_diff($this->failed_courses, $this->completed_courses);
        $active_inc = array_diff($this->inc_courses, $this->completed_courses);
        $active_dropped = array_diff($this->dropped_courses, $this->completed_courses);
        if (!empty($active_failed) || !empty($active_inc) || !empty($active_dropped)) {
            return false;
        }

        $terms = $this->getOrderedCurriculumTerms();
        if (empty($terms)) {
            return false;
        }

        $firstTerm = $terms[0];
        $firstTermKey = ($firstTerm['year'] ?? '') . '|' . ($firstTerm['semester'] ?? '');
        $totalCourses = 0;
        $completedCourses = 0;

        foreach ($this->all_courses as $course) {
            $termKey = ($course['year'] ?? '') . '|' . ($course['semester'] ?? '');
            if ($termKey !== $firstTermKey) {
                continue;
            }

            $totalCourses++;
            if (!empty($course['completed'])) {
                $completedCourses++;
            }
        }

        return $totalCourses > 0 && $completedCourses > 0 && $completedCourses < $totalCourses;
    }

    private function evaluateShiftTransfereePolicyGate() {
        $classification = strtolower(trim($this->student_classification));
        $this->legacy_transferee_inferred = $this->inferLegacyTransfereeStatus();
        $is_transferee = ($classification !== '' && strpos($classification, 'transferee') !== false)
            || $this->legacy_transferee_inferred;
        $applies = $is_transferee || $this->has_active_shift_request;

        $active_failed = array_diff($this->failed_courses, $this->completed_courses);
        $active_inc = array_diff($this->inc_courses, $this->completed_courses);
        $active_dropped = array_diff($this->dropped_courses, $this->completed_courses);
        $failed_course_count = count($active_failed) + count($active_inc) + count($active_dropped);

        $average_grade = $this->calculatePolicyAverageGrade();
        $reasons = [];

        if ($failed_course_count > 0) {
            $reasons[] = 'Student has failing, INC, or dropped courses on record.';
        }

        if ($average_grade !== null && $average_grade > 2.0) {
            $reasons[] = 'Average grade is above 2.00.';
        }

        $this->policy_gate_status = [
            'applies' => $applies,
            'eligible' => (!$applies || empty($reasons)),
            'reasons' => $reasons,
            'average_grade' => $average_grade,
            'failed_course_count' => $failed_course_count,
            'classification' => $this->student_classification !== '' ? $this->student_classification : ($this->legacy_transferee_inferred ? 'Transferee (inferred)' : ''),
            'has_active_shift_request' => $this->has_active_shift_request,
            'legacy_transferee_inferred' => $this->legacy_transferee_inferred,
        ];
    }

    private function calculatePolicyAverageGrade() {
        $columns = $this->getStudentChecklistColumns();
        $has_approval_column = isset($columns['grade_approved']);
        $selectColumns = $this->buildChecklistAttemptSelectColumns();
        if ($has_approval_column) {
            $selectColumns[] = 'grade_approved';
        }

        $sql = "
            SELECT " . implode(', ', array_unique($selectColumns)) . "
            FROM student_checklists
            WHERE student_id = ?
            AND " . $this->buildChecklistAnyGradeCondition() . "
        ";

        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return $this->student_gwa;
        }

        $stmt->bind_param("s", $this->student_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $grades = [];
        while ($row = $result->fetch_assoc()) {
            $effectiveAttempt = $this->resolveEffectiveChecklistAttempt($row, $has_approval_column);
            if ($effectiveAttempt === null) {
                continue;
            }

            if (is_numeric($effectiveAttempt['grade'])) {
                $grades[] = (float)$effectiveAttempt['grade'];
            }
        }
        $stmt->close();

        if (!empty($grades)) {
            return round(array_sum($grades) / count($grades), 2);
        }

        return $this->student_gwa;
    }
    
    /**
     * Parse prerequisite string into array of course codes
     * Filters out non-course prerequisites (year standing, percentage requirements, etc.)
     * Handles "&" separators (e.g., "GNED 11 & 12" → ["GNED 11", "GNED 12"])
     */
    private function parsePrerequisites($prereq_string) {
        $normalizeToken = static function ($value) {
            $value = strtoupper(trim((string)$value));
            if ($value === '') {
                return '';
            }

            $value = preg_replace('/\s+/', ' ', $value);
            $value = preg_replace('/^([A-Z]{2,})(\d+[A-Z]*)$/', '$1 $2', $value);
            $value = preg_replace('/^([A-Z]{2,}(?:\s+[A-Z]{1,})?)[\s-]+(\d+[A-Z]*)$/', '$1 $2', $value);

            return trim((string)$value);
        };

        $looksNonCourse = static function ($value) {
            $upper = strtoupper(trim((string)$value));
            if ($upper === '') {
                return true;
            }

            foreach ([
                'YEAR',
                'STANDING',
                'INCOMING',
                '%',
                'ALL SUBJECT',
                'ALL MAJOR',
                'GRADUATING',
                'PROF ED',
                'TOTAL UNIT',
                'TOTAL UNITS',
                'HS ',
                'HIGH SCHOOL',
                'GWA',
                'AVERAGE GRADE',
            ] as $fragment) {
                if (strpos($upper, $fragment) !== false) {
                    return true;
                }
            }

            return false;
        };

        $prereq_string = trim((string)$prereq_string);
        if ($prereq_string === '' || strtoupper($prereq_string) === 'NONE') {
            return [];
        }

        $normalized = str_replace(["\r\n", "\r", "\n"], ', ', $prereq_string);
        $normalized = preg_replace('/\s*[;\/]\s*/', ', ', $normalized);
        $normalized = preg_replace('/\s+(?:AND|and)\s+/', ', ', $normalized);
        $normalized = preg_replace_callback(
            '/\b([A-Z]{2,}(?:\s+[A-Z]{1,})?[\s-]*\d+[A-Z]*)\s*(?:&|,)\s*((?:\d+[A-Z]*\s*(?:,|&)\s*)*\d+[A-Z]*)/i',
            static function ($m) use ($normalizeToken) {
                $first = $normalizeToken($m[1]);
                if ($first === '') {
                    return $m[0];
                }

                $prefix = preg_replace('/\s+\d+[A-Z]*$/', '', $first);
                $tailParts = preg_split('/\s*(?:,|&)\s*/', trim((string)$m[2]));
                $expanded = [$first];
                foreach ($tailParts as $part) {
                    $part = trim((string)$part);
                    if ($part === '') {
                        continue;
                    }
                    $expanded[] = $normalizeToken($prefix . ' ' . $part);
                }

                return implode(', ', array_filter($expanded));
            },
            $normalized
        );

        $segments = preg_split('/\s*,\s*/', $normalized);
        $valid_prereqs = [];
        $seen = [];
        $last_prefix = '';

        foreach ($segments as $segment) {
            $segment = trim((string)$segment);
            if ($segment === '' || $looksNonCourse($segment)) {
                continue;
            }

            $matches = [];
            preg_match_all('/\b([A-Z]{2,}(?:\s+[A-Z]{1,})?[\s-]*\d+[A-Z]*)\b/i', $segment, $matches);

            $codes = [];
            if (!empty($matches[1])) {
                foreach ($matches[1] as $match) {
                    $normalizedCode = $normalizeToken($match);
                    if ($normalizedCode !== '') {
                        $codes[] = $normalizedCode;
                    }
                }
            } elseif ($last_prefix !== '' && preg_match('/^\d+[A-Z]*$/i', $segment)) {
                $normalizedCode = $normalizeToken($last_prefix . ' ' . $segment);
                if ($normalizedCode !== '') {
                    $codes[] = $normalizedCode;
                }
            }

            foreach ($codes as $code) {
                if (!preg_match('/^([A-Z]{2,}(?:\s+[A-Z]{1,})?)\s+\d+[A-Z]*$/', $code, $pm)) {
                    continue;
                }

                $last_prefix = $pm[1];
                if (!isset($seen[$code])) {
                    $seen[$code] = true;
                    $valid_prereqs[] = $code;
                }
            }
        }

        return $valid_prereqs;
        
        // Normalize "&" separators to commas before splitting
        // Handle "GNED 11 & 12" → "GNED 11, GNED 12" by expanding abbreviated codes
        $prereq_string = preg_replace_callback('/(\b[A-Z]+\s+\d+\w*)\s*&\s*(\d+\w*)/', function($m) {
            // Extract prefix from first code (e.g., "GNED" from "GNED 11")
            $prefix = preg_replace('/\s+\d+\w*$/', '', $m[1]);
            return $m[1] . ', ' . $prefix . ' ' . $m[2];
        }, $prereq_string);
        
        // Split by comma and clean up
        $prereqs = array_map('trim', explode(',', $prereq_string));
        $valid_prereqs = [];
        
        foreach ($prereqs as $prereq) {
            if (empty($prereq)) continue;
            
            // Skip non-course-code prerequisites
            if (stripos($prereq, 'year') !== false || stripos($prereq, 'standing') !== false) continue;
            if (stripos($prereq, 'incoming') !== false) continue;
            if (stripos($prereq, '%') !== false) continue; // "70% Total Units taken"
            if (stripos($prereq, 'All ') === 0) continue;  // "All Subjects", "All Major Subjects"
            if (stripos($prereq, 'Graduating') !== false) continue;
            if (stripos($prereq, 'PROF Ed') !== false || stripos($prereq, 'PROF ed') !== false) continue;
            if (stripos($prereq, 'HS ') === 0) continue; // "HS Physics-Mechanics"
            if (stripos($prereq, 'Total') !== false) continue;
            
            // Handle abbreviated lists like "EDUC 75, 80, 85" → "80" and "85" are number-only
            // These get expanded by matching against the previous valid prereq's prefix
            if (preg_match('/^\d+\w*$/', $prereq) && !empty($valid_prereqs)) {
                // Get prefix from last valid prereq
                $last = end($valid_prereqs);
                if (preg_match('/^([A-Z]+\s+)\d/', $last, $pm)) {
                    $prereq = $pm[1] . $prereq;
                }
            }
            
            $valid_prereqs[] = $prereq;
        }
        
        return $valid_prereqs;
    }

    /**
     * Extract explicit year-standing constraints from raw prerequisite text.
     * Examples:
     * - "Incoming 4th yr."
     * - "4th Year Standing"
     */
    private function extractStandingConstraint($prereq_string) {
        $parseYear = static function ($value) {
            $value = strtolower(trim((string)$value));
            if ($value === '') {
                return 0;
            }

            if (preg_match('/(\d+)/', $value, $m)) {
                return (int)$m[1];
            }

            $wordMap = [
                'first' => 1,
                'second' => 2,
                'third' => 3,
                'fourth' => 4,
                'fifth' => 5,
            ];

            return $wordMap[$value] ?? 0;
        };

        $prereq_string = trim((string)$prereq_string);
        if ($prereq_string === '') {
            return null;
        }

        $normalized = preg_replace('/\s+/', ' ', $prereq_string);

        if (preg_match('/(?:for\s+)?incoming\s+(first|second|third|fourth|fifth|\d(?:st|nd|rd|th)?)\s*(?:yr|year)\b/i', $normalized, $m)) {
            $year = $parseYear($m[1]);
            if ($year > 0) {
                return [
                    'type' => 'incoming',
                    'year' => $year,
                ];
            }
        }

        if (preg_match('/(?:^|[^a-z])(first|second|third|fourth|fifth|\d(?:st|nd|rd|th)?)\s*(?:yr|year)\s*(?:standing|level)?\b/i', $normalized, $m)) {
            $year = $parseYear($m[1]);
            if ($year > 0) {
                return [
                    'type' => 'standing',
                    'year' => $year,
                ];
            }
        }

        if (preg_match('/\b(?:standing|level)\s+for\s+(first|second|third|fourth|fifth|\d(?:st|nd|rd|th)?)\s*(?:yr|year)\b/i', $normalized, $m)) {
            $year = $parseYear($m[1]);
            if ($year > 0) {
                return [
                    'type' => 'standing',
                    'year' => $year,
                ];
            }
        }

        if (preg_match('/\b(first|second|third|fourth|fifth)\s+year\s+standing\b/i', $normalized, $m)) {
            $year = $parseYear($m[1]);
            if ($year > 0) {
                return [
                    'type' => 'standing',
                    'year' => $year,
                ];
            }
        }

        if (preg_match('/incoming\s+(\d)(?:st|nd|rd|th)?\s*(?:yr|year)/i', $prereq_string, $m)) {
            return [
                'type' => 'incoming',
                'year' => (int)$m[1],
            ];
        }

        if (preg_match('/(\d)(?:st|nd|rd|th)?\s*year\s*standing/i', $prereq_string, $m)) {
            return [
                'type' => 'standing',
                'year' => (int)$m[1],
            ];
        }

        return null;
    }

    private function yearLabelToOrder($year_label) {
        if (preg_match('/(\d+)/', (string)$year_label, $m)) {
            return (int)$m[1];
        }

        $year_order = ['1st Yr' => 1, '2nd Yr' => 2, '3rd Yr' => 3, '4th Yr' => 4];
        return $year_order[$year_label] ?? 0;
    }

    private function semesterLabelToOrder($semester_label) {
        $sem_order = ['1st Sem' => 1, '2nd Sem' => 2, 'Mid Year' => 3];
        return $sem_order[$semester_label] ?? 0;
    }

    private function getCurriculumTermOrder($year_label, $semester_label) {
        $year_order = $this->yearLabelToOrder($year_label);
        $semester_order = $this->semesterLabelToOrder($semester_label);
        if ($year_order <= 0 || $semester_order <= 0) {
            return 999;
        }

        return (($year_order - 1) * 3) + $semester_order;
    }

    private function isNonCreditCourse(array $course) {
        $code = strtoupper(trim((string)($course['code'] ?? '')));
        $title = strtoupper(trim((string)($course['title'] ?? '')));

        if ($code === 'CVSU 101') {
            return true;
        }

        return strpos($title, 'NON-CREDIT') !== false || strpos($title, 'NON CREDIT') !== false;
    }

    private function getCountedCourseUnits(array $course) {
        if ($this->isNonCreditCourse($course)) {
            return 0;
        }

        return (float)($course['units'] ?? 0);
    }

    private function sumCountedCourseUnits(array $courses) {
        $total = 0.0;
        foreach ($courses as $course) {
            $total += $this->getCountedCourseUnits($course);
        }

        return $total;
    }

    /**
     * Enforce explicit year-standing constraints while still allowing
     * transferees/credited students to take any same-semester course whose
     * true blockers are already cleared.
     */
    private function standingConstraintSatisfied($course_code, $target_year, $target_semester) {
        $constraint = $this->standing_constraint_map[$course_code] ?? null;
        if (empty($constraint) || empty($constraint['year'])) {
            return true;
        }

        $term_year_order = $this->yearLabelToOrder($target_year);
        $required_year = (int)$constraint['year'];
        $constraint_type = (string)($constraint['type'] ?? 'standing');

        if ($constraint_type === 'incoming') {
            if ($term_year_order >= $required_year) {
                return true;
            }

            return $term_year_order === ($required_year - 1) && $target_semester === 'Mid Year';
        }

        return $term_year_order >= $required_year;
    }
    
    /**
     * CSP PHASE: Check if all prerequisites are satisfied for a course
     * Enforces prerequisites for ALL courses regardless if minor or major
     */
    private function prerequisitesSatisfied($course_code) {
        if (!isset($this->prerequisite_map[$course_code])) {
            return true;
        }
        
        $prereqs = $this->prerequisite_map[$course_code];
        
        if (empty($prereqs)) {
            return true;
        }
        
        // Normalize completed courses for comparison
        $normalized_completed = array_map('trim', $this->completed_courses);
        $normalized_completed = array_map('strtoupper', $normalized_completed);
        
        foreach ($prereqs as $prereq) {
            $prereq_normalized = strtoupper(trim($prereq));
            
            if (!in_array($prereq_normalized, $normalized_completed)) {
                if (!in_array($prereq, $this->completed_courses)) {
                    return false;
                }
            }
        }
        
        return true;
    }
    
    /**
     * GREEDY PHASE: Prioritize courses for optimal scheduling
    * Priority factors (enhanced with back and failed course rules):
     * 1. HIGHEST: Failed/INC/dropped courses that need retake (back subjects)
     * 2. HIGH: Lower-year back subjects prioritized over higher-year ones
     * 3. Courses with pre-requisites (regardless if minor or major) 
     * 4. Courses with more dependents (critical path)
     * 5. Courses matching current term's year
     * 6. Higher unit courses (maximize progress)
     * 7. Year level progression (lower years first)
     */
    private function prioritizeCourses($courses, $target_year = null, $simulated_completed = [], $target_semester = null) {
        $priority_scores = [];
        $target_year_order = $target_year !== null ? $this->yearLabelToOrder($target_year) : 0;
        $target_term_order = ($target_year !== null && $target_semester !== null)
            ? $this->getCurriculumTermOrder($target_year, $target_semester)
            : 0;
        $dependent_cache = [];
        
        foreach ($courses as $course_code => $course) {
            $score = 0;
            $course_year_order = $this->yearLabelToOrder($course['year'] ?? '');
            $term_order = $this->getCurriculumTermOrder($course['year'] ?? '', $course['semester'] ?? '');
            
            // HIGHEST PRIORITY: Back and failed courses that need retake
            if (!empty($course['needs_retake'])) {
                $score += 260; // Enormous bonus for retake courses
                
                // Within retakes, prioritize lower year levels first
                $year_retake_priority = [
                    '1st Yr' => 120,
                    '2nd Yr' => 90,
                    '3rd Yr' => 60,
                    '4th Yr' => 30
                ];
                $score += $year_retake_priority[$course['year']] ?? 0;
            }

            // Earlier curriculum terms should be cleared first to minimize cascading delay.
            $score += max(0, 135 - ($term_order * 8));

            // Prefer courses that belong to the current target term, but still
            // keep unresolved earlier-term backlog ahead of future-term fill.
            if ($target_term_order > 0 && $term_order < 999) {
                if ($term_order === $target_term_order) {
                    $score += 75;
                } elseif ($term_order < $target_term_order) {
                    $gap = $target_term_order - $term_order;
                    $score += !empty($course['needs_retake'])
                        ? max(28, 68 - ($gap * 6))
                        : max(12, 38 - ($gap * 5));
                } else {
                    $gap = $term_order - $target_term_order;
                    $score -= min(35, $gap * 10);
                }
            } elseif ($target_year_order > 0 && $course_year_order > 0 && $course_year_order < $target_year_order) {
                $score += !empty($course['needs_retake']) ? 80 : 45;
            }
            
            // HIGH PRIORITY: Courses that have prerequisites (they're harder to schedule)
            $prereqs = $this->prerequisite_map[$course_code] ?? [];
            if (!empty($prereqs)) {
                $score += 18; // Bonus for prerequisite-gated courses
                $score += min(18, count($prereqs) * 4);
            }
            
            // Factor: Count dependent chain (recursive - counts all downstream courses)
            if (!isset($dependent_cache[$course_code])) {
                $dependent_cache[$course_code] = $this->countDependentChain($course_code, $simulated_completed);
            }
            $dependent_count = $dependent_cache[$course_code];
            $score += $dependent_count * 15;
            
            // Factor: Bonus for matching current term's year
            if ($target_year !== null && $course['year'] === $target_year) {
                $score += 24;
            }

            // Flexible irregular fill can expose future-semester courses.
            // Favor the semester currently being planned before reaching ahead.
            if ($target_semester !== null && ($course['semester'] ?? '') === $target_semester) {
                $score += 12;
            }
            
            // Factor: Unit weight (more units = higher priority)
            $score += $course['units'] * 2;
            
            // Factor: Year level progression (complete lower years first)
            $year_priority = [
                '1st Yr' => 70,
                '2nd Yr' => 50,
                '3rd Yr' => 30,
                '4th Yr' => 10
            ];
            $score += $year_priority[$course['year']] ?? 0;
            
            // Factor: 1st sem before 2nd sem
            if ($course['semester'] === '1st Sem') {
                $score += 5;
            }
            
            // Valid cross-registration should help shorten completion time, especially for retakes.
            if (!empty($course['cross_registered'])) {
                $score += !empty($course['needs_retake']) ? 28 : 6;
            }
            
            $priority_scores[$course_code] = [
                'score' => $score,
                'term_order' => $term_order,
                'course_code' => $course_code,
            ];
        }
        
        uasort($priority_scores, static function ($a, $b) {
            if (($a['score'] ?? 0) !== ($b['score'] ?? 0)) {
                return ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
            }

            if (($a['term_order'] ?? 999) !== ($b['term_order'] ?? 999)) {
                return ($a['term_order'] ?? 999) <=> ($b['term_order'] ?? 999);
            }

            return strcmp((string)($a['course_code'] ?? ''), (string)($b['course_code'] ?? ''));
        });
        
        $prioritized = [];
        foreach ($priority_scores as $course_code => $meta) {
            $prioritized[$course_code] = $courses[$course_code];
        }
        
        return $prioritized;
    }
    
    /**
     * Count ALL courses that depend on this course (recursive chain)
     * This properly identifies critical path courses
     * Uses simulated_completed to reflect courses already planned in earlier terms
     */
    private function countDependentChain($course_code, $simulated_completed = [], &$visited = []) {
        if (isset($visited[$course_code])) {
            return 0;
        }
        $visited[$course_code] = true;
        
        $count = 0;
        foreach ($this->prerequisite_map as $other_course => $prereqs) {
            if (in_array($other_course, $simulated_completed)) {
                continue;
            }
            
            if (in_array($course_code, $prereqs)) {
                $count++;
                $count += $this->countDependentChain($other_course, $simulated_completed, $visited);
            }
        }
        
        return $count;
    }
    
    /**
     * Determine the max units allowed for a term based on curriculum and retention policy
     * Uses curriculum total units per year/semester as the baseline max.
     * Retention policy may reduce the limit further.
     */
    private function getMaxUnitsForTerm($retention_status, $year = null, $semester = null) {
        // Get curriculum-based max units for this term
        $curriculum_max = 21; // fallback for extra terms beyond curriculum
        if ($year !== null && $semester !== null) {
            $key = $year . '|' . $semester;
            if (isset($this->term_max_units[$key])) {
                $curriculum_max = $this->term_max_units[$key];
            }
        }
        
        // Apply retention policy limits
        $retention_limit = $curriculum_max;
        switch ($retention_status) {
            case 'Probation':
            case 'Disqualification':
                $retention_limit = 15;
                break;
            case 'Warning':
                $statuses = array_values($this->retention_history);
                $len = count($statuses);
                if ($len >= 2 && $statuses[$len - 1] === 'Warning' && $statuses[$len - 2] === 'Warning') {
                    $retention_limit = 15;
                }
                break;
        }
        
        return min($curriculum_max, $retention_limit);
    }

    /**
     * For extra terms beyond the defined curriculum years, inherit the unit cap
     * from the latest curriculum term that uses the same semester label.
     * This keeps Mid Year terms constrained to the actual midyear load.
     */
    private function getReferenceTermForSemester($semester) {
        $terms = array_reverse($this->getOrderedCurriculumTerms());
        foreach ($terms as $term) {
            if (($term['semester'] ?? '') !== $semester) {
                continue;
            }

            $key = ($term['year'] ?? '') . '|' . ($term['semester'] ?? '');
            if (!isset($this->term_max_units[$key])) {
                continue;
            }

            return [
                'year' => $term['year'],
                'semester' => $term['semester'],
                'max_units' => $this->term_max_units[$key],
            ];
        }

        return null;
    }
    
    /**
     * Check if a term should be skipped due to Disqualification
     * Disqualified students are ineligible to enroll for one semester
     */
    private function shouldSkipTerm($term_index, &$skip_tracker) {
        if ($this->retention_status === 'Disqualification') {
            if (!isset($skip_tracker['disq_skip_done'])) {
                $skip_tracker['disq_skip_done'] = true;
                return true; // Skip this semester
            }
        }
        return false;
    }
    
    /**
     * Check if study plan generation should stop completely
     * Two disqualification statuses = no longer eligible
     */
    private function shouldStopGeneration() {
        if (!empty($this->policy_gate_status['applies']) && empty($this->policy_gate_status['eligible'])) {
            return true;
        }
        // Stop if disqualified twice
        if (isset($this->disqualification_count) && $this->disqualification_count >= 2) {
            return true;
        }
        // Stop if any course has been failed 3 or more times
        if (!empty($this->thrice_failed_courses)) {
            return true;
        }
        return false;
    }

    private function applyNearGraduationForceAdd(&$study_plan, &$simulated_completed, &$simulated_all_courses, $current_term) {
        $remaining = array_filter($simulated_all_courses, function($course) {
            return !$course['completed'];
        });

        if (empty($remaining) || count($remaining) > 3) {
            return;
        }

        // Enable force-add when the student is already in late-stage planning,
        // either by current anchor term or by remaining courses being 4th-year terms.
        $current_term_order = $this->getCurriculumTermOrder($current_term['year'] ?? '', $current_term['semester'] ?? '');
        $late_anchor = $current_term_order >= 10 && $current_term_order < 999;

        $min_remaining_order = 999;
        foreach ($remaining as $course) {
            $order = $this->getCurriculumTermOrder($course['year'] ?? '', $course['semester'] ?? '');
            if ($order > 0 && $order < $min_remaining_order) {
                $min_remaining_order = $order;
            }
        }
        $late_remaining = $min_remaining_order >= 10 && $min_remaining_order < 999;

        if (!$late_anchor && !$late_remaining) {
            return;
        }

        $target_index = null;
        foreach ($study_plan as $index => $term) {
            if (($term['year'] ?? '') === '4th Yr' && ($term['semester'] ?? '') === '2nd Sem') {
                $target_index = $index;
                break;
            }
        }

        if ($target_index === null) {
            $study_plan[] = [
                'year' => '4th Yr',
                'semester' => '2nd Sem',
                'courses' => [],
                'total_units' => 0,
                'max_units' => $this->getMaxUnitsForTerm('None', '4th Yr', '2nd Sem'),
                'retention_status' => 'None',
                'retake_count' => 0,
                'cross_reg_count' => 0,
                'forced_add_count' => 0,
                'skipped' => false
            ];
            $target_index = count($study_plan) - 1;
        }

        $forced_courses = $this->prioritizeCourses($remaining, '4th Yr', $simulated_completed, '2nd Sem');
        $added_count = 0;

        foreach ($forced_courses as $course_code => $course) {
            if (isset($study_plan[$target_index]['courses'][$course_code])) {
                continue;
            }

            $course['forced_added'] = true;
            $course['forced_reason'] = 'Forced Added - Near Graduation';
            $study_plan[$target_index]['courses'][$course_code] = $course;
            $study_plan[$target_index]['total_units'] += $this->getCountedCourseUnits($course);
            $study_plan[$target_index]['forced_add_count'] = ($study_plan[$target_index]['forced_add_count'] ?? 0) + 1;

            $simulated_completed[] = $course_code;
            $simulated_all_courses[$course_code]['completed'] = true;
            $simulated_all_courses[$course_code]['forced_added'] = true;
            $simulated_all_courses[$course_code]['forced_reason'] = 'Forced Added - Near Graduation';
            $added_count++;
        }

        if ($added_count > 0) {
            if (!isset($study_plan[$target_index]['retake_count'])) {
                $study_plan[$target_index]['retake_count'] = 0;
            }
            if (!isset($study_plan[$target_index]['cross_reg_count'])) {
                $study_plan[$target_index]['cross_reg_count'] = 0;
            }
        }
    }

    /**
     * Students who are still following a clean, regular progression should
     * see the curriculum exactly as defined, without greedy reordering,
     * cross-registration substitutions, or extra projected semesters.
     *
     * This applies to:
     * - brand-new students with no academic history yet
     * - students with only passing history and no active back subjects
     *
     * Irregular scenarios such as active failed/INC/dropped courses,
     * transferee/shift gating, or retention issues should continue to use the
     * optimization engine.
     */
    private function shouldUseExactCurriculumPlan() {
        if (!$this->hasValidatedAcademicHistory()) {
            return true;
        }

        $active_failed = array_values(array_filter(array_diff($this->failed_courses, $this->completed_courses)));
        $active_inc = array_values(array_filter(array_diff($this->inc_courses, $this->completed_courses)));
        $active_dropped = array_values(array_filter(array_diff($this->dropped_courses, $this->completed_courses)));

        if (!empty($active_failed) || !empty($active_inc) || !empty($active_dropped)) {
            return false;
        }

        if (!empty($this->policy_gate_status['applies'])) {
            return false;
        }

        if (!empty($this->thrice_failed_courses)) {
            return false;
        }

        if ($this->retention_status !== 'None' && $this->retention_status !== '') {
            return false;
        }

        return true;
    }

    /**
     * Cross-registration and other irregular-plan optimizations should only
     * activate after the student has at least one validated checklist attempt.
     * Brand-new students, or records with no approved/credited attempts yet,
     * should continue to mirror the curriculum exactly.
     */
    private function hasValidatedAcademicHistory() {
        if (!empty($this->semester_grade_history)) {
            return true;
        }

        if (!empty($this->completed_courses)) {
            return true;
        }

        if (!empty($this->failed_courses) || !empty($this->inc_courses) || !empty($this->dropped_courses)) {
            return true;
        }

        return false;
    }

    /**
     * Transferees and shifting students should be able to fill an irregular
     * term up to the target curriculum cap using any truly eligible remaining
     * courses, not only courses from the currently labeled semester bucket.
     */
    private function shouldUseFlexibleIrregularFill() {
        return $this->hasValidatedAcademicHistory() && !empty($this->policy_gate_status['applies']);
    }

    /**
     * Build a term-by-term plan that mirrors the student's curriculum/checklist
     * exactly, preserving the original year and semester of each course.
     */
    private function buildExactCurriculumPlan() {
        $terms = $this->getOrderedCurriculumTerms();

        $study_plan = [];
        foreach ($terms as $term) {
            $term_courses = [];
            foreach ($this->all_courses as $course_code => $course) {
                if ($course['completed']) {
                    continue;
                }
                if (($course['year'] ?? '') !== $term['year'] || ($course['semester'] ?? '') !== $term['semester']) {
                    continue;
                }
                $term_courses[$course_code] = $course;
            }

            if (empty($term_courses)) {
                continue;
            }

            $study_plan[] = [
                'year' => $term['year'],
                'semester' => $term['semester'],
                'courses' => $term_courses,
                'total_units' => $this->sumCountedCourseUnits($term_courses),
                'max_units' => $this->getMaxUnitsForTerm('None', $term['year'], $term['semester']),
                'retention_status' => 'None',
                'retake_count' => 0,
                'cross_reg_count' => 0,
                'forced_add_count' => 0,
                'skipped' => false
            ];
        }

        return $study_plan;
    }
    
    /**
     * Generate complete study plan using CSP + Greedy Algorithm
     * Enhanced with:
     * - Retention policy enforcement (unit limits, semester skips, stop generation)
    * - Back and failed course prioritization (lower years first)
     * - Cross-registration support
     * - No overloading constraint
     */
    public function generateOptimizedPlan() {
        // Check if student has been disqualified twice - stop generation
        if ($this->shouldStopGeneration()) {
            return [];
        }

        if ($this->shouldUseExactCurriculumPlan()) {
            return $this->buildExactCurriculumPlan();
        }
        
        $study_plan = [];
        
        // IMPORTANT: Use copies for simulation, don't modify original data
        $simulated_completed = $this->completed_courses;
        $simulated_all_courses = $this->all_courses;
        
        // Determine starting year/semester based on completed courses
        $current_term = $this->determineCurrentTerm();

        $terms = $this->getOrderedCurriculumTerms();
        if (empty($terms)) {
            return [];
        }
        
        // Start from current term
        $start_index = 0;
        foreach ($terms as $index => $term) {
            if ($term['year'] === $current_term['year'] && $term['semester'] === $current_term['semester']) {
                $start_index = $index;
                break;
            }
        }
        
        // Retention policy: Determine initial retention status
        $initial_max = $this->getMaxUnitsForTerm($this->retention_status, $terms[$start_index]['year'] ?? '1st Yr', $terms[$start_index]['semester'] ?? '1st Sem');
        $retention_limited = ($this->retention_status !== 'None' && $this->retention_status !== '' && $initial_max < ($this->term_max_units[($terms[$start_index]['year'] ?? '1st Yr') . '|' . ($terms[$start_index]['semester'] ?? '1st Sem')] ?? 21));
        $retention_terms_remaining = $retention_limited ? 2 : 0;
        $skip_tracker = [];
        $is_first_term = true;
        
        // Generate plan for remaining terms
        for ($i = $start_index; $i < count($terms); $i++) {
            $term = $terms[$i];
            
            // RETENTION: Check if this term should be skipped (Disqualification)
            if ($is_first_term && $this->shouldSkipTerm($i, $skip_tracker)) {
                $study_plan[] = [
                    'year' => $term['year'],
                    'semester' => $term['semester'],
                    'courses' => [],
                    'total_units' => 0,
                    'skipped' => true,
                    'skip_reason' => 'Disqualification - Ineligible to enroll this semester'
                ];
                $is_first_term = false;
                // After skip, next terms are retention-limited
                $retention_terms_remaining = 2;
                continue;
            }
            $is_first_term = false;
            
            // Determine max units for this specific term from curriculum
            $max_units = $this->getMaxUnitsForTerm(
                ($retention_terms_remaining > 0) ? $this->retention_status : 'None',
                $term['year'],
                $term['semester']
            );
            
            // CSP PHASE: Get available courses for this semester
            $available = $this->applyConstraintsForSimulation($term['semester'], $simulated_completed, $simulated_all_courses);

            // Keep higher-year courses that already satisfy prerequisites.
            // This is important for transferees and students with credited subjects:
            // once lower-year blockers are cleared, the plan should fill the term with
            // any same-semester course that is truly available instead of leaving it underloaded.
            $course_year_order = ['1st Yr' => 1, '2nd Yr' => 2, '3rd Yr' => 3, '4th Yr' => 4];
            $term_year_order = $course_year_order[$term['year']] ?? 4;

            // Include backlog courses from prior years/semesters, but only if their original semester matches the current semester,
            // or if cross-registration is possible for this semester.
            $backlog = $this->applyConstraintsForSimulation(null, $simulated_completed, $simulated_all_courses);
            foreach ($backlog as $code => $course) {
                $course_year = $course_year_order[$course['year']] ?? 1;
                // Only allow if course is from a lower year and its semester matches the current semester
                if ($course_year < $term_year_order && $course['semester'] === $term['semester']) {
                    $available[$code] = $course;
                } else if ($course_year < $term_year_order) {
                    $cross_course = $this->findCrossRegistrationOffering($code, $term['semester'], $course);
                    // Only cross-register if the course is offered in the current semester by another program
                    if ($cross_course !== null) {
                        // Check prerequisites (case-insensitive)
                        $prereqs = $this->prerequisite_map[$code] ?? [];
                        $prereqs_met = true;
                        $completed_upper = array_map('strtoupper', $simulated_completed);
                        foreach ($prereqs as $prereq) {
                            if (!in_array(strtoupper($prereq), $completed_upper)) {
                                $prereqs_met = false;
                                break;
                            }
                        }
                        if ($prereqs_met) {
                            $available[$code] = $simulated_all_courses[$code];
                            $available[$code]['cross_registered'] = true;
                            $available[$code]['cross_reg_source_program'] = $cross_course['cross_reg_source_program'] ?? ($cross_course['programs'] ?? '');
                        }
                    }
                }
            }
            
            // CROSS-REGISTRATION: Check for courses available in other programs
            // Only if the course is in the student's curriculum but not available in their program this semester
            $remaining_course_codes = array_keys(array_filter($simulated_all_courses, function($c) {
                return !$c['completed'];
            }));
            
            foreach ($remaining_course_codes as $needed_code) {
                // If course is not already available and not yet planned
                if (!isset($available[$needed_code])) {
                    $cross_course = $this->findCrossRegistrationOffering($needed_code, $term['semester'], $simulated_all_courses[$needed_code] ?? []);
                    // Only cross-register if the course is offered in the current semester by another program
                    if ($cross_course !== null) {
                        // Check prerequisites (case-insensitive)
                        $prereqs = $this->prerequisite_map[$needed_code] ?? [];
                        $prereqs_met = true;
                        $completed_upper = array_map('strtoupper', $simulated_completed);
                        foreach ($prereqs as $prereq) {
                            if (!in_array(strtoupper($prereq), $completed_upper)) {
                                $prereqs_met = false;
                                break;
                            }
                        }
                        if ($prereqs_met) {
                            $available[$needed_code] = $simulated_all_courses[$needed_code];
                            $available[$needed_code]['cross_registered'] = true;
                            $available[$needed_code]['cross_reg_source_program'] = $cross_course['cross_reg_source_program'] ?? ($cross_course['programs'] ?? '');
                        }
                    }
                }
            }

            foreach (array_keys($available) as $code) {
                if (!$this->standingConstraintSatisfied($code, $term['year'], $term['semester'])) {
                    unset($available[$code]);
                }
            }

            if ($this->shouldUseFlexibleIrregularFill()) {
                foreach ($simulated_all_courses as $code => $course) {
                    if (!empty($course['completed']) || isset($available[$code])) {
                        continue;
                    }
                    if (!$this->standingConstraintSatisfied($code, $term['year'], $term['semester'])) {
                        continue;
                    }
                    if (!$this->prerequisitesSatisfiedForCompletedSet($code, $simulated_completed)) {
                        continue;
                    }
                    $available[$code] = $course;
                }
            }
              
            if (empty($available)) {
                // Check if there are any remaining courses at all
                $remaining_check = array_filter($simulated_all_courses, function($c) {
                    return !$c['completed'];
                });
                if (empty($remaining_check)) {
                    break; // All courses completed
                }
                continue; // No courses available this semester, try next
            }
            
            // GREEDY PHASE: Build optimal course selection respecting unit limits
            $term_courses = $this->buildTermPlanFromAvailable(
                $available,
                $max_units,
                $term['year'],
                $simulated_completed,
                false,
                $term['semester']
            );
            
            if (!empty($term_courses)) {
                // Count retake courses in this term
                $retake_count = 0;
                $cross_reg_count = 0;
                foreach ($term_courses as $tc) {
                    if (!empty($tc['needs_retake'])) $retake_count++;
                    if (!empty($tc['cross_registered'])) $cross_reg_count++;
                }
                
                $study_plan[] = [
                    'year' => $term['year'],
                    'semester' => $term['semester'],
                    'courses' => $term_courses,
                    'total_units' => $this->sumCountedCourseUnits($term_courses),
                    'max_units' => $max_units,
                    'retention_status' => ($max_units < 21) ? $this->retention_status : 'None',
                    'retake_count' => $retake_count,
                    'cross_reg_count' => $cross_reg_count,
                    'forced_add_count' => 0,
                    'skipped' => false
                ];
                
                // Mark as completed in simulation
                foreach ($term_courses as $course_code => $course) {
                    $simulated_completed[] = $course_code;
                    $simulated_all_courses[$course_code]['completed'] = true;
                }
            }
            
            // Track retention-limited terms countdown
            if ($retention_terms_remaining > 0) {
                $retention_terms_remaining--;
            }
            
            // Check if all courses are completed in simulation
            $remaining = array_filter($simulated_all_courses, function($course) {
                return !$course['completed'];
            });
            
            if (empty($remaining)) {
                break;
            }
        }
        
        // If there are still remaining courses after all standard terms,
        // force-add the final 1 to 3 courses into 4th Yr / 2nd Sem for
        // students who are already entering that term so they do not
        // extend one more semester just for a very small remainder.
        $this->applyNearGraduationForceAdd($study_plan, $simulated_completed, $simulated_all_courses, $current_term);

        // If there are still remaining courses after all standard terms,
        // add extra terms to accommodate them
        $remaining = array_filter($simulated_all_courses, function($course) {
            return !$course['completed'];
        });
        
        $extra_term_count = 0;
        $consecutive_empty = 0;
        $sem_cycle = $this->getExtraTermSemesterCycle();
        $sem_cycle_count = count($sem_cycle);
        while (!empty($remaining) && $extra_term_count < 9 && $consecutive_empty < 3 && $sem_cycle_count > 0) {
            $semester = $sem_cycle[$extra_term_count % $sem_cycle_count];
            $extra_year_num = 5 + intval($extra_term_count / $sem_cycle_count);
            $year_label = $extra_year_num . 'th Yr';
            $reference_term = $this->getReferenceTermForSemester($semester);
            $extra_max_units = $reference_term !== null
                ? $this->getMaxUnitsForTerm('None', $reference_term['year'], $reference_term['semester'])
                : (!empty($this->term_max_units) ? max($this->term_max_units) : 21);
            
            $available = $this->applyConstraintsForSimulation($semester, $simulated_completed, $simulated_all_courses);
            
            // Include backlog courses from all prior semesters
            $backlog = $this->applyConstraintsForSimulation(null, $simulated_completed, $simulated_all_courses);
            foreach ($backlog as $code => $course) {
                if (!isset($available[$code])) {
                    $available[$code] = $course;
                }
            }
            
            // Cross-registration for extra terms
            $remaining_codes = array_keys(array_filter($simulated_all_courses, function($c) {
                return !$c['completed'];
            }));
            foreach ($remaining_codes as $needed_code) {
                if (!isset($available[$needed_code])) {
                    $cross_course = $this->findCrossRegistrationOffering($needed_code, $semester, $simulated_all_courses[$needed_code] ?? []);
                    if ($cross_course !== null) {
                        $prereqs = $this->prerequisite_map[$needed_code] ?? [];
                        $prereqs_met = true;
                        $completed_upper = array_map('strtoupper', $simulated_completed);
                        foreach ($prereqs as $prereq) {
                            if (!in_array(strtoupper($prereq), $completed_upper)) {
                                $prereqs_met = false;
                                break;
                            }
                        }
                        if ($prereqs_met) {
                            $available[$needed_code] = $simulated_all_courses[$needed_code];
                            $available[$needed_code]['cross_registered'] = true;
                            $available[$needed_code]['cross_reg_source_program'] = $cross_course['cross_reg_source_program'] ?? ($cross_course['programs'] ?? '');
                        }
                    }
                }
            }

            foreach (array_keys($available) as $code) {
                if (!$this->standingConstraintSatisfied($code, $year_label, $semester)) {
                    unset($available[$code]);
                }
                if (isset($available[$code]) && !$this->prerequisitesSatisfiedForCompletedSet($code, $simulated_completed)) {
                    unset($available[$code]);
                }
            }
            
            if (empty($available)) {
                $consecutive_empty++;
                $extra_term_count++;
                continue;
            }
            
            $term_courses = $this->buildTermPlanFromAvailable(
                $available,
                $extra_max_units,
                $year_label,
                $simulated_completed,
                false,
                $semester
            );
            if (empty($term_courses)) {
                $consecutive_empty++;
                $extra_term_count++;
                continue;
            }
            $consecutive_empty = 0;
            
            // Count retake and cross-reg courses
            $retake_count = 0;
            $cross_reg_count = 0;
            foreach ($term_courses as $tc) {
                if (!empty($tc['needs_retake'])) $retake_count++;
                if (!empty($tc['cross_registered'])) $cross_reg_count++;
            }
            
            $study_plan[] = [
                'year' => $year_label,
                'semester' => $semester,
                'courses' => $term_courses,
                'total_units' => array_sum(array_column($term_courses, 'units')),
                'max_units' => $extra_max_units,
                'retention_status' => 'None',
                'retake_count' => $retake_count,
                'cross_reg_count' => $cross_reg_count,
                'forced_add_count' => 0,
                'skipped' => false
            ];
            
            foreach ($term_courses as $course_code => $course) {
                $simulated_completed[] = $course_code;
                $simulated_all_courses[$course_code]['completed'] = true;
            }
            
            $remaining = array_filter($simulated_all_courses, function($course) {
                return !$course['completed'];
            });
            $extra_term_count++;
        }
        
        return $study_plan;
    }
    
    /**
     * Apply constraints for simulation (doesn't modify class properties)
     */
    private function applyConstraintsForSimulation($target_semester, $simulated_completed, $simulated_all_courses) {
        $available = [];
        
        foreach ($simulated_all_courses as $code => $course) {
            if ($course['completed']) {
                continue;
            }
            
            // Check semester constraint
            if ($target_semester !== null && $course['semester'] !== $target_semester) {
                continue;
            }
            
            // Check prerequisites using simulated completed courses (case-insensitive)
            $prereqs = $this->prerequisite_map[$code] ?? [];
            $prereqs_met = true;
            $completed_upper = array_map('strtoupper', $simulated_completed);
            foreach ($prereqs as $prereq) {
                if (!in_array(strtoupper($prereq), $completed_upper)) {
                    $prereqs_met = false;
                    break;
                }
            }
            
            if ($prereqs_met) {
                $available[$code] = $course;
            }
        }
        
        return $available;
    }

    private function prerequisitesSatisfiedForCompletedSet($course_code, array $completedSet) {
        if (!isset($this->prerequisite_map[$course_code])) {
            return true;
        }

        $prereqs = $this->prerequisite_map[$course_code];
        if (empty($prereqs)) {
            return true;
        }

        $normalized_completed = array_map(
            static fn($value) => strtoupper(trim((string)$value)),
            $completedSet
        );

        foreach ($prereqs as $prereq) {
            if (!in_array(strtoupper(trim((string)$prereq)), $normalized_completed, true)) {
                return false;
            }
        }

        return true;
    }
    
    /**
     * Build term plan from available courses (doesn't modify class properties)
     * Uses prioritizeCourses with target_year for better scheduling
     * Enforces NO OVERLOADING constraint (respects max_units strictly)
     */
    private function buildTermPlanFromAvailable($available, $max_units, $target_year = null, $simulated_completed = [], $allowConcurrentPrereqChaining = false, $target_semester = null) {
        // Use the greedy prioritization algorithm
        if (!$allowConcurrentPrereqChaining) {
            $prioritized = $this->prioritizeCourses($available, $target_year, $simulated_completed, $target_semester);

            $selected = [];
            $total_units = 0;

            foreach ($prioritized as $code => $course) {
                // NO OVERLOADING: Strict unit limit enforcement
                $course_units = $this->getCountedCourseUnits($course);
                if ($total_units + $course_units <= $max_units) {
                    $selected[$code] = $course;
                    $total_units += $course_units;
                }
            }

            return $selected;
        }

        $selected = [];
        $total_units = 0;
        $remaining = $available;
        $progress = true;

        while ($progress && !empty($remaining)) {
            $progress = false;
            $effectiveCompleted = array_values(array_unique(array_merge($simulated_completed, array_keys($selected))));
            $prioritized = $this->prioritizeCourses($remaining, $target_year, $effectiveCompleted, $target_semester);

            foreach ($prioritized as $code => $course) {
                $course_units = $this->getCountedCourseUnits($course);
                if ($total_units + $course_units > $max_units) {
                    continue;
                }

                if (!$this->prerequisitesSatisfiedForCompletedSet($code, $effectiveCompleted)) {
                    continue;
                }

                $selected[$code] = $course;
                $total_units += $course_units;
                unset($remaining[$code]);
                $progress = true;
                break;
            }
        }

        return $selected;
    }
    
    /**
     * A semester is completed only when all curriculum courses in that term are passed
     * and there are no active back subjects (failed/INC/dropped not yet passed) in that term.
     */
    private function isSemesterCompleted($year, $semester) {
        $term_courses = [];
        foreach ($this->all_courses as $code => $course) {
            if ($course['year'] === $year && $course['semester'] === $semester) {
                $term_courses[$code] = $course;
            }
        }

        // If no curriculum rows exist for the term, treat it as complete.
        if (empty($term_courses)) {
            return true;
        }

        // Active back subjects are failed/INC/dropped courses that are not yet passed.
        $active_failed = array_flip(array_map('strtoupper', array_diff($this->failed_courses, $this->completed_courses)));
        $active_inc = array_flip(array_map('strtoupper', array_diff($this->inc_courses, $this->completed_courses)));
        $active_dropped = array_flip(array_map('strtoupper', array_diff($this->dropped_courses, $this->completed_courses)));

        foreach ($term_courses as $code => $course) {
            if (empty($course['completed'])) {
                return false;
            }

            $upper_code = strtoupper($code);
            if (isset($active_failed[$upper_code]) || isset($active_inc[$upper_code]) || isset($active_dropped[$upper_code])) {
                return false;
            }
        }

        return true;
    }

    private function getOrderedCurriculumTerms() {
        $terms = [];
        $seen = [];

        foreach ($this->all_courses as $course) {
            $year = trim((string)($course['year'] ?? ''));
            $semester = trim((string)($course['semester'] ?? ''));
            if ($year === '' || $semester === '') {
                continue;
            }

            $key = $year . '|' . $semester;
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $terms[] = ['year' => $year, 'semester' => $semester];
        }

        if (empty($terms)) {
            foreach (array_keys($this->term_max_units) as $termKey) {
                $parts = explode('|', (string)$termKey, 2);
                if (count($parts) !== 2) {
                    continue;
                }

                $year = trim((string)$parts[0]);
                $semester = trim((string)$parts[1]);
                if ($year === '' || $semester === '') {
                    continue;
                }

                $key = $year . '|' . $semester;
                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $terms[] = ['year' => $year, 'semester' => $semester];
            }
        }

        if (empty($terms)) {
            return [
                ['year' => '1st Yr', 'semester' => '1st Sem'],
                ['year' => '1st Yr', 'semester' => '2nd Sem'],
                ['year' => '1st Yr', 'semester' => 'Mid Year'],
                ['year' => '2nd Yr', 'semester' => '1st Sem'],
                ['year' => '2nd Yr', 'semester' => '2nd Sem'],
                ['year' => '2nd Yr', 'semester' => 'Mid Year'],
                ['year' => '3rd Yr', 'semester' => '1st Sem'],
                ['year' => '3rd Yr', 'semester' => '2nd Sem'],
                ['year' => '3rd Yr', 'semester' => 'Mid Year'],
                ['year' => '4th Yr', 'semester' => '1st Sem'],
                ['year' => '4th Yr', 'semester' => '2nd Sem'],
                ['year' => '4th Yr', 'semester' => 'Mid Year'],
            ];
        }

        usort($terms, function ($a, $b) {
            $yearCompare = $this->yearLabelToOrder($a['year']) <=> $this->yearLabelToOrder($b['year']);
            if ($yearCompare !== 0) {
                return $yearCompare;
            }

            return $this->semesterLabelToOrder($a['semester']) <=> $this->semesterLabelToOrder($b['semester']);
        });

        return $terms;
    }

    private function getExtraTermSemesterCycle() {
        $terms = $this->getOrderedCurriculumTerms();
        if (empty($terms)) {
            return ['1st Sem', '2nd Sem', 'Mid Year'];
        }

        $lastYear = (string)($terms[count($terms) - 1]['year'] ?? '');
        $cycle = [];

        foreach ($terms as $term) {
            if ((string)($term['year'] ?? '') !== $lastYear) {
                continue;
            }

            $semester = trim((string)($term['semester'] ?? ''));
            if ($semester === '' || in_array($semester, $cycle, true)) {
                continue;
            }

            $cycle[] = $semester;
        }

        if (!empty($cycle)) {
            return $cycle;
        }

        foreach ($terms as $term) {
            $semester = trim((string)($term['semester'] ?? ''));
            if ($semester === '' || in_array($semester, $cycle, true)) {
                continue;
            }

            $cycle[] = $semester;
        }

        return !empty($cycle) ? $cycle : ['1st Sem', '2nd Sem', 'Mid Year'];
    }

    private function termHasActiveNonRetakeIncompleteCourses($year, $semester) {
        foreach ($this->all_courses as $course) {
            if (($course['year'] ?? '') !== $year || ($course['semester'] ?? '') !== $semester) {
                continue;
            }

            if (!empty($course['completed'])) {
                continue;
            }

            if (empty($course['needs_retake'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine the anchor term for future planning.
     *
     * For irregular students with actual checklist history, the plan should
     * continue from the next chronological term after the latest attempted
     * semester, then re-insert lower-year back subjects into future eligible
     * terms. This avoids displaying failed subjects as if they remain in an
     * already elapsed historical semester.
     *
     * If no history exists yet, fall back to the first incomplete curriculum
     * term so brand-new cases still start correctly.
     */
    private function determineCurrentTerm() {
        $terms = $this->getOrderedCurriculumTerms();
        $termIndexMap = [];
        foreach ($terms as $index => $term) {
            $termIndexMap[$term['year'] . '|' . $term['semester']] = $index;
        }

        $firstIncompleteIndex = -1;
        foreach ($terms as $index => $term) {
            if (!$this->isSemesterCompleted($term['year'], $term['semester'])) {
                $firstIncompleteIndex = $index;
                break;
            }
        }

        if ($firstIncompleteIndex < 0) {
            return $terms[count($terms) - 1];
        }

        $latestHistoryIndex = -1;
        foreach ($this->semester_grade_history as $termKey => $termData) {
            if (!isset($termIndexMap[$termKey])) {
                continue;
            }

            $latestHistoryIndex = max($latestHistoryIndex, $termIndexMap[$termKey]);
        }

        $firstIncompleteTerm = $terms[$firstIncompleteIndex];

        // Keep the earliest incomplete term when it still has normal untaken
        // subjects. Only skip forward when the old term is incomplete solely
        // because of retakes/back subjects already carried into future planning.
        if ($this->termHasActiveNonRetakeIncompleteCourses($firstIncompleteTerm['year'], $firstIncompleteTerm['semester'])) {
            return $firstIncompleteTerm;
        }

        if ($latestHistoryIndex >= 0 && $firstIncompleteIndex <= $latestHistoryIndex) {
            $nextIndex = min($latestHistoryIndex + 1, count($terms) - 1);
            return $terms[$nextIndex];
        }

        return $firstIncompleteTerm;
    }
    
    /**
     * Get student's completion statistics
     * Enhanced with retention and back-subject info
     */
    public function getCompletionStats() {
        $valid_courses = array_filter($this->all_courses, function($course) {
            return !empty($course['code']) && $course['units'] > 0;
        });

        $valid_course_codes = [];
        foreach ($valid_courses as $course) {
            $code = strtoupper(trim((string)($course['code'] ?? '')));
            if ($code !== '') {
                $valid_course_codes[$code] = true;
            }
        }

        $total_courses = count($valid_courses);

        $completed_count = 0;
        $completed_units = 0;
        foreach ($valid_courses as $course) {
            if (!empty($course['completed'])) {
                $completed_count++;
                $completed_units += $this->getCountedCourseUnits($course);
            }
        }

        $remaining_count = max(0, $total_courses - $completed_count);

        // Keep dashboard counters scoped to the student's current curriculum.
        $normalized_completed = array_flip(array_map(
            static fn($code) => strtoupper(trim((string)$code)),
            $this->completed_courses
        ));

        $active_failed = array_values(array_filter($this->failed_courses, function ($code) use ($normalized_completed, $valid_course_codes) {
            $normalized = strtoupper(trim((string)$code));
            return $normalized !== '' && isset($valid_course_codes[$normalized]) && !isset($normalized_completed[$normalized]);
        }));
        $active_inc = array_values(array_filter($this->inc_courses, function ($code) use ($normalized_completed, $valid_course_codes) {
            $normalized = strtoupper(trim((string)$code));
            return $normalized !== '' && isset($valid_course_codes[$normalized]) && !isset($normalized_completed[$normalized]);
        }));
        $active_dropped = array_values(array_filter($this->dropped_courses, function ($code) use ($normalized_completed, $valid_course_codes) {
            $normalized = strtoupper(trim((string)$code));
            return $normalized !== '' && isset($valid_course_codes[$normalized]) && !isset($normalized_completed[$normalized]);
        }));

        $failed_count = count($active_failed);
        $inc_count = count($active_inc);
        $dropped_count = count($active_dropped);

        $total_units = 0;
        foreach ($valid_courses as $course) {
            $total_units += $this->getCountedCourseUnits($course);
        }
        
        $completion_percentage = 0;
        if ($total_courses > 0) {
            $raw_percentage = ($completed_count / $total_courses) * 100;
            $completion_percentage = min(100, max(0, round($raw_percentage, 1)));
        }
        
        return [
            'total_courses' => $total_courses,
            'completed_courses' => min($completed_count, $total_courses),
            'remaining_courses' => $remaining_count,
            'completion_percentage' => $completion_percentage,
            'total_units' => $total_units,
            'completed_units' => min($completed_units, $total_units),
            'remaining_units' => max(0, $total_units - $completed_units),
            'failed_courses' => $failed_count,
            'inc_courses' => $inc_count,
            'dropped_courses' => $dropped_count,
            'back_subjects' => $failed_count + $inc_count + $dropped_count,
            'retention_status' => $this->retention_status,
            'retention_history' => $this->retention_history,
            'thrice_failed_courses' => $this->thrice_failed_courses,
            'thrice_failed_count' => count($this->thrice_failed_courses),
            'policy_gate' => $this->policy_gate_status,
        ];
    }
    
    /**
     * Get current retention status
     */
    public function getRetentionStatus() {
        return $this->retention_status;
    }
    
    /**
     * Get retention history per semester
     */
    public function getRetentionHistory() {
        return $this->retention_history;
    }

    public function getPolicyGateStatus() {
        return $this->policy_gate_status;
    }
    
    /**
     * Get completed (past) terms with course details for display
     * Returns semesters that have graded courses, sorted chronologically
     */
    public function getCompletedTerms() {
        $year_order = ['1st Yr' => 1, '2nd Yr' => 2, '3rd Yr' => 3, '4th Yr' => 4];
        $sem_order = ['1st Sem' => 1, '2nd Sem' => 2, 'Mid Year' => 3];
        
        $terms = [];
        foreach ($this->semester_grade_history as $term_key => $term_data) {
            // Only mark/display a semester as completed when all curriculum
            // courses in that semester are passed.
            if (!$this->isSemesterCompleted($term_data['year'], $term_data['semester'])) {
                continue;
            }

            $courses = [];
            foreach ($term_data['courses'] as $c) {
                $course_info = $this->all_courses[$c['code']] ?? null;
                $prerequisite = trim((string)($course_info['prerequisite'] ?? ''));
                if ($prerequisite === '' || strtoupper($prerequisite) === 'NONE') {
                    $prerequisite = 'None';
                }
                $courses[] = [
                    'code' => $c['code'],
                    'title' => $course_info['title'] ?? $c['code'],
                    'units' => $course_info['units'] ?? 0,
                    'prerequisite' => $prerequisite,
                    'grade' => $c['grade'],
                    'failed' => $c['failed']
                ];
            }
            $terms[] = [
                'year' => $term_data['year'],
                'semester' => $term_data['semester'],
                'courses' => $courses,
                'total_units' => $this->sumCountedCourseUnits($courses),
                'retention_status' => $this->retention_history[$term_key] ?? 'None'
            ];
        }
        
        // Sort chronologically
        usort($terms, function($a, $b) use ($year_order, $sem_order) {
            $ya = $year_order[$a['year']] ?? 0;
            $yb = $year_order[$b['year']] ?? 0;
            if ($ya !== $yb) return $ya - $yb;
            return ($sem_order[$a['semester']] ?? 0) - ($sem_order[$b['semester']] ?? 0);
        });
        
        return $terms;
    }
    
    /**
     * Get all curriculum courses grouped by term (year + semester),
     * split into completed vs uncomplete lists for popup display.
     */
    public function getAllCoursesGroupedByTerm() {
        $year_order = ['1st Yr' => 1, '2nd Yr' => 2, '3rd Yr' => 3, '4th Yr' => 4];
        $sem_order  = ['1st Sem' => 1, '2nd Sem' => 2, 'Mid Year' => 3];

        // Build course_code -> grade info map from grade history
        $course_grade_map = [];
        foreach ($this->semester_grade_history as $term_data) {
            foreach ($term_data['courses'] as $c) {
                // Keep the most recent grade if a course appears multiple times
                $course_grade_map[$c['code']] = [
                    'grade'  => $c['grade'],
                    'failed' => $c['failed']
                ];
            }
        }

        $terms = [];
        foreach ($this->all_courses as $code => $course) {
            $term_key = $course['year'] . '|' . $course['semester'];
            if (!isset($terms[$term_key])) {
                $terms[$term_key] = [
                    'year'       => $course['year'],
                    'semester'   => $course['semester'],
                    'completed'  => [],
                    'uncomplete' => []
                ];
            }

            $grade_info = $course_grade_map[$code] ?? null;

            if ($course['completed']) {
                $prerequisite = trim((string)($course['prerequisite'] ?? ''));
                if ($prerequisite === '' || strtoupper($prerequisite) === 'NONE') {
                    $prerequisite = 'None';
                }
                $terms[$term_key]['completed'][] = [
                    'code'  => $code,
                    'title' => $course['title'],
                    'units' => $course['units'],
                    'prerequisite' => $prerequisite,
                    'grade' => $grade_info['grade'] ?? ''
                ];
            } else {
                $prerequisite = trim((string)($course['prerequisite'] ?? ''));
                if ($prerequisite === '' || strtoupper($prerequisite) === 'NONE') {
                    $prerequisite = 'None';
                }
                $reason = 'Not Yet Taken';
                $grade  = '';
                if ($course['is_failed']) {
                    $reason = 'Failed';
                    $grade  = $grade_info['grade'] ?? '5.0';
                } elseif ($course['is_inc']) {
                    $reason = 'INC';
                    $grade  = 'INC';
                } elseif ($course['is_dropped']) {
                    $reason = 'Dropped';
                    $grade  = 'DRP';
                }
                $terms[$term_key]['uncomplete'][] = [
                    'code'   => $code,
                    'title'  => $course['title'],
                    'units'  => $course['units'],
                    'prerequisite' => $prerequisite,
                    'grade'  => $grade,
                    'reason' => $reason
                ];
            }
        }

        // Sort terms chronologically
        uksort($terms, function ($a, $b) use ($year_order, $sem_order) {
            list($ya, $sa) = explode('|', $a);
            list($yb, $sb) = explode('|', $b);
            $ya_ord = $year_order[$ya] ?? 0;
            $yb_ord = $year_order[$yb] ?? 0;
            if ($ya_ord !== $yb_ord) return $ya_ord - $yb_ord;
            return ($sem_order[$sa] ?? 0) - ($sem_order[$sb] ?? 0);
        });

        return $terms;
    }

    /**
     * Get courses that have been failed 3 or more times.
     * These trigger plan generation to stop.
     */
    public function getThriceFailedCourses() {
        $result = [];
        foreach ($this->thrice_failed_courses as $code => $count) {
            $course_info = $this->all_courses[$code] ?? null;
            $result[$code] = [
                'code'       => $code,
                'title'      => $course_info['title'] ?? $code,
                'units'      => $course_info['units'] ?? 0,
                'fail_count' => $count
            ];
        }
        return $result;
    }

    /**
    * Get list of back and failed subjects
     */
    public function getBackSubjects() {
        $back = [];
        foreach ($this->all_courses as $code => $course) {
            if ($course['needs_retake']) {
                $back[$code] = $course;
            }
        }
        return $back;
    }
    
    public function __destruct() {
        if ($this->conn) {
            closeDBConnection($this->conn);
        }
    }
}
