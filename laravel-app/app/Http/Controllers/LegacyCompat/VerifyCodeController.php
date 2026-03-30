<?php

namespace App\Http\Controllers\LegacyCompat;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class VerifyCodeController extends Controller
{
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

            if ($dbCode !== $code) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid code.',
                ]);
            }

            if ($expiresAt === '' || strtotime($expiresAt) < time()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Code expired.',
                ]);
            }

            return response()->json(['success' => true]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Unable to verify code right now. Please try again.',
            ], 500);
        }
    }
}
