<?php

namespace App\Http\Controllers\LegacyCompat;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class ForgotPasswordController extends Controller
{
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
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function sendResetCodeEmail(string $email, string $code): void
    {
        $emailConfigPath = $this->resolveLegacyEmailConfigPath();
        require_once $emailConfigPath;

        $mail = getMailer();
        $mail->addAddress($email);
        $mail->isHTML(false);
        $mail->Subject = 'Your Password Reset Code';
        $mail->Body = "Your password reset code is: {$code}\nThis code will expire in 10 minutes.";

        if (!$mail->send()) {
            throw new \RuntimeException('Mailer Error: ' . $mail->ErrorInfo);
        }
    }

    private function resolveLegacyEmailConfigPath(): string
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

        throw new \RuntimeException('Email configuration file is missing.');
    }
}
