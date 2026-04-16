<?php

namespace App\Http\Controllers\LegacyCompat;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StudentIdController extends Controller
{
    public function check(Request $request): JsonResponse
    {
        $studentId = (string) ($request->input('student_id', ''));

        if ($studentId === '') {
            return response()->json([
                'exists' => false,
                'error' => 'No student_id provided',
            ]);
        }

        $availability = $this->registrationAvailability($studentId);
        if (!$availability['allowed']) {
            return response()->json([
                'exists' => true,
                'allowed' => false,
                'message' => $availability['message'],
            ]);
        }

        $this->ensureMasterlistTable();
        $allowed = DB::table('student_masterlist')
            ->where('student_number', $studentId)
            ->exists();

        return response()->json([
            'exists' => false,
            'allowed' => $allowed,
            'message' => $allowed
                ? (string) ($availability['message'] ?? '')
                : 'This student number is not included in the official masterlist. Please contact the administrator.',
        ]);
    }

    private function registrationAvailability(string $studentId): array
    {
        $student = DB::table('student_info')
            ->select(['student_number', 'status'])
            ->where('student_number', $studentId)
            ->first();

        if ($student === null) {
            return ['allowed' => true, 'reapply' => false, 'message' => ''];
        }

        $status = strtolower(trim((string) ($student->status ?? '')));
        if ($status === 'archived') {
            return [
                'allowed' => false,
                'reapply' => false,
                'message' => 'This account has been archived. Please contact the administrator if access needs to be restored.',
            ];
        }

        if ($status !== 'rejected') {
            return [
                'allowed' => false,
                'reapply' => false,
                'message' => 'Student number already exists in the system.',
            ];
        }

        $cooldownDays = max(0, min(365, (int) ($this->settingValue('rejection_cooldown_days', '0'))));
        if ($cooldownDays <= 0) {
            return ['allowed' => true, 'reapply' => true, 'message' => ''];
        }

        $this->ensureRejectionLogTable();
        $log = DB::table('student_rejection_log')
            ->select(['rejected_at'])
            ->where('student_number', $studentId)
            ->first();

        $rejectedAtTs = $log !== null ? strtotime((string) ($log->rejected_at ?? '')) : false;
        if ($rejectedAtTs === false) {
            return ['allowed' => true, 'reapply' => true, 'message' => ''];
        }

        $eligibleAt = strtotime('+' . $cooldownDays . ' days', $rejectedAtTs);
        if ($eligibleAt !== false && $eligibleAt > time()) {
            return [
                'allowed' => false,
                'reapply' => false,
                'message' => 'This account was rejected. You may re-apply after ' . date('M d, Y h:i A', $eligibleAt) . '.',
            ];
        }

        return ['allowed' => true, 'reapply' => true, 'message' => ''];
    }

    private function ensureMasterlistTable(): void
    {
        DB::statement("CREATE TABLE IF NOT EXISTS student_masterlist (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_number VARCHAR(32) NOT NULL,
            last_name VARCHAR(150) NOT NULL,
            first_name VARCHAR(150) NOT NULL,
            middle_initial VARCHAR(8) NULL,
            program VARCHAR(255) NOT NULL,
            source_filename VARCHAR(255) NULL,
            uploaded_by VARCHAR(120) NULL,
            uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_student_masterlist_student_number (student_number),
            KEY idx_student_masterlist_program (program),
            KEY idx_student_masterlist_uploaded_at (uploaded_at)
        )");
    }

    private function ensureRejectionLogTable(): void
    {
        DB::statement("CREATE TABLE IF NOT EXISTS student_rejection_log (
            student_number VARCHAR(50) PRIMARY KEY,
            rejected_at DATETIME NOT NULL,
            rejected_by VARCHAR(120) NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )");
    }

    private function settingValue(string $name, string $default = ''): string
    {
        $value = DB::table('system_settings')
            ->where('setting_name', $name)
            ->orderByDesc('id')
            ->value('setting_value');

        return $value === null ? $default : (string) $value;
    }
}
