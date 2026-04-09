<?php

namespace App\Http\Controllers\LegacyCompat;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class AuthController extends Controller
{
    private const LOGIN_MAX_ATTEMPTS = 5;
    private const LOGIN_WINDOW_SECONDS = 300;

    public function unifiedLogin(Request $request): JsonResponse
    {
        try {
            if ($request->method() !== 'POST') {
                return response()->json(['status' => 'error', 'message' => 'Invalid request method.']);
            }

            $username = trim((string) $request->input('username', ''));
            $password = trim((string) $request->input('password', ''));
            $rememberMe = (bool) $request->input('remember_me', false);
            $rateLimit = $this->checkRateLimit($request, 'login', self::LOGIN_MAX_ATTEMPTS, self::LOGIN_WINDOW_SECONDS);
            if (!$rateLimit['allowed']) {
                return response()->json([
                    'status' => 'rate_limited',
                    'message' => $rateLimit['message'],
                ], 429);
            }

            if ($username === '' || $password === '') {
                $this->recordRateLimitAttempt($request, 'login');
                return response()->json(['status' => 'error', 'message' => 'Student ID/Username or password cannot be empty.']);
            }

            $user = $this->resolveUser($username);
            if ($user === null) {
                $this->recordRateLimitAttempt($request, 'login');
                if (preg_match('/^[0-9]+$/', $username)) {
                    return response()->json(['status' => 'error', 'message' => 'Student ID not found. Please check and try again.']);
                }

                return response()->json(['status' => 'error', 'message' => 'Account not found. Please check your credentials.']);
            }

            if (!password_verify($password, (string) ($user['password'] ?? ''))) {
                $this->recordRateLimitAttempt($request, 'login');
                return response()->json(['status' => 'error', 'message' => 'Invalid password. Please try again.']);
            }

            if ($user['type'] === 'student') {
                if (!$this->studentHasMasterlistAccess((string) ($user['student_id'] ?? ''))) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'This student account is not authorized by the current official masterlist. Please contact the administrator.',
                    ], 403);
                }

                $status = (string) ($user['status'] ?? 'approved');
                if ($status === 'pending') {
                    return response()->json(['status' => 'pending', 'message' => 'Your account is pending approval. Please wait for the admin to approve.']);
                }
                if ($status === 'rejected') {
                    return response()->json(['status' => 'rejected', 'message' => 'Your account was rejected. Please contact admin for more information.']);
                }
            }

            $this->resetRateLimit($request, 'login');
            $response = [
                'status' => 'success',
                'redirect' => $this->redirectForType($user['type']),
                'user_type' => $user['type'],
                'session' => $this->sessionPayload($user, $request->ip()),
                'clear_remember_cookie' => $user['type'] === 'student' && !$rememberMe,
            ];

            if ($user['type'] === 'student' && $rememberMe) {
                $remember = $this->createRememberToken((string) $user['student_id']);
                if ($remember !== null) {
                    $response['remember'] = $remember;
                }
            }

            return response()->json($response);
        } catch (Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
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
            ->select(['attempts', 'first_attempt'])
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
        $windowStart = now()->subSeconds(self::LOGIN_WINDOW_SECONDS)->toDateTimeString();

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

    private function resolveUser(string $username): ?array
    {
        if (preg_match('/^[0-9]+$/', $username)) {
            $student = DB::table('student_info')
                ->select([
                    DB::raw('student_number as student_id'),
                    'last_name',
                    'first_name',
                    'middle_name',
                    'email',
                    'password',
                    DB::raw('contact_number as contact_no'),
                    DB::raw("CONCAT_WS(', ', house_number_street, brgy, town, province) as address"),
                    DB::raw('date_of_admission as admission_date'),
                    'picture',
                    'status',
                    'program',
                ])
                ->where('student_number', $username)
                ->first();

            if ($student !== null) {
                $studentArray = (array) $student;
                return array_merge($studentArray, ['type' => 'student']);
            }
        }

        $admin = DB::table('admin')
            ->select([
                'username',
                DB::raw("CONCAT_WS(' ', first_name, middle_name, last_name) as full_name"),
                'password',
            ])
            ->where('username', $username)
            ->first();

        if ($admin !== null) {
            return array_merge((array) $admin, ['type' => 'admin']);
        }

        $adviser = DB::table('adviser')
            ->select([
                'id',
                DB::raw("CONCAT_WS(' ', first_name, middle_name, last_name) as full_name"),
                'username',
                'password',
                'pronoun',
            ])
            ->where('username', $username)
            ->first();

        if ($adviser !== null) {
            return array_merge((array) $adviser, ['type' => 'adviser']);
        }

        $pcTable = $this->resolveProgramCoordinatorTable();
        if ($pcTable !== null) {
            $pc = DB::table($pcTable)
                ->select([
                    'id',
                    DB::raw("CONCAT_WS(' ', first_name, middle_name, last_name) as full_name"),
                    'username',
                    'password',
                    'pronoun',
                ])
                ->where('username', $username)
                ->first();

            if ($pc !== null) {
                return array_merge((array) $pc, ['type' => 'program_coordinator']);
            }
        }

        return null;
    }
    private function resolveProgramCoordinatorTable(): ?string
    {
        $tables = DB::select("SHOW TABLES LIKE 'program_coordinator'");
        if (!empty($tables)) {
            return 'program_coordinator';
        }

        $tables = DB::select("SHOW TABLES LIKE 'program_coordinators'");
        if (!empty($tables)) {
            return 'program_coordinators';
        }

        return null;
    }

    private function redirectForType(string $type): string
    {
        return match ($type) {
            'student' => 'student/home_page_student.php',
            'admin' => 'admin/index.php',
            'adviser' => 'adviser/index.php',
            'program_coordinator' => 'program_coordinator/index.php',
            default => 'index.html',
        };
    }

    private function sessionPayload(array $user, string $ip): array
    {
        $base = [
            'login_time' => time(),
            'user_ip' => $ip !== '' ? $ip : 'unknown',
            'user_type' => $user['type'],
        ];

        return match ($user['type']) {
            'student' => array_merge($base, [
                'student_id' => $user['student_id'],
                'last_name' => $user['last_name'] ?? '',
                'first_name' => $user['first_name'] ?? '',
                'middle_name' => $user['middle_name'] ?? '',
                'email' => $user['email'] ?? '',
                'contact_no' => $user['contact_no'] ?? '',
                'address' => $user['address'] ?? '',
                'admission_date' => $user['admission_date'] ?? '',
                'picture' => $user['picture'] ?? '',
                'program' => $user['program'] ?? '',
            ]),
            'admin' => array_merge($base, [
                'admin_id' => $user['username'] ?? '',
                'admin_username' => $user['username'] ?? '',
                'admin_full_name' => $user['full_name'] ?? '',
            ]),
            'adviser' => array_merge($base, [
                'id' => $user['id'] ?? null,
                'full_name' => $user['full_name'] ?? '',
                'username' => $user['username'] ?? '',
                'pronoun' => $user['pronoun'] ?? '',
            ]),
            'program_coordinator' => array_merge($base, [
                'id' => $user['id'] ?? null,
                'full_name' => $user['full_name'] ?? '',
                'username' => $user['username'] ?? '',
                'pronoun' => $user['pronoun'] ?? '',
            ]),
            default => $base,
        };
    }

    private function createRememberToken(string $studentId): ?array
    {
        try {
            $token = bin2hex(random_bytes(32));
            $tokenHash = password_hash($token, PASSWORD_DEFAULT);
            $expiry = time() + (30 * 24 * 60 * 60);

            DB::table('student_info')
                ->where('student_number', $studentId)
                ->update([
                    'remember_token' => $tokenHash,
                    'remember_token_expiry' => DB::raw('FROM_UNIXTIME(' . (int) $expiry . ')'),
                ]);

            return [
                'cookie_value' => $studentId . ':' . $token . ':student',
                'expires' => $expiry,
            ];
        } catch (Throwable $e) {
            return null;
        }
    }

    private function studentHasMasterlistAccess(string $studentId): bool
    {
        if ($studentId === '') {
            return false;
        }

        $this->ensureMasterlistTable();

        return DB::table('student_masterlist')
            ->where('student_number', $studentId)
            ->exists();
    }

    private function ensureMasterlistTable(): void
    {
        DB::statement("CREATE TABLE IF NOT EXISTS student_masterlist (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_number VARCHAR(32) NOT NULL,
            last_name VARCHAR(150) NOT NULL,
            first_name VARCHAR(150) NOT NULL,
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
}
