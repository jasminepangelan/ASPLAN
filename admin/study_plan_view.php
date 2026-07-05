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

header('Location: ../student/study_plan.php?admin_view=1&student_id=' . urlencode($studentId));
exit();
