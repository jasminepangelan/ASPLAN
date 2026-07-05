<?php
require_once __DIR__ . '/../config/config.php';

if (!isset($_SESSION['admin_id']) && !isset($_SESSION['admin_username'])) {
    header('Location: ../index.html');
    exit();
}

$studentId = isset($_GET['student_id']) ? trim((string)$_GET['student_id']) : '';
if ($studentId === '') {
    die('Invalid student ID.');
}

define('ASPLAN_ADMIN_STUDY_PLAN_VIEW', true);
$adminStudyPlanStudentId = $studentId;

require __DIR__ . '/../student/study_plan.php';
