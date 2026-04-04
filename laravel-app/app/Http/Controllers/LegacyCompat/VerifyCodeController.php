<?php

namespace App\Http\Controllers\LegacyCompat;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class VerifyCodeController extends Controller
{
    private const VERIFY_RESET_MAX_ATTEMPTS = 5;
    private const VERIFY_RESET_WINDOW_SECONDS = 600;

    public function verify(Request $request): JsonResponse
    {
        try {
            $studentId = trim((string) $request->input('student_id', ''));
            $code = trim((string) $request->input('code', ''));

            if ($studentId === '' || $code === '') {
                return response()->json([
                    'success' => false,
                    'message' => 'Student ID and code are required.',
                ]);
            }

            $rateAction = $this->scopedRateLimitAction('verify_reset_code', $studentId);
            $rateLimit = $this->checkRateLimit($request, $rateAction, self::VERIFY_RESET_MAX_ATTEMPTS, self::VERIFY_RESET_WINDOW_SECONDS);
            if (!$rateLimit['allowed']) {
                return response()->json([
                    'success' => false,
                    'message' => $rateLimit['message'],
                ], 429);
            }

            $email = DB::table('student_info')
                ->where('student_number', $studentId)
                ->value('email');

            if ($email === null || $email === '') {
                return response()->json([
                    'success' => false,
                    'message' => 'Student ID not found.',
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

            $this->resetRateLimit($request, $rateAction);
            return response()->json(['success' => true]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Unable to verify code right now. Please try again.',
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
        $windowStart = now()->subSeconds(self::VERIFY_RESET_WINDOW_SECONDS)->toDateTimeString();

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
}
