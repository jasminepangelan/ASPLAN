<?php

if (!function_exists('psProgramShiftRequiredTables')) {
    function psProgramShiftRequiredTables(): array
    {
        return [
            'program_shift_requests',
            'program_shift_approvals',
            'program_shift_audit',
            'program_shift_credit_map',
        ];
    }
}

if (!function_exists('psProgramShiftSchemaStatements')) {
    function psProgramShiftSchemaStatements(): array
    {
        return [
            'create_program_shift_requests' => "CREATE TABLE IF NOT EXISTS program_shift_requests (
                id INT AUTO_INCREMENT PRIMARY KEY,
                request_code VARCHAR(40) NOT NULL,
                student_number VARCHAR(20) NOT NULL,
                student_name VARCHAR(255) DEFAULT NULL,
                current_program VARCHAR(255) NOT NULL,
                requested_program VARCHAR(255) NOT NULL,
                reason TEXT DEFAULT NULL,
                status ENUM('pending_adviser','pending_current_coordinator','pending_destination_coordinator','pending_coordinator','approved','rejected','cancelled') NOT NULL DEFAULT 'pending_adviser',
                adviser_action_by VARCHAR(100) DEFAULT NULL,
                adviser_action_name VARCHAR(255) DEFAULT NULL,
                adviser_action_at DATETIME DEFAULT NULL,
                adviser_comment TEXT DEFAULT NULL,
                coordinator_action_by VARCHAR(100) DEFAULT NULL,
                coordinator_action_name VARCHAR(255) DEFAULT NULL,
                coordinator_action_at DATETIME DEFAULT NULL,
                coordinator_comment TEXT DEFAULT NULL,
                executed_by VARCHAR(100) DEFAULT NULL,
                executed_at DATETIME DEFAULT NULL,
                execution_note TEXT DEFAULT NULL,
                requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uk_program_shift_request_code (request_code),
                KEY idx_program_shift_student (student_number),
                KEY idx_program_shift_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
            'normalize_program_shift_requests_status' => "ALTER TABLE program_shift_requests MODIFY COLUMN status ENUM('pending_adviser','pending_current_coordinator','pending_destination_coordinator','pending_coordinator','approved','rejected','cancelled') NOT NULL DEFAULT 'pending_adviser'",
            'create_program_shift_approvals' => "CREATE TABLE IF NOT EXISTS program_shift_approvals (
                id INT AUTO_INCREMENT PRIMARY KEY,
                request_id INT NOT NULL,
                stage ENUM('adviser','coordinator') NOT NULL,
                action ENUM('approve','reject') NOT NULL,
                actor_username VARCHAR(100) DEFAULT NULL,
                actor_name VARCHAR(255) DEFAULT NULL,
                actor_program VARCHAR(255) DEFAULT NULL,
                comments TEXT DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_shift_approvals_request (request_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
            'create_program_shift_audit' => "CREATE TABLE IF NOT EXISTS program_shift_audit (
                id INT AUTO_INCREMENT PRIMARY KEY,
                request_id INT DEFAULT NULL,
                event_key VARCHAR(100) NOT NULL,
                event_message VARCHAR(255) NOT NULL,
                actor_username VARCHAR(100) DEFAULT NULL,
                actor_role VARCHAR(60) DEFAULT NULL,
                metadata_json LONGTEXT DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_shift_audit_request (request_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
            'create_program_shift_credit_map' => "CREATE TABLE IF NOT EXISTS program_shift_credit_map (
                id INT AUTO_INCREMENT PRIMARY KEY,
                request_id INT NOT NULL,
                student_number VARCHAR(20) NOT NULL,
                source_program VARCHAR(255) NOT NULL,
                destination_program VARCHAR(255) NOT NULL,
                source_course_code VARCHAR(50) NOT NULL,
                destination_course_code VARCHAR(50) NOT NULL,
                final_grade VARCHAR(20) DEFAULT NULL,
                evaluator_remarks VARCHAR(255) DEFAULT NULL,
                mapped_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_shift_credit_request (request_id),
                KEY idx_shift_credit_student (student_number)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        ];
    }
}

if (!function_exists('psProgramShiftConnectionError')) {
    function psProgramShiftConnectionError($conn): string
    {
        return trim((string) ($conn->error ?? 'Unknown database error'));
    }
}

if (!function_exists('psProgramShiftTableExists')) {
    function psProgramShiftTableExists($conn, string $tableName): bool
    {
        $safeTableName = preg_replace('/[^A-Za-z0-9_]/', '', $tableName);
        if ($safeTableName === '') {
            return false;
        }

        $result = $conn->query("SHOW TABLES LIKE '" . $safeTableName . "'");
        return $result && (int) ($result->num_rows ?? 0) > 0;
    }
}

if (!function_exists('psProgramShiftSchemaIssues')) {
    function psProgramShiftSchemaIssues($conn): array
    {
        $issues = [];

        foreach (psProgramShiftRequiredTables() as $tableName) {
            if (!psProgramShiftTableExists($conn, $tableName)) {
                $issues[] = 'Missing table `' . $tableName . '`.';
            }
        }

        if (psProgramShiftTableExists($conn, 'program_shift_requests')) {
            $statusColumn = $conn->query("SHOW COLUMNS FROM program_shift_requests LIKE 'status'");
            if (!$statusColumn || (int) ($statusColumn->num_rows ?? 0) === 0) {
                $issues[] = 'Missing `program_shift_requests.status` column.';
            } else {
                $column = $statusColumn->fetch_assoc();
                $statusType = strtolower((string) ($column['Type'] ?? ''));
                $requiredStatuses = [
                    'pending_adviser',
                    'pending_current_coordinator',
                    'pending_destination_coordinator',
                    'pending_coordinator',
                    'approved',
                    'rejected',
                    'cancelled',
                ];

                foreach ($requiredStatuses as $statusName) {
                    if (strpos($statusType, "'" . $statusName . "'") === false) {
                        $issues[] = 'Outdated `program_shift_requests.status` enum definition.';
                        break;
                    }
                }
            }
        }

        return $issues;
    }
}

if (!function_exists('psAssertProgramShiftSchemaReady')) {
    function psAssertProgramShiftSchemaReady($conn): void
    {
        $issues = psProgramShiftSchemaIssues($conn);
        if (empty($issues)) {
            return;
        }

        $message = 'Program shift schema is not ready. Run `dev/migrations/apply_program_shift_schema.php`. Details: ' . implode(' ', $issues);
        error_log($message);
        throw new RuntimeException($message);
    }
}

if (!function_exists('psRunProgramShiftSchemaMigration')) {
    function psRunProgramShiftSchemaMigration($conn): array
    {
        $beforeIssues = psProgramShiftSchemaIssues($conn);
        $executed = [];

        foreach (psProgramShiftSchemaStatements() as $step => $sql) {
            $result = $conn->query($sql);
            if ($result === false) {
                throw new RuntimeException('Program shift schema migration failed at step `' . $step . '`: ' . psProgramShiftConnectionError($conn));
            }
            $executed[] = $step;
        }

        $afterIssues = psProgramShiftSchemaIssues($conn);
        if (!empty($afterIssues)) {
            throw new RuntimeException('Program shift schema migration finished with remaining issues: ' . implode(' ', $afterIssues));
        }

        return [
            'before_issues' => $beforeIssues,
            'after_issues' => $afterIssues,
            'executed_steps' => $executed,
        ];
    }
}
