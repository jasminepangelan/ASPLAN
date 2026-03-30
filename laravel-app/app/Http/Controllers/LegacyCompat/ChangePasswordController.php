<?php

namespace App\Http\Controllers\LegacyCompat;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class ChangePasswordController extends Controller
{
    public function change(Request $request): JsonResponse
    {
        try {
            $studentId = trim((string) $request->input('student_id', ''));
            $currentPassword = (string) $request->input('current_password', '');
            $newPassword = (string) $request->input('new_password', '');

            if ($studentId === '') {
                return response()->json(['success' => false, 'message' => 'User not logged in']);
            }

            if (!preg_match('/^[0-9]{1,20}$/', $studentId)) {
                return response()->json(['success' => false, 'message' => 'Invalid Student ID format']);
            }

            if ($currentPassword === '' || $newPassword === '') {
                return response()->json(['success' => false, 'message' => 'Current and new passwords are required']);
            }

            $minimumPasswordLength = $this->policySettingInt('min_password_length', 8, 6, 64);
            if (strlen($newPassword) < $minimumPasswordLength) {
                return response()->json([
                    'success' => false,
                    'message' => 'Password must be at least ' . $minimumPasswordLength . ' characters long',
                ]);
            }

            $storedPassword = (string) (DB::table('student_info')
                ->where('student_number', $studentId)
                ->value('password') ?? '');

            if ($storedPassword === '' || !password_verify($currentPassword, $storedPassword)) {
                return response()->json(['success' => false, 'message' => 'Current password is incorrect']);
            }

            $passwordHistoryCount = $this->policySettingInt('password_history_count', 5, 0, 24);
            if ($this->isPasswordReuseDetected($studentId, $newPassword, $passwordHistoryCount, $storedPassword)) {
                return response()->json([
                    'success' => false,
                    'message' => 'New password was recently used. Please choose a different password.',
                ]);
            }

            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            $updated = DB::table('student_info')
                ->where('student_number', $studentId)
                ->update(['password' => $hashedPassword]);

            if ($updated <= 0) {
                return response()->json(['success' => false, 'message' => 'Failed to update password']);
            }

            $this->recordPasswordHistory($studentId, $hashedPassword);
            $this->sendPasswordChangeNotification($studentId);
            return response()->json(['success' => true]);
        } catch (Throwable $e) {
            error_log('ChangePasswordController error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to update password']);
        }
    }

    private function policySettingInt(string $key, int $default, int $min, int $max): int
    {
        $raw = DB::table('system_settings')
            ->where('setting_name', $key)
            ->orderByDesc('id')
            ->value('setting_value');

        $value = is_numeric($raw) ? (int) $raw : $default;
        if ($value < $min) {
            $value = $min;
        }
        if ($value > $max) {
            $value = $max;
        }

        return $value;
    }

    private function ensurePasswordHistoryTable(): void
    {
        DB::statement("CREATE TABLE IF NOT EXISTS password_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_number VARCHAR(50) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_password_history_student (student_number, changed_at)
        )");
    }

    private function isPasswordReuseDetected(string $studentId, string $plainPassword, int $historyLimit, string $currentHash): bool
    {
        if ($historyLimit <= 0) {
            return false;
        }

        if ($currentHash !== '' && password_verify($plainPassword, $currentHash)) {
            return true;
        }

        $this->ensurePasswordHistoryTable();

        $history = DB::table('password_history')
            ->select(['password_hash'])
            ->where('student_number', $studentId)
            ->orderByDesc('changed_at')
            ->limit(max(1, $historyLimit))
            ->get();

        foreach ($history as $row) {
            $hash = (string) ($row->password_hash ?? '');
            if ($hash !== '' && password_verify($plainPassword, $hash)) {
                return true;
            }
        }

        return false;
    }

    private function recordPasswordHistory(string $studentId, string $hash): void
    {
        $this->ensurePasswordHistoryTable();
        DB::table('password_history')->insert([
            'student_number' => $studentId,
            'password_hash' => $hash,
        ]);
    }

    private function sendPasswordChangeNotification(string $studentId): void
    {
        try {
            $student = DB::table('student_info')
                ->select(['email', 'last_name', 'first_name', 'middle_name'])
                ->where('student_number', $studentId)
                ->first();

            if ($student === null) {
                return;
            }

            $email = trim((string) ($student->email ?? ''));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return;
            }

            $nameParts = [
                trim((string) ($student->first_name ?? '')),
                trim((string) ($student->middle_name ?? '')),
                trim((string) ($student->last_name ?? '')),
            ];
            $fullName = trim(implode(' ', array_filter($nameParts, static fn ($value) => $value !== '')));
            if ($fullName === '') {
                $fullName = 'Student';
            }

            $rootPath = dirname(base_path());
            require_once $rootPath . '/includes/EmailNotification.php';

            $notifier = new \EmailNotification();
            $notifier->sendPasswordChange($email, $fullName);
        } catch (Throwable $e) {
            error_log('ChangePasswordController notification error: ' . $e->getMessage());
        }
    }
}
