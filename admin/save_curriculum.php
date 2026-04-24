<?php
require_once __DIR__ . '/../config/config.php';

if (!isset($_SESSION['admin_username']) && !isset($_SESSION['admin_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

require __DIR__ . '/../program_coordinator/save_curriculum.php';
