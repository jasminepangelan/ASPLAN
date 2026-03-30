<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/checklist_service.php';
require_once __DIR__ . '/../includes/env_loader.php';

header('Content-Type: application/json');

$useLaravelBridge = getenv('USE_LARAVEL_BRIDGE') === '1';

elsInfo('Checklist save attempted', [], 'checklist_api');

try {
    // Validate request method
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        elsWarning('Invalid request method for checklist save', ['method' => $_SERVER['REQUEST_METHOD']], 'checklist_api');
        throw new Exception('Invalid request method');
    }

    // Validate user access
    $accessResult = csValidateUserAccess();
    if (!$accessResult['authorized']) {
        http_response_code(403);
        elsWarning('Unauthorized checklist save attempt', ['error' => $accessResult['error']], 'checklist_api');
        throw new Exception($accessResult['error']);
    }

    // Validate CSRF token
    $csrfResult = csValidateCSRFToken();
    if (!$csrfResult['valid']) {
        http_response_code(403);
        elsWarning('Invalid CSRF token for checklist save', ['error' => $csrfResult['error']], 'checklist_api');
        throw new Exception($csrfResult['error']);
    }

    // Parse input
    $inputResult = csParseChecklistInput();
    if ($inputResult['error']) {
        elsWarning('Failed to parse checklist input', ['error' => $inputResult['error']], 'checklist_api');
        throw new Exception($inputResult['error']);
    }

    $mode = $inputResult['mode'];
    $studentId = $inputResult['student_id'];
    $courses = $inputResult['courses'];
    $grades = $inputResult['grades'];
    $remarks = $inputResult['evaluator_remarks'];
    $professors = $inputResult['professors'];

    if ($useLaravelBridge) {
        $bridgeUrl = laravelBridgeUrl('/api/save-checklist');
        $bridgePayload = [
            'student_id' => $studentId,
            'courses' => $courses,
            'professor_instructors' => $professors,
        ];

        if ($mode === 'bulk') {
            $bridgePayload['bulk_approve'] = true;
            $bridgePayload['grades'] = $grades;
            $bridgePayload['professors'] = $professors;
        } else {
            $bridgePayload['final_grades'] = $grades;
            $bridgePayload['evaluator_remarks'] = $remarks;
        }

        $payloadJson = json_encode($bridgePayload);
        $response = false;

        if (function_exists('curl_init')) {
            $ch = curl_init($bridgeUrl);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payloadJson,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            ]);
            $response = curl_exec($ch);
            curl_close($ch);
        } else {
            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-Type: application/json\r\n",
                    'content' => $payloadJson,
                    'timeout' => 10,
                ],
            ]);
            $response = @file_get_contents($bridgeUrl, false, $context);
        }

        if ($response !== false) {
            echo $response;
            exit;
        }
    }

    // Get database connection
    $conn = getDBConnection();

    elsInfo('Checklist processing', ['mode' => $mode, 'student_id' => $studentId, 'course_count' => count($courses)], 'checklist_api');

    // Process bulk approval
    if ($mode === 'bulk') {
        $saveResult = csSaveBulkChecklistApprovals($conn, $studentId, $courses, $grades, $professors);
        
        if ($saveResult['success'] || $saveResult['count'] > 0) {
            elsInfo('Bulk checklist approval successful', ['student_id' => $studentId, 'count' => $saveResult['count']], 'checklist_api');
            echo json_encode([
                'status' => 'success',
                'message' => "Bulk approved {$saveResult['count']} records"
            ]);
        } else {
            elsError('Bulk checklist approval failed', ['student_id' => $studentId, 'errors' => $saveResult['errors']], 'checklist_api');
            throw new Exception('Failed to save bulk records: ' . implode('; ', $saveResult['errors']));
        }
    } else {
        // Standard save
        $saveResult = csSaveChecklistRecords($conn, $studentId, $courses, $grades, $remarks, $professors);
        
        if ($saveResult['success'] || $saveResult['count'] > 0) {
            elsInfo('Checklist save successful', ['student_id' => $studentId, 'count' => $saveResult['count'], 'errors' => count($saveResult['errors'])], 'checklist_api');
            echo json_encode([
                'status' => 'success',
                'message' => "Successfully saved {$saveResult['count']} records"
            ]);
        } else {
            elsError('Checklist save failed', ['student_id' => $studentId, 'errors' => $saveResult['errors']], 'checklist_api');
            throw new Exception('Failed to save records: ' . implode('; ', $saveResult['errors']));
        }
    }

    closeDBConnection($conn);

} catch (Exception $e) {
    elsError('Checklist API exception', ['error' => $e->getMessage()], 'checklist_api', $e);
    echo json_encode([
        'status' => 'error',
        'message' => elsSanitizeForUser($e->getMessage())
    ]);
}
?>
