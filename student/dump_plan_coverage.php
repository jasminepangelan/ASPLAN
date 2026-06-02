<?php
// CLI helper: dump study plan coverage JSON for a student
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/generate_study_plan.php';

if (PHP_SAPI !== 'cli') {
    echo "This script is CLI-only.\n";
    exit(1);
}

if ($argc < 2) {
    echo "Usage: php student/dump_plan_coverage.php <student_id>\n";
    exit(1);
}

$student_id = $argv[1];
$generator = StudyPlanGenerator::createForStudent($student_id);
$plan = $generator->generateOptimizedPlan();
$coverage = $generator->getPlanCoverage();

$out = [
    'student_id' => $student_id,
    'plan' => $plan,
    'coverage' => $coverage,
];

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
