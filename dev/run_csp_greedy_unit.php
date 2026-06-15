<?php
// Lightweight CLI harness to run a single CSP+Greedy scenario without requiring DB
// Usage: php dev/run_csp_greedy_unit.php

require_once __DIR__ . '/../student/generate_study_plan.php';

if (php_sapi_name() !== 'cli') {
    echo "This script is CLI-only.\n";
    exit(1);
}

// Helpers copied/adapted from the synthetic suite
function makeCourse(string $code, string $year, string $semester, array $options = []): array {
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

function buildTermMaxUnits(int $maxUnits = 21): array {
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

// Create instance without running constructor (avoids DB calls)
$rc = new ReflectionClass(StudyPlanGenerator::class);
$generator = $rc->newInstanceWithoutConstructor();

// Seed internal properties to simulate a student with a failed course
$allCourses = [];
$courses = [
    makeCourse('RT101', '1st Yr', '1st Sem', ['is_failed' => true, 'needs_retake' => true]),
    makeCourse('RT102', '1st Yr', '1st Sem'),
    makeCourse('EX201', '2nd Yr', '1st Sem'),
];

foreach ($courses as $c) {
    $allCourses[$c['code']] = $c;
}

// Set private properties via reflection
$set = function($obj, $name, $value) use ($rc) {
    $p = $rc->getProperty($name);
    $p->setAccessible(true);
    $p->setValue($obj, $value);
};

$set($generator, 'all_courses', $allCourses);
$prereqMap = [];
foreach ($allCourses as $code => $c) {
    $prereqMap[$code] = $c['prereqs'] ?? [];
}
$set($generator, 'prerequisite_map', $prereqMap);
$set($generator, 'completed_courses', []);
$set($generator, 'failed_courses', ['RT101']);
$set($generator, 'inc_courses', []);
$set($generator, 'dropped_courses', []);
$set($generator, 'term_max_units', buildTermMaxUnits(21));
$set($generator, 'retention_status', 'Warning');
$set($generator, 'retention_history', []);
$set($generator, 'semester_grade_history', []);
$set($generator, 'course_failure_counts', ['RT101' => 1]);
$set($generator, 'thrice_failed_courses', []);
$set($generator, 'policy_gate_status', ['applies' => false, 'eligible' => true, 'reasons' => []]);

// Some methods may expect student_id and program fields
$set($generator, 'student_id', 'SIMULATED_STUDENT');
$set($generator, 'program_label', 'SIMULATED_PROGRAM');

// Invoke greedy generator method
$m = $rc->getMethod('generateOptimizedPlan');
$m->setAccessible(true);

try {
    $plan = $m->invoke($generator);
    echo json_encode(['status' => 'ok', 'plan' => $plan], JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

exit(0);
