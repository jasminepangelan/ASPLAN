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
            $studentId = trim((string) $request->input('student_id', ''));
            if ($studentId === '') {
                return response()->json([
                    'success' => false,
                    'message' => 'Student ID is required.',
                ]);
            }

            if (!preg_match('/^[0-9]{1,20}$/', $studentId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid Student ID format.',
                ]);
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

            if (!preg_match('/^([a-zA-Z0-9_.+-]+)@cvsu.edu\.ph$/', (string) $email)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only CvSU accounts are allowed.',
                ]);
            }

            $code = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
            $expiry = date('Y-m-d H:i:s', strtotime('+10 minutes'));

            DB::statement('CREATE TABLE IF NOT EXISTS password_resets (
                email VARCHAR(255) PRIMARY KEY,
                code VARCHAR(10),
                expires_at DATETIME
            )');

            DB::table('password_resets')->updateOrInsert(
                ['email' => $email],
                ['code' => $code, 'expires_at' => $expiry]
            );

            $this->sendResetCodeEmail((string) $email, $code);

            return response()->json(['success' => true]);
        } catch (Throwable $e) {
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

            if (function_exists('getMailer')) {
                $mail = getMailer();
                $mail->addAddress($email);
                $mail->isHTML(false);
                $mail->Subject = 'Your Password Reset Code';
                $mail->Body = "Your password reset code is: {$code}\nThis code will expire in 10 minutes.";

                if (!$mail->send()) {
                    throw new \RuntimeException($this->formatMailerErrorMessage((string) $mail->ErrorInfo));
                }

                return;
            }
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
}
