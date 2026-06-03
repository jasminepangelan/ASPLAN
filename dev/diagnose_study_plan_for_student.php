<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../student/generate_study_plan.php';

// Usage: open this file in the browser as
// dev/diagnose_study_plan_for_student.php?student_id=220100064

$student_id = trim((string)($_GET['student_id'] ?? ''));
if ($student_id === '') {
    echo "Provide student_id as query param, e.g. ?student_id=220100064";
    exit;
}

try {
    $gen = StudyPlanGenerator::createForStudent($student_id);
} catch (Throwable $e) {
    echo "Generator instantiation failed: " . htmlspecialchars($e->getMessage());
    exit;
}


$out = [];
$out['effective_term'] = $gen->getEffectiveCurrentTerm();
$out['completion_stats'] = $gen->getCompletionStats();
$out['plan_coverage'] = $gen->getPlanCoverage();

// Use reflection to read internal state for diagnostics
$rc = new ReflectionClass($gen);
// ordered terms (private method)
try {
    $rm = $rc->getMethod('getOrderedCurriculumTerms');
    $rm->setAccessible(true);
    $out['ordered_terms'] = $rm->invoke($gen);
} catch (ReflectionException $e) {
    $out['ordered_terms'] = null;
}

// semester_grade_history and internal course lists
foreach (['semester_grade_history', 'all_courses', 'completed_courses', 'failed_courses', 'inc_courses', 'dropped_courses'] as $prop) {
    try {
        $p = $rc->getProperty($prop);
        $p->setAccessible(true);
        $out[$prop] = $p->getValue($gen);
    } catch (ReflectionException $e) {
        $out[$prop] = null;
    }
}

header('Content-Type: application/json');
echo json_encode($out, JSON_PRETTY_PRINT);

exit;
