<?php

namespace App\Http\Controllers\LegacyCompat;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class ResetPasswordController extends Controller
{
    private const RESET_CODE_MAX_ATTEMPTS = 5;
    private const RESET_CODE_WINDOW_SECONDS = 600;

    public function reset(Request $request): JsonResponse
    {
        try {
            $studentId = trim((string) $request->input('student_id', ''));
            $code = trim((string) $request->input('code', ''));
            $password = (string) $request->input('password', '');

            if ($studentId === '' || $code === '' || $password === '') {
                return response()->json([
                    'success' => false,
                    'message' => 'All fields are required.',
                ]);
            }

            $rateAction = $this->scopedRateLimitAction('reset_password_code', $studentId);
            $rateLimit = $this->checkRateLimit($request, $rateAction, self::RESET_CODE_MAX_ATTEMPTS, self::RESET_CODE_WINDOW_SECONDS);
            if (!$rateLimit['allowed']) {
                return response()->json([
                    'success' => false,
                    'message' => $rateLimit['message'],
                ], 429);
            }

            $minimumPasswordLength = $this->policySettingInt('min_password_length', 8, 6, 64);
            if (strlen($password) < $minimumPasswordLength) {
                return response()->json([
                    'success' => false,
                    'message' => 'Password must be at least ' . $minimumPasswordLength . ' characters long.',
                ]);
            }

            $email = DB::table('student_info')
                ->where('student_number', $studentId)
                ->value('email');

            if ($email === null || $email === '') {
                return response()->json([
                    'success' => false,
                    'message' => 'Student not found.',
                ]);
            }

            $reset = DB::table('password_resets')
                ->select(['code', 'expires_at'])
                ->where('email', $email)
                ->orderByDesc('expires_at')
                ->first();

            if ($reset === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'No reset request found.',
                ]);
            }

            $dbCode = (string) ($reset->code ?? '');
            $expiresAt = (string) ($reset->expires_at ?? '');

            if (!$this->matchesStoredCode($dbCode, $code)) {
                $this->recordRateLimitAttempt($request, $rateAction);
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid code.',
                ]);
            }

            if ($expiresAt === '' || strtotime($expiresAt) < time()) {
                $this->recordRateLimitAttempt($request, $rateAction);
                return response()->json([
                    'success' => false,
                    'message' => 'Code expired.',
                ]);
            }

            $currentHash = (string) (DB::table('student_info')
                ->where('student_number', $studentId)
                ->value('password') ?? '');

            $passwordHistoryCount = $this->policySettingInt('password_history_count', 5, 0, 24);
            if ($this->isPasswordReuseDetected($studentId, $password, $passwordHistoryCount, $currentHash)) {
                return response()->json([
                    'success' => false,
                    'message' => 'New password was recently used. Please choose a different password.',
                ]);
            }

            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $updated = DB::table('student_info')
                ->where('student_number', $studentId)
                ->update(['password' => $hashed]);

            if ($updated > 0) {
                $this->resetRateLimit($request, $rateAction);
                $this->recordPasswordHistory($studentId, $hashed);
                DB::table('password_resets')->where('email', $email)->delete();
                $this->sendPasswordChangeNotification($studentId);
                return response()->json(['success' => true]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Failed to update password.',
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Unable to reset password right now. Please try again.',
            ], 500);
        }
    }

    private function matchesStoredCode(string $storedCode, string $submittedCode): bool
    {
        $hashInfo = password_get_info($storedCode);
        if (!empty($hashInfo['algo'])) {
            return password_verify($submittedCode, $storedCode);
        }

        return hash_equals($storedCode, $submittedCode);
    }

    private function scopedRateLimitAction(string $action, string $scope): string
    {
        $normalizedAction = preg_replace('/[^a-z0-9_]+/i', '_', $action) ?: 'rate_limit';
        $suffix = substr(hash('sha256', strtolower(trim($scope))), 0, 16);

        return substr($normalizedAction . '_' . $suffix, 0, 50);
    }

    private function ensureRateLimitTable(): void
    {
        DB::statement("CREATE TABLE IF NOT EXISTS rate_limits (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ip_address VARCHAR(45) NOT NULL,
            action VARCHAR(50) NOT NULL,
            attempts INT DEFAULT 0,
            first_attempt DATETIME NOT NULL,
            last_attempt DATETIME NOT NULL,
            INDEX idx_ip_action (ip_address, action),
            INDEX idx_last_attempt (last_attempt)
        )");
    }

    private function checkRateLimit(Request $request, string $action, int $maxAttempts, int $windowSeconds): array
    {
        $this->ensureRateLimitTable();

        $ip = (string) ($request->ip() ?: 'unknown');
        $windowStart = now()->subSeconds($windowSeconds)->toDateTimeString();
        $record = DB::table('rate_limits')
            ->select(['id', 'attempts', 'first_attempt'])
            ->where('ip_address', $ip)
            ->where('action', $action)
            ->where('first_attempt', '>', $windowStart)
            ->first();

        if ($record !== null) {
            $attempts = (int) ($record->attempts ?? 0);
            $firstAttempt = strtotime((string) ($record->first_attempt ?? '')) ?: time();

            if ($attempts >= $maxAttempts) {
                $retryAfter = max(0, $windowSeconds - (time() - $firstAttempt));
                return [
                    'allowed' => false,
                    'message' => 'Too many attempts. Please try again in ' . max(1, (int) ceil($retryAfter / 60)) . ' minutes.',
                ];
            }
        }

        return ['allowed' => true, 'message' => ''];
    }

    private function recordRateLimitAttempt(Request $request, string $action): void
    {
        $this->ensureRateLimitTable();

        $ip = (string) ($request->ip() ?: 'unknown');
        $now = now()->toDateTimeString();
        $windowStart = now()->subSeconds(self::RESET_CODE_WINDOW_SECONDS)->toDateTimeString();

        $record = DB::table('rate_limits')
            ->select(['id'])
            ->where('ip_address', $ip)
            ->where('action', $action)
            ->where('first_attempt', '>', $windowStart)
            ->first();

        if ($record !== null) {
            DB::table('rate_limits')
                ->where('id', (int) $record->id)
                ->update([
                    'attempts' => DB::raw('attempts + 1'),
                    'last_attempt' => $now,
                ]);
        } else {
            DB::table('rate_limits')->insert([
                'ip_address' => $ip,
                'action' => $action,
                'attempts' => 1,
                'first_attempt' => $now,
                'last_attempt' => $now,
            ]);
        }

        DB::table('rate_limits')
            ->where('last_attempt', '<', now()->subHour()->toDateTimeString())
            ->delete();
    }

    private function resetRateLimit(Request $request, string $action): void
    {
        $this->ensureRateLimitTable();

        DB::table('rate_limits')
            ->where('ip_address', (string) ($request->ip() ?: 'unknown'))
            ->where('action', $action)
            ->delete();
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
            error_log('ResetPasswordController notification error: ' . $e->getMessage());
        }
    }
}
