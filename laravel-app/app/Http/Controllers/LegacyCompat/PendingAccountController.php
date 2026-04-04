<?php

namespace App\Http\Controllers\LegacyCompat;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class PendingAccountController extends Controller
{
    public function adminList(Request $request): JsonResponse
    {
        try {
            if (!$this->isBridgeAuthorized($request)) {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
            }

            $adminId = trim((string) $request->input('admin_id', ''));
            if ($adminId === '' || !$this->adminExists($adminId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Access denied. Please log in as admin.',
                ], 401);
            }

            $pendingAccounts = DB::table('student_info')
                ->select([
                    DB::raw('student_number as student_id'),
                    'last_name',
                    'first_name',
                    'middle_name',
                ])
                ->where('status', 'pending')
                ->orderBy('student_number')
                ->get()
                ->map(static fn ($row): array => (array) $row)
                ->all();

            return response()->json([
                'success' => true,
                'pending_accounts' => $pendingAccounts,
                'pending_count' => count($pendingAccounts),
                'auto_approve_enabled' => $this->isAutoApproveEnabled(),
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load pending accounts',
            ], 500);
        }
    }

    public function adviserList(Request $request): JsonResponse
    {
        try {
            if (!$this->isBridgeAuthorized($request)) {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
            }

            $adviserId = (int) $request->input('adviser_id', 0);
            if ($adviserId <= 0 || !$this->adviserExists($adviserId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Access denied. Please log in.',
                ], 401);
            }

            $batches = $this->loadAdviserBatches($adviserId);
            if (empty($batches)) {
                return response()->json([
                    'success' => true,
                    'pending_accounts' => [],
                    'pending_count' => 0,
                ]);
            }

            $pendingAccounts = $this->loadPendingAccountsForBatches($batches);

            return response()->json([
                'success' => true,
                'pending_accounts' => $pendingAccounts,
                'pending_count' => count($pendingAccounts),
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load pending accounts',
            ], 500);
        }
    }

    public function adminApprove(Request $request): JsonResponse
    {
        try {
            if (!$this->isBridgeAuthorized($request)) {
                return $this->response(false, 'Unauthorized', 'error', 403);
            }

            $studentId = trim((string) $request->input('student_id', ''));
            $adminId = trim((string) $request->input('admin_id', $request->input('approved_by', '')));

            if ($studentId === '') {
                return $this->response(false, 'Invalid student ID', 'error', 422);
            }

            if (!$this->isValidStudentId($studentId)) {
                return $this->response(false, 'Invalid student ID format', 'error', 422);
            }

            if ($adminId === '' || !$this->adminExists($adminId)) {
                return $this->response(false, 'Invalid admin session', 'error', 422);
            }

            $updated = DB::table('student_info')
                ->where('student_number', $studentId)
                ->update([
                    'status' => 'approved',
                    'approved_by' => $adminId,
                ]);

            if ($updated <= 0) {
                return $this->response(false, 'Student account not found', 'error', 404);
            }

            return $this->response(true, 'Account approved successfully.');
        } catch (Throwable $e) {
            return $this->response(false, 'Database error', 'error', 500);
        }
    }

    public function adminReject(Request $request): JsonResponse
    {
        try {
            if (!$this->isBridgeAuthorized($request)) {
                return $this->response(false, 'Unauthorized', 'error', 403);
            }

            $studentId = trim((string) $request->input('student_id', ''));
            $adminId = trim((string) $request->input('admin_id', ''));

            if ($studentId === '') {
                return $this->response(false, 'Invalid student ID', 'error', 422);
            }

            if (!$this->isValidStudentId($studentId)) {
                return $this->response(false, 'Invalid student ID format', 'error', 422);
            }

            if ($adminId === '' || !$this->adminExists($adminId)) {
                return $this->response(false, 'Invalid admin session', 'error', 422);
            }

            $updated = DB::table('student_info')
                ->where('student_number', $studentId)
                ->update(['status' => 'rejected']);

            if ($updated <= 0) {
                return $this->response(false, 'Student account not found', 'error', 404);
            }

            return $this->response(true, 'Account rejected successfully.');
        } catch (Throwable $e) {
            return $this->response(false, 'Database error', 'error', 500);
        }
    }

    public function adviserApprove(Request $request): JsonResponse
    {
        try {
            if (!$this->isBridgeAuthorized($request)) {
                return $this->response(false, 'Unauthorized', 'message', 403);
            }

            $studentId = trim((string) $request->input('student_id', ''));
            $adviserId = (int) $request->input('adviser_id', 0);

            if ($studentId === '') {
                return $this->response(false, 'No account selected.', 'message', 422);
            }

            if (!$this->isValidStudentId($studentId)) {
                return $this->response(false, 'Invalid student ID format.', 'message', 422);
            }

            if ($adviserId <= 0 || !$this->adviserExists($adviserId)) {
                return $this->response(false, 'Access denied. Please log in.', 'message', 401);
            }

            $batches = $this->loadAdviserBatches($adviserId);
            if (empty($batches)) {
                return $this->response(false, 'No batch assigned to this adviser.', 'message', 403);
            }

            if (!$this->studentMatchesAnyBatch($studentId, $batches)) {
                return $this->response(false, 'Access denied for selected student.', 'message', 403);
            }

            $exists = DB::table('student_info')
                ->where('student_number', $studentId)
                ->exists();

            if (!$exists) {
                return $this->response(false, 'Student account not found.', 'message', 404);
            }

            DB::table('student_info')
                ->where('student_number', $studentId)
                ->update(['status' => 'approved']);

            return $this->response(true, 'Account approved successfully');
        } catch (Throwable $e) {
            return $this->response(false, 'Error rejecting account.', 'message', 500);
        }
    }

    public function adviserReject(Request $request): JsonResponse
    {
        try {
            if (!$this->isBridgeAuthorized($request)) {
                return $this->response(false, 'Unauthorized', 'message', 403);
            }

            $studentId = trim((string) $request->input('student_id', ''));
            $adviserId = (int) $request->input('adviser_id', 0);

            if ($studentId === '') {
                return $this->response(false, 'No account selected.', 'message', 422);
            }

            if (!$this->isValidStudentId($studentId)) {
                return $this->response(false, 'Invalid student ID format.', 'message', 422);
            }

            if ($adviserId <= 0 || !$this->adviserExists($adviserId)) {
                return $this->response(false, 'Access denied. Please log in.', 'message', 401);
            }

            $batches = $this->loadAdviserBatches($adviserId);
            if (empty($batches)) {
                return $this->response(false, 'No batch assigned to this adviser.', 'message', 403);
            }

            if (!$this->studentMatchesAnyBatch($studentId, $batches)) {
                return $this->response(false, 'Access denied for selected student.', 'message', 403);
            }

            $updated = DB::table('student_info')
                ->where('student_number', $studentId)
                ->update(['status' => 'rejected']);

            if ($updated <= 0) {
                return $this->response(false, 'Student account not found.', 'message', 404);
            }

            return $this->response(true, 'Account rejected successfully.');
        } catch (Throwable $e) {
            return $this->response(false, 'Error approving account.', 'message', 500);
        }
    }

    private function isValidStudentId(string $studentId): bool
    {
        return preg_match('/^[A-Za-z0-9\-]{1,30}$/', $studentId) === 1;
    }

    private function isBridgeAuthorized(Request $request): bool
    {
        return filter_var($request->input('bridge_authorized', false), FILTER_VALIDATE_BOOL);
    }

    private function adminExists(string $adminId): bool
    {
        return DB::table('admin')->where('username', $adminId)->exists();
    }

    private function adviserExists(int $adviserId): bool
    {
        return DB::table('adviser')->where('id', $adviserId)->exists();
    }

    private function loadAdviserBatches(int $adviserId): array
    {
        return array_values(array_filter(array_map(
            static fn ($value): string => trim((string) $value),
            DB::table('adviser_batch')
                ->where('adviser_id', $adviserId)
                ->pluck('batch')
                ->all()
        ), static fn (string $batch): bool => $batch !== ''));
    }

    private function loadPendingAccountsForBatches(array $batches): array
    {
        $pending = [];
        $seen = [];

        foreach ($batches as $batch) {
            $rows = DB::table('student_info')
                ->select([
                    DB::raw('student_number as student_id'),
                    'last_name',
                    'first_name',
                    'middle_name',
                ])
                ->where('status', 'pending')
                ->where('student_number', 'like', $batch . '%')
                ->orderBy('student_number')
                ->get();

            foreach ($rows as $row) {
                $studentId = (string) ($row->student_id ?? '');
                if ($studentId === '' || isset($seen[$studentId])) {
                    continue;
                }

                $seen[$studentId] = true;
                $pending[] = [
                    'student_id' => $studentId,
                    'last_name' => (string) ($row->last_name ?? ''),
                    'first_name' => (string) ($row->first_name ?? ''),
                    'middle_name' => (string) ($row->middle_name ?? ''),
                ];
            }
        }

        return $pending;
    }

    private function isAutoApproveEnabled(): bool
    {
        return (string) DB::table('system_settings')
            ->where('setting_name', 'auto_approve_students')
            ->value('setting_value') === '1';
    }

    private function studentMatchesAnyBatch(string $studentId, array $batches): bool
    {
        foreach ($batches as $batch) {
            if (str_starts_with($studentId, (string) $batch)) {
                return true;
            }
        }

        return false;
    }

    private function response(bool $success, string $message, string $queryKey = 'message', int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => $success,
            'message' => $message,
            'query_key' => $queryKey,
        ], $status);
    }
}
