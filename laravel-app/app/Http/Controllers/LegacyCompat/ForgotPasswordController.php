<?php

namespace App\Http\Controllers\LegacyCompat;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Config;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

class ForgotPasswordController extends Controller
{
    private const FORGOT_PASSWORD_MAX_ATTEMPTS = 3;
    private const FORGOT_PASSWORD_WINDOW_SECONDS = 600;

    private function ensurePasswordResetsTable(): void
    {
        DB::statement('CREATE TABLE IF NOT EXISTS password_resets (
            email VARCHAR(255) PRIMARY KEY,
            code VARCHAR(255),
            expires_at DATETIME
        )');
        DB::statement('ALTER TABLE password_resets MODIFY COLUMN code VARCHAR(255) NULL');
    }

    private function normalizeMailEnvValue($value, bool $collapseInternalWhitespace = false): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        $value = trim($value);

        if ($collapseInternalWhitespace) {
            $value = preg_replace('/\s+/', '', $value) ?? $value;
        }

        return $value;
    }

    public function sendCode(Request $request): JsonResponse
    {
        try {
            $rateLimit = $this->checkRateLimit($request, 'forgot_password', self::FORGOT_PASSWORD_MAX_ATTEMPTS, self::FORGOT_PASSWORD_WINDOW_SECONDS);
            if (!$rateLimit['allowed']) {
                return response()->json([
                    'success' => false,
                    'message' => $rateLimit['message'],
                ], 429);
            }

            $studentId = trim((string) $request->input('student_id', ''));
            if ($studentId === '') {
                $this->recordRateLimitAttempt($request, 'forgot_password');
                return response()->json([
                    'success' => false,
                    'message' => 'Student ID is required.',
                ]);
            }

            if (!preg_match('/^[0-9]{1,20}$/', $studentId)) {
                $this->recordRateLimitAttempt($request, 'forgot_password');
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid Student ID format.',
                ]);
            }

            $email = DB::table('student_info')
                ->where('student_number', $studentId)
                ->value('email');

            if ($email === null || $email === '') {
                $this->recordRateLimitAttempt($request, 'forgot_password');
                return response()->json([
                    'success' => false,
                    'message' => 'Student ID not found.',
                ]);
            }

            if (!preg_match('/^([a-zA-Z0-9_.+-]+)@cvsu.edu\.ph$/', (string) $email)) {
                $this->recordRateLimitAttempt($request, 'forgot_password');
                return response()->json([
                    'success' => false,
                    'message' => 'Only CvSU accounts are allowed.',
                ]);
            }

            $code = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
            $codeHash = password_hash($code, PASSWORD_DEFAULT);
            $expiry = date('Y-m-d H:i:s', strtotime('+10 minutes'));

            $this->ensurePasswordResetsTable();

            DB::table('password_resets')->updateOrInsert(
                ['email' => $email],
                ['code' => $codeHash, 'expires_at' => $expiry]
            );

            $this->sendResetCodeEmail((string) $email, $code);
            $this->recordRateLimitAttempt($request, 'forgot_password');

            return response()->json(['success' => true]);
        } catch (Throwable $e) {
            $this->recordRateLimitAttempt($request, 'forgot_password');
            return response()->json([
                'success' => false,
                'message' => $this->formatMailerErrorMessage($e->getMessage()),
            ]);
        }
    }

    private function sendResetCodeEmail(string $email, string $code): void
    {
        $emailConfigPath = $this->resolveLegacyEmailConfigPath();
        if ($emailConfigPath !== null) {
            require_once $emailConfigPath;

            if (function_exists('sendConfiguredEmail')) {
                $sendResult = sendConfiguredEmail(
                    $email,
                    'Your Password Reset Code',
                    "Your password reset code is: {$code}\nThis code will expire in 10 minutes."
                );

                if (empty($sendResult['success'])) {
                    throw new \RuntimeException($this->formatMailerErrorMessage((string) ($sendResult['error'] ?? 'Unable to send email.')));
                }

                return;
            }
        }

        if ($this->sendResetCodeEmailThroughResend($email, $code)) {
            return;
        }

        $host = $this->normalizeMailEnvValue(env('MAIL_HOST') ?: env('SMTP_HOST') ?: '');
        $port = (int) ($this->normalizeMailEnvValue(env('MAIL_PORT') ?: env('SMTP_PORT') ?: '587') ?: 587);
        $username = $this->normalizeMailEnvValue(env('MAIL_USERNAME') ?: env('SMTP_USERNAME') ?: '');
        $password = $this->normalizeMailEnvValue(env('MAIL_PASSWORD') ?: env('SMTP_PASSWORD') ?: '', true);
        $encryption = $this->normalizeMailEnvValue(env('MAIL_ENCRYPTION') ?: env('SMTP_SECURE') ?: 'tls');
        $fromAddress = $this->normalizeMailEnvValue(env('MAIL_FROM_ADDRESS') ?: env('SMTP_FROM_EMAIL') ?: $username);
        $fromName = $this->normalizeMailEnvValue(env('MAIL_FROM_NAME') ?: env('SMTP_FROM_NAME') ?: 'ASPLAN');

        if ($host === '' || $username === '' || $password === '' || $fromAddress === '') {
            throw new \RuntimeException('Email service is not configured.');
        }

        Config::set('mail.default', 'smtp');
        Config::set('mail.mailers.smtp.transport', 'smtp');
        Config::set('mail.mailers.smtp.host', $host);
        Config::set('mail.mailers.smtp.port', $port);
        Config::set('mail.mailers.smtp.username', $username);
        Config::set('mail.mailers.smtp.password', $password);
        Config::set('mail.mailers.smtp.encryption', $encryption !== '' ? $encryption : null);
        Config::set('mail.from.address', $fromAddress);
        Config::set('mail.from.name', $fromName);

        Mail::raw(
            "Your password reset code is: {$code}\nThis code will expire in 10 minutes.",
            static function ($message) use ($email): void {
                $message->to($email)
                    ->subject('Your Password Reset Code');
            }
        );
    }

    private function sendResetCodeEmailThroughResend(string $email, string $code): bool
    {
        $apiKey = $this->normalizeMailEnvValue(env('RESEND_API_KEY') ?: '');
        $fromEmail = $this->normalizeMailEnvValue(env('RESEND_FROM_EMAIL') ?: env('MAIL_FROM_ADDRESS') ?: '');
        $fromName = $this->normalizeMailEnvValue(env('RESEND_FROM_NAME') ?: env('MAIL_FROM_NAME') ?: 'ASPLAN');
        $replyTo = $this->normalizeMailEnvValue(env('RESEND_REPLY_TO') ?: $fromEmail);

        if ($apiKey === '' || $fromEmail === '' || !function_exists('curl_init')) {
            return false;
        }

        $payload = [
            'from' => $fromName !== '' ? ($fromName . ' <' . $fromEmail . '>') : $fromEmail,
            'to' => [$email],
            'subject' => 'Your Password Reset Code',
            'text' => "Your password reset code is: {$code}\nThis code will expire in 10 minutes.",
        ];

        if ($replyTo !== '') {
            $payload['reply_to'] = $replyTo;
        }

        $ch = curl_init('https://api.resend.com/emails');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false || $response === '' || $httpCode < 200 || $httpCode >= 300) {
            $decoded = is_string($response) ? json_decode($response, true) : null;
            $errorMessage = trim((string) (($decoded['message'] ?? '') ?: ($decoded['error'] ?? '') ?: $curlError));
            if ($errorMessage !== '') {
                throw new \RuntimeException($this->formatMailerErrorMessage($errorMessage));
            }

            return false;
        }

        return true;
    }

    private function formatMailerErrorMessage(string $message): string
    {
        $message = trim($message);
        if ($message === '') {
            return 'Unable to send the verification email right now. Please try again later.';
        }

        if (stripos($message, 'Could not connect to SMTP host') !== false || stripos($message, 'Network is unreachable') !== false) {
            return 'Unable to connect to the configured SMTP server. Please check the mail host, port, and outbound network access.';
        }

        return preg_replace('/^(Mailer Error:\s*)+/i', 'Mailer Error: ', $message) ?: 'Unable to send the verification email right now.';
    }

    private function resolveLegacyEmailConfigPath(): ?string
    {
        $candidates = [
            dirname(__DIR__, 5) . '/config/email.php',
            dirname(__DIR__, 4) . '/config/email.php',
            base_path('../config/email.php'),
            base_path('../../config/email.php'),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '' && is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
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
        $windowStart = now()->subSeconds(self::FORGOT_PASSWORD_WINDOW_SECONDS)->toDateTimeString();

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
}
