<?php
session_start();

if (!isset($_SESSION['admin_id']) || empty($_SESSION['admin_id'])) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Access denied.';
    exit;
}

$filename = 'student_masterlist_template.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'wb');
if ($output === false) {
    http_response_code(500);
    echo 'Unable to generate template.';
    exit;
}

fputcsv($output, ['Student Number', 'Last name', 'First name']);
fputcsv($output, ['220100001', 'Dela Cruz', 'Juan']);

fclose($output);
exit;
