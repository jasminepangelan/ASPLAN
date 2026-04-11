<?php

namespace App\Http\Controllers\LegacyCompat;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ProgramShiftController extends Controller
{
    public function studentOverview(Request $request): JsonResponse
    {
        try {
            if (!$this->isBridgeAuthorized($request)) {
                return $this->response(false, 'Unauthorized', 403);
            }

            $studentNumber = trim((string) $request->input('student_id', ''));
            if ($studentNumber === '') {
                return $this->response(false, 'Student profile not found.', 404);
            }

            $studentRow = DB::table('student_info')
                ->where('student_number', $studentNumber)
                ->first();

            if ($studentRow === null) {
                return $this->response(false, 'Student profile not found.', 404);
            }

            $student = (array) $studentRow;
            $history = $this->loadStudentHistory($studentNumber);
            $historyStats = $this->buildStudentHistoryStats($history);

            return $this->response(true, 'Program shift overview loaded.', 200, [
                'student' => $student,
                'current_program' => trim((string) ($student['program'] ?? '')),
                'program_options' => $this->getProgramOptions(),
                'history' => $history,
                'history_stats' => $historyStats,
            ]);
        } catch (Throwable $e) {
            return $this->response(false, 'Failed to load program shift overview.', 500);
        }
    }

    public function studentSubmit(Request $request): JsonResponse
    {
        try {
            if (!$this->isBridgeAuthorized($request)) {
                return $this->response(false, 'Unauthorized', 403);
            }

            $studentNumber = trim((string) $request->input('student_id', ''));
            $requestedProgram = trim((string) $request->input('requested_program', ''));
            $reason = trim((string) $request->input('reason', ''));

            if ($studentNumber === '') {
                return $this->response(false, 'Student record not found.', 404);
            }

            $studentRow = DB::table('student_info')
                ->where('student_number', $studentNumber)
                ->first();

            if ($studentRow === null) {
                return $this->response(false, 'Student record not found.', 404);
            }

            $student = (array) $studentRow;
            $currentProgram = trim((string) ($student['program'] ?? ''));
            if ($currentProgram === '') {
                return $this->response(false, 'Your current program is not set. Please contact the administrator.', 422);
            }

            if ($requestedProgram === '') {
                return $this->response(false, 'Please select a destination program.', 422);
            }

            if (strcasecmp($this->normalizeProgramLabel($currentProgram), $this->normalizeProgramLabel($requestedProgram)) === 0) {
                return $this->response(false, 'You are already enrolled in the selected program.', 422);
            }

            if ($this->hasActiveShiftRequest($studentNumber)) {
                return $this->response(false, 'You already have a pending shift request.', 409);
            }

            $requestCode = $this->generateRequestCode();
            $studentName = $this->getStudentDisplayName($student);
            $now = now()->toDateTimeString();

            DB::table('program_shift_requests')->insert([
                'request_code' => $requestCode,
                'student_number' => $studentNumber,
                'student_name' => $studentName,
                'current_program' => $currentProgram,
                'requested_program' => $requestedProgram,
                'reason' => $reason,
                'status' => 'pending_adviser',
                'requested_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $requestId = (int) DB::table('program_shift_requests')
                ->where('request_code', $requestCode)
                ->value('id');

            $this->addAuditLog($requestId > 0 ? $requestId : null, 'request_submitted', 'Program shift request submitted by student.', $studentNumber, 'student', [
                'current_program' => $currentProgram,
                'requested_program' => $requestedProgram,
            ]);

            $this->sendProgramShiftSubmittedEmail($student, $requestCode, $currentProgram, $requestedProgram);
            $this->notifyAdvisersOfProgramShiftRequest($student, $requestCode, $currentProgram, $requestedProgram, $reason);

            return $this->response(true, 'Shift request submitted successfully.', 200, [
                'request_id' => $requestId,
                'request_code' => $requestCode,
            ]);
        } catch (Throwable $e) {
            return $this->response(false, 'Unable to save shift request.', 500);
        }
    }

    public function adviserQueue(Request $request): JsonResponse
    {
        try {
            if (!$this->isBridgeAuthorized($request)) {
                return $this->response(false, 'Unauthorized', 403);
            }

            $adviserId = (int) $request->input('adviser_id', 0);
            $username = trim((string) $request->input('adviser_username', $request->input('username', '')));

            if ($adviserId <= 0 && $username === '') {
                return $this->response(false, 'Access denied. Please log in.', 401);
            }

            $programKeys = $this->resolveAdviserProgramKeys($adviserId, $username);
            $adviserBatches = $this->resolveAdviserBatches($adviserId, $username);
            $queue = $this->loadAdviserQueue($programKeys, $adviserBatches);
            $recentLogs = $this->loadAdviserActionLog($username, $programKeys, $adviserBatches, 12);

            return $this->response(true, 'Program shift queue loaded.', 200, [
                'queue' => $queue,
                'queue_count' => count($queue),
                'recent_logs' => $recentLogs,
            ]);
        } catch (Throwable $e) {
            return $this->response(false, 'Failed to load program shift queue.', 500);
        }
    }

    public function coordinatorQueue(Request $request): JsonResponse
    {
        try {
            if (!$this->isBridgeAuthorized($request)) {
                return $this->response(false, 'Unauthorized', 403);
            }

            $username = trim((string) $request->input('username', ''));
            if ($username === '') {
                return $this->response(false, 'Access denied. Please log in.', 401);
            }

            $programKeys = $this->resolveCoordinatorProgramKeys($username);
            $queue = $this->loadCoordinatorQueue($programKeys);

            return $this->response(true, 'Program shift queue loaded.', 200, [
                'queue' => $queue,
                'queue_count' => count($queue),
            ]);
        } catch (Throwable $e) {
            return $this->response(false, 'Failed to load program shift queue.', 500);
        }
    }

    public function adviserDecision(Request $request): JsonResponse
    {
        try {
            if (!$this->isBridgeAuthorized($request)) {
                return $this->response(false, 'Unauthorized', 403);
            }

            $requestId = (int) $request->input('request_id', 0);
            $action = strtolower(trim((string) $request->input('action', '')));
            $comment = trim((string) $request->input('comment', ''));
            $adviserId = (int) $request->input('adviser_id', 0);
            $actorUsername = trim((string) $request->input('adviser_username', $request->input('username', '')));
            $actorName = trim((string) $request->input('adviser_name', $request->input('full_name', $actorUsername)));

            if ($requestId <= 0) {
                return $this->response(false, 'Shift request not found.', 422);
            }
            if (!in_array($action, ['approve', 'reject'], true)) {
                return $this->response(false, 'Invalid action.', 422);
            }
            if ($actorUsername === '') {
                return $this->response(false, 'Access denied. Please log in.', 401);
            }

            $programKeys = $this->resolveAdviserProgramKeys($adviserId, $actorUsername);
            $adviserBatches = $this->resolveAdviserBatches($adviserId, $actorUsername);
            $requestRow = $this->fetchShiftRequest($requestId);
            if ($requestRow === null) {
                return $this->response(false, 'Shift request not found.', 404);
            }

            if ((string) ($requestRow['status'] ?? '') !== 'pending_adviser') {
                return $this->response(false, 'This request is not pending adviser review.', 422);
            }

            if (!$this->requestMatchesAdviserScope($requestRow, $programKeys, $adviserBatches)) {
                return $this->response(false, 'You are not assigned to review this request for the selected program and batch scope.', 403);
            }

            $now = now()->toDateTimeString();
            $nextStatus = $action === 'approve' ? 'pending_current_coordinator' : 'rejected';

            DB::beginTransaction();

            try {
                $updated = DB::table('program_shift_requests')
                    ->where('id', $requestId)
                    ->where('status', 'pending_adviser')
                    ->update([
                        'status' => $nextStatus,
                        'adviser_action_by' => $actorUsername,
                        'adviser_action_name' => $actorName,
                        'adviser_action_at' => $now,
                        'adviser_comment' => $comment,
                    ]);

                if ($updated <= 0) {
                    throw new \RuntimeException('This request was already processed by another user. Please refresh the queue.');
                }

                DB::table('program_shift_approvals')->insert([
                    'request_id' => $requestId,
                    'stage' => 'adviser',
                    'action' => $action,
                    'actor_username' => $actorUsername,
                    'actor_name' => $actorName,
                    'actor_program' => implode(', ', $programKeys),
                    'comments' => $comment,
                    'created_at' => $now,
                ]);

                $this->addAuditLog($requestId, 'adviser_' . $action, $action === 'approve'
                    ? 'Adviser approved and forwarded the shift request to the current-program coordinator.'
                    : 'Adviser rejected the shift request.', $actorUsername, 'adviser', [
                    'comment' => $comment,
                    'next_status' => $nextStatus,
                ]);

                DB::commit();
            } catch (Throwable $e) {
                DB::rollBack();
                return $this->response(false, $e->getMessage() !== '' ? $e->getMessage() : 'Unable to process adviser decision.', 500);
            }

            $studentEmail = $this->fetchStudentEmail((string) ($requestRow['student_number'] ?? ''));
            if ($studentEmail !== '') {
                $this->sendProgramShiftStatusEmail(
                $studentEmail,
                (string) ($requestRow['student_name'] ?? ''),
                (string) ($requestRow['request_code'] ?? ''),
                $action === 'approve' ? 'Pending Current Program Coordinator Review' : 'Rejected by Adviser',
                (string) ($requestRow['current_program'] ?? ''),
                (string) ($requestRow['requested_program'] ?? ''),
                $action === 'approve'
                    ? 'Your request has been forwarded to the Program Coordinator of your current program.'
                    : 'Your request was rejected by the Adviser.'
            );
        }

        return $this->response(true, $action === 'approve'
                ? 'Request approved and forwarded to the current-program coordinator.'
                : 'Request rejected by adviser.');
        } catch (Throwable $e) {
            return $this->response(false, 'Unable to process adviser decision.', 500);
        }
    }

    public function coordinatorDecision(Request $request): JsonResponse
    {
        try {
            if (!$this->isBridgeAuthorized($request)) {
                return $this->response(false, 'Unauthorized', 403);
            }

            $requestId = (int) $request->input('request_id', 0);
            $action = strtolower(trim((string) $request->input('action', '')));
            $comment = trim((string) $request->input('comment', ''));
            $actorUsername = trim((string) $request->input('username', $request->input('coordinator_username', '')));
            $actorName = trim((string) $request->input('full_name', $request->input('coordinator_name', $actorUsername)));

            if ($requestId <= 0) {
                return $this->response(false, 'Shift request not found.', 422);
            }
            if (!in_array($action, ['approve', 'reject'], true)) {
                return $this->response(false, 'Invalid action.', 422);
            }
            if ($actorUsername === '') {
                return $this->response(false, 'Access denied. Please log in.', 401);
            }

            $programKeys = $this->resolveCoordinatorProgramKeys($actorUsername);
            $requestRow = $this->fetchShiftRequest($requestId);
            if ($requestRow === null) {
                return $this->response(false, 'Shift request not found.', 404);
            }

            $stage = $this->resolveCoordinatorStage($requestRow);
            if ($stage === '') {
                return $this->response(false, 'This request is not pending Program Coordinator review.', 422);
            }

            $scopeProgram = $stage === 'current'
                ? (string) ($requestRow['current_program'] ?? '')
                : (string) ($requestRow['requested_program'] ?? '');
            if (!$this->programMatchesActorKeys($scopeProgram, $programKeys)) {
                return $this->response(false, $stage === 'current'
                    ? 'You are not assigned to review the student\'s current program.'
                    : 'You are not assigned to review this destination program.', 403);
            }

            $now = now()->toDateTimeString();
            $newStatus = $action === 'approve'
                ? ($stage === 'current' ? 'pending_destination_coordinator' : 'approved')
                : 'rejected';
            $executionResult = null;

            DB::beginTransaction();

            try {
                $updated = DB::table('program_shift_requests')
                    ->where('id', $requestId)
                    ->where('status', (string) ($requestRow['status'] ?? ''))
                    ->update([
                        'status' => $newStatus,
                        'coordinator_action_by' => $actorUsername,
                        'coordinator_action_name' => $actorName,
                        'coordinator_action_at' => $now,
                        'coordinator_comment' => $comment,
                    ]);

                if ($updated <= 0) {
                    throw new \RuntimeException('This request was already processed by another user. Please refresh the queue.');
                }

                DB::table('program_shift_approvals')->insert([
                    'request_id' => $requestId,
                    'stage' => 'coordinator',
                    'action' => $action,
                    'actor_username' => $actorUsername,
                    'actor_name' => $actorName,
                    'actor_program' => implode(', ', $programKeys),
                    'comments' => $comment,
                    'created_at' => $now,
                ]);

                $auditMessage = 'Program Coordinator ' . $action . 'd the shift request.';
                if ($stage === 'current' && $action === 'approve') {
                    $auditMessage = 'Current-program coordinator approved and forwarded the shift request to the destination-program coordinator.';
                } elseif ($stage === 'current' && $action === 'reject') {
                    $auditMessage = 'Current-program coordinator rejected the shift request.';
                } elseif ($stage === 'destination' && $action === 'approve') {
                    $auditMessage = 'Destination-program coordinator approved and executed the shift request.';
                } elseif ($stage === 'destination' && $action === 'reject') {
                    $auditMessage = 'Destination-program coordinator rejected the shift request.';
                }

                $this->addAuditLog($requestId, 'coordinator_' . $action, $auditMessage, $actorUsername, 'program_coordinator', [
                    'comment' => $comment,
                    'stage' => $stage,
                    'next_status' => $newStatus,
                ]);

                if ($action === 'approve' && $stage === 'destination') {
                    $executionResult = $this->executeApprovedShift($requestRow, $actorUsername, $now);
                    if (!$executionResult['ok']) {
                        throw new \RuntimeException((string) ($executionResult['message'] ?? 'Unable to execute approved shift.'));
                    }
                }

                DB::commit();
            } catch (Throwable $e) {
                DB::rollBack();
                return $this->response(false, $e->getMessage() !== '' ? $e->getMessage() : 'Unable to process coordinator decision.', 500);
            }

            $studentEmail = $this->fetchStudentEmail((string) ($requestRow['student_number'] ?? ''));
            if ($studentEmail !== '') {
                if ($action === 'approve' && $stage === 'current') {
                    $details = 'Your request was approved by the Program Coordinator of your current program and has been forwarded to the Program Coordinator of your requested program.';
                    $statusLabel = 'Pending Destination Program Coordinator Review';
                } elseif ($action === 'approve') {
                    $details = ((string) ($executionResult['message'] ?? 'Request approved.') . ' Shift execution completed.');
                    $statusLabel = 'Approved';
                } else {
                    $details = $stage === 'current'
                        ? 'Your request was rejected by the Program Coordinator of your current program.'
                        : 'Your request was rejected by the Program Coordinator of your requested program.';
                    $statusLabel = 'Rejected by Program Coordinator';
                }
                $this->sendProgramShiftStatusEmail(
                    $studentEmail,
                    (string) ($requestRow['student_name'] ?? ''),
                    (string) ($requestRow['request_code'] ?? ''),
                    $statusLabel,
                    (string) ($requestRow['current_program'] ?? ''),
                    (string) ($requestRow['requested_program'] ?? ''),
                    $details
                );
            }

            return $this->response(true, $action === 'approve'
                ? ($stage === 'current'
                    ? 'Request approved and forwarded to the destination-program coordinator.'
                    : 'Request approved and shift execution completed.')
                : 'Request rejected by Program Coordinator.');
        } catch (Throwable $e) {
            return $this->response(false, 'Unable to process coordinator decision.', 500);
        }
    }

    private function loadAdviserQueue(array $programKeys, array $adviserBatches): array
    {
        try {
            $rows = DB::table('program_shift_requests')
                ->where('status', 'pending_adviser')
                ->orderBy('requested_at')
                ->orderBy('id')
                ->get()
                ->map(static fn ($row): array => (array) $row)
                ->all();
        } catch (Throwable $e) {
            return [];
        }

        if (empty($programKeys) || empty($adviserBatches)) {
            return [];
        }

        return array_values(array_filter($rows, function (array $row) use ($programKeys, $adviserBatches): bool {
            return $this->requestMatchesAdviserScope($row, $programKeys, $adviserBatches);
        }));
    }

    private function loadCoordinatorQueue(array $programKeys = []): array
    {
        try {
            $rows = DB::table('program_shift_requests')
                ->whereIn('status', $this->coordinatorPendingStatuses())
                ->orderBy('adviser_action_at')
                ->orderBy('requested_at')
                ->orderBy('id')
                ->get()
                ->map(static fn ($row): array => (array) $row)
                ->all();

            if (empty($programKeys)) {
                return $rows;
            }

            return array_values(array_filter($rows, function (array $row) use ($programKeys): bool {
                $stage = $this->resolveCoordinatorStage($row);
                $scopeProgram = $stage === 'current'
                    ? (string) ($row['current_program'] ?? '')
                    : (string) ($row['requested_program'] ?? '');
                return $this->programMatchesActorKeys($scopeProgram, $programKeys);
            }));
        } catch (Throwable $e) {
            return [];
        }
    }

    private function loadAdviserActionLog(string $actorUsername, array $programKeys, array $adviserBatches, int $limit = 12): array
    {
        $actorUsername = trim($actorUsername);
        if ($actorUsername === '') {
            return [];
        }

        $limit = max(1, min(50, $limit));

        try {
            $rows = DB::table('program_shift_approvals as a')
                ->join('program_shift_requests as r', 'r.id', '=', 'a.request_id')
                ->where('a.stage', 'adviser')
                ->where('a.actor_username', $actorUsername)
                ->orderByDesc('a.created_at')
                ->orderByDesc('a.id')
                ->limit($limit)
                ->get([
                    'a.request_id',
                    'a.action',
                    'a.comments',
                    DB::raw('a.created_at AS action_at'),
                    'r.request_code',
                    'r.student_number',
                    'r.student_name',
                    'r.current_program',
                    'r.requested_program',
                    'r.status',
                ])
                ->map(static fn ($row): array => (array) $row)
                ->all();
        } catch (Throwable $e) {
            return [];
        }

        if (empty($programKeys) || empty($adviserBatches)) {
            return [];
        }

        return array_values(array_filter($rows, function (array $row) use ($programKeys, $adviserBatches): bool {
            return $this->requestMatchesAdviserScope($row, $programKeys, $adviserBatches);
        }));
    }

    private function fetchShiftRequest(int $requestId): ?array
    {
        try {
            $row = DB::table('program_shift_requests')
                ->where('id', $requestId)
                ->first();

            return $row ? (array) $row : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    private function loadStudentHistory(string $studentNumber): array
    {
        try {
            return DB::table('program_shift_requests')
                ->where('student_number', $studentNumber)
                ->orderByDesc('requested_at')
                ->orderByDesc('id')
                ->get()
                ->map(static fn ($row): array => (array) $row)
                ->all();
        } catch (Throwable $e) {
            return [];
        }
    }

    private function buildStudentHistoryStats(array $history): array
    {
        $stats = [
            'all' => count($history),
            'pending' => 0,
            'approved' => 0,
            'rejected' => 0,
        ];

        foreach ($history as $row) {
            $status = (string) ($row['status'] ?? '');
            if ($status === 'approved') {
                $stats['approved']++;
            } elseif ($status === 'rejected') {
                $stats['rejected']++;
            }

            if (in_array($status, array_merge(['pending_adviser'], $this->coordinatorPendingStatuses()), true)) {
                $stats['pending']++;
            }
        }

        return $stats;
    }

    private function getProgramOptions(): array
    {
        try {
            $sources = [
                ['table' => 'curriculum_courses', 'column' => 'program'],
                ['table' => 'student_info', 'column' => 'program'],
                ['table' => 'program_shift_requests', 'column' => 'current_program'],
                ['table' => 'program_shift_requests', 'column' => 'requested_program'],
            ];

            $unique = [];
            foreach ($sources as $source) {
                $table = $source['table'];
                $column = $source['column'];

                if (!Schema::hasTable($table)) {
                    continue;
                }

                $rows = DB::table($table)
                    ->selectRaw('DISTINCT TRIM(' . $column . ') AS program')
                    ->whereNotNull($column)
                    ->whereRaw('TRIM(' . $column . ') != ""')
                    ->orderBy($column)
                    ->get();

                foreach ($rows as $row) {
                    $program = trim((string) ($row->program ?? ''));
                    if ($program === '') {
                        continue;
                    }

                    $key = strtoupper($program);
                    if (!isset($unique[$key])) {
                        $unique[$key] = $program;
                    }
                }
            }

            $options = array_values($unique);
            natcasesort($options);
            return array_values($options);
        } catch (Throwable $e) {
            return [];
        }
    }

    private function hasActiveShiftRequest(string $studentNumber): bool
    {
        try {
            return DB::table('program_shift_requests')
                ->where('student_number', $studentNumber)
                ->whereIn('status', array_merge(['pending_adviser'], $this->coordinatorPendingStatuses()))
                ->exists();
        } catch (Throwable $e) {
            return false;
        }
    }

    private function executeApprovedShift(array $requestRow, string $actorUsername, string $timestamp): array
    {
        $requestId = (int) ($requestRow['id'] ?? 0);
        $studentNumber = trim((string) ($requestRow['student_number'] ?? ''));
        $sourceProgram = trim((string) ($requestRow['current_program'] ?? ''));
        $destinationProgram = trim((string) ($requestRow['requested_program'] ?? ''));

        if ($requestId <= 0 || $studentNumber === '' || $sourceProgram === '' || $destinationProgram === '') {
            return ['ok' => false, 'message' => 'Missing program information for execution.'];
        }

        $sourceCurriculumYear = $this->resolveStudentCurriculumYear($studentNumber, $sourceProgram);
        $destinationCurriculumYear = $this->latestCurriculumYear($destinationProgram);
        $destinationCourses = $this->fetchCurriculumCourses($destinationProgram, $destinationCurriculumYear);
        $sourceCourses = $this->fetchCurriculumCourses($sourceProgram, $sourceCurriculumYear);
        $canAutoCredit = !empty($destinationCourses) && !empty($sourceCourses);
        $autoCreditSkippedReason = '';
        if (!$canAutoCredit) {
            $autoCreditSkippedReason = 'Auto-credit skipped because curriculum entries are missing for the source or destination program.';
        }

        $sourceCourseIndex = [];
        if ($canAutoCredit) {
            foreach ($sourceCourses as $sourceCourseRow) {
                $signature = $this->buildCourseSignature($sourceCourseRow);
                if (!isset($sourceCourseIndex[$signature])) {
                    $sourceCourseIndex[$signature] = [];
                }
                $sourceCourseIndex[$signature][] = $sourceCourseRow;
            }
        }

        $credited = 0;
        $hasGradeSubmittedAt = Schema::hasColumn('student_checklists', 'grade_submitted_at');

        if ($canAutoCredit) {
            foreach ($destinationCourses as $destCourse) {
                $courseCode = trim((string) ($destCourse['course_code'] ?? ''));
                $signature = $this->buildCourseSignature($destCourse);
                if ($courseCode === '' || !isset($sourceCourseIndex[$signature])) {
                    continue;
                }

                $gradeRow = null;
                $finalGrade = '';
                $sourceCourseCode = '';
                foreach ($sourceCourseIndex[$signature] as $sourceCourseRow) {
                    $candidateSourceCode = trim((string) ($sourceCourseRow['course_code'] ?? ''));
                    if ($candidateSourceCode === '') {
                        continue;
                    }

                    $candidateGradeRow = $this->fetchLatestChecklistGrade($studentNumber, $candidateSourceCode);
                    if ($candidateGradeRow === null) {
                        continue;
                    }

                    $candidateFinalGrade = trim((string) ($candidateGradeRow['final_grade'] ?? ''));
                    if (!$this->gradeIsPassing($candidateFinalGrade)) {
                        continue;
                    }

                    $gradeRow = $candidateGradeRow;
                    $finalGrade = $candidateFinalGrade;
                    $sourceCourseCode = $candidateSourceCode;
                    break;
                }

                if ($gradeRow === null || $sourceCourseCode === '') {
                    continue;
                }

                $remarks = trim((string) ($gradeRow['evaluator_remarks'] ?? ''));
                if ($remarks === '') {
                    $remarks = 'Credited (Shift Equivalency)';
                } elseif (stripos($remarks, 'credited') === false) {
                    $remarks .= ' | Credited (Shift Equivalency)';
                }

                $existingRow = DB::table('student_checklists')
                    ->where('student_id', $studentNumber)
                    ->whereRaw('TRIM(course_code) = ?', [$courseCode])
                    ->orderByDesc('id')
                    ->first();

                if ($existingRow) {
                    $updatePayload = [
                        'final_grade' => $finalGrade,
                        'evaluator_remarks' => $remarks,
                        'grade_approved' => 1,
                        'approved_at' => $timestamp,
                        'approved_by' => 'shift_engine',
                        'updated_at' => $timestamp,
                        'submitted_by' => 'shift_engine',
                    ];
                    if ($hasGradeSubmittedAt) {
                        $updatePayload['grade_submitted_at'] = $timestamp;
                    }

                    DB::table('student_checklists')
                        ->where('id', (int) $existingRow->id)
                        ->update($updatePayload);
                } else {
                    $insertPayload = [
                        'student_id' => $studentNumber,
                        'course_code' => $courseCode,
                        'status' => 'Taken',
                        'final_grade' => $finalGrade,
                        'evaluator_remarks' => $remarks,
                        'submitted_by' => 'shift_engine',
                        'grade_approved' => 1,
                        'approved_at' => $timestamp,
                        'approved_by' => 'shift_engine',
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ];
                    if ($hasGradeSubmittedAt) {
                        $insertPayload['grade_submitted_at'] = $timestamp;
                    }

                    DB::table('student_checklists')->insert($insertPayload);
                }

                DB::table('program_shift_credit_map')->insert([
                    'request_id' => $requestId,
                    'student_number' => $studentNumber,
                    'source_program' => $sourceProgram,
                    'destination_program' => $destinationProgram,
                    'source_course_code' => $sourceCourseCode,
                    'destination_course_code' => $courseCode,
                    'final_grade' => $finalGrade,
                    'evaluator_remarks' => $remarks,
                    'mapped_at' => $timestamp,
                ]);

                $credited++;
            }
        }

        DB::table('student_info')
            ->where('student_number', $studentNumber)
            ->update([
                'program' => $destinationProgram,
                'curriculum_year' => $destinationCurriculumYear !== '' ? $destinationCurriculumYear : null,
            ]);

        if (Schema::hasTable('student_study_plan_overrides')) {
            DB::table('student_study_plan_overrides')
                ->where('student_id', $studentNumber)
                ->delete();
        }

        $executionNote = 'Shift executed successfully. Auto-credited courses: ' . $credited . '.';
        if (!$canAutoCredit) {
            $executionNote .= ' ' . $autoCreditSkippedReason;
        }
        DB::table('program_shift_requests')
            ->where('id', $requestId)
            ->update([
                'status' => 'approved',
                'executed_by' => $actorUsername,
                'executed_at' => $timestamp,
                'execution_note' => $executionNote,
            ]);

        $this->addAuditLog($requestId, 'shift_executed', 'Program shift executed and student program updated.', $actorUsername, 'program_coordinator', [
            'student_number' => $studentNumber,
            'source_program' => $sourceProgram,
            'destination_program' => $destinationProgram,
            'credited_courses' => $credited,
            'auto_credit_skipped' => !$canAutoCredit,
            'auto_credit_skip_reason' => $autoCreditSkippedReason,
        ]);

        return [
            'ok' => true,
            'message' => $executionNote,
            'credited_courses' => $credited,
        ];
    }

    private function fetchCurriculumCourses(string $programLabel, string $curriculumYear = ''): array
    {
        $programLabel = trim($programLabel);
        if ($programLabel === '') {
            return [];
        }

        if ($curriculumYear === '') {
            $curriculumYear = $this->latestCurriculumYear($programLabel);
        }

        try {
            if (Schema::hasTable('curriculum_courses')) {
                $programLabels = $this->resolveCurriculumProgramLabels($programLabel);
                $query = DB::table('curriculum_courses')
                    ->selectRaw('TRIM(course_code) AS course_code, TRIM(course_title) AS course_title, IFNULL(credit_units_lec, 0) AS credit_units_lec, IFNULL(credit_units_lab, 0) AS credit_units_lab, IFNULL(lect_hrs_lec, 0) AS lect_hrs_lec, IFNULL(lect_hrs_lab, 0) AS lect_hrs_lab');

                if (!empty($programLabels)) {
                    $query->where(function ($builder) use ($programLabels): void {
                        foreach (array_values($programLabels) as $index => $label) {
                            $method = $index === 0 ? 'whereRaw' : 'orWhereRaw';
                            $builder->{$method}('UPPER(TRIM(program)) = ?', [strtoupper(trim($label))]);
                        }
                    });
                } else {
                    $query->whereRaw('TRIM(program) = ?', [$programLabel]);
                }

                if ($curriculumYear !== '') {
                    $query->where('curriculum_year', (int) $curriculumYear);
                }

                $courses = $query
                    ->orderBy('course_code')
                    ->get()
                    ->map(static fn ($row): array => (array) $row)
                    ->all();
                if (!empty($courses)) {
                    return $courses;
                }
            }

            if (!Schema::hasTable('cvsucarmona_courses')) {
                return [];
            }

            $tokens = $this->resolveProgramTokens($programLabel);
            if (empty($tokens)) {
                return [];
            }

            $conditions = [];
            $bindings = [];
            foreach ($tokens as $token) {
                $conditions[] = 'FIND_IN_SET(?, REPLACE(UPPER(programs), " ", "")) > 0';
                $bindings[] = $token;
            }

            $curriculumYearClause = '';
            if ($curriculumYear !== '') {
                $curriculumYearClause = ' AND TRIM(SUBSTRING_INDEX(curriculumyear_coursecode, "_", 1)) = ?';
                $bindings[] = $curriculumYear;
            }

            $sql = 'SELECT DISTINCT
                        TRIM(SUBSTRING_INDEX(curriculumyear_coursecode, "_", -1)) AS course_code,
                        TRIM(course_title) AS course_title,
                        IFNULL(credit_units_lec, 0) AS credit_units_lec,
                        IFNULL(credit_units_lab, 0) AS credit_units_lab,
                        IFNULL(lect_hrs_lec, 0) AS lect_hrs_lec,
                        IFNULL(lect_hrs_lab, 0) AS lect_hrs_lab
                    FROM cvsucarmona_courses
                    WHERE (' . implode(' OR ', $conditions) . ')' . $curriculumYearClause;

            $rows = DB::select($sql, $bindings);

            return array_map(static fn ($row): array => (array) $row, $rows);
        } catch (Throwable $e) {
            return [];
        }
    }

    private function resolveCurriculumProgramLabels(string $programLabel): array
    {
        $candidates = [];
        $values = [
            trim($programLabel),
            $this->canonicalProgramLabel($this->normalizeProgramKey($programLabel)),
        ];

        foreach ($values as $value) {
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }

            $candidates[$value] = true;
            $normalized = strtoupper(trim((string) preg_replace('/\s+/', ' ', $value)));

            if ($normalized === 'BACHELOR OF SCIENCE IN BUSINESS ADMINISTRATION MAJOR IN MARKETING MANAGEMENT') {
                $candidates['Bachelor of Science in Business Administration - Major in Marketing Management'] = true;
            } elseif ($normalized === 'BACHELOR OF SCIENCE IN BUSINESS ADMINISTRATION MAJOR IN HUMAN RESOURCE MANAGEMENT') {
                $candidates['Bachelor of Science in Business Administration - Major in Human Resource Management'] = true;
            } elseif ($normalized === 'BACHELOR OF SECONDARY EDUCATION MAJOR IN MATHEMATICS') {
                $candidates['Bachelor of Secondary Education major Math'] = true;
            } elseif ($normalized === 'BACHELOR OF SECONDARY EDUCATION MAJOR IN MATH') {
                $candidates['Bachelor of Secondary Education Major in Mathematics'] = true;
            }
        }

        return array_keys($candidates);
    }

    private function latestCurriculumYear(string $programLabel): string
    {
        $programLabels = $this->resolveCurriculumProgramLabels($programLabel);
        if (Schema::hasTable('curriculum_courses') && !empty($programLabels)) {
            $query = DB::table('curriculum_courses')->selectRaw('MAX(curriculum_year) AS latest_year');
            $query->where(function ($builder) use ($programLabels): void {
                foreach (array_values($programLabels) as $index => $label) {
                    $method = $index === 0 ? 'whereRaw' : 'orWhereRaw';
                    $builder->{$method}('UPPER(TRIM(program)) = ?', [strtoupper(trim($label))]);
                }
            });

            $row = $query->first();
            $year = $this->normalizeCurriculumYear((string) ($row->latest_year ?? ''));
            if ($year !== '') {
                return $year;
            }
        }

        $programKey = strtoupper(trim($this->normalizeProgramKey($programLabel)));
        if ($programKey !== '' && Schema::hasTable('program_curriculum_years')) {
            $year = $this->normalizeCurriculumYear((string) DB::table('program_curriculum_years')
                ->where('program', $programKey)
                ->max('curriculum_year'));
            if ($year !== '') {
                return $year;
            }
        }

        if (!Schema::hasTable('cvsucarmona_courses')) {
            return '';
        }

        $tokens = $this->resolveProgramTokens($programLabel);
        if (empty($tokens)) {
            return '';
        }

        $conditions = [];
        $bindings = [];
        foreach ($tokens as $token) {
            $conditions[] = 'FIND_IN_SET(?, REPLACE(UPPER(programs), " ", "")) > 0';
            $bindings[] = $token;
        }

        $rows = DB::select(
            'SELECT MAX(TRIM(SUBSTRING_INDEX(curriculumyear_coursecode, "_", 1))) AS latest_year
             FROM cvsucarmona_courses
             WHERE ' . implode(' OR ', $conditions),
            $bindings
        );
        $row = $rows[0] ?? null;

        return $this->normalizeCurriculumYear((string) ($row->latest_year ?? ''));
    }

    private function resolveStudentCurriculumYear(string $studentId, string $programLabel = ''): string
    {
        $studentId = trim($studentId);
        if ($studentId === '') {
            return $this->latestCurriculumYear($programLabel);
        }

        try {
            $row = DB::table('student_info')
                ->select(['program', 'curriculum_year'])
                ->where('student_number', $studentId)
                ->first();

            $storedProgram = trim((string) ($row->program ?? ''));
            $storedProgramKey = strtoupper(trim($this->normalizeProgramKey($storedProgram)));
            $selectedProgramKey = strtoupper(trim($this->normalizeProgramKey($programLabel)));
            $storedYear = $this->normalizeCurriculumYear((string) ($row->curriculum_year ?? ''));

            if ($selectedProgramKey !== '' && $storedProgramKey !== '' && $selectedProgramKey !== $storedProgramKey) {
                return $this->latestCurriculumYear($programLabel);
            }

            if ($storedYear !== '') {
                return $storedYear;
            }
        } catch (Throwable $e) {
            return $this->latestCurriculumYear($programLabel);
        }

        return $this->latestCurriculumYear($programLabel);
    }

    private function normalizeCurriculumYear(string $value): string
    {
        $value = trim($value);
        if (preg_match('/^\d{4}$/', $value)) {
            return $value;
        }

        return '';
    }

    private function addAuditLog(?int $requestId, string $eventKey, string $eventMessage, ?string $actorUsername, ?string $actorRole, array $metadata = []): void
    {
        DB::table('program_shift_audit')->insert([
            'request_id' => $requestId,
            'event_key' => $eventKey,
            'event_message' => $eventMessage,
            'actor_username' => $actorUsername,
            'actor_role' => $actorRole,
            'metadata_json' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => now()->toDateTimeString(),
        ]);
    }

    private function fetchStudentEmail(string $studentNumber): string
    {
        $studentNumber = trim($studentNumber);
        if ($studentNumber === '') {
            return '';
        }

        try {
            $email = DB::table('student_info')
                ->where('student_number', $studentNumber)
                ->value('email');

            $email = trim((string) $email);
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return '';
            }

            return $email;
        } catch (Throwable $e) {
            return '';
        }
    }

    private function fetchLatestChecklistGrade(string $studentNumber, string $courseCode): ?array
    {
        try {
            $row = DB::table('student_checklists')
                ->select([
                    'final_grade',
                    'evaluator_remarks',
                    'approved_by',
                    'grade_approved',
                    'final_grade_2',
                    'evaluator_remarks_2',
                    'final_grade_3',
                    'evaluator_remarks_3',
                ])
                ->where('student_id', trim($studentNumber))
                ->whereRaw('TRIM(course_code) = ?', [trim($courseCode)])
                ->where(function ($query): void {
                    $query->where(function ($nested): void {
                        $nested->whereNotNull('final_grade')
                            ->whereRaw("TRIM(final_grade) != ''");
                    })->orWhere(function ($nested): void {
                        $nested->whereNotNull('final_grade_2')
                            ->whereRaw("TRIM(final_grade_2) != ''");
                    })->orWhere(function ($nested): void {
                        $nested->whereNotNull('final_grade_3')
                            ->whereRaw("TRIM(final_grade_3) != ''");
                    });
                })
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->first();

            return $row ? $this->resolveChecklistCreditAttempt((array) $row) : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    private function sendProgramShiftSubmittedEmail(array $student, string $requestCode, string $currentProgram, string $requestedProgram): void
    {
        $email = trim((string) ($student['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $studentName = trim((string) ($student['first_name'] ?? ''));
        $lastName = trim((string) ($student['last_name'] ?? ''));
        if ($lastName !== '') {
            $studentName = trim($lastName . ', ' . $studentName);
        }

        $this->sendProgramShiftMail(
            $email,
            $studentName,
            'submitted',
            $requestCode,
            $currentProgram,
            $requestedProgram,
            'Your program shift request has been submitted successfully and is waiting for review.'
        );
    }

    private function sendProgramShiftStatusEmail(string $email, string $studentName, string $requestCode, string $statusLabel, string $currentProgram, string $requestedProgram, string $details = ''): void
    {
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $this->sendProgramShiftMail(
            $email,
            trim($studentName),
            $statusLabel,
            $requestCode,
            $currentProgram,
            $requestedProgram,
            $details
        );
    }

    private function sendProgramShiftMail(string $email, string $studentName, string $statusLabel, string $requestCode, string $currentProgram, string $requestedProgram, string $details = ''): void
    {
        try {
            $rootPath = dirname(base_path());
            if (!class_exists('EmailNotification')) {
                require_once $rootPath . '/includes/EmailNotification.php';
            }

            if (!class_exists('EmailNotification')) {
                return;
            }

            $notifier = new \EmailNotification();

            if ($statusLabel === 'submitted') {
                $notifier->sendProgramShiftSubmitted($email, $studentName, $requestCode, $currentProgram, $requestedProgram);
            } else {
                $notifier->sendProgramShiftStatusUpdate($email, $studentName, $requestCode, $statusLabel, $currentProgram, $requestedProgram, $details);
            }
        } catch (Throwable $e) {
            // Notification failures must not block the request flow.
        }
    }

    private function notifyAdvisersOfProgramShiftRequest(array $student, string $requestCode, string $currentProgram, string $requestedProgram, string $reason = ''): void
    {
        try {
            if (!Schema::hasTable('adviser') || !Schema::hasColumn('adviser', 'email')) {
                return;
            }

            $currentProgramKeys = $this->resolveProgramTokens($currentProgram);
            if (empty($currentProgramKeys)) {
                return;
            }

            $rows = DB::table('adviser')
                ->select(['id', 'email', 'first_name', 'last_name', 'username', 'program'])
                ->get();

            $rootPath = dirname(base_path());
            if (!class_exists('EmailNotification')) {
                require_once $rootPath . '/includes/EmailNotification.php';
            }
            if (!class_exists('EmailNotification')) {
                return;
            }

            $notifier = new \EmailNotification();
            $seen = [];

            $studentName = trim((string) ($student['last_name'] ?? ''));
            $studentFirst = trim((string) ($student['first_name'] ?? ''));
            if ($studentFirst !== '') {
                $studentName .= ($studentName !== '' ? ', ' : '') . $studentFirst;
            }
            if ($studentName === '') {
                $studentName = (string) ($student['student_number'] ?? '');
            }

            foreach ($rows as $row) {
                $rowArray = (array) $row;
                if (!$this->programMatchesActorKeys((string) ($rowArray['program'] ?? ''), $currentProgramKeys)) {
                    continue;
                }

                $adviserBatches = $this->resolveAdviserBatches((int) ($rowArray['id'] ?? 0), (string) ($rowArray['username'] ?? ''));
                if (!$this->studentMatchesAdviserBatches((string) ($student['student_number'] ?? ''), $adviserBatches)) {
                    continue;
                }

                $email = trim((string) ($rowArray['email'] ?? ''));
                if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    continue;
                }

                $emailKey = strtolower($email);
                if (isset($seen[$emailKey])) {
                    continue;
                }
                $seen[$emailKey] = true;

                $adviserName = trim((string) ($rowArray['first_name'] ?? '') . ' ' . (string) ($rowArray['last_name'] ?? ''));
                if ($adviserName === '') {
                    $adviserName = trim((string) ($rowArray['username'] ?? 'Adviser'));
                }
                if ($adviserName === '') {
                    $adviserName = 'Adviser';
                }

                $notifier->sendProgramShiftAdviserNotification(
                    $email,
                    $adviserName,
                    $studentName,
                    $requestCode,
                    $currentProgram,
                    $requestedProgram,
                    $reason
                );
            }
        } catch (Throwable $e) {
            // Adviser notification failures must not block submission.
        }
    }

    private function gradeIsPassing(string $gradeRaw): bool
    {
        $grade = strtoupper(trim($gradeRaw));
        if ($grade === '') {
            return false;
        }

        $failMarkers = ['5.00', 'FAILED', 'FAIL', 'INC', 'INCOMPLETE', 'DRP', 'DROP', 'DROPPED'];
        if (in_array($grade, $failMarkers, true)) {
            return false;
        }

        if (is_numeric($grade)) {
            return (float) $grade <= 3.0;
        }

        return true;
    }

    private function buildCourseSignature(array $courseRow): string
    {
        $code = strtoupper(trim((string) ($courseRow['course_code'] ?? '')));
        $title = strtoupper(trim((string) ($courseRow['course_title'] ?? '')));
        $cuLec = (int) ($courseRow['credit_units_lec'] ?? 0);
        $cuLab = (int) ($courseRow['credit_units_lab'] ?? 0);
        $lhLec = (int) ($courseRow['lect_hrs_lec'] ?? 0);
        $lhLab = (int) ($courseRow['lect_hrs_lab'] ?? 0);

        if ($title === '') {
            $title = $code;
        }

        return implode('|', [$code, $title, $cuLec, $cuLab, $lhLec, $lhLab]);
    }

    private function checklistAttemptApproved(string $remark, string $approvedBy, int $gradeApproved): bool
    {
        if ($gradeApproved === 1) {
            return true;
        }

        if (trim($approvedBy) !== '') {
            return true;
        }

        $remark = strtoupper(trim($remark));
        if ($remark === '') {
            return false;
        }

        return strpos($remark, 'APPROVED') !== false || strpos($remark, 'CREDITED') !== false;
    }

    private function resolveChecklistCreditAttempt(array $row): ?array
    {
        $attempts = [
            [
                'grade' => trim((string) ($row['final_grade'] ?? '')),
                'remark' => trim((string) ($row['evaluator_remarks'] ?? '')),
            ],
            [
                'grade' => trim((string) ($row['final_grade_2'] ?? '')),
                'remark' => trim((string) ($row['evaluator_remarks_2'] ?? '')),
            ],
            [
                'grade' => trim((string) ($row['final_grade_3'] ?? '')),
                'remark' => trim((string) ($row['evaluator_remarks_3'] ?? '')),
            ],
        ];

        $approvedBy = trim((string) ($row['approved_by'] ?? ''));
        $gradeApproved = (int) ($row['grade_approved'] ?? 0);

        for ($index = count($attempts) - 1; $index >= 0; $index--) {
            $grade = $attempts[$index]['grade'];
            if ($grade === '') {
                continue;
            }

            if (!$this->checklistAttemptApproved($attempts[$index]['remark'], $approvedBy, $gradeApproved)) {
                continue;
            }

            return [
                'final_grade' => $grade,
                'evaluator_remarks' => $attempts[$index]['remark'],
                'approved_by' => $approvedBy,
                'attempt_slot' => $index + 1,
            ];
        }

        return null;
    }

    private function resolveAdviserProgramKeys(int $adviserId, string $username): array
    {
        $keys = [];

        if ($adviserId > 0) {
            try {
                $program = DB::table('adviser')->where('id', $adviserId)->value('program');
                $keys = $this->parseProgramList((string) $program);
            } catch (Throwable $e) {
                $keys = [];
            }
        }

        if (!empty($keys)) {
            return $keys;
        }

        $username = trim($username);
        if ($username === '') {
            return [];
        }

        try {
            $program = DB::table('adviser')->where('username', $username)->value('program');
            return $this->parseProgramList((string) $program);
        } catch (Throwable $e) {
            return [];
        }
    }

    private function resolveAdviserBatches(int $adviserId, string $username = ''): array
    {
        if (!Schema::hasTable('adviser_batch')) {
            return [];
        }

        $resolvedAdviserId = $adviserId;
        if ($resolvedAdviserId <= 0) {
            $username = trim($username);
            if ($username !== '' && Schema::hasTable('adviser')) {
                try {
                    $resolvedAdviserId = (int) DB::table('adviser')->where('username', $username)->value('id');
                } catch (Throwable $e) {
                    $resolvedAdviserId = 0;
                }
            }
        }

        if ($resolvedAdviserId <= 0) {
            return [];
        }

        try {
            return DB::table('adviser_batch')
                ->where('adviser_id', $resolvedAdviserId)
                ->orderBy('batch')
                ->pluck('batch')
                ->map(static fn ($batch): string => trim((string) $batch))
                ->filter(static fn (string $batch): bool => $batch !== '')
                ->unique()
                ->values()
                ->all();
        } catch (Throwable $e) {
            return [];
        }
    }

    private function resolveCoordinatorProgramKeys(string $username): array
    {
        $username = trim($username);
        if ($username === '') {
            return [];
        }

        foreach (['program_coordinator', 'program_coordinators'] as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            try {
                $program = DB::table($table)->where('username', $username)->value('program');
                $keys = $this->parseProgramList((string) $program);
                if (!empty($keys)) {
                    return $keys;
                }
            } catch (Throwable $e) {
                continue;
            }
        }

        return [];
    }

    private function getStudentDisplayName(array $studentRow): string
    {
        $last = trim((string) ($studentRow['last_name'] ?? ''));
        $first = trim((string) ($studentRow['first_name'] ?? ''));
        $middle = trim((string) ($studentRow['middle_name'] ?? ''));

        $fullName = $last;
        if ($first !== '') {
            $fullName .= ($fullName === '' ? '' : ', ') . $first;
        }
        if ($middle !== '') {
            $fullName .= ' ' . $middle;
        }

        return trim($fullName);
    }

    private function parseProgramList(string $programRaw): array
    {
        $programRaw = trim($programRaw);
        if ($programRaw === '') {
            return [];
        }

        $parts = preg_split('/\s*(?:,|;|\r\n|\r|\n)\s*/', $programRaw);
        $normalized = [];
        foreach ($parts as $part) {
            $key = $this->normalizeProgramKey($part);
            if ($key !== '') {
                $normalized[] = $key;
            }
        }

        return array_values(array_unique($normalized, SORT_STRING));
    }

    private function normalizeProgramKey(string $programName): string
    {
        $programName = trim($programName);
        if ($programName === '') {
            return '';
        }

        $normalized = strtoupper((string) preg_replace('/\s+/', ' ', $programName));

        if ((strpos($normalized, 'BUSINESS ADMINISTRATION') !== false || strpos($normalized, 'BSBA') !== false) && strpos($normalized, 'HUMAN RESOURCE') !== false) {
            return 'BSBA-HRM';
        }
        if ((strpos($normalized, 'BUSINESS ADMINISTRATION') !== false || strpos($normalized, 'BSBA') !== false) && strpos($normalized, 'MARKETING') !== false) {
            return 'BSBA-MM';
        }
        if (strpos($normalized, 'INFORMATION TECHNOLOGY') !== false) {
            return 'BSIT';
        }
        if (strpos($normalized, 'INDUSTRIAL TECHNOLOGY') !== false) {
            return 'BSINDT';
        }
        if (strpos($normalized, 'COMPUTER ENGINEERING') !== false) {
            return 'BSCPE';
        }
        if (strpos($normalized, 'CIVIL ENGINEERING') !== false) {
            return 'BSCE';
        }
        if (strpos($normalized, 'ELECTRICAL ENGINEERING') !== false) {
            return 'BSEE';
        }
        if (strpos($normalized, 'MECHANICAL ENGINEERING') !== false) {
            return 'BSME';
        }
        if ((strpos($normalized, 'SECONDARY EDUCATION') !== false || strpos($normalized, 'BSED') !== false) && strpos($normalized, 'ENGLISH') !== false) {
            return 'BSED-ENGLISH';
        }
        if ((strpos($normalized, 'SECONDARY EDUCATION') !== false || strpos($normalized, 'BSED') !== false) && (strpos($normalized, 'MATH') !== false || strpos($normalized, 'MATHEMATICS') !== false)) {
            return 'BSED-MATH';
        }
        if ((strpos($normalized, 'SECONDARY EDUCATION') !== false || strpos($normalized, 'BSED') !== false) && strpos($normalized, 'SCIENCE') !== false) {
            return 'BSED-SCIENCE';
        }

        if (preg_match('/\b(BSCS|BSIT|BSIS|BSBA|BSA|BSED|BEED|BSCPE|BSCP[E]?|BSCE|BSEE|BSME|BSTM|BSHM|BSN)\b/', $normalized, $codeMatch)) {
            $baseCode = strtoupper($codeMatch[1]);
        } elseif (strpos($normalized, 'BS ') === 0) {
            $subject = trim(substr($normalized, 3));
            $baseCode = 'BS' . $this->acronymFromPhrase($subject);
        } elseif (strpos($normalized, 'BACHELOR OF SCIENCE IN') !== false) {
            $subject = trim(str_replace('BACHELOR OF SCIENCE IN', '', $normalized));
            $baseCode = 'BS' . $this->acronymFromPhrase($subject);
        } elseif (strpos($normalized, 'BACHELOR OF SECONDARY EDUCATION') !== false) {
            $baseCode = 'BSED';
        } elseif (strpos($normalized, 'BACHELOR OF ELEMENTARY EDUCATION') !== false) {
            $baseCode = 'BEED';
        } else {
            $baseCode = strtoupper($programName);
        }

        $majorKey = '';
        if (preg_match('/MAJOR\s+IN\s+(.+)$/', $normalized, $majorMatch)) {
            $majorKey = $this->acronymFromPhrase($majorMatch[1]);
        }

        return $majorKey !== '' ? ($baseCode . '-' . $majorKey) : $baseCode;
    }

    private function canonicalProgramLabel(string $programKey): string
    {
        $normalizedKey = strtoupper(trim($programKey));
        if ($normalizedKey === '') {
            return '';
        }

        $map = [
            'BSBA-MM' => 'Bachelor of Science in Business Administration Major in Marketing Management',
            'BSBA-HRM' => 'Bachelor of Science in Business Administration Major in Human Resource Management',
            'BSCPE' => 'Bachelor of Science in Computer Engineering',
            'BSCS' => 'Bachelor of Science in Computer Science',
            'BSHM' => 'Bachelor of Science in Hospitality Management',
            'BSINDT' => 'Bachelor of Science in Industrial Technology',
            'BSIT' => 'Bachelor of Science in Information Technology',
            'BSED-ENGLISH' => 'Bachelor of Secondary Education Major in English',
            'BSED-MATH' => 'Bachelor of Secondary Education Major in Mathematics',
            'BSED-SCIENCE' => 'Bachelor of Secondary Education Major in Science',
        ];

        return $map[$normalizedKey] ?? '';
    }

    private function normalizeProgramLabel(string $value): string
    {
        $value = strtoupper(trim($value));
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/\s+/', ' ', $value);
        return $value ?? '';
    }

    private function generateRequestCode(): string
    {
        return 'SHIFT-' . now()->format('YmdHis') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
    }

    private function acronymFromPhrase(string $text): string
    {
        $cleaned = strtoupper(trim($text));
        if ($cleaned === '') {
            return '';
        }

        $cleaned = preg_replace('/[^A-Z0-9\s]/', ' ', $cleaned);
        $cleaned = preg_replace('/\s+/', ' ', $cleaned);
        $tokens = explode(' ', (string) $cleaned);
        $skip = ['OF', 'IN', 'AND', 'THE', 'A', 'AN', 'MAJOR', 'PROGRAM'];
        $result = '';

        foreach ($tokens as $token) {
            if ($token === '' || in_array($token, $skip, true)) {
                continue;
            }
            $result .= substr($token, 0, 1);
        }

        return $result;
    }

    private function resolveProgramTokens(string $programLabel): array
    {
        $normalizedKey = $this->normalizeProgramKey($programLabel);
        if ($normalizedKey === '') {
            return [];
        }

        $tokens = $this->expandProgramKeyAliases([$normalizedKey]);
        $tokens[] = strtoupper($normalizedKey);

        return array_values(array_unique(array_filter(array_map(static function ($token) {
            return strtoupper(trim((string) $token));
        }, $tokens), static function ($token) {
            return $token !== '';
        })));
    }

    private function expandProgramKeyAliases(array $keys): array
    {
        $expanded = [];
        foreach ($keys as $key) {
            $normalized = strtoupper(trim((string) $key));
            if ($normalized === '') {
                continue;
            }

            $expanded[$normalized] = true;

            if ($normalized === 'BSCPE' || $normalized === 'BSCOE') {
                $expanded['BSCPE'] = true;
                $expanded['BSCOE'] = true;
            }

            if ($normalized === 'BSINDT' || $normalized === 'BSINDTECH') {
                $expanded['BSINDT'] = true;
                $expanded['BSINDTECH'] = true;
            }
        }

        return array_keys($expanded);
    }

    private function programMatchesActorKeys(string $programLabel, array $actorProgramKeys): bool
    {
        if (empty($actorProgramKeys)) {
            return true;
        }

        $programKey = $this->normalizeProgramKey($programLabel);
        if ($programKey === '') {
            return true;
        }

        $actorKeysExpanded = $this->expandProgramKeyAliases($actorProgramKeys);
        $programKeysExpanded = $this->expandProgramKeyAliases([$programKey]);

        return !empty(array_intersect($programKeysExpanded, $actorKeysExpanded));
    }

    private function studentMatchesAdviserBatches(string $studentNumber, array $batches): bool
    {
        $studentNumber = trim($studentNumber);
        if ($studentNumber === '' || empty($batches)) {
            return false;
        }

        foreach ($batches as $batch) {
            $batch = trim((string) $batch);
            if ($batch !== '' && str_starts_with($studentNumber, $batch)) {
                return true;
            }
        }

        return false;
    }

    private function requestMatchesAdviserScope(array $requestRow, array $programKeys, array $adviserBatches): bool
    {
        if (empty($programKeys) || empty($adviserBatches)) {
            return false;
        }

        if (!$this->programMatchesActorKeys((string) ($requestRow['current_program'] ?? ''), $programKeys)) {
            return false;
        }

        return $this->studentMatchesAdviserBatches((string) ($requestRow['student_number'] ?? ''), $adviserBatches);
    }

    private function coordinatorPendingStatuses(): array
    {
        return ['pending_current_coordinator', 'pending_destination_coordinator', 'pending_coordinator'];
    }

    private function resolveCoordinatorStage(array $requestRow): string
    {
        $status = trim((string) ($requestRow['status'] ?? ''));
        if ($status === 'pending_current_coordinator') {
            return 'current';
        }
        if ($status === 'pending_destination_coordinator' || $status === 'pending_coordinator') {
            return 'destination';
        }

        return '';
    }

    private function isBridgeAuthorized(Request $request): bool
    {
        return filter_var($request->input('bridge_authorized', false), FILTER_VALIDATE_BOOL);
    }

    private function response(bool $success, string $message, int $status = 200, array $extra = []): JsonResponse
    {
        return response()->json(array_merge([
            'success' => $success,
            'message' => $message,
        ], $extra), $status);
    }
}
