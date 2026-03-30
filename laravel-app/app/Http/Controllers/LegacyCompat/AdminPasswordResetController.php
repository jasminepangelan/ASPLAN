<?php

namespace App\Http\Controllers\LegacyCompat;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class AdminPasswordResetController extends Controller
{
    public function reset(Request $request): JsonResponse
    {
        try {
            if (!$this->isBridgeAuthorized($request)) {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
            }

            $newPassword = (string) $request->input('new_password', '');
            $confirmPassword = (string) $request->input('confirm_password', '');

            if ($newPassword === '' || $confirmPassword === '') {
                return response()->json(['success' => false, 'message' => 'Both password fields are required.'], 422);
            }

            if ($newPassword !== $confirmPassword) {
                return response()->json(['success' => false, 'message' => 'Passwords do not match.'], 422);
            }

            if (strlen($newPassword) < 6) {
                return response()->json(['success' => false, 'message' => 'Password must be at least 6 characters long.'], 422);
            }

            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            $updated = DB::table('admins')
                ->where('username', 'admin')
                ->update(['password' => $hashedPassword]);

            if ($updated <= 0) {
                return response()->json(['success' => false, 'message' => 'Admin user not found.'], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Admin password updated successfully!',
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update password: ' . $e->getMessage(),
            ], 500);
        }
    }
}
