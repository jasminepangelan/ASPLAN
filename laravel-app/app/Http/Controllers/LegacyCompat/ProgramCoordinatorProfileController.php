<?php

namespace App\Http\Controllers\LegacyCompat;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ProgramCoordinatorProfileController extends Controller
{
    public function view(Request $request): JsonResponse
    {
        try {
            if (!$this->isBridgeAuthorized($request)) {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
            }

            $username = trim((string) $request->input('username', ''));
            if ($username === '') {
                return response()->json(['success' => false, 'message' => 'Profile not found.'], 404);
            }

            $table = $this->resolveTableVariant();
            if ($table === null) {
                return response()->json(['success' => false, 'message' => 'Profile table not found.'], 500);
            }

            $profile = DB::table($table)
                ->where('username', $username)
                ->first();

            if ($profile === null) {
                return response()->json(['success' => false, 'message' => 'Profile not found.'], 404);
            }

            return response()->json([
                'success' => true,
                'profile' => (array) $profile,
            ]);
        } catch (Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Failed to load profile.'], 500);
        }
    }

    public function update(Request $request): JsonResponse
    {
        try {
            if (!$this->isBridgeAuthorized($request)) {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
            }

            $username = trim((string) $request->input('username', ''));
            if ($username === '') {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
            }

            $firstName = trim((string) $request->input('first_name', ''));
            $lastName = trim((string) $request->input('last_name', ''));
            $middleName = trim((string) $request->input('middle_name', ''));
            $prefix = trim((string) $request->input('prefix', ''));
            $suffix = trim((string) $request->input('suffix', ''));
            $email = trim((string) $request->input('adviser_email', ''));
            $sex = trim((string) $request->input('sex', ''));
            $pronoun = trim((string) $request->input('pronoun', ''));
            $program = trim((string) $request->input('program', ''));
            $newPassword = (string) $request->input('new_password', '');

            if ($firstName === '' || $lastName === '') {
                return response()->json(['success' => false, 'message' => 'First name and last name are required.'], 422);
            }

            $table = $this->resolveTableVariant();
            if ($table === null) {
                return response()->json(['success' => false, 'message' => 'Profile table not found.'], 500);
            }

            $payload = [
                'first_name' => $firstName,
                'last_name' => $lastName,
                'middle_name' => $middleName,
                'prefix' => $prefix,
                'suffix' => $suffix,
                'adviser_email' => $email,
                'sex' => $sex,
                'pronoun' => $pronoun,
                'program' => $program,
            ];

            if ($newPassword !== '') {
                $payload['password'] = password_hash($newPassword, PASSWORD_DEFAULT);
            }

            $updated = DB::table($table)
                ->where('username', $username)
                ->update($payload);

            if ($updated < 0) {
                return response()->json(['success' => false, 'message' => 'Failed to update profile.'], 500);
            }

            $fullName = trim(implode(' ', array_filter([$prefix, $firstName, $middleName, $lastName, $suffix], static fn (string $part): bool => $part !== '')));

            return response()->json([
                'success' => true,
                'message' => 'Profile updated successfully!',
                'profile' => array_merge($payload, [
                    'username' => $username,
                ]),
                'full_name' => $fullName,
            ]);
        } catch (Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Failed to update profile.'], 500);
        }
    }

    private function resolveTableVariant(): ?string
    {
        if (Schema::hasTable('program_coordinator')) {
            return 'program_coordinator';
        }

        if (Schema::hasTable('program_coordinators')) {
            return 'program_coordinators';
        }

        return null;
    }

    private function isBridgeAuthorized(Request $request): bool
    {
        return filter_var($request->input('bridge_authorized', false), FILTER_VALIDATE_BOOL);
    }
}
