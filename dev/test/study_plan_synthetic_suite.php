<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../student/generate_study_plan.php';

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "This script is CLI-only.\n";
    exit(1);
}

function getGeneratorReflection(): ReflectionClass
{
    static $reflection = null;
    if ($reflection === null) {
        $reflection = new ReflectionClass(StudyPlanGenerator::class);
    }

    return $reflection;
}

function setGeneratorProperty(StudyPlanGenerator $generator, string $property, $value): void
{
    $reflection = getGeneratorReflection();
    $prop = $reflection->getProperty($property);
    $prop->setAccessible(true);
    $prop->setValue($generator, $value);
}

function invokeGeneratorMethod(StudyPlanGenerator $generator, string $method, array $args = [])
{
    $reflection = getGeneratorReflection();
    $target = $reflection->getMethod($method);
    $target->setAccessible(true);
    return $target->invokeArgs($generator, $args);
}

function defaultPolicyGate(): array
{
    return [
        'applies' => false,
        'eligible' => true,
        'reasons' => [],
        'average_grade' => null,
        'failed_course_count' => 0,
        'classification' => '',
        'has_active_shift_request' => false,
        'legacy_transferee_inferred' => false,
    ];
}

function buildTermMaxUnits(int $maxUnits = 21): array
{
    $years = ['1st Yr', '2nd Yr', '3rd Yr', '4th Yr'];
    $sems = ['1st Sem', '2nd Sem', 'Mid Year'];
    $map = [];

    foreach ($years as $year) {
        foreach ($sems as $sem) {
            $map[$year . '|' . $sem] = $maxUnits;
        }
    }

    return $map;
}

function makeCourse(
    string $code,
    string $year,
    string $semester,
    array $options = []
): array {
    $title = $options['title'] ?? ($code . ' Course');
    $units = isset($options['units']) ? (float) $options['units'] : 3.0;
    $prereqs = $options['prereqs'] ?? [];

    return [
        'code' => $code,
        'title' => $title,
        'units' => $units,
        'credit_unit_lec' => (int) $units,
        'credit_unit_lab' => 0,
        'lect_hrs_lec' => 0,
        'lect_hrs_lab' => 0,
        'prereqs' => $prereqs,
        'prerequisite' => empty($prereqs) ? 'None' : implode(', ', $prereqs),
        'year' => $year,
        'semester' => $semester,
        'completed' => !empty($options['completed']),
        'is_failed' => !empty($options['is_failed']),
        'is_inc' => !empty($options['is_inc']),
        'is_dropped' => !empty($options['is_dropped']),
        'needs_retake' => !empty($options['needs_retake']),
        'cross_registered' => !empty($options['cross_registered']),
    ];
}

function seedGenerator(
    StudyPlanGenerator $generator,
    array $courses,
    array $options = []
): void {
    $allCourses = [];
    $prereqMap = [];
    $standingMap = [];
    $completed = [];
    $failed = [];
    $inc = [];
    $dropped = [];

    foreach ($courses as $course) {
        $code = trim((string) ($course['code'] ?? ''));
        if ($code === '') {
            continue;
        }

        $allCourses[$code] = $course;
        $prereqMap[$code] = $course['prereqs'] ?? [];
        $standingMap[$code] = $course['standing_constraint'] ?? null;

        if (!empty($course['completed'])) {
            $completed[] = $code;
        }
        if (!empty($course['is_failed'])) {
            $failed[] = $code;
        }
        if (!empty($course['is_inc'])) {
            $inc[] = $code;
        }
        if (!empty($course['is_dropped'])) {
            $dropped[] = $code;
        }
    }

    setGeneratorProperty($generator, 'all_courses', $allCourses);
    setGeneratorProperty($generator, 'prerequisite_map', $prereqMap);
    setGeneratorProperty($generator, 'standing_constraint_map', $standingMap);
    setGeneratorProperty($generator, 'completed_courses', $options['completed_courses'] ?? $completed);
    setGeneratorProperty($generator, 'failed_courses', $options['failed_courses'] ?? $failed);
    setGeneratorProperty($generator, 'inc_courses', $options['inc_courses'] ?? $inc);
    setGeneratorProperty($generator, 'dropped_courses', $options['dropped_courses'] ?? $dropped);
    setGeneratorProperty($generator, 'term_max_units', $options['term_max_units'] ?? buildTermMaxUnits(21));
    setGeneratorProperty($generator, 'retention_status', $options['retention_status'] ?? 'None');
    setGeneratorProperty($generator, 'retention_history', $options['retention_history'] ?? []);
    setGeneratorProperty($generator, 'semester_grade_history', $options['semester_grade_history'] ?? []);
    setGeneratorProperty($generator, 'course_failure_counts', $options['course_failure_counts'] ?? []);
    setGeneratorProperty($generator, 'thrice_failed_courses', $options['thrice_failed_courses'] ?? []);
    setGeneratorProperty($generator, 'disqualification_count', $options['disqualification_count'] ?? 0);
    setGeneratorProperty($generator, 'policy_gate_status', $options['policy_gate_status'] ?? defaultPolicyGate());
    setGeneratorProperty($generator, 'cross_reg_courses', $options['cross_reg_courses'] ?? []);
    setGeneratorProperty($generator, 'cross_reg_equivalent_courses', $options['cross_reg_equivalent_courses'] ?? []);
}

function makeGenerator(string $seedStudent, string $seedProgram): StudyPlanGenerator
{
    static $baseGenerator = null;

    if ($baseGenerator === null) {
        $baseGenerator = new StudyPlanGenerator($seedStudent, $seedProgram);
    }

    return $baseGenerator;
}

function assertScenario(bool $condition, string $message): array
{
    return [
        'pass' => $condition,
        'message' => $message,
    ];
}

function planContainsTag(array $plan, string $tag): bool
{
    foreach ($plan as $term) {
        foreach (($term['courses'] ?? []) as $course) {
            if (!empty($course[$tag])) {
                return true;
            }
        }
    }
    return false;
}

function findFirstTerm(array $plan): ?array
{
    if (empty($plan)) {
        return null;
    }

    return $plan[0];
}

$conn = getDBConnection();
$seedQuery = $conn->query("SELECT student_number, program FROM student_info ORDER BY student_number ASC LIMIT 1");
$seedRow = $seedQuery ? $seedQuery->fetch_assoc() : null;
closeDBConnection($conn);

if (!$seedRow) {
    echo "No student records found. Cannot run synthetic suite.\n";
    exit(1);
}

$seedStudent = (string) ($seedRow['student_number'] ?? '');
$seedProgram = (string) ($seedRow['program'] ?? '');

$scenarios = [];

$scenarios['exact_curriculum_path'] = function () use ($seedStudent, $seedProgram) {
    $generator = makeGenerator($seedStudent, $seedProgram);
    seedGenerator($generator, [
        makeCourse('EX101', '1st Yr', '1st Sem'),
        makeCourse('EX102', '1st Yr', '2nd Sem'),
        makeCourse('EX201', '2nd Yr', '1st Sem'),
    ]);

    $plan = $generator->generateOptimizedPlan();
    $ok = count($plan) === 3
        && ($plan[0]['year'] ?? '') === '1st Yr'
        && ($plan[0]['semester'] ?? '') === '1st Sem';

    return assertScenario($ok, 'Exact curriculum path preserves original term order.');
};

$scenarios['retake_tag'] = function () use ($seedStudent, $seedProgram) {
    $generator = makeGenerator($seedStudent, $seedProgram);
    seedGenerator($generator, [
        makeCourse('RT101', '1st Yr', '1st Sem', ['is_failed' => true, 'needs_retake' => true]),
        makeCourse('RT102', '1st Yr', '1st Sem'),
    ], [
        'failed_courses' => ['RT101'],
        'retention_status' => 'Warning',
        'retention_history' => ['1st Yr|1st Sem' => 'Warning', '1st Yr|2nd Sem' => 'Warning'],
    ]);

    $plan = $generator->generateOptimizedPlan();
    return assertScenario(planContainsTag($plan, 'needs_retake'), 'Retake-tagged course appears in generated plan.');
};

$scenarios['cross_registration'] = function () use ($seedStudent, $seedProgram) {
    $generator = makeGenerator($seedStudent, $seedProgram);
    seedGenerator($generator, [
        makeCourse('CR100', '1st Yr', '1st Sem'),
        makeCourse('CR200', '1st Yr', '2nd Sem'),
    ], [
        'failed_courses' => ['CR100'],
        'cross_reg_courses' => [
            'CR200' => [
                'sample' => [
                    'code' => 'CR200',
                    'semester' => '1st Sem',
                    'programs' => 'BSIT',
                    'cross_reg_source_program' => 'BSIT',
                    'cross_registered' => true,
                ],
            ],
        ],
    ]);

    $plan = $generator->generateOptimizedPlan();
    return assertScenario(planContainsTag($plan, 'cross_registered'), 'Cross-registered course is injected when offering exists.');
};

$scenarios['cross_registration_equivalency_fallback'] = function () use ($seedStudent, $seedProgram) {
    $generator = makeGenerator($seedStudent, $seedProgram);
    $course = makeCourse('EQ200', '1st Yr', '2nd Sem', [
        'title' => 'Data Structures',
        'units' => 3,
    ]);

    seedGenerator($generator, [
        makeCourse('EQ100', '1st Yr', '1st Sem'),
        $course,
    ], [
        'failed_courses' => ['DUMMY_FAIL_FLAG'],
        'cross_reg_courses' => [],
        'cross_reg_equivalent_courses' => [
            'DATA STRUCTURES|3|0|0|0' => [
                'eqsig' => [
                    'code' => 'EQX200',
                    'title' => 'Data Structures',
                    'units' => 3,
                    'credit_unit_lec' => 3,
                    'credit_unit_lab' => 0,
                    'lect_hrs_lec' => 0,
                    'lect_hrs_lab' => 0,
                    'semester' => '1st Sem',
                    'programs' => 'BSIT',
                    'cross_reg_source_program' => 'BSIT',
                    'cross_registered' => true,
                ],
            ],
        ],
    ]);

    $plan = $generator->generateOptimizedPlan();
    return assertScenario(planContainsTag($plan, 'cross_registered'), 'Cross-registration fallback through equivalent course signature works.');
};

$scenarios['prerequisite_enforced_no_same_term_chain'] = function () use ($seedStudent, $seedProgram) {
    $generator = makeGenerator($seedStudent, $seedProgram);
    seedGenerator($generator, [
        makeCourse('PR100', '1st Yr', '1st Sem'),
        makeCourse('PR200', '1st Yr', '1st Sem', ['prereqs' => ['PR100']]),
    ], [
        'failed_courses' => ['DUMMY_FAIL_FLAG'],
    ]);

    $plan = $generator->generateOptimizedPlan();
    $first = findFirstTerm($plan);
    $firstCodes = array_keys((array)($first['courses'] ?? []));
    $ok = in_array('PR100', $firstCodes, true) && !in_array('PR200', $firstCodes, true);

    return assertScenario($ok, 'Prerequisite chaining is not allowed within the same term by default.');
};

$scenarios['prerequisite_case_insensitive_match'] = function () use ($seedStudent, $seedProgram) {
    $generator = makeGenerator($seedStudent, $seedProgram);
    seedGenerator($generator, [
        makeCourse('CI200', '1st Yr', '1st Sem', ['prereqs' => ['MATH 1']]),
    ]);

    $isSatisfied = (bool) invokeGeneratorMethod(
        $generator,
        'prerequisitesSatisfiedForCompletedSet',
        ['CI200', ['math 1']]
    );

    return assertScenario($isSatisfied, 'Prerequisite checks are case-insensitive.');
};

$scenarios['standing_constraint_enforced'] = function () use ($seedStudent, $seedProgram) {
    $generator = makeGenerator($seedStudent, $seedProgram);
    $course = makeCourse('ST300', '2nd Yr', '1st Sem');
    $course['standing_constraint'] = ['type' => 'standing', 'year' => 3];
    seedGenerator($generator, [$course]);

    $blocked = !(bool) invokeGeneratorMethod($generator, 'standingConstraintSatisfied', ['ST300', '2nd Yr', '1st Sem']);
    $allowed = (bool) invokeGeneratorMethod($generator, 'standingConstraintSatisfied', ['ST300', '3rd Yr', '1st Sem']);

    return assertScenario($blocked && $allowed, 'Standing constraints block earlier years and allow required year onward.');
};

$scenarios['incoming_constraint_midyear_rule'] = function () use ($seedStudent, $seedProgram) {
    $generator = makeGenerator($seedStudent, $seedProgram);
    $course = makeCourse('IN200', '1st Yr', 'Mid Year');
    $course['standing_constraint'] = ['type' => 'incoming', 'year' => 2];
    seedGenerator($generator, [$course]);

    $midYearAllowed = (bool) invokeGeneratorMethod($generator, 'standingConstraintSatisfied', ['IN200', '1st Yr', 'Mid Year']);
    $secondSemBlocked = !(bool) invokeGeneratorMethod($generator, 'standingConstraintSatisfied', ['IN200', '1st Yr', '2nd Sem']);
    $yearTwoAllowed = (bool) invokeGeneratorMethod($generator, 'standingConstraintSatisfied', ['IN200', '2nd Yr', '1st Sem']);

    return assertScenario($midYearAllowed && $secondSemBlocked && $yearTwoAllowed, 'Incoming-year standing rule allows prior midyear only.');
};

$scenarios['non_credit_excluded_from_unit_cap'] = function () use ($seedStudent, $seedProgram) {
    $generator = makeGenerator($seedStudent, $seedProgram);
    $termMax = buildTermMaxUnits(21);
    $termMax['1st Yr|1st Sem'] = 3;

    seedGenerator($generator, [
        makeCourse('CVSU 101', '1st Yr', '1st Sem', ['title' => 'Institutional Orientation', 'units' => 3]),
        makeCourse('NC102', '1st Yr', '1st Sem', ['units' => 3]),
        makeCourse('NC103', '1st Yr', '1st Sem', ['units' => 3]),
    ], [
        'failed_courses' => ['DUMMY_FAIL_FLAG'],
        'term_max_units' => $termMax,
    ]);

    $plan = $generator->generateOptimizedPlan();
    $first = findFirstTerm($plan);
    $firstCodes = array_keys((array)($first['courses'] ?? []));
    $ok = in_array('CVSU 101', $firstCodes, true)
        && (float)($first['total_units'] ?? 0.0) <= 3.0
        && count($firstCodes) >= 2;

    return assertScenario($ok, 'Non-credit course does not consume counted unit cap.');
};

$scenarios['no_overloading_enforced'] = function () use ($seedStudent, $seedProgram) {
    $generator = makeGenerator($seedStudent, $seedProgram);
    $termMax = buildTermMaxUnits(21);
    $termMax['1st Yr|1st Sem'] = 6;

    seedGenerator($generator, [
        makeCourse('OL101', '1st Yr', '1st Sem', ['units' => 3]),
        makeCourse('OL102', '1st Yr', '1st Sem', ['units' => 3]),
        makeCourse('OL103', '1st Yr', '1st Sem', ['units' => 3]),
    ], [
        'failed_courses' => ['DUMMY_FAIL_FLAG'],
        'term_max_units' => $termMax,
    ]);

    $plan = $generator->generateOptimizedPlan();
    $first = findFirstTerm($plan);
    $units = (float)($first['total_units'] ?? 0.0);
    $count = count((array)($first['courses'] ?? []));
    $ok = $units <= 6.0 && $count <= 2;

    return assertScenario($ok, 'Overloading is prevented by max unit constraint.');
};

$scenarios['retention_escalates_two_warnings'] = function () use ($seedStudent, $seedProgram) {
    $generator = makeGenerator($seedStudent, $seedProgram);
    seedGenerator($generator, [], [
        'semester_grade_history' => [
            '1st Yr|1st Sem' => [
                'year' => '1st Yr',
                'semester' => '1st Sem',
                'total_subjects' => 10,
                'failed_subjects' => 3,
                'courses' => [],
            ],
            '1st Yr|2nd Sem' => [
                'year' => '1st Yr',
                'semester' => '2nd Sem',
                'total_subjects' => 10,
                'failed_subjects' => 3,
                'courses' => [],
            ],
        ],
        'retention_history' => [],
        'retention_status' => 'None',
    ]);

    invokeGeneratorMethod($generator, 'calculateRetentionHistory');
    $history = (array) invokeGeneratorMethod($generator, 'getRetentionHistory');
    $ok = (($history['1st Yr|2nd Sem'] ?? '') === 'Probation');

    return assertScenario($ok, 'Two consecutive Warning semesters escalate to Probation.');
};

$scenarios['retention_escalates_two_probations'] = function () use ($seedStudent, $seedProgram) {
    $generator = makeGenerator($seedStudent, $seedProgram);
    seedGenerator($generator, [], [
        'semester_grade_history' => [
            '1st Yr|1st Sem' => [
                'year' => '1st Yr',
                'semester' => '1st Sem',
                'total_subjects' => 10,
                'failed_subjects' => 6,
                'courses' => [],
            ],
            '1st Yr|2nd Sem' => [
                'year' => '1st Yr',
                'semester' => '2nd Sem',
                'total_subjects' => 10,
                'failed_subjects' => 6,
                'courses' => [],
            ],
        ],
        'retention_history' => [],
        'retention_status' => 'None',
    ]);

    invokeGeneratorMethod($generator, 'calculateRetentionHistory');
    $history = (array) invokeGeneratorMethod($generator, 'getRetentionHistory');
    $ok = (($history['1st Yr|2nd Sem'] ?? '') === 'Disqualification');

    return assertScenario($ok, 'Two consecutive Probation semesters escalate to Disqualification.');
};

$scenarios['determine_current_term_advances_past_retake_only_term'] = function () use ($seedStudent, $seedProgram) {
    $generator = makeGenerator($seedStudent, $seedProgram);
    seedGenerator($generator, [
        makeCourse('DT101', '1st Yr', '1st Sem', ['is_failed' => true, 'needs_retake' => true]),
        makeCourse('DT201', '1st Yr', '2nd Sem', ['completed' => true]),
        makeCourse('DT301', '2nd Yr', '1st Sem'),
    ], [
        'failed_courses' => ['DT101'],
        'completed_courses' => ['DT201'],
        'semester_grade_history' => [
            '1st Yr|2nd Sem' => [
                'year' => '1st Yr',
                'semester' => '2nd Sem',
                'total_subjects' => 1,
                'failed_subjects' => 0,
                'courses' => [
                    ['code' => 'DT201', 'grade' => '1.75', 'failed' => false],
                ],
            ],
        ],
    ]);

    $term = (array) invokeGeneratorMethod($generator, 'determineCurrentTerm');
    $ok = (($term['year'] ?? '') === '2nd Yr') && (($term['semester'] ?? '') === '1st Sem');

    return assertScenario($ok, 'Planner anchor advances beyond a retake-only incomplete term when history already progressed.');
};

$scenarios['ordered_terms_follow_actual_curriculum_midyear_pattern'] = function () use ($seedStudent, $seedProgram) {
    $generator = makeGenerator($seedStudent, $seedProgram);
    seedGenerator($generator, [
        makeCourse('CS101', '1st Yr', '1st Sem'),
        makeCourse('CS102', '1st Yr', '2nd Sem'),
        makeCourse('CS201', '2nd Yr', '1st Sem'),
        makeCourse('CS202', '2nd Yr', '2nd Sem'),
        makeCourse('CS301', '3rd Yr', '1st Sem'),
        makeCourse('CS302', '3rd Yr', '2nd Sem'),
        makeCourse('CS399', '3rd Yr', 'Mid Year'),
        makeCourse('CS401', '4th Yr', '1st Sem'),
        makeCourse('CS402', '4th Yr', '2nd Sem'),
    ]);

    $terms = (array) invokeGeneratorMethod($generator, 'getOrderedCurriculumTerms');
    $labels = array_map(function ($term) {
        return ($term['year'] ?? '') . '|' . ($term['semester'] ?? '');
    }, $terms);

    $ok = !in_array('1st Yr|Mid Year', $labels, true)
        && !in_array('2nd Yr|Mid Year', $labels, true)
        && in_array('3rd Yr|Mid Year', $labels, true)
        && !in_array('4th Yr|Mid Year', $labels, true);

    return assertScenario($ok, 'Curriculum term order only includes midyear where the actual curriculum defines it.');
};

$scenarios['extra_term_midyear_uses_curriculum_midyear_cap'] = function () use ($seedStudent, $seedProgram) {
    $generator = makeGenerator($seedStudent, $seedProgram);
    $termMax = buildTermMaxUnits(21);
    $termMax['3rd Yr|Mid Year'] = 3;

    seedGenerator($generator, [
        makeCourse('MY101', '1st Yr', '1st Sem', ['completed' => true]),
        makeCourse('MY102', '1st Yr', '2nd Sem', ['completed' => true]),
        makeCourse('MY201', '2nd Yr', '1st Sem', ['completed' => true]),
        makeCourse('MY202', '2nd Yr', '2nd Sem', ['completed' => true]),
        makeCourse('MY301', '3rd Yr', '1st Sem', ['completed' => true]),
        makeCourse('MY302', '3rd Yr', '2nd Sem', ['completed' => true]),
        makeCourse('MY399', '3rd Yr', 'Mid Year', ['completed' => true]),
        makeCourse('MY401', '4th Yr', '1st Sem', ['completed' => true]),
        makeCourse('MY402', '4th Yr', '2nd Sem', ['completed' => true]),
        makeCourse('MY501', '4th Yr', 'Mid Year', ['units' => 3, 'is_failed' => true, 'needs_retake' => true]),
        makeCourse('MY502', '4th Yr', 'Mid Year', ['units' => 3, 'is_failed' => true, 'needs_retake' => true]),
    ], [
        'completed_courses' => ['MY101', 'MY102', 'MY201', 'MY202', 'MY301', 'MY302', 'MY399', 'MY401', 'MY402'],
        'failed_courses' => ['MY501', 'MY502'],
        'term_max_units' => $termMax,
        'semester_grade_history' => [
            '4th Yr|2nd Sem' => [
                'year' => '4th Yr',
                'semester' => '2nd Sem',
                'total_subjects' => 1,
                'failed_subjects' => 0,
                'courses' => [
                    ['code' => 'MY402', 'grade' => '1.50', 'failed' => false],
                ],
            ],
        ],
    ]);

    $plan = $generator->generateOptimizedPlan();
    $midYearTerms = array_values(array_filter($plan, function ($term) {
        return ($term['semester'] ?? '') === 'Mid Year';
    }));
    $firstMidYear = $midYearTerms[0] ?? null;

    $ok = $firstMidYear !== null
        && (int)($firstMidYear['max_units'] ?? 0) === 3
        && (float)($firstMidYear['total_units'] ?? 0.0) <= 3.0;

    return assertScenario($ok, 'Extra midyear terms inherit the actual curriculum midyear unit cap.');
};

$scenarios['prerequisite_parser_expands_compound_tokens'] = function () use ($seedStudent, $seedProgram) {
    $generator = makeGenerator($seedStudent, $seedProgram);
    $parsed = (array) invokeGeneratorMethod($generator, 'parsePrerequisites', ['GNED 11 & 12, MATH2']);
    $ok = in_array('GNED 11', $parsed, true)
        && in_array('GNED 12', $parsed, true)
        && in_array('MATH 2', $parsed, true);

    return assertScenario($ok, 'Prerequisite parser expands compact and compound prerequisite tokens.');
};

$scenarios['standing_constraint_parser_detects_incoming'] = function () use ($seedStudent, $seedProgram) {
    $generator = makeGenerator($seedStudent, $seedProgram);
    $constraint = (array) invokeGeneratorMethod($generator, 'extractStandingConstraint', ['Incoming 4th yr.']);
    $ok = (($constraint['type'] ?? '') === 'incoming') && ((int)($constraint['year'] ?? 0) === 4);

    return assertScenario($ok, 'Standing parser detects incoming year constraints from free text.');
};

$scenarios['retention_probation_unit_cap'] = function () use ($seedStudent, $seedProgram) {
    $generator = makeGenerator($seedStudent, $seedProgram);
    $courses = [];
    for ($i = 1; $i <= 6; $i++) {
        $courses[] = makeCourse('PB10' . $i, '1st Yr', '1st Sem', ['units' => 3]);
    }
    seedGenerator($generator, $courses, [
        'retention_status' => 'Probation',
        'retention_history' => ['1st Yr|1st Sem' => 'Probation'],
    ]);

    $plan = $generator->generateOptimizedPlan();
    $first = findFirstTerm($plan);
    $ok = $first !== null
        && (int) ($first['max_units'] ?? 0) === 15
        && (float) ($first['total_units'] ?? 0) <= 15.0;

    return assertScenario($ok, 'Probation enforces 15-unit limit.');
};

$scenarios['disqualification_skip'] = function () use ($seedStudent, $seedProgram) {
    $generator = makeGenerator($seedStudent, $seedProgram);
    seedGenerator($generator, [
        makeCourse('DQ101', '1st Yr', '1st Sem'),
    ], [
        'retention_status' => 'Disqualification',
        'retention_history' => ['1st Yr|1st Sem' => 'Disqualification'],
        'disqualification_count' => 1,
    ]);

    $plan = $generator->generateOptimizedPlan();
    $first = findFirstTerm($plan);
    $ok = $first !== null && !empty($first['skipped']);

    return assertScenario($ok, 'Disqualification creates skipped first term.');
};

$scenarios['thrice_failed_stop'] = function () use ($seedStudent, $seedProgram) {
    $generator = makeGenerator($seedStudent, $seedProgram);
    seedGenerator($generator, [
        makeCourse('TF101', '1st Yr', '1st Sem'),
    ], [
        'thrice_failed_courses' => ['TF101' => 3],
    ]);

    $plan = $generator->generateOptimizedPlan();
    return assertScenario(empty($plan), '3+ failures stop generation.');
};

$scenarios['policy_gate_pause'] = function () use ($seedStudent, $seedProgram) {
    $generator = makeGenerator($seedStudent, $seedProgram);
    seedGenerator($generator, [
        makeCourse('PG101', '1st Yr', '1st Sem'),
    ], [
        'policy_gate_status' => [
            'applies' => true,
            'eligible' => false,
            'reasons' => ['Policy blocked'],
            'average_grade' => 2.5,
            'failed_course_count' => 1,
            'classification' => 'Transferee',
            'has_active_shift_request' => true,
            'legacy_transferee_inferred' => false,
        ],
    ]);

    $plan = $generator->generateOptimizedPlan();
    return assertScenario(empty($plan), 'Policy gate pause stops plan generation.');
};

$scenarios['policy_gate_flexible_fill'] = function () use ($seedStudent, $seedProgram) {
    $generator = makeGenerator($seedStudent, $seedProgram);
    seedGenerator($generator, [
        makeCourse('FL101', '1st Yr', '1st Sem'),
        makeCourse('FL201', '1st Yr', '2nd Sem'),
    ], [
        'policy_gate_status' => [
            'applies' => true,
            'eligible' => true,
            'reasons' => [],
            'average_grade' => 1.75,
            'failed_course_count' => 0,
            'classification' => 'Transferee',
            'has_active_shift_request' => false,
            'legacy_transferee_inferred' => false,
        ],
    ]);

    $plan = $generator->generateOptimizedPlan();
    $first = findFirstTerm($plan);
    $courseCount = $first !== null ? count($first['courses'] ?? []) : 0;

    return assertScenario($courseCount >= 2, 'Flexible irregular fill can pull off-semester eligible courses.');
};

$scenarios['forced_add_near_graduation'] = function () use ($seedStudent, $seedProgram) {
    $generator = makeGenerator($seedStudent, $seedProgram);
    seedGenerator($generator, [
        makeCourse('FG401', '4th Yr', '1st Sem', ['completed' => true]),
        makeCourse('FG499', '4th Yr', '2nd Sem', ['prereqs' => ['FGPRE']]),
    ], [
        'retention_status' => 'Warning',
        'retention_history' => ['4th Yr|1st Sem' => 'Warning'],
    ]);

    $plan = $generator->generateOptimizedPlan();
    return assertScenario(planContainsTag($plan, 'forced_added'), 'Near-graduation force-add path executed.');
};

$scenarios['extended_beyond_fourth_year'] = function () use ($seedStudent, $seedProgram) {
    $generator = makeGenerator($seedStudent, $seedProgram);
    seedGenerator($generator, [
        makeCourse('EX501', '4th Yr', '1st Sem', ['is_failed' => true, 'needs_retake' => true]),
        makeCourse('EX502', '4th Yr', '1st Sem', ['is_failed' => true, 'needs_retake' => true]),
        makeCourse('EX503', '4th Yr', '1st Sem', ['is_failed' => true, 'needs_retake' => true]),
        makeCourse('EX504', '4th Yr', '1st Sem', ['is_failed' => true, 'needs_retake' => true]),
    ], [
        'failed_courses' => ['EX501', 'EX502', 'EX503', 'EX504'],
        'semester_grade_history' => [
            '4th Yr|1st Sem' => [
                'year' => '4th Yr',
                'semester' => '1st Sem',
                'total_subjects' => 4,
                'failed_subjects' => 4,
                'courses' => [
                    ['code' => 'EX501', 'grade' => '5.0', 'failed' => true],
                    ['code' => 'EX502', 'grade' => '5.0', 'failed' => true],
                    ['code' => 'EX503', 'grade' => '5.0', 'failed' => true],
                    ['code' => 'EX504', 'grade' => '5.0', 'failed' => true],
                ],
            ],
        ],
    ]);

    $plan = $generator->generateOptimizedPlan();
    $hasExtended = false;
    foreach ($plan as $term) {
        if (preg_match('/(\d+)/', (string) ($term['year'] ?? ''), $m) && (int) $m[1] > 4) {
            $hasExtended = true;
            break;
        }
    }

    return assertScenario($hasExtended, 'Extra terms beyond 4th year are generated when needed.');
};

$results = [];
$passed = 0;

foreach ($scenarios as $name => $runner) {
    try {
        $result = $runner();
        $results[$name] = $result;
        if (!empty($result['pass'])) {
            $passed++;
        }
    } catch (Throwable $e) {
        $results[$name] = [
            'pass' => false,
            'message' => 'Exception: ' . $e->getMessage(),
        ];
    }
}

echo "Study Plan Synthetic Scenario Suite\n";
echo "Generated: " . date('Y-m-d H:i:s') . "\n";
echo "Seed student: {$seedStudent}\n";
echo "\n";

foreach ($results as $name => $result) {
    $status = !empty($result['pass']) ? 'PASS' : 'FAIL';
    echo "[{$status}] {$name} - {$result['message']}\n";
}

echo "\nSummary: {$passed}/" . count($results) . " scenarios passed.\n";
if ($passed !== count($results)) {
    exit(1);
}

echo "Done.\n";
