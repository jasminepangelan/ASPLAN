<?php
require_once __DIR__ . '/../config/config.php';

if (!isset($_SESSION['admin_username']) && !isset($_SESSION['admin_id'])) {
    header('Location: ../index.html');
    exit();
}

require __DIR__ . '/../program_coordinator/curriculum_management.php';
