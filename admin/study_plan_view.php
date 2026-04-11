<?php
require_once __DIR__ . '/../config/config.php';

if (!isset($_SESSION['admin_id']) && !isset($_SESSION['admin_username'])) {
    header('Location: ../index.html');
    exit();
}

require __DIR__ . '/../program_coordinator/study_plan_view.php';
