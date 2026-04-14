<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../student/generate_study_plan.php';
require_once __DIR__ . '/../../includes/academic_hold_service.php';

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "This script is CLI-only.\n";
    exit(1);
}

function parseCliArgs(array $argv): array
{
    $options = [
        'student' => null,
        'limit' => null,
        'show-students' => false,
    ];

    foreach ($argv as $arg) {
        if (strpos($arg, '--student=') === 0) {
            $options['student'] = trim(substr($arg, strlen('--student=')));
            continue;
        }

        if (strpos($arg, '--limit=') === 0) {
            $value = (int) trim(substr($arg, strlen('--limit=')));
            $options['limit'] = $value > 0 ? $value : null;
            continue;
        }

        if ($arg === '--show-students') {
            $options['show-students'] = true;
        }
    }

    return $options;
}

function extractYearNumber($yearLabel): int
{
    if (preg_match('/(\d+)/', (string) $yearLabel, $matches)) {
        return (int) $matches[1];
    }

    return 0;
}

function planHasTaggedCourse(array $plan, string $tag): bool
{
    foreach ($plan as $term) {
        $courses = $term['courses'] ?? [];
        foreach ($courses as $course) {
            if (!empty($course[$tag])) {
                return true;
            }
        }
    }

    return false;
}

function planHasCrossRegWithSource(array $plan): bool
{
    foreach ($plan as $term) {
        $courses = $term['courses'] ?? [];
        foreach ($courses as $course) {
            if (!empty($course['cross_registered']) && trim((string) ($course['cross_reg_source_program'] ?? '')) !== '') {
                return true;
            }
        }
    }

    return false;
}

function planHasSkippedTerm(array $plan): bool
{
    foreach ($plan as $term) {
        if (!empty($term['skipped'])) {
            return true;
        }
    }

    return false;
}

function planHasExtendedTerm(array $plan): bool
{
    foreach ($plan as $term) {
        if (extractYearNumber($term['year'] ?? '') > 4) {
            return true;
        }
    }

    return false;
}

function hasPartialHistoricalTerm(array $allCoursesByTerm): bool
{
    foreach ($allCoursesByTerm as $termData) {
        $completed = $termData['completed'] ?? [];
        $uncomplete = $termData['uncomplete'] ?? [];
        if (!empty($completed) && !empty($uncomplete)) {
            return true;
        }
    }

    return false;
}

function historyContainsStatus(array $history, string $status): bool
{
    foreach ($history as $value) {
        if ((string) $value === $status) {
            return true;
        }
    }

    return false;
}

function addScenarioHit(array &$scenarioHits, string $key, string $studentId): void
{
    if (!isset($scenarioHits[$key])) {
        return;
    }

    if (!in_array($studentId, $scenarioHits[$key], true)) {
        $scenarioHits[$key][] = $studentId;
    }
}

$options = parseCliArgs(array_slice($argv, 1));
$conn = getDBConnection();

$scenarioDefinitions = [
    'plan_generated_nonempty' => 'Plan generated with future terms',
    'exact_curriculum_candidate' => 'Regular exact-curriculum path candidate',
    'irregular_path_candidate' => 'Irregular optimization path candidate',
    'no_history_new_or_empty' => 'No completed history / likely new student',
    'fully_completed' => 'Fully completed curriculum',
    'partial_historical_term' => 'Historical term has both completed/uncompleted courses',
    'retake_courses_present' => 'Retake-tagged course appears in plan',
    'cross_reg_present' => 'Cross-registration appears in plan',
    'cross_reg_with_source' => 'Cross-registration has source program label',
    'forced_added_present' => 'Near-graduation forced-add used',
    'extended_beyond_4th_year' => 'Plan extends beyond 4th year',
    'skipped_term_present' => 'Skipped term generated (retention disqualification)',
    'retention_warning_seen' => 'Retention history includes Warning',
    'retention_probation_seen' => 'Retention history includes Probation',
    'retention_disqualification_seen' => 'Retention history includes Disqualification',
    'retention_current_warning' => 'Current retention status is Warning',
    'retention_current_probation' => 'Current retention status is Probation',
    'retention_current_disqualification' => 'Current retention status is Disqualification',
    'thrice_failed_stop' => 'Plan stopped due to 3+ failed attempts',
    'policy_gate_paused' => 'Plan paused by transferee/shift policy gate',
    'policy_gate_applies_eligible' => 'Policy gate applies but student is eligible',
    'academic_hold_active' => 'Academic hold active (read-only policy)',
    'plan_empty_with_completed_terms' => 'No future plan but completed terms exist',
    'remaining_1_to_3_courses' => 'Only 1 to 3 remaining courses',
];

$scenarioHits = [];
foreach (array_keys($scenarioDefinitions) as $key) {
    $scenarioHits[$key] = [];
}

$students = [];
if (!empty($options['student'])) {
    $stmt = $conn->prepare("SELECT student_number, program FROM student_info WHERE student_number = ? LIMIT 1");
    $stmt->bind_param('s', $options['student']);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $students[] = $row;
    }
    $stmt->close();
} else {
    $sql = "SELECT student_number, program FROM student_info ORDER BY student_number ASC";
    if (!empty($options['limit'])) {
        $sql .= " LIMIT " . (int) $options['limit'];
    }

    $result = $conn->query($sql);
    while ($result && $row = $result->fetch_assoc()) {
        $students[] = $row;
    }
}

$processed = 0;
$errors = [];
$studentSnapshots = [];

foreach ($students as $student) {
    $studentId = trim((string) ($student['student_number'] ?? ''));
    if ($studentId === '') {
        continue;
    }

    try {
        $program = (string) ($student['program'] ?? '');
        $generator = new StudyPlanGenerator($studentId, $program);
        $plan = $generator->generateOptimizedPlan();
        $stats = $generator->getCompletionStats();
        $policyGate = $generator->getPolicyGateStatus();
        $completedTerms = $generator->getCompletedTerms();
        $allCoursesByTerm = $generator->getAllCoursesGroupedByTerm();
        $academicHold = ahsGetStudentAcademicHold($conn, $studentId);

        $retentionStatus = (string) ($stats['retention_status'] ?? 'None');
        $retentionHistory = is_array($stats['retention_history'] ?? null) ? $stats['retention_history'] : [];
        $backSubjects = (int) ($stats['back_subjects'] ?? 0);
        $remainingCourses = (int) ($stats['remaining_courses'] ?? 0);
        $thriceFailedCount = (int) ($stats['thrice_failed_count'] ?? 0);
        $policyApplies = !empty($policyGate['applies']);
        $policyEligible = !empty($policyGate['eligible']);

        if (!empty($plan)) {
            addScenarioHit($scenarioHits, 'plan_generated_nonempty', $studentId);
        }

        if ($backSubjects === 0 && $retentionStatus === 'None' && !$policyApplies && $thriceFailedCount === 0 && empty($academicHold['active']) && !empty($plan)) {
            addScenarioHit($scenarioHits, 'exact_curriculum_candidate', $studentId);
        }

        if ($backSubjects > 0 || $retentionStatus !== 'None' || $policyApplies || $thriceFailedCount > 0 || !empty($academicHold['active'])) {
            addScenarioHit($scenarioHits, 'irregular_path_candidate', $studentId);
        }

        if (empty($completedTerms) && (int) ($stats['completed_courses'] ?? 0) === 0) {
            addScenarioHit($scenarioHits, 'no_history_new_or_empty', $studentId);
        }

        if ($remainingCourses === 0) {
            addScenarioHit($scenarioHits, 'fully_completed', $studentId);
        }

        if (hasPartialHistoricalTerm($allCoursesByTerm)) {
            addScenarioHit($scenarioHits, 'partial_historical_term', $studentId);
        }

        if (planHasTaggedCourse($plan, 'needs_retake')) {
            addScenarioHit($scenarioHits, 'retake_courses_present', $studentId);
        }

        if (planHasTaggedCourse($plan, 'cross_registered')) {
            addScenarioHit($scenarioHits, 'cross_reg_present', $studentId);
        }

        if (planHasCrossRegWithSource($plan)) {
            addScenarioHit($scenarioHits, 'cross_reg_with_source', $studentId);
        }

        if (planHasTaggedCourse($plan, 'forced_added')) {
            addScenarioHit($scenarioHits, 'forced_added_present', $studentId);
        }

        if (planHasExtendedTerm($plan)) {
            addScenarioHit($scenarioHits, 'extended_beyond_4th_year', $studentId);
        }

        if (planHasSkippedTerm($plan)) {
            addScenarioHit($scenarioHits, 'skipped_term_present', $studentId);
        }

        if (historyContainsStatus($retentionHistory, 'Warning')) {
            addScenarioHit($scenarioHits, 'retention_warning_seen', $studentId);
        }

        if (historyContainsStatus($retentionHistory, 'Probation')) {
            addScenarioHit($scenarioHits, 'retention_probation_seen', $studentId);
        }

        if (historyContainsStatus($retentionHistory, 'Disqualification')) {
            addScenarioHit($scenarioHits, 'retention_disqualification_seen', $studentId);
        }

        if ($retentionStatus === 'Warning') {
            addScenarioHit($scenarioHits, 'retention_current_warning', $studentId);
        }

        if ($retentionStatus === 'Probation') {
            addScenarioHit($scenarioHits, 'retention_current_probation', $studentId);
        }

        if ($retentionStatus === 'Disqualification') {
            addScenarioHit($scenarioHits, 'retention_current_disqualification', $studentId);
        }

        if ($thriceFailedCount > 0 && empty($plan)) {
            addScenarioHit($scenarioHits, 'thrice_failed_stop', $studentId);
        }

        if ($policyApplies && !$policyEligible) {
            addScenarioHit($scenarioHits, 'policy_gate_paused', $studentId);
        }

        if ($policyApplies && $policyEligible) {
            addScenarioHit($scenarioHits, 'policy_gate_applies_eligible', $studentId);
        }

        if (!empty($academicHold['active'])) {
            addScenarioHit($scenarioHits, 'academic_hold_active', $studentId);
        }

        if (empty($plan) && !empty($completedTerms)) {
            addScenarioHit($scenarioHits, 'plan_empty_with_completed_terms', $studentId);
        }

        if ($remainingCourses >= 1 && $remainingCourses <= 3) {
            addScenarioHit($scenarioHits, 'remaining_1_to_3_courses', $studentId);
        }

        if ($options['show-students']) {
            $studentSnapshots[] = [
                'student_id' => $studentId,
                'program' => $program,
                'completed' => (int) ($stats['completed_courses'] ?? 0),
                'total' => (int) ($stats['total_courses'] ?? 0),
                'remaining' => $remainingCourses,
                'retention' => $retentionStatus,
                'policy_applies' => $policyApplies,
                'policy_eligible' => $policyEligible,
                'thrice_failed' => $thriceFailedCount,
                'has_plan' => !empty($plan),
            ];
        }

        $processed++;
    } catch (Throwable $e) {
        $errors[] = [
            'student_id' => $studentId,
            'error' => $e->getMessage(),
        ];
    }
}

closeDBConnection($conn);

echo "Study Plan Scenario Audit\n";
echo "Generated: " . date('Y-m-d H:i:s') . "\n";
echo "Students processed: {$processed}\n";
echo "Errors: " . count($errors) . "\n\n";

foreach ($scenarioDefinitions as $key => $description) {
    $hits = $scenarioHits[$key] ?? [];
    $count = count($hits);
    $status = $count > 0 ? 'COVERED' : 'NOT COVERED';
    $samples = $count > 0 ? implode(', ', array_slice($hits, 0, 8)) : '-';

    echo str_pad("[{$status}]", 14)
        . str_pad($description, 52)
        . " hits=" . str_pad((string) $count, 4, ' ', STR_PAD_LEFT)
        . " sample=" . $samples
        . "\n";
}

$uncovered = [];
foreach ($scenarioDefinitions as $key => $description) {
    if (empty($scenarioHits[$key])) {
        $uncovered[] = $description;
    }
}

echo "\n";
if (!empty($uncovered)) {
    echo "Uncovered scenarios in current DB data:\n";
    foreach ($uncovered as $item) {
        echo " - {$item}\n";
    }
} else {
    echo "All tracked scenarios are covered by current DB data.\n";
}

if (!empty($errors)) {
    echo "\nStudents with processing errors:\n";
    foreach (array_slice($errors, 0, 20) as $error) {
        echo " - " . $error['student_id'] . ": " . $error['error'] . "\n";
    }
}

if ($options['show-students']) {
    echo "\nStudent snapshots:\n";
    foreach ($studentSnapshots as $snapshot) {
        echo " - {$snapshot['student_id']} | {$snapshot['completed']}/{$snapshot['total']} | remaining {$snapshot['remaining']} | retention {$snapshot['retention']} | policy "
            . ($snapshot['policy_applies'] ? ($snapshot['policy_eligible'] ? 'eligible' : 'paused') : 'none')
            . " | thrice_failed {$snapshot['thrice_failed']} | has_plan "
            . ($snapshot['has_plan'] ? 'yes' : 'no')
            . "\n";
    }
}

echo "\nDone.\n";
